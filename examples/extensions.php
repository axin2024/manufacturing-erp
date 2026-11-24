<?php

declare(strict_types=1);

use ManufacturingErp\Value;

return [
    'format_version' => 1,
    'quality_for_new_cases' => ['work' => 'certificate-reference-v1'],
    'quality_rules' => [
        'certificate-reference-v1' => static function (array $case, array $decision): ?string {
            if ($decision['accepted_milli'] > 0 && !preg_match('/\ACERT [A-Z0-9-]+\z/', $decision['reason'])) {
                return '放行前请填写检验报告编号，例如 CERT QA-001。';
            }
            return null;
        },
    ],
    'allocation_for_new_work' => 'setup-weighted-v1',
    'cost_policies' => [
        'setup-weighted-v1' => static function (int $amount, int $completed, int $ordered): int {
            if ($ordered === 1) {
                return Value::allocation($amount, $completed, $ordered);
            }
            $firstHalf = intdiv($ordered, 2);
            $setupWeight = Value::allocation($amount, 3, 4);
            if ($completed <= $firstHalf) {
                return Value::allocation($setupWeight, $completed, $firstHalf);
            }
            return $setupWeight + Value::allocation($amount - $setupWeight, $completed - $firstHalf, $ordered - $firstHalf);
        },
    ],
];
