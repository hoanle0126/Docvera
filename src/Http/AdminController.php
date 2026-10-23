<?php

declare(strict_types=1);

namespace Docvera\Http;

use Docvera\Auth\Principal;
use Docvera\Core\Application;
use Docvera\Security\CsrfGuard;
use Docvera\Security\ValidationException;

/**
 * Handles the Administration Layer (/admin/*) with strict Administrator role enforcement.
 */
final class AdminController
{
    private Application $app;

    public function __construct(Application $app)
    {
        $this->app = $app;
    }

    public function assertAdmin(Principal $principal): void
    {
        if (!$principal->isAdmin()) {
            throw new ValidationException('Administrator privileges are required to access this control panel.', 403);
        }
    }

    /**
     * @param callable(string, array<string, mixed>): string $renderView
     */
    public function handle(Request $request, Principal $principal, callable $renderView): never
    {
        if (!$principal->isAdmin()) {
            if ($request->wantsJson()) {
                Response::json(['ok' => false, 'error' => 'Administrator privileges required.'], 403);
            }
            $html = $renderView('error', [
                'pageTitle' => 'Admin Access Required — Docvera',
                'statusCode' => 403,
                'heading' => 'Administrator privileges required',
                'message' => 'Your account does not have permission to view or modify the Docvera Administration Layer.',
            ]);
            Response::html($html, 403);
        }

        $section = $this->resolveSection($request->path);
        $flashMessage = isset($_GET['saved']) ? (string) $_GET['saved'] : null;
        $errorMessage = null;

        if ($request->method === 'POST') {
            if (!CsrfGuard::verify((string) $request->input('_csrf', ''))) {
                $errorMessage = 'Invalid security token. Please submit the form again.';
            } else {
                try {
                    $msg = $this->processPostAction($request, $section);
                    $this->app->registry->invalidateCache();
                    if ($request->wantsJson()) {
                        Response::json(['ok' => true, 'message' => $msg]);
                    }
                    Response::redirect('/admin/' . ($section === 'overview' ? '' : $section) . '?saved=' . urlencode($msg));
                } catch (ValidationException $ve) {
                    $errorMessage = $ve->getMessage();
                    if ($request->wantsJson()) {
                        Response::json(['ok' => false, 'error' => $errorMessage], 422);
                    }
                }
            }
        }

        $viewData = $this->buildAdminViewData($section, $principal, $flashMessage, $errorMessage);
        $html = $renderView('admin', $viewData);
        Response::html($html);
    }

    public function processPostAction(Request $request, string $section): string
    {
        $action = (string) ($request->input('form_action') ?? $request->input('admin_action', ''));
        $isFormSubmit = $request->input('form_action') !== null;

        switch ($action) {
            case 'save_deployment_mode':
                $mode = (string) $request->input('deployment_mode', 'saas');
                if (!in_array($mode, ['free_ads', 'saas', 'private'], true)) {
                    throw new ValidationException('Invalid deployment mode selected.');
                }
                $regModeInput = $request->input('registration_mode', null);
                $regEnabledInput = $request->input('registration_enabled', null);
                $regOpen = true;
                if ($regModeInput !== null) {
                    $regOpen = ((string) $regModeInput) === 'open';
                } elseif ($regEnabledInput !== null) {
                    $regOpen = (bool) $regEnabledInput;
                } elseif ($isFormSubmit) {
                    $regOpen = false;
                }

                if ($mode === 'free_ads') {
                    $this->app->platform->setSettings([
                        'deployment_mode' => 'free_ads',
                        'guest_tools_enabled' => $isFormSubmit ? (bool) $request->input('guest_tools_enabled', true) : true,
                        'registration_mode' => $regOpen ? 'open' : 'closed',
                        'registration_enabled' => $regOpen,
                        'billing_enabled' => false,
                        'ads_enabled' => true,
                    ]);
                } elseif ($mode === 'private') {
                    $this->app->platform->setSettings([
                        'deployment_mode' => 'private',
                        'guest_tools_enabled' => false,
                        'registration_mode' => 'closed',
                        'registration_enabled' => false,
                        'billing_enabled' => false,
                        'ads_enabled' => false,
                    ]);
                } else {
                    $this->app->platform->setSettings([
                        'deployment_mode' => 'saas',
                        'guest_tools_enabled' => $isFormSubmit ? (bool) $request->input('guest_tools_enabled', false) : (bool) $request->input('guest_tools_enabled', true),
                        'registration_mode' => $regOpen ? 'open' : 'closed',
                        'registration_enabled' => $regOpen,
                        'billing_enabled' => $isFormSubmit ? (bool) $request->input('billing_enabled', true) : true,
                        'ads_enabled' => (bool) $request->input('ads_enabled', false),
                    ]);
                }
                return sprintf('Deployment mode updated to "%s".', strtoupper($mode));

            case 'save_tool_override':
                $toolId = trim((string) $request->input('tool_id', ''));
                if ($this->app->registry->get($toolId) === null) {
                    throw new ValidationException('Unknown tool ID.');
                }
                $existing = $this->app->platform->getToolOverride($toolId) ?? [];

                $allowedPlansRaw = $request->input('allowed_plans', $existing['allowed_plans'] ?? []);
                $allowedPlans = is_array($allowedPlansRaw)
                    ? array_values(array_filter(array_map('strval', $allowedPlansRaw)))
                    : [];

                $defaultOptions = is_array($existing['default_options'] ?? null) ? $existing['default_options'] : [];
                $rawDefaultOpts = $request->input('default_options', null);
                if (is_array($rawDefaultOpts)) {
                    $defaultOptions = [];
                    foreach ($rawDefaultOpts as $k => $v) {
                        $defaultOptions[(string) $k] = $v;
                    }
                }

                $maxMb = $request->input('max_file_size_mb', $request->input('max_file_size_mb_override', $existing['max_file_size_mb'] ?? null));
                $maxFiles = $request->input('max_files', $request->input('max_files_override', $existing['max_files'] ?? null));

                $payload = array_merge($existing, [
                    'tool_id' => $toolId,
                    'is_enabled' => (bool) $request->input('is_enabled', false),
                    'guest_allowed' => (bool) $request->input('guest_allowed', false),
                    'allowed_plans' => $allowedPlans,
                    'max_file_size_mb' => $maxMb,
                    'max_files' => $maxFiles,
                    'api_enabled' => (bool) $request->input('api_enabled', false),
                    'default_options' => $defaultOptions,
                ]);

                if ($request->input('seo_title') !== null) {
                    $payload['seo_title'] = trim((string) $request->input('seo_title', ''));
                }
                if ($request->input('seo_description') !== null) {
                    $payload['seo_description'] = trim((string) $request->input('seo_description', ''));
                }
                if ($request->input('seo_canonical') !== null) {
                    $payload['seo_canonical'] = trim((string) $request->input('seo_canonical', ''));
                }
                if ($request->input('seo_indexable') !== null || $section === 'seo') {
                    $payload['seo_indexable'] = (bool) $request->input('seo_indexable', false);
                }
                if ($request->input('seo_in_sitemap') !== null || $section === 'seo') {
                    $payload['seo_in_sitemap'] = (bool) $request->input('seo_in_sitemap', false);
                }

                $this->app->platform->saveToolOverride($payload);
                $this->app->registry->invalidateCache();
                return sprintf('Tool configuration for "%s" saved.', $toolId);

            case 'create_user':
                $user = $this->app->auth->register(
                    (string) $request->input('name', ''),
                    (string) $request->input('email', ''),
                    (string) $request->input('password', ''),
                    (string) $request->input('role', 'user'),
                    (string) $request->input('plan_id', 'free'),
                    true
                );
                return sprintf('User "%s" (%s) created.', $user['name'], $user['email']);

            case 'update_user':
            case 'save_user':
                $userId = (string) $request->input('user_id', '');
                $user = $this->app->platform->findUserById($userId);
                if ($user === null) {
                    throw new ValidationException('User not found.');
                }
                $user['role'] = in_array($request->input('role'), ['user', 'admin'], true) ? (string) $request->input('role') : 'user';
                $user['plan_id'] = (string) $request->input('plan_id', 'free');
                $user['status'] = in_array($request->input('status'), ['active', 'suspended'], true) ? (string) $request->input('status') : 'active';
                $apiOverrideVal = (string) $request->input('api_enabled_override', '');
                $user['api_enabled_override'] = $apiOverrideVal === '' ? ($user['api_enabled_override'] ?? null) : ((int) $apiOverrideVal);
                $user['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                $this->app->platform->saveUser($user);
                return sprintf('Updated account "%s".', $user['email']);

            case 'save_plan':
                $rawPlanId = (string) ($request->input('plan_id') ?? $request->input('id', ''));
                $planId = preg_replace('/[^a-z0-9_\-]/', '', strtolower($rawPlanId)) ?: '';
                if ($planId === '') {
                    throw new ValidationException('Valid plan ID is required.');
                }
                $allowedToolsInput = trim((string) ($request->input('allowed_tools_csv') ?? $request->input('allowed_tools', '*')));
                $allowedTools = $allowedToolsInput === '*' || $allowedToolsInput === ''
                    ? ['*']
                    : array_values(array_filter(array_map('trim', explode(',', $allowedToolsInput))));

                $existingPlan = $this->app->platform->findPlan($planId) ?? [];

                $planData = [
                    'id' => $planId,
                    'name' => trim((string) $request->input('name', $existingPlan['name'] ?? ucfirst($planId))),
                    'slug' => $planId,
                    'description' => trim((string) $request->input('description', $existingPlan['description'] ?? '')),
                    'is_public' => $planId === 'guest' ? false : (bool) $request->input('is_public', false),
                    'is_default' => $planId === 'free',
                    'price_monthly_cents' => max(0, (int) $request->input('price_monthly_cents', 0)),
                    'price_yearly_cents' => max(0, (int) $request->input('price_yearly_cents', 0)),
                    'currency' => strtoupper(trim((string) $request->input('currency', $existingPlan['currency'] ?? 'USD'))),
                    'stripe_price_monthly_id' => trim((string) $request->input('stripe_price_monthly_id', $existingPlan['stripe_price_monthly_id'] ?? '')) ?: null,
                    'stripe_price_yearly_id' => trim((string) $request->input('stripe_price_yearly_id', $existingPlan['stripe_price_yearly_id'] ?? '')) ?: null,
                    'paypal_plan_monthly_id' => trim((string) $request->input('paypal_plan_monthly_id', $existingPlan['paypal_plan_monthly_id'] ?? '')) ?: null,
                    'paypal_plan_yearly_id' => trim((string) $request->input('paypal_plan_yearly_id', $existingPlan['paypal_plan_yearly_id'] ?? '')) ?: null,
                    'sort_order' => (int) $request->input('sort_order', $existingPlan['sort_order'] ?? 1),
                    'entitlements' => [
                        'ops_per_day' => max(1, (int) $request->input('ops_per_day', 25)),
                        'ops_per_month' => max(1, (int) $request->input('ops_per_month', 500)),
                        'max_file_size_mb' => max(1, (int) $request->input('max_file_size_mb', 50)),
                        'max_files_per_op' => max(1, (int) $request->input('max_files_per_op', 20)),
                        'max_pages_per_op' => max(1, (int) $request->input('max_pages_per_op', 500)),
                        'concurrent_jobs' => max(1, (int) $request->input('concurrent_jobs', 2)),
                        'ocr_allowed' => (bool) $request->input('ocr_allowed', false),
                        'batch_allowed' => (bool) $request->input('batch_allowed', false),
                        'allowed_tools' => $allowedTools,
                        'api_access' => (bool) $request->input('api_access', false),
                        'api_requests_per_day' => max(0, (int) $request->input('api_requests_per_day', 0)),
                        'api_requests_per_month' => max(0, (int) $request->input('api_requests_per_month', 0)),
                        'api_rate_limit_per_min' => max(1, (int) $request->input('api_rate_limit_per_min', 30)),
                        'retention_minutes' => max(1, (int) $request->input('retention_minutes', 60)),
                        'ads_visible' => (bool) $request->input('ads_visible', false),
                    ],
                ];
                $this->app->platform->savePlan($planData);
                return sprintf('Plan "%s" updated.', $planData['name']);

            case 'save_billing_settings':
                $updates = [
                    'billing_enabled' => (bool) $request->input('billing_enabled', false),
                    'stripe_enabled' => (bool) $request->input('stripe_enabled', false),
                    'stripe_mode' => $request->input('stripe_mode') === 'live' ? 'live' : 'test',
                    'stripe_publishable_key' => trim((string) $request->input('stripe_publishable_key', '')),
                    'paypal_enabled' => (bool) $request->input('paypal_enabled', false),
                    'paypal_mode' => $request->input('paypal_mode') === 'live' ? 'live' : 'sandbox',
                    'paypal_client_id' => trim((string) $request->input('paypal_client_id', '')),
                    'paypal_webhook_id' => trim((string) $request->input('paypal_webhook_id', '')),
                ];
                // Only overwrite secret fields if non-empty values are submitted
                if (trim((string) $request->input('stripe_secret_key', '')) !== '') {
                    $updates['stripe_secret_key'] = trim((string) $request->input('stripe_secret_key'));
                }
                if (trim((string) $request->input('stripe_webhook_secret', '')) !== '') {
                    $updates['stripe_webhook_secret'] = trim((string) $request->input('stripe_webhook_secret'));
                }
                if (trim((string) $request->input('paypal_client_secret', '')) !== '') {
                    $updates['paypal_client_secret'] = trim((string) $request->input('paypal_client_secret'));
                }
                if (trim((string) $request->input('paypal_webhook_secret', '')) !== '') {
                    $updates['paypal_webhook_secret'] = trim((string) $request->input('paypal_webhook_secret'));
                }
                $this->app->platform->setSettings($updates);
                return 'Billing provider settings updated.';

            case 'update_subscription':
                $subId = (string) $request->input('subscription_id', '');
                $sub = $this->app->platform->findSubscription($subId);
                if ($sub === null) {
                    throw new ValidationException('Subscription not found.');
                }
                $newStatus = (string) $request->input('status', 'active');
                $sub['status'] = $newStatus;
                $sub['updated_at'] = gmdate('Y-m-d\TH:i:s\Z');
                $this->app->platform->saveSubscription($sub);
                if (in_array($newStatus, ['canceled', 'expired'], true)) {
                    $user = $this->app->platform->findUserById((string) $sub['user_id']);
                    if ($user !== null) {
                        $user['plan_id'] = 'free';
                        $this->app->platform->saveUser($user);
                    }
                }
                return sprintf('Subscription %s status set to %s.', $subId, $newStatus);

            case 'purge_job_now':
                $jobId = (string) $request->input('job_id', '');
                $this->app->cleanup->deleteJobNow($jobId);
                return sprintf('Temporary files for job %s purged.', $jobId);

            case 'run_queue_worker':
                $count = $this->app->executor->processAllQueuedJobs(25);
                return sprintf('Processed %d queued job(s).', $count);

            case 'run_storage_cleanup':
            case 'run_cleanup':
                $res = $this->app->cleanup->purgeExpired();
                return sprintf(
                    'Cleanup completed: %d expired jobs, %d orphaned directories, %d staged router directories purged.',
                    $res['expired_jobs_purged'],
                    $res['orphaned_dirs_purged'],
                    $res['staged_dirs_purged']
                );

            case 'save_privacy_retention':
            case 'save_privacy':
                $this->app->platform->setSettings([
                    'default_retention_minutes' => max(1, (int) $request->input('default_retention_minutes', 60)),
                    'max_retention_minutes' => max(5, (int) $request->input('max_retention_minutes', 1440)),
                    'allow_delete_now' => (bool) $request->input('allow_delete_now', false),
                    'processing_locality_notice' => trim((string) $request->input('processing_locality_notice', 'Server-side local execution')),
                ]);
                return 'Privacy and retention policies saved.';

            case 'revoke_api_key':
                $keyId = (string) $request->input('key_id', '');
                $this->app->auth->revokeApiKey($keyId);
                return sprintf('API key %s revoked.', $keyId);

            case 'save_seo_settings':
            case 'save_seo':
                $this->app->platform->setSettings([
                    'seo_default_title_suffix' => trim((string) $request->input('seo_default_title_suffix', 'Docvera Document Workbench')),
                    'seo_default_description' => trim((string) $request->input('seo_default_description', '')),
                    'seo_site_indexable' => (bool) $request->input('seo_site_indexable', false),
                    'seo_sitemap_enabled' => (bool) $request->input('seo_sitemap_enabled', false),
                ]);
                return 'Global SEO settings saved.';

            case 'save_tool_seo':
                $toolId = trim((string) $request->input('tool_id', ''));
                if ($this->app->registry->get($toolId) === null) {
                    throw new ValidationException('Unknown tool ID.');
                }
                $existing = $this->app->platform->getToolOverride($toolId) ?? ['tool_id' => $toolId];
                $this->app->platform->saveToolOverride(array_merge($existing, [
                    'tool_id' => $toolId,
                    'seo_title' => trim((string) $request->input('seo_title', '')),
                    'seo_description' => trim((string) $request->input('seo_description', '')),
                    'seo_canonical' => trim((string) $request->input('seo_canonical', '')),
                    'seo_indexable' => (bool) $request->input('seo_indexable', false),
                    'seo_in_sitemap' => (bool) $request->input('seo_in_sitemap', false),
                ]));
                $this->app->registry->invalidateCache();
                return sprintf('SEO metadata for "%s" saved.', $toolId);

            case 'save_branding':
                $existingAdsEnabled = (bool) $this->app->platform->getSetting('ads_enabled', false);
                $this->app->platform->setSettings([
                    'site_name' => trim((string) $request->input('site_name', 'Docvera')) ?: 'Docvera',
                    'site_tagline' => trim((string) $request->input('site_tagline', 'Self-Hosted PDF & Document Workbench')),
                    'accent_color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $request->input('accent_color', ''))
                        ? (string) $request->input('accent_color')
                        : '#c23b22',
                    'support_email' => trim((string) $request->input('support_email', 'ops@localhost')),
                    'footer_notice' => trim((string) $request->input('footer_notice', '')),
                    'ads_enabled' => $request->input('ads_enabled') !== null ? (bool) $request->input('ads_enabled') : $existingAdsEnabled,
                    'ad_slot_header' => trim((string) $request->input('ad_slot_header', '')),
                    'ad_slot_tool_sidebar' => trim((string) $request->input('ad_slot_tool_sidebar', '')),
                    'ad_slot_result' => trim((string) $request->input('ad_slot_result', '')),
                ]);
                return 'Branding and ad slot settings updated.';

            case 'save_cms_page':
                $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower((string) $request->input('slug', ''))) ?: '';
                if ($slug === '') {
                    throw new ValidationException('Page slug is required.');
                }
                $content = (string) ($request->input('content') ?? $request->input('body', ''));
                $this->app->platform->saveCmsPage([
                    'slug' => $slug,
                    'title' => trim((string) $request->input('title', ucfirst($slug))),
                    'meta_description' => trim((string) $request->input('meta_description', '')),
                    'content' => $content,
                    'body' => $content,
                    'is_published' => (bool) $request->input('is_published', false),
                    'in_sitemap' => (bool) $request->input('in_sitemap', false),
                ]);
                return sprintf('Page "/p/%s" saved.', $slug);

            case 'save_security_settings':
                $regMode = in_array($request->input('registration_mode'), ['open', 'invite_only', 'closed'], true)
                    ? (string) $request->input('registration_mode')
                    : 'open';
                $this->app->platform->setSettings([
                    'registration_mode' => $regMode,
                    'registration_enabled' => $regMode === 'open',
                    'guest_tools_enabled' => (bool) $request->input('guest_tools_enabled', false),
                    'api_global_enabled' => (bool) $request->input('api_global_enabled', false),
                ]);
                return 'Security & access settings saved.';

            case 'run_migrations':
                $executed = $this->app->migrator->migrate();
                return $executed === []
                    ? 'Database schema is already up to date (0 pending migrations).'
                    : sprintf('Executed %d migration(s): %s', count($executed), implode(', ', $executed));

            case 'clear_staged_cache':
            case 'clear_cache':
                $res = $this->app->cleanup->purgeExpired(time() + 3600);
                $this->app->registry->invalidateCache();
                return sprintf('Cleared %d staged directory(ies) and refreshed capability cache.', $res['staged_dirs_purged']);
        }

        throw new ValidationException('Unknown administrative action.');
    }

    /**
     * @return array<string, mixed>
     */
    public function buildAdminViewData(
        string $section,
        Principal $principal,
        ?string $flashMessage = null,
        ?string $errorMessage = null
    ): array {
        $settings = $this->app->platform->getAllSettings();
        $settings['registration_enabled'] = (($settings['registration_mode'] ?? 'open') === 'open')
            && (($settings['registration_enabled'] ?? true) !== false);

        $tools = $this->app->registry->all(true);
        $capabilities = $this->app->capabilities->detectAll(true);
        $capReport = $this->app->capabilities->toMachineReadableReport();
        $apiAuditLogs = $this->app->platform->listApiAuditLogs(null, 100);

        $jobsDir = $this->app->storage->getJobsBaseDir();
        $stagedDir = $this->app->storage->getStagedBaseDir();
        $logsDir = (string) $this->app->config->get('paths.logs');

        return [
            'pageTitle' => 'Admin Control Panel (' . ucfirst($section) . ') — Docvera',
            'section' => $section,
            'activeSection' => $section,
            'principal' => $principal,
            'flashMessage' => (string) ($flashMessage ?? ''),
            'errorMessage' => $errorMessage,
            'settings' => $settings,
            'usingDefaultSecret' => $this->app->isUsingDefaultSecret(),
            'tools' => $tools,
            'toolOverrides' => $this->app->platform->getAllToolOverrides(),
            'users' => $this->app->platform->listUsers(),
            'plans' => $this->app->platform->listPlans(false),
            'subscriptions' => $this->app->platform->listSubscriptions(),
            'payments' => $this->app->platform->listPayments(),
            'webhookEvents' => $this->app->platform->listWebhookEvents(50),
            'billingProviders' => $this->app->billing->getProviderStatuses(),
            'usageBuckets' => $this->app->platform->listUsageBuckets(100),
            'jobs' => $this->app->jobs->findAll(100),
            'capabilities' => $capabilities,
            'capReport' => $capReport,
            'capabilityReport' => $capReport,
            'apiKeys' => $this->app->platform->listApiKeys(),
            'apiAuditLogs' => $apiAuditLogs,
            'apiLogs' => $apiAuditLogs,
            'cmsPages' => $this->app->platform->listCmsPages(false),
            'appliedMigrations' => $this->app->migrator->getAppliedMigrations(),
            'pendingMigrations' => $this->app->migrator->getPendingMigrations(),
            'storageStats' => [
                'jobs_dirs' => count(glob($jobsDir . '/job_*') ?: []),
                'jobs_bytes' => $this->calculateDirBytes($jobsDir),
                'staged_dirs' => count(glob($stagedDir . '/stg_*') ?: []),
                'staged_bytes' => $this->calculateDirBytes($stagedDir),
                'logs_bytes' => $this->calculateDirBytes($logsDir),
            ],
        ];
    }

    private function resolveSection(string $path): string
    {
        if ($path === '/admin') {
            return 'overview';
        }
        if (preg_match('#^/admin/([a-z0-9\-]+)$#', $path, $m)) {
            $valid = [
                'overview', 'tools', 'users', 'plans', 'subscriptions', 'payments',
                'usage', 'jobs', 'health', 'storage', 'api-usage', 'seo',
                'branding', 'pages', 'security', 'updates',
            ];
            if (in_array($m[1], $valid, true)) {
                return $m[1];
            }
        }
        return 'overview';
    }

    private function calculateDirBytes(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }
        $total = 0;
        $items = @scandir($dir);
        if (!is_array($items)) {
            return 0;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..' || $item === '.gitkeep') {
                continue;
            }
            $full = $dir . '/' . $item;
            if (is_dir($full) && !is_link($full)) {
                $total += $this->calculateDirBytes($full);
            } elseif (is_file($full)) {
                $total += (int) @filesize($full);
            }
        }
        return $total;
    }
}

