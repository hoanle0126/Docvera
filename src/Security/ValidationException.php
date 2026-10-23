<?php

declare(strict_types=1);

namespace Docvera\Security;

use RuntimeException;

final class ValidationException extends RuntimeException
{
    public function __construct(string $message, int $code = 422)
    {
        parent::__construct($message, $code);
    }
}

