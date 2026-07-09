<?php

use App\Database\Seeds\ReferenceDataSeeder;
use App\DTOs\AssetData;
use App\Enums\DepreciationMethod;
use App\Exceptions\NotFoundException;
use App\Models\BusinessModel;
use App\Services\AssetService;
use App\Services\LedgerService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 자산대장·감가상각 서비스 — CRUD, 상각률 자동조회, 스케줄, 장부 반영.
 *
 * @internal
 */
final class AssetServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $seed = ReferenceDataSeeder::class; // 계정과목 + 상각률
    protected $namespace;
    private AssetService $service;
    private int $userId;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AssetService();

        $users = new UserModel();
        $users->save(new User(['username' => 'owner1', 'email' => 'o@test.com', 'password' => 'secret12345']));
        $this->userId = (int) $users->getInsertID();

        $businesses = model(BusinessModel::class);
        $businesses->insert(['user_id' => $this->userId, 'name' => '내상회']);
        $this->businessId = (int) $businesses->getInsertID();
    }

    public function testCreateResolvesRate(): void
    {
        $id = $this->service->create($this->userId, $this->businessId, new AssetData(
            assetType: '비품',
            name: '노트북',
            acquiredAt: '2024-01-01',
            acquisitionCost: 10_000_000,
            depreciationMethod: DepreciationMethod::StraightLine,
            usefulLife: 5,
        ));
        $asset = $this->service->get($this->userId, $this->businessId, $id);

        // 내용연수 5 · 정액법 → 상각률 0.2
        $this->assertEqualsWithDelta(0.2, (float) $asset['depreciation_rate'], 0.0001);
    }

    public function testScheduleStraightLine(): void
    {
        $id = $this->service->create($this->userId, $this->businessId, new AssetData(
            assetType: '비품',
            name: '노트북',
            acquiredAt: '2024-01-01',
            acquisitionCost: 10_000_000,
            depreciationMethod: DepreciationMethod::StraightLine,
            usefulLife: 5,
        ));

        $schedule = $this->service->schedule($this->userId, $this->businessId, $id);

        $this->assertSame(2_000_000, $schedule[0]['depreciation']);           // 1차년 200만
        $this->assertSame(1000, $schedule[array_key_last($schedule)]['book_value']); // 비망가액 1,000
    }

    public function testScheduleEmptyWhenNoDepreciationInfo(): void
    {
        $id = $this->service->create($this->userId, $this->businessId, new AssetData(
            assetType: '기타',
            name: '상각정보없음',
            acquiredAt: '2024-01-01',
            acquisitionCost: 1_000_000,
        ));

        $this->assertSame([], $this->service->schedule($this->userId, $this->businessId, $id));
    }

    public function testPostDepreciationCreatesExpenseAndIsIdempotent(): void
    {
        $id = $this->service->create($this->userId, $this->businessId, new AssetData(
            assetType: '비품',
            name: '노트북',
            acquiredAt: '2024-01-01',
            acquisitionCost: 10_000_000,
            depreciationMethod: DepreciationMethod::StraightLine,
            usefulLife: 5,
        ));

        $entryId = $this->service->postDepreciation($this->userId, $this->businessId, $id, 2024);
        $this->assertNotNull($entryId);

        $ledger  = new LedgerService();
        $entries = $ledger->listForBusiness($this->userId, $this->businessId, ['fiscal_year' => 2024]);
        $this->assertCount(1, $entries);
        $this->assertSame(2_000_000, (int) $entries[0]['supply_amount']);
        $this->assertSame('감가상각비', $entries[0]['account_name']);
        $this->assertSame(0, (int) $entries[0]['vat']); // 감가상각비는 부가세 없음

        // 재반영해도 중복 생성되지 않음(멱등)
        $this->service->postDepreciation($this->userId, $this->businessId, $id, 2024);
        $this->assertCount(1, $ledger->listForBusiness($this->userId, $this->businessId, ['fiscal_year' => 2024]));
    }

    public function testOtherUserCannotAccess(): void
    {
        $other = new UserModel();
        $other->save(new User(['username' => 'other1', 'email' => 'x@test.com', 'password' => 'secret12345']));

        $this->expectException(NotFoundException::class);
        $this->service->listForBusiness((int) $other->getInsertID(), $this->businessId);
    }
}
