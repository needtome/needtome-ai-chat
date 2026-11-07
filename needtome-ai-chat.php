<?php
/**
 * Plugin Name: NeedToMe AI Chat
 * Plugin URI: https://needtome.com/needtome-ai-chat/
 * Description: An AI consultant in a chat on your site: answers visitors 24/7 from your own knowledge base (site pages, documents, price lists), speaks the visitor's language, collects leads and hands a dialog over to a person. Requires an account at <a href="https://kra.name/" target="_blank" rel="noopener">kra.name</a>, where the chat, its knowledge and its answers are set up.
 * Version: 1.0.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Parad0x
 * Author URI: https://needtome.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: needtome-ai-chat
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

const AIWORKER_CHAT_OPTION = 'aiworker_chat_options';
const AIWORKER_CHAT_VERSION = '1.0.0';
/** Keys of the colour schemes and of the advanced colours of the widget (the same keys as at kra.name); '' = as saved in the chat. */
const AIWORKER_CHAT_SCHEME_KEYS = ['', 'light', 'dim', 'dark', 'auto'];
require_once plugin_dir_path(__FILE__).'includes/multichat.php';
require_once plugin_dir_path(__FILE__).'includes/kb-sync.php';
require_once plugin_dir_path(__FILE__).'includes/kb-site.php';
require_once plugin_dir_path(__FILE__).'includes/products-sync.php';
require_once plugin_dir_path(__FILE__).'includes/mcp-help.php';
require_once plugin_dir_path(__FILE__).'includes/channels.php';
require_once plugin_dir_path(__FILE__).'includes/connect.php';
require_once plugin_dir_path(__FILE__).'includes/assistant.php';
require_once plugin_dir_path(__FILE__).'includes/chat-pick.php';
require_once plugin_dir_path(__FILE__).'includes/chat-settings.php';
require_once plugin_dir_path(__FILE__).'includes/lazy-blocks.php';
require_once plugin_dir_path(__FILE__).'includes/kb-remote.php';
require_once plugin_dir_path(__FILE__).'includes/needtome-profile.php';

const AIWORKER_CHAT_COLOR_KEYS = ['header_bg', 'header_text', 'bg', 'surface', 'text', 'muted', 'border', 'visitor_bubble', 'visitor_text', 'ai_bubble', 'input_bg'];

// The settings screen follows Polylang's admin language filter (?lang=ru or the saved filter),
// even when the WordPress admin itself stays in another language. Other screens are unchanged.
// Load without a $locale argument: since WP 6.5 a catalog loaded for another locale is not used.
add_action('admin_init', static function (): void {
    $locale = aiworker_chat_polylang_admin_locale();
    if ($locale !== '' && $locale !== determine_locale()) {
        $mofile = WP_LANG_DIR.'/plugins/needtome-ai-chat-'.$locale.'.mo';
        if (is_readable($mofile)) {
            unload_textdomain('needtome-ai-chat');
            load_textdomain('needtome-ai-chat', $mofile);
        }
    }
}, 1);

/** Locale chosen in Polylang's admin language filter, only on this plugin's settings screen; '' otherwise. */
function aiworker_chat_polylang_admin_locale(): string {
    if (!is_admin() || !function_exists('pll_languages_list')) {
        return '';
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only screen routing.
    $page = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
    if ($page !== 'aiworker-chat') {
        return '';
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only language selection.
    $slug = isset($_GET['lang']) && is_string($_GET['lang']) ? sanitize_key(wp_unslash($_GET['lang'])) : null;
    if ($slug === null) {
        $slug = sanitize_key((string) get_user_meta(get_current_user_id(), 'pll_filter_content', true));
    }
    if ($slug === '' || $slug === 'all') {
        return '';
    }
    $slugs = pll_languages_list(['fields' => 'slug']);
    $locales = pll_languages_list(['fields' => 'locale']);
    $index = is_array($slugs) ? array_search($slug, $slugs, true) : false;

    return ($index !== false && isset($locales[$index])) ? sanitize_text_field($locales[$index]) : '';
}

/** The site's languages (Polylang, WPML, otherwise the site language): chat language code => name. */
function aiworker_chat_site_languages(): array {
    $code = static function (string $locale): string {
        $locale = str_replace('_', '-', $locale);
        return strtolower($locale) === 'pt-br' ? 'pt-BR' : strtolower(strtok($locale, '-'));
    };
    $out = [];
    if (function_exists('pll_languages_list')) {
        foreach ((array) pll_languages_list(['fields' => '']) as $l) {
            if (is_object($l) && ! empty($l->locale)) $out[$code((string) $l->locale)] = (string) $l->name;
        }
    }
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML owns this documented integration hook.
    foreach ((array) apply_filters('wpml_active_languages', null, ['skip_missing' => 0]) as $c => $l) {
        if (is_array($l)) $out[$code((string) ($l['default_locale'] ?? $c))] = (string) ($l['native_name'] ?? $c);
    }
    if (! $out) {
        $locale = (string) get_locale();
        $name = $locale === 'en_US' ? 'English' : (class_exists('Locale') ? \Locale::getDisplayLanguage($locale, $locale) : '');
        // mbstring is optional: without it the name keeps the case intl gave it, except an ASCII first letter
        if ($name !== '') $name = function_exists('mb_convert_case') ? mb_convert_case($name, MB_CASE_TITLE, 'UTF-8') : ucfirst($name);
        $out[$code($locale)] = $name !== '' ? $name : $code($locale);
    }
    return $out;
}

function aiworker_chat_schemes(): array {
    return ['' => __('As set for the chat at kra.name', 'needtome-ai-chat'), 'light' => __('Light', 'needtome-ai-chat'), 'dim' => __('Dim (grey-blue)', 'needtome-ai-chat'),
        'dark' => __('Dark', 'needtome-ai-chat'), 'auto' => __('Follow the visitor\'s system', 'needtome-ai-chat')];
}

function aiworker_chat_color_labels(): array {
    return ['header_bg' => __('Header: background', 'needtome-ai-chat'), 'header_text' => __('Header: text', 'needtome-ai-chat'), 'bg' => __('Conversation background', 'needtome-ai-chat'),
        'surface' => __('Window and panels', 'needtome-ai-chat'), 'text' => __('Main text', 'needtome-ai-chat'), 'muted' => __('Secondary text', 'needtome-ai-chat'),
        'border' => __('Borders and lines', 'needtome-ai-chat'), 'visitor_bubble' => __('Visitor messages: background', 'needtome-ai-chat'), 'visitor_text' => __('Visitor messages: text', 'needtome-ai-chat'),
        'ai_bubble' => __('Consultant answers: background', 'needtome-ai-chat'), 'input_bg' => __('Input field: background', 'needtome-ai-chat')];
}

/** The kra.name installation the widget comes from; a developer may point a test site elsewhere with this filter. */
function aiworker_chat_base(): string {
    $base = (string) apply_filters('aiworker_chat_base', 'https://kra.name/app');
    return preg_match('#^https://[^\s"\'<>]+$#', $base) ? rtrim($base, '/') : 'https://kra.name/app';
}

function aiworker_chat_defaults(): array {
    return ['enabled' => 0, 'key' => '', 'theme' => 'classic', 'color' => '#4f46e5', 'position' => 'right', 'voice' => 1, 'rating' => 1, 'privacy' => 1, 'mount' => 'aiworker-chat', 'height' => 560,
        'load' => 'immediate', 'delay' => 5, 'lang' => 'auto', 'scheme' => '', 'colors' => [], 'pages_mode' => 'all', 'pages' => '', 'offset_x' => '', 'offset_y' => '', 'stack_order' => 0, 'stack' => 1, 'greet_popup' => null, 'remote_greet_popup' => null, 'privacy_url' => '', 'chat_id' => 0, 'name' => ''];
}

function aiworker_chat_options(): array {
    return wp_parse_args(aiworker_chat_read_options(AIWORKER_CHAT_OPTION), aiworker_chat_defaults());
}

function aiworker_chat_valid_key(string $key): bool { return (bool) preg_match('/^wk_[A-Za-z0-9]{24}$/', $key); }

function aiworker_chat_sanitize($input): array {
    $input = is_array($input) ? $input : [];
    $out = aiworker_chat_defaults();
    $key = sanitize_text_field((string) ($input['key'] ?? ''));
    $out['key'] = aiworker_chat_valid_key($key) ? $key : '';
    // the chat can be shown on the site only with a key that looks right (the page also checks it with kra.name)
    $out['enabled'] = ! empty($input['enabled']) && $out['key'] !== '' ? 1 : 0;
    $out['theme'] = in_array($input['theme'] ?? '', ['classic', 'bar', 'panel', 'inline'], true) ? $input['theme'] : 'classic';
    $mount = trim((string) ($input['mount'] ?? ''));
    $out['mount'] = preg_match('/^[#.]?[A-Za-z][A-Za-z0-9_-]{0,63}$/', $mount) ? ltrim($mount, '#') : 'aiworker-chat';
    $out['height'] = max(320, min(1200, absint($input['height'] ?? 560) ?: 560));
    $out['color'] = sanitize_hex_color($input['color'] ?? '') ?: '#4f46e5';
    $scheme = (string) ($input['scheme'] ?? '');
    $out['scheme'] = in_array($scheme, AIWORKER_CHAT_SCHEME_KEYS, true) ? $scheme : '';
    $out['colors'] = [];
    foreach ((array) ($input['colors'] ?? []) as $k => $v) {
        $hex = sanitize_hex_color((string) $v);
        if (in_array($k, AIWORKER_CHAT_COLOR_KEYS, true) && $hex && strlen($hex) === 7) $out['colors'][$k] = strtolower($hex);
    }
    $out['position'] = ($input['position'] ?? '') === 'left' ? 'left' : 'right';
    // the launcher's offset for this site in pixels; empty = the chat's own offset at kra.name
    foreach (['offset_x', 'offset_y'] as $k) { $v = trim((string) ($input[$k] ?? '')); $out[$k] = preg_match('/^-?\d{1,3}$/', $v) ? (string) max(-300, min(300, (int) $v)) : ''; }
    $out['voice'] = empty($input['voice']) ? 0 : 1;
    $out['rating'] = empty($input['rating']) ? 0 : 1;
    $out['privacy'] = empty($input['privacy']) ? 0 : 1;
    $out['load'] = in_array($input['load'] ?? '', ['immediate', 'interaction', 'delay'], true) ? $input['load'] : 'immediate';
    $out['delay'] = max(1, min(120, absint($input['delay'] ?? 5)));
    $lang = trim((string) ($input['lang'] ?? 'auto'));
    $out['lang'] = $lang === 'auto' || preg_match('/^[a-z]{2,3}(?:-[A-Za-z]{2,4})?$/', $lang) ? $lang : 'auto';
    $out['pages_mode'] = in_array($input['pages_mode'] ?? '', ['all', 'only', 'except'], true) ? $input['pages_mode'] : 'all';
    $out['pages'] = implode("\n", aiworker_chat_page_rules((string) ($input['pages'] ?? '')));
    $out['stack_order'] = max(0, min(9999, (int) ($input['stack_order'] ?? 0)));
    $out['stack'] = isset($input['stack']) ? (int) ! empty($input['stack']) : 1;
    $out['greet_popup'] = isset($input['greet_popup']) && $input['greet_popup'] !== '' ? (int) ! empty($input['greet_popup']) : null;
    $out['privacy_url'] = esc_url_raw((string) ($input['privacy_url'] ?? ''), ['http', 'https']);
    return $out;
}

/**
 * The page list: one path per line, without the site address — «/», «/contacts/», «/shop/*». A full address pasted by
 * mistake keeps only its path; up to 200 lines.
 */
function aiworker_chat_page_rules(string $raw): array {
    $rules = [];
    foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
        $line = trim(wp_strip_all_tags($line));
        if ($line === '') continue;
        if (preg_match('#^https?://#i', $line)) $line = (string) (wp_parse_url($line, PHP_URL_PATH) ?: '/');
        if ($line[0] !== '/') $line = '/'.$line;
        // any alphabet stays (/контакты/): the rule is kept decoded, the same form the visited path is compared in
        $line = rawurldecode($line);
        $line = (string) preg_replace('#[^\p{L}\p{N}\p{M}/_\-.*~+]#u', '', $line);
        if ($line !== '' && ! in_array($line, $rules, true)) $rules[] = aiworker_chat_utf8_substr($line, 0, 300);
        if (count($rules) >= 200) break;
    }
    return $rules;
}

/** UTF-8-safe substring without requiring the optional mbstring extension. */
function aiworker_chat_utf8_substr(string $text, int $start, int $length): string {
    if (function_exists('mb_substr')) return mb_substr($text, $start, $length, 'UTF-8');
    if (function_exists('iconv_substr')) {
        $substring = @iconv_substr($text, $start, $length, 'UTF-8');
        if ($substring !== false) return $substring;
    }
    if (preg_match_all('/./us', $text, $matches)) return implode('', array_slice($matches[0], $start, $length));
    return '';
}

/**
 * Whether a path matches one rule: an exact path (a trailing slash does not matter) or a «*» pattern. Letter case is
 * ignored by the /iu regular expression itself, so the check needs no mbstring (WordPress has no fallback for
 * mb_strtolower) and still treats /Контакты and /контакты as one page.
 */
function aiworker_chat_path_matches(string $path, string $rule): bool {
    $norm = static function (string $p): string { $p = rawurldecode($p); return $p === '/' ? '/' : rtrim($p, '/'); };
    $path = $norm($path); $rule = $norm($rule);
    $re = '#^'.str_replace('\*', '.*', preg_quote($rule, '#')).'$#iu';
    return (bool) @preg_match($re, $path); // a path that is not valid UTF-8 matches nothing instead of breaking the page
}

/** Whether the chat goes on the current page by the «pages» setting. */
function aiworker_chat_on_this_page(array $o): bool {
    if (($o['pages_mode'] ?? 'all') === 'all') return true;
    $rules = aiworker_chat_page_rules((string) ($o['pages'] ?? ''));
    // esc_url_raw keeps percent-encoding and UTF-8 bytes (sanitize_text_field strips %XX), so /%D0%BA… still matches
    // a rule written as /к…; the path is decoded once, in aiworker_chat_path_matches().
    $requestUri = isset($_SERVER['REQUEST_URI']) && is_string($_SERVER['REQUEST_URI']) ? esc_url_raw(wp_unslash($_SERVER['REQUEST_URI'])) : '/';
    // Not wp_parse_url: PHP's parse_url replaces bytes 0x80–0x9F in an unencoded UTF-8 path with «_».
    $path = (string) preg_replace('#^[a-z][a-z0-9+.\-]*://[^/?\#]*#i', '', $requestUri);
    $path = (string) strtok($path, '?#');
    if ($path === '' || $path[0] !== '/') $path = '/'.$path;
    $home = (string) (wp_parse_url(home_url('/'), PHP_URL_PATH) ?: '/'); // a site in a sub-folder: rules are written from its root
    if ($home !== '/' && strpos($path, rtrim($home, '/')) === 0) $path = substr($path, strlen(rtrim($home, '/'))) ?: '/';
    $hit = false;
    foreach ($rules as $rule) if (aiworker_chat_path_matches($path, $rule)) { $hit = true; break; }
    return $o['pages_mode'] === 'only' ? $hit : ! $hit;
}

/**
 * The four steps of step 1 as done (true), not done (false) or unknown (null), from the key check and, with AI Worker
 * connected, the account's chats: 1 account and chat, 2 consultant and knowledge base set up, 3 this site allowed, 4 key.
 */
function aiworker_chat_setup_steps(string $key, ?array $check): array {
    $steps = [1 => null, 2 => null, 3 => null, 4 => null];
    if ($key !== '' && $check && empty($check['unreachable'])) {
        $http = (int) ($check['http'] ?? 0);
        if (! aiworker_chat_valid_key($key) || $http === 404) {
            $steps[4] = false;
        } else {
            $steps[1] = $steps[4] = true;
            $steps[3] = $http !== 403;
            if ($http === 200) $steps[2] = empty($check['setup_required']);
        }
    }
    if (aiworker_chat_is_connected()) {
        $chats = aiworker_chat_account_chats();
        if (is_array($chats)) {
            if ($steps[1] === null) $steps[1] = (bool) $chats;
            // the chat linked here: its consultant and this site among its allowed sites
            foreach ($chats as $c) {
                if ((int) ($c['id'] ?? 0) !== aiworker_chat_linked_id()) continue;
                if (empty($c['persona']['name'])) $steps[2] = false;
                if ($steps[3] === null && isset($c['site_domains'])) $steps[3] = in_array(aiworker_chat_site_host(), (array) $c['site_domains'], true);
            }
        }
    }
    return $steps;
}

/** The language the preview speaks: the chat's language, or with «auto» the language this settings screen is shown in. */
function aiworker_chat_preview_lang(array $o): string {
    $auto = substr(aiworker_chat_polylang_admin_locale() ?: (string) get_user_locale(), 0, 2) ?: 'en';
    return ($o['lang'] ?? 'auto') === 'auto' ? $auto : (string) $o['lang'];
}

/**
 * Checks this chat's configuration at kra.name and prepares the connection status and preview persona.
 * @return array{state?:string, text?:string, voice_allowed:bool, unreachable?:bool, http?:int, setup_required?:bool, persona?:array, key?:string, texts?:int, icons?:int, locale?:string, lang?:string}
 */
function aiworker_chat_check(string $key, bool $fresh = false): array {
    if (! aiworker_chat_valid_key($key)) return ['state' => 'error', 'text' => __('The key is missing or has a wrong format (wk_ and 24 letters or digits).', 'needtome-ai-chat'), 'voice_allowed' => false];
    $cached = $fresh ? false : get_transient(aiworker_chat_option_name('aiworker_chat_check'));
    // the texts are in the screen's language, so a check cached in another language is made again
    // the consultant's name and role come in the preview's language (with «auto», kra.name answers in the chat's
    // default language, which put an English name into a Russian preview)
    $lang = aiworker_chat_preview_lang(aiworker_chat_options());
    if (is_array($cached) && ($cached['key'] ?? '') === $key && ($cached['locale'] ?? '') === determine_locale() && ($cached['lang'] ?? '') === $lang && isset($cached['voice_allowed'], $cached['unreachable'], $cached['http'], $cached['texts'], $cached['icons'])) return $cached;
    $origin = untrailingslashit(home_url());
    $response = wp_remote_get(aiworker_chat_base().'/api/web/'.rawurlencode($key).'/config?lang='.rawurlencode($lang), ['timeout' => 10, 'headers' => ['Origin' => $origin, 'Accept' => 'application/json']]);
    // http and setup_required feed the checklist of step 1 (aiworker_chat_setup_steps)
    $result = ['voice_allowed' => false, 'unreachable' => false, 'http' => 0, 'setup_required' => false];
    if (is_wp_error($response)) {
        /* translators: %s: the network error */
        $result = ['state' => 'error', 'unreachable' => true, 'text' => sprintf(__('kra.name cannot be reached from this site: %s', 'needtome-ai-chat'), $response->get_error_message())] + $result;
    } else {
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $result['http'] = $code;
        $result['setup_required'] = $code === 200 && is_array($body) && ! empty($body['setup_required']);
        $host = (string) wp_parse_url($origin, PHP_URL_HOST);
        /* translators: %s: this site's domain */
        if ($code === 403) $result += ['state' => 'error', 'text' => sprintf(__('The domain %s is not in this chat\'s allowed sites at kra.name. Until it is added, the chat does not appear on the site.', 'needtome-ai-chat'), $host)];
        elseif ($code === 404) $result += ['state' => 'error', 'text' => __('No chat with this key, or the chat is switched off (for example, its subscription ended). Check the key on the chat\'s card at kra.name.', 'needtome-ai-chat')];
        elseif ($code === 200 && is_array($body) && ! empty($body['ok']) && ! empty($body['setup_required'])) $result = ['state' => 'warning', 'text' => __('The key and the domain are fine, but the chat is not set up yet: visitors will see a request to finish the setup (instructions and knowledge base at kra.name).', 'needtome-ai-chat')] + $result;
        /* translators: 1: this site's domain, 2: the chat's name */
        elseif ($code === 200 && is_array($body) && ! empty($body['ok'])) $result = ['state' => 'success', 'text' => sprintf(__('Connected: the key is right, %1$s is allowed, the chat «%2$s» is ready to answer.', 'needtome-ai-chat'), $host, sanitize_text_field((string) ($body['name'] ?? '')))] + $result;
        /* translators: %d: HTTP status code */
        else $result = ['state' => 'error', 'unreachable' => true, 'text' => sprintf(__('Unexpected answer from kra.name (HTTP %d).', 'needtome-ai-chat'), $code)] + $result;
        // the consultant's name, role, avatar, the chat's own scheme and its paid add-ons feed the preview and the form
        if ($code === 200 && is_array($body) && ! empty($body['ok'])) {
            $result['voice_allowed'] = ! empty($body['voice_allowed']);
            if (array_key_exists('greet_popup', $body)) aiworker_chat_remember_remote(['greet_popup' => $body['greet_popup']]);
            $result['persona'] = ['name' => sanitize_text_field((string) ($body['name'] ?? '')), 'title' => sanitize_text_field((string) ($body['title'] ?? '')),
                'tab_label' => sanitize_text_field((string) ($body['tab_label'] ?? '')), 'avatar' => esc_url_raw((string) ($body['avatar'] ?? ''), ['https']),
                'scheme' => in_array($body['scheme'] ?? '', ['light', 'dim', 'dark', 'auto'], true) ? $body['scheme'] : 'light',
                // the greeting (one per language) and the quick questions, so the preview shows the chat's own
                'greeting' => array_map(static function ($g): string { return sanitize_textarea_field((string) $g); }, array_filter((array) ($body['greeting'] ?? []), 'is_string')),
                'suggestions' => array_values(array_filter(array_map('sanitize_text_field', array_map('strval', array_filter((array) ($body['suggestions'] ?? []), 'is_scalar'))), 'strlen')),
                // the chat button icon: the chosen one, the own image and the ready icons' SVGs (the preview draws them)
                'launcher_icon' => sanitize_key((string) ($body['launcher_icon'] ?? 'chat')) ?: 'chat', 'launcher_icon_url' => esc_url_raw((string) ($body['launcher_icon_url'] ?? ''), ['https']),
                'launcher_icons' => array_map(static function ($svg): string { return wp_kses((string) $svg, aiworker_chat_svg_kses()); }, array_filter((array) ($body['launcher_icons'] ?? []), 'is_string'))];
        }
    }
    $result['key'] = $key;
    $result['texts'] = 1; // a check cached before the greeting was kept is made again
    $result['icons'] = 1; // and before the launcher icons were kept
    $result['locale'] = determine_locale();
    $result['lang'] = $lang;
    set_transient(aiworker_chat_option_name('aiworker_chat_check'), $result, 5 * MINUTE_IN_SECONDS);
    return $result;
}

/** The scheme and host of a URL («https://example.com»), or '' when it has none. */
function aiworker_chat_origin(string $url): string {
    return preg_match('#^(https?://[^/?\#]+)#i', $url, $m) ? strtolower($m[1]) : '';
}

/**
 * What a site Content-Security-Policy must allow for the chat: the plugin's loader (served from this site or its CDN) and
 * the widget script, its API and live events (all under the kra.name base address), the avatar and photo previews, and
 * two Trusted Types policies (the loader's script URL and the widget's own markup). Nothing inline is printed on the
 * site's pages, so no nonce or 'unsafe-inline' is needed unless the policy uses 'strict-dynamic'. A theme or security
 * plugin that builds the CSP can merge this list; the settings page checks the live header.
 */
function aiworker_chat_csp_sources(): array {
    $origin = aiworker_chat_origin(aiworker_chat_base());
    $base = aiworker_chat_base().'/';
    // the loader from this site's own origin (scheme, host and port) is «'self'»; from a CDN, that CDN's origin
    $loader = aiworker_chat_origin(aiworker_chat_asset_url('loader.js'));
    if ($loader === '' || $loader === aiworker_chat_origin(home_url('/'))) $loader = "'self'";
    return (array) apply_filters('aiworker_chat_csp_sources', [
        'script-src' => array_values(array_unique([$loader, $base])), 'connect-src' => [$base], 'img-src' => [$origin, 'blob:'],
        'trusted-types' => ['aiworker-chat', 'aiworker-chat-loader'],
    ]);
}

/**
 * Whether a CSP source list lets $source (a URL, «'self'» or «blob:») through: «*», «https:», «'self'» for this site,
 * or a host source — with or without its scheme, a «*.» wildcard host, and a path that must prefix the URL's path
 * («https://kra.name/app/» allows everything under /app/).
 */
function aiworker_chat_csp_allows(array $have, string $source): bool {
    $keywords = array_map('strtolower', array_map('strval', $have));
    if ($source === 'blob:') return in_array('blob:', $keywords, true);
    $home = aiworker_chat_origin(home_url('/'));
    if ($source === "'self'") {
        if (in_array("'self'", $keywords, true)) return true;
        $source = $home.'/';
    }
    if (! preg_match('#^(https?)://([^/]+)(/.*)?$#i', $source, $u)) return false;
    $scheme = strtolower($u[1]); $host = strtolower($u[2]); $path = ($u[3] ?? '') !== '' ? $u[3] : '/';
    if (in_array('*', $keywords, true) || in_array($scheme.':', $keywords, true) || ($scheme === 'https' && in_array('http:', $keywords, true))) return true;
    if ($scheme.'://'.$host === $home && in_array("'self'", $keywords, true)) return true;
    foreach ($have as $token) {
        // scheme and host are case-insensitive, the path is not
        if (! preg_match('#^(?:(https?)://)?(\*\.)?([A-Za-z0-9.\-]+(?::\d+)?)(/\S*)?$#i', (string) $token, $m)) continue;
        $want = strtolower($m[1] ?? '');
        if ($want === 'https' && $scheme !== 'https') continue;            // http://host also allows https://host
        $tokenHost = strtolower($m[3]);
        $hostOk = $m[2] !== '' ? substr($host, -strlen('.'.$tokenHost)) === '.'.$tokenHost : $host === $tokenHost;
        if (! $hostOk) continue;
        $tokenPath = $m[4] ?? '';
        if ($tokenPath === '' || $tokenPath === '/' || (substr($tokenPath, -1) === '/' ? strpos($path, $tokenPath) === 0 : $path === $tokenPath)) return true;
    }
    return false;
}

/** A header's directives by lowercase name; the browser uses the first of repeated names. */
function aiworker_chat_csp_directives(string $header): array {
    $directives = [];
    foreach (explode(';', $header) as $part) {
        $tokens = preg_split('/\s+/', trim($part));
        $name = $tokens ? strtolower((string) array_shift($tokens)) : '';
        if ($name !== '' && ! isset($directives[$name])) $directives[$name] = $tokens;
    }
    return $directives;
}

/** The source list the browser applies to the loader's and the widget's <script src>: script-src-elem, script-src, default-src. */
function aiworker_chat_csp_script_sources(array $directives): ?array {
    foreach (['script-src-elem', 'script-src', 'default-src'] as $name) if (isset($directives[$name])) return $directives[$name];
    return null;
}

/**
 * Directives of one CSP header that would block the chat, each as a whole «directive sources» line to put into the
 * header in place of the existing one (its sources plus what the chat needs); [] when nothing blocks it. Scripts follow
 * the browser's fallback chain script-src-elem → script-src → default-src; the rest fall back to default-src. A
 * directive that is missing is suggested with the fallback's sources, because adding it replaces the fallback.
 */
function aiworker_chat_csp_gaps(string $header): array {
    $directives = aiworker_chat_csp_directives($header);
    $gaps = [];
    foreach (aiworker_chat_csp_sources() as $directive => $needed) {
        if ($directive === 'trusted-types') {
            // the trusted-types directive alone limits which policies may be created, with or without require-trusted-types-for
            if (! isset($directives['trusted-types'])) continue;
            $have = $directives['trusted-types'];
            $missing = in_array('*', $have, true) ? [] : array_values(array_diff($needed, $have));
            if ($missing) $gaps[] = 'trusted-types '.implode(' ', array_merge(array_diff($have, ["'none'"]), $missing));
            continue;
        }
        $chain = $directive === 'script-src' ? ['script-src-elem', 'script-src', 'default-src'] : [$directive, 'default-src'];
        $from = null;
        foreach ($chain as $name) if (isset($directives[$name])) { $from = $name; break; }
        if ($from === null) continue;
        $have = $directives[$from];
        // with 'strict-dynamic' host sources do not count for scripts: the loader's nonce decides (aiworker_chat_csp_check)
        if ($directive === 'script-src' && in_array("'strict-dynamic'", array_map('strtolower', $have), true)) continue;
        $missing = array_values(array_filter($needed, static function ($source) use ($have) { return $source !== '' && ! aiworker_chat_csp_allows($have, $source); }));
        if (! $missing) continue;
        $gaps[] = ($from === 'default-src' ? $directive : $from).' '.implode(' ', array_values(array_unique(array_merge(array_diff($have, ["'none'"]), $missing))));
    }
    return $gaps;
}

/** One policy with the chat's sources merged in: each directive aiworker_chat_csp_gaps() names is replaced or added. */
function aiworker_chat_csp_patch_policy(string $policy): string {
    $gaps = aiworker_chat_csp_gaps($policy);
    if (! $gaps) return $policy;
    $parts = array_values(array_filter(array_map('trim', explode(';', $policy)), 'strlen'));
    foreach ($gaps as $gap) {
        $name = strtolower((string) strtok($gap, ' '));
        $found = false;
        foreach ($parts as $i => $part) {
            if (strtolower((string) strtok($part, " \t")) !== $name) continue;
            $parts[$i] = $gap;   // the browser uses the first of repeated names, so only that one is replaced
            $found = true;
            break;
        }
        if (! $found) $parts[] = $gap;
    }
    return implode('; ', $parts);
}

/**
 * A Content-Security-Policy that the theme or another plugin sends from PHP would block the chat until its owner adds
 * the chat's sources by hand. Just before the page template runs (all such headers are set by then, nothing is printed
 * yet), the chat's sources are merged into every such header; under 'strict-dynamic' the loader's tag takes the
 * policy's nonce. A policy set by the web server (.htaccess, nginx) is out of PHP's reach: the settings page still
 * shows what to add there.
 */
function aiworker_chat_csp_merge($template) {
    if (! apply_filters('aiworker_chat_merge_csp', true, $template) || ! aiworker_chat_loader_options()['chats'] || headers_sent()) return $template;
    $headers = [];
    foreach (headers_list() as $line) {
        if (stripos($line, 'Content-Security-Policy:') === 0) $headers[] = trim(substr($line, strlen('Content-Security-Policy:')));
    }
    if (! $headers) return $template;
    $patched = [];
    foreach ($headers as $header) {
        $policies = [];
        foreach (explode(',', $header) as $policy) {
            if (trim($policy) === '') continue;
            $policy = aiworker_chat_csp_patch_policy(trim($policy));
            $script = aiworker_chat_csp_script_sources(aiworker_chat_csp_directives($policy));
            if ($script && in_array("'strict-dynamic'", array_map('strtolower', $script), true) && empty($GLOBALS['aiworker_chat_csp_nonce'])) {
                foreach ($script as $token) if (preg_match("/^'nonce-([^']+)'$/", $token, $m)) { $GLOBALS['aiworker_chat_csp_nonce'] = $m[1]; break; }
            }
            $policies[] = $policy;
        }
        $patched[] = implode(', ', $policies);
    }
    if ($patched === $headers) return $template;
    header_remove('Content-Security-Policy');
    foreach ($patched as $header) header('Content-Security-Policy: '.$header, false);
    return $template;
}
add_filter('template_include', 'aiworker_chat_csp_merge', PHP_INT_MAX);

/** The front page's CSP checked against the chat's needs (cached with the connection check). */
function aiworker_chat_csp_check(bool $fresh = false): array {
    $cached = $fresh ? false : get_transient('aiworker_chat_csp');
    if (is_array($cached) && isset($cached['nonce'])) return $cached;
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is a WordPress core SSL verification filter.
    $sslVerify = apply_filters('https_local_ssl_verify', false);
    $response = wp_remote_get(home_url('/'), ['timeout' => 10, 'redirection' => 3, 'sslverify' => $sslVerify]);
    // several CSP headers, or several policies joined with commas into one header, are each enforced on their own
    $policies = [];
    if (! is_wp_error($response)) foreach ((array) wp_remote_retrieve_header($response, 'content-security-policy') as $header) {
        foreach (explode(',', (string) $header) as $policy) if (trim($policy) !== '') $policies[] = trim($policy);
    }
    $body = is_wp_error($response) ? '' : (string) wp_remote_retrieve_body($response);
    $tagNonce = preg_match('/<script\b[^>]*\bdata-aiworker-chat-options=[^>]*>/i', $body, $tag) && preg_match('/\snonce=(["\']?)([^"\'\s>]+)\1/i', $tag[0], $n) ? $n[2] : '';
    $gaps = []; $nonce = false;
    foreach ($policies as $policy) {
        $gaps = array_merge($gaps, aiworker_chat_csp_gaps($policy));
        // with 'strict-dynamic' host sources do not count: the loader's tag needs this policy's nonce (a CSP plugin adds
        // it through wp_script_attributes); the loader then may add the widget script itself
        $script = aiworker_chat_csp_script_sources(aiworker_chat_csp_directives($policy));
        if ($script && in_array("'strict-dynamic'", array_map('strtolower', $script), true) && ($tagNonce === '' || ! in_array("'nonce-".$tagNonce."'", $script, true))) $nonce = true;
    }
    $result = ['gaps' => array_values(array_unique($gaps)), 'nonce' => $nonce, 'checked' => ! is_wp_error($response)];
    set_transient('aiworker_chat_csp', $result, 5 * MINUTE_IN_SECONDS);
    return $result;
}

add_action('admin_init', static function (): void {
    register_setting('aiworker_chat', aiworker_chat_option_name(AIWORKER_CHAT_OPTION), ['type' => 'array', 'sanitize_callback' => 'aiworker_chat_sanitize_saved', 'default' => aiworker_chat_defaults()]);
});

// Saved settings (a new key, a domain just allowed at kra.name) are checked afresh on the next settings page.
add_action('init', static function (): void {
    foreach (aiworker_chat_ids() as $id) add_action('update_option_'.aiworker_chat_option_name(AIWORKER_CHAT_OPTION, $id), static function () use ($id): void {
        delete_transient(aiworker_chat_option_name('aiworker_chat_check', $id));
        delete_transient('aiworker_chat_csp');
    });
}, 5);

add_action('admin_menu', static function (): void {
    Aiworker_Chat_Profile::menu(__('NeedToMe AI Chat', 'needtome-ai-chat'), 'aiworker-chat', 'aiworker_chat_settings_page', 'manage_options', __('AI Chat', 'needtome-ai-chat'));
});

add_filter('plugin_action_links_'.plugin_basename(__FILE__), static function (array $links): array {
    array_unshift($links, '<a href="'.esc_url(Aiworker_Chat_Profile::url('aiworker-chat')).'">'.esc_html__('Settings', 'needtome-ai-chat').'</a>');
    return $links;
});

/** HTML elements used by the settings form controls assembled by this plugin. */
function aiworker_chat_admin_html_allowed(): array {
    $allowed = wp_kses_allowed_html('post');
    $allowed['details'] = ['class' => true, 'id' => true, 'open' => true];
    $allowed['input'] = ['type' => true, 'id' => true, 'name' => true, 'value' => true, 'class' => true, 'min' => true, 'max' => true, 'step' => true,
        'pattern' => true, 'placeholder' => true, 'autocomplete' => true, 'spellcheck' => true, 'checked' => true, 'disabled' => true, 'list' => true, 'data-default-color' => true, 'aria-label' => true];
    $allowed['select'] = ['id' => true, 'name' => true, 'class' => true];
    $allowed['option'] = ['value' => true, 'selected' => true, 'disabled' => true];
    $allowed['textarea'] = ['id' => true, 'name' => true, 'rows' => true, 'class' => true, 'placeholder' => true];
    $allowed['label'] = ['class' => true, 'for' => true];
    $allowed['summary'] = ['class' => true];
    $allowed['span'] = ['class' => true];
    $allowed['div'] = ['id' => true, 'class' => true];
    $allowed['p'] = ['class' => true];
    return $allowed;
}

/** A form row: label on the left, control and hint on the right (stacks on narrow screens). */
function aiworker_chat_row(string $label, string $control, string $hint = '', string $for = '', string $class = ''): void {
    echo '<div class="aiworker-chat-row'.($class ? ' '.esc_attr($class) : '').'"><div class="aiworker-chat-row__label">'.($for ? '<label for="'.esc_attr($for).'">'.esc_html($label).'</label>' : esc_html($label)).'</div>'
        .'<div class="aiworker-chat-row__control">'.wp_kses($control, aiworker_chat_admin_html_allowed()).($hint !== '' ? '<p class="description">'.wp_kses($hint, wp_kses_allowed_html('post')).'</p>' : '').'</div></div>';
}

/** The tab this screen shows; rendering and asset loading must agree, or a tab that falls back to Settings runs without its scripts. */
function aiworker_chat_admin_tab(): string {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation.
    $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
    // The statistics tab exists only while AI Worker is connected; the wizard is then in step 1 of Settings.
    $tabs = aiworker_chat_is_connected() ? ['settings', 'kb', 'assistant'] : ['settings', 'kb'];
    return in_array($tab, $tabs, true) ? $tab : 'settings';
}

function aiworker_chat_settings_page(): void {
    if (! current_user_can('manage_options')) return;
    // MCP and Telegram are sub-tabs of the setup wizard in step 1 now: an old ?tab=mcp|telegram opens them there
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation.
    $requestedTab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
    if (in_array($requestedTab, ['mcp', 'telegram'], true)) $_GET['aiworker_chat_sub'] = $requestedTab;
    $activeTab = aiworker_chat_admin_tab();
    $oauth = aiworker_chat_is_connected();
    $o = aiworker_chat_options();
    $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    $name = esc_attr(aiworker_chat_option_name(AIWORKER_CHAT_OPTION));
    $recheck = isset($_GET['aiworker_chat_recheck']) && wp_verify_nonce(sanitize_text_field(wp_unslash((string) ($_GET['_wpnonce'] ?? ''))), 'aiworker_chat_recheck');
    $check = $activeTab === 'settings' && $o['key'] !== '' ? aiworker_chat_check((string) $o['key'], $recheck) : null;
    // only while the chat is shown: until then nothing of it is on the site's pages for a policy to block
    $csp = $activeTab === 'settings' && $o['key'] !== '' && ! empty($o['enabled']) ? aiworker_chat_csp_check($recheck) : null;
    $connected = $check && in_array($check['state'], ['success', 'warning'], true);
    $voiceAllowed = $check && ! empty($check['voice_allowed']);
    // kra.name could not answer (network, 5xx): the switches keep their saved values, so saving other settings during
    // an outage neither hides the chat nor turns the microphone off.
    $unknown = $check && ! empty($check['unreachable']);
    $canEnable = $connected || $unknown;
    $privacyUrl = get_privacy_policy_url();
    $sel = static function (string $field, array $options, string $current, string $id = '') use ($name): string {
        $h = '<select'.($id ? ' id="'.esc_attr($id).'"' : '').' name="'.$name.'['.esc_attr($field).']">';
        foreach ($options as $v => $label) $h .= '<option value="'.esc_attr((string) $v).'"'.selected($current, (string) $v, false).'>'.esc_html($label).'</option>';
        return $h.'</select>';
    };
    $pill = ! $o['key'] ? ['off', __('Not connected', 'needtome-ai-chat')] : ($connected ? ($check['state'] === 'success' ? ['ok', __('Connected', 'needtome-ai-chat')] : ['warn', __('Needs setup at kra.name', 'needtome-ai-chat')]) : ['err', __('Not working', 'needtome-ai-chat')]);
    $live = $o['enabled'] && $connected;
    ?>
    <div class="wrap aiworker-chat">
      <div class="aiworker-chat-head">
        <h1><?php esc_html_e('NeedToMe AI Chat', 'needtome-ai-chat'); ?> <span><?php esc_html_e('for your site', 'needtome-ai-chat'); ?></span></h1>
        <?php if ($activeTab === 'settings') : ?>
          <span class="aiworker-chat-pill aiworker-chat-pill--<?php echo esc_attr($pill[0]); ?>"><?php echo esc_html($pill[1]); ?></span>
          <span class="aiworker-chat-pill aiworker-chat-pill--<?php echo $live ? 'ok' : 'off'; ?>"><?php echo $live ? esc_html__('Shown on the site', 'needtome-ai-chat') : esc_html__('Hidden on the site', 'needtome-ai-chat'); ?></span>
        <?php endif; ?>
      </div>
      <h2 class="nav-tab-wrapper aiworker-chat-tabs">
        <?php
        $tabs = ['settings' => __('Settings', 'needtome-ai-chat'), 'kb' => __('Knowledge base', 'needtome-ai-chat'), 'assistant' => __('Statistics', 'needtome-ai-chat')];
        if (! $oauth) unset($tabs['assistant']);
        foreach ($tabs as $tab => $label) :
            $tabUrl = Aiworker_Chat_Profile::url('aiworker-chat', ['tab' => $tab, 'aiworker_chat_instance' => aiworker_chat_current_id()]);
        ?>
          <a href="<?php echo esc_url($tabUrl); ?>" class="nav-tab <?php echo $activeTab === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
      </h2>
      <?php if ($activeTab === 'settings') : ?>
      <?php settings_errors(); ?>
      <?php aiworker_chat_assistant_notice(); ?>
      <?php if ($csp && ($csp['gaps'] || ! empty($csp['nonce']))): ?><div class="notice notice-error inline"><p><strong><?php esc_html_e('This site\'s Content-Security-Policy will block the chat.', 'needtome-ai-chat'); ?></strong><?php if ($csp['gaps']): ?> <?php esc_html_e('Use these directives in the CSP header of your theme or security plugin (each replaces the directive of the same name):', 'needtome-ai-chat'); ?><?php endif; ?></p>
        <?php if ($csp['gaps']): ?><p><code><?php echo esc_html(implode('; ', $csp['gaps'])); ?></code></p><?php endif; ?>
        <?php if (! empty($csp['nonce'])): ?><p><?php esc_html_e('The policy uses \'strict-dynamic\': the script tag aiworker-chat-loader needs the policy\'s nonce (security plugins that add nonces to WordPress scripts do this).', 'needtome-ai-chat'); ?></p><?php endif; ?>
        <p class="description"><?php echo wp_kses(__('Developers can take this list from <code>aiworker_chat_csp_sources()</code>.', 'needtome-ai-chat'), ['code' => []]); ?></p></div><?php endif; ?>
      <?php if (! empty($o['enabled'])) : ?><p class="description"><?php esc_html_e('When this chat is shown, the plugin automatically merges its required sources into Content-Security-Policy headers sent by PHP. It cannot change server-level headers. Add the aiworker_chat_merge_csp filter and return false to turn off this merge.', 'needtome-ai-chat'); ?></p><?php endif; ?>
      <form method="post" action="options.php" class="aiworker-chat-grid">
        <?php settings_fields('aiworker_chat'); aiworker_chat_context_field(); ?>
        <div class="aiworker-chat-main">

          <section class="aiworker-chat-card aiworker-chat-card--connect">
            <h2><span class="aiworker-chat-num">1</span><?php esc_html_e('Connect your chat', 'needtome-ai-chat'); ?></h2>
            <?php
            // connected through AI Worker with the chat it linked: the chat is chosen in the box, the key is not typed
            $linkedMode = $oauth && $o['key'] !== '' && aiworker_chat_linked_id() > 0;
            $steps = aiworker_chat_setup_steps((string) $o['key'], $check);
            $stepClass = static function (?bool $done): string { return $done === null ? '' : ($done ? ' class="is-done"' : ' class="is-missing"'); };
            ?>
            <p class="aiworker-chat-lead"><?php echo wp_kses(sprintf(
                /* translators: %s: link to kra.name */
                __('The AI consultant\'s knowledge base and instructions are filled in at the %s client panel:', 'needtome-ai-chat'),
                '<a href="https://kra.name/" target="_blank" rel="noopener">kra.name</a>'), ['a' => ['href' => [], 'target' => [], 'rel' => []]]); ?></p>
            <?php $allDone = ! in_array(false, $steps, true) && ! in_array(null, $steps, true);
            // all four done: one ✓ line; the steps open on a click
            if ($allDone) : ?><details class="aiworker-chat-steps-done"><summary><?php esc_html_e('Everything is connected', 'needtome-ai-chat'); ?></summary><?php endif; ?>
            <ol class="aiworker-chat-steps aiworker-chat-steps--check">
              <li<?php echo $stepClass($steps[1]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup. ?>><?php echo wp_kses(sprintf(/* translators: %s: link to the kra.name panel */ __('Sign up and create a site chat in the %s.', 'needtome-ai-chat'), '<a href="https://kra.name/app/panel" target="_blank" rel="noopener">'.esc_html__('kra.name panel', 'needtome-ai-chat').'</a>'), ['a' => ['href' => [], 'target' => [], 'rel' => []]]); ?></li>
              <li<?php echo $stepClass($steps[2]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup. ?>><?php esc_html_e('Choose the consultant and fill the chat\'s knowledge base.', 'needtome-ai-chat'); ?></li>
              <li<?php echo $stepClass($steps[3]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup. ?>><?php if ($linkedMode) : echo wp_kses(sprintf(/* translators: %s: this site's domain */ __('%s was added to the chat\'s allowed sites automatically.', 'needtome-ai-chat'), '<code>'.esc_html($host).'</code>'), ['code' => []]); else : ?><?php echo wp_kses(sprintf(/* translators: 1: this site's domain, 2: wildcard for its subdomains */ __('Add %1$s to the chat\'s allowed sites (www is included; %2$s allows every subdomain).', 'needtome-ai-chat'), '<code>'.esc_html($host).'</code>', '<code>*.'.esc_html(preg_replace('/^www\./', '', $host)).'</code>'), ['code' => []]); ?><?php endif; ?></li>
              <li<?php echo $stepClass($steps[4]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup. ?>><?php echo esc_html($linkedMode ? __('The chat\'s public key was put in automatically.', 'needtome-ai-chat') : __('Copy the public key wk_… from the chat\'s card and paste it here.', 'needtome-ai-chat')); ?></li>
            </ol>
            <?php if ($allDone) : ?></details><?php endif; ?>
            <div class="aiworker-chat-connect-row<?php echo $linkedMode ? ' aiworker-chat-connect-row--linked' : ''; ?>">
            <?php if ($linkedMode || count(aiworker_chat_ids()) > 1) : ?>
            <input type="hidden" name="<?php echo esc_attr($name); ?>[key]" value="<?php echo esc_attr($o['key']); ?>">
            <?php else : ?>
            <div class="aiworker-chat-key">
              <label for="aiworker-chat-key"><?php esc_html_e('Public chat key', 'needtome-ai-chat'); ?></label>
              <div class="aiworker-chat-key__row">
                <input id="aiworker-chat-key" type="text" name="<?php echo esc_attr($name); ?>[key]" value="<?php echo esc_attr($o['key']); ?>" pattern="wk_[A-Za-z0-9]{24}" placeholder="wk_xxxxxxxxxxxxxxxxxxxxxxxx" autocomplete="off" spellcheck="false">
                <button type="submit" class="button button-secondary" id="aiworker-chat-key-save"<?php echo $o['key'] !== '' ? ' hidden' : ''; ?>><?php esc_html_e('Save and check', 'needtome-ai-chat'); ?></button>
              </div>

              <p class="description"><?php esc_html_e('The key only selects the chat; it is not a secret.', 'needtome-ai-chat'); ?></p>
            </div>
            <?php endif; ?>
            <?php aiworker_chat_pick_panel(); ?>
            </div>
            <?php aiworker_chat_list_controls(); ?>
            <?php if (count(aiworker_chat_ids()) === 1 && $oauth && $o['key'] !== '') aiworker_chat_wizard_block($allDone); // all connected: the card starts folded ?>
            <?php // all fine is already the ✓ of the steps above; a problem keeps its line, which says what to fix
            if ($check && $check['state'] !== 'success'): ?>
              <div class="aiworker-chat-status aiworker-chat-status--<?php echo esc_attr($check['state']); ?>"><?php echo esc_html($check['text']); ?>
                <a href="<?php echo esc_url(wp_nonce_url(Aiworker_Chat_Profile::url('aiworker-chat', ['aiworker_chat_recheck' => 1, 'aiworker_chat_instance' => aiworker_chat_current_id()]), 'aiworker_chat_recheck')); ?>"><?php esc_html_e('Check again', 'needtome-ai-chat'); ?></a></div>
            <?php endif; ?>
            <label class="aiworker-chat-toggle<?php echo $canEnable ? '' : ' is-off'; ?>">
              <input type="checkbox" name="<?php echo esc_attr($name); ?>[enabled]" value="1" <?php checked($o['enabled'] && $canEnable); ?> <?php disabled(! $canEnable); ?>>
              <span class="aiworker-chat-toggle__ui" aria-hidden="true"></span>
              <span><strong><?php esc_html_e('Show the chat on this site', 'needtome-ai-chat'); ?></strong>
                <small><?php echo $canEnable ? esc_html__('Visitors see the chat on the pages chosen below.', 'needtome-ai-chat') : esc_html__('Available once a working key is saved and checked.', 'needtome-ai-chat'); ?></small></span>
            </label>
          </section>

          <?php aiworker_chat_tabs(); ?>
          <?php if (count(aiworker_chat_ids()) > 1 && $oauth && $o['key'] !== '') aiworker_chat_wizard_block($allDone); ?>
          <section class="aiworker-chat-card">
            <h2><span class="aiworker-chat-num">2</span><?php esc_html_e('Look', 'needtome-ai-chat'); ?></h2>
            <?php
            aiworker_chat_row(__('Design', 'needtome-ai-chat'), $sel('theme', ['classic' => __('Classic: button and window', 'needtome-ai-chat'), 'bar' => __('Bar at the bottom', 'needtome-ai-chat'), 'panel' => __('Side panel', 'needtome-ai-chat'), 'inline' => __('Inside a page block', 'needtome-ai-chat')], (string) $o['theme'], 'aiworker-chat-theme'), '', 'aiworker-chat-theme');
            $shortcode = '<code>'.esc_html('[aiworker_chat id="'.aiworker_chat_current_id().'"]').'</code>';
            aiworker_chat_row(__('Block id', 'needtome-ai-chat'), '<input id="aiworker-chat-mount" type="text" class="regular-text" name="'.$name.'[mount]" value="'.esc_attr((string) $o['mount']).'" pattern="[#.]?[A-Za-z][A-Za-z0-9_\-]{0,63}">',
                wp_kses(sprintf(/* translators: 1: this chat's shortcode, 2: the HTML element */ __('Put the %1$s shortcode into a post or page (or %2$s into the theme). Pages without the block show the usual button.', 'needtome-ai-chat'), $shortcode, '<code>&lt;div id="'.esc_html((string) $o['mount']).'"&gt;&lt;/div&gt;</code>'), ['code' => []]), 'aiworker-chat-mount', 'aiworker-chat-inline-only');
            aiworker_chat_row(__('Block height, px', 'needtome-ai-chat'), '<input id="aiworker-chat-height" type="number" min="320" max="1200" name="'.$name.'[height]" value="'.esc_attr((string) $o['height']).'">', '', 'aiworker-chat-height', 'aiworker-chat-inline-only');
            aiworker_chat_row(__('Accent colour', 'needtome-ai-chat'), '<input id="aiworker-chat-color" class="aiworker-chat-color aiworker-chat-color--accent" type="text" name="'.$name.'[color]" value="'.esc_attr($o['color']).'" data-default-color="#4f46e5">', '', 'aiworker-chat-color');
            aiworker_chat_row(__('Colour scheme', 'needtome-ai-chat'), $sel('scheme', aiworker_chat_schemes(), (string) $o['scheme'], 'aiworker-chat-scheme'), esc_html__('«Follow the visitor\'s system» switches between light and dark by the device setting.', 'needtome-ai-chat'), 'aiworker-chat-scheme');
            $colors = '<details class="aiworker-chat-colors"'.(! empty($o['colors']) ? ' open' : '').'><summary>'.esc_html__('Change separate colours', 'needtome-ai-chat').'</summary><p class="description">'.esc_html__('Empty means the scheme\'s colour.', 'needtome-ai-chat').'</p><div class="aiworker-chat-colors__grid">';
            foreach (aiworker_chat_color_labels() as $k => $label) $colors .= '<div class="aiworker-chat-colors__item"><span class="aiworker-chat-colors__name">'.esc_html($label).'</span><input class="aiworker-chat-color" type="text" name="'.$name.'[colors]['.esc_attr($k).']" value="'.esc_attr((string) ($o['colors'][$k] ?? '')).'" data-default-color="" aria-label="'.esc_attr($label).'"></div>';
            aiworker_chat_row(__('Advanced colours', 'needtome-ai-chat'), $colors.'</div></details>');
            if (count(aiworker_chat_ids()) > 1) aiworker_chat_row(__('Order on the page', 'needtome-ai-chat'), '<input type="number" min="0" max="9999" name="'.$name.'[stack_order]" value="'.esc_attr((string) $o['stack_order']).'">', __('0 is at the bottom; the next chat is placed above it.', 'needtome-ai-chat'));
            if (count(aiworker_chat_ids()) > 1) aiworker_chat_row(__('Stack chats', 'needtome-ai-chat'), '<input type="hidden" name="'.$name.'[stack]" value="0"><label><input type="checkbox" name="'.$name.'[stack]" value="1" '.checked(! empty($o['stack']), true, false).'> '.esc_html__('Place chats above each other', 'needtome-ai-chat').'</label>');
            aiworker_chat_row(__('Position', 'needtome-ai-chat'), $sel('position', ['right' => __('Right', 'needtome-ai-chat'), 'left' => __('Left', 'needtome-ai-chat')], (string) $o['position'], 'aiworker-chat-position'), '', 'aiworker-chat-position');
            aiworker_chat_row(__('Button offset, px', 'needtome-ai-chat'), '<span class="aiworker-chat-offset"><label>'.esc_html__('sideways', 'needtome-ai-chat').' <input id="aiworker-chat-offset-x" type="number" min="-300" max="300" step="1" name="'.$name.'[offset_x]" value="'.esc_attr((string) $o['offset_x']).'" placeholder="—"></label>'
                .'<label>'.esc_html__('up / down', 'needtome-ai-chat').' <input id="aiworker-chat-offset-y" type="number" min="-300" max="300" step="1" name="'.$name.'[offset_y]" value="'.esc_attr((string) $o['offset_y']).'" placeholder="—"></label></span>',
                esc_html__('Moves the chat button from its corner: positive values go right and down. Empty uses the offset set for the chat at kra.name. Watch the button in the corner of this page.', 'needtome-ai-chat'), 'aiworker-chat-offset-x');
            ?>
          </section>

          <section class="aiworker-chat-card">
            <h2><span class="aiworker-chat-num">3</span><?php esc_html_e('Where to show the chat', 'needtome-ai-chat'); ?></h2>
            <?php
            aiworker_chat_row(__('Pages', 'needtome-ai-chat'), $sel('pages_mode', ['all' => __('On every page', 'needtome-ai-chat'), 'only' => __('Only on the pages listed', 'needtome-ai-chat'), 'except' => __('On every page except those listed', 'needtome-ai-chat')], (string) $o['pages_mode'], 'aiworker-chat-pages-mode'), '', 'aiworker-chat-pages-mode');
            aiworker_chat_row(__('Page list', 'needtome-ai-chat'), '<textarea id="aiworker-chat-pages" name="'.$name.'[pages]" rows="5" class="large-text code" placeholder="/&#10;/contacts/&#10;/shop/*">'.esc_textarea((string) $o['pages']).'</textarea>',
                wp_kses(__('One page per line, without the site address: <code>/</code> is the home page, <code>/contacts/</code> one page, <code>/shop/*</code> a section with everything inside it.', 'needtome-ai-chat'), ['code' => []]), 'aiworker-chat-pages', 'aiworker-chat-pages-only');
            aiworker_chat_row(__('Show the chat', 'needtome-ai-chat'), $sel('load', ['immediate' => __('When the page opens', 'needtome-ai-chat'), 'interaction' => __('After the visitor\'s first action (scroll, tap, key)', 'needtome-ai-chat'), 'delay' => __('After a delay', 'needtome-ai-chat')], (string) $o['load'], 'aiworker-chat-load')
                .' <span class="aiworker-chat-delay"><input id="aiworker-chat-delay" type="number" min="1" max="120" name="'.$name.'[delay]" value="'.esc_attr((string) $o['delay']).'"> '.esc_html__('seconds', 'needtome-ai-chat').'</span>',
                esc_html__('A later start does not slow the page down.', 'needtome-ai-chat'), 'aiworker-chat-load');
            $langs = ['auto' => __('Auto: the language of the page', 'needtome-ai-chat')] + aiworker_chat_site_languages();
            if (! isset($langs[$o['lang']])) $langs[(string) $o['lang']] = (string) $o['lang'];
            aiworker_chat_row(__('Language', 'needtome-ai-chat'), $sel('lang', $langs, (string) $o['lang'], 'aiworker-chat-lang'),
                esc_html__('«Auto» speaks the language of the page the visitor opened (its URL prefix or language subdomain, then its lang attribute), otherwise the browser\'s. A chosen language is used on every page.', 'needtome-ai-chat'), 'aiworker-chat-lang');
            ?>
            <?php if ($oauth) aiworker_chat_lazy('channels'); else echo wp_kses(aiworker_chat_channels_block(), aiworker_chat_channels_kses()); // the channel states come from kra.name ?>
          </section>

          <section class="aiworker-chat-card">
            <h2><span class="aiworker-chat-num">4</span><?php esc_html_e('Features', 'needtome-ai-chat'); ?></h2>
            <?php
            if (count(aiworker_chat_ids()) > 1 && ! $linkedMode) aiworker_chat_row(__('Greeting teaser', 'needtome-ai-chat'), '<select name="'.$name.'[greet_popup]"><option value=""'.selected($o['greet_popup'], null, false).'>'.esc_html__('As set for the chat at kra.name', 'needtome-ai-chat').'</option><option value="1"'.selected($o['greet_popup'], 1, false).'>'.esc_html__('Enabled', 'needtome-ai-chat').'</option><option value="0"'.selected($o['greet_popup'] === 0, true, false).'>'.esc_html__('Disabled', 'needtome-ai-chat').'</option></select>');
            $voiceEditable = $voiceAllowed || $unknown;
            $voice = '<label class="aiworker-chat-check'.($voiceEditable ? '' : ' is-off').'"><input type="checkbox" name="'.$name.'[voice]" value="1" '.checked($o['voice'] && $voiceEditable, true, false).' '.disabled(! $voiceEditable, true, false).'> '.esc_html__('Show the microphone: visitors dictate instead of typing', 'needtome-ai-chat').'</label>';
            if (! $voiceEditable) $voice .= '<span class="aiworker-chat-paid">'.esc_html__('Paid add-on', 'needtome-ai-chat').'</span>';
            aiworker_chat_row(__('Voice input', 'needtome-ai-chat'), $voice, $voiceEditable ? '' : wp_kses(sprintf(/* translators: %s: link to the kra.name billing page */ __('Not included in your plan. Voice input is a paid add-on of the chat: turn it on in %s.', 'needtome-ai-chat'), '<a href="https://kra.name/app/panel/billing" target="_blank" rel="noopener">'.esc_html__('«Subscription»', 'needtome-ai-chat').'</a>'), ['a' => ['href' => [], 'target' => [], 'rel' => []]]));
            aiworker_chat_row(__('Dialog rating', 'needtome-ai-chat'), '<label class="aiworker-chat-check"><input type="checkbox" name="'.$name.'[rating]" value="1" '.checked($o['rating'], 1, false).'> '.esc_html__('Ask for 1–5 stars when the consultant has answered every question', 'needtome-ai-chat').'</label>', esc_html__('If the rating is off for the chat at kra.name, this box does not turn it on.', 'needtome-ai-chat'));
            $privacyHint = $privacyUrl ? '<a href="'.esc_url($privacyUrl).'" target="_blank" rel="noopener">'.esc_html($privacyUrl).'</a>' : esc_html__('No privacy page is chosen: set it in Settings → Privacy, otherwise the link does not appear.', 'needtome-ai-chat');
            if (count(aiworker_chat_ids()) > 1) aiworker_chat_row(__('Privacy policy URL', 'needtome-ai-chat'), '<input type="url" class="regular-text" name="'.$name.'[privacy_url]" value="'.esc_attr((string) $o['privacy_url']).'">', __('Leave empty to use the site privacy policy.', 'needtome-ai-chat'));
            $stepNo = 4;
            aiworker_chat_row(__('Privacy policy', 'needtome-ai-chat'), '<label class="aiworker-chat-check"><input type="checkbox" name="'.$name.'[privacy]" value="1" '.checked($o['privacy'], 1, false).'> '.esc_html__('Show a link to this site\'s privacy policy in the chat', 'needtome-ai-chat').'</label>', $privacyHint);
            ?>
          </section>

          <?php if ($linkedMode) { aiworker_chat_lazy('chat', ++$stepNo, true); aiworker_chat_lazy('payment', ++$stepNo, true); } ?>

          <div class="aiworker-chat-save"><?php submit_button(__('Save settings', 'needtome-ai-chat'), 'primary large', 'submit', false); ?><button type="button" class="button aiworker-chat-reset" hidden><?php esc_html_e('Reset preview', 'needtome-ai-chat'); ?></button><span class="aiworker-chat-reset-done description" role="status" hidden><?php esc_html_e('Preview reset to the saved settings.', 'needtome-ai-chat'); ?></span><span class="description"><?php esc_html_e('The preview changes at once; the site gets the changes after saving.', 'needtome-ai-chat'); ?></span></div>
        </div>
        <aside class="aiworker-chat-side"><?php if ($oauth) aiworker_chat_lazy('usage', 0, true); ?><?php aiworker_chat_preview($o, (array) ($check['persona'] ?? []), $voiceAllowed); ?></aside>
      </form>
      <?php aiworker_chat_pick_forms(); aiworker_chat_list_form(); ?>
      <?php aiworker_chat_assistant_forms(); ?>
      <?php Aiworker_Chat_Profile::support_box(__('NeedToMe AI Chat', 'needtome-ai-chat')); ?>
      <?php elseif ($activeTab === 'kb') : ?>
        <?php aiworker_chat_tabs('kb'); aiworker_chat_kb_tab(); ?>
      <?php elseif ($activeTab === 'assistant') : ?>
        <?php aiworker_chat_tabs('assistant'); aiworker_chat_assistant_tab(); ?>
      <?php endif; ?>
    </div>
    <?php
}

/**
 * Live preview, as on the chat's page at kra.name: the widget in demo mode (nothing is sent, no visitor dialogs are
 * created) sits on this very page — the chat button in its corner with the greeting next to it, the window opens on a
 * click. Every change in the form (look, position, offset) is applied to it at once, before saving. Without a key it
 * shows a sample consultant. The «inside a page block» design is shown in the side column.
 */
function aiworker_chat_preview(array $o, array $persona, bool $voiceAllowed): void {
    $colors = [];
    foreach ((array) $o['colors'] as $k => $v) if (in_array($k, AIWORKER_CHAT_COLOR_KEYS, true)) $colors[] = $k.':'.$v;
    // «auto» in the preview speaks the language this settings page is shown in: Polylang's admin filter
    // (?lang=ru) when it is set, otherwise the user's admin language.
    $autoLang = substr(aiworker_chat_polylang_admin_locale() ?: (string) get_user_locale(), 0, 2) ?: 'en';
    $lang = aiworker_chat_preview_lang($o);
    $chatScheme = (string) ($persona['scheme'] ?? 'light');
    $hasKey = aiworker_chat_valid_key((string) ($o['key'] ?? ''));
    $widgetVersion = (string) get_option('aiworker_chat_widget_version', '');
    if (! preg_match('/^[A-Za-z0-9._-]{1,40}$/', $widgetVersion)) $widgetVersion = gmdate('YmdH');
    $attrs = [
        'src' => aiworker_chat_base().'/widget.js?v='.rawurlencode($widgetVersion), 'data-demo' => '1', 'data-base' => aiworker_chat_base(), 'data-greet' => '1',
        'data-name' => (string) ($persona['name'] ?? '') ?: __('Anna', 'needtome-ai-chat'), 'data-title' => (string) ($persona['title'] ?? '') ?: __('Consultant', 'needtome-ai-chat'), 'data-avatar' => (string) ($persona['avatar'] ?? ''),
        'data-tab-label' => (string) ($persona['tab_label'] ?? ''), 'data-lang' => $lang ?: 'en',
        'data-demo-note' => __('This is a preview in the WordPress settings: AI answers, voice and files work in the real chat on the site.', 'needtome-ai-chat'),
        'data-mount' => 'aiworker-chat-inline', 'data-height' => (string) $o['height'],
        'data-theme' => (string) $o['theme'], 'data-scheme' => (string) ($o['scheme'] ?: $chatScheme), 'data-colors' => implode(';', $colors),
        'data-color' => (string) $o['color'], 'data-position' => (string) $o['position'], 'data-voice' => $o['voice'] && $voiceAllowed ? '1' : '0', 'data-rating' => $o['rating'] ? '1' : '0',
    ];
    if (! empty($persona['greeting'])) $attrs['data-greeting'] = (string) wp_json_encode($persona['greeting']);
    if (! empty($persona['launcher_icons'])) $attrs += ['data-launcher-icons' => (string) wp_json_encode($persona['launcher_icons']), 'data-launcher-icon' => (string) ($persona['launcher_icon'] ?? 'chat'), 'data-launcher-icon-url' => (string) ($persona['launcher_icon_url'] ?? '')];
    if (! empty($persona['suggestions'])) $attrs['data-suggestions'] = (string) wp_json_encode($persona['suggestions']);
    if ($o['offset_x'] !== '') $attrs['data-offset-x'] = (string) $o['offset_x'];
    if ($o['offset_y'] !== '') $attrs['data-offset-y'] = (string) $o['offset_y'];
    $attrs['defer'] = true;
    $cfg = wp_json_encode(['option' => aiworker_chat_option_name(AIWORKER_CHAT_OPTION), 'chatScheme' => $chatScheme, 'keys' => AIWORKER_CHAT_COLOR_KEYS, 'autoLang' => $autoLang, 'voiceAllowed' => $voiceAllowed, 'script' => $attrs, 'autoLoad' => $hasKey], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>
      <div class="aiworker-chat-preview" data-config="<?php echo esc_attr((string) $cfg); ?>">
      <h2><?php esc_html_e('Live preview', 'needtome-ai-chat'); ?></h2>
      <p class="aiworker-chat-preview__actions"><button type="button" class="button" id="aiworker-chat-preview-load"<?php echo $hasKey ? ' hidden' : ''; ?>><?php esc_html_e('Load live preview', 'needtome-ai-chat'); ?></button><button type="button" class="button" id="aiworker-chat-open" data-open-label="<?php esc_attr_e('Open the chat window', 'needtome-ai-chat'); ?>" data-close-label="<?php esc_attr_e('Close the chat window', 'needtome-ai-chat'); ?>"<?php echo $hasKey ? '' : ' hidden'; ?>><?php esc_html_e('Open the chat window', 'needtome-ai-chat'); ?></button></p>
      <p><?php echo $hasKey ? esc_html__('The chat button in the corner of this page is your chat with the look from this form, exactly as visitors will see it on the site. Every change, including the position and the offset, appears at once, before saving.', 'needtome-ai-chat') : esc_html__('This sample consultant preview loads widget.js from kra.name in your browser only when you click “Load live preview”. Every change, including the position and the offset, appears at once, before saving.', 'needtome-ai-chat'); ?></p>
      <div class="aiworker-chat-inline-only aiworker-chat-inline-preview"><p class="description"><?php esc_html_e('«Inside a page block»: this is how the chat stands in the block of a page.', 'needtome-ai-chat'); ?></p><div id="aiworker-chat-inline"></div></div>
    </div>
    <?php
}

function aiworker_chat_asset_url(string $asset): string {
    return plugins_url($asset, __FILE__);
}

function aiworker_chat_asset_version(string $asset): string {
    $path = plugin_dir_path(__FILE__).$asset;
    return AIWORKER_CHAT_VERSION.'.'.(int) @filemtime($path);
}

/** Admin interface assets and their translated, nonce-protected settings; only this plugin's screen needs them. */
function aiworker_chat_admin_assets(string $hook): void {
    if ($hook !== 'needtome_page_aiworker-chat') return;
    $tab = aiworker_chat_admin_tab();
    $settingsScreen = $tab === 'settings';
    // WordPress's own colour picker is needed only beside the settings form.
    if ($settingsScreen) wp_enqueue_style('wp-color-picker');
    $style = 'assets/admin/admin.css';
    wp_enqueue_style('aiworker-chat-admin', aiworker_chat_asset_url($style), [], aiworker_chat_asset_version($style));
    $configAsset = 'assets/admin/config.js';
    wp_enqueue_script('aiworker-chat-admin-config', aiworker_chat_asset_url($configAsset), [], aiworker_chat_asset_version($configAsset), true);
    $scripts = [];
    if ($settingsScreen) $scripts += [
        'settings.js' => ['wp-color-picker'],
        'assistant-tabs.js' => [],
        'channels.js' => [],
        'signin.js' => [],
        'chat-settings.js' => [],
        'kb-remote.js' => [],
        'lazy-blocks.js' => [],
        'mcp-help.js' => [],
    ];
    if ($tab === 'kb') $scripts += [
        'signin.js' => [],
        'chat-settings.js' => [],
        'kb-remote.js' => [],
        'kb-preview.js' => [],
        'lazy-blocks.js' => [],
    ];
    if ($tab === 'assistant') $scripts += ['assistant-tabs.js' => [], 'mcp-help.js' => []];
    foreach ($scripts as $file => $dependencies) {
        $asset = 'assets/admin/'.$file;
        $handle = 'aiworker-chat-'.str_replace('.js', '', $file);
        $dependencies[] = 'aiworker-chat-admin-config';
        wp_enqueue_script($handle, aiworker_chat_asset_url($asset), $dependencies, aiworker_chat_asset_version($asset), true);
    }
    $assistantStyleNeeded = $tab === 'assistant' || (in_array($tab, ['', 'settings', 'kb'], true) && aiworker_chat_is_connected() && aiworker_chat_options()['key'] !== '');
    if ($assistantStyleNeeded) {
        $asset = 'assets/admin/assistant.css';
        wp_enqueue_style('aiworker-chat-assistant', aiworker_chat_asset_url($asset), ['aiworker-chat-admin'], aiworker_chat_asset_version($asset));
    }
    $ajax = aiworker_chat_admin_ajax_url();
    $config = [
        'signin' => ['ajax' => $ajax, 'nonce' => wp_create_nonce('aiworker_chat_signin_status')],
        'list' => ['ajax' => $ajax, 'lang' => aiworker_chat_wizard_lang()],
        'lazy' => ['ajax' => $ajax, 'nonce' => wp_create_nonce('aiworker_chat_lazy'), 'failed' => __('kra.name did not answer. Reload the page to try again.', 'needtome-ai-chat'), 'loading' => __('Loading from kra.name…', 'needtome-ai-chat'), 'lang' => aiworker_chat_wizard_lang()],
        'remote' => ['ajax' => $ajax, /* translators: 1: current page, 2: number of pages */ 'faqPage' => __('Page %1$d of %2$d', 'needtome-ai-chat'), 'nonce' => wp_create_nonce('aiworker_chat_kb_remote'), 'saving' => __('Saving…', 'needtome-ai-chat'), 'done' => __('Done.', 'needtome-ai-chat'), 'failed' => __('Not saved: kra.name answered with an error. Try again a bit later.', 'needtome-ai-chat'), 'lang' => aiworker_chat_wizard_lang()],
        'preview' => ['ajax' => $ajax, 'nonce' => wp_create_nonce('aiworker_chat_kb_preview'), 'field' => aiworker_chat_option_name(AIWORKER_CHAT_KB_OPTION).'[', 'loading' => __('Loading…', 'needtome-ai-chat'), 'failed' => __('The preview could not be loaded. Try again.', 'needtome-ai-chat')],
        'mcp' => ['copied' => __('Copied', 'needtome-ai-chat')],
        'settings' => [
            'saveFailed' => __('Not saved: kra.name did not accept the changes of a block above (see its message).', 'needtome-ai-chat'),
            't' => [
                'saving' => __('Saving…', 'needtome-ai-chat'), 'saved' => __('Saved to kra.name.', 'needtome-ai-chat'), 'nothing' => __('Nothing has changed.', 'needtome-ai-chat'),
                'stale' => __('The chat was changed at kra.name after this page was opened. Reload the page to see those changes, then make yours again.', 'needtome-ai-chat'),
                'invalid' => __('kra.name did not accept some values: see the fields marked below.', 'needtome-ai-chat'), 'failed' => __('Not saved: kra.name answered with an error. Try again a bit later.', 'needtome-ai-chat'),
                'reload' => __('Reload the page', 'needtome-ai-chat'), 'testing' => __('Sending a test message…', 'needtome-ai-chat'), 'noChannels' => __('Delivery is off or no channel is saved yet.', 'needtome-ai-chat'),
                'translating' => __('Translating…', 'needtome-ai-chat'), 'translated' => __('The empty fields are filled with the translation. Check them and press «Save settings».', 'needtome-ai-chat'),
                'nothingToTranslate' => __('Nothing to translate: the fields of the other languages are filled already.', 'needtome-ai-chat'),
                'iconUploading' => __('Uploading…', 'needtome-ai-chat'), 'iconRemoving' => __('Removing…', 'needtome-ai-chat'),
                'iconUploaded' => __('Your image is uploaded to kra.name and chosen as the icon.', 'needtome-ai-chat'), 'iconRemoved' => __('Your image is removed; the standard icon is back.', 'needtome-ai-chat'),
                'iconBad' => __('Choose a PNG, JPEG or WebP image up to 1 MB.', 'needtome-ai-chat'),
            ],
        ],
    ];
    $handle = 'aiworker-chat-admin-config';
    wp_add_inline_script($handle, 'window.aiworkerChatAdmin = '.wp_json_encode($config).';', 'before');
}
add_action('admin_enqueue_scripts', 'aiworker_chat_admin_assets');

add_action('wp_enqueue_scripts', static function (): void {
    $options = aiworker_chat_loader_options();
    if (! $options['chats']) return;
    aiworker_chat_widget_version_due();
    // the file's time in the version too, so visitors' browsers do not keep an old loader of the same version
    wp_enqueue_script('aiworker-chat-loader', aiworker_chat_asset_url('loader.js'), [], aiworker_chat_asset_version('loader.js'), true);
    // The options ride on the loader's own tag, not in an inline script, so a CSP without 'unsafe-inline' or nonces
    // still runs the chat (loader.js reads the attribute with JSON.parse).
    $json = (string) wp_json_encode($options);
    add_filter('script_loader_tag', static function (string $tag, string $handle) use ($json): string {
        if ($handle !== 'aiworker-chat-loader') return $tag;
        // the nonce of a 'strict-dynamic' policy (aiworker_chat_csp_merge), unless a security plugin has added one
        $nonce = ! empty($GLOBALS['aiworker_chat_csp_nonce']) && ! preg_match('/\snonce=/i', $tag) ? ' nonce="'.esc_attr((string) $GLOBALS['aiworker_chat_csp_nonce']).'"' : '';
        return (string) preg_replace('/<script\b/', '<script data-aiworker-chat-options="'.esc_attr($json).'"'.$nonce, $tag, 1);
    }, 10, 2);
});

// [aiworker_chat]: the block the inline chat is placed into (the same element id as in the settings).
add_shortcode('aiworker_chat', static function ($atts = []): string {
    $o = aiworker_chat_options();
    $key = is_array($atts) && isset($atts['key']) ? (string) $atts['key'] : '';
    $id = is_array($atts) && isset($atts['id']) ? (string) $atts['id'] : '';
    foreach (aiworker_chat_chats() as $chat) if (($key !== '' && $key === $chat['key']) || ($id !== '' && $id === $chat['id'])) { $o = $chat; break; }
    $mount = (string) ($o['mount'] ?? 'aiworker-chat');
    return substr($mount, 0, 1) === '.' ? '<div class="'.esc_attr(substr($mount, 1)).'"></div>' : '<div id="'.esc_attr($mount).'"></div>';
});

register_activation_hook(__FILE__, static function ($networkWide = false): void {
    if ($networkWide && is_multisite()) {
        foreach (get_sites(['number' => 0, 'fields' => 'ids']) as $blogId) {
            switch_to_blog((int) $blogId);
            try { aiworker_chat_restore_removal_jobs(); } finally { restore_current_blog(); }
        }
        return;
    }
    aiworker_chat_restore_removal_jobs();
});

register_deactivation_hook(__FILE__, static function ($networkWide = false): void {
    $clear = static function (): void {
        wp_unschedule_hook('aiworker_chat_kb_sync');
        wp_unschedule_hook('aiworker_chat_kb_remove');
        wp_clear_scheduled_hook('aiworker_chat_widget_version_refresh');
        wp_clear_scheduled_hook('aiworker_chat_kb_daily');
    };
    if ($networkWide && is_multisite()) {
        foreach (get_sites(['number' => 0, 'fields' => 'ids']) as $blogId) {
            switch_to_blog((int) $blogId);
            try { $clear(); } finally { restore_current_blog(); }
        }
        return;
    }
    $clear();
});
