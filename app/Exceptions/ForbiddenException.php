<?php

namespace App\Exceptions;

/**
 * 권한 없음(다른 테넌트 리소스 접근 등).
 */
final class ForbiddenException extends DomainException
{
    public function httpStatusCode(): int
    {
        return 403;
    }

    public function errorCode(): string
    {
        return 'FORBIDDEN';
    }
}
