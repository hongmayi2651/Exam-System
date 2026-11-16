<?php
/**
 * 系统配置（模板文件，可提交到仓库）
 *
 * 部署时请复制本文件为 config/config.php，再改里面的数据库账号密码：
 *     copy config.example.php config.php     (Windows)
 *     cp config.example.php config.php      (Linux)
 *
 * config.php 已在 .gitignore 中，不会被提交。
 */
defined('APP_ROOT') or exit('Access denied');

return array(

    /* ---------------- 数据库 ---------------- */
    'db' => array(
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'linux_exam',
        'user'    => 'root',
        'pass'    => '',              // ← phpstudy 默认 'root'；XAMPP 默认留空 ''
        'charset' => 'utf8mb4',
    ),

    /* ---------------- 站点 ---------------- */
    'site' => array(
        // 站点根 URL，留空则自动识别。部署在子目录时必须填，例如 /linux-exam
        'base_url'   => '',
        'timezone'   => 'Asia/Shanghai',
        // 单点登录：同一账号只允许一处在线，后登录的顶掉先登录的
        'single_session' => true,

        // 实操题附件：允许上传任意类型，但禁止这些可被 Web 直接执行/渲染的扩展名
        'upload_ext_deny' => array(
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht',
            'jsp', 'jspx', 'asp', 'aspx', 'asa', 'cer', 'cdx', 'cgi', 'pl',
            'hta', 'htaccess', 'userini',
            // 会被浏览器当页面渲染、可能引发 XSS 的类型
            'html', 'htm', 'xhtml', 'shtml', 'svg', 'xml', 'xsl',
        ),
        // 单个附件上限（MB）
        'upload_max_mb' => 20,
        // 每题最多上传个数
        'upload_max_count' => 6,
        // 缩略图能预览的图片类型（其余类型按附件展示）
        'image_ext' => array('jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'),

        // 打包全班答卷时，单张截图内嵌 base64 的上限（KB）。
        // 超过就改为在 ZIP 里单独存文件 + HTML 用相对路径引用，避免 PHP 内存爆掉。
        'embed_image_max_kb' => 4096,
    ),

    /* ---------------- 考试默认参数 ---------------- */
    'exam' => array(
        'duration_min'  => 120,
        'total_score'   => 100,
        // 交卷后是否允许学生查看解析（本系统默认不给学生看试卷，保留开关备用）
        'show_analysis' => false,
    ),

    /* ---------------- 调试 ---------------- */
    'debug' => false,  // ← 正式使用请保持 false；开发时可临时改 true
);