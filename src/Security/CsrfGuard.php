<?php

declare(strict_types=1);

namespace Docvera\Security;

final class CsrfGuard
{
    public static function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            $isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
                || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
            session_name('docvera_sid');
            session_set_cookie_params([
                'httponly' => true,
                'secure' => $isHttps,
                'samesite' => 'Lax',
                'path' => '/',
            ]);
            @session_start();
        }
    }

    public static function getToken(): string
    {
        self::ensureSession();
        if (empty($_SESSION['_csrf_token']) || !is_string($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf_token'];
    }

    public static function rotateToken(): string
    {
        self::ensureSession();
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf_token'];
    }

    public static function getSessionIdentity(): string
    {
        self::ensureSession();
        if (empty($_SESSION['_docvera_identity']) || !is_string($_SESSION['_docvera_identity'])) {
            $_SESSION['_docvera_identity'] = bin2hex(random_bytes(24));
        }
        return $_SESSION['_docvera_identity'];
    }

    public static function verify(?string $submittedToken): bool
    {
        if ($submittedToken === null || $submittedToken === '') {
            return false;
        }
        $expected = self::getToken();
        return hash_equals($expected, $submittedToken);
    }
}

