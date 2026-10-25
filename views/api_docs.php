<?php
/**
 * @var array<int, array<string, mixed>> $endpoints
 * @var array<string, array{http_status: int, description: string}> $errorCodes
 * @var \Docvera\Auth\Principal $principal
 */
declare(strict_types=1);
?>
<div class="shell">
    <nav class="breadcrumb-nav" aria-label="Breadcrumb">
        <a href="/">Tools</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">REST API</span>
    </nav>

    <header class="capabilities-header">
        <div>
            <h1 class="workbench-title">REST API</h1>
            <p class="workbench-lead">
                Every tool on this page is also an endpoint under <code>/api/v1</code>. Requests run through the same pipeline as the browser, so the same limits and file rules apply. This reference is generated from the tools installed on this server.
            </p>
        </div>
        <div class="blocked-actions" style="margin-top:0;">
            <a href="/api/v1/openapi.json" class="btn-secondary">OpenAPI spec</a>
            <a href="/dashboard" class="btn-primary">Get an API key</a>
        </div>
    </header>

    <!-- Authentication, Rate Limits, Idempotency & Async Workflow Overview -->
    <section class="cap-section" aria-labelledby="api-overview-heading">
        <h2 id="api-overview-heading" class="section-heading">Authentication</h2>
        <div class="result-telemetry-grid">
            <div class="telemetry-cell">
                <span class="telemetry-label">Authentication header</span>
                <span class="telemetry-value"><code>Authorization: Bearer dcv_live_…</code></span>
            </div>
            <div class="telemetry-cell">
                <span class="telemetry-label">Alternative key header</span>
                <span class="telemetry-value"><code>X-API-Key: dcv_live_…</code></span>
            </div>
            <div class="telemetry-cell">
                <span class="telemetry-label">Idempotent replay header</span>
                <span class="telemetry-value"><code>Idempotency-Key: &lt;unique-uuid&gt;</code></span>
            </div>
            <div class="telemetry-cell">
                <span class="telemetry-label">Asynchronous execution</span>
                <span class="telemetry-value"><code>async=1</code> or <code>Prefer: respond-async</code></span>
            </div>
        </div>

        <div class="tool-primary-pane" style="margin-top:1rem;">
            <h3 class="subsection-heading">Discovery &amp; Job endpoints</h3>
            <ul style="margin:0;padding-left:1.25rem;font-family:var(--font-mono);font-size:0.825rem;line-height:1.7;">
                <li><code>GET /api/v1/health</code> — Service health and core runtime readiness probe (Public)</li>
                <li><code>GET /api/v1/capabilities</code> — Full capability report and active tool drivers (Public)</li>
                <li><code>GET /api/v1/tools</code> — List all API-capable tools and live server driver availability (Public)</li>
                <li><code>GET /api/v1/usage</code> — Inspect daily/monthly quota and API request telemetry (Authenticated)</li>
                <li><code>GET /api/v1/jobs</code> — List recent jobs for the authenticated user (Scope: <code>jobs:read</code>)</li>
                <li><code>GET /api/v1/jobs/{job_id}</code> — Poll synchronous or asynchronous job status and output telemetry (Scope: <code>jobs:read</code>)</li>
                <li><code>GET /api/v1/jobs/{job_id}/download</code> — Stream the verified output artifact while within retention window (Scope: <code>jobs:download</code>)</li>
                <li><code>DELETE /api/v1/jobs/{job_id}</code> — Immediately wipe job working and output files from disk (Scope: <code>jobs:delete</code>)</li>
            </ul>
        </div>
    </section>

    <!-- Structured Error Codes -->
    <section class="cap-section" aria-labelledby="api-errors-heading">
        <h2 id="api-errors-heading" class="section-heading">Errors</h2>
        <div class="cap-table-wrap">
            <table class="cap-table">
                <thead>
                    <tr>
                        <th>Error code</th>
                        <th>HTTP status</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($errorCodes as $info): ?>
                        <tr>
                            <td><code><?= htmlspecialchars((string) ($info['code'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
                            <td><code><?= (int) ($info['http_status'] ?? 400) ?></code></td>
                            <td><?= htmlspecialchars((string) ($info['description'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Live Tool Endpoints -->
    <section class="cap-section" aria-labelledby="api-endpoints-heading">
        <h2 id="api-endpoints-heading" class="section-heading">Endpoints <span class="section-count"><?= count($endpoints) ?></span></h2>

        <div style="display:flex;flex-direction:column;gap:1.5rem;">
            <?php foreach ($endpoints as $ep): ?>
                <?php
                $accInput = is_array($ep['accepted_input'] ?? null) ? $ep['accepted_input'] : [];
                $authSpec = is_array($ep['authentication'] ?? null) ? $ep['authentication'] : [];
                $syncEx = is_array($ep['example_sync_response'] ?? null) ? $ep['example_sync_response'] : [];
                ?>
                <article class="tool-primary-pane" id="ep-<?= htmlspecialchars((string) $ep['tool_id'], ENT_QUOTES, 'UTF-8') ?>">
                    <header class="tool-pane-header">
                        <div class="tool-header-top">
                            <h3 class="tool-heading" style="font-size:1.25rem;">
                                <code><?= htmlspecialchars((string) $ep['method'] . ' ' . (string) ($ep['path'] ?? $ep['endpoint'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code>
                                — <?= htmlspecialchars((string) $ep['name'], ENT_QUOTES, 'UTF-8') ?>
                            </h3>
                            <?php if (!empty($ep['available_on_server'])): ?>
                                <span class="status-pill is-ready">Ready · <?= htmlspecialchars((string) $ep['active_driver'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php else: ?>
                                <span class="status-pill is-unavailable">Driver Missing on Host</span>
                            <?php endif; ?>
                        </div>
                        <p class="tool-description"><?= htmlspecialchars((string) $ep['description'], ENT_QUOTES, 'UTF-8') ?></p>
                    </header>

                    <div class="result-telemetry-grid">
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Authentication and scope</span>
                            <span class="telemetry-value"><code><?= htmlspecialchars(implode(' | ', (array) ($authSpec['required_scopes'] ?? ['tools:execute'])), ENT_QUOTES, 'UTF-8') ?></code></span>
                        </div>
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Accepted input and files</span>
                            <span class="telemetry-value">
                                <code><?= htmlspecialchars((string) ($accInput['file_field'] ?? 'files[]'), ENT_QUOTES, 'UTF-8') ?></code>
                                (<?= (int) ($accInput['min_files'] ?? 1) ?>–<?= (int) ($accInput['max_files'] ?? 1) ?> files)
                            </span>
                        </div>
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Supported MIME types</span>
                            <span class="telemetry-value"><code><?= htmlspecialchars(implode(', ', (array) ($accInput['supported_mimes'] ?? [])), ENT_QUOTES, 'UTF-8') ?></code></span>
                        </div>
                        <div class="telemetry-cell">
                            <span class="telemetry-label">Output format</span>
                            <span class="telemetry-value"><code><?= htmlspecialchars((string) ($syncEx['output_mime'] ?? 'application/pdf'), ENT_QUOTES, 'UTF-8') ?></code></span>
                        </div>
                    </div>

                    <?php if (!empty($ep['options'])): ?>
                        <h4 class="spec-sheet-title">Available parameters (<code>multipart/form-data</code>)</h4>
                        <div class="cap-table-wrap" style="margin-bottom:1.25rem;">
                            <table class="cap-table">
                                <thead>
                                    <tr>
                                        <th>Field name</th>
                                        <th>Type</th>
                                        <th>Default</th>
                                        <th>Allowed values / constraints</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($ep['options'] as $opt): ?>
                                        <tr>
                                            <td>
                                                <code><?= htmlspecialchars((string) $opt['name'], ENT_QUOTES, 'UTF-8') ?></code>
                                                <?php if (!empty($opt['required'])): ?>
                                                    <span class="required-mark">*</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><code><?= htmlspecialchars((string) $opt['type'], ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td><code><?= htmlspecialchars((string) ($opt['default'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></code></td>
                                            <td>
                                                <?php if (!empty($opt['choices'])): ?>
                                                    <code><?= htmlspecialchars(implode(' | ', array_map('strval', array_keys((array) $opt['choices']))), ENT_QUOTES, 'UTF-8') ?></code>
                                                <?php elseif (($opt['min'] ?? null) !== null || ($opt['max'] ?? null) !== null): ?>
                                                    <code><?= (int) ($opt['min'] ?? 0) ?>..<?= (int) ($opt['max'] ?? 9999) ?></code>
                                                <?php else: ?>
                                                    <code>string</code>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars((string) ($opt['help_text'] ?? $opt['label'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <tr>
                                        <td><code>async</code></td>
                                        <td><code>boolean</code></td>
                                        <td><code>0</code></td>
                                        <td><code>0 | 1</code></td>
                                        <td>Submit <code>async=1</code> to queue job for background execution (HTTP 202).</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <div class="api-examples-stack">
                        <div>
                            <h4 class="spec-sheet-title">Request</h4>
                            <pre class="api-code-block"><code><?= htmlspecialchars((string) ($ep['example_curl'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></pre>
                        </div>
                        <div>
                            <h4 class="spec-sheet-title">Response</h4>
                            <pre class="api-code-block"><code><?= htmlspecialchars((string) json_encode($syncEx, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') ?></code></pre>
                        </div>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
</div>
