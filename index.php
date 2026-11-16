<?php
/**
 * 登录页
 */
require __DIR__ . '/lib/bootstrap.php';

$err = '';

// 已登录直接进对应首页
$u = current_user();
if ($u) {
    if ($u['role'] === 'admin')   redirect('admin/index.php');
    if ($u['role'] === 'teacher') redirect('teacher/index.php');
    redirect('student/index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = post('username');
    $password = post('password');
    if ($username === '' || $password === '') {
        $err = '请输入账号和密码';
    } else {
        list($ok, $msg, $user) = do_login($username, $password);
        if ($ok) {
            $go = isset($_SESSION['redirect_after_login']) ? $_SESSION['redirect_after_login'] : '';
            unset($_SESSION['redirect_after_login']);
            if ($user['must_change_pwd'] && (int)setting('force_pwd_change', 1) === 1) {
                flash('首次登录，请先修改密码。', 'warn');
                redirect('password.php');
            }
            if ($go !== '' && strpos($go, '//') === false) {
                header('Location: ' . $go);
                exit;
            }
            if ($user['role'] === 'admin')   redirect('admin/index.php');
            if ($user['role'] === 'teacher') redirect('teacher/index.php');
            redirect('student/index.php');
        }
        $err = $msg;
    }
}

if (get('err') === 'expired') {
    $err = '登录状态已失效，请重新登录';
}

$siteName = setting('site_name', 'Linux 服务器配置与管理 期末考试');
$school   = setting('school_name', '');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>登录 · <?php echo h($siteName); ?></title>
<link rel="stylesheet" href="<?php echo h(base_url('assets/css/app.css')); ?>">
</head>
<body class="login-body">

<div class="login-card">
  <div class="logo"><span class="mark">LX</span></div>
  <h1><?php echo h($siteName); ?></h1>
  <div class="desc"><?php echo h($school); ?>　考试系统</div>

  <?php if ($err !== ''): ?>
    <div class="alert err"><?php echo h($err); ?></div>
  <?php endif; ?>
  <?php if (get('err') === 'logout'): ?>
    <div class="alert ok">已安全退出</div>
  <?php endif; ?>

  <form method="post" autocomplete="off">
    <?php echo csrf_field(); ?>
    <div class="form-row">
      <input class="input" type="text" name="username" placeholder="学号 / 工号" required autofocus
             value="<?php echo h(post('username')); ?>">
    </div>
    <div class="form-row">
      <input class="input" type="password" name="password" placeholder="密码" required>
    </div>
    <button class="btn lg" type="submit">登 录</button>
  </form>

  <div class="login-foot">
    开考后计时不可暂停，时间到系统自动交卷<br>
    忘记密码请联系监考教师
  </div>
</div>

</body>
</html>
