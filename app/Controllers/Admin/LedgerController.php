<?php

namespace App\Controllers\Admin;

use App\DTOs\LedgerData;
use App\Enums\AccountCategory;
use App\Enums\EntryType;
use App\Enums\EvidenceType;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\AccountModel;
use App\Models\PartnerModel;
use App\Services\ExcelColumnMapperService;
use CodeIgniter\HTTP\RedirectResponse;
use RuntimeException;

/**
 * 장부 관리(Admin). 사업장 스코프 하위에서 개별 입력·수정·삭제·복사.
 */
class LedgerController extends BaseAdminController
{
    /**
     * 장부 목록(필터 + 합계).
     */
    public function index(int $businessId): RedirectResponse|string
    {
        $business = $this->business($businessId);
        if ($business === null) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        // 화이트리스트 필터만 읽는다(자연어 검색 결과도 이 파라미터로 전달된다).
        $filters = [
            'fiscal_year' => $this->request->getGet('fiscal_year'),
            'entry_type'  => $this->request->getGet('entry_type'),
            'date_from'   => $this->request->getGet('date_from'),
            'date_to'     => $this->request->getGet('date_to'),
            'account_id'  => $this->request->getGet('account_id'),
            'partner_id'  => $this->request->getGet('partner_id'),
            'amount_min'  => $this->request->getGet('amount_min'),
            'amount_max'  => $this->request->getGet('amount_max'),
            'keyword'     => $this->request->getGet('keyword'),
        ];

        $ledger  = service('ledgerService');
        $entries = $ledger->listForBusiness($this->authUserId(), $businessId, $filters);

        return $this->render('admin/ledger/index', [
            'business'      => $business,
            'entries'       => $entries,
            'summary'       => $ledger->summarize($entries),
            'years'         => $ledger->availableYears($this->authUserId(), $businessId),
            'filters'       => $filters,
            'filterSummary' => $ledger->filterSummary($businessId, $filters),
            'query'         => (string) $this->request->getGet('q'),
        ]);
    }

    /**
     * 입력 폼.
     */
    public function new(int $businessId): RedirectResponse|string
    {
        $business = $this->business($businessId);
        if ($business === null) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        return $this->render('admin/ledger/form', $this->formData($business, null));
    }

    /**
     * 입력 처리.
     */
    public function create(int $businessId): RedirectResponse
    {
        if (! $this->validate($this->rules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            service('ledgerService')->create(
                $this->authUserId(),
                $businessId,
                LedgerData::fromArray($this->request->getPost()),
            );
        } catch (NotFoundException) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->with('errors', $e->errors());
        }

        return redirect()->to("/admin/businesses/{$businessId}/ledger")
            ->with('message', '거래가 장부에 입력되었습니다.');
    }

    /**
     * 수정 폼.
     */
    public function edit(int $businessId, int $entryId): RedirectResponse|string
    {
        $business = $this->business($businessId);
        if ($business === null) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        try {
            $entry = service('ledgerService')->get($this->authUserId(), $businessId, $entryId);
        } catch (NotFoundException) {
            return redirect()->to("/admin/businesses/{$businessId}/ledger")
                ->with('error', '장부 항목을 찾을 수 없습니다.');
        }

        if ($guard = $this->assetEntryGuard($businessId, $entry)) {
            return $guard;
        }

        return $this->render('admin/ledger/form', $this->formData($business, $entry));
    }

    /**
     * 수정 처리.
     */
    public function update(int $businessId, int $entryId): RedirectResponse
    {
        try {
            $entry = service('ledgerService')->get($this->authUserId(), $businessId, $entryId);
        } catch (NotFoundException) {
            return redirect()->to("/admin/businesses/{$businessId}/ledger")
                ->with('error', '장부 항목을 찾을 수 없습니다.');
        }
        if ($guard = $this->assetEntryGuard($businessId, $entry)) {
            return $guard;
        }

        if (! $this->validate($this->rules())) {
            return redirect()->back()->withInput()->with('errors', $this->validator->getErrors());
        }

        try {
            service('ledgerService')->update(
                $this->authUserId(),
                $businessId,
                $entryId,
                LedgerData::fromArray($this->request->getPost()),
            );
        } catch (NotFoundException) {
            return redirect()->to("/admin/businesses/{$businessId}/ledger")
                ->with('error', '장부 항목을 찾을 수 없습니다.');
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->with('errors', $e->errors());
        }

        return redirect()->to("/admin/businesses/{$businessId}/ledger")
            ->with('message', '거래가 수정되었습니다.');
    }

    /**
     * 삭제 처리.
     */
    public function delete(int $businessId, int $entryId): RedirectResponse
    {
        try {
            $entry = service('ledgerService')->get($this->authUserId(), $businessId, $entryId);
        } catch (NotFoundException) {
            return redirect()->to("/admin/businesses/{$businessId}/ledger")
                ->with('error', '장부 항목을 찾을 수 없습니다.');
        }
        if ($guard = $this->assetEntryGuard($businessId, $entry)) {
            return $guard;
        }

        service('ledgerService')->delete($this->authUserId(), $businessId, $entryId);

        return redirect()->to("/admin/businesses/{$businessId}/ledger")
            ->with('message', '거래가 삭제되었습니다.');
    }

    /**
     * 복사 입력 — 항목을 복제하고 수정 폼으로 이동(반복 거래용).
     */
    public function copy(int $businessId, int $entryId): RedirectResponse
    {
        try {
            $entry = service('ledgerService')->get($this->authUserId(), $businessId, $entryId);
        } catch (NotFoundException) {
            return redirect()->to("/admin/businesses/{$businessId}/ledger")
                ->with('error', '장부 항목을 찾을 수 없습니다.');
        }
        if ($guard = $this->assetEntryGuard($businessId, $entry)) {
            return $guard;
        }

        $newId = service('ledgerService')->copy($this->authUserId(), $businessId, $entryId);

        return redirect()->to("/admin/businesses/{$businessId}/ledger/{$newId}/edit")
            ->with('message', '복사되었습니다. 내용을 수정하세요.');
    }

    /**
     * 자산_구입/자산_매각 전표는 장부에서 직접 수정·삭제·복사 불가(자산대장에서 관리).
     *
     * @param array<string, mixed> $entry
     */
    private function assetEntryGuard(int $businessId, array $entry): ?RedirectResponse
    {
        if (in_array($entry['entry_type'], ['asset_purchase', 'asset_disposal'], true)) {
            return redirect()->to("/admin/businesses/{$businessId}/assets")
                ->with('error', '자산 연동 전표는 자산대장에서 관리하세요.');
        }

        return null;
    }

    /**
     * 엑셀·CSV 업로드 폼.
     */
    public function importForm(int $businessId): RedirectResponse|string
    {
        $business = $this->business($businessId);
        if ($business === null) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        return $this->render('admin/ledger/import', ['business' => $business, 'result' => null]);
    }

    /**
     * ① 업로드 처리 — 파일을 임시 저장하고 컬럼 자동매핑을 분석해 확인 화면을 띄운다(아직 커밋 안 함).
     */
    public function import(int $businessId): RedirectResponse|string
    {
        $business = $this->business($businessId);
        if ($business === null) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        $file = $this->request->getFile('csv');
        $ext  = $file !== null ? strtolower($file->getExtension()) : '';
        if ($file === null || ! $file->isValid() || ! in_array($ext, ['csv', 'txt', 'xls', 'xlsx'], true)) {
            return redirect()->back()->with('error', 'CSV 또는 엑셀(xls, xlsx) 파일을 선택하세요.');
        }

        $this->cleanupStaleImports();

        // 서버 임시 저장. 원본 경로는 세션에만 보관해 다른 사용자의 교차접근을 막는다.
        $token    = bin2hex(random_bytes(16));
        $relative = $file->store('imports', $token . '.' . $ext); // writable/uploads/imports/{token}.{ext}
        $absolute = WRITEPATH . 'uploads/' . $relative;

        try {
            $grid = service('spreadsheetReader')->read($absolute, $ext);
        } catch (RuntimeException $e) {
            @unlink($absolute);

            return redirect()->back()->with('error', $e->getMessage());
        }

        if (count($grid) < 2) {
            @unlink($absolute);

            return redirect()->back()->with('error', '헤더 행과 최소 1개의 데이터 행이 필요합니다.');
        }

        session()->set($this->importSessionKey($token), [
            'rel'      => $relative,
            'ext'      => $ext,
            'user'     => $this->authUserId(),
            'business' => $businessId,
            'name'     => $file->getClientName(),
        ]);

        $analysis = service('excelColumnMapperService')->analyze($grid);

        return $this->renderMapping(
            $business,
            $token,
            $file->getClientName(),
            $analysis->headers,
            $analysis->samples,
            $analysis->assignments,
        );
    }

    /**
     * ② 매핑 확인 처리 — 사용자가 확정한 컬럼 매핑으로 일괄 등록한다.
     */
    public function importConfirm(int $businessId): RedirectResponse|string
    {
        $business = $this->business($businessId);
        if ($business === null) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        $importUrl = "/admin/businesses/{$businessId}/ledger/import";
        $token     = (string) $this->request->getPost('token');
        $rec       = session()->get($this->importSessionKey($token));
        if (! is_array($rec) || (int) $rec['user'] !== $this->authUserId() || (int) $rec['business'] !== $businessId) {
            return redirect()->to($importUrl)->with('error', '업로드 세션이 만료되었습니다. 파일을 다시 올려주세요.');
        }

        $absolute = WRITEPATH . 'uploads/' . $rec['rel'];
        if (! is_file($absolute)) {
            session()->remove($this->importSessionKey($token));

            return redirect()->to($importUrl)->with('error', '업로드 파일을 찾을 수 없습니다. 다시 올려주세요.');
        }

        try {
            $grid = service('spreadsheetReader')->read($absolute, (string) $rec['ext']);
        } catch (RuntimeException $e) {
            return redirect()->to($importUrl)->with('error', $e->getMessage());
        }

        $mapper   = service('excelColumnMapperService');
        $resolved = $mapper->resolvePostedMapping((array) $this->request->getPost('mapping'));

        // 표준필드 중복·필수누락 시 확인 화면을 사용자 선택 상태로 다시 띄운다.
        if ($resolved['error'] !== null) {
            $split = $mapper->splitGrid($grid);

            return $this->renderMapping(
                $business,
                $token,
                (string) $rec['name'],
                $split['headers'],
                $split['samples'],
                $resolved['assignments'],
                $resolved['error'],
            );
        }

        $rows   = service('ledgerImportService')->parseWithMapping(array_slice($grid, 1), $resolved['mapping']);
        $result = service('ledgerImportService')->import($this->authUserId(), $businessId, $rows);

        // 정리: 임시 파일·세션 제거
        @unlink($absolute);
        session()->remove($this->importSessionKey($token));

        return $this->render('admin/ledger/import', ['business' => $business, 'result' => $result]);
    }

    /**
     * 매핑 확인 화면을 렌더한다.
     *
     * @param array<string, mixed>    $business
     * @param list<string>            $headers
     * @param list<list<string>>      $samples
     * @param array<int, string|null> $assignments 열index → 표준필드키(제안/사용자선택)
     */
    private function renderMapping(
        array $business,
        string $token,
        string $sourceName,
        array $headers,
        array $samples,
        array $assignments,
        ?string $error = null,
    ): string {
        return $this->render('admin/ledger/import_mapping', [
            'business'    => $business,
            'token'       => $token,
            'sourceName'  => $sourceName,
            'headers'     => $headers,
            'samples'     => $samples,
            'assignments' => $assignments,
            'fields'      => ExcelColumnMapperService::fields(),
            'error'       => $error,
        ]);
    }

    /**
     * 임포트 임시파일 세션 키. 토큰 형식(32 hex)을 방어적으로 검증한다.
     */
    private function importSessionKey(string $token): string
    {
        $safe = preg_match('/^[a-f0-9]{32}$/', $token) === 1 ? $token : 'invalid';

        return 'ledger_import_' . $safe;
    }

    /**
     * 미리보기만 하고 확정하지 않아 남은 임시 업로드를 정리한다(TTL 경과분).
     * 세션 기반 토큰 만료를 보완해 writable/uploads/imports 무한 증가를 막는다.
     */
    private function cleanupStaleImports(): void
    {
        $dir = WRITEPATH . 'uploads/imports';
        if (! is_dir($dir)) {
            return;
        }

        $ttl = 3600; // 1시간 경과한 임시 업로드는 제거
        foreach (glob($dir . '/*') ?: [] as $path) {
            if (is_file($path) && (time() - (int) filemtime($path)) > $ttl) {
                @unlink($path);
            }
        }
    }

    /**
     * 폼 공통 데이터(계정 목록·증빙유형).
     *
     * @param array<string, mixed>      $business
     * @param array<string, mixed>|null $entry
     *
     * @return array<string, mixed>
     */
    private function formData(array $business, ?array $entry): array
    {
        $accounts = model(AccountModel::class);
        $isMfg    = (bool) $business['is_manufacturing'];

        return [
            'business'        => $business,
            'entry'           => $entry,
            'incomeAccounts'  => $accounts->forCategory(AccountCategory::Income),
            'expenseAccounts' => $accounts->forCategory(AccountCategory::Expense, $isMfg),
            'partners'        => model(PartnerModel::class)->forBusiness((int) $business['id']),
            'evidenceTypes'   => EvidenceType::cases(),
            'entryTypes'      => [EntryType::Income, EntryType::Expense],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function business(int $businessId): ?array
    {
        try {
            return service('businessService')->get($this->authUserId(), $businessId);
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function rules(): array
    {
        return [
            'entry_date'    => 'required|valid_date[Y-m-d]',
            'entry_type'    => 'required|in_list[income,expense]',
            'description'   => 'required|max_length[255]',
            'supply_amount' => 'required',
        ];
    }
}
