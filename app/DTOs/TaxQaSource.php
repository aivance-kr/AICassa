<?php

namespace App\DTOs;

/**
 * 세무 Q&A 답변의 출처(근거 문서 섹션). 인용 표기·원문 뷰어 링크에 사용한다.
 */
final readonly class TaxQaSource
{
    /**
     * @param string $doc     근거 문서 파일명(예: 간편장부_계산식명세.md) — 뷰어 조회 화이트리스트 키
     * @param string $title   문서 제목(H1)
     * @param string $heading 섹션 제목
     * @param string $anchor  섹션 조회 키(예: sec-3) — 경로우회 방지용 불투명 식별자
     */
    public function __construct(
        public string $doc,
        public string $title,
        public string $heading,
        public string $anchor,
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'doc'     => $this->doc,
            'title'   => $this->title,
            'heading' => $this->heading,
            'anchor'  => $this->anchor,
        ];
    }
}
