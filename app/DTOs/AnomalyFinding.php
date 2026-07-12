<?php

namespace App\DTOs;

use App\Enums\AnomalySeverity;

/**
 * 이상탐지 경고 1건(값 객체).
 *
 * 각 경고는 근거(항목·금액·규칙)를 detail 에 명시한다. 금액·집계는 도메인 서비스가
 * 재계산한 값이며(AI 계산 금지), source 로 규칙/AI 출처를 구분한다.
 */
final readonly class AnomalyFinding
{
    /**
     * @param AnomalySeverity $severity 심각도
     * @param string          $code     경고 코드(UPPER_SNAKE_CASE)
     * @param string          $title    한 줄 요약
     * @param string          $detail   근거(항목·금액·규칙)
     * @param string          $source   'rule'(결정적 규칙) | 'ai'(AI 추론)
     */
    public function __construct(
        public AnomalySeverity $severity,
        public string $code,
        public string $title,
        public string $detail,
        public string $source = 'rule',
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'severity'       => $this->severity->value,
            'severity_label' => $this->severity->label(),
            'code'           => $this->code,
            'title'          => $this->title,
            'detail'         => $this->detail,
            'source'         => $this->source,
        ];
    }
}
