<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<?php
    $isEdit = $business !== null;
    $action = $isEdit ? '/admin/businesses/' . $business['id'] : '/admin/businesses';
    // old() 우선(검증 실패 재입력), 그다음 기존 값
    $val = static fn (string $k): string => (string) (old($k) ?? ($business[$k] ?? ''));
?>
<h1><?= $isEdit ? '사업장 수정' : '사업장 등록' ?></h1>

<form class="card" method="post" action="<?= $action ?>">
    <?= csrf_field() ?>

    <label for="name">상호 <span style="color:#dc2626">*</span></label>
    <input type="text" id="name" name="name" value="<?= esc($val('name')) ?>" required>

    <label for="owner_name">대표자명</label>
    <input type="text" id="owner_name" name="owner_name" value="<?= esc($val('owner_name')) ?>">

    <label for="birth_date">생년월일</label>
    <input type="date" id="birth_date" name="birth_date" value="<?= esc($val('birth_date')) ?>">

    <label for="biz_reg_no">사업자등록번호</label>
    <input type="text" id="biz_reg_no" name="biz_reg_no" value="<?= esc($val('biz_reg_no')) ?>" placeholder="123-45-67890">

    <label for="income_type">소득종류</label>
    <input type="text" id="income_type" name="income_type" value="<?= esc($val('income_type')) ?>" placeholder="사업소득 / 부동산임대 등">

    <label for="industry_name">업종</label>
    <div class="ac-field">
        <input type="text" id="industry_name" name="industry_name" value="<?= esc($val('industry_name')) ?>"
            autocomplete="off" data-ac-search placeholder="업종명 또는 코드로 검색">
        <div class="ac-list" hidden></div>
    </div>

    <label for="industry_code">주업종코드</label>
    <div class="ac-field">
        <input type="text" id="industry_code" name="industry_code" value="<?= esc($val('industry_code')) ?>"
            autocomplete="off" data-ac-search placeholder="업종명 또는 코드로 검색">
        <div class="ac-list" hidden></div>
    </div>
    <p class="ac-warn" id="industry_code_warn" hidden></p>

    <label for="address">사업장 소재지</label>
    <input type="text" id="address" name="address" value="<?= esc($val('address')) ?>">

    <label for="phone">연락처</label>
    <input type="text" id="phone" name="phone" value="<?= esc($val('phone')) ?>">

    <div class="checkbox">
        <input type="checkbox" id="is_manufacturing" name="is_manufacturing" value="1"
            <?= (old('is_manufacturing') ?? ($business['is_manufacturing'] ?? 0)) ? 'checked' : '' ?>>
        <label for="is_manufacturing" style="margin:0;">제조업 (재료매입·제조경비 계정 사용)</label>
    </div>

    <div class="actions">
        <button type="submit" class="btn"><?= $isEdit ? '수정' : '등록' ?></button>
        <a href="/admin/businesses" class="btn secondary">취소</a>
    </div>
</form>

<style>
    /* 업종코드 자동완성 */
    .ac-field { position: relative; }
    .ac-list {
        position: absolute; z-index: 20; left: 0; right: 0; top: 100%;
        max-height: 260px; overflow-y: auto;
        background: #fff; border: 1px solid #d1d5db; border-top: none;
        border-radius: 0 0 6px 6px; box-shadow: 0 6px 16px rgba(0, 0, 0, .08);
    }
    .ac-item { padding: 8px 10px; cursor: pointer; font-size: 14px; }
    .ac-item small { color: #6b7280; }
    .ac-item.is-active, .ac-item:hover { background: #eff6ff; }
    .ac-empty { padding: 8px 10px; color: #6b7280; font-size: 14px; }
    .ac-warn { margin: 4px 0 0; color: #dc2626; font-size: 13px; }
</style>

<script>
(function () {
    'use strict';
    var SEARCH_URL = <?= json_encode(site_url('admin/industry-codes/search')) ?>;
    var codeInput = document.getElementById('industry_code');
    var nameInput = document.getElementById('industry_name');
    var warnEl    = document.getElementById('industry_code_warn');

    // 코드 선택 시 두 필드 동시 채움
    function applyPick(code, name) {
        codeInput.value = code;
        nameInput.value = name;
        clearWarn();
    }

    function clearWarn() {
        warnEl.hidden = true;
        warnEl.textContent = '';
    }

    // 자동완성 요청(디바운스) — 응답을 지정한 목록 요소에 렌더링
    function attach(input, listEl) {
        var timer = null;
        var active = -1;

        function close() {
            listEl.hidden = true;
            listEl.innerHTML = '';
            active = -1;
        }

        function render(results) {
            listEl.innerHTML = '';
            if (!results.length) {
                var empty = document.createElement('div');
                empty.className = 'ac-empty';
                empty.textContent = '검색 결과가 없습니다.';
                listEl.appendChild(empty);
                listEl.hidden = false;
                return;
            }
            results.forEach(function (row) {
                var item = document.createElement('div');
                item.className = 'ac-item';
                // XSS 방지 — textContent 로만 조립
                var label = row.code + ' · ' + row.name;
                if (row.useful_life !== null && row.useful_life !== undefined) {
                    label += ' ';
                }
                item.textContent = label;
                if (row.useful_life !== null && row.useful_life !== undefined) {
                    var meta = document.createElement('small');
                    meta.textContent = '(내용연수 ' + row.useful_life + '년)';
                    item.appendChild(meta);
                }
                // blur 보다 먼저 실행되도록 mousedown 사용
                item.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    applyPick(row.code, row.name);
                    close();
                });
                listEl.appendChild(item);
            });
            active = -1;
            listEl.hidden = false;
        }

        function query(q) {
            fetch(SEARCH_URL + '?q=' + encodeURIComponent(q), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(function (res) { return res.ok ? res.json() : { results: [] }; })
                .then(function (data) { render(data.results || []); })
                .catch(function () { close(); });
        }

        input.addEventListener('input', function () {
            var q = input.value.trim();
            clearTimeout(timer);
            if (q.length < 1) { close(); return; }
            timer = setTimeout(function () { query(q); }, 250);
        });

        // 키보드 탐색(↑/↓/Enter/Esc)
        input.addEventListener('keydown', function (e) {
            var items = listEl.querySelectorAll('.ac-item');
            if (listEl.hidden || !items.length) { return; }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                active += (e.key === 'ArrowDown') ? 1 : -1;
                if (active < 0) { active = items.length - 1; }
                if (active >= items.length) { active = 0; }
                items.forEach(function (el, i) { el.classList.toggle('is-active', i === active); });
                items[active].scrollIntoView({ block: 'nearest' });
            } else if (e.key === 'Enter' && active >= 0) {
                e.preventDefault();
                items[active].dispatchEvent(new MouseEvent('mousedown'));
            } else if (e.key === 'Escape') {
                close();
            }
        });

        input.addEventListener('blur', function () {
            // 항목 클릭(mousedown) 처리 후 닫히도록 지연
            setTimeout(close, 150);
        });
    }

    // 주업종코드 blur 시 존재하지 않는 코드 경고(저장은 막지 않음)
    codeInput.addEventListener('blur', function () {
        var code = codeInput.value.trim();
        if (code === '') { clearWarn(); return; }
        setTimeout(function () {
            fetch(SEARCH_URL + '?q=' + encodeURIComponent(code), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            })
                .then(function (res) { return res.ok ? res.json() : { exists: true }; })
                .then(function (data) {
                    if (data.exists) {
                        clearWarn();
                    } else {
                        warnEl.textContent = '⚠ 표준산업분류에 없는 업종코드입니다. 목록에서 선택해 확인하세요.';
                        warnEl.hidden = false;
                    }
                })
                .catch(function () { clearWarn(); });
        }, 200);
    });

    document.querySelectorAll('[data-ac-search]').forEach(function (input) {
        attach(input, input.parentElement.querySelector('.ac-list'));
    });
}());
</script>
<?= $this->endSection() ?>
