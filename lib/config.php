<?php
/**
 * 配置加载器
 *
 * 优先读 config/config.php（真实配置，已被 .gitignore 排除，不入库）；
 * 找不到时回退到 config/config.example.php（模板，可入库）。
 *
 * 好处：clone 仓库后不建 config.php 也能跑 install_check.php 看到自检结果，
 *      只会报「数据库连接失败」这一项，不会因缺文件直接崩掉。
 */
defined('APP_ROOT') or exit('Access denied');

function app_config_path()
{
    $real = APP_ROOT . '/config/config.php';
    if (is_file($real)) {
        return $real;
    }
    $tpl = APP_ROOT . '/config/config.example.php';
    if (is_file($tpl)) {
        return $tpl;
    }
    return null;
}

/**
 * 载入配置数组；两个文件都不存在时给出可操作的提示。
 * @return array
 */
function app_config()
{
    $path = app_config_path();
    if ($path === null) {
        http_response_code(500);
        exit('<div style="font:14px/1.8 Microsoft YaHei;padding:40px">'
            . '缺少配置文件。请把 <code>config/config.example.php</code> 复制为 <code>config/config.php</code>，'
            . '并填入数据库账号密码。</div>');
    }
    return require $path;
}

/** 当前实际生效的配置来源（用于自检页展示） */
function app_config_source()
{
    $p = app_config_path();
    return $p === null ? '（无）' : str_replace(APP_ROOT . DIRECTORY_SEPARATOR, '', $p);
}