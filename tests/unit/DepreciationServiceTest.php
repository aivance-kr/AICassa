<?php

use App\Enums\DepreciationMethod;
use App\Services\DepreciationService;
use App\Services\TaxRuleResolver;
use CodeIgniter\Test\CIUnitTestCase;
use Config\TaxRules;

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

    /**
     * 개정 세법이 스케줄 중간 연도부터 적용되는 경우(연도별 룰셋 분기 재현).
     * 2027년부터 비망가액이 5,000원으로 개정된 가상 룰셋을 사용해,
     * 완전상각 종료 시점의 잔존 장부가액이 연도별 룰셋을 따르는지 검증한다.
     */
    public function testAppliesPerYearMemorandumValueAcrossSchedule(): void
    {
        $config       = new TaxRules();
        $config->sets = [
            2023 => ['vat_divisor' => 10, 'memorandum_value' => 1000, 'declining_residual_divisor' => 20],
            2027 => ['vat_divisor' => 10, 'memorandum_value' => 5000, 'declining_residual_divisor' => 20],
        ];
        $service = new DepreciationService(new TaxRuleResolver($config));

        // 2025년 취득, 내용연수 5년(0.2), 취득금액 1,000만원 → 완전상각은 2029년
        $schedule = $service->generateSchedule(
            DepreciationMethod::StraightLine,
            10_000_000,
            0.2,
            2025,
            1,
        );

        // 완전상각 종료 연도(2029)는 2027 개정 룰셋 적용 → 비망가액 5,000원 잔존
        $last = end($schedule);
        $this->assertSame(2029, $last['year']);
        $this->assertSame(5000, $last['book_value']);
        $this->assertSame(9_995_000, $last['accumulated']);
    }
}
