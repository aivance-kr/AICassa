<?php

namespace App\Controllers\Operator;

use App\Exceptions\AlreadyExistsException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use CodeIgniter\HTTP\RedirectResponse;

/**
 * 연도별 세법 파라미터 관리(운영자). 리스트 → 상세 → 일괄 수정 + 새 연도 추가.
 */
final class TaxParameterController extends BaseOperatorController
{
    private const BASE = '/operator/tax-parameters';

    /**
     * 등록된 귀속연도 목록.
     */
    public function index(): string
    {
        return $this->render('operator/tax_parameters/index', [
            'years' => service('taxParameterService')->registeredYears(),
        ]);
    }

    /**
     * 특정 연도 상세(전체 파라미터).
     */
    public function show(int $year): RedirectResponse|string
    {
        try {
            $params = service('taxParameterService')->paramsForYear($year);
        } catch (NotFoundException $e) {
            return redirect()->to(self::BASE)->with('error', $e->getMessage());
        }

        return $this->render('operator/tax_parameters/show', [
            'year'   => $year,
            'params' => $params,
        ]);
    }

    /**
     * 특정 연도 일괄 수정 폼.
     */
    public function edit(int $year): RedirectResponse|string
    {
        try {
            $params = service('taxParameterService')->paramsForYear($year);
        } catch (NotFoundException $e) {
            return redirect()->to(self::BASE)->with('error', $e->getMessage());
        }

        return $this->render('operator/tax_parameters/edit', [
            'year'   => $year,
            'params' => $params,
        ]);
    }

    /**
     * 일괄 수정 처리.
     */
    public function update(int $year): RedirectResponse
    {
        /** @var array<string, string> $values */
        $values = (array) $this->request->getPost('params');

        try {
            service('taxParameterService')->bulkUpsert($year, $values);
        } catch (NotFoundException $e) {
            return redirect()->to(self::BASE)->with('error', $e->getMessage());
        } catch (ValidationException $e) {
            return redirect()->back()->withInput()->with('errors', $e->errors());
        }

        return redirect()->to(self::BASE . '/' . $year)
            ->with('message', "{$year}년 세법 파라미터가 저장되었습니다.");
    }

    /**
     * 새 귀속연도 추가(최신 등록 연도값 복제).
     */
    public function createYear(): RedirectResponse
    {
        if (! $this->validate(['fiscal_year' => 'required|is_natural_no_zero|greater_than[1999]|less_than[2100]'])) {
            return redirect()->to(self::BASE)->with('error', '추가할 귀속연도를 올바르게 입력하세요.');
        }

        $year = (int) $this->request->getPost('fiscal_year');

        try {
            service('taxParameterService')->createYear($year);
        } catch (AlreadyExistsException $e) {
            return redirect()->to(self::BASE)->with('error', $e->getMessage());
        } catch (NotFoundException $e) {
            return redirect()->to(self::BASE)->with('error', $e->getMessage());
        }

        return redirect()->to(self::BASE . '/' . $year . '/edit')
            ->with('message', "{$year}년을 추가했습니다. 값을 확인·수정하세요.");
    }
}
