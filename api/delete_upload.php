<?php
/**
 * 删除自己上传的附件（交卷前）
 */
require __DIR__ . '/_common.php';
api_csrf();

list($u, $exam, $es, $examId) = api_context();

$id = (int)post('id');
$row = db_one('SELECT * FROM uploads WHERE id = ? AND exam_id = ? AND student_id = ?',
    array($id, $examId, $u['id']));
if (!$row) {
    json_out(array('ok' => false, 'msg' => '记录不存在或无权删除'));
}

$abs = APP_ROOT . '/uploads/' . $row['file_path'];
if (is_file($abs)) {
    @unlink($abs);
}
db_exec('DELETE FROM uploads WHERE id = ?', array($id));
log_event('upload', '删除附件 ' . $row['file_path'], $examId, $u['id']);

json_out(array('ok' => true));
