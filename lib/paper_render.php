<?php
/**
 * 学生答卷渲染（供“查看答卷”与“全班打包下载”共用）
 */
defined('APP_ROOT') or exit('Access denied');

/** 判断某条附件记录是否为图片 */
function paper_is_image($f)
{
    return (isset($f['kind']) && $f['kind'] === 'image')
        || is_image_file($f['file_path'], isset($f['orig_name']) ? $f['orig_name'] : '');
}

/**
 * 图片地址。
 *
 * 三种情形：
 *  1. $embed=true 且文件较小（≤ embed_image_max_kb）→ 返回 base64 data URI，离线自包含
 *  2. $embed=true 但文件过大 → 返回 ZIP 内相对路径（HTML 在 ZIP 根，图在 images/ 子目录）
 *  3. $embed=false 或文件不存在 → 返回站点可访问 URL
 *
 * ★ 内存保护：避免 40 人 × 多道实操题 × 20MB 附件把 PHP 内存撑爆。
 */
function paper_image_src($f, $embed, $zipBase = 'images')
{
    global $CONFIG;
    $rel = $f['file_path'];
    $abs = APP_ROOT . '/uploads/' . $rel;
    $maxKb = isset($CONFIG['site']['embed_image_max_kb'])
        ? (int)$CONFIG['site']['embed_image_max_kb'] : 4096;

    $exists = is_file($abs);
    $tooBig = $maxKb > 0 && $exists && filesize($abs) > $maxKb * 1024;

    if ($embed && $exists && !$tooBig) {
        $mime = !empty($f['mime']) ? $f['mime'] : 'image/png';
        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($abs));
    }
    if ($embed && $tooBig) {
        return $zipBase . '/' . basename($rel);
    }
    return upload_url($rel);
}

/** 非图片附件的链接：打包时用 ZIP 内相对路径，在线时用站点 URL */
function paper_file_href($f, $embed, $zipBase = 'images')
{
    global $CONFIG;
    $rel = $f['file_path'];
    $abs = APP_ROOT . '/uploads/' . $rel;
    $maxKb = isset($CONFIG['site']['embed_image_max_kb'])
        ? (int)$CONFIG['site']['embed_image_max_kb'] : 4096;
    $tooBig = $maxKb > 0 && is_file($abs) && filesize($abs) > $maxKb * 1024;

    if ($embed) {
        /* 非图片一律外置：base64 编码一个 docx/zip 只会把 HTML 撑得又慢又难看 */
        return $zipBase . '/' . basename($rel);
    }
    return upload_url($rel);
}

/** 判断这张图片在打包时是否需要把原文件一并放进 ZIP（大图走相对路径） */
function paper_image_is_external($f)
{
    global $CONFIG;
    $abs = APP_ROOT . '/uploads/' . $f['file_path'];
    $maxKb = isset($CONFIG['site']['embed_image_max_kb'])
        ? (int)$CONFIG['site']['embed_image_max_kb'] : 4096;
    return $maxKb > 0 && is_file($abs) && filesize($abs) > $maxKb * 1024;
}

/**
 * 打包时：列出必须原样放进 ZIP 的附件（所有非图片附件 + 超大图片）。
 * 返回值 key 为 uploads 表 id，value 为该行记录。
 */
function paper_files_to_zip($shots)
{
    $out = array();
    foreach ($shots as $qlist) {
        if (!$qlist) continue;
        foreach ($qlist as $f) {
            if (!paper_is_image($f) || paper_image_is_external($f)) {
                $out[(int)$f['id']] = $f;
            }
        }
    }
    return $out;
}

/** 得分显示：'12 / 20' 或 '未评分' */
function paper_qscore($score, $full)
{
    if ($score === null) {
        return '未评分';
    }
    return rtrim(rtrim(number_format((float)$score, 1), '0'), '.')
        . ' / ' . rtrim(rtrim(number_format((float)$full, 1), '0'), '.');
}

/**
 * 渲染某学生完整答卷 HTML。
 * @param bool   $embed    是否内嵌截图(base64)并内联样式（用于离线打包）
 * @param string $zipBase  $embed=true 时外置文件的子目录名（默认 images）
 */
function render_student_paper($exam, $stu, $es, $questions, $answers, $shots, $graded, $embed = false, $zipBase = 'images')
{
    /* 本系统只有四种题型，大题编号为 一~四（与 lib/helpers.php 的 section_titles() 一致） */
    $sectionTitle = array(
        'single'    => '一、单项选择题',
        'judge'     => '二、判断题',
        'short'     => '三、简答题',
        'practical' => '四、实操题',
    );
    $statusText = array(
        'assigned'  => '未开始',
        'ongoing'   => '答题中',
        'submitted' => '已交卷待阅',
        'graded'    => '已阅卷',
    );
    $submitTypeText = array(
        'manual'  => '主动交卷',
        'timeout' => '超时',
        'force'   => '强制交卷',
    );
    $totalScore = 0;
    foreach ($questions as $q) {
        $totalScore += (float)$q['exam_score'];
    }
    $examId = (int)$exam['id'];
    $sid    = (int)$es['student_id'];
    $css    = '';
    if ($embed) {
        $css = @file_get_contents(APP_ROOT . '/assets/css/app.css');
    }

    ob_start();
    include __DIR__ . '/paper_template.php';
    return ob_get_clean();
}