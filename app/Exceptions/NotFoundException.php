<?php

namespace App\Exceptions;

/**
 * 리소스를 찾을 수 없음.
 */
final class NotFoundException extends DomainException
{
    public function httpStatusCode(): int
    {
        return 404;
    }

    public function errorCode(): string
    {
        return 'NOT_FOUND';
    }
}
