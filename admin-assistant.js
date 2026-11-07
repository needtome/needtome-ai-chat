/* NeedToMe AI Chat: the setup wizard (step 1 of Settings) and the «Statistics» tab; each page has one of the two. Talks only to this site's admin-ajax
 * (includes/assistant.php); every text from kra.name is put into the page as text, links only from the «links» field. */
(function () {
  'use strict';
  var c = window.aiworkerChatAssistant, root = document.getElementById('aiworker-chat-wizard');
  if (!c || !root) return;
  var t = c.t, $ = function (n) { return root.querySelector('[data-aw="' + n + '"]'); };
  var el = { account: $('account'), log: $('log'), pending: $('pending'), error: $('error'), text: $('text'),
    send: $('send'), clear: $('clear'), period: $('period'), range: $('range'), from: $('from'), to: $('to'), stats: $('stats') };
  // The wizard and statistics use the chat linked to this site by «Connect».
  var busy = false, pollTimer = null, chatId = c.chatId && c.chatId !== '0' ? c.chatId : '';
  if (el.text) el.text.placeholder = t.placeholder;

  function call(op, extra) {
    var data = new FormData();
    data.append('action', 'aiworker_chat_assistant');
    data.append('nonce', c.nonce);
    data.append('op', op);
    if (chatId) data.append('chat_id', chatId);
    if (c.lang) data.append('lang', c.lang);
    Object.keys(extra || {}).forEach(function (k) { data.append(k, extra[k]); });
    return fetch(c.ajax, { method: 'POST', body: data, credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .catch(function () { return { code: 0, body: {} }; });
  }

  function errorText(res) {
    var code = res.code, body = res.body || {};
    if (code === 401) return t.e401;
    if (code === 403) return body.error === 'chat_not_in_plan' ? (body.message ? String(body.message) : t.e403plan) : t.e403;
    if (code === 404) return t.e404;
    if (code === 422) return String(body.message || body.error || '');
    // token_limit: a free account's tokens are spent (retry_after is null) — kra.name explains it in message
    if (code === 429) return body.retry_after ? t.e429.replace('%s', String(body.retry_after)) : String(body.message || t.e429.replace('%s', '60'));
    if (code === 0) return res.error || t.e0;
    if (code >= 500) return body.message ? String(body.message) : t.e5xx;
    return String(body.message || body.error || t.e5xx);
  }

  function showError(res) {
    var msg = res ? errorText(res) : '';
    el.error.hidden = !msg;
    el.error.querySelector('p').textContent = msg;
    if (res && res.code === 401 && res.body && res.body.connected === false) window.setTimeout(function () { window.location.href = c.connectUrl; }, 2500);
  }

  // links: {"text as written": "href"} (as in the kra.name panel) or [{url|href, label|text}]; only http(s) targets.
  function linkMap(links) {
    var map = {};
    if (Array.isArray(links)) links.forEach(function (l) { if (l && (l.url || l.href)) map[l.label || l.text || l.url || l.href] = l.url || l.href; });
    else if (links && typeof links === 'object') Object.keys(links).forEach(function (k) { map[k] = links[k]; });
    Object.keys(map).forEach(function (k) { if (typeof map[k] !== 'string' || !/^https?:\/\//i.test(map[k])) delete map[k]; });
    return map;
  }

  function fillText(node, text, links) {
    var map = linkMap(links), keys = Object.keys(map).filter(Boolean).sort(function (a, b) { return b.length - a.length; });
    text = String(text || '');
    while (text) {
      var at = -1, key = '';
      keys.forEach(function (k) { var i = text.indexOf(k); if (i !== -1 && (at === -1 || i < at)) { at = i; key = k; } });
      if (at === -1) { node.appendChild(document.createTextNode(text)); break; }
      if (at > 0) node.appendChild(document.createTextNode(text.slice(0, at)));
      var a = document.createElement('a');
      a.href = map[key]; a.target = '_blank'; a.rel = 'noopener noreferrer'; a.textContent = key;
      node.appendChild(a);
      text = text.slice(at + key.length);
    }
  }

  function render(body) {
    body = body || {};
    var messages = Array.isArray(body.messages) ? body.messages : [];
    el.log.textContent = '';
    messages.forEach(function (m, i) {
      var role = m && m.role === 'user' ? 'user' : 'assistant';
      var div = document.createElement('div');
      div.className = 'aiworker-chat-aw__msg aiworker-chat-aw__msg--' + role;
      if (body.watching && i === messages.length - 1 && /⏳/.test(m.text || '')) div.className += ' aiworker-chat-aw__msg--working';
      var who = document.createElement('small');
      who.textContent = role === 'user' ? t.you : t.assistant;
      div.appendChild(who);
      fillText(div, m && m.text, role === 'assistant' ? m.links : null);
      el.log.appendChild(div);
    });
    el.log.scrollTop = el.log.scrollHeight;

    var pending = Array.isArray(body.pending) ? body.pending : [];
    el.pending.textContent = '';
    el.pending.hidden = !pending.length;
    if (pending.length) {
      var h = document.createElement('strong'); h.textContent = t.pending; el.pending.appendChild(h);
      var ul = document.createElement('ul');
      pending.forEach(function (p) { var li = document.createElement('li'); li.textContent = typeof p === 'string' ? p : String((p && p.summary) || ''); ul.appendChild(li); });
      el.pending.appendChild(ul);
      var apply = document.createElement('button'); apply.type = 'button'; apply.className = 'button button-primary'; apply.textContent = t.apply;
      var discard = document.createElement('button'); discard.type = 'button'; discard.className = 'button'; discard.textContent = t.discard; discard.style.marginLeft = '8px';
      apply.addEventListener('click', function () { act('apply'); });
      discard.addEventListener('click', function () { act('discard'); });
      el.pending.appendChild(apply); el.pending.appendChild(discard);
    }

    window.clearTimeout(pollTimer);
    // kra.name sets the pace (poll_after, seconds) and how long to watch (watch_until, a timestamp); a stale page stops asking
    var until = Date.parse(body.watch_until || '') || 0;
    if (body.watching && (!until || until > Date.now())) pollTimer = window.setTimeout(function () { act('assistant', null, true); }, Math.max(3, Math.min(60, Number(body.poll_after) || 8)) * 1000);
  }

  function setBusy(on) {
    busy = on;
    [el.send, el.clear].forEach(function (b) { if (b) b.disabled = on; });
    el.pending.querySelectorAll('button').forEach(function (b) { b.disabled = on; });
    el.send.textContent = on ? t.sending : t.send;
  }

  function act(op, extra, quiet) {
    if (busy && !quiet) return Promise.resolve();
    if (!quiet) setBusy(true);
    return call(op, extra).then(function (res) {
      if (!quiet) setBusy(false);
      if (res.code === 200) { showError(null); render(res.body); if (op === 'apply') loadStats(); }
      else showError(res);
      return res;
    });
  }

  if (el.log) {
    el.send.addEventListener('click', function () {
      var text = el.text.value.trim();
      if (!text) { el.text.focus(); return; }
      act('ask', { text: text }).then(function (res) { if (res && res.code === 200) el.text.value = ''; });
    });
    el.text.addEventListener('keydown', function (e) { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); el.send.click(); } });
    el.clear.addEventListener('click', function () { if (window.confirm(t.clearConfirm)) act('clear'); });
  }

  /* Statistics */
  var ALIAS = { sessions: ['sessions', 'dialogs'], messages_in: ['messages_in'], answers: ['answers', 'messages_out'], leads: ['leads'],
    rated_high: ['rated_high'], rated_low: ['rated_low'], voice: ['voice'] };
  function val(row, key) { var n = 0; (ALIAS[key] || [key]).some(function (k) { if (row && row[k] != null) { n = Number(row[k]) || 0; return true; } return false; }); return n; }
  function fmt(n) { return Number(n || 0).toLocaleString(); }
  function node(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text != null) n.textContent = text; return n; }

  function meter(label, used, limit, text, percent) {
    var box = node('div', 'aiworker-chat-aw__meter');
    var pct = percent != null ? Number(percent) : (limit > 0 ? used / limit * 100 : null);
    var line = node('div', '', label + ': ' + (text || (limit > 0 ? fmt(used) + ' ' + t.of + ' ' + fmt(limit) : fmt(used) + ' · ' + t.noLimit)));
    box.appendChild(line);
    if (pct != null && isFinite(pct)) {
      var bar = node('i'), fill = node('b');
      fill.style.width = Math.max(0, Math.min(100, pct)) + '%';
      bar.appendChild(fill); box.appendChild(bar);
      if (pct >= 100) box.className += ' is-full'; else if (pct >= 80) box.className += ' is-warn';
    }
    return box;
  }

  function chart(days) {
    var ns = 'http://www.w3.org/2000/svg', w = 600, h = 160, pad = 20, n = days.length;
    var max = Math.max(1, Math.max.apply(null, days.map(function (d) { return Math.max(d.sessions, d.leads); })));
    var svg = document.createElementNS(ns, 'svg');
    svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h); svg.setAttribute('class', 'aiworker-chat-aw__chart'); svg.setAttribute('role', 'img');
    var slot = (w - pad) / n, bw = Math.max(2, slot * 0.38);
    days.forEach(function (d, i) {
      [['sessions', '#4f46e5', 0], ['leads', '#00a32a', 1]].forEach(function (s) {
        var v = d[s[0]], bh = (h - pad) * v / max, r = document.createElementNS(ns, 'rect');
        r.setAttribute('x', pad + i * slot + s[2] * bw); r.setAttribute('y', h - pad - bh); r.setAttribute('width', bw - 1); r.setAttribute('height', bh);
        r.setAttribute('fill', s[1]);
        var tip = document.createElementNS(ns, 'title'); tip.textContent = d.date + ' — ' + t.cols[s[0]] + ': ' + v; r.appendChild(tip);
        svg.appendChild(r);
      });
      if (n <= 10 || i % Math.ceil(n / 8) === 0) {
        var lb = document.createElementNS(ns, 'text');
        lb.setAttribute('x', pad + i * slot); lb.setAttribute('y', h - 4); lb.setAttribute('font-size', '10'); lb.setAttribute('fill', '#646970');
        lb.textContent = String(d.date).slice(5); svg.appendChild(lb);
      }
    });
    var wrap = node('div'); wrap.appendChild(svg);
    var legend = node('div', 'aiworker-chat-aw__legend');
    [['sessions', '#4f46e5'], ['leads', '#00a32a']].forEach(function (s) { var i = node('i'); i.style.background = s[1]; legend.appendChild(i); legend.appendChild(document.createTextNode(t.cols[s[0]])); });
    wrap.appendChild(legend);
    return wrap;
  }

  function renderStats(body) {
    el.stats.textContent = '';
    body = body || {};
    var tok = body.tokens;
    if (tok) el.stats.appendChild(meter(tok.label || t.tokens, Number(tok.used) || 0, Number(tok.limit) || 0, tok.text, tok.percent));
    // the account's tokens are already shown above from body.tokens; meters repeats them as free_tokens / tokens
    (Array.isArray(body.meters) ? body.meters : []).forEach(function (m) { if (m && !(tok && (m.key === 'free_tokens' || m.key === 'tokens'))) el.stats.appendChild(meter(String(m.label || m.key || ''), Number(m.used) || 0, Number(m.limit) || 0, m.text)); });

    var chats = Array.isArray(body.chats) ? body.chats : [], totals = {}, byDay = {};
    chats.forEach(function (ch) {
      Object.keys(ALIAS).forEach(function (k) { totals[k] = (totals[k] || 0) + val(ch.totals || {}, k); });
      (Array.isArray(ch.daily) ? ch.daily : []).forEach(function (d) {
        var date = String(d.date || d.day || '').slice(0, 10); if (!date) return;
        var row = byDay[date] || (byDay[date] = { date: date });
        Object.keys(ALIAS).forEach(function (k) { row[k] = (row[k] || 0) + val(d, k); });
      });
    });
    var cards = node('div', 'aiworker-chat-aw__cards');
    Object.keys(ALIAS).forEach(function (k) {
      if (k === 'voice' && !totals.voice) return;
      var cell = node('div'); cell.appendChild(node('b', '', fmt(totals[k]))); cell.appendChild(node('span', '', t.cols[k])); cards.appendChild(cell);
    });
    el.stats.appendChild(cards);

    var days = Object.keys(byDay).sort().map(function (d) { return byDay[d]; });
    if (!days.length || !days.some(function (d) { return d.sessions || d.messages_in || d.leads; })) { el.stats.appendChild(node('p', 'description', t.empty)); return; }
    el.stats.appendChild(node('h3', '', t.byDay));
    el.stats.appendChild(chart(days));
    var table = node('table', 'widefat striped'), head = node('tr');
    [t.day, t.cols.sessions, t.cols.messages_in, t.cols.answers, t.cols.leads, t.cols.rated_high, t.cols.rated_low].forEach(function (h) { head.appendChild(node('th', '', h)); });
    var thead = node('thead'); thead.appendChild(head); table.appendChild(thead);
    var tbody = node('tbody');
    days.slice().reverse().forEach(function (d) {
      var tr = node('tr');
      [d.date, fmt(d.sessions), fmt(d.messages_in), fmt(d.answers), fmt(d.leads), fmt(d.rated_high), fmt(d.rated_low)].forEach(function (v) { tr.appendChild(node('td', '', v)); });
      tbody.appendChild(tr);
    });
    table.appendChild(tbody);
    var scroll = node('div', 'aiworker-chat-aw__scroll'); scroll.appendChild(table);
    el.stats.appendChild(scroll);
  }

  function loadStats() {
    if (!el.stats) return;
    var custom = el.period && el.period.value === 'custom';
    if (el.range) el.range.hidden = !custom;
    // a range waits for both dates; the earlier one may be typed second
    if (custom && (!el.from.value || !el.to.value || el.from.value > el.to.value)) return;
    // a new period: the spinner again while kra.name answers
    if (el.stats.querySelector('.aiworker-chat-aw__meter, .aiworker-chat-aw__cards')) { var wait = node('p', 'aiworker-chat-aw__loading', ' ' + t.loading); wait.insertBefore(node('i', 'aiworker-chat-aw__spin'), wait.firstChild); el.stats.textContent = ''; el.stats.appendChild(wait); }
    var query = { period: el.period.value };
    if (custom) { query.from = el.from.value; query.to = el.to.value; }
    call('stats', query).then(function (res) {
      if (res.code === 200) renderStats(res.body);
      else { el.stats.textContent = ''; el.stats.appendChild(node('p', 'description', errorText(res))); }
    });
  }
  [el.period, el.from, el.to].forEach(function (input) { if (input) input.addEventListener('change', loadStats); });

  // Dictation, as in the kra.name panel: MediaRecorder → this site's admin-ajax → kra.name's speech recognition (the
  // account's tokens); where kra.name cannot transcribe (no such call yet, not set up) the browser's own recognition.
  var mic = $('mic'), micStatus = $('micStatus'), wave = $('wave'), rec = null, sr = null, micBusy = false, meterStop = null;
  // the sound wave next to the button while it records, from the microphone's level (as in the kra.name panel); without
  // Web Audio (or for the browser's own recognition) the bars just move, so it is still visible that it listens
  function soundMeter(stream) {
    if (!wave) return function () {};
    wave.hidden = false;
    var bars = wave.children, AC = window.AudioContext || window.webkitAudioContext;
    if (!stream || !AC) { wave.classList.add('is-fallback'); return function () { wave.classList.remove('is-fallback'); wave.hidden = true; }; }
    try {
      var ctx = new AC(), source = ctx.createMediaStreamSource(stream), analyser = ctx.createAnalyser(), frame = 0;
      analyser.fftSize = 256; source.connect(analyser);
      if (ctx.resume) ctx.resume().catch(function () { wave.classList.add('is-fallback'); });
      var data = new Uint8Array(analyser.fftSize);
      (function draw() {
        analyser.getByteTimeDomainData(data);
        var sum = 0;
        for (var i = 0; i < data.length; i++) { var v = (data[i] - 128) / 128; sum += v * v; }
        var level = Math.min(1, Math.sqrt(sum / data.length) * 5);
        for (var j = 0; j < bars.length; j++) bars[j].style.height = Math.round(3 + level * (12 + 7 * Math.sin(j * 1.8 + 1))) + 'px';
        frame = requestAnimationFrame(draw);
      })();
      return function () { cancelAnimationFrame(frame); source.disconnect(); ctx.close(); wave.hidden = true; };
    } catch (e) { wave.classList.add('is-fallback'); return function () { wave.classList.remove('is-fallback'); wave.hidden = true; }; }
  }
  function micSay(text) { if (!micStatus) return; micStatus.textContent = text || ''; micStatus.hidden = !text; }
  // getting the microphone ready and recognizing take a second or more: a spinner on the button and a line in the field
  // say so (as in the kra.name panel), otherwise nothing seemed to happen after the stop
  var busyBox = $('busy');
  function micState(state) {
    mic.classList.toggle('is-rec', state === 'rec'); mic.classList.toggle('is-busy', state === 'busy'); mic.classList.toggle('is-prep', state === 'prep');
    if (busyBox) {
      var working = state === 'prep' || state === 'busy';
      busyBox.lastChild.textContent = state === 'prep' ? t.micPrep : state === 'busy' ? t.micBusy : '';
      busyBox.hidden = !working; busyBox.parentNode.classList.toggle('is-working', working);
    }
    var label = state === 'rec' ? t.micStop : t.mic; mic.title = label; mic.setAttribute('aria-label', label);
    mic.setAttribute('aria-pressed', state === 'rec' ? 'true' : 'false');
  }
  // Safari's «Allow» lasts until the page reloads, so it asks every time: once, after the first recording, say where to
  // allow the microphone for good (as the kra.name panel does). The flag is a per-browser convenience; storage may be blocked.
  function safariHint() {
    var ua = navigator.userAgent || '';
    if (!/Safari\//.test(ua) || /Chrome|Chromium|CriOS|Edg|OPR|Firefox|FxiOS/.test(ua)) return;
    try {
      var key = 'aiworkerChatMicSafariHint';
      if (window.localStorage.getItem(key)) return;
      window.localStorage.setItem(key, '1');
    } catch (e) { return; }
    setTimeout(function () { micSay(t.micSafari); }, 2500);
  }
  function insert(text) {
    var f = el.text, v = f.value, a = typeof f.selectionStart === 'number' ? f.selectionStart : v.length, b = typeof f.selectionEnd === 'number' ? f.selectionEnd : v.length;
    var before = v.slice(0, a), piece = (before && !/\s$/.test(before) ? ' ' : '') + text;
    f.value = before + piece + v.slice(b); f.focus();
    try { f.setSelectionRange((before + piece).length, (before + piece).length); } catch (e) { /* not focusable yet */ }
  }
  function browserRecognition() {
    var SR = window.SpeechRecognition || window.webkitSpeechRecognition;
    if (!SR) { micState('idle'); micSay(t.micNone); return; }
    sr = new SR(); sr.lang = c.lang === 'uk' ? 'uk-UA' : c.lang === 'en' ? 'en-US' : 'ru-RU'; sr.interimResults = false;
    sr.onresult = function (e) { var x = e.results[0] && e.results[0][0] ? e.results[0][0].transcript : ''; if (x) insert(x.trim()); };
    sr.onerror = function (e) { micSay(e.error === 'not-allowed' ? t.micDenied : t.micFailed); };
    sr.onend = function () { sr = null; if (meterStop) { meterStop(); meterStop = null; } micState('idle'); if (micStatus.textContent === t.micSpeak) micSay(''); };
    try { sr.start(); micState('rec'); micSay(t.micSpeak); meterStop = soundMeter(null); } catch (e) { sr = null; micState('idle'); micSay(t.micFailed); }
  }
  function record() {
    if (micBusy || mic.classList.contains('is-prep')) return;   // a second press while the microphone wakes up
    if (sr) { sr.stop(); return; }
    if (rec) { if (rec.state === 'recording') rec.stop(); return; }
    if (!navigator.mediaDevices || !window.MediaRecorder) { browserRecognition(); return; }
    micState('prep'); micSay('');
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      var type = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4', 'audio/ogg'].filter(function (m) { return MediaRecorder.isTypeSupported && MediaRecorder.isTypeSupported(m); })[0];
      var chunks = [];
      try { rec = new MediaRecorder(stream, type ? { mimeType: type } : undefined); } catch (e) { stream.getTracks().forEach(function (x) { x.stop(); }); rec = null; browserRecognition(); return; }
      rec.ondataavailable = function (e) { if (e.data && e.data.size) chunks.push(e.data); };
      var recorder = rec, safety = 0;   // this recording's own recorder and its 2-minute stop
      rec.onstop = function () {
        clearTimeout(safety);
        if (meterStop) { meterStop(); meterStop = null; }
        stream.getTracks().forEach(function (x) { x.stop(); });
        var mime = recorder.mimeType || 'audio/webm'; rec = null;
        var blob = new Blob(chunks, { type: mime });
        if (blob.size < 1200) { micState('idle'); micSay(t.micShort); return; }
        micBusy = true; micState('busy'); micSay('');
        var ext = mime.indexOf('mp4') >= 0 ? 'm4a' : mime.indexOf('ogg') >= 0 ? 'ogg' : 'webm';
        var data = new FormData();
        data.append('action', 'aiworker_chat_assistant'); data.append('nonce', c.nonce); data.append('op', 'voice');
        if (c.lang) data.append('lang', c.lang);
        data.append('audio', blob, 'voice.' + ext);
        fetch(c.ajax, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); }).catch(function () { return { code: 0, body: {} }; }).then(function (res) {
          micBusy = false; micState('idle'); micSay('');
          var body = res.body || {};
          if (res.code === 200 && body.text) { insert(String(body.text)); safariHint(); return; }
          // kra.name without the call (404) or without recognition set up (503): say it again for the browser's own
          if ((res.code === 404 || res.code === 503 || res.code === 0) && (window.SpeechRecognition || window.webkitSpeechRecognition)) { browserRecognition(); return; }
          micSay(body.error ? String(body.error) : res.code === 402 || res.code === 429 ? errorText(res) : t.micFailed);
        });
      };
      rec.start(250); micState('rec'); micSay(t.micSpeak); meterStop = soundMeter(stream);
      // safety stop after 2 minutes, of this recording only: a later one has its own recorder and timer
      safety = setTimeout(function () { if (recorder.state === 'recording') recorder.stop(); }, 120000);
    }).catch(function (e) { rec = null; micState('idle'); micSay(e && (e.name === 'NotAllowedError' || e.name === 'SecurityError') ? t.micDenied : t.micFailed); });
  }
  if (mic && (window.MediaRecorder || window.SpeechRecognition || window.webkitSpeechRecognition)) {
    mic.hidden = false; micState('idle');
    mic.addEventListener('click', function (e) { e.preventDefault(); record(); });
  }

  call('me').then(function (res) {
    if (res.code !== 200) { showError(res); return; }
    var me = res.body || {}, chats = Array.isArray(me.chats) ? me.chats : [];
    var acc = me.account || {};
    // the account's email, so the owner sees which kra.name account it is; its middle is hidden (ro***@gmail.com)
    var mask = function (m) { var at = m.indexOf('@'); return at < 1 ? m : m.slice(0, Math.min(2, at)) + '***' + m.slice(at); };
    var who = typeof acc === 'string' ? acc : (acc.email_masked || (acc.email ? mask(String(acc.email)) : acc.name));
    if (el.account) el.account.textContent = [who, acc.plan_name || (me.plan && (me.plan.name || me.plan))].filter(function (x) { return typeof x === 'string' && x; }).join(' · ');
    // the wizard may be closed for this account (for example, a free account without tokens): say why, keep the statistics
    if (me.wizard && me.wizard.available === false) showError({ code: 422, body: { message: typeof me.wizard.blocked === 'string' ? me.wizard.blocked : t.e403 } });
    if (!chatId || !chats.some(function (ch) { return String(ch.id) === chatId; })) { showError({ code: 404, body: {} }); return; }
    if (el.log) act('assistant');
    loadStats();
  });
})();
