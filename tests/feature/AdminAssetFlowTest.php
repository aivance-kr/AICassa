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
        $this->assertCount(1, $entries);
        $this->assertSame(2_000_000, (int) $entries[0]['supply_amount']);
        $this->assertSame('감가상각비', $entries[0]['account_name']);
    }
}
