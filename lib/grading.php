<?php
/**
 * 判分与试卷装配
 */
defined('APP_ROOT') or exit('Access denied');

/** 取考试的试卷题目（含题目本体），按 seq 排序 */
function exam_questions($examId)
{
    $rows = db_all(
        'SELECT q.*, eq.seq, eq.score AS exam_score, eq.section
           FROM exam_questions eq
           JOIN questions q ON q.id = eq.question_id
          WHERE eq.exam_id = ?
          ORDER BY eq.seq ASC',
        array($examId)
    );
    foreach ($rows as &$r) {
        $r['options']     = jdec($r['options']);
        $r['rubric']      = jdec($r['rubric']);
        $r['pract_shots'] = jdec($r['pract_shots']);
    }
    unset($r);
    return $rows;
}

/** 考试基本信息 */
function get_exam($examId)
{
    $e = db_one('SELECT * FROM exams WHERE id = ?', array($examId));
    if ($e) {
        $e['settings_arr'] = jdec($e['settings'], array());
    }
    return $e;
}

/** 取考生在某场考试的记录 */
function get_exam_student($examId, $studentId)
{
    return db_one('SELECT * FROM exam_students WHERE exam_id = ? AND student_id = ?', array($examId, $studentId));
}

/** 取学生全部答案，key = question_id */
function student_answers($examId, $studentId)
{
    $rows = db_all('SELECT * FROM answers WHERE exam_id = ? AND student_id = ?', array($examId, $studentId));
    $out = array();
    foreach ($rows as $r) {
        $out[(int)$r['question_id']] = $r;
    }
    return $out;
}

/** 学生某题的附件（截图 / 任意文件） */
function student_uploads($examId, $studentId, $questionId = null)
{
    if ($questionId === null) {
        $rows = db_all('SELECT * FROM uploads WHERE exam_id = ? AND student_id = ? ORDER BY id ASC',
            array($examId, $studentId));
    } else {
        $rows = db_all('SELECT * FROM uploads WHERE exam_id = ? AND student_id = ? AND question_id = ? ORDER BY id ASC',
            array($examId, $studentId, $questionId));
    }
    return $rows;
}

/**
 * 判定某道客观题得分
 */
function score_one_objective($question, $answerText, $fullScore, $rule = null)
{
    list($isCorrect, $ratio) = judge_objective($question['type'], $question['answer'], $answerText, $rule);
    $score = round($fullScore * $ratio, 1);
    return array($isCorrect, $score);
}

/**
 * 重算某考生的客观题总分，并同步 answers 表
 * 返回客观题总分
 */
function recalc_objective($examId, $studentId)
{
    $qs    = exam_questions($examId);
    $ans   = student_answers($examId, $studentId);
    $total = 0.0;

    foreach ($qs as $q) {
        if (!is_objective($q['type'])) {
            continue;
        }
        $mine = isset($ans[(int)$q['id']]) ? (string)$ans[(int)$q['id']]['answer_text'] : '';
        list($isCorrect, $score) = score_one_objective($q, $mine, (float)$q['exam_score']);
        $total += $score;

        if (isset($ans[(int)$q['id']])) {
            db_exec('UPDATE answers SET is_correct = ?, score = ?, auto_scored = 1 WHERE id = ?',
                array($isCorrect, $score, $ans[(int)$q['id']]['id']));
        } else {
            // 没作答也建一行，便于统计
            db_exec('INSERT INTO answers (exam_id, student_id, question_id, answer_text, is_correct, score, auto_scored, updated_at)
                     VALUES (?,?,?,?,?,?,?,?)',
                array($examId, $studentId, $q['id'], '', 0, 0, 1, now()));
        }
    }
    $total = round($total, 1);
    db_exec('UPDATE exam_students SET score_objective = ? WHERE exam_id = ? AND student_id = ?',
        array($total, $examId, $studentId));
    return $total;
}

/**
 * 汇总某考生总分
 * 主观题得分：若已阅卷取 grading 之和；未阅卷则 score_subjective 为 NULL
 */
function recalc_total($examId, $studentId)
{
    $obj = (float)db_val('SELECT score_objective FROM exam_students WHERE exam_id = ? AND student_id = ?',
        array($examId, $studentId), 0);

    $subjectiveQs = db_all(
        "SELECT eq.question_id, eq.score FROM exam_questions eq
          WHERE eq.exam_id = ? AND eq.section IN ('short','practical')",
        array($examId)
    );
    $gradedCount = (int)db_val(
        'SELECT COUNT(*) FROM grading WHERE exam_id = ? AND student_id = ?',
        array($examId, $studentId), 0
    );
    $gradedSum = (float)db_val(
        'SELECT COALESCE(SUM(score),0) FROM grading WHERE exam_id = ? AND student_id = ?',
        array($examId, $studentId), 0
    );

    $subjective = null;
    $status     = 'submitted';
    if (count($subjectiveQs) === 0 || $gradedCount >= count($subjectiveQs)) {
        $subjective = round($gradedSum, 1);
        $status     = 'graded';
    }
    $total = $subjective === null ? null : round($obj + $subjective, 1);

    db_exec('UPDATE exam_students SET score_subjective = ?, score_total = ?, status = ?,
                    graded_at = IF(? IS NULL, graded_at, ?)
              WHERE exam_id = ? AND student_id = ?',
        array($subjective, $total, $status,
              $subjective, ($subjective === null ? null : now()),
              $examId, $studentId));

    return array('objective' => $obj, 'subjective' => $subjective, 'total' => $total);
}

/** 考试剩余秒数（按学生个人 deadline，同时也受全卷 close_at 约束） */
function remaining_seconds($examStudentRow, $examRow)
{
    $now = time();
    $deadline = null;
    if (!empty($examStudentRow['deadline_at'])) {
        $deadline = strtotime($examStudentRow['deadline_at']);
    }
    $close = !empty($examRow['close_at']) ? strtotime($examRow['close_at']) : null;
    if ($deadline === null && $close === null) {
        return 0;
    }
    if ($deadline === null) {
        $deadline = $close;
    } elseif ($close !== null) {
        $deadline = min($deadline, $close);
    }
    return max(0, $deadline - $now);
}

/** 从上传记录生成可访问 URL */
function upload_url($relPath)
{
    return base_url('uploads/' . ltrim($relPath, '/'));
}

/**
 * 收卷：计算客观题得分、锁定答卷
 * @param string $type manual | timeout | force
 */
function finalize_exam($examId, $studentId, $type = 'manual')
{
    $es = get_exam_student($examId, $studentId);
    if (!$es) {
        return false;
    }
    if (in_array($es['status'], array('submitted', 'graded'), true)) {
        return true;
    }
    recalc_objective($examId, $studentId);
    db_exec("UPDATE exam_students SET status = 'submitted', submit_at = ?, submit_type = ? WHERE id = ?",
        array(now(), $type, $es['id']));
    recalc_total($examId, $studentId);
    log_event('submit', '交卷（' . $type . '）', $examId, $studentId);
    return true;
}
