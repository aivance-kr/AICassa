<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<?php $bid = $business['id']; ?>
<h1>컬럼 매핑 확인 <span class="muted">— <?= esc($business['name']) ?></span></h1>
<p class="page-desc">
    업로드 파일: <strong><?= esc($sourceName) ?></strong> ·
    각 열을 장부 표준 항목에 연결하세요. <strong>*</strong> 는 필수 항목입니다.
    (AI/자동 제안이 미리 선택되어 있으며, 잘못된 곳은 직접 바꾸면 됩니다.)
</p>

<?php if ($error !== null): ?>
    <div class="flash err"><?= esc($error) ?></div>
<?php endif; ?>

<form method="post" action="/admin/businesses/<?= $bid ?>/ledger/import/confirm">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= esc($token, 'attr') ?>">

    <table class="tbl" style="margin-bottom:16px;">
        <thead>
            <tr>
                <th style="width:220px;">원본 열</th>
                <th>샘플 값(앞 <?= count($samples) ?>행)</th>
                <th style="width:220px;">장부 표준 항목</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($headers as $i => $header): ?>
            <?php $selected = $assignments[$i] ?? null; ?>
            <tr>
                <td>
                    <span class="muted">열 <?= (int) $i + 1 ?></span><br>
                    <strong><?= $header === '' ? '<span class="muted">(빈 헤더)</span>' : esc($header) ?></strong>
                </td>
                <td class="muted">
                    <?php $vals = []; ?>
                    <?php foreach ($samples as $row): ?>
                        <?php $vals[] = esc((string) ($row[$i] ?? '')); ?>
                    <?php endforeach; ?>
                    <?= implode(' · ', array_filter($vals, static fn ($v): bool => $v !== '')) ?: '—' ?>
                </td>
                <td>
                    <select name="mapping[<?= (int) $i ?>]" style="width:100%; padding:6px 8px; border:1px solid var(--border); border-radius:6px;">
                        <option value="">(무시)</option>
                        <?php foreach ($fields as $key => $meta): ?>
                            <option value="<?= esc($key, 'attr') ?>" <?= $selected === $key ? 'selected' : '' ?>>
                                <?= esc($meta['label']) ?><?= $meta['required'] ? ' *' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p class="muted" style="margin-bottom:16px;">
        · 부가세는 비고(증빙유형) 기준으로 자동계산됩니다.<br>
        · 계정과목·거래처가 비어 있으면 이력 기반 자동분류로 초안을 채웁니다(거래처는 미등록 시 해당 행 건너뜀).
    </p>

    <div class="toolbar">
        <button type="submit" class="btn">이대로 등록</button>
        <a href="/admin/businesses/<?= $bid ?>/ledger/import" class="btn secondary">다시 업로드</a>
    </div>
</form>
<?= $this->endSection() ?>
