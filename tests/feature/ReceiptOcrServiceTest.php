<?php

use App\Database\Seeds\AccountSeeder;
use App\Exceptions\NotFoundException;
use App\Exceptions\OcrProcessingException;
use App\Models\BusinessModel;
use App\Models\PartnerModel;
use App\Services\ReceiptOcrService;
use CodeIgniter\HTTP\CURLRequest;
use CodeIgniter\HTTP\Files\UploadedFile;
use CodeIgniter\HTTP\Response;
use CodeIgniter\HTTP\ResponseInterface;
use CodeIgniter\Shield\Entities\User;
use CodeIgniter\Shield\Models\UserModel;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * 영수증 AI 판독 서비스 — 소유권·파일검증·계정/거래처 매핑·외부응답 파싱 검증.
 * Claude HTTP 호출은 스텁으로 대체하고, 업로드 파일은 테스트 더블로 주입한다.
 *
 * @internal
 */
final class ReceiptOcrServiceTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $seed = AccountSeeder::class;
    protected $namespace;
    private int $userId;
    private int $businessId;

    protected function setUp(): void
    {
        parent::setUp();

        // env('ANTHROPIC_API_KEY') 가 채워져 있어야 호출 경로까지 진행된다.
        // (.env 의 빈 값이 $_ENV 를 선점하므로 세 곳 모두 덮어쓴다)
        putenv('ANTHROPIC_API_KEY=test-key');
        $_ENV['ANTHROPIC_API_KEY']    = 'test-key';
        $_SERVER['ANTHROPIC_API_KEY'] = 'test-key';

        $users = new UserModel();
        $users->save(new User(['username' => 'ocr1', 'email' => 'ocr@test.com', 'password' => 'secret12345']));
        $this->userId = (int) $users->getInsertID();

        $businesses = model(BusinessModel::class);
        $businesses->insert(['user_id' => $this->userId, 'name' => '판독상회']);
        $this->businessId = (int) $businesses->getInsertID();
    }

    protected function tearDown(): void
    {
        // 판독 성공 시 원본 사진은 첨부용으로 보존되므로 테스트 후 정리한다.
        array_map('unlink', glob(WRITEPATH . 'uploads/receipts/ocrtest_*') ?: []);
        parent::tearDown();
    }

    /**
     * 정상 판독: 계정과목명·거래처명이 기존 등록건과 일치하면 id 로 매핑된다.
     */
    public function testRecognizeMapsAccountAndPartner(): void
    {
        model(PartnerModel::class)->insert([
            'business_id' => $this->businessId,
            'name'        => '오피스마트',
        ]);

        $service = new ReceiptOcrService(http: $this->stubClient([
            'entry_type'    => 'expense',
            'entry_date'    => '2026-03-15',
            'description'   => '사무용품',
            'supply_amount' => 45000,
            'evidence_type' => 'tax_invoice',
            'account_name'  => '소모품비',
            'partner_name'  => '오피스마트',
        ]));

        $result = $service->recognize($this->userId, $this->businessId, $this->fakeFile());

        $this->assertSame('expense', $result['entry_type']);
        $this->assertSame(45000, $result['supply_amount']);
        $this->assertSame('tax_invoice', $result['evidence_type']);
        $this->assertNotNull($result['account_id']);   // '소모품비' 매칭
        $this->assertNotNull($result['partner_id']);   // '오피스마트' 매칭
        $this->assertStringStartsWith('receipts/', $result['receipt_path']);
    }

    /**
     * 미등록 거래처는 id 를 채우지 않고 이름(hint)만 남긴다.
     */
    public function testRecognizeLeavesUnknownPartnerUnmatched(): void
    {
        $service = new ReceiptOcrService(http: $this->stubClient([
            'entry_type'    => 'expense',
            'entry_date'    => '2026-03-15',
            'description'   => '점심',
            'supply_amount' => 12000,
            'evidence_type' => 'cash_receipt',
            'account_name'  => null,
            'partner_name'  => '없는식당',
        ]));

        $result = $service->recognize($this->userId, $this->businessId, $this->fakeFile());

        $this->assertNull($result['partner_id']);
        $this->assertSame('없는식당', $result['partner_name']);
        $this->assertNull($result['account_id']);
    }

    /**
     * 타 사용자 사업장은 판독 불가.
     */
    public function testRecognizeRejectsForeignBusiness(): void
    {
        $other = new UserModel();
        $other->save(new User(['username' => 'ocr2', 'email' => 'ocr2@test.com', 'password' => 'secret12345']));

        $service = new ReceiptOcrService(http: $this->stubClient([]));

        $this->expectException(NotFoundException::class);
        $service->recognize((int) $other->getInsertID(), $this->businessId, $this->fakeFile());
    }

    /**
     * Claude 가 JSON 이 아닌 응답을 주면 판독 실패로 전환된다.
     */
    public function testRecognizeThrowsOnUnparsableResponse(): void
    {
        $service = new ReceiptOcrService(http: $this->stubClientRaw('죄송합니다, 이미지를 읽을 수 없습니다.'));

        $this->expectException(OcrProcessingException::class);
        $service->recognize($this->userId, $this->businessId, $this->fakeFile());
    }

    /**
     * Anthropic API 가 200 이 아니면 판독 실패.
     */
    public function testRecognizeThrowsOnApiError(): void
    {
        $service = new ReceiptOcrService(http: $this->stubClientStatus(429));

        $this->expectException(OcrProcessingException::class);
        $service->recognize($this->userId, $this->businessId, $this->fakeFile());
    }

    /**
     * Anthropic 응답(JSON 텍스트 블록)을 흉내내는 CURLRequest 스텁.
     *
     * @param array<string, mixed> $ocrJson
     */
    private function stubClient(array $ocrJson): CURLRequest
    {
        return $this->stubClientRaw(json_encode($ocrJson));
    }

    /**
     * 모델 출력 텍스트를 그대로 담은 200 응답 스텁.
     */
    private function stubClientRaw(string $modelText): CURLRequest
    {
        $body = json_encode(['content' => [['type' => 'text', 'text' => $modelText]]]);

        return $this->makeClient(200, (string) $body);
    }

    private function stubClientStatus(int $status): CURLRequest
    {
        return $this->makeClient($status, '{"error":"rate_limited"}');
    }

    private function makeClient(int $status, string $body): CURLRequest
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

    /**
     * is_uploaded_file 검사를 우회하는 업로드 파일 더블.
     */
    private function fakeFile(): UploadedFile
    {
        return new class ('', 'receipt.png') extends UploadedFile {
            public function __construct(
                string $path,
                string $originalName,
                ?string $mimeType = null,
                ?int $size = null,
                ?int $error = null,
                ?string $clientPath = null,
            ) {
                // 부모 생성자 건너뜀 — 실제 업로드가 아니므로.
            }

            public function isValid(): bool
            {
                return true;
            }

            public function hasMoved(): bool
            {
                return false;
            }

            public function getSize(?string $unit = 'b'): int
            {
                return 1024;
            }

            public function getMimeType(): string
            {
                return 'image/png';
            }

            public function store(?string $folderName = null, ?string $fileName = null): string
            {
                $dir = WRITEPATH . 'uploads/receipts';
                if (! is_dir($dir)) {
                    mkdir($dir, 0777, true);
                }
                $name = 'ocrtest_' . bin2hex(random_bytes(4)) . '.png';
                file_put_contents($dir . '/' . $name, 'dummy-image-bytes');

                return 'receipts/' . $name;
            }
        };
    }
}
