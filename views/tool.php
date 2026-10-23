<?php
/**
 * @var \Docvera\Tools\ToolDefinition $tool
 * @var array<string, mixed>|null $stagedManifest
 * @var string|null $errorMessage
 * @var array<string, \Docvera\Tools\ToolDefinition> $allTools
 * @var array<string, string> $categories
 * @var int $maxFileSizeMb
 * @var int $retentionMinutes
 * @var string $csrfToken
 */
declare(strict_types=1);

$isAvailable = $tool->availability->available;
$categoryLabel = $categories[$tool->category] ?? 'Document Tools';
$stagedInsp = ($stagedManifest !== null && is_array($stagedManifest['inspection'] ?? null))
    ? $stagedManifest['inspection']
    : [];
?>
<div class="shell tool-workbench-layout">
    <nav class="breadcrumb-nav" aria-label="Breadcrumb">
        <a href="/">Tools</a>
        <span aria-hidden="true">/</span>
        <span><?= htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8') ?></span>
        <span aria-hidden="true">/</span>
        <span aria-current="page"><?= htmlspecialchars($tool->name, ENT_QUOTES, 'UTF-8') ?></span>
    </nav>

    <div class="tool-workspace-grid">
        <!-- Left / Main Column: Tool Execution Workbench -->
        <div class="tool-primary-pane">
            <header class="tool-pane-header">
                <div class="tool-header-top">
                    <h1 class="tool-heading"><?= htmlspecialchars($tool->name, ENT_QUOTES, 'UTF-8') ?></h1>
                    <?php if ($isAvailable): ?>
                        <span class="status-pill is-ready">
                            Ready · <?= htmlspecialchars((string) $tool->availability->activeDriver, ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php else: ?>
                        <span class="status-pill is-unavailable">
                            Unavailable on this server
                        </span>
                    <?php endif; ?>
                </div>
                <p class="tool-description"><?= htmlspecialchars($tool->description, ENT_QUOTES, 'UTF-8') ?></p>
            </header>

            <?php if ($errorMessage !== null && $errorMessage !== ''): ?>
                <div class="alert-banner is-error" role="alert" id="server-validation-alert">
                    <strong>Unable to process document:</strong>
                    <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <div class="alert-banner is-error" role="alert" id="client-validation-alert" hidden></div>

            <?php if (isset($decision) && !$decision->allowed): ?>
                <div class="alert-banner is-error" role="alert" id="entitlement-blocked-alert">
                    <strong>Plan or Quota Restriction:</strong>
                    <?= htmlspecialchars((string) $decision->message, ENT_QUOTES, 'UTF-8') ?>
                    <div class="blocked-actions" style="margin-top:0.75rem;">
                        <?php if (isset($principal) && $principal->isGuest()): ?>
                            <a href="/login" class="btn-secondary">Sign in</a>
                            <a href="/register" class="btn-primary">Create free account</a>
                        <?php else: ?>
                            <a href="/pricing" class="btn-primary">View plans and quotas</a>
                            <a href="/dashboard" class="btn-secondary">Account dashboard</a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$isAvailable): ?>
                <!-- Graceful Dependency Unavailable Diagnostic State -->
                <section class="capability-blocked-panel" aria-labelledby="unavail-heading">
                    <h2 id="unavail-heading" class="blocked-title">
                        Required server dependency not detected
                    </h2>
                    <p class="blocked-reason">
                        <?= htmlspecialchars((string) $tool->availability->unavailableReason, ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <div class="blocked-details">
                        <p>
                            Docvera inspects server capabilities before accepting uploads so your documents never fail mid-operation with an unexpected server error.
                        </p>
                        <dl class="spec-dl">
                            <div>
                                <dt>Required Driver</dt>
                                <dd><code><?= htmlspecialchars(implode(' / ', $tool->candidateDrivers), ENT_QUOTES, 'UTF-8') ?></code></dd>
                            </div>
                            <div>
                                <dt>Current Status</dt>
                                <dd>Binary not found in system <code>PATH</code> or <code>.env</code> configuration</dd>
                            </div>
                        </dl>
                        <div class="blocked-actions">
                            <a href="/system/capabilities" class="btn-secondary">See installed engines</a>
                            <a href="/?status=available" class="btn-primary">Browse available tools</a>
                        </div>
                    </div>
                </section>
            <?php else: ?>
                <!-- Active Processing Form -->
                <form
                    action="/tools/<?= htmlspecialchars($tool->id, ENT_QUOTES, 'UTF-8') ?>/process"
                    method="post"
                    enctype="multipart/form-data"
                    id="tool-process-form"
                    class="tool-form"
                    data-tool-id="<?= htmlspecialchars($tool->id, ENT_QUOTES, 'UTF-8') ?>"
                    data-min-files="<?= (int) $tool->minFiles ?>"
                    data-max-files="<?= (int) $tool->maxFiles ?>"
                    data-max-mb="<?= (int) $maxFileSizeMb ?>"
                    data-supports-page-preview="<?= $tool->supportsPagePreview ? '1' : '0' ?>"
                >
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <?php if ($stagedManifest !== null): ?>
                        <input
                            type="hidden"
                            name="stage_id"
                            id="staged-file-id-input"
                            value="<?= htmlspecialchars((string) $stagedManifest['stage_id'], ENT_QUOTES, 'UTF-8') ?>"
                            data-page-count="<?= (int) ($stagedInsp['page_count'] ?? 0) ?>"
                        >
                    <?php endif; ?>

                    <!-- Step 1: File Selection / Dropzone -->
                    <fieldset class="workbench-step">
                        <legend class="step-legend">
                            <span class="step-num">1</span>
                            <span>Add your files <span class="step-hint"><?= htmlspecialchars(implode(', ', $tool->supportedExtensions), ENT_QUOTES, 'UTF-8') ?></span></span>
                        </legend>

                        <?php if ($stagedManifest !== null): ?>
                            <div class="staged-banner" id="active-staged-banner">
                                <div class="staged-banner-info">
                                    <span class="staged-badge">Staged from Workbench Router</span>
                                    <strong class="staged-name"><?= htmlspecialchars((string) $stagedManifest['safe_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <span class="staged-meta">
                                        (<?= number_format(((int) $stagedManifest['size']) / 1024, 1) ?> KB
                                        <?php if (!empty($stagedInsp['page_count'])): ?>
                                            · <?= (int) $stagedInsp['page_count'] ?> pages
                                        <?php endif; ?>
                                        <?php if (!empty($stagedInsp['width_pts']) && !empty($stagedInsp['height_pts'])): ?>
                                            · <?= (int) round((float) $stagedInsp['width_pts']) ?>×<?= (int) round((float) $stagedInsp['height_pts']) ?> pt
                                        <?php endif; ?>)
                                    </span>
                                </div>
                                <a href="/tools/<?= htmlspecialchars($tool->id, ENT_QUOTES, 'UTF-8') ?>" class="btn-text">Remove staged file</a>
                            </div>
                        <?php endif; ?>

                        <div class="file-drop-zone" id="tool-dropzone">
                            <label for="tool-file-input" class="file-drop-label">
                                <span class="drop-headline">
                                    <?php if ($tool->maxFiles > 1): ?>
                                        Drop up to <?= (int) $tool->maxFiles ?> <?= htmlspecialchars(implode('/', $tool->supportedExtensions), ENT_QUOTES, 'UTF-8') ?> files here, or click to browse
                                    <?php else: ?>
                                        Drop your <?= htmlspecialchars(implode('/', $tool->supportedExtensions), ENT_QUOTES, 'UTF-8') ?> file here, or click to browse
                                    <?php endif; ?>
                                </span>
                                <span class="drop-subline">
                                    <?php if ($tool->minFiles > 1): ?>
                                        Requires at least <?= (int) $tool->minFiles ?> files · Max <?= (int) $maxFileSizeMb ?> MB per file
                                    <?php else: ?>
                                        Max <?= (int) $maxFileSizeMb ?> MB · Validated by MIME signature
                                    <?php endif; ?>
                                </span>
                                <span class="btn-secondary drop-browse-trigger">Choose <?= $tool->maxFiles > 1 ? 'files' : 'file' ?></span>
                            </label>
                            <input
                                type="file"
                                id="tool-file-input"
                                name="files[]"
                                class="sr-only"
                                accept="<?= htmlspecialchars(implode(',', array_merge($tool->supportedExtensions, $tool->supportedMimes)), ENT_QUOTES, 'UTF-8') ?>"
                                <?= $tool->maxFiles > 1 ? 'multiple' : '' ?>
                            >
                        </div>

                        <!-- Selected Files Queue & Interactive Page Inspector -->
                        <div id="selected-files-container" class="selected-files-list" hidden></div>

                        <?php if ($tool->supportsPagePreview): ?>
                            <div id="interactive-page-inspector" class="page-inspector-panel" hidden>
                                <div class="page-inspector-header">
                                    <span class="page-inspector-title">Interactive Page Selector</span>
                                    <span class="page-inspector-hint" id="page-inspector-hint">
                                        Click pages to toggle selection.
                                    </span>
                                </div>
                                <div id="page-cards-grid" class="page-cards-grid"></div>
                            </div>
                        <?php endif; ?>

                        <?php if ($tool->id === 'text-to-pdf'): ?>
                            <div class="direct-text-box">
                                <label for="direct-text-content" class="field-label">
                                    Or paste / write plain text directly (optional if uploading a .txt file)
                                </label>
                                <textarea
                                    id="direct-text-content"
                                    name="text_content"
                                    rows="6"
                                    class="field-textarea"
                                    placeholder="Type or paste plain text content here to typeset into a paginated PDF…"
                                ></textarea>
                            </div>
                        <?php endif; ?>
                    </fieldset>

                    <!-- Step 2: Tool Options -->
                    <?php if ($tool->options !== []): ?>
                        <fieldset class="workbench-step">
                            <legend class="step-legend">
                                <span class="step-num">2</span>
                                <span>Options</span>
                            </legend>

                            <div class="options-grid">
                                <?php foreach ($tool->options as $field): ?>
                                    <?php
                                    $fieldId = 'opt-' . $field->name;
                                    $defaultVal = $field->default;
                                    // Pre-populate metadata fields if a staged PDF was inspected
                                    if ($tool->id === 'pdf-metadata' && isset($stagedInsp[$field->name]) && $stagedInsp[$field->name] !== '') {
                                        $defaultVal = $stagedInsp[$field->name];
                                    }
                                    ?>
                                    <div class="option-field">
                                        <label for="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>" class="field-label">
                                            <?= htmlspecialchars($field->label, ENT_QUOTES, 'UTF-8') ?>
                                            <?php if ($field->required): ?>
                                                <span class="required-mark" aria-hidden="true">*</span>
                                            <?php endif; ?>
                                        </label>

                                        <?php if ($field->type === 'select'): ?>
                                            <select
                                                id="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>"
                                                name="<?= htmlspecialchars($field->name, ENT_QUOTES, 'UTF-8') ?>"
                                                class="field-select"
                                            >
                                                <?php foreach ($field->choices as $val => $label): ?>
                                                    <option
                                                        value="<?= htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8') ?>"
                                                        <?= (string) $defaultVal === (string) $val ? 'selected' : '' ?>
                                                    >
                                                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php elseif ($field->type === 'number'): ?>
                                            <input
                                                type="number"
                                                id="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>"
                                                name="<?= htmlspecialchars($field->name, ENT_QUOTES, 'UTF-8') ?>"
                                                value="<?= htmlspecialchars((string) $defaultVal, ENT_QUOTES, 'UTF-8') ?>"
                                                <?php if ($field->min !== null): ?>min="<?= (int) $field->min ?>"<?php endif; ?>
                                                <?php if ($field->max !== null): ?>max="<?= (int) $field->max ?>"<?php endif; ?>
                                                class="field-input"
                                                <?= $field->required ? 'required' : '' ?>
                                            >
                                        <?php elseif ($field->type === 'password'): ?>
                                            <input
                                                type="password"
                                                id="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>"
                                                name="<?= htmlspecialchars($field->name, ENT_QUOTES, 'UTF-8') ?>"
                                                value=""
                                                placeholder="<?= htmlspecialchars((string) ($field->placeholder ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                class="field-input"
                                                autocomplete="off"
                                                <?= $field->required ? 'required' : '' ?>
                                            >
                                        <?php else: ?>
                                            <input
                                                type="text"
                                                id="<?= htmlspecialchars($fieldId, ENT_QUOTES, 'UTF-8') ?>"
                                                name="<?= htmlspecialchars($field->name, ENT_QUOTES, 'UTF-8') ?>"
                                                value="<?= htmlspecialchars((string) $defaultVal, ENT_QUOTES, 'UTF-8') ?>"
                                                placeholder="<?= htmlspecialchars((string) ($field->placeholder ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                class="field-input"
                                                <?= $field->required ? 'required' : '' ?>
                                            >
                                        <?php endif; ?>

                                        <?php if ($field->helpText !== null): ?>
                                            <p class="field-help"><?= htmlspecialchars($field->helpText, ENT_QUOTES, 'UTF-8') ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </fieldset>
                    <?php endif; ?>

                    <!-- Step 3: Execute & Privacy Guarantee -->
                    <div class="workbench-submit-bar">
                        <div class="submit-privacy-note">
                            <span>Processed on this server only. Deleted after <?= (int) $retentionMinutes ?> minutes, or right away once you download.</span>
                        </div>
                        <button type="submit" class="btn-primary btn-execute" id="tool-submit-button">
                            <?= htmlspecialchars($tool->ctaLabel, ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </div>

                    <!-- Live Processing Progress State -->
                    <div id="processing-progress-panel" class="progress-panel" hidden aria-live="polite">
                        <div class="progress-bar-track">
                            <div class="progress-bar-fill"></div>
                        </div>
                        <p class="progress-status-text" id="progress-status-text">
                            Validating MIME signatures and executing <?= htmlspecialchars((string) $tool->availability->activeDriver, ENT_QUOTES, 'UTF-8') ?> driver…
                        </p>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <!-- Right Sidebar: Technical Specification & Related Tools -->
        <aside class="tool-sidebar" aria-label="Tool specification and related operations">
            <div class="spec-sheet">
                <h2 class="spec-sheet-title">About this tool</h2>
                <dl class="spec-dl">
                    <div>
                        <dt>ID</dt>
                        <dd><code><?= htmlspecialchars($tool->id, ENT_QUOTES, 'UTF-8') ?></code></dd>
                    </div>
                    <div>
                        <dt>Accepts</dt>
                        <dd><code><?= htmlspecialchars(implode(', ', $tool->supportedMimes), ENT_QUOTES, 'UTF-8') ?></code></dd>
                    </div>
                    <div>
                        <dt>Files</dt>
                        <dd>
                            <?php if ($tool->minFiles === $tool->maxFiles): ?>
                                Exactly <?= (int) $tool->minFiles ?> file
                            <?php else: ?>
                                <?= (int) $tool->minFiles ?> to <?= (int) $tool->maxFiles ?> files
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Returns</dt>
                        <dd><?= htmlspecialchars($tool->outputExtensionLabel, ENT_QUOTES, 'UTF-8') ?> (<code><?= htmlspecialchars($tool->outputMime, ENT_QUOTES, 'UTF-8') ?></code>)</dd>
                    </div>
                    <div>
                        <dt>Engine</dt>
                        <dd>
                            <?php if ($tool->availability->activeDriver !== null): ?>
                                <code><?= htmlspecialchars($tool->availability->activeDriver, ENT_QUOTES, 'UTF-8') ?></code>
                            <?php else: ?>
                                <span class="text-warn">None (Missing binary)</span>
                            <?php endif; ?>
                        </dd>
                    </div>
                    <div>
                        <dt>Time limit</dt>
                        <dd><?= (int) $tool->timeoutSeconds ?> seconds</dd>
                    </div>
                    <div>
                        <dt>Runs on</dt>
                        <dd><?= htmlspecialchars((string) ($processingLocalityNotice ?? 'This server, in a private folder per job'), ENT_QUOTES, 'UTF-8') ?></dd>
                    </div>
                    <div>
                        <dt>Kept for</dt>
                        <dd><?= (int) $retentionMinutes ?> minutes, or until you delete it</dd>
                    </div>
                </dl>
            </div>

            <?php if (!empty($adsVisible) && !empty($adSlotSidebar)): ?>
                <div class="spec-sheet ad-slot-box" aria-label="Sponsored notice">
                    <?= $adSlotSidebar ?>
                </div>
            <?php endif; ?>

            <div class="related-tools-box">
                <h2 class="spec-sheet-title">More in <?= htmlspecialchars($categoryLabel, ENT_QUOTES, 'UTF-8') ?></h2>
                <ul class="related-tools-list">
                    <?php foreach ($allTools as $rel): ?>
                        <?php if ($rel->category === $tool->category && $rel->id !== $tool->id): ?>
                            <li>
                                <a href="/tools/<?= htmlspecialchars($rel->id, ENT_QUOTES, 'UTF-8') ?>" class="related-tool-link">
                                    <span><?= htmlspecialchars($rel->name, ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if (!$rel->availability->available): ?>
                                        <span class="related-unavail-tag">Unavailable</span>
                                    <?php else: ?>
                                        <span aria-hidden="true">→</span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </ul>
            </div>
        </aside>
    </div>
</div>

