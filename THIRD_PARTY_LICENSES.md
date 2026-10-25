# Third-Party Licenses & External Dependencies

Docvera (`src/`, `views/`, `public/`, `bin/`, `database/`, `tests/`, `docs/`) is original software licensed under the **Apache License 2.0** (see [`LICENSE`](LICENSE)).

It ships with **zero bundled third-party PHP Composer libraries** and **zero bundled third-party JavaScript libraries** inside the repository. A lightweight, self-contained PSR-4 autoloader is provided in `vendor/autoload.php`.

---

## 1. PHP Runtime & Standard Extensions

Docvera runs on standard PHP 8.2+ and utilizes built-in PHP extensions:

| Component | License | Role in Docvera |
| :--- | :--- | :--- |
| **PHP Runtime (`>= 8.2`)** | PHP License v3.01 | Core application runtime and CLI execution. |
| **`ext-fileinfo`** | PHP License v3.01 | True magic-byte MIME verification (`finfo_file`) inside `UploadValidator`. |
| **`ext-gd`** | GD License / BSD-style | Native image inspection, JPEG/PNG decoding, and image-to-PDF conversion (`NativePhpDriver`). |
| **`ext-zip`** | PHP License v3.01 | Multi-file ZIP archive packaging for `split-pdf` and `pdf-to-images`, plus OpenXML container inspection. |
| **`ext-mbstring` / `ext-json`** | PHP License v3.01 | UTF-8 string sanitization, JSON persistence store, and REST API serialization. |
| **`ext-pdo` (`pdo_sqlite` / `pdo_mysql`)** | PHP License v3.01 | Relational database persistence when `DB_CONNECTION=sqlite` or `DB_CONNECTION=mysql`. |

---

## 2. Optional Host OS CLI Binaries (Not Bundled in Repository)

Docvera invokes standard operating-system command-line utilities installed separately on the host server via `SafeProcessRunner` (`proc_open` with array arguments, never shell strings). **None of these binaries are bundled or redistributed inside the Docvera source repository.**

| Host Binary | Package Name (Debian/Ubuntu) | Upstream License | Used By Docvera Driver |
| :--- | :--- | :--- | :--- |
| **QPDF (`qpdf`)** | `qpdf` | Apache License 2.0 | `Docvera\Drivers\QpdfDriver` (lossless merge, page extract/delete/reorder/rotate, AES-256 encryption, decryption) |
| **Poppler Utilities (`pdfinfo`, `pdftoppm`, `pdftotext`, `pdfseparate`, `pdfunite`)** | `poppler-utils` | GPL v2 / GPL v3 | `Docvera\Drivers\PopplerDriver` & `Docvera\Inspection\PdfInspector` (page counting, rasterization to PNG/JPG, PDF split/merge) |
| **Ghostscript (`gs`)** | `ghostscript` | AGPL v3 | `Docvera\Drivers\GhostscriptDriver` (PDF compression, grayscale conversion, PostScript overlay stamping for watermark/page-numbers/crop/metadata/flatten) |
| **LibreOffice (`soffice`)** | `libreoffice` | MPL v2.0 | `Docvera\Drivers\LibreOfficeDriver` (headless Office-to-PDF conversion for Word, Excel, and PowerPoint) |
| **Tesseract OCR (`tesseract`)** | `tesseract-ocr` | Apache License 2.0 | `Docvera\Drivers\TesseractOcrDriver` (searchable PDF and plain-text optical character recognition) |

> **License Isolation Note:** Because Docvera interacts with host CLI tools strictly as separate operating-system processes over standard CLI arguments and temporary files (`proc_open`), and does not link against or redistribute Ghostscript or Poppler binaries, Docvera's own source code remains cleanly licensed under the **Apache License 2.0**. When distributing pre-built container images that bundle OS packages, ensure compliance with the respective upstream Debian/Ubuntu package licenses.

---

## 3. Typography & Icons

- **System & Editorial Fonts:** Primary interface typography falls back cleanly to native system sans/serif/monospace stacks (`Instrument Serif`, `Plus Jakarta Sans`, `JetBrains Mono` loaded optionally via Google Fonts under the **SIL Open Font License 1.1**).
- **SVG Icons:** All inline SVG icons in `views/` are custom-authored geometric primitives created specifically for Docvera.
