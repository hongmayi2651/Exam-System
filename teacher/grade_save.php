<?php
/**
 * 保存某生某题评分
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
$u = require_login(array('teacher', 'admin'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('teacher/index.php');
}
csrf_check();

$examId  = (int)post('exam_id');
$sid     = (int)post('sid');
$qid     = (int)post('qid');
$comment = post('comment');
$goNext  = post('next', '1') === '1';

$eq = db_one('SELECT eq.score, q.rubric, q.type FROM exam_questions eq JOIN questions q ON q.id = eq.question_id
              WHERE eq.exam_id = ? AND eq.question_id = ?', array($examId, $qid));
if (!$eq) {
    flash('题目不属于本卷', 'err');
    redirect('teacher/grade.php?exam_id=' . $examId . '&sid=' . $sid);
}

$full  = (float)$eq['score'];
$items = isset($_POST['items']) && is_array($_POST['items']) ? $_POST['items'] : array();

// 规范化：每项 0~该评分点上限，合计不超过满分
$rubric = jdec($eq['rubric']);
$clean  = array();
$sum    = 0.0;
foreach ($items as $k => $v) {
    $v = round((float)$v, 1);
    if ($v < 0) $v = 0;
    if ($rubric && isset($rubric[$k]) && isset($rubric[$k]['score'])) {
        $mx = (float)$rubric[$k]['score'];
        if ($v > $mx) $v = $mx;
    }
    if ($v != 0 || $rubric) {
        $clean[(string)$k] = $v;
    }
    $sum += $v;
}
if ($sum > $full) {
    $sum = $full;
}
$sum = round($sum, 1);

$exists = db_val('SELECT id FROM grading WHERE exam_id = ? AND student_id = ? AND question_id = ?',
    array($examId, $sid, $qid));
$json = json_encode($clean, JSON_UNESCAPED_UNICODE);
if ($exists) {
    db_exec('UPDATE grading SET item_scores = ?, score = ?, comment = ?, teacher_id = ?, updated_at = ? WHERE id = ?',
        array($json, $sum, $comment, $u['id'], now(), $exists));
} else {
    db_insert('INSERT INTO grading (exam_id, student_id, question_id, item_scores, score, comment, teacher_id, created_at)
               VALUES (?,?,?,?,?,?,?,?)',
        array($examId, $sid, $qid, $json, $sum, $comment, $u['id'], now()));
}
// 同步答案表上的分数
db_exec('UPDATE answers SET score = ? WHERE exam_id = ? AND student_id = ? AND question_id = ?',
    array($sum, $examId, $sid, $qid));

$r = recalc_total($examId, $sid);
log_event('grade', '评分 第' . $qid . '题 ' . $sum . ' 分', $examId, $sid);

flash('已保存：本题 ' . fmt_num($sum) . ' 分'
    . ($r['total'] === null ? '' : '，当前总分 ' . fmt_num($r['total'])), 'ok');

/* 计算下一站 */
$nextQid = 0;
$nextSid = $sid;
if ($goNext) {
    $subQs = db_all("SELECT q.id FROM exam_questions eq JOIN questions q ON q.id = eq.question_id
                      WHERE eq.exam_id = ? AND eq.section IN ('short','practical') ORDER BY eq.seq ASC",
        array($examId));
    $done = array();
    foreach (db_all('SELECT question_id FROM grading WHERE exam_id = ? AND student_id = ?', array($examId, $sid)) as $g) {
        $done[(int)$g['question_id']] = true;
    }
    foreach ($subQs as $q) {
        $k = (int)$q['id'];
        if (!isset($done[$k])) { $nextQid = $k; break; }
    }
    if ($nextQid === 0) {
        // 该生已评完，找下一个有未评题目的学生
        $students = db_all("SELECT student_id FROM exam_students WHERE exam_id = ? AND status IN ('submitted','graded')
                            ORDER BY student_id ASC", array($examId));
        $totalQs = count($subQs);
        foreach ($students as $s) {
            $k = (int)$s['student_id'];
            if ($k === $sid) continue;
            $cnt = (int)db_val('SELECT COUNT(*) FROM grading WHERE exam_id = ? AND student_id = ?', array($examId, $k), 0);
            if ($cnt < $totalQs) { $nextSid = $k; break; }
        }
    }
}

if ($nextQid > 0) {
    redirect('teacher/grade.php?exam_id=' . $examId . '&sid=' . $nextSid . '&qid=' . $nextQid);
} elseif ($nextSid !== $sid) {
    redirect('teacher/grade.php?exam_id=' . $examId . '&sid=' . $nextSid);
} else {
    redirect('teacher/grade.php?exam_id=' . $examId . '&sid=' . $sid . '&qid=' . $qid);
}
