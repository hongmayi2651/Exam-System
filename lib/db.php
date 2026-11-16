<?php
/**
 * 数据库连接（PDO 单例）
 */
defined('APP_ROOT') or exit('Access denied');

function db()
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    global $CONFIG;
    $c = $CONFIG['db'];
    $dsn = 'mysql:host=' . $c['host'] . ';port=' . $c['port'] . ';dbname=' . $c['name'] . ';charset=' . $c['charset'];
    try {
        $pdo = new PDO($dsn, $c['user'], $c['pass'], array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ));
    } catch (PDOException $e) {
        http_response_code(500);
        $msg = '数据库连接失败：' . $e->getMessage();
        if (!empty($CONFIG['debug'])) {
            $msg .= '<br>请检查 config/config.php 中的 db 配置，并确认已导入 sql/install.sql。';
        }
        exit('<div style="font:14px/1.8 Microsoft YaHei;padding:40px">' . $msg . '</div>');
    }
    return $pdo;
}

/** 查询多行 */
function db_all($sql, $params = array())
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** 查询一行 */
function db_one($sql, $params = array())
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

/** 查询单个值 */
function db_val($sql, $params = array(), $default = null)
{
    $st = db()->prepare($sql);
    $st->execute($params);
    $v = $st->fetchColumn();
    return $v === false ? $default : $v;
}

/** 执行写操作，返回影响行数 */
function db_exec($sql, $params = array())
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st->rowCount();
}

/** 插入并返回自增 ID */
function db_insert($sql, $params = array())
{
    db_exec($sql, $params);
    return (int)db()->lastInsertId();
}
