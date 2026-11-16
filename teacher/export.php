<?php
/**
 * 成绩导出：CSV / XLS(HTML 表格)
 * export.php?exam_id=1&fmt=csv|xls
 *
 * ★ 性能：所有逐人查询合并为每张表一次全量查询，避免 40 人 × 3 次 = 120 次往返
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
$u = require_login(array('teacher', 'admin'));

$examId = (int)get('exam_id');
$fmt    = get('fmt', 'csv');
$exam   = get_exam($examId);
if (!$exam) {
    exit('考试不存在');
}

$students = db_all(
    "SELECT es.*, u.username, u.name, c.name AS class_name
       FROM exam_students es
       JOIN users u ON u.id = es.student_id
       LEFT JOIN classes c ON c.id = u.class_id
      WHERE es.exam_id = ?
      ORDER BY u.username ASC",
    array($examId)
);
$questions = exam_questions($examId);

/* 一次查回本场所有客观题得分 / 主观题得分 / 附件数 */
$scoreMap = array();
foreach (db_all('SELECT student_id, question_id, score FROM answers WHERE exam_id = ?',
                array($examId)) as $a) {
    $scoreMap[(int)$a['student_id']][(int)$a['question_id']] = $a['score'];
}
foreach (db_all('SELECT student_id, question_id, score FROM grading WHERE exam_id = ?',
                array($examId)) as $g) {
    $scoreMap[(int)$g['student_id']][(int)$g['question_id']] = $g['score'];
}
$attMap = array();
foreach (db_all('SELECT student_id, COUNT(*) c FROM uploads WHERE exam_id = ? GROUP BY student_id',
                array($examId)) as $r) {
    $attMap[(int)$r['student_id']] = (int)$r['c'];
}

$statusText = array(
    'assigned' => '未开始', 'ongoing' => '答题中',
    'submitted' => '已交卷待阅', 'graded' => '已阅卷',
);

$headers = array('学号', '姓名', '班级', '状态', '开始时间', '交卷时间', '用时(分)', '交卷方式', '切屏次数', '附件数');
foreach ($questions as $q) {
    $headers[] = '第' . $q['seq'] . '题(' . type_label($q['type']) . '/'
        . fmt_num($q['exam_score']) . '分)';
}
$headers[] = '客观分';
$headers[] = '主观分';
$headers[] = '总分';

$rows = array();
foreach ($students as $s) {
    $sid = (int)$s['student_id'];
    $used = '';
    if ($s['start_at'] && $s['submit_at']) {
        $used = round((strtotime($s['submit_at']) - strtotime($s['start_at'])) / 60);
    }
    $tmap = array('manual' => '主动', 'timeout' => '超时', 'force' => '强制');
    $row = array(
        $s['username'], $s['name'], (string)$s['class_name'],
        isset($statusText[$s['status']]) ? $statusText[$s['status']] : $s['status'],
        (string)$s['start_at'], (string)$s['submit_at'], $used,
        isset($tmap[$s['submit_type']]) ? $tmap[$s['submit_type']] : '',
        (int)$s['blur_count'],
        isset($attMap[$sid]) ? $attMap[$sid] : 0,
    );
    foreach ($questions as $q) {
        $qid = (int)$q['id'];
        $row[] = isset($scoreMap[$sid][$qid]) && $scoreMap[$sid][$qid] !== null
            ? fmt_num($scoreMap[$sid][$qid]) : '';
    }
    $row[] = fmt_num($s['score_objective']);
    $row[] = $s['score_subjective'] === null ? '' : fmt_num($s['score_subjective']);
    $row[] = $s['score_total'] === null ? '' : fmt_num($s['score_total']);
    $rows[] = $row;
}

$filename = preg_replace('/[\\\\\/:*?"<>|]/u', '_', $exam['title']) . '_成绩_' . date('Ymd_His');

if ($fmt === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM，避免 Excel 打开中文乱码
    fputcsv($out, $headers);
    foreach ($rows as $r) {
        fputcsv($out, $r);
    }
    fclose($out);
    log_event('export', '导出 CSV', $examId, null);
    exit;
}

// XLS（HTML 表格）
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
echo "\xEF\xBB\xBF";
?>
<html><head><meta charset="utf-8">
<style>
table{border-collapse:collapse}
th,td{border:1px solid #999;padding:4px 8px;font-size:11pt;font-family:微软雅黑;mso-number-format:"\@"}
th{background:#e8eff8;font-weight:bold;text-align:center}
</style></head><body>
<table>
<thead><tr>
<?php foreach ($headers as $hd): ?><th><?php echo h($hd); ?></th><?php endforeach; ?>
</tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
<?php foreach ($r as $cell): ?><td><?php echo h($cell); ?></td><?php endforeach; ?>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</body></html>
<?php
log_event('export', '导出 XLS', $examId, null);