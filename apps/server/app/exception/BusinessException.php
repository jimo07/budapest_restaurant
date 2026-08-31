<?php

declare(strict_types=1);

namespace app\exception;

use RuntimeException;

class BusinessException extends RuntimeException
{
    public function __construct(string $message, private readonly int $businessCode = 40000, private readonly int $httpStatus = 400)
    {
        parent::__construct($message);
    }

    public function businessCode(): int { return $this->businessCode; }
    public function httpStatus(): int { return $this->httpStatus; }
}
