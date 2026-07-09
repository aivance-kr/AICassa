<?php

namespace App\Exceptions;

/**
 * 영수증 AI 판독 실패(외부 서비스 오류·응답 파싱 실패).
 * 외부(Claude) 응답은 신뢰하지 않으며, 형식이 어긋나면 이 예외로 전환한다.
 */
final class OcrProcessingException extends DomainException
{
    public function httpStatusCode(): int
    {
        return 502;
    }

    public function errorCode(): string
    {
        return 'OCR_PROCESSING_FAILED';
    }
}
