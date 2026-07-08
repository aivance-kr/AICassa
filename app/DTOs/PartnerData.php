<?php

namespace App\DTOs;

/**
 * 거래처 생성·수정 입력 DTO.
 */
final readonly class PartnerData
{
    public function __construct(
        public string $name,
        public ?string $bizRegNo = null,
        public ?string $phone = null,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: trim((string) ($data['name'] ?? '')),
            bizRegNo: self::normalizeBizNo($data['biz_reg_no'] ?? null),
            phone: self::nullableString($data['phone'] ?? null),
        );
    }

    /**
     * Model 저장용 배열.
     *
     * @return array<string, mixed>
     */
    public function toDatabaseArray(): array
    {
        return [
            'name'       => $this->name,
            'biz_reg_no' => $this->bizRegNo,
            'phone'      => $this->phone,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function normalizeBizNo(mixed $value): ?string
    {
        $value = self::nullableString($value);
        if ($value === null) {
            return null;
        }

        return str_replace('-', '', $value);
    }
}
