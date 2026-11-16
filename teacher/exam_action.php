<?php
/**
 * 考试控制：开考 / 统一收卷 / 强制交卷 / 延时
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
$u = require_login(array('teacher', 'admin'));

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('teacher/index.php');
}
csrf_check();

$examId = (int)post('exam_id');
$do     = post('do');
$exam   = get_exam($examId);
if (!$exam) {
    flash('考试不存在', 'err');
    redirect('teacher/index.php');
}

switch ($do) {
    case 'open':
        if ($exam['status'] !== 'ready') {
            flash('当前状态不能开考（需要先发布试卷）', 'err');
            break;
        }
        $qcnt = (int)db_val('SELECT COUNT(*) FROM exam_questions WHERE exam_id = ?', array($examId), 0);
        if ($qcnt === 0) {
            flash('试卷里还没有题目，不能开考', 'err');
            break;
        }
        $openAt  = now();
        $closeAt = date('Y-m-d H:i:s', strtotime($openAt) + (int)$exam['duration_min'] * 60);
        db_exec("UPDATE exams SET status = 'running', open_at = ?, close_at = ?, updated_at = ? WHERE id = ?",
            array($openAt, $closeAt, now(), $examId));
        log_event('open', '开考', $examId, null);
        flash('已开考，收卷时间 ' . fmt_dt($closeAt, 'H:i:s'), 'ok');
        break;

    case 'close':
        $rows = db_all("SELECT student_id FROM exam_students WHERE exam_id = ? AND status IN ('ongoing','assigned')",
            array($examId));
        $n = 0;
        foreach ($rows as $r) {
            finalize_exam($examId, (int)$r['student_id'], 'force');
            $n++;
        }
        db_exec("UPDATE exams SET status = 'finished', updated_at = ? WHERE id = ?", array(now(), $examId));
        log_event('close', '统一收卷，处理 ' . $n . ' 人', $examId, null);
        flash('已收卷，共处理 ' . $n . ' 名考生。', 'ok');
        break;

    case 'force_submit':
        $sid = (int)post('sid');
        if ($sid <= 0) {
            flash('参数错误', 'err');
            break;
        }
        finalize_exam($examId, $sid, 'force');
        log_event('force', '教师强制交卷', $examId, $sid);
        flash('已强制收该考生答卷。', 'ok');
        break;

    case 'reopen':
        /* 只允许对「考试中」的考试延长；已结束的不能被重新激活 */
        if ($exam['status'] !== 'running') {
            flash('当前状态不能延长（只有考试中的可以延时）', 'err');
            break;
        }
        $addMin = (int)post('add_min', 10);
        if ($addMin <= 0) { $addMin = 10; }
        $base    = $exam['close_at'] ? $exam['close_at'] : now();
        $newClose = date('Y-m-d H:i:s', strtotime($base) + $addMin * 60);
        db_exec("UPDATE exams SET close_at = ?, updated_at = ? WHERE id = ?",
            array($newClose, now(), $examId));
        // 同步延长个人截止时间
        db_exec("UPDATE exam_students SET deadline_at = DATE_ADD(deadline_at, INTERVAL ? MINUTE)
                  WHERE exam_id = ? AND status = 'ongoing' AND deadline_at IS NOT NULL",
            array($addMin, $examId));
        log_event('reopen', '延长 ' . $addMin . ' 分钟', $examId, null);
        flash('已延长 ' . $addMin . ' 分钟，新收卷时间 ' . fmt_dt($newClose, 'H:i:s'), 'ok');
        break;

    default:
        flash('未知操作', 'err');
}

redirect('teacher/index.php?exam_id=' . $examId);