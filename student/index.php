<?php
/**
 * 学生首页：我的考试
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
$u = require_login('student');

$rows = db_all(
    "SELECT e.*, es.status AS my_status, es.start_at, es.submit_at,
            es.deadline_at, es.blur_count,
            (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id = e.id) AS qcount
       FROM exam_students es
       JOIN exams e ON e.id = es.exam_id
      WHERE es.student_id = ?
      ORDER BY e.open_at DESC, e.id DESC",
    array($u['id'])
);

$statusMap = array(
    'draft'    => array('未发布', 'gray'),
    'ready'    => array('待开考', 'orange'),
    'running'  => array('考试中', 'green'),
    'finished' => array('已结束', 'gray'),
);
$myMap = array(
    'assigned'  => array('未开始', 'gray'),
    'ongoing'   => array('答题中', 'orange'),
    'submitted' => array('已交卷', 'blue'),
    'graded'    => array('已交卷', 'blue'),
);
$upMb  = (int)setting('upload_max_mb', $CONFIG['site']['upload_max_mb']);
$upCnt = (int)setting('upload_max_count', $CONFIG['site']['upload_max_count']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>我的考试</title>
<link rel="stylesheet" href="<?php echo h(base_url('assets/css/app.css')); ?>">
</head>
<body>
<div class="topbar">
  <div class="brand">Linux 考试系统<small><?php echo h(setting('site_name')); ?></small></div>
  <div class="spacer"></div>
  <span class="who"><?php echo h($u['name']); ?>（<?php echo h($u['username']); ?>）</span>
  <a href="<?php echo h(base_url('password.php')); ?>">改密码</a>
  <a href="<?php echo h(base_url('logout.php')); ?>">退出</a>
</div>

<div class="wrap">
  <?php $f = take_flash(); if ($f): ?>
    <div class="alert <?php echo h($f['type']); ?>"><?php echo h($f['msg']); ?></div>
  <?php endif; ?>

  <div class="card">
    <h2>我的考试<span class="sub">共 <?php echo count($rows); ?> 场</span></h2>

    <?php if (!$rows): ?>
      <div class="alert info">当前没有分配给你的考试。如果应该有，请联系任课教师。</div>
    <?php else: ?>
      <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>考试名称</th>
            <th class="center">题量</th>
            <th class="center">时长</th>
            <th class="center">分值</th>
            <th class="center">考试状态</th>
            <th class="center">我的状态</th>
            <th class="center">操作</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $st = isset($statusMap[$r['status']]) ? $statusMap[$r['status']] : array($r['status'], 'gray');
            $ms = isset($myMap[$r['my_status']]) ? $myMap[$r['my_status']] : array($r['my_status'], 'gray');
        ?>
          <tr>
            <td>
              <strong><?php echo h($r['title']); ?></strong>
              <div class="small muted"><?php echo h($r['subject']); ?></div>
            </td>
            <td class="center"><?php echo (int)$r['qcount']; ?></td>
            <td class="center"><?php echo (int)$r['duration_min']; ?> 分钟</td>
            <td class="center"><?php echo fmt_num($r['total_score']); ?></td>
            <td class="center"><span class="badge <?php echo $st[1]; ?>"><?php echo h($st[0]); ?></span></td>
            <td class="center"><span class="badge <?php echo $ms[1]; ?>"><?php echo h($ms[0]); ?></span></td>
            <td class="center nowrap">
              <?php if ($r['my_status'] === 'assigned'): ?>
                <?php if ($r['status'] === 'running'): ?>
                  <a class="btn sm" href="exam.php?id=<?php echo (int)$r['id']; ?>">进入考试</a>
                <?php else: ?>
                  <span class="muted small">未开考</span>
                <?php endif; ?>
              <?php elseif ($r['my_status'] === 'ongoing'): ?>
                <a class="btn sm danger" href="exam.php?id=<?php echo (int)$r['id']; ?>">继续答题</a>
              <?php else: ?>
                <span class="muted small">—</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    <?php endif; ?>
  </div>

  <div class="card tight">
    <h3>考试须知</h3>
    <ol class="small muted" style="margin:0;padding-left:20px;line-height:2">
      <li>开考后计时开始，中途不可暂停；时间到系统自动交卷。</li>
      <li>答案边答边自动保存，浏览器意外关闭后重新登录可继续答题，<strong>计时不会重置</strong>。</li>
      <li>实操题在本人虚拟机（CentOS 8）中完成任务：既可以<strong>直接 Ctrl+V 粘贴截图</strong>，也可以<strong>上传任意类型附件</strong>
          （单个不超过 <?php echo $upMb; ?>MB，每题最多 <?php echo $upCnt; ?> 个）。</li>
      <li>附件名建议统一带上题号与学号，例如 <code>4-1_命令结果_学号.png</code>，便于教师核对。</li>
      <li>切换窗口会被记录，累计超限将提示监考教师。</li>
      <li>交卷后不能再修改答案；交卷页只显示交卷成功，试卷内容与成绩均不展示，成绩由任课教师统一公布。</li>
    </ol>
  </div>
</div>
</body>
</html>
