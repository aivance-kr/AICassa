<?php

use App\Database\Seeds\ReferenceDataSeeder;
use App\DTOs\AssetData;
use App\Enums\DepreciationMethod;
use App\Exceptions\NotFoundException;
use App\Models\BusinessModel;
use App\Models\LedgerEntryModel;
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

        $ledger     = new LedgerService();
        $depreEntry = fn (): array => array_values(array_filter(
            $ledger->listForBusiness($this->userId, $this->businessId, ['fiscal_year' => 2024]),
            static fn ($e) => $e['entry_type'] === 'expense', // 감가상각비(비용). 자산_구입 전표는 제외
        ));

        $expenses = $depreEntry();
        $this->assertCount(1, $expenses);
        $this->assertSame(2_000_000, (int) $expenses[0]['supply_amount']);
        $this->assertSame('감가상각비', $expenses[0]['account_name']);
        $this->assertSame(0, (int) $expenses[0]['vat']); // 감가상각비는 부가세 없음

        // 재반영해도 중복 생성되지 않음(멱등)
        $this->service->postDepreciation($this->userId, $this->businessId, $id, 2024);
        $this->assertCount(1, $depreEntry());
    }

    public function testOtherUserCannotAccess(): void
    {
        $other = new UserModel();
        $other->save(new User(['username' => 'other1', 'email' => 'x@test.com', 'password' => 'secret12345']));

        $this->expectException(NotFoundException::class);
        $this->service->listForBusiness((int) $other->getInsertID(), $this->businessId);
    }

    private function assetEntries(int $assetId, string $type): array
    {
        return model(LedgerEntryModel::class)
            ->where('business_id', $this->businessId)
            ->where('asset_id', $assetId)
            ->where('entry_type', $type)
            ->findAll();
    }

    public function testCreatePostsAssetPurchaseEntry(): void
    {
        $id = $this->service->create($this->userId, $this->businessId, new AssetData(
            assetType: '비품',
            name: '노트북',
            acquiredAt: '2024-01-01',
            acquisitionCost: 10_000_000,
        ));

        $entries = $this->assetEntries($id, 'asset_purchase');
        $this->assertCount(1, $entries);
        $this->assertSame(10_000_000, (int) $entries[0]['supply_amount']);
        $this->assertSame(0, (int) $entries[0]['vat']);
        $this->assertSame(2024, (int) $entries[0]['fiscal_year']);
    }

    public function testDisposalPostsNegativeEntryAndClearsWhenRemoved(): void
    {
        $id = $this->service->create($this->userId, $this->businessId, new AssetData(
            assetType: '차량운반구',
            name: '트럭',
            acquiredAt: '2022-01-01',
            acquisitionCost: 30_000_000,
        ));
        $this->assertCount(0, $this->assetEntries($id, 'asset_disposal'));

        // 처분 정보 입력 → 매각 전표(음수) 생성
        $this->service->update($this->userId, $this->businessId, $id, new AssetData(
            assetType: '차량운반구',
            name: '트럭',
            acquiredAt: '2022-01-01',
            acquisitionCost: 30_000_000,
            disposedAt: '2024-06-30',
            disposalAmount: 5_000_000,
        ));
        $disposal = $this->assetEntries($id, 'asset_disposal');
        $this->assertCount(1, $disposal);
        $this->assertSame(-5_000_000, (int) $disposal[0]['supply_amount']);

        // 처분 정보 제거 → 매각 전표 삭제
        $this->service->update($this->userId, $this->businessId, $id, new AssetData(
            assetType: '차량운반구',
            name: '트럭',
            acquiredAt: '2022-01-01',
            acquisitionCost: 30_000_000,
        ));
        $this->assertCount(0, $this->assetEntries($id, 'asset_disposal'));
    }

    public function testDeleteRemovesLinkedEntries(): void
    {
        $id = $this->service->create($this->userId, $this->businessId, new AssetData(
            assetType: '비품',
            name: '노트북',
            acquiredAt: '2024-01-01',
            acquisitionCost: 10_000_000,
            depreciationMethod: DepreciationMethod::StraightLine,
            usefulLife: 5,
        ));
        $this->service->postDepreciation($this->userId, $this->businessId, $id, 2024);

        // 구입 전표 + 감가상각 전표가 자산에 연동돼 있음
        $linked = model(LedgerEntryModel::class)
            ->where('business_id', $this->businessId)->where('asset_id', $id)->findAll();
        $this->assertGreaterThanOrEqual(2, count($linked));

        $this->service->delete($this->userId, $this->businessId, $id);

        $remaining = model(LedgerEntryModel::class)
            ->where('business_id', $this->businessId)->where('asset_id', $id)->findAll();
        $this->assertCount(0, $remaining);
    }
}
