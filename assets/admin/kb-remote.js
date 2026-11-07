(function () {
    var FAQ_PAGE = 20;
    // kra.name returns every question at once, and the block is rebuilt after each save: the view outlives it
    var faqView = { query: '', page: 1, open: false, top: 0, saved: false };
    function initFaq(box, c) {
      if (!box) return;
      var part = function (name) { return box.querySelector('[data-kbr-faq="' + name + '"]'); };
      var list = part('list'), form = part('form'), filter = part('filter'), pager = part('pager');
      function apply() {
        var query = filter.value.trim().toLowerCase(), rows = Array.prototype.slice.call(list.children);
        var found = rows.filter(function (row) {
          return !query || (row.querySelector('[data-kbr-field="question"]').value + '\n' + row.querySelector('[data-kbr-field="answer"]').value).toLowerCase().indexOf(query) !== -1;
        });
        var pages = Math.max(1, Math.ceil(found.length / FAQ_PAGE));
        faqView.query = filter.value; faqView.page = Math.min(Math.max(1, faqView.page), pages);
        rows.forEach(function (row) { row.hidden = true; });
        found.slice((faqView.page - 1) * FAQ_PAGE, faqView.page * FAQ_PAGE).forEach(function (row) { row.hidden = false; });
        part('none').hidden = !rows.length || found.length > 0;
        part('count').textContent = rows.length ? (query ? found.length + ' / ' : '') + rows.length : '';
        pager.hidden = pages < 2;
        part('page').textContent = c.faqPage.replace('%1$d', faqView.page).replace('%2$d', pages);
        pager.querySelector('[data-kbr-faq-page="-1"]').disabled = faqView.page < 2;
        pager.querySelector('[data-kbr-faq-page="1"]').disabled = faqView.page >= pages;
      }
      filter.value = faqView.query;
      filter.addEventListener('input', function () { faqView.page = 1; apply(); });
      box.addEventListener('click', function (e) {
        var add = e.target.closest('[data-kbr-faq-add]'), step = e.target.closest('[data-kbr-faq-page]');
        if (add) {
          // the form opens where the button was pressed, so it is in view on a long list
          var tools = add.parentNode;
          tools.getAttribute('data-kbr-faq') === 'top' ? tools.parentNode.insertBefore(form, tools.nextSibling) : tools.parentNode.insertBefore(form, tools);
          form.hidden = false; form.querySelector('[data-kbr-field="question"]').focus();
        } else if (e.target.closest('[data-kbr-faq-cancel]')) form.hidden = true;
        else if (step) { faqView.page += Number(step.getAttribute('data-kbr-faq-page')); apply(); box.scrollIntoView({ block: 'start' }); }
      });
      apply();
      var dialog = box.closest('dialog');
      dialog.addEventListener('close', function () { faqView.open = false; });
      dialog.querySelector('[data-kbr-faq-close]').addEventListener('click', function () { dialog.close(); });
      box.closest('#aiworker-chat-kbr').addEventListener('click', function (e) {
        if (e.target.closest('[data-kbr-faq-open]')) { faqView.open = true; dialog.showModal(); }
      });
      // a save rebuilds the block: the window comes back where it was
      if (faqView.open) { dialog.showModal(); dialog.scrollTop = faqView.top; }
    }
    function initRemote(root) {
      if (!root || root.getAttribute('data-aiworker-chat-initialized')) return;
      root.setAttribute('data-aiworker-chat-initialized', '1');
      var c = window.aiworkerChatAdmin.remote;
      var notice = root.querySelector('[data-kbr="notice"]'), add = root.querySelector('[data-kbr="add"]');
      var faqNotice = root.querySelector('[data-kbr="faq-notice"]'), target = notice;
      function say(type, text) { target.className = 'aiworker-chat-kbr__notice notice inline notice-' + type; target.hidden = !text; target.firstChild.textContent = text || ''; }
      // the add form: a text source has a text field instead of an address
      if (add) {
        var type = add.querySelector('[data-kbr-field="type"]'), sync = function () {
          var t = type.value === 'text';
          add.querySelector('[data-kbr-field="url"]').hidden = t; add.querySelector('[data-kbr-field="text"]').hidden = !t;
          add.querySelector('[data-kbr-hint="site"]').hidden = type.value !== 'site';
        };
        type.addEventListener('change', sync); sync();
      }
      initFaq(root.querySelector('[data-kbr="faq"]'), c);
      if (faqView.saved && faqNotice) { faqView.saved = false; target = faqNotice; say('success', c.done); target = notice; }
      // a deletion waits here until the owner answers; Cancel has the focus, so Enter deletes nothing
      var ask = root.querySelector('[data-kbr="confirm"]'), asked = null;
      function confirmPart(name) { return ask.querySelector('[data-kbr-confirm="' + name + '"]'); }
      root.addEventListener('click', function (e) {
        var answer = e.target.closest('[data-kbr-confirm]');
        if (answer && answer.tagName === 'BUTTON') {
          var pressed = asked; asked = null; ask.close();
          if (pressed && answer.getAttribute('data-kbr-confirm') === 'yes') run(pressed);
          return;
        }
        var b = e.target.closest('[data-kbr-op]'); if (!b) return;
        if (!b.hasAttribute('data-confirm')) { run(b); return; }
        asked = b;
        confirmPart('text').textContent = b.getAttribute('data-confirm');
        confirmPart('yes').textContent = b.textContent;
        ask.showModal(); confirmPart('no').focus();
      });
      function run(b) {
        var op = b.getAttribute('data-kbr-op'), scope = b.closest('.aiworker-chat-kbr__qa') || b.closest('[data-kbr="add"]') || b;
        var dialog = b.closest('dialog');
        target = dialog ? faqNotice : notice;
        var data = new FormData();
        data.append('action', 'aiworker_chat_kb_remote'); data.append('nonce', c.nonce); data.append('op', op); data.append('lang', c.lang);
        data.append('id', b.getAttribute('data-id') || (scope.getAttribute && scope.getAttribute('data-id')) || '');
        scope.querySelectorAll && scope.querySelectorAll('[data-kbr-field]').forEach(function (f) { data.append(f.getAttribute('data-kbr-field'), f.value); });
        b.disabled = true; say('info', c.saving);
        fetch(c.ajax, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); }).catch(function () { return { code: 0, body: {} }; }).then(function (res) {
          b.disabled = false;
          var body = res.body || {}, ok = res.code >= 200 && res.code < 300;
          if (!ok) {
            var msg = body.message || body.error || (body.errors ? Object.keys(body.errors).map(function (k) { return [].concat(body.errors[k]).join(' '); }).join(' ') : '') || res.error || c.failed;
            say('error', String(msg)); return;
          }
          // the list changed at kra.name: show it as it is now
          if (dialog) { faqView.top = dialog.scrollTop; faqView.saved = true; }
          if (window.aiworkerChatLazyReload) window.aiworkerChatLazyReload(root, 'sources'); else say('success', c.done);
        });
      }
    }
    function initRemoteTree(scope) {
      if (scope && scope.matches && scope.matches('#aiworker-chat-kbr')) initRemote(scope);
      if (scope && scope.querySelectorAll) scope.querySelectorAll('#aiworker-chat-kbr').forEach(initRemote);
    }
    initRemoteTree(document);
    document.addEventListener('aiworkerChatLazyPut', function (e) { initRemoteTree(e.detail && e.detail.root); });
})();
