<?php

declare(strict_types=1);

$configuration = require dirname(__DIR__, 2) . '/examples/extensions.php';
unset($configuration['quality_for_new_cases'], $configuration['allocation_for_new_work']);
if (getenv('ERP_TEST_BAD_COST') === '1') {
    $configuration['cost_policies']['setup-weighted-v1'] = static fn(): int => 0;
}
return $configuration;
