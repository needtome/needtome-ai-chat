(function (root) {
        if (!root || !root.querySelector('.aiworker-chat-aw__fold')) return;
        var fold = root.querySelector('.aiworker-chat-aw__fold');
        function setOpen(open) {
          root.classList.toggle('is-collapsed', !open); fold.setAttribute('aria-expanded', open ? 'true' : 'false');
          fold.firstChild.textContent = fold.getAttribute(open ? 'data-label-close' : 'data-label-open');
        }
        fold.addEventListener('click', function () {
          var open = root.classList.contains('is-collapsed');
          setOpen(open);
          // folded: forget the tab in the address, so a reload keeps the card folded
          if (!open) { try { var u = new URL(location.href); u.searchParams.delete('aiworker_chat_sub'); history.replaceState(null, '', u); } catch (e) { /* old browser */ } }
        });
        root.querySelectorAll('.aiworker-chat-aw__tab').forEach(function (tab) {
          tab.addEventListener('click', function () {
            setOpen(true);
            root.querySelectorAll('.aiworker-chat-aw__tab').forEach(function (x) {
              var on = x === tab; x.classList.toggle('is-active', on); x.setAttribute('aria-selected', on ? 'true' : 'false');
              document.getElementById(x.getAttribute('aria-controls')).hidden = !on;
            });
            try { var u = new URL(location.href); u.searchParams.set('aiworker_chat_sub', tab.getAttribute('data-sub')); history.replaceState(null, '', u); } catch (e) { /* old browser */ }
          });
        });
      })(document.getElementById('aiworker-chat-wizard'));
