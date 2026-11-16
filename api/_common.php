<?php
/**
 * API 公共前置：校验登录、考试状态、剩余时间
 */
require dirname(__DIR__) . '/lib/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * @return array [$user, $exam, $examStudent, $examId]
 */
function api_context($requireOngoing = true)
{
    $u = require_login('student');

    $examId = 0;
    if (isset($_POST['exam_id'])) {
        $examId = (int)$_POST['exam_id'];
    } elseif (isset($_GET['exam_id'])) {
        $examId = (int)$_GET['exam_id'];
    }
    if ($examId <= 0) {
        json_out(array('ok' => false, 'msg' => '缺少考试参数'));
    }
    $exam = get_exam($examId);
    if (!$exam) {
        json_out(array('ok' => false, 'msg' => '考试不存在'));
    }
    $es = get_exam_student($examId, $u['id']);
    if (!$es) {
        json_out(array('ok' => false, 'msg' => '这场考试没有分配给你'));
    }
    if (in_array($es['status'], array('submitted', 'graded'), true)) {
        json_out(array('ok' => false, 'submitted' => true, 'msg' => '已交卷'));
    }
    if ($requireOngoing) {
        if ($es['status'] !== 'ongoing') {
            json_out(array('ok' => false, 'msg' => '尚未开始答题'));
        }
        // 服务端时间到 → 自动收卷
        if (remaining_seconds($es, $exam) <= 0) {
            finalize_exam($examId, $u['id'], 'timeout');
            json_out(array('ok' => false, 'submitted' => true, 'msg' => '考试时间到，已自动交卷'));
        }
    }
    return array($u, $exam, $es, $examId);
}

/** 校验 CSRF（POST） */
function api_csrf()
{
    $t = isset($_POST['_token']) ? $_POST['_token'] : '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$t)) {
        json_out(array('ok' => false, 'msg' => '页面已过期，请刷新后重试'), 419);
    }
}
