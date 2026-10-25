<?php

declare(strict_types=1);

namespace Docvera\Http;

use Docvera\Auth\Principal;
use Docvera\Core\Application;
use Docvera\Security\CsrfGuard;
use Docvera\Security\ValidationException;
use Docvera\Tools\ToolRegistry;
use Throwable;

final class Controller
{
    private Application $app;
    private ApiController $apiController;
    private AdminController $adminController;
    private InstallController $installController;

    public function __construct(Application $app)
    {
        $this->app = $app;
        $this->apiController = new ApiController($app);
        $this->adminController = new AdminController($app);
        $this->installController = new InstallController($app);
    }

    public function getInstallController(): InstallController
    {
        return $this->installController;
    }

    public function handle(Request $request): never
    {
        $path = $request->path;
        $method = $request->method;

        // 1. Versioned REST API (/api/v1/*) and Health Probe (/healthz)
        if ($method === 'GET' && $path === '/healthz') {
            $report = $this->app->capabilities->toMachineReadableReport();
            $coreReady = (bool) ($report['healthy'] ?? $report['core_ready'] ?? false);
            Response::json([
                'ok' => $coreReady,
                'status' => $coreReady ? 'healthy' : 'degraded',
                'version' => (string) $this->app->config->get('version', '2.0.0'),
                'core_ready' => $coreReady,
            ], $coreReady ? 200 : 503);
        }

        if (str_starts_with($path, '/api/v1/')) {
            $this->apiController->handle($request);
        }

        // 2. Billing Webhooks (Stateless, signature-verified)
        if ($method === 'POST' && preg_match('#^/webhooks/billing/([a-z0-9_\-]+)$#', $path, $m)) {
            $this->webhookBillingAction($request, $m[1]);
        }

        CsrfGuard::ensureSession();

        // Opportunistic lightweight TTL cleanup (runs roughly 5% of requests)
        if (random_int(1, 20) === 1) {
            try {
                $this->app->cleanup->purgeExpired();
            } catch (Throwable) {
                // Ignore background cleanup errors during request handling
            }
        }

        $principal = $this->app->auth->resolveSessionPrincipal();

        // 2b. Guided Web Installer (/install)
        if ($path === '/install') {
            $this->installController->handle($request, fn(string $v, array $d): string => $this->renderView($v, $d, $principal));
        }

        $basePath = (string) $this->app->config->get('paths.base');
        if (!$this->app->isInstalled() && !is_file($basePath . '/.env') && $method === 'GET' && $path === '/') {
            Response::redirect('/install');
        }

        // 3. SEO Sitemap & Robots
        if ($method === 'GET' && $path === '/sitemap.xml') {
            $this->sitemapAction();
        }
        if ($method === 'GET' && $path === '/robots.txt') {
            $this->robotsAction();
        }

        // 4. Authentication Routes
        if ($path === '/login') {
            $this->loginAction($request, $principal);
        }
        if ($path === '/register') {
            $this->registerAction($request, $principal);
        }
        if ($path === '/logout') {
            $this->app->auth->logout();
            Response::redirect('/');
        }

        // 5. Administration Layer (/admin & /admin/*)
        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            $this->adminController->handle($request, $principal, fn(string $v, array $d): string => $this->renderView($v, $d, $principal));
        }

        // 6. Enforce Private Deployment Mode on public tool routes for unauthenticated guests
        $deploymentMode = $this->app->entitlements->getDeploymentMode();
        if ($deploymentMode === 'private' && $principal->isGuest()) {
            if ($request->wantsJson()) {
                Response::json([
                    'ok' => false,
                    'error' => 'This Docvera instance is configured as a private internal workspace. Authentication is required.',
                ], 401);
            }
            Response::redirect('/login?notice=private_workspace');
        }

        // 7. User Dashboard & Account Management
        if ($path === '/dashboard' || str_starts_with($path, '/dashboard/')) {
            $this->dashboardAction($request, $principal);
        }

        // 8. Pricing & Subscription Checkout
        if ($method === 'GET' && $path === '/pricing') {
            $this->pricingAction($principal);
        }
        if ($method === 'POST' && $path === '/billing/checkout') {
            $this->billingCheckoutAction($request, $principal);
        }
        if ($method === 'POST' && $path === '/billing/cancel') {
            $this->billingCancelAction($request, $principal);
        }

        // 9. Developer REST API Documentation
        if ($method === 'GET' && $path === '/developers/api') {
            $this->apiDocsPageAction($principal);
        }

        // 10. CMS Pages (/p/{slug})
        if ($method === 'GET' && preg_match('#^/p/([a-z0-9\-]+)$#', $path, $m)) {
            $this->cmsPageAction($m[1], $principal);
        }

        // 11. Core Workbench Routes
        if ($method === 'GET' && $path === '/') {
            $this->homeAction($request, $principal);
        }

        if ($method === 'POST' && $path === '/router/inspect') {
            $this->inspectRouterFileAction($request, $principal);
        }

        if ($method === 'GET' && $path === '/system/capabilities') {
            $this->capabilitiesPageAction($principal);
        }

        if (preg_match('#^/tools/([a-z0-9\-]+)$#', $path, $m) && $method === 'GET') {
            $this->toolAction($request, $principal, $m[1]);
        }

        if (preg_match('#^/tools/([a-z0-9\-]+)/process$#', $path, $m) && $method === 'POST') {
            $this->processToolAction($request, $principal, $m[1]);
        }

        if (preg_match('#^/jobs/(job_[a-f0-9]{32})$#', $path, $m) && $method === 'GET') {
            $this->jobResultAction($request, $principal, $m[1]);
        }

        if (preg_match('#^/jobs/(job_[a-f0-9]{32})/download$#', $path, $m) && $method === 'GET') {
            $this->downloadJobAction($request, $principal, $m[1]);
        }

        if (preg_match('#^/jobs/(job_[a-f0-9]{32})/delete$#', $path, $m) && $method === 'POST') {
            $this->deleteJobAction($request, $principal, $m[1]);
        }

        $this->notFoundAction($principal);
    }

    private function homeAction(Request $request, Principal $principal): never
    {
        $allTools = $this->app->registry->all();
        // Only display tools enabled in Admin Tool Management on public directory
        $tools = array_filter($allTools, static fn($t): bool => $t->enabled);
        $categories = ToolRegistry::getCategoryLabels();
        $searchQuery = trim((string) $request->input('q', ''));
        $selectedCategory = trim((string) $request->input('category', 'all'));
        $statusFilter = trim((string) $request->input('status', 'all'));

        $stagedManifest = null;
        $compatibleTools = [];
        $stageId = trim((string) $request->input('stage_id', ''));
        if ($stageId !== '') {
            $sessionHash = $this->app->storage->hashToken(CsrfGuard::getSessionIdentity());
            $stagedManifest = $this->app->storage->resolveStagedRouterFile($stageId, $sessionHash);
            if ($stagedManifest !== null) {
                $compatibleTools = array_values(array_filter(
                    $this->app->registry->findCompatibleForMime((string) $stagedManifest['mime']),
                    static fn($t): bool => $t->enabled
                ));
            }
        }

        $filteredTools = [];
        foreach ($tools as $tool) {
            if ($selectedCategory !== 'all' && $selectedCategory !== '' && $tool->category !== $selectedCategory) {
                continue;
            }
            if ($statusFilter === 'available' && !$tool->availability->available) {
                continue;
            }
            if ($searchQuery !== '') {
                $haystack = strtolower($tool->name . ' ' . $tool->description . ' ' . implode(' ', $tool->supportedExtensions));
                if (!str_contains($haystack, strtolower($searchQuery))) {
                    continue;
                }
            }
            $filteredTools[$tool->id] = $tool;
        }

        $capabilities = $this->app->capabilities->detectAll();
        $availableCount = count(array_filter($tools, static fn($t): bool => $t->availability->available));
        $entitlements = $this->app->entitlements->resolvePlanEntitlements($principal);

        $siteName = (string) $this->app->platform->getSetting('site_name', 'Docvera');
        $siteTagline = (string) $this->app->platform->getSetting('site_tagline', 'Self-Hosted PDF & Document Workbench');

        $html = $this->renderView('home', [
            'pageTitle' => $siteName . ' — ' . $siteTagline,
            'metaDescription' => (string) $this->app->platform->getSetting('seo_default_description', ''),
            'tools' => $tools,
            'filteredTools' => $filteredTools,
            'categories' => $categories,
            'searchQuery' => $searchQuery,
            'selectedCategory' => $selectedCategory,
            'statusFilter' => $statusFilter,
            'stagedManifest' => $stagedManifest,
            'compatibleTools' => $compatibleTools,
            'capabilities' => $capabilities,
            'availableCount' => $availableCount,
            'totalToolsCount' => count($tools),
            'retentionMinutes' => (int) ($entitlements['retention_minutes'] ?? 60),
            'maxFileSizeMb' => (int) ($entitlements['max_file_size_mb'] ?? 50),
        ], $principal);

        Response::html($html);
    }

    private function inspectRouterFileAction(Request $request, Principal $principal): never
    {
        if (!CsrfGuard::verify((string) $request->input('_csrf', ''))) {
            if ($request->wantsJson()) {
                Response::json(['ok' => false, 'error' => 'Invalid security token. Please refresh the page and try again.'], 403);
            }
            Response::redirect('/?error=csrf');
        }

        try {
            $files = $request->uploadedFiles('file');
            if ($files === []) {
                $files = $request->uploadedFiles('files');
            }
            $allowedAllMimes = [
                'application/pdf',
                'image/jpeg',
                'image/png',
                'text/plain',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                'application/vnd.ms-powerpoint',
            ];

            $ent = $this->app->entitlements->resolvePlanEntitlements($principal);
            $maxBytes = max(1, (int) ($ent['max_file_size_mb'] ?? 50)) * 1024 * 1024;

            $validated = $this->app->validator->validateFiles($files, $allowedAllMimes, 1, 1, $maxBytes);
            $vFile = $validated[0];

            $inspection = [
                'mime' => $vFile['mime'],
                'extension' => strtoupper($vFile['extension']),
                'size_bytes' => $vFile['size'],
            ];

            if ($vFile['mime'] === 'application/pdf') {
                $pdfInfo = $this->app->inspector->inspect($vFile['path']);
                $inspection = array_merge($inspection, $pdfInfo);
            } elseif (in_array($vFile['mime'], ['image/jpeg', 'image/png'], true)) {
                $imgSize = @getimagesize($vFile['path']);
                if (is_array($imgSize)) {
                    $inspection['image_width'] = (int) $imgSize[0];
                    $inspection['image_height'] = (int) $imgSize[1];
                }
            }

            $sessionHash = $this->app->storage->hashToken(CsrfGuard::getSessionIdentity());
            $manifest = $this->app->storage->stageRouterFile($vFile, $sessionHash, $inspection);
            $compatible = array_values(array_filter(
                $this->app->registry->findCompatibleForMime($vFile['mime']),
                static fn($t): bool => $t->enabled
            ));

            if ($request->wantsJson()) {
                $toolsPayload = [];
                foreach ($compatible as $tool) {
                    $toolsPayload[] = [
                        'id' => $tool->id,
                        'name' => $tool->name,
                        'description' => $tool->description,
                        'category' => $tool->category,
                        'min_files' => $tool->minFiles,
                        'available' => $tool->availability->available,
                        'driver' => $tool->availability->activeDriver,
                        'unavailable_reason' => $tool->availability->unavailableReason,
                        'url' => '/tools/' . $tool->id . '?stage_id=' . $manifest['stage_id'],
                    ];
                }

                Response::json([
                    'ok' => true,
                    'stage_id' => $manifest['stage_id'],
                    'safe_name' => $manifest['safe_name'],
                    'mime' => $manifest['mime'],
                    'size' => $manifest['size'],
                    'inspection' => $inspection,
                    'compatible_tools' => $toolsPayload,
                ]);
            }

            Response::redirect('/?stage_id=' . urlencode((string) $manifest['stage_id']));
        } catch (ValidationException $ve) {
            if ($request->wantsJson()) {
                Response::json(['ok' => false, 'error' => $ve->getMessage()], 422);
            }
            Response::redirect('/?router_error=' . urlencode($ve->getMessage()));
        }
    }

    private function toolAction(Request $request, Principal $principal, string $slug, ?string $errorMessage = null, int $statusCode = 200): never
    {
        $tool = $this->app->registry->get($slug);
        if ($tool === null || (!$tool->enabled && !$principal->isAdmin())) {
            $this->notFoundAction($principal);
        }

        $decision = $this->app->entitlements->evaluateToolAccess($principal, $tool, false);

        $stagedManifest = null;
        $stageId = trim((string) $request->input('stage_id', ''));
        if ($stageId !== '') {
            $sessionHash = $this->app->storage->hashToken(CsrfGuard::getSessionIdentity());
            $resolved = $this->app->storage->resolveStagedRouterFile($stageId, $sessionHash);
            if ($resolved !== null && $tool->acceptsMime((string) $resolved['mime'])) {
                $stagedManifest = $resolved;
            }
        }

        $baseUrl = rtrim((string) $this->app->config->get('url', 'http://localhost:8080'), '/');
        $seoTitle = ($tool->seoTitle !== null && $tool->seoTitle !== '')
            ? $tool->seoTitle
            : ($tool->name . ' — ' . (string) $this->app->platform->getSetting('seo_default_title_suffix', 'Docvera Document Workbench'));
        $seoDesc = ($tool->seoDescription !== null && $tool->seoDescription !== '')
            ? $tool->seoDescription
            : $tool->description;
        $canonicalUrl = ($tool->seoCanonical !== null && $tool->seoCanonical !== '')
            ? $tool->seoCanonical
            : ($baseUrl . '/tools/' . $tool->id);
        $indexable = ((bool) $this->app->platform->getSetting('seo_site_indexable', true)) && $tool->seoIndexable && $this->app->entitlements->getDeploymentMode() !== 'private';

        $jsonLd = $indexable ? [
            '@context' => 'https://schema.org',
            '@type' => 'WebApplication',
            'name' => $tool->name . ' — ' . (string) $this->app->platform->getSetting('site_name', 'Docvera'),
            'description' => $seoDesc,
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'All',
            'url' => $canonicalUrl,
        ] : null;

        $html = $this->renderView('tool', [
            'pageTitle' => $seoTitle,
            'metaDescription' => $seoDesc,
            'canonicalUrl' => $canonicalUrl,
            'seoIndexable' => $indexable,
            'jsonLd' => $jsonLd,
            'tool' => $tool,
            'decision' => $decision,
            'stagedManifest' => $stagedManifest,
            'errorMessage' => $errorMessage,
            'allTools' => array_filter($this->app->registry->all(), static fn($t): bool => $t->enabled),
            'categories' => ToolRegistry::getCategoryLabels(),
            'maxFileSizeMb' => $decision->maxFileSizeMb,
            'retentionMinutes' => $decision->retentionMinutes,
            'processingLocalityNotice' => (string) $this->app->platform->getSetting('processing_locality_notice', 'This server, in a private folder per job'),
        ], $principal);

        Response::html($html, $statusCode);
    }

    private function processToolAction(Request $request, Principal $principal, string $slug): never
    {
        $tool = $this->app->registry->get($slug);
        if ($tool === null) {
            if ($request->wantsJson()) {
                Response::json(['ok' => false, 'error' => 'Requested tool does not exist.'], 404);
            }
            $this->notFoundAction($principal);
        }

        if (!CsrfGuard::verify((string) $request->input('_csrf', ''))) {
            if ($request->wantsJson()) {
                Response::json(['ok' => false, 'error' => 'Invalid security token. Please reload the page and try again.'], 403);
            }
            $this->toolAction($request, $principal, $slug, 'Security session expired. Please submit the form again.', 403);
        }

        try {
            $rawFiles = $request->uploadedFiles('files');

            // Prepend staged file from Homepage Smart Router if present
            $stageId = trim((string) $request->input('stage_id', ''));
            if ($stageId !== '') {
                $sessionHash = $this->app->storage->hashToken(CsrfGuard::getSessionIdentity());
                $staged = $this->app->storage->resolveStagedRouterFile($stageId, $sessionHash);
                if ($staged !== null && isset($staged['path']) && is_file((string) $staged['path'])) {
                    array_unshift($rawFiles, [
                        'path' => (string) $staged['path'],
                        'original_name' => (string) $staged['safe_name'],
                        'size' => (int) $staged['size'],
                        'error' => UPLOAD_ERR_OK,
                    ]);
                }
            }

            $options = [];
            foreach ($tool->options as $optField) {
                $val = $request->input($optField->name, $optField->default);
                $options[$optField->name] = $val;
            }
            if ($request->input('text_content') !== null) {
                $options['text_content'] = (string) $request->input('text_content');
            }

            $jobRecord = $this->app->executor->execute(
                $tool->id,
                $rawFiles,
                $options,
                CsrfGuard::getSessionIdentity(),
                $principal,
                'web',
                false
            );

            $jobId = (string) $jobRecord['id'];
            $rawToken = (string) $jobRecord['download_token'];

            $_SESSION['job_tokens'] = $_SESSION['job_tokens'] ?? [];
            $_SESSION['job_tokens'][$jobId] = $rawToken;

            $resultUrl = '/jobs/' . $jobId . '?token=' . urlencode($rawToken);

            if ($request->wantsJson()) {
                Response::json([
                    'ok' => true,
                    'job_id' => $jobId,
                    'redirect_url' => $resultUrl,
                    'download_url' => '/jobs/' . $jobId . '/download?token=' . urlencode($rawToken),
                    'output_filename' => $jobRecord['output_filename'],
                    'output_bytes' => $jobRecord['output_bytes'],
                    'summary' => $jobRecord['summary'],
                ]);
            }

            Response::redirect($resultUrl);
        } catch (ValidationException $ve) {
            $status = $ve->getCode() >= 400 && $ve->getCode() < 600 ? $ve->getCode() : 422;
            if ($request->wantsJson()) {
                Response::json(['ok' => false, 'error' => $ve->getMessage()], $status);
            }
            $this->toolAction($request, $principal, $slug, $ve->getMessage(), $status);
        } catch (Throwable) {
            if ($request->wantsJson()) {
                Response::json(['ok' => false, 'error' => 'An unexpected processing error occurred. Temporary files have been cleaned up.'], 500);
            }
            $this->toolAction($request, $principal, $slug, 'An unexpected processing error occurred. Temporary files have been cleaned up.', 500);
        }
    }

    private function jobResultAction(Request $request, Principal $principal, string $jobId): never
    {
        $job = $this->app->jobs->find($jobId);
        if ($job === null) {
            $this->notFoundAction($principal);
        }

        $token = (string) $request->input('token', $_SESSION['job_tokens'][$jobId] ?? '');
        if (!$this->isAuthorizedForJob($job, $principal, $token)) {
            $html = $this->renderView('error', [
                'pageTitle' => 'Access Denied — Docvera',
                'statusCode' => 403,
                'heading' => 'Access to this document job is restricted',
                'message' => 'Temporary document jobs are strictly bound to the user account, session, and cryptographic token that created them.',
            ], $principal);
            Response::html($html, 403);
        }

        $tool = $this->app->registry->get((string) $job['tool_id']);
        $fileStillOnDisk = false;
        if (($job['deleted_at'] ?? null) === null && !empty($job['output_filename'])) {
            $outPath = $this->app->storage->getJobOutputPath($jobId, (string) $job['output_filename']);
            $fileStillOnDisk = is_file($outPath);
        }

        $html = $this->renderView('job_result', [
            'pageTitle' => ($tool ? $tool->name : 'Document Result') . ' — Docvera',
            'seoIndexable' => false,
            'job' => $job,
            'tool' => $tool,
            'token' => $token,
            'fileStillOnDisk' => $fileStillOnDisk,
            'allowDeleteNow' => (bool) $this->app->platform->getSetting('allow_delete_now', true),
        ], $principal);

        Response::html($html);
    }

    private function downloadJobAction(Request $request, Principal $principal, string $jobId): never
    {
        $job = $this->app->jobs->find($jobId);
        if ($job === null) {
            $this->notFoundAction($principal);
        }

        $token = (string) $request->input('token', $_SESSION['job_tokens'][$jobId] ?? '');
        if (!$this->isAuthorizedForJob($job, $principal, $token)) {
            Response::html($this->renderView('error', [
                'pageTitle' => 'Access Denied — Docvera',
                'statusCode' => 403,
                'heading' => 'Access to this document job is restricted',
                'message' => 'You do not have permission to download this file.',
            ], $principal), 403);
        }

        if (($job['deleted_at'] ?? null) !== null || empty($job['output_filename'])) {
            Response::html($this->renderView('error', [
                'pageTitle' => 'File Purged — Docvera',
                'statusCode' => 410,
                'heading' => 'This temporary file has been permanently deleted',
                'message' => 'The output file was either deleted manually or expired according to the server retention policy.',
            ], $principal), 410);
        }

        $outPath = $this->app->storage->getJobOutputPath($jobId, (string) $job['output_filename']);
        if (!is_file($outPath) || !is_readable($outPath)) {
            Response::html($this->renderView('error', [
                'pageTitle' => 'File Expired — Docvera',
                'statusCode' => 410,
                'heading' => 'This temporary file is no longer on disk',
                'message' => 'The temporary workspace for this job has already been cleaned up.',
            ], $principal), 410);
        }

        Response::download(
            $outPath,
            (string) $job['output_filename'],
            (string) ($job['output_mime'] ?: 'application/octet-stream')
        );
    }

    private function deleteJobAction(Request $request, Principal $principal, string $jobId): never
    {
        if (!CsrfGuard::verify((string) $request->input('_csrf', ''))) {
            if ($request->wantsJson()) {
                Response::json(['ok' => false, 'error' => 'Invalid CSRF token.'], 403);
            }
            Response::redirect('/');
        }

        $job = $this->app->jobs->find($jobId);
        if ($job === null) {
            if ($request->wantsJson()) {
                Response::json(['ok' => false, 'error' => 'Job not found.'], 404);
            }
            $this->notFoundAction($principal);
        }

        $token = (string) $request->input('token', $_SESSION['job_tokens'][$jobId] ?? '');
        if (!$this->isAuthorizedForJob($job, $principal, $token)) {
            if ($request->wantsJson()) {
                Response::json(['ok' => false, 'error' => 'Forbidden.'], 403);
            }
            Response::html('Forbidden', 403);
        }

        $this->app->cleanup->deleteJobNow($jobId);

        if ($request->wantsJson()) {
            Response::json([
                'ok' => true,
                'job_id' => $jobId,
                'status' => 'deleted_by_user',
                'message' => 'All temporary input and output files for this operation have been permanently wiped from the server.',
            ]);
        }

        $returnTo = (string) $request->input('return_to', '');
        if ($returnTo === 'dashboard') {
            Response::redirect('/dashboard?deleted=' . urlencode($jobId));
        }

        Response::redirect('/jobs/' . $jobId . ($token !== '' ? '?token=' . urlencode($token) : ''));
    }

    private function loginAction(Request $request, Principal $principal): never
    {
        if ($principal->isAuthenticated() && $request->method === 'GET') {
            Response::redirect('/dashboard');
        }

        $errorMessage = null;
        $notice = isset($_GET['notice']) ? (string) $_GET['notice'] : null;

        if ($request->method === 'POST') {
            if (!CsrfGuard::verify((string) $request->input('_csrf', ''))) {
                $errorMessage = 'Security token expired. Please submit the form again.';
            } else {
                try {
                    $this->app->auth->login(
                        (string) $request->input('email', ''),
                        (string) $request->input('password', ''),
                        (string) ($request->server['REMOTE_ADDR'] ?? '127.0.0.1')
                    );
                    Response::redirect('/dashboard');
                } catch (ValidationException $ve) {
                    $errorMessage = $ve->getMessage();
                }
            }
        }

        $regOpen = $this->app->auth->isRegistrationOpen();

        $html = $this->renderView('auth_login', [
            'pageTitle' => 'Sign In — Docvera',
            'seoIndexable' => false,
            'errorMessage' => $errorMessage,
            'notice' => $notice,
            'registrationOpen' => $regOpen,
            'registrationEnabled' => $regOpen,
        ], $principal);

        Response::html($html);
    }

    private function registerAction(Request $request, Principal $principal): never
    {
        if ($principal->isAuthenticated() && $request->method === 'GET') {
            Response::redirect('/dashboard');
        }

        $regOpen = $this->app->auth->isRegistrationOpen();

        $errorMessage = null;
        if ($request->method === 'POST') {
            if (!CsrfGuard::verify((string) $request->input('_csrf', ''))) {
                $errorMessage = 'Security token expired. Please submit the form again.';
            } else {
                try {
                    $user = $this->app->auth->register(
                        (string) $request->input('name', ''),
                        (string) $request->input('email', ''),
                        (string) $request->input('password', ''),
                        'user',
                        null,
                        false,
                        (string) ($request->server['REMOTE_ADDR'] ?? '127.0.0.1')
                    );
                    $_SESSION['auth_user_id'] = (string) $user['id'];
                    Response::redirect('/dashboard');
                } catch (ValidationException $ve) {
                    $errorMessage = $ve->getMessage();
                }
            }
        }

        $html = $this->renderView('auth_register', [
            'pageTitle' => 'Create Account — Docvera',
            'seoIndexable' => false,
            'errorMessage' => $errorMessage,
            'registrationOpen' => $regOpen,
            'registrationEnabled' => $regOpen,
        ], $principal);

        Response::html($html);
    }

    private function dashboardAction(Request $request, Principal $principal): never
    {
        if ($principal->isGuest() || $principal->userId === null) {
            Response::redirect('/login');
        }

        $flashMessage = isset($_GET['msg']) ? trim((string) $_GET['msg']) : '';
        $errorMessage = null;
        $newApiKeyPlain = $_SESSION['flash_new_api_key'] ?? null;
        unset($_SESSION['flash_new_api_key']);

        if ($request->method === 'POST') {
            if (!CsrfGuard::verify((string) $request->input('_csrf', ''))) {
                $errorMessage = 'Invalid security token. Please try again.';
            } else {
                $dashAction = (string) $request->input('dashboard_action', '');
                if ($dashAction === '') {
                    $dashAction = match ($request->path) {
                        '/dashboard/api-keys/create' => 'create_api_key',
                        '/dashboard/api-keys/rotate' => 'rotate_api_key',
                        '/dashboard/api-keys/revoke' => 'revoke_api_key',
                        '/dashboard/security/password' => 'update_password',
                        '/dashboard/profile' => 'update_profile',
                        default => '',
                    };
                }
                try {
                    if ($dashAction === 'create_api_key') {
                        $ent = $this->app->entitlements->resolvePlanEntitlements($principal);
                        if (!(bool) ($ent['api_access'] ?? false) && !$principal->isAdmin()) {
                            throw new ValidationException('Your current plan does not permit creating API keys. Upgrade to a plan with API access.', 403);
                        }
                        $scopesInput = $request->input('scopes', ['tools:execute', 'jobs:read', 'jobs:download', 'jobs:delete']);
                        $scopes = is_array($scopesInput) ? array_values(array_map('strval', $scopesInput)) : ['tools:execute', 'jobs:read', 'jobs:download', 'jobs:delete'];
                        $keyName = trim((string) ($request->input('key_name') ?? $request->input('label', 'Production Key'))) ?: 'Production Key';
                        $created = $this->app->auth->createApiKey(
                            $principal->userId,
                            $keyName,
                            $scopes,
                            (int) ($ent['api_rate_limit_per_min'] ?? 60)
                        );
                        $_SESSION['flash_new_api_key'] = $created['plain_secret'];
                        Response::redirect('/dashboard?msg=' . urlencode('API key created. Copy your secret token below — it will only be shown once.'));
                    } elseif ($dashAction === 'rotate_api_key') {
                        $rotated = $this->app->auth->rotateApiKey((string) $request->input('key_id', ''), $principal->userId);
                        $_SESSION['flash_new_api_key'] = $rotated['plain_secret'];
                        Response::redirect('/dashboard?msg=' . urlencode('API key rotated. Your previous secret has been revoked and the new secret is displayed once below.'));
                    } elseif ($dashAction === 'revoke_api_key') {
                        $this->app->auth->revokeApiKey((string) $request->input('key_id', ''), $principal->userId);
                        Response::redirect('/dashboard?msg=' . urlencode('API key permanently revoked.'));
                    } elseif ($dashAction === 'update_profile') {
                        $user = $this->app->platform->findUserById($principal->userId);
                        if ($user !== null) {
                            $newName = trim((string) $request->input('name', $user['name']));
                            if ($newName === '') {
                                throw new ValidationException('Display name cannot be empty.');
                            }
                            $user['name'] = $newName;
                            $user['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                            $this->app->platform->saveUser($user);
                        }
                        Response::redirect('/dashboard?msg=' . urlencode('Account profile updated.'));
                    } elseif ($dashAction === 'update_password') {
                        $this->app->auth->updatePassword(
                            $principal->userId,
                            (string) $request->input('current_password', ''),
                            (string) $request->input('new_password', '')
                        );
                        Response::redirect('/dashboard?msg=' . urlencode('Password updated.'));
                    }
                } catch (ValidationException $ve) {
                    $errorMessage = $ve->getMessage();
                }
            }
        }

        $sessionHash = $this->app->storage->hashToken($principal->sessionIdentity);
        $recentJobs = $this->app->jobs->findByUserOrSession($principal->userId, $sessionHash, 50);
        foreach ($recentJobs as &$j) {
            $jId = (string) $j['id'];
            $j['file_on_disk'] = ($j['deleted_at'] ?? null) === null
                && !empty($j['output_filename'])
                && is_file($this->app->storage->getJobOutputPath($jId, (string) $j['output_filename']));
        }
        unset($j);

        $usage = $this->app->entitlements->getUsageSummary($principal);
        $plan = $this->app->entitlements->resolvePlanForPrincipal($principal);
        $subscription = $this->app->platform->findLatestSubscriptionForUser($principal->userId);
        $payments = $this->app->platform->listPayments($principal->userId);
        $apiKeys = $this->app->platform->listApiKeys($principal->userId);
        $apiLogs = $this->app->platform->listApiAuditLogs($principal->userId, 25);

        $html = $this->renderView('dashboard', [
            'pageTitle' => 'User Dashboard — Docvera',
            'seoIndexable' => false,
            'principal' => $principal,
            'usage' => $usage,
            'plan' => $plan,
            'subscription' => $subscription,
            'payments' => $payments,
            'recentJobs' => $recentJobs,
            'apiKeys' => $apiKeys,
            'apiLogs' => $apiLogs,
            'newApiKeyPlain' => $newApiKeyPlain,
            'flashMessage' => $flashMessage,
            'errorMessage' => $errorMessage,
            'billingEnabled' => $this->app->entitlements->isBillingEnabled(),
            'allowDeleteNow' => (bool) $this->app->platform->getSetting('allow_delete_now', true),
        ], $principal);

        Response::html($html);
    }

    private function pricingAction(Principal $principal, ?string $errorMessage = null, ?array $checkoutSession = null): never
    {
        $plans = $this->app->platform->listPlans(true);
        $billingEnabled = $this->app->entitlements->isBillingEnabled();
        $providers = $this->app->billing->getProviderStatuses();
        $anyConfigured = $this->app->billing->isAnyProviderConfigured();

        $html = $this->renderView('pricing', [
            'pageTitle' => 'Plans & Quotas — Docvera',
            'plans' => $plans,
            'principal' => $principal,
            'billingEnabled' => $billingEnabled,
            'providers' => $providers,
            'anyProviderConfigured' => $anyConfigured,
            'errorMessage' => $errorMessage,
            'checkoutSession' => $checkoutSession,
        ], $principal);

        Response::html($html);
    }

    private function billingCheckoutAction(Request $request, Principal $principal): never
    {
        if ($principal->isGuest() || $principal->userId === null) {
            Response::redirect('/login?notice=checkout_requires_account');
        }
        if (!CsrfGuard::verify((string) $request->input('_csrf', ''))) {
            $this->pricingAction($principal, 'Invalid security token. Please try again.');
        }

        try {
            $planId = (string) $request->input('plan_id', 'pro');
            $provider = (string) $request->input('provider', 'stripe');
            $interval = (string) $request->input('interval', 'monthly');
            $baseUrl = rtrim((string) $this->app->config->get('url', 'http://localhost:8080'), '/');

            $result = $this->app->billing->startCheckout(
                $principal->userId,
                $planId,
                $provider,
                $interval,
                $baseUrl . '/dashboard'
            );

            if ($request->wantsJson()) {
                Response::json(['ok' => true, 'checkout' => $result['checkout'], 'subscription' => $result['subscription_record']]);
            }

            $this->pricingAction($principal, null, $result);
        } catch (ValidationException $ve) {
            if ($request->wantsJson()) {
                Response::json(['ok' => false, 'error' => $ve->getMessage()], 422);
            }
            $this->pricingAction($principal, $ve->getMessage());
        }
    }

    private function billingCancelAction(Request $request, Principal $principal): never
    {
        if ($principal->isGuest() || $principal->userId === null) {
            Response::redirect('/login');
        }
        if (!CsrfGuard::verify((string) $request->input('_csrf', ''))) {
            Response::redirect('/dashboard?msg=' . urlencode('Invalid security token.'));
        }

        try {
            $subId = (string) $request->input('subscription_id', '');
            $immediate = (string) $request->input('cancel_mode', 'period_end') === 'immediate';
            $this->app->billing->cancelSubscription($subId, $principal->userId, $immediate);
            Response::redirect('/dashboard?msg=' . urlencode($immediate ? 'Subscription canceled immediately.' : 'Subscription set to cancel at the end of the current billing period.'));
        } catch (ValidationException $ve) {
            Response::redirect('/dashboard?msg=' . urlencode($ve->getMessage()));
        }
    }

    private function webhookBillingAction(Request $request, string $provider): never
    {
        try {
            $rawBody = (string) file_get_contents('php://input');
            $result = $this->app->billing->handleWebhook($provider, $rawBody, $request->server);
            Response::json($result, 200);
        } catch (ValidationException $ve) {
            $code = ($ve->getCode() >= 400 && $ve->getCode() < 600) ? $ve->getCode() : 400;
            Response::json(['ok' => false, 'error' => $ve->getMessage()], $code);
        }
    }

    private function apiDocsPageAction(Principal $principal): never
    {
        $endpoints = $this->app->apiDocs->getEndpointsDocumentation();
        $errors = $this->app->apiDocs->getErrorCodesReference();

        $html = $this->renderView('api_docs', [
            'pageTitle' => 'Developer REST API Documentation (/api/v1) — Docvera',
            'endpoints' => $endpoints,
            'errorCodes' => $errors,
            'principal' => $principal,
        ], $principal);

        Response::html($html);
    }

    private function cmsPageAction(string $slug, Principal $principal): never
    {
        $page = $this->app->platform->getCmsPage($slug);
        if ($page === null || (!$page['is_published'] && !$principal->isAdmin())) {
            $this->notFoundAction($principal);
        }

        $html = $this->renderView('page', [
            'pageTitle' => (string) $page['title'] . ' — Docvera',
            'metaDescription' => (string) ($page['meta_description'] ?? ''),
            'page' => $page,
            'retentionMinutes' => (int) $this->app->platform->getSetting('default_retention_minutes', 60),
            'localityNotice' => (string) $this->app->platform->getSetting('processing_locality_notice', 'Server-side local execution'),
        ], $principal);

        Response::html($html);
    }

    private function capabilitiesPageAction(Principal $principal): never
    {
        $capabilities = $this->app->capabilities->detectAll();
        $tools = $this->app->registry->all();
        $report = $this->app->capabilities->toMachineReadableReport();

        $html = $this->renderView('capabilities', [
            'pageTitle' => 'Server Capabilities & Dependency Health — Docvera',
            'capabilities' => $capabilities,
            'tools' => $tools,
            'report' => $report,
        ], $principal);

        Response::html($html);
    }

    private function sitemapAction(): never
    {
        if (!(bool) $this->app->platform->getSetting('seo_sitemap_enabled', true)
            || $this->app->entitlements->getDeploymentMode() === 'private'
        ) {
            Response::xml('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>');
        }

        $base = rtrim((string) $this->app->config->get('url', 'http://localhost:8080'), '/');
        $urls = [$base . '/'];

        foreach ($this->app->registry->all() as $tool) {
            if ($tool->enabled && $tool->seoIndexable && $tool->seoInSitemap) {
                $urls[] = $base . '/tools/' . $tool->id;
            }
        }

        foreach ($this->app->platform->listCmsPages(true) as $p) {
            if (!empty($p['in_sitemap'])) {
                $urls[] = $base . '/p/' . $p['slug'];
            }
        }

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($urls as $u) {
            $xml .= "  <url><loc>" . htmlspecialchars($u, ENT_XML1, 'UTF-8') . "</loc></url>\n";
        }
        $xml .= "</urlset>\n";

        Response::xml($xml);
    }

    private function robotsAction(): never
    {
        $base = rtrim((string) $this->app->config->get('url', 'http://localhost:8080'), '/');
        $indexable = (bool) $this->app->platform->getSetting('seo_site_indexable', true)
            && $this->app->entitlements->getDeploymentMode() !== 'private';

        if (!$indexable) {
            Response::text("User-agent: *\nDisallow: /\n");
        }

        $txt = "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /dashboard\nDisallow: /jobs/\nDisallow: /api/\n";
        if ((bool) $this->app->platform->getSetting('seo_sitemap_enabled', true)) {
            $txt .= "Sitemap: " . $base . "/sitemap.xml\n";
        }
        Response::text($txt);
    }

    private function notFoundAction(?Principal $principal = null): never
    {
        $p = $principal ?? $this->app->auth->resolveSessionPrincipal();
        $html = $this->renderView('error', [
            'pageTitle' => 'Page Not Found — Docvera',
            'statusCode' => 404,
            'heading' => 'There’s nothing at this address',
            'message' => 'The link may be old, or the tool may have been renamed. Every tool is listed on the home page.',
        ], $p);
        Response::html($html, 404);
    }

    /**
     * @param array<string, mixed> $job
     */
    public function isAuthorizedForJob(array $job, Principal $principal, string $rawToken): bool
    {
        if ($principal->isAdmin()) {
            return true;
        }
        if ($principal->userId !== null && (string) ($job['user_id'] ?? '') === $principal->userId) {
            return true;
        }
        $sessionHash = $this->app->storage->hashToken($principal->sessionIdentity);
        if ((string) ($job['session_hash'] ?? '') !== '' && hash_equals((string) ($job['session_hash'] ?? ''), $sessionHash)) {
            return true;
        }
        if ($rawToken !== '') {
            $tokenHash = $this->app->storage->hashToken($rawToken);
            if ((string) ($job['download_token_hash'] ?? '') !== '' && hash_equals((string) ($job['download_token_hash'] ?? ''), $tokenHash)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function renderView(string $viewName, array $data = [], ?Principal $principal = null): string
    {
        $viewsDir = (string) $this->app->config->get('paths.views');
        $viewFile = $viewsDir . '/' . $viewName . '.php';
        $layoutFile = $viewsDir . '/layout.php';

        $principal = $principal ?? $this->app->auth->resolveSessionPrincipal();
        $csrfToken = CsrfGuard::getToken();
        $settings = $this->app->platform->getAllSettings();
        $appName = (string) ($settings['site_name'] ?? $this->app->config->get('name', 'Docvera'));
        $siteTagline = (string) ($settings['site_tagline'] ?? 'Self-Hosted PDF & Document Workbench');
        $accentColor = (string) ($settings['accent_color'] ?? '#c23b22');
        $appVersion = (string) $this->app->config->get('version', '2.0.0');
        $deploymentMode = $this->app->entitlements->getDeploymentMode();
        $billingEnabled = $this->app->entitlements->isBillingEnabled();
        $adsVisible = $this->app->entitlements->areAdsVisibleForPrincipal($principal);
        $adSlotHeader = $adsVisible ? (string) ($settings['ad_slot_header'] ?? '') : '';
        $adSlotSidebar = $adsVisible ? (string) ($settings['ad_slot_tool_sidebar'] ?? '') : '';
        $adSlotResult = $adsVisible ? (string) ($settings['ad_slot_result'] ?? '') : '';
        $footerNotice = (string) ($settings['footer_notice'] ?? '');
        $retentionMinutes = (int) ($data['retentionMinutes'] ?? $settings['default_retention_minutes'] ?? 60);

        extract($data, EXTR_SKIP);

        ob_start();
        require $viewFile;
        $content = (string) ob_get_clean();

        ob_start();
        require $layoutFile;
        return (string) ob_get_clean();
    }
}
