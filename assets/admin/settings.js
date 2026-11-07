var aiworkerChatAdminConfig = window.aiworkerChatAdmin || {};
var aiworkerChatKey = document.getElementById('aiworker-chat-key');
var aiworkerChatKeySave = document.getElementById('aiworker-chat-key-save');
if (aiworkerChatKey && aiworkerChatKeySave) {
  var aiworkerChatKeySaved = aiworkerChatKey.value;
  aiworkerChatKey.addEventListener('input', function () { aiworkerChatKeySave.hidden = aiworkerChatKey.value.trim() === aiworkerChatKeySaved; });
}
// «Add another chat» opens in place; the server stores nothing until a chat is chosen, so Cancel only puts the page back
(function () {
  var form = document.getElementById('aiworker-chat-list-form'), config = aiworkerChatAdminConfig.list, plain = false, undo = null;
  if (!form || !config) return;
  function node(html) { var box = document.createElement('template'); box.innerHTML = html; return box.content.firstElementChild; }
  function open(add) {
    var data = new FormData(form);
    data.set('action', 'aiworker_chat_add_open');
    data.set('lang', config.lang);
    add.disabled = true;
    fetch(config.ajax, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (res) {
      var d = res && res.success && res.data;
      if (!d) throw new Error('add');
      if (d.url) { window.location.href = d.url; return; }
      var list = document.getElementById('aiworker-chat-chat-list'), pick = document.getElementById('aiworker-chat-pick');
      var newList = node(d.list), newPick = d.pick ? node(d.pick) : null;
      // keys typed but not saved yet stay in their rows
      list.querySelectorAll('input[name^="keys["]').forEach(function (old) {
        var row = newList.querySelector('input[name="' + old.name + '"]'); if (row) row.value = old.value;
      });
      add.disabled = false;
      list.replaceWith(newList);
      if (newPick && pick) pick.replaceWith(newPick); else if (newPick) newList.parentNode.insertBefore(newPick, newList);
      undo = function () { newList.replaceWith(list); if (newPick && pick) newPick.replaceWith(pick); else if (newPick) newPick.remove(); };
      if (!newPick) newList.querySelector('input[name="keys[new]"]').focus();
    }).catch(function () { add.disabled = false; plain = true; add.click(); });
  }
  document.addEventListener('click', function (e) {
    var add = e.target.closest('#aiworker-chat-chat-list button[name="add"]');
    if (add && !plain) { e.preventDefault(); open(add); return; }
    if (undo && e.target.closest('[data-aiworker-chat-add-cancel]')) { undo(); undo = null; }
  });
})();
if (window.jQuery && window.jQuery.fn && window.jQuery.fn.wpColorPicker) {
  var aiworkerChatPickerChanged = function () { setTimeout(function () { window.aiworkerChatPreviewPush && window.aiworkerChatPreviewPush(); }, 0); };
  window.jQuery('.aiworker-chat-color').wpColorPicker({ change: aiworkerChatPickerChanged, clear: aiworkerChatPickerChanged });
}
(function () {
      var preview = document.querySelector('.aiworker-chat-preview'), form = document.querySelector('form.aiworker-chat-grid'); if (!preview || !form) return;
      var c = JSON.parse(preview.getAttribute('data-config') || '{}');
      var previewLoad = document.getElementById('aiworker-chat-preview-load'), previewOpen = document.getElementById('aiworker-chat-open'), previewStarted = false;
      function loadPreview() {
        if (previewStarted) return;
        previewStarted = true;
        var script = document.createElement('script'), attrs = c.script || {};
        Object.keys(attrs).forEach(function (name) {
          if (name === 'defer') script.defer = !!attrs[name];
          else script.setAttribute(name, String(attrs[name]));
        });
        script.addEventListener('load', function () {
          if (previewLoad) previewLoad.hidden = true;
          if (previewOpen) previewOpen.hidden = false;
          if (previewOpen) syncLabel();
        });
        script.addEventListener('error', function () {
          previewStarted = false;
          if (previewLoad) { previewLoad.disabled = false; previewLoad.hidden = false; }
        });
        if (previewLoad) previewLoad.disabled = true;
        document.body.appendChild(script);
      }
      if (previewLoad) previewLoad.addEventListener('click', loadPreview);
      var field = function (n) { return form.querySelector('[name="' + c.option + '[' + n + ']"]'); };
      var hex = function (v) { return /^#[0-9a-fA-F]{6}$/.test(v || '') ? v.toLowerCase() : ''; };
      var px = function (n) { var v = field(n) && field(n).value.trim(); return /^-?\d{1,3}$/.test(v || '') ? parseInt(v, 10) : null; };
      var queued = false;
      function push() {
        if (queued) return; queued = true;
        requestAnimationFrame(function () {
          queued = false;
          var colors = {}; c.keys.forEach(function (k) { var i = form.querySelector('[name="' + c.option + '[colors][' + k + ']"]'), v = i && hex(i.value); if (v) colors[k] = v; });
          var lang = (field('lang') && field('lang').value) || 'auto', l = lang === 'auto' ? c.autoLang : lang, extra = {};
          // the greeting and quick questions as typed in «Chat texts», in the preview's language, before saving
          var g = document.querySelector('[data-cs-field="texts.' + l + '.greeting"]'), q = document.querySelectorAll('[data-cs-sugg="' + l + '"]');
          if (g && g.value.trim()) extra.greeting = g.value.trim(); // empty: the chat's own greeting stays
          var an = document.querySelector('[data-cs-field="ai_notice"]'), gp = document.querySelector('[data-cs-field="greet_popup"]');
          if (an) extra.aiNotice = an.checked; if (gp) extra.greet = gp.checked;
          document.documentElement.style.setProperty('--aiworker-chat-accent', hex(field('color').value) || '#4f46e5'); // the icon picker shows the chat's colour
          var ic = document.querySelector('[data-cs-field="launcher_icon"]:checked');
          if (ic) { extra.launcherIcon = ic.value; extra.launcherIconUrl = ic.value === 'custom' ? ic.getAttribute('data-url') || '' : ''; }
          if (q.length) extra.suggestions = Array.prototype.map.call(q, function (f) { return f.value.trim(); }).filter(Boolean);
          window.dispatchEvent(new CustomEvent('aiworker-preview-options', { detail: Object.assign(extra, {
            theme: field('theme').value, scheme: field('scheme').value || c.chatScheme, colors: colors, color: hex(field('color').value) || '#4f46e5',
            position: field('position').value, offsetX: px('offset_x'), offsetY: px('offset_y'), voice: c.voiceAllowed && field('voice').checked, rating: field('rating').checked,
            height: parseInt(field('height').value, 10) || 560, lang: l }) }));
        });
      }
      var rows = function () {
        document.querySelectorAll('.aiworker-chat-inline-only').forEach(function (r) { r.style.display = field('theme').value === 'inline' ? '' : 'none'; });
        document.querySelectorAll('.aiworker-chat-pages-only').forEach(function (r) { r.style.display = field('pages_mode').value === 'all' ? 'none' : ''; });
        var d = document.querySelector('.aiworker-chat-delay'); if (d) d.style.display = field('load').value === 'delay' ? '' : 'none';
      };
      rows(); form.addEventListener('change', rows);
      form.addEventListener('input', push); form.addEventListener('change', push);
      // the «Chat texts» card loads after the page and may sit outside the form
      document.addEventListener('input', function (e) { if (e.target.closest && e.target.closest('[data-cs-field$=".greeting"], [data-cs-sugg], [data-cs-field="ai_notice"], [data-cs-field="greet_popup"]')) push(); });
      document.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('[data-cs-field="ai_notice"], [data-cs-field="greet_popup"], [data-cs-field="launcher_icon"]')) push(); });
      document.addEventListener('aiworkerChatLazyPut', function (e) { if (e.detail && e.detail.name === 'chat') push(); });
      // One button opens and closes the preview window; its label follows the window state.
      var toggleBtn = document.getElementById('aiworker-chat-open');
      // .aiw-* selectors below belong to the remote widget's markup.
      var chatWindow = function () { return document.querySelector('.aiw-win:not(.aiw-inline)'); };
      var syncLabel = function () {
        var w = chatWindow(), on = !!(w && w.classList.contains('on')), label = on ? toggleBtn.dataset.closeLabel : toggleBtn.dataset.openLabel;
        if (toggleBtn.textContent !== label) toggleBtn.textContent = label; // only real changes, so the observer does not re-trigger itself
        toggleBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
      };
      toggleBtn.addEventListener('click', function () {
        var w = chatWindow(), close = w && w.classList.contains('on') && w.querySelector('.aiw-close');
        if (close) close.click(); else window.dispatchEvent(new Event('aiworker:open'));
      });
      new MutationObserver(syncLabel).observe(document.body, { subtree: true, attributes: true, attributeFilter: ['class'] });
      syncLabel();
      // Desktop: the save card sits at the top of the right column; narrow screens keep it as a sticky bar under the form.
      var save = form.querySelector('.aiworker-chat-save'), main = save && save.parentNode, side = form.querySelector('.aiworker-chat-side'), wide = window.matchMedia('(min-width: 1101px)');
      var placeSave = function () { if (!save || !side) return; if (wide.matches) { side.insertBefore(save, side.firstChild); save.classList.add('is-side'); } else { main.appendChild(save); save.classList.remove('is-side'); } };
      placeSave(); if (wide.addEventListener) wide.addEventListener('change', placeSave); else wide.addListener(placeSave);
      window.aiworkerChatPreviewPush = push; // the WordPress colour picker reports its changes through this
      // «Reset preview»: unsaved edits in the form and the preview go back to the saved settings; nothing is saved.
      // The button shows only while the form differs from what was loaded.
      var reset = form.querySelector('.aiworker-chat-reset'), resetDone = form.querySelector('.aiworker-chat-reset-done');
      var saved = Array.prototype.map.call(form.querySelectorAll('input[name], select[name], textarea[name]'), function (f) {
        return { f: f, v: f.value, on: f.checked };
      });
      var differs = function () { return saved.some(function (s) { return (s.f.type === 'checkbox' || s.f.type === 'radio') ? s.f.checked !== s.on : s.f.value !== s.v; }); };
      var syncReset = function () {
        if (!reset) return; var d = differs(); reset.hidden = !d;
        if (d && resetDone) resetDone.hidden = true;
      };
      if (reset) {
        form.addEventListener('input', syncReset); form.addEventListener('change', syncReset);
        window.aiworkerChatPreviewPush = function () { push(); syncReset(); };
        reset.addEventListener('click', function () {
          saved.forEach(function (s) {
            if (s.f.type === 'checkbox' || s.f.type === 'radio') { s.f.checked = s.on; return; }
            s.f.value = s.v;
            if (window.jQuery && s.f.classList.contains('aiworker-chat-color') && window.jQuery(s.f).data('wpWpColorPicker')) {
              if (s.v) window.jQuery(s.f).wpColorPicker('color', s.v); else window.jQuery(s.f).trigger('change');
            }
          });
          rows(); push(); syncReset();
          if (resetDone) resetDone.hidden = false;
          form.querySelector('[type="submit"][name="submit"]').focus();
        });
      }
      if (c.autoLoad) loadPreview();
    })();
