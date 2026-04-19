<?php

declare(strict_types=1);

namespace Plugin\TemplateX\Controller;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\PluginDataRepository;
use App\AI\Service\AiFacade;
use App\Service\File\FileProcessor;
use App\Service\PluginDataService;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use PhpOffice\PhpWord\IOFactory as PhpWordIOFactory;
use PhpOffice\PhpWord\TemplateProcessor;
use OpenApi\Attributes as OA;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

#[Route('/api/v1/user/{userId}/plugins/templatex', name: 'api_plugin_templatex_')]
#[OA\Tag(name: 'TemplateX Plugin')]
class TemplateXController extends AbstractController
{
    private const PLUGIN_NAME = 'templatex';
    private const CONFIG_GROUP = 'P_templatex';
    private const DATA_TYPE_FORM = 'templatex_form';
    private const DATA_TYPE_CANDIDATE = 'templatex_candidate';
    private const DATA_TYPE_TEMPLATE = 'templatex_template';
    private const DATA_TYPE_VALIDATION = 'templatex_validation';
    private const ALLOWED_UPLOAD_EXTENSIONS = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'tiff', 'tif', 'bmp', 'txt', 'rtf', 'odt', 'xls', 'xlsx', 'pptx'];

    private const DEFAULT_VARIABLE_SOURCES = [
        'firstname' => ['primary' => 'form', 'fallback' => 'ai'],
        'lastname' => ['primary' => 'form', 'fallback' => 'ai'],
        'fullname' => ['primary' => 'ai', 'fallback' => 'form'],
        'address1' => ['primary' => 'ai'],
        'address2' => ['primary' => 'ai'],
        'zip' => ['primary' => 'ai'],
        'birthdate' => ['primary' => 'ai', 'fallback' => 'form'],
        'nationality' => ['primary' => 'form'],
        'maritalstatus' => ['primary' => 'form'],
        'number' => ['primary' => 'ai', 'fallback' => 'form'],
        'email' => ['primary' => 'ai', 'fallback' => 'form'],
        'target-position' => ['primary' => 'form'],
        'currentposition' => ['primary' => 'ai', 'fallback' => 'form'],
        'relevantposlist' => ['primary' => 'form'],
        'relevantfortargetposlist' => ['primary' => 'form', 'fallback' => 'ai'],
        'education' => ['primary' => 'ai', 'fallback' => 'form'],
        'moving' => ['primary' => 'form'],
        'travelorcommute' => ['primary' => 'form'],
        'commute' => ['primary' => 'form'],
        'travel' => ['primary' => 'form'],
        'noticeperiod' => ['primary' => 'form'],
        'currentansalary' => ['primary' => 'form'],
        'expectedansalary' => ['primary' => 'form'],
        'workinghours' => ['primary' => 'form'],
        'benefits' => ['primary' => 'form'],
        'languageslist' => ['primary' => 'form', 'fallback' => 'ai'],
        'otherskillslist' => ['primary' => 'form', 'fallback' => 'ai'],
    ];

    public function __construct(
        private PluginDataService $pluginData,
        private PluginDataRepository $pluginDataRepository,
        private ConfigRepository $configRepository,
        private RateLimitService $rateLimitService,
        private ModelConfigService $modelConfigService,
        private LoggerInterface $logger,
        private AiFacade $aiFacade,
        private FileProcessor $fileProcessor,
        #[Autowire('%app.upload_dir%')] private string $uploadDir,
    ) {
    }

    // =========================================================================
    // Setup & Configuration
    // =========================================================================

    #[Route('/setup-check', name: 'setup_check', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/templatex/setup-check',
        summary: 'Check plugin setup status',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Setup status')]
    public function setupCheck(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $candidateCount = $this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE);
        $templateCount = $this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE);
        $formCount = $this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM);
        $config = $this->getPluginConfig($userId);

        return $this->json([
            'success' => true,
            'status' => 'ready',
            'checks' => [
                'plugin_installed' => true,
                'has_forms' => $formCount > 0,
                'has_templates' => $templateCount > 0,
                'has_candidates' => $candidateCount > 0,
            ],
            'counts' => [
                'forms' => $formCount,
                'templates' => $templateCount,
                'candidates' => $candidateCount,
            ],
            'config' => $config,
        ]);
    }

    #[Route('/setup', name: 'setup', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/templatex/setup',
        summary: 'Initialize plugin with default form',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Setup result')]
    public function setup(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $hadForms = $this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM) > 0;

        if (!$hadForms) {
            $this->seedDefaultForm($userId);
        }

        return $this->json([
            'success' => true,
            'message' => $hadForms
                ? 'Forms already exist, no changes made'
                : 'Plugin initialized with default form',
            'counts' => [
                'forms' => $this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM),
                'templates' => $this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE),
                'candidates' => $this->pluginData->count($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE),
            ],
        ]);
    }

    #[Route('/config', name: 'config_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/templatex/config',
        summary: 'Get plugin configuration',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Plugin config')]
    public function configGet(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return $this->json([
            'success' => true,
            'config' => $this->getPluginConfig($userId),
        ]);
    }

    #[Route('/config', name: 'config_update', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/user/{userId}/plugins/templatex/config',
        summary: 'Update plugin configuration',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Updated config')]
    public function configUpdate(int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $allowedKeys = [
            'default_language', 'company_name',
            'extraction_model', 'validation_model',
            'default_template_id',
        ];

        $updated = [];
        foreach ($allowedKeys as $key) {
            if (array_key_exists($key, $data)) {
                $this->configRepository->setValue($userId, self::CONFIG_GROUP, $key, (string) $data[$key]);
                $updated[] = $key;
            }
        }

        return $this->json([
            'success' => true,
            'updated' => $updated,
            'config' => $this->getPluginConfig($userId),
        ]);
    }

    // =========================================================================
    // Template Management
    // =========================================================================

    #[Route('/templates', name: 'templates_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/templatex/templates',
        summary: 'List all templates',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'List of templates')]
    public function templatesList(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $templates = $this->pluginData->list($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE);

        return $this->json([
            'success' => true,
            'templates' => array_values($templates),
        ]);
    }

    #[Route('/templates', name: 'templates_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/templatex/templates',
        summary: 'Upload a DOCX template file',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 201, description: 'Template created')]
    public function templatesCreate(int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['success' => false, 'error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        $originalName = $file->getClientOriginalName();
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext !== 'docx') {
            return $this->json(['success' => false, 'error' => 'Only .docx files are allowed'], Response::HTTP_BAD_REQUEST);
        }

        $name = $request->request->get('name', pathinfo($originalName, PATHINFO_FILENAME));
        $templateId = 'tpl_' . bin2hex(random_bytes(6));

        $dir = $this->uploadDir . '/' . $userId . '/templatex/templates/' . $templateId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file->move($dir, 'template.docx');

        $placeholders = $this->extractPlaceholders($dir . '/template.docx');

        $templateData = [
            'id' => $templateId,
            'name' => $name,
            'original_filename' => $originalName,
            'placeholders' => $placeholders,
            'placeholder_count' => count($placeholders),
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];

        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId, $templateData);

        return $this->json([
            'success' => true,
            'template' => $templateData,
        ], Response::HTTP_CREATED);
    }

    #[Route('/templates/{templateId}', name: 'templates_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/templatex/templates/{templateId}',
        summary: 'Get template metadata with placeholders',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Template details')]
    public function templatesGet(int $userId, string $templateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $template = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId);
        if (!$template) {
            return $this->json(['success' => false, 'error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'template' => $template,
        ]);
    }

    #[Route('/templates/{templateId}', name: 'templates_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/user/{userId}/plugins/templatex/templates/{templateId}',
        summary: 'Delete a template',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Template deleted')]
    public function templatesDelete(int $userId, string $templateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->pluginData->exists($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId)) {
            return $this->json(['success' => false, 'error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        $dir = $this->uploadDir . '/' . $userId . '/templatex/templates/' . $templateId;
        $this->removeDirectory($dir);

        $this->pluginData->delete($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId);

        return $this->json(['success' => true, 'message' => 'Template deleted']);
    }

    #[Route('/templates/{templateId}/placeholders', name: 'templates_placeholders', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/templatex/templates/{templateId}/placeholders',
        summary: 'List detected placeholders for a template',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Placeholder list')]
    public function templatesPlaceholders(int $userId, string $templateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $template = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId);
        if (!$template) {
            return $this->json(['success' => false, 'error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'placeholders' => $template['placeholders'] ?? [],
        ]);
    }

    #[Route('/templates/{templateId}/download', name: 'templates_download', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/templatex/templates/{templateId}/download',
        summary: 'Download original template file',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Template DOCX file')]
    public function templatesDownload(int $userId, string $templateId, #[CurrentUser] ?User $user): Response
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $template = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId);
        if (!$template) {
            return $this->json(['success' => false, 'error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        $filePath = $this->uploadDir . '/' . $userId . '/templatex/templates/' . $templateId . '/template.docx';
        if (!is_file($filePath)) {
            return $this->json(['success' => false, 'error' => 'Template file not found on disk'], Response::HTTP_NOT_FOUND);
        }

        $downloadName = $template['original_filename'] ?? ($template['name'] . '.docx');
        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $downloadName);

        return $response;
    }

    // =========================================================================
    // Form Management
    // =========================================================================

    #[Route('/forms', name: 'forms_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/templatex/forms',
        summary: 'List all form definitions',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'List of forms')]
    public function formsList(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $forms = $this->pluginData->list($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM);

        return $this->json([
            'success' => true,
            'forms' => array_values($forms),
        ]);
    }

    #[Route('/forms', name: 'forms_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/templatex/forms',
        summary: 'Create a new form definition',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 201, description: 'Form created')]
    public function formsCreate(int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true);
        if (!$data || empty($data['name'])) {
            return $this->json(['success' => false, 'error' => 'Form name is required'], Response::HTTP_BAD_REQUEST);
        }

        $formId = $data['id'] ?? ('form_' . bin2hex(random_bytes(6)));

        $formData = [
            'id' => $formId,
            'name' => $data['name'],
            'language' => $data['language'] ?? 'de',
            'version' => $data['version'] ?? 1,
            'fields' => $data['fields'] ?? [],
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];

        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $formId, $formData);

        return $this->json([
            'success' => true,
            'form' => $formData,
        ], Response::HTTP_CREATED);
    }

    #[Route('/forms/{formId}', name: 'forms_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/templatex/forms/{formId}',
        summary: 'Get a form definition',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Form details')]
    public function formsGet(int $userId, string $formId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $form = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $formId);
        if (!$form) {
            return $this->json(['success' => false, 'error' => 'Form not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'form' => $form,
        ]);
    }

    #[Route('/forms/{formId}', name: 'forms_update', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/user/{userId}/plugins/templatex/forms/{formId}',
        summary: 'Update a form definition',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Form updated')]
    public function formsUpdate(int $userId, string $formId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $existing = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $formId);
        if (!$existing) {
            return $this->json(['success' => false, 'error' => 'Form not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $updatable = ['name', 'language', 'version', 'fields'];
        foreach ($updatable as $field) {
            if (array_key_exists($field, $data)) {
                $existing[$field] = $data[$field];
            }
        }
        $existing['updated_at'] = date('c');

        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $formId, $existing);

        return $this->json([
            'success' => true,
            'form' => $existing,
        ]);
    }

    #[Route('/forms/{formId}', name: 'forms_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/user/{userId}/plugins/templatex/forms/{formId}',
        summary: 'Delete a form definition',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Form deleted')]
    public function formsDelete(int $userId, string $formId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->pluginData->exists($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $formId)) {
            return $this->json(['success' => false, 'error' => 'Form not found'], Response::HTTP_NOT_FOUND);
        }

        $this->pluginData->delete($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $formId);

        return $this->json(['success' => true, 'message' => 'Form deleted']);
    }

    #[Route('/forms/import-parse', name: 'forms_import_parse', methods: ['POST'], priority: 10)]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/templatex/forms/import-parse',
        summary: 'Parse pasted text or DOCX into structured form fields using AI',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Parsed form fields')]
    public function formsImportParse(int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $text = null;

        $file = $request->files->get('file');
        if ($file) {
            $ext = strtolower($file->getClientOriginalExtension());
            if ($ext !== 'docx') {
                return $this->json(['success' => false, 'error' => 'Only .docx files are supported'], Response::HTTP_BAD_REQUEST);
            }
            $text = $this->extractTextFromDocx($file->getPathname());
            if ($text === null) {
                return $this->json(['success' => false, 'error' => 'Could not extract text from DOCX'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        } else {
            $body = json_decode($request->getContent(), true);
            $text = $body['text'] ?? null;
        }

        if (!$text || trim($text) === '') {
            return $this->json(['success' => false, 'error' => 'No text provided. Paste variable definitions or upload a .docx file.'], Response::HTTP_BAD_REQUEST);
        }

        $prompt = $this->buildImportParsePrompt($text);

        try {
            $messages = [
                ['role' => 'system', 'content' => 'You are a precise document structure parser. Return only valid JSON arrays.'],
                ['role' => 'user', 'content' => $prompt],
            ];
            $aiOptions = $this->resolveAiModelOptions($userId);
            $result = $this->aiFacade->chat($messages, $userId, $aiOptions);
            $content = $result['content'] ?? '';

            $parsed = $this->parseJsonFromAiResponse($content);
            if ($parsed === null || !is_array($parsed)) {
                return $this->json(['success' => false, 'error' => 'AI returned unparseable response'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $fields = isset($parsed[0]) ? $parsed : ($parsed['fields'] ?? []);

            $validated = [];
            foreach ($fields as $field) {
                if (empty($field['key'])) {
                    continue;
                }
                $fieldData = [
                    'key' => $field['key'],
                    'label' => $field['label'] ?? $field['key'],
                    'type' => $this->normalizeFieldType($field['type'] ?? 'text'),
                    'required' => (bool) ($field['required'] ?? false),
                    'source' => $this->normalizeSource($field['source'] ?? 'form'),
                    'fallback' => $this->normalizeSource($field['fallback'] ?? null),
                    'hint' => $field['hint'] ?? null,
                    'options' => $field['options'] ?? null,
                ];
                if (($fieldData['type'] === 'table') && !empty($field['columns'])) {
                    $fieldData['columns'] = $field['columns'];
                }
                $validated[] = $fieldData;
            }

            return $this->json([
                'success' => true,
                'fields' => $validated,
                'field_count' => count($validated),
                'model' => $result['model'] ?? 'unknown',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Import parse failed: ' . $e->getMessage());

            return $this->json(['success' => false, 'error' => 'AI parsing failed: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    // =========================================================================
    // Entry (Candidate) Management
    // =========================================================================

    #[Route('/candidates', name: 'candidates_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/templatex/candidates',
        summary: 'List all entries',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'List of entries')]
    public function candidatesList(int $userId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $candidates = $this->pluginData->list($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE);

        return $this->json([
            'success' => true,
            'candidates' => array_values($candidates),
        ]);
    }

    #[Route('/candidates', name: 'candidates_create', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/templatex/candidates',
        summary: 'Create a new entry',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 201, description: 'Entry created')]
    public function candidatesCreate(int $userId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $entryId = 'entry_' . bin2hex(random_bytes(6));

        $entryData = [
            'id' => $entryId,
            'name' => $data['name'] ?? '',
            'form_id' => $data['form_id'] ?? 'default',
            'template_id' => $data['template_id'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'field_values' => $data['field_values'] ?? [],
            'files' => [],
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];

        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $entryId, $entryData);

        return $this->json([
            'success' => true,
            'candidate' => $entryData,
        ], Response::HTTP_CREATED);
    }

    #[Route('/candidates/{candidateId}', name: 'candidates_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/templatex/candidates/{candidateId}',
        summary: 'Get entry detail',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Entry details')]
    public function candidatesGet(int $userId, string $candidateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $candidate = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$candidate) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'candidate' => $candidate,
        ]);
    }

    #[Route('/candidates/{candidateId}', name: 'candidates_update', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/user/{userId}/plugins/templatex/candidates/{candidateId}',
        summary: 'Update an entry',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Entry updated')]
    public function candidatesUpdate(int $userId, string $candidateId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $existing = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$existing) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];

        $updatable = ['name', 'form_id', 'template_id', 'status', 'field_values'];
        foreach ($updatable as $field) {
            if (array_key_exists($field, $data)) {
                $existing[$field] = $data[$field];
            }
        }
        $existing['updated_at'] = date('c');

        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $existing);

        return $this->json([
            'success' => true,
            'candidate' => $existing,
        ]);
    }

    #[Route('/candidates/{candidateId}', name: 'candidates_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/user/{userId}/plugins/templatex/candidates/{candidateId}',
        summary: 'Delete an entry and all its files',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Entry deleted')]
    public function candidatesDelete(int $userId, string $candidateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        if (!$this->pluginData->exists($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId)) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $dir = $this->uploadDir . '/' . $userId . '/templatex/candidates/' . $candidateId;
        $this->removeDirectory($dir);

        $this->pluginData->delete($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);

        return $this->json(['success' => true, 'message' => 'Entry deleted']);
    }

    #[Route('/candidates/{candidateId}/upload-cv', name: 'candidates_upload_cv', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/templatex/candidates/{candidateId}/upload-cv',
        summary: 'Upload primary document',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'CV uploaded')]
    public function candidatesUploadCv(int $userId, string $candidateId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $existing = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$existing) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['success' => false, 'error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, self::ALLOWED_UPLOAD_EXTENSIONS, true)) {
            return $this->json(['success' => false, 'error' => 'Unsupported file type: ' . $ext], Response::HTTP_BAD_REQUEST);
        }

        $dir = $this->uploadDir . '/' . $userId . '/templatex/candidates/' . $candidateId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $originalName = $file->getClientOriginalName();
        $storedName = 'cv.' . $ext;
        $file->move($dir, $storedName);

        $existing['files']['cv'] = [
            'filename' => $originalName,
            'stored_as' => $storedName,
            'mime_type' => $file->getClientMimeType() ?? mime_content_type($dir . '/' . $storedName) ?? 'application/octet-stream',
            'size' => filesize($dir . '/' . $storedName),
            'uploaded_at' => date('c'),
        ];
        $existing['updated_at'] = date('c');

        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $existing);

        return $this->json([
            'success' => true,
            'file' => $existing['files']['cv'],
        ]);
    }

    #[Route('/candidates/{candidateId}/upload-doc', name: 'candidates_upload_doc', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/templatex/candidates/{candidateId}/upload-doc',
        summary: 'Upload additional document',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Document uploaded')]
    public function candidatesUploadDoc(int $userId, string $candidateId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $existing = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$existing) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['success' => false, 'error' => 'No file uploaded'], Response::HTTP_BAD_REQUEST);
        }

        $dir = $this->uploadDir . '/' . $userId . '/templatex/candidates/' . $candidateId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $originalName = $file->getClientOriginalName();
        $safeFilename = bin2hex(random_bytes(4)) . '-' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalName);
        $file->move($dir, $safeFilename);

        $docEntry = [
            'filename' => $originalName,
            'stored_as' => $safeFilename,
            'mime_type' => $file->getClientMimeType() ?? 'application/octet-stream',
            'size' => filesize($dir . '/' . $safeFilename),
            'uploaded_at' => date('c'),
        ];

        if (!isset($existing['files'])) {
            $existing['files'] = [];
        }
        if (!isset($existing['files']['additional'])) {
            $existing['files']['additional'] = [];
        }
        $existing['files']['additional'][] = $docEntry;
        $existing['updated_at'] = date('c');

        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $existing);

        return $this->json([
            'success' => true,
            'file' => $docEntry,
        ]);
    }

    #[Route('/candidates/{candidateId}/files/{slot}/{fileIndex}', name: 'candidates_file_delete', methods: ['DELETE'], requirements: ['fileIndex' => '\d+'])]
    #[OA\Delete(
        path: '/api/v1/user/{userId}/plugins/templatex/candidates/{candidateId}/files/{slot}/{fileIndex}',
        summary: 'Delete a source file (CV or additional document) from an entry',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'File deleted')]
    public function candidatesFileDelete(int $userId, string $candidateId, string $slot, int $fileIndex, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $dir = $this->uploadDir . '/' . $userId . '/templatex/candidates/' . $candidateId;

        if ($slot === 'cv') {
            $cvFile = $entry['files']['cv'] ?? null;
            if (!$cvFile) {
                return $this->json(['success' => false, 'error' => 'No CV file found'], Response::HTTP_NOT_FOUND);
            }
            $storedAs = $cvFile['stored_as'] ?? '';
            if ($storedAs && is_file($dir . '/' . $storedAs)) {
                unlink($dir . '/' . $storedAs);
            }
            unset($entry['files']['cv']);
        } elseif ($slot === 'additional') {
            $additionalDocs = $entry['files']['additional'] ?? [];
            if (!isset($additionalDocs[$fileIndex])) {
                return $this->json(['success' => false, 'error' => 'File not found at index'], Response::HTTP_NOT_FOUND);
            }
            $storedAs = $additionalDocs[$fileIndex]['stored_as'] ?? '';
            if ($storedAs && is_file($dir . '/' . $storedAs)) {
                unlink($dir . '/' . $storedAs);
            }
            array_splice($entry['files']['additional'], $fileIndex, 1);
        } else {
            return $this->json(['success' => false, 'error' => 'Invalid slot. Use "cv" or "additional"'], Response::HTTP_BAD_REQUEST);
        }

        $entry['updated_at'] = date('c');
        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);

        return $this->json(['success' => true, 'candidate' => $entry]);
    }

    // =========================================================================
    // AI Extraction & Variable Resolution
    // =========================================================================

    #[Route('/candidates/{candidateId}/extract', name: 'candidates_extract', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/templatex/candidates/{candidateId}/extract',
        summary: 'Extract structured data from uploaded CV using AI',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Extraction result')]
    public function candidatesExtract(int $userId, string $candidateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        if (empty($entry['files']['cv'])) {
            return $this->json(['success' => false, 'error' => 'No CV uploaded for this entry'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $storedAs = $entry['files']['cv']['stored_as'] ?? 'cv.pdf';
            $ext = strtolower(pathinfo($storedAs, PATHINFO_EXTENSION));
            $relativePath = $userId . '/templatex/candidates/' . $candidateId . '/' . $storedAs;
            [$rawText, $extractMeta] = $this->fileProcessor->extractText($relativePath, $ext, $userId);
            $rawText = $rawText ?? '';

            if (empty(trim($rawText))) {
                return $this->json(['success' => false, 'error' => 'Could not extract text from CV'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $form = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $entry['form_id'] ?? 'default');
            $formFields = $form['fields'] ?? [];

            $prompt = $this->buildExtractionPrompt($rawText, $formFields);
            $messages = [
                ['role' => 'system', 'content' => 'You are a precise CV data extraction assistant. Return only valid JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ];
            $aiOptions = $this->resolveAiModelOptions($userId);
            $result = $this->aiFacade->chat($messages, $userId, $aiOptions);
            $content = $result['content'] ?? '';

            $extracted = $this->parseJsonFromAiResponse($content);
            if ($extracted === null) {
                return $this->json(['success' => false, 'error' => 'Failed to parse AI extraction result'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $entry['ai_extracted'] = $extracted;
            $entry['status'] = 'extracted';
            $entry['updated_at'] = date('c');
            $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);

            return $this->json([
                'success' => true,
                'extracted' => $extracted,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('CV extraction failed', [
                'error' => $e->getMessage(),
                'candidateId' => $candidateId,
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Extraction failed: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/candidates/{candidateId}/parse-documents', name: 'candidates_parse_documents', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/templatex/candidates/{candidateId}/parse-documents',
        summary: 'Parse uploaded documents with AI to auto-fill form fields',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Parsed field suggestions')]
    public function candidatesParseDocuments(int $userId, string $candidateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $form = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $entry['form_id'] ?? 'default');
        if (!$form) {
            return $this->json(['success' => false, 'error' => 'Form definition not found'], Response::HTTP_NOT_FOUND);
        }

        $candidateDir = $this->uploadDir . '/' . $userId . '/templatex/candidates/' . $candidateId;
        $allTexts = [];

        if (!empty($entry['files']['cv'])) {
            $storedAs = $entry['files']['cv']['stored_as'] ?? 'cv.pdf';
            $ext = strtolower(pathinfo($storedAs, PATHINFO_EXTENSION));
            $relativePath = $userId . '/templatex/candidates/' . $candidateId . '/' . $storedAs;
            try {
                [$text] = $this->fileProcessor->extractText($relativePath, $ext, $userId);
                if (!empty(trim((string) $text))) {
                    $allTexts[] = '=== Primary Document (' . ($entry['files']['cv']['filename'] ?? $storedAs) . ') ===' . "\n" . $text;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Parse-documents: failed to extract CV text', ['error' => $e->getMessage()]);
            }
        }

        foreach ($entry['files']['additional'] ?? [] as $doc) {
            $storedAs = $doc['stored_as'] ?? '';
            if (empty($storedAs) || !is_file($candidateDir . '/' . $storedAs)) {
                continue;
            }
            $ext = strtolower(pathinfo($storedAs, PATHINFO_EXTENSION));
            $relativePath = $userId . '/templatex/candidates/' . $candidateId . '/' . $storedAs;
            try {
                [$text] = $this->fileProcessor->extractText($relativePath, $ext, $userId);
                if (!empty(trim((string) $text))) {
                    $allTexts[] = '=== Document (' . ($doc['filename'] ?? $storedAs) . ') ===' . "\n" . $text;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('Parse-documents: failed to extract doc text', ['error' => $e->getMessage(), 'file' => $storedAs]);
            }
        }

        if (empty($allTexts)) {
            return $this->json(['success' => false, 'error' => 'No documents uploaded or text could not be extracted from any document'], Response::HTTP_BAD_REQUEST);
        }

        $combinedText = implode("\n\n", $allTexts);

        $fields = $form['fields'] ?? [];
        $fieldDescriptions = [];
        foreach ($fields as $field) {
            $type = $field['type'] ?? 'text';
            $desc = $field['key'] . ' (' . $type . '): ' . ($field['label'] ?? $field['key']);
            if (!empty($field['hint'])) {
                $desc .= ' — ' . $field['hint'];
            }
            if (!empty($field['options'])) {
                $desc .= ' [allowed values: ' . implode(', ', $field['options']) . ']';
            }
            if ($type === 'list') {
                $desc .= ' [return as JSON array of strings]';
            }
            if ($type === 'table') {
                $columns = $field['columns'] ?? [];
                $colKeys = array_map(fn ($c) => ($c['key'] ?? '') . ' (' . ($c['label'] ?? $c['key'] ?? '') . ')', $columns);
                $desc .= ' — columns: ' . implode(', ', $colKeys) . '. [return as JSON array of objects with these column keys]';
            }
            $fieldDescriptions[] = $desc;
        }

        $fieldsBlock = implode("\n", array_map(fn ($d) => '- ' . $d, $fieldDescriptions));

        $prompt = <<<PROMPT
        You are an assistant that extracts form field values from documents.
        Below are the form fields that need to be filled, followed by the document text.

        For each field, extract the most appropriate value from the documents. Rules:
        - For "select" fields, ONLY return one of the allowed values listed in brackets, or null if not found.
        - For "list" fields, return a JSON array of strings (one entry per item).
        - For "table" fields, return a JSON array of objects where each object has the column keys listed in the field description. Most recent entries first.
        - For "text" fields, return a plain string value.
        - For "checkbox" fields, return true or false.
        - For "date" fields, return in YYYY-MM-DD format.
        - Return null for any field where no matching information is found. Do NOT guess or invent data.
        - If a document is an interview transcript, extract answers to questions that match the field descriptions.

        Form fields:
        {$fieldsBlock}

        Documents:
        ---
        {$combinedText}
        ---

        Return ONLY a valid JSON object where keys are the field keys and values are the extracted data.
        PROMPT;

        try {
            $messages = [
                ['role' => 'system', 'content' => 'You are a precise document parsing assistant. Return only valid JSON.'],
                ['role' => 'user', 'content' => $prompt],
            ];
            $aiOptions = $this->resolveAiModelOptions($userId);
            $result = $this->aiFacade->chat($messages, $userId, $aiOptions);
            $content = $result['content'] ?? '';

            $parsed = $this->parseJsonFromAiResponse($content);
            if ($parsed === null) {
                return $this->json(['success' => false, 'error' => 'Failed to parse AI response'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return $this->json([
                'success' => true,
                'suggestions' => $parsed,
                'documents_parsed' => count($allTexts),
                'model' => $result['model'] ?? 'unknown',
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Parse-documents failed', ['error' => $e->getMessage(), 'candidateId' => $candidateId]);
            return $this->json(['success' => false, 'error' => 'Document parsing failed: ' . $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/candidates/{candidateId}/variables', name: 'candidates_variables_get', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/templatex/candidates/{candidateId}/variables',
        summary: 'Get resolved variables for an entry',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Resolved variables')]
    public function candidatesVariablesGet(int $userId, string $candidateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $form = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $entry['form_id'] ?? 'default');
        $formFields = $form['fields'] ?? [];
        $resolved = $this->resolveVariables($entry, $formFields);

        $tableFieldMeta = $this->getTableFieldMeta($formFields);

        return $this->json([
            'success' => true,
            'variables' => $resolved['variables'],
            'table_fields' => $tableFieldMeta,
            'sources' => $this->getVariableSources($formFields),
        ]);
    }

    #[Route('/candidates/{candidateId}/variables', name: 'candidates_variables_update', methods: ['PUT'])]
    #[OA\Put(
        path: '/api/v1/user/{userId}/plugins/templatex/candidates/{candidateId}/variables',
        summary: 'Update variable overrides for an entry',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Updated variables')]
    public function candidatesVariablesUpdate(int $userId, string $candidateId, Request $request, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true) ?? [];
        $entry['variable_overrides'] = $data['overrides'] ?? $data;
        $entry['updated_at'] = date('c');
        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);

        $form = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $entry['form_id'] ?? 'default');
        $formFields = $form['fields'] ?? [];
        $resolved = $this->resolveVariables($entry, $formFields);

        return $this->json([
            'success' => true,
            'variables' => $resolved['variables'],
            'table_fields' => $this->getTableFieldMeta($formFields),
        ]);
    }

    // =========================================================================
    // Document Generation
    // =========================================================================

    #[Route('/candidates/{candidateId}/generate/{templateId}', name: 'candidates_generate', methods: ['POST'])]
    #[OA\Post(
        path: '/api/v1/user/{userId}/plugins/templatex/candidates/{candidateId}/generate/{templateId}',
        summary: 'Generate a DOCX document from template and resolved variables',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Generated document metadata')]
    public function candidatesGenerate(int $userId, string $candidateId, string $templateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $template = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_TEMPLATE, $templateId);
        if (!$template) {
            return $this->json(['success' => false, 'error' => 'Template not found'], Response::HTTP_NOT_FOUND);
        }

        $templatePath = $this->uploadDir . '/' . $userId . '/templatex/templates/' . $templateId . '/template.docx';
        if (!is_file($templatePath)) {
            return $this->json(['success' => false, 'error' => 'Template file not found on disk'], Response::HTTP_NOT_FOUND);
        }

        try {
            $form = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, $entry['form_id'] ?? 'default');
            $formFields = $form['fields'] ?? [];
            $resolved = $this->resolveVariables($entry, $formFields);
            $variables = $resolved['variables'];

            $arrays = $this->collectArrayData($entry, $formFields);

            $cleanedPath = $this->cleanTemplateMacros($templatePath);

            $tp = new TemplateProcessor($cleanedPath);
            $tp->setMacroOpeningChars('{{');
            $tp->setMacroClosingChars('}}');

            $templatePlaceholders = $tp->getVariables();
            $classified = $this->classifyTemplatePlaceholders($templatePlaceholders, $variables, $arrays);

            $this->processRowGroups($tp, $classified['rowGroups'], $arrays);
            $this->processBlockGroups($tp, $classified['blockGroups'], $arrays);
            $this->processCheckboxes($tp, $classified['checkboxes'], $variables);
            $this->processLists($tp, $classified['lists'], $variables);
            $this->processScalars($tp, $classified['scalars'], $variables);

            $docId = 'doc_' . bin2hex(random_bytes(6));
            $genDir = $this->uploadDir . '/' . $userId . '/templatex/candidates/' . $candidateId . '/generated';
            if (!is_dir($genDir)) {
                mkdir($genDir, 0755, true);
            }
            $outputPath = $genDir . '/' . $docId . '.docx';
            $tp->saveAs($outputPath);

            if (is_file($cleanedPath)) {
                unlink($cleanedPath);
            }

            $docMeta = [
                'id' => $docId,
                'template_id' => $templateId,
                'template_name' => $template['name'] ?? $templateId,
                'filename' => $docId . '.docx',
                'generated_at' => date('c'),
                'variable_snapshot' => $variables,
            ];

            if (!isset($entry['documents'])) {
                $entry['documents'] = [];
            }
            $entry['documents'][$docId] = $docMeta;
            $entry['status'] = 'generated';
            $entry['updated_at'] = date('c');
            $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);

            return $this->json([
                'success' => true,
                'document' => $docMeta,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Document generation failed', [
                'error' => $e->getMessage(),
                'candidateId' => $candidateId,
            ]);

            return $this->json([
                'success' => false,
                'error' => 'Generation failed: ' . $e->getMessage(),
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/candidates/{candidateId}/documents', name: 'candidates_documents_list', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/templatex/candidates/{candidateId}/documents',
        summary: 'List generated documents for an entry',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'List of generated documents')]
    public function candidatesDocumentsList(int $userId, string $candidateId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'success' => true,
            'documents' => array_values($entry['documents'] ?? []),
        ]);
    }

    #[Route('/candidates/{candidateId}/documents/{documentId}/download', name: 'candidates_documents_download', methods: ['GET'])]
    #[OA\Get(
        path: '/api/v1/user/{userId}/plugins/templatex/candidates/{candidateId}/documents/{documentId}/download',
        summary: 'Download a generated document',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Generated DOCX file')]
    public function candidatesDocumentDownload(int $userId, string $candidateId, string $documentId, #[CurrentUser] ?User $user): Response
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $docMeta = $entry['documents'][$documentId] ?? null;
        if (!$docMeta) {
            return $this->json(['success' => false, 'error' => 'Document not found'], Response::HTTP_NOT_FOUND);
        }

        $filePath = $this->uploadDir . '/' . $userId . '/templatex/candidates/' . $candidateId . '/generated/' . $docMeta['filename'];
        if (!is_file($filePath)) {
            return $this->json(['success' => false, 'error' => 'Document file not found on disk'], Response::HTTP_NOT_FOUND);
        }

        $downloadName = ($entry['name'] ?? 'document') . '_' . ($docMeta['template_name'] ?? 'template') . '.docx';
        $downloadName = preg_replace('/[^a-zA-Z0-9._-]/', '_', $downloadName);
        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $downloadName);

        return $response;
    }

    #[Route('/candidates/{candidateId}/documents/{documentId}', name: 'candidates_documents_delete', methods: ['DELETE'])]
    #[OA\Delete(
        path: '/api/v1/user/{userId}/plugins/templatex/candidates/{candidateId}/documents/{documentId}',
        summary: 'Delete a generated document',
        security: [['ApiKey' => []]],
        tags: ['TemplateX Plugin']
    )]
    #[OA\Response(response: 200, description: 'Document deleted')]
    public function candidatesDocumentDelete(int $userId, string $candidateId, string $documentId, #[CurrentUser] ?User $user): JsonResponse
    {
        if (!$this->canAccessPlugin($user, $userId)) {
            return $this->json(['success' => false, 'error' => 'Unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $entry = $this->pluginData->get($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId);
        if (!$entry) {
            return $this->json(['success' => false, 'error' => 'Entry not found'], Response::HTTP_NOT_FOUND);
        }

        $docMeta = $entry['documents'][$documentId] ?? null;
        if (!$docMeta) {
            return $this->json(['success' => false, 'error' => 'Document not found'], Response::HTTP_NOT_FOUND);
        }

        $filePath = $this->uploadDir . '/' . $userId . '/templatex/candidates/' . $candidateId . '/generated/' . $docMeta['filename'];
        if (is_file($filePath)) {
            unlink($filePath);
        }

        unset($entry['documents'][$documentId]);
        $entry['updated_at'] = date('c');
        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_CANDIDATE, $candidateId, $entry);

        return $this->json(['success' => true, 'message' => 'Document deleted']);
    }

    // =========================================================================
    // Assets (frontend)
    // =========================================================================

    #[Route('/assets/{path}', name: 'assets', methods: ['GET'], requirements: ['path' => '.+'])]
    public function assets(string $path): Response
    {
        $pluginDir = dirname(__DIR__, 2);
        $file = $pluginDir . '/frontend/' . $path;

        if (!is_file($file) || !str_starts_with(realpath($file), realpath($pluginDir . '/frontend'))) {
            return new Response('Not found', Response::HTTP_NOT_FOUND);
        }

        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $mimeTypes = [
            'js' => 'application/javascript',
            'css' => 'text/css',
            'html' => 'text/html',
            'json' => 'application/json',
            'svg' => 'image/svg+xml',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
        ];

        return new Response(
            file_get_contents($file),
            Response::HTTP_OK,
            ['Content-Type' => $mimeTypes[$ext] ?? 'application/octet-stream']
        );
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function canAccessPlugin(?User $user, int $userId): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->getId() !== $userId) {
            return false;
        }

        return $this->configRepository->getValue($userId, self::CONFIG_GROUP, 'enabled') === '1';
    }

    /** @return array<string, string> */
    private function getPluginConfig(int $userId): array
    {
        $defaults = [
            'default_language' => 'de',
            'company_name' => '',
            'extraction_model' => 'default',
            'validation_model' => 'default',
            'default_template_id' => '',
        ];

        $config = [];
        foreach ($defaults as $key => $default) {
            $config[$key] = $this->configRepository->getValue($userId, self::CONFIG_GROUP, $key) ?? $default;
        }

        return $config;
    }

    /**
     * Extract {{...}} placeholders from a DOCX file.
     *
     * Word often splits placeholder text across multiple XML runs (<w:r>),
     * so we concatenate all run text within each paragraph before matching.
     *
     * @return list<array{key: string, type: string}>
     */
    private function extractPlaceholders(string $docxPath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($docxPath) !== true) {
            $this->logger->warning('Failed to open DOCX for placeholder extraction', ['path' => $docxPath]);
            return [];
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            return [];
        }

        // Strip namespace prefixes from tags so DOMDocument can find elements by local name
        $xml = preg_replace('/<(\/?)(?:w|mc|r|wp|wps|a|v|o):/', '<$1', $xml);
        $xml = preg_replace('/\s+xmlns[^=]*="[^"]*"/', '', $xml);

        $doc = new \DOMDocument();
        @$doc->loadXML($xml);

        $paragraphs = $doc->getElementsByTagName('p');
        $found = [];

        foreach ($paragraphs as $paragraph) {
            $text = '';
            $runs = $paragraph->getElementsByTagName('r');
            foreach ($runs as $run) {
                $tNodes = $run->getElementsByTagName('t');
                foreach ($tNodes as $t) {
                    $text .= $t->textContent;
                }
            }

            if (preg_match_all('/\{\{([^}]+)\}\}/', $text, $matches)) {
                foreach ($matches[1] as $key) {
                    $key = trim($key);
                    $found[$key] = true;
                }
            }
        }

        $placeholders = [];
        foreach (array_keys($found) as $key) {
            $placeholders[] = [
                'key' => $key,
                'type' => $this->classifyPlaceholder($key),
            ];
        }

        return $placeholders;
    }

    private function classifyPlaceholder(string $key): string
    {
        if (str_starts_with($key, '#') || str_starts_with($key, '/')) {
            return 'block_marker';
        }
        if (str_contains($key, '.') && !str_starts_with($key, 'checkb') && !str_starts_with($key, 'optional.')) {
            return 'row_field';
        }
        if (str_starts_with($key, 'checkb.')) {
            return 'checkbox';
        }
        if (str_ends_with($key, 'list')) {
            return 'list';
        }

        return 'text';
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }

        rmdir($dir);
    }

    private function seedDefaultForm(int $userId): void
    {
        $defaultForm = [
            'id' => 'default',
            'name' => 'Standard Kandidatenprofil',
            'language' => 'de',
            'version' => 1,
            'fields' => [
                ['key' => 'firstname', 'label' => 'Vorname', 'type' => 'text', 'required' => true, 'source' => 'form'],
                ['key' => 'lastname', 'label' => 'Nachname', 'type' => 'text', 'required' => true, 'source' => 'form'],
                ['key' => 'target-position', 'label' => 'Vorgestellte Position', 'type' => 'text', 'required' => true, 'source' => 'form'],
                ['key' => 'nationality', 'label' => 'Nationalität', 'type' => 'text', 'required' => false, 'source' => 'form'],
                ['key' => 'maritalstatus', 'label' => 'Familienstand', 'type' => 'select', 'options' => ['ledig', 'verheiratet', 'geschieden', 'verwitwet'], 'required' => false, 'source' => 'form'],
                ['key' => 'relevantposlist', 'label' => 'Relevante vorherige Positionen', 'type' => 'list', 'required' => false, 'source' => 'form', 'hint' => 'Eine Position pro Zeile'],
                ['key' => 'relevantfortargetposlist', 'label' => 'Relevante Berufserfahrung für Position', 'type' => 'list', 'required' => false, 'source' => 'form', 'hint' => 'z.B. Direct Reports, Mitarbeiteranzahl'],
                ['key' => 'moving', 'label' => 'Umzugsbereitschaft', 'type' => 'select', 'options' => ['Ja', 'Nein'], 'required' => false, 'source' => 'form'],
                ['key' => 'commute', 'label' => 'Pendelbereitschaft', 'type' => 'select', 'options' => ['Ja', 'Nein'], 'required' => false, 'source' => 'form'],
                ['key' => 'travel', 'label' => 'Reisebereitschaft', 'type' => 'select', 'options' => ['Ja', 'Nein'], 'required' => false, 'source' => 'form'],
                ['key' => 'noticeperiod', 'label' => 'Kündigungsfrist', 'type' => 'text', 'required' => false, 'source' => 'form'],
                ['key' => 'currentansalary', 'label' => 'Aktuelles Bruttojahresgehalt', 'type' => 'text', 'required' => false, 'source' => 'form'],
                ['key' => 'expectedansalary', 'label' => 'Erwartetes Bruttojahresgehalt', 'type' => 'text', 'required' => false, 'source' => 'form', 'hint' => "'nicht relevant' zum Weglassen"],
                ['key' => 'workinghours', 'label' => 'Vertragliche Arbeitszeit', 'type' => 'text', 'required' => false, 'source' => 'form', 'hint' => "'nicht relevant' zum Weglassen"],
                ['key' => 'benefits', 'label' => 'Sonstige Leistungen', 'type' => 'list', 'required' => false, 'source' => 'form', 'hint' => 'z.B. Firmenwagen, Bonus'],
                ['key' => 'languageslist', 'label' => 'Sprachkenntnisse', 'type' => 'list', 'required' => false, 'source' => 'form', 'hint' => 'z.B. Deutsch (Muttersprache)'],
                ['key' => 'otherskillslist', 'label' => 'Sonstige Kenntnisse', 'type' => 'list', 'required' => false, 'source' => 'form', 'hint' => 'z.B. SAP, MS Office'],
            ],
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];

        $this->pluginData->set($userId, self::PLUGIN_NAME, self::DATA_TYPE_FORM, 'default', $defaultForm);
    }

    private function buildExtractionPrompt(string $rawText, array $formFields = []): string
    {
        $fieldLines = [];

        $defaultScalars = [
            'firstname' => 'First name / given name',
            'lastname' => 'Last name / family name / surname',
            'fullname' => 'Full name (firstname + lastname combined)',
            'address1' => 'Street and house number',
            'address2' => 'City',
            'zip' => 'Postal code',
            'birthdate' => 'Date of birth (DD.MM.YYYY format)',
            'number' => 'Phone number',
            'email' => 'Email address',
            'currentposition' => 'Current/most recent job title',
            'education' => 'Education and degrees',
            'languageslist' => '(array of strings): Language skills',
            'otherskillslist' => '(array of strings): Other skills (IT, tools)',
        ];

        $coveredKeys = [];

        foreach ($formFields as $field) {
            $key = $field['key'] ?? '';
            if ($key === '') {
                continue;
            }
            $coveredKeys[$key] = true;
            $label = $field['label'] ?? $key;
            $hint = !empty($field['hint']) ? ' — ' . $field['hint'] : '';

            if (($field['type'] ?? 'text') === 'table') {
                $columns = $field['columns'] ?? [];
                if (empty($columns)) {
                    continue;
                }
                $colDescs = [];
                foreach ($columns as $col) {
                    $colDescs[] = ($col['key'] ?? '') . ' (string): ' . ($col['label'] ?? $col['key'] ?? '');
                }
                $colBlock = implode("\n              ", $colDescs);
                $fieldLines[] = "- {$key} (array of objects): {$label}{$hint}. Most recent first. Each entry:\n              {$colBlock}";
            } elseif (($field['type'] ?? 'text') === 'list') {
                $fieldLines[] = "- {$key} (array of strings): {$label}{$hint}";
            } else {
                $fieldLines[] = "- {$key} (string): {$label}{$hint}";
            }
        }

        foreach ($defaultScalars as $key => $desc) {
            if (!isset($coveredKeys[$key])) {
                $fieldLines[] = "- {$key} {$desc}";
            }
        }

        $fieldsBlock = implode("\n            ", $fieldLines);

        return <<<PROMPT
            You are extracting structured data from a CV/resume document. Return a JSON object with these fields. Use null for any field not found in the document. Do NOT invent or guess data.

            Fields to extract:
            {$fieldsBlock}

            Return ONLY valid JSON, no explanation.

            CV Text:
            ---
            {$rawText}
            ---
            PROMPT;
    }

    private function parseJsonFromAiResponse(string $content): ?array
    {
        $decoded = json_decode($content, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/```(?:json)?\s*\n?(.*?)\n?\s*```/s', $content, $match)) {
            $decoded = json_decode($match[1], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        if (preg_match('/\{[\s\S]*\}/', $content, $match)) {
            $decoded = json_decode($match[0], true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function buildImportParsePrompt(string $text): string
    {
        return <<<PROMPT
        You are parsing a variable definition table for a document template system.
        The input is pasted text (likely from a Confluence page or Word document) that describes template placeholders.

        Each variable has a placeholder like {{key}} and a description of where the data comes from and what type it is.

        Parse this into a JSON array of field objects. Each field object must have:
        - "key" (string): the placeholder name WITHOUT curly braces, lowercase, using hyphens for compound names
        - "label" (string): a human-readable German label for the field
        - "type" (string): one of "text", "textarea", "select", "list", "date", "checkbox"
        - "required" (boolean): true if the field seems mandatory
        - "source" (string): "form" if data comes from a questionnaire/form, "ai" if extracted from CV/documents
        - "fallback" (string|null): secondary source if primary is empty. "form" or "ai" or null
        - "hint" (string|null): any special instructions (e.g. "leave empty if not relevant")
        - "options" (array|null): for "select" type, the list of allowed values

        Rules for determining type:
        - If description says "als Liste verwaltet" or "Ein oder mehr Einträge" -> type "list"
        - If the value is "Ja oder Nein" or "Ja/Nein" -> type "select" with options ["Ja", "Nein"]
        - If it contains dates -> type "text" (we use text for dates)
        - If it needs multi-line content (like career details) -> type "textarea"
        - Default -> type "text"

        Rules for determining source:
        - "aus dem Lebenslauf extrahiert" or "muss aus dem Lebenslauf" -> source "ai"
        - "kommt aus dem Formular" or "aus dem vorbereiteten Formular" -> source "form"
        - "kommt aus dem Formular, Lebenslauf-Extraktion als Fallback" -> source "form", fallback "ai"
        - "aus dem Lebenslauf, Formular als Fallback" or "wenn nicht, schaue in das Formular" -> source "ai", fallback "form"
        - If both form and CV are mentioned, the first one mentioned is primary

        IMPORTANT skip rules:
        - SKIP any {{checkb.*}} placeholders (checkbox derivatives are auto-generated)
        - SKIP any {{groupname.field}} placeholders where groupname.field represents table row data (these are handled separately as table fields)
        - SKIP any {{#blockname}} / {{/blockname}} block markers

        Special handling:
        - If description says "Weglassen wenn nicht relevant" or similar -> add hint "Leave empty if not relevant"
        - For fields with "nicht relevant" logic -> add hint explaining the conditional

        Return ONLY a valid JSON array of field objects. No explanation, no markdown.

        Input text:
        ---
        {$text}
        ---
        PROMPT;
    }

    private function extractTextFromDocx(string $path): ?string
    {
        try {
            $phpWord = PhpWordIOFactory::load($path);
            $text = '';
            foreach ($phpWord->getSections() as $section) {
                foreach ($section->getElements() as $element) {
                    $text .= $this->extractElementText($element) . "\n";
                }
            }

            return trim($text) !== '' ? $text : null;
        } catch (\Throwable $e) {
            $this->logger->warning('DOCX text extraction failed: ' . $e->getMessage());

            return null;
        }
    }

    private function extractElementText(mixed $element): string
    {
        if (method_exists($element, 'getText')) {
            $t = $element->getText();

            return is_string($t) ? $t : '';
        }

        if (method_exists($element, 'getElements')) {
            $parts = [];
            foreach ($element->getElements() as $child) {
                $parts[] = $this->extractElementText($child);
            }

            return implode(' ', array_filter($parts));
        }

        if (method_exists($element, 'getRows')) {
            $rows = [];
            foreach ($element->getRows() as $row) {
                $cells = [];
                foreach ($row->getCells() as $cell) {
                    $cellParts = [];
                    foreach ($cell->getElements() as $cellElement) {
                        $cellParts[] = $this->extractElementText($cellElement);
                    }
                    $cells[] = implode(' ', array_filter($cellParts));
                }
                $rows[] = implode("\t", $cells);
            }

            return implode("\n", $rows);
        }

        return '';
    }

    private function normalizeFieldType(string $type): string
    {
        $valid = ['text', 'textarea', 'select', 'list', 'date', 'number', 'checkbox', 'table'];

        return in_array($type, $valid, true) ? $type : 'text';
    }

    private function getTableFieldMeta(array $formFields): object
    {
        $meta = [];
        foreach ($formFields as $field) {
            if (($field['type'] ?? '') === 'table' && !empty($field['key'])) {
                $meta[$field['key']] = [
                    'label' => $field['label'] ?? $field['key'],
                    'columns' => $field['columns'] ?? [],
                ];
            }
        }

        return (object) $meta;
    }

    private function normalizeSource(?string $source): ?string
    {
        if ($source === null || $source === '') {
            return null;
        }
        $valid = ['form', 'ai'];

        return in_array($source, $valid, true) ? $source : 'form';
    }

    /**
     * Build variable source map from form fields, falling back to hardcoded defaults.
     *
     * @param array $formFields The form's fields[] array, each with optional 'source' and 'fallback'
     * @return array<string, array{primary: string, fallback?: string}>
     */
    private function getVariableSources(array $formFields = []): array
    {
        $sources = [];
        foreach ($formFields as $field) {
            $key = $field['key'] ?? null;
            if ($key === null) {
                continue;
            }
            $primary = $field['source'] ?? 'form';
            $sources[$key] = ['primary' => $primary];
            $fallback = $field['fallback'] ?? null;
            if ($fallback !== null && $fallback !== '') {
                $sources[$key]['fallback'] = $fallback;
            }
        }

        foreach (self::DEFAULT_VARIABLE_SOURCES as $key => $config) {
            if (!isset($sources[$key])) {
                $sources[$key] = $config;
            }
        }

        return $sources;
    }

    /** @return array{variables: array<string, mixed>} */
    private function resolveVariables(array $entry, ?array $formFields = null): array
    {
        $formData = $entry['field_values'] ?? [];
        $aiData = $entry['ai_extracted'] ?? [];
        $overrides = $entry['variable_overrides'] ?? [];
        $sources = $this->getVariableSources($formFields ?? []);

        $variables = [];

        foreach ($sources as $key => $config) {
            if (array_key_exists($key, $overrides) && $overrides[$key] !== null) {
                $variables[$key] = $overrides[$key];
                continue;
            }

            $primarySource = $config['primary'];
            $fallbackSource = $config['fallback'] ?? null;
            $value = null;

            if ($primarySource === 'ai') {
                $value = $aiData[$key] ?? null;
            } elseif ($primarySource === 'form') {
                $value = $formData[$key] ?? null;
            }

            if ($value === null && $fallbackSource !== null) {
                if ($fallbackSource === 'ai') {
                    $value = $aiData[$key] ?? null;
                } elseif ($fallbackSource === 'form') {
                    $value = $formData[$key] ?? null;
                }
            }

            $variables[$key] = $value;
        }

        if (isset($variables['expectedansalary']) && strtolower((string) $variables['expectedansalary']) === 'nicht relevant') {
            $variables['expectedansalary'] = null;
        }
        if (isset($variables['workinghours']) && strtolower((string) $variables['workinghours']) === 'nicht relevant') {
            $variables['workinghours'] = null;
        }

        // Auto-generate checkbox variables from form fields with type=checkbox
        $checkboxKeys = [];
        foreach (($formFields ?? []) as $field) {
            if (($field['type'] ?? '') === 'checkbox' && !empty($field['key'])) {
                $checkboxKeys[] = $field['key'];
            }
        }
        // Backward compat: always include these three if present
        foreach (['moving', 'commute', 'travel'] as $legacyKey) {
            if (isset($variables[$legacyKey]) && !in_array($legacyKey, $checkboxKeys, true)) {
                $checkboxKeys[] = $legacyKey;
            }
        }
        foreach ($checkboxKeys as $cbKey) {
            $cbYes = strtolower((string) ($variables[$cbKey] ?? '')) === 'ja'
                || ($variables[$cbKey] === true);
            $variables['checkb.' . $cbKey . '.yes'] = $cbYes;
            $variables['checkb.' . $cbKey . '.no'] = !$cbYes;
        }

        // Backward compat: travelorcommute → commute/travel checkboxes
        if (!isset($variables['commute']) && isset($variables['travelorcommute'])) {
            $commuteVal = $formData['commute'] ?? $variables['travelorcommute'] ?? '';
            $commuteYes = strtolower((string) $commuteVal) === 'ja';
            $variables['checkb.commute.yes'] = $commuteYes;
            $variables['checkb.commute.no'] = !$commuteYes;
        }
        if (!isset($variables['travel']) && isset($variables['travelorcommute'])) {
            $travelVal = $formData['travel'] ?? $variables['travelorcommute'] ?? '';
            $travelYes = strtolower((string) $travelVal) === 'ja';
            $variables['checkb.travel.yes'] = $travelYes;
            $variables['checkb.travel.no'] = !$travelYes;
        }

        return [
            'variables' => $variables,
        ];
    }

    private function resolveAiModelOptions(int $userId): array
    {
        $modelId = $this->modelConfigService->getDefaultModel('CHAT', $userId);
        if ($modelId) {
            return [
                'model' => $this->modelConfigService->getModelName($modelId),
                'provider' => $this->modelConfigService->getProviderForModel($modelId),
            ];
        }

        $modelId = $this->modelConfigService->getDefaultModel('CHAT', 0);
        if ($modelId) {
            return [
                'model' => $this->modelConfigService->getModelName($modelId),
                'provider' => $this->modelConfigService->getProviderForModel($modelId),
            ];
        }

        return [];
    }

    /**
     * Collect array/repeating-group data from the entry.
     * Each key maps to an array of associative arrays (rows) or flat string arrays (lists).
     *
     * @return array<string, array<int, array<string, string>|string>>
     */
    private function collectArrayData(array $entry, array $formFields): array
    {
        $arrays = [];
        $formData = $entry['field_values'] ?? [];
        $aiData = $entry['ai_extracted'] ?? [];
        $overrides = $entry['variable_overrides'] ?? [];

        $scannedKeys = [];
        foreach ($formFields as $field) {
            $key = $field['key'] ?? '';
            $type = $field['type'] ?? 'text';
            if ($key === '' || ($type !== 'table' && $type !== 'list')) {
                continue;
            }
            $scannedKeys[$key] = true;
            $primarySource = $field['source'] ?? 'form';
            $fallbackSource = $field['fallback'] ?? null;

            $val = $overrides[$key] ?? null;
            if ($val === null) {
                $val = $primarySource === 'ai' ? ($aiData[$key] ?? null) : ($formData[$key] ?? null);
            }
            if ($val === null && $fallbackSource !== null) {
                $val = $fallbackSource === 'ai' ? ($aiData[$key] ?? null) : ($formData[$key] ?? null);
            }
            if (is_array($val) && !empty($val)) {
                $arrays[$key] = $val;
            }
        }

        // Backward compat: pick up hardcoded list keys from DEFAULT_VARIABLE_SOURCES
        $legacyListKeys = ['relevantposlist', 'relevantfortargetposlist', 'languageslist', 'otherskillslist', 'benefits'];
        foreach ($legacyListKeys as $key) {
            if (isset($scannedKeys[$key])) {
                continue;
            }
            $val = $overrides[$key] ?? $formData[$key] ?? $aiData[$key] ?? null;
            if (is_array($val) && !empty($val)) {
                $arrays[$key] = $val;
            }
        }

        // Backward compat: pick up legacy 'stations' from ai_extracted if not covered by a form table field
        if (!isset($scannedKeys['stations']) && !isset($arrays['stations'])) {
            $stations = $aiData['stations'] ?? [];
            if (array_key_exists('stations', $overrides) && is_array($overrides['stations'])) {
                $stations = $overrides['stations'];
            }
            if (!empty($stations)) {
                $arrays['stations'] = $stations;
            }
        }

        return $arrays;
    }

    /**
     * Classify template placeholders into rendering modes by inspecting patterns.
     *
     * - ROW groups: {{groupname.field}} where groupname is a known array of objects
     * - BLOCK groups: {{#groupname}} / {{/groupname}} bracket pairs
     * - Checkboxes: {{checkb.key.yes}} / {{checkb.key.no}}
     * - Lists: placeholder whose resolved value is an array (flat list of strings)
     * - Scalars: everything else
     *
     * @return array{rowGroups: array<string, list<string>>, blockGroups: list<string>, checkboxes: array<string, list<string>>, lists: list<string>, scalars: list<string>}
     */
    private function classifyTemplatePlaceholders(array $placeholders, array $variables, array $arrays): array
    {
        $rowGroups = [];
        $blockGroupNames = [];
        $checkboxes = [];
        $lists = [];
        $scalars = [];

        $arrayObjectKeys = [];
        foreach ($arrays as $name => $data) {
            if (!empty($data) && is_array($data[0] ?? null)) {
                $arrayObjectKeys[$name] = true;
            }
        }

        foreach ($placeholders as $ph) {
            if (str_starts_with($ph, '#') || str_starts_with($ph, '/')) {
                $blockGroupNames[trim($ph, '#/')] = true;
                continue;
            }

            if (str_starts_with($ph, 'checkb.')) {
                $parts = explode('.', $ph);
                $cbKey = $parts[1] ?? '';
                if ($cbKey !== '') {
                    $checkboxes[$cbKey][] = $ph;
                }
                continue;
            }

            if (str_contains($ph, '.')) {
                $prefix = explode('.', $ph)[0];
                if (isset($arrayObjectKeys[$prefix])) {
                    $rowGroups[$prefix][] = $ph;
                    continue;
                }
            }

            $val = $variables[$ph] ?? null;
            if (is_array($val) || isset($arrays[$ph])) {
                $lists[] = $ph;
                continue;
            }

            $scalars[] = $ph;
        }

        return [
            'rowGroups' => $rowGroups,
            'blockGroups' => array_keys($blockGroupNames),
            'checkboxes' => $checkboxes,
            'lists' => $lists,
            'scalars' => $scalars,
        ];
    }

    /**
     * ROW mode: clone table rows for repeating groups like stations.
     * Template has {{stations.employer}}, {{stations.time}}, etc. in a table row.
     * PhpWord cloneRow duplicates the row, suffixing #1, #2, etc.
     */
    private function processRowGroups(TemplateProcessor $tp, array $rowGroups, array $arrays): void
    {
        foreach ($rowGroups as $groupName => $fields) {
            $data = $arrays[$groupName] ?? [];
            $count = count($data);
            if ($count === 0) {
                foreach ($fields as $field) {
                    $tp->setValue($field, '');
                }
                continue;
            }

            $anchorField = $fields[0] ?? null;
            if ($anchorField === null) {
                continue;
            }

            try {
                $tp->cloneRow($anchorField, $count);
            } catch (\Throwable $e) {
                $this->logger->warning('cloneRow failed, falling back to setValue', [
                    'group' => $groupName,
                    'anchor' => $anchorField,
                    'error' => $e->getMessage(),
                ]);
                foreach ($fields as $field) {
                    $tp->setValue($field, '');
                }
                continue;
            }

            $uniqueFieldSuffixes = [];
            foreach ($fields as $field) {
                $suffix = substr($field, strlen($groupName) + 1);
                $uniqueFieldSuffixes[$suffix] = true;
            }

            for ($i = 0; $i < $count; $i++) {
                $num = $i + 1;
                $row = $data[$i] ?? [];
                foreach (array_keys($uniqueFieldSuffixes) as $suffix) {
                    $cleanSuffix = str_replace('.N', '', $suffix);
                    $value = $row[$cleanSuffix] ?? '';
                    $value = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    if (str_contains($value, "\n")) {
                        $value = str_replace("\n", '</w:t><w:br/><w:t>', $value);
                    }
                    $phName = "{$groupName}.{$suffix}";
                    $tp->setValue($phName . '#' . $num, $value);
                }
            }
        }
    }

    /**
     * BLOCK mode: clone everything between {{#name}} and {{/name}} markers.
     * Fills inner placeholders per iteration.
     */
    private function processBlockGroups(TemplateProcessor $tp, array $blockGroupNames, array $arrays): void
    {
        foreach ($blockGroupNames as $groupName) {
            $data = $arrays[$groupName] ?? [];
            $count = count($data);
            if ($count === 0) {
                try {
                    $tp->cloneBlock($groupName, 0, true, true);
                } catch (\Throwable) {
                }
                continue;
            }

            try {
                $tp->cloneBlock($groupName, $count, true, true);
            } catch (\Throwable $e) {
                $this->logger->warning('cloneBlock failed', [
                    'group' => $groupName,
                    'error' => $e->getMessage(),
                ]);
                continue;
            }

            for ($i = 0; $i < $count; $i++) {
                $num = $i + 1;
                $row = is_array($data[$i]) ? $data[$i] : ['value' => (string) $data[$i]];
                foreach ($row as $field => $value) {
                    $value = (string) ($value ?? '');
                    $value = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    if (str_contains($value, "\n")) {
                        $value = str_replace("\n", '</w:t><w:br/><w:t>', $value);
                    }
                    $tp->setValue("{$groupName}.{$field}#{$num}", $value);
                }
            }
        }
    }

    /**
     * Checkbox mode: detect all checkb.KEY.yes / checkb.KEY.no pairs.
     * Checks or unchecks Word checkbox content controls.
     */
    private function processCheckboxes(TemplateProcessor $tp, array $checkboxes, array $variables): void
    {
        foreach ($checkboxes as $cbKey => $fields) {
            $yesVal = (bool) ($variables["checkb.{$cbKey}.yes"] ?? false);
            foreach ($fields as $ph) {
                $isYes = str_ends_with($ph, '.yes');
                try {
                    $tp->setCheckbox($ph, $isYes ? $yesVal : !$yesVal);
                } catch (\Throwable) {
                    $tp->setValue($ph, ($isYes ? $yesVal : !$yesVal) ? '☑' : '☐');
                }
            }
        }
    }

    /**
     * LIST mode: array values rendered as newline-separated text with OOXML line breaks.
     */
    private function processLists(TemplateProcessor $tp, array $listKeys, array $variables): void
    {
        foreach ($listKeys as $key) {
            $val = $variables[$key] ?? null;
            $text = is_array($val) ? implode("\n", array_map('strval', $val)) : (string) ($val ?? '');
            $text = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $text = str_replace("\n", '</w:t><w:br/><w:t>', $text);
            $tp->setValue($key, $text);
        }
    }

    /**
     * Scalar mode: simple text replacement for all remaining placeholders.
     */
    private function processScalars(TemplateProcessor $tp, array $scalarKeys, array $variables): void
    {
        foreach ($scalarKeys as $key) {
            $value = $variables[$key] ?? null;
            $value = htmlspecialchars((string) ($value ?? ''), ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $tp->setValue($key, $value);
        }
    }

    private function cleanTemplateMacros(string $docxPath): string
    {
        $cleanedPath = dirname($docxPath) . '/template_cleaned.docx';
        copy($docxPath, $cleanedPath);

        $zip = new \ZipArchive();
        if ($zip->open($cleanedPath) !== true) {
            return $cleanedPath;
        }

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            $zip->close();
            return $cleanedPath;
        }

        $xml = preg_replace('/\{(<[^>]*>)*\{/', '{{', $xml);
        $xml = preg_replace('/\}(<[^>]*>)*\}/', '}}', $xml);

        $xml = preg_replace_callback('/\{\{(.*?)\}\}/s', function ($match) {
            $inner = strip_tags($match[1]);
            $inner = preg_replace('/\s+/', '', $inner);
            return '{{' . trim($inner) . '}}';
        }, $xml);

        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        return $cleanedPath;
    }
}
