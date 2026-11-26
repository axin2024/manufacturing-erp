<?php

declare(strict_types=1);

namespace ManufacturingErp;

use RuntimeException;

final class BusinessError extends RuntimeException
{
    public function __construct(string $message, public readonly int $status = 422, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }
}
