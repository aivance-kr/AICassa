<?php

namespace App\DTOs;

/**
 * 재고 입력 DTO(기초·기말 상품/재료).
 */
final readonly class InventoryData
{
    public function __construct(
        public int $goodsBegin = 0,
        public int $goodsEnd = 0,
        public int $materialsBegin = 0,
        public int $materialsEnd = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            goodsBegin: self::toInt($data['goods_begin'] ?? 0),
            goodsEnd: self::toInt($data['goods_end'] ?? 0),
            materialsBegin: self::toInt($data['materials_begin'] ?? 0),
            materialsEnd: self::toInt($data['materials_end'] ?? 0),
        );
    }

    /**
     * @return array<string, int>
     */
    public function toDatabaseArray(): array
    {
        return [
            'goods_begin'     => $this->goodsBegin,
            'goods_end'       => $this->goodsEnd,
            'materials_begin' => $this->materialsBegin,
            'materials_end'   => $this->materialsEnd,
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
