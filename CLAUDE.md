# CLAUDE.md — Quick Reference for AI Coding Assistants

Please read [`AGENTS.md`](AGENTS.md) and [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the complete architecture and security rules of **Docvera**.

## Quick Verification Workflow
Before considering any task complete, execute:
```bash
find src bin public views tests config -name '*.php' -print0 | xargs -0 -n1 php -l
php bin/capabilities
php bin/install --check-only
php bin/test
```

## Key Files Map
- **Entrypoints:** `public/index.php` (HTTP), `bin/capabilities`, `bin/install`, `bin/worker`, `bin/cleanup`, `bin/migrate`, `bin/test`
- **Tool Registry & Execution:** `src/Tools/ToolRegistry.php`, `src/Tools/ToolExecutionService.php`
- **Processing Drivers:** `src/Drivers/{QpdfDriver,PopplerDriver,GhostscriptDriver,NativePhpDriver,LibreOfficeDriver,TesseractOcrDriver}.php`
- **Security Boundaries:** `src/Security/UploadValidator.php`, `src/Security/SafeProcessRunner.php`, `src/Security/CsrfGuard.php`
- **Controllers:** `src/Http/Controller.php`, `src/Http/ApiController.php`, `src/Http/AdminController.php`, `src/Http/InstallController.php`
- **Persistence & Quotas:** `src/Database/Connection.php`, `src/Database/JobRepository.php`, `src/Database/PlatformRepository.php`, `src/Platform/EntitlementService.php`

Please read [`AGENTS.md`](AGENTS.md) and [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the complete architecture and security rules of **Docvera**.

## Quick Verification Workflow
Before considering any task complete, execute:
```bash
find src bin public views tests config -name '*.php' -print0 | xargs -0 -n1 php -l
php bin/capabilities
php bin/install --check-only
php bin/test
```

## Key Files Map
- **Entrypoints:** `public/index.php` (HTTP), `bin/capabilities`, `bin/install`, `bin/worker`, `bin/cleanup`, `bin/migrate`, `bin/test`
- **Tool Registry & Execution:** `src/Tools/ToolRegistry.php`, `src/Tools/ToolExecutionService.php`
- **Processing Drivers:** `src/Drivers/{QpdfDriver,PopplerDriver,GhostscriptDriver,NativePhpDriver,LibreOfficeDriver,TesseractOcrDriver}.php`
- **Security Boundaries:** `src/Security/UploadValidator.php`, `src/Security/SafeProcessRunner.php`, `src/Security/CsrfGuard.php`
- **Controllers:** `src/Http/Controller.php`, `src/Http/ApiController.php`, `src/Http/AdminController.php`, `src/Http/InstallController.php`
- **Persistence & Quotas:** `src/Database/Connection.php`, `src/Database/JobRepository.php`, `src/Database/PlatformRepository.php`, `src/Platform/EntitlementService.php`

