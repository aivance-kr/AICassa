<?php

use App\DTOs\BusinessData;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Services\BusinessService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 사업장 서비스 — CRUD + 멀티테넌시 스코프 검증.
 *
 * @internal
 */
final class BusinessServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = null;

    private BusinessService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BusinessService();
    }

    private function makeUser(string $email): int
    {
        $users    = new UserModel();
        $username = 'u' . substr(md5($email), 0, 8);
        $users->save(new User(['username' => $username, 'email' => $email, 'password' => 'secret12345']));

        return (int) $users->getInsertID();
    }

    public function testCreateAndGet(): void
    {
        $userId = $this->makeUser('a@test.com');
        $id     = $this->service->create($userId, BusinessData::fromArray([
            'name'             => '길동상회',
            'biz_reg_no'       => '123-45-67890',
            'is_manufacturing' => true,
        ]));

        $this->assertGreaterThan(0, $id);

        $business = $this->service->get($userId, $id);
        $this->assertSame('길동상회', $business['name']);
        $this->assertSame('1234567890', $business['biz_reg_no']); // 하이픈 정규화
        $this->assertSame(1, (int) $business['is_manufacturing']);
    }

    public function testListForUserIsScoped(): void
    {
        $userA = $this->makeUser('a@test.com');
        $userB = $this->makeUser('b@test.com');
        $this->service->create($userA, BusinessData::fromArray(['name' => 'A상회']));
        $this->service->create($userA, BusinessData::fromArray(['name' => 'A마트']));
        $this->service->create($userB, BusinessData::fromArray(['name' => 'B상회']));

        $this->assertCount(2, $this->service->listForUser($userA));
        $this->assertCount(1, $this->service->listForUser($userB));
    }

    public function testGetOtherUsersBusinessThrows(): void
    {
        $userA = $this->makeUser('a@test.com');
        $userB = $this->makeUser('b@test.com');
        $id    = $this->service->create($userA, BusinessData::fromArray(['name' => 'A상회']));

        $this->expectException(NotFoundException::class);
        $this->service->get($userB, $id);
    }

    public function testUpdate(): void
    {
        $userId = $this->makeUser('a@test.com');
        $id     = $this->service->create($userId, BusinessData::fromArray(['name' => '옛상호']));

        $updated = $this->service->update($userId, $id, BusinessData::fromArray(['name' => '새상호']));
        $this->assertSame('새상호', $updated['name']);
    }

    public function testDeleteThenGetThrows(): void
    {
        $userId = $this->makeUser('a@test.com');
        $id     = $this->service->create($userId, BusinessData::fromArray(['name' => '삭제상회']));

        $this->service->delete($userId, $id);

        $this->expectException(NotFoundException::class);
        $this->service->get($userId, $id);
    }

    public function testCreateWithEmptyNameThrowsValidation(): void
    {
        $userId = $this->makeUser('a@test.com');

        $this->expectException(ValidationException::class);
        $this->service->create($userId, BusinessData::fromArray(['name' => '']));
    }
}
