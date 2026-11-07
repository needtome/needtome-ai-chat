(function () {
  'use strict';
  // Old cached HTML supplies a single object. New pages supply one array on the same loader tag.
  var tag = document.currentScript && document.currentScript.hasAttribute('data-aiworker-chat-options') ? document.currentScript : document.querySelector('script[data-aiworker-chat-options]');
  var config = {};
  try { config = tag ? JSON.parse(tag.getAttribute('data-aiworker-chat-options')) : (window.AIWorkerChatOptions || {}); } catch (e) {}
  if (!config || typeof config !== 'object') config = {};
  var base = /^https:\/\/[^\s"'<>]+$/.test(config.base || '') ? config.base.replace(/\/$/, '') : 'https://kra.name/app';
  var list = Array.isArray(config.chats) ? config.chats : [config], states = [], seen = {}, replaying = false, policy = null, policyTried = false, policyFailed = false, winner = null;
  list.forEach(function (options) {
    if (!options || !/^wk_[A-Za-z0-9]{24}$/.test(options.key || '') || seen[options.key]) return;
    seen[options.key] = true;
    var state = { options: options, key: options.key, order: parseInt(options.stack_order, 10) || 0, started: false, ready: false, waiting: [] };
    states.push(state);
    if ((options.greet_popup === true || options.greet_popup === 1 || options.greet_popup === '1') && (!winner || state.order < winner.order)) winner = state;
  });
  function start(state, done) {
    if (state.ready) { if (done) done(); return; }
    if (done) state.waiting.push(done);
    if (state.started) return;
    state.started = true;
    var options = state.options, key = state.key, script = document.createElement('script');
    var version = String(config.widget_version || options.widget_version || '');
    if (!/^[A-Za-z0-9._-]{1,40}$/.test(version)) version = String(Math.floor(Date.now() / 86400000));
    var url = base + '/widget.js?v=' + encodeURIComponent(version);
    // One policy for all instances, with exactly the previous URL boundary and CSP failure behaviour.
    try {
      if (policyFailed) throw new TypeError('policy unavailable');
      if (!policyTried) {
        policyTried = true;
        policy = window.trustedTypes && window.trustedTypes.createPolicy ? window.trustedTypes.createPolicy('aiworker-chat-loader', {
          createScriptURL: function (value) { if (value.indexOf(base + '/') !== 0) throw new TypeError('unexpected script URL'); return value; }
        }) : null;
      }
      script.src = policy ? policy.createScriptURL(url) : url;
    } catch (e) {
      policyFailed = true;
      if (window.console) console.error('AI Worker chat: this site\'s Content-Security-Policy blocks the chat. Open NeedToMe > AI Chat in WordPress to see what to allow.');
      state.started = false;
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
    script.setAttribute('data-stack-order', String(state.order));
    if (options.stack === 0 || options.stack === '0') script.setAttribute('data-stack', '0');
    if (winner) script.setAttribute('data-greet', state === winner ? '1' : '0');
    else if (options.greet_popup === 0 || options.greet_popup === false || options.greet_popup === '0') script.setAttribute('data-greet', '0');
    script.onload = function () {
      state.ready = true;
      var waiting = state.waiting; state.waiting = [];
      waiting.forEach(function (done) { done(); });
    };
    script.onerror = function () { state.started = false; if (script.parentNode) script.parentNode.removeChild(script); };
    document.body.appendChild(script);
  }
  function triggerKey(target) {
    while (target && target !== document) {
      if (target.getAttribute) { var key = target.getAttribute('data-aiworker-chat'); if (key) return key; }
      target = target.parentNode;
    }
    return '';
  }
  function open(key, original) {
    var selected = states.filter(function (state) { return !key || state.key === key; });
    if (!selected.length || selected.every(function (state) { return state.ready; })) return;
    if (original && original.stopImmediatePropagation) original.stopImmediatePropagation();
    var left = selected.length;
    selected.forEach(function (state) {
      start(state, function () {
        left--;
        if (left) return;
        replaying = true;
        try { window.dispatchEvent(new CustomEvent('aiworker:open', key ? { detail: { key: key } } : {})); }
        finally { replaying = false; }
      });
    });
  }
  window.addEventListener('aiworker:open', function (event) {
    if (!replaying) open(event.detail && event.detail.key ? String(event.detail.key) : '', event);
  });
  window.addEventListener('click', function (event) {
    var key = triggerKey(event.target);
    if (key) open(key);
  }, true);
  var events = ['scroll', 'click', 'mousemove', 'touchstart', 'keydown'];
  function interaction(event) {
    // A targeted trigger must not wake every interaction-loaded chat.
    if (triggerKey(event.target)) return;
    states.forEach(function (state) { if (state.options.load === 'interaction') start(state); });
    events.forEach(function (name) { window.removeEventListener(name, interaction, true); });
  }
  if (states.some(function (state) { return state.options.load === 'interaction'; })) events.forEach(function (name) { window.addEventListener(name, interaction, { capture: true, passive: true }); });
  states.forEach(function (state) {
    var options = state.options;
    if (options.load === 'delay') setTimeout(function () { start(state); }, Math.max(1, Math.min(120, parseInt(options.delay, 10) || 5)) * 1000);
    else if (options.load !== 'interaction') start(state);
  });
}());
