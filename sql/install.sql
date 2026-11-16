-- =====================================================================
--  Linux 服务器配置与管理 · 考试系统  建库建表脚本
--  适用：MySQL 5.7 / 8.0，MariaDB 10.3+
--  用法：mysql -u root -p < install.sql
--        或在 phpMyAdmin / Navicat 中整段执行
--
--  ★ 安全说明：本脚本已改为幂等版本，重复执行不会删除已有数据。
--    所有表使用 CREATE TABLE IF NOT EXISTS，初始数据使用 INSERT IGNORE。
--    如确需重建（清空重来），请先手动 mysqldump 备份，然后
--    再执行文件末尾注释里的 "强制重建" 段。
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE DATABASE IF NOT EXISTS `linux_exam`
  DEFAULT CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE `linux_exam`;

-- ---------------------------------------------------------------------
-- 1. 班级
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `classes` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`       VARCHAR(50)  NOT NULL COMMENT '班级名称，如 25计网1班',
  `remark`     VARCHAR(200) DEFAULT NULL,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_class_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='班级';

-- ---------------------------------------------------------------------
-- 2. 用户（管理员 / 教师 / 学生）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`       VARCHAR(50)  NOT NULL COMMENT '登录名，学生用学号',
  `name`           VARCHAR(50)  NOT NULL COMMENT '真实姓名',
  `password`       VARCHAR(255) NOT NULL COMMENT 'password_hash() 结果',
  `role`           ENUM('admin','teacher','student') NOT NULL DEFAULT 'student',
  `class_id`       INT UNSIGNED DEFAULT NULL,
  `status`         TINYINT      NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `must_change_pwd` TINYINT     NOT NULL DEFAULT 0,
  `active_session` VARCHAR(64)  DEFAULT NULL COMMENT '当前有效会话令牌，用于单点登录',
  `last_login_at`  DATETIME     DEFAULT NULL,
  `last_login_ip`  VARCHAR(45)  DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  KEY `idx_class` (`class_id`),
  KEY `idx_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='用户';

-- ---------------------------------------------------------------------
-- 3. 题库（题型：单选 / 判断 / 简答 / 实操）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `questions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type`        ENUM('single','judge','short','practical') NOT NULL
                COMMENT '单选/判断/简答/实操',
  `chapter`     VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '章节/项目，如 项目5',
  `topic`       VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '考点，如 LVM',
  `difficulty`  TINYINT      NOT NULL DEFAULT 2 COMMENT '1易 2中 3难',
  `stem`        TEXT         NOT NULL COMMENT '题干（支持纯文本+换行）',
  `options`     TEXT         DEFAULT NULL COMMENT 'JSON: [{"k":"A","t":"选项内容"},...]',
  `answer`      TEXT         DEFAULT NULL COMMENT '单选"B" 判断"T"/"F"',
  `analysis`    TEXT         DEFAULT NULL COMMENT '解析',
  `ref_answer`  TEXT         DEFAULT NULL COMMENT '简答/实操参考答案',
  `rubric`      TEXT         DEFAULT NULL COMMENT 'JSON 评分点: [{"item":"要点","score":2}]',
  `pract_steps` TEXT         DEFAULT NULL COMMENT '实操任务要求（纯文本，换行分段）',
  `pract_shots` TEXT         DEFAULT NULL COMMENT 'JSON 截图/附件清单: [{"no":"①","desc":"..."}]',
  `default_score` DECIMAL(4,1) NOT NULL DEFAULT 0 COMMENT '默认分值',
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_type` (`type`),
  KEY `idx_topic` (`topic`),
  KEY `idx_chapter` (`chapter`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='题库';

-- ---------------------------------------------------------------------
-- 4. 考试
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exams` (
  `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title`        VARCHAR(100) NOT NULL,
  `subject`      VARCHAR(50)  NOT NULL DEFAULT 'Linux服务器配置与管理',
  `notice`       TEXT         DEFAULT NULL COMMENT '考前须知',
  `duration_min` INT          NOT NULL DEFAULT 120 COMMENT '考试时长（分钟）',
  `total_score`  DECIMAL(5,1) NOT NULL DEFAULT 100,
  `plan_start`   DATETIME     DEFAULT NULL COMMENT '计划开考时间（仅提示用）',
  `open_at`      DATETIME     DEFAULT NULL COMMENT '实际开考时刻，教师点"开考"后写入',
  `close_at`     DATETIME     DEFAULT NULL COMMENT '统一收卷时刻 = open_at + duration',
  `status`       ENUM('draft','ready','running','finished') NOT NULL DEFAULT 'draft',
  `settings`     TEXT         DEFAULT NULL COMMENT 'JSON: 判分规则、防作弊开关等',
  `created_by`   INT UNSIGNED DEFAULT NULL,
  `created_at`   DATETIME     NOT NULL,
  `updated_at`   DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='考试';

-- ---------------------------------------------------------------------
-- 5. 试卷题目（某场考试包含哪些题、顺序、分值）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exam_questions` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id`     INT UNSIGNED NOT NULL,
  `question_id` INT UNSIGNED NOT NULL,
  `section`     ENUM('single','judge','short','practical') NOT NULL,
  `seq`         INT          NOT NULL COMMENT '卷面顺序，从1开始',
  `score`       DECIMAL(4,1) NOT NULL COMMENT '本卷该题分值（可覆盖题库默认分）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_question` (`exam_id`,`question_id`),
  KEY `idx_exam_seq` (`exam_id`,`seq`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='试卷题目';

-- ---------------------------------------------------------------------
-- 6. 考生与答卷状态
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `exam_students` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id`          INT UNSIGNED NOT NULL,
  `student_id`       INT UNSIGNED NOT NULL,
  `status`           ENUM('assigned','ongoing','submitted','graded') NOT NULL DEFAULT 'assigned'
                     COMMENT 'assigned未开始 ongoing答题中 submitted已交卷 graded已阅卷',
  `start_at`         DATETIME     DEFAULT NULL COMMENT '个人开始答题时刻',
  `deadline_at`      DATETIME     DEFAULT NULL COMMENT '个人截止时刻',
  `submit_at`        DATETIME     DEFAULT NULL,
  `submit_type`      ENUM('manual','timeout','force') DEFAULT NULL
                     COMMENT 'manual主动交卷 timeout超时自动 force教师强制',
  `ip`               VARCHAR(45)  DEFAULT NULL,
  `session_token`    VARCHAR(64)  DEFAULT NULL,
  `blur_count`       INT          NOT NULL DEFAULT 0 COMMENT '切屏次数',
  `blur_seconds`     INT          NOT NULL DEFAULT 0 COMMENT '切屏累计秒数',
  `score_objective`  DECIMAL(5,1) NOT NULL DEFAULT 0 COMMENT '客观题得分',
  `score_subjective` DECIMAL(5,1) DEFAULT NULL COMMENT '主观题得分',
  `score_total`      DECIMAL(5,1) DEFAULT NULL,
  `graded_by`        INT UNSIGNED DEFAULT NULL,
  `graded_at`        DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_exam_student` (`exam_id`,`student_id`),
  KEY `idx_status` (`exam_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='考生答卷状态';

-- ---------------------------------------------------------------------
-- 7. 答案（每生每题一行，答题过程中不断更新）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `answers` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id`     INT UNSIGNED NOT NULL,
  `student_id`  INT UNSIGNED NOT NULL,
  `question_id` INT UNSIGNED NOT NULL,
  `answer_text` MEDIUMTEXT   DEFAULT NULL COMMENT '选择:"A" 判断:"T"/"F"  简答/实操:文本',
  `is_correct`  TINYINT      DEFAULT NULL COMMENT '客观题判对 1/0',
  `score`       DECIMAL(4,1) DEFAULT NULL,
  `auto_scored` TINYINT      NOT NULL DEFAULT 0,
  `updated_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_answer` (`exam_id`,`student_id`,`question_id`),
  KEY `idx_student` (`exam_id`,`student_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='答案';

-- ---------------------------------------------------------------------
-- 8. 附件（实操题：粘贴的截图 + 任意类型附件）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `uploads` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id`     INT UNSIGNED NOT NULL,
  `student_id`  INT UNSIGNED NOT NULL,
  `question_id` INT UNSIGNED NOT NULL,
  `orig_name`   VARCHAR(200) DEFAULT NULL,
  `file_path`   VARCHAR(200) NOT NULL COMMENT '相对 uploads/ 的路径',
  `mime`        VARCHAR(100) DEFAULT NULL,
  `ext`         VARCHAR(20)  DEFAULT NULL COMMENT '扩展名（小写）',
  `kind`        ENUM('image','file') NOT NULL DEFAULT 'file' COMMENT 'image=可预览图片 file=其它附件',
  `size`        INT UNSIGNED NOT NULL DEFAULT 0,
  `sha1`        CHAR(40)     DEFAULT NULL,
  `source`      ENUM('paste','pick') NOT NULL DEFAULT 'pick' COMMENT 'paste=剪贴板粘贴 pick=选择文件',
  `created_at`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_owner` (`exam_id`,`student_id`,`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='实操题附件';

-- ---------------------------------------------------------------------
-- 9. 人工评分（简答 / 实操）
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `grading` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id`     INT UNSIGNED NOT NULL,
  `student_id`  INT UNSIGNED NOT NULL,
  `question_id` INT UNSIGNED NOT NULL,
  `item_scores` TEXT         DEFAULT NULL COMMENT 'JSON: {"0":2,"1":1.5} 各评分点得分',
  `score`       DECIMAL(4,1) NOT NULL DEFAULT 0 COMMENT '本题总分',
  `comment`     TEXT         DEFAULT NULL COMMENT '教师评语',
  `teacher_id`  INT UNSIGNED DEFAULT NULL,
  `created_at`  DATETIME     NOT NULL,
  `updated_at`  DATETIME     DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_grading` (`exam_id`,`student_id`,`question_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='人工评分';

-- ---------------------------------------------------------------------
-- 10. 行为日志
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `exam_id`    INT UNSIGNED DEFAULT NULL,
  `student_id` INT UNSIGNED DEFAULT NULL,
  `event`      VARCHAR(30)  NOT NULL COMMENT 'login/logout/start/blur/save/upload/submit/grade/force',
  `detail`     VARCHAR(255) DEFAULT NULL,
  `ip`         VARCHAR(45)  DEFAULT NULL,
  `created_at` DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_exam_student` (`exam_id`,`student_id`),
  KEY `idx_event` (`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='行为日志';

-- ---------------------------------------------------------------------
-- 11. 系统设置
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
  `k` VARCHAR(50)  NOT NULL,
  `v` TEXT         DEFAULT NULL,
  PRIMARY KEY (`k`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='系统设置';

-- ---------------------------------------------------------------------
-- 初始数据
-- 默认管理员：admin / Admin@123   教师：teacher / Teacher@123
-- ★ 首次登录后请立刻改密码
-- INSERT IGNORE：重复执行本脚本时，若账号/班级已存在则自动跳过，不会覆盖。
-- ---------------------------------------------------------------------
INSERT IGNORE INTO `settings` (`k`,`v`) VALUES
  ('site_name',        'Linux 服务器配置与管理 期末考试'),
  ('school_name',      '马鞍山职业技术学院'),
  ('blur_limit',       '5'),
  ('upload_max_mb',    '20'),
  ('upload_max_count', '6'),
  ('force_pwd_change', '1');

INSERT IGNORE INTO `classes` (`name`,`remark`,`created_at`) VALUES
  ('25计网1班','',NOW()),
  ('25计网2班','',NOW());

INSERT IGNORE INTO `users` (`username`,`name`,`password`,`role`,`class_id`,`status`,`must_change_pwd`,`created_at`) VALUES
  ('admin','系统管理员', '$2y$10$f3CbMpSPENtiRRmVRnnMSO9Q7T33AhsyV/BLWYevAlu/RkRRossw.','admin',NULL,1,1,NOW()),
  ('teacher','苏新',     '$2y$10$MABotRyjYEyArcgu.mSeguRNOqzhCEaemL5xgB/zXMB08JY/72bQS','teacher',NULL,1,1,NOW());

SET FOREIGN_KEY_CHECKS = 1;

-- 初始密码：admin / Admin@123　　teacher / Teacher@123
-- 上面的 password 字段已是实测可用的 bcrypt 哈希，导入后即可直接登录（首次登录会要求改密码）。
-- 如果换了 PHP 环境导致校验失败，用管理员登录后访问 install_check.php 点「重置初始密码」即可重新生成。

-- ---------------------------------------------------------------------
-- 强制重建（★ 危险：会清空所有数据，请谨慎）
-- 需要清空重来时，先备份：
--   mysqldump -u root -p linux_exam > backup_$(date +%Y%m%d).sql
-- 然后取消下面注释执行（或直接删库重建）：
-- ---------------------------------------------------------------------
-- DROP TABLE IF EXISTS `logs`;
-- DROP TABLE IF EXISTS `grading`;
-- DROP TABLE IF EXISTS `uploads`;
-- DROP TABLE IF EXISTS `answers`;
-- DROP TABLE IF EXISTS `exam_students`;
-- DROP TABLE IF EXISTS `exam_questions`;
-- DROP TABLE IF EXISTS `exams`;
-- DROP TABLE IF EXISTS `questions`;
-- DROP TABLE IF EXISTS `users`;
-- DROP TABLE IF EXISTS `classes`;
-- DROP TABLE IF EXISTS `settings`;