<?php
/**
 * @var string $section
 * @var string $flashMessage
 * @var string|null $errorMessage
 * @var array<string, mixed> $settings
 * @var array<string, \Docvera\Tools\ToolDefinition> $tools
 * @var array<string, array<string, mixed>> $toolOverrides
 * @var array<int, array<string, mixed>> $users
 * @var array<int, array<string, mixed>> $plans
 * @var array<int, array<string, mixed>> $subscriptions
 * @var array<int, array<string, mixed>> $payments
 * @var array<int, array<string, mixed>> $webhookEvents
 * @var array<int, array<string, mixed>> $usageBuckets
 * @var array<int, array<string, mixed>> $jobs
 * @var array<int, array<string, mixed>> $apiLogs
 * @var array<int, array<string, mixed>> $apiKeys
 * @var array<int, array<string, mixed>> $cmsPages
 * @var array<string, \Docvera\Capabilities\CapabilityStatus> $capabilities
 * @var array<string, mixed> $capabilityReport
 * @var array<string, array<string, mixed>> $billingProviders
 * @var array<int, string> $appliedMigrations
 * @var array<int, string> $pendingMigrations
 * @var array<string, int> $storageStats
 * @var string $csrfToken
 */
declare(strict_types=1);

$navSections = [
    'overview' => 'Deployment Mode & Overview',
    'tools' => 'Tool Management',
    'users' => 'Users & Roles',
    'plans' => 'Plans & Quotas',
    'subscriptions' => 'Subscriptions',
    'payments' => 'Payments & Webhooks',
    'usage' => 'Usage Buckets',
    'jobs' => 'Processing Jobs',
    'health' => 'System Health',
    'storage' => 'Storage & Cleanup',
    'api-usage' => 'API Usage & Keys',
    'seo' => 'SEO & Sitemap',
    'branding' => 'Branding & Ad Slots',
    'pages' => 'CMS Pages',
    'security' => 'Security & Billing Keys',
    'updates' => 'Update Center',
];

$allAvail = !empty($capabilityReport['summary']['all_available']);
$availDrivers = (int) ($capabilityReport['summary']['available_count'] ?? 0);
$totalDrivers = (int) ($capabilityReport['summary']['total_checked'] ?? 0);
?>
<div class="shell admin-workbench">
    <header class="capabilities-header">
        <div>
            <h1 class="workbench-title">Administration</h1>
            <p class="workbench-lead">
                Central control plane for deployment modes, entitlements, tool availability, billing webhooks, privacy retention, and system diagnostics.
            </p>
        </div>
        <div class="blocked-actions" style="margin-top:0;">
            <?php if ($allAvail): ?>
                <span class="status-pill is-ready">System Healthy (<?= $availDrivers ?>/<?= $totalDrivers ?> Capabilities)</span>
            <?php else: ?>
                <span class="status-pill is-unavailable">Degraded Host (<?= $availDrivers ?>/<?= $totalDrivers ?> Capabilities Ready)</span>
            <?php endif; ?>
            <span class="status-pill is-purged">Mode: <?= htmlspecialchars(strtoupper((string) ($settings['deployment_mode'] ?? 'saas')), ENT_QUOTES, 'UTF-8') ?></span>
        </div>
    </header>

    <?php if (!empty($flashMessage)): ?>
        <div class="staged-banner" role="status" style="margin-bottom:1.5rem;">
            <div class="staged-banner-info">
                <span class="staged-badge">Saved</span>
                <span><?= htmlspecialchars((string) $flashMessage, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="alert-banner is-error" role="alert">
            <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="admin-layout-grid">
        <!-- Left Navigation Rail -->
        <aside class="admin-sidebar" aria-label="Administration sections">
            <nav class="admin-nav-list">
                <?php foreach ($navSections as $slug => $label): ?>
                    <a
                        href="/admin/<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>"
                        class="admin-nav-link <?= $section === $slug ? 'is-active' : '' ?>"
                    >
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
            </nav>
        </aside>

        <!-- Main Section Content -->
        <div class="admin-main-pane">
            <?php if ($section === 'overview'): ?>
                <section class="tool-primary-pane">
                    <header class="tool-pane-header">
                        <h2 class="tool-heading">Deployment mode</h2>
                        <p class="tool-description">
                            Switch Docvera between three commercial/enterprise operating models without modifying source code.
                        </p>
                    </header>

                    <form action="/admin/overview" method="post">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="form_action" value="save_deployment_mode">

                        <div class="options-grid" style="margin-bottom:1.5rem;">
                            <div class="option-field">
                                <label for="dep-mode" class="field-label">Active deployment model</label>
                                <select id="dep-mode" name="deployment_mode" class="field-select">
                                    <option value="free_ads" <?= ($settings['deployment_mode'] ?? '') === 'free_ads' ? 'selected' : '' ?>>
                                        1. FREE / AD-SUPPORTED (Guest tools enabled, accounts optional, billing disabled, configurable ad slots)
                                    </option>
                                    <option value="saas" <?= ($settings['deployment_mode'] ?? 'saas') === 'saas' ? 'selected' : '' ?>>
                                        2. FREEMIUM / PAID SAAS (Guest/free limits, accounts, paid subscriptions, API monetization)
                                    </option>
                                    <option value="private" <?= ($settings['deployment_mode'] ?? '') === 'private' ? 'selected' : '' ?>>
                                        3. PRIVATE INTERNAL WORKSPACE (Public guest tools disabled, admin-controlled access, billing disabled)
                                    </option>
                                </select>
                            </div>
                        </div>

                        <div class="options-grid" style="margin-bottom:1.5rem;">
                            <label class="checkbox-inline">
                                <input type="checkbox" name="guest_tools_enabled" value="1" <?= !empty($settings['guest_tools_enabled']) ? 'checked' : '' ?>>
                                Allow Unauthenticated Guest Tool Usage
                            </label>
                            <label class="checkbox-inline">
                                <input type="checkbox" name="registration_enabled" value="1" <?= !empty($settings['registration_enabled']) ? 'checked' : '' ?>>
                                Allow Public Self-Service Registration
                            </label>
                            <label class="checkbox-inline">
                                <input type="checkbox" name="billing_enabled" value="1" <?= !empty($settings['billing_enabled']) ? 'checked' : '' ?>>
                                Enable subscription billing and paid upgrades
                            </label>
                            <label class="checkbox-inline">
                                <input type="checkbox" name="ads_enabled" value="1" <?= !empty($settings['ads_enabled']) ? 'checked' : '' ?>>
                                Enable Advertisement Slots (for plans with <code>ads_visible</code>)
                            </label>
                        </div>

                        <button type="submit" class="btn-primary">Apply deployment mode and access rules</button>
                    </form>
                </section>

                <section class="cap-section" style="margin-top:2rem;">
                    <h2 class="section-heading">Platform snapshot</h2>
                    <div class="result-telemetry-grid">
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Registered users</span>
                            <span class="telemetry-value"><?= count($users) ?></span>
                        </div>
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Configured plans</span>
                            <span class="telemetry-value"><?= count($plans) ?></span>
                        </div>
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Subscriptions / payments</span>
                            <span class="telemetry-value"><?= count($subscriptions) ?> subs · <?= count($payments) ?> payments</span>
                        </div>
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Recorded jobs</span>
                            <span class="telemetry-value"><?= count($jobs) ?> recent</span>
                        </div>
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Active job workspaces on disk</span>
                            <span class="telemetry-value"><?= (int) $storageStats['jobs_dirs'] ?> (<?= number_format($storageStats['jobs_bytes'] / 1024, 1) ?> KB)</span>
                        </div>
                        <div class="telemetry-cell">
                            <span class="telemetry-label">API keys / audit entries</span>
                            <span class="telemetry-value"><?= count($apiKeys) ?> keys · <?= count($apiLogs) ?> logs</span>
                        </div>
                    </div>
                </section>

            <?php elseif ($section === 'tools'): ?>
                <section class="tool-primary-pane">
                    <header class="tool-pane-header">
                        <h2 class="tool-heading">Tool registry and entitlement overrides</h2>
                        <p class="tool-description">
                            Configure per-tool enable/disable state, guest access, API exposure, file count/size overrides, and execution timeouts without editing code.
                        </p>
                    </header>

                    <div class="cap-table-wrap">
                        <table class="cap-table">
                            <thead>
                                <tr>
                                    <th>Tool</th>
                                    <th>Driver status</th>
                                    <th>Access flags</th>
                                    <th>Max files / max MB / timeout</th>
                                    <th>Save</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tools as $tool): ?>
                                    <tr>
                                        <form action="/admin/tools" method="post">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="form_action" value="save_tool_override">
                                            <input type="hidden" name="tool_id" value="<?= htmlspecialchars($tool->id, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="seo_indexable" value="<?= $tool->seoIndexable ? '1' : '0' ?>">
                                            <input type="hidden" name="seo_in_sitemap" value="<?= $tool->seoInSitemap ? '1' : '0' ?>">
                                            <td>
                                                <strong><?= htmlspecialchars($tool->name, ENT_QUOTES, 'UTF-8') ?></strong>
                                                <div class="cap-key-sub"><code><?= htmlspecialchars($tool->id, ENT_QUOTES, 'UTF-8') ?></code></div>
                                            </td>
                                            <td>
                                                <?php if ($tool->availability->available): ?>
                                                    <span class="status-pill is-ready"><?= htmlspecialchars((string) $tool->availability->activeDriver, ENT_QUOTES, 'UTF-8') ?></span>
                                                <?php else: ?>
                                                    <span class="status-pill is-unavailable">Not installed</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div style="display:flex;flex-direction:column;gap:0.25rem;">
                                                    <label class="checkbox-inline" style="min-height:24px;">
                                                        <input type="checkbox" name="is_enabled" value="1" <?= $tool->enabled ? 'checked' : '' ?>> Enabled
                                                    </label>
                                                    <label class="checkbox-inline" style="min-height:24px;">
                                                        <input type="checkbox" name="guest_allowed" value="1" <?= $tool->guestEligible ? 'checked' : '' ?>> Guest Allowed
                                                    </label>
                                                    <label class="checkbox-inline" style="min-height:24px;">
                                                        <input type="checkbox" name="api_enabled" value="1" <?= $tool->apiEligible ? 'checked' : '' ?>> REST API Enabled
                                                    </label>
                                                </div>
                                            </td>
                                            <td>
                                                <div style="display:flex;gap:0.4rem;align-items:center;flex-wrap:wrap;">
                                                    <input type="number" name="max_files_override" class="field-input" style="width:72px;min-height:34px;padding:0.25rem 0.4rem;" value="<?= (int) $tool->maxFiles ?>" title="Max Files">
                                                    <input type="number" name="max_file_size_mb_override" class="field-input" style="width:78px;min-height:34px;padding:0.25rem 0.4rem;" value="<?= $tool->maxFileSizeMb > 0 ? (int) $tool->maxFileSizeMb : '' ?>" placeholder="Plan MB" title="Max MB Override">
                                                    <input type="number" name="timeout_override" class="field-input" style="width:72px;min-height:34px;padding:0.25rem 0.4rem;" value="<?= (int) $tool->timeoutSeconds ?>" title="Timeout (s)">
                                                </div>
                                            </td>
                                            <td>
                                                <button type="submit" class="btn-secondary btn-sm">Update</button>
                                            </td>
                                        </form>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            <?php elseif ($section === 'users'): ?>
                <section class="tool-primary-pane" style="margin-bottom:2rem;">
                    <header class="tool-pane-header">
                        <h2 class="tool-heading">Provision user account</h2>
                        <p class="tool-description">Create an internal user, customer account, or administrator directly.</p>
                    </header>
                    <form action="/admin/users" method="post" class="options-grid">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="form_action" value="create_user">
                        <div class="option-field">
                            <label class="field-label">Name</label>
                            <input type="text" name="name" class="field-input" required placeholder="Team Member">
                        </div>
                        <div class="option-field">
                            <label class="field-label">Email</label>
                            <input type="email" name="email" class="field-input" required placeholder="member@company.com">
                        </div>
                        <div class="option-field">
                            <label class="field-label">Password</label>
                            <input type="password" name="password" class="field-input" minlength="8" required>
                        </div>
                        <div class="option-field">
                            <label class="field-label">Role</label>
                            <select name="role" class="field-select">
                                <option value="user">User</option>
                                <option value="admin">Administrator</option>
                            </select>
                        </div>
                        <div class="option-field">
                            <label class="field-label">Assigned plan</label>
                            <select name="plan_id" class="field-select">
                                <?php foreach ($plans as $pl): ?>
                                    <?php if ($pl['id'] !== 'guest'): ?>
                                        <option value="<?= htmlspecialchars((string) $pl['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $pl['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="option-field" style="justify-content:flex-end;">
                            <button type="submit" class="btn-primary">Create user</button>
                        </div>
                    </form>
                </section>

                <section class="cap-section">
                    <h2 class="section-heading">User directory (<?= count($users) ?>)</h2>
                    <div class="cap-table-wrap">
                        <table class="cap-table">
                            <thead>
                                <tr>
                                    <th>User</th>
                                    <th>Role</th>
                                    <th>Plan tier</th>
                                    <th>Status</th>
                                    <th>Save</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <form action="/admin/users" method="post">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="form_action" value="save_user">
                                            <input type="hidden" name="user_id" value="<?= htmlspecialchars((string) $u['id'], ENT_QUOTES, 'UTF-8') ?>">
                                            <td>
                                                <strong><?= htmlspecialchars((string) $u['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                <div class="cap-key-sub"><code><?= htmlspecialchars((string) $u['email'], ENT_QUOTES, 'UTF-8') ?></code></div>
                                            </td>
                                            <td>
                                                <select name="role" class="field-select field-select-sm">
                                                    <option value="user" <?= ($u['role'] ?? '') === 'user' ? 'selected' : '' ?>>user</option>
                                                    <option value="admin" <?= ($u['role'] ?? '') === 'admin' ? 'selected' : '' ?>>admin</option>
                                                </select>
                                            </td>
                                            <td>
                                                <select name="plan_id" class="field-select field-select-sm">
                                                    <?php foreach ($plans as $pl): ?>
                                                        <option value="<?= htmlspecialchars((string) $pl['id'], ENT_QUOTES, 'UTF-8') ?>" <?= ($u['plan_id'] ?? '') === $pl['id'] ? 'selected' : '' ?>>
                                                            <?= htmlspecialchars((string) $pl['name'], ENT_QUOTES, 'UTF-8') ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </td>
                                            <td>
                                                <select name="status" class="field-select field-select-sm">
                                                    <option value="active" <?= ($u['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>active</option>
                                                    <option value="suspended" <?= ($u['status'] ?? '') === 'suspended' ? 'selected' : '' ?>>suspended</option>
                                                </select>
                                            </td>
                                            <td>
                                                <button type="submit" class="btn-secondary btn-sm">Save</button>
                                            </td>
                                        </form>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            <?php elseif ($section === 'plans'): ?>
                <section class="cap-section">
                    <h2 class="section-heading">Configurable plans and quota engine (including guest limits)</h2>
                    <div style="display:flex;flex-direction:column;gap:1.5rem;">
                        <?php foreach ($plans as $pl): ?>
                            <?php $ent = is_array($pl['entitlements'] ?? null) ? $pl['entitlements'] : []; ?>
                            <form action="/admin/plans" method="post" class="tool-primary-pane">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="form_action" value="save_plan">
                                <input type="hidden" name="id" value="<?= htmlspecialchars((string) $pl['id'], ENT_QUOTES, 'UTF-8') ?>">

                                <div class="tool-header-top" style="margin-bottom:1rem;">
                                    <h3 class="tool-heading" style="font-size:1.2rem;">
                                        Plan: <code><?= htmlspecialchars((string) $pl['id'], ENT_QUOTES, 'UTF-8') ?></code>
                                    </h3>
                                    <button type="submit" class="btn-primary btn-sm">Save plan quotas</button>
                                </div>

                                <div class="options-grid" style="margin-bottom:1rem;">
                                    <div class="option-field">
                                        <label class="field-label">Display name</label>
                                        <input type="text" name="name" class="field-input" value="<?= htmlspecialchars((string) $pl['name'], ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="option-field">
                                        <label class="field-label">Monthly price (cents USD)</label>
                                        <input type="number" name="price_monthly_cents" class="field-input" value="<?= (int) $pl['price_monthly_cents'] ?>">
                                    </div>
                                    <div class="option-field">
                                        <label class="field-label">Yearly price (cents USD)</label>
                                        <input type="number" name="price_yearly_cents" class="field-input" value="<?= (int) $pl['price_yearly_cents'] ?>">
                                    </div>
                                    <div class="option-field">
                                        <label class="field-label">Ops / day</label>
                                        <input type="number" name="ops_per_day" class="field-input" value="<?= (int) ($ent['ops_per_day'] ?? 10) ?>">
                                    </div>
                                    <div class="option-field">
                                        <label class="field-label">Ops / month</label>
                                        <input type="number" name="ops_per_month" class="field-input" value="<?= (int) ($ent['ops_per_month'] ?? 100) ?>">
                                    </div>
                                    <div class="option-field">
                                        <label class="field-label">Max file size (MB)</label>
                                        <input type="number" name="max_file_size_mb" class="field-input" value="<?= (int) ($ent['max_file_size_mb'] ?? 25) ?>">
                                    </div>
                                    <div class="option-field">
                                        <label class="field-label">Max files / op</label>
                                        <input type="number" name="max_files_per_op" class="field-input" value="<?= (int) ($ent['max_files_per_op'] ?? 5) ?>">
                                    </div>
                                    <div class="option-field">
                                        <label class="field-label">Max pages / op</label>
                                        <input type="number" name="max_pages_per_op" class="field-input" value="<?= (int) ($ent['max_pages_per_op'] ?? 100) ?>">
                                    </div>
                                    <div class="option-field">
                                        <label class="field-label">Concurrent jobs</label>
                                        <input type="number" name="concurrent_jobs" class="field-input" value="<?= (int) ($ent['concurrent_jobs'] ?? 1) ?>">
                                    </div>
                                    <div class="option-field">
                                        <label class="field-label">API requests / day</label>
                                        <input type="number" name="api_requests_per_day" class="field-input" value="<?= (int) ($ent['api_requests_per_day'] ?? 0) ?>">
                                    </div>
                                    <div class="option-field">
                                        <label class="field-label">API requests / month</label>
                                        <input type="number" name="api_requests_per_month" class="field-input" value="<?= (int) ($ent['api_requests_per_month'] ?? 0) ?>">
                                    </div>
                                    <div class="option-field">
                                        <label class="field-label">API rate limit / min</label>
                                        <input type="number" name="api_rate_limit_per_min" class="field-input" value="<?= (int) ($ent['api_rate_limit_per_min'] ?? 30) ?>">
                                    </div>
                                    <div class="option-field">
                                        <label class="field-label">Retention window (minutes)</label>
                                        <input type="number" name="retention_minutes" class="field-input" value="<?= (int) ($ent['retention_minutes'] ?? 60) ?>">
                                    </div>
                                    <div class="option-field">
                                        <label class="field-label">Allowed tools (<code>*</code> or CSV slugs)</label>
                                        <input type="text" name="allowed_tools" class="field-input" value="<?= htmlspecialchars(is_array($ent['allowed_tools'] ?? null) ? implode(',', $ent['allowed_tools']) : (string) ($ent['allowed_tools'] ?? '*'), ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                </div>

                                <div style="display:flex;gap:1.25rem;flex-wrap:wrap;">
                                    <label class="checkbox-inline"><input type="checkbox" name="ocr_allowed" value="1" <?= !empty($ent['ocr_allowed']) ? 'checked' : '' ?>> OCR Allowed</label>
                                    <label class="checkbox-inline"><input type="checkbox" name="batch_allowed" value="1" <?= !empty($ent['batch_allowed']) ? 'checked' : '' ?>> Multi-File Batch Allowed</label>
                                    <label class="checkbox-inline"><input type="checkbox" name="api_access" value="1" <?= !empty($ent['api_access']) ? 'checked' : '' ?>> REST API Access</label>
                                    <label class="checkbox-inline"><input type="checkbox" name="ads_visible" value="1" <?= !empty($ent['ads_visible']) ? 'checked' : '' ?>> Show Advertisements</label>
                                    <label class="checkbox-inline"><input type="checkbox" name="is_public" value="1" <?= !empty($pl['is_public']) ? 'checked' : '' ?>> Listed on Public Pricing Page</label>
                                    <label class="checkbox-inline"><input type="checkbox" name="is_active" value="1" <?= !empty($pl['is_active']) ? 'checked' : '' ?>> Active</label>
                                </div>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </section>

            <?php elseif ($section === 'subscriptions'): ?>
                <section class="cap-section">
                    <h2 class="section-heading">Subscriptions ledger (<?= count($subscriptions) ?>)</h2>
                    <div class="cap-table-wrap">
                        <table class="cap-table">
                            <thead>
                                <tr>
                                    <th>Subscription ID</th>
                                    <th>User ID</th>
                                    <th>Plan and interval</th>
                                    <th>Provider and external ID</th>
                                    <th>Status</th>
                                    <th>Current period end</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($subscriptions === []): ?>
                                    <tr><td colspan="6">No subscriptions recorded yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($subscriptions as $sub): ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars((string) $sub['id'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><code><?= htmlspecialchars((string) $sub['user_id'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><code><?= htmlspecialchars((string) $sub['plan_id'] . ' (' . (string) $sub['billing_interval'] . ')', ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><code><?= htmlspecialchars((string) $sub['provider'] . ' · ' . (string) ($sub['provider_subscription_id'] ?? 'n/a'), ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><span class="status-pill is-ready"><?= htmlspecialchars((string) $sub['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                            <td><code><?= htmlspecialchars((string) ($sub['current_period_end'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            <?php elseif ($section === 'payments'): ?>
                <section class="cap-section">
                    <h2 class="section-heading">Payments and verified webhook events</h2>
                    <div class="cap-table-wrap" style="margin-bottom:2rem;">
                        <table class="cap-table">
                            <thead>
                                <tr>
                                    <th>Payment ID</th>
                                    <th>User ID</th>
                                    <th>Provider</th>
                                    <th>Amount</th>
                                    <th>Status</th>
                                    <th>Created at</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($payments === []): ?>
                                    <tr><td colspan="6">No payment transactions recorded yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($payments as $pmt): ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars((string) $pmt['id'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><code><?= htmlspecialchars((string) $pmt['user_id'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><code><?= htmlspecialchars((string) $pmt['provider'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><code>$<?= number_format(((int) $pmt['amount_cents']) / 100, 2) ?> <?= htmlspecialchars((string) $pmt['currency'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><span class="status-pill <?= ($pmt['status'] ?? '') === 'succeeded' ? 'is-ready' : 'is-error' ?>"><?= htmlspecialchars((string) $pmt['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                            <td><code><?= htmlspecialchars((string) $pmt['created_at'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <h3 class="section-heading">Webhook event log (<?= count($webhookEvents) ?>)</h3>
                    <div class="cap-table-wrap">
                        <table class="cap-table">
                            <thead>
                                <tr>
                                    <th>Provider</th>
                                    <th>Event ID</th>
                                    <th>Event type</th>
                                    <th>Processed status</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($webhookEvents === []): ?>
                                    <tr><td colspan="5">No webhook deliveries processed yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($webhookEvents as $ev): ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars((string) $ev['provider'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><code><?= htmlspecialchars((string) $ev['event_id'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><code><?= htmlspecialchars((string) $ev['event_type'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><span class="status-pill is-ready"><?= htmlspecialchars((string) $ev['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                            <td><code><?= htmlspecialchars((string) $ev['processed_at'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            <?php elseif ($section === 'usage'): ?>
                <section class="cap-section">
                    <h2 class="section-heading">Authoritative usage buckets (<?= count($usageBuckets) ?>)</h2>
                    <div class="cap-table-wrap">
                        <table class="cap-table">
                            <thead>
                                <tr>
                                    <th>Subject key</th>
                                    <th>Bucket key</th>
                                    <th>Operations count</th>
                                    <th>Bytes processed</th>
                                    <th>API requests</th>
                                    <th>Updated at</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($usageBuckets === []): ?>
                                    <tr><td colspan="6">No usage buckets recorded yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($usageBuckets as $ub): ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars((string) $ub['subject_key'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><code><?= htmlspecialchars((string) $ub['bucket_key'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><code><?= (int) $ub['ops_count'] ?></code></td>
                                            <td><code><?= number_format(((int) $ub['bytes_processed']) / 1024, 1) ?> KB</code></td>
                                            <td><code><?= (int) $ub['api_requests_count'] ?></code></td>
                                            <td><code><?= htmlspecialchars((string) $ub['updated_at'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            <?php elseif ($section === 'jobs'): ?>
                <section class="cap-section">
                    <h2 class="section-heading">Processing jobs ledger (<?= count($jobs) ?>)</h2>
                    <div class="cap-table-wrap">
                        <table class="cap-table">
                            <thead>
                                <tr>
                                    <th>Job ID</th>
                                    <th>Tool and driver</th>
                                    <th>User / source</th>
                                    <th>Status</th>
                                    <th>Bytes / pages</th>
                                    <th>Expires / deleted</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($jobs === []): ?>
                                    <tr><td colspan="6">No processing jobs recorded yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($jobs as $j): ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars((string) $j['id'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td>
                                                <strong><?= htmlspecialchars((string) $j['tool_id'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                <div class="cap-key-sub"><code><?= htmlspecialchars((string) ($j['driver_used'] ?? 'n/a'), ENT_QUOTES, 'UTF-8') ?></code></div>
                                            </td>
                                            <td>
                                                <code><?= htmlspecialchars((string) ($j['user_id'] ?? 'guest'), ENT_QUOTES, 'UTF-8') ?></code>
                                                <div class="cap-key-sub"><code><?= htmlspecialchars((string) ($j['source'] ?? 'web'), ENT_QUOTES, 'UTF-8') ?></code></div>
                                            </td>
                                            <td>
                                                <span class="status-pill <?= ($j['status'] ?? '') === 'completed' ? 'is-ready' : 'is-unavailable' ?>">
                                                    <?= htmlspecialchars((string) $j['status'], ENT_QUOTES, 'UTF-8') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div>In: <?= number_format(((int) ($j['input_bytes'] ?? 0)) / 1024, 1) ?> KB</div>
                                                <div class="cap-key-sub">Out: <?= number_format(((int) ($j['output_bytes'] ?? 0)) / 1024, 1) ?> KB · <?= (int) ($j['page_count'] ?? 0) ?>p</div>
                                            </td>
                                            <td>
                                                <code><?= htmlspecialchars((string) ($j['deleted_at'] ?? $j['expires_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></code>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            <?php elseif ($section === 'health'): ?>
                <section class="cap-section">
                    <h2 class="section-heading">System health</h2>
                    <?php if (!$allAvail): ?>
                        <div class="capability-blocked-panel" style="margin-bottom:1.5rem;">
                            <h3 class="blocked-title">Degraded host capabilities detected (<?= $availDrivers ?>/<?= $totalDrivers ?> Ready)</h3>
                            <p class="blocked-reason">
                                One or more optional or required host dependencies are missing. Docvera never reports a false green "healthy" status when a capability check fails.
                            </p>
                        </div>
                    <?php endif; ?>

                    <div class="result-telemetry-grid" style="margin-bottom:1.5rem;">
                        <div class="telemetry-cell">
                            <span class="telemetry-label">PHP runtime</span>
                            <span class="telemetry-value"><code><?= htmlspecialchars(PHP_VERSION . ' (' . PHP_SAPI . ')', ENT_QUOTES, 'UTF-8') ?></code></span>
                        </div>
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Database driver</span>
                            <span class="telemetry-value"><code><?= htmlspecialchars((string) ($capabilityReport['environment']['db_driver'] ?? 'file'), ENT_QUOTES, 'UTF-8') ?></code></span>
                        </div>
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Async queue worker</span>
                            <span class="telemetry-value"><code>bin/worker</code> (CLI Queue Daemon)</span>
                        </div>
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Scheduled cleanup</span>
                            <span class="telemetry-value"><code>bin/cleanup</code> + Opportunistic Request TTL</span>
                        </div>
                    </div>

                    <div class="cap-table-wrap">
                        <table class="cap-table">
                            <thead>
                                <tr>
                                    <th>Component</th>
                                    <th>Category</th>
                                    <th>Status</th>
                                    <th>Version / binary path</th>
                                    <th>Diagnostic details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($capabilities as $cap): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($cap->name, ENT_QUOTES, 'UTF-8') ?></strong>
                                            <div class="cap-key-sub"><code><?= htmlspecialchars($cap->key, ENT_QUOTES, 'UTF-8') ?></code></div>
                                        </td>
                                        <td><code><?= htmlspecialchars($cap->category, ENT_QUOTES, 'UTF-8') ?></code></td>
                                        <td>
                                            <?php if ($cap->available): ?>
                                                <span class="status-pill is-ready">Ready</span>
                                            <?php else: ?>
                                                <span class="status-pill is-error">Missing / Unavailable</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <code><?= htmlspecialchars((string) ($cap->version ?? '—'), ENT_QUOTES, 'UTF-8') ?></code>
                                            <?php if ($cap->binaryPath !== null): ?>
                                                <div class="cap-key-sub"><code><?= htmlspecialchars($cap->binaryPath, ENT_QUOTES, 'UTF-8') ?></code></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($cap->description, ENT_QUOTES, 'UTF-8') ?>
                                            <?php if (!$cap->available && $cap->missingReason !== null): ?>
                                                <div class="cap-missing-note"><?= htmlspecialchars($cap->missingReason, ENT_QUOTES, 'UTF-8') ?></div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            <?php elseif ($section === 'storage'): ?>
                <section class="tool-primary-pane" style="margin-bottom:2rem;">
                    <header class="tool-pane-header">
                        <h2 class="tool-heading">Storage telemetry and manual cleanup</h2>
                        <p class="tool-description">Inspect temporary job directories and trigger immediate expiration sweeps.</p>
                    </header>

                    <div class="result-telemetry-grid" style="margin-bottom:1.5rem;">
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Active job directories</span>
                            <span class="telemetry-value"><?= (int) $storageStats['jobs_dirs'] ?> dirs (<?= number_format($storageStats['jobs_bytes'] / 1024, 1) ?> KB)</span>
                        </div>
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Staged router files</span>
                            <span class="telemetry-value"><?= (int) $storageStats['staged_dirs'] ?> dirs (<?= number_format($storageStats['staged_bytes'] / 1024, 1) ?> KB)</span>
                        </div>
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Diagnostic logs size</span>
                            <span class="telemetry-value"><?= number_format($storageStats['logs_bytes'] / 1024, 1) ?> KB</span>
                        </div>
                    </div>

                    <form action="/admin/storage" method="post" style="margin-bottom:2rem;">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="form_action" value="run_cleanup">
                        <button type="submit" class="btn-primary">Run expired workspace purge now</button>
                    </form>

                    <form action="/admin/storage" method="post" class="options-grid">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="form_action" value="save_privacy">
                        <div class="option-field">
                            <label class="field-label">Default retention window (minutes)</label>
                            <input type="number" name="default_retention_minutes" class="field-input" value="<?= (int) ($settings['default_retention_minutes'] ?? 60) ?>" min="1" max="10080">
                        </div>
                        <div class="option-field">
                            <label class="field-label">Explicit processing locality disclosure</label>
                            <input type="text" name="processing_locality_notice" class="field-input" value="<?= htmlspecialchars((string) ($settings['processing_locality_notice'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="option-field" style="justify-content:center;">
                            <label class="checkbox-inline">
                                <input type="checkbox" name="allow_delete_now" value="1" <?= !empty($settings['allow_delete_now']) ? 'checked' : '' ?>>
                                Enable User "Delete File Now" Button
                            </label>
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <button type="submit" class="btn-secondary">Save privacy and retention policy</button>
                        </div>
                    </form>
                </section>

            <?php elseif ($section === 'api-usage'): ?>
                <section class="cap-section">
                    <h2 class="section-heading">Provisioned API keys (<?= count($apiKeys) ?>)</h2>
                    <div class="cap-table-wrap" style="margin-bottom:2rem;">
                        <table class="cap-table">
                            <thead>
                                <tr>
                                    <th>Key ID and label</th>
                                    <th>User ID</th>
                                    <th>Prefix</th>
                                    <th>Scopes</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($apiKeys === []): ?>
                                    <tr><td colspan="5">No API keys created across any user accounts yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($apiKeys as $ak): ?>
                                        <tr>
                                            <td>
                                                <strong><?= htmlspecialchars((string) ($ak['name'] ?? $ak['label'] ?? 'API Key'), ENT_QUOTES, 'UTF-8') ?></strong>
                                                <div class="cap-key-sub"><code><?= htmlspecialchars((string) $ak['id'], ENT_QUOTES, 'UTF-8') ?></code></div>
                                            </td>
                                            <td><code><?= htmlspecialchars((string) $ak['user_id'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><code><?= htmlspecialchars((string) ($ak['key_prefix'] ?? 'dcv_live_***'), ENT_QUOTES, 'UTF-8') ?>…</code></td>
                                            <td><code><?= htmlspecialchars(implode(', ', (array) ($ak['scopes'] ?? [])), ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td>
                                                <?php if (($ak['revoked_at'] ?? null) !== null): ?>
                                                    <span class="status-pill is-purged">Revoked</span>
                                                <?php else: ?>
                                                    <span class="status-pill is-ready">Active</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <h2 class="section-heading">API request audit logs (<?= count($apiLogs) ?>)</h2>
                    <div class="cap-table-wrap">
                        <table class="cap-table">
                            <thead>
                                <tr>
                                    <th>Request ID</th>
                                    <th>User / key ID</th>
                                    <th>Endpoint</th>
                                    <th>Status</th>
                                    <th>Latency</th>
                                    <th>Timestamp</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($apiLogs === []): ?>
                                    <tr><td colspan="6">No API requests logged yet.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($apiLogs as $log): ?>
                                        <tr>
                                            <td><code><?= htmlspecialchars((string) $log['request_id'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><code><?= htmlspecialchars((string) ($log['user_id'] ?? '—') . ' / ' . (string) ($log['api_key_id'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><code><?= htmlspecialchars((string) $log['method'] . ' ' . (string) $log['endpoint'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><code><?= (int) $log['status_code'] ?></code></td>
                                            <td><code><?= (int) $log['duration_ms'] ?> ms</code></td>
                                            <td><code><?= htmlspecialchars((string) $log['created_at'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            <?php elseif ($section === 'seo'): ?>
                <section class="tool-primary-pane" style="margin-bottom:2rem;">
                    <header class="tool-pane-header">
                        <h2 class="tool-heading">Global SEO and indexing configuration</h2>
                        <p class="tool-description">Control site-wide indexing, canonical metadata defaults, and XML sitemap generation.</p>
                    </header>
                    <form action="/admin/seo" method="post" class="options-grid">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="form_action" value="save_seo">
                        <div class="option-field">
                            <label class="field-label">Default title suffix</label>
                            <input type="text" name="seo_default_title_suffix" class="field-input" value="<?= htmlspecialchars((string) ($settings['seo_default_title_suffix'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="option-field">
                            <label class="field-label">Default meta description</label>
                            <input type="text" name="seo_default_description" class="field-input" value="<?= htmlspecialchars((string) ($settings['seo_default_description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="option-field" style="justify-content:center;">
                            <label class="checkbox-inline"><input type="checkbox" name="seo_site_indexable" value="1" <?= !empty($settings['seo_site_indexable']) ? 'checked' : '' ?>> Allow Search Indexing (<code>index, follow</code>)</label>
                            <label class="checkbox-inline"><input type="checkbox" name="seo_sitemap_enabled" value="1" <?= !empty($settings['seo_sitemap_enabled']) ? 'checked' : '' ?>> Enable <code>/sitemap.xml</code></label>
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <button type="submit" class="btn-primary">Save global SEO settings</button>
                        </div>
                    </form>
                </section>

                <section class="cap-section">
                    <h2 class="section-heading">Per-Tool SEO metadata and sitemap inclusion</h2>
                    <div class="cap-table-wrap">
                        <table class="cap-table">
                            <thead>
                                <tr>
                                    <th>Tool</th>
                                    <th>Custom title and meta description</th>
                                    <th>Canonical URL override</th>
                                    <th>Index / sitemap</th>
                                    <th>Save</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tools as $tool): ?>
                                    <tr>
                                        <form action="/admin/seo" method="post">
                                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="form_action" value="save_tool_override">
                                            <input type="hidden" name="tool_id" value="<?= htmlspecialchars($tool->id, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="is_enabled" value="<?= $tool->enabled ? '1' : '0' ?>">
                                            <input type="hidden" name="guest_allowed" value="<?= $tool->guestEligible ? '1' : '0' ?>">
                                            <input type="hidden" name="api_enabled" value="<?= $tool->apiEligible ? '1' : '0' ?>">
                                            <input type="hidden" name="max_files_override" value="<?= (int) $tool->maxFiles ?>">
                                            <input type="hidden" name="timeout_override" value="<?= (int) $tool->timeoutSeconds ?>">
                                            <td>
                                                <strong><?= htmlspecialchars($tool->name, ENT_QUOTES, 'UTF-8') ?></strong>
                                                <div class="cap-key-sub"><code>/tools/<?= htmlspecialchars($tool->id, ENT_QUOTES, 'UTF-8') ?></code></div>
                                            </td>
                                            <td>
                                                <input type="text" name="seo_title" class="field-input" style="min-height:34px;margin-bottom:0.35rem;" value="<?= htmlspecialchars((string) ($tool->seoTitle ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Custom Title">
                                                <input type="text" name="seo_description" class="field-input" style="min-height:34px;" value="<?= htmlspecialchars((string) ($tool->seoDescription ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Custom Meta Description">
                                            </td>
                                            <td>
                                                <input type="text" name="seo_canonical" class="field-input" style="min-height:34px;" value="<?= htmlspecialchars((string) ($tool->seoCanonical ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="https://...">
                                            </td>
                                            <td>
                                                <label class="checkbox-inline" style="min-height:24px;"><input type="checkbox" name="seo_indexable" value="1" <?= $tool->seoIndexable ? 'checked' : '' ?>> Indexable</label>
                                                <label class="checkbox-inline" style="min-height:24px;"><input type="checkbox" name="seo_in_sitemap" value="1" <?= $tool->seoInSitemap ? 'checked' : '' ?>> In Sitemap</label>
                                            </td>
                                            <td>
                                                <button type="submit" class="btn-secondary btn-sm">Save SEO</button>
                                            </td>
                                        </form>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>

            <?php elseif ($section === 'branding'): ?>
                <section class="tool-primary-pane">
                    <header class="tool-pane-header">
                        <h2 class="tool-heading">Branding and configurable advertisement slots</h2>
                        <p class="tool-description">Customize workspace identity, accent color, footer notice, and ad slot markup for ad-supported deployments.</p>
                    </header>
                    <form action="/admin/branding" method="post" class="options-grid">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="form_action" value="save_branding">
                        <div class="option-field">
                            <label class="field-label">Site / brand name</label>
                            <input type="text" name="site_name" class="field-input" value="<?= htmlspecialchars((string) ($settings['site_name'] ?? 'Docvera'), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="option-field">
                            <label class="field-label">Header tagline</label>
                            <input type="text" name="site_tagline" class="field-input" value="<?= htmlspecialchars((string) ($settings['site_tagline'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="option-field">
                            <label class="field-label">Accent color (hex)</label>
                            <input type="text" name="accent_color" class="field-input" value="<?= htmlspecialchars((string) ($settings['accent_color'] ?? '#c23b22'), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="option-field" style="grid-column: 1 / -1;">
                            <label class="field-label">Footer compliance / privacy notice</label>
                            <input type="text" name="footer_notice" class="field-input" value="<?= htmlspecialchars((string) ($settings['footer_notice'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="option-field" style="grid-column: 1 / -1;">
                            <label class="field-label">Header ad slot HTML (displayed when ads enabled and plan has <code>ads_visible</code>)</label>
                            <textarea name="ad_slot_header" class="field-textarea" rows="3"><?= htmlspecialchars((string) ($settings['ad_slot_header'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div class="option-field" style="grid-column: 1 / -1;">
                            <label class="field-label">Tool sidebar ad slot HTML</label>
                            <textarea name="ad_slot_tool_sidebar" class="field-textarea" rows="3"><?= htmlspecialchars((string) ($settings['ad_slot_tool_sidebar'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div class="option-field" style="grid-column: 1 / -1;">
                            <label class="field-label">Result page ad slot HTML</label>
                            <textarea name="ad_slot_result" class="field-textarea" rows="3"><?= htmlspecialchars((string) ($settings['ad_slot_result'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <button type="submit" class="btn-primary">Save branding and ad slots</button>
                        </div>
                    </form>
                </section>

            <?php elseif ($section === 'pages'): ?>
                <section class="cap-section">
                    <h2 class="section-heading">Compliance and documentation pages (<code>/p/{slug}</code>)</h2>
                    <div style="display:flex;flex-direction:column;gap:1.5rem;">
                        <?php foreach ($cmsPages as $pg): ?>
                            <form action="/admin/pages" method="post" class="tool-primary-pane">
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="form_action" value="save_cms_page">
                                <input type="hidden" name="slug" value="<?= htmlspecialchars((string) $pg['slug'], ENT_QUOTES, 'UTF-8') ?>">
                                <div class="tool-header-top" style="margin-bottom:0.75rem;">
                                    <h3 class="tool-heading" style="font-size:1.15rem;">
                                        <code>/p/<?= htmlspecialchars((string) $pg['slug'], ENT_QUOTES, 'UTF-8') ?></code>
                                    </h3>
                                    <button type="submit" class="btn-secondary btn-sm">Save page</button>
                                </div>
                                <div class="options-grid" style="margin-bottom:0.75rem;">
                                    <div class="option-field">
                                        <label class="field-label">Page title</label>
                                        <input type="text" name="title" class="field-input" value="<?= htmlspecialchars((string) $pg['title'], ENT_QUOTES, 'UTF-8') ?>" required>
                                    </div>
                                    <div class="option-field">
                                        <label class="field-label">Meta description</label>
                                        <input type="text" name="meta_description" class="field-input" value="<?= htmlspecialchars((string) ($pg['meta_description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                    </div>
                                </div>
                                <div class="option-field" style="margin-bottom:0.75rem;">
                                    <label class="field-label">Body content</label>
                                    <textarea name="body" class="field-textarea" rows="4"><?= htmlspecialchars((string) ($pg['body'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea>
                                </div>
                                <div style="display:flex;gap:1rem;">
                                    <label class="checkbox-inline"><input type="checkbox" name="is_published" value="1" <?= !empty($pg['is_published']) ? 'checked' : '' ?>> Published</label>
                                    <label class="checkbox-inline"><input type="checkbox" name="in_sitemap" value="1" <?= !empty($pg['in_sitemap']) ? 'checked' : '' ?>> Include in Sitemap</label>
                                </div>
                            </form>
                        <?php endforeach; ?>
                    </div>
                </section>

            <?php elseif ($section === 'security'): ?>
                <section class="tool-primary-pane" style="margin-bottom:2rem;">
                    <header class="tool-pane-header">
                        <h2 class="tool-heading">Security controls and billing provider status</h2>
                        <p class="tool-description">
                            Secret keys are loaded from environment variables (<code>.env</code>) or encrypted/masked platform settings and are never echoed back in client markup.
                        </p>
                    </header>

                    <?php if (!empty($usingDefaultSecret)): ?>
                        <div class="capability-blocked-panel" style="margin-bottom:1.5rem;">
                            <h3 class="blocked-title">Notice: ephemeral fallback <code>APP_SECRET</code> Active</h3>
                            <p class="blocked-reason">
                                Your <code>.env</code> file is using the default placeholder <code>APP_SECRET</code>. Docvera has automatically generated and persisted a 256-bit key in <code>storage/app/database/.app_secret</code> (mode <code>0600</code>), but we recommend setting a permanent 64-character hex string in <code>.env</code> for production clusters.
                            </p>
                        </div>
                    <?php endif; ?>

                    <div class="cap-table-wrap" style="margin-bottom:1.5rem;">
                        <table class="cap-table">
                            <thead>
                                <tr>
                                    <th>Billing provider</th>
                                    <th>Configuration state</th>
                                    <th>Operating mode</th>
                                    <th>Webhook endpoint</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($billingProviders as $pKey => $pStat): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars((string) ($pStat['label'] ?? $pStat['display_name'] ?? $pKey), ENT_QUOTES, 'UTF-8') ?></strong></td>
                                        <td>
                                            <?php if (!empty($pStat['configured'])): ?>
                                                <span class="status-pill is-ready">Configured</span>
                                            <?php else: ?>
                                                <span class="status-pill is-unavailable">Unconfigured (Missing Keys)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?= htmlspecialchars((string) $pStat['mode'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                        <td><code>POST /webhooks/billing/<?= htmlspecialchars($pKey, ENT_QUOTES, 'UTF-8') ?></code></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <form action="/admin/security" method="post" class="options-grid" style="margin-bottom:2rem;padding-top:1.25rem;border-top:1px solid var(--border-hairline);">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="form_action" value="save_billing_settings">
                        <div style="grid-column: 1 / -1;">
                            <h3 style="font-size:1rem;margin:0 0 0.25rem;">Payment gateway credentials (Write-Only secrets)</h3>
                            <p style="font-size:0.82rem;color:var(--ink-secondary);margin:0 0 0.75rem;">
                                Leave secret fields blank to preserve existing stored credentials.
                            </p>
                        </div>
                        <div class="option-field">
                            <label class="checkbox-inline"><input type="checkbox" name="billing_enabled" value="1" <?= !empty($settings['billing_enabled']) ? 'checked' : '' ?>> Enable Commercial Subscription Billing</label>
                        </div>
                        <div class="option-field">
                            <label class="checkbox-inline"><input type="checkbox" name="stripe_enabled" value="1" <?= !empty($settings['stripe_enabled']) ? 'checked' : '' ?>> Enable Stripe Checkout and Webhooks</label>
                        </div>
                        <div class="option-field">
                            <label class="field-label">Stripe mode</label>
                            <select name="stripe_mode" class="field-select">
                                <option value="test" <?= ($settings['stripe_mode'] ?? 'test') === 'test' ? 'selected' : '' ?>>test (Sandbox)</option>
                                <option value="live" <?= ($settings['stripe_mode'] ?? '') === 'live' ? 'selected' : '' ?>>live (Production)</option>
                            </select>
                        </div>
                        <div class="option-field">
                            <label class="field-label">Stripe publishable key</label>
                            <input type="text" name="stripe_publishable_key" class="field-input" value="<?= htmlspecialchars((string) ($settings['stripe_publishable_key'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="pk_test_...">
                        </div>
                        <div class="option-field">
                            <label class="field-label">Stripe secret key (Write-Only)</label>
                            <input type="password" name="stripe_secret_key" class="field-input" value="" placeholder="<?= !empty($settings['stripe_secret_key']) ? '•••••••• (Configured)' : 'sk_test_...' ?>" autocomplete="new-password">
                        </div>
                        <div class="option-field">
                            <label class="field-label">Stripe Webhook secret (Write-Only)</label>
                            <input type="password" name="stripe_webhook_secret" class="field-input" value="" placeholder="<?= !empty($settings['stripe_webhook_secret']) ? '•••••••• (Configured)' : 'whsec_...' ?>" autocomplete="new-password">
                        </div>

                        <div class="option-field">
                            <label class="checkbox-inline"><input type="checkbox" name="paypal_enabled" value="1" <?= !empty($settings['paypal_enabled']) ? 'checked' : '' ?>> Enable PayPal Subscriptions</label>
                        </div>
                        <div class="option-field">
                            <label class="field-label">PayPal mode</label>
                            <select name="paypal_mode" class="field-select">
                                <option value="sandbox" <?= ($settings['paypal_mode'] ?? 'sandbox') === 'sandbox' ? 'selected' : '' ?>>sandbox</option>
                                <option value="live" <?= ($settings['paypal_mode'] ?? '') === 'live' ? 'selected' : '' ?>>live</option>
                            </select>
                        </div>
                        <div class="option-field">
                            <label class="field-label">PayPal client ID</label>
                            <input type="text" name="paypal_client_id" class="field-input" value="<?= htmlspecialchars((string) ($settings['paypal_client_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="AeA1QIZXiflr1_-r...">
                        </div>
                        <div class="option-field">
                            <label class="field-label">PayPal client secret (Write-Only)</label>
                            <input type="password" name="paypal_client_secret" class="field-input" value="" placeholder="<?= !empty($settings['paypal_client_secret']) ? '•••••••• (Configured)' : 'ECYYrr...' ?>" autocomplete="new-password">
                        </div>
                        <div class="option-field">
                            <label class="field-label">PayPal Webhook ID</label>
                            <input type="text" name="paypal_webhook_id" class="field-input" value="<?= htmlspecialchars((string) ($settings['paypal_webhook_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="0EH40505U7...">
                        </div>
                        <div class="option-field">
                            <label class="field-label">PayPal Webhook secret (Write-Only)</label>
                            <input type="password" name="paypal_webhook_secret" class="field-input" value="" placeholder="<?= !empty($settings['paypal_webhook_secret']) ? '•••••••• (Configured)' : 'whsec_paypal_...' ?>" autocomplete="new-password">
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <button type="submit" class="btn-primary">Save payment gateway configuration</button>
                        </div>
                    </form>

                    <form action="/admin/security" method="post" class="options-grid" style="margin-bottom:1.5rem;padding-top:1.25rem;border-top:1px solid var(--border-hairline);">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="form_action" value="save_security_settings">
                        <div class="option-field">
                            <label class="field-label">Registration policy</label>
                            <select name="registration_mode" class="field-select">
                                <option value="open" <?= ($settings['registration_mode'] ?? 'open') === 'open' ? 'selected' : '' ?>>Open (Public Self-Registration)</option>
                                <option value="closed" <?= ($settings['registration_mode'] ?? '') === 'closed' ? 'selected' : '' ?>>Closed (Admin Provisioning Only)</option>
                            </select>
                        </div>
                        <div class="option-field" style="justify-content:center;">
                            <label class="checkbox-inline"><input type="checkbox" name="guest_tools_enabled" value="1" <?= !empty($settings['guest_tools_enabled']) ? 'checked' : '' ?>> Allow Guest Tool Execution</label>
                            <label class="checkbox-inline"><input type="checkbox" name="api_global_enabled" value="1" <?= !empty($settings['api_global_enabled']) ? 'checked' : '' ?>> Enable Global REST API (<code>/api/v1/*</code>)</label>
                        </div>
                        <div style="grid-column: 1 / -1;">
                            <button type="submit" class="btn-secondary">Save security and access policy</button>
                        </div>
                    </form>

                    <ul style="margin:0;padding-left:1.25rem;font-size:0.875rem;line-height:1.7;color:var(--ink-secondary);">
                        <li><strong>Isolated Per-Job Workspaces:</strong> Every document operation executes inside a <code>0700</code> directory with UUID-renamed filenames.</li>
                        <li><strong>Zero Shell Interpolation:</strong> All external CLI drivers execute through parameterized array <code>proc_open</code> calls with strict timeouts.</li>
                        <li><strong>Hashed API Keys:</strong> API keys use <code>dcv_live_</code> prefix + CSPRNG entropy and are stored exclusively as SHA-256 hashes.</li>
                        <li><strong>Webhook Verification:</strong> Stripe (<code>Stripe-Signature</code> HMAC-SHA256 + timestamp replay window) and PayPal webhook headers are verified before processing and deduplicated via <code>webhook_events</code>.</li>
                    </ul>
                </section>

            <?php elseif ($section === 'updates'): ?>
                <section class="tool-primary-pane">
                    <header class="tool-pane-header">
                        <h2 class="tool-heading">Update center, schema migrations and safe maintenance</h2>
                        <p class="tool-description">
                            Inspect application version, verify database migrations, clear ephemeral caches, and follow the documented zero-downtime upgrade sequence.
                        </p>
                    </header>

                    <div class="result-telemetry-grid" style="margin-bottom:1.5rem;">
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Codebase version</span>
                            <span class="telemetry-value"><code>v<?= htmlspecialchars($appVersion ?? '2.0.0', ENT_QUOTES, 'UTF-8') ?></code></span>
                        </div>
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Tracked schema version</span>
                            <span class="telemetry-value"><code>v<?= htmlspecialchars((string) ($settings['schema_version'] ?? '2.0.0'), ENT_QUOTES, 'UTF-8') ?></code></span>
                        </div>
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Applied migrations</span>
                            <span class="telemetry-value"><code><?= count($appliedMigrations) ?> applied</code></span>
                        </div>
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Pending migrations</span>
                            <span class="telemetry-value"><code><?= count($pendingMigrations) ?> pending</code></span>
                        </div>
                    </div>

                    <div class="blocked-actions" style="margin-bottom:2rem;">
                        <form action="/admin/updates" method="post" class="inline-delete-form">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="form_action" value="run_migrations">
                            <button type="submit" class="btn-primary">Run database migrations</button>
                        </form>

                        <form action="/admin/updates" method="post" class="inline-delete-form">
                            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="form_action" value="clear_cache">
                            <button type="submit" class="btn-secondary">Clear expired staging and temp cache</button>
                        </form>
                    </div>

                    <h3 style="font-size:0.95rem;margin:0 0 0.5rem;">Documented safe upgrade and rollback sequence</h3>
                    <pre class="api-code-block"><code># 1. Pre-Upgrade Backup (Database + .env configuration)
cp .env .env.backup
cp -r storage/app/database storage/app/database.backup-$(date +%Y%m%d)

# 2. Pull or unpack new release and verify host dependencies
php bin/capabilities

# 3. Execute idempotent database and file-store migrations
php bin/migrate

# 4. Run full automated verification suite
php bin/test

# 5. Rollback Procedure (if needed)
cp -r storage/app/database.backup-YYYYMMDD/* storage/app/database/</code></pre>
                </section>
            <?php endif; ?>
        </div>
    </div>
</div>
