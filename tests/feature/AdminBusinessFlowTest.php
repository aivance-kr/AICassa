<?php

use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;

/**
 * Admin 사업장 화면 — 인증·라우팅·컨트롤러 동작 통합 검증.
 *
 * 주의: 이 환경의 PHP 출력 핸들러가 응답 본문의 비ASCII를 HTML 엔티티로
 * 변환하므로, 한글 문자열 대신 ASCII 마커/DB 상태로 단언한다.
 *
 * @internal
 */
final class AdminBusinessFlowTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected $namespace = null;

    private function makeUser(): User
    {
        $users = new UserModel();
        $users->save(new User(['username' => 'admin1', 'email' => 'admin@test.com', 'password' => 'secret12345']));

        return $users->findById($users->getInsertID());
    }

    public function testUnauthenticatedIsRedirected(): void
    {
        $this->get('/admin/businesses')->assertRedirect();
    }

    public function testAuthenticatedCanViewList(): void
    {
        $result = $this->actingAs($this->makeUser())->get('/admin/businesses');
        $result->assertOK();
        $result->assertSee('/admin/businesses/new'); // 등록 버튼 링크(ASCII)
    }

    public function testCreatePersistsScopedToUser(): void
    {
        $user = $this->makeUser();

        $this->actingAs($user)
            ->post('/admin/businesses', ['name' => '테스트상회', 'is_manufacturing' => '1'])
            ->assertRedirectTo('/admin/businesses');

        // 컨트롤러 경로로 저장된 결과를 서비스로 검증
        $list = service('businessService')->listForUser((int) $user->id);
        $this->assertCount(1, $list);
        $this->assertSame('테스트상회', $list[0]['name']);
        $this->assertSame(1, (int) $list[0]['is_manufacturing']);
    }

    public function testCreateWithEmptyNameRedirectsBack(): void
    {
        $result = $this->actingAs($this->makeUser())->post('/admin/businesses', ['name' => '']);
        $result->assertRedirect();

        $this->assertCount(0, service('businessService')->listForUser((int) auth()->id()));
    }

    public function testCannotEditOtherUsersBusiness(): void
    {
        $owner = $this->makeUser();
        $id    = service('businessService')->create((int) $owner->id, \App\DTOs\BusinessData::fromArray(['name' => '내상회']));

        $intruder = new UserModel();
        $intruder->save(new User(['username' => 'intruder', 'email' => 'intruder@test.com', 'password' => 'secret12345']));
        $intruderUser = $intruder->findById($intruder->getInsertID());

        // 타 사용자 사업장 수정 폼 접근 → 목록으로 리다이렉트
        $this->actingAs($intruderUser)->get("/admin/businesses/{$id}/edit")->assertRedirect();
    }
}
