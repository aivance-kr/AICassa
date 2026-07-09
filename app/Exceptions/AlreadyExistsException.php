<?php

namespace App\Exceptions;

/**
 * 중복 리소스(유니크 제약 위반).
 */
final class AlreadyExistsException extends DomainException
{
    public function httpStatusCode(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'ALREADY_EXISTS';
    }
}
