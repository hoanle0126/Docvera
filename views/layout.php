<?php
/**
 * @var string $pageTitle
 * @var string $content
 * @var string $csrfToken
 * @var string $appName
 * @var string $siteTagline
 * @var string $accentColor
 * @var string $appVersion
 * @var string $deploymentMode
 * @var bool $billingEnabled
 * @var bool $adsVisible
 * @var string $adSlotHeader
 * @var string $footerNotice
 * @var int $retentionMinutes
 * @var \Docvera\Auth\Principal|null $principal
 * @var string|null $metaDescription
 * @var string|null $canonicalUrl
 * @var bool|null $seoIndexable
 * @var array<string, mixed>|null $jsonLd
 */
declare(strict_types=1);

$currentPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$metaDesc = trim((string) ($metaDescription ?? ''));
$canonical = trim((string) ($canonicalUrl ?? ''));
$isIndexable = ($seoIndexable ?? true) && ($deploymentMode ?? 'saas') !== 'private';
$customAccent = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($accentColor ?? '')) ? (string) $accentColor : '#c23b22';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title><?= htmlspecialchars($pageTitle ?? 'Docvera', ENT_QUOTES, 'UTF-8') ?></title>
    <?php if ($metaDesc !== ''): ?>
        <meta name="description" content="<?= htmlspecialchars($metaDesc, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <meta name="robots" content="<?= $isIndexable ? 'index, follow' : 'noindex, nofollow' ?>">
    <?php if ($canonical !== ''): ?>
        <link rel="canonical" href="<?= htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') ?>">
        <meta property="og:url" content="<?= htmlspecialchars($canonical, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle ?? 'Docvera', ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($metaDesc !== ''): ?>
        <meta property="og:description" content="<?= htmlspecialchars($metaDesc, ENT_QUOTES, 'UTF-8') ?>">
    <?php endif; ?>
    <meta property="og:type" content="website">
    <?php if (isset($jsonLd) && is_array($jsonLd)): ?>
        <script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
    <?php endif; ?>
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="preload" href="/assets/fonts/archivo-latin-var.woff2" as="font" type="font/woff2" crossorigin>
    <link rel="stylesheet" href="/assets/css/docvera.css">
    <?php if ($customAccent !== '#c23b22'): ?>
        <style>:root { --accent-primary: <?= htmlspecialchars($customAccent, ENT_QUOTES, 'UTF-8') ?>; }</style>
    <?php endif; ?>
</head>
<body>
    <a href="#main-workbench" class="skip-link">Skip to content</a>

    <?php
    $isGuest = !isset($principal) || $principal->isGuest();
    $showPlans = ($billingEnabled ?? false) || ($deploymentMode ?? 'saas') === 'saas';
    $navClass = static fn (bool $active): string => 'nav-link' . ($active ? ' is-active' : '');
    $ariaCurrent = static fn (bool $active): string => $active ? ' aria-current="page"' : '';
    $isTools = $currentPath === '/' || str_starts_with($currentPath, '/tools/');
    $isApi = str_starts_with($currentPath, '/developers/api');
    $isPlans = $currentPath === '/pricing';
    $isCaps = $currentPath === '/system/capabilities';
    ?>
    <header class="site-header" role="banner">
        <div class="shell header-inner">
            <a href="/" class="brand-mark" aria-label="<?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?> home">
                <span class="brand-sheet" aria-hidden="true"></span>
                <span class="brand-wordmark"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></span>
            </a>

            <button type="button" class="nav-toggle" aria-expanded="false" aria-controls="site-nav-panel">
                <span class="nav-toggle-label">Menu</span>
                <span class="nav-toggle-glyph" aria-hidden="true"></span>
            </button>

            <div class="nav-panel" id="site-nav-panel">
                <nav class="site-nav" aria-label="Primary">
                    <a href="/" class="<?= $navClass($isTools) ?>"<?= $ariaCurrent($isTools) ?>>Tools</a>
                    <a href="/developers/api" class="<?= $navClass($isApi) ?>"<?= $ariaCurrent($isApi) ?>>API</a>
                    <?php if ($showPlans): ?>
                        <a href="/pricing" class="<?= $navClass($isPlans) ?>"<?= $ariaCurrent($isPlans) ?>>Plans</a>
                    <?php endif; ?>
                    <a href="/system/capabilities" class="<?= $navClass($isCaps) ?>"<?= $ariaCurrent($isCaps) ?>>Capabilities</a>
                </nav>

                <div class="header-utility">
                    <span class="retention-stamp" title="Files are processed on this server in isolated directories and purged automatically after <?= (int) $retentionMinutes ?> minutes." aria-label="Local processing, files purged after <?= (int) $retentionMinutes ?> minutes">
                        <span class="retention-stamp-key" aria-hidden="true">Purge</span>
                        <span class="retention-stamp-val" aria-hidden="true"><?= (int) $retentionMinutes ?>m</span>
                    </span>

                    <?php if (!$isGuest): ?>
                        <a href="/dashboard" class="<?= $navClass(str_starts_with($currentPath, '/dashboard')) ?>">Dashboard</a>
                        <?php if ($principal->isAdmin()): ?>
                            <a href="/admin" class="<?= $navClass(str_starts_with($currentPath, '/admin')) ?>">Admin</a>
                        <?php endif; ?>
                        <a href="/logout" class="nav-link">Sign out</a>
                    <?php else: ?>
                        <a href="/login" class="<?= $navClass($currentPath === '/login') ?>">Sign in</a>
                        <?php if (($deploymentMode ?? 'saas') !== 'private'): ?>
                            <a href="/register" class="header-cta">Create account</a>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <?php if (!empty($adsVisible) && !empty($adSlotHeader)): ?>
        <div class="shell ad-slot-banner" aria-label="Sponsored notice">
            <?= $adSlotHeader ?>
        </div>
    <?php endif; ?>

    <main id="main-workbench" class="site-main" role="main">
        <?= $content ?>
    </main>

    <footer class="site-footer" role="contentinfo">
        <div class="shell footer-inner">
            <div class="footer-col">
                <p class="footer-title"><?= htmlspecialchars($appName, ENT_QUOTES, 'UTF-8') ?></p>
                <p class="footer-note">
                    <?php if (!empty($footerNotice)): ?>
                        <?= htmlspecialchars($footerNotice, ENT_QUOTES, 'UTF-8') ?>
                    <?php else: ?>
                        Every document is processed on this server, never sent to a third-party cloud, and deleted <?= (int) $retentionMinutes ?> minutes after upload.
                    <?php endif; ?>
                </p>
            </div>
            <nav aria-labelledby="footer-tools-heading">
                <p class="footer-heading" id="footer-tools-heading">Product</p>
                <ul class="footer-links">
                    <li><a href="/">All tools</a></li>
                    <li><a href="/pricing">Plans</a></li>
                    <li><a href="/developers/api">API reference</a></li>
                </ul>
            </nav>
            <nav aria-labelledby="footer-server-heading">
                <p class="footer-heading" id="footer-server-heading">This server</p>
                <ul class="footer-links">
                    <li><a href="/system/capabilities">Installed engines</a></li>
                    <li><a href="/api/v1/capabilities">Health check (JSON)</a></li>
                </ul>
            </nav>
            <nav aria-labelledby="footer-legal-heading">
                <p class="footer-heading" id="footer-legal-heading">Policies</p>
                <ul class="footer-links">
                    <li><a href="/p/privacy">Privacy</a></li>
                    <li><a href="/p/terms">Terms</a></li>
                    <li><a href="/p/security">Security</a></li>
                </ul>
            </nav>
            <p class="footer-colophon">
                <span>Version <?= htmlspecialchars($appVersion, ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string) ($deploymentMode ?? 'saas'))), ENT_QUOTES, 'UTF-8') ?> mode</span>
                <span>Files kept <?= (int) $retentionMinutes ?> min</span>
            </p>
        </div>
    </footer>

    <button type="button" id="scroll-top-btn" class="scroll-top-btn" aria-label="Scroll back to top" title="Scroll back to top">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="square" stroke-linejoin="miter" aria-hidden="true">
            <polyline points="18 15 12 9 6 15"></polyline>
        </svg>
    </button>

    <script src="/assets/js/docvera.js" defer></script>
</body>
</html>
