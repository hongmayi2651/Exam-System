<?php
/**
 * Pack all submitted/graded student papers into one downloadable ZIP.
 * Each paper is a single HTML file with base64-embedded screenshots (small images)
 * or a sibling image file (large ones), inlined CSS, plus a score CSV and a README.
 * pack_papers.php?exam_id=1
 *
 * ★ 内存保护：超过 config 里 embed_image_max_kb 的图片不再 base64 内嵌，
 *   而是原样拷进 ZIP 的 images/ 子目录，HTML 里用相对路径 images/xxx.png 引用。
 *   这样 40 人 × 多道实操题 × 大附件也不会把 PHP 内存撑爆。
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/paper_render.php';
$u = require_login(array('teacher', 'admin'));

$examId = (int)get('exam_id');
$exam   = get_exam($examId);
if (!$exam) {
    exit('<div style="font:14px/1.8 Microsoft YaHei;padding:40px">考试不存在。<a href="index.php">返回看板</a></div>');
}

/* All students who already submitted or were graded */
$students = db_all(
    "SELECT es.*, u.username, u.name, c.name AS class_name
       FROM exam_students es
       JOIN users u ON u.id = es.student_id
       LEFT JOIN classes c ON c.id = u.class_id
      WHERE es.exam_id = ? AND es.status IN ('submitted','graded')
      ORDER BY u.username ASC",
    array($examId)
);
if (!$students) {
    exit('<div style="font:14px/1.8 Microsoft YaHei;padding:40px">该场考试还没有人交卷，无可打包。<a href="index.php?exam_id=' . $examId . '">返回看板</a></div>');
}

$questions = exam_questions($examId);

/* Per-question score map for the CSV
   ★ 一次查询取回该场考试所有人的 answers / grading，避免 for 循环里逐个查（N+1） */
$scoreMap = array();
foreach (db_all('SELECT student_id, question_id, score FROM answers WHERE exam_id = ?', array($examId)) as $a) {
    $scoreMap[(int)$a['student_id']][(int)$a['question_id']] = $a['score'];
}
foreach (db_all('SELECT student_id, question_id, score FROM grading WHERE exam_id = ?', array($examId)) as $g) {
    $scoreMap[(int)$g['student_id']][(int)$g['question_id']] = $g['score'];
}
$answerRows = array();
foreach (db_all('SELECT * FROM answers WHERE exam_id = ?', array($examId)) as $a) {
    $answerRows[(int)$a['student_id']][(int)$a['question_id']] = $a;
}
$gradingRows = array();
foreach (db_all('SELECT * FROM grading WHERE exam_id = ?', array($examId)) as $g) {
    $gradingRows[(int)$g['student_id']][(int)$g['question_id']] = $g;
}
$uploadRows = array();
foreach (db_all('SELECT * FROM uploads WHERE exam_id = ?', array($examId)) as $f) {
    $uploadRows[(int)$f['student_id']][(int)$f['question_id']][] = $f;
}

list($csvHeaders, $csvRows) = build_score_table($exam, $students, $questions, $scoreMap);
$csv    = csv_to_string($csvHeaders, $csvRows);
$readme = build_readme($exam, $students, count($questions));

$zipPath = tempnam(sys_get_temp_dir(), 'paper_zip_');
if ($zipPath === false) {
    exit('<div style="font:14px/1.8 Microsoft YaHei;padding:40px">无法创建临时文件，请检查服务器可写路径。</div>');
}

$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
    unlink($zipPath);
    exit('<div style="font:14px/1.8 Microsoft YaHei;padding:40px">ZipArchive 初始化失败，请确认 PHP 已启用 ZipArchive 扩展。</div>');
}

/* 收集本卷所有需要外置到 ZIP 的附件：所有非图片附件 + 超大图片 */
$externalMap = array();

foreach ($students as $s) {
    $sid     = (int)$s['student_id'];
    $answers = isset($answerRows[$sid]) ? $answerRows[$sid] : array();
    $shots   = isset($uploadRows[$sid]) ? $uploadRows[$sid] : array();
    $graded  = isset($gradingRows[$sid]) ? $gradingRows[$sid] : array();

    /* The student row doubles as both the student info and exam_students row. */
    $html  = render_student_paper($exam, $s, $s, $questions, $answers, $shots, $graded, true, 'images');
    $fname = preg_replace('/[\\\\\/:*?"<>|]/u', '_', $s['username'] . '_' . $s['name']) . '.html';
    $zip->addFromString($fname, $html);

    /* 外置附件原样拷进 images/ 子目录，HTML 里已经改成了相对路径引用 */
    foreach (paper_files_to_zip($shots) as $f) {
        $abs = APP_ROOT . '/uploads/' . $f['file_path'];
        if (!is_file($abs)) continue;
        $base = pathinfo($f['file_path'], PATHINFO_BASENAME);
        $zip->addFile($abs, 'images/' . $base);
        $externalMap[(int)$f['id']] = 'images/' . $base;
    }
}

$zip->addFromString('成绩表.csv', $csv);
$zip->addFromString('README.txt', $readme);
$zip->close();

/* Stream the ZIP as a download, then clean up. */
$base = preg_replace('/[\\\\\/:*?"<>|]/u', '_', $exam['title']);
$dl   = $base . '_全班答卷_' . date('Ymd_His') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $dl . '"');
header('Content-Length: ' . filesize($zipPath));
readfile($zipPath);
unlink($zipPath);

log_event('pack', '全班答卷打包下载，' . count($students) . ' 人', $examId, null);
exit;

/**
 * Build the score-table headers and rows.
 * @return array [headers, rows]
 */
function build_score_table($exam, $students, $questions, $scoreMap)
{
    $statusText = array(
        'assigned'  => '未开始',
        'ongoing'   => '答题中',
        'submitted' => '已交卷·待阅',
        'graded'    => '已阅卷',
    );
    $submitText = array('manual' => '手动', 'timeout' => '超时', 'force' => '强制');

    $headers = array('学号', '姓名', '班级', '状态', '开始时间', '交卷时间', '用时(分)', '交卷方式', '切屏次数', '附件数');
    foreach ($questions as $q) {
        $headers[] = '第' . $q['seq'] . '题' . type_label($q['type'])
            . '/' . rtrim(rtrim(number_format($q['exam_score'], 1), '0'), '.') . '分';
    }
    $headers[] = '客观分';
    $headers[] = '主观分';
    $headers[] = '总分';

    /* 附件数：一次查回来，避免循环里逐个 COUNT */
    $attMap = array();
    foreach (db_all('SELECT student_id, COUNT(*) c FROM uploads WHERE exam_id = ? GROUP BY student_id',
                    array((int)$exam['id'])) as $r) {
        $attMap[(int)$r['student_id']] = (int)$r['c'];
    }

    $rows = array();
    foreach ($students as $s) {
        $sid  = (int)$s['student_id'];
        $used = '';
        if ($s['start_at'] && $s['submit_at']) {
            /* 交卷时刻 - 开始时刻，分钟，取整 */
            $used = (int)round((strtotime($s['submit_at']) - strtotime($s['start_at'])) / 60);
            if ($used < 0) $used = 0;
        }
        $row = array(
            $s['username'],
            $s['name'],
            (string)$s['class_name'],
            isset($statusText[$s['status']]) ? $statusText[$s['status']] : $s['status'],
            (string)$s['start_at'],
            (string)$s['submit_at'],
            $used,
            isset($submitText[$s['submit_type']]) ? $submitText[$s['submit_type']] : '',
            (int)$s['blur_count'],
            isset($attMap[$sid]) ? $attMap[$sid] : 0,
        );
        foreach ($questions as $q) {
            $qid = (int)$q['id'];
            $row[] = isset($scoreMap[$sid][$qid]) && $scoreMap[$sid][$qid] !== null
                ? rtrim(rtrim(number_format((float)$scoreMap[$sid][$qid], 1), '0'), '.')
                : '';
        }
        $row[] = rtrim(rtrim(number_format((float)$s['score_objective'], 1), '0'), '.');
        $row[] = $s['score_subjective'] === null
            ? '' : rtrim(rtrim(number_format((float)$s['score_subjective'], 1), '0'), '.');
        $row[] = $s['score_total'] === null
            ? '' : rtrim(rtrim(number_format((float)$s['score_total'], 1), '0'), '.');
        $rows[] = $row;
    }

    return array($headers, $rows);
}

/** Write a table to a memory stream and return the CSV string. */
function csv_to_string($headers, $rows)
{
    $fh = fopen('php://temp', 'w+');
    fwrite($fh, "\xEF\xBB\xBF"); /* UTF-8 BOM so Excel opens Chinese correctly */
    fputcsv($fh, $headers);
    foreach ($rows as $r) {
        fputcsv($fh, $r);
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv;
}

/** Build the README text for the archive. */
function build_readme($exam, $students, $questionCount)
{
    $lines = array();
    $lines[] = '试卷打包说明';
    $lines[] = '================';
    $lines[] = '考试：' . $exam['title'];
    $lines[] = '考试 ID：' . (int)$exam['id'];
    $lines[] = '时长：' . (int)$exam['duration_min'] . ' 分钟';
    $lines[] = '打包时间：' . date('Y-m-d H:i:s');
    $lines[] = '学生人数：' . count($students);
    $lines[] = '题目数量：' . $questionCount;
    $lines[] = '';
    $lines[] = '文件说明：';
    $lines[] = '1. 每位学生一个 <学号>_<姓名>.html 文件，包含完整答卷（前言+题目+学生作答+得分+参考答案）。';
    $lines[] = '2. 小图已内嵌 base64 到 HTML；超过配置上限的大图原样放在 images/ 子目录，';
    $lines[] = '   HTML 里用相对路径 images/xxx.png 引用，浏览器直接打开 HTML 就能看图。';
    $lines[] = '3. 样式已内联，离线也能正常显示、可打印。';
    $lines[] = '4. 成绩表.csv 为全班成绩表，含各题得分、附件数与总分（UTF-8 带 BOM，Excel 直接打开）。';
    $lines[] = '5. 本打包仅包含已交卷或已阅卷的学生。';
    $lines[] = '';
    return implode("\r\n", $lines);
}