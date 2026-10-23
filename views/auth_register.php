<?php
/**
 * @var string|null $errorMessage
 * @var bool $registrationEnabled
 * @var string $csrfToken
 */
declare(strict_types=1);
?>
<div class="shell auth-workbench">
    <nav class="breadcrumb-nav" aria-label="Breadcrumb">
        <a href="/">Tools</a>
        <span aria-hidden="true">/</span>
        <span aria-current="page">Register Account</span>
    </nav>

    <section class="auth-sheet" aria-labelledby="register-heading">
        <header class="tool-pane-header">
            <h1 id="register-heading" class="tool-heading">Create an account</h1>
            <p class="tool-description">
                Keep a history of your jobs, get higher limits and use the API.
            </p>
        </header>

        <?php if (!empty($errorMessage)): ?>
            <div class="alert-banner is-error" role="alert">
                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if (!$registrationEnabled): ?>
            <div class="capability-blocked-panel">
                <h2 class="blocked-title">Sign-ups are closed on this server</h2>
                <p class="blocked-reason">
                    Self-service registration is currently disabled by the system administrator.
                </p>
                <div class="blocked-actions">
                    <a href="/login" class="btn-primary">Sign in with existing account</a>
                    <a href="/" class="btn-secondary">Return to workbench</a>
                </div>
            </div>
        <?php else: ?>
            <form action="/register" method="post" class="auth-form">
                <input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                <div class="option-field">
                    <label for="reg-name" class="field-label">Full name or team label <span class="required-mark">*</span></label>
                    <input
                        type="text"
                        id="reg-name"
                        name="name"
                        class="field-input"
                        required
                        autocomplete="name"
                        placeholder="Ada Lovelace"
                    >
                </div>

                <div class="option-field">
                    <label for="reg-email" class="field-label">Email address <span class="required-mark">*</span></label>
                    <input
                        type="email"
                        id="reg-email"
                        name="email"
                        class="field-input"
                        required
                        autocomplete="email"
                        placeholder="you@company.com"
                    >
                </div>

                <div class="option-field">
                    <label for="reg-password" class="field-label">Password (minimum 8 characters) <span class="required-mark">*</span></label>
                    <input
                        type="password"
                        id="reg-password"
                        name="password"
                        class="field-input"
                        minlength="8"
                        required
                        autocomplete="new-password"
                    >
                </div>

                <div class="workbench-submit-bar">
                    <span class="submit-privacy-note">
                        Already registered? <a href="/login">Sign in here</a>
                    </span>
                    <button type="submit" class="btn-primary">Create account</button>
                </div>
            </form>
        <?php endif; ?>
    </section>
</div>

