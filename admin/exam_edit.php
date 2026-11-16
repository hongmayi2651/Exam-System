<?php
/**
 * 新建 / 编辑考试 + 组卷（按题型批量选题）+ 分配考生
 */
require dirname(__DIR__) . '/lib/bootstrap.php';
$u = require_login(array('admin'));

$id   = (int)get('id');
$exam = $id ? get_exam($id) : null;

/* ---------------- 处理提交 ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = post('act');

    /* 保存基本信息 */
    if ($act === 'info') {
        $title    = post('title');
        $subject  = post('subject', 'Linux服务器配置与管理');
        $duration = max(10, (int)post('duration_min', 120));
        $total    = (float)post('total_score', 100);
        $notice   = post('notice');
        $plan     = post('plan_start');

        if ($title === '') {
            flash('考试名称不能为空', 'err');
            redirect('admin/exam_edit.php' . ($id ? '?id=' . $id : ''));
        }
        $planVal = $plan === '' ? null : str_replace('T', ' ', $plan) . (strlen($plan) === 16 ? ':00' : '');

        if ($id) {
            db_exec('UPDATE exams SET title=?, subject=?, duration_min=?, total_score=?, notice=?, plan_start=?, updated_at=? WHERE id=?',
                array($title, $subject, $duration, $total, $notice, $planVal, now(), $id));
            flash('已保存', 'ok');
            redirect('admin/exam_edit.php?id=' . $id);
        } else {
            $newId = db_insert('INSERT INTO exams (title, subject, duration_min, total_score, notice, plan_start, status, created_by, created_at)
                                VALUES (?,?,?,?,?,?,?,?,?)',
                array($title, $subject, $duration, $total, $notice, $planVal, 'draft', $u['id'], now()));
            flash('考试已创建，接下来选题和分配考生', 'ok');
            redirect('admin/exam_edit.php?id=' . $newId);
        }
    }

    /* 保存试卷题目 */
    if ($act === 'questions' && $exam) {
        $picked = isset($_POST['pick']) && is_array($_POST['pick']) ? $_POST['pick'] : array();
        $scores = isset($_POST['score']) && is_array($_POST['score']) ? $_POST['score'] : array();
        $order  = type_list();

        $rows = array();
        if ($picked) {
            $in = implode(',', array_map('intval', array_keys($picked)));
            $qs = db_all("SELECT id, type FROM questions WHERE id IN ($in)");
            $typeMap = array();
            foreach ($qs as $q) {
                $typeMap[(int)$q['id']] = $q['type'];
            }
            foreach ($typeMap as $qid => $type) {
                $sc = isset($scores[$qid]) ? (float)$scores[$qid] : 1;
                if ($sc <= 0) $sc = 1;
                $rows[] = array($qid, $type, $sc);
            }
            usort($rows, function ($a, $b) use ($order) {
                $ia = array_search($a[1], $order, true);
                $ib = array_search($b[1], $order, true);
                if ($ia === $ib) return $a[0] - $b[0];
                return $ia - $ib;
            });
        }

        db_exec('DELETE FROM exam_questions WHERE exam_id = ?', array($id));
        $seq = 1;
        foreach ($rows as $r) {
            db_insert('INSERT INTO exam_questions (exam_id, question_id, section, seq, score) VALUES (?,?,?,?,?)',
                array($id, $r[0], $r[1], $seq, $r[2]));
            $seq++;
        }
        $sum = 0;
        foreach ($rows as $r) { $sum += $r[2]; }
        flash('试卷已保存：' . count($rows) . ' 题，合计 ' . fmt_num($sum) . ' 分', 'ok');
        redirect('admin/exam_edit.php?id=' . $id);
    }

    /* 分配考生 */
    if ($act === 'students' && $exam) {
        $classIds = isset($_POST['class_ids']) && is_array($_POST['class_ids']) ? $_POST['class_ids'] : array();
        $classIds = array_map('intval', $classIds);
        $mode = post('mode', 'append');
        if ($mode === 'replace') {
            db_exec('DELETE FROM exam_students WHERE exam_id = ?', array($id));
        }
        $n = 0;
        if ($classIds) {
            $in = implode(',', $classIds);
            $studs = db_all("SELECT id FROM users WHERE role='student' AND status=1 AND class_id IN ($in)");
            foreach ($studs as $s) {
                $exist = db_val('SELECT id FROM exam_students WHERE exam_id=? AND student_id=?', array($id, (int)$s['id']));
                if (!$exist) {
                    db_insert('INSERT INTO exam_students (exam_id, student_id, status) VALUES (?,?,?)',
                        array($id, (int)$s['id'], 'assigned'));
                    $n++;
                }
            }
        }
        // 也支持按学号逐条加
        $extra = post('extra_ids');
        if ($extra !== '') {
            foreach (preg_split('/[\s,，]+/u', $extra) as $no) {
                $no = trim($no);
                if ($no === '') continue;
                $s = db_one("SELECT id FROM users WHERE username=? AND role='student'", array($no));
                if (!$s) continue;
                $exist = db_val('SELECT id FROM exam_students WHERE exam_id=? AND student_id=?', array($id, (int)$s['id']));
                if (!$exist) {
                    db_insert('INSERT INTO exam_students (exam_id, student_id, status) VALUES (?,?,?)',
                        array($id, (int)$s['id'], 'assigned'));
                    $n++;
                }
            }
        }
        flash('已添加 ' . $n . ' 名考生', 'ok');
        redirect('admin/exam_edit.php?id=' . $id);
    }

    /* 发布 / 取消发布 */
    if ($act === 'publish' && $exam) {
        $qc = (int)db_val('SELECT COUNT(*) FROM exam_questions WHERE exam_id=?', array($id), 0);
        if ($qc === 0) {
            flash('试卷还没有题目，不能发布', 'err');
        } else {
            db_exec("UPDATE exams SET status='ready', updated_at=? WHERE id=? AND status='draft'", array(now(), $id));
            flash('已发布，可以到看板点「开考」了', 'ok');
        }
        redirect('admin/exam_edit.php?id=' . $id);
    }
    if ($act === 'unpublish' && $exam) {
        db_exec("UPDATE exams SET status='draft', updated_at=? WHERE id=? AND status='ready'", array(now(), $id));
        flash('已撤回为未发布', 'ok');
        redirect('admin/exam_edit.php?id=' . $id);
    }
}

/* ---------------- 渲染 ---------------- */
$exam = $id ? get_exam($id) : null;
$classes = db_all('SELECT c.*, (SELECT COUNT(*) FROM users u WHERE u.class_id=c.id AND u.role="student") AS cnt
                     FROM classes c ORDER BY c.id');

$bank = array();
$chapters = array();   // 每个题型下出现过的章节
$difficulties = array();
if ($exam) {
    foreach (type_list() as $t) {
        $bank[$t] = db_all('SELECT id, type, chapter, topic, difficulty, stem, default_score
                              FROM questions WHERE type = ? ORDER BY id ASC', array($t));
        $chapters[$t] = array();
        foreach ($bank[$t] as $q) {
            $ch = trim((string)$q['chapter']);
            if ($ch !== '') { $chapters[$t][$ch] = true; }
        }
        $chapters[$t] = array_keys($chapters[$t]);
        sort($chapters[$t]);
    }
}

$picked = array();
$pickedScore = array();
if ($exam) {
    foreach (db_all('SELECT question_id, score FROM exam_questions WHERE exam_id = ?', array($id)) as $r) {
        $picked[(int)$r['question_id']] = true;
        $pickedScore[(int)$r['question_id']] = $r['score'];
    }
}
$planVal = $exam && $exam['plan_start'] ? date('Y-m-d\TH:i', strtotime($exam['plan_start'])) : '';
$curSum = 0;
foreach ($pickedScore as $sc) { $curSum += (float)$sc; }
$statusMap = array('draft' => '未发布', 'ready' => '待开考', 'running' => '考试中', 'finished' => '已结束');
$dMap = array(1 => '易', 2 => '中', 3 => '难');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo $exam ? '编辑考试' : '新建考试'; ?></title>
<link rel="stylesheet" href="<?php echo h(base_url('assets/css/app.css')); ?>">
</head>
<body>
<div class="topbar">
  <div class="brand"><?php echo $exam ? '编辑考试' : '新建考试'; ?><small><?php echo h(setting('site_name')); ?></small></div>
  <div class="spacer"></div>
  <a href="index.php">返回后台</a>
  <a href="<?php echo h(base_url('logout.php')); ?>">退出</a>
</div>

<div class="wrap">
  <?php $fl = take_flash(); if ($fl): ?>
    <div class="alert <?php echo h($fl['type']); ?>"><?php echo h($fl['msg']); ?></div>
  <?php endif; ?>

  <!-- 基本信息 -->
  <div class="card">
    <h2>基本信息</h2>
    <form method="post">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="act" value="info">
      <div class="flex gap16" style="flex-wrap:wrap">
        <div class="form-row flex1" style="min-width:280px">
          <label class="lbl">考试名称</label>
          <input class="input" name="title" required
                 value="<?php echo h($exam ? $exam['title'] : 'Linux 服务器配置与管理 期末考试'); ?>">
        </div>
        <div class="form-row" style="width:210px">
          <label class="lbl">科目</label>
          <input class="input" name="subject" value="<?php echo h($exam ? $exam['subject'] : 'Linux服务器配置与管理'); ?>">
        </div>
        <div class="form-row" style="width:130px">
          <label class="lbl">时长（分钟）</label>
          <input class="input" type="number" name="duration_min" value="<?php echo (int)($exam ? $exam['duration_min'] : 120); ?>">
        </div>
        <div class="form-row" style="width:130px">
          <label class="lbl">卷面总分</label>
          <input class="input" type="number" step="0.5" name="total_score" value="<?php echo (float)($exam ? $exam['total_score'] : 100); ?>">
        </div>
        <div class="form-row" style="width:200px">
          <label class="lbl">计划开考（提示用）</label>
          <input class="input" type="datetime-local" name="plan_start" value="<?php echo h($planVal); ?>">
        </div>
      </div>
      <div class="form-row">
        <label class="lbl">考前须知（学生答题页顶部显示）</label>
        <textarea class="input" name="notice" rows="3"
                  placeholder="例如：① 实操题在本人虚拟机 CentOS 8 中完成；② 截图需包含命令与返回结果；③ 截图可直接粘贴，也可上传任意类型附件；④ 交卷后不可修改。"><?php echo h($exam ? $exam['notice'] : ''); ?></textarea>
      </div>
      <button class="btn" type="submit"><?php echo $exam ? '保存基本信息' : '创建考试'; ?></button>
      <?php if ($exam): ?>
        <span class="small muted" style="margin-left:12px">
          当前状态：<span class="badge <?php echo $exam['status'] === 'draft' ? 'gray' : ($exam['status'] === 'running' ? 'green' : 'orange'); ?>">
            <?php echo h(isset($statusMap[$exam['status']]) ? $statusMap[$exam['status']] : $exam['status']); ?>
          </span>
        </span>
      <?php endif; ?>
    </form>
  </div>

  <?php if ($exam): ?>

  <!-- ============ 组卷 ============ -->
  <div class="card">
    <h2>试卷题目<span class="sub">可直接按题型批量选题，再逐题微调；保存后按 单选→判断→简答→实操 自动排号</span></h2>

    <div class="alert <?php echo abs($curSum - (float)$exam['total_score']) < 0.01 ? 'ok' : 'warn'; ?>">
      当前已选 <strong><?php echo count($picked); ?></strong> 题，合计
      <strong><?php echo fmt_num($curSum); ?></strong> 分；卷面总分设定为 <?php echo fmt_num($exam['total_score']); ?> 分。
    </div>

    <!-- 整卷快捷组卷 -->
    <div class="card tight" style="background:#f2f6fd;border:1px solid var(--blue-line);margin-bottom:16px">
      <div class="flex aic gap10" style="flex-wrap:wrap">
        <strong style="color:#0e3f8c">整卷快捷组卷</strong>
        <span class="small muted">填好各题型题数与分值，一键从题库随机抽取（会替换当前整卷选择题）</span>
      </div>
      <div class="flex aic gap10 mt10" style="flex-wrap:wrap">
        <?php foreach (type_list() as $t): ?>
          <div class="grp" style="display:flex;align-items:center;gap:5px">
            <label class="small" style="color:#5b6472"><?php echo h(type_label($t)); ?></label>
            <input type="number" min="0" class="qb-num" data-type="<?php echo $t; ?>" value="0"
                   style="width:64px;padding:4px 7px;font-size:12.5px;border:1px solid #d5dce8;border-radius:5px;font-family:inherit"
                   title="抽几题">
            <span class="small muted">题 ×</span>
            <input type="number" min="0.5" step="0.5" class="qb-score" data-type="<?php echo $t; ?>" value="1"
                   style="width:64px;padding:4px 7px;font-size:12.5px;border:1px solid #d5dce8;border-radius:5px;font-family:inherit"
                   title="每题分值">
            <span class="small muted">分</span>
          </div>
        <?php endforeach; ?>
        <button type="button" class="btn" id="btnQuickPick">一键随机抽题</button>
        <button type="button" class="btn ghost" id="btnQuickClear">清空整卷</button>
        <span class="small muted">建议 20×1 + 10×1 + 4×5 + 5×10 = 100 分</span>
      </div>
    </div>

    <form method="post" id="paperForm">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="act" value="questions">

      <?php foreach (type_list() as $t):
          $list = $bank[$t];
          $selCnt = 0;
          foreach ($list as $q) { if (isset($picked[(int)$q['id']])) $selCnt++; }
      ?>
        <details data-type="<?php echo $t; ?>" <?php echo $t === 'single' ? 'open' : ''; ?> style="margin-bottom:14px">
          <summary style="cursor:pointer;font-weight:600;color:#0e3f8c;padding:6px 0">
            <?php echo h(type_label($t)); ?>（题库 <?php echo count($list); ?> 题，已选
            <span class="sel-cnt" data-type="<?php echo $t; ?>"><?php echo $selCnt; ?></span>）
          </summary>

          <!-- 按题型批量选择 -->
          <div class="batchbar">
            <div class="grp">
              <label>数量</label>
              <input type="number" min="0" class="n-input" data-type="<?php echo $t; ?>" value="0">
            </div>
            <div class="grp">
              <label>难度</label>
              <select class="d-sel" data-type="<?php echo $t; ?>">
                <option value="0">全部</option>
                <option value="1">易</option>
                <option value="2">中</option>
                <option value="3">难</option>
              </select>
            </div>
            <div class="grp">
              <label>章节</label>
              <select class="c-sel" data-type="<?php echo $t; ?>">
                <option value="">全部</option>
                <?php foreach ($chapters[$t] as $ch): ?>
                  <option value="<?php echo h($ch); ?>"><?php echo h($ch); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="button" class="btn sm pick-random" data-type="<?php echo $t; ?>">随机抽 N 题</button>
            <button type="button" class="btn sm ghost pick-first" data-type="<?php echo $t; ?>">顺序取前 N 题</button>
            <span class="sep"></span>
            <button type="button" class="btn sm ghost pick-all" data-type="<?php echo $t; ?>">全选</button>
            <button type="button" class="btn sm ghost pick-none" data-type="<?php echo $t; ?>">清空本类</button>
            <span class="sep"></span>
            <div class="grp">
              <label>统一分值</label>
              <input type="number" min="0.5" step="0.5" class="s-input" data-type="<?php echo $t; ?>" value="1">
            </div>
            <button type="button" class="btn sm ghost apply-score" data-type="<?php echo $t; ?>">套用到已选</button>
            <span class="cnt" data-type="<?php echo $t; ?>">当前匹配 <b>0</b> 题</span>
          </div>

          <div class="table-wrap">
          <table class="table">
            <thead><tr>
              <th style="width:40px" class="center">选</th>
              <th>题干</th>
              <th style="width:80px" class="center">难度</th>
              <th style="width:90px" class="center">分值</th>
            </tr></thead>
            <tbody>
            <?php if (!$list): ?>
              <tr><td colspan="4" class="muted center">题库里没有<?php echo h(type_label($t)); ?>，请先到题库管理添加或导入</td></tr>
            <?php endif; ?>
            <?php foreach ($list as $q):
                $qid = (int)$q['id'];
                $isP = isset($picked[$qid]);
                $sc  = $isP ? $pickedScore[$qid] : ($q['default_score'] > 0 ? $q['default_score'] : 1);
                $st  = mb_substr(trim(preg_replace('/\s+/u', ' ', $q['stem'])), 0, 70);
            ?>
              <tr data-qid="<?php echo $qid; ?>" data-diff="<?php echo (int)$q['difficulty']; ?>"
                  data-chapter="<?php echo h($q['chapter']); ?>">
                <td class="center">
                  <input type="checkbox" class="cb" name="pick[<?php echo $qid; ?>]" value="1" <?php echo $isP ? 'checked' : ''; ?>>
                </td>
                <td>
                  <div class="small"><?php echo h($st); ?></div>
                  <div class="small muted">#<?php echo $qid; ?> · <?php echo h($q['topic']); ?> · <?php echo h($q['chapter']); ?></div>
                </td>
                <td class="center small"><?php echo h(isset($dMap[(int)$q['difficulty']]) ? $dMap[(int)$q['difficulty']] : '中'); ?></td>
                <td class="center">
                  <input class="input sm sc-input" type="number" step="0.5" min="0.5"
                         style="width:70px;text-align:center"
                         name="score[<?php echo $qid; ?>]" value="<?php echo fmt_num($sc); ?>">
                </td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          </div>
        </details>
      <?php endforeach; ?>

      <div class="summary-bar" id="summaryBar">
        <div class="grow">
          已选 <b id="sumCnt">0</b> 题　合计 <b id="sumScore">0</b> 分　
          卷面 <b><?php echo fmt_num($exam['total_score']); ?></b> 分　
          <span id="sumDiff" class="small"></span>
        </div>
        <button class="btn" type="submit">保存试卷</button>
        <span class="small muted">勾选状态与分值改完必须点保存才生效</span>
      </div>
    </form>
  </div>

  <!-- 分配考生 -->
  <div class="card">
    <h2>分配考生<span class="sub">当前 <?php echo (int)db_val('SELECT COUNT(*) FROM exam_students WHERE exam_id=?', array($id), 0); ?> 人</span></h2>
    <form method="post">
      <?php echo csrf_field(); ?>
      <input type="hidden" name="act" value="students">
      <div class="form-row">
        <label class="lbl">按班级添加</label>
        <div class="flex gap10" style="flex-wrap:wrap">
          <?php foreach ($classes as $c): ?>
            <label class="opt" style="padding:7px 14px;margin:0">
              <input type="checkbox" name="class_ids[]" value="<?php echo (int)$c['id']; ?>">
              <span><?php echo h($c['name']); ?>（<?php echo (int)$c['cnt']; ?> 人）</span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="form-row">
        <label class="lbl">或按学号逐个添加（逗号或换行分隔）</label>
        <textarea class="input" name="extra_ids" rows="2" placeholder="2510101, 2510102"></textarea>
      </div>
      <div class="inline">
        <label><input type="radio" name="mode" value="append" checked> 追加</label>
        <label><input type="radio" name="mode" value="replace"> 清空后重建</label>
        <button class="btn" type="submit" style="margin-left:10px">保存考生</button>
      </div>
    </form>

    <?php
      $assignList = db_all('SELECT u.username, u.name FROM exam_students es JOIN users u ON u.id=es.student_id
                             WHERE es.exam_id=? ORDER BY u.username', array($id));
      if ($assignList):
    ?>
      <details style="margin-top:14px">
        <summary style="cursor:pointer;font-size:13px;color:#1e4fa8">查看已分配名单（<?php echo count($assignList); ?> 人）</summary>
        <div class="small muted" style="margin-top:8px;line-height:2">
          <?php foreach ($assignList as $a): ?>
            <span class="badge gray" style="margin:2px 4px 2px 0"><?php echo h($a['username'] . ' ' . $a['name']); ?></span>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
  </div>

  <!-- 发布 -->
  <div class="card">
    <h2>发布与开考</h2>
    <div class="inline">
      <?php if ($exam['status'] === 'draft'): ?>
        <form method="post" style="display:inline">
          <?php echo csrf_field(); ?><input type="hidden" name="act" value="publish">
          <button class="btn">发布试卷</button>
        </form>
        <span class="small muted">发布后才能到看板点「开考」。发布后仍可撤回修改。</span>
      <?php elseif ($exam['status'] === 'ready'): ?>
        <form method="post" style="display:inline">
          <?php echo csrf_field(); ?><input type="hidden" name="act" value="unpublish">
          <button class="btn ghost">撤回为未发布</button>
        </form>
        <a class="btn" href="<?php echo h(base_url('teacher/index.php?exam_id=' . $id)); ?>">去考试看板开考</a>
      <?php else: ?>
        <a class="btn" href="<?php echo h(base_url('teacher/index.php?exam_id=' . $id)); ?>">去考试看板</a>
      <?php endif; ?>
    </div>
    <?php if ($exam['status'] === 'running'): ?>
      <div class="alert warn mt10">考试进行中。修改题目不会影响已开始的考生，但可能造成卷面不一致，建议不要在中途改。</div>
    <?php endif; ?>
  </div>

  <?php endif; ?>
</div>

<?php if ($exam): ?>
<script>
(function () {
  'use strict';
  var TYPES = <?php echo json_encode(type_list()); ?>;
  var TOTAL = <?php echo (float)$exam['total_score']; ?>;

  function box(type) { return document.querySelector('details[data-type="' + type + '"]'); }
  function rows(type) {
    var b = box(type);
    if (!b) return [];
    return Array.prototype.slice.call(b.querySelectorAll('tbody tr[data-qid]'));
  }
  function cntEl(type) { return document.querySelector('.sel-cnt[data-type="' + type + '"]'); }
  function matchEl(type) { return document.querySelector('.cnt[data-type="' + type + '"] b'); }

  /* 只统计当前筛选条件命中的题，让"数量"有参照 */
  function filterRows(type) {
    var b = box(type);
    if (!b) return [];
    var d = b.querySelector('.d-sel').value;
    var c = b.querySelector('.c-sel').value;
    return rows(type).filter(function (tr) {
      if (d !== '0' && tr.getAttribute('data-diff') !== d) return false;
      if (c !== '' && tr.getAttribute('data-chapter') !== c) return false;
      return true;
    });
  }

  function refreshMatch(type) {
    var m = matchEl(type);
    if (m) m.textContent = filterRows(type).length;
  }

  function refreshType(type) {
    var list = rows(type);
    var n = 0;
    list.forEach(function (tr) { if (tr.querySelector('.cb').checked) n++; });
    var e = cntEl(type);
    if (e) e.textContent = n;
    refreshMatch(type);
  }

  function refreshAll() {
    var allRows = [];
    TYPES.forEach(function (t) { refreshType(t); allRows = allRows.concat(rows(t)); });
    var n = 0, s = 0;
    allRows.forEach(function (tr) {
      if (tr.querySelector('.cb').checked) {
        n++;
        var v = parseFloat(tr.querySelector('.sc-input').value);
        if (!isNaN(v)) s += v;
      }
    });
    s = Math.round(s * 10) / 10;
    document.getElementById('sumCnt').textContent = n;
    document.getElementById('sumScore').textContent = (Math.round(s * 10) / 10).toString();
    var d = Math.round((s - TOTAL) * 10) / 10;
    var diff = document.getElementById('sumDiff');
    if (Math.abs(d) < 0.01) {
      diff.innerHTML = '<span class="badge green">与卷面总分一致</span>';
    } else if (d > 0) {
      diff.innerHTML = '<span class="badge red">超出 ' + d + ' 分</span>';
    } else {
      diff.innerHTML = '<span class="badge orange">还差 ' + Math.abs(d) + ' 分</span>';
    }
  }

  /* ------- 单类批量操作 ------- */
  function setChecked(type, trs) {
    rows(type).forEach(function (tr) { tr.querySelector('.cb').checked = false; });
    trs.forEach(function (tr) { tr.querySelector('.cb').checked = true; });
    refreshAll();
  }

  function shuffle(a) {
    var arr = a.slice();
    for (var i = arr.length - 1; i > 0; i--) {
      var j = Math.floor(Math.random() * (i + 1));
      var t = arr[i]; arr[i] = arr[j]; arr[j] = t;
    }
    return arr;
  }

  function pickN(type, n, mode) {
    var pool = filterRows(type);
    if (!pool.length) { alert('当前筛选条件下没有题目，请放宽难度或章节。'); return; }
    n = parseInt(n, 10) || 0;
    if (n <= 0) { alert('请先填「数量」。'); return; }
    if (n > pool.length) {
      n = pool.length;
      alert('当前筛选条件只有 ' + pool.length + ' 题，已全选。');
    }
    var chosen = mode === 'random' ? shuffle(pool).slice(0, n) : pool.slice(0, n);
    setChecked(type, chosen);
  }

  TYPES.forEach(function (t) {
    var b = box(t);
    if (!b) return;

    b.querySelector('.pick-random').addEventListener('click', function () {
      pickN(t, b.querySelector('.n-input').value, 'random');
    });
    b.querySelector('.pick-first').addEventListener('click', function () {
      pickN(t, b.querySelector('.n-input').value, 'first');
    });
    b.querySelector('.pick-all').addEventListener('click', function () {
      setChecked(t, filterRows(t));
    });
    b.querySelector('.pick-none').addEventListener('click', function () {
      setChecked(t, []);
    });
    b.querySelector('.apply-score').addEventListener('click', function () {
      var v = parseFloat(b.querySelector('.s-input').value);
      if (isNaN(v) || v <= 0) { alert('请填一个大于 0 的分值。'); return; }
      var n = 0;
      rows(t).forEach(function (tr) {
        if (tr.querySelector('.cb').checked) { tr.querySelector('.sc-input').value = v; n++; }
      });
      refreshAll();
      if (n === 0) alert('本类还没有勾选题目。');
    });

    b.querySelector('.d-sel').addEventListener('change', function () { refreshMatch(t); });
    b.querySelector('.c-sel').addEventListener('change', function () { refreshMatch(t); });

    b.addEventListener('change', function (e) {
      if (e.target.classList.contains('cb') || e.target.classList.contains('sc-input')) {
        refreshAll();
      }
    });
    b.addEventListener('input', function (e) {
      if (e.target.classList.contains('sc-input')) { refreshAll(); }
    });
  });

  /* ------- 整卷快捷组卷 ------- */
  var btnQuick = document.getElementById('btnQuickPick');
  if (btnQuick) {
    btnQuick.addEventListener('click', function () {
      var plan = [];
      TYPES.forEach(function (t) {
        var nEl = document.querySelector('.qb-num[data-type="' + t + '"]');
        var sEl = document.querySelector('.qb-score[data-type="' + t + '"]');
        plan.push({ type: t, n: parseInt(nEl.value, 10) || 0, s: parseFloat(sEl.value) || 1 });
      });
      var totalN = plan.reduce(function (a, p) { return a + p.n; }, 0);
      if (totalN <= 0) { alert('请至少给一个题型填题数。'); return; }

      var sum = plan.reduce(function (a, p) { return a + p.n * p.s; }, 0);
      var msg = '将按以下配置从题库随机抽题（会覆盖当前整卷的勾选）：\n\n';
      plan.forEach(function (p) {
        if (p.n > 0) msg += '· ' + p.type + '：' + p.n + ' 题 × ' + p.s + ' 分\n';
      });
      msg += '\n合计 ' + Math.round(sum * 10) / 10 + ' 分（卷面 ' + TOTAL + ' 分）\n确定继续？';
      if (!confirm(msg)) return;

      var short = [];
      plan.forEach(function (p) {
        var pool = rows(p.type);
        if (p.n === 0) { setChecked(p.type, []); return; }
        if (pool.length < p.n) {
          short.push(p.type + ' 题库只有 ' + pool.length + ' 题');
          setChecked(p.type, pool);
        } else {
          setChecked(p.type, shuffle(pool).slice(0, p.n));
        }
        rows(p.type).forEach(function (tr) {
          if (tr.querySelector('.cb').checked) tr.querySelector('.sc-input').value = p.s;
        });
      });
      refreshAll();
      if (short.length) alert('注意：' + short.join('；') + '。');
    });

    document.getElementById('btnQuickClear').addEventListener('click', function () {
      if (!confirm('清空整卷所有已选题目？')) return;
      TYPES.forEach(function (t) { setChecked(t, []); });
    });
  }

  refreshAll();
})();
</script>
<?php endif; ?>
</body>
</html>
