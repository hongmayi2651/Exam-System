<?php
/**
 * 用户与班级管理
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
$u = require_login(array('admin'));

/* 新增班级 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'add_class') {
    csrf_check();
    $name = post('class_name');
    if ($name !== '') {
        $exist = db_val('SELECT id FROM classes WHERE name = ?', array($name));
        if ($exist) {
            flash('班级已存在', 'err');
        } else {
            db_insert('INSERT INTO classes (name, created_at) VALUES (?,?)', array($name, now()));
            flash('已添加班级：' . $name, 'ok');
        }
    }
    redirect('admin/users.php');
}

/* 新增/修改单个学生 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save_user') {
    csrf_check();
    $uid   = (int)post('uid');
    $no    = post('username');
    $name  = post('name');
    $cid   = (int)post('class_id');
    $pwd   = post('password');

    if ($no === '' || $name === '') {
        flash('学号和姓名不能为空', 'err');
        redirect('admin/users.php');
    }
    if ($uid) {
        db_exec('UPDATE users SET username=?, name=?, class_id=? WHERE id=?', array($no, $name, $cid ?: null, $uid));
        if ($pwd !== '') {
            db_exec('UPDATE users SET password=?, must_change_pwd=1, active_session=NULL WHERE id=?',
                array(password_hash($pwd, PASSWORD_DEFAULT), $uid));
        }
        flash('已更新', 'ok');
    } else {
        $exist = db_val('SELECT id FROM users WHERE username = ?', array($no));
        if ($exist) {
            flash('学号 ' . $no . ' 已存在', 'err');
        } else {
            $pwd = $pwd === '' ? '123456' : $pwd;
            db_insert('INSERT INTO users (username, name, password, role, class_id, status, must_change_pwd, created_at)
                       VALUES (?,?,?,?,?,?,?,?)',
                array($no, $name, password_hash($pwd, PASSWORD_DEFAULT), 'student', $cid ?: null, 1, 1, now()));
            flash('已添加学生：' . $name, 'ok');
        }
    }
    redirect('admin/users.php' . ($cid ? '?class_id=' . $cid : ''));
}

/* 批量导入 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'import') {
    csrf_check();
    $cid  = (int)post('class_id');
    $text = post('bulk');
    $defPwd = post('def_pwd', '123456');
    if ($text === '' && !empty($_FILES['csv']['tmp_name'])) {
        $text = file_get_contents($_FILES['csv']['tmp_name']);
        if (!mb_check_encoding($text, 'UTF-8')) {
            $enc = mb_detect_encoding($text, array('GB18030', 'GBK', 'UTF-8'), true);
            if ($enc) $text = mb_convert_encoding($text, 'UTF-8', $enc);
        }
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    }
    $lines = preg_split('/\r\n|\r|\n/', (string)$text);
    $okCnt = 0; $dup = 0; $bad = 0;
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '') continue;
        $parts = preg_split('/[,，\t\s]+/u', $ln);
        // 跳过表头
        if (isset($parts[0]) && in_array($parts[0], array('学号', 'username', '账号'), true)) continue;
        $no = isset($parts[0]) ? trim($parts[0]) : '';
        $nm = isset($parts[1]) ? trim($parts[1]) : '';
        if ($no === '' || $nm === '') { $bad++; continue; }
        if (db_val('SELECT id FROM users WHERE username = ?', array($no))) { $dup++; continue; }
        db_insert('INSERT INTO users (username, name, password, role, class_id, status, must_change_pwd, created_at)
                   VALUES (?,?,?,?,?,?,?,?)',
            array($no, $nm, password_hash($defPwd, PASSWORD_DEFAULT), 'student', $cid ?: null, 1, 1, now()));
        $okCnt++;
    }
    flash('导入完成：新增 ' . $okCnt . ' 人，重复跳过 ' . $dup . ' 人，格式错误 ' . $bad . ' 行（初始密码已统一设置）', 'ok');
    redirect('admin/users.php' . ($cid ? '?class_id=' . $cid : ''));
}

/* 重置密码 / 启停 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'reset_pwd') {
    csrf_check();
    $uid = (int)post('uid');
    $new = post('new_pwd', '123456');
    if ($new === '') { $new = '123456'; }
    db_exec('UPDATE users SET password = ?, must_change_pwd = 1, active_session = NULL WHERE id = ?',
        array(password_hash($new, PASSWORD_DEFAULT), $uid));
    /* 不把明文密码写进 flash 提示，避免它出现在页面、浏览器历史、日志里 */
    flash('该账号密码已重置为默认初始密码，该用户下次登录必须修改密码。', 'ok');
    log_event('admin', '重置某学生账号密码');
    redirect('admin/users.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'toggle') {
    csrf_check();
    $uid = (int)post('uid');
    db_exec('UPDATE users SET status = 1 - status, active_session = NULL WHERE id = ?', array($uid));
    flash('状态已切换', 'ok');
    redirect('admin/users.php');
}

/* 列表 */
$classId = (int)get('class_id');
$kw = get('kw');
$classes = db_all('SELECT c.*, (SELECT COUNT(*) FROM users u WHERE u.class_id=c.id AND u.role="student") AS cnt
                     FROM classes c ORDER BY c.id');

$sql = 'SELECT u.*, c.name AS class_name FROM users u LEFT JOIN classes c ON c.id = u.class_id WHERE 1=1';
$params = array();
if ($classId > 0) { $sql .= ' AND u.class_id = ?'; $params[] = $classId; }
if ($kw !== '') { $sql .= ' AND (u.username LIKE ? OR u.name LIKE ?)'; $params[] = "%$kw%"; $params[] = "%$kw%"; }
$sql .= " ORDER BY FIELD(u.role,'admin','teacher','student'), u.class_id, u.username";
$list = db_all($sql, $params);

$editId = (int)get('edit');
$editUser = $editId ? db_one('SELECT * FROM users WHERE id = ?', array($editId)) : null;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>用户与班级</title>
<link rel="stylesheet" href="<?php echo h(base_url('assets/css/app.css')); ?>">
</head>
<body>
<div class="topbar">
  <div class="brand">用户与班级<small>共 <?php echo count($list); ?> 条</small></div>
  <div class="spacer"></div>
  <a href="index.php">返回后台</a>
  <a href="<?php echo h(base_url('logout.php')); ?>">退出</a>
</div>

<div class="wrap">
  <?php $f = take_flash(); if ($f): ?>
    <div class="alert <?php echo h($f['type']); ?>"><?php echo h($f['msg']); ?></div>
  <?php endif; ?>

  <div class="card">
    <h2>班级</h2>
    <div class="flex gap10 aic" style="flex-wrap:wrap;margin-bottom:12px">
      <?php foreach ($classes as $c): ?>
        <a class="btn sm <?php echo $classId === (int)$c['id'] ? '' : 'ghost'; ?>"
           href="users.php?class_id=<?php echo (int)$c['id']; ?>">
          <?php echo h($c['name']); ?>（<?php echo (int)$c['cnt']; ?>）
        </a>
      <?php endforeach; ?>
      <?php if ($classId): ?><a class="btn sm gray" href="users.php">全部</a><?php endif; ?>
    </div>
    <form method="post" class="inline">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="do" value="add_class">
      <input class="input sm" name="class_name" placeholder="新班级名称，如 25计网3班" style="width:230px">
      <button class="btn sm ghost" type="submit">添加班级</button>
    </form>
  </div>

  <div class="card">
    <h2><?php echo $editUser ? '编辑用户 ' . h($editUser['username']) : '新增学生'; ?></h2>
    <form method="post" class="flex gap10 aic" style="flex-wrap:wrap">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="do" value="save_user">
      <input type="hidden" name="uid" value="<?php echo $editUser ? (int)$editUser['id'] : 0; ?>">
      <input class="input" name="username" placeholder="学号" style="width:140px" required
             value="<?php echo h($editUser ? $editUser['username'] : ''); ?>">
      <input class="input" name="name" placeholder="姓名" style="width:120px" required
             value="<?php echo h($editUser ? $editUser['name'] : ''); ?>">
      <select class="input" name="class_id" style="width:170px">
        <option value="0">未分班</option>
        <?php foreach ($classes as $c): ?>
          <option value="<?php echo (int)$c['id']; ?>"
            <?php echo ($editUser && (int)$editUser['class_id'] === (int)$c['id']) || (!$editUser && $classId === (int)$c['id']) ? 'selected' : ''; ?>>
            <?php echo h($c['name']); ?>
          </option>
        <?php endforeach; ?>
      </select>
      <input class="input" name="password" placeholder="密码（留空则 123456）" style="width:190px">
      <button class="btn" type="submit"><?php echo $editUser ? '保存修改' : '添加'; ?></button>
      <?php if ($editUser): ?><a class="btn ghost" href="users.php">取消编辑</a><?php endif; ?>
    </form>
  </div>

  <div class="card">
    <h2>批量导入学生<span class="sub">每行「学号 姓名」，逗号/空格/制表符都行</span></h2>
    <form method="post" enctype="multipart/form-data">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="do" value="import">
      <div class="flex gap10" style="flex-wrap:wrap">
        <div class="form-row" style="width:190px">
          <label class="lbl">导入到班级</label>
          <select class="input" name="class_id">
            <option value="0">未分班</option>
            <?php foreach ($classes as $c): ?>
              <option value="<?php echo (int)$c['id']; ?>" <?php echo $classId === (int)$c['id'] ? 'selected' : ''; ?>>
                <?php echo h($c['name']); ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row" style="width:190px">
          <label class="lbl">初始密码</label>
          <input class="input" name="def_pwd" value="123456">
        </div>
        <div class="form-row flex1" style="min-width:240px">
          <label class="lbl">或上传 CSV</label>
          <input class="input" type="file" name="csv" accept=".csv,.txt">
        </div>
      </div>
      <div class="form-row">
        <label class="lbl">粘贴名单</label>
        <textarea class="input" name="bulk" rows="5" placeholder="2510101 张三&#10;2510102 李四"></textarea>
      </div>
      <button class="btn" type="submit">开始导入</button>
    </form>
  </div>

  <div class="card">
    <h2>用户列表</h2>
    <form class="inline mb10" method="get">
      <?php if ($classId): ?><input type="hidden" name="class_id" value="<?php echo $classId; ?>"><?php endif; ?>
      <input class="input sm" name="kw" value="<?php echo h($kw); ?>" placeholder="搜索学号/姓名">
      <button class="btn sm ghost" type="submit">搜索</button>
    </form>
    <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>学号</th><th>姓名</th><th>角色</th><th>班级</th>
          <th class="center">状态</th><th class="center">最近登录</th><th class="center">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($list as $s):
          $roleMap = array('admin' => array('管理员', 'red'), 'teacher' => array('教师', 'orange'), 'student' => array('学生', 'blue'));
          $rl = $roleMap[$s['role']];
      ?>
        <tr>
          <td><strong><?php echo h($s['username']); ?></strong></td>
          <td><?php echo h($s['name']); ?></td>
          <td><span class="badge <?php echo $rl[1]; ?>"><?php echo $rl[0]; ?></span></td>
          <td class="small muted"><?php echo h($s['class_name']); ?></td>
          <td class="center">
            <?php echo (int)$s['status'] === 1 ? '<span class="badge green">启用</span>' : '<span class="badge gray">停用</span>'; ?>
            <?php if ((int)$s['must_change_pwd'] === 1): ?><span class="badge orange">待改密</span><?php endif; ?>
          </td>
          <td class="center small"><?php echo h(fmt_dt($s['last_login_at'])); ?></td>
          <td class="center nowrap">
            <a class="btn sm ghost" href="users.php?edit=<?php echo (int)$s['id']; ?><?php echo $classId ? '&class_id=' . $classId : ''; ?>">编辑</a>
            <form method="post" style="display:inline" onsubmit="return confirm('把 <?php echo h($s['name']); ?> 的密码重置为默认初始密码？')">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="do" value="reset_pwd">
              <input type="hidden" name="uid" value="<?php echo (int)$s['id']; ?>">
              <button class="btn sm ghost" type="submit">重置密码</button>
            </form>
            <form method="post" style="display:inline">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="do" value="toggle">
              <input type="hidden" name="uid" value="<?php echo (int)$s['id']; ?>">
              <button class="btn sm <?php echo (int)$s['status'] === 1 ? 'danger' : 'ghost'; ?>" type="submit">
                <?php echo (int)$s['status'] === 1 ? '停用' : '启用'; ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <div class="small muted" style="margin-top:10px">
      注意：学生初始密码导入后标记为「待改密」，首次登录必须改密码（可在 <a href="settings.php">系统设置</a> 里调整策略）。
    </div>
  </div>
</div>
</body>
</html>