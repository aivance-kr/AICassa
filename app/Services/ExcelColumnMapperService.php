<?php

namespace App\Services;

use App\DTOs\ColumnMappingResult;
use App\Libraries\AnthropicClient;
use Throwable;

/**
 * 엑셀/CSV 임포트 컬럼 자동매핑(유스케이스).
 *
 * 고객사마다 다른 헤더 행을 장부 표준 스키마(날짜·구분·계정과목·거래내용·거래처·금액·비고)로 매핑한다.
 * 설계 원칙(에픽 #20):
 *  - **정확일치 우선**: 동의어 사전으로 로컬 매칭을 먼저 시도하고, 남은 헤더가 있을 때만 LLM 을 호출한다.
 *  - **헤더 행만** LLM 에 전달하므로 토큰 비용이 매우 낮다.
 *  - **닫힌 어휘**: AI 출력은 표준 필드 키로만 흡수하고, 알 수 없는 값·중복은 폐기한다.
 *  - **graceful**: AI 미설정·실패 시 로컬 매칭 결과만으로 동작한다(사용자가 UI 에서 보완).
 */
final class ExcelColumnMapperService
{
    /** 미리보기에 보여줄 샘플 데이터 행 수. */
    private const SAMPLE_ROWS = 3;

    /**
     * 표준 필드 메타(키 → 라벨·필수여부). 키는 LedgerImportService 가 소비하는 행 배열 키와 동일하다.
     *
     * @var array<string, array{label:string, required:bool}>
     */
    private const FIELDS = [
        'date'        => ['label' => '날짜(거래일자)',   'required' => true],
        'type'        => ['label' => '구분(수입/비용)',  'required' => true],
        'account'     => ['label' => '계정과목',         'required' => false],
        'description' => ['label' => '거래내용(적요)',   'required' => true],
        'partner'     => ['label' => '거래처(상호)',     'required' => false],
        'amount'      => ['label' => '금액(공급가액)',   'required' => true],
        'evidence'    => ['label' => '비고(증빙유형)',   'required' => false],
    ];

    /**
     * 로컬 매칭용 동의어 사전(정규화 후 비교). 첫 일치 필드로 매핑한다.
     *
     * @var array<string, list<string>>
     */
    private const SYNONYMS = [
        'date'        => ['날짜', '일자', '거래일', '거래일자', '작성일', 'date'],
        'type'        => ['구분', '유형', '수입비용', '입출구분', '거래구분', 'type'],
        'account'     => ['계정과목', '계정', '과목', 'account'],
        'description' => ['거래내용', '적요', '내용', '품목', '상품명', '내역', 'description', 'memo'],
        'partner'     => ['거래처', '상호', '거래처명', '공급처', '매입처', '매출처', '거래상대방', 'partner'],
        'amount'      => ['금액', '공급가액', '공급가', '가액', '매출액', '매입액', 'amount'],
        'evidence'    => ['비고', '증빙', '증빙유형', '증빙종류', 'evidence'],
    ];

    private ?AnthropicClient $ai;

    public function __construct(?AnthropicClient $ai = null)
    {
        // AI 클라이언트는 Services 팩토리에서 env 기반 주입. null 이면 로컬 매칭만 동작.
        $this->ai = $ai;
    }

    /**
     * 표준 필드 메타를 반환한다(컨트롤러·뷰가 드롭다운·검증에 사용).
     *
     * @return array<string, array{label:string, required:bool}>
     */
    public static function fields(): array
    {
        return self::FIELDS;
    }

    /**
     * 필수 표준 필드 키 목록.
     *
     * @return list<string>
     */
    public static function requiredFields(): array
    {
        return array_keys(array_filter(self::FIELDS, static fn (array $m): bool => $m['required']));
    }

    /**
     * 그리드(헤더 + 데이터)를 분석해 매핑 제안을 만든다.
     *
     * @param list<list<string>> $grid 첫 행이 헤더, 이후가 데이터
     */
    public function analyze(array $grid): ColumnMappingResult
    {
        $split       = $this->splitGrid($grid);
        $assignments = $this->suggest($split['headers']);

        return new ColumnMappingResult($split['headers'], $split['samples'], $assignments);
    }

    /**
     * 그리드를 헤더 행과 앞부분 샘플 행으로 분리한다(컨트롤러 재렌더에서도 재사용).
     *
     * @param list<list<string>> $grid
     *
     * @return array{headers: list<string>, samples: list<list<string>>}
     */
    public function splitGrid(array $grid): array
    {
        return [
            'headers' => array_map('strval', $grid[0] ?? []),
            'samples' => array_values(array_slice($grid, 1, self::SAMPLE_ROWS)),
        ];
    }

    /**
     * 사용자가 확정한 매핑(열index → 표준필드키)을 검증해 field→열index 매핑을 만든다.
     * 표준 필드 중복 지정·필수 필드 누락 시 error 메시지를 담아 반환한다(컨트롤러가 재렌더).
     *
     * @param array<array-key, mixed> $posted mapping[열index] = 표준필드키('' = 무시)
     *
     * @return array{mapping: array<string, int>, assignments: array<int, string>, error: string|null}
     */
    public function resolvePostedMapping(array $posted): array
    {
        // 1) 유효한 (열index → 표준필드키)만 추린다(재표시용, 중복 포함).
        $assignments = [];
        foreach ($posted as $idx => $field) {
            if (ctype_digit((string) $idx) && is_string($field) && isset(self::FIELDS[$field])) {
                $assignments[(int) $idx] = $field;
            }
        }

        // 2) field → 열index 로 뒤집으며 중복 지정을 검출한다.
        $mapping = [];
        foreach ($assignments as $idx => $field) {
            if (isset($mapping[$field])) {
                return [
                    'mapping'     => [],
                    'assignments' => $assignments,
                    'error'       => "표준 필드 '" . self::FIELDS[$field]['label'] . "'가 두 개 이상의 열에 지정되었습니다.",
                ];
            }
            $mapping[$field] = $idx;
        }

        // 3) 필수 필드 누락 검증.
        $missing = array_diff(self::requiredFields(), array_keys($mapping));
        if ($missing !== []) {
            $labels = array_map(static fn (string $f): string => self::FIELDS[$f]['label'], $missing);

            return [
                'mapping'     => [],
                'assignments' => $assignments,
                'error'       => '필수 항목을 모두 지정하세요: ' . implode(', ', $labels),
            ];
        }

        return ['mapping' => $mapping, 'assignments' => $assignments, 'error' => null];
    }

    /**
     * 헤더 배열을 표준 필드로 매핑 제안한다. 로컬 매칭 우선, 남은 헤더만 AI.
     *
     * @param list<string> $headers
     *
     * @return array<int, string|null> 소스컬럼 index → 표준 필드 키(또는 null)
     */
    public function suggest(array $headers): array
    {
        $assignments = array_fill(0, count($headers), null);
        $used        = []; // 이미 배정된 표준 필드(중복 방지)

        // 1) 로컬 동의어 정확/정규화 매칭
        foreach ($headers as $i => $header) {
            $field = $this->localMatch($header);
            if ($field !== null && ! isset($used[$field])) {
                $assignments[$i] = $field;
                $used[$field]    = true;
            }
        }

        // 2) 미해결 헤더가 있고 아직 못 채운 필드가 있으면 AI 보완
        $unresolved = array_keys($assignments, null, true);
        $missing    = array_diff(array_keys(self::FIELDS), array_keys($used));

        if ($this->ai !== null && $unresolved !== [] && $missing !== []) {
            try {
                foreach ($this->aiSuggest($headers) as $i => $field) {
                    if (
                        ($assignments[$i] ?? null) === null
                        && isset(self::FIELDS[$field])
                        && ! isset($used[$field])
                    ) {
                        $assignments[$i] = $field;
                        $used[$field]    = true;
                    }
                }
            } catch (Throwable $e) {
                log_message('warning', '컬럼 자동매핑 AI 실패(로컬 매칭만 사용): {msg}', ['msg' => $e->getMessage()]);
            }
        }

        return $assignments;
    }

    /**
     * 동의어 사전으로 헤더 하나를 표준 필드로 매칭한다. 실패 시 null.
     */
    private function localMatch(string $header): ?string
    {
        $normalized = $this->normalize($header);
        if ($normalized === '') {
            return null;
        }

        foreach (self::SYNONYMS as $field => $words) {
            foreach ($words as $word) {
                if ($normalized === $this->normalize($word)) {
                    return $field;
                }
            }
        }

        return null;
    }

    /**
     * 비교용 정규화: 공백·괄호·구분기호 제거 후 소문자화.
     */
    private function normalize(string $value): string
    {
        $value = (string) preg_replace('/[\s()\[\]{}\-_.\/]+/u', '', $value);

        return mb_strtolower($value);
    }

    /**
     * 헤더 목록을 Claude 로 표준 필드에 매핑한다(닫힌 어휘 JSON).
     *
     * @param list<string> $headers
     *
     * @return array<int, string> index → 표준 필드 키
     */
    private function aiSuggest(array $headers): array
    {
        if ($this->ai === null) {
            return [];
        }

        $answer = $this->ai->completeText($this->buildPrompt($headers), 256);
        $json   = $this->parseJson($answer);

        $map = [];
        foreach ($json as $key => $value) {
            if (! ctype_digit((string) $key) || ! is_string($value)) {
                continue;
            }
            $field = trim($value);
            if (isset(self::FIELDS[$field])) {
                $map[(int) $key] = $field;
            }
        }

        return $map;
    }

    /**
     * 닫힌 어휘 프롬프트. 헤더에 index 를 붙여 제시하고, 표준 필드 키로만 매핑을 요청한다.
     *
     * @param list<string> $headers
     */
    private function buildPrompt(array $headers): string
    {
        $lines = [];
        foreach ($headers as $i => $header) {
            $lines[] = "{$i}: " . str_replace(["\r", "\n"], ' ', $header);
        }
        $headerList = implode("\n", $lines);

        $fieldList = [];
        foreach (self::FIELDS as $key => $meta) {
            $fieldList[] = "{$key} = {$meta['label']}";
        }
        $fields = implode("\n", $fieldList);

        return <<<PROMPT
            너는 한국 간편장부 엑셀 임포트의 컬럼 매핑기다.
            아래 "소스 헤더"(각 줄은 `열번호: 헤더명`)를 장부 표준 필드로 매핑하라.
            출력은 JSON 객체 하나만(설명·마크다운·코드펜스 금지): 키는 열번호(정수 문자열), 값은 표준 필드 키.
            매핑할 표준 필드가 없는 열은 아예 생략하라. 한 표준 필드는 최대 한 열에만 매핑하라.

            표준 필드(키 = 뜻):
            {$fields}

            소스 헤더:
            {$headerList}
            PROMPT;
    }

    /**
     * 모델 출력에서 JSON 객체를 파싱한다(코드펜스 방어적 제거).
     *
     * @return array<array-key, mixed>
     */
    private function parseJson(string $text): array
    {
        $clean = trim($text);
        $clean = (string) preg_replace('/^```(?:json)?|```$/m', '', $clean);
        $clean = trim($clean);

        /** @var mixed $data */
        $data = json_decode($clean, true);
        if (! is_array($data)) {
            return [];
        }

        /** @var array<array-key, mixed> $data */
        return $data;
    }
}
