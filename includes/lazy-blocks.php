<?php
/**
 * Blocks of the settings screen that read kra.name (each call takes a second or two) load after the page: the page shows
 * a spinner in their place and fetches each block's HTML from admin-ajax, so the screen opens at once instead of
 * waiting for every call. The blocks' own scripts run after they are put in.
 */

if (! defined('ABSPATH')) exit;

/** The blocks that can load this way: name => renderer (the number is the step number, when the block has one). */
function aiworker_chat_lazy_blocks(): array {
    return [
        'usage' => static function (): void { aiworker_chat_usage_block(); },
        'chat' => static function (int $n): void { aiworker_chat_chat_step($n); },
        'payment' => static function (int $n): void { aiworker_chat_payment_step($n); },
        'channels' => static function (): void { aiworker_chat_channels_block(); },
        'answer' => static function (): void { aiworker_chat_answer_mode_block(); },
        'sources' => static function (): void { aiworker_chat_kb_remote_block(); },
    ];
}

/** The placeholder: a spinner where the block will be. $card: the block is a card of its own (a step). */
function aiworker_chat_lazy(string $block, int $number = 0, bool $card = false): void {
    static $script = false;
    echo '<div class="aiwc-lazy'.($card ? ' aiwc-card' : '').'" data-aiwc-lazy="'.esc_attr($block).'" data-n="'.esc_attr((string) $number).'">'
        .'<p class="aiwc-aw__loading"><i class="aiwc-aw__spin" aria-hidden="true"></i> '.esc_html__('Loading from kra.name…', 'needtome-ai-chat').'</p></div>';
    if ($script) return;
    $script = true;
    add_action('admin_footer', 'aiworker_chat_lazy_script');
}

/** The kra.name GETs a block needs that are not cached on this site: [[path, query], …]. */
function aiworker_chat_lazy_calls(string $block): array {
    $chat = (int) get_option(AIWORKER_CHAT_LINKED_OPTION, 0);
    $lang = aiworker_chat_wizard_lang();
    $me = get_transient('aiworker_chat_me_'.$lang) ? [] : [['/plugin/me', ['lang' => $lang]]];
    switch ($block) {
        case 'usage': return get_transient('aiworker_chat_usage_'.$chat.'_'.$lang) ? [] : [['/plugin/usage', ['chat_id' => $chat, 'lang' => $lang]]];
        case 'chat':
        case 'answer': return [['/plugin/chat', ['chat_id' => $chat, 'lang' => $lang]]];
        case 'payment': return $me;
        case 'channels': return get_transient('aiworker_chat_channels_'.$chat) ? [] : [['/plugin/channels', ['chat_id' => $chat]]];
        case 'sources': return [['/plugin/sources', ['chat_id' => $chat, 'lang' => $lang]], ['/plugin/faq', ['chat_id' => $chat, 'lang' => $lang]]];
    }
    return [];
}

/**
 * AJAX: the HTML of every lazy block of the screen in one request (blocks[] = «name:number»). What they need from
 * kra.name is asked for in parallel first, so the screen waits for the slowest call, not for all of them in turn.
 */
function aiworker_chat_lazy_ajax(): void {
    if (! current_user_can('manage_options')) wp_send_json_error(null, 403);
    check_ajax_referer('aiwc_lazy', 'nonce');
    $known = aiworker_chat_lazy_blocks();
    $wanted = [];
    foreach ((array) wp_unslash($_POST['blocks'] ?? []) as $item) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Each item is split and sanitized below.
        [$name, $number] = array_pad(explode(':', sanitize_text_field((string) $item), 2), 2, '0');
        $name = sanitize_key($name);
        if (isset($known[$name])) $wanted[$name.':'.absint($number)] = [$name, absint($number)];
    }
    if (! $wanted) wp_send_json_error(null, 400);
    // the blocks in the screen's language: the plugin's own texts too, not only kra.name's
    $locales = ['ru' => 'ru_RU', 'uk' => 'uk', 'en' => 'en_US'];
    switch_to_locale($locales[aiworker_chat_wizard_lang()]);
    $calls = [];
    foreach ($wanted as [$name]) foreach (aiworker_chat_lazy_calls($name) as $call) $calls[aiworker_chat_api_get_url($call[0], $call[1])] = $call;
    aiworker_chat_api_prefetch(array_values($calls));
    $html = [];
    foreach ($wanted as $id => [$name, $number]) {
        ob_start();
        $known[$name]($number);
        $html[$id] = (string) ob_get_clean();
    }
    wp_send_json_success(['html' => $html]);
}
add_action('wp_ajax_aiwc_lazy', 'aiworker_chat_lazy_ajax');

function aiworker_chat_lazy_script(): void {
    $data = ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('aiwc_lazy'), 'failed' => __('kra.name did not answer. Reload the page to try again.', 'needtome-ai-chat'), 'loading' => __('Loading from kra.name…', 'needtome-ai-chat'), 'lang' => aiworker_chat_wizard_lang()];
    ?>
    <script>
    (function (c) {
      // a block that changed something at kra.name reloads itself: aiwcLazyReload(itsElement, 'name')
      window.aiwcLazyReload = function (el, block) {
        var box = document.createElement('div'); box.className = 'aiwc-lazy aiwc-card'; box.setAttribute('data-aiwc-lazy', block); box.setAttribute('data-n', '0');
        box.innerHTML = '<p class="aiwc-aw__loading"><i class="aiwc-aw__spin" aria-hidden="true"></i> </p>'; box.firstChild.appendChild(document.createTextNode(c.loading));
        el.parentNode.replaceChild(box, el); load([box]);
      };
      load(Array.prototype.slice.call(document.querySelectorAll('[data-aiwc-lazy]')));
      // every placeholder of the screen in one request
      function load(boxes) {
        if (!boxes.length) return;
        var b = new FormData(), id = function (box) { return box.getAttribute('data-aiwc-lazy') + ':' + (box.getAttribute('data-n') || '0'); };
        b.append('action', 'aiwc_lazy'); b.append('nonce', c.nonce); b.append('lang', c.lang);
        boxes.forEach(function (box) { b.append('blocks[]', id(box)); });
        fetch(c.ajax, { method: 'POST', body: b, credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (r) {
          if (!r || !r.success) throw new Error('failed');
          boxes.forEach(function (box) { var name = box.getAttribute('data-aiwc-lazy'); put(box, r.data.html[id(box)] || ''); document.dispatchEvent(new CustomEvent('aiwc-lazy-put', { detail: name })); });
        }).catch(function () { boxes.forEach(function (box) { box.innerHTML = ''; var p = document.createElement('p'); p.className = 'description'; p.textContent = c.failed; box.appendChild(p); }); });
      }
      function put(box, html) {
        var tmp = document.createElement('div'); tmp.innerHTML = html;
        var nodes = Array.prototype.slice.call(tmp.childNodes), parent = box.parentNode;
        nodes.forEach(function (n) { parent.insertBefore(n, box); });
        parent.removeChild(box);
        // scripts put in with innerHTML do not run: run each in its place, so document.currentScript still works
        nodes.forEach(function (n) {
          var list = n.nodeType === 1 ? (n.tagName === 'SCRIPT' ? [n] : Array.prototype.slice.call(n.querySelectorAll('script'))) : [];
          list.forEach(function (old) { var s = document.createElement('script'); s.text = old.text; old.parentNode.replaceChild(s, old); });
        });
      }
    })(<?php echo wp_json_encode($data); ?>);
    </script>
    <?php
}
