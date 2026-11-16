/* ===================================================================
   Linux 服务器配置与管理 考试系统 · 答题页脚本
   ================================================================== */
(function () {
  'use strict';

  var CFG = window.EXAM || {};
  var API = (CFG.base || '') + '/api/';
  var remaining = parseInt(CFG.remaining || 0, 10);
  var timerEl = document.getElementById('timer');
  var stateEl = document.getElementById('saveState');
  var submitted = false;
  var saveTimers = {};
  var blurWarned = false;   /* 页面级标记：切屏超限只提醒一次，不重复弹窗 */

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  /* ---------------- 倒计时 ---------------- */
  function paintTimer() {
    if (!timerEl) return;
    var s = Math.max(0, remaining);
    var h = Math.floor(s / 3600);
    var m = Math.floor((s % 3600) / 60);
    var ss = s % 60;
    timerEl.textContent = (h > 0 ? h + ':' : '') +
      String(m).padStart(2, '0') + ':' + String(ss).padStart(2, '0');
    timerEl.classList.remove('warn', 'danger');
    if (s <= 300) timerEl.classList.add('danger');
    else if (s <= 900) timerEl.classList.add('warn');
  }

  function tick() {
    if (submitted) return;
    remaining--;
    paintTimer();
    if (remaining <= 0) {
      doSubmit('timeout');
    }
  }
  paintTimer();
  setInterval(tick, 1000);

  /* ---------------- 心跳：以服务端时间为准 ---------------- */
  setInterval(function () {
    if (submitted) return;
    fetch(API + 'heartbeat.php?exam_id=' + CFG.examId, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (!d.ok) return;
      if (d.submitted) {
        submitted = true;
        location.href = (CFG.base || '') + '/student/result.php?id=' + CFG.examId;
        return;
      }
      if (typeof d.remaining === 'number') {
        if (Math.abs(d.remaining - remaining) > 5) {
          remaining = d.remaining;
          paintTimer();
        }
      }
    }).catch(function () {});
  }, 20000);

  /* ---------------- 状态提示 ---------------- */
  function setState(txt, cls) {
    if (!stateEl) return;
    stateEl.textContent = txt;
    stateEl.className = 'save-state ' + (cls || '');
  }

  /* ---------------- 收集答案 ---------------- */
  function collect(card) {
    var type = card.getAttribute('data-type');
    if (type === 'single' || type === 'judge') {
      var r = card.querySelector('input[type=radio]:checked');
      return r ? r.value : '';
    }
    var ta = card.querySelector('textarea');
    return ta ? ta.value : '';
  }

  /* ---------------- 保存 ---------------- */
  function save(card, immediate) {
    var qid = card.getAttribute('data-qid');
    var val = collect(card);
    clearTimeout(saveTimers[qid]);
    var fire = function () {
      setState('保存中…', 'saving');
      var body = new FormData();
      body.append('exam_id', CFG.examId);
      body.append('question_id', qid);
      body.append('answer', val);
      body.append('_token', window.CSRF || '');
      fetch(API + 'save.php', {
        method: 'POST', body: body, credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (r) { return r.json(); }).then(function (d) {
        if (!d.ok) {
          setState(d.msg || '保存失败', 'danger');
          if (d.submitted) {
            submitted = true;
            setTimeout(function () { location.href = (CFG.base || '') + '/student/result.php?id=' + CFG.examId; }, 1200);
          }
          return;
        }
        setState('已自动保存', 'saved');
        refreshCardState(qid);
      }).catch(function () {
        setState('网络异常，稍后自动重试', 'danger');
      });
    };
    if (immediate) { fire(); } else { saveTimers[qid] = setTimeout(fire, 700); }
  }

  /* ---------------- 答题卡状态 ---------------- */
  function cardDone(qid) {
    var card = document.getElementById('q' + qid);
    if (!card) return false;
    var v = collect(card);
    if (v !== '') return true;
    var box = document.getElementById('shots' + qid);
    return !!(box && box.querySelector('.shot,.filechip'));
  }

  function refreshCardState(qid) {
    var done = cardDone(qid);
    var a = document.querySelector('#qnavGrid a[data-qid="' + qid + '"]');
    if (a) { a.classList.toggle('done', done); }
    var card = document.getElementById('q' + qid);
    if (card) card.classList.toggle('done', done);
    var cnt = document.querySelectorAll('#qnavGrid a.done').length;
    var el = document.getElementById('answeredCnt');
    if (el) el.textContent = cnt;
  }

  /* ---------------- 绑定作答事件 ---------------- */
  document.querySelectorAll('.qcard').forEach(function (card) {
    card.addEventListener('change', function (e) {
      var t = e.target;
      if (t.type === 'radio') {
        card.querySelectorAll('.opt').forEach(function (o) { o.classList.remove('sel'); });
        if (t.closest('.opt')) t.closest('.opt').classList.add('sel');
        save(card, true);
      }
    });
    card.addEventListener('input', function (e) {
      if (e.target.tagName === 'TEXTAREA') { save(card, false); }
    });
    card.addEventListener('blur', function (e) {
      if (e.target.tagName === 'TEXTAREA') { save(card, true); }
    }, true);
  });

  /* ==================================================================
     实操题附件：选择文件 / 剪贴板粘贴 / 拖拽
     ================================================================== */
  function attachArea(qid) { return document.getElementById('shots' + qid); }

  function countAtt(qid) {
    var box = attachArea(qid);
    return box ? box.querySelectorAll('.shot,.filechip').length : 0;
  }

  function renderAtt(qid, d) {
    var box = attachArea(qid);
    if (!box) return;
    var el;
    if (d.kind === 'image') {
      el = document.createElement('div');
      el.className = 'shot';
      el.setAttribute('data-id', d.id);
      el.innerHTML = '<img src="' + esc(d.url) + '" alt="">' +
        '<button class="del" type="button" title="删除">×</button>' +
        '<div class="nm">' + esc(d.name || '') + '</div>';
      el.querySelector('img').addEventListener('click', function () { openLightbox(d.url); });
    } else {
      el = document.createElement('div');
      el.className = 'filechip';
      el.setAttribute('data-id', d.id);
      var ext = esc(d.ext || 'FILE');
      el.innerHTML = '<div class="ic">' + ext + '</div>' +
        '<div class="meta">' +
          '<a class="nm" href="' + esc(d.url) + '" download="' + esc(d.name || '') + '" title="下载查看">' + esc(d.name || '') + '</a>' +
          '<div class="sz">' + esc(d.size_text || '') + '</div>' +
        '</div>' +
        '<button class="del" type="button" title="删除">×</button>';
    }
    box.appendChild(el);
  }

  function uploadOne(qid, file, source) {
    var box = attachArea(qid);
    if (!box) return;
    var maxCount = parseInt(CFG.upMax || 6, 10);
    if (countAtt(qid) >= maxCount) {
      alert('本题最多上传 ' + maxCount + ' 个附件，请先删除多余的。');
      return;
    }
    if (file.size > (CFG.upMb || 20) * 1024 * 1024) {
      alert('「' + file.name + '」超过 ' + (CFG.upMb || 20) + 'MB，请压缩后再传。');
      return;
    }

    var ph = document.createElement('div');
    ph.className = 'filechip uploading';
    var isImg = /^image\//.test(file.type || '');
    ph.innerHTML = '<div class="ic">' + (isImg ? 'IMG' : '…') + '</div>' +
      '<div class="meta"><span class="nm">上传中…</span><div class="sz">' + esc(file.name || '') + '</div></div>';
    box.appendChild(ph);
    setState('上传中…', 'saving');

    var fd = new FormData();
    fd.append('exam_id', CFG.examId);
    fd.append('question_id', qid);
    fd.append('source', source || 'pick');
    fd.append('file', file, file.name || ('paste_' + Date.now() + '.png'));
    fd.append('_token', window.CSRF || '');

    fetch(API + 'upload.php', {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (ph.parentNode) box.removeChild(ph);
      if (!d.ok) {
        alert(d.msg || '上传失败');
        setState('上传失败', 'danger');
        return;
      }
      renderAtt(qid, d);
      setState('已上传', 'saved');
      refreshCardState(qid);
    }).catch(function () {
      if (ph.parentNode) box.removeChild(ph);
      alert('上传失败，请检查网络或文件大小');
      setState('上传失败', 'danger');
    });
  }

  function uploadList(qid, files, source) {
    var arr = Array.prototype.slice.call(files || []);
    if (!arr.length) return;
    var maxCount = parseInt(CFG.upMax || 6, 10);
    var room = maxCount - countAtt(qid);
    if (room <= 0) {
      alert('本题最多上传 ' + maxCount + ' 个附件，请先删除多余的。');
      return;
    }
    if (arr.length > room) {
      alert('本题还可上传 ' + room + ' 个，已只上传前 ' + room + ' 个。');
      arr = arr.slice(0, room);
    }
    arr.forEach(function (f) { uploadOne(qid, f, source); });
  }

  /* 选择文件 */
  document.querySelectorAll('.file-input').forEach(function (inp) {
    inp.addEventListener('change', function () {
      var qid = inp.getAttribute('data-qid');
      uploadList(qid, inp.files, 'pick');
      inp.value = '';
    });
  });

  /* 粘贴（截图直接 Ctrl+V） */
  document.addEventListener('paste', function (e) {
    if (submitted) return;
    var el = document.activeElement;
    var card = el && el.closest ? el.closest('.qcard') : null;
    if (!card || card.getAttribute('data-type') !== 'practical') return;

    var cd = e.clipboardData || window.clipboardData;
    if (!cd) return;
    var files = [];
    if (cd.items && cd.items.length) {
      for (var i = 0; i < cd.items.length; i++) {
        var it = cd.items[i];
        if (it.kind === 'file') {
          var f = it.getAsFile();
          if (f) {
            // 剪贴板里的图片常常没有名字，补一个
            if (!f.name || f.name === 'image.png' || f.name === 'blob') {
              var ext = (f.type || 'image/png').split('/')[1] || 'png';
              try {
                f = new File([f], '粘贴截图_' + tsName() + '.' + ext, { type: f.type });
              } catch (err) { /* 老浏览器忽略 */ }
            }
            files.push(f);
          }
        }
      }
    }
    if (!files.length) {
      var txt = cd.getData ? cd.getData('text/plain') : '';
      if (txt) {
        // 纯文本粘贴交给浏览器默认行为（粘进文本框），不拦截
        return;
      }
      return;
    }
    e.preventDefault();
    uploadList(card.getAttribute('data-qid'), files, 'paste');
  });

  function tsName() {
    var d = new Date();
    function p(n) { return String(n).padStart(2, '0'); }
    return '' + d.getFullYear() + p(d.getMonth() + 1) + p(d.getDate()) + '_' +
      p(d.getHours()) + p(d.getMinutes()) + p(d.getSeconds());
  }

  /* 拖拽上传 */
  document.querySelectorAll('.qcard[data-type=practical]').forEach(function (card) {
    var qid = card.getAttribute('data-qid');
    ['dragenter', 'dragover'].forEach(function (ev) {
      card.addEventListener(ev, function (e) {
        if (!e.dataTransfer) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'copy';
        card.classList.add('dropzone');
      });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
      card.addEventListener(ev, function (e) {
        if (ev === 'dragleave' && card.contains(e.relatedTarget)) return;
        card.classList.remove('dropzone');
      });
    });
    card.addEventListener('drop', function (e) {
      if (!e.dataTransfer) return;
      e.preventDefault();
      card.classList.remove('dropzone');
      if (submitted) return;
      uploadList(qid, e.dataTransfer.files, 'pick');
    });
  });

  /* 删除附件 */
  document.addEventListener('click', function (e) {
    if (!e.target.classList.contains('del')) return;
    var item = e.target.closest('.shot,.filechip');
    if (!item) return;
    var id = item.getAttribute('data-id');
    if (!id) return;
    if (!confirm('确定删除这个附件？')) return;
    var fd = new FormData();
    fd.append('exam_id', CFG.examId);
    fd.append('id', id);
    fd.append('_token', window.CSRF || '');
    fetch(API + 'delete_upload.php', {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (d.ok) {
        var card = item.closest('.qcard');
        item.parentNode.removeChild(item);
        if (card) refreshCardState(card.getAttribute('data-qid'));
      } else {
        alert(d.msg || '删除失败');
      }
    });
  });

  /* ---------------- 切屏记录 ---------------- */
  var hiddenAt = 0;
  document.addEventListener('visibilitychange', function () {
    if (submitted) return;
    var fd = new FormData();
    fd.append('exam_id', CFG.examId);
    fd.append('_token', window.CSRF || '');
    if (document.hidden) {
      hiddenAt = Date.now();
      fd.append('event', 'blur');
      fd.append('seconds', 0);
    } else {
      if (!hiddenAt) return;
      var sec = Math.round((Date.now() - hiddenAt) / 1000);
      hiddenAt = 0;
      fd.append('event', 'focus');
      fd.append('seconds', sec);
    }
    fetch(API + 'log.php', {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.ok && typeof d.blur_count === 'number') {
        var el = document.getElementById('blurCnt');
        if (el) el.textContent = d.blur_count;
        /* 超限提醒：页面级只弹一次，避免每次切屏回来都骚扰 */
        if (d.over_limit && !blurWarned) {
          blurWarned = true;
          alert('注意：切换窗口已达 ' + d.blur_count + ' 次，超过 ' + CFG.blurLimit + ' 次将记录在案并提示监考教师。');
        }
      }
    }).catch(function () {});
  });

  /* ---------------- 交卷 ---------------- */
  function doSubmit(reason) {
    if (submitted) return;
    submitted = true;
    setState('正在交卷…', 'saving');
    var fd = new FormData();
    fd.append('exam_id', CFG.examId);
    fd.append('reason', reason || 'manual');
    fd.append('_token', window.CSRF || '');
    fetch(API + 'submit.php', {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    }).then(function (r) { return r.json(); }).then(function (d) {
      location.href = (CFG.base || '') + '/student/result.php?id=' + CFG.examId + (d.auto ? '&auto=1' : '');
    }).catch(function () {
      submitted = false;
      setState('交卷失败，请重试', 'danger');
    });
  }

  function confirmSubmit() {
    var done = document.querySelectorAll('#qnavGrid a.done').length;
    var un = CFG.qcount - done;
    var msg = un > 0
      ? '还有 ' + un + ' 题未作答，确定交卷吗？\n交卷后不能再修改。'
      : '共 ' + CFG.qcount + ' 题，确定交卷吗？\n交卷后不能再修改。';
    if (confirm(msg)) doSubmit('manual');
  }

  var b1 = document.getElementById('btnSubmitTop');
  var b2 = document.getElementById('btnSubmitBottom');
  if (b1) b1.addEventListener('click', confirmSubmit);
  if (b2) b2.addEventListener('click', confirmSubmit);

  /* 页面关闭提醒 */
  window.addEventListener('beforeunload', function (e) {
    if (submitted) return;
    e.preventDefault();
    e.returnValue = '考试尚未交卷，确定离开吗？';
    return e.returnValue;
  });

  /* ---------------- 图片灯箱 ---------------- */
  var lb = document.getElementById('lightbox');
  function openLightbox(src) {
    if (!lb) return;
    lb.querySelector('img').src = src;
    lb.classList.add('show');
  }
  if (lb) {
    document.addEventListener('click', function (e) {
      if (e.target === lb || e.target.parentNode === lb) {
        lb.classList.remove('show');
      }
    });
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') lb.classList.remove('show');
    });
  }

  /* ---------------- 题号导航 ---------------- */
  document.querySelectorAll('#qnavGrid a').forEach(function (a) {
    a.addEventListener('click', function () {
      document.querySelectorAll('#qnavGrid a').forEach(function (x) { x.classList.remove('cur'); });
      a.classList.add('cur');
    });
  });

  /* 首屏刷新已答状态（含已上传附件） */
  window.addEventListener('load', function () {
    document.querySelectorAll('.qcard').forEach(function (card) {
      refreshCardState(card.getAttribute('data-qid'));
    });
  });
})();