<?php
/**
 * @var \Docvera\Auth\Principal $principal
 * @var array<string, mixed> $usage
 * @var array<string, mixed> $plan
 * @var array<string, mixed>|null $subscription
 * @var array<int, array<string, mixed>> $payments
 * @var array<int, array<string, mixed>> $recentJobs
 * @var array<int, array<string, mixed>> $apiKeys
 * @var array<int, array<string, mixed>> $apiLogs
 * @var string|null $newApiKeyPlain
 * @var string $flashMessage
 * @var string|null $errorMessage
 * @var bool $billingEnabled
 * @var bool $allowDeleteNow
 * @var string $csrfToken
 */
declare(strict_types=1);

$ent = is_array($plan['entitlements'] ?? null) ? $plan['entitlements'] : [];
$apiAccess = !empty($usage['api_access']) || !empty($ent['api_access']);
?>
<div class="shell">
    <nav class="breadcrumb-nav" aria-label="Breadcrumb">
        <a href="/">Tools</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Dashboard</span>
    </nav>

    <header class="capabilities-header">
        <div>
            <h1 class="workbench-title">Your workspace</h1>
            <p class="workbench-lead">
                Signed in as <strong><?= htmlspecialchars((string) $principal->name, ENT_QUOTES, 'UTF-8') ?></strong>,
                <?= htmlspecialchars((string) $principal->email, ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>
        <div class="blocked-actions" style="margin-top:0;">
            <span class="status-pill is-ready">Plan: <?= htmlspecialchars((string) ($plan['name'] ?? $principal->planId), ENT_QUOTES, 'UTF-8') ?></span>
            <?php if ($billingEnabled): ?>
                <a href="/pricing" class="btn-secondary">Change plan</a>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!empty($flashMessage)): ?>
        <div class="staged-banner" role="status" style="margin-bottom:1.5rem;">
            <div class="staged-banner-info">
                <span class="staged-badge">Updated</span>
                <span><?= htmlspecialchars((string) $flashMessage, ENT_QUOTES, 'UTF-8') ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($errorMessage)): ?>
        <div class="alert-banner is-error" role="alert">
            <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($newApiKeyPlain)): ?>
        <div class="capability-blocked-panel is-ready" role="alert" style="margin-bottom:2rem;">
            <h2 class="blocked-title" style="color:var(--status-ready-fg);">Copy your new API key now</h2>
            <p class="blocked-reason">
                Docvera stores only the SHA-256 hash of this secret key. This full key will never be displayed again:
            </p>
            <div style="padding:0.75rem 1rem;background:var(--bg-sheet);border:1px solid var(--ink-primary);font-family:var(--font-mono);font-size:0.95rem;word-break:break-all;">
                <code id="one-time-api-key"><?= htmlspecialchars($newApiKeyPlain, ENT_QUOTES, 'UTF-8') ?></code>
            </div>
        </div>
    <?php endif; ?>

    <!-- Section 1: Authoritative Quota & Plan Telemetry -->
    <section class="cap-section" aria-labelledby="quota-heading">
        <h2 id="quota-heading" class="section-heading">Usage this period</h2>
        <div class="result-telemetry-grid">
            <div class="telemetry-cell">
                <span class="telemetry-label">Operations today (<?= htmlspecialchars((string) ($usage['period_day'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)</span>
                <span class="telemetry-value"><?= (int) ($usage['ops_today'] ?? 0) ?> / <?= (int) ($usage['ops_per_day_limit'] ?? 25) ?></span>
            </div>
            <div class="telemetry-cell">
                <span class="telemetry-label">Operations this month (<?= htmlspecialchars((string) ($usage['period_month'] ?? ''), ENT_QUOTES, 'UTF-8') ?>)</span>
                <span class="telemetry-value"><?= (int) ($usage['ops_this_month'] ?? 0) ?> / <?= (int) ($usage['ops_per_month_limit'] ?? 500) ?></span>
            </div>
            <div class="telemetry-cell">
                <span class="telemetry-label">Max file size / files per op</span>
                <span class="telemetry-value"><?= (int) ($usage['max_file_size_mb'] ?? $ent['max_file_size_mb'] ?? 25) ?> MB · <?= (int) ($usage['max_files_per_op'] ?? $ent['max_files_per_op'] ?? 5) ?> files</span>
            </div>
            <div class="telemetry-cell">
                <span class="telemetry-label">Max pages / concurrent jobs</span>
                <span class="telemetry-value"><?= (int) ($usage['max_pages_per_op'] ?? $ent['max_pages_per_op'] ?? 100) ?> pages · <?= (int) ($usage['active_concurrent_jobs'] ?? 0) ?>/<?= (int) ($usage['concurrent_jobs_limit'] ?? 2) ?> active</span>
            </div>
            <div class="telemetry-cell">
                <span class="telemetry-label">API requests today</span>
                <span class="telemetry-value">
                    <?php if ($apiAccess): ?>
                        <?= (int) ($usage['api_requests_today'] ?? 0) ?> / <?= (int) ($usage['api_requests_per_day_limit'] ?? 0) ?>
                    <?php else: ?>
                        Not enabled on current plan
                    <?php endif; ?>
                </span>
            </div>
            <div class="telemetry-cell">
                <span class="telemetry-label">File retention window</span>
                <span class="telemetry-value"><?= (int) ($usage['retention_minutes'] ?? $ent['retention_minutes'] ?? 60) ?> minutes</span>
            </div>
        </div>

        <?php if (is_array($subscription)): ?>
            <div class="output-artifact-bar" style="margin-top:1rem;">
                <div class="artifact-details">
                    <p class="artifact-filename">
                        Subscription: <code><?= htmlspecialchars((string) $subscription['id'], ENT_QUOTES, 'UTF-8') ?></code>
                        · Status: <span class="status-pill is-ready"><?= htmlspecialchars(strtoupper((string) $subscription['status']), ENT_QUOTES, 'UTF-8') ?></span>
                    </p>
                    <p class="artifact-meta">
                        Provider: <code><?= htmlspecialchars((string) $subscription['provider'], ENT_QUOTES, 'UTF-8') ?></code>
                        · Interval: <code><?= htmlspecialchars((string) $subscription['billing_interval'], ENT_QUOTES, 'UTF-8') ?></code>
                        · Current Period Ends: <code><?= htmlspecialchars((string) ($subscription['current_period_end'] ?? 'N/A'), ENT_QUOTES, 'UTF-8') ?></code>
                        <?php if (!empty($subscription['cancel_at_period_end'])): ?>
                            · <strong>Scheduled to cancel at period end</strong>
                        <?php endif; ?>
                    </p>
                </div>
                <?php if (in_array((string) $subscription['status'], ['active', 'trialing', 'past_due'], true)): ?>
                    <form action="/billing/cancel" method="post" class="inline-delete-form">
                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="subscription_id" value="<?= htmlspecialchars((string) $subscription['id'], ENT_QUOTES, 'UTF-8') ?>">
                        <input type="hidden" name="cancel_mode" value="period_end">
                        <button type="submit" class="btn-danger">Cancel at period end</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- Section 2: Recent Document Processing Jobs -->
    <section class="cap-section" aria-labelledby="jobs-heading">
        <h2 id="jobs-heading" class="section-heading">Recent jobs</h2>
        <p style="margin:0 0 0.85rem;font-size:0.85rem;color:var(--ink-secondary);">
            Document files are never retained longer than your configured retention window (<?= (int) ($ent['retention_minutes'] ?? 60) ?>m). After files are wiped from disk, only non-sensitive operation telemetry remains below.
        </p>
        <div class="cap-table-wrap">
            <table class="cap-table">
                <thead>
                    <tr>
                        <th>Job ID</th>
                        <th>Tool and source</th>
                        <th>Status</th>
                        <th>Input / output</th>
                        <th>Retention and deletion</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($recentJobs === []): ?>
                        <tr>
                            <td colspan="6">No document operations recorded for this workspace yet.</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentJobs as $j): ?>
                            <?php
                            $jId = (string) $j['id'];
                            $onDisk = !empty($j['file_on_disk']);
                            $deletedAt = $j['deleted_at'] ?? null;
                            ?>
                            <tr>
                                <td><code><?= htmlspecialchars($jId, ENT_QUOTES, 'UTF-8') ?></code></td>
                                <td>
                                    <strong><?= htmlspecialchars((string) $j['tool_id'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <div class="cap-key-sub">
                                        Source: <code><?= htmlspecialchars((string) ($j['source'] ?? 'web'), ENT_QUOTES, 'UTF-8') ?></code>
                                        · Driver: <code><?= htmlspecialchars((string) ($j['driver_used'] ?? 'n/a'), ENT_QUOTES, 'UTF-8') ?></code>
                                    </div>
                                </td>
                                <td>
                                    <?php if (($j['status'] ?? '') === 'completed'): ?>
                                        <span class="status-pill is-ready">Completed</span>
                                    <?php elseif (($j['status'] ?? '') === 'failed'): ?>
                                        <span class="status-pill is-error">Failed</span>
                                    <?php else: ?>
                                        <span class="status-pill is-unavailable"><?= htmlspecialchars((string) $j['status'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div>In: <?= (int) ($j['input_count'] ?? 1) ?> file(s) (<?= number_format(((int) ($j['input_bytes'] ?? 0)) / 1024, 1) ?> KB)</div>
                                    <div class="cap-key-sub">
                                        Out: <?= number_format(((int) ($j['output_bytes'] ?? 0)) / 1024, 1) ?> KB
                                        <?php if (!empty($j['page_count'])): ?>
                                            · <?= (int) $j['page_count'] ?> pages
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if ($deletedAt !== null || !$onDisk): ?>
                                        <span class="status-pill is-purged">Files Purged</span>
                                        <div class="cap-key-sub"><?= htmlspecialchars((string) ($deletedAt ?? $j['expires_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php else: ?>
                                        <div>Expires: <code><?= htmlspecialchars((string) $j['expires_at'], ENT_QUOTES, 'UTF-8') ?></code></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($onDisk): ?>
                                        <div class="artifact-actions">
                                            <a href="/jobs/<?= htmlspecialchars($jId, ENT_QUOTES, 'UTF-8') ?>/download" class="btn-primary btn-sm">Download</a>
                                            <?php if ($allowDeleteNow): ?>
                                                <form action="/jobs/<?= htmlspecialchars($jId, ENT_QUOTES, 'UTF-8') ?>/delete" method="post" class="inline-delete-form">
                                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="return_to" value="dashboard">
                                                    <button type="submit" class="btn-danger btn-sm">Delete now</button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <a href="/tools/<?= htmlspecialchars((string) $j['tool_id'], ENT_QUOTES, 'UTF-8') ?>" class="btn-text">Run Tool Again</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Section 3: REST API Keys & Audit Telemetry -->
    <section class="cap-section" aria-labelledby="apikeys-heading">
        <h2 id="apikeys-heading" class="section-heading">API keys</h2>
        <?php if (!$apiAccess): ?>
            <div class="capability-blocked-panel">
                <h3 class="blocked-title">Your plan doesn’t include API access</h3>
                <p class="blocked-reason">
                    Your current plan (<code><?= htmlspecialchars((string) ($plan['name'] ?? $principal->planId), ENT_QUOTES, 'UTF-8') ?></code>) does not include programmatic API access.
                </p>
                <div class="blocked-actions">
                    <a href="/pricing" class="btn-primary">Upgrade plan for API access</a>
                    <a href="/developers/api" class="btn-secondary">Read API specification</a>
                </div>
            </div>
        <?php else: ?>
            <div class="tool-primary-pane" style="margin-bottom:1.5rem;">
                <form action="/dashboard/api-keys/create" method="post" class="options-grid" style="align-items:end;">
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="option-field">
                        <label for="api-key-label" class="field-label">New API key label</label>
                        <input type="text" id="api-key-label" name="label" class="field-input" placeholder="Production Backend Worker" required>
                    </div>
                    <div class="option-field">
                        <span class="field-label">Permitted Scopes</span>
                        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;min-height:44px;align-items:center;">
                            <label class="checkbox-inline"><input type="checkbox" name="scopes[]" value="tools:execute" checked> <code>tools:execute</code></label>
                            <label class="checkbox-inline"><input type="checkbox" name="scopes[]" value="jobs:read" checked> <code>jobs:read</code></label>
                            <label class="checkbox-inline"><input type="checkbox" name="scopes[]" value="jobs:download" checked> <code>jobs:download</code></label>
                            <label class="checkbox-inline"><input type="checkbox" name="scopes[]" value="jobs:delete" checked> <code>jobs:delete</code></label>
                        </div>
                    </div>
                    <div>
                        <button type="submit" class="btn-primary">Generate secret key</button>
                    </div>
                </form>
            </div>

            <div class="cap-table-wrap" style="margin-bottom:1.5rem;">
                <table class="cap-table">
                    <thead>
                        <tr>
                            <th>Key ID and label</th>
                            <th>Prefix</th>
                            <th>Scopes</th>
                            <th>Status and last used</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($apiKeys === []): ?>
                            <tr>
                                <td colspan="5">No API keys created yet. Generate a key above to authenticate against <code>/api/v1/*</code>.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($apiKeys as $k): ?>
                                <?php $revoked = ($k['revoked_at'] ?? null) !== null; ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars((string) ($k['name'] ?? $k['label'] ?? 'API Key'), ENT_QUOTES, 'UTF-8') ?></strong>
                                        <div class="cap-key-sub"><code><?= htmlspecialchars((string) $k['id'], ENT_QUOTES, 'UTF-8') ?></code></div>
                                    </td>
                                    <td><code><?= htmlspecialchars((string) ($k['key_prefix'] ?? 'dcv_live_***'), ENT_QUOTES, 'UTF-8') ?>…</code></td>
                                    <td><code><?= htmlspecialchars(implode(', ', (array) ($k['scopes'] ?? [])), ENT_QUOTES, 'UTF-8') ?></code></td>
                                    <td>
                                        <?php if ($revoked): ?>
                                            <span class="status-pill is-purged">Revoked</span>
                                            <div class="cap-key-sub"><?= htmlspecialchars((string) $k['revoked_at'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php else: ?>
                                            <span class="status-pill is-ready">Active</span>
                                            <div class="cap-key-sub">Last used: <?= htmlspecialchars((string) ($k['last_used_at'] ?? 'Never'), ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$revoked): ?>
                                            <div class="artifact-actions">
                                                <form action="/dashboard/api-keys/rotate" method="post" class="inline-delete-form">
                                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="key_id" value="<?= htmlspecialchars((string) $k['id'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <button type="submit" class="btn-secondary btn-sm">Rotate</button>
                                                </form>
                                                <form action="/dashboard/api-keys/revoke" method="post" class="inline-delete-form">
                                                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                    <input type="hidden" name="key_id" value="<?= htmlspecialchars((string) $k['id'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <button type="submit" class="btn-danger btn-sm">Revoke</button>
                                                </form>
                                            </div>
                                        <?php else: ?>
                                            <span class="related-unavail-tag">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($apiLogs !== []): ?>
                <h3 style="font-size:0.95rem;margin:0 0 0.5rem;">Recent API requests</h3>
                <div class="cap-table-wrap">
                    <table class="cap-table">
                        <thead>
                            <tr>
                                <th>Request ID</th>
                                <th>Method and endpoint</th>
                                <th>Tool</th>
                                <th>HTTP status</th>
                                <th>Duration</th>
                                <th>Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($apiLogs as $log): ?>
                                <tr>
                                    <td><code><?= htmlspecialchars((string) $log['request_id'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                    <td><code><?= htmlspecialchars((string) $log['method'] . ' ' . (string) $log['endpoint'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                    <td><code><?= htmlspecialchars((string) ($log['tool_id'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></code></td>
                                    <td><code><?= (int) $log['status_code'] ?></code></td>
                                    <td><code><?= (int) $log['duration_ms'] ?> ms</code></td>
                                    <td><code><?= htmlspecialchars((string) $log['created_at'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>

    <!-- Section 4: Account & Security Settings -->
    <section class="cap-section" aria-labelledby="security-heading">
        <h2 id="security-heading" class="section-heading">Account and security</h2>
        <div class="tool-primary-pane" style="max-width:640px;">
            <form action="/dashboard/security/password" method="post" class="options-grid">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="option-field">
                    <label for="cur-password" class="field-label">Current password</label>
                    <input type="password" id="cur-password" name="current_password" class="field-input" required autocomplete="current-password">
                </div>
                <div class="option-field">
                    <label for="new-password" class="field-label">New password (min 8 chars)</label>
                    <input type="password" id="new-password" name="new_password" class="field-input" minlength="8" required autocomplete="new-password">
                </div>
                <div style="grid-column: 1 / -1;">
                    <button type="submit" class="btn-secondary">Update account password</button>
                </div>
            </form>
        </div>
    </section>
</div>
