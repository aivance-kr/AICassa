<?php

// 자주 쓰는 검증 메시지 한글화. 여기 없는 키는 자동으로 영문(en)으로 폴백된다.
return [
    'required'           => '{field} 항목은 필수입니다.',
    'valid_email'        => '{field} 항목은 올바른 이메일 형식이어야 합니다.',
    'min_length'         => '{field} 항목은 최소 {param}자 이상이어야 합니다.',
    'max_length'         => '{field} 항목은 최대 {param}자까지 입력할 수 있습니다.',
    'integer'            => '{field} 항목은 정수여야 합니다.',
    'is_natural'         => '{field} 항목은 0 이상의 정수여야 합니다.',
    'is_natural_no_zero' => '{field} 항목은 0보다 큰 정수여야 합니다.',
    'valid_date'         => '{field} 항목은 올바른 날짜 형식이어야 합니다.',
    'in_list'            => '{field} 항목의 값이 허용되지 않습니다.',
];
