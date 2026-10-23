<?php
/**
 * @var array<string, mixed> $page
 * @var int $retentionMinutes
 * @var string $localityNotice
 */
declare(strict_types=1);
?>
<div class="shell result-workbench">
    <nav class="breadcrumb-nav" aria-label="Breadcrumb">
        <a href="/">Tools</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page"><?= htmlspecialchars((string) $page['title'], ENT_QUOTES, 'UTF-8') ?></span>
    </nav>

    <article class="result-sheet prose-sheet" aria-labelledby="cms-page-title">
        <header class="tool-pane-header">
            <h1 id="cms-page-title" class="tool-heading"><?= htmlspecialchars((string) $page['title'], ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="tool-description">
                Files on this server are kept for <?= (int) $retentionMinutes ?> minutes, then deleted.
            </p>
        </header>

        <div class="prose-body">
            <?php
            $paragraphs = preg_split('/\R{2,}/', trim((string) ($page['body'] ?? ''))) ?: [];
            foreach ($paragraphs as $para):
                $trimmed = trim($para);
                if ($trimmed === '') {
                    continue;
                }
            ?>
                <p><?= nl2br(htmlspecialchars($trimmed, ENT_QUOTES, 'UTF-8')) ?></p>
            <?php endforeach; ?>
        </div>

        <footer class="result-footer-links" style="margin-top:2rem;padding-top:1rem;border-top:1px solid var(--border-hairline);">
            <a href="/" class="btn-secondary">See all tools</a>
            <a href="/system/capabilities" class="btn-text">What this server can do</a>
        </footer>
    </article>
</div>

