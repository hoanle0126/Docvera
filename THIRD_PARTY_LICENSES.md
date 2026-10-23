# Third-Party Licenses, Copyright & Redistribution Notices

**Product:** Docvera — Self-Hosted PDF & Document Workbench (`v2.0.0`)  
**License Audit Status:** Verified for Commercial Source-Code Distribution (Codester Marketplace)

This document inventories every third-party dependency, font, icon, stylesheet, script, sample fixture, and optional host binary referenced by **Docvera**.

---

## 1. Bundled PHP Packages (`composer.json`)

**Zero third-party PHP packages are bundled or required.**

Docvera uses a lightweight, zero-dependency PSR-4 autoloader (`vendor/autoload.php`) written specifically for this project and relies exclusively on standard PHP 8.2+ runtime extensions (`ext-fileinfo`, `ext-zip`, `ext-mbstring`, `ext-openssl`, `ext-json`, `ext-pdo`).

| Component | Source | License | Bundled in ZIP | Commercial Redistribution |
| :--- | :--- | :--- | :--- | :--- |
| `Docvera\*` Application Source (`src/`) | Original 1st-party code | Commercial Item License | Yes | Permitted (1st-party) |
| `vendor/autoload.php` PSR-4 Loader | Original 1st-party code | Commercial Item License | Yes | Permitted (1st-party) |

---

## 2. Bundled JavaScript Packages (`public/assets/js/app.js`)

**Zero third-party JavaScript libraries or npm packages are bundled.**

All client-side interactivity (drag-and-drop file staging, Smart File Router XHR inspection, multi-file queue reordering, one-time API key copy-to-clipboard, and progressive form states) is implemented in dependency-free vanilla ES6+ JavaScript (`public/assets/js/app.js`).

| Component | Source | License | Bundled in ZIP | Commercial Redistribution |
| :--- | :--- | :--- | :--- | :--- |
| `public/assets/js/app.js` | Original 1st-party code | Commercial Item License | Yes | Permitted (1st-party) |

---

## 3. Typography & Fonts (`public/assets/fonts/`, `public/assets/css/docvera.css`)

Docvera bundles two open-source variable fonts and serves them from the host itself, so no font request ever leaves the server (consistent with the local-processing guarantee). Both are licensed under the SIL Open Font License 1.1, which permits bundling and redistribution with software. The full license texts ship beside the files.

| Font Family | Designer / Foundry | License | File(s) | Source |
| :--- | :--- | :--- | :--- | :--- |
| **Archivo** (variable: weight 100–900, width 62–125%) | Omnibus-Type | OFL-1.1 (`public/assets/fonts/OFL-Archivo.txt`) | `archivo-latin-var.woff2`, `archivo-latin-ext-var.woff2` | `@fontsource-variable/archivo@5.1.0` |
| **JetBrains Mono** (variable: weight 100–800) | JetBrains | OFL-1.1 (`public/assets/fonts/OFL-JetBrainsMono.txt`) | `jetbrains-mono-latin-var.woff2` | `@fontsource-variable/jetbrains-mono@5.1.0` |

Fallback stacks: `ui-sans-serif, system-ui, sans-serif` and `SFMono-Regular, Consolas, monospace`.

---

## 4. Icons, Illustrations, Stylesheets & Images

All UI icons, category badges, structural hairlines, and marketplace preview graphics are 1st-party inline SVG paths or programmatically captured screenshots of the Docvera interface.

- **Competitor Assets:** Zero competitor logos, brand names, sales copy, screenshots, or code snippets are included.
- **Stock Photography:** Zero third-party stock photos are bundled in the product package.

---

## 5. Sample Documents & Test Fixtures (`tests/Fixtures/FixtureFactory.php`)

Docvera generates all test and verification documents programmatically at runtime via `Docvera\Tests\Fixtures\FixtureFactory` using native PHP PDF stream construction and GD/PPM synthesis. Zero third-party copyrighted PDF, Word, Excel, or PowerPoint files are distributed in the release archive.

---

## 6. External Host System Binaries (NOT Bundled — Installed Separately by Customer)

**Important License Compliance Notice:**  
Docvera **DOES NOT bundle** any third-party executable binaries (`gs`, `qpdf`, `pdfinfo`, `pdftoppm`, `pdftotext`, `soffice`, `tesseract`) inside the downloadable ZIP package.

Customers install these standard system packages directly from their operating system package manager (`apt`, `dnf`, `apk`, `brew`) on their own server. Docvera interacts with them exclusively as separate OS processes via `proc_open()` CLI arguments and does not link against their libraries.

| System Package | Upstream Project | Upstream License | Bundled in ZIP | Customer Installation Command (Ubuntu/Debian) |
| :--- | :--- | :--- | :--- | :--- |
| **Ghostscript** (`gs`) | Artifex Software | AGPLv3 (OS package) | **No** (Never bundled) | `sudo apt install ghostscript` |
| **Poppler Utilities** (`pdfinfo`, `pdftoppm`, `pdftotext`) | Poppler / Freedesktop | GPLv2 / GPLv3 (OS package) | **No** (Never bundled) | `sudo apt install poppler-utils` |
| **QPDF** (`qpdf`) | Jay Berkenbilt / QPDF | Apache License 2.0 | **No** (Never bundled) | `sudo apt install qpdf` |
| **LibreOffice Headless** (`soffice`) | The Document Foundation | MPLv2.0 | **No** (Never bundled) | `sudo apt install libreoffice-core libreoffice-writer libreoffice-calc libreoffice-impress` |
| **Tesseract OCR** (`tesseract`) | Tesseract OCR Project | Apache License 2.0 | **No** (Never bundled) | `sudo apt install tesseract-ocr tesseract-ocr-eng` |
