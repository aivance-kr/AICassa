<?php

use App\DTOs\PartnerData;
use App\Exceptions\AlreadyExistsException;
use App\Exceptions\NotFoundException;
use App\Models\BusinessModel;
use App\Models\UserModel;
use App\Services\PartnerService;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 거래처 서비스 — CRUD, 스코프, 중복 방지, CSV 일괄등록.
 *
 * @internal
 */
final class PartnerServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $namespace = null;

    private PartnerService $service;
    private int $userId;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PartnerService();

        $users = model(UserModel::class);
        $users->insert(['email' => 'owner@test.com', 'password_hash' => 'x']);
        $this->userId = (int) $users->getInsertID();

        $businesses = model(BusinessModel::class);
        $businesses->insert(['user_id' => $this->userId, 'name' => '내상회']);
        $this->businessId = (int) $businesses->getInsertID();
    }

    public function testCreateAndDuplicateBizRegNo(): void
    {
        $id = $this->service->create($this->userId, $this->businessId, PartnerData::fromArray([
            'name'       => '가나상사',
            'biz_reg_no' => '111-22-33333',
        ]));
        $this->assertGreaterThan(0, $id);

        // 동일 사업자번호(하이픈 무시) 재등록 → 중복 예외
        $this->expectException(AlreadyExistsException::class);
        $this->service->create($this->userId, $this->businessId, PartnerData::fromArray([
            'name'       => '가나상사(중복)',
            'biz_reg_no' => '1112233333',
        ]));
    }

    public function testOperationOnUnownedBusinessThrows(): void
    {
        $other  = model(UserModel::class);
        $other->insert(['email' => 'other@test.com', 'password_hash' => 'x']);
        $otherId = (int) $other->getInsertID();

        $this->expectException(NotFoundException::class);
        $this->service->listForBusiness($otherId, $this->businessId);
    }

    public function testImportFromRowsCountsCorrectly(): void
    {
        // 기존 1건 등록(DB 중복 케이스용)
        $this->service->create($this->userId, $this->businessId, PartnerData::fromArray([
            'name'       => '기존상사',
            'biz_reg_no' => '999-99-99999',
        ]));

        $rows = [
            ['name' => '정상상사', 'biz_reg_no' => '100-00-00001', 'phone' => '02-1111'], // OK
            ['name' => '무번호상사', 'biz_reg_no' => '', 'phone' => ''],                  // 사업자번호 없음 → skip
            ['name' => '', 'biz_reg_no' => '100-00-00002', 'phone' => ''],                // 상호 없음 → skip
            ['name' => '중복상사', 'biz_reg_no' => '100-00-00001', 'phone' => ''],        // 파일 내 중복 → skip
            ['name' => '기존중복', 'biz_reg_no' => '9999999999', 'phone' => ''],          // DB 중복 → skip
            ['name' => '정상상사2', 'biz_reg_no' => '100-00-00003', 'phone' => ''],       // OK
        ];

        $result = $this->service->importFromRows($this->userId, $this->businessId, $rows);

        $this->assertSame(2, $result->imported);
        $this->assertSame(4, $result->skipped);
        $this->assertCount(4, $result->errors);

        // 최종 DB에는 기존 1 + 신규 2 = 3건
        $this->assertCount(3, $this->service->listForBusiness($this->userId, $this->businessId));
    }

    public function testParseCsvSkipsHeader(): void
    {
        $csv = "거래처상호,사업자등록번호,연락처\n가나상사,111-22-33333,02-123-4567\n다라상사,222-33-44444,";
        $rows = $this->service->parseCsv($csv);

        $this->assertCount(2, $rows);
        $this->assertSame('가나상사', $rows[0]['name']);
        $this->assertSame('222-33-44444', $rows[1]['biz_reg_no']);
    }
}
