# AGENTS.md — Docvera Engineering & Maintenance Guide

This file provides authoritative architectural context, security invariants, and verification commands for AI coding agents (OpenAI Codex, Claude Code, Antigravity, Cursor) and human maintainers working on **Docvera**.

---

## 1. Project Identity

- **Project:** Docvera — Privacy-First, Self-Hosted Document Processing Platform & REST API
- **License:** Apache-2.0 (`LICENSE`)
- **Stack:** PHP 8.2+ (strict types, zero external Composer runtime dependencies), vanilla HTML5/CSS/JS frontend, dual persistence layer (`flock` JSON file store by default, or PDO SQLite / MySQL), and native OS CLI document engines (`qpdf`, `poppler-utils`, `ghostscript`, `libreoffice`, `tesseract-ocr`, plus PHP `gd` & `zip`).

---

## 2. Essential Commands

Run these commands from the repository root (`/home/hoanle0126/PersonalProject/Codester/Docvera`):

```bash
# 1. Syntax check all PHP files
find src bin public views tests config -name '*.php' -print0 | xargs -0 -n1 php -l

# 2. Inspect detected host binaries and active tool matrix (22 tools)
php bin/capabilities
php bin/capabilities --json

# 3. Verify pre-flight installer checks without mutating state
php bin/install --check-only

# 4. Run the full integration, output-verification, and security test suite
php bin/test

# 5. Start local development server
php -S 127.0.0.1:8080 -t public public/index.php
```

> **Important Note on State Cleanliness:** Running `php bin/capabilities` or `php bin/install --check-only` on an uninitialized workspace may create `storage/app/database/.app_secret`. Always run `php bin/test` (which uses an isolated `/tmp` directory) and ensure `storage/app/database/` has no untracked runtime files before committing.

---

## 3. Non-Negotiable Security & Architectural Invariants

1. **Never Construct Shell Command Strings (`src/Security/SafeProcessRunner.php`):**
   - Strictly forbid `exec()`, `shell_exec()`, `system()`, `passthru()`, `popen()`, or string-based `proc_open()`.
   - Every external binary execution MUST pass a discrete `list<string>` argument array to `SafeProcessRunner::run()`.
   - Validate page ranges via `PdfInspector::parsePageRangeSpec()` before passing them to `qpdf` or `gs`, and use `--` option terminators where supported when passing user-supplied strings (such as PDF passwords).

2. **Never Trust Upload Metadata (`src/Security/UploadValidator.php`):**
   - Every uploaded file must pass size bounds, filename sanitization, extension allowlisting, `finfo_file()` magic-byte MIME verification, and format-specific header/archive verification (`verifyMagicHeader()` and `verifyOpenXmlArchive()`).
   - Never weaken the OpenXML ZIP-bomb ratio/size/entry limits or path-traversal checks.

3. **Preserve Ephemeral Workspace Isolation (`src/Storage/TempStorageManager.php`):**
   - Every job executes inside `storage/app/jobs/job_<32hex>/{input,work,output}` with `0700` permissions.
   - `input/` and `work/` MUST be purged in a `finally` block inside `ToolExecutionService` immediately after driver execution completes or fails.
   - Sensitive request parameters (`user_password`, `password`) and internal staging paths (`staged_inputs`, `queued_options`) MUST NOT remain in persisted job summaries or API responses.

4. **Honest Capability Detection (`src/Capabilities/CapabilityDetector.php`):**
   - Never stub or fake a conversion output if a host binary (`soffice`, `tesseract`, `gs`, `qpdf`, `pdftoppm`) is missing.
   - Tools must truthfully report their availability and fallback driver (`qpdf → poppler → ghostscript`) via `ToolRegistry`.

5. **Output-Verifying Tests (`tests/DocveraTestSuite.php`):**
   - Any new tool, driver change, or security fix MUST include deterministic tests in `tests/DocveraTestSuite.php` that inspect physical output properties (PDF structure, page count, dimensions, encryption state, extracted text, or HTTP error codes).
