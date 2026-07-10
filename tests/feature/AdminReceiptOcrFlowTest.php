<?php

use App\Models\BusinessModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * Admin 영수증 판독 엔드포인트 — 인증·라우팅·에러 JSON·CSRF 토큰 반환 검증.
 * 실제 Claude 호출은 하지 않고, 파일 미첨부로 서비스 검증 예외 경로를 통과시킨다.
 *
 * @internal
 */
final class AdminReceiptOcrFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected $namespace;
    private User $user;
    private int $businessId;

    protected function setUp(): void
    {
        Services::reset();
        parent::setUp();

        $users = new UserModel();
        $users->save(new User(['username' => 'admin1', 'email' => 'admin@test.com', 'password' => 'secret12345']));
        $this->user = $users->findById($users->getInsertID());

        $businesses = model(BusinessModel::class);
        $businesses->insert(['user_id' => $this->user->id, 'name' => '내상회']);
        $this->businessId = (int) $businesses->getInsertID();
    }

    private function url(): string
    {
        return "/admin/businesses/{$this->businessId}/ledger/receipts/recognize";
    }

    public function testUnauthenticatedRedirected(): void
    {
        $this->post($this->url())->assertRedirect();
    }

    /**
     * 파일 미첨부 시 도메인 예외(422)가 상태코드로 매핑되고, 실패 응답에도 새 CSRF 토큰이 실린다.
     * (CSRF 재생성 환경에서 판독 실패 후 이어지는 저장 제출이 깨지지 않도록)
     */
    public function testMissingFileReturns422WithFreshCsrf(): void
    {
        $result = $this->actingAs($this->user)->post($this->url());

        $result->assertStatus(422);
        $json = json_decode((string) $result->getJSON(), true);
        $this->assertSame('VALIDATION_ERROR', $json['error']['code']);
        $this->assertArrayHasKey('csrf_hash', $json);
        $this->assertArrayHasKey('csrf_name', $json);
    }

    /**
     * 타 사용자 사업장으로는 판독 요청이 불가(소유권 검증 → 404).
     */
    public function testForeignBusinessReturns404(): void
    {
        $others = new UserModel();
        $others->save(new User(['username' => 'intruder', 'email' => 'x@test.com', 'password' => 'secret12345']));
        $intruder = $others->findById($others->getInsertID());

        $result = $this->actingAs($intruder)->post($this->url());

        $result->assertStatus(404);
        $json = json_decode((string) $result->getJSON(), true);
        $this->assertSame('NOT_FOUND', $json['error']['code']);
    }
}
