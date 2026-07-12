<?php

namespace App\DTOs;

/**
 * 세무조정 입력 DTO(소득금액계산서 ⑫⑬⑯⑰⑳㉑).
 */
final readonly class TaxAdjustmentData
{
    public function __construct(
        public int $revenueExclude = 0,
        public int $revenueAdd = 0,
        public int $expenseExclude = 0,
        public int $expenseAdd = 0,
        public int $donationOver = 0,
        public int $donationCarryover = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            revenueExclude: self::toInt($data['revenue_exclude'] ?? 0),
            revenueAdd: self::toInt($data['revenue_add'] ?? 0),
            expenseExclude: self::toInt($data['expense_exclude'] ?? 0),
            expenseAdd: self::toInt($data['expense_add'] ?? 0),
            donationOver: self::toInt($data['donation_over'] ?? 0),
            donationCarryover: self::toInt($data['donation_carryover'] ?? 0),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toDatabaseArray(): array
    {
        return [
            'revenue_exclude'    => $this->revenueExclude,
            'revenue_add'        => $this->revenueAdd,
            'expense_exclude'    => $this->expenseExclude,
            'expense_add'        => $this->expenseAdd,
            'donation_over'      => $this->donationOver,
            'donation_carryover' => $this->donationCarryover,
        ];
    }

    private static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return (int) str_replace([',', ' '], '', (string) $value);
    }
}
