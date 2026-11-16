<?php
/**
 * 教师首页：考试看板
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
$u = require_login(array('teacher', 'admin'));

$examList = db_all('SELECT * FROM exams ORDER BY id DESC');
$examId   = (int)get('exam_id', 0);
if ($examId === 0 && $examList) {
    $examId = (int)$examList[0]['id'];
}
$exam = $examId ? get_exam($examId) : null;

$students = array();
$stat = array('all' => 0, 'ongoing' => 0, 'submitted' => 0, 'graded' => 0, 'notstart' => 0, 'avg' => '—', 'blur' => 0);
if ($exam) {
    $students = db_all(
        "SELECT es.*, u.username, u.name, c.name AS class_name
           FROM exam_students es
           JOIN users u ON u.id = es.student_id
           LEFT JOIN classes c ON c.id = u.class_id
          WHERE es.exam_id = ?
          ORDER BY u.username ASC",
        array($examId)
    );
    $scoreSum = 0; $scoreCnt = 0;
    foreach ($students as &$s) {
        $stat['all']++;
        if ($s['status'] === 'assigned') $stat['notstart']++;
        if ($s['status'] === 'ongoing')   $stat['ongoing']++;
        if ($s['status'] === 'submitted') $stat['submitted']++;
        if ($s['status'] === 'graded')    $stat['graded']++;
        if ($s['blur_count'] > 0)         $stat['blur']++;
        if ($s['score_total'] !== null) { $scoreSum += (float)$s['score_total']; $scoreCnt++; }
        $s['remaining'] = $s['status'] === 'ongoing' ? remaining_seconds($s, $exam) : -1;
    }
    unset($s);
    if ($scoreCnt > 0) {
        $stat['avg'] = round($scoreSum / $scoreCnt, 1);
    }
}

$statusMap = array(
    'assigned'  => array('未开始', 'gray'),
    'ongoing'   => array('答题中', 'orange'),
    'submitted' => array('已交卷·待阅', 'blue'),
    'graded'    => array('已阅卷', 'green'),
);
$examStatus = array(
    'draft'    => array('未发布', 'gray'),
    'ready'    => array('待开考', 'orange'),
    'running'  => array('考试中', 'green'),
    'finished' => array('已结束', 'gray'),
);
$es = $exam && isset($examStatus[$exam['status']]) ? $examStatus[$exam['status']] : array('—', 'gray');
$blurLimit = (int)setting('blur_limit', 5);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>考试看板</title>
<link rel="stylesheet" href="<?php echo h(base_url('assets/css/app.css')); ?>">
</head>
<body>
<div class="topbar">
  <div class="brand">考试看板<small><?php echo h(setting('site_name')); ?></small></div>
  <div class="spacer"></div>
  <span class="who"><?php echo h($u['name']); ?>（<?php echo $u['role'] === 'admin' ? '管理员' : '教师'; ?>）</span>
  <a href="<?php echo h(base_url('admin/index.php')); ?>">管理后台</a>
  <a href="<?php echo h(base_url('password.php')); ?>">改密码</a>
  <a href="<?php echo h(base_url('logout.php')); ?>">退出</a>
</div>

<div class="wrap">
  <?php $f = take_flash(); if ($f): ?>
    <div class="alert <?php echo h($f['type']); ?>"><?php echo h($f['msg']); ?></div>
  <?php endif; ?>

  <div class="card tight">
    <form class="inline" method="get">
      <label class="lbl" style="margin:0">选择考试</label>
      <select class="input" name="exam_id" onchange="this.form.submit()" style="min-width:320px">
        <?php foreach ($examList as $e): ?>
          <option value="<?php echo (int)$e['id']; ?>" <?php echo (int)$e['id'] === $examId ? 'selected' : ''; ?>>
            <?php echo h($e['title']); ?>（<?php echo h($e['status']); ?>）
          </option>
        <?php endforeach; ?>
      </select>
      <?php if ($exam): ?>
        <span class="badge <?php echo $es[1]; ?>"><?php echo h($es[0]); ?></span>
        <span class="small muted">
          时长 <?php echo (int)$exam['duration_min']; ?> 分钟 ·
          开考 <?php echo h(fmt_dt($exam['open_at'])); ?> ·
          收卷 <?php echo h(fmt_dt($exam['close_at'])); ?>
        </span>
      <?php endif; ?>
    </form>
  </div>

  <?php if (!$exam): ?>
    <div class="alert info">还没有任何考试。请先到 <a href="<?php echo h(base_url('admin/exam_edit.php')); ?>">管理后台</a> 创建考试。</div>
  <?php else: ?>

    <div class="stats">
      <div class="stat"><div class="k">应考人数</div><div class="v"><?php echo $stat['all']; ?></div></div>
      <div class="stat"><div class="k">答题中</div><div class="v"><?php echo $stat['ongoing']; ?></div></div>
      <div class="stat"><div class="k">已交卷</div><div class="v"><?php echo $stat['submitted'] + $stat['graded']; ?></div></div>
      <div class="stat"><div class="k">已阅卷</div><div class="v"><?php echo $stat['graded']; ?></div></div>
      <div class="stat"><div class="k">平均分</div><div class="v"><?php echo $stat['avg']; ?></div></div>
      <div class="stat"><div class="k">有切屏记录</div><div class="v"><?php echo $stat['blur']; ?></div></div>
    </div>

    <div class="card tight flex gap10 aic" style="flex-wrap:wrap">
      <strong class="small">考试控制：</strong>
      <?php if ($exam['status'] === 'draft'): ?>
        <span class="small muted">考试尚未发布试卷，请先编辑考试并发布。</span>
      <?php elseif ($exam['status'] === 'ready'): ?>
        <form method="post" action="exam_action.php" style="display:inline">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="exam_id" value="<?php echo $examId; ?>">
          <input type="hidden" name="do" value="open">
          <button class="btn" onclick="return confirm('现在开考？全体考生将开始计时 <?php echo (int)$exam['duration_min']; ?> 分钟。')">开 考</button>
        </form>
        <span class="small muted">开考后计时开始，无法暂停。</span>
      <?php elseif ($exam['status'] === 'running'): ?>
        <form method="post" action="exam_action.php" style="display:inline">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="exam_id" value="<?php echo $examId; ?>">
          <input type="hidden" name="do" value="close">
          <button class="btn danger" onclick="return confirm('收卷后将强制提交所有未交卷考生的答卷，确定吗？')">统一收卷</button>
        </form>
        <span class="small muted">或等到 <?php echo h(fmt_dt($exam['close_at'], 'H:i')); ?> 系统自动收卷。</span>
        <form method="post" action="exam_action.php" class="inline" style="display:inline">
          <?php echo csrf_field(); ?>
          <input type="hidden" name="exam_id" value="<?php echo $examId; ?>">
          <input type="hidden" name="do" value="reopen">
          <input class="input sm" type="number" name="add_min" value="10" style="width:70px" title="延长分钟数">
          <button class="btn sm ghost" type="submit">延长</button>
        </form>
      <?php else: ?>
        <span class="badge gray">考试已结束</span>
      <?php endif; ?>
      <div class="spacer" style="flex:1"></div>
      <a class="btn ghost" href="export.php?exam_id=<?php echo $examId; ?>&fmt=csv">导出成绩 CSV</a>
      <a class="btn ghost" href="export.php?exam_id=<?php echo $examId; ?>&fmt=xls">导出成绩 Excel</a>
      <a class="btn ghost" href="preview.php?exam_id=<?php echo $examId; ?>">试卷预览</a>
      <a class="btn ghost" href="pack_papers.php?exam_id=<?php echo $examId; ?>">打包下载全班答卷</a>
      <a class="btn" href="grade.php?exam_id=<?php echo $examId; ?>">进入阅卷</a>
    </div>

    <div class="card">
      <h2>考生列表<span class="sub">点击「阅卷」进入该生答卷</span></h2>
      <div class="table-wrap">
      <table class="table">
        <thead>
          <tr>
            <th>学号</th><th>姓名</th><th>班级</th>
            <th class="center">状态</th>
            <th class="center">开始</th>
            <th class="center">交卷</th>
            <th class="center">剩余</th>
            <th class="num">客观分</th>
            <th class="num">主观分</th>
            <th class="num">总分</th>
            <th class="center">附件</th>
            <th class="center">切屏</th>
            <th class="center">操作</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$students): ?>
          <tr><td colspan="13" class="center muted">这场考试还没有分配考生</td></tr>
        <?php endif; ?>
        <?php foreach ($students as $s):
            $st = isset($statusMap[$s['status']]) ? $statusMap[$s['status']] : array($s['status'], 'gray');
            $attCnt = (int)db_val('SELECT COUNT(*) FROM uploads WHERE exam_id = ? AND student_id = ?',
                array($examId, (int)$s['student_id']), 0);
        ?>
          <tr>
            <td><?php echo h($s['username']); ?></td>
            <td><strong><?php echo h($s['name']); ?></strong></td>
            <td class="small muted"><?php echo h($s['class_name']); ?></td>
            <td class="center"><span class="badge <?php echo $st[1]; ?>"><?php echo h($st[0]); ?></span></td>
            <td class="center small"><?php echo h(fmt_dt($s['start_at'], 'H:i:s')); ?></td>
            <td class="center small"><?php echo h(fmt_dt($s['submit_at'], 'H:i:s')); ?></td>
            <td class="center small">
              <?php
                if ($s['status'] === 'ongoing') {
                    echo $s['remaining'] > 0 ? fmt_countdown($s['remaining']) : '<span class="badge red">已超时</span>';
                } else {
                    echo '<span class="muted">—</span>';
                }
              ?>
            </td>
            <td class="num"><?php echo fmt_num($s['score_objective']); ?></td>
            <td class="num"><?php echo $s['score_subjective'] === null ? '<span class="muted">—</span>' : fmt_num($s['score_subjective']); ?></td>
            <td class="num">
              <?php echo $s['score_total'] === null ? '<span class="muted">—</span>' : '<strong style="color:#c0392b">' . fmt_num($s['score_total']) . '</strong>'; ?>
            </td>
            <td class="center small">
              <?php echo $attCnt > 0 ? '<span class="badge blue">' . $attCnt . '</span>' : '<span class="muted">0</span>'; ?>
            </td>
            <td class="center">
              <?php if ((int)$s['blur_count'] > 0): ?>
                <span class="badge <?php echo (int)$s['blur_count'] > $blurLimit ? 'red' : 'orange'; ?>">
                  <?php echo (int)$s['blur_count']; ?>
                </span>
              <?php else: ?><span class="muted">0</span><?php endif; ?>
            </td>
            <td class="center nowrap">
              <?php if ($s['status'] === 'submitted' || $s['status'] === 'graded'): ?>
                <a class="btn sm ghost" href="grade.php?exam_id=<?php echo $examId; ?>&sid=<?php echo (int)$s['student_id']; ?>">阅卷</a>
              <?php elseif ($s['status'] === 'ongoing'): ?>
                <form method="post" action="exam_action.php" style="display:inline"
                      onsubmit="return confirm('强制收该考生的卷？')">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="exam_id" value="<?php echo $examId; ?>">
                  <input type="hidden" name="sid" value="<?php echo (int)$s['student_id']; ?>">
                  <input type="hidden" name="do" value="force_submit">
                  <button class="btn sm danger" type="submit">强制交卷</button>
                </form>
              <?php else: ?>
                <span class="muted small">未开始</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>

    <div class="card tight">
      <div class="small muted">
        说明：单选与判断交卷即自动判分；简答与实操需教师按评分点逐项打分。全部主观题评完后状态自动变为「已阅卷」并汇总总分。
        导出 Excel 为 <code>.xls</code>（HTML 表格格式），可直接用 Excel / WPS 打开另存为 xlsx。
      </div>
    </div>

  <?php endif; ?>
</div>
</body>
</html>
