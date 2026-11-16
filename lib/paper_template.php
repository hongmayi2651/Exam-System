<?php defined('APP_ROOT') or exit('Access denied'); ?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>答卷 · <?php echo h($exam['title']); ?> · <?php echo h($stu['name']); ?></title>
<?php if ($embed): ?>
  <style><?php echo $css; ?></style>
<?php else: ?>
  <link rel="stylesheet" href="<?php echo h(base_url('assets/css/app.css')); ?>">
<?php endif; ?>
</head>
<body>

<?php if ($embed): ?>
  <div class="topbar no-print" style="position:static">
    <div class="brand">答卷<small><?php echo h($exam['title']); ?></small></div>
    <div class="spacer"></div>
    <a class="btn sm ghost" href="javascript:window.print()">打印</a>
  </div>
<?php else: ?>
  <div class="topbar no-print">
    <div class="brand">查看答卷<small><?php echo h($exam['title']); ?></small></div>
    <div class="spacer"></div>
    <a class="btn sm ghost" href="grade.php?exam_id=<?php echo $examId; ?>&sid=<?php echo $sid; ?>">返回阅卷</a>
    <a class="btn sm ghost" href="index.php?exam_id=<?php echo $examId; ?>">返回看板</a>
    <a class="btn sm ghost" href="javascript:window.print()">打印</a>
    <a class="btn sm ghost" href="<?php echo h(base_url('logout.php')); ?>">退出</a>
  </div>
<?php endif; ?>

<div class="exam-layout">
  <div class="exam-main">

    <div class="card tight mb10">
      <div class="flex aic gap10" style="flex-wrap:wrap">
        <strong><?php echo h($stu['name']); ?></strong>
        <span class="small muted"><?php echo h($stu['username']); ?></span>
        <span class="small muted"><?php echo h(isset($stu['class_name']) ? $stu['class_name'] : ''); ?></span>
        <span class="badge blue"><?php echo isset($statusText[$es['status']]) ? $statusText[$es['status']] : h($es['status']); ?></span>
        <?php if ($es['submit_type']): ?>
          <span class="badge gray"><?php echo isset($submitTypeText[$es['submit_type']]) ? $submitTypeText[$es['submit_type']] : h($es['submit_type']); ?></span>
        <?php endif; ?>
        <?php if ($es['blur_count'] > 0): ?>
          <span class="badge orange">切屏 <?php echo (int)$es['blur_count']; ?> 次</span>
        <?php endif; ?>
      </div>
      <div class="small muted mt10">
        开始 <?php echo h(fmt_dt($es['start_at'])); ?>
        · 交卷 <?php echo h(fmt_dt($es['submit_at'])); ?>
        · 共 <?php echo count($questions); ?> 题 / <?php echo rtrim(rtrim(number_format($totalScore, 1), '0'), '.'); ?> 分
      </div>
      <div class="small muted mt10">
        客观分 <strong><?php echo rtrim(rtrim(number_format((float)$es['score_objective'], 1), '0'), '.'); ?></strong>
        · 主观分 <strong><?php echo $es['score_subjective'] === null ? '—' : rtrim(rtrim(number_format((float)$es['score_subjective'], 1), '0'), '.'); ?></strong>
        · 总分 <strong><?php echo $es['score_total'] === null ? '—' : rtrim(rtrim(number_format((float)$es['score_total'], 1), '0'), '.'); ?></strong>
      </div>
    </div>

    <?php if ($exam['notice']): ?>
      <div class="card">
        <h3>考前须知</h3>
        <div class="pre small muted"><?php echo h($exam['notice']); ?></div>
      </div>
    <?php endif; ?>

    <?php $lastSection = ''; ?>
    <?php foreach ($questions as $idx => $q):
        $qid   = (int)$q['id'];
        $a     = isset($answers[$qid]) ? (string)$answers[$qid]['answer_text'] : '';
        $aNorm = strtoupper(str_replace(array(' ', ',', '，'), '', $a));
        $ansRow = isset($answers[$qid]) ? $answers[$qid] : null;
        $gRow   = isset($graded[$qid]) ? $graded[$qid] : null;
        $full   = (float)$q['exam_score'];

        if (is_objective($q['type'])) {
            $qScore = $ansRow && $ansRow['score'] !== null ? (float)$ansRow['score'] : null;
        } else {
            if ($gRow) {
                $qScore = (float)$gRow['score'];
            } elseif ($ansRow && $ansRow['score'] !== null) {
                $qScore = (float)$ansRow['score'];
            } else {
                $qScore = null;
            }
        }

        if ($q['section'] !== $lastSection) {
            $lastSection = $q['section'];
            echo '<h2 style="margin:22px 0 12px;font-size:16px;color:#0e3f8c;">'
               . h(isset($sectionTitle[$q['section']]) ? $sectionTitle[$q['section']] : '')
               . '</h2>';
        }
    ?>
    <div class="qcard <?php echo $a !== '' ? 'done' : ''; ?>" id="q<?php echo $qid; ?>">
      <div class="qhead">
        <span class="qno">第 <?php echo (int)$q['seq']; ?> 题</span>
        <span class="badge blue"><?php echo h(type_label($q['type'])); ?></span>
        <span class="qscore"><?php echo paper_qscore($qScore, $full); ?></span>
        <?php if ($q['topic']): ?><span class="badge gray"><?php echo h($q['topic']); ?></span><?php endif; ?>
      </div>

      <div class="stem"><?php echo h($q['stem']); ?></div>

      <?php if ($q['type'] === 'single'): ?>
        <?php $correctK = strtoupper(trim((string)$q['answer'])); ?>
        <?php foreach ($q['options'] as $op):
            $k = strtoupper($op['k']);
            $isSel = $aNorm === $k;
            $isCorrect = $k === $correctK;
            $cls = $isSel ? 'opt sel' : 'opt';
            $style = $isCorrect ? 'border-color:#12805c;background:#f2faf7' : '';
        ?>
          <div class="<?php echo $cls; ?>" style="<?php echo $style; ?>">
            <span class="k"><?php echo h($op['k']); ?></span>
            <span><?php echo h($op['t']); ?></span>
            <?php if ($isCorrect): ?><span class="small" style="color:#12805c;margin-left:auto">正确答案</span><?php endif; ?>
            <?php if ($isSel): ?><span class="small" style="color:#1e4fa8;margin-left:6px">学生所选</span><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <?php if ($a === ''): ?>
          <div class="small muted mt10">（未作答）</div>
        <?php endif; ?>
        <?php if ($ansRow && $ansRow['is_correct'] !== null): ?>
          <div class="small mt10" style="color:<?php echo $ansRow['is_correct'] ? '#12805c' : '#c0392b'; ?>">
            <?php echo $ansRow['is_correct'] ? '✓ 回答正确' : '✗ 回答错误'; ?>
          </div>
        <?php endif; ?>

      <?php elseif ($q['type'] === 'judge'): ?>
        <div class="opt <?php echo $aNorm === 'T' ? 'sel' : ''; ?>">
          <span class="k">√</span><span>正确（T）</span>
        </div>
        <div class="opt <?php echo $aNorm === 'F' ? 'sel' : ''; ?>">
          <span class="k">×</span><span>错误（F）</span>
        </div>
        <div class="small mt10">正确答案：<strong><?php echo h($q['answer']); ?></strong><?php echo $a === '' ? '　（学生未作答）' : ''; ?></div>

      <?php elseif ($q['type'] === 'short'): ?>
        <div class="answer-box <?php echo $a === '' ? 'empty' : ''; ?>"><?php echo $a === '' ? '（未作答）' : h($a); ?></div>
        <?php if ($q['ref_answer']): ?>
          <details style="margin-top:12px">
            <summary style="cursor:pointer;color:#1e4fa8;font-size:13px">查看参考答案（点击展开）</summary>
            <div class="answer-box mt10"><?php echo h($q['ref_answer']); ?></div>
          </details>
        <?php endif; ?>

      <?php else: /* practical */ ?>
        <?php if ($q['pract_steps']): ?>
          <div class="card tight" style="background:#fbfcff;border:1px solid #e6eaf2;margin-bottom:12px">
            <div class="small" style="color:#5b6472;font-weight:600;margin-bottom:4px">任务要求</div>
            <div class="pre small"><?php echo h($q['pract_steps']); ?></div>
          </div>
        <?php endif; ?>
        <?php if ($q['pract_shots']): ?>
          <div class="small" style="color:#5b6472;font-weight:600;margin-bottom:6px">需提交的截图 / 附件</div>
          <div class="shot-list" style="margin-bottom:12px">
            <?php foreach ($q['pract_shots'] as $s): ?>
              <span class="item"><?php echo h($s['no']); ?> <?php echo h($s['desc']); ?></span>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <div class="small" style="color:#5b6472;font-weight:600;margin-bottom:6px">学生作答（操作说明）</div>
        <div class="answer-box <?php echo $a === '' ? 'empty' : ''; ?>"><?php echo $a === '' ? '（未作答）' : h($a); ?></div>

        <?php if (!empty($shots[$qid])): ?>
          <div class="small" style="color:#5b6472;font-weight:600;margin:14px 0 6px">
            提交的附件（<?php echo count($shots[$qid]); ?> 个）
          </div>
          <div class="shots">
            <?php foreach ($shots[$qid] as $i => $f):
                /* 图片内嵌 base64；其它类型或超大图片给下载链接 */
                $isImg = paper_is_image($f);
                $zipB  = isset($zipBase) ? $zipBase : 'images';
                $fName = (isset($f['orig_name']) && $f['orig_name'] !== '') ? $f['orig_name'] : basename($f['file_path']);
                $fSize = (isset($f['size']) && (int)$f['size'] > 0) ? fmt_size($f['size']) : '';
            ?>
            <?php if ($isImg): ?>
              <div class="shot">
                <img src="<?php echo h(paper_image_src($f, $embed, $zipB)); ?>" alt="">
                <div class="nm">图 <?php echo $i + 1; ?> · <?php echo h($fName); ?></div>
              </div>
            <?php else: ?>
              <?php
                /* 非图片附件：打包时用 ZIP 内相对路径，在线查看时用站点 URL */
                $fHref = paper_file_href($f, $embed, $zipB);
              ?>
              <div class="shot">
                <div class="nm">
                  <a href="<?php echo h($fHref); ?>" download="<?php echo h($fName); ?>" title="下载查看">
                    📎 附件 <?php echo $i + 1; ?> · <?php echo h($fName); ?>
                  </a>
                  <?php if ($fSize): ?><span class="sz"><?php echo h($fSize); ?></span><?php endif; ?>
                </div>
              </div>
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div class="alert warn mt10">该生没有上传任何附件。</div>
        <?php endif; ?>
        <?php if ($q['ref_answer']): ?>
          <details style="margin-top:12px">
            <summary style="cursor:pointer;color:#1e4fa8;font-size:13px">查看参考答案（点击展开）</summary>
            <div class="answer-box mt10"><?php echo h($q['ref_answer']); ?></div>
          </details>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>

    <div class="card tight center no-print" style="margin-top:18px">
      <?php if (!$embed): ?>
        <a class="btn" href="grade.php?exam_id=<?php echo $examId; ?>&sid=<?php echo $sid; ?>">返回阅卷</a>
      <?php endif; ?>
      <button class="btn ghost" type="button" onclick="window.print()">打印答卷</button>
    </div>

  </div>
</div>

</body>
</html>