<?php
/**
 * @var array<string, mixed> $job
 * @var \Docvera\Tools\ToolDefinition|null $tool
 * @var string $token
 * @var bool $fileStillOnDisk
 * @var string $csrfToken
 */
declare(strict_types=1);

$jobId = (string) $job['id'];
$toolName = $tool ? $tool->name : (string) ($job['tool_id'] ?? 'Document Operation');
$toolSlug = (string) ($job['tool_id'] ?? '');
$outputBytes = (int) ($job['output_bytes'] ?? 0);
$inputBytes = (int) ($job['input_bytes'] ?? 0);
$summary = is_array($job['summary'] ?? null) ? $job['summary'] : [];
$isDeleted = !$fileStillOnDisk || ($job['deleted_at'] ?? null) !== null;
?>
<div class="shell result-workbench">
    <nav class="breadcrumb-nav" aria-label="Breadcrumb">
        <a href="/">Tools</a>
        <span aria-hidden="true">/</span>
        <?php if ($tool !== null): ?>
            <a href="/tools/<?= htmlspecialchars($tool->id, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($tool->name, ENT_QUOTES, 'UTF-8') ?></a>
            <span aria-hidden="true">/</span>
        <?php endif; ?>
        <span aria-current="page">Operation Result</span>
    </nav>

    <section class="result-sheet" aria-labelledby="result-heading">
        <?php if ($isDeleted): ?>
            <div class="purged-confirmation-state" id="purged-state-panel">
                <span class="status-pill is-purged">Temporary Files Purged</span>
                <h1 id="result-heading" class="result-title">
                    All files for this operation have been permanently deleted.
                </h1>
                <p class="result-lead">
                    Job <code><?= htmlspecialchars($jobId, ENT_QUOTES, 'UTF-8') ?></code> has been wiped from the server's temporary storage directory. Zero copies of your input or output documents remain on disk.
                </p>
                <div class="result-actions-row">
                    <?php if ($tool !== null): ?>
                        <a href="/tools/<?= htmlspecialchars($tool->id, ENT_QUOTES, 'UTF-8') ?>" class="btn-primary">
                            Process another document with <?= htmlspecialchars($tool->name, ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endif; ?>
                    <a href="/" class="btn-secondary">Return to tools index</a>
                </div>
            </div>
        <?php else: ?>
            <div id="active-result-panel">
                <div class="result-header">
                    <span class="status-pill is-ready">Completed · Verified Output</span>
                    <h1 id="result-heading" class="result-title">
                        <?= htmlspecialchars($toolName, ENT_QUOTES, 'UTF-8') ?> finished
                    </h1>
                    <p class="result-lead">
                        Your output file has been verified and is ready for download. Input working files were already purged upon completion.
                    </p>
                </div>

                <!-- Output File Card & Primary Download / Delete Now Actions -->
                <div class="output-artifact-bar">
                    <div class="artifact-details">
                        <p class="artifact-filename"><?= htmlspecialchars((string) $job['output_filename'], ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="artifact-meta">
                            <span><?= htmlspecialchars((string) $job['output_mime'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span>· <?= number_format($outputBytes / 1024, 1) ?> KB (<?= number_format($outputBytes) ?> bytes)</span>
                            <span>· Engine: <code><?= htmlspecialchars((string) $job['driver_used'], ENT_QUOTES, 'UTF-8') ?></code></span>
                        </p>
                    </div>

                    <div class="artifact-actions">
                        <a
                            href="/jobs/<?= htmlspecialchars($jobId, ENT_QUOTES, 'UTF-8') ?>/download?token=<?= urlencode($token) ?>"
                            class="btn-primary btn-download"
                            id="download-output-btn"
                        >
                            Download <?= htmlspecialchars((string) $job['output_filename'], ENT_QUOTES, 'UTF-8') ?>
                        </a>

                        <?php if ($allowDeleteNow ?? true): ?>
                            <form
                                action="/jobs/<?= htmlspecialchars($jobId, ENT_QUOTES, 'UTF-8') ?>/delete"
                                method="post"
                                id="delete-now-form"
                                class="inline-delete-form"
                            >
                                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn-danger" id="delete-now-btn">
                                    Delete file now
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($adsVisible) && !empty($adSlotResult)): ?>
                    <div class="ad-slot-banner" style="margin-bottom:1.25rem;" aria-label="Sponsored notice">
                        <?= $adSlotResult ?>
                    </div>
                <?php endif; ?>

                <!-- Operation Telemetry & Verification Summary -->
                <div class="result-telemetry-grid">
                    <div class="telemetry-cell">
                        <span class="telemetry-label">Job identifier</span>
                        <span class="telemetry-value"><code><?= htmlspecialchars($jobId, ENT_QUOTES, 'UTF-8') ?></code></span>
                    </div>
                    <div class="telemetry-cell">
                        <span class="telemetry-label">Input files</span>
                        <span class="telemetry-value"><?= (int) $job['input_count'] ?> (<?= number_format($inputBytes / 1024, 1) ?> KB)</span>
                    </div>
                    <div class="telemetry-cell">
                        <span class="telemetry-label">Output size</span>
                        <span class="telemetry-value"><?= number_format($outputBytes / 1024, 1) ?> KB</span>
                    </div>
                    <?php foreach ($summary as $sKey => $sVal): ?>
                        <?php if (is_scalar($sVal) && !in_array($sKey, ['original_bytes', 'output_bytes'], true)): ?>
                            <div class="telemetry-cell">
                                <span class="telemetry-label"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) $sKey)), ENT_QUOTES, 'UTF-8') ?></span>
                                <span class="telemetry-value">
                                    <?php if (is_bool($sVal)): ?>
                                        <?= $sVal ? 'Yes' : 'No' ?>
                                    <?php elseif ($sKey === 'saved_percent'): ?>
                                        <?= htmlspecialchars((string) $sVal, ENT_QUOTES, 'UTF-8') ?>% reduction
                                    <?php else: ?>
                                        <?= htmlspecialchars((string) $sVal, ENT_QUOTES, 'UTF-8') ?>
                                    <?php endif; ?>
                                </span>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <div class="telemetry-cell">
                        <span class="telemetry-label">Auto-Deletion policy</span>
                        <span class="telemetry-value">Expires at <?= htmlspecialchars((string) $job['expires_at'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>

                <div class="result-footer-links">
                    <?php if ($tool !== null): ?>
                        <a href="/tools/<?= htmlspecialchars($tool->id, ENT_QUOTES, 'UTF-8') ?>" class="btn-secondary">
                            ← Process another file with <?= htmlspecialchars($tool->name, ENT_QUOTES, 'UTF-8') ?>
                        </a>
                    <?php endif; ?>
                    <a href="/" class="btn-text">Back to all tools</a>
                </div>
            </div>
        <?php endif; ?>
    </section>
</div>

