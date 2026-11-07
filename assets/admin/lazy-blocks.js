(function (c) {
      // a block that changed something at kra.name reloads itself: aiworkerChatLazyReload(itsElement, 'name')
      window.aiworkerChatLazyReload = function (el, block) {
        var box = document.createElement('div'); box.className = 'aiworker-chat-lazy aiworker-chat-card'; box.setAttribute('data-aiworker-chat-lazy', block); box.setAttribute('data-n', '0');
        box.innerHTML = '<p class="aiworker-chat-aw__loading"><i class="aiworker-chat-aw__spin" aria-hidden="true"></i> </p>'; box.firstChild.appendChild(document.createTextNode(c.loading));
        el.parentNode.replaceChild(box, el); load([box]);
      };
      load(Array.prototype.slice.call(document.querySelectorAll('[data-aiworker-chat-lazy]')));
      // every placeholder of the screen in one request
      function load(boxes) {
        if (!boxes.length) return;
        var b = new FormData(), id = function (box) { return box.getAttribute('data-aiworker-chat-lazy') + ':' + (box.getAttribute('data-n') || '0'); };
        b.append('action', 'aiworker_chat_lazy'); b.append('nonce', c.nonce); b.append('lang', c.lang);
        boxes.forEach(function (box) { b.append('blocks[]', id(box)); });
        fetch(c.ajax, { method: 'POST', body: b, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
          if (!r || !r.success) throw new Error('failed');
          boxes.forEach(function (box) {
            var name = box.getAttribute('data-aiworker-chat-lazy'), root = put(box, r.data.html[id(box)] || '');
            if (window.aiworkerChatInit) window.aiworkerChatInit(root);
            document.dispatchEvent(new CustomEvent('aiworkerChatLazyPut', { detail: { name: name, root: root } }));
          });
        }).catch(function () { boxes.forEach(function (box) { box.innerHTML = ''; var p = document.createElement('p'); p.className = 'description'; p.textContent = c.failed; box.appendChild(p); }); });
      }
      function put(box, html) {
        var tmp = document.createElement('div'); tmp.innerHTML = html;
        var nodes = Array.prototype.slice.call(tmp.childNodes), parent = box.parentNode;
        nodes.forEach(function (n) { parent.insertBefore(n, box); });
        parent.removeChild(box);
        return parent;
      }
    })(window.aiworkerChatAdmin.lazy);
