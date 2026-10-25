# Security Policy

Docvera processes untrusted document uploads (PDFs, images, Office archives) using native CLI engines (`qpdf`, `poppler`, `ghostscript`, `libreoffice`, `tesseract`). We treat file upload validation, subprocess execution, workspace isolation, and multi-tenant authorization as critical security boundaries.

---

## Supported Versions

| Version | Supported |
| :--- | :--- |
| `2.0.x` (`main`) | Yes |
| `< 2.0` | No |

---

## Reporting a Vulnerability

**Please do not report security vulnerabilities through public GitHub issues.**

If you discover a potential vulnerability—such as command/argument injection, path traversal, ZIP/decompression bomb bypass, SSRF, cross-user job access, or authentication bypass:

1. **GitHub Private Vulnerability Reporting (Preferred):** Use the **Report a vulnerability** button under the repository's **Security → Advisories** tab.
2. **Include in your report:**
   - Affected component (`UploadValidator`, `SafeProcessRunner`, specific driver, HTTP/API controller, etc.).
   - Minimal reproduction steps or proof-of-concept request/file generator.
   - Impact assessment (e.g., arbitrary file read, RCE, DoS, unauthorized job download).

### Response Targets
- **Initial acknowledgment:** within 72 hours.
- **Triage & reproduction confirmation:** within 7 days.
- **Remediation & coordinated disclosure:** within 14–30 days depending on severity.

---

## Built-In Security Controls & Threat Model

### 1. Subprocess Execution Boundary (`src/Security/SafeProcessRunner.php`)
- All external CLI tools are invoked via `proc_open(..., $commandArray, ...)` with `bypass_shell => true` (on Windows) and direct `execvp`-style array arguments on POSIX systems. Shell command strings are **never** interpolated from user filenames or options.
- Subprocesses run inside a minimal, sanitized environment (`PATH`, `HOME`, `TMPDIR` scoped to the job's isolated `work/` directory, `OMP_THREAD_LIMIT=2`).
- Every subprocess enforces a strict wall-clock timeout (`PROCESS_TIMEOUT_SECONDS`, default `60s`), kills runaway process groups (`posix_kill(-$pid, 9)`), and caps captured `stdout`/`stderr` buffers at 1 MB to prevent memory exhaustion.

### 2. Hostile Upload & Archive Verification (`src/Security/UploadValidator.php`)
- Filenames are stripped of path components, null bytes, control characters, and leading dots or hyphens (`-`), then replaced on disk with deterministic server-generated names (`input_001_<random>.pdf`).
- MIME types are verified via `finfo_file(FILEINFO_MIME_TYPE)` and format-specific magic-byte headers (`%PDF-`, JPEG/PNG/WebP/GIF headers, OLE2 `\xD0\xCF\x11\xE0`, and OpenXML ZIP signatures).
- OpenXML archives (`.docx`, `.xlsx`, `.pptx`) are inspected before reaching LibreOffice to reject path traversal entries (`..`), excessive entry counts (`> 2,500`), uncompressed size bombs (`> 250 MB`), and extreme compression ratios (`> 100:1` above 10 MB).

### 3. Ephemeral Workspace Isolation & Secret Scrubbing
- Each job runs in `storage/app/jobs/job_<32hex>/{input,work,output}` with `0700` permissions.
- Uploaded `input/` and intermediate `work/` directories are purged immediately inside a `finally` block when processing completes or fails.
- Sensitive job options (such as `user_password` / `password` used for `protect-pdf` or `unlock-pdf`) are never persisted in completed job summaries or asynchronous job records once execution finishes.
- Output artifacts are stored outside `public/` and require either the owning user session/API key or a valid HMAC-SHA256 `download_token`.

