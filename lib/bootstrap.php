<?php
/**
 * 引导文件：所有入口页第一行 require 这个
 */
define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/lib/config.php';
$CONFIG = app_config();

date_default_timezone_set($CONFIG['site']['timezone']);
mb_internal_encoding('UTF-8');

if (!empty($CONFIG['debug'])) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
}

// 会话
if (session_status() !== PHP_SESSION_ACTIVE) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params(array(
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ));
    session_name('LNXEXAMSID');
    session_start();
}

require APP_ROOT . '/lib/helpers.php';
require APP_ROOT . '/lib/db.php';
require APP_ROOT . '/lib/content.php';
require APP_ROOT . '/lib/auth.php';
require APP_ROOT . '/lib/grading.php';

// 目录保护（直接访问 lib/config 下的文件会走到这里）
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) {
    exit('Access denied');
}