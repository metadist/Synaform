# Synaform — Document → Tika → AI flow benchmark

Generated: 2026-05-12 09:44 CEST  
Stack: synaplan local Docker (backend + tika 3.3.0 + qdrant + ollama).  
Test data: `wwwroot/hhff/scans_roh/` (3 real questionnaire scans, total 11.72 MB).

## TL;DR

- **Tika is healthy and fast** (HTTP 200 in 2 ms, version `Apache Tika 3.3.0`).
- **Tika is not on the hot path for image scans.** `FileProcessor` routes JPG/PNG straight to the configured Vision-AI provider; Tika is bypassed entirely.
- The configured **Vision-AI provider for user 1 is Anthropic**, but every Anthropic vision call in this benchmark returned `HTTP 400`. `AiFacade` correctly falls back to **Google Gemini 2.5 Pro**, which is the model that actually produces the text — at **~40–73 s per image**.
- For 3 scans in one dataset, the perceived wait is **~190 s end-to-end** (we measured 187 s). 1.3 s of that is the failed Anthropic round-trip per image; the rest is Gemini.
- The chat / "information processing" model is `openai gpt-5.4` (id 180). It runs **once** per dataset after all sources are extracted, so it is **not** multiplied by file count.

## Tika service status

- Endpoint: `http://tika:9998/version`
- Reachable: **YES** (HTTP 200, 2 ms round-trip)
- Version: `Apache Tika 3.3.0`
- `/tika` hello: `This is Tika Server (Apache Tika 3.3.0). Please PUT`

## AI configuration (user 1)

| Capability | Provider | Model id | Model name |
|------------|----------|----------|------------|
| Chat (information processing) | openai | 180 | `gpt-5.4` |
| Vision (image processing / OCR fallback) | anthropic | (provider default) | (provider default) |

The vision row deserves a footnote: the provider is set to Anthropic, but no specific model id is pinned. `AiFacade::analyzeImage()` therefore asks `AnthropicProvider` to pick its default vision model, which it currently resolves to `claude-3-5-sonnet-20241022`. **All three calls in this benchmark returned HTTP 400.** AiFacade correctly retries through its provider chain (anthropic → google) and Google Gemini 2.5 Pro is what actually produces text.

## Per-file timing

| File | Size | MIME | Strategy | Wall-clock | Text bytes |
|------|-----:|------|----------|-----------:|-----------:|
| IMG_2116.JPG | 4.30 MB | image/jpeg | `vision_ai` | 72.91 s | 906 |
| IMG_2117.JPG | 3.43 MB | image/jpeg | `vision_ai` | 70.05 s | 1,137 |
| IMG_2118.JPG | 3.99 MB | image/jpeg | `vision_ai` | 43.98 s | 1,491 |
| **Total** | **11.72 MB** | — | — | **186.94 s** | — |

Average per file: **62.31 s**.

What that 62 s breaks down to (from the request log inside the bench):

```
~1.3 s   Anthropic call to api.anthropic.com → HTTP 400
~0.0 s   AiFacade circuit-breaker logging + provider fallback
~40–70 s Google Gemini 2.5 Pro POST .../models/gemini-2.5-pro:generateContent → HTTP 200
~0.1 s   FileProcessor TextCleaner pass + return
```

## Strategy reference

`FileProcessor::extractText` picks one strategy per file:

| Strategy | Used for | Notes |
|----------|----------|-------|
| `native_text` | text/plain, text/markdown, text/csv, text/html | filesystem read, ~ms |
| `vision_ai` | image/jpeg, image/png, image/gif, image/webp | sends file to Vision-AI provider; 5–60+ s/image |
| `tika` | office formats (PDF/DOCX/XLSX/PPTX/…) | local Tika, ~ms–2 s |
| `rasterize_vision` | PDFs whose Tika output is empty / low-quality | Ghostscript-rasterise then send each page to Vision-AI |
| `tika_disabled` / `tika_failed` | when Tika is unreachable for a non-image | empty result, warning logged |

## Per-file extraction preview

### IMG_2116.JPG · 72.91 s · 906 bytes
> 16.80 Datum:24.02 Vermittlung aus der Schweiz Ident/ Wohnort: Mobil: E-mail: LOGI DES FIIC Zeugnisse/Referenzen: Vorgeschlagen: Interview: Organigramm: Direkt E-Mail An Florian Schmid B + CheckSite/PG …

### IMG_2117.JPG · 70.05 s · 1,137 bytes
> Datum: 16.01.2016 Dateinr.: Mülheim a.d. Ruhr 0721-2044 9447 Vermittlung Research: Wohnort: Mobil: Email: Xing/LinkedIn Interviewer: Emily Burke Familienstand: Foto: Vorgesetzer: Julia PD Tätig als: T …

### IMG_2118.JPG · 43.98 s · 1,491 bytes
> TI VC CheckSit PG Xing/LinkedIn Vermittlung Datum: 12.01.26 Projekt: initiativ Interviewer: JP Research: Dateinr.: Name Kandidat/in: Julian Spangenberg Wohnort: Alter: 27.02.1978 Familienstand: Mobil: …

The extracted text is short but accurate, which is the worst case for cost-vs-value: each call burns 40–70 s for a kilobyte of text. That kilobyte is enough for the downstream Synaform extract-LLM to fill the form fields, but the cost is dominated by the Vision-AI roundtrip, not the chat-LLM call that follows it.

## Root cause: why is it slow?

1. **Anthropic Vision is broken on this stack** — `claude-3-5-sonnet-20241022` returns HTTP 400 for every image we sent. Most likely cause: an out-of-date API key, exhausted credits, or a model identifier that the org no longer has access to. AiFacade is doing the right thing (falling back), but each failed attempt costs ~1.3 s and pollutes the circuit-breaker log.
2. **Google Gemini 2.5 Pro is heavy** — Gemini 2.5 Pro is the highest-quality vision model available, which is also why it is the slowest. For form-scan OCR-style work the lighter `gemini-2.5-flash` typically returns in 8–15 s.
3. **Calls are sequential** — `FileProcessor::extractFromImage()` is called once per file from the synchronous `extractText()` path. When the Synaform "Read files & auto-fill" button fires `parse-documents`, it loops through every file (`cv` + `additional[]` + `urls[]`) one by one. Three images therefore wait for three full Vision-AI round-trips back to back.

## Recommended fixes (cheapest first)

1. **Fix the Anthropic key / model alias** (or remove Anthropic from the vision provider chain). A correct Anthropic Vision call typically returns in 6–12 s. Single-image cost would drop from ~62 s to ~10–15 s.
2. **Switch the user's default vision model to `gemini-2.5-flash`** (BMODELS row, BTAG `pic2text`, lighter quality tier). Same Google API, ~5× faster, more than good enough for OCR on questionnaire scans.
3. **Parallelise Vision-AI calls inside `parse-documents`** (backend change). Today the loop is sequential; even a `curl_multi` with concurrency 3 would cut a 3-image dataset to wall-clock = max(per-image), not sum.
4. (Done in earlier session) Surface real progress on the frontend: live elapsed timer, per-file count, image-aware hint, "keep this tab open" warning.

## How to re-run this benchmark

The script is committed to `_devnotes/synaform-bench.php`. To run it again against fresh test data:

```bash
# 1. Drop the new files into user 1's bench dir
mkdir -p /var/www/backend/var/uploads/1/synaform-bench/
docker cp <YOUR_FILE.JPG> synaplan-backend:/var/www/backend/var/uploads/1/synaform-bench/
docker compose -f /wwwroot/synaplan/docker-compose.yml exec backend \
    chown www-data:www-data /var/www/backend/var/uploads/1/synaform-bench/<YOUR_FILE.JPG>

# 2. Push the bench script in (or symlink it to a path inside the container)
docker cp /wwwroot/Synaform/_devnotes/synaform-bench.php \
    synaplan-backend:/tmp/synaform-bench.php

# 3. Run it; STDOUT is the report, STDERR is the debug log
docker compose -f /wwwroot/synaplan/docker-compose.yml exec -T backend \
    php /tmp/synaform-bench.php > /tmp/synaform-bench-report.md \
                                2> /tmp/synaform-bench-report.log
```

The bench instantiates the real `SynaformController`, pulls the live `FileProcessor` and `ModelConfigService` out of the container via reflection, and runs each file through `extractText()` exactly the way the production "Read files & auto-fill" path does — so the timings are realistic, not synthetic.

## Plugin dashboard

The same `Tika status / Information AI / Image AI / counts` snapshot is now available **live** at `GET /api/v1/user/{userId}/plugins/synaform/dashboard` and rendered as a "System status" card on the Synaform overview tab. Hitting "Refresh" re-probes Tika and re-reads the AI configuration in real time.

## Conclusion

Tika is fine. The slow part is the Vision-AI call for every image, and on this local stack the configured primary provider (Anthropic) is failing with HTTP 400, forcing every request through the heavy Gemini 2.5 Pro fallback. Two minutes for three scans is therefore expected behaviour given the current configuration — not a bug in the extraction pipeline. The two highest-leverage actions are: (1) fix or remove Anthropic so the chain returns immediately on the first provider, (2) point the vision capability at `gemini-2.5-flash` for typical OCR-style scans. Either change brings the per-image cost back into the "long but bearable" 5–15 s range. A third, structural improvement is to parallelise the per-file loop inside `parse-documents` so a 3-image dataset stops costing 3 × per_image and starts costing 1 × per_image wall-clock.
