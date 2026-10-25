<?php

declare(strict_types=1);

namespace Docvera\Http;

final class Response
{
    public static function html(string $content, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: SAMEORIGIN');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
            header("Content-Security-Policy: default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline'; font-src 'self'; script-src 'self' 'unsafe-inline'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
        }
        echo $content;
        exit;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function json(array $payload, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function redirect(string $url, int $status = 303): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Location: ' . $url);
        }
        exit;
    }

    public static function download(string $filePath, string $downloadFilename, string $mimeType): never
    {
        $cleanName = preg_replace('/[^a-zA-Z0-9._\-]/', '_', $downloadFilename) ?: 'document';
        $size = (int) filesize($filePath);

        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: ' . $mimeType);
            header('Content-Disposition: attachment; filename="' . $cleanName . '"');
            header('Content-Length: ' . $size);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store, no-cache, must-revalidate');
        }

        readfile($filePath);
        exit;
    }

    public static function xml(string $xmlContent, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/xml; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
        }
        echo $xmlContent;
        exit;
    }

    public static function text(string $textContent, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/plain; charset=UTF-8');
            header('X-Content-Type-Options: nosniff');
        }
        echo $textContent;
        exit;
    }
}

