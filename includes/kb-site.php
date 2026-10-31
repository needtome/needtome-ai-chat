<?php
/**
 * Site-wide content for the chat's knowledge base, besides posts: the text widgets of each widget area (sidebars,
 * footers) and the ACF options pages (contacts, opening hours and similar settings of the site). Each widget area
 * and each options page is one document; they are few, so every pass sends them all.
 */

if (! defined('ABSPATH')) exit;

/** Shorter texts (a «Follow us» line, a lone heading) only clutter the search, so they are not sent. */
const AIWORKER_CHAT_KB_SITE_MIN_CHARS = 40;

/** Widget areas with text: sidebar id => ['name' => …, 'text' => …]. Text, Custom HTML and block widgets are read. */
function aiworker_chat_kb_widget_areas(?array $kb = null): array {
    global $wp_registered_sidebars;
    $kb = $kb ?? aiworker_chat_kb_options();
    $default = empty($kb['all_langs']) ? aiworker_chat_kb_default_lang() : null;
    $out = [];
    foreach ((array) get_option('sidebars_widgets', []) as $sidebar => $widgets) {
        if ($sidebar === 'wp_inactive_widgets' || $sidebar === 'array_version' || ! is_array($widgets)) continue;
        $texts = [];
        foreach ($widgets as $widgetId) {
            if (! preg_match('/^(text|custom_html|block)-(\d+)$/', (string) $widgetId, $m)) continue;
            $instance = ((array) get_option('widget_'.$m[1], []))[(int) $m[2]] ?? null;
            if (! is_array($instance)) continue;
            // Polylang can show a widget in one language only: like a translation, it goes only when every language is sent
            if ($default !== null && ! empty($instance['pll_lang']) && $instance['pll_lang'] !== $default) continue;
            $html = $m[1] === 'block' ? do_blocks((string) ($instance['content'] ?? '')) : (string) ($instance[$m[1] === 'text' ? 'text' : 'content'] ?? '');
            if ($m[1] === 'text' && ! empty($instance['filter']) && $instance['filter'] !== 'content') $html = wpautop($html);
            $text = aiworker_chat_kb_html_to_text(strip_shortcodes($html));
            $title = aiworker_chat_kb_text($instance['title'] ?? '');
            if ($text === '') continue;
            $texts[] = ($title !== '' ? '## '.$title."\n\n" : '').$text;
        }
        if (! $texts) continue;
        $name = (string) ($wp_registered_sidebars[$sidebar]['name'] ?? $sidebar);
        $out[$sidebar] = ['name' => $name, 'text' => implode("\n\n", $texts)];
    }
    return $out;
}

/** ACF options pages that have fields: slug => ['title' => …, 'post_id' => …, 'fields' => [field, …]]. */
function aiworker_chat_kb_acf_option_pages(): array {
    if (! function_exists('acf_get_options_pages') || ! function_exists('acf_get_field_groups') || ! function_exists('acf_get_fields')) return [];
    $out = [];
    foreach ((array) acf_get_options_pages() as $slug => $page) {
        $fields = [];
        foreach ((array) acf_get_field_groups(['options_page' => $slug]) as $group) $fields = array_merge($fields, (array) acf_get_fields($group));
        if ($fields) $out[(string) $slug] = ['title' => (string) ($page['page_title'] ?? $page['menu_title'] ?? $slug), 'post_id' => (string) ($page['post_id'] ?? 'options'), 'fields' => $fields];
    }
    return $out;
}

/** Whether there is anything site-wide to offer on this site (the settings card shows the rows only then). */
function aiworker_chat_kb_site_available(): array {
    return ['widgets' => (bool) aiworker_chat_kb_widget_areas(), 'acf_options' => (bool) aiworker_chat_kb_acf_option_pages()];
}

/** The site-wide documents chosen in the settings, each with its id «widgets:<area>» or «acf-options:<page>». */
function aiworker_chat_kb_site_docs(?array $kb = null): array {
    $kb = $kb ?? aiworker_chat_kb_options();
    $docs = [];
    $lang = aiworker_chat_kb_default_lang();
    $doc = static function (string $id, string $title, string $content) use ($lang): ?array {
        $content = trim($content);
        if (aiworker_chat_kb_chars($content) < AIWORKER_CHAT_KB_SITE_MIN_CHARS) return null;
        return ['id' => $id, 'url' => home_url('/'), 'title' => $title, 'lang' => $lang, 'type' => 'site', 'content' => '# '.$title."\n\n".$content];
    };
    if (! empty($kb['widgets'])) {
        foreach (aiworker_chat_kb_widget_areas($kb) as $sidebar => $area) {
            /* translators: %s: name of the widget area, e.g. «Footer» */
            $d = $doc('widgets:'.$sidebar, sprintf(__('Site widgets: %s', 'aiworker-chat'), $area['name']), $area['text']);
            if ($d) $docs[] = $d;
        }
    }
    if (! empty($kb['acf_options']) && function_exists('get_field_object')) {
        foreach (aiworker_chat_kb_acf_option_pages() as $slug => $page) {
            $lines = [];
            foreach ($page['fields'] as $field) {
                if (empty($field['name'])) continue;
                $object = get_field_object((string) $field['name'], $page['post_id']);
                if ($object) $lines = array_merge($lines, aiworker_chat_kb_acf_lines($object, (string) (($object['label'] ?? '') !== '' ? $object['label'] : $field['name'])));
            }
            $d = $doc('acf-options:'.$slug, $page['title'], implode("\n", $lines));
            if ($d) $docs[] = $d;
        }
    }
    return $docs;
}

/**
 * Worker step: sends every chosen site-wide document and deletes the ones sent before that are gone now. Returns
 * the ids now at kra.name, or null after a failure (recorded and rescheduled).
 */
function aiworker_chat_kb_site_step(array $kb, array &$rejected, string $token): ?array {
    $docs = aiworker_chat_kb_site_docs($kb);
    $ids = array_column($docs, 'id');
    $parts = [];
    foreach ($docs as $doc) $parts = array_merge($parts, aiworker_chat_kb_parts($doc));
    if ($parts) { $r = aiworker_chat_kb_send_docs($parts, $rejected, $token); if ($r !== '') { aiworker_chat_kb_fail($r, ''); return null; } }
    $gone = array_values(array_diff((array) (aiworker_chat_kb_state()['site_ids'] ?? []), $ids));
    if ($gone) {
        if (! aiworker_chat_kb_lock_touch($token)) return null;
        [$code, , $err] = aiworker_chat_kb_request('POST', 'documents/delete', ['site' => home_url('/'), 'ids' => $gone]);
        if ($err !== '') { aiworker_chat_kb_fail($code === 401 ? 'auth' : ($code === 429 ? 'retry:60' : 'error'), $err); return null; }
    }
    aiworker_chat_kb_state(['site_ids' => $ids]);
    return $ids;
}

/** Queues the site-wide documents for sending. */
function aiworker_chat_kb_enqueue_site(): void {
    $kb = aiworker_chat_kb_options();
    if (! $kb['enabled'] || (empty($kb['widgets']) && empty($kb['acf_options']) && ! aiworker_chat_kb_state()['site_ids'])) return;
    add_option(AIWORKER_CHAT_KB_INBOX.str_replace('-', '', wp_generate_uuid4()), ['ids' => [], 'reconcile' => 0, 'site' => 1], '', false);
    if (! wp_next_scheduled('aiworker_chat_kb_sync')) wp_schedule_single_event(time() + 30, 'aiworker_chat_kb_sync');
}

// Widgets are edited in Appearance → Widgets or the Customizer; both save these options.
foreach (['sidebars_widgets', 'widget_text', 'widget_custom_html', 'widget_block'] as $aiworker_chat_widget_option) {
    add_action('update_option_'.$aiworker_chat_widget_option, 'aiworker_chat_kb_enqueue_site');
}
// An ACF options page was saved (its post id is a string such as «options»).
add_action('acf/save_post', static function ($postId): void {
    if (! is_numeric($postId) && is_string($postId) && strpos($postId, 'term_') !== 0 && strpos($postId, 'user_') !== 0 && strpos($postId, 'comment_') !== 0) aiworker_chat_kb_enqueue_site();
}, 20);
