<?php

declare(strict_types=1);

// Built-in PHP server static asset passthrough (strictly bounded to public/)
if (PHP_SAPI === 'cli-server') {
    $uriPath = rawurldecode((string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
    $publicRoot = realpath(__DIR__);
    if ($uriPath !== '/' && $publicRoot !== false && !str_contains($uriPath, "\0")) {
        $basename = basename($uriPath);
        if ($basename !== '' && !str_starts_with($basename, '.')) {
            $resolvedStatic = realpath($publicRoot . '/' . ltrim($uriPath, '/'));
            if (
                $resolvedStatic !== false
                && str_starts_with($resolvedStatic, $publicRoot . DIRECTORY_SEPARATOR)
                && is_file($resolvedStatic)
            ) {
                return false;
            }
        }
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Docvera\Core\Application;
use Docvera\Http\Controller;
use Docvera\Http\Request;

$app = new Application();
$controller = new Controller($app);
$controller->handle(Request::capture());

