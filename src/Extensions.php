<?php

declare(strict_types=1);

namespace ManufacturingErp;

use Closure;
use LogicException;

final class Extensions
{
    public const QUALITY_DEFAULT = 'erp-inspection-v1';
    public const ALLOCATION_DEFAULT = 'erp-quantity-v1';

    private function __construct(private readonly array $configuration)
    {
    }

    public static function load(): self
    {
        $file = getenv('ERP_EXTENSIONS_FILE');
        if ($file === false || $file === '') {
            return new self([]);
        }
        $resolved = realpath($file);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) {
            throw new LogicException('ERP extension configuration is unavailable.');
        }
        $configuration = require $resolved;
        if (!is_array($configuration) || ($configuration['format_version'] ?? null) !== 1) {
            throw new LogicException('Unsupported ERP extension format.');
        }
        return new self($configuration);
    }

    public function qualityVersion(string $kind): string
    {
        $version = $this->configuration['quality_for_new_cases'][$kind] ?? self::QUALITY_DEFAULT;
        if ($version !== self::QUALITY_DEFAULT) {
            $this->rule('quality_rules', $version);
        }
        return $version;
    }

    public function validate(): void
    {
        foreach (['purchase', 'work', 'subcontract'] as $kind) {
            $this->qualityVersion($kind);
        }
        $this->allocationVersion();
    }

    public function checkDecision(string $version, array $case, array $decision): void
    {
        if ($version === self::QUALITY_DEFAULT) {
            return;
        }
        $rule = $this->rule('quality_rules', $version);
        $reason = $rule($case, $decision);
        if ($reason === null) {
            return;
        }
        if (!is_string($reason)) {
            throw new LogicException('An ERP inspection rule must return null or a subject-safe rejection message.');
        }
        throw new BusinessError(Value::text($reason, 255, '检验规则反馈'));
    }

    public function allocationVersion(): string
    {
        $version = $this->configuration['allocation_for_new_work'] ?? self::ALLOCATION_DEFAULT;
        if ($version !== self::ALLOCATION_DEFAULT) {
            $this->rule('cost_policies', $version);
        }
        return $version;
    }

    public function accumulatedCost(string $version, int $totalCost, int $completed, int $ordered, int $previous): int
    {
        $policy = $this->rule('cost_policies', $version);
        $amount = $policy($totalCost, $completed, $ordered);
        Value::assert(is_int($amount) && $amount >= $previous && $amount <= $totalCost
            && ($completed < $ordered || $amount === $totalCost), '该成本规则给出了无效的累计分摊金额。', 503);
        return $amount;
    }

    private function rule(string $section, mixed $version): Closure
    {
        if (!is_string($version) || !preg_match('/\A[a-z][a-z0-9.-]{0,56}-v[1-9][0-9]{0,3}\z/', $version)) {
            throw new LogicException('An ERP rule identifier must end in a positive version number.');
        }
        $rule = $this->configuration[$section][$version] ?? null;
        Value::assert($rule instanceof Closure, '业务记录指定的扩展版本尚未安装。', 503);
        return $rule;
    }
}
