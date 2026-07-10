<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<?php
    $bid    = $business['id'];
    $isEdit = $entry !== null;
    $action = $isEdit
        ? "/admin/businesses/{$bid}/ledger/" . $entry['id']
        : "/admin/businesses/{$bid}/ledger";
    $val = static fn (string $k, mixed $d = '') => old($k) ?? ($entry[$k] ?? $d);
    $curType     = (string) $val('entry_type', 'expense');
    $curAccount  = (string) $val('account_id');
    $curEvidence = (string) $val('evidence_type', 'tax_invoice');
    $curPartner  = (string) $val('partner_id');
?>
<h1><?= $isEdit ? '거래 수정' : '거래 입력' ?> <span class="muted">— <?= esc($business['name']) ?></span></h1>

<form class="card" method="post" action="<?= $action ?>">
    <?= csrf_field() ?>
    <input type="hidden" id="receipt_path" name="receipt_path" value="<?= esc($val('receipt_path')) ?>">

    <fieldset style="border:1px solid #e5e7eb; border-radius:8px; padding:12px 16px; margin-bottom:16px;">
        <legend class="muted" style="padding:0 6px;">영수증·세금계산서 사진으로 자동입력</legend>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <input type="file" id="receipt_file" accept="image/jpeg,image/png,image/webp">
            <button type="button" id="scanBtn" class="btn secondary">AI 판독</button>
            <span id="scanStatus" class="muted"></span>
        </div>
        <p class="muted" style="margin:8px 0 0;">사진을 올리고 <strong>AI 판독</strong>을 누르면 아래 항목이 자동으로 채워집니다. 내용을 사진과 대조·확인한 뒤 저장하세요.</p>
    </fieldset>

    <label>구분 <span style="color:#dc2626">*</span></label>
    <div style="display:flex; gap:16px;">
        <label class="checkbox" style="margin:0;">
            <input type="radio" name="entry_type" value="income" <?= $curType === 'income' ? 'checked' : '' ?>> 수입
        </label>
        <label class="checkbox" style="margin:0;">
            <input type="radio" name="entry_type" value="expense" <?= $curType === 'expense' ? 'checked' : '' ?>> 비용
        </label>
    </div>

    <label for="entry_date">일자 <span style="color:#dc2626">*</span></label>
    <input type="date" id="entry_date" name="entry_date" value="<?= esc($val('entry_date')) ?>" required>

    <label for="account_id">계정과목</label>
    <select id="account_id" name="account_id"><!-- JS로 채움 --></select>

    <label for="description">거래내용 <span style="color:#dc2626">*</span></label>
    <input type="text" id="description" name="description" value="<?= esc($val('description')) ?>" required>

    <label for="partner_id">거래처</label>
    <select id="partner_id" name="partner_id">
        <option value="">선택 안 함</option>
        <?php foreach ($partners as $p): ?>
            <option value="<?= (int) $p['id'] ?>" <?= $curPartner === (string) $p['id'] ? 'selected' : '' ?>><?= esc($p['name']) ?></option>
        <?php endforeach; ?>
    </select>

    <label for="supply_amount">공급가액 <span style="color:#dc2626">*</span></label>
    <input type="text" id="supply_amount" name="supply_amount" inputmode="numeric" value="<?= esc($val('supply_amount')) ?>" required>

    <label for="evidence_type">증빙유형(비고)</label>
    <select id="evidence_type" name="evidence_type">
        <?php foreach ($evidenceTypes as $ev): ?>
            <option value="<?= $ev->value ?>" <?= $curEvidence === $ev->value ? 'selected' : '' ?>><?= esc($ev->label()) ?></option>
        <?php endforeach; ?>
    </select>

    <p class="muted" style="margin-top:12px;">
        부가세(자동계산): <strong id="vatPreview">0</strong> 원
        <br>과세증빙(세금계산서·신용카드·현금영수증)만 공급가액의 10%가 부과됩니다.
    </p>

    <div class="actions">
        <button type="submit" class="btn"><?= $isEdit ? '수정' : '입력' ?></button>
        <a href="/admin/businesses/<?= $bid ?>/ledger" class="btn secondary">취소</a>
    </div>
</form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
const ACCOUNTS = {
    income: <?= json_encode(array_map(static fn ($a) => ['id' => (int) $a['id'], 'name' => $a['name']], $incomeAccounts)) ?>,
    expense: <?= json_encode(array_map(static fn ($a) => ['id' => (int) $a['id'], 'name' => $a['name']], $expenseAccounts)) ?>,
};
// 면세 증빙: 계산서/간이영수증/기타
const EXEMPT = ['invoice', 'simple_receipt', 'other'];
const CUR_ACCOUNT = <?= json_encode($curAccount) ?>;

const accountSelect = document.getElementById('account_id');
function renderAccounts() {
    const type = document.querySelector('input[name=entry_type]:checked').value;
    const list = ACCOUNTS[type] || [];
    accountSelect.innerHTML = '<option value="">선택 안 함</option>'
        + list.map(a => `<option value="${a.id}" ${String(a.id) === CUR_ACCOUNT ? 'selected' : ''}>${a.name}</option>`).join('');
}
function updateVat() {
    const raw = (document.getElementById('supply_amount').value || '').replace(/[, ]/g, '');
    const amount = parseInt(raw, 10) || 0;
    const ev = document.getElementById('evidence_type').value;
    const vat = EXEMPT.includes(ev) ? 0 : Math.trunc(Math.abs(amount) / 10) * (amount < 0 ? -1 : 1);
    document.getElementById('vatPreview').textContent = vat.toLocaleString();
}
document.querySelectorAll('input[name=entry_type]').forEach(r => r.addEventListener('change', renderAccounts));
document.getElementById('supply_amount').addEventListener('input', updateVat);
document.getElementById('evidence_type').addEventListener('change', updateVat);
renderAccounts();
updateVat();

// ── 영수증 사진 AI 판독 → 폼 자동채움 ──────────────────────────────
const RECOGNIZE_URL = '/admin/businesses/<?= (int) $bid ?>/ledger/receipts/recognize';
const CSRF_INPUT = document.querySelector('input[name="<?= csrf_token() ?>"]');
const scanBtn = document.getElementById('scanBtn');
const scanStatus = document.getElementById('scanStatus');

function setStatus(msg, isError = false) {
    scanStatus.textContent = msg;
    scanStatus.style.color = isError ? '#dc2626' : '';
}

scanBtn.addEventListener('click', async () => {
    const file = document.getElementById('receipt_file').files[0];
    if (!file) {
        setStatus('먼저 사진 파일을 선택하세요.', true);
        return;
    }

    const body = new FormData();
    body.append('receipt', file);

    scanBtn.disabled = true;
    setStatus('판독 중…');

    try {
        const res = await fetch(RECOGNIZE_URL, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF_INPUT.value },
            body,
        });
        const data = await res.json();

        // CSRF 토큰은 매 POST마다 재생성되므로 새 값으로 갱신(이후 저장 제출 실패 방지).
        if (data.csrf_hash) {
            CSRF_INPUT.value = data.csrf_hash;
        }
        if (!res.ok) {
            setStatus((data.error && data.error.message) || '판독에 실패했습니다.', true);
            return;
        }

        applyResult(data);
    } catch (e) {
        setStatus('판독 요청 중 오류가 발생했습니다.', true);
    } finally {
        scanBtn.disabled = false;
    }
});

function applyResult(data) {
    setStatus('판독 완료 — 내용을 사진과 대조·확인하세요.');

    // 구분 → 계정과목 목록 재구성이 선행돼야 account_id 선택이 유효
    const typeRadio = document.querySelector(`input[name=entry_type][value=${data.entry_type}]`);
    if (typeRadio) {
        typeRadio.checked = true;
    }
    renderAccounts();

    if (data.entry_date) {
        document.getElementById('entry_date').value = data.entry_date;
    }
    document.getElementById('description').value = data.description || '';
    document.getElementById('supply_amount').value = data.supply_amount || '';
    if (data.evidence_type) {
        document.getElementById('evidence_type').value = data.evidence_type;
    }
    document.getElementById('receipt_path').value = data.receipt_path || '';

    accountSelect.value = data.account_id ? String(data.account_id) : '';

    const partnerSelect = document.getElementById('partner_id');
    if (data.partner_id) {
        partnerSelect.value = String(data.partner_id);
    } else {
        partnerSelect.value = '';
        if (data.partner_name) {
            setStatus(`미등록 거래처: "${data.partner_name}" — 필요 시 직접 등록하세요.`, true);
        }
    }

    updateVat();
}
</script>
<?= $this->endSection() ?>
