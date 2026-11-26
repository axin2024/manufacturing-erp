<?php

declare(strict_types=1);

namespace ManufacturingErp;

use JsonException;

final class WebInput
{
    public static function posted(): array
    {
        $type = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '', 2)[0]));
        if ($type !== 'application/json') {
            Value::assert(!isset($_POST['_workbench']) || ($_POST['_complete'] ?? null) === '1',
                '表单未完整接收，请检查服务器的请求大小及字段数量限制。', 413);
            return $_POST;
        }
        $content = file_get_contents('php://input', false, null, 0, 16_777_217);
        Value::assert(is_string($content) && strlen($content) <= 16_777_216, '请求内容超过 16 MiB。', 413);
        return self::json($content, '请求内容', true);
    }

    public static function text(mixed $value, string $field, int $limit = 255): string
    {
        Value::assert(is_string($value), $field . '须为文本。');
        return Value::text($value, $limit, $field);
    }

    public static function integer(mixed $value, string $field, int $minimum = 1, int $maximum = PHP_INT_MAX): int
    {
        $integer = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        Value::assert($integer !== false && $integer >= $minimum && $integer <= $maximum, $field . '须为有效范围内的整数。',422,$field);
        return $integer;
    }

    public static function decimal(mixed $value, int $precision, int $maximum, bool $signed = false): int
    {
        if ($signed && (is_string($value) || is_int($value)) && str_starts_with((string) $value, '-')) {
            return -Value::scaled(substr((string) $value, 1), $precision, $maximum);
        }
        return Value::scaled($value, $precision, $maximum);
    }

    public static function id(array $input, string $key): int
    {
        return self::integer($input[$key] ?? null, '记录编号');
    }

    public static function optionalId(array $input, string $key): ?int
    {
        return !isset($input[$key]) || $input[$key] === '' ? null : self::id($input, $key);
    }

    public static function quantity(array $input, string $key): int
    {
        return self::decimal($input[$key] ?? null, 3, Value::MAX_QUANTITY);
    }

    public static function price(array $input, string $key): int
    {
        return self::decimal($input[$key] ?? null, 2, Value::MAX_UNIT_PRICE);
    }

    public static function lines(array $input, string $key, callable $parse, int $maximum = 100): array
    {
        $rows = $input[$key] ?? null;
        Value::assert(is_array($rows) && count($rows) > 0 && count($rows) <= $maximum, '单据行数超出有效范围。');
        $result = [];
        foreach ($rows as $index => $row) {
            Value::assert(is_int($index) && $index >= 0 && is_array($row) && !array_is_list($row), '单据行格式无效。');
            $result[] = $parse($row);
        }
        return $result;
    }

    public static function timestamp(mixed $value): string
    {
        Value::assert(is_string($value), '时间格式无效。');
        $value = str_replace('T', ' ', $value);
        return Value::timestamp(strlen($value) === 16 ? $value . ':00' : $value);
    }

    public static function json(mixed $value, string $field, bool $object = false): array
    {
        try {
            $result = is_string($value) ? Value::fromJson($value) : $value;
        } catch (JsonException) {
            throw new BusinessError($field . '必须是有效的 JSON。');
        }
        Value::assert(is_array($result) && (!$object || !array_is_list($result) || $result === []), $field . '格式无效。');
        return $result;
    }

    public static function stringParameter(array $source, string $key, string $default = ''): string
    {
        $value = $source[$key] ?? $default;
        Value::assert(is_string($value), '参数格式无效。',422,$key);
        return $value;
    }
}
