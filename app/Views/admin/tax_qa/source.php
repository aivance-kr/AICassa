<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<h1>출처 원문 <span class="muted">— <?= esc($section['heading']) ?></span></h1>
<p class="page-desc">
    <?= esc($section['title']) ?> · <code><?= esc($section['doc']) ?></code>
</p>

<div class="card" style="max-width:900px;">
    <pre style="white-space:pre-wrap; word-break:break-word; margin:0; font-family:'Malgun Gothic',monospace; font-size:13px; line-height:1.6;"><?= esc($section['body']) ?></pre>
</div>

<div class="toolbar" style="margin-top:16px;">
    <a href="/admin/tax-qa" class="btn secondary">← 세무 Q&A로 돌아가기</a>
</div>
<?= $this->endSection() ?>
