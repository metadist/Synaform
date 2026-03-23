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
        summary: 'Upload primary document (PDF)',
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
        if ($ext !== 'pdf') {
            return $this->json(['success' => false, 'error' => 'Only PDF files are allowed for CV upload'], Response::HTTP_BAD_REQUEST);
        }

        $dir = $this->uploadDir . '/' . $userId . '/templatex/candidates/' . $candidateId;
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $file->move($dir, 'cv.pdf');

        $existing['files']['cv'] = [
            'filename' => $file->getClientOriginalName(),
            'stored_as' => 'cv.pdf',
            'mime_type' => 'application/pdf',
            'size' => filesize($dir . '/cv.pdf'),
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
            $relativePath = $userId . '/templatex/candidates/' . $candidateId . '/cv.pdf';
            [$rawText, $extractMeta] = $this->fileProcessor->extractText($relativePath, 'pdf', $userId);
            $rawText = $rawText ?? '';

            if (empty(trim($rawText))) {
                return $this->json(['success' => false, 'error' => 'Could not extract text from CV'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            $prompt = $this->buildExtractionPrompt($rawText);
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
            $desc = $field['key'] . ' (' . ($field['type'] ?? 'text') . '): ' . ($field['label'] ?? $field['key']);
            if (!empty($field['hint'])) {
                $desc .= ' — ' . $field['hint'];
            }
            if (!empty($field['options'])) {
                $desc .= ' [allowed values: ' . implode(', ', $field['options']) . ']';
            }
            if ($field['type'] === 'list') {
                $desc .= ' [return as JSON array of strings]';
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

        $resolved = $this->resolveVariables($entry);

        return $this->json([
            'success' => true,
            'variables' => $resolved['variables'],
            'station_count' => $resolved['station_count'],
            'sources' => $this->getVariableSources(),
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

        $resolved = $this->resolveVariables($entry);

        return $this->json([
            'success' => true,
            'variables' => $resolved['variables'],
            'station_count' => $resolved['station_count'],
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
            $resolved = $this->resolveVariables($entry);
            $variables = $resolved['variables'];
            $stationCount = $resolved['station_count'];

            $cleanedPath = $this->cleanTemplateMacros($templatePath);

            $tp = new TemplateProcessor($cleanedPath);
            $tp->setMacroOpeningChars('{{');
            $tp->setMacroClosingChars('}}');

            $stations = $entry['ai_extracted']['stations'] ?? [];
            if (isset($entry['variable_overrides']['stations']) && is_array($entry['variable_overrides']['stations'])) {
                $stations = $entry['variable_overrides']['stations'];
            }

            if ($stationCount > 0) {
                $tp->cloneRow('stations.time.N', $stationCount);
                for ($i = 0; $i < $stationCount; $i++) {
                    $num = $i + 1;
                    $station = $stations[$i] ?? [];
                    $tp->setValue('stations.time.N#' . $num, $station['time'] ?? '');
                    $tp->setValue('stations.employer.N#' . $num, $station['employer'] ?? '');
                    $details = $station['details'] ?? '';
                    $details = str_replace("\n", '</w:t><w:br/><w:t>', $details);
                    $tp->setValue('stations.details.N#' . $num, $details);
                }
            }

            $checkboxKeys = ['moving', 'commute', 'travel'];
            foreach ($checkboxKeys as $cbKey) {
                $yesVal = $variables["checkb.{$cbKey}.yes"] ?? false;
                $tp->setCheckbox("checkb.{$cbKey}.yes", (bool) $yesVal);
                $tp->setCheckbox("checkb.{$cbKey}.no", !(bool) $yesVal);
            }

            $listKeys = ['relevantposlist', 'relevantfortargetposlist', 'languageslist', 'otherskillslist', 'benefits'];
            foreach ($listKeys as $listKey) {
                $val = $variables[$listKey] ?? null;
                $listText = is_array($val) ? implode("\n", $val) : (string) ($val ?? '');
                $listText = str_replace("\n", '</w:t><w:br/><w:t>', $listText);
                $tp->setValue($listKey, $listText);
            }

            $skipKeys = $listKeys;
            foreach ($variables as $key => $value) {
                if (str_starts_with($key, 'stations.') || str_starts_with($key, 'checkb.') || in_array($key, $skipKeys, true)) {
                    continue;
                }
                $tp->setValue($key, (string) ($value ?? ''));
            }

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
        if (str_starts_with($key, 'stations.')) {
            return 'station_field';
        }
        if (str_starts_with($key, 'checkb')) {
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

    private function buildExtractionPrompt(string $rawText): string
    {
        return <<<PROMPT
            You are extracting structured data from a CV/resume document. Return a JSON object with these fields. Use null for any field not found in the document. Do NOT invent or guess data.

            Fields to extract:
            - fullname (string): Full name
            - address1 (string): Street and house number
            - address2 (string): City
            - zip (string): Postal code
            - birthdate (string): Date of birth (DD.MM.YYYY format)
            - number (string): Phone number
            - email (string): Email address
            - currentposition (string): Current/most recent job title
            - education (string): Education and degrees
            - languageslist (array of strings): Language skills
            - otherskillslist (array of strings): Other skills (IT, tools)
            - stations (array of objects): Career history, most recent first. Each station:
              - employer (string): Company name
              - time (string): Time range, e.g. "02/2021 – heute"
              - details (string): Full details with sub-positions, bullet points, achievements. Preserve original formatting and line breaks.

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

    /** @return array<string, array{primary: string, fallback?: string}> */
    private function getVariableSources(): array
    {
        return [
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
    }

    /** @return array{variables: array<string, mixed>, station_count: int} */
    private function resolveVariables(array $entry): array
    {
        $formData = $entry['field_values'] ?? [];
        $aiData = $entry['ai_extracted'] ?? [];
        $overrides = $entry['variable_overrides'] ?? [];
        $sources = $this->getVariableSources();

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

        $movingYes = strtolower((string) ($variables['moving'] ?? '')) === 'ja';
        $variables['checkb.moving.yes'] = $movingYes;
        $variables['checkb.moving.no'] = !$movingYes;

        $commuteVal = $formData['commute'] ?? $variables['travelorcommute'] ?? '';
        $commuteYes = strtolower((string) $commuteVal) === 'ja';
        $variables['checkb.commute.yes'] = $commuteYes;
        $variables['checkb.commute.no'] = !$commuteYes;

        $travelVal = $formData['travel'] ?? $variables['travelorcommute'] ?? '';
        $travelYes = strtolower((string) $travelVal) === 'ja';
        $variables['checkb.travel.yes'] = $travelYes;
        $variables['checkb.travel.no'] = !$travelYes;

        $stations = $aiData['stations'] ?? [];
        if (array_key_exists('stations', $overrides) && is_array($overrides['stations'])) {
            $stations = $overrides['stations'];
        }
        $stationCount = count($stations);

        for ($i = 0; $i < $stationCount; $i++) {
            $num = $i + 1;
            $station = $stations[$i] ?? [];
            $variables['stations.time.' . $num] = $station['time'] ?? '';
            $variables['stations.employer.' . $num] = $station['employer'] ?? '';
            $variables['stations.details.' . $num] = $station['details'] ?? '';
        }

        return [
            'variables' => $variables,
            'station_count' => $stationCount,
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
