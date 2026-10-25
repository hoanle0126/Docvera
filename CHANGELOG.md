# Changelog

All notable changes to **Docvera** are documented in this file.

---

## `v2.0.0` (2026-10-25) — Open-Source Hardening, REST API & Multi-Driver Platform Release

### Security & Processing Hardening
- **OpenXML & OLE2 Upload Verification (`src/Security/UploadValidator.php`):** Added deep ZIP container inspection for `.docx`, `.xlsx`, and `.pptx` uploads (enforcing entry count `<= 2,500`, uncompressed size `<= 250 MB`, compression ratio `<= 100:1` above 10 MB, `[Content_Types].xml` structure checks, and path-traversal rejection) plus OLE2 magic-byte verification for legacy `.doc`/`.xls`/`.ppt` files.
- **Subprocess Output Caps & Process-Group Termination (`src/Security/SafeProcessRunner.php`):** Capped captured `stdout` and `stderr` buffers at 1 MB per subprocess and added process-group `SIGKILL` (`posix_kill(-$pid, 9)`) on timeout.
- **QPDF Argument & Encryption Hardening (`src/Drivers/QpdfDriver.php`):** Validated `rotate-pages` page range expressions via `PdfInspector::parsePageRangeSpec()`, enforced encrypted PDF rejection across all non-unlock operations even when `pdfinfo` reports `page_count > 0`, and added `--` option terminators before password arguments.
- **Automatic Multi-Driver Fallback (`src/Tools/ToolExecutionService.php`, `src/Drivers/PopplerDriver.php`, `src/Drivers/TesseractOcrDriver.php`):** Tools with fallback chains (`qpdf → poppler → ghostscript`) now automatically fall back to the next available driver if a primary binary fails at runtime; `TesseractOcrDriver` now supports Ghostscript PDF rasterization when `pdftoppm` is absent.
- **Sensitive Parameter Scrubbing:** Asynchronous job records now scrub `request_params` and staging metadata upon completion or failure, and `/api/v1/jobs/*` responses strip internal staging keys (`queued_options`, `staged_inputs`).
- **HTTP Security Headers & Dev Server Traversal Guard (`src/Http/Response.php`, `public/index.php`):** Added `Content-Security-Policy` and `Permissions-Policy` headers on HTML responses and hardened PHP built-in server static file resolution with `realpath()` boundary checks.

### REST API & Developer Experience
- **Health & Job Listing Endpoints:** Added `GET /healthz`, `GET /api/v1/health`, `GET /api/v1/jobs`, and `/api/v1/account/usage` alias, plus `Prefer: respond-async` header support.
- **Container & CI Workflows:** Added production-ready [`Dockerfile`](Dockerfile), [`docker-compose.yml`](docker-compose.yml), and GitHub Actions CI matrix ([`.github/workflows/ci.yml`](.github/workflows/ci.yml)) testing all 22 tools across PHP 8.2 and 8.3.
- **Platform Capabilities:** Guided Web Installer (`/install`), CLI Installer (`bin/install`), Multi-Deployment Modes (`saas`, `free_ads`, `private`), Configurable Plans & Quota Engine, Optional Stripe & PayPal Billing Webhooks, Scoped API Keys (`dcv_live_...`), Async Queue Worker (`bin/worker`), and 16-Section Admin Console (`/admin/*`).

---

## `v1.0.0` (2026-01-15) — Initial Workbench Engine

- **22 Self-Hosted PDF & Document Tools** across Organize, Convert to PDF, Convert from PDF, Optimize & Stamp, and Security & Metadata categories.
- **Homepage Smart File Router (`/router/inspect`)** with magic-byte MIME detection and compatible tool recommendations.
- **Isolated `0700` Per-Job Ephemeral Workspaces (`storage/app/jobs/job_*`)** with immediate `input/` + `work/` purge and TTL output cleanup (`bin/cleanup`).
