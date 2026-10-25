<?php

declare(strict_types=1);

namespace App\Support\Errors;

use RuntimeException;

final class DomainException extends RuntimeException
{
    public const INSUFFICIENT_STOCK = 'INSUFFICIENT_STOCK';

    public const INSUFFICIENT_CAPACITY = 'INSUFFICIENT_CAPACITY';

    public const PERIOD_CLOSED = 'PERIOD_CLOSED';

    public const INVALID_STATE_TRANSITION = 'INVALID_STATE_TRANSITION';

    public const FORBIDDEN = 'FORBIDDEN';

    public const NOT_FOUND = 'NOT_FOUND';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 409,
    ) {
        parent::__construct($message);
    }
}
