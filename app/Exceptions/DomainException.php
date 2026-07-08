<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * 도메인 예외 기반 클래스.
 * HTTP 상태코드와 에러 코드(문자열)를 함께 노출한다.
 */
abstract class DomainException extends RuntimeException
{
    /**
     * HTTP 상태코드.
     */
    abstract public function httpStatusCode(): int;

    /**
     * 에러 코드(UPPER_SNAKE_CASE).
     */
    abstract public function errorCode(): string;
}
