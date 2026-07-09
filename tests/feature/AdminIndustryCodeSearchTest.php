<?php

use App\Models\IndustryCodeModel;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Shield\Test\AuthenticationTesting;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;

/**
 * 업종코드 검색(자동완성) — 모델 검색 로직 + Admin 엔드포인트 인증/응답 검증.
 *
 * 주의: 이 환경의 출력 핸들러가 비ASCII를 엔티티로 변환하므로,
 * 엔드포인트 단언은 ASCII 코드/구조로만 하고 한글 매칭은 모델로 직접 검증한다.
 *
 * @internal
 */
final class AdminIndustryCodeSearchTest extends CIUnitTestCase
{
    use DatabaseTestTrait;
    use FeatureTestTrait;
    use AuthenticationTesting;

    protected $namespace;

    protected function setUp(): void
    {
        Services::reset();
        parent::setUp();

        // 검색 대상 참조 데이터 소량 주입(전체 시드는 불필요·느림)
        $this->db->table('industry_codes')->insertBatch([
            ['code' => '552101', 'name' => '한식 음식점업', 'useful_life' => 5],
            ['code' => '552102', 'name' => '중식 음식점업', 'useful_life' => 5],
            ['code' => '620100', 'name' => '컴퓨터 프로그래밍 서비스업', 'useful_life' => null],
        ]);
    }

    private function makeUser(): User
    {
        $users = new UserModel();
        $users->save(new User(['username' => 'admin1', 'email' => 'admin@test.com', 'password' => 'secret12345']));

        return $users->findById($users->getInsertID());
    }

    public function testSearchByCodePrefixMatches(): void
    {
        $rows = model(IndustryCodeModel::class)->search('5521');
        $this->assertCount(2, $rows);
        $this->assertSame('552101', $rows[0]['code']);
    }

    public function testSearchByNameKeywordMatches(): void
    {
        $rows = model(IndustryCodeModel::class)->search('음식점');
        $this->assertCount(2, $rows);
    }

    public function testSearchEmptyKeywordReturnsEmpty(): void
    {
        $this->assertSame([], model(IndustryCodeModel::class)->search('   '));
    }

    public function testExistsByCode(): void
    {
        $model = model(IndustryCodeModel::class);
        $this->assertTrue($model->existsByCode('552101'));
        $this->assertFalse($model->existsByCode('999999'));
        $this->assertFalse($model->existsByCode(''));
    }

    public function testEndpointRequiresAuth(): void
    {
        $this->get('/admin/industry-codes/search?q=5521')->assertRedirect();
    }

    public function testEndpointReturnsJsonForAuthenticatedUser(): void
    {
        $result = $this->actingAs($this->makeUser())
            ->get('/admin/industry-codes/search?q=552101');

        $result->assertOK();
        $json = json_decode((string) $result->getJSON(), true);
        $this->assertTrue($json['exists']);
        $this->assertSame('552101', $json['results'][0]['code']);
    }

    public function testEndpointExistsFalseForUnknownCode(): void
    {
        $result = $this->actingAs($this->makeUser())
            ->get('/admin/industry-codes/search?q=999999');

        $result->assertOK();
        $json = json_decode((string) $result->getJSON(), true);
        $this->assertFalse($json['exists']);
        $this->assertSame([], $json['results']);
    }
}
