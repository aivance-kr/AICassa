<?php

namespace App\Services;

use App\DTOs\TaxQaAnswer;
use App\DTOs\TaxQaSource;
use App\Libraries\AnthropicClient;
use Throwable;

/**
 * 세무 Q&A 챗봇(RAG · 유스케이스).
 *
 * docs/*.md 를 제목 단위 섹션으로 인덱싱하고, 질의 키워드로 관련 섹션을 렉시컬 검색해
 * Claude 에 **근거 섹션만** 주입한다. 답변은 항상 출처(섹션)를 동반하며, 근거로 답할 수
 * 없으면 "확인 불가"로 응답한다(출처 없는 단정 금지 — 환각 억제).
 *
 * 임베딩 인프라 없이 동작한다(Anthropic 임베딩 미제공). 코퍼스가 작아 렉시컬 검색으로 충분.
 * AI 출력의 출처 id 는 **검색된 섹션과 정확일치할 때만** 채택한다(할루시네이션 인용 폐기).
 */
final class TaxQaService
{
    /**
     * 검색 상위 섹션 수.
     */
    private const TOP_K = 5;

    /**
     * 제목 일치 가중치(본문 대비).
     */
    private const HEADING_WEIGHT = 3;

    /**
     * 프롬프트에 넣을 섹션당 최대 길이(문자).
     */
    private const MAX_SECTION_CHARS = 1500;

    private const NO_MATCH    = '근거 문서에서 관련 내용을 찾지 못했습니다. 질문을 더 구체적으로 입력해 보세요.';
    private const AI_DISABLED = 'AI 도우미가 설정되지 않아 답변할 수 없습니다(관리자 문의).';
    private const AI_ERROR    = 'AI 응답을 가져오지 못했습니다. 잠시 후 다시 시도하세요.';
    private const NOT_FOUND   = '근거 문서에서 확인되지 않는 내용입니다.';

    /**
     * 검색 키워드에서 떼어낼 조사(정확일치 recall 개선용).
     *
     * @var list<string>
     */
    private const JOSA = ['은', '는', '이', '가', '을', '를', '에', '의', '도', '로', '과', '와', '만'];

    private ?AnthropicClient $ai;
    private string $docsPath;

    /**
     * 파싱된 섹션 메모(요청 단위).
     *
     * @var list<array<string, string>>|null
     */
    private ?array $sectionsCache = null;

    public function __construct(?AnthropicClient $ai = null, ?string $docsPath = null)
    {
        // AI 클라이언트는 Services 팩토리에서 env 기반 주입. null 이면 검색만 하고 확인 불가 응답.
        $this->ai       = $ai;
        $this->docsPath = $docsPath ?? ROOTPATH . 'docs';
    }

    /**
     * 질문에 근거 기반으로 답한다. 근거로 답할 수 없으면 "확인 불가".
     */
    public function ask(string $question): TaxQaAnswer
    {
        $question = trim($question);
        if ($question === '') {
            return TaxQaAnswer::unanswerable('질문을 입력하세요.');
        }

        $hits = $this->retrieve($question, self::TOP_K);
        if ($hits === []) {
            return TaxQaAnswer::unanswerable(self::NO_MATCH);
        }
        if ($this->ai === null) {
            return TaxQaAnswer::unanswerable(self::AI_DISABLED);
        }

        // 섹션에 프롬프트용 id(S1..) 부여.
        $labeled = [];

        foreach ($hits as $i => $section) {
            $labeled['S' . ($i + 1)] = $section;
        }

        try {
            $raw  = $this->ai->completeText($this->buildPrompt($question, $labeled), 800);
            $json = $this->parseJson($raw);
        } catch (Throwable $e) {
            log_message('warning', '세무 Q&A AI 실패: {msg}', ['msg' => $e->getMessage()]);

            return TaxQaAnswer::unanswerable(self::AI_ERROR);
        }

        $answerable = ($json['answerable'] ?? false) === true;
        $text       = is_string($json['answer'] ?? null) ? trim((string) $json['answer']) : '';
        if (! $answerable || $text === '') {
            return TaxQaAnswer::unanswerable($text !== '' ? $text : self::NOT_FOUND);
        }

        // 출처 없는 단정 방지 — 검색된 섹션과 정확일치하는 인용만 채택, 없으면 확인 불가.
        $sources = $this->resolveSources($json['sources'] ?? [], $labeled);
        if ($sources === []) {
            return TaxQaAnswer::unanswerable(self::NOT_FOUND);
        }

        return new TaxQaAnswer($text, true, $sources);
    }

    /**
     * 질의 키워드로 관련 섹션을 점수순으로 top-K 선별한다.
     *
     * @return list<array<string, string>>
     */
    public function retrieve(string $query, int $k = self::TOP_K): array
    {
        $terms = $this->keywords($query);
        if ($terms === []) {
            return [];
        }

        /** @var list<array{score: int, section: array<string, string>}> $scored */
        $scored = [];

        foreach ($this->loadSections() as $section) {
            $headingLc = mb_strtolower($section['heading']);
            $bodyLc    = mb_strtolower($section['body']);

            $score = 0;

            foreach ($terms as $term) {
                $score += substr_count($headingLc, $term) * self::HEADING_WEIGHT;
                $score += substr_count($bodyLc, $term);
            }

            if ($score > 0) {
                $scored[] = ['score' => $score, 'section' => $section];
            }
        }

        usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(static fn (array $x): array => $x['section'], array_slice($scored, 0, $k));
    }

    /**
     * 근거 문서 파일명 목록(뷰어 화이트리스트).
     *
     * @return list<string>
     */
    public function docFiles(): array
    {
        $files = glob($this->docsPath . DIRECTORY_SEPARATOR . '*.md') ?: [];
        $names = array_map('basename', $files);
        sort($names);

        return $names;
    }

    /**
     * (문서, 앵커)로 섹션 하나를 조회한다(원문 뷰어). 없으면 null.
     *
     * @return array<string, string>|null
     */
    public function section(string $doc, string $anchor): ?array
    {
        foreach ($this->loadSections() as $section) {
            if ($section['doc'] === $doc && $section['anchor'] === $anchor) {
                return $section;
            }
        }

        return null;
    }

    /**
     * AI 가 인용한 섹션 id 를 검색 집합과 정확일치할 때만 출처로 변환한다.
     *
     * @param array<string, array<string, string>> $labeled
     *
     * @return list<TaxQaSource>
     */
    private function resolveSources(mixed $rawIds, array $labeled): array
    {
        $sources = [];
        $seen    = [];

        foreach ((array) $rawIds as $id) {
            if (! is_string($id)) {
                continue;
            }
            $id = trim($id);
            if (! isset($labeled[$id]) || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $s         = $labeled[$id];

            $sources[] = new TaxQaSource($s['doc'], $s['title'], $s['heading'], $s['anchor']);
        }

        return $sources;
    }

    /**
     * 닫힌 근거 프롬프트. 검색된 섹션만 제시하고 엄격한 JSON 만 요청한다.
     *
     * @param array<string, array<string, string>> $labeled
     */
    private function buildPrompt(string $question, array $labeled): string
    {
        $blocks = [];

        foreach ($labeled as $id => $section) {
            $body     = mb_substr($section['body'], 0, self::MAX_SECTION_CHARS);
            $blocks[] = "[{$id}] (문서: {$section['doc']} › {$section['heading']})\n{$body}";
        }
        $context  = implode("\n\n", $blocks);
        $notFound = self::NOT_FOUND;

        return <<<PROMPT
            너는 한국 간편장부 세무 도우미다. 아래 [근거] 섹션만 사용해 질문에 답하라.
            JSON 객체 하나만 출력하라(설명·마크다운·코드펜스 금지):
            {"answerable": true 또는 false, "answer": "답변 또는 확인 불가 사유", "sources": ["S1", ...]}
            규칙:
            - 근거에 있는 내용만으로 답하고, 실제로 사용한 섹션 id 를 sources 에 모두 넣어라.
            - 근거로 답할 수 없으면 answerable=false, answer="{$notFound}", sources=[].
            - 근거에 없는 사실을 추측하거나 창작하지 마라.

            [근거]
            {$context}

            질문: {$question}
            PROMPT;
    }

    /**
     * 질의를 키워드 집합으로 만든다(소문자화·조사 제거·2자 이상).
     *
     * @return list<string>
     */
    private function keywords(string $query): array
    {
        $normalized = mb_strtolower(trim($query));
        $rawTokens  = preg_split('/[^\p{L}\p{N}]+/u', $normalized) ?: [];

        $terms = [];

        foreach ($rawTokens as $token) {
            if (mb_strlen($token) < 2) {
                continue;
            }
            $trimmed = $this->trimParticle($token);
            if (mb_strlen($trimmed) >= 2) {
                $terms[$trimmed] = true;
            }
        }

        return array_keys($terms);
    }

    /**
     * 3자 이상 토큰의 후행 조사 1자를 제거한다(정확일치 recall 개선).
     */
    private function trimParticle(string $token): string
    {
        if (mb_strlen($token) < 3) {
            return $token;
        }

        return in_array(mb_substr($token, -1), self::JOSA, true) ? mb_substr($token, 0, -1) : $token;
    }

    /**
     * docs/*.md 를 제목 단위 섹션으로 파싱한다(코드펜스 내 #는 제목으로 오인하지 않음).
     *
     * @return list<array<string, string>>
     */
    private function loadSections(): array
    {
        if ($this->sectionsCache !== null) {
            return $this->sectionsCache;
        }

        $sections = [];

        foreach ($this->docFiles() as $file) {
            $content = @file_get_contents($this->docsPath . DIRECTORY_SEPARATOR . $file);
            if ($content === false) {
                continue;
            }
            $sections = [...$sections, ...$this->parseDoc($file, $content)];
        }

        return $this->sectionsCache = $sections;
    }

    /**
     * 문서 하나를 섹션 배열로 파싱한다.
     *
     * @return list<array<string, string>>
     */
    private function parseDoc(string $file, string $content): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        $title = $file;

        foreach ($lines as $line) {
            if (preg_match('/^#\s+(.+?)\s*$/', $line, $m) === 1) {
                $title = trim($m[1]);
                break;
            }
        }

        $sections = [];
        $heading  = $title;
        $buffer   = [];
        $ord      = 0;
        $inFence  = false;

        foreach ($lines as $line) {
            if (preg_match('/^```/', $line) === 1) {
                $inFence  = ! $inFence;
                $buffer[] = $line;

                continue;
            }

            if (! $inFence && preg_match('/^(#{1,3})\s+(.+?)\s*$/', $line, $m) === 1) {
                $ord     = $this->pushSection($sections, $file, $title, $heading, $buffer, $ord);
                $buffer  = [];
                $heading = strlen($m[1]) === 1 ? $title : trim($m[2]);

                continue;
            }

            $buffer[] = $line;
        }

        $this->pushSection($sections, $file, $title, $heading, $buffer, $ord);

        return $sections;
    }

    /**
     * 버퍼가 비어있지 않으면 섹션 하나를 추가하고 순번을 올린다. 새 순번을 반환.
     *
     * @param list<array<string, string>> $sections
     * @param list<string>                $buffer
     */
    private function pushSection(array &$sections, string $file, string $title, string $heading, array $buffer, int $ord): int
    {
        $body = trim(implode("\n", $buffer));
        if ($body === '') {
            return $ord;
        }

        $ord++;
        $sections[] = [
            'doc'     => $file,
            'title'   => $title,
            'heading' => $heading,
            'anchor'  => 'sec-' . $ord,
            'body'    => $body,
        ];

        return $ord;
    }

    /**
     * 모델 출력에서 JSON 객체를 파싱한다(코드펜스 방어적 제거).
     *
     * @return array<string, mixed>
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

        /** @var array<string, mixed> $data */
        return $data;
    }
}
