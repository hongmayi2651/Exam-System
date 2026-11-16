<?php
/**
 * 通用函数库
 */
defined('APP_ROOT') or exit('Access denied');

/** HTML 转义 */
function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** 当前时间字符串 */
function now()
{
    return date('Y-m-d H:i:s');
}

/** 时间格式化 */
function fmt_dt($dt, $fmt = 'Y-m-d H:i')
{
    if (empty($dt) || $dt === '0000-00-00 00:00:00') {
        return '—';
    }
    $ts = strtotime($dt);
    return $ts ? date($fmt, $ts) : '—';
}

/** 跳转（参数为站点根相对路径） */
function redirect($url)
{
    if (strpos($url, 'http') !== 0) {
        $url = base_url($url);
    }
    header('Location: ' . $url);
    exit;
}

/** 站点根 URL */
function base_url($path = '')
{
    static $base = null;
    if ($base === null) {
        global $CONFIG;
        if (!empty($CONFIG['site']['base_url'])) {
            $base = rtrim($CONFIG['site']['base_url'], '/');
        } else {
            $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME']));
            // 去掉 /student /teacher /admin /api 这一层
            $script = preg_replace('#/(student|teacher|admin|api)$#', '', $script);
            $base = rtrim($script, '/');
        }
    }
    $path = ltrim($path, '/');
    return $path === '' ? $base : $base . '/' . $path;
}

/** JSON 输出并结束 */
function json_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/** 读取 POST/GET */
function post($k, $default = '')
{
    return isset($_POST[$k]) ? (is_array($_POST[$k]) ? $_POST[$k] : trim((string)$_POST[$k])) : $default;
}

function get($k, $default = '')
{
    return isset($_GET[$k]) ? (is_array($_GET[$k]) ? $_GET[$k] : trim((string)$_GET[$k])) : $default;
}

/** CSRF */
function csrf_token()
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function csrf_field()
{
    return '<input type="hidden" name="_token" value="' . h(csrf_token()) . '">';
}

function csrf_check()
{
    $t = isset($_POST['_token']) ? $_POST['_token'] : (isset($_GET['_token']) ? $_GET['_token'] : '');
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$t)) {
        http_response_code(419);
        exit('页面已过期，请刷新后重试。');
    }
}

/** 一次性提示 */
function flash($msg, $type = 'ok')
{
    $_SESSION['flash'] = array('msg' => $msg, 'type' => $type);
}

function take_flash()
{
    if (empty($_SESSION['flash'])) {
        return null;
    }
    $f = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $f;
}

/* ===================================================================
   题型
   =================================================================== */

/** 全部题型，顺序即卷面顺序 */
function type_list()
{
    return array('single', 'judge', 'short', 'practical');
}

/** 题型中文名 */
function type_label($type)
{
    $map = array(
        'single'    => '单选题',
        'judge'     => '判断题',
        'short'     => '简答题',
        'practical' => '实操题',
    );
    return isset($map[$type]) ? $map[$type] : $type;
}

/** 卷面大题标题 */
function section_titles()
{
    return array(
        'single'    => '一、单项选择题',
        'judge'     => '二、判断题',
        'short'     => '三、简答题',
        'practical' => '四、实操题',
    );
}

/** 是否客观题（可自动判分） */
function is_objective($type)
{
    return in_array($type, array('single', 'judge'), true);
}

/** 是否主观题（需人工阅卷） */
function is_subjective($type)
{
    return in_array($type, array('short', 'practical'), true);
}

/* ===================================================================
   杂项
   =================================================================== */

/** 写日志 */
function log_event($event, $detail = '', $examId = null, $studentId = null)
{
    try {
        $st = db()->prepare('INSERT INTO logs (exam_id, student_id, event, detail, ip, created_at) VALUES (?,?,?,?,?,?)');
        $st->execute(array(
            $examId, $studentId, $event,
            mb_substr((string)$detail, 0, 250),
            isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null,
            now(),
        ));
    } catch (Exception $e) {
        // 日志失败不影响主流程
    }
}

/** 读取设置 */
function setting($k, $default = null)
{
    static $cache = null;
    if ($cache === null) {
        $cache = array();
        try {
            $rows = db()->query('SELECT k, v FROM settings')->fetchAll();
            foreach ($rows as $r) {
                $cache[$r['k']] = $r['v'];
            }
        } catch (Exception $e) {
            // 表还没建好时不要让整个页面崩掉
        }
    }
    return array_key_exists($k, $cache) ? $cache[$k] : $default;
}

/** 写设置 */
function set_setting($k, $v)
{
    $st = db()->prepare('REPLACE INTO settings (k, v) VALUES (?,?)');
    $st->execute(array($k, $v));
}

/** 解析 JSON 字段，失败返回默认 */
function jdec($str, $default = array())
{
    if (empty($str)) {
        return $default;
    }
    $v = json_decode($str, true);
    return is_array($v) ? $v : $default;
}

/** 客观题判分：返回 [是否全对, 得分比例] */
function judge_objective($type, $correct, $mine, $rule = null)
{
    $correct = strtoupper(str_replace(array(' ', ',', '，'), '', (string)$correct));
    $mine    = strtoupper(str_replace(array(' ', ',', '，'), '', (string)$mine));

    if ($mine === '') {
        return array(0, 0);
    }
    // 单选 / 判断：完全相等才给分
    return $correct === $mine ? array(1, 1) : array(0, 0);
}

/** 秒 → mm:ss */
function fmt_countdown($seconds)
{
    $seconds = max(0, (int)$seconds);
    $h = floor($seconds / 3600);
    $m = floor(($seconds % 3600) / 60);
    $s = $seconds % 60;
    if ($h > 0) {
        return sprintf('%d:%02d:%02d', $h, $m, $s);
    }
    return sprintf('%02d:%02d', $m, $s);
}

/** 去掉小数末尾的 0：12.50 → 12.5；20.00 → 20 */
function fmt_num($v)
{
    $s = number_format((float)$v, 1, '.', '');
    return rtrim(rtrim($s, '0'), '.');
}

/** 文件大小友好显示 */
function fmt_size($bytes)
{
    $bytes = (float)$bytes;
    if ($bytes < 1024) {
        return round($bytes) . ' B';
    }
    if ($bytes < 1024 * 1024) {
        return round($bytes / 1024, 1) . ' KB';
    }
    if ($bytes < 1024 * 1024 * 1024) {
        return round($bytes / 1024 / 1024, 1) . ' MB';
    }
    return round($bytes / 1024 / 1024 / 1024, 2) . ' GB';
}

/** 是否图片（按扩展名判断，用于前端决定显示缩略图还是附件条） */
function is_image_file($path, $origName = '')
{
    global $CONFIG;
    $name = $origName !== '' ? $origName : $path;
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($ext, $CONFIG['site']['image_ext'], true);
}

/** 扩展名 */
function file_ext($name)
{
    return strtolower(pathinfo((string)$name, PATHINFO_EXTENSION));
}

/**
 * 渲染一条附件（图片显示缩略图，其它类型显示文件条）
 * @param array $f     uploads 表的一行
 * @param bool  $canDel 是否显示删除按钮（学生答题页 true，阅卷页 false）
 */
function att_item_html($f, $canDel = true)
{
    $url  = upload_url($f['file_path']);
    $name = (string)$f['orig_name'];
    if ($name === '') {
        $name = basename($f['file_path']);
    }
    $kind = isset($f['kind']) && $f['kind'] !== ''
        ? $f['kind']
        : (is_image_file($f['file_path'], $name) ? 'image' : 'file');
    $del = $canDel ? '<button class="del" type="button" title="删除">×</button>' : '';

    if ($kind === 'image') {
        return '<div class="shot" data-id="' . (int)$f['id'] . '">'
            . '<img src="' . h($url) . '" alt="">' . $del
            . '<div class="nm">' . h($name) . '</div>'
            . '</div>';
    }

    $extTxt = strtoupper(file_ext($name));
    if ($extTxt === '') {
        $extTxt = 'FILE';
    }
    return '<div class="filechip" data-id="' . (int)$f['id'] . '">'
        . '<div class="ic">' . h(mb_substr($extTxt, 0, 5)) . '</div>'
        . '<div class="meta">'
        . '<a class="nm" href="' . h($url) . '" download="' . h($name) . '" title="下载查看">' . h($name) . '</a>'
        . '<div class="sz">' . h(fmt_size(isset($f['size']) ? $f['size'] : 0)) . '</div>'
        . '</div>' . $del
        . '</div>';
}

/**
 * 上传时校验扩展名是否被禁
 * 返回 true 表示允许
 */
function upload_ext_allowed($name)
{
    global $CONFIG;
    $ext = file_ext($name);
    if ($ext === '') {
        return true; // 无扩展名（极少数情况），保存时会被加上 .bin
    }
    return !in_array($ext, $CONFIG['site']['upload_ext_deny'], true);
}
