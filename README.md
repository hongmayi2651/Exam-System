# Exam-System

Linux 服务器配置与管理 · 考试系统

按 OpenStack 考试系统的架构复刻的一门课考试系统。原生 PHP + MySQL，零外部依赖（无 Composer、无 CDN），机房内网可直接部署。

## 与 OpenStack 版的差异

| 项目 | OpenStack 版 | 本系统（Linux 版） |
|---|---|---|
| 数据库 | `os_exam` | `linux_exam` |
| 题型 | 单选 / **多选** / 判断 / 简答 / 实操 | 单选 / 判断 / 简答 / 实操（**去掉多选**） |
| 组卷 | 逐题勾选 | 逐题勾选 **＋ 整卷快捷组卷 ＋ 按题型批量选题（难度/章节筛选、随机抽 N 题、统一分值）** |
| 实操题附件 | 只能传图片，最多 4 张 5MB | **可 Ctrl+V 粘贴截图**，**可上传任意类型附件**，默认每题 6 个 20MB |
| 阅卷附件展示 | 只显示图片缩略图 | 图片显示缩略图（点开放大），其它类型显示文件条（点击下载） |
| 导出成绩 | 9 列固定 + 每题得分 | 增加**附件数**列，便于核对谁没传附件 |
| 题库 | 47 题（5 题型） | 由题库 CSV 导入（题型 4 种），覆盖网络管理、进程管理、文件查找、打包压缩、SSH、DHCP、DNS、Apache、FTP、Samba、防火墙 |
| 会话名 | `OSEXAMSID` | `LNXEXAMSID` |

## 快速开始

```bash
# 1. 建库
mysql -u root -p < sql/install.sql

# 2. 准备配置文件
cp config/config.example.php config/config.php
#    然后编辑 config/config.php，填入数据库账号密码

# 3. 浏览器访问 install_check.php 自检
#    （clone 下来没建 config.php 也能跑，只会报数据库连接失败这一项）

# 4. 登录 admin / Admin@123（首次登录强制改密）

# 5. 后台 → 导入题库 → 上传你自己的题库 CSV
```

详细部署见 `docs/01-部署说明.md`，教师操作见 `docs/02-教师使用手册.md`，学生端说明见 `docs/03-学生须知.md`。

## 目录

```
config/      config.example.php（模板，入库）+ config.php（真实配置，不入库）
lib/         核心库：config / bootstrap / db / auth / helpers / content / grading
api/         答题页接口：save / upload / delete_upload / heartbeat / submit / log
student/     学生端：我的考试 / 答题 / 交卷结果
teacher/     教师端：考试看板 / 阅卷工作台 / 导出 / 打包答卷
admin/       管理端：考试与组卷 / 题库 / 导入 / 用户与班级 / 设置
assets/      自写 CSS 与 JS
uploads/     实操题附件（exam/student/question 三层目录，只保留 .htaccess）
sql/         install.sql（11 张表，幂等，可重复执行）
docs/        部署说明 / 教师手册 / 学生须知
```

## 设计要点

- **计时权威在服务端**：以 `start_at + duration` 与 `exams.close_at` 取小者为准，前端每 20 秒心跳校时，超时由服务端收卷，改前端时间无效。
- **答案防抖自动保存**：选项即存，文本 0.7 秒防抖，失焦立即存；断线重连继续答，计时不重置。
- **交卷后学生看不到试卷也看不到成绩**：交卷页只有「交卷成功」，学生列表不显示得分列。成绩由教师统一公布。
- **主观题必须全部评完才转「已阅卷」**：只看 `grading` 记录条数，避免漏评。
- **附件安全**：文件名随机化；扩展名黑名单（php/phtml/htm/svg/xml 等）在接口层拒绝；`uploads/.htaccess` 关闭 PHP 引擎与 CGI；仅实操题可上传，且校验题目属于本人试卷。
- **打包答卷内存保护**：`teacher/pack_papers.php` 对超过 `embed_image_max_kb` 的图片不再 base64 内嵌，改为原样拷进 ZIP 的 `images/` 子目录、HTML 用相对路径引用，避免大批量附件把 PHP 内存撑爆。
- **组卷批量选择纯前端完成**：题库整体渲染进表单，抽题/筛选/统一分值都在浏览器里做，无需往返服务器，改完点保存一次性提交。

## 安全要点（发布到公开仓库前请确认）

- `config/config.php` 已被 `.gitignore` 排除，只提交 `config/config.example.php`。
- 站点根 `.htaccess` 与 `config/ lib/ sql/ uploads/` 下的 `.htaccess` 已配好：禁目录列举、禁下载 sql/md/txt/log、禁直接访问源码目录、uploads 关闭 PHP 引擎。
- `install_check.php` 的「重置初始密码」需要管理员已登录并带 CSRF token 才生效，未授权访问无效。
- `sql/install.sql` 已改为幂等版本（`CREATE TABLE IF NOT EXISTS` + `INSERT IGNORE`），重复执行不会删数据。
- 进行中的考试不能删除；考试「延长」只在「考试中」状态可用，已结束的不会被重新激活。

## 自测

部署后可用 `install_check.php` 做环境自检。功能覆盖范围：环境自检、登录与强制改密、CSV 导入、按题型批量组卷与排号、发布开考、客观题自动判分、粘贴截图与任意附件上传（含拒绝 .php）、附件删除与重传、心跳校时、超时自动收卷、重复交卷幂等、阅卷超限截断、成绩汇总、CSV/XLS 导出、越权访问与 CSRF 防护。

> **注意：以上脚本会写入测试数据，只能在测试库上跑，不要在生产库上执行。**