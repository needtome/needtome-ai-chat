document.addEventListener('click', function (event) {
  var tab = event.target.closest('.aiworker-chat-channels__tab');
  if (!tab) return;
  var root = tab.closest('.aiworker-chat-channels');
  if (!root) return;
  root.querySelectorAll('.aiworker-chat-channels__tab').forEach(function (item) {
    var active = item === tab;
    item.classList.toggle('is-active', active);
    item.setAttribute('aria-selected', active ? 'true' : 'false');
    var panel = document.getElementById(item.getAttribute('aria-controls'));
    if (panel) panel.hidden = !active;
  });
});
