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
</script>
<?= $this->endSection() ?>
