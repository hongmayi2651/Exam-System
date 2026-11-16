<?php
/**
 * View a single student's full answer sheet.
 * paper.php?exam_id=1&sid=5
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
require dirname(__DIR__) . '/lib/paper_render.php';
$u = require_login(array('teacher', 'admin'));

$examId = (int)get('exam_id');
$sid    = (int)get('sid');
$exam   = get_exam($examId);
if (!$exam) {
    exit('<div style="font:14px/1.8 Microsoft YaHei;padding:40px">考试不存在。<a href="index.php">返回看板</a></div>');
}

$es = get_exam_student($examId, $sid);
if (!$es) {
    exit('<div style="font:14px/1.8 Microsoft YaHei;padding:40px">该生不在此考试中。<a href="index.php?exam_id=' . $examId . '">返回看板</a></div>');
}

$stu = db_one(
    'SELECT u.id, u.username, u.name, c.name AS class_name
       FROM users u
       LEFT JOIN classes c ON c.id = u.class_id
      WHERE u.id = ?',
    array($sid)
);
if (!$stu) {
    exit('<div style="font:14px/1.8 Microsoft YaHei;padding:40px">用户不存在。<a href="index.php?exam_id=' . $examId . '">返回看板</a></div>');
}

$questions = exam_questions($examId);
$answers   = student_answers($examId, $sid);

$shots = array();
foreach (student_uploads($examId, $sid) as $f) {
    $shots[(int)$f['question_id']][] = $f;
}

$graded = array();
foreach (db_all('SELECT * FROM grading WHERE exam_id = ? AND student_id = ?', array($examId, $sid)) as $g) {
    $graded[(int)$g['question_id']] = $g;
}

echo render_student_paper($exam, $stu, $es, $questions, $answers, $shots, $graded, false);
