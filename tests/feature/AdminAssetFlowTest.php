<?php

use App\Database\Seeds\ReferenceDataSeeder;
use App\Models\BusinessModel;
use App\Services\AssetService;
use App\Services\LedgerService;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Admin 자산대장 — 인증·라우팅·등록·감가상각비 장부 반영 검증.
 *
 * @internal
 */
final class AdminAssetFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected $seed = ReferenceDataSeeder::class;
    protected $namespace;
    private User $user;
    private int $businessId;

    protected function setUp(): void
    {
        Services::reset(); // 인증 세션 격리
        parent::setUp();

        $users = new UserModel();
        $users->save(new User(['username' => 'admin1', 'email' => 'admin@test.com', 'password' => 'secret12345']));
        $this->user = $users->findById($users->getInsertID());

        $businesses = model(BusinessModel::class);
        $businesses->insert(['user_id' => $this->user->id, 'name' => '내상회']);
        $this->businessId = (int) $businesses->getInsertID();
    }

    public function testUnauthenticatedRedirected(): void
    {
        $this->get("/admin/businesses/{$this->businessId}/assets")->assertRedirect();
    }

    public function testFormShowsIndustryBasedUsefulLifeHint(): void
    {
        // 업종코드 143107 → 내용연수 10 (표준산업분류연계표)
        model(BusinessModel::class)->update($this->businessId, ['industry_code' => '143107']);

        $res = $this->actingAs($this->user)->get("/admin/businesses/{$this->businessId}/assets/new");
        $res->assertOK();
        $res->assertSee('업종 기준 자동');
        $res->assertSee('143107');
    }

    public function testCreateResolvesUsefulLifeFromIndustryWhenOmitted(): void
    {
        model(BusinessModel::class)->update($this->businessId, ['industry_code' => '143107']); // 내용연수 10

        // 내용연수 미입력으로 등록 → 업종 기준 자동 적용
        $this->actingAs($this->user)->post("/admin/businesses/{$this->businessId}/assets", [
            'asset_type'          => '기계장치',
            'name'                => '채굴기',
            'acquired_at'         => '2024-01-01',
            'acquisition_cost'    => '10,000,000',
            'depreciation_method' => 'straight_line',
            'useful_life'         => '',
        ])->assertRedirectTo("/admin/businesses/{$this->businessId}/assets");

        $assets = (new AssetService())->listForBusiness((int) $this->user->id, $this->businessId);
        $this->assertSame(10, (int) $assets[0]['useful_life']);
        $this->assertEqualsWithDelta(0.1, (float) $assets[0]['depreciation_rate'], 0.0001); // 정액법 10년 → 0.1
    }

    public function testCreateAndScheduleThenPost(): void
    {
        // 자산 등록(정액법 5년)
        $this->actingAs($this->user)->post("/admin/businesses/{$this->businessId}/assets", [
            'asset_type'          => '비품',
            'name'                => '노트북',
            'acquired_at'         => '2024-01-01',
            'acquisition_cost'    => '10,000,000',
            'depreciation_method' => 'straight_line',
            'useful_life'         => '5',
        ])->assertRedirectTo("/admin/businesses/{$this->businessId}/assets");

        $assets = (new AssetService())->listForBusiness((int) $this->user->id, $this->businessId);
        $this->assertCount(1, $assets);
        $assetId = (int) $assets[0]['id'];

        // 스케줄 화면 렌더(1차년 200만)
        $res = $this->actingAs($this->user)->get("/admin/businesses/{$this->businessId}/assets/{$assetId}/schedule");
        $res->assertOK();
        $res->assertSee('2,000,000');

        // 2024년 감가상각비 장부 반영
        $this->actingAs($this->user)
            ->post("/admin/businesses/{$this->businessId}/assets/{$assetId}/depreciation", ['year' => '2024'])
            ->assertRedirect();

        $entries = (new LedgerService())->listForBusiness((int) $this->user->id, $this->businessId, ['fiscal_year' => 2024]);
        // 감가상각비(비용) 전표만 확인 — 자산 등록 시 자동 생성된 자산_구입 전표는 제외
        $expenses = array_values(array_filter($entries, static fn ($e) => $e['entry_type'] === 'expense'));
        $this->assertCount(1, $expenses);
        $this->assertSame(2_000_000, (int) $expenses[0]['supply_amount']);
        $this->assertSame('감가상각비', $expenses[0]['account_name']);

        // 자산_구입 전표도 장부에 자동 반영되었는지 확인
        $purchase = array_values(array_filter($entries, static fn ($e) => $e['entry_type'] === 'asset_purchase'));
        $this->assertCount(1, $purchase);
        $this->assertSame(10_000_000, (int) $purchase[0]['supply_amount']);
    }
}
