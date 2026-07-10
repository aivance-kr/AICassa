<?php

namespace App\DTOs;

use App\Enums\EntryType;
use App\Enums\EvidenceType;
use App\Exceptions\OcrProcessingException;

/**
 * 영수증 AI 판독 결과(값 객체).
 *
 * 다른 DTO 와 달리 fromArray() 가 신뢰할 수 없는 외부(Claude) 응답을 검증하는 지점이다.
 * 필수 값이 없거나 형식이 어긋나면 OcrProcessingException 을 던진다("AI 응답은 불신" 원칙).
 * 계정과목명·거래처명은 원문(hint)만 담고, 실제 account_id/partner_id 매핑은 서비스가 수행한다.
 */
final readonly class OcrResult
{
    public function __construct(
        public EntryType $entryType,
        public string $entryDate,
        public string $description,
        public int $supplyAmount,
        public ?EvidenceType $evidenceType,
        public ?string $accountName,
        public ?string $partnerName,
    ) {
    }

    /**
     * @param array<string, mixed> $json Claude 가 반환한 JSON 디코드 결과
     *
     * @throws OcrProcessingException 필수 값 누락·형식 불일치 시
     */
    public static function fromArray(array $json): self
    {
        // 이 폼은 수입/비용만 허용(자산 전표는 자산대장에서 관리).
        $entryType = EntryType::tryFrom((string) ($json['entry_type'] ?? ''));
        if ($entryType !== EntryType::Income && $entryType !== EntryType::Expense) {
            throw new OcrProcessingException('AI 응답의 거래 구분을 해석할 수 없습니다.');
        }

        $entryDate = trim((string) ($json['entry_date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $entryDate) !== 1) {
            throw new OcrProcessingException('AI 응답의 일자 형식이 올바르지 않습니다.');
        }

        return new self(
            entryType: $entryType,
            entryDate: $entryDate,
            description: trim((string) ($json['description'] ?? '')),
            supplyAmount: (int) ($json['supply_amount'] ?? 0),
            evidenceType: EvidenceType::tryFrom((string) ($json['evidence_type'] ?? '')),
            accountName: self::nullableString($json['account_name'] ?? null),
            partnerName: self::nullableString($json['partner_name'] ?? null),
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
