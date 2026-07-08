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
use CodeIgniter\HTTP\RedirectResponse;

/**
 * 장부 관리(Admin). 사업장 스코프 하위에서 개별 입력·수정·삭제·복사.
 */
class LedgerController extends BaseAdminController
{
    /**
     * 장부 목록(필터 + 합계).
     */
    public function index(int $businessId): string|RedirectResponse
    {
        $business = $this->business($businessId);
        if ($business === null) {
            return redirect()->to('/admin/businesses')->with('error', '사업장을 찾을 수 없습니다.');
        }

        $filters = [
            'fiscal_year' => $this->request->getGet('fiscal_year'),
            'entry_type'  => $this->request->getGet('entry_type'),
            'date_from'   => $this->request->getGet('date_from'),
            'date_to'     => $this->request->getGet('date_to'),
        ];

        $entries = service('ledgerService')->listForBusiness($this->authUserId(), $businessId, $filters);

        return $this->render('admin/ledger/index', [
            'business' => $business,
            'entries'  => $entries,
            'summary'  => service('ledgerService')->summarize($entries),
            'years'    => service('ledgerService')->availableYears($this->authUserId(), $businessId),
            'filters'  => $filters,
        ]);
    }

    /**
     * 입력 폼.
     */
    public function new(int $businessId): string|RedirectResponse
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
    public function edit(int $businessId, int $entryId): string|RedirectResponse
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

        return $this->render('admin/ledger/form', $this->formData($business, $entry));
    }

    /**
     * 수정 처리.
     */
    public function update(int $businessId, int $entryId): RedirectResponse
    {
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
            service('ledgerService')->delete($this->authUserId(), $businessId, $entryId);
        } catch (NotFoundException) {
            return redirect()->to("/admin/businesses/{$businessId}/ledger")
                ->with('error', '장부 항목을 찾을 수 없습니다.');
        }

        return redirect()->to("/admin/businesses/{$businessId}/ledger")
            ->with('message', '거래가 삭제되었습니다.');
    }

    /**
     * 복사 입력 — 항목을 복제하고 수정 폼으로 이동(반복 거래용).
     */
    public function copy(int $businessId, int $entryId): RedirectResponse
    {
        try {
            $newId = service('ledgerService')->copy($this->authUserId(), $businessId, $entryId);
        } catch (NotFoundException) {
            return redirect()->to("/admin/businesses/{$businessId}/ledger")
                ->with('error', '장부 항목을 찾을 수 없습니다.');
        }

        return redirect()->to("/admin/businesses/{$businessId}/ledger/{$newId}/edit")
            ->with('message', '복사되었습니다. 내용을 수정하세요.');
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
