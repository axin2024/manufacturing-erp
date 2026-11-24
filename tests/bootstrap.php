<?php

declare(strict_types=1);

use ManufacturingErp\BusinessError;
use ManufacturingErp\Exports;

require dirname(__DIR__) . '/src/bootstrap.php';

if (getenv('ERP_TEST_DATABASE') !== '1') {
    fwrite(STDERR, "Use a disposable database and set ERP_TEST_DATABASE=1 before running the integration tests.\n");
    exit(2);
}

$checks = 0;

function same(mixed $expected, mixed $actual, string $description): void
{
    global $checks;
    if ($expected !== $actual) {
        throw new RuntimeException($description . "\nExpected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    ++$checks;
}

function rejects(int $status, callable $operation, string $description, ?string $messagePart = null): void
{
    try {
        $operation();
    } catch (BusinessError $error) {
        same($status, $error->status, $description);
        if ($messagePart !== null) {
            same(true, str_contains($error->getMessage(), $messagePart), $description . ' returns the reason');
        }
        return;
    }
    throw new RuntimeException($description . ' unexpectedly succeeded.');
}

function finish(string $suite): void
{
    global $checks;
    echo $suite . ': ' . $checks . " assertions passed.\n";
}

function csvRecords(Exports $exports, string $kind, ?int $id = null, ?string $filter = null): array
{
    $handle = $exports->open($kind, $id, $filter)['stream'];
    try {
        same("\xEF\xBB\xBF", fread($handle, 3), 'CSV has the UTF-8 BOM');
        $columns = fgetcsv($handle, 0, ',', '"', '');
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[] = array_combine($columns, $row);
        }
        return $rows;
    } finally {
        fclose($handle);
    }
}
