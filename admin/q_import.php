<?php
/**
 * 题库批量导入（CSV，16 列）
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
$u = require_login(array('admin'));

$COLS = array('type', 'chapter', 'topic', 'difficulty', 'stem', 'optA', 'optB', 'optC', 'optD',
              'answer', 'analysis', 'ref_answer', 'rubric', 'pract_steps', 'pract_shots', 'score');

$result = null;
$error  = '';

/** 把 CSV 文本解析成二维数组 */
function parse_csv_text($text)
{
    // 去 BOM
    $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $rows = array();
    foreach ($lines as $line) {
        if (trim($line) === '') continue;
        $rows[] = csv_split_line($line);
    }
    return $rows;
}

/** 多字节安全地按逗号拆分一行，支持双引号包裹与 "" 转义。
 *  PHP 自带的 str_getcsv/fgetcsv 对多字节 UTF-8 内容存在解析缺陷（会把中文行错误合并字段），
 *  这里仅把 ASCII 的 , 与 " 作为分隔符/引号处理，不影响任何 UTF-8 多字节字符。 */
function csv_split_line($line)
{
    $fields = array();
    $cur = '';
    $inQuotes = false;
    $len = strlen($line);
    for ($i = 0; $i < $len; $i++) {
        $ch = $line[$i];
        if ($inQuotes) {
            if ($ch === '"') {
                if ($i + 1 < $len && $line[$i + 1] === '"') {
                    $cur .= '"';
                    $i++;
                } else {
                    $inQuotes = false;
                }
            } else {
                $cur .= $ch;
            }
        } else {
            if ($ch === '"') {
                $inQuotes = true;
            } elseif ($ch === ',') {
                $fields[] = $cur;
                $cur = '';
            } else {
                $cur .= $ch;
            }
        }
    }
    $fields[] = $cur;
    return $fields;
}

/** 编码识别并转 UTF-8 */
function to_utf8($s)
{
    if (!mb_check_encoding($s, 'UTF-8')) {
        $enc = mb_detect_encoding($s, array('GB18030', 'GBK', 'BIG5', 'UTF-8'), true);
        if ($enc) {
            $s = mb_convert_encoding($s, 'UTF-8', $enc);
        }
    }
    return $s;
}

/* 下载模板 */
if (get('tpl') === '1') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="题库导入模板.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, $COLS);
    fputcsv($out, array('single', '项目2', 'fdisk', '1',
        '查看当前系统磁盘分区表的命令是（　）。', 'df -h', 'fdisk -l', 'du -sh', 'mount',
        'B', 'fdisk -l 列出磁盘与分区信息；df 看的是文件系统使用率。', '', '', '', '', '1'));
    fputcsv($out, array('judge', '项目4', '权限', '1',
        '目录 /data 的权限为 755，普通用户可以在其中创建文件。', '', '', '', '',
        'F', '目录需要写权限（w）才能创建文件，755 对其他人只有读和执行权限。', '', '', '', '', '1'));
    fputcsv($out, array('short', '项目5', 'LVM', '2',
        '简述创建 LVM 逻辑卷的完整步骤。', '', '', '', '',
        '', '', "① 用 pvcreate 建立物理卷\n② 用 vgcreate 建立卷组\n③ 用 lvcreate 建立逻辑卷\n④ mkfs 格式化后 mount 挂载",
        "写出 pvcreate / vgcreate / lvcreate 三步 | 3；写出格式化与挂载 | 2；顺序正确 | 1",
        '', '', '6'));
    fputcsv($out, array('practical', '项目5', 'LVM', '2',
        '在新增的 20G 空白磁盘上创建 LVM 逻辑卷并挂载。', '', '', '', '',
        '', '', "1. pvcreate /dev/sdb\n2. vgcreate vgdata /dev/sdb\n3. lvcreate -L 10G -n lvdata vgdata\n4. mkfs.xfs /dev/vgdata/lvdata\n5. mount 并写入 /etc/fstab",
        "创建 PV/VG/LV 成功，容量正确 | 4；格式化为 xfs | 2；挂载到 /data 并 df 可见 | 3；写入 fstab 且 mount -a 无报错 | 3",
        "1. 在 VMware 给虚拟机新增一块 20G 磁盘，用 lsblk 确认设备名\n2. 依次创建 PV、VG、LV（卷组名 vgdata，逻辑卷 lvdata，10G）\n3. 格式化为 xfs，挂载到 /data\n4. 把挂载写入 /etc/fstab，执行 mount -a 验证不报错",
        "lsblk 输出；pvs/vgs/lvs 三条命令输出；df -hT 显示 /data；cat /etc/fstab 片段", '12'));
    fclose($out);
    exit;
}

/* 执行导入 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $text = '';
    if (!empty($_FILES['csv']['tmp_name']) && is_uploaded_file($_FILES['csv']['tmp_name'])) {
        $text = file_get_contents($_FILES['csv']['tmp_name']);
    } else {
        $text = post('csv_text');
    }
    if (trim($text) === '') {
        $error = '没有收到 CSV 内容';
    } else {
        $text = to_utf8($text);
        $rows = parse_csv_text($text);
        if (!$rows) {
            $error = 'CSV 解析失败';
        } else {
            // 判断首行是否为表头
            $head = $rows[0];
            $isHead = isset($head[0]) && in_array(strtolower(trim($head[0])), array('type', '题型'), true);
            if ($isHead) {
                array_shift($rows);
            }
            $okCnt = 0; $skip = array();
            $seq = 0;
            $typeMap = array(
                'single' => 'single', '单选' => 'single', '单选题' => 'single',
                'judge' => 'judge', '判断' => 'judge', '判断题' => 'judge',
                'short' => 'short', '简答' => 'short', '简答题' => 'short',
                'practical' => 'practical', '实操' => 'practical', '实操题' => 'practical',
            );
            foreach ($rows as $r) {
                $seq++;
                if (count($r) < 5) { $skip[] = "第{$seq}行列数不足"; continue; }
                $r = array_pad($r, 16, '');
                $g = function ($i) use ($r) { return trim((string)$r[$i]); };

                $tKey = mb_strtolower($g(0));
                $type = isset($typeMap[$tKey]) ? $typeMap[$tKey] : (isset($typeMap[$g(0)]) ? $typeMap[$g(0)] : '');
                if ($type === '') { $skip[] = "第{$seq}行题型无法识别：{$g(0)}"; continue; }
                if ($g(4) === '') { $skip[] = "第{$seq}行题干为空"; continue; }

                $options = null; $answer = null;
                if ($type === 'single') {
                    $opts = array();
                    for ($i = 5; $i <= 8; $i++) {
                        if ($g($i) !== '') {
                            $opts[] = array('k' => chr(65 + ($i - 5)), 't' => $g($i));
                        }
                    }
                    if (count($opts) < 2) { $skip[] = "第{$seq}行选项少于2个"; continue; }
                    $options = json_encode($opts, JSON_UNESCAPED_UNICODE);
                    $answer  = norm_choice($g(9));
                    if ($answer === '') { $skip[] = "第{$seq}行缺少答案"; continue; }
                } elseif ($type === 'judge') {
                    $answer = norm_judge($g(9));
                }

                // 分号分隔的多条目
                $normMulti = function ($s) {
                    $s = str_replace(array('；', "\r\n"), array("\n", "\n"), (string)$s);
                    return trim($s);
                };
                $rubric = null;
                if ($g(12) !== '') {
                    $rr = text_to_rubric($normMulti($g(12)));
                    $rubric = $rr ? json_encode($rr, JSON_UNESCAPED_UNICODE) : null;
                }
                $shots = null;
                if ($g(14) !== '') {
                    $ss = text_to_shots($normMulti($g(14)));
                    $shots = $ss ? json_encode($ss, JSON_UNESCAPED_UNICODE) : null;
                }

                $score = (float)$g(15);
                if ($score <= 0) { $score = 1; }

                db_insert('INSERT INTO questions (type, chapter, topic, difficulty, stem, options, answer,
                            analysis, ref_answer, rubric, pract_steps, pract_shots, default_score, created_at)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
                    array($type, $g(1), $g(2), max(1, min(3, (int)$g(3) ?: 2)), $g(4),
                          $options, $answer, $g(10), $g(11), $rubric,
                          $normMulti($g(13)), $shots, $score, now()));
                $okCnt++;
            }
            $result = array('ok' => $okCnt, 'skip' => $skip);
            log_event('admin', '导入题库 ' . $okCnt . ' 题');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>导入题库</title>
<link rel="stylesheet" href="<?php echo h(base_url('assets/css/app.css')); ?>">
</head>
<body>
<div class="topbar">
  <div class="brand">导入题库<small>CSV 批量导入</small></div>
  <div class="spacer"></div>
  <a href="questions.php">题库管理</a>
  <a href="index.php">返回后台</a>
</div>

<div class="wrap">
  <?php if ($error): ?><div class="alert err"><?php echo h($error); ?></div><?php endif; ?>

  <?php if ($result): ?>
    <div class="alert ok">成功导入 <strong><?php echo (int)$result['ok']; ?></strong> 道题。</div>
    <?php if ($result['skip']): ?>
      <div class="card">
        <h3>跳过 <?php echo count($result['skip']); ?> 行</h3>
        <div class="small muted" style="line-height:2">
          <?php foreach (array_slice($result['skip'], 0, 30) as $s): ?>
            <div><?php echo h($s); ?></div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
    <div class="card tight">
      <a class="btn" href="questions.php">去题库查看</a>
      <a class="btn ghost" href="q_import.php">继续导入</a>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>CSV 格式说明</h2>
    <div class="small" style="line-height:2">
      共 16 列，顺序固定：
      <code>type, chapter, topic, difficulty, stem, optA, optB, optC, optD, answer, analysis, ref_answer, rubric, pract_steps, pract_shots, score</code>
    </div>
    <ul class="small muted" style="line-height:2">
      <li><code>type</code>：single / judge / short / practical（也接受中文「单选」「判断」「简答」「实操」）</li>
      <li><code>difficulty</code>：1 易 / 2 中 / 3 难</li>
      <li><code>answer</code>：单选写 <code>B</code>；判断题写 <code>T</code>/<code>F</code>（也认「对」「错误」）</li>
      <li><code>rubric</code>：多条用 <code>；</code> 分隔，每条写成 <code>要点 | 分值</code></li>
      <li><code>pract_steps</code>、<code>pract_shots</code>：多条用 <code>；</code> 分隔，导入后自动换行</li>
      <li>字段内如果要换行，请用全角「；」代替，不要直接断行（CSV 逐行解析）</li>
      <li>文件编码 UTF-8 或 GBK 都可以，程序会自动识别</li>
    </ul>
    <a class="btn ghost" href="q_import.php?tpl=1">下载 CSV 模板</a>
  </div>

  <div class="card">
    <h2>上传文件</h2>
    <form method="post" enctype="multipart/form-data">
      <?php echo csrf_field(); ?>
      <div class="form-row">
        <input class="input" type="file" name="csv" accept=".csv,text/csv">
        <div class="hint">选择 CSV 文件；也可以先导出题库再编辑后回传。</div>
      </div>
      <div class="form-row">
        <label class="lbl">或者直接把 CSV 内容粘贴到这里</label>
        <textarea class="input" name="csv_text" rows="6" placeholder="type,chapter,topic,...&#10;single,项目2,fdisk,1,&quot;题干&quot;,A,B,C,D,B,,,,,,1"></textarea>
      </div>
      <button class="btn" type="submit">开始导入</button>
    </form>
  </div>

  <div class="card tight">
    <div class="small muted">重复导入会生成重复题目（系统不做去重）。如果导错了，可以到题库里按题型筛选后逐条删除。</div>
  </div>
</div>
</body>
</html>
