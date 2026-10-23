# Changelog

All notable changes to **Docvera — Self-Hosted PDF & Document Workbench** are documented in this file.

---

## [2.0.0] — Commercial Marketplace Release (2026-10-24)

### Added
- **Guided Web Installer (`/install`) & CLI Installer (`php bin/install`):**
  - Pre-flight readiness inspector that strictly separates **REQUIRED** host prerequisites (PHP 8.2+, `fileinfo`, `openssl`, `zip`, `mbstring`, writable `0700` storage directories) from **OPTIONAL CAPABILITIES** (`ghostscript`, `poppler`, `qpdf`, `libreoffice`, `tesseract`, `ext-gd`).
  - Live database connection tester (`file`, `sqlite`, `mysql`/`pgsql`) with zero credential leakage in error output.
  - Automatic 256-bit CSPRNG `APP_SECRET` generation, `.env` creation (`0600`), database migration execution, and initial Administrator provisioning.
  - Permanent post-install lockout (`storage/app/installed.lock` + `APP_INSTALLED=true`) returning HTTP `403` on any subsequent `/install` request.
- **Multi-Deployment Commercial Operating Modes:**
  - Switchable at runtime in `/admin/overview` between **Freemium / Paid SaaS (`saas`)**, **Free / Ad-Supported Utility (`free_ads`)**, and **Private Internal Workspace (`private`)**.
- **Configurable Plans & Authoritative Entitlement Engine:**
  - Seeded `guest`, `free`, `pro`, and `business` tiers with per-day/per-month operation quotas, file-size limits, page-count caps, batch limits, OCR permissions, concurrency slots, API rate limits, retention windows, and ad visibility flags.
- **Subscription Billing & Verified Webhook Engine:**
  - Stripe Checkout & PayPal Subscriptions integration with `Stripe-Signature` HMAC-SHA256 timestamp verification, PayPal webhook signature verification, and idempotent replay protection (`webhook_events`).
- **Versioned Developer REST API (`/api/v1/*`):**
  - Bearer and `X-Api-Key` authentication with hashed `dcv_live_` secrets, key rotation/revocation, scoped permissions (`tools:execute`, `jobs:read`, `jobs:download`, `jobs:delete`), `Idempotency-Key` replay cache, synchronous execution, and asynchronous queued execution (`?async=1`) via `php bin/worker`.
  - Dynamic `/developers/api` documentation and `/api/v1/openapi.json` OpenAPI 3.1.0 specification generated directly from `ToolRegistry`.
- **16-Section Administration Console (`/admin/*`):**
  - Overview & Business Mode, Tool Management, Users & Roles, Plans & Quotas, Subscriptions, Payments & Webhooks, Usage Buckets, Processing Jobs, Truthful System Health, Storage & Cleanup, API Usage & Keys, SEO & Sitemap, Branding & Ad Slots, CMS Pages (`/p/privacy`, `/p/terms`, `/p/security`), Security & Billing Keys, and Update Center.
- **Clean-Release Packaging & Verification Tooling:**
  - Added `bin/package-release` to build a clean customer distribution archive (`dist/docvera-v2.0.0-codester.zip`) free of `.git`, secrets, temporary uploads, or dev artifacts.
  - Expanded automated verification suite (`php bin/test`) to 35 end-to-end assertions including v1.0.0-to-v2.0.0 upgrade verification.

### Security & Reliability Fixes
- Added IP + account brute-force lockout (`429 Too Many Requests`) on `/login` and `/register` plus CSRF token rotation on session privilege transitions.
- Fixed asynchronous queue concurrency slot accounting so `active_concurrent_jobs` remains reserved while a job is `queued` and is released deterministically when `bin/worker` completes or when a queued job is deleted/purged.
- Unified `GhostscriptDriver::splitPdf()` to support `split_mode=every_n` (`every_n_pages`) on hosts without `pdfseparate`.
- Added encrypted PDF pre-checks to `QpdfDriver::mergePdf()` and `QpdfDriver::rotatePages()` for consistent `422` validation messages.
- Added fallback CSPRNG secret persistence (`storage/app/database/.app_secret`) when `.env` `APP_SECRET` is left at its placeholder value.

---

## [1.0.0] — Initial Workbench Engine Release (2026-01-15)

### Added
- 22-tool PDF & Document Workbench directory with Homepage Smart File Router (`/router/inspect`).
- 18 core PDF, image, and text tools powered by `GhostscriptDriver`, `PopplerDriver`, `QpdfDriver`, and `NativePhpPdfDriver`.
- 4 capability-gated Office & OCR tools (`word-to-pdf`, `excel-to-pdf`, `powerpoint-to-pdf`, `ocr-pdf`) powered by `LibreOfficeDriver` and `TesseractOcrDriver`.
- Strict magic-byte upload validator (`UploadValidator`), zero-shell subprocess runner (`SafeProcessRunner`), isolated `0700` per-job workspaces (`TempStorageManager`), and scheduled TTL garbage collection (`CleanupService`).
