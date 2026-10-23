<?php
/**
 * @var array<int, array<string, mixed>> $plans
 * @var \Docvera\Auth\Principal $principal
 * @var bool $billingEnabled
 * @var array<string, array<string, mixed>> $providers
 * @var bool $anyProviderConfigured
 * @var string|null $errorMessage
 * @var array<string, mixed>|null $checkoutSession
 * @var string $csrfToken
 */
declare(strict_types=1);
?>
<div class="shell">
    <nav class="breadcrumb-nav" aria-label="Breadcrumb">
        <a href="/">Tools</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Plans</span>
    </nav>

    <header class="capabilities-header">
        <div>
            <h1 class="workbench-title">Plans and limits</h1>
            <p class="workbench-lead">
                Each plan sets how many files you can process, how large they can be, and how long results are kept. The same limits apply in the browser and over the API.
            </p>
        </div>
        <div>
            <?php if (!$principal->isGuest()): ?>
                <span class="status-pill is-ready">Your plan: <?= htmlspecialchars(ucfirst($principal->planId), ENT_QUOTES, 'UTF-8') ?></span>
            <?php else: ?>
                <span class="status-pill is-purged">Not signed in</span>
            <?php endif; ?>
        </div>
    </header>

    <?php if (!empty($errorMessage)): ?>
        <div class="alert-banner is-error" role="alert">
            <strong>Billing / Checkout Notice:</strong>
            <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if (is_array($checkoutSession) && isset($checkoutSession['checkout'])): ?>
        <?php $co = $checkoutSession['checkout']; ?>
        <div class="staged-banner" role="status" style="margin-bottom:1.5rem;">
            <div class="staged-banner-info">
                <span class="staged-badge">Checkout Session Prepared (<?= htmlspecialchars(strtoupper((string) ($co['provider'] ?? 'stripe')), ENT_QUOTES, 'UTF-8') ?> · <?= htmlspecialchars(strtoupper((string) ($co['mode'] ?? 'test')), ENT_QUOTES, 'UTF-8') ?>)</span>
                <strong>Session ID: <code><?= htmlspecialchars((string) ($co['checkout_id'] ?? $co['checkout_session_id'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></strong>
                <span class="staged-meta">
                    Interval: <?= htmlspecialchars((string) ($co['interval'] ?? 'monthly'), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <a href="<?= htmlspecialchars((string) ($co['checkout_url'] ?? '/dashboard'), ENT_QUOTES, 'UTF-8') ?>" class="btn-primary">
                Proceed to Provider Checkout →
            </a>
        </div>
    <?php endif; ?>

    <?php if (!$billingEnabled): ?>
        <div class="capability-blocked-panel" style="margin-bottom:2rem;">
            <h2 class="blocked-title">Paid plans are turned off on this server</h2>
            <p class="blocked-reason">
                This Docvera host is operating with commercial subscription billing disabled. Quota tiers are managed directly by the system administrator.
            </p>
        </div>
    <?php elseif (!$anyProviderConfigured): ?>
        <div class="capability-blocked-panel" style="margin-bottom:2rem;">
            <h2 class="blocked-title">Checkout isn’t set up yet</h2>
            <p class="blocked-reason">
                Neither Stripe nor PayPal credentials have been configured in <code>.env</code> or Admin Settings. Paid checkouts are disabled until a payment provider is configured.
            </p>
            <p style="margin:0;font-size:0.85rem;color:var(--ink-secondary);">
                Administrators can configure <code>STRIPE_SECRET_KEY</code> / <code>STRIPE_WEBHOOK_SECRET</code> or <code>PAYPAL_CLIENT_ID</code> / <code>PAYPAL_SECRET</code> in <a href="/admin/security">Admin → Security &amp; Billing Keys</a>.
            </p>
        </div>
    <?php endif; ?>

    <!-- Comparative Compare plans -->
    <section class="cap-section" aria-labelledby="matrix-heading">
        <h2 id="matrix-heading" class="section-heading">Compare plans</h2>
        <div class="cap-table-wrap">
            <table class="cap-table pricing-table">
                <thead>
                    <tr>
                        <th>Plan</th>
                        <th>Price</th>
                        <th>Jobs</th>
                        <th>File limits</th>
                        <th>Parallel jobs</th>
                        <th>API</th>
                        <th>Files kept</th>
                        <th><span class="sr-only">Action</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($plans as $plan): ?>
                        <?php
                        $pId = (string) $plan['id'];
                        $ent = is_array($plan['entitlements'] ?? null) ? $plan['entitlements'] : [];
                        $monthlyUsd = ((int) ($plan['price_monthly_cents'] ?? 0)) / 100;
                        $yearlyUsd = ((int) ($plan['price_yearly_cents'] ?? 0)) / 100;
                        $isCurrent = (!$principal->isGuest() && $principal->planId === $pId) || ($principal->isGuest() && $pId === 'guest');
                        ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars((string) $plan['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                            </td>
                            <td>
                                <?php if ($monthlyUsd <= 0): ?>
                                    Free
                                <?php else: ?>
                                    <div>$<?= number_format($monthlyUsd, 2) ?> a month</div>
                                    <div class="cap-key-sub">$<?= number_format($yearlyUsd, 2) ?> a year</div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?= (int) ($ent['ops_per_day'] ?? 0) ?> a day</div>
                                <div class="cap-key-sub"><?= (int) ($ent['ops_per_month'] ?? 0) ?> a month</div>
                            </td>
                            <td>
                                <div><?= (int) ($ent['max_file_size_mb'] ?? 0) ?> MB per file</div>
                                <div class="cap-key-sub">
                                    Up to <?= (int) ($ent['max_files_per_op'] ?? 1) ?> files · <?= (int) ($ent['max_pages_per_op'] ?? 0) ?> pages
                                </div>
                            </td>
                            <td>
                                <div><?= (int) ($ent['concurrent_jobs'] ?? 1) ?> at once</div>
                                <div class="cap-key-sub">
                                    <?= !empty($ent['ocr_allowed']) ? 'OCR included' : 'No OCR' ?>
                                    · <?= !empty($ent['batch_allowed']) ? 'batch jobs' : 'one file at a time' ?>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($ent['api_access'])): ?>
                                    <div><?= (int) ($ent['api_requests_per_day'] ?? 0) ?> requests a day</div>
                                    <div class="cap-key-sub">Up to <?= (int) ($ent['api_rate_limit_per_min'] ?? 30) ?>/min</div>
                                <?php else: ?>
                                    <span class="related-unavail-tag">Not included</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?= (int) ($ent['retention_minutes'] ?? 60) ?> minutes</div>
                                <div class="cap-key-sub"><?= !empty($ent['ads_visible']) ? 'With ads' : 'No ads' ?></div>
                            </td>
                            <td>
                                <?php if ($isCurrent): ?>
                                    <span class="status-pill is-ready">Active Tier</span>
                                <?php elseif ($pId === 'guest'): ?>
                                    <span class="related-unavail-tag">Default Anonymous</span>
                                <?php elseif ($monthlyUsd <= 0): ?>
                                    <?php if ($principal->isGuest()): ?>
                                        <a href="/register" class="btn-secondary">Start free</a>
                                    <?php else: ?>
                                        <span class="related-unavail-tag">Included</span>
                                    <?php endif; ?>
                                <?php elseif ($billingEnabled && $anyProviderConfigured): ?>
                                    <form action="/billing/checkout" method="post" class="checkout-inline-form">
                                        <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="plan_id" value="<?= htmlspecialchars($pId, ENT_QUOTES, 'UTF-8') ?>">
                                        <div class="checkout-controls">
                                            <select name="interval" class="field-select field-select-sm" aria-label="Billing interval">
                                                <option value="monthly">Monthly ($<?= number_format($monthlyUsd, 0) ?>)</option>
                                                <option value="yearly">Yearly ($<?= number_format($yearlyUsd, 0) ?>)</option>
                                            </select>
                                            <select name="provider" class="field-select field-select-sm" aria-label="Payment provider">
                                                <?php foreach ($providers as $provKey => $provInfo): ?>
                                                    <?php if (!empty($provInfo['configured'])): ?>
                                                        <option value="<?= htmlspecialchars($provKey, ENT_QUOTES, 'UTF-8') ?>">
                                                            <?= htmlspecialchars((string) ($provInfo['label'] ?? $provInfo['display_name'] ?? $provKey), ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars((string) ($provInfo['mode'] ?? 'test'), ENT_QUOTES, 'UTF-8') ?>)
                                                        </option>
                                                    <?php endif; ?>
                                                <?php endforeach; ?>
                                            </select>
                                            <button type="submit" class="btn-primary btn-sm">Subscribe</button>
                                        </div>
                                    </form>
                                <?php else: ?>
                                    <span class="status-pill is-unavailable">Coming soon</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
