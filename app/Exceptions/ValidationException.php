<?php

namespace App\Exceptions;

/**
 * 유효성 검사 실패.
 */
final class ValidationException extends DomainException
{
    /**
     * @param array<string, string> $errors 필드별 오류 메시지
     */
    public function __construct(
        private readonly array $errors,
        string $message = '유효성 검사에 실패했습니다.',
    ) {
        parent::__construct($message);
    }

    public function httpStatusCode(): int
    {
        return 422;
    }

    public function errorCode(): string
    {
        return 'VALIDATION_ERROR';
    }

    /**
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
