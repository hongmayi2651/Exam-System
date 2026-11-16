<?php
/**
 * 交卷
 */
require __DIR__ . '/_common.php';
api_csrf();

list($u, $exam, $es, $examId) = api_context();

$reason = post('reason', 'manual');
if (!in_array($reason, array('manual', 'timeout'), true)) {
    $reason = 'manual';
}
finalize_exam($examId, $u['id'], $reason);

json_out(array('ok' => true, 'auto' => $reason === 'timeout' ? 1 : 0));
