<?php

namespace App\DTOs;

use App\Enums\DepreciationMethod;

/**
 * 사업용 자산 생성·수정 입력 DTO. 상각률은 서비스에서 내용연수로 자동 조회한다.
 */
final readonly class AssetData
{
    public function __construct(
        public string $assetType,
        public string $name,
        public string $acquiredAt,
        public int $acquisitionCost,
        public ?DepreciationMethod $depreciationMethod = null,
        public ?int $usefulLife = null,
        public ?string $disposedAt = null,
        public ?int $disposalAmount = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            assetType: trim((string) ($data['asset_type'] ?? '')),
            name: trim((string) ($data['name'] ?? '')),
            acquiredAt: trim((string) ($data['acquired_at'] ?? '')),
            acquisitionCost: self::toInt($data['acquisition_cost'] ?? 0),
            depreciationMethod: DepreciationMethod::tryFrom((string) ($data['depreciation_method'] ?? '')),
            usefulLife: self::toNullableInt($data['useful_life'] ?? null),
            disposedAt: self::nullableString($data['disposed_at'] ?? null),
            disposalAmount: self::toNullableInt($data['disposal_amount'] ?? null),
        );
    }

    /**
     * Model 저장용 기본 배열(business_id·depreciation_rate 는 서비스가 채운다).
     *
     * @return array<string, mixed>
     */
    public function toDatabaseArray(): array
    {
        return [
            'asset_type'          => $this->assetType,
            'name'                => $this->name,
            'acquired_at'         => $this->acquiredAt,
            'acquisition_cost'    => $this->acquisitionCost,
            'depreciation_method' => $this->depreciationMethod?->value,
            'useful_life'         => $this->usefulLife,
            'disposed_at'         => $this->disposedAt,
            'disposal_amount'     => $this->disposalAmount,
        ];
    }

    private static function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        return (int) str_replace([',', ' '], '', (string) $value);
    }

    private static function toNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) str_replace([',', ' '], '', (string) $value);
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
