<?php
/**
 * 题库管理：列表 / 新增 / 编辑 / 删除
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
$u = require_login(array('admin'));

$act = get('act', 'list');
$id  = (int)get('id');

/* 删除 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'del') {
    csrf_check();
    $did = (int)post('id');
    $used = (int)db_val('SELECT COUNT(*) FROM exam_questions WHERE question_id = ?', array($did), 0);
    if ($used > 0) {
        flash('该题已被 ' . $used . ' 场考试引用，不能删除。可先到考试编辑里取消勾选。', 'err');
    } else {
        db_exec('DELETE FROM questions WHERE id = ?', array($did));
        flash('已删除', 'ok');
    }
    redirect('admin/questions.php');
}

/* 保存 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && post('do') === 'save') {
    csrf_check();
    $qid   = (int)post('id');
    $type  = post('type');
    if (!in_array($type, type_list(), true)) {
        $type = 'single';
    }
    $stem = trim(post('stem'));
    if ($stem === '') {
        flash('题干不能为空', 'err');
        redirect('admin/questions.php?act=' . ($qid ? 'edit&id=' . $qid : 'new'));
    }

    $options = null;
    $answer  = null;
    if ($type === 'single') {
        $options = json_encode(text_to_options(post('options_text')), JSON_UNESCAPED_UNICODE);
        $answer  = norm_choice(post('answer_text'));
    } elseif ($type === 'judge') {
        $answer = norm_judge(post('answer_judge'));
    }

    $rubric = null;
    if (is_subjective($type)) {
        $r = text_to_rubric(post('rubric_text'));
        $rubric = $r ? json_encode($r, JSON_UNESCAPED_UNICODE) : null;
    }
    $shots = null;
    if ($type === 'practical') {
        $s = text_to_shots(post('shots_text'));
        $shots = $s ? json_encode($s, JSON_UNESCAPED_UNICODE) : null;
    }

    $data = array(
        'type'          => $type,
        'chapter'       => post('chapter'),
        'topic'         => post('topic'),
        'difficulty'    => max(1, min(3, (int)post('difficulty', 2))),
        'stem'          => $stem,
        'options'       => $options,
        'answer'        => $answer,
        'analysis'      => post('analysis'),
        'ref_answer'    => post('ref_answer'),
        'rubric'        => $rubric,
        'pract_steps'   => post('pract_steps'),
        'pract_shots'   => $shots,
        'default_score' => (float)post('default_score', 1),
        'updated_at'    => now(),
    );

    if ($qid) {
        db_exec('UPDATE questions SET type=?, chapter=?, topic=?, difficulty=?, stem=?, options=?, answer=?,
                        analysis=?, ref_answer=?, rubric=?, pract_steps=?, pract_shots=?, default_score=?, updated_at=?
                  WHERE id=?',
            array_merge(array_values($data), array($qid)));
        flash('已保存', 'ok');
    } else {
        $data['created_at'] = now();
        $qid = db_insert('INSERT INTO questions (type, chapter, topic, difficulty, stem, options, answer,
                          analysis, ref_answer, rubric, pract_steps, pract_shots, default_score, created_at, updated_at)
                          VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            array_values($data));
        flash('已新增题目', 'ok');
    }
    redirect('admin/questions.php?act=edit&id=' . $qid);
}

/* 编辑/新增表单数据 */
$edit = null;
if ($act === 'edit' && $id) {
    $edit = db_one('SELECT * FROM questions WHERE id = ?', array($id));
    if (!$edit) {
        flash('题目不存在', 'err');
        redirect('admin/questions.php');
    }
}

/* 列表筛选 */
$ft = get('ft', '');
$kw = get('kw', '');
$where = '1=1';
$params = array();
if ($ft !== '' && in_array($ft, type_list(), true)) {
    $where .= ' AND type = ?';
    $params[] = $ft;
}
if ($kw !== '') {
    $where .= ' AND (stem LIKE ? OR topic LIKE ? OR chapter LIKE ?)';
    $params[] = '%' . $kw . '%';
    $params[] = '%' . $kw . '%';
    $params[] = '%' . $kw . '%';
}
$list = db_all("SELECT * FROM questions WHERE $where
                 ORDER BY FIELD(type,'single','judge','short','practical'), id ASC", $params);
$counts = array();
foreach (db_all('SELECT type, COUNT(*) c FROM questions GROUP BY type') as $r) {
    $counts[$r['type']] = (int)$r['c'];
}
$total = array_sum($counts);

$isForm = ($act === 'new' || ($act === 'edit' && $edit));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>题库管理</title>
<link rel="stylesheet" href="<?php echo h(base_url('assets/css/app.css')); ?>">
</head>
<body>
<div class="topbar">
  <div class="brand">题库管理<small>共 <?php echo $total; ?> 题</small></div>
  <div class="spacer"></div>
  <a href="index.php">返回后台</a>
  <a href="q_import.php">批量导入</a>
  <a href="<?php echo h(base_url('logout.php')); ?>">退出</a>
</div>

<div class="wrap">
  <?php $f = take_flash(); if ($f): ?>
    <div class="alert <?php echo h($f['type']); ?>"><?php echo h($f['msg']); ?></div>
  <?php endif; ?>

<?php if ($isForm): ?>
  <?php
    $v = function ($k, $def = '') use ($edit) { return $edit && isset($edit[$k]) ? $edit[$k] : $def; };
    $type = $v('type', 'single');
  ?>
  <div class="card">
    <h2><?php echo $edit ? '编辑题目 #' . (int)$edit['id'] : '新增题目'; ?></h2>
    <form method="post">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="do" value="save">
      <input type="hidden" name="id" value="<?php echo $edit ? (int)$edit['id'] : 0; ?>">

      <div class="flex gap16" style="flex-wrap:wrap">
        <div class="form-row" style="width:150px">
          <label class="lbl">题型</label>
          <select class="input" name="type" id="typeSel">
            <?php foreach (type_list() as $t): ?>
              <option value="<?php echo $t; ?>" <?php echo $type === $t ? 'selected' : ''; ?>><?php echo h(type_label($t)); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row" style="width:150px">
          <label class="lbl">章节 / 项目</label>
          <input class="input" name="chapter" value="<?php echo h($v('chapter')); ?>" placeholder="项目5">
        </div>
        <div class="form-row" style="width:170px">
          <label class="lbl">考点</label>
          <input class="input" name="topic" value="<?php echo h($v('topic')); ?>" placeholder="LVM">
        </div>
        <div class="form-row" style="width:110px">
          <label class="lbl">难度</label>
          <select class="input" name="difficulty">
            <?php foreach (array(1 => '易', 2 => '中', 3 => '难') as $k2 => $lb): ?>
              <option value="<?php echo $k2; ?>" <?php echo (int)$v('difficulty', 2) === $k2 ? 'selected' : ''; ?>><?php echo $lb; ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-row" style="width:120px">
          <label class="lbl">默认分值</label>
          <input class="input" type="number" step="0.5" name="default_score" value="<?php echo (float)$v('default_score', 1); ?>">
        </div>
      </div>

      <div class="form-row">
        <label class="lbl">题干</label>
        <textarea class="input" name="stem" rows="4" required><?php echo h($v('stem')); ?></textarea>
      </div>

      <div id="boxChoice">
        <div class="form-row">
          <label class="lbl">选项（每行一个，自动编号 A、B、C、D）</label>
          <textarea class="input" name="options_text" rows="5" placeholder="下列哪个命令用于查看磁盘分区表&#10;fdisk -l&#10;df -h&#10;du -sh&#10;mount"><?php echo h($v('options') ? options_to_text($v('options')) : ''); ?></textarea>
        </div>
        <div class="form-row">
          <label class="lbl">正确答案（如 B）</label>
          <input class="input" name="answer_text" value="<?php echo h($v('answer')); ?>" placeholder="B">
        </div>
      </div>

      <div id="boxJudge" style="display:none">
        <div class="form-row">
          <label class="lbl">正确答案</label>
          <select class="input" name="answer_judge" style="width:160px">
            <option value="T" <?php echo $v('answer') === 'T' ? 'selected' : ''; ?>>正确 √</option>
            <option value="F" <?php echo $v('answer') === 'F' ? 'selected' : ''; ?>>错误 ×</option>
          </select>
        </div>
      </div>

      <div class="form-row">
        <label class="lbl">解析（选填）</label>
        <textarea class="input" name="analysis" rows="2"><?php echo h($v('analysis')); ?></textarea>
      </div>

      <div id="boxSubjective">
        <div class="form-row">
          <label class="lbl">参考答案（简答/实操，阅卷时可见）</label>
          <textarea class="input" name="ref_answer" rows="4"><?php echo h($v('ref_answer')); ?></textarea>
        </div>
        <div class="form-row">
          <label class="lbl">评分点（每行一条：<code>要点 | 分值</code>）</label>
          <textarea class="input" name="rubric_text" rows="5" placeholder="能写出 pvcreate / vgcreate / lvcreate 三步 | 3&#10;能说明 xfs 格式化与挂载 | 2&#10;表述完整、有层次 | 1"><?php echo h($v('rubric') ? rubric_to_text($v('rubric')) : ''); ?></textarea>
        </div>
      </div>

      <div id="boxPractical" style="display:none">
        <div class="form-row">
          <label class="lbl">实操任务要求（贴给学生）</label>
          <textarea class="input" name="pract_steps" rows="6" placeholder="1. 新增一块 20G 空白磁盘，创建 LVM 逻辑卷 …&#10;2. 格式化为 xfs 并挂载到 /data …"><?php echo h($v('pract_steps')); ?></textarea>
        </div>
        <div class="form-row">
          <label class="lbl">需提交的截图 / 附件清单（每行一条，自动加 ①②③）</label>
          <textarea class="input" name="shots_text" rows="4" placeholder="lsblk 输出，能看到新增磁盘与逻辑卷&#10;pvs/vgs/lvs 三条命令的输出"><?php echo h($v('pract_shots') ? shots_to_text($v('pract_shots')) : ''); ?></textarea>
          <div class="hint">学生端可直接 Ctrl+V 粘贴截图，也可上传任意类型附件（单个大小与个数在「系统设置」里调）。</div>
        </div>
      </div>

      <button class="btn" type="submit">保存</button>
      <a class="btn ghost" href="questions.php">返回列表</a>
    </form>
  </div>

  <script>
  (function(){
    var sel = document.getElementById('typeSel');
    function sync(){
      var t = sel.value;
      document.getElementById('boxChoice').style.display = (t==='single')?'':'none';
      document.getElementById('boxJudge').style.display = (t==='judge')?'':'none';
      document.getElementById('boxSubjective').style.display = (t==='short'||t==='practical')?'':'none';
      document.getElementById('boxPractical').style.display = (t==='practical')?'':'none';
    }
    sel.addEventListener('change', sync);
    sync();
  })();
  </script>

<?php else: ?>

  <div class="card tight flex aic gap10" style="flex-wrap:wrap">
    <a class="btn" href="questions.php?act=new">+ 新增题目</a>
    <a class="btn ghost" href="q_import.php">批量导入 CSV</a>
    <form class="inline" method="get" style="margin-left:auto">
      <select class="input sm" name="ft" onchange="this.form.submit()">
        <option value="">全部题型</option>
        <?php foreach (type_list() as $t): ?>
          <option value="<?php echo $t; ?>" <?php echo $ft === $t ? 'selected' : ''; ?>>
            <?php echo h(type_label($t)); ?>（<?php echo isset($counts[$t]) ? $counts[$t] : 0; ?>）
          </option>
        <?php endforeach; ?>
      </select>
      <input class="input sm" name="kw" value="<?php echo h($kw); ?>" placeholder="搜索题干/考点/章节">
      <button class="btn sm ghost" type="submit">搜索</button>
    </form>
  </div>

  <div class="card">
    <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th style="width:60px">#</th><th style="width:90px">题型</th>
          <th>题干</th><th style="width:90px">章节</th><th style="width:110px">考点</th>
          <th style="width:70px" class="center">分值</th>
          <th style="width:130px" class="center">操作</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$list): ?>
        <tr><td colspan="7" class="center muted">没有题目</td></tr>
      <?php endif; ?>
      <?php foreach ($list as $q): ?>
        <tr>
          <td class="muted"><?php echo (int)$q['id']; ?></td>
          <td><span class="badge blue"><?php echo h(type_label($q['type'])); ?></span></td>
          <td>
            <div class="small"><?php echo h(mb_substr(trim(preg_replace('/\s+/u', ' ', $q['stem'])), 0, 90)); ?></div>
            <?php if ($q['answer']): ?>
              <div class="small muted">答案：<strong><?php echo h($q['answer'] === 'T' ? '正确' : ($q['answer'] === 'F' ? '错误' : $q['answer'])); ?></strong></div>
            <?php endif; ?>
          </td>
          <td class="small"><?php echo h($q['chapter']); ?></td>
          <td class="small"><?php echo h($q['topic']); ?></td>
          <td class="center"><?php echo fmt_num($q['default_score']); ?></td>
          <td class="center nowrap">
            <a class="btn sm ghost" href="questions.php?act=edit&id=<?php echo (int)$q['id']; ?>">编辑</a>
            <form method="post" style="display:inline" onsubmit="return confirm('确定删除这道题？')">
              <?php echo csrf_field(); ?>
              <input type="hidden" name="do" value="del">
              <input type="hidden" name="id" value="<?php echo (int)$q['id']; ?>">
              <button class="btn sm danger" type="submit">删除</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

<?php endif; ?>
</div>
</body>
</html>
