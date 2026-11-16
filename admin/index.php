<?php
/**
 * 管理后台首页：考试管理
 *
 * ★ 已加固：进行中的考试不允许删除（会破坏正在答题的考生的状态）
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
$u = require_login(array('admin'));

/* 删除考试 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'del') {
    csrf_check();
    $id = (int)post('id');
    $target = get_exam($id);

    if (!$target) {
        flash('考试不存在', 'err');
        redirect('admin/index.php');
    }
    /* 进行中的考试不允许删除：会中断正在答题的学生的计时与答卷 */
    if ($target['status'] === 'running') {
        flash('这场考试正在进行中，不能删除。请先在考试看板里点「统一收卷」。', 'err');
        redirect('admin/index.php');
    }

    $files = db_all('SELECT file_path FROM uploads WHERE exam_id = ?', array($id));
    foreach ($files as $f) {
        $abs = APP_ROOT . '/uploads/' . $f['file_path'];
        if (is_file($abs)) @unlink($abs);
    }
    db_exec('DELETE FROM uploads WHERE exam_id = ?', array($id));
    db_exec('DELETE FROM grading WHERE exam_id = ?', array($id));
    db_exec('DELETE FROM answers WHERE exam_id = ?', array($id));
    db_exec('DELETE FROM exam_students WHERE exam_id = ?', array($id));
    db_exec('DELETE FROM exam_questions WHERE exam_id = ?', array($id));
    db_exec('DELETE FROM exams WHERE id = ?', array($id));
    log_event('admin', '删除考试 #' . $id);
    flash('考试已删除（含答案与附件）', 'ok');
    redirect('admin/index.php');
}

$exams = db_all('SELECT e.*,
    (SELECT COUNT(*) FROM exam_questions eq WHERE eq.exam_id = e.id) AS qcnt,
    (SELECT COALESCE(SUM(eq.score),0) FROM exam_questions eq WHERE eq.exam_id = e.id) AS qscore,
    (SELECT COUNT(*) FROM exam_students es WHERE es.exam_id = e.id) AS scnt
    FROM exams e ORDER BY e.id DESC');

$qcntAll = (int)db_val('SELECT COUNT(*) FROM questions', array(), 0);
$uCnt    = (int)db_val("SELECT COUNT(*) FROM users WHERE role='student'", array(), 0);
$statusText = array('draft' => array('未发布', 'gray'), 'ready' => array('待开考', 'orange'),
                    'running' => array('考试中', 'green'), 'finished' => array('已结束', 'gray'));

/* 各题型题量 */
$typeCnt = array();
foreach (db_all('SELECT type, COUNT(*) c FROM questions GROUP BY type') as $r) {
    $typeCnt[$r['type']] = (int)$r['c'];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>管理后台</title>
<link rel="stylesheet" href="<?php echo h(base_url('assets/css/app.css')); ?>">
</head>
<body>
<div class="topbar">
  <div class="brand">管理后台<small><?php echo h(setting('site_name')); ?></small></div>
  <div class="spacer"></div>
  <span class="who"><?php echo h($u['name']); ?>（管理员）</span>
  <a href="<?php echo h(base_url('teacher/index.php')); ?>">考试看板</a>
  <a href="<?php echo h(base_url('password.php')); ?>">改密码</a>
  <a href="<?php echo h(base_url('logout.php')); ?>">退出</a>
</div>

<div class="wrap">
  <?php $f = take_flash(); if ($f): ?>
    <div class="alert <?php echo h($f['type']); ?>"><?php echo h($f['msg']); ?></div>
  <?php endif; ?>

  <div class="stats">
    <div class="stat"><div class="k">考试场次</div><div class="v"><?php echo count($exams); ?></div></div>
    <div class="stat"><div class="k">题库总量</div><div class="v"><?php echo $qcntAll; ?><small>题</small></div></div>
    <?php foreach (type_list() as $t): ?>
      <div class="stat">
        <div class="k"><?php echo h(type_label($t)); ?></div>
        <div class="v"><?php echo isset($typeCnt[$t]) ? $typeCnt[$t] : 0; ?><small>题</small></div>
      </div>
    <?php endforeach; ?>
    <div class="stat"><div class="k">学生账号</div><div class="v"><?php echo $uCnt; ?><small>人</small></div></div>
  </div>

  <div class="card tight flex gap10" style="flex-wrap:wrap">
    <a class="btn" href="exam_edit.php">+ 新建考试</a>
    <a class="btn ghost" href="questions.php">题库管理</a>
    <a class="btn ghost" href="users.php">用户与班级</a>
    <a class="btn ghost" href="q_import.php">导入题库</a>
    <a class="btn ghost" href="settings.php">系统设置</a>
  </div>

  <div class="card">
    <h2>考试列表</h2>
    <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>#</th><th>考试名称</th><th class="center">状态</th>
          <th class="center">题量</th><th class="center">卷面分</th><th class="center">考生</th>
          <th class="center">时长</th><th class="center">开考时间</th><th class="center">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$exams): ?>
        <tr><td colspan="9" class="center muted">还没有考试，点上面的「新建考试」开始。</td></tr>
      <?php endif; ?>
      <?php foreach ($exams as $e):
          $st = isset($statusText[$e['status']]) ? $statusText[$e['status']] : array($e['status'], 'gray');
          $isRunning = ($e['status'] === 'running');
      ?>
        <tr>
          <td class="muted"><?php echo (int)$e['id']; ?></td>
          <td>
            <strong><?php echo h($e['title']); ?></strong>
            <div class="small muted"><?php echo h($e['subject']); ?></div>
          </td>
          <td class="center"><span class="badge <?php echo $st[1]; ?>"><?php echo h($st[0]); ?></span></td>
          <td class="center"><?php echo (int)$e['qcnt']; ?></td>
          <td class="center"><?php echo fmt_num($e['qscore']); ?></td>
          <td class="center"><?php echo (int)$e['scnt']; ?></td>
          <td class="center"><?php echo (int)$e['duration_min']; ?>′</td>
          <td class="center small"><?php echo h(fmt_dt($e['open_at'] ? $e['open_at'] : $e['plan_start'])); ?></td>
          <td class="center nowrap">
            <a class="btn sm ghost" href="exam_edit.php?id=<?php echo (int)$e['id']; ?>">编辑/组卷</a>
            <a class="btn sm ghost" href="<?php echo h(base_url('teacher/index.php?exam_id=' . (int)$e['id'])); ?>">看板</a>
            <?php if ($isRunning): ?>
              <span class="small muted" title="考试进行中，不能删除。请先在考试看板里点「统一收卷」。">进行中</span>
            <?php else: ?>
              <form method="post" style="display:inline" onsubmit="return confirm('删除后本场考试的所有答卷、评分与附件都会一并删除，且不可恢复。确定吗？')">
                  <?php echo csrf_field(); ?>
                  <input type="hidden" name="do" value="del">
                  <input type="hidden" name="id" value="<?php echo (int)$e['id']; ?>">
                  <button class="btn sm danger" type="submit">删除</button>
              </form>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <div class="card tight">
    <div class="small muted" style="line-height:2">
      工作顺序建议：① 题库管理里确认题目齐全 → ② 新建考试，用「整卷快捷组卷 / 按题型批量选题」组卷 → ③ 分配考生 →
      ④ 考试前到看板点「开考」 → ⑤ 收卷后阅卷、导出成绩。
    </div>
  </div>
</div>
</body>
</html>