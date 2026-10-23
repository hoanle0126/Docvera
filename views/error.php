<?php
/**
 * @var int $statusCode
 * @var string $heading
 * @var string $message
 */
declare(strict_types=1);
?>
<div class="shell error-workbench">
    <section class="error-sheet" aria-labelledby="error-heading">
        <p class="error-code"><?= (int) $statusCode ?></p>
        <h1 id="error-heading" class="error-title"><?= htmlspecialchars($heading, ENT_QUOTES, 'UTF-8') ?></h1>
        <p class="error-message"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <div class="error-actions">
            <a href="/" class="btn-primary">See all tools</a>
            <a href="/system/capabilities" class="btn-text">What this server can do</a>
        </div>
    </section>
</div>

