<?php

namespace App\Services;

use App\DTOs\BusinessData;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\BusinessModel;

/**
 * 사업장 유스케이스. 모든 작업은 user_id 스코프로 소유권을 검증한다.
 */
final class BusinessService
{
    private BusinessModel $businesses;

    public function __construct(?BusinessModel $businesses = null)
    {
        $this->businesses = $businesses ?? model(BusinessModel::class);
    }

    /**
     * 사용자의 사업장 목록.
     *
     * @return list<array<string, mixed>>
     */
    public function listForUser(int $userId): array
    {
        return $this->businesses->forUser($userId);
    }

    /**
     * 사용자 소유 사업장 단건 조회. 없으면 예외.
     *
     * @return array<string, mixed>
     */
    public function get(int $userId, int $businessId): array
    {
        $business = $this->businesses->findOwned($userId, $businessId);
        if ($business === null) {
            throw new NotFoundException('사업장을 찾을 수 없습니다.');
        }

        return $business;
    }

    /**
     * 사업장 등록.
     *
     * @return int 생성된 사업장 ID
     */
    public function create(int $userId, BusinessData $data): int
    {
        $row            = $data->toDatabaseArray();
        $row['user_id'] = $userId;

        if (! $this->businesses->insert($row)) {
            throw new ValidationException($this->businesses->errors());
        }

        return (int) $this->businesses->getInsertID();
    }

    /**
     * 사업장 수정.
     *
     * @return array<string, mixed> 수정된 사업장
     */
    public function update(int $userId, int $businessId, BusinessData $data): array
    {
        $this->get($userId, $businessId); // 소유권 검증

        if (! $this->businesses->update($businessId, $data->toDatabaseArray())) {
            throw new ValidationException($this->businesses->errors());
        }

        return $this->get($userId, $businessId);
    }

    /**
     * 사업장 삭제(소프트).
     */
    public function delete(int $userId, int $businessId): void
    {
        $this->get($userId, $businessId); // 소유권 검증
        $this->businesses->delete($businessId);
    }
}
