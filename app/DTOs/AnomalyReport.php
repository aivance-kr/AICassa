<?php

namespace App\DTOs;

use App\Enums\AnomalySeverity;

/**
 * 신고 전 이상탐지 리포트(값 객체). 경고 목록 + 심각도별 집계.
 */
final readonly class AnomalyReport
{
    /**
     * @param int                  $year     귀속연도
     * @param list<AnomalyFinding> $findings 심각도 내림차순 정렬된 경고 목록
     */
    public function __construct(
        public int $year,
        public array $findings,
    ) {
    }

    /**
     * 경고가 하나도 없으면 true(신고 전 점검 통과).
     */
    public function isClean(): bool
    {
        return $this->findings === [];
    }

    /**
     * '위험' 등급 경고가 있으면 true.
     */
    public function hasBlocking(): bool
    {
        foreach ($this->findings as $finding) {
            if ($finding->severity === AnomalySeverity::High) {
                return true;
            }
        }

        return false;
    }

    /**
     * 심각도별 건수.
     *
     * @return array{high:int, warning:int, info:int}
     */
    public function counts(): array
    {
        $counts = ['high' => 0, 'warning' => 0, 'info' => 0];

        foreach ($this->findings as $finding) {
            $counts[$finding->severity->value]++;
        }

        return $counts;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'year'     => $this->year,
            'clean'    => $this->isClean(),
            'counts'   => $this->counts(),
            'findings' => array_map(static fn (AnomalyFinding $f): array => $f->toArray(), $this->findings),
        ];
    }
}
