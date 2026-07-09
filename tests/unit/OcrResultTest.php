<?php

use App\DTOs\OcrResult;
use App\Enums\EntryType;
use App\Enums\EvidenceType;
use App\Exceptions\OcrProcessingException;
use CodeIgniter\Test\CIUnitTestCase;

/**
 * 영수증 AI 판독 결과 DTO 검증. 신뢰할 수 없는 외부(Claude) 응답을 안전하게 파싱하는지 확인한다.
 *
 * @internal
 */
final class OcrResultTest extends CIUnitTestCase
{
    /**
     * 정상 응답은 필드가 그대로 매핑된다.
     */
    public function testFromArrayMapsValidResponse(): void
    {
        $result = OcrResult::fromArray([
            'entry_type'    => 'expense',
            'entry_date'    => '2026-03-15',
            'description'   => '사무용품 구입',
            'supply_amount' => 45000,
            'evidence_type' => 'tax_invoice',
            'account_name'  => '소모품비',
            'partner_name'  => '오피스마트',
        ]);

        $this->assertSame(EntryType::Expense, $result->entryType);
        $this->assertSame('2026-03-15', $result->entryDate);
        $this->assertSame('사무용품 구입', $result->description);
        $this->assertSame(45000, $result->supplyAmount);
        $this->assertSame(EvidenceType::TaxInvoice, $result->evidenceType);
        $this->assertSame('소모품비', $result->accountName);
        $this->assertSame('오피스마트', $result->partnerName);
    }

    /**
     * 숫자는 정수로 캐스팅되고, 빈 이름은 null 로 정규화된다.
     */
    public function testFromArrayNormalizesOptionalFields(): void
    {
        $result = OcrResult::fromArray([
            'entry_type'    => 'income',
            'entry_date'    => '2026-01-02',
            'description'   => '',
            'supply_amount' => '120000',
            'evidence_type' => '',
            'account_name'  => '  ',
            'partner_name'  => null,
        ]);

        $this->assertSame(EntryType::Income, $result->entryType);
        $this->assertSame(120000, $result->supplyAmount);
        $this->assertNull($result->evidenceType);
        $this->assertNull($result->accountName);
        $this->assertNull($result->partnerName);
    }

    /**
     * 자산 전표 등 수입/비용이 아닌 구분은 거부(이 폼은 수입/비용만 허용).
     */
    public function testFromArrayRejectsNonLedgerEntryType(): void
    {
        $this->expectException(OcrProcessingException::class);

        OcrResult::fromArray([
            'entry_type' => 'asset_purchase',
            'entry_date' => '2026-03-15',
        ]);
    }

    /**
     * 알 수 없는 구분 문자열도 거부.
     */
    public function testFromArrayRejectsUnknownEntryType(): void
    {
        $this->expectException(OcrProcessingException::class);

        OcrResult::fromArray([
            'entry_type' => 'garbage',
            'entry_date' => '2026-03-15',
        ]);
    }

    /**
     * 잘못된 일자 형식은 거부.
     */
    public function testFromArrayRejectsInvalidDate(): void
    {
        $this->expectException(OcrProcessingException::class);

        OcrResult::fromArray([
            'entry_type' => 'expense',
            'entry_date' => '2026/03/15',
        ]);
    }
}
