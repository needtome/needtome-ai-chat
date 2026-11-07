<?php
/**
 * Blocks of the settings screen that read kra.name (each call takes a second or two) load after the page: the page shows
 * a spinner in their place and fetches each block's HTML from admin-ajax, so the screen opens at once instead of
 * waiting for every call. Their controls are initialized by delegated admin assets after insertion.
 */

if (! defined('ABSPATH')) exit;

/** The blocks that can load this way: name => renderer (the number is the step number, when the block has one). */
function aiworker_chat_lazy_blocks(): array {
    return [
        'usage' => static function (int $n = 0): string { return aiworker_chat_usage_block(); },
        'chat' => static function (int $n): string { return aiworker_chat_chat_step($n); },
        'payment' => static function (int $n): string { return aiworker_chat_payment_step($n); },
        'channels' => static function (int $n = 0): string { return aiworker_chat_channels_block(); },
        'answer' => static function (int $n = 0): string { return aiworker_chat_answer_mode_block(); },
        'sources' => static function (): string { return aiworker_chat_kb_remote_block(); },
    ];
}

/** The placeholder: a spinner where the block will be. $card: the block is a card of its own (a step). */
function aiworker_chat_lazy(string $block, int $number = 0, bool $card = false): void {
    echo '<div class="aiworker-chat-lazy'.($card ? ' aiworker-chat-card' : '').'" data-aiworker-chat-lazy="'.esc_attr($block).'" data-n="'.esc_attr((string) $number).'">'
        .'<p class="aiworker-chat-aw__loading"><i class="aiworker-chat-aw__spin" aria-hidden="true"></i> '.esc_html__('Loading from kra.name…', 'needtome-ai-chat').'</p></div>';
}

/** The kra.name GETs a block needs that are not cached on this site: [[path, query], …]. */
function aiworker_chat_lazy_calls(string $block): array {
    $chat = aiworker_chat_linked_id();
    $lang = aiworker_chat_wizard_lang();
    $me = get_transient('aiworker_chat_me_'.$lang) ? [] : [['/plugin/me', ['lang' => $lang]]];
    // a chat known only by its key has no chat_id: kra.name answers chat-scoped calls with 0 by 422, the usage card falls back to /plugin/me
    if ($chat <= 0) return $block === 'usage' || $block === 'payment' ? $me : [];
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
    check_ajax_referer('aiworker_chat_lazy', 'nonce');
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
    foreach ($wanted as $id => [$name, $number]) $html[$id] = (string) $known[$name]($number);
    wp_send_json_success(['html' => $html]);
}
add_action('wp_ajax_aiworker_chat_lazy', 'aiworker_chat_lazy_ajax');
