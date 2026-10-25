# Contributing to Docvera

Thank you for your interest in contributing to **Docvera**! Docvera is a privacy-first, self-hosted document processing platform and REST API designed to run reliably on standard Linux servers, containers, and PHP 8.2+ environments without external cloud dependencies.

---

## 1. Local Development Quickstart

### Prerequisites
- **PHP `>= 8.2`** with `fileinfo`, `gd`, `json`, `mbstring`, `pdo_sqlite`, and `zip` extensions.
- **Recommended CLI binaries** (optional; missing binaries degrade gracefully):
  - `qpdf`
  - `poppler-utils` (`pdfinfo`, `pdftoppm`, `pdftotext`, `pdfseparate`, `pdfunite`)
  - `ghostscript` (`gs`)
  - `libreoffice` (`soffice`)
  - `tesseract-ocr` (`tesseract`)

On Ubuntu/Debian:
```bash
sudo apt-get update && sudo apt-get install -y \
  php-cli php-gd php-mbstring php-sqlite3 php-xml php-zip \
  ghostscript poppler-utils qpdf libreoffice tesseract-ocr
```

### Clone, Inspect & Run
```bash
git clone https://github.com/hoanle0126/Docvera.git
cd Docvera

# 1. Inspect detected host binaries and active tool matrix (22 tools)
php bin/capabilities

# 2. Run the automated test suite (creates an isolated temp workspace)
php bin/test

# 3. Start the local development server on http://127.0.0.1:8080
php -S 127.0.0.1:8080 -t public public/index.php
```

---

## 2. Architecture & Engineering Principles

Before opening a pull request, please review [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md). Every contribution must uphold these core invariants:

1. **Zero Shell Interpolation (`SafeProcessRunner`):**
   - Never call `exec()`, `shell_exec()`, `system()`, `passthru()`, or `popen()`.
   - Always invoke external binaries through `Docvera\Security\SafeProcessRunner::run(array $command, string $cwd, int $timeoutSeconds)`, passing arguments as a discrete string array (`proc_open`).
   - Always validate page specifications (`PdfInspector::parsePageRangeSpec`) and terminate option flags (`--`) before passing user-supplied strings such as PDF passwords to CLI tools.
2. **Hostile-Input Upload Validation (`UploadValidator`):**
   - Never trust browser-supplied `$_FILES['type']` or file extensions alone.
   - Verify magic bytes (`%PDF-`, JPEG/PNG/WebP/GIF headers, OLE2 headers, or OpenXML ZIP structures with ZIP-bomb and path-traversal guards).
3. **Ephemeral Workspace Isolation (`TempStorageManager`):**
   - Every job runs inside an isolated `0700` directory (`storage/app/jobs/job_<32hex>/{input,work,output}`).
   - Uploaded `input/` and intermediate `work/` files must be wiped immediately inside a `finally` block as soon as the driver finishes.
4. **Truthful Capability Detection (`CapabilityDetector`):**
   - Never fake or stub a document conversion when a required binary is missing.
   - If a tool depends on an optional binary, declare its candidate drivers in `ToolRegistry` so Docvera automatically marks the tool unavailable with an actionable remediation message when the binary is absent.
5. **Real Output Verification in Tests (`tests/DocveraTestSuite.php`):**
   - Tests must inspect the generated artifact (valid PDF header/trailer, page count via `PdfInspector`, rotated bounding box dimensions, encryption state, extracted text, or ZIP archive entries)—not just HTTP 200 status codes.

---

## 3. Adding a New Document Tool or Processing Driver

See the step-by-step guide in [`docs/ARCHITECTURE.md#adding-a-new-tool-or-driver`](docs/ARCHITECTURE.md#adding-a-new-tool-or-driver):
1. Register any new binary check in `src/Capabilities/CapabilityDetector.php`.
2. Implement or extend the driver in `src/Drivers/*Driver.php` (`ProcessingDriverInterface`).
3. Register the `ToolDefinition` and option schema in `src/Tools/ToolRegistry.php` (this automatically wires the Web UI, Smart File Router, `/api/v1/tools/{slug}`, and OpenAPI 3.1.0 documentation).
4. Add deterministic integration tests in `tests/DocveraTestSuite.php`.

---

## 4. Submitting a Pull Request

1. Create a feature or bugfix branch from `main`.
2. Run syntax lint and the full test suite before committing:
   ```bash
   composer lint
   php bin/capabilities
   php bin/test
   ```
3. Open a pull request completing the checklist in [`.github/PULL_REQUEST_TEMPLATE.md`](.github/PULL_REQUEST_TEMPLATE.md).

