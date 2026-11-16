<?php
/**
 * 环境自检 / 首次初始化
 * 部署完成后先访问这个页面。
 *
 * 「重置初始密码」需要管理员已登录并带 CSRF token，避免未授权访问本文件即可重置密码。
 * 用法：先登录 admin，再访问  install_check.php?init=1&_token=xxx
 *      （token 从登录后的页面表单里取，或直接在后台「系统设置」页的任一个表单里看）
 *
 * 注意：本文件不 require lib/config.php —— bootstrap 已经加载过，重复 require 会重复定义函数直接报错。
 */
require __DIR__ . '/lib/bootstrap.php';

$checks = array();
$fatal  = false;
$pdo    = null;

/* ---------------- 配置来源 ---------------- */
$cfgPath = app_config_path();
$usingTpl = ($cfgPath !== null && basename($cfgPath) === 'config.example.php');

/* ---------------- 各项检查 ---------------- */
$phpOk = version_compare(PHP_VERSION, '7.0.0', '>=');
$checks[] = array('PHP 版本', PHP_VERSION, $phpOk, '需要 7.0 及以上，推荐 7.4 / 8.0 / 8.1 / 8.2');
if (!$phpOk) { $fatal = true; }

foreach (array('pdo_mysql' => 'PDO MySQL 扩展', 'mbstring' => 'mbstring 扩展',
               'fileinfo' => 'fileinfo 扩展（识别上传文件真实类型，建议）') as $ext => $label) {
    $ok = extension_loaded($ext);
    $optional = ($ext === 'fileinfo');
    $checks[] = array($label, $ok ? '已安装' : '未安装', $ok || $optional, $optional ? '可选，不影响主流程' : '必须安装');
    if (!$ok && !$optional) { $fatal = true; }
}

$upDir = APP_ROOT . '/uploads';
if (!is_dir($upDir)) {
    @mkdir($upDir, 0777, true);
}
$writable = is_dir($upDir) && is_writable($upDir);
$checks[] = array('uploads 目录可写', $writable ? '是' : '否', $writable, '实操题附件上传依赖此目录');
if (!$writable) { $fatal = true; }

$checks[] = array('配置文件', $usingTpl ? 'config.example.php（模板）' : 'config/config.php', !$usingTpl,
    $usingTpl ? '⚠ 请先复制为 config/config.php 并填入真实数据库账号密码' : '使用真实配置');

/* ---------------- 数据库连接 + 数据表完整性 ---------------- */
$dbOk = false;
$dbMsg = '';
$tables = array();
if ($cfgPath !== null) {
    $c = $CONFIG['db'];
    try {
        $dsn = 'mysql:host=' . $c['host'] . ';port=' . $c['port'] . ';dbname=' . $c['name'] . ';charset=' . $c['charset'];
        $pdo = new PDO($dsn, $c['user'], $c['pass'], array(PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $dbOk = true;
        $dbMsg = '连接成功';
        $rows = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $tables = $rows;
    } catch (Exception $e) {
        $dbMsg = $usingTpl ? '数据库连接失败（当前用的是模板配置，请建 config/config.php）' : $e->getMessage();
    }
} else {
    $dbMsg = '缺少配置文件';
}
$checks[] = array('数据库连接', $dbMsg, $dbOk, '库名 ' . (isset($CONFIG['db']['name']) ? $CONFIG['db']['name'] : '?') . '，请先导入 sql/install.sql');
if (!$dbOk) { $fatal = true; }

$needTables = array('classes','users','questions','exams','exam_questions','exam_students','answers','uploads','grading','logs','settings');
$missing = $dbOk ? array_diff($needTables, $tables) : $needTables;
$checks[] = array('数据表完整性', empty($missing) ? '11 张表齐全' : ('缺少：' . implode(',', $missing)), empty($missing), '缺少请重新导入 sql/install.sql');
if (!empty($missing)) { $fatal = true; }

$iniUp = ini_get('upload_max_filesize');
$iniPost = ini_get('post_max_size');
$checks[] = array('php.ini 上传上限', $iniUp . ' / post ' . $iniPost, true,
    '实操题附件单文件上限受这里约束；系统设置里填的数不能超过 php.ini 的值');

/* ---------------- 校验并执行重置 ----------------
   必须放在数据库可用性检查之后：current_user() 内部会连库，
   库不可用时它会直接输出错误页并 exit，自检页就再也打不开了。 */
$canInit = false;
$initDeny = '';
$done = '';
if (isset($_GET['init'])) {
    if (!$dbOk || !empty($missing)) {
        $initDeny = '数据库未就绪，无法重置初始密码。请先按上面提示修复。';
    } else {
        $u = current_user();
        if (!$u || $u['role'] !== 'admin') {
            $initDeny = '重置初始密码需要管理员账号已登录后访问本页面。';
        } elseif (empty($_SESSION['csrf'])) {
            $initDeny = '缺少 CSRF 校验，请从已登录的页面跳转过来。';
        } else {
            $t = isset($_GET['_token']) ? (string)$_GET['_token'] : '';
            if (!hash_equals($_SESSION['csrf'], $t)) {
                $initDeny = 'CSRF 校验失败，请刷新页面后重试。';
            } else {
                $canInit = true;
            }
        }
        if ($canInit) {
            $st = $pdo->prepare('UPDATE users SET password = ?, must_change_pwd = 1 WHERE username = ?');
            $st->execute(array(password_hash('Admin@123', PASSWORD_DEFAULT), 'admin'));
            $st->execute(array(password_hash('Teacher@123', PASSWORD_DEFAULT), 'teacher'));
            $done = '初始密码已重置：admin / Admin@123　　teacher / Teacher@123';
            log_event('admin', 'install_check 重置初始密码');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title>环境自检 · Linux 考试系统</title>
<style>
body{font:14px/1.7 "Microsoft YaHei","PingFang SC",sans-serif;background:#f4f6fa;color:#1f2430;margin:0;padding:32px}
.box{max-width:860px;margin:0 auto;background:#fff;border-radius:10px;box-shadow:0 2px 16px rgba(20,40,80,.08);padding:28px 32px}
h1{font-size:20px;margin:0 0 6px}
.sub{color:#6b7280;font-size:13px;margin-bottom:20px}
table{width:100%;border-collapse:collapse;margin-bottom:18px}
th,td{padding:9px 10px;border-bottom:1px solid #eef1f6;text-align:left;font-size:13px;vertical-align:top}
th{background:#f8fafc;font-weight:600;color:#374151;width:180px}
.ok{color:#12805c;font-weight:600}
.bad{color:#c0392b;font-weight:600}
.tip{color:#8a94a6;font-size:12px}
.btn{display:inline-block;padding:9px 20px;background:#1e4fa8;color:#fff;border-radius:6px;text-decoration:none;font-size:14px}
.btn.gray{background:#6b7280}
.msg{background:#e8f7f0;border-left:4px solid #12805c;padding:10px 14px;border-radius:4px;margin-bottom:16px;font-size:13px}
.warn{background:#fdf3e7;border-left:4px solid #d98324;padding:10px 14px;border-radius:4px;margin-bottom:16px;font-size:13px}
.deny{background:#fdecea;border-left:4px solid #c0392b;padding:10px 14px;border-radius:4px;margin-bottom:16px;font-size:13px}
code{background:#f0f3f8;padding:1px 5px;border-radius:3px;font-family:Consolas,monospace}
</style>
</head>
<body>
<div class="box">
  <h1>环境自检</h1>
  <div class="sub">Linux 服务器配置与管理考试系统 · 部署后先跑一遍这个页面</div>

  <?php if ($done): ?><div class="msg"><?php echo htmlspecialchars($done); ?></div><?php endif; ?>
  <?php if ($initDeny): ?><div class="deny"><?php echo htmlspecialchars($initDeny); ?></div><?php endif; ?>

  <table>
    <?php foreach ($checks as $c): ?>
    <tr>
      <th><?php echo htmlspecialchars($c[0]); ?></th>
      <td>
        <span class="<?php echo $c[2] ? 'ok' : 'bad'; ?>"><?php echo $c[2] ? '✔ ' : '✘ '; ?><?php echo htmlspecialchars($c[1]); ?></span>
        <div class="tip"><?php echo htmlspecialchars($c[3]); ?></div>
      </td>
    </tr>
    <?php endforeach; ?>
  </table>

  <?php if (!$fatal): ?>
    <?php if ($done === ''): ?>
      <div class="warn">
        检查全部通过，可以直接登录：<code>admin / Admin@123</code>　<code>teacher / Teacher@123</code>（首次登录会要求改密码）。<br>
        如果登录时提示密码错误（例如换了 PHP 版本导致哈希不匹配），点下面这个按钮重新生成。<br>
        <span class="tip">重置按钮需要管理员已登录并带 CSRF token，未登录访问无效。</span>
      </div>
      <a class="btn" href="index.php">去登录</a>
      <?php
        /* 只在管理员已登录、且会话里有 csrf token 时才渲染带 _token 的链接 */
        $resetTip = '';
        $resetHref = 'install_check.php?init=1';
        $resetCls = 'gray';
        try {
            if (!empty($_SESSION['uid'])) {
                $cur = current_user();
                if ($cur && $cur['role'] === 'admin' && !empty($_SESSION['csrf'])) {
                    $resetHref = 'install_check.php?init=1&_token=' . urlencode($_SESSION['csrf']);
                } else {
                    $resetTip = '（需管理员已登录并带 _token 参数）';
                }
            } else {
                $resetTip = '（需管理员已登录并带 _token 参数）';
            }
        } catch (Exception $e) {
            $resetTip = '（当前无法判断登录状态）';
        }
      ?>
      <a class="btn <?php echo $resetCls; ?>" href="<?php echo htmlspecialchars($resetHref); ?>">
        重置初始密码<?php echo $resetTip; ?>
      </a>
    <?php else: ?>
      <a class="btn" href="index.php">进入登录页</a>
    <?php endif; ?>
  <?php else: ?>
    <div class="warn">上面有未通过的检查项，请先按提示修复再继续。</div>
  <?php endif; ?>

  <p class="tip" style="margin-top:22px">
    正式开考前请：① 确认 <code>config/config.php</code> 中 <code>debug</code> 为 <code>false</code>；
    ② 登录后台把 admin / teacher 密码改掉；③ 删除本文件 <code>install_check.php</code>。
  </p>
</div>
</body>
</html>