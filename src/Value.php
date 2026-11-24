<?php

declare(strict_types=1);

namespace ManufacturingErp;

use DateTimeImmutable;
use JsonException;

final class Value
{
    public const SCALE = 1000;
    public const MAX_QUANTITY = 1_000_000_000;
    public const MAX_UNIT_PRICE = 1_000_000_000;

    public static function text(string $value, int $max, string $field): string
    {
        $value = trim($value);
        self::assert($value !== '' && strlen($value) <= $max && preg_match('//u', $value)
            && !preg_match('/[\x00-\x1f\x7f]/', $value), $field . '不能为空或包含控制字符，且不得超出长度限制。');
        return $value;
    }

    public static function code(string $value, string $field = '编号'): string
    {
        $value = strtoupper(trim($value));
        self::assert((bool) preg_match('/\A[A-Z0-9][A-Z0-9_.-]{0,39}\z/', $value), $field . '格式无效。');
        return $value;
    }

    public static function day(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        self::assert($date !== false && $date->format('Y-m-d') === $value && $value >= '1000-01-01', '日期格式无效。');
        return $value;
    }

    public static function quantity(int $quantity): int
    {
        self::assert($quantity > 0 && $quantity <= self::MAX_QUANTITY, '数量超出有效范围。');
        return $quantity;
    }

    public static function timestamp(string $value): string
    {
        $moment = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        self::assert($moment !== false && $moment->format('Y-m-d H:i:s') === $value && $value >= '1000-01-01 00:00:00',
            '时间格式无效，请使用 UTC 的 YYYY-MM-DD HH:MM:SS。');
        return $value;
    }

    public static function unitPrice(int $cents): int
    {
        self::assert($cents >= 0 && $cents <= self::MAX_UNIT_PRICE, '单价超出有效范围。');
        return $cents;
    }

    public static function unit(string $value): string
    {
        return self::text($value, 24, '单位');
    }

    public static function convert(int $quantity, int $factor): int
    {
        $product = self::quantity($quantity) * self::quantity($factor);
        self::assert($product % self::SCALE === 0, '单位换算后的数量超出三位小数精度。');
        return self::quantity(intdiv($product, self::SCALE));
    }

    public static function scaled(mixed $value, int $precision, int $maximum): int
    {
        self::assert(is_string($value) || is_int($value), '请输入有效数字。');
        $text = (string) $value;
        self::assert((bool) preg_match('/\A([0-9]{1,10})(?:\.([0-9]{1,' . $precision . '}))?\z/', $text, $parts),
            '数字格式不正确或小数位超限。');
        $number = (int) $parts[1] * (10 ** $precision) + (int) str_pad($parts[2] ?? '', $precision, '0');
        self::assert($number <= $maximum, '数字超出范围。');
        return $number;
    }

    public static function extendedPrice(int $quantity, int $unitPrice): int
    {
        return intdiv(self::quantity($quantity) * self::unitPrice($unitPrice) + 500, self::SCALE);
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function fromJson(string $value): array
    {
        $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new JsonException('Expected a JSON object or array.');
        }
        return $decoded;
    }

    public static function assert(bool $ok, string $message, int $status = 422): void
    {
        if (!$ok) {
            throw new BusinessError($message, $status);
        }
    }
}
