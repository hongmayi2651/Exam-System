<?php
/**
 * 登录 / 权限
 */
defined('APP_ROOT') or exit('Access denied');

/** 当前登录用户（数组或 null） */
function current_user()
{
    static $user = null;
    static $loaded = false;
    if ($loaded) {
        return $user;
    }
    $loaded = true;
    if (empty($_SESSION['uid'])) {
        return null;
    }
    $u = db_one('SELECT * FROM users WHERE id = ? AND status = 1', array($_SESSION['uid']));
    if (!$u) {
        return null;
    }
    // 单点登录校验
    global $CONFIG;
    if (!empty($CONFIG['site']['single_session'])) {
        $sessToken = isset($_SESSION['token']) ? $_SESSION['token'] : '';
        if ($u['active_session'] !== null && $u['active_session'] !== ''
            && !hash_equals((string)$u['active_session'], (string)$sessToken)) {
            do_logout(false);
            return null;
        }
    }
    $user = $u;
    return $user;
}

/** 要求登录，可限定角色 */
function require_login($roles = null)
{
    $u = current_user();
    if (!$u) {
        if (is_ajax()) {
            json_out(array('ok' => false, 'msg' => '登录已失效，请重新登录'), 401);
        }
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        redirect('index.php?err=expired');
    }
    if ($roles !== null) {
        $roles = (array)$roles;
        if (!in_array($u['role'], $roles, true)) {
            http_response_code(403);
            exit('<div style="font:14px/1.8 Microsoft YaHei;padding:40px">无权限访问该页面。'
                . '<br><a href="' . h(base_url('index.php')) . '">返回首页</a></div>');
        }
    }
    return $u;
}

function is_ajax()
{
    return (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);
}

/**
 * 登录校验
 * @return array [ok(bool), msg(string), user(array|null)]
 */
function do_login($username, $password)
{
    $u = db_one('SELECT * FROM users WHERE username = ?', array($username));
    if (!$u) {
        return array(false, '账号或密码错误', null);
    }
    if ((int)$u['status'] !== 1) {
        return array(false, '该账号已停用，请联系管理员', null);
    }
    if (!password_verify($password, $u['password'])) {
        return array(false, '账号或密码错误', null);
    }

    $token = bin2hex(random_bytes(16));
    db_exec('UPDATE users SET active_session = ?, last_login_at = ?, last_login_ip = ? WHERE id = ?',
        array($token, now(), isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null, $u['id']));

    session_regenerate_id(true);
    $_SESSION['uid']   = (int)$u['id'];
    $_SESSION['token'] = $token;
    $_SESSION['role']  = $u['role'];

    log_event('login', '用户 ' . $u['username'] . ' 登录');
    return array(true, '登录成功', $u);
}

/** 退出登录 */
function do_logout($clearSession = true)
{
    if (!empty($_SESSION['uid'])) {
        db_exec('UPDATE users SET active_session = NULL WHERE id = ?', array($_SESSION['uid']));
    }
    if ($clearSession) {
        $_SESSION = array();
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    } else {
        unset($_SESSION['uid'], $_SESSION['token'], $_SESSION['role']);
    }
}
