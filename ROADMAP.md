# Docvera Public Roadmap

This roadmap tracks the engineering priorities for **Docvera** as a privacy-first, self-hosted document processing platform and REST API.

---

## Shipped & Verified Today (`v2.0`)

- **22 Self-Hosted Document Tools** across 5 functional categories:
  - **Organize:** Merge PDF, Split PDF, Extract Pages, Delete Pages, Reorder Pages, Rotate Pages.
  - **Convert to PDF:** Images to PDF (`JPG`/`PNG`/`WebP`), Text/Markdown to PDF, Word to PDF, Excel to PDF, PowerPoint to PDF.
  - **Convert from PDF:** PDF to Images (`PNG`/`JPG` at 72–300 DPI), OCR PDF (`Searchable PDF` or `Plain Text`).
  - **Optimize & Stamp:** Compress PDF (`/screen`, `/ebook`, `/printer`), Grayscale PDF, Flatten PDF, Watermark PDF, Page Numbers, Crop PDF.
  - **Security & Metadata:** Protect PDF (`AES-256` via QPDF or Ghostscript fallback), Unlock PDF, Edit PDF Metadata.
- **Automatic Driver Fallback & Truthful Capability Detection:**
  - Multi-driver execution (`qpdf → poppler → ghostscript`) with live pre-flight diagnostics (`php bin/capabilities`, `GET /api/v1/health`, `GET /api/v1/capabilities`).
- **Hostile-Input Hardening & Privacy Lifecycle:**
  - Magic-byte and OpenXML archive inspection (`UploadValidator`), array-only subprocess execution with process-group timeouts and 1 MB output caps (`SafeProcessRunner`), isolated `0700` per-job workspaces (`TempStorageManager`), immediate `input/` + `work/` purge in `finally` blocks, and TTL cleanup (`bin/cleanup`).
- **Versioned REST API (`/api/v1/*`) & OpenAPI 3.1.0 Spec:**
  - Scoped API keys (`tools:execute`, `jobs:read`, `jobs:download`, `jobs:delete`), `Idempotency-Key` replay protection, synchronous (`200 OK`) and asynchronous (`202 Accepted` + `bin/worker`) execution.
- **Multi-Deployment Operating Modes:**
  - `private` (internal team workspace), `saas` (quota-governed multi-user service), and `free_ads` (public utility), supporting zero-dependency JSON file persistence, SQLite, or MySQL.

---

## Near-Term Milestones (`v2.1` – `v2.2`)

1. **Multi-Step Workflow Pipelines (`/api/v1/pipelines`):**
   - Chain multiple tools in a single upload lifecycle (e.g., `ocr-pdf → compress-pdf → watermark-pdf → protect-pdf`) inside one isolated workspace without re-uploading intermediate artifacts.
2. **Webhook Callbacks for Asynchronous Jobs:**
   - Optional signed `webhook_url` parameter on `async=1` API requests so workers push completion/failure events with HMAC-SHA256 signatures rather than requiring polling.
3. **PDF/A Archival Conversion & VeraPDF Validation (`pdf-to-pdfa`):**
   - Ghostscript PDF/A-1b and PDF/A-2b archival output profiles for legal and government record compliance.
4. **PDF Form Inspection & Programmatic Field Filling (`inspect-forms`, `fill-pdf-form`):**
   - Extract AcroForm field schemas as JSON and populate form fields via REST API before optional flattening.

---

## Medium-Term Milestones (`v2.3+`)

1. **Cryptographic PDF Signing (`sign-pdf`):**
   - PKCS#12 / X.509 digital signature application and signature verification reporting.
2. **Deterministic PDF Redaction (`redact-pdf`):**
   - True content-stream and raster redaction (removing underlying text/vector glyphs rather than drawing black rectangles).
3. **S3-Compatible Object Storage Backend (Optional):**
   - Optional encrypted S3/MinIO storage adapter for horizontally scaled multi-worker deployments while keeping local `0700` scratch execution default.
4. **Expanded Language Packs & Layout-Preserving OCR:**
   - Configurable multi-language Tesseract packs (`eng+deu+fra+vie+...`) and deskew/preprocessing filters.

