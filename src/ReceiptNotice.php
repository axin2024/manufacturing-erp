<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class ReceiptNotice
{
    public static function identity(string $source, string $number): array
    {
        $source = Value::code($source, '外部来源');
        $number = Value::code($number, '外部回执号');
        return ['source' => $source, 'external_number' => $number,
            'event_key' => 'receipt-notice-' . hash('sha256', Value::json([$source, $number]))];
    }
}
