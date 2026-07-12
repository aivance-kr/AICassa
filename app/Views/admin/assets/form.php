<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<?php
    $bid    = $business['id'];
    $isEdit = $asset !== null;
    $action = $isEdit
        ? "/admin/businesses/{$bid}/assets/" . $asset['id']
        : "/admin/businesses/{$bid}/assets";
    $val = static fn (string $k, mixed $d = '') => old($k) ?? ($asset[$k] ?? $d);
?>
<h1><?= $isEdit ? '자산 수정' : '자산 등록' ?> <span class="muted">— <?= esc($business['name']) ?></span></h1>

<form class="card" method="post" action="<?= $action ?>">
    <?= csrf_field() ?>

    <label for="asset_type">자산종류 <span style="color:#dc2626">*</span></label>
    <select id="asset_type" name="asset_type" required>
        <option value="">선택</option>
        <?php foreach ($assetTypes as $t): ?>
            <option value="<?= esc($t['name']) ?>" <?= (string) $val('asset_type') === $t['name'] ? 'selected' : '' ?>><?= esc($t['name']) ?></option>
        <?php endforeach; ?>
    </select>

    <label for="name">자산명 <span style="color:#dc2626">*</span></label>
    <input type="text" id="name" name="name" value="<?= esc($val('name')) ?>" required>
    <button type="button" id="adviseBtn" class="btn secondary" style="margin-top:8px;">🤖 AI 제안(분류·상각방법·내용연수)</button>
    <small id="adviseHint" class="muted" style="display:block; margin-top:6px;"></small>

    <label for="acquired_at">취득일 <span style="color:#dc2626">*</span></label>
    <input type="date" id="acquired_at" name="acquired_at" value="<?= esc($val('acquired_at')) ?>" required>

    <label for="acquisition_cost">취득금액 <span style="color:#dc2626">*</span></label>
    <input type="text" id="acquisition_cost" name="acquisition_cost" inputmode="numeric" value="<?= esc($val('acquisition_cost')) ?>" required>

    <label for="depreciation_method">상각방법</label>
    <select id="depreciation_method" name="depreciation_method">
        <option value="">선택 안 함</option>
        <?php foreach ($methods as $m): ?>
            <option value="<?= $m->value ?>" <?= (string) $val('depreciation_method') === $m->value ? 'selected' : '' ?>><?= esc($m->label()) ?></option>
        <?php endforeach; ?>
    </select>

    <label for="useful_life">내용연수(년)</label>
    <input type="number" id="useful_life" name="useful_life" min="2" max="60"
           value="<?= esc($val('useful_life')) ?>"
           <?= $autoUsefulLife !== null ? 'placeholder="' . esc('업종 기준 자동 · ' . $autoUsefulLife . '년', 'attr') . '"' : '' ?>>
    <p class="muted" style="margin-top:4px;">
        상각방법을 선택하면 내용연수로 상각률이 자동 조회됩니다.
        <?php if ($autoUsefulLife !== null): ?>
            비워 두면 사업장 업종코드(<?= esc($business['industry_code']) ?>) 기준 <strong><?= esc((string) $autoUsefulLife) ?>년</strong>이 자동 적용됩니다.
        <?php else: ?>
            사업장 업종코드가 없으면 내용연수를 직접 입력해야 합니다.
        <?php endif; ?>
    </p>

    <label for="disposed_at">처분일(선택)</label>
    <input type="date" id="disposed_at" name="disposed_at" value="<?= esc($val('disposed_at')) ?>">

    <label for="disposal_amount">처분금액(선택)</label>
    <input type="text" id="disposal_amount" name="disposal_amount" inputmode="numeric" value="<?= esc($val('disposal_amount')) ?>">

    <div class="actions">
        <button type="submit" class="btn"><?= $isEdit ? '수정' : '등록' ?></button>
        <a href="/admin/businesses/<?= $bid ?>/assets" class="btn secondary">취소</a>
    </div>
</form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
// ── 자산명 → 분류·상각방법·내용연수·소액자산 AI 제안(초안) ──────────────
const ADVISE_URL = '/admin/businesses/<?= (int) $bid ?>/assets/advise';
const CSRF_INPUT = document.querySelector('input[name="<?= csrf_token() ?>"]');
const adviseBtn      = document.getElementById('adviseBtn');
const adviseHint     = document.getElementById('adviseHint');
const nameInput      = document.getElementById('name');
const assetTypeSel   = document.getElementById('asset_type');
const methodSel      = document.getElementById('depreciation_method');
const usefulLifeInput = document.getElementById('useful_life');
const costInput      = document.getElementById('acquisition_cost');
const acquiredInput  = document.getElementById('acquired_at');
const SOURCE_LABEL = { history: '과거 이력', ai: 'AI 추천', none: '자동 판정' };

adviseBtn.addEventListener('click', async () => {
    const name = nameInput.value.trim();
    if (!name) {
        adviseHint.textContent = '자산명을 먼저 입력하세요.';
        return;
    }

    const body = new FormData();
    body.append('name', name);
    body.append('acquisition_cost', costInput.value);
    body.append('acquired_at', acquiredInput.value);

    adviseBtn.disabled = true;
    adviseHint.textContent = 'AI 제안 요청 중…';
    try {
        const res = await fetch(ADVISE_URL, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF_INPUT.value },
            body,
        });
        const data = await res.json();
        if (data.csrf_hash) {
            CSRF_INPUT.value = data.csrf_hash; // 매 POST마다 재생성되는 토큰 갱신
        }
        if (!res.ok) {
            adviseHint.textContent = (data.error && data.error.message) || '제안을 가져오지 못했습니다.';
            return;
        }
        applyAdvice(data);
    } catch (e) {
        adviseHint.textContent = '제안을 가져오지 못했습니다.';
    } finally {
        adviseBtn.disabled = false;
    }
});

// 제안은 초안(draft)일 뿐 — 사용자가 이미 고른 값은 덮어쓰지 않는다.
function applyAdvice(d) {
    if (d.asset_type && !assetTypeSel.value) {
        const opt = [...assetTypeSel.options].find(o => o.value === d.asset_type);
        if (opt) assetTypeSel.value = d.asset_type;
    }
    if (d.depreciation_method && !methodSel.value) {
        methodSel.value = d.depreciation_method;
    }
    if (d.useful_life && !usefulLifeInput.value) {
        usefulLifeInput.value = String(d.useful_life);
    }

    const parts = [];
    const label = SOURCE_LABEL[d.source] || '제안';
    if (d.asset_type) {
        parts.push(`${label}: 분류 "${d.asset_type}"`);
    }
    if (d.useful_life) {
        parts.push(`내용연수 ${d.useful_life}년(업종기준)`);
    }
    if (d.is_low_value) {
        const won = Number(d.low_value_threshold).toLocaleString();
        parts.push(`⚠ 취득금액이 소액자산 한도(${won}원) 이하 — 자산 등록 대신 즉시비용(비용) 처리를 검토하세요`);
    }
    adviseHint.textContent = parts.length ? parts.join(' · ') + ' (확인 후 저장하세요)' : '제안할 내용이 없습니다.';
}
</script>
<?= $this->endSection() ?>
