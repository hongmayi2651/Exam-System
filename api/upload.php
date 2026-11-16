<?php
/**
 * 实操题附件上传
 * 支持：剪贴板粘贴的截图（source=paste）、任意类型文件（source=pick）
 * 类型不限，但拒绝可被 Web 直接执行/渲染的扩展名（见 config.php 的 upload_ext_deny）
 */
require __DIR__ . '/_common.php';
api_csrf();

list($u, $exam, $es, $examId) = api_context();

$qid = (int)post('question_id');
$eq  = db_one("SELECT eq.question_id, q.type FROM exam_questions eq JOIN questions q ON q.id = eq.question_id
               WHERE eq.exam_id = ? AND eq.question_id = ?", array($examId, $qid));
if (!$eq) {
    json_out(array('ok' => false, 'msg' => '题目不属于本卷'));
}
if ($eq['type'] !== 'practical') {
    json_out(array('ok' => false, 'msg' => '只有实操题可以上传附件'));
}

if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
    json_out(array('ok' => false, 'msg' => '没有收到文件'));
}
$f = $_FILES['file'];
if ($f['error'] !== UPLOAD_ERR_OK) {
    $map = array(
        UPLOAD_ERR_INI_SIZE   => '文件超过服务器上传限制（php.ini 的 upload_max_filesize）',
        UPLOAD_ERR_FORM_SIZE  => '文件超过表单限制',
        UPLOAD_ERR_PARTIAL    => '文件只上传了一部分',
        UPLOAD_ERR_NO_FILE    => '没有选择文件',
        UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时目录',
        UPLOAD_ERR_CANT_WRITE => '服务器磁盘写入失败',
    );
    json_out(array('ok' => false, 'msg' => isset($map[$f['error']]) ? $map[$f['error']] : '上传失败'));
}

/* 上限取系统设置（可在后台改），配置与设置取小 */
$maxMb = (int)setting('upload_max_mb', $CONFIG['site']['upload_max_mb']);
if ($maxMb <= 0) {
    $maxMb = (int)$CONFIG['site']['upload_max_mb'];
}
$maxBytes = $maxMb * 1024 * 1024;
if ($f['size'] > $maxBytes) {
    json_out(array('ok' => false, 'msg' => '文件超过 ' . $maxMb . 'MB'));
}
if ($f['size'] <= 0) {
    json_out(array('ok' => false, 'msg' => '文件是空的，请重新上传'));
}

$maxCount = (int)setting('upload_max_count', $CONFIG['site']['upload_max_count']);
if ($maxCount <= 0) {
    $maxCount = (int)$CONFIG['site']['upload_max_count'];
}
$cnt = (int)db_val('SELECT COUNT(*) FROM uploads WHERE exam_id=? AND student_id=? AND question_id=?',
    array($examId, $u['id'], $qid), 0);
if ($cnt >= $maxCount) {
    json_out(array('ok' => false, 'msg' => '本题最多上传 ' . $maxCount . ' 个附件'));
}

/* 扩展名与类型判定 */
$origName = (string)$f['name'];
if (!upload_ext_allowed($origName)) {
    json_out(array('ok' => false, 'msg' => '不允许上传 .' . file_ext($origName)
        . ' 类型的文件（可执行/可渲染类型有安全风险），请先压缩成 zip 再传'));
}

$ext  = file_ext($origName);
$info = @getimagesize($f['tmp_name']);
$isImage = ($info !== false);
if ($isImage) {
    // 按真实类型纠正扩展名，避免 .jpg 里装 PNG 之类的错配
    $extMap = array(
        IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif',
        IMAGETYPE_BMP  => 'bmp', IMAGETYPE_WEBP => 'webp',
    );
    $realExt = isset($extMap[$info[2]]) ? $extMap[$info[2]] : $ext;
    $ext    = $realExt;
    $mime   = $info['mime'];
    $kind   = 'image';
} else {
    if ($ext === '') { $ext = 'bin'; }
    $mime = 'application/octet-stream';
    if (function_exists('finfo_open')) {
        $fi = @finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $m = @finfo_file($fi, $f['tmp_name']);
            if ($m) { $mime = $m; }
            @finfo_close($fi);
        }
    }
    $kind = 'file';
}

/* 存盘：uploads/<examId>/<studentId>/<qid>/ */
$relDir = $examId . '/' . $u['id'] . '/' . $qid;
$absDir = APP_ROOT . '/uploads/' . $relDir;
if (!is_dir($absDir) && !@mkdir($absDir, 0777, true)) {
    json_out(array('ok' => false, 'msg' => '无法创建上传目录，请检查 uploads 目录权限'));
}

$prefix = $kind === 'image' ? 'shot' : 'att';
$fname  = $prefix . '_q' . $qid . '_' . date('His') . '_' . substr(md5(uniqid('', true)), 0, 6) . '.' . $ext;
$absFile = $absDir . '/' . $fname;
if (!@move_uploaded_file($f['tmp_name'], $absFile)) {
    json_out(array('ok' => false, 'msg' => '保存文件失败'));
}
@chmod($absFile, 0644);

$src = post('source', 'pick');
if (!in_array($src, array('paste', 'pick'), true)) {
    $src = 'pick';
}

$relPath = $relDir . '/' . $fname;
$id = db_insert('INSERT INTO uploads (exam_id, student_id, question_id, orig_name, file_path, mime, ext, kind, size, sha1, source, created_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
    array($examId, $u['id'], $qid, mb_substr($origName, 0, 180), $relPath,
          mb_substr($mime, 0, 90), $ext, $kind, (int)$f['size'], sha1_file($absFile), $src, now()));

log_event('upload', '上传附件 ' . $relPath, $examId, $u['id']);

json_out(array(
    'ok'        => true,
    'id'        => $id,
    'url'       => upload_url($relPath),
    'name'      => mb_substr($origName, 0, 80),
    'ext'       => strtoupper($ext),
    'kind'      => $kind,
    'size'      => (int)$f['size'],
    'size_text' => fmt_size($f['size']),
));
