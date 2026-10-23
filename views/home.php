<?php
/**
 * @var array<string, \Docvera\Tools\ToolDefinition> $tools
 * @var array<string, \Docvera\Tools\ToolDefinition> $filteredTools
 * @var array<string, string> $categories
 * @var string $searchQuery
 * @var string $selectedCategory
 * @var string $statusFilter
 * @var array<string, mixed>|null $stagedManifest
 * @var list<\Docvera\Tools\ToolDefinition> $compatibleTools
 * @var int $availableCount
 * @var int $totalToolsCount
 * @var int $retentionMinutes
 * @var int $maxFileSizeMb
 * @var string $csrfToken
 */
declare(strict_types=1);

$routerError = isset($_GET['router_error']) ? trim((string) $_GET['router_error']) : '';
?>
<div class="shell workbench-home">
    <!-- Top Bar: Workbench Header + Smart File Inspector & Router -->
    <section class="workbench-top-grid" aria-labelledby="workbench-heading">
        <div class="workbench-intro">
            <p class="workbench-kicker"><?= (int) $availableCount ?> of <?= (int) $totalToolsCount ?> tools ready on this server</p>
            <h1 id="workbench-heading" class="workbench-title">
                PDF and document tools that never leave this server.
            </h1>
            <p class="workbench-lead">
                Every file is processed here, kept in its own folder and deleted after <?= (int) $retentionMinutes ?> minutes, or sooner if you ask.
            </p>
        </div>

        <!-- Smart Document Inspector & Router Box (Never a dead-end upload box) -->
        <div class="smart-router-panel" id="smart-router-panel">
            <div class="router-header">
                <h2 class="router-title">Not sure which tool?</h2>
                <span class="router-meta">Up to <?= (int) $maxFileSizeMb ?> MB</span>
            </div>

            <?php if ($routerError !== ''): ?>
                <div class="alert-banner is-error" role="alert">
                    <?= htmlspecialchars($routerError, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php if ($stagedManifest !== null): ?>
                <?php
                $insp = is_array($stagedManifest['inspection'] ?? null) ? $stagedManifest['inspection'] : [];
                $sizeKb = round(((int) ($stagedManifest['size'] ?? 0)) / 1024, 1);
                ?>
                <div class="staged-file-panel" id="staged-router-result">
                    <div class="staged-file-summary">
                        <div>
                            <p class="staged-filename"><?= htmlspecialchars((string) $stagedManifest['safe_name'], ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="staged-specs">
                                <span><?= htmlspecialchars((string) ($insp['extension'] ?? 'FILE'), ENT_QUOTES, 'UTF-8') ?></span>
                                <span>· <?= number_format($sizeKb, 1) ?> KB</span>
                                <?php if (!empty($insp['page_count'])): ?>
                                    <span>· <?= (int) $insp['page_count'] ?> <?= (int) $insp['page_count'] === 1 ? 'page' : 'pages' ?></span>
                                <?php endif; ?>
                                <?php if (!empty($insp['width_pts']) && !empty($insp['height_pts'])): ?>
                                    <span>· <?= (int) round((float) $insp['width_pts']) ?>×<?= (int) round((float) $insp['height_pts']) ?> pt</span>
                                <?php endif; ?>
                                <?php if (!empty($insp['encrypted'])): ?>
                                    <span class="spec-flag is-warn">· Password protected</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <a href="/" class="btn-text">Clear file</a>
                    </div>

                    <p class="router-prompt">What do you want to do with it?</p>
                    <div class="compatible-actions-list">
                        <?php foreach ($compatibleTools as $cTool): ?>
                            <?php if ($cTool->availability->available): ?>
                                <a
                                    href="/tools/<?= htmlspecialchars($cTool->id, ENT_QUOTES, 'UTF-8') ?>?stage_id=<?= urlencode((string) $stagedManifest['stage_id']) ?>"
                                    class="compatible-action-item"
                                >
                                    <span class="compatible-action-name"><?= htmlspecialchars($cTool->name, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="compatible-action-go" aria-hidden="true">→</span>
                                </a>
                            <?php else: ?>
                                <div class="compatible-action-item is-disabled" title="<?= htmlspecialchars((string) $cTool->availability->unavailableReason, ENT_QUOTES, 'UTF-8') ?>">
                                    <span class="compatible-action-name"><?= htmlspecialchars($cTool->name, ENT_QUOTES, 'UTF-8') ?></span>
                                    <span class="compatible-action-unavail">Not installed</span>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php else: ?>
                <form
                    action="/router/inspect"
                    method="post"
                    enctype="multipart/form-data"
                    id="smart-router-form"
                    class="router-dropzone"
                >
                    <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <label for="router-file-input" class="router-drop-label">
                        <span class="router-drop-primary">Drop a file here</span>
                        <span class="router-drop-secondary">
                            We read its type, page count and whether it’s locked, then list only the tools that can open it.
                        </span>
                        <span class="btn-secondary router-browse-btn">Choose a file</span>
                    </label>
                    <input
                        type="file"
                        id="router-file-input"
                        name="file"
                        class="sr-only"
                        accept=".pdf,.jpg,.jpeg,.png,.txt,.docx,.doc,.xlsx,.xls,.pptx,.ppt"
                    >
                </form>
                <div id="router-live-result" class="staged-file-panel" hidden></div>
            <?php endif; ?>
        </div>

        <form action="/" method="get" class="tool-filter-form" role="search" aria-label="Filter document tools">
            <div class="search-field-wrap">
                <label for="tool-search-input" class="sr-only">Search tools by name, action, or file extension</label>
                <input
                    type="search"
                    id="tool-search-input"
                    name="q"
                    value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="Search tools: merge, rotate, .docx…"
                    class="search-input"
                    autocomplete="off"
                >
                <kbd class="search-shortcut" aria-hidden="true">/</kbd>
            </div>

            <div class="filter-bar">
                <div class="category-tabs" role="tablist" aria-label="Tool categories">
                    <button
                        type="submit"
                        name="category"
                        value="all"
                        data-category-filter="all"
                        class="category-tab <?= ($selectedCategory === 'all' || $selectedCategory === '') ? 'is-selected' : '' ?>"
                    >
                        All <span class="category-tab-count"><?= count($tools) ?></span>
                    </button>
                    <?php foreach ($categories as $catKey => $catLabel): ?>
                        <?php
                        $catCount = count(array_filter($tools, static fn($t): bool => $t->category === $catKey));
                        ?>
                        <button
                            type="submit"
                            name="category"
                            value="<?= htmlspecialchars($catKey, ENT_QUOTES, 'UTF-8') ?>"
                            data-category-filter="<?= htmlspecialchars($catKey, ENT_QUOTES, 'UTF-8') ?>"
                            class="category-tab <?= $selectedCategory === $catKey ? 'is-selected' : '' ?>"
                        >
                            <?= htmlspecialchars($catLabel, ENT_QUOTES, 'UTF-8') ?> <span class="category-tab-count"><?= $catCount ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <div class="availability-toggle">
                    <label class="checkbox-inline">
                        <input
                            type="checkbox"
                            id="filter-available-only"
                            name="status"
                            value="available"
                            <?= $statusFilter === 'available' ? 'checked' : '' ?>
                        >
                        <span>Hide tools this server can’t run</span>
                    </label>
                </div>
            </div>
        </form>
    </section>

    <!-- Main Tool Index Table / Architectural Grid -->
    <section class="tools-directory-section" aria-label="Document processing tools">
        <?php foreach ($categories as $catKey => $catLabel): ?>
            <?php
            $catTools = array_filter(
                $filteredTools,
                static fn(\Docvera\Tools\ToolDefinition $t): bool => $t->category === $catKey
            );
            if ($catTools === []) {
                continue;
            }
            ?>
            <div class="category-group" data-category-section="<?= htmlspecialchars($catKey, ENT_QUOTES, 'UTF-8') ?>">
                <div class="category-group-header">
                    <h2 class="category-group-title"><?= htmlspecialchars($catLabel, ENT_QUOTES, 'UTF-8') ?></h2>
                    <span class="category-group-count"><?= count($catTools) ?> <?= count($catTools) === 1 ? 'tool' : 'tools' ?></span>
                </div>

                <ol class="tool-ledger" role="list">
                    <?php foreach ($catTools as $tool): ?>
                        <?php
                        $isAvail = $tool->availability->available;
                        $searchKeywords = strtolower($tool->name . ' ' . $tool->description . ' ' . implode(' ', $tool->supportedExtensions));
                        ?>
                        <li
                            class="tool-row <?= $isAvail ? 'is-available' : 'is-unavailable' ?>"
                            data-tool-item
                            data-tool-category="<?= htmlspecialchars($tool->category, ENT_QUOTES, 'UTF-8') ?>"
                            data-tool-available="<?= $isAvail ? '1' : '0' ?>"
                            data-tool-search="<?= htmlspecialchars($searchKeywords, ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <h3 class="tool-row-title">
                                <a href="/tools/<?= htmlspecialchars($tool->id, ENT_QUOTES, 'UTF-8') ?>" class="tool-row-link">
                                    <?= htmlspecialchars($tool->name, ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            </h3>
                            <p class="tool-row-desc">
                                <?= htmlspecialchars($tool->description, ENT_QUOTES, 'UTF-8') ?>
                                <?php if (!$isAvail && $tool->availability->unavailableReason !== null): ?>
                                    <span class="tool-unavail-reason"><?= htmlspecialchars($tool->availability->unavailableReason, ENT_QUOTES, 'UTF-8') ?></span>
                                <?php endif; ?>
                            </p>
                            <span class="tool-io-spec">
                                <?= htmlspecialchars(implode(' ', $tool->supportedExtensions), ENT_QUOTES, 'UTF-8') ?>
                                <span aria-hidden="true">→</span><span class="sr-only">to</span>
                                <?= htmlspecialchars($tool->outputExtensionLabel, ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <span class="tool-row-state">
                                <?php if ($isAvail): ?>
                                    <span class="tool-row-go" aria-hidden="true">→</span>
                                <?php else: ?>
                                    <span class="tool-status-unavail">Not installed</span>
                                <?php endif; ?>
                            </span>
                        </li>
                    <?php endforeach; ?>
                </ol>
            </div>
        <?php endforeach; ?>

        <div id="empty-filter-state" class="empty-filter-box" <?= $filteredTools !== [] ? 'hidden' : '' ?>>
            <p class="empty-filter-title">Nothing matches that search.</p>
            <p class="empty-filter-sub">Try a file extension like <code>.docx</code>, or a verb like “merge”.</p>
            <a href="/" class="btn-secondary" id="reset-tool-filters">Show all tools</a>
        </div>
    </section>
</div>

