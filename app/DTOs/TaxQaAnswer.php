<?php

namespace App\DTOs;

/**
 * 세무 Q&A 응답(값 객체).
 *
 * 근거 문서에 기반한 답변과 출처를 담는다. 근거로 답할 수 없으면 answerable=false 로
 * "확인 불가"를 반환한다(출처 없는 단정 금지 — 환각 억제).
 */
final readonly class TaxQaAnswer
{
    /**
     * @param string            $answer     답변 텍스트(확인 불가 시 안내 문구)
     * @param bool              $answerable 근거로 답할 수 있었는가
     * @param list<TaxQaSource> $sources    인용한 근거 섹션(확인 불가 시 빈 배열)
     */
    public function __construct(
        public string $answer,
        public bool $answerable,
        public array $sources,
    ) {
    }

    /**
     * 근거로 답할 수 없는 경우(출처 없는 단정 방지).
     */
    public static function unanswerable(string $message): self
    {
        return new self($message, false, []);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'answer'     => $this->answer,
            'answerable' => $this->answerable,
            'sources'    => array_map(static fn (TaxQaSource $s): array => $s->toArray(), $this->sources),
        ];
    }
}
