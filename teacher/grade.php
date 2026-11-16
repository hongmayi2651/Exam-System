<?php
/**
 * 阅卷工作台
 * grade.php?exam_id=1&sid=5&qid=101
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
$u = require_login(array('teacher', 'admin'));

$examId = (int)get('exam_id');
$exam   = get_exam($examId);
if (!$exam) {
    exit('<div style="font:14px/1.8 Microsoft YaHei;padding:40px">考试不存在。<a href="index.php">返回</a></div>');
}

/* 已交卷的考生 */
$students = db_all(
    "SELECT es.*, u.username, u.name
       FROM exam_students es
       JOIN users u ON u.id = es.student_id
      WHERE es.exam_id = ? AND es.status IN ('submitted','graded')
      ORDER BY u.username ASC",
    array($examId)
);
if (!$students) {
    exit('<div style="font:14px/1.8 Microsoft YaHei;padding:40px">这场考试还没有人交卷。<a href="index.php?exam_id='
        . $examId . '">返回看板</a></div>');
}

/* 主观题列表（简答 + 实操） */
$subQs = db_all(
    "SELECT q.id, q.type, q.stem, q.rubric, q.ref_answer, q.pract_steps, eq.seq, eq.score
       FROM exam_questions eq JOIN questions q ON q.id = eq.question_id
      WHERE eq.exam_id = ? AND eq.section IN ('short','practical')
      ORDER BY eq.seq ASC",
    array($examId)
);
$totalQs = count($subQs);

/* 每个考生已评题数（一次性查出来，避免循环里反复查） */
$doneMap = array();
foreach (db_all('SELECT student_id, COUNT(*) c FROM grading WHERE exam_id = ? GROUP BY student_id', array($examId)) as $r) {
    $doneMap[(int)$r['student_id']] = (int)$r['c'];
}

/* 当前考生 */
$sid = (int)get('sid', 0);
$validSids = array_map(function ($s) { return (int)$s['student_id']; }, $students);
if ($sid <= 0 || !in_array($sid, $validSids, true)) {
    $sid = $validSids[0];
}
$student = null;
foreach ($students as $s) {
    if ((int)$s['student_id'] === $sid) { $student = $s; break; }
}

/* 该生各主观题的打分状态 */
$graded = array();
foreach (db_all('SELECT * FROM grading WHERE exam_id = ? AND student_id = ?', array($examId, $sid)) as $g) {
    $graded[(int)$g['question_id']] = $g;
}
$answers = student_answers($examId, $sid);
$atts    = array();
foreach (student_uploads($examId, $sid) as $f) {
    $atts[(int)$f['question_id']][] = $f;
}

/* 当前题目 */
$qid = (int)get('qid', 0);
$cur = null;
foreach ($subQs as $q) {
    if ((int)$q['id'] === $qid) { $cur = $q; break; }
}
if (!$cur && $subQs) {
    // 默认第一道未阅的
    foreach ($subQs as $q) {
        if (!isset($graded[(int)$q['id']])) { $cur = $q; break; }
    }
    if (!$cur) { $cur = $subQs[0]; }
}
$curId = $cur ? (int)$cur['id'] : 0;

$rubric     = $cur ? jdec($cur['rubric']) : array();
$myAnswer   = isset($answers[$curId]) ? (string)$answers[$curId]['answer_text'] : '';
$myAtts     = isset($atts[$curId]) ? $atts[$curId] : array();
$curGrading = isset($graded[$curId]) ? $graded[$curId] : null;
$itemScores = $curGrading ? jdec($curGrading['item_scores']) : array();

$gradedCount   = count($graded);
$doneStudents  = 0;
foreach ($students as $s) {
    if ($s['status'] === 'graded') { $doneStudents++; }
}
$f = take_flash();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>阅卷 · <?php echo h($exam['title']); ?></title>
<link rel="stylesheet" href="<?php echo h(base_url('assets/css/app.css')); ?>">
</head>
<body>

<div class="topbar">
  <div class="brand">阅卷<small><?php echo h($exam['title']); ?></small></div>
  <div class="spacer"></div>
  <span class="who">已阅 <?php echo $doneStudents; ?>/<?php echo count($students); ?> 人 ·
    当前考生主观题 <?php echo $gradedCount; ?>/<?php echo $totalQs; ?> 题</span>
  <a href="index.php?exam_id=<?php echo $examId; ?>">返回看板</a>
  <a href="<?php echo h(base_url('logout.php')); ?>">退出</a>
</div>

<?php if ($f): ?>
  <div style="padding:10px 22px 0"><div class="alert <?php echo h($f['type']); ?>" style="margin:0"><?php echo h($f['msg']); ?></div></div>
<?php endif; ?>

<div class="grade-layout">
  <!-- 左：学生 -->
  <div class="grade-list">
    <?php foreach ($students as $s):
        $done = isset($doneMap[(int)$s['student_id']]) ? $doneMap[(int)$s['student_id']] : 0;
    ?>
      <div class="item <?php echo (int)$s['student_id'] === $sid ? 'active' : ''; ?>"
           onclick="location.href='grade.php?exam_id=<?php echo $examId; ?>&sid=<?php echo (int)$s['student_id']; ?>'">
        <div>
          <div class="nm"><?php echo h($s['name']); ?></div>
          <div class="sub"><?php echo h($s['username']); ?></div>
        </div>
        <div class="right">
          <?php if ($done >= $totalQs && $totalQs > 0): ?>
            <span class="badge green">已完成</span>
          <?php else: ?>
            <span class="badge orange"><?php echo $done; ?>/<?php echo $totalQs; ?></span>
          <?php endif; ?>
          <div class="sub" style="margin-top:3px">总 <?php echo $s['score_total'] === null ? '—' : fmt_num($s['score_total']); ?></div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- 中：答案 -->
  <div class="grade-main">
    <?php if (!$cur): ?>
      <div class="card"><div class="alert info" style="margin:0">这份试卷没有主观题，无需人工阅卷。</div></div>
    <?php else: ?>
      <div class="card tight mb10">
        <div class="flex aic gap10" style="flex-wrap:wrap">
          <strong><?php echo h($student['name']); ?></strong>
          <span class="small muted"><?php echo h($student['username']); ?></span>
          <span class="badge blue">客观分 <?php echo fmt_num($student['score_objective']); ?></span>
          <span class="small muted">交卷 <?php echo h(fmt_dt($student['submit_at'], 'm-d H:i')); ?></span>
          <?php if ((int)$student['blur_count'] > 0): ?>
            <span class="badge orange">切屏 <?php echo (int)$student['blur_count']; ?> 次</span>
          <?php endif; ?>
          <a class="btn sm ghost" style="margin-left:auto" href="paper.php?exam_id=<?php echo $examId; ?>&sid=<?php echo $sid; ?>">查看答卷</a>
        </div>
      </div>

      <div class="card tight mb10">
        <div class="inline" style="gap:6px">
          <?php foreach ($subQs as $q):
              $k = (int)$q['id'];
              $isDone = isset($graded[$k]);
          ?>
            <a class="btn sm <?php echo $k === $curId ? '' : 'ghost'; ?>"
               style="<?php echo $isDone && $k !== $curId ? 'border-color:#12805c;color:#12805c' : ''; ?>"
               href="grade.php?exam_id=<?php echo $examId; ?>&sid=<?php echo $sid; ?>&qid=<?php echo $k; ?>">
              第<?php echo (int)$q['seq']; ?>题<?php echo $isDone ? ' ✓' : ''; ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="card">
        <div class="flex aic gap10 mb10" style="flex-wrap:wrap">
          <span class="badge blue"><?php echo h(type_label($cur['type'])); ?></span>
          <span class="small muted">第 <?php echo (int)$cur['seq']; ?> 题 · 满分
            <?php echo fmt_num($cur['score']); ?> 分</span>
        </div>
        <div class="stem" style="white-space:pre-wrap;margin-bottom:14px"><?php echo h($cur['stem']); ?></div>

        <?php if ($cur['type'] === 'practical' && $cur['pract_steps']): ?>
          <div class="card tight" style="background:#fbfcff;border:1px solid #e6eaf2;margin-bottom:14px">
            <div class="small" style="color:#5b6472;font-weight:600;margin-bottom:4px">任务要求</div>
            <div class="pre small"><?php echo h($cur['pract_steps']); ?></div>
          </div>
        <?php endif; ?>

        <div class="small" style="color:#5b6472;font-weight:600;margin-bottom:6px">
          学生作答<?php echo $cur['type'] === 'practical' ? '（操作说明）' : ''; ?>
        </div>
        <div class="answer-box <?php echo $myAnswer === '' ? 'empty' : ''; ?>"><?php echo $myAnswer === '' ? '（未作答）' : h($myAnswer); ?></div>

        <?php if ($myAtts): ?>
          <div class="small" style="color:#5b6472;font-weight:600;margin:16px 0 6px">
            提交的附件（<?php echo count($myAtts); ?> 个；图片点击放大，其它类型点击下载）
          </div>
          <div class="shots" id="teacherAtts">
            <?php foreach ($myAtts as $f2) { echo att_item_html($f2, false); } ?>
          </div>
        <?php elseif ($cur['type'] === 'practical'): ?>
          <div class="alert warn mt10">该生没有上传任何截图或附件。</div>
        <?php endif; ?>

        <?php if ($cur['ref_answer']): ?>
          <details style="margin-top:16px">
            <summary style="cursor:pointer;color:#1e4fa8;font-size:13px">查看参考答案（点击展开）</summary>
            <div class="answer-box mt10"><?php echo h($cur['ref_answer']); ?></div>
          </details>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- 右：评分 -->
  <div class="grade-side">
    <?php if ($cur): ?>
    <form method="post" action="grade_save.php" id="gradeForm">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="exam_id" value="<?php echo $examId; ?>">
      <input type="hidden" name="sid" value="<?php echo $sid; ?>">
      <input type="hidden" name="qid" value="<?php echo $curId; ?>">

      <h3 style="margin-bottom:4px">评分（满分 <?php echo fmt_num($cur['score']); ?>）</h3>
      <div class="small muted mb10">按评分点给分，系统自动合计。</div>

      <?php if ($rubric): ?>
        <?php foreach ($rubric as $i => $item):
            $val = isset($itemScores[$i]) ? $itemScores[$i] : (isset($itemScores[(string)$i]) ? $itemScores[(string)$i] : '');
            $max = isset($item['score']) ? (float)$item['score'] : 0;
        ?>
          <div class="rubric-item">
            <div class="t"><?php echo h($item['item']); ?></div>
            <div class="s">
              <input class="input score-input" type="number" step="0.5" min="0" max="<?php echo $max; ?>"
                     name="items[<?php echo $i; ?>]" value="<?php echo $val === '' ? '' : h($val); ?>"
                     data-max="<?php echo $max; ?>" placeholder="0">
              <span class="max">/ <?php echo fmt_num($max); ?> 分</span>
              <button type="button" class="btn sm ghost fill-btn" data-val="<?php echo $max; ?>">满分</button>
              <button type="button" class="btn sm ghost fill-btn" data-val="0">0</button>
            </div>
          </div>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="rubric-item">
          <div class="t">本题总分</div>
          <div class="s">
            <input class="input score-input" type="number" step="0.5" min="0"
                   max="<?php echo (float)$cur['score']; ?>" name="items[0]"
                   value="<?php echo $curGrading ? h($curGrading['score']) : ''; ?>"
                   data-max="<?php echo (float)$cur['score']; ?>" placeholder="0">
            <span class="max">/ <?php echo fmt_num($cur['score']); ?> 分</span>
          </div>
        </div>
      <?php endif; ?>

      <div class="card tight" style="background:#f8fafc;margin:14px 0">
        <div class="flex between aic">
          <span class="small" style="color:#5b6472">本题合计</span>
          <span style="font-size:20px;font-weight:700;color:#0e3f8c"><span id="sumScore">0</span>
            <small class="muted" style="font-size:12px">/ <?php echo fmt_num($cur['score']); ?></small>
          </span>
        </div>
      </div>

      <div class="form-row">
        <label class="lbl">评语（可选）</label>
        <textarea class="input" name="comment" rows="3" placeholder="例如：命令正确但未截图；/etc/fstab 里 UUID 写错。"><?php echo $curGrading ? h($curGrading['comment']) : ''; ?></textarea>
      </div>

      <div class="flex gap10">
        <button class="btn flex1" type="submit" name="next" value="1">保存并下一题</button>
        <button class="btn ghost" type="submit" name="next" value="0">仅保存</button>
      </div>

      <hr class="sep">
      <div class="small muted" style="line-height:1.9">
        快捷：输入框里按 <code>Tab</code> 可快速跳到下一个评分点，<code>Ctrl+Enter</code> 保存并进下一题。<br>
        客观分已在交卷时算好，这里只处理简答与实操。
      </div>
    </form>
    <?php endif; ?>
  </div>
</div>

<div class="lightbox" id="lightbox"><img src="" alt=""></div>

<script>
(function () {
  var lb = document.getElementById('lightbox');
  document.addEventListener('click', function (e) {
    if (e.target.tagName === 'IMG' && e.target.closest('.shot')) {
      lb.querySelector('img').src = e.target.src;
      lb.classList.add('show');
    } else if (e.target === lb || e.target.parentNode === lb) {
      lb.classList.remove('show');
    }
  });

  var inputs = document.querySelectorAll('.score-input');
  var sumEl = document.getElementById('sumScore');
  function recalc() {
    var s = 0;
    inputs.forEach(function (i) {
      var v = parseFloat(i.value);
      var max = parseFloat(i.getAttribute('data-max')) || 0;
      if (isNaN(v)) v = 0;
      if (v < 0) v = 0;
      if (max > 0 && v > max) { v = max; i.value = max; }
      s += v;
    });
    sumEl.textContent = (Math.round(s * 10) / 10).toString();
  }
  inputs.forEach(function (i) { i.addEventListener('input', recalc); });
  document.querySelectorAll('.fill-btn').forEach(function (b) {
    b.addEventListener('click', function () {
      var box = b.closest('.rubric-item').querySelector('.score-input');
      box.value = b.getAttribute('data-val');
      recalc();
    });
  });
  recalc();

  // 键盘：Ctrl+Enter 保存并下一题
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) {
      var f = document.getElementById('gradeForm');
      if (f) {
        var h = document.createElement('input');
        h.type = 'hidden'; h.name = 'next'; h.value = '1';
        f.appendChild(h); f.submit();
      }
    }
  });
})();
</script>
</body>
</html>
