<?php

use App\Database\Seeds\AccountSeeder;
use App\Enums\ClassifierSource;
use App\Enums\DepreciationMethod;
use App\Libraries\AnthropicClient;
use App\Models\AssetModel;
use App\Models\BusinessModel;
use App\Services\AssetAdvisorService;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 자산 등록 어시스트 — 이력 우선·AI 분류(닫힌 어휘)·소액자산 판정·graceful 폴백.
 *
 * @internal
 */
final class AssetAdvisorServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $seed = AccountSeeder::class;
    protected $namespace;
    private int $userId;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();

        $users = new UserModel();
        $users->save(new User(['username' => 'owner1', 'email' => 'o@test.com', 'password' => 'secret12345']));
        $this->userId = (int) $users->getInsertID();

        $businesses = model(BusinessModel::class);
        $businesses->insert(['user_id' => $this->userId, 'name' => '내상회']);
        $this->businessId = (int) $businesses->getInsertID();
    }

    public function testHistoryReusesPastAssetClassification(): void
    {
        model(AssetModel::class)->insert([
            'business_id'         => $this->businessId, 'asset_type' => '비품', 'name' => '노트북',
            'acquired_at'         => '2023-05-01', 'acquisition_cost' => 1500000,
            'depreciation_method' => 'declining_balance',
        ]);

        $advice = (new AssetAdvisorService())->advise($this->businessId, '노트북');

        $this->assertSame(ClassifierSource::History, $advice->source);
        $this->assertSame('비품', $advice->assetType);
        $this->assertSame(DepreciationMethod::DecliningBalance, $advice->method);
    }

    public function testAiClassifiesWhenNoHistory(): void
    {
        $service = $this->serviceWithAi('{"asset_type":"기계장치","method":"정률법"}');

        $advice = $service->advise($this->businessId, '선반절삭기');

        $this->assertSame(ClassifierSource::Ai, $advice->source);
        $this->assertSame('기계장치', $advice->assetType);
        $this->assertSame(DepreciationMethod::DecliningBalance, $advice->method);
    }

    public function testAiClosedVocabularyRejection(): void
    {
        // 목록에 없는 자산분류는 폐기(null), 유효한 상각방법만 채택.
        $service = $this->serviceWithAi('{"asset_type":"우주선","method":"정액법"}');

        $advice = $service->advise($this->businessId, '알수없는물건');

        $this->assertNull($advice->assetType);
        $this->assertSame(DepreciationMethod::StraightLine, $advice->method);
    }

    public function testGracefulWhenAiFails(): void
    {
        $service = $this->serviceWithAiStatus(500);

        $advice = $service->advise($this->businessId, '선반절삭기');

        $this->assertSame(ClassifierSource::None, $advice->source);
        $this->assertNull($advice->assetType);
        $this->assertNull($advice->method);
    }

    public function testLowValueThresholdBoundary(): void
    {
        $service = new AssetAdvisorService(); // AI 미주입 — 결정적 판정만

        // 한도(기본 100만원) 이하 → 즉시비용 후보
        $under = $service->advise($this->businessId, '공구', 1000000);
        $this->assertTrue($under->isLowValue);
        $this->assertSame(1000000, $under->lowValueThreshold);

        // 한도 초과 → 아님
        $over = $service->advise($this->businessId, '공구', 1000001);
        $this->assertFalse($over->isLowValue);
    }

    public function testUsefulLifeNullWithoutIndustryCode(): void
    {
        // 사업장에 업종코드가 없으면 내용연수는 결정적으로 null.
        $advice = (new AssetAdvisorService())->advise($this->businessId, '비품');
        $this->assertNull($advice->usefulLife);
    }

    private function serviceWithAi(string $modelText): AssetAdvisorService
    {
        $body = (string) json_encode(['content' => [['type' => 'text', 'text' => $modelText]]]);
        $ai   = new AnthropicClient($this->stubHttp(200, $body), 'test-key', 'claude-sonnet-5', 5);

        return new AssetAdvisorService(ai: $ai);
    }

    private function serviceWithAiStatus(int $status): AssetAdvisorService
    {
        $ai = new AnthropicClient($this->stubHttp($status, '{"error":"boom"}'), 'test-key', 'claude-sonnet-5', 5);

        return new AssetAdvisorService(ai: $ai);
    }

    private function stubHttp(int $status, string $body): CURLRequest
    {
        return new class ($status, $body) extends CURLRequest {
            public function __construct(private int $stubStatus, private string $stubBody)
            {
                // 부모 생성자(네트워크 설정)는 건너뛴다.
            }

            public function request($method, string $url, array $options = []): ResponseInterface
            {
                $response = new Response(config('App'));
                $response->setStatusCode($this->stubStatus);
                $response->setBody($this->stubBody);

                return $response;
            }
        };
    }
}
