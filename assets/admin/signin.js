(function (d) {
  var c = (window.aiworkerChatAdmin || {}).signin || {}, timer = 0, until = 0, next = '';
  function ask() {
    if (Date.now() > until) { clearInterval(timer); timer = 0; return; }
    if (document.hidden) return;
    var body = new FormData();
    body.append('action', 'aiworker_chat_signin_status');
    body.append('nonce', c.nonce);
    fetch(c.ajax, { method: 'POST', body: body, credentials: 'same-origin' }).then(function (response) { return response.json(); }).then(function (result) {
      if (result && result.success && result.data.connected) {
        clearInterval(timer);
        timer = 0;
        location.href = next;
        if (next.split('#')[0] === location.href.split('#')[0]) location.reload();
      }
    }).catch(function () {});
  }
  function linkFor(event) {
    return event.target && event.target.closest ? event.target.closest('.aiworker-chat-signin') : null;
  }
  function watch(event) {
    var link = linkFor(event);
    if (!link) return null;
    next = link.getAttribute('data-next');
    until = Date.now() + 60 * 60 * 1000;
    if (!timer) timer = setInterval(ask, 4000);
    return link;
  }
  ['contextmenu', 'copy', 'auxclick'].forEach(function (name) { d.addEventListener(name, watch); });
  d.addEventListener('click', function (event) {
    var link = watch(event);
    if (!link || event.button || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    var width = 560, height = 760;
    var left = Math.max(0, (screen.availWidth - width) / 2), top = Math.max(0, (screen.availHeight - height) / 2);
    var popup = window.open(link.href + '&popup=1', 'aiworker-chat-signin', 'popup,width=' + width + ',height=' + height + ',left=' + left + ',top=' + top);
    if (popup) { event.preventDefault(); popup.focus(); } else link.target = '_blank';
  });
})(document);
