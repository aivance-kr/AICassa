<?php

namespace App\DTOs;

use App\Enums\EntryType;
use App\Enums\EvidenceType;

/**
 * 장부(거래) 입력 DTO. 부가세·귀속연도는 서비스에서 파생하므로 여기엔 포함하지 않는다.
 */
final readonly class LedgerData
{
    public function __construct(
        public string $entryDate,
        public EntryType $entryType,
        public string $description,
        public int $supplyAmount,
        public ?int $accountId = null,
        public ?int $partnerId = null,
        public ?EvidenceType $evidenceType = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            entryDate: trim((string) ($data['entry_date'] ?? '')),
            entryType: EntryType::tryFrom((string) ($data['entry_type'] ?? '')) ?? EntryType::Expense,
            description: trim((string) ($data['description'] ?? '')),
            supplyAmount: self::toInt($data['supply_amount'] ?? 0),
            accountId: self::toNullableInt($data['account_id'] ?? null),
            partnerId: self::toNullableInt($data['partner_id'] ?? null),
            evidenceType: EvidenceType::tryFrom((string) ($data['evidence_type'] ?? '')),
        );
    }

    /**
     * 금액 문자열(천단위 콤마 포함 가능)을 정수로.
     */
    private static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return (int) str_replace([',', ' '], '', (string) $value);
    }

    private static function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === '0') {
            return null;
        }

        return (int) $value;
    }
}
