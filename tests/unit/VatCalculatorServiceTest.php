<?php

use App\Enums\EvidenceType;
use App\Services\TaxRuleResolver;
use App\Services\VatCalculatorService;
use CodeIgniter\Test\CIUnitTestCase;
use Config\TaxRules;

/**
 * 부가세 자동계산 규칙 검증 (VBA cbo비고_Change 이식).
 *
 * @internal
 */
final class VatCalculatorServiceTest extends CIUnitTestCase
{
    private VatCalculatorService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VatCalculatorService();
    }

    /**
     * 과세 증빙(세금계산서·신용카드·현금영수증)은 공급가액의 10%.
     */
    public function testVatableEvidenceReturnsTenPercent(): void
    {
        $this->assertSame(58895, $this->service->calculate(588950, EvidenceType::TaxInvoice, 2025));
        $this->assertSame(117790, $this->service->calculate(1177900, EvidenceType::CreditCard, 2025));
        $this->assertSame(500000, $this->service->calculate(5000000, EvidenceType::CashReceipt, 2025));
    }

    /**
     * 면세 증빙(계산서·간이영수증·기타)은 부가세 0.
     */
    public function testNonVatableEvidenceReturnsZero(): void
    {
        $this->assertSame(0, $this->service->calculate(1000000, EvidenceType::Invoice, 2025));
        $this->assertSame(0, $this->service->calculate(1000000, EvidenceType::SimpleReceipt, 2025));
        $this->assertSame(0, $this->service->calculate(1000000, EvidenceType::Other, 2025));
    }

    /**
     * 10으로 나누어 떨어지지 않으면 원 단위 절사(VBA Int).
     */
    public function testVatIsFloored(): void
    {
        // 588955 × 0.1 = 58895.5 → 58895
        $this->assertSame(58895, $this->service->calculate(588955, EvidenceType::TaxInvoice, 2025));
    }

    /**
     * 음수 공급가액(자산 매각)은 크기 기준 절사 후 부호 유지.
     */
    public function testNegativeAmountKeepsSign(): void
    {
        $this->assertSame(-58895, $this->service->calculate(-588955, EvidenceType::TaxInvoice, 2025));
    }

    /**
     * 개정 세율은 시행연도부터 적용된다(as-of).
     * 2027년 부가세 제수가 5(=20%)로 개정된 가상 룰셋으로 연도별 분기를 검증한다.
     */
    public function testAppliesRevisedVatRateFromEffectiveYear(): void
    {
        $config       = new TaxRules();
        $config->sets = [
            2023 => ['vat_divisor' => 10, 'memorandum_value' => 1000, 'declining_residual_divisor' => 20],
            2027 => ['vat_divisor' => 5, 'memorandum_value' => 1000, 'declining_residual_divisor' => 20],
        ];
        $service = new VatCalculatorService(new TaxRuleResolver($config));

        // 2026 귀속: 아직 기존 세율(10%)
        $this->assertSame(100000, $service->calculate(1000000, EvidenceType::TaxInvoice, 2026));
        // 2027 귀속: 개정 세율(20%)
        $this->assertSame(200000, $service->calculate(1000000, EvidenceType::TaxInvoice, 2027));
    }
}
