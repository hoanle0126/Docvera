<?php
/**
 * @var array<string, \Docvera\Capabilities\CapabilityStatus> $capabilities
 * @var array<string, \Docvera\Tools\ToolDefinition> $tools
 * @var array<string, mixed> $report
 */
declare(strict_types=1);

$availTools = array_filter($tools, static fn($t): bool => $t->availability->available);
?>
<div class="shell capabilities-workbench">
    <nav class="breadcrumb-nav" aria-label="Breadcrumb">
        <a href="/">Tools</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Installed engines</span>
    </nav>

    <header class="capabilities-header">
        <div>
            <h1 class="workbench-title">What this server can do</h1>
            <p class="workbench-lead">
                A live check of the programs and permissions Docvera relies on. A tool switches itself off when the engine it needs is missing, and turns back on once it’s installed.
            </p>
        </div>
        <div class="capabilities-actions">
            <a href="/api/v1/capabilities" class="btn-secondary">Health check as JSON</a>
        </div>
    </header>

    <!-- System Binaries & Runtime Probes -->
    <section class="cap-section" aria-labelledby="runtime-probes-heading">
        <h2 id="runtime-probes-heading" class="section-heading">Engines and runtime</h2>
        <div class="cap-table-wrap">
            <table class="cap-table">
                <thead>
                    <tr>
                        <th scope="col">Engine</th>
                        <th scope="col">Type</th>
                        <th scope="col">Status</th>
                        <th scope="col">Version</th>
                        <th scope="col">What it does</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($capabilities as $cap): ?>
                        <tr>
                            <td class="cap-name-cell">
                                <strong><?= htmlspecialchars($cap->label, ENT_QUOTES, 'UTF-8') ?></strong>
                                <div class="cap-key-sub"><code><?= htmlspecialchars($cap->key, ENT_QUOTES, 'UTF-8') ?></code></div>
                            </td>
                            <td><code><?= htmlspecialchars($cap->category, ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td>
                                <?php if ($cap->available): ?>
                                    <span class="status-pill is-ready">Installed</span>
                                <?php elseif ($cap->required): ?>
                                    <span class="status-pill is-error">Missing, required</span>
                                <?php else: ?>
                                    <span class="status-pill is-unavailable">Not installed</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <code><?= htmlspecialchars($cap->version ?? '—', ENT_QUOTES, 'UTF-8') ?></code>
                            </td>
                            <td class="cap-desc-cell">
                                <p><?= htmlspecialchars($cap->description, ENT_QUOTES, 'UTF-8') ?></p>
                                <?php if (!$cap->available && $cap->missingReason !== null): ?>
                                    <p class="cap-missing-note"><?= htmlspecialchars($cap->missingReason, ENT_QUOTES, 'UTF-8') ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Tool Availability Resolution Table -->
    <section class="cap-section" aria-labelledby="tool-resolution-heading">
        <div class="section-header-row">
            <h2 id="tool-resolution-heading" class="section-heading">
                Tools <span class="section-count"><?= count($availTools) ?> of <?= count($tools) ?> ready</span>
            </h2>
        </div>

        <div class="cap-table-wrap">
            <table class="cap-table">
                <thead>
                    <tr>
                        <th scope="col" style="width:24%;">Tool</th>
                        <th scope="col" style="width:12%;">Group</th>
                        <th scope="col" style="width:30%;">Can use</th>
                        <th scope="col" style="width:18%;">Using</th>
                        <th scope="col" style="width:16%;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tools as $tool): ?>
                        <tr>
                            <td style="white-space:nowrap;">
                                <a href="/tools/<?= htmlspecialchars($tool->id, ENT_QUOTES, 'UTF-8') ?>" class="table-tool-link">
                                    <?= htmlspecialchars($tool->name, ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </td>
                            <td style="white-space:nowrap;"><code>Group <?= htmlspecialchars($tool->priorityGroup, ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td style="white-space:nowrap;"><code><?= htmlspecialchars(implode(' → ', $tool->candidateDrivers), ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td style="white-space:nowrap;">
                                <?php if ($tool->availability->activeDriver !== null): ?>
                                    <code><?= htmlspecialchars($tool->availability->activeDriver, ENT_QUOTES, 'UTF-8') ?></code>
                                <?php else: ?>
                                    <span>—</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap;">
                                <?php if ($tool->availability->available): ?>
                                    <span class="status-pill is-ready">Ready</span>
                                <?php else: ?>
                                    <div class="status-badge-group">
                                        <span class="status-pill is-unavailable">Not installed</span>
                                        <?php if (!empty($tool->availability->unavailableReason)): ?>
                                            <button
                                                type="button"
                                                class="status-info-badge"
                                                data-tooltip="<?= htmlspecialchars((string) $tool->availability->unavailableReason, ENT_QUOTES, 'UTF-8') ?>"
                                                aria-label="<?= htmlspecialchars((string) $tool->availability->unavailableReason, ENT_QUOTES, 'UTF-8') ?>"
                                            >
                                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="square" stroke-linejoin="miter" aria-hidden="true">
                                                    <circle cx="12" cy="12" r="10"></circle>
                                                    <line x1="12" y1="16" x2="12" y2="12"></line>
                                                    <line x1="12" y1="8" x2="12.01" y2="8"></line>
                                                </svg>
                                                <span class="sr-only"><?= htmlspecialchars((string) $tool->availability->unavailableReason, ENT_QUOTES, 'UTF-8') ?></span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>

