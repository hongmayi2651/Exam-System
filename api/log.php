<?php
/**
 * 记录切屏等行为
 */
require __DIR__ . '/_common.php';
api_csrf();

list($u, $exam, $es, $examId) = api_context(false);

$event   = post('event', 'blur');
$seconds = (int)post('seconds', 0);
if (!in_array($event, array('blur', 'focus', 'copy', 'paste'), true)) {
    $event = 'blur';
}

if ($event === 'blur') {
    db_exec('UPDATE exam_students SET blur_count = blur_count + 1 WHERE id = ?', array($es['id']));
} elseif ($event === 'focus' && $seconds > 0) {
    $seconds = min($seconds, 3600);
    db_exec('UPDATE exam_students SET blur_seconds = blur_seconds + ? WHERE id = ?', array($seconds, $es['id']));
}
log_event($event, $event . ' 持续 ' . $seconds . ' 秒', $examId, $u['id']);

$es2 = get_exam_student($examId, $u['id']);
$limit = (int)setting('blur_limit', 5);

json_out(array(
    'ok'         => true,
    'blur_count' => (int)$es2['blur_count'],
    'over_limit' => (int)$es2['blur_count'] > $limit,
));
