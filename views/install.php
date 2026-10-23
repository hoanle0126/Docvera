<?php
/**
 * @var array{
 *   all_required_passed: bool,
 *   required_checks: list<array<string, mixed>>,
 *   optional_capabilities: list<array<string, mixed>>,
 *   runtime_services: list<array<string, mixed>>
 * } $report
 * @var array<string, mixed> $submitted
 * @var string|null $errorMessage
 * @var string $csrfToken
 */
declare(strict_types=1);

$allRequiredPassed = !empty($report['all_required_passed']);
$requiredChecks = $report['required_checks'] ?? [];
$optionalCaps = $report['optional_capabilities'] ?? [];
$runtimeServices = $report['runtime_services'] ?? [];
?>
<div class="shell capabilities-page">
    <header class="capabilities-header">
        <div>
            <div class="tool-category-tag" style="margin-bottom:0.4rem;">PRE-FLIGHT ENVIRONMENT VERIFICATION and SETUP</div>
            <h1 class="workbench-title">Install Docvera</h1>
            <p class="workbench-lead">
                Verify required server prerequisites, inspect optional document processing binaries, configure your deployment mode, and provision the primary Administrator account.
            </p>
        </div>
        <div class="blocked-actions" style="margin-top:0;">
            <?php if ($allRequiredPassed): ?>
                <span class="status-pill is-ready">All Required Checks Passed</span>
            <?php else: ?>
                <span class="status-pill is-error">Action Needed: Required Checks Missing</span>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!empty($errorMessage)): ?>
        <div class="alert-banner is-error" role="alert" style="margin-bottom:1.5rem;">
            <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <!-- 1. REQUIRED HOST PREREQUISITES -->
    <section class="cap-section" aria-labelledby="req-heading" style="margin-bottom:2rem;">
        <h2 id="req-heading" class="section-heading">1. Required Server Prerequisites (Must Pass to Install)</h2>
        <div class="cap-table-wrap">
            <table class="cap-table">
                <thead>
                    <tr>
                        <th>Check</th>
                        <th>Requirement class</th>
                        <th>Detected status</th>
                        <th>Value / remediation</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($requiredChecks as $chk): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars((string) $chk['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <div class="cap-key-sub"><code><?= htmlspecialchars((string) $chk['key'], ENT_QUOTES, 'UTF-8') ?></code></div>
                            </td>
                            <td><code>REQUIRED</code></td>
                            <td>
                                <?php if (!empty($chk['passed'])): ?>
                                    <span class="status-pill is-ready">PASS</span>
                                <?php else: ?>
                                    <span class="status-pill is-error">REQUIRED — MISSING</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code><?= htmlspecialchars((string) $chk['current'], ENT_QUOTES, 'UTF-8') ?></code>
                                <?php if (empty($chk['passed'])): ?>
                                    <div class="cap-missing-note">Remediation: <code><?= htmlspecialchars((string) $chk['remediation'], ENT_QUOTES, 'UTF-8') ?></code></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- 2. OPTIONAL PROCESSING BINARIES -->
    <section class="cap-section" aria-labelledby="opt-heading" style="margin-bottom:2rem;">
        <h2 id="opt-heading" class="section-heading">2. Optional Document Processing Binaries (Gracefully Degraded if Absent)</h2>
        <p style="margin:0 0 1rem;font-size:0.9rem;color:var(--ink-secondary);">
            Missing optional binaries <strong>do not block installation</strong>. Docvera automatically adapts its tool directory to your host capabilities—for example, if <code>tesseract</code> is not installed, OCR is marked unavailable while all other PDF and image tools operate normally.
        </p>
        <div class="cap-table-wrap">
            <table class="cap-table">
                <thead>
                    <tr>
                        <th>Binary engine</th>
                        <th>Requirement class</th>
                        <th>Host availability</th>
                        <th>Feature impact and installation command</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($optionalCaps as $opt): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars((string) $opt['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <div class="cap-key-sub"><code><?= htmlspecialchars((string) $opt['key'], ENT_QUOTES, 'UTF-8') ?></code></div>
                            </td>
                            <td><code>OPTIONAL CAPABILITY</code></td>
                            <td>
                                <?php if (!empty($opt['available'])): ?>
                                    <span class="status-pill is-ready">Installed</span>
                                    <div class="cap-key-sub"><code><?= htmlspecialchars((string) ($opt['binary_path'] ?? $opt['version']), ENT_QUOTES, 'UTF-8') ?></code></div>
                                <?php else: ?>
                                    <span class="status-pill is-unavailable">Optional (Not Installed)</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?= htmlspecialchars((string) $opt['impact'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php if (empty($opt['available'])): ?>
                                    <div class="cap-key-sub" style="margin-top:0.25rem;">Install anytime: <code><?= htmlspecialchars((string) $opt['remediation'], ENT_QUOTES, 'UTF-8') ?></code></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- 3. QUEUE & SCHEDULER / CRON REQUIREMENTS -->
    <section class="cap-section" aria-labelledby="svc-heading" style="margin-bottom:2rem;">
        <h2 id="svc-heading" class="section-heading">3. Queue Worker and Scheduled Cleanup Architecture</h2>
        <div class="cap-table-wrap">
            <table class="cap-table">
                <thead>
                    <tr>
                        <th>Service</th>
                        <th>Requirement class</th>
                        <th>Mode</th>
                        <th>Operational notes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($runtimeServices as $svc): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars((string) $svc['name'], ENT_QUOTES, 'UTF-8') ?></strong></td>
                            <td><code><?= htmlspecialchars((string) $svc['category'], ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td><span class="status-pill is-ready"><?= htmlspecialchars((string) $svc['status'], ENT_QUOTES, 'UTF-8') ?></span></td>
                            <td><?= htmlspecialchars((string) $svc['description'], ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- 4. CONFIGURATION & INITIAL ADMIN PROVISIONING -->
    <section class="tool-primary-pane" aria-labelledby="setup-heading">
        <header class="tool-pane-header">
            <h2 id="setup-heading" class="tool-heading">4. Instance Configuration and Administrator Setup</h2>
            <p class="tool-description">
                Completing this form generates a cryptographic 256-bit <code>APP_SECRET</code>, writes <code>.env</code> (mode <code>0600</code>), runs all database migrations, creates your Administrator account, and locks <code>/install</code> against future execution.
            </p>
        </header>

        <form action="/install" method="post">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <h3 style="font-size:0.95rem;margin:0 0 0.75rem;">Workspace and business mode</h3>
            <div class="options-grid" style="margin-bottom:1.5rem;">
                <div class="option-field">
                    <label for="inst-site-name" class="field-label">Workspace / brand name</label>
                    <input type="text" id="inst-site-name" name="site_name" class="field-input" value="<?= htmlspecialchars((string) $submitted['site_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="option-field">
                    <label for="inst-app-url" class="field-label">Public base URL</label>
                    <input type="url" id="inst-app-url" name="app_url" class="field-input" value="<?= htmlspecialchars((string) $submitted['app_url'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="option-field">
                    <label for="inst-mode" class="field-label">Initial business mode</label>
                    <select id="inst-mode" name="deployment_mode" class="field-select">
                        <option value="saas" <?= ($submitted['deployment_mode'] ?? 'saas') === 'saas' ? 'selected' : '' ?>>Freemium / Paid SaaS (Guest quotas, accounts, plans, REST API)</option>
                        <option value="free_ads" <?= ($submitted['deployment_mode'] ?? '') === 'free_ads' ? 'selected' : '' ?>>Free / Ad-Supported Public Utility</option>
                        <option value="private" <?= ($submitted['deployment_mode'] ?? '') === 'private' ? 'selected' : '' ?>>Private Internal Workspace (Authentication Required)</option>
                    </select>
                </div>
                <div class="option-field">
                    <label for="inst-retention" class="field-label">Default file retention (minutes)</label>
                    <input type="number" id="inst-retention" name="default_retention_minutes" class="field-input" value="<?= (int) ($submitted['default_retention_minutes'] ?? 60) ?>" min="5" max="10080" required>
                </div>
                <div class="option-field">
                    <label for="inst-max-mb" class="field-label">Default max upload size (MB)</label>
                    <input type="number" id="inst-max-mb" name="max_file_size_mb" class="field-input" value="<?= (int) ($submitted['max_file_size_mb'] ?? 50) ?>" min="1" max="500" required>
                </div>
            </div>

            <h3 style="font-size:0.95rem;margin:0 0 0.75rem;padding-top:1rem;border-top:1px solid var(--border-hairline);">Database storage engine</h3>
            <div class="options-grid" style="margin-bottom:1.5rem;">
                <div class="option-field">
                    <label for="inst-db-driver" class="field-label">Persistence driver</label>
                    <select id="inst-db-driver" name="db_driver" class="field-select">
                        <option value="file" <?= ($submitted['db_driver'] ?? 'file') === 'file' ? 'selected' : '' ?>>Concurrency-Safe File Store (Zero DB Daemon Required)</option>
                        <option value="sqlite" <?= ($submitted['db_driver'] ?? '') === 'sqlite' ? 'selected' : '' ?>>SQLite 3 (WAL Mode via pdo_sqlite)</option>
                        <option value="mysql" <?= ($submitted['db_driver'] ?? '') === 'mysql' ? 'selected' : '' ?>>MySQL / MariaDB / PostgreSQL (via PDO DSN)</option>
                    </select>
                </div>
                <div class="option-field">
                    <label for="inst-sqlite-path" class="field-label">SQLite path (if SQLite selected)</label>
                    <input type="text" id="inst-sqlite-path" name="db_sqlite_path" class="field-input" value="<?= htmlspecialchars((string) $submitted['db_sqlite_path'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="option-field">
                    <label for="inst-db-dsn" class="field-label">PDO DSN (if MySQL / PostgreSQL)</label>
                    <input type="text" id="inst-db-dsn" name="db_dsn" class="field-input" value="<?= htmlspecialchars((string) $submitted['db_dsn'], ENT_QUOTES, 'UTF-8') ?>" placeholder="mysql:host=127.0.0.1;port=3306;dbname=docvera;charset=utf8mb4">
                </div>
                <div class="option-field">
                    <label for="inst-db-user" class="field-label">DB username (optional)</label>
                    <input type="text" id="inst-db-user" name="db_username" class="field-input" value="<?= htmlspecialchars((string) $submitted['db_username'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="option-field">
                    <label for="inst-db-pass" class="field-label">DB password (optional)</label>
                    <input type="password" id="inst-db-pass" name="db_password" class="field-input" value="" autocomplete="new-password">
                </div>
            </div>

            <h3 style="font-size:0.95rem;margin:0 0 0.75rem;padding-top:1rem;border-top:1px solid var(--border-hairline);">Primary administrator account</h3>
            <div class="options-grid" style="margin-bottom:1.5rem;">
                <div class="option-field">
                    <label for="inst-admin-name" class="field-label">Administrator name</label>
                    <input type="text" id="inst-admin-name" name="admin_name" class="field-input" value="<?= htmlspecialchars((string) $submitted['admin_name'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="option-field">
                    <label for="inst-admin-email" class="field-label">Administrator email</label>
                    <input type="email" id="inst-admin-email" name="admin_email" class="field-input" value="<?= htmlspecialchars((string) $submitted['admin_email'], ENT_QUOTES, 'UTF-8') ?>" required>
                </div>
                <div class="option-field">
                    <label for="inst-admin-pass" class="field-label">Administrator password (min 8 chars)</label>
                    <input type="password" id="inst-admin-pass" name="admin_password" class="field-input" minlength="8" required autocomplete="new-password">
                </div>
            </div>

            <button type="submit" class="btn-primary" <?= !$allRequiredPassed ? 'disabled' : '' ?>>
                Complete Installation and Lock Installer →
            </button>
        </form>
    </section>
</div>

