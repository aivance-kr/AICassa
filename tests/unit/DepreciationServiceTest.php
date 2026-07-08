<?php

use App\Enums\DepreciationMethod;
use App\Services\DepreciationService;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 감가상각비 계산 검증 (VBA sQuery연도별감가상각정보 이식).
 *
 * @internal
 */
final class DepreciationServiceTest extends CIUnitTestCase
{
    private DepreciationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DepreciationService();
    }

    /**
     * 정액법 — 1월 취득, 내용연수 5년(상각률 0.2), 취득금액 1,000만원.
     * 매년 200만원씩, 마지막 해엔 비망가액 1,000원을 남긴다.
     */
    public function testStraightLineFullSchedule(): void
    {
        $schedule = $this->service->generateSchedule(
            DepreciationMethod::StraightLine,
            10_000_000,
            0.2,
            2020,
            1,
        );

        $depreciations = array_column($schedule, 'depreciation');
        $this->assertSame([2_000_000, 2_000_000, 2_000_000, 2_000_000, 1_999_000], $depreciations);

        // 최종 장부가액 = 비망가액 1,000원
        $last = end($schedule);
        $this->assertSame(1000, $last['book_value']);
        // 누계 = 취득금액 − 비망가액
        $this->assertSame(9_999_000, $last['accumulated']);
    }

    /**
     * 정액법 — 7월 취득 시 첫해 월할상각((13−7)/12 = 6/12).
     */
    public function testStraightLineMidYearAcquisitionProration(): void
    {
        $schedule = $this->service->generateSchedule(
            DepreciationMethod::StraightLine,
            1_200_000,
            0.2,
            2020,
            7,
        );

        // 240,000 × 6/12 = 120,000
        $this->assertSame(120_000, $schedule[0]['depreciation']);
    }

    /**
     * 정률법 — 1월 취득, 내용연수 5년(상각률 0.451), 취득금액 1,000만원.
     * 첫해 = 장부가액 × 0.451, 완전상각 시 비망가액 1,000원까지.
     */
    public function testDecliningBalance(): void
    {
        $schedule = $this->service->generateSchedule(
            DepreciationMethod::DecliningBalance,
            10_000_000,
            0.451,
            2020,
            1,
        );

        // 첫해: 10,000,000 × 0.451 = 4,510,000
        $this->assertSame(4_510_000, $schedule[0]['depreciation']);

        // 완전상각: 최종 장부가액 1,000원, 누계 = 취득금액 − 1,000
        $last = end($schedule);
        $this->assertSame(1000, $last['book_value']);
        $this->assertSame(9_999_000, $last['accumulated']);

        // 상각비는 매년 감소(정률법 특성)
        $deps = array_column($schedule, 'depreciation');
        $this->assertGreaterThan($deps[1], $deps[0]);
    }

    /**
     * 처분연도 월할상각 — 6월 처분 시 처분연도 상각비 × 6/12.
     */
    public function testDisposalYearProration(): void
    {
        $schedule = $this->service->generateSchedule(
            DepreciationMethod::StraightLine,
            10_000_000,
            0.2,
            2020,
            1,
            2022, // 처분연도
            6,    // 처분월
        );

        // 2020, 2021, 2022(처분) 세 해만
        $this->assertCount(3, $schedule);
        // 처분연도: 2,000,000 × 6/12 = 1,000,000
        $this->assertSame(1_000_000, $schedule[2]['depreciation']);
        $this->assertSame(2022, $schedule[2]['year']);
    }
}
