    (function () {
      var c = window.aiworkerChatAdmin.preview, dlg = document.getElementById('aiworker-chat-kb-preview');
      var opener = document.querySelector('.aiworker-chat-kb__preview-open');
      if (!dlg || !opener) return;
      var body = dlg.querySelector('.aiworker-chat-modal__body');
      dlg.querySelector('.aiworker-chat-modal__close').addEventListener('click', function () { dlg.close(); });
      dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
      opener.addEventListener('click', function () {
        var form = this.closest('form'), data = new FormData();
        data.append('action', 'aiworker_chat_kb_preview'); data.append('nonce', c.nonce);
        new FormData(form).forEach(function (v, k) { if (k.indexOf(c.field) === 0) data.append(k, v); });
        body.textContent = c.loading; dlg.showModal();
        // page (and lang, if the screen has one) make the answer use this screen's language, as the screen does
        var q = new URLSearchParams(location.search), url = c.ajax + '&page=aiworker-chat' + (q.get('lang') ? '&lang=' + encodeURIComponent(q.get('lang')) : '');
        fetch(url, {method: 'POST', body: data, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (r) {
          if (! r || ! r.success) throw new Error();
          body.innerHTML = r.data.html;
        }).catch(function () { body.textContent = c.failed; });
      });
    })();
