<?php

namespace App\Services;

use App\Enums\TaxParameterType;
use App\Exceptions\AlreadyExistsException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Models\TaxParameterModel;

/**
 * 연도별 세법 파라미터 운영자 유스케이스.
 *
 * 운영자 CRUD(연도 목록/상세 조회, 일괄 수정, 새 연도 추가)를 담당한다.
 * 계산에 쓰이는 스칼라 룰셋(부가세 제수·비망가액·정률 잔존율)은 이 테이블을 소스로
 * {@see \App\Services\TaxRuleResolver} 가 시행연도 기준으로 해석하므로, 운영자가 여기서
 * 값을 바꾸면 별도 코드 수정 없이 계산에 반영된다.
 */
final class TaxParameterService
{
    private TaxParameterModel $params;

    public function __construct(?TaxParameterModel $params = null)
    {
        $this->params = $params ?? model(TaxParameterModel::class);
    }

    /**
     * 등록된 귀속연도 목록(내림차순) + 연도별 파라미터 수.
     *
     * @return list<array{fiscal_year: int, param_count: int}>
     */
    public function registeredYears(): array
    {
        return $this->params->registeredYears();
    }

    /**
     * 특정 연도의 전체 파라미터. 미등록 연도면 예외.
     *
     * @return list<array<string, mixed>>
     */
    public function paramsForYear(int $fiscalYear): array
    {
        $rows = $this->params->paramsForYear($fiscalYear);
        if ($rows === []) {
            throw new NotFoundException("{$fiscalYear}년 세법 파라미터가 등록되어 있지 않습니다.");
        }

        return $rows;
    }

    /**
     * 해당 연도 파라미터 등록 여부.
     */
    public function hasYear(int $fiscalYear): bool
    {
        return $this->params->where('fiscal_year', $fiscalYear)->countAllResults() > 0;
    }

    /**
     * 특정 연도 파라미터 일괄 수정.
     * 폼에서 넘어온 [param_key => 원시 문자열값] 을 검증 후 반영한다.
     * (키 신규 생성은 허용하지 않고, 해당 연도에 이미 존재하는 파라미터만 갱신)
     *
     * @param array<string, string> $values
     */
    public function bulkUpsert(int $fiscalYear, array $values): void
    {
        $rows  = $this->paramsForYear($fiscalYear); // 미등록 연도면 여기서 예외
        $byKey = [];

        foreach ($rows as $row) {
            $byKey[(string) $row['param_key']] = $row;
        }

        $errors  = [];
        $updates = [];

        foreach ($values as $key => $raw) {
            $row = $byKey[$key] ?? null;
            if ($row === null) {
                continue; // 알 수 없는 키는 무시(폼 변조 방어)
            }

            $type       = TaxParameterType::from((string) $row['value_type']);
            $normalized = $this->validateValue($type, (string) $raw);
            if ($normalized === null) {
                $errors[$key] = "'{$row['label']}' 값 형식이 올바르지 않습니다({$type->label()}).";

                continue;
            }

            $updates[(int) $row['id']] = $normalized;
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $db = db_connect();
        $db->transStart();

        foreach ($updates as $id => $value) {
            $this->params->update($id, ['param_value' => $value]);
        }
        $db->transComplete();

        if ($db->transStatus() === false) {
            throw new ValidationException(['_' => '세법 파라미터 저장에 실패했습니다.']);
        }
    }

    /**
     * 새 귀속연도 추가 — 기준 연도(미지정 시 최신 연도)의 전체 파라미터를 복제한다.
     *
     * @param int      $newYear       추가할 귀속연도
     * @param int|null $cloneFromYear 복제 기준 연도(기본: 최신 등록 연도)
     */
    public function createYear(int $newYear, ?int $cloneFromYear = null): void
    {
        if ($this->hasYear($newYear)) {
            throw new AlreadyExistsException("{$newYear}년 세법 파라미터가 이미 존재합니다.");
        }

        $source = $cloneFromYear ?? $this->params->latestYear();
        if ($source === null) {
            throw new NotFoundException('복제할 기준 연도 파라미터가 없습니다.');
        }

        $sourceRows = $this->params->paramsForYear($source);
        if ($sourceRows === []) {
            throw new NotFoundException("{$source}년 기준 파라미터가 없습니다.");
        }

        $batch = array_map(static fn (array $r): array => [
            'fiscal_year' => $newYear,
            'param_key'   => $r['param_key'],
            'value_type'  => $r['value_type'],
            'param_value' => $r['param_value'],
            'category'    => $r['category'],
            'label'       => $r['label'],
            'unit'        => $r['unit'],
            'sort_order'  => $r['sort_order'],
            'description' => $r['description'],
        ], $sourceRows);

        $this->params->insertBatch($batch);
    }

    /**
     * 타입별 값 검증 및 정규화. 유효하면 저장할 문자열, 아니면 null.
     */
    private function validateValue(TaxParameterType $type, string $raw): ?string
    {
        $raw = trim($raw);

        return match ($type) {
            TaxParameterType::Integer => is_numeric($raw) ? (string) (int) $raw : null,
            TaxParameterType::Decimal => is_numeric($raw) ? $raw : null,
            TaxParameterType::Text    => $raw,
            TaxParameterType::Json    => $this->normalizeJson($raw),
        };
    }

    /**
     * JSON 문자열 유효성 검증 후 정규화(재인코딩)한다. 유효하지 않으면 null.
     */
    private function normalizeJson(string $raw): ?string
    {
        if ($raw === '') {
            return '[]';
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return null;
        }

        $encoded = json_encode($decoded, JSON_UNESCAPED_UNICODE);

        return $encoded === false ? null : $encoded;
    }
}
