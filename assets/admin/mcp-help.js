// «Copy»: the text field just before the button; the button says it was copied
document.addEventListener('click', function (e) {
  var prompt = e.target.closest && e.target.closest('#aiworker-chat-mcp .aiworker-chat-mcp__prompt');
  if (prompt) prompt.select();
});
    document.querySelectorAll('#aiworker-chat-mcp [data-copy-prev]').forEach(function (b) {
      b.addEventListener('click', function () {
        var f = b.previousElementSibling, label = b.textContent, done = function () { b.textContent = window.aiworkerChatAdmin.mcp.copied; setTimeout(function () { b.textContent = label; }, 1800); };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(f.value).then(done, function () { f.select(); document.execCommand('copy'); done(); });
        else { f.select(); document.execCommand('copy'); done(); }
      });
    });
