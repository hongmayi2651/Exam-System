<?php
/**
 * 修改密码（所有角色通用）
 */
require __DIR__ . '/lib/bootstrap.php';
$u = require_login();

$err = '';
$ok  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $old  = post('old');
    $new  = post('new');
    $new2 = post('new2');

    if ($old === '' || $new === '') {
        $err = '请填写完整';
    } elseif (!password_verify($old, $u['password'])) {
        $err = '原密码不正确';
    } elseif (strlen($new) < 6) {
        $err = '新密码至少 6 位';
    } elseif ($new !== $new2) {
        $err = '两次输入的新密码不一致';
    } else {
        db_exec('UPDATE users SET password = ?, must_change_pwd = 0 WHERE id = ?',
            array(password_hash($new, PASSWORD_DEFAULT), $u['id']));
        $ok = '密码已修改';
        $u['must_change_pwd'] = 0;
        log_event('password', '用户修改密码');
    }
}

$homeMap = array('admin' => 'admin/index.php', 'teacher' => 'teacher/index.php', 'student' => 'student/index.php');
$home = isset($homeMap[$u['role']]) ? $homeMap[$u['role']] : 'index.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>修改密码</title>
<link rel="stylesheet" href="<?php echo h(base_url('assets/css/app.css')); ?>">
</head>
<body>
<div class="topbar">
  <div class="brand">修改密码<small><?php echo h(setting('site_name')); ?></small></div>
  <div class="spacer"></div>
  <span class="who"><?php echo h($u['name']); ?>（<?php echo h($u['username']); ?>）</span>
  <a href="<?php echo h(base_url($home)); ?>">返回首页</a>
  <a href="<?php echo h(base_url('logout.php')); ?>">退出</a>
</div>

<div class="wrap-narrow">
  <div class="card">
    <h2>修改密码</h2>
    <?php if ($u['must_change_pwd']): ?>
      <div class="alert warn">首次登录必须修改初始密码后才能继续使用。</div>
    <?php endif; ?>
    <?php if ($err): ?><div class="alert err"><?php echo h($err); ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="alert ok"><?php echo h($ok); ?>，<a href="<?php echo h(base_url($home)); ?>">返回首页</a></div><?php endif; ?>

    <form method="post">
      <?php echo csrf_field(); ?>
      <div class="form-row">
        <label class="lbl">原密码</label>
        <input class="input" type="password" name="old" required>
      </div>
      <div class="form-row">
        <label class="lbl">新密码</label>
        <input class="input" type="password" name="new" required>
        <div class="hint">至少 6 位，建议字母加数字</div>
      </div>
      <div class="form-row">
        <label class="lbl">确认新密码</label>
        <input class="input" type="password" name="new2" required>
      </div>
      <button class="btn" type="submit">保存</button>
      <a class="btn ghost" href="<?php echo h(base_url($home)); ?>">返回</a>
    </form>
  </div>
</div>
</body>
</html>
