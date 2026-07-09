<?php

namespace App\DTOs;

/**
 * 사업장 생성·수정 입력 DTO.
 */
final readonly class BusinessData
{
    public function __construct(
        public string $name,
        public ?string $ownerName = null,
        public ?string $birthDate = null,
        public ?string $bizRegNo = null,
        public ?string $address = null,
        public ?string $phone = null,
        public ?string $industryCode = null,
        public ?string $industryName = null,
        public ?string $incomeType = null,
        public bool $isManufacturing = false,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: trim((string) ($data['name'] ?? '')),
            ownerName: self::nullableString($data['owner_name'] ?? null),
            birthDate: self::nullableString($data['birth_date'] ?? null),
            bizRegNo: self::normalizeBizNo($data['biz_reg_no'] ?? null),
            address: self::nullableString($data['address'] ?? null),
            phone: self::nullableString($data['phone'] ?? null),
            industryCode: self::nullableString($data['industry_code'] ?? null),
            industryName: self::nullableString($data['industry_name'] ?? null),
            incomeType: self::nullableString($data['income_type'] ?? null),
            isManufacturing: (bool) ($data['is_manufacturing'] ?? false),
        );
    }

    /**
     * Model 저장용 배열(snake_case 키).
     *
     * @return array<string, mixed>
     */
    public function toDatabaseArray(): array
    {
        return [
            'name'             => $this->name,
            'owner_name'       => $this->ownerName,
            'birth_date'       => $this->birthDate,
            'biz_reg_no'       => $this->bizRegNo,
            'address'          => $this->address,
            'phone'            => $this->phone,
            'industry_code'    => $this->industryCode,
            'industry_name'    => $this->industryName,
            'income_type'      => $this->incomeType,
            'is_manufacturing' => $this->isManufacturing ? 1 : 0,
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

    /**
     * 사업자등록번호는 하이픈을 제거해 정규화한다(중복비교 일관성).
     */
    private static function normalizeBizNo(mixed $value): ?string
    {
        $value = self::nullableString($value);
        if ($value === null) {
            return null;
        }

        return str_replace('-', '', $value);
    }
}
