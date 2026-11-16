<?php
/**
 * 保存单题答案（自动保存 / 交卷前调用）
 */
require __DIR__ . '/_common.php';
api_csrf();

list($u, $exam, $es, $examId) = api_context();

$qid    = (int)post('question_id');
$answer = isset($_POST['answer']) ? (string)$_POST['answer'] : '';

// 校验题目属于本卷
$eq = db_one('SELECT eq.score, q.type FROM exam_questions eq JOIN questions q ON q.id = eq.question_id
              WHERE eq.exam_id = ? AND eq.question_id = ?', array($examId, $qid));
if (!$eq) {
    json_out(array('ok' => false, 'msg' => '题目不属于本卷'));
}

// 客观题答案规范化；判断题统一成 T / F
if ($eq['type'] === 'single') {
    $answer = mb_substr(strtoupper(preg_replace('/[^A-Za-z]/', '', $answer)), 0, 1);
} elseif ($eq['type'] === 'judge') {
    $answer = ($answer === 'T' || $answer === 'F') ? $answer : '';
} else {
    $answer = mb_substr($answer, 0, 8000);
}

$exists = db_val('SELECT id FROM answers WHERE exam_id = ? AND student_id = ? AND question_id = ?',
    array($examId, $u['id'], $qid));
if ($exists) {
    db_exec('UPDATE answers SET answer_text = ?, updated_at = ? WHERE id = ?',
        array($answer, now(), $exists));
} else {
    db_exec('INSERT INTO answers (exam_id, student_id, question_id, answer_text, updated_at) VALUES (?,?,?,?,?)',
        array($examId, $u['id'], $qid, $answer, now()));
}

json_out(array('ok' => true, 'remaining' => remaining_seconds($es, $exam)));
