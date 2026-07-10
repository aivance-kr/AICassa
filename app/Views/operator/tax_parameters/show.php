<?= $this->extend('operator/layout') ?>

<?= $this->section('content') ?>
<?php
    // 카테고리별 그룹핑(모델이 category, sort_order 순으로 정렬해 전달)
    $grouped = [];
    foreach ($params as $p) {
        $grouped[(string) $p['category']][] = $p;
    }
    $catLabel = static fn (string $c): string => (\App\Enums\TaxParameterCategory::tryFrom($c)?->label()) ?? $c;
?>
<div class="toolbar">
    <a href="/operator/tax-parameters" class="btn secondary">← 목록</a>
    <a href="/operator/tax-parameters/<?= (int) $year ?>/edit" class="btn"><?= esc($year) ?>년 값 수정</a>
</div>
<h1><?= esc($year) ?>년 세법 파라미터</h1>
<p class="page-desc">계산에 실제 적용되는 값입니다. 수정하려면 우측 상단 버튼을 사용하세요.</p>

<?php foreach ($grouped as $cat => $rows): ?>
    <h2 style="font-size:15px; margin:22px 0 8px;">
        <span class="cat"><?= esc($catLabel($cat)) ?></span>
    </h2>
    <table class="tbl">
        <thead>
            <tr>
                <th style="width:30%;">파라미터</th>
                <th style="width:35%;">값</th>
                <th>설명</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $p): ?>
                <?php $isJson = \App\Enums\TaxParameterType::from((string) $p['value_type'])->isComplex(); ?>
                <tr>
                    <td>
                        <strong><?= esc($p['label']) ?></strong><br>
                        <span class="muted"><code><?= esc($p['param_key']) ?></code></span>
                    </td>
                    <td>
                        <?php if ($isJson): ?>
                            <pre style="margin:0; white-space:pre-wrap; font-size:12px;"><?= esc(json_encode(json_decode((string) $p['param_value'], true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
                        <?php else: ?>
                            <?= esc($p['param_value']) ?><?php if ($p['unit'] !== null && $p['unit'] !== ''): ?><span class="unit"><?= esc($p['unit']) ?></span><?php endif; ?>
                        <?php endif; ?>
                    </td>
                    <td class="muted"><?= esc($p['description'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
<?php endforeach; ?>
<?= $this->endSection() ?>
