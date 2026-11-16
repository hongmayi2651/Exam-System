<?php
/**
 * 系统设置
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
$u = require_login(array('admin'));

$keys = array(
    'site_name'         => array('站点名称', 'text', '登录页与顶栏显示的名称'),
    'school_name'       => array('学校名称', 'text', ''),
    'blur_limit'        => array('切屏次数告警阈值', 'number', '超过这个次数会在学生端弹出提醒，记录写进看板'),
    'upload_max_mb'     => array('单个附件上限（MB）', 'number', '实操题截图/附件单文件上限；不能超过 php.ini 的 upload_max_filesize'),
    'upload_max_count'  => array('每题附件最多个数', 'number', '实操题每道题最多可提交几个附件'),
    'force_pwd_change'  => array('首次登录强制改密码', 'select:1|0', '1=开启（推荐）；0=关闭，学生可直接用初始密码考试'),
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    foreach ($keys as $k => $meta) {
        if (isset($_POST[$k])) {
            set_setting($k, trim((string)$_POST[$k]));
        }
    }
    flash('设置已保存', 'ok');
    redirect('admin/settings.php');
}

$vals = array();
foreach (db_all('SELECT k, v FROM settings') as $r) {
    $vals[$r['k']] = $r['v'];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>系统设置</title>
<link rel="stylesheet" href="<?php echo h(base_url('assets/css/app.css')); ?>">
</head>
<body>
<div class="topbar">
  <div class="brand">系统设置<small><?php echo h(setting('site_name')); ?></small></div>
  <div class="spacer"></div>
  <a href="index.php">返回后台</a>
  <a href="<?php echo h(base_url('logout.php')); ?>">退出</a>
</div>

<div class="wrap-narrow">
  <?php $f = take_flash(); if ($f): ?>
    <div class="alert <?php echo h($f['type']); ?>"><?php echo h($f['msg']); ?></div>
  <?php endif; ?>

  <div class="card">
    <h2>考试参数</h2>
    <form method="post">
      <?php echo csrf_field(); ?>
      <?php foreach ($keys as $k => $meta):
          $label = $meta[0]; $type = $meta[1]; $hint = $meta[2];
          $val = isset($vals[$k]) ? $vals[$k] : '';
      ?>
        <div class="form-row">
          <label class="lbl"><?php echo h($label); ?></label>
          <?php if (strpos($type, 'select:') === 0):
              $opts = explode('|', substr($type, 7));
              $labels = array('0' => '否', '1' => '是');
          ?>
            <select class="input" name="<?php echo $k; ?>" style="width:280px">
              <?php foreach ($opts as $o): ?>
                <option value="<?php echo h($o); ?>" <?php echo (string)$val === (string)$o ? 'selected' : ''; ?>>
                  <?php echo h(isset($labels[$o]) ? $labels[$o] : $o); ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php elseif ($type === 'number'): ?>
            <input class="input" type="number" name="<?php echo $k; ?>" value="<?php echo h($val); ?>" style="width:140px">
          <?php else: ?>
            <input class="input" name="<?php echo $k; ?>" value="<?php echo h($val); ?>">
          <?php endif; ?>
          <?php if ($hint): ?><div class="hint"><?php echo h($hint); ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <button class="btn" type="submit">保存设置</button>
    </form>
  </div>

  <div class="card">
    <h2>环境信息</h2>
    <table class="table">
      <tr><th style="width:180px">PHP 版本</th><td><?php echo PHP_VERSION; ?></td></tr>
      <tr><th>服务器</th><td><?php echo h(isset($_SERVER['SERVER_SOFTWARE']) ? $_SERVER['SERVER_SOFTWARE'] : ''); ?></td></tr>
      <tr><th>MySQL 版本</th><td><?php echo h(db_val('SELECT VERSION()', array(), '未知')); ?></td></tr>
      <tr><th>上传目录</th><td><?php echo is_writable(APP_ROOT . '/uploads') ? '<span class="badge green">可写</span>' : '<span class="badge red">不可写</span>'; ?></td></tr>
      <tr><th>php.ini 上传上限</th><td><?php echo h(ini_get('upload_max_filesize') . ' / post_max_size ' . ini_get('post_max_size')); ?></td></tr>
      <tr><th>允许的附件类型</th><td>不限（拒绝 <?php echo h(implode('、', $CONFIG['site']['upload_ext_deny'])); ?> 等可执行/可渲染类型）</td></tr>
      <tr><th>时区</th><td><?php echo h(date_default_timezone_get() . '　当前时间 ' . now()); ?></td></tr>
      <tr><th>调试模式</th><td><?php echo !empty($CONFIG['debug']) ? '<span class="badge orange">开启（正式使用请关闭）</span>' : '<span class="badge green">关闭</span>'; ?></td></tr>
    </table>
    <div class="small muted" style="margin-top:10px">
      调试模式在 <code>config/config.php</code> 里改：<code>'debug' =&gt; false</code>。
    </div>
  </div>

  <div class="card">
    <h2>数据维护</h2>
    <div class="small muted" style="line-height:2">
      数据库名：<code><?php echo h($CONFIG['db']['name']); ?></code>　
      备份建议：考试前用 phpMyAdmin 或 <code>mysqldump -u root -p <?php echo h($CONFIG['db']['name']); ?> &gt; backup.sql</code> 导出一次，
      同时把 <code>uploads/</code> 目录整个拷走。
    </div>
  </div>
</div>
</body>
</html>
