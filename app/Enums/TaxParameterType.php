<?php

namespace App\Enums;

/**
 * 세법 파라미터 값 타입.
 * 저장은 문자열 컬럼(param_value) 하나로 하되, 이 타입으로 해석 방식을 구분한다.
 *  - Integer/Decimal: 스칼라 숫자(예: 비망가액 1000, 부가세율 0.1)
 *  - Text: 스칼라 문자열(예: 서식 버전 '2025-v1')
 *  - Json: 복합 세율표(예: 소득세 누진구간, 경비율표)
 */
enum TaxParameterType: string
{
    case Integer = 'int';
    case Decimal = 'decimal';
    case Text    = 'string';
    case Json    = 'json';

    /**
     * 한글 표시명.
     */
    public function label(): string
    {
        return match ($this) {
            self::Integer => '정수',
            self::Decimal => '소수',
            self::Text    => '문자열',
            self::Json    => 'JSON(표)',
        };
    }

    /**
     * 복합값(JSON) 여부 — 화면에서 textarea/스칼라 입력 분기에 사용.
     */
    public function isComplex(): bool
    {
        return $this === self::Json;
    }

    /**
     * 저장 문자열을 타입에 맞는 PHP 값으로 캐스팅한다.
     *
     * @return float|int|list<mixed>|string
     */
    public function cast(string $raw): array|float|int|string
    {
        return match ($this) {
            self::Integer => (int) $raw,
            self::Decimal => (float) $raw,
            self::Text    => $raw,
            self::Json    => self::decodeJson($raw),
        };
    }

    /**
     * JSON 문자열을 배열로 디코드한다. 실패 시 빈 배열.
     *
     * @return list<mixed>
     */
    private static function decodeJson(string $raw): array
    {
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
