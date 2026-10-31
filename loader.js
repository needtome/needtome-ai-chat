(function () {
  'use strict';
  // The plugin puts the options on this script's own tag (no inline script, so a strict CSP still runs the chat).
  var tag = document.currentScript && document.currentScript.hasAttribute('data-aiworker-chat-options') ? document.currentScript : document.querySelector('script[data-aiworker-chat-options]');
  var options = {};
  try { options = tag ? JSON.parse(tag.getAttribute('data-aiworker-chat-options')) : (window.AIWorkerChatOptions || {}); } catch (e) { options = {}; }
  if (!options || typeof options !== 'object') options = {};
  var base = /^https:\/\/[^\s"'<>]+$/.test(options.base || '') ? options.base.replace(/\/$/, '') : 'https://kra.name/app';
  var key = options.key || '';
  var started = false;
  var openWhenReady = false;
  function start(open) {
    if (open) openWhenReady = true;
    if (started) return;
    if (!/^wk_[A-Za-z0-9]{24}$/.test(key)) return;
    started = true;
    var script = document.createElement('script');
    // A daily version keeps visitors on the current widget after an update without defeating the browser cache.
    var url = base + '/widget.js?v=' + Math.floor(Date.now() / 86400000);
    // Sites with Trusted Types only accept script URLs from a policy the CSP names (trusted-types aiworker-chat-loader).
    try {
      var policy = window.trustedTypes && window.trustedTypes.createPolicy ? window.trustedTypes.createPolicy('aiworker-chat-loader', {
        createScriptURL: function (value) { if (value.indexOf(base + '/') !== 0) throw new TypeError('unexpected script URL'); return value; }
      }) : null;
      script.src = policy ? policy.createScriptURL(url) : url;
    } catch (e) {
      if (window.console) console.error('AI Worker chat: this site\'s Content-Security-Policy blocks the chat. Open Settings > NeedToMe AI Chat in WordPress to see what to allow.');
      started = false;
      return;
    }
    script.setAttribute('data-key', key);
    script.setAttribute('data-lang', options.lang || 'auto');
    script.setAttribute('data-theme', options.theme || 'classic');
    if (options.theme === 'inline') {
      if (/^[.]?[A-Za-z][A-Za-z0-9_-]{0,63}$/.test(options.mount || '')) script.setAttribute('data-mount', options.mount);
      if (options.height) script.setAttribute('data-height', String(options.height));
    }
    script.setAttribute('data-color', options.color || '#4f46e5');
    if (/^(light|dim|dark|auto)$/.test(options.scheme || '')) script.setAttribute('data-scheme', options.scheme);
    var colors = [];
    if (options.colors && typeof options.colors === 'object') for (var k in options.colors) if (/^[a-z_]+$/.test(k) && /^#[0-9a-fA-F]{6}$/.test(options.colors[k] || '')) colors.push(k + ':' + options.colors[k]);
    if (colors.length) script.setAttribute('data-colors', colors.join(';'));
    script.setAttribute('data-position', options.position || 'right');
    // the launcher's offset for this site (empty = the chat's own offset at kra.name)
    if (/^-?\d{1,3}$/.test(String(options.offset_x))) script.setAttribute('data-site-offset-x', String(options.offset_x));
    if (/^-?\d{1,3}$/.test(String(options.offset_y))) script.setAttribute('data-site-offset-y', String(options.offset_y));
    script.setAttribute('data-voice', options.voice ? '1' : '0');
    script.setAttribute('data-rating', options.rating === 0 || options.rating === '0' ? '0' : '1');
    if (/^https?:\/\//.test(options.privacy_url || '')) script.setAttribute('data-privacy-url', options.privacy_url);
    script.onload = function () { if (openWhenReady) window.dispatchEvent(new Event('aiworker:open')); };
    document.body.appendChild(script);
  }
  if (options.load === 'interaction') {
    var events = ['scroll', 'click', 'mousemove', 'touchstart', 'keydown'];
    var once = function () { events.forEach(function (event) { window.removeEventListener(event, once, true); }); start(false); };
    events.forEach(function (event) { window.addEventListener(event, once, { capture: true, passive: true }); });
  } else if (options.load === 'delay') {
    setTimeout(function () { start(false); }, Math.max(1, Math.min(120, parseInt(options.delay, 10) || 5)) * 1000);
  } else start(false);
  window.addEventListener('aiworker:open', function () { start(true); });
}());
