<?php
/**
 * 学生答题页
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
$u = require_login('student');

$examId = (int)get('id');
$exam   = get_exam($examId);
if (!$exam) {
    exit('<div style="font:14px/1.8 Microsoft YaHei;padding:40px">考试不存在。</div>');
}
$es = get_exam_student($examId, $u['id']);
if (!$es) {
    exit('<div style="font:14px/1.8 Microsoft YaHei;padding:40px">这场考试没有分配给当前账号。</div>');
}
if (in_array($es['status'], array('submitted', 'graded'), true)) {
    redirect('student/result.php?id=' . $examId);
}
if ($exam['status'] !== 'running') {
    $t = $exam['status'] === 'ready' ? '尚未开考' : ($exam['status'] === 'finished' ? '考试已结束' : '考试未发布');
    exit('<div style="font:14px/1.8 Microsoft YaHei;padding:40px">' . htmlspecialchars($t) . '，暂时不能答题。<br><a href="index.php">返回</a></div>');
}

/* 首次进入：写入开始时刻与个人截止时刻 */
if ($es['status'] === 'assigned' || empty($es['start_at'])) {
    $startTs  = time();
    $deadline = $startTs + (int)$exam['duration_min'] * 60;
    if (!empty($exam['close_at']) && strtotime($exam['close_at']) < $deadline) {
        $deadline = strtotime($exam['close_at']);
    }
    db_exec('UPDATE exam_students SET status = ?, start_at = ?, deadline_at = ?, ip = ? WHERE id = ?',
        array('ongoing', date('Y-m-d H:i:s', $startTs), date('Y-m-d H:i:s', $deadline),
              isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null, $es['id']));
    log_event('start', '开始答题', $examId, $u['id']);
    $es = get_exam_student($examId, $u['id']);
}

$remaining = remaining_seconds($es, $exam);
if ($remaining <= 0) {
    /* 统一走 lib/grading.php 的收卷逻辑，避免在此处重复实现 */
    finalize_exam($examId, $u['id'], 'timeout');
    flash('考试时间已到，系统已自动交卷。', 'warn');
    redirect('student/result.php?id=' . $examId . '&auto=1');
}

$questions = exam_questions($examId);
$answers   = student_answers($examId, $u['id']);
$atts      = array();
foreach (student_uploads($examId, $u['id']) as $f) {
    $atts[(int)$f['question_id']][] = $f;
}

$sectionTitles = section_titles();
$lastSection   = '';

$totalScore = 0;
foreach ($questions as $q) {
    $totalScore += (float)$q['exam_score'];
}
$blurLimit = (int)setting('blur_limit', 5);
$upMax     = (int)setting('upload_max_count', $CONFIG['site']['upload_max_count']);
$upMb      = (int)setting('upload_max_mb', $CONFIG['site']['upload_max_mb']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>答题 · <?php echo h($exam['title']); ?></title>
<link rel="stylesheet" href="<?php echo h(base_url('assets/css/app.css')); ?>">
</head>
<body>

<div class="exam-head no-print">
  <div>
    <div class="title"><?php echo h($exam['title']); ?></div>
    <div class="meta">
      <?php echo h($u['name']); ?>　<?php echo h($u['username']); ?>
      共 <?php echo count($questions); ?> 题 / <?php echo fmt_num($totalScore); ?> 分
    </div>
  </div>
  <div class="spacer"></div>
  <div class="save-state" id="saveState">已自动保存</div>
  <div class="timer" id="timer">--:--</div>
  <button class="btn danger" id="btnSubmitTop" type="button">交 卷</button>
</div>

<div class="exam-layout">
  <div class="exam-main" id="examMain">

    <?php if ($exam['notice']): ?>
      <div class="card">
        <h3>考前须知</h3>
        <div class="pre small muted"><?php echo h($exam['notice']); ?></div>
      </div>
    <?php endif; ?>

    <?php foreach ($questions as $q):
        $qid   = (int)$q['id'];
        $a     = isset($answers[$qid]) ? (string)$answers[$qid]['answer_text'] : '';
        $aNorm = strtoupper(str_replace(array(' ', ',', '，'), '', $a));
        $myAtts = isset($atts[$qid]) ? $atts[$qid] : array();

        if ($q['section'] !== $lastSection) {
            $lastSection = $q['section'];
            echo '<h2 style="margin:22px 0 12px;font-size:16px;color:#0e3f8c;">'
               . h(isset($sectionTitles[$q['section']]) ? $sectionTitles[$q['section']] : '') . '</h2>';
        }
    ?>
    <div class="qcard <?php echo ($a !== '' || $myAtts) ? 'done' : ''; ?>" id="q<?php echo $qid; ?>"
         data-qid="<?php echo $qid; ?>" data-type="<?php echo h($q['type']); ?>">
      <div class="qhead">
        <span class="qno">第 <?php echo (int)$q['seq']; ?> 题</span>
        <span class="badge blue"><?php echo h(type_label($q['type'])); ?></span>
        <span class="qscore">本题 <?php echo fmt_num($q['exam_score']); ?> 分</span>
        <?php if ($q['topic']): ?><span class="badge gray"><?php echo h($q['topic']); ?></span><?php endif; ?>
      </div>

      <div class="stem"><?php echo h($q['stem']); ?></div>

      <?php if ($q['type'] === 'single'): ?>
        <?php foreach ($q['options'] as $op): ?>
          <label class="opt <?php echo $aNorm === strtoupper($op['k']) ? 'sel' : ''; ?>">
            <input type="radio" name="q<?php echo $qid; ?>" value="<?php echo h($op['k']); ?>"
                   <?php echo $aNorm === strtoupper($op['k']) ? 'checked' : ''; ?>>
            <span class="k"><?php echo h($op['k']); ?></span>
            <span><?php echo h($op['t']); ?></span>
          </label>
        <?php endforeach; ?>

      <?php elseif ($q['type'] === 'judge'): ?>
        <label class="opt <?php echo $aNorm === 'T' ? 'sel' : ''; ?>">
          <input type="radio" name="q<?php echo $qid; ?>" value="T" <?php echo $aNorm === 'T' ? 'checked' : ''; ?>>
          <span class="k">√</span><span>正确</span>
        </label>
        <label class="opt <?php echo $aNorm === 'F' ? 'sel' : ''; ?>">
          <input type="radio" name="q<?php echo $qid; ?>" value="F" <?php echo $aNorm === 'F' ? 'checked' : ''; ?>>
          <span class="k">×</span><span>错误</span>
        </label>

      <?php elseif ($q['type'] === 'short'): ?>
        <textarea class="input" rows="6" name="q<?php echo $qid; ?>"
                  placeholder="请在此作答，可分条书写。"><?php echo h($a); ?></textarea>

      <?php else: /* practical */ ?>
        <div class="card tight" style="background:#fbfcff;border:1px solid #e6eaf2;margin-bottom:14px">
          <div class="small" style="color:#5b6472;font-weight:600;margin-bottom:6px">任务要求</div>
          <div class="pre small"><?php echo h($q['pract_steps']); ?></div>
        </div>

        <?php if ($q['pract_shots']): ?>
          <div class="small" style="color:#5b6472;font-weight:600;margin-bottom:6px">需提交的截图 / 附件</div>
          <div class="shot-list" style="margin-bottom:12px">
            <?php foreach ($q['pract_shots'] as $s): ?>
              <span class="item"><?php echo h($s['no']); ?> <?php echo h($s['desc']); ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <textarea class="input" rows="4" name="q<?php echo $qid; ?>"
                  placeholder="简要写出你的操作思路、关键命令或遇到的报错（选填，但建议写，便于教师核对）。"><?php echo h($a); ?></textarea>

        <div class="att-block">
          <div class="att-title">
            我的附件
            <span class="att-hint">（截图可直接 Ctrl+V 粘贴到上面的输入框；也可上传任意类型文件）</span>
          </div>
          <div class="shots" id="shots<?php echo $qid; ?>">
            <?php foreach ($myAtts as $f) { echo att_item_html($f, true); } ?>
          </div>

          <label class="uploader" style="margin-top:10px">
            <input type="file" multiple class="file-input" data-qid="<?php echo $qid; ?>">
            <div class="big">点击选择文件上传</div>
            截图 / 文本 / 压缩包 / 文档都可以，单个不超过 <?php echo $upMb; ?>MB，本题最多 <?php echo $upMax; ?> 个
          </label>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="card tight center no-print">
      <button class="btn danger lg" id="btnSubmitBottom" type="button">交 卷</button>
      <div class="small muted mt10">交卷后不能再修改答案，请确认已全部作答。</div>
    </div>
  </div>

  <div class="exam-side no-print">
    <div class="qnav">
      <h3>答题卡（已答 <span id="answeredCnt">0</span>/<?php echo count($questions); ?>）</h3>
      <div class="grid" id="qnavGrid">
        <?php foreach ($questions as $q):
            $qid = (int)$q['id'];
            $a2  = isset($answers[$qid]) ? trim((string)$answers[$qid]['answer_text']) : '';
            $hasAtt = !empty($atts[$qid]);
        ?>
          <a href="#q<?php echo $qid; ?>" data-qid="<?php echo $qid; ?>"
             class="<?php echo ($a2 !== '' || $hasAtt) ? 'done' : ''; ?>"><?php echo (int)$q['seq']; ?></a>
        <?php endforeach; ?>
      </div>
      <div class="legend">
        <span><i style="background:#12805c"></i>已答</span>
        <span><i style="background:#f0f3f8;border:1px solid #ddd"></i>未答</span>
      </div>
      <div style="border-top:1px solid #eef1f6;padding-top:10px;font-size:12px;color:#8a94a6;line-height:1.9">
        开始时间：<?php echo h(fmt_dt($es['start_at'], 'H:i:s')); ?><br>
        截止时间：<?php echo h(fmt_dt($es['deadline_at'], 'H:i:s')); ?><br>
        切屏记录：<span id="blurCnt"><?php echo (int)$es['blur_count']; ?></span> 次
      </div>
    </div>

    <div class="card tight" style="margin-top:14px">
      <div class="small" style="color:#8a94a6;line-height:1.9">
        <strong style="color:#5b6472">实操题提示</strong><br>
        先在虚拟机里把服务跑起来，操作完成后截图。<br>
        截图要能看清<strong>命令与返回结果</strong>；文件名建议带题号与学号。
      </div>
    </div>
  </div>
</div>

<div class="lightbox" id="lightbox"><img src="" alt=""></div>

<script>
window.CSRF = <?php echo json_encode(csrf_token()); ?>;
window.EXAM = {
  base:      <?php echo json_encode(base_url('')); ?>,
  examId:    <?php echo $examId; ?>,
  remaining: <?php echo (int)$remaining; ?>,
  qcount:    <?php echo count($questions); ?>,
  blurLimit: <?php echo $blurLimit; ?>,
  upMax:     <?php echo $upMax; ?>,
  upMb:      <?php echo $upMb; ?>
};
</script>
<script src="<?php echo h(base_url('assets/js/exam.js')); ?>"></script>
</body>
</html>