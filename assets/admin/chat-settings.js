(function () {
    var c = window.aiworkerChatAdmin || {};
    function initChatset(root) {
      if (!root || root.getAttribute('data-aiworker-chat-initialized')) return;
      root.setAttribute('data-aiworker-chat-initialized', '1');
      // the wizard's script config (nonce, admin-ajax) is printed in the footer, after this block: read it on click
      var t = c.settings.t;
      var updated = root.getAttribute('data-updated'), status = root.querySelector('[data-cs="status"]');
      var notice = root.querySelector('[data-cs="notice"]');
      // language tabs of the texts
      root.querySelectorAll('[data-cs-lang]').forEach(function (b) {
        b.addEventListener('click', function () {
          root.querySelectorAll('[data-cs-lang]').forEach(function (x) { x.classList.toggle('is-active', x === b); });
          root.querySelectorAll('[data-cs-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-cs-pane') !== b.getAttribute('data-cs-lang'); });
        });
      });
      function read() {
        var v = { texts: {} };
        root.querySelectorAll('[data-cs-field]').forEach(function (f) {
          var path = f.getAttribute('data-cs-field').split('.');
          var val = f.type === 'checkbox' ? f.checked : f.type === 'radio' ? (f.checked ? f.value : undefined) : f.value;
          if (val === undefined) return;
          if (path[0] === 'texts') { (v.texts[path[1]] = v.texts[path[1]] || {})[path[2]] = val; } else v[path[0]] = path[0] === 'persona_id' ? Number(val) : val;
        });
        var ld = root.querySelector('[data-cs="delivery"]');
        if (ld && !ld.hidden) {
          v.leads_delivery = { enabled: ld.querySelector('[data-ld="enabled"]').checked, channels: {} };
          ld.querySelectorAll('[data-ld-channel]').forEach(function (cb) {
            var type = cb.getAttribute('data-ld-channel'), fields = {};
            if (cb.checked) ld.querySelectorAll('[data-ld-fields="' + type + '"] [data-ld-field]').forEach(function (f) {
              if (f.getAttribute('data-ld-secret') === '1' && !f.value) return;   // an empty secret keeps the saved one
              fields[f.getAttribute('data-ld-field')] = f.value;
            });
            v.leads_delivery.channels[type] = { selected: cb.checked, fields: fields };
          });
        }
        root.querySelectorAll('[data-cs-sugg]').forEach(function (f) { var l = f.getAttribute('data-cs-sugg'); var tx = v.texts[l] = v.texts[l] || {}; (tx.suggestions = tx.suggestions || []); if (f.value.trim()) tx.suggestions.push(f.value.trim()); });
        return v;
      }
      // only what differs from what was read is sent, so a field changed at kra.name meanwhile is left alone
      function diff(a, b) {
        var out = {};
        Object.keys(a).forEach(function (k) {
          if (k === 'texts') {
            Object.keys(a.texts).forEach(function (l) { Object.keys(a.texts[l]).forEach(function (f) {
              if (JSON.stringify(a.texts[l][f]) !== JSON.stringify((b.texts[l] || {})[f])) { out.texts = out.texts || {}; (out.texts[l] = out.texts[l] || {})[f] = a.texts[l][f]; }
            }); });
          } else if (JSON.stringify(a[k]) !== JSON.stringify(b[k])) out[k] = a[k];
        });
        return out;
      }
      var initial = read();
      // where leads go matters only while leads are collected
      var collect = root.querySelector('[data-cs-field="leads_enabled"]'), delivery = root.querySelector('[data-cs="delivery"]');
      if (collect && delivery) collect.addEventListener('change', function () { delivery.hidden = !collect.checked; });
      // a ticked channel shows its fields
      root.querySelectorAll('[data-ld-channel]').forEach(function (cb) {
        cb.addEventListener('change', function () { var box = root.querySelector('[data-ld-fields="' + cb.getAttribute('data-ld-channel') + '"]'); if (box) box.hidden = !cb.checked; });
      });
      function tool(op, extra) {
        var c = window.aiworkerChatAssistant || {}, data = new FormData();
        data.append('action', 'aiworker_chat_chat_tool'); data.append('nonce', c.nonce); data.append('op', op); if (c.lang) data.append('lang', c.lang);
        Object.keys(extra || {}).forEach(function (k) { data.append(k, extra[k]); });
        return fetch(c.ajax, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); }).catch(function () { return { code: 0, body: {} }; });
      }
      // the chat button icon: ready ones are saved with the other fields; an own image is uploaded (or removed) at once
      var iconFile = root.querySelector('[data-cs="icon-file"]'), iconDel = root.querySelector('[data-cs="icon-delete"]'), iconStatus = root.querySelector('[data-cs="icon-status"]'), custom = root.querySelector('[data-cs="icon-custom"]');
      var iconDone = function (res, uploaded) {
        var body = res.body || {};
        if (res.code !== 200) { iconStatus.textContent = body.message || (body.errors && body.errors.file && body.errors.file[0]) || res.error || t.failed; iconStatus.classList.add('aiworker-chat-chatset__err'); return; }
        iconStatus.classList.remove('aiworker-chat-chatset__err'); iconStatus.textContent = uploaded ? t.iconUploaded : t.iconRemoved;
        var radio = custom.querySelector('input'); radio.setAttribute('data-url', body.launcher_icon_url || ''); custom.querySelector('img').src = body.launcher_icon_url || '';
        custom.hidden = !uploaded; iconDel.hidden = !uploaded;
        var pick = root.querySelector('[data-cs-field="launcher_icon"][value="' + (body.launcher_icon || 'chat') + '"]'); if (pick) pick.checked = true;
        if (body.updated_at) updated = body.updated_at; initial.launcher_icon = body.launcher_icon || 'chat';
        if (window.aiworkerChatPreviewPush) window.aiworkerChatPreviewPush();
      };
      if (iconFile) iconFile.addEventListener('change', function () {
        var f = iconFile.files[0]; if (!f) return;
        if (f.size > 1048576 || !/^image\/(png|jpeg|webp)$/.test(f.type)) { iconDone({ code: 422, body: { message: t.iconBad } }); iconFile.value = ''; return; }
        iconStatus.textContent = t.iconUploading; tool('icon_upload', { file: f }).then(function (res) { iconFile.value = ''; iconDone(res, true); });
      });
      if (iconDel) iconDel.addEventListener('click', function () { iconStatus.textContent = t.iconRemoving; tool('icon_delete').then(function (res) { iconDone(res, false); }); });
      var test = root.querySelector('[data-cs="test"]');
      if (test) test.addEventListener('click', function () {
        var list = root.querySelector('[data-cs="results"]'); test.disabled = true; list.hidden = false; list.textContent = t.testing;
        tool('test').then(function (res) {
          test.disabled = false; list.textContent = '';
          var results = (res.body || {}).results;
          if (res.code !== 200 || !Array.isArray(results)) { list.textContent = (res.body && (res.body.message || res.body.error)) || res.error || t.failed; return; }
          if (!results.length) { list.textContent = t.noChannels; return; }
          results.forEach(function (r) { var li = document.createElement('li'); li.className = r.ok ? 'is-ok' : 'is-err'; li.textContent = (r.ok ? '✓ ' : '✗ ') + r.type + ': ' + (r.message || ''); list.appendChild(li); });
        });
      });
      var tr = root.querySelector('[data-cs="translate"]');
      if (tr) tr.addEventListener('click', function () {
        tr.disabled = true; status.textContent = t.translating;
        tool('translate', { source: root.querySelector('[data-cs="source"]').value }).then(function (res) {
          tr.disabled = false; status.textContent = '';
          var all = (res.body || {}).translations;
          if (res.code !== 200 || !all) { say('error', (res.body && (res.body.message || res.body.error)) || res.error || t.failed); return; }
          var filled = 0;
          // only empty fields are filled; nothing is saved until «Save settings»
          Object.keys(all).forEach(function (l) {
            var tx = all[l] || {};
            ['name', 'title', 'tab_label', 'greeting'].forEach(function (k) { var f = root.querySelector('[data-cs-field="texts.' + l + '.' + k + '"]'); if (f && !f.value && tx[k]) { f.value = tx[k]; filled++; } });
            if (Array.isArray(tx.suggestions)) { var queue = tx.suggestions.slice(); root.querySelectorAll('[data-cs-sugg="' + l + '"]').forEach(function (f) { if (!f.value && queue.length) { f.value = queue.shift(); filled++; } }); }
          });
          say(filled ? 'success' : 'info', filled ? t.translated : t.nothingToTranslate);
        });
      });
      function say(type, text, reload) {
        notice.className = 'aiworker-chat-chatset__notice notice inline notice-' + type; notice.hidden = !text;
        var p = notice.firstChild; p.textContent = text || '';
        if (reload) { var a = document.createElement('a'); a.href = location.href; a.textContent = ' ' + t.reload; p.appendChild(a); }
      }
      // «Save settings» of the page saves this block to kra.name first (aiworkerChatSaveAll below); resolves false on an error
      root.aiworkerChatSave = function () {
        root.querySelectorAll('[data-cs-error]').forEach(function (e) { e.hidden = true; });
        var now = read(), changes = diff(now, initial);
        if (!Object.keys(changes).length) return Promise.resolve(true);
        var c = window.aiworkerChatAssistant || {};
        status.textContent = t.saving; say('', '');
        var data = new FormData();
        data.append('action', 'aiworker_chat_chat_save'); data.append('nonce', c.nonce); if (c.lang) data.append('lang', c.lang); data.append('chat', JSON.stringify(changes)); data.append('updated_at', updated || '');
        return fetch(c.ajax, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); }).catch(function () { return { code: 0, body: {} }; }).then(function (res) {
          status.textContent = '';
          var body = res.body || {};
          if (res.code === 200) { updated = body.updated_at || updated; initial = now; return true; }
          if (res.code === 409) { say('warning', t.stale, true); return false; }
          if (res.code === 422 && body.errors) {
            say('error', t.invalid);
            Object.keys(body.errors).forEach(function (k) {
              var e = root.querySelector('[data-cs-error="' + k.replace(/\.\d+$/, '') + '"]'); if (!e) return;
              e.textContent = [].concat(body.errors[k]).join(' '); e.hidden = false;
            });
            return false;
          }
          say('error', res.error || (body.message ? String(body.message) : t.failed));
          return false;
        });
      };
    }
    function initChatsets(scope) {
      if (scope && scope.matches && scope.matches('.aiworker-chat-chatset')) initChatset(scope);
      if (scope && scope.querySelectorAll) scope.querySelectorAll('.aiworker-chat-chatset').forEach(initChatset);
    }
    window.aiworkerChatInit = initChatsets;
    initChatsets(document);
    // One button saves everything: «Save settings» first sends what changed in these blocks to kra.name, then the
    // plugin's own settings; an error at kra.name stops the submit and is shown at its block.
    window.aiworkerChatSaveFailed = c.settings.saveFailed;
    if (!window.aiworkerChatSaveHooked) {
      window.aiworkerChatSaveHooked = true;
      document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches || !form.matches('form[action$="options.php"]') || form.dataset.aiworkerChatSaved) return;
        var blocks = Array.prototype.filter.call(document.querySelectorAll('.aiworker-chat-chatset'), function (b) { return typeof b.aiworkerChatSave === 'function'; });
        if (!blocks.length) return;
        e.preventDefault();
        var button = form.querySelector('[type="submit"]'); if (button) button.disabled = true;
        var said = form.querySelector('.aiworker-chat-save__error');
        if (said) said.remove();
        var stop = function (bad) {
          if (button) button.disabled = false;
          var msg = document.createElement('p'); msg.className = 'aiworker-chat-save__error'; msg.setAttribute('role', 'alert'); msg.textContent = window.aiworkerChatSaveFailed || '';
          var box = form.querySelector('.aiworker-chat-save'); if (box) box.appendChild(msg);
          var notice = bad && bad.querySelector('[data-cs="notice"]:not([hidden]), .aiworker-chat-chatset__err:not([hidden])');
          (notice || bad || msg).scrollIntoView({ behavior: 'smooth', block: 'center' });
        };
        Promise.all(blocks.map(function (b) { return b.aiworkerChatSave(); })).then(function (ok) {
          if (ok.indexOf(false) !== -1) { stop(blocks[ok.indexOf(false)]); return; }
          form.dataset.aiworkerChatSaved = '1';
          HTMLFormElement.prototype.submit.call(form);   // the button is named «submit» and hides form.submit()
        }).catch(function () { stop(null); });
      }, true);
    }
})();
