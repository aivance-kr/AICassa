<?php

namespace App\Services;

use App\Enums\DepreciationMethod;

/**
 * 감가상각비 계산.
 *
 * 원본 프로그램(mModules → sQuery연도별감가상각정보) 규칙을 이식:
 *  - 계산한도액: 정액법 = Int(취득금액 × 상각률), 정률법 = Int(직전장부가액 × 상각률)
 *  - 월할상각: 취득연도는 (13 − 취득월)/12, 처분연도는 처분월/12
 *  - 비망가액 1,000원: 자산을 완전상각하지 않고 장부상 최소가액 1,000원을 남긴다
 *  - 정률법 잔존가액 = Int(취득금액 / 20) (취득금액의 5%)
 *
 * 모든 중간계산은 원 단위 절사(VBA Int) 규칙을 따른다.
 */
final class DepreciationService
{
    /**
     * 비망가액(장부상 남기는 최소 가액)
     */
    public const MEMORANDUM_VALUE = 1000;

    /**
     * 무한 루프 방지용 최대 상각 연수
     */
    private const MAX_YEARS = 100;

    /**
     * 취득~처분(또는 완전상각)까지의 연도별 감가상각 스케줄을 생성한다.
     *
     * @param DepreciationMethod $method             상각방법
     * @param int                $acquisitionCost    취득금액(원)
     * @param float              $rate               상각률(0~1)
     * @param int                $acquiredYear       취득연도
     * @param int                $acquiredMonth      취득월(1~12)
     * @param int|null           $disposalYear       처분연도(미처분이면 null)
     * @param int|null           $disposalMonth      처분월(1~12, 미처분이면 null)
     * @param int                $openingAccumulated 기초 감가상각누계액(전기말)
     *
     * @return list<array{year:int, depreciation:int, accumulated:int, book_value:int}>
     */
    public function generateSchedule(
        DepreciationMethod $method,
        int $acquisitionCost,
        float $rate,
        int $acquiredYear,
        int $acquiredMonth,
        ?int $disposalYear = null,
        ?int $disposalMonth = null,
        int $openingAccumulated = 0,
    ): array {
        $schedule    = [];
        $accumulated = $openingAccumulated;
        $bookValue   = $acquisitionCost - $openingAccumulated;

        for ($i = 0; $i < self::MAX_YEARS; $i++) {
            $year   = $acquiredYear + $i;
            $isAcq  = $year === $acquiredYear;
            $isDisp = $disposalYear !== null && $year === $disposalYear;

            $depreciation = $this->annualDepreciation(
                $method,
                $acquisitionCost,
                $bookValue,
                $rate,
                $isAcq ? $acquiredMonth : null,
                $isDisp ? ($disposalMonth ?? 12) : null,
            );

            $accumulated += $depreciation;
            $bookValue -= $depreciation;

            $schedule[] = [
                'year'         => $year,
                'depreciation' => $depreciation,
                'accumulated'  => $accumulated,
                'book_value'   => $bookValue,
            ];

            // 처분연도까지만, 또는 비망가액까지 상각 완료 시 종료
            if ($isDisp || $bookValue <= self::MEMORANDUM_VALUE) {
                break;
            }
        }

        return $schedule;
    }

    /**
     * 한 연도의 감가상각비(한도액)를 계산한다.
     *
     * @param int|null $acquiredMonth 취득연도인 경우 취득월, 아니면 null
     * @param int|null $disposalMonth 처분연도인 경우 처분월, 아니면 null
     */
    public function annualDepreciation(
        DepreciationMethod $method,
        int $acquisitionCost,
        int $bookValue,
        float $rate,
        ?int $acquiredMonth = null,
        ?int $disposalMonth = null,
    ): int {
        // 계산한도액: 정액법=취득금액 기준, 정률법=직전장부가액 기준
        $base  = $method === DepreciationMethod::StraightLine ? $acquisitionCost : $bookValue;
        $limit = (int) ($base * $rate);

        // 월할상각 — 취득연도 우선, 그다음 처분연도
        if ($acquiredMonth !== null && $acquiredMonth !== 1) {
            $months = 13 - $acquiredMonth;           // 취득월~12월 개월수
            $limit  = (int) ($limit / 12 * $months);
        } elseif ($disposalMonth !== null && $disposalMonth !== 12) {
            $limit = (int) ($limit / 12 * $disposalMonth);
        }

        // 비망가액 처리 — 상각 후 장부가액이 하한 이하로 내려가지 않도록 1,000원을 남긴다
        if ($method === DepreciationMethod::StraightLine) {
            $limit = min($limit, $bookValue);
            if (($bookValue - $limit) <= self::MEMORANDUM_VALUE) {
                $limit = $bookValue - self::MEMORANDUM_VALUE;
            }
        } else {
            $residual = intdiv($acquisitionCost, 20); // 정률법 잔존가액 = 취득금액의 5%
            if (($bookValue - $limit) <= $residual) {
                $limit = $bookValue - self::MEMORANDUM_VALUE;
            }
        }

        return max(0, $limit);
    }
}
