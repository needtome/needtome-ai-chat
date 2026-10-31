<?php
/**
 * Knowledge base from this site: the owner picks post types and, for each, the fields (title, content, excerpt,
 * taxonomies, post meta, ACF). Matching published posts are sent to the chat's knowledge base at kra.name, where they
 * are chunked and vectorized. Changes are queued on save/trash/delete and sent by WP-Cron; a daily reconcile removes
 * documents that no longer match. Contract: docs/wordpress-kb-sync-contract.md in the kra.name repository.
 */

if (! defined('ABSPATH')) exit;

const AIWORKER_CHAT_KB_OPTION = 'aiworker_chat_kb';
/** Where the client manages the chat's own knowledge sources at kra.name. */
const AIWORKER_CHAT_KB_PANEL_URL = 'https://kra.name/app/panel/chat';
const AIWORKER_CHAT_KB_QUEUE = 'aiworker_chat_kb_queue';
const AIWORKER_CHAT_KB_STATE = 'aiworker_chat_kb_state';
const AIWORKER_CHAT_KB_BATCH = 25;               // posts per cron run
const AIWORKER_CHAT_KB_REQUEST_DOCS = 50;        // server: max_batch_docs
const AIWORKER_CHAT_KB_REQUEST_BYTES = 1500000;  // server: max_batch_bytes is 2 MB; keep a margin for JSON overhead
const AIWORKER_CHAT_KB_PART_CHARS = 55000;       // server: max_chars_per_doc is 60000; longer posts go in parts
const AIWORKER_CHAT_KB_MAX_PARTS = 200;
const AIWORKER_CHAT_KB_CORE_FIELDS = ['title', 'content', 'excerpt'];

function aiworker_chat_kb_defaults(): array {
    return ['enabled' => 0, 'token' => '', 'types' => [], 'products' => 0, 'all_langs' => 0, 'widgets' => 0, 'acf_options' => 0];
}

function aiworker_chat_kb_options(): array {
    $o = wp_parse_args((array) get_option(AIWORKER_CHAT_KB_OPTION, []), aiworker_chat_kb_defaults());
    $o['types'] = is_array($o['types']) ? $o['types'] : [];
    return $o;
}

/** Public post types that can be sent (attachments and builder internals are left out). */
function aiworker_chat_kb_post_types(): array {
    $types = [];
    foreach (get_post_types(['public' => true], 'objects') as $type) {
        if (in_array($type->name, ['attachment', 'elementor_library', 'e-landing-page'], true)) continue;
        $types[$type->name] = $type->labels->name ?: $type->name;
    }
    return $types;
}

/** ACF fields attached to a post type: name => label (only types that hold text-like values). */
function aiworker_chat_kb_acf_fields(string $postType): array {
    if (! function_exists('acf_get_field_groups') || ! function_exists('acf_get_fields')) return [];
    $skip = ['tab', 'message', 'accordion', 'image', 'gallery', 'file', 'password', 'color_picker', 'google_map', 'oembed'];
    $out = [];
    foreach (acf_get_field_groups() as $group) {
        if (! aiworker_chat_kb_acf_group_applies((array) ($group['location'] ?? []), $postType)) continue;
        foreach ((array) acf_get_fields($group) as $field) {
            if (empty($field['name']) || in_array($field['type'] ?? '', $skip, true) || aiworker_chat_kb_secret_name((string) $field['name'])) continue;
            $out[$field['name']] = ($field['label'] ?? '') !== '' ? $field['label'] : $field['name'];
        }
    }
    return $out;
}

/**
 * Whether an ACF field group can appear on posts of a type: a «post_type» rule for it, or rules that only exist for
 * pages (page type, template, parent…) or posts (format, category…) when no post type is named.
 */
function aiworker_chat_kb_acf_group_applies(array $location, string $postType): bool {
    $pageParams = ['page_type', 'page', 'page_template', 'page_parent'];
    $postParams = ['post', 'post_template', 'post_format', 'post_category', 'post_taxonomy', 'post_status'];
    foreach ($location as $rules) {
        $params = [];
        foreach ((array) $rules as $rule) {
            $param = (string) ($rule['param'] ?? '');
            if ($param === 'post_type') {
                if (($rule['operator'] ?? '==') === '==' && ($rule['value'] ?? '') === $postType) return true;
                continue 2;
            }
            $params[] = $param;
        }
        if ($postType === 'page' && array_intersect($params, $pageParams)) return true;
        if ($postType !== 'page' && array_intersect($params, $postParams)) return true;
    }
    return false;
}

/** Custom field keys used by a post type (private «_» keys and ACF-managed keys are left out). */
function aiworker_chat_kb_meta_keys(string $postType): array {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Core has no API that discovers existing custom-field names; the prepared query is current and capped at 300 results.
    $keys = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
         WHERE p.post_type = %s AND pm.meta_key NOT LIKE %s ORDER BY pm.meta_key LIMIT 300",
        $postType, $wpdb->esc_like('_').'%'
    ));
    $acf = array_keys(aiworker_chat_kb_acf_fields($postType));
    return array_values(array_filter(array_diff(array_map('strval', (array) $keys), $acf), static function ($k) { return ! aiworker_chat_kb_secret_name($k); }));
}

function aiworker_chat_kb_sanitize($input): array {
    $input = is_array($input) ? $input : [];
    $current = aiworker_chat_kb_options();
    $out = aiworker_chat_kb_defaults();
    // a sync token pasted before the AI Worker sign-in took over is kept until removed; a new one is not taken
    $out['token'] = empty($input['token_clear']) ? (string) $current['token'] : '';
    $out['enabled'] = ! empty($input['enabled']) && ($out['token'] !== '' || aiworker_chat_is_connected()) ? 1 : 0;
    $out['all_langs'] = empty($input['all_langs']) ? 0 : 1;
    // the catalog box is shown only with WooCommerce: without it the saved choice is kept
    $out['products'] = aiworker_chat_products_available() ? (! empty($input['products']) ? 1 : 0) : (int) ! empty($current['products']);
    $out['widgets'] = empty($input['widgets']) ? 0 : 1;
    $out['acf_options'] = empty($input['acf_options']) ? 0 : 1;
    $known = aiworker_chat_kb_post_types();
    foreach ((array) ($input['types'] ?? []) as $type => $cfg) {
        if (! isset($known[$type]) || ! is_array($cfg) || empty($cfg['on'])) continue;
        $pick = static function (array $values, array $allowed): array { return array_values(array_intersect(array_map('strval', $values), $allowed)); };
        $out['types'][$type] = [
            'fields' => $pick((array) ($cfg['fields'] ?? []), AIWORKER_CHAT_KB_CORE_FIELDS),
            'tax' => $pick((array) ($cfg['tax'] ?? []), get_object_taxonomies($type)),
            'meta' => $pick((array) ($cfg['meta'] ?? []), aiworker_chat_kb_meta_keys($type)),
            'acf' => $pick((array) ($cfg['acf'] ?? []), array_keys(aiworker_chat_kb_acf_fields($type))),
        ];
    }
    return $out;
}

add_action('admin_init', static function (): void {
    register_setting('aiworker_chat_kb', AIWORKER_CHAT_KB_OPTION, ['type' => 'array', 'sanitize_callback' => 'aiworker_chat_kb_sanitize', 'default' => aiworker_chat_kb_defaults()]);
});

/** A scalar, list or ACF value as one line of text. */
function aiworker_chat_kb_text($value): string {
    if (is_object($value)) {
        if ($value instanceof WP_Post) return get_the_title($value);
        if ($value instanceof WP_Term) return $value->name;
        $value = (array) $value;
    }
    if (is_array($value)) {
        foreach (['title', 'label', 'name', 'post_title'] as $k) if (isset($value[$k]) && is_scalar($value[$k]) && ! isset($value[0])) return trim((string) $value[$k]);
        $parts = array_filter(array_map('aiworker_chat_kb_text', $value), static function ($v) { return $v !== ''; });
        return implode('; ', $parts);
    }
    if (is_bool($value)) return $value ? __('yes', 'needtome-ai-chat') : __('no', 'needtome-ai-chat');
    return trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags((string) $value)));
}

function aiworker_chat_kb_post_lang(int $postId): ?string {
    if (function_exists('pll_get_post_language')) { $l = pll_get_post_language($postId, 'slug'); if ($l) return (string) $l; }
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML owns this documented integration hook.
    $wpml = apply_filters('wpml_post_language_details', null, $postId);
    if (is_array($wpml) && ! empty($wpml['language_code'])) return (string) $wpml['language_code'];
    return null;
}

/** The site's default language (Polylang, WPML), or null on a site with one language. */
function aiworker_chat_kb_default_lang(): ?string {
    if (function_exists('pll_default_language')) { $l = pll_default_language('slug'); return $l ? (string) $l : null; }
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML owns this documented integration hook.
    $l = apply_filters('wpml_default_language', null);
    return is_string($l) && $l !== '' ? $l : null;
}

/** The post's translations in the other languages: language => post id (itself included). */
function aiworker_chat_kb_translation_ids(WP_Post $post): array {
    if (function_exists('pll_get_post_translations')) return array_map('intval', (array) pll_get_post_translations($post->ID));
    $ids = [];
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML owns these documented integration hooks.
    foreach ((array) apply_filters('wpml_active_languages', null, ['skip_missing' => 0]) as $code => $lang) {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
        $id = (int) apply_filters('wpml_object_id', $post->ID, $post->post_type, false, (string) $code);
        if ($id) $ids[(string) $code] = $id;
    }
    return $ids;
}

/**
 * Only the default language is sent (unless «send every language» is on): the knowledge base finds an answer in any
 * language, and each document lists the addresses of its translations so the chat links the visitor's language.
 */
function aiworker_chat_kb_lang_sent(int $postId, array $kb): bool {
    if (! empty($kb['all_langs'])) return true;
    $default = aiworker_chat_kb_default_lang();
    if ($default === null) return true;
    $lang = aiworker_chat_kb_post_lang($postId);
    return $lang === null || $lang === $default;
}

/** Published translations of a post: language => address, at most 10. */
function aiworker_chat_kb_translations(WP_Post $post): array {
    $out = [];
    foreach (aiworker_chat_kb_translation_ids($post) as $lang => $id) {
        if ($id === $post->ID) continue;
        $t = get_post($id);
        if (! $t || $t->post_status !== 'publish' || $t->post_password !== '') continue;
        $out[(string) $lang] = get_permalink($t);
        if (count($out) >= 10) break;
    }
    return $out;
}

/** Field types whose values never go to the knowledge base. */
const AIWORKER_CHAT_KB_SKIP_TYPES = ['password', 'image', 'gallery', 'file', 'google_map', 'oembed', 'user'];

/** Names that look like secrets (password, token, API key…): skipped whatever the field type says. */
function aiworker_chat_kb_secret_name(string $name): bool {
    return (bool) preg_match('/password|passwd|secret|token|api[_-]?key|private[_-]?key|credential/i', $name)
        || (bool) preg_match('/(^|[_\-])(pass|pwd|auth|salt|nonce|pin)($|[_\-])/i', $name);
}

/**
 * An ACF field as «Label: value» lines. Groups, repeaters and flexible content are expanded with their sub-field
 * labels («Group / Sub: value»), so the consultant sees what each value means. Every nested value is checked against
 * its own field definition (flexible content: the row's layout), and values without a definition, protected types
 * (password, files, images…) and secret-looking names are left out at every depth.
 */
function aiworker_chat_kb_acf_lines(array $field, string $label, int $depth = 0): array {
    $type = (string) ($field['type'] ?? '');
    $value = $field['value'] ?? null;
    if ($depth > 3 || $value === null || $value === '' || $value === []) return [];
    if (in_array($type, AIWORKER_CHAT_KB_SKIP_TYPES, true) || aiworker_chat_kb_secret_name((string) ($field['name'] ?? ''))) return [];
    $defs = static function (array $subFields): array {
        $out = [];
        foreach ($subFields as $sub) if (is_array($sub) && ! empty($sub['name'])) $out[$sub['name']] = $sub;
        return $out;
    };
    $expand = static function (array $row, string $prefix, array $subDefs) use ($depth): array {
        $out = [];
        foreach ($row as $key => $v) {
            if ($key === 'acf_fc_layout' || ! isset($subDefs[$key])) continue;   // no definition: type unknown, do not send
            $sub = $subDefs[$key];
            $sub['value'] = $v;
            $out = array_merge($out, aiworker_chat_kb_acf_lines($sub, $prefix.' / '.(($sub['label'] ?? '') !== '' ? $sub['label'] : $key), $depth + 1));
        }
        return $out;
    };
    if ($type === 'group' && is_array($value)) return $expand($value, $label, $defs((array) ($field['sub_fields'] ?? [])));
    if ($type === 'repeater' && is_array($value)) {
        $out = []; $subDefs = $defs((array) ($field['sub_fields'] ?? []));
        foreach (array_values($value) as $i => $row) if (is_array($row)) $out = array_merge($out, $expand($row, $label.' '.($i + 1), $subDefs));
        return $out;
    }
    if ($type === 'flexible_content' && is_array($value)) {
        $layouts = [];
        foreach ((array) ($field['layouts'] ?? []) as $layout) if (is_array($layout) && ! empty($layout['name'])) $layouts[$layout['name']] = $defs((array) ($layout['sub_fields'] ?? []));
        $out = [];
        foreach (array_values($value) as $i => $row) {
            if (! is_array($row)) continue;
            $subDefs = $layouts[(string) ($row['acf_fc_layout'] ?? '')] ?? [];
            $out = array_merge($out, $expand($row, $label.' '.($i + 1), $subDefs));
        }
        return $out;
    }
    if (is_array($value) && $depth > 0 && ! in_array($type, ['checkbox', 'select', 'radio', 'button_group', 'post_object', 'relationship', 'taxonomy', 'link', 'page_link'], true)) return [];
    $text = aiworker_chat_kb_text($value);
    return $text === '' ? [] : [$label.': '.$text];
}

/**
 * The document for one post, or null when the post must not be in the knowledge base (not published, protected, a
 * type that is not selected, or nothing to send).
 */
function aiworker_chat_kb_document(WP_Post $post, ?array $kb = null): ?array {
    $kb = $kb ?? aiworker_chat_kb_options();
    $cfg = $kb['types'][$post->post_type] ?? null;
    if (! $cfg || $post->post_status !== 'publish' || $post->post_password !== '') return null;
    if ($post->post_type === 'product' && aiworker_chat_products_on($kb)) return null;   // sent to the catalog instead
    if (! aiworker_chat_kb_lang_sent($post->ID, $kb)) return null;   // a translation: listed on its original instead
    // The server chunks by meaning: fields first as «Label: value» lines, an empty line, then the text with
    // «#» headings, paragraphs separated by an empty line and «- » list items.
    $fieldLines = [];
    foreach ((array) $cfg['tax'] as $tax) {
        $terms = get_the_terms($post, $tax);
        if (! $terms || is_wp_error($terms)) continue;
        $taxObj = get_taxonomy($tax);
        $fieldLines[] = ($taxObj ? $taxObj->labels->name : $tax).': '.implode(', ', wp_list_pluck($terms, 'name'));
    }
    foreach ((array) $cfg['meta'] as $key) {
        if (aiworker_chat_kb_secret_name((string) $key)) continue;
        $v = aiworker_chat_kb_text(maybe_unserialize(get_post_meta($post->ID, $key, true)));
        if ($v !== '') $fieldLines[] = $key.': '.$v;
    }
    if ($cfg['acf'] && function_exists('get_field')) {
        $labels = aiworker_chat_kb_acf_fields($post->post_type);
        foreach ((array) $cfg['acf'] as $name) {
            $object = function_exists('get_field_object') ? get_field_object($name, $post->ID) : null;
            $fieldLines = array_merge($fieldLines, $object ? aiworker_chat_kb_acf_lines($object, (string) ($labels[$name] ?? $name)) : []);
        }
    }
    $blocks = [];
    $fields = (array) $cfg['fields'];
    if (in_array('title', $fields, true)) $blocks[] = '# '.aiworker_chat_kb_text(get_the_title($post));
    if (in_array('excerpt', $fields, true) && has_excerpt($post)) $blocks[] = aiworker_chat_kb_text($post->post_excerpt);
    if (in_array('content', $fields, true)) $blocks[] = aiworker_chat_kb_html_to_text(do_blocks(strip_shortcodes((string) $post->post_content)));
    $body = trim(implode("\n\n", array_filter($blocks, static function ($b) { return trim((string) $b) !== ''; })));
    // a bare title (or nothing) is not knowledge
    if (! $fieldLines && ($body === '' || preg_match('/^# [^\n]*$/u', $body))) return null;
    $content = trim(implode("\n", $fieldLines)."\n\n".$body);
    return [
        'id' => 'post:'.$post->ID, 'url' => get_permalink($post), 'title' => get_the_title($post), 'lang' => aiworker_chat_kb_post_lang($post->ID),
        'type' => $post->post_type, 'modified' => get_post_modified_time('c', true, $post), 'content' => $content,
    ] + (empty($kb['all_langs']) && ($translations = aiworker_chat_kb_translations($post)) ? ['translations' => $translations] : []);
}

/**
 * Post HTML as plain text the server can chunk by meaning: «#»…«######» headings, paragraphs separated by an empty
 * line, «- » list items, table cells joined with « | », no HTML.
 */
function aiworker_chat_kb_html_to_text(string $html): string {
    $html = preg_replace('#<(script|style|noscript)\b[^>]*>.*?</\1>#is', '', $html);
    $html = preg_replace_callback('#<h([1-6])\b[^>]*>(.*?)</h\1>#is', static function ($m) {
        $text = trim(preg_replace('/\s+/u', ' ', wp_strip_all_tags($m[2])));
        return $text === '' ? '' : "\n\n".str_repeat('#', (int) $m[1]).' '.$text."\n\n";
    }, $html);
    $html = preg_replace('#<li\b[^>]*>#i', "\n- ", $html);
    $html = preg_replace('#<br\s*/?>#i', "\n", $html);
    $html = preg_replace('#</(td|th)>#i', ' | ', $html);
    $html = preg_replace('#</(p|div|ul|ol|table|tr|blockquote|figure|section|article|pre|dl|dd|dt)>#i', "\n\n", $html);
    $text = html_entity_decode(wp_strip_all_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $lines = array_map(static function ($l) { return trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', rtrim($l, " |\t"))); }, explode("\n", $text));
    $text = preg_replace("/\n{3,}/", "\n\n", implode("\n", $lines));
    return trim($text);
}

/**
 * A document as the parts the server accepts: one part when it fits, otherwise «part»/«parts» pieces split at
 * paragraph (or, failing that, line and word) boundaries, each at most AIWORKER_CHAT_KB_PART_CHARS characters.
 * At most AIWORKER_CHAT_KB_MAX_PARTS parts: $truncated says whether text was left over after them.
 */
function aiworker_chat_kb_parts(array $doc, ?bool &$truncated = null): array {
    $truncated = false;
    $text = (string) $doc['content'];
    $limit = AIWORKER_CHAT_KB_PART_CHARS;
    if (strlen($text) <= $limit || aiworker_chat_kb_chars($text) <= $limit) return [$doc];   // bytes ≥ characters
    // Walk the text by byte offset, one bounded window at a time: a window is at most $limit characters taken with a
    // UTF-8 regex at the current offset, so memory stays proportional to the window, not to the document, and works
    // without mbstring. Separators are ASCII, so byte positions inside a valid UTF-8 window are character-safe cuts.
    $pieces = []; $offset = 0; $total = strlen($text);
    while ($offset < $total && count($pieces) < AIWORKER_CHAT_KB_MAX_PARTS) {
        while ($offset < $total && ctype_space($text[$offset])) $offset++;
        if ($offset >= $total) break;
        if (preg_match('/\G.{1,'.$limit.'}/su', $text, $m, 0, $offset) === 1) {
            $window = $m[0];
        } else {
            $window = substr($text, $offset, $limit);   // invalid UTF-8: bytes, never more characters than the limit
        }
        $take = strlen($window);
        if ($offset + $take < $total) {                 // not the last window: cut at a paragraph, line or space
            $cut = 0;
            foreach (["\n\n", "\n", ' '] as $sep) {
                $at = strrpos($window, $sep);
                if ($at !== false && $at > (int) ($take / 2)) { $cut = $at + strlen($sep); break; }
            }
            if ($cut) $take = $cut;
        }
        $piece = trim(substr($text, $offset, $take));
        if ($piece !== '') $pieces[] = $piece;
        $offset += $take;
        unset($window, $m);
    }
    while ($offset < $total && ctype_space($text[$offset])) $offset++;
    $truncated = $offset < $total;
    $parts = [];
    foreach ($pieces as $i => $piece) $parts[] = array_merge($doc, ['content' => $piece, 'part' => $i + 1, 'parts' => count($pieces)]);
    return $parts;
}

/** Length in characters (not bytes), with or without mbstring. */
function aiworker_chat_kb_chars(string $text): int {
    if (function_exists('mb_strlen')) return mb_strlen($text, 'UTF-8');
    if (function_exists('iconv_strlen')) { $n = @iconv_strlen($text, 'UTF-8'); if ($n !== false) return $n; }
    // count UTF-8 lead bytes (everything except 10xxxxxx continuation bytes) without building arrays
    return strlen($text) - preg_match_all('/[\x80-\xBF]/', $text);
}

/** IDs of every published post of the selected types. */
function aiworker_chat_kb_all_ids(?array $kb = null): array {
    $kb = $kb ?? aiworker_chat_kb_options();
    $types = array_keys($kb['types']);
    if (aiworker_chat_products_on($kb)) $types = array_values(array_diff($types, ['product']));   // sent to the catalog instead
    if (! $types) return [];
    $ids = array_map('intval', get_posts(['post_type' => $types, 'post_status' => 'publish', 'has_password' => false,
        'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => false, 'lang' => '']));
    return array_values(array_filter($ids, static function (int $id) use ($kb): bool { return aiworker_chat_kb_lang_sent($id, $kb); }));
}

/** Whether the content can be sent: a saved sync token or the AI Worker sign-in. */
function aiworker_chat_kb_can_sync(?array $kb = null): bool {
    $kb = $kb ?? aiworker_chat_kb_options();
    return (string) $kb['token'] !== '' || aiworker_chat_is_connected();
}

/** One call to the chat's knowledge API at kra.name. Returns [http code, decoded body or null, error text]. */
function aiworker_chat_kb_request(string $method, string $path, ?array $body = null, int $timeout = 30): array {
    $o = aiworker_chat_options();
    $kb = aiworker_chat_kb_options();
    if (! aiworker_chat_valid_key((string) $o['key'])) return [0, null, __('The chat key is missing.', 'needtome-ai-chat')];
    // a saved sync token (wks_…) as before; otherwise the AI Worker sign-in, whose token kra.name accepts for its account's chats
    $legacy = (string) $kb['token'];
    for ($attempt = 0; $attempt < 2; $attempt++) {
        $bearer = $legacy;
        if ($legacy === '') {
            $bearer = aiworker_chat_oauth_access_token($attempt > 0, $busy);
            if ($bearer === null) return [0, null, $busy === 'unreachable' ? __('AI Worker cannot be reached right now. The connection is kept; try again in a minute.', 'needtome-ai-chat') : __('The connection to AI Worker is being renewed. Try again in a few seconds.', 'needtome-ai-chat')];
            if ($bearer === '') return [0, null, __('Sign in to AI Worker to send the content to the knowledge base.', 'needtome-ai-chat')];
        }
        $args = ['method' => $method, 'timeout' => $timeout, 'redirection' => 0, 'headers' => ['Authorization' => 'Bearer '.$bearer, 'Accept' => 'application/json']];
        if ($body !== null) { $args['headers']['Content-Type'] = 'application/json'; $args['body'] = wp_json_encode($body); }
        $res = wp_remote_request(aiworker_chat_base().'/api/web/'.rawurlencode((string) $o['key']).'/kb/wp/'.ltrim($path, '/'), $args);
        if (is_wp_error($res)) return [0, null, $res->get_error_message()];
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code === 401 && $legacy === '' && $attempt === 0) continue;   // an expired access token: refresh once
        break;
    }
    $data = json_decode((string) wp_remote_retrieve_body($res), true);
    // a server error message is shown on the settings page: keep it short (a database error can carry the whole query)
    $message = is_array($data) && ! empty($data['message']) ? wp_html_excerpt((string) $data['message'], 300, '…') : 'HTTP '.$code;
    return [$code, is_array($data) ? $data : null, $code >= 200 && $code < 300 ? '' : $message];
}

function aiworker_chat_kb_state(array $patch = []): array {
    $state = wp_parse_args((array) get_option(AIWORKER_CHAT_KB_STATE, []), ['last_sync' => 0, 'last_error' => '', 'sent' => 0, 'deleted' => 0, 'site_ids' => []]);
    if ($patch) { $state = array_merge($state, $patch); update_option(AIWORKER_CHAT_KB_STATE, $state, false); }
    return $state;
}

/**
 * The queue. Producers (save/trash/delete hooks, settings, «Sync now») never touch the main queue: each enqueue adds
 * its own «inbox» option with a unique name, an atomic insert that needs no lock. Only the worker — one at a time —
 * moves inbox entries into the main queue and removes what it has sent, so a post changed while the worker talks to
 * kra.name waits in the inbox and is sent on the next run instead of being lost.
 */
const AIWORKER_CHAT_KB_INBOX = 'aiworker_chat_kb_in_';
const AIWORKER_CHAT_KB_RUNNING = 'aiworker_chat_kb_running';

function aiworker_chat_kb_queue_read(): array {
    wp_cache_delete(AIWORKER_CHAT_KB_QUEUE, 'options');
    $q = (array) get_option(AIWORKER_CHAT_KB_QUEUE, []);
    $ids = (array) ($q['ids'] ?? []);
    if ($ids && array_keys($ids) !== range(0, count($ids) - 1)) $ids = array_keys($ids);   // older id => seq format
    return ['ids' => array_values(array_unique(array_map('intval', $ids))), 'reconcile' => (int) ! empty($q['reconcile']),
        'products' => array_values(array_unique(array_map('intval', (array) ($q['products'] ?? [])))), 'preconcile' => (int) ! empty($q['preconcile']), 'pclean' => (int) ($q['pclean'] ?? 1), 'site' => (int) ! empty($q['site'])];
}

/** Inbox entries waiting for the worker: option name => ['ids' => [...], 'reconcile' => 0|1]. */
function aiworker_chat_kb_inbox(): array {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Options API cannot fetch all inbox rows by this prefix; each row is claimed and deleted only after merging.
    $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id", $wpdb->esc_like(AIWORKER_CHAT_KB_INBOX).'%'));
    $out = [];
    foreach ((array) $rows as $row) $out[$row->option_name] = (array) maybe_unserialize($row->option_value);
    return $out;
}

/** Queue post IDs for sending (or deleting) and wake the cron worker shortly. */
function aiworker_chat_kb_enqueue(array $ids, bool $reconcile = false): void {
    $entry = ['ids' => array_values(array_unique(array_map('intval', $ids))), 'reconcile' => $reconcile ? 1 : 0];
    if ($entry['ids'] || $entry['reconcile']) add_option(AIWORKER_CHAT_KB_INBOX.str_replace('-', '', wp_generate_uuid4()), $entry, '', false);
    if (! wp_next_scheduled('aiworker_chat_kb_sync')) wp_schedule_single_event(time() + 30, 'aiworker_chat_kb_sync');
}

/** Worker only: moves inbox entries into the main queue (each entry is deleted only after it is merged). */
function aiworker_chat_kb_take_inbox(): array {
    $q = aiworker_chat_kb_queue_read();
    $inbox = aiworker_chat_kb_inbox();
    if (! $inbox) return $q;
    foreach ($inbox as $entry) {
        $q['ids'] = array_merge($q['ids'], array_map('intval', (array) ($entry['ids'] ?? [])));
        if (! empty($entry['reconcile'])) $q['reconcile'] = 1;
        if (! empty($entry['site'])) $q['site'] = 1;
        $q['products'] = array_merge($q['products'], array_map('intval', (array) ($entry['products'] ?? [])));
        // a new full pass of the catalog starts clean: a rejection in it holds back its reconcile
        if (! empty($entry['preconcile'])) { $q['preconcile'] = 1; $q['pclean'] = 1; }
    }
    $q['ids'] = array_values(array_unique($q['ids']));
    $q['products'] = array_values(array_unique($q['products']));
    update_option(AIWORKER_CHAT_KB_QUEUE, $q, false);
    foreach (array_keys($inbox) as $name) delete_option($name);
    return $q;
}

/** Takes the worker lock; returns its token, or '' when another worker holds a fresh lock. */
function aiworker_chat_kb_lock_take(): string {
    global $wpdb;
    $token = wp_generate_uuid4();
    if (aiworker_chat_option_insert(AIWORKER_CHAT_KB_RUNNING, ['token' => $token, 'at' => time()])) return $token;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The lock needs a fresh atomic read to distinguish a live worker from a stale lock.
    $raw = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", AIWORKER_CHAT_KB_RUNNING));
    $held = (array) maybe_unserialize((string) $raw);
    if (time() - (int) ($held['at'] ?? 0) < 5 * MINUTE_IN_SECONDS) return '';
    // a stale lock (a worker that died): remove exactly that row, then compete for the lock like a new worker
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete makes stale-lock recovery atomic and removes only the value just read.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", AIWORKER_CHAT_KB_RUNNING, (string) $raw));
    wp_cache_delete(AIWORKER_CHAT_KB_RUNNING, 'options');
    return aiworker_chat_option_insert(AIWORKER_CHAT_KB_RUNNING, ['token' => $token, 'at' => time()]) ? $token : '';
}

/**
 * Heartbeat: refreshes this worker's lock so a long run (many parts, a slow kra.name) is not taken for a dead one.
 * Returns false when the lock is no longer this worker's; the worker must then stop without writing the queue.
 */
function aiworker_chat_kb_lock_touch(string $token): bool {
    global $wpdb;
    if ($token === '') return true;   // tests and direct calls without a worker lock
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional update is the worker-lock heartbeat; the options API cannot compare-and-set this lock safely.
    $updated = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value LIKE %s",
        maybe_serialize(['token' => $token, 'at' => time()]), AIWORKER_CHAT_KB_RUNNING, '%'.$wpdb->esc_like($token).'%'));
    wp_cache_delete(AIWORKER_CHAT_KB_RUNNING, 'options');
    if ($updated) return true;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fresh lock read after a failed heartbeat verifies whether the same worker still owns it.
    $raw = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", AIWORKER_CHAT_KB_RUNNING));
    return strpos($raw, $token) !== false;   // same second: nothing changed, still ours
}

/** Releases the worker lock only if it is still this worker's. */
function aiworker_chat_kb_lock_release(string $token): void {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional delete releases only this worker's token and cannot be expressed as an atomic options API call.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", AIWORKER_CHAT_KB_RUNNING, '%'.$wpdb->esc_like($token).'%'));
    wp_cache_delete(AIWORKER_CHAT_KB_RUNNING, 'options');
}

/**
 * Sends documents in requests that respect the server's limits (50 documents, about 2 MB). Returns '' or an error
 * code: 'auth' (bad token: stop until it is replaced), 'retry:<seconds>' (throttled) or 'error' (back off).
 */
function aiworker_chat_kb_send_docs(array $docs, array &$rejected, string $token = ''): string {
    $batch = []; $bytes = 0;
    $flush = static function () use (&$batch, &$bytes, &$rejected, $token): string {
        if (! $batch) return '';
        if (! aiworker_chat_kb_lock_touch($token)) return 'lost';
        [$code, $data, $err] = aiworker_chat_kb_request('POST', 'documents', ['site' => home_url('/'), 'docs' => $batch]);
        $batch = []; $bytes = 0;
        if ($code === 401) return 'auth';
        if ($code === 429) return 'retry:'.max(30, (int) ($data['retry_after'] ?? 60));
        if ($err !== '') return 'error';
        foreach ((array) ($data['results'] ?? []) as $r) if (($r['status'] ?? '') === 'rejected') $rejected[(string) ($r['id'] ?? '')] = (string) ($r['reason'] ?? '');
        return '';
    };
    foreach ($docs as $doc) {
        // last guard: a part over the character limit is never sent (the splitter should not produce one)
        if (aiworker_chat_kb_chars((string) $doc['content']) > AIWORKER_CHAT_KB_PART_CHARS) { $rejected[$doc['id'].(isset($doc['part']) ? '#'.$doc['part'] : '')] = 'too_long'; continue; }
        $size = strlen((string) wp_json_encode($doc));
        if ($batch && (count($batch) >= AIWORKER_CHAT_KB_REQUEST_DOCS || $bytes + $size > AIWORKER_CHAT_KB_REQUEST_BYTES)) { $r = $flush(); if ($r !== '') return $r; }
        $batch[] = $doc; $bytes += $size;
    }
    return $flush();
}

/** Records a failed run and schedules the next attempt (none for a bad token: the owner must enter a new one). */
function aiworker_chat_kb_fail(string $result, string $message): void {
    if ($result === 'lost') return;   // another worker owns the queue now and continues
    if ($result === 'auth') { aiworker_chat_kb_state(['last_error' => __('kra.name rejected the sync token: create a new one on the chat\'s card and save it here.', 'needtome-ai-chat')]); return; }
    $delay = strpos($result, 'retry:') === 0 ? (int) substr($result, 6) : 15 * MINUTE_IN_SECONDS;
    aiworker_chat_kb_state(['last_error' => $message !== '' ? $message : __('kra.name is not reachable; the sync will be retried.', 'needtome-ai-chat')]);
    wp_schedule_single_event(time() + $delay, 'aiworker_chat_kb_sync');
}

/** Cron worker: sends one batch of posts, then reschedules itself while there is work left. */
function aiworker_chat_kb_process(): void {
    $kb = aiworker_chat_kb_options();
    if (! $kb['enabled']) return;
    $token = aiworker_chat_kb_lock_take();
    if ($token === '') {
        // another worker is running: try again later; the inbox keeps everything queued meanwhile
        if (! wp_next_scheduled('aiworker_chat_kb_sync')) wp_schedule_single_event(time() + MINUTE_IN_SECONDS, 'aiworker_chat_kb_sync');
        return;
    }
    try {
        aiworker_chat_kb_run_batch($kb, $token);
    } finally {
        aiworker_chat_kb_lock_release($token);
    }
}

function aiworker_chat_kb_run_batch(array $kb, string $token = ''): void {
    if (! aiworker_chat_kb_lock_touch($token)) return;
    $q = aiworker_chat_kb_take_inbox();
    $batch = array_slice($q['ids'], 0, AIWORKER_CHAT_KB_BATCH);
    $docs = []; $gone = []; $posts = 0; $cut = [];
    foreach ($batch as $id) {
        $post = get_post((int) $id);
        $doc = $post ? aiworker_chat_kb_document($post, $kb) : null;
        if (! $doc) { $gone[] = 'post:'.(int) $id; continue; }
        $docs = array_merge($docs, aiworker_chat_kb_parts($doc, $truncated)); $posts++;
        if ($truncated) $cut[] = $doc['id'];
    }
    $rejected = (array) (aiworker_chat_kb_state()['rejected'] ?? []);
    foreach ($docs as $d) unset($rejected[$d['id'].(isset($d['part']) ? '#'.$d['part'] : '')], $rejected[$d['id']]);
    // a post longer than the parts limit is sent without its tail: the status lists such posts on their own
    $shortened = array_values(array_unique(array_merge(array_diff((array) (aiworker_chat_kb_state()['truncated'] ?? []), array_column($docs, 'id'), $gone), $cut)));
    $before = $rejected;
    if ($docs) { $r = aiworker_chat_kb_send_docs($docs, $rejected, $token); if ($r !== '') { aiworker_chat_kb_fail($r, ''); return; } }
    if ($gone) {
        if (! aiworker_chat_kb_lock_touch($token)) return;
        [$code, , $err] = aiworker_chat_kb_request('POST', 'documents/delete', ['site' => home_url('/'), 'ids' => $gone]);
        if ($err !== '') { aiworker_chat_kb_fail($code === 401 ? 'auth' : ($code === 429 ? 'retry:60' : 'error'), $err); return; }
    }
    $rest = array_slice($q['ids'], count($batch));
    // widgets and options pages go once the posts are through, on their own change or on a full pass
    $siteIds = null;
    if (! $rest && ($q['site'] || $q['reconcile'])) {
        if (! aiworker_chat_kb_lock_touch($token)) return;
        $siteIds = aiworker_chat_kb_site_step($kb, $rejected, $token);
        if ($siteIds === null) return;
        $posts += count($siteIds);
    }
    $reconciled = false;
    if (! $rest && $q['reconcile']) {
        if (! aiworker_chat_kb_lock_touch($token)) return;
        $all = array_merge(array_map(static function ($id) { return 'post:'.$id; }, aiworker_chat_kb_all_ids($kb)), (array) $siteIds);
        [$code, , $err] = aiworker_chat_kb_request('POST', 'documents/reconcile', ['site' => home_url('/'), 'ids' => $all]);
        if ($err !== '') { aiworker_chat_kb_fail($code === 401 ? 'auth' : ($code === 429 ? 'retry:60' : 'error'), $err); return; }
        $reconciled = true;
        // a reconcile removes what is no longer selected, so a deselected post is no longer «sent without its end»
        $shortened = array_values(array_intersect($shortened, $all));
    }
    // only this worker writes the main queue (checked again: a lost lock means another worker owns it now);
    // anything enqueued meanwhile is still in the inbox
    if (! aiworker_chat_kb_lock_touch($token)) return;
    $catalog = aiworker_chat_products_step($kb, $q, $token);
    $catalogFailed = $catalog === null;   // recorded and rescheduled by the step; the products stay queued
    if ($catalogFailed) $catalog = ['products' => $q['products'], 'preconcile' => $q['preconcile'], 'pclean' => $q['pclean']];
    if (! aiworker_chat_kb_lock_touch($token)) return;
    update_option(AIWORKER_CHAT_KB_QUEUE, ['ids' => $rest, 'reconcile' => $reconciled ? 0 : $q['reconcile'], 'site' => $siteIds === null ? $q['site'] : 0] + $catalog, false);
    // «missing_part»: a later part arrived without its part 1 on the server; send the whole post again next run,
    // unless part 1 itself was rejected (e.g. over a limit), which a resend would not fix.
    $resend = [];
    foreach (array_diff_key($rejected, $before) as $docId => $reason) {
        if ($reason !== 'missing_part' || ! preg_match('/^post:(\d+)#\d+$/', (string) $docId, $m)) continue;
        if (! isset($rejected['post:'.$m[1].'#1'])) $resend[(int) $m[1]] = true;
    }
    if ($resend) aiworker_chat_kb_enqueue(array_keys($resend));
    $state = aiworker_chat_kb_state();
    aiworker_chat_kb_state(['last_sync' => time(), 'last_error' => $catalogFailed ? $state['last_error'] : '', 'sent' => (int) $state['sent'] + $posts, 'deleted' => (int) $state['deleted'] + count($gone), 'rejected' => array_slice($rejected, -50, null, true), 'truncated' => array_slice($shortened, -50)]);
    delete_transient('aiworker_chat_kb_status');
    if ($rest || ($q['reconcile'] && ! $reconciled) || $resend || (! $catalogFailed && ($catalog['products'] || $catalog['preconcile'])) || aiworker_chat_kb_inbox()) wp_schedule_single_event(time() + 5, 'aiworker_chat_kb_sync');
}

/** The source's state at kra.name (documents, characters, vectors), cached for five minutes. */
function aiworker_chat_kb_remote_status(): ?array {
    $cached = get_transient('aiworker_chat_kb_status');
    if (is_array($cached)) return $cached;
    [$code, $data] = aiworker_chat_kb_request('GET', 'status?site='.rawurlencode(home_url('/')));
    $status = $code === 200 && is_array($data) ? $data : ['ok' => false, 'code' => $code];
    set_transient('aiworker_chat_kb_status', $status, 5 * MINUTE_IN_SECONDS);
    return $status;
}

add_action('aiworker_chat_kb_sync', 'aiworker_chat_kb_process');

/** Daily full check: every selected post again plus removal of documents the site no longer exports. */
add_action('aiworker_chat_kb_daily', static function (): void {
    $kb = aiworker_chat_kb_options();
    if ($kb['enabled']) aiworker_chat_kb_enqueue(aiworker_chat_kb_all_ids($kb), true);
    if (aiworker_chat_products_on($kb)) aiworker_chat_products_enqueue(aiworker_chat_products_all_ids($kb), true);
});
add_action('init', static function (): void {
    if (aiworker_chat_kb_options()['enabled'] && ! wp_next_scheduled('aiworker_chat_kb_daily')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'aiworker_chat_kb_daily');
});

// Content changes: a post that stops matching is queued too, so its document is removed.
add_action('save_post', static function (int $postId, WP_Post $post): void {
    if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) return;
    $kb = aiworker_chat_kb_options();
    if (! $kb['enabled'] || ! isset($kb['types'][$post->post_type])) return;
    $ids = [$postId];
    // a translation changed: its original lists the translations' addresses, so it is sent again too
    if (empty($kb['all_langs']) && ($default = aiworker_chat_kb_default_lang()) !== null) $ids[] = (int) (aiworker_chat_kb_translation_ids($post)[$default] ?? 0);
    aiworker_chat_kb_enqueue(array_values(array_filter(array_unique($ids))));
}, 20, 2);
foreach (['trashed_post', 'deleted_post'] as $aiworker_chat_post_action) {
    add_action($aiworker_chat_post_action, static function (int $postId): void { if (aiworker_chat_kb_options()['enabled']) aiworker_chat_kb_enqueue([$postId]); });
}
// A new selection of types or fields: send everything again and drop what is no longer selected.
// (the very first save adds the option, later saves update it)
// The catalog: switched on, everything is sent; switched off, a full pass with no products removes it at kra.name.
add_action('update_option_'.AIWORKER_CHAT_KB_OPTION, static function ($old, $new): void {
    if (! empty($new['enabled'])) aiworker_chat_kb_enqueue(aiworker_chat_kb_all_ids((array) $new), true);
    if (! empty($new['enabled']) && (aiworker_chat_products_on((array) $new) || ! empty(((array) $old)['products']))) aiworker_chat_products_enqueue(aiworker_chat_products_all_ids((array) $new), true);
}, 10, 2);
add_action('add_option_'.AIWORKER_CHAT_KB_OPTION, static function ($option, $value): void {
    if (! empty($value['enabled'])) aiworker_chat_kb_enqueue(aiworker_chat_kb_all_ids((array) $value), true);
    if (aiworker_chat_products_on((array) $value)) aiworker_chat_products_enqueue(aiworker_chat_products_all_ids((array) $value), true);
}, 10, 2);

add_action('admin_post_aiwc_kb_sync', static function (): void {
    if (! current_user_can('manage_options')) wp_die('', '', ['response' => 403]);
    check_admin_referer('aiwc_kb_sync');
    aiworker_chat_kb_enqueue(aiworker_chat_kb_all_ids(), true);
    if (aiworker_chat_products_on()) aiworker_chat_products_enqueue(aiworker_chat_products_all_ids(), true);
    wp_safe_redirect(Aiworker_Chat_Profile::url('aiworker-chat', ['tab' => 'kb', 'aiwc_kb' => 'queued']));
    exit;
});

/** The settings card «Knowledge base from this site». */
/** The «Knowledge base» tab: what the chat learns from kra.name, and the form that adds this site's own content. */
function aiworker_chat_kb_tab(): void {
    ?>
    <div class="aiwc-main aiwc-kb-tab">
      <div class="aiwc-card aiwc-kb-intro">
        <p class="aiwc-lead"><?php echo wp_kses(sprintf(
            /* translators: %s: link to the chat's knowledge sources at kra.name */
            __('The consultant\'s knowledge sources (pages of your site, files, Google Sheets and Docs, questions and answers) are set up in the chat\'s settings at %s, card «What the AI answers from». Here you can also send content straight from this site\'s database: it stays up to date by itself whenever you edit the site.', 'needtome-ai-chat'),
            '<a href="'.esc_url(AIWORKER_CHAT_KB_PANEL_URL).'" target="_blank" rel="noopener">kra.name</a>'), ['a' => ['href' => [], 'target' => [], 'rel' => []]]); ?></p>
        <p class="description"><?php esc_html_e('If you send this site\'s content from here, do not also add the same site as a «Site» source at kra.name (or leave out the sections sent from here): the knowledge base does not merge duplicates, so the same text would take space twice.', 'needtome-ai-chat'); ?></p>
      </div>
      <?php if (function_exists('aiworker_chat_lazy') && (int) get_option(AIWORKER_CHAT_LINKED_OPTION, 0) > 0 && aiworker_chat_is_connected()) { aiworker_chat_lazy('answer', 0, true); aiworker_chat_lazy('sources', 0, true); } ?>
      <form method="post" action="options.php" class="aiwc-main">
        <?php settings_fields('aiworker_chat_kb'); ?>
        <?php aiworker_chat_kb_card(); ?>
        <div class="aiwc-save"><?php submit_button(__('Save settings', 'needtome-ai-chat'), 'primary large', 'submit', false); ?></div>
      </form>
    </div>
    <?php
}

function aiworker_chat_kb_card(): void {
    $kb = aiworker_chat_kb_options();
    $name = esc_attr(AIWORKER_CHAT_KB_OPTION);
    $types = aiworker_chat_kb_post_types();
    $state = aiworker_chat_kb_state();
    $coreLabels = ['title' => __('Title', 'needtome-ai-chat'), 'content' => __('Content', 'needtome-ai-chat'), 'excerpt' => __('Excerpt', 'needtome-ai-chat')];
    $box = static function (string $field, string $value, string $label, bool $on): string {
        return '<label class="aiwc-check aiwc-kb__opt"><input type="checkbox" name="'.esc_attr($field).'[]" value="'.esc_attr($value).'" '.checked($on, true, false).'> '.esc_html($label).'</label>';
    };
    ?>
    <section class="aiwc-card" id="aiwc-kb">
      <h2><?php esc_html_e('Content from this site', 'needtome-ai-chat'); ?></h2>
      <p class="aiwc-lead"><?php esc_html_e('Choose the post types and fields the consultant should know. Published posts of these types are sent to the chat\'s knowledge base at kra.name and vectorized there; changes are sent automatically.', 'needtome-ai-chat'); ?></p>
      <?php if (sanitize_key(wp_unslash($_GET['aiwc_kb'] ?? '')) === 'queued'): // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only picks the notice shown after the redirect; nothing is changed. ?><div class="notice notice-success inline"><p><?php esc_html_e('Full sync is queued; WordPress runs it in the background with its scheduled tasks (WP-Cron), usually within a minute while the site has visitors.', 'needtome-ai-chat'); ?></p></div><?php endif; ?>
      <?php
      $canSync = aiworker_chat_kb_can_sync($kb);
      if ($kb['token'] !== '') aiworker_chat_row(__('Sync token', 'needtome-ai-chat'),
          '<p>'.esc_html__('A sync token from the kra.name panel is saved and used.', 'needtome-ai-chat').' <label class="aiwc-check"><input type="checkbox" name="'.$name.'[token_clear]" value="1"> '.esc_html__('Remove', 'needtome-ai-chat').'</label></p>',
          esc_html__('Without it the content is sent through the AI Worker sign-in.', 'needtome-ai-chat'));
      elseif (! $canSync) aiworker_chat_row(__('AI Worker', 'needtome-ai-chat'),
          '<p>'.esc_html__('Sign in to AI Worker to send this site\'s content to the chat\'s knowledge base.', 'needtome-ai-chat').'</p>'
          .'<p>'.aiworker_chat_signin_link('kb', __('Sign in to AI Worker', 'needtome-ai-chat')).'</p>');
      aiworker_chat_row(__('Sync', 'needtome-ai-chat'),
          '<label class="aiwc-check"><input type="checkbox" name="'.$name.'[enabled]" value="1" '.checked((bool) $kb['enabled'], true, false).disabled(! $canSync, true, false).'> '.esc_html__('Send the selected content to the chat\'s knowledge base', 'needtome-ai-chat').'</label>');
      $defaultLang = aiworker_chat_kb_default_lang();
      if ($defaultLang !== null) aiworker_chat_row(__('Languages', 'needtome-ai-chat'),
          '<label class="aiwc-check"><input type="checkbox" name="'.$name.'[all_langs]" value="1" '.checked(! empty($kb['all_langs']), true, false).'> '.esc_html__('Translations differ in content — send every language', 'needtome-ai-chat').'</label>',
          /* translators: %s: the site's default language code */
          esc_html(sprintf(__('Off: only the posts in the default language (%s) are sent, each with the addresses of its translations. The chat finds the answer whatever the visitor\'s language and links the page in that language.', 'needtome-ai-chat'), $defaultLang)));
      if (aiworker_chat_products_available()) aiworker_chat_row(__('Product catalog', 'needtome-ai-chat'),
          '<label class="aiwc-check"><input type="checkbox" name="'.$name.'[products]" value="1" '.checked((bool) $kb['products'], true, false).'> '.esc_html__('Send the WooCommerce products to the chat\'s catalog', 'needtome-ai-chat').'</label>',
          esc_html__('Prices, sale prices, stock, categories, attributes and variations, so the consultant can pick products by exact characteristics. Products then do not go as separate documents: the «Products» row below chooses which descriptions and extra fields (taxonomies, ACF, custom fields) are added to each product.', 'needtome-ai-chat'));
      ?>
      <div class="aiwc-kb__types">
        <?php foreach ($types as $type => $label):
            $cfg = $kb['types'][$type] ?? null; $on = (bool) $cfg; $field = $name.'[types]['.esc_attr($type).']';
            $count = (int) (wp_count_posts($type)->publish ?? 0);
            $tax = get_object_taxonomies($type, 'objects'); $acf = aiworker_chat_kb_acf_fields($type); $meta = aiworker_chat_kb_meta_keys($type); ?>
          <details class="aiwc-kb__type"<?php echo $on ? ' open' : ''; ?>>
            <summary><label class="aiwc-check"><input type="checkbox" name="<?php echo esc_attr($field); ?>[on]" value="1" <?php checked($on); ?>> <strong><?php echo esc_html($label); ?></strong></label>
              <span class="description"><?php echo esc_html(sprintf(/* translators: %d: number of published posts */ _n('%d published', '%d published', $count, 'needtome-ai-chat'), $count)); ?></span></summary>
            <div class="aiwc-kb__fields">
              <div><b><?php esc_html_e('Fields', 'needtome-ai-chat'); ?></b>
                <?php foreach ($coreLabels as $k => $l) echo wp_kses($box($field.'[fields]', $k, $l, $on ? in_array($k, (array) $cfg['fields'], true) : $k !== 'excerpt'), aiworker_chat_admin_html_allowed()); ?></div>
              <?php if ($tax): ?><div><b><?php esc_html_e('Taxonomies', 'needtome-ai-chat'); ?></b>
                <?php foreach ($tax as $t) if ($t->public || $t->show_ui) echo wp_kses($box($field.'[tax]', $t->name, $t->labels->name, $on && in_array($t->name, (array) $cfg['tax'], true)), aiworker_chat_admin_html_allowed()); ?></div><?php endif; ?>
              <?php if ($acf): ?><div><b><?php esc_html_e('ACF fields', 'needtome-ai-chat'); ?></b>
                <?php foreach ($acf as $k => $l) echo wp_kses($box($field.'[acf]', $k, $l, $on && in_array($k, (array) $cfg['acf'], true)), aiworker_chat_admin_html_allowed()); ?></div><?php endif; ?>
              <?php if ($meta): ?><div><b><?php esc_html_e('Custom fields', 'needtome-ai-chat'); ?></b>
                <?php foreach ($meta as $k) echo wp_kses($box($field.'[meta]', $k, $k, $on && in_array($k, (array) $cfg['meta'], true)), aiworker_chat_admin_html_allowed()); ?></div><?php endif; ?>
            </div>
          </details>
        <?php endforeach; ?>
      </div>
      <?php
      // shown on every site, so the owner knows these sources exist; what is found here now is named in the hint
      echo '<h3 class="aiwc-kb__more">'.esc_html__('Other data of the site', 'needtome-ai-chat').'</h3>';
      $areas = array_column(aiworker_chat_kb_widget_areas($kb), 'name');
      aiworker_chat_row(__('Widgets', 'needtome-ai-chat'),
          '<label class="aiwc-check"><input type="checkbox" name="'.$name.'[widgets]" value="1" '.checked(! empty($kb['widgets']), true, false).'> '.esc_html__('Send the text of the widgets (sidebars, footer)', 'needtome-ai-chat').'</label>',
          esc_html__('Text, Custom HTML and block widgets; each widget area is one document. Lists of posts, menus and very short texts are left out.', 'needtome-ai-chat').' '
          .($areas ? esc_html(sprintf(/* translators: %s: names of the widget areas */ __('Found now: %s.', 'needtome-ai-chat'), implode(', ', $areas)))
              : esc_html__('No widget with text on this site now: once you add one in Appearance → Widgets, it is sent.', 'needtome-ai-chat')));
      $acf = function_exists('acf_get_options_pages');
      $optionPages = aiworker_chat_kb_acf_option_pages();
      aiworker_chat_row(__('ACF options pages', 'needtome-ai-chat'),
          '<label class="aiwc-check'.($acf ? '' : ' is-off').'"><input type="checkbox" name="'.$name.'[acf_options]" value="1" '.checked(! empty($kb['acf_options']), true, false).disabled(! $acf && empty($kb['acf_options']), true, false).'> '.esc_html__('Send the fields of the site settings pages', 'needtome-ai-chat').'</label>',
          esc_html__('Contacts, opening hours, delivery terms and other data set once for the whole site. Password, file and image fields are not sent.', 'needtome-ai-chat').' '
          .(! $acf ? esc_html__('Needs the Advanced Custom Fields plugin with options pages; it is not active on this site.', 'needtome-ai-chat')
              : ($optionPages ? esc_html(sprintf(/* translators: %s: names of the ACF options pages */ __('Found now: %s.', 'needtome-ai-chat'), implode(', ', array_column($optionPages, 'title'))))
                  : esc_html__('ACF is active, but no options page has fields yet.', 'needtome-ai-chat'))));
      echo '<p class="description">'.esc_html__('Field values of the posts themselves (ACF fields, custom fields, taxonomies) are chosen in each post type above.', 'needtome-ai-chat').'</p>';
      $waiting = aiworker_chat_kb_queue_read()['ids'];
      $waitingProducts = aiworker_chat_kb_queue_read()['products'];
      foreach (aiworker_chat_kb_inbox() as $entry) {
          $waiting = array_merge($waiting, array_map('intval', (array) ($entry['ids'] ?? [])));
          $waitingProducts = array_merge($waitingProducts, array_map('intval', (array) ($entry['products'] ?? [])));
      }
      $queued = count(array_unique($waiting));
      $status = '';
      if ($kb['enabled']) {
          $status = $state['last_sync'] ? sprintf(/* translators: 1: date, 2: sent count, 3: removed count */ __('Last sync: %1$s. Sent: %2$d, removed: %3$d.', 'needtome-ai-chat'), wp_date(get_option('date_format').' '.get_option('time_format'), (int) $state['last_sync']), (int) $state['sent'], (int) $state['deleted']) : __('Not synced yet.', 'needtome-ai-chat');
          if ($queued) $status .= ' '.sprintf(/* translators: %d: posts waiting */ _n('%d post is waiting to be sent.', '%d posts are waiting to be sent.', $queued, 'needtome-ai-chat'), $queued);
          if ($state['last_error'] !== '') $status .= ' '.sprintf(/* translators: %s: error */ __('Last error: %s', 'needtome-ai-chat'), $state['last_error']);
          $rejectedCount = count((array) ($state['rejected'] ?? []));
          if ($rejectedCount) $status .= ' '.sprintf(/* translators: 1: number of posts, 2: reasons */ __('Not accepted by kra.name: %1$d (%2$s).', 'needtome-ai-chat'), $rejectedCount, implode(', ', array_unique(array_values((array) $state['rejected']))));
          $truncatedCount = count((array) ($state['truncated'] ?? []));
          if ($truncatedCount) $status .= ' '.sprintf(/* translators: %d: number of posts */ __('Sent without their end, longer than the knowledge base takes: %d.', 'needtome-ai-chat'), $truncatedCount);
          $remote = $canSync ? aiworker_chat_kb_remote_status() : null;
          if ($remote && ! empty($remote['ok'])) {
              $indexing = (array) ($remote['indexing'] ?? []);
              $status .= ' '.sprintf(/* translators: 1: documents, 2: characters, 3: vector state, 4: embedded chunks, 5: all chunks */ __('In the chat\'s knowledge base: %1$d documents, %2$s characters; vectors: %3$s (%4$d of %5$d parts).', 'needtome-ai-chat'),
                  (int) ($remote['docs'] ?? 0), number_format_i18n((int) ($remote['chars'] ?? 0)), (string) ($indexing['state'] ?? '—'), (int) ($indexing['embedded'] ?? 0), (int) ($indexing['chunks'] ?? 0));
              if (! empty($indexing['dropped'])) $status .= ' '.sprintf(/* translators: %d: parts over the plan limit */ _n('%d part is over the plan\'s knowledge limit and is not used.', '%d parts are over the plan\'s knowledge limit and are not used.', (int) $indexing['dropped'], 'needtome-ai-chat'), (int) $indexing['dropped']);
          }
          $catalogStatus = $canSync ? aiworker_chat_products_status_text($kb, $state, count(array_unique($waitingProducts))) : '';
          if ($catalogStatus !== '') $status .= ' '.$catalogStatus;
      }
      $syncUrl = wp_nonce_url(admin_url('admin-post.php?action=aiwc_kb_sync'), 'aiwc_kb_sync');
      aiworker_chat_row(__('Status', 'needtome-ai-chat'), '<p>'.esc_html($status ?: __('Sync is off.', 'needtome-ai-chat')).'</p>'
          .'<p><button type="button" class="button aiwc-kb__preview-open">'.esc_html__('Preview what will be sent', 'needtome-ai-chat').'</button> '
          .($kb['enabled'] ? '<a class="button" href="'.esc_url($syncUrl).'">'.esc_html__('Sync everything now', 'needtome-ai-chat').'</a>' : '').'</p>');
      ?>
      <dialog class="aiwc-modal" id="aiwc-kb-preview" aria-labelledby="aiwc-kb-preview-title">
        <div class="aiwc-modal__head"><h2 id="aiwc-kb-preview-title"><?php esc_html_e('What will be sent to the knowledge base', 'needtome-ai-chat'); ?></h2>
          <button type="button" class="aiwc-modal__close" aria-label="<?php esc_attr_e('Close', 'needtome-ai-chat'); ?>">&times;</button></div>
        <div class="aiwc-modal__body" aria-live="polite"></div>
      </dialog>
      <?php aiworker_chat_kb_preview_script(); ?>
    </section>
    <?php
}

/** AJAX: the preview for the choice in the form now, saved or not. */
function aiworker_chat_kb_preview_ajax(): void {
    if (! current_user_can('manage_options')) wp_send_json_error(null, 403);
    check_ajax_referer('aiwc_kb_preview', 'nonce');
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked field by field against the known types, fields and taxonomies.
    $input = isset($_POST[AIWORKER_CHAT_KB_OPTION]) && is_array($_POST[AIWORKER_CHAT_KB_OPTION]) ? wp_unslash($_POST[AIWORKER_CHAT_KB_OPTION]) : [];
    unset($input['token_clear']);
    $kb = aiworker_chat_kb_sanitize($input);
    ob_start();
    aiworker_chat_kb_preview($kb);
    wp_send_json_success(['html' => ob_get_clean()]);
}
add_action('wp_ajax_aiwc_kb_preview', 'aiworker_chat_kb_preview_ajax');

/** Opens the preview popup with the knowledge-base fields of the settings form. */
function aiworker_chat_kb_preview_script(): void {
    $data = ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('aiwc_kb_preview'), 'field' => AIWORKER_CHAT_KB_OPTION.'[', 'loading' => __('Loading…', 'needtome-ai-chat'), 'failed' => __('The preview could not be loaded. Try again.', 'needtome-ai-chat')];
    ?>
    <script>
    (function () {
      var c = <?php echo wp_json_encode($data); ?>, dlg = document.getElementById('aiwc-kb-preview'), body = dlg.querySelector('.aiwc-modal__body');
      dlg.querySelector('.aiwc-modal__close').addEventListener('click', function () { dlg.close(); });
      dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
      document.querySelector('.aiwc-kb__preview-open').addEventListener('click', function () {
        var form = this.closest('form'), data = new FormData();
        data.append('action', 'aiwc_kb_preview'); data.append('nonce', c.nonce);
        new FormData(form).forEach(function (v, k) { if (k.indexOf(c.field) === 0) data.append(k, v); });
        body.textContent = c.loading; dlg.showModal();
        // page (and lang, if the screen has one) make the answer use this screen's language, as the screen does
        var q = new URLSearchParams(location.search), url = c.ajax + '?page=aiworker-chat' + (q.get('lang') ? '&lang=' + encodeURIComponent(q.get('lang')) : '');
        fetch(url, {method: 'POST', body: data, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (r) {
          if (! r || ! r.success) throw new Error();
          body.innerHTML = r.data.html;
        }).catch(function () { body.textContent = c.failed; });
      });
    })();
    </script>
    <?php
}

/** Shows the first documents with the given settings, so the selection can be checked before saving and syncing; a long post goes in parts of this text. */
function aiworker_chat_kb_preview(array $kb): void {
    $ids = aiworker_chat_kb_all_ids($kb);
    echo '<div class="aiwc-kb__preview"><p><strong>'.esc_html(sprintf(/* translators: %d: number of posts */ _n('%d post matches the selection.', '%d posts match the selection.', count($ids), 'needtome-ai-chat'), count($ids))).'</strong> '
        .esc_html__('The first three documents with the choice above, even before it is saved; a long post is sent in parts of this text:', 'needtome-ai-chat').'</p>';
    foreach (aiworker_chat_kb_site_docs($kb) as $doc) echo '<details><summary>'.esc_html($doc['title']).'</summary><pre>'.esc_html(wp_json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)).'</pre></details>';
    foreach (array_slice($ids, 0, 3) as $id) {
        $doc = aiworker_chat_kb_document(get_post($id), $kb);
        if (! $doc) continue;
        $parts = aiworker_chat_kb_parts($doc, $truncated);
        $note = $truncated ? '<p class="description">'.esc_html(sprintf(/* translators: %d: number of parts */ _n('This post is longer than the knowledge base takes: only its first %d part is sent.', 'This post is longer than the knowledge base takes: only its first %d parts are sent.', count($parts), 'needtome-ai-chat'), count($parts))).'</p>' : '';
        unset($parts);
        echo '<details><summary>'.esc_html($doc['title'].' — '.$doc['url']).'</summary>'.$note.'<pre>'.esc_html(wp_json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)).'</pre></details>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $note is built from escaped text.
    }
    echo '</div>';
}
