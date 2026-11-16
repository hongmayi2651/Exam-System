<?php
/**
 * 学生交卷结果页
 * 只显示交卷成功与基本信息，不展示试卷内容、不展示成绩。
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
$u = require_login('student');

$examId = (int)get('id');
$exam   = get_exam($examId);
$es     = get_exam_student($examId, $u['id']);
if (!$exam || !$es) {
    exit('<div style="font:14px/1.8 Microsoft YaHei;padding:40px">没有找到这场考试。</div>');
}
// 还没交卷的不允许进这个页面
if (!in_array($es['status'], array('submitted', 'graded'), true)) {
    redirect('student/exam.php?id=' . $examId);
}

$auto = get('auto') === '1';
$fl   = take_flash();

$usedMin = '';
if ($es['start_at'] && $es['submit_at']) {
    $usedMin = round((strtotime($es['submit_at']) - strtotime($es['start_at'])) / 60) . ' 分钟';
}
$tmap = array('manual' => '主动交卷', 'timeout' => '超时自动交卷', 'force' => '监考教师收卷');
$typeText = isset($tmap[$es['submit_type']]) ? $tmap[$es['submit_type']] : '已交卷';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>交卷成功</title>
<link rel="stylesheet" href="<?php echo h(base_url('assets/css/app.css')); ?>">
<style>
.done-wrap{max-width:620px;margin:0 auto;padding:56px 22px}
.done-card{background:#fff;border-radius:12px;box-shadow:0 2px 18px rgba(20,40,80,.08);padding:40px 40px 32px;text-align:center}
.done-icon{
  width:76px;height:76px;margin:0 auto 22px;border-radius:50%;background:#e8f7f0;
  display:flex;align-items:center;justify-content:center;
}
.done-icon svg{width:38px;height:38px}
.done-card h1{font-size:24px;margin-bottom:8px;color:#12805c}
.done-card .lead{color:#5b6472;font-size:14px;line-height:1.9;margin-bottom:26px}
.info{text-align:left;background:#f8fafc;border-radius:8px;padding:16px 20px;font-size:13.5px}
.info .row{display:flex;padding:7px 0;border-bottom:1px dashed #e6eaf2}
.info .row:last-child{border-bottom:none}
.info .k{width:96px;flex:none;color:#8a94a6}
.info .v{flex:1;color:#1f2430}
.tip{margin-top:22px;font-size:12.5px;color:#8a94a6;line-height:1.9}
.acts{margin-top:26px;display:flex;gap:10px;justify-content:center}
</style>
</head>
<body>
<div class="topbar">
  <div class="brand">交卷结果<small><?php echo h(setting('site_name')); ?></small></div>
  <div class="spacer"></div>
  <span class="who"><?php echo h($u['name']); ?>（<?php echo h($u['username']); ?>）</span>
  <a href="index.php">我的考试</a>
  <a href="<?php echo h(base_url('logout.php')); ?>">退出</a>
</div>

<div class="done-wrap">
  <div class="done-card">
    <div class="done-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="#12805c" stroke-width="2.6"
           stroke-linecap="round" stroke-linejoin="round">
        <polyline points="4 12.5 9.5 18 20 6.5"></polyline>
      </svg>
    </div>

    <h1>交卷成功</h1>
    <div class="lead">
      <?php if ($fl): ?>
        <?php echo h($fl['msg']); ?>
      <?php elseif ($auto): ?>
        考试时间已到，系统已自动为你交卷。
      <?php else: ?>
        你的答卷已提交，本次考试结束。
      <?php endif; ?>
    </div>

    <div class="info">
      <div class="row"><div class="k">考试科目</div><div class="v"><?php echo h($exam['subject']); ?></div></div>
      <div class="row"><div class="k">考试名称</div><div class="v"><?php echo h($exam['title']); ?></div></div>
      <div class="row"><div class="k">交卷时间</div><div class="v"><?php echo h(fmt_dt($es['submit_at'], 'Y-m-d H:i:s')); ?></div></div>
      <?php if ($usedMin !== ''): ?>
        <div class="row"><div class="k">答题用时</div><div class="v"><?php echo h($usedMin); ?></div></div>
      <?php endif; ?>
      <div class="row"><div class="k">交卷方式</div><div class="v"><?php echo h($typeText); ?></div></div>
    </div>

    <div class="tip">
      答卷已锁定，不能再修改。<br>
      成绩由任课教师评阅后统一公布，本页面不展示试卷内容与分数。
    </div>

    <div class="acts">
      <a class="btn ghost" href="index.php">返回我的考试</a>
      <a class="btn gray" href="<?php echo h(base_url('logout.php')); ?>">退出登录</a>
    </div>
  </div>
</div>
</body>
</html>
