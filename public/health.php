<?php

declare(strict_types=1);

use ManufacturingErp\Database;
use ManufacturingErp\Extensions;
use ManufacturingErp\Migrations;

require dirname(__DIR__) . '/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    http_response_code(405);
    exit('{"status":"method_not_allowed"}');
}
try {
    Extensions::load()->validate();
    $ready = (new Migrations(Database::connect(), dirname(__DIR__) . '/database/migrations'))->ready();
} catch (Throwable) {
    $ready = false;
}
http_response_code($ready ? 200 : 503);
if ($method !== 'HEAD') {
    echo $ready ? '{"status":"ready"}' : '{"status":"unavailable"}';
}
