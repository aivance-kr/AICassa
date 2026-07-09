<?php

namespace App\Enums;

/**
 * 증빙유형(장부 비고).
 * 부가세 자동계산의 기준 — 과세증빙만 10% 부가세 발생.
 * (원본 VBA: 계산서·간이영수증·기타 → 부가세 0, 그 외 → 금액×10%)
 */
enum EvidenceType: string
{
    case TaxInvoice    = 'tax_invoice';    // 세금계산서
    case Invoice       = 'invoice';        // 계산서(면세)
    case CreditCard    = 'credit_card';    // 신용카드
    case CashReceipt   = 'cash_receipt';   // 현금영수증
    case SimpleReceipt = 'simple_receipt'; // 간이영수증
    case Other         = 'other';          // 기타

    /**
     * 부가세가 발생하는(과세) 증빙 여부.
     */
    public function isVatable(): bool
    {
        return match ($this) {
            self::TaxInvoice, self::CreditCard, self::CashReceipt => true,
            self::Invoice, self::SimpleReceipt, self::Other       => false,
        };
    }

    /**
     * 한글 표시명.
     */
    public function label(): string
    {
        return match ($this) {
            self::TaxInvoice    => '세금계산서',
            self::Invoice       => '계산서',
            self::CreditCard    => '신용카드',
            self::CashReceipt   => '현금영수증',
            self::SimpleReceipt => '간이영수증',
            self::Other         => '기타',
        };
    }

    /**
     * 한글 표시명으로 역매핑(일괄 업로드용). 미일치 시 null.
     */
    public static function fromLabel(string $label): ?self
    {
        foreach (self::cases() as $case) {
            if ($case->label() === trim($label)) {
                return $case;
            }
        }

        return null;
    }
}
