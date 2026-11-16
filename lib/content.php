<?php
/**
 * 题目内容的文本 ⇄ JSON 转换
 * 便于在后台表单和 CSV 里用"一行一项"的方式编辑，避免手写 JSON。
 */
defined('APP_ROOT') or exit('Access denied');

/** 选项：每行一个选项，自动编号 A、B、C… */
function text_to_options($text)
{
    $lines = preg_split('/\r\n|\r|\n/', (string)$text);
    $out = array();
    $i = 0;
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '') continue;
        // 允许写 "A. 内容" / "A、内容" / "A 内容"，去掉前缀
        $ln = preg_replace('/^[A-Ha-h]\s*[\.、:：\)）]\s*/u', '', $ln);
        $out[] = array('k' => chr(65 + $i), 't' => $ln);
        $i++;
        if ($i >= 8) break;
    }
    return $out;
}

function options_to_text($json)
{
    $arr = is_array($json) ? $json : jdec($json);
    $lines = array();
    foreach ($arr as $op) {
        $lines[] = (isset($op['k']) ? $op['k'] . '. ' : '') . (isset($op['t']) ? $op['t'] : '');
    }
    return implode("\n", $lines);
}

/** 评分点：每行 "要点描述 | 分值" */
function text_to_rubric($text)
{
    $lines = preg_split('/\r\n|\r|\n/', (string)$text);
    $out = array();
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '') continue;
        $parts = preg_split('/[|｜]/u', $ln);
        $item = trim($parts[0]);
        $sc = isset($parts[1]) ? (float)trim($parts[1]) : 1;
        if ($item === '') continue;
        $out[] = array('item' => $item, 'score' => $sc);
    }
    return $out;
}

function rubric_to_text($json)
{
    $arr = is_array($json) ? $json : jdec($json);
    $lines = array();
    foreach ($arr as $r) {
        $lines[] = (isset($r['item']) ? $r['item'] : '') . ' | ' . (isset($r['score']) ? $r['score'] : '');
    }
    return implode("\n", $lines);
}

/** 截图/附件清单：每行 "① 描述" 或 "描述" */
function text_to_shots($text)
{
    $lines = preg_split('/\r\n|\r|\n/', (string)$text);
    $marks = array('①','②','③','④','⑤','⑥','⑦','⑧','⑨','⑩');
    $out = array();
    $i = 0;
    foreach ($lines as $ln) {
        $ln = trim($ln);
        if ($ln === '') continue;
        $no = isset($marks[$i]) ? $marks[$i] : ('(' . ($i + 1) . ')');
        // 已自带序号则沿用
        if (preg_match('/^([①②③④⑤⑥⑦⑧⑨⑩])\s*(.+)$/u', $ln, $m)) {
            $no = $m[1];
            $ln = $m[2];
        }
        $out[] = array('no' => $no, 'desc' => $ln);
        $i++;
    }
    return $out;
}

function shots_to_text($json)
{
    $arr = is_array($json) ? $json : jdec($json);
    $lines = array();
    foreach ($arr as $s) {
        $lines[] = (isset($s['no']) ? $s['no'] : '') . ' ' . (isset($s['desc']) ? $s['desc'] : '');
    }
    return implode("\n", $lines);
}

/** 判断题答案规范化：T/F */
function norm_judge($v)
{
    $v = strtoupper(trim((string)$v));
    $t = array('T', 'TRUE', '对', '正确', '√', '1', 'Y', 'YES', '是');
    return in_array($v, $t, true) ? 'T' : 'F';
}

/** 选择题答案规范化 */
function norm_choice($v)
{
    return strtoupper(preg_replace('/[^A-Za-z]/', '', (string)$v));
}
