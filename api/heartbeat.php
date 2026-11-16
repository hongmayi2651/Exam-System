<?php
/**
 * 心跳：前端定期调用，用于校准剩余时间、发现超时收卷
 */
require __DIR__ . '/_common.php';

list($u, $exam, $es, $examId) = api_context(false);

if (in_array($es['status'], array('submitted', 'graded'), true)) {
    json_out(array('ok' => true, 'submitted' => true, 'remaining' => 0));
}
if ($es['status'] !== 'ongoing') {
    json_out(array('ok' => true, 'started' => false, 'remaining' => 0));
}

$remaining = remaining_seconds($es, $exam);
if ($remaining <= 0) {
    finalize_exam($examId, $u['id'], 'timeout');
    json_out(array('ok' => true, 'submitted' => true, 'remaining' => 0));
}

json_out(array('ok' => true, 'remaining' => $remaining, 'server_time' => now()));
