<?php
/**
 * 试卷预览：以学生答题页样式只读展示整张试卷（教师 / 管理员）
 * preview.php?exam_id=5
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
$u = require_login(array('teacher', 'admin'));

$examId = (int)get('exam_id');
$exam   = get_exam($examId);
if (!$exam) {
    exit('<div style="font:14px/1.8 Microsoft YaHei;padding:40px">考试不存在。'
        . '<a href="index.php">返回</a></div>');
}

$questions = exam_questions($examId);
if (!$questions) {
    exit('<div style="font:14px/1.8 Microsoft YaHei;padding:40px">这张试卷还没有题目。'
        . '<a href="index.php?exam_id=' . $examId . '">返回看板</a></div>');
}

/* 本系统只有四种题型，直接复用 lib/helpers.php 的统一映射，避免编号漂移 */
$sectionTitle = section_titles();
$totalScore = 0;
foreach ($questions as $q) {
    $totalScore += (float)$q['exam_score'];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>试卷预览 · <?php echo h($exam['title']); ?></title>
<link rel="stylesheet" href="<?php echo h(base_url('assets/css/app.css')); ?>">
</head>
<body>

<div class="exam-head no-print">
  <div>
    <div class="title">【预览】<?php echo h($exam['title']); ?></div>
    <div class="meta">
      预览模式 · 共 <?php echo count($questions); ?> 题 /
      <?php echo rtrim(rtrim(number_format($totalScore, 1), '0'), '.'); ?> 分 ·
      时长 <?php echo (int)$exam['duration_min']; ?> 分钟
    </div>
  </div>
  <div class="spacer"></div>
  <a class="btn ghost" href="index.php?exam_id=<?php echo $examId; ?>">返回看板</a>
  <a class="btn ghost" href="grade.php?exam_id=<?php echo $examId; ?>">进入阅卷</a>
  <button class="btn ghost" type="button" onclick="window.print()">打印 / 另存 PDF</button>
</div>

<div class="exam-layout">
  <div class="exam-main">

    <?php if ($exam['notice']): ?>
      <div class="card">
        <h3>考前须知</h3>
        <div class="pre small muted"><?php echo h($exam['notice']); ?></div>
      </div>
    <?php endif; ?>

    <?php $lastSection = ''; ?>
    <?php foreach ($questions as $idx => $q):
        $qid  = (int)$q['id'];
        $score = rtrim(rtrim(number_format((float)$q['exam_score'], 1), '0'), '.');

        if ($q['section'] !== $lastSection) {
            $lastSection = $q['section'];
            echo '<h2 style="margin:22px 0 12px;font-size:16px;color:#0e3f8c;">'
               . h(isset($sectionTitle[$q['section']]) ? $sectionTitle[$q['section']] : '')
               . '</h2>';
        }
    ?>
    <div class="qcard" id="q<?php echo $qid; ?>">
      <div class="qhead">
        <span class="qno">第 <?php echo (int)$q['seq']; ?> 题</span>
        <span class="badge blue"><?php echo h(type_label($q['type'])); ?></span>
        <span class="qscore">满分 <?php echo $score; ?> 分</span>
        <?php if ($q['topic']): ?><span class="badge gray"><?php echo h($q['topic']); ?></span><?php endif; ?>
      </div>

      <div class="stem"><?php echo h($q['stem']); ?></div>

      <?php if ($q['type'] === 'single'): ?>
        <?php foreach ($q['options'] as $op): ?>
          <div class="opt">
            <span class="k"><?php echo h($op['k']); ?></span>
            <span><?php echo h($op['t']); ?></span>
          </div>
        <?php endforeach; ?>

      <?php elseif ($q['type'] === 'judge'): ?>
        <div class="opt"><span class="k">√</span><span>正确</span></div>
        <div class="opt"><span class="k">×</span><span>错误</span></div>

      <?php elseif ($q['type'] === 'short'): ?>
        <textarea class="input" rows="6" readonly
                  placeholder="请在此处作答，可分条书写。"></textarea>

      <?php else: /* practical */ ?>
        <?php if ($q['pract_steps']): ?>
          <div class="card tight" style="background:#fbfcff;border:1px solid #e6eaf2;margin-bottom:14px">
            <div class="small" style="color:#5b6472;font-weight:600;margin-bottom:4px">任务要求</div>
            <div class="pre small"><?php echo h($q['pract_steps']); ?></div>
          </div>
        <?php endif; ?>

        <?php if ($q['pract_shots']): ?>
          <div class="small" style="color:#5b6472;font-weight:600;margin-bottom:6px">需提交的截图 / 附件</div>
          <div class="shot-list" style="margin-bottom:12px">
            <?php foreach ($q['pract_shots'] as $s): ?>
              <span class="item"><?php echo h($s['no']); ?> <?php echo h($s['desc']); ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <textarea class="input" rows="4" readonly
                  placeholder="简要写出你的操作思路或遇到的报错（选填，但建议写，便于教师核对）。"></textarea>

        <div class="small muted" style="margin-top:10px">此处为学生上传截图 / 附件区域（预览不显示上传控件）</div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="card tight center no-print" style="margin-top:18px">
      <a class="btn" href="index.php?exam_id=<?php echo $examId; ?>">返回看板</a>
      <button class="btn ghost" type="button" onclick="window.print()">打印 / 另存 PDF</button>
    </div>

  </div>
</div>

</body>
</html>