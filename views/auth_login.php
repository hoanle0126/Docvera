<?php
/**
 * @var string|null $errorMessage
 * @var string $notice
 * @var string $deploymentMode
 * @var string $csrfToken
 */
declare(strict_types=1);
?>
<div class="shell auth-workbench">
    <nav class="breadcrumb-nav" aria-label="Breadcrumb">
        <a href="/">Tools</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Sign in</span>
    </nav>

    <section class="auth-sheet" aria-labelledby="login-heading">
        <header class="tool-pane-header">
            <h1 id="login-heading" class="tool-heading">Sign in to Docvera</h1>
            <p class="tool-description">
                See your recent jobs, usage and API keys.
            </p>
        </header>

        <?php if (!empty($notice)): ?>
            <div class="alert-banner is-info" role="status">
                <?php if ($notice === 'private_workspace'): ?>
                    This Docvera deployment is configured as a private internal workspace. Please sign in to access document tools.
                <?php elseif ($notice === 'checkout_requires_account'): ?>
                    Please sign in or register an account before starting a plan subscription checkout.
                <?php else: ?>
                    <?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($errorMessage)): ?>
            <div class="alert-banner is-error" role="alert">
                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form action="/login" method="post" class="auth-form">
            <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

            <div class="option-field">
                <label for="login-email" class="field-label">Email address <span class="required-mark">*</span></label>
                <input
                    type="email"
                    id="login-email"
                    name="email"
                    class="field-input"
                    required
                    autocomplete="email"
                    placeholder="you@company.com"
                >
            </div>

            <div class="option-field">
                <label for="login-password" class="field-label">Password <span class="required-mark">*</span></label>
                <input
                    type="password"
                    id="login-password"
                    name="password"
                    class="field-input"
                    required
                    autocomplete="current-password"
                >
            </div>

            <div class="workbench-submit-bar">
                <?php if ($deploymentMode !== 'private'): ?>
                    <span class="submit-privacy-note">
                        Need an account? <a href="/register">Create a free account</a>
                    </span>
                <?php else: ?>
                    <span class="submit-privacy-note">Private deployment · Contact administrator for access</span>
                <?php endif; ?>
                <button type="submit" class="btn-primary">Sign in</button>
            </div>
        </form>
    </section>
</div>

