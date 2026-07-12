<?= $this->extend('admin/layout') ?>

<?= $this->section('content') ?>
<h1>세무 Q&A <span class="muted">— 간편장부 도우미</span></h1>
<p class="page-desc">
    프로젝트 문서를 근거로 답하는 세무 도우미입니다. 답변에는 항상 출처가 표시되며,
    근거 문서에서 확인되지 않는 내용은 <strong>"확인 불가"</strong>로 응답합니다(참고용 — 최종 판단은 세무 전문가 확인).
</p>

<?= csrf_field() ?>

<div id="qaThread" style="margin-bottom:16px;"></div>

<form id="qaForm" class="card" style="max-width:760px;">
    <label for="question">질문</label>
    <textarea id="question" name="question" rows="2" required
              placeholder="예) 간편장부 대상자 기준이 뭐야? / 세금계산서 부가세는 어떻게 계산돼?"
              style="width:100%; padding:8px 10px; border:1px solid var(--border); border-radius:6px; font-size:14px; resize:vertical;"></textarea>
    <div class="actions">
        <button type="submit" id="askBtn" class="btn">질문하기</button>
    </div>
    <p class="muted" style="margin-top:12px;">
        근거 문서: <?= esc(implode(', ', $docs)) ?>
    </p>
</form>
<?= $this->endSection() ?>

<?= $this->section('scripts') ?>
<script>
const ASK_URL = '/admin/tax-qa/ask';
const SOURCE_URL = '/admin/tax-qa/source';
const CSRF_INPUT = document.querySelector('input[name="<?= csrf_token() ?>"]');
const qaForm = document.getElementById('qaForm');
const questionInput = document.getElementById('question');
const askBtn = document.getElementById('askBtn');
const thread = document.getElementById('qaThread');

function bubble(cls, build) {
    const box = document.createElement('div');
    box.className = 'card';
    box.style.cssText = 'max-width:760px; margin-bottom:10px;' + cls;
    build(box);
    thread.appendChild(box);
    box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    return box;
}

function renderAnswer(box, data) {
    box.innerHTML = '';
    const p = document.createElement('div');
    p.style.whiteSpace = 'pre-wrap';
    p.textContent = data.answer || '';
    if (!data.answerable) {
        p.style.color = '#991b1b';
    }
    box.appendChild(p);

    if (data.answerable && Array.isArray(data.sources) && data.sources.length) {
        const src = document.createElement('div');
        src.className = 'muted';
        src.style.marginTop = '10px';
        src.appendChild(document.createTextNode('출처: '));
        data.sources.forEach((s, i) => {
            if (i > 0) src.appendChild(document.createTextNode(' · '));
            const a = document.createElement('a');
            a.href = SOURCE_URL + '?doc=' + encodeURIComponent(s.doc) + '&anchor=' + encodeURIComponent(s.anchor);
            a.target = '_blank';
            a.rel = 'noopener';
            a.textContent = s.title + ' › ' + s.heading;
            src.appendChild(a);
        });
        box.appendChild(src);
    }
}

qaForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const question = questionInput.value.trim();
    if (!question) return;

    bubble('background:#f1f5f9;', (box) => {
        const q = document.createElement('div');
        q.style.fontWeight = '600';
        q.textContent = 'Q. ' + question;
        box.appendChild(q);
    });

    const answerBox = bubble('', (box) => { box.textContent = '답변 생성 중…'; box.className += ' muted'; });
    questionInput.value = '';
    askBtn.disabled = true;

    try {
        const body = new FormData();
        body.append('question', question);
        const res = await fetch(ASK_URL, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF_INPUT.value },
            body,
        });
        const data = await res.json();
        if (data.csrf_hash) CSRF_INPUT.value = data.csrf_hash;
        answerBox.className = 'card';
        answerBox.style.cssText = 'max-width:760px; margin-bottom:10px;';
        if (!res.ok) {
            renderAnswer(answerBox, { answerable: false, answer: (data.error && data.error.message) || '오류가 발생했습니다.' });
        } else {
            renderAnswer(answerBox, data);
        }
    } catch (err) {
        answerBox.className = 'card';
        answerBox.textContent = '답변을 가져오지 못했습니다.';
    } finally {
        askBtn.disabled = false;
        questionInput.focus();
    }
});
</script>
<?= $this->endSection() ?>
