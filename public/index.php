<?php

declare(strict_types=1);

// Built-in PHP server static asset passthrough
if (PHP_SAPI === 'cli-server') {
    $uriPath = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $staticFile = __DIR__ . $uriPath;
    if ($uriPath !== '/' && is_file($staticFile)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';

use Docvera\Core\Application;
use Docvera\Http\Controller;
use Docvera\Http\Request;

$app = new Application();
$controller = new Controller($app);
$controller->handle(Request::capture());

