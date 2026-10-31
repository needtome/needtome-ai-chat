<?php
/**
 * Plugin Name: NeedToMe AI Chat for Your Site
 * Plugin URI: https://needtome.com/needtome-ai-chat/
 * Description: An AI consultant in a chat on your site: answers visitors 24/7 from your own knowledge base (site pages, documents, price lists), speaks the visitor's language, collects leads and hands a dialog over to a person. Requires an account at <a href="https://kra.name/" target="_blank" rel="noopener">kra.name</a>, where the chat, its knowledge and its answers are set up.
 * Version: 0.10.2
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Parad0x
 * Author URI: https://needtome.com/
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: aiworker-chat
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

const AIWORKER_CHAT_OPTION = 'aiworker_chat_options';
const AIWORKER_CHAT_VERSION = '0.10.2';
/** Keys of the colour schemes and of the advanced colours of the widget (the same keys as at kra.name); '' = as saved in the chat. */
const AIWORKER_CHAT_SCHEME_KEYS = ['', 'light', 'dim', 'dark', 'auto'];
require_once __DIR__.'/includes/kb-sync.php';
require_once __DIR__.'/includes/kb-site.php';
require_once __DIR__.'/includes/products-sync.php';
require_once __DIR__.'/includes/mcp-help.php';
require_once __DIR__.'/includes/channels.php';
require_once __DIR__.'/includes/connect.php';
require_once __DIR__.'/includes/assistant.php';
require_once __DIR__.'/includes/chat-pick.php';
require_once __DIR__.'/includes/chat-settings.php';
require_once __DIR__.'/includes/lazy-blocks.php';
require_once __DIR__.'/includes/kb-remote.php';
require_once __DIR__.'/includes/needtome-profile.php';

const AIWORKER_CHAT_COLOR_KEYS = ['header_bg', 'header_text', 'bg', 'surface', 'text', 'muted', 'border', 'visitor_bubble', 'visitor_text', 'ai_bubble', 'input_bg'];

// The settings screen follows Polylang's admin language filter (?lang=ru or the saved filter),
// even when the WordPress admin itself stays in another language. Other screens are unchanged.
// Load without a $locale argument: since WP 6.5 a catalog loaded for another locale is not used.
add_action('admin_init', static function (): void {
    $locale = aiworker_chat_polylang_admin_locale();
    if ($locale !== '' && $locale !== determine_locale()) {
        $mofile = WP_LANG_DIR.'/plugins/aiworker-chat-'.$locale.'.mo';
        if (is_readable($mofile)) {
            unload_textdomain('aiworker-chat');
            load_textdomain('aiworker-chat', $mofile);
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
    return ['' => __('As set for the chat at kra.name', 'aiworker-chat'), 'light' => __('Light', 'aiworker-chat'), 'dim' => __('Dim (grey-blue)', 'aiworker-chat'),
        'dark' => __('Dark', 'aiworker-chat'), 'auto' => __('Follow the visitor\'s system', 'aiworker-chat')];
}

function aiworker_chat_color_labels(): array {
    return ['header_bg' => __('Header: background', 'aiworker-chat'), 'header_text' => __('Header: text', 'aiworker-chat'), 'bg' => __('Conversation background', 'aiworker-chat'),
        'surface' => __('Window and panels', 'aiworker-chat'), 'text' => __('Main text', 'aiworker-chat'), 'muted' => __('Secondary text', 'aiworker-chat'),
        'border' => __('Borders and lines', 'aiworker-chat'), 'visitor_bubble' => __('Visitor messages: background', 'aiworker-chat'), 'visitor_text' => __('Visitor messages: text', 'aiworker-chat'),
        'ai_bubble' => __('Consultant answers: background', 'aiworker-chat'), 'input_bg' => __('Input field: background', 'aiworker-chat')];
}

/** The kra.name installation the widget comes from; a developer may point a test site elsewhere with this filter. */
function aiworker_chat_base(): string {
    $base = (string) apply_filters('aiworker_chat_base', 'https://kra.name/app');
    return preg_match('#^https://[^\s"\'<>]+$#', $base) ? rtrim($base, '/') : 'https://kra.name/app';
}

function aiworker_chat_defaults(): array {
    return ['enabled' => 0, 'key' => '', 'theme' => 'classic', 'color' => '#4f46e5', 'position' => 'right', 'voice' => 1, 'rating' => 1, 'privacy' => 1, 'mount' => 'aiworker-chat', 'height' => 560,
        'load' => 'immediate', 'delay' => 5, 'lang' => 'auto', 'scheme' => '', 'colors' => [], 'pages_mode' => 'all', 'pages' => '', 'offset_x' => '', 'offset_y' => ''];
}

function aiworker_chat_options(): array {
    return wp_parse_args((array) get_option(AIWORKER_CHAT_OPTION, []), aiworker_chat_defaults());
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
 * Asks kra.name for this chat's configuration the way a visitor's browser would (Origin = this site) and explains the
 * answer: a wrong key, a domain missing from the chat's allowed sites, an unfinished chat, or ready.
 * @return array{state: string, text: string}
 */
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
                if ((int) ($c['id'] ?? 0) !== (int) get_option(AIWORKER_CHAT_LINKED_OPTION, 0)) continue;
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

function aiworker_chat_check(string $key, bool $fresh = false): array {
    if (! aiworker_chat_valid_key($key)) return ['state' => 'error', 'text' => __('The key is missing or has a wrong format (wk_ and 24 letters or digits).', 'aiworker-chat'), 'voice_allowed' => false];
    $cached = $fresh ? false : get_transient('aiworker_chat_check');
    // the texts are in the screen's language, so a check cached in another language is made again
    // the consultant's name and role come in the preview's language (with «auto», kra.name answers in the chat's
    // default language, which put an English name into a Russian preview)
    $lang = aiworker_chat_preview_lang(aiworker_chat_options());
    if (is_array($cached) && ($cached['key'] ?? '') === $key && ($cached['locale'] ?? '') === determine_locale() && ($cached['lang'] ?? '') === $lang && isset($cached['voice_allowed'], $cached['unreachable'], $cached['http'], $cached['texts'])) return $cached;
    $origin = untrailingslashit(home_url());
    $response = wp_remote_get(aiworker_chat_base().'/api/web/'.rawurlencode($key).'/config?lang='.rawurlencode($lang), ['timeout' => 10, 'headers' => ['Origin' => $origin, 'Accept' => 'application/json']]);
    // http and setup_required feed the checklist of step 1 (aiworker_chat_setup_steps)
    $result = ['voice_allowed' => false, 'unreachable' => false, 'http' => 0, 'setup_required' => false];
    if (is_wp_error($response)) {
        /* translators: %s: the network error */
        $result = ['state' => 'error', 'unreachable' => true, 'text' => sprintf(__('kra.name cannot be reached from this site: %s', 'aiworker-chat'), $response->get_error_message())] + $result;
    } else {
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $result['http'] = $code;
        $result['setup_required'] = $code === 200 && is_array($body) && ! empty($body['setup_required']);
        $host = (string) wp_parse_url($origin, PHP_URL_HOST);
        /* translators: %s: this site's domain */
        if ($code === 403) $result += ['state' => 'error', 'text' => sprintf(__('The domain %s is not in this chat\'s allowed sites at kra.name. Until it is added, the chat does not appear on the site.', 'aiworker-chat'), $host)];
        elseif ($code === 404) $result += ['state' => 'error', 'text' => __('No chat with this key, or the chat is switched off (for example, its subscription ended). Check the key on the chat\'s card at kra.name.', 'aiworker-chat')];
        elseif ($code === 200 && is_array($body) && ! empty($body['ok']) && ! empty($body['setup_required'])) $result = ['state' => 'warning', 'text' => __('The key and the domain are fine, but the chat is not set up yet: visitors will see a request to finish the setup (instructions and knowledge base at kra.name).', 'aiworker-chat')] + $result;
        /* translators: 1: this site's domain, 2: the chat's name */
        elseif ($code === 200 && is_array($body) && ! empty($body['ok'])) $result = ['state' => 'success', 'text' => sprintf(__('Connected: the key is right, %1$s is allowed, the chat «%2$s» is ready to answer.', 'aiworker-chat'), $host, sanitize_text_field((string) ($body['name'] ?? '')))] + $result;
        /* translators: %d: HTTP status code */
        else $result = ['state' => 'error', 'unreachable' => true, 'text' => sprintf(__('Unexpected answer from kra.name (HTTP %d).', 'aiworker-chat'), $code)] + $result;
        // the consultant's name, role, avatar, the chat's own scheme and its paid add-ons feed the preview and the form
        if ($code === 200 && is_array($body) && ! empty($body['ok'])) {
            $result['voice_allowed'] = ! empty($body['voice_allowed']);
            $result['persona'] = ['name' => sanitize_text_field((string) ($body['name'] ?? '')), 'title' => sanitize_text_field((string) ($body['title'] ?? '')),
                'tab_label' => sanitize_text_field((string) ($body['tab_label'] ?? '')), 'avatar' => esc_url_raw((string) ($body['avatar'] ?? ''), ['https']),
                'scheme' => in_array($body['scheme'] ?? '', ['light', 'dim', 'dark', 'auto'], true) ? $body['scheme'] : 'light',
                // the greeting (one per language) and the quick questions, so the preview shows the chat's own
                'greeting' => array_map(static function ($g): string { return sanitize_textarea_field((string) $g); }, array_filter((array) ($body['greeting'] ?? []), 'is_string')),
                'suggestions' => array_values(array_filter(array_map('sanitize_text_field', array_map('strval', array_filter((array) ($body['suggestions'] ?? []), 'is_scalar'))), 'strlen'))];
        }
    }
    $result['key'] = $key;
    $result['texts'] = 1; // a check cached before the greeting was kept is made again
    $result['locale'] = determine_locale();
    $result['lang'] = $lang;
    set_transient('aiworker_chat_check', $result, 5 * MINUTE_IN_SECONDS);
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
    $loader = aiworker_chat_origin(plugins_url('loader.js', __FILE__));
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
    $o = aiworker_chat_options();
    if (empty($o['enabled']) || ! aiworker_chat_valid_key((string) $o['key']) || headers_sent()) return $template;
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
    register_setting('aiworker_chat', AIWORKER_CHAT_OPTION, ['type' => 'array', 'sanitize_callback' => 'aiworker_chat_sanitize', 'default' => aiworker_chat_defaults()]);
});

// Saved settings (a new key, a domain just allowed at kra.name) are checked afresh on the next settings page.
add_action('update_option_'.AIWORKER_CHAT_OPTION, static function (): void { delete_transient('aiworker_chat_check'); delete_transient('aiworker_chat_csp'); });

add_action('admin_menu', static function (): void {
    Aiworker_Chat_Profile::menu(__('NeedToMe AI Chat', 'aiworker-chat'), 'aiworker-chat', 'aiworker_chat_settings_page', 'manage_options', __('AI Chat', 'aiworker-chat'));
});

add_filter('plugin_action_links_'.plugin_basename(__FILE__), static function (array $links): array {
    array_unshift($links, '<a href="'.esc_url(Aiworker_Chat_Profile::url('aiworker-chat')).'">'.esc_html__('Settings', 'aiworker-chat').'</a>');
    return $links;
});

/** HTML elements used by the settings form controls assembled by this plugin. */
function aiworker_chat_admin_html_allowed(): array {
    $allowed = wp_kses_allowed_html('post');
    $allowed['details'] = ['class' => true, 'id' => true, 'open' => true];
    $allowed['input'] = ['type' => true, 'id' => true, 'name' => true, 'value' => true, 'class' => true, 'min' => true, 'max' => true, 'step' => true,
        'pattern' => true, 'placeholder' => true, 'autocomplete' => true, 'spellcheck' => true, 'checked' => true, 'disabled' => true, 'list' => true, 'data-default-color' => true];
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
    echo '<div class="aiwc-row'.($class ? ' '.esc_attr($class) : '').'"><div class="aiwc-row__label">'.($for ? '<label for="'.esc_attr($for).'">'.esc_html($label).'</label>' : esc_html($label)).'</div>'
        .'<div class="aiwc-row__control">'.wp_kses($control, aiworker_chat_admin_html_allowed()).($hint !== '' ? '<p class="description">'.wp_kses($hint, wp_kses_allowed_html('post')).'</p>' : '').'</div></div>';
}

function aiworker_chat_settings_page(): void {
    if (! current_user_can('manage_options')) return;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab navigation.
    $activeTab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : 'settings';
    // MCP and Telegram are sub-tabs of the setup wizard in step 1 now: an old ?tab=mcp|telegram opens them there
    if (in_array($activeTab, ['mcp', 'telegram'], true)) { $_GET['aiwc_sub'] = $activeTab; $activeTab = 'settings'; }
    // The statistics tab exists only while AI Worker is connected; the wizard is then in step 1 of Settings.
    $oauth = aiworker_chat_is_connected();
    if (! in_array($activeTab, $oauth ? ['settings', 'kb', 'assistant', 'plugins', 'development'] : ['settings', 'kb', 'plugins', 'development'], true)) $activeTab = 'settings';
    $o = aiworker_chat_options();
    $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    $name = esc_attr(AIWORKER_CHAT_OPTION);
    $recheck = isset($_GET['aiwc_recheck']) && wp_verify_nonce(sanitize_text_field(wp_unslash((string) ($_GET['_wpnonce'] ?? ''))), 'aiwc_recheck');
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
    $pill = ! $o['key'] ? ['off', __('Not connected', 'aiworker-chat')] : ($connected ? ($check['state'] === 'success' ? ['ok', __('Connected', 'aiworker-chat')] : ['warn', __('Needs setup at kra.name', 'aiworker-chat')]) : ['err', __('Not working', 'aiworker-chat')]);
    $live = $o['enabled'] && $connected;
    aiworker_chat_admin_styles();
    ?>
    <div class="wrap aiwc">
      <div class="aiwc-head">
        <h1><?php esc_html_e('NeedToMe AI Chat', 'aiworker-chat'); ?> <span><?php esc_html_e('for your site', 'aiworker-chat'); ?></span></h1>
        <?php if ($activeTab === 'settings') : ?>
          <span class="aiwc-pill aiwc-pill--<?php echo esc_attr($pill[0]); ?>"><?php echo esc_html($pill[1]); ?></span>
          <span class="aiwc-pill aiwc-pill--<?php echo $live ? 'ok' : 'off'; ?>"><?php echo $live ? esc_html__('Shown on the site', 'aiworker-chat') : esc_html__('Hidden on the site', 'aiworker-chat'); ?></span>
        <?php endif; ?>
      </div>
      <h2 class="nav-tab-wrapper aiwc-tabs">
        <?php
        $tabs = ['settings' => __('Settings', 'aiworker-chat'), 'kb' => __('Knowledge base', 'aiworker-chat'), 'assistant' => __('Statistics', 'aiworker-chat')] + Aiworker_Chat_Profile::tab_labels();
        if (! $oauth) unset($tabs['assistant']);
        foreach ($tabs as $tab => $label) :
            $tabUrl = Aiworker_Chat_Profile::url('aiworker-chat', ['tab' => $tab]);
        ?>
          <a href="<?php echo esc_url($tabUrl); ?>" class="nav-tab <?php echo $activeTab === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
        <?php endforeach; ?>
      </h2>
      <?php if ($activeTab === 'settings') : ?>
      <?php aiworker_chat_assistant_notice(); ?>
      <?php if ($csp && ($csp['gaps'] || ! empty($csp['nonce']))): ?><div class="notice notice-error inline"><p><strong><?php esc_html_e('This site\'s Content-Security-Policy will block the chat.', 'aiworker-chat'); ?></strong><?php if ($csp['gaps']): ?> <?php esc_html_e('Use these directives in the CSP header of your theme or security plugin (each replaces the directive of the same name):', 'aiworker-chat'); ?><?php endif; ?></p>
        <?php if ($csp['gaps']): ?><p><code><?php echo esc_html(implode('; ', $csp['gaps'])); ?></code></p><?php endif; ?>
        <?php if (! empty($csp['nonce'])): ?><p><?php esc_html_e('The policy uses \'strict-dynamic\': the script tag aiworker-chat-loader needs the policy\'s nonce (security plugins that add nonces to WordPress scripts do this).', 'aiworker-chat'); ?></p><?php endif; ?>
        <p class="description"><?php echo wp_kses(__('Developers can take this list from <code>aiworker_chat_csp_sources()</code>.', 'aiworker-chat'), ['code' => []]); ?></p></div><?php endif; ?>
      <form method="post" action="options.php" class="aiwc-grid">
        <?php settings_fields('aiworker_chat'); ?>
        <div class="aiwc-main">

          <section class="aiwc-card aiwc-card--connect">
            <h2><span class="aiwc-num">1</span><?php esc_html_e('Connect your chat', 'aiworker-chat'); ?></h2>
            <?php
            // connected through AI Worker with the chat it linked: the chat is chosen in the box, the key is not typed
            $linkedMode = $oauth && $o['key'] !== '' && (int) get_option(AIWORKER_CHAT_LINKED_OPTION, 0) > 0;
            $steps = aiworker_chat_setup_steps((string) $o['key'], $check);
            $stepClass = static function (?bool $done): string { return $done === null ? '' : ($done ? ' class="is-done"' : ' class="is-missing"'); };
            ?>
            <p class="aiwc-lead"><?php echo wp_kses(sprintf(
                /* translators: %s: link to kra.name */
                __('The AI consultant\'s knowledge base and instructions are filled in at the %s client panel:', 'aiworker-chat'),
                '<a href="https://kra.name/" target="_blank" rel="noopener">kra.name</a>'), ['a' => ['href' => [], 'target' => [], 'rel' => []]]); ?></p>
            <?php $allDone = ! in_array(false, $steps, true) && ! in_array(null, $steps, true);
            // all four done: one ✓ line; the steps open on a click
            if ($allDone) : ?><details class="aiwc-steps-done"><summary><?php esc_html_e('Everything is connected', 'aiworker-chat'); ?></summary><?php endif; ?>
            <ol class="aiwc-steps aiwc-steps--check">
              <li<?php echo $stepClass($steps[1]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup. ?>><?php echo wp_kses(sprintf(/* translators: %s: link to the kra.name panel */ __('Sign up and create a site chat in the %s.', 'aiworker-chat'), '<a href="https://kra.name/app/panel" target="_blank" rel="noopener">'.esc_html__('kra.name panel', 'aiworker-chat').'</a>'), ['a' => ['href' => [], 'target' => [], 'rel' => []]]); ?></li>
              <li<?php echo $stepClass($steps[2]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup. ?>><?php esc_html_e('Choose the consultant and fill the chat\'s knowledge base.', 'aiworker-chat'); ?></li>
              <li<?php echo $stepClass($steps[3]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup. ?>><?php if ($linkedMode) : echo wp_kses(sprintf(/* translators: %s: this site's domain */ __('%s was added to the chat\'s allowed sites automatically.', 'aiworker-chat'), '<code>'.esc_html($host).'</code>'), ['code' => []]); else : ?><?php echo wp_kses(sprintf(/* translators: 1: this site's domain, 2: wildcard for its subdomains */ __('Add %1$s to the chat\'s allowed sites (www is included; %2$s allows every subdomain).', 'aiworker-chat'), '<code>'.esc_html($host).'</code>', '<code>*.'.esc_html(preg_replace('/^www\./', '', $host)).'</code>'), ['code' => []]); ?><?php endif; ?></li>
              <li<?php echo $stepClass($steps[4]); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Fixed markup. ?>><?php echo esc_html($linkedMode ? __('The chat\'s public key was put in automatically.', 'aiworker-chat') : __('Copy the public key wk_… from the chat\'s card and paste it here.', 'aiworker-chat')); ?></li>
            </ol>
            <?php if ($allDone) : ?></details><?php endif; ?>
            <div class="aiwc-connect-row<?php echo $linkedMode ? ' aiwc-connect-row--linked' : ''; ?>">
            <?php if ($linkedMode) : ?>
            <input type="hidden" name="<?php echo esc_attr($name); ?>[key]" value="<?php echo esc_attr($o['key']); ?>">
            <?php else : ?>
            <div class="aiwc-key">
              <label for="aiwc-key"><?php esc_html_e('Public chat key', 'aiworker-chat'); ?></label>
              <div class="aiwc-key__row">
                <input id="aiwc-key" type="text" name="<?php echo esc_attr($name); ?>[key]" value="<?php echo esc_attr($o['key']); ?>" pattern="wk_[A-Za-z0-9]{24}" placeholder="wk_xxxxxxxxxxxxxxxxxxxxxxxx" autocomplete="off" spellcheck="false">
                <button type="submit" class="button button-secondary" id="aiwc-key-save"<?php echo $o['key'] !== '' ? ' hidden' : ''; ?>><?php esc_html_e('Save and check', 'aiworker-chat'); ?></button>
              </div>
              <script>
              (function (i, b) { var saved = i.value; i.addEventListener('input', function () { b.hidden = i.value.trim() === saved; }); })(document.getElementById('aiwc-key'), document.getElementById('aiwc-key-save'));
              </script>
              <p class="description"><?php esc_html_e('The key only selects the chat; it is not a secret.', 'aiworker-chat'); ?></p>
            </div>
            <?php endif; ?>
            <?php aiworker_chat_pick_panel(); ?>
            </div>
            <?php if ($oauth && $o['key'] !== '') aiworker_chat_wizard_block($allDone); // all connected: the card starts folded ?>
            <?php // all fine is already the ✓ of the steps above; a problem keeps its line, which says what to fix
            if ($check && $check['state'] !== 'success'): ?>
              <div class="aiwc-status aiwc-status--<?php echo esc_attr($check['state']); ?>"><?php echo esc_html($check['text']); ?>
                <a href="<?php echo esc_url(wp_nonce_url(Aiworker_Chat_Profile::url('aiworker-chat', ['aiwc_recheck' => 1]), 'aiwc_recheck')); ?>"><?php esc_html_e('Check again', 'aiworker-chat'); ?></a></div>
            <?php endif; ?>
            <label class="aiwc-toggle<?php echo $canEnable ? '' : ' is-off'; ?>">
              <input type="checkbox" name="<?php echo esc_attr($name); ?>[enabled]" value="1" <?php checked($o['enabled'] && $canEnable); ?> <?php disabled(! $canEnable); ?>>
              <span class="aiwc-toggle__ui" aria-hidden="true"></span>
              <span><strong><?php esc_html_e('Show the chat on this site', 'aiworker-chat'); ?></strong>
                <small><?php echo $canEnable ? esc_html__('Visitors see the chat on the pages chosen below.', 'aiworker-chat') : esc_html__('Available once a working key is saved and checked.', 'aiworker-chat'); ?></small></span>
            </label>
          </section>

          <section class="aiwc-card">
            <h2><span class="aiwc-num">2</span><?php esc_html_e('Look', 'aiworker-chat'); ?></h2>
            <?php
            aiworker_chat_row(__('Design', 'aiworker-chat'), $sel('theme', ['classic' => __('Classic: button and window', 'aiworker-chat'), 'bar' => __('Bar at the bottom', 'aiworker-chat'), 'panel' => __('Side panel', 'aiworker-chat'), 'inline' => __('Inside a page block', 'aiworker-chat')], (string) $o['theme'], 'aiwc-theme'), '', 'aiwc-theme');
            aiworker_chat_row(__('Block id', 'aiworker-chat'), '<input id="aiwc-mount" type="text" class="regular-text" name="'.$name.'[mount]" value="'.esc_attr((string) $o['mount']).'" pattern="[#.]?[A-Za-z][A-Za-z0-9_\-]{0,63}">',
                wp_kses(sprintf(/* translators: 1: the shortcode, 2: the HTML element */ __('Put the %1$s shortcode into a post or page (or %2$s into the theme). Pages without the block show the usual button.', 'aiworker-chat'), '<code>[aiworker_chat]</code>', '<code>&lt;div id="'.esc_html((string) $o['mount']).'"&gt;&lt;/div&gt;</code>'), ['code' => []]), 'aiwc-mount', 'aiwc-inline-only');
            aiworker_chat_row(__('Block height, px', 'aiworker-chat'), '<input id="aiwc-height" type="number" min="320" max="1200" name="'.$name.'[height]" value="'.esc_attr((string) $o['height']).'">', '', 'aiwc-height', 'aiwc-inline-only');
            aiworker_chat_row(__('Accent colour', 'aiworker-chat'), '<input id="aiwc-color" class="aiwc-color aiwc-color--accent" type="text" name="'.$name.'[color]" value="'.esc_attr($o['color']).'" data-default-color="#4f46e5">', '', 'aiwc-color');
            aiworker_chat_row(__('Colour scheme', 'aiworker-chat'), $sel('scheme', aiworker_chat_schemes(), (string) $o['scheme'], 'aiwc-scheme'), esc_html__('«Follow the visitor\'s system» switches between light and dark by the device setting.', 'aiworker-chat'), 'aiwc-scheme');
            $colors = '<details class="aiwc-colors"'.(! empty($o['colors']) ? ' open' : '').'><summary>'.esc_html__('Change separate colours', 'aiworker-chat').'</summary><p class="description">'.esc_html__('Empty means the scheme\'s colour.', 'aiworker-chat').'</p><div class="aiwc-colors__grid">';
            foreach (aiworker_chat_color_labels() as $k => $label) $colors .= '<div class="aiwc-colors__item"><span class="aiwc-colors__name">'.esc_html($label).'</span><input class="aiwc-color" type="text" name="'.$name.'[colors]['.esc_attr($k).']" value="'.esc_attr((string) ($o['colors'][$k] ?? '')).'" data-default-color="" aria-label="'.esc_attr($label).'"></div>';
            aiworker_chat_row(__('Advanced colours', 'aiworker-chat'), $colors.'</div></details>');
            aiworker_chat_row(__('Position', 'aiworker-chat'), $sel('position', ['right' => __('Right', 'aiworker-chat'), 'left' => __('Left', 'aiworker-chat')], (string) $o['position'], 'aiwc-position'), '', 'aiwc-position');
            aiworker_chat_row(__('Button offset, px', 'aiworker-chat'), '<span class="aiwc-offset"><label>'.esc_html__('sideways', 'aiworker-chat').' <input id="aiwc-offset-x" type="number" min="-300" max="300" step="1" name="'.$name.'[offset_x]" value="'.esc_attr((string) $o['offset_x']).'" placeholder="—"></label>'
                .'<label>'.esc_html__('up / down', 'aiworker-chat').' <input id="aiwc-offset-y" type="number" min="-300" max="300" step="1" name="'.$name.'[offset_y]" value="'.esc_attr((string) $o['offset_y']).'" placeholder="—"></label></span>',
                esc_html__('Moves the chat button from its corner: positive values go right and down. Empty uses the offset set for the chat at kra.name. Watch the button in the corner of this page.', 'aiworker-chat'), 'aiwc-offset-x');
            ?>
          </section>

          <section class="aiwc-card">
            <h2><span class="aiwc-num">3</span><?php esc_html_e('Where to show the chat', 'aiworker-chat'); ?></h2>
            <?php
            aiworker_chat_row(__('Pages', 'aiworker-chat'), $sel('pages_mode', ['all' => __('On every page', 'aiworker-chat'), 'only' => __('Only on the pages listed', 'aiworker-chat'), 'except' => __('On every page except those listed', 'aiworker-chat')], (string) $o['pages_mode'], 'aiwc-pages-mode'), '', 'aiwc-pages-mode');
            aiworker_chat_row(__('Page list', 'aiworker-chat'), '<textarea id="aiwc-pages" name="'.$name.'[pages]" rows="5" class="large-text code" placeholder="/&#10;/contacts/&#10;/shop/*">'.esc_textarea((string) $o['pages']).'</textarea>',
                wp_kses(__('One page per line, without the site address: <code>/</code> is the home page, <code>/contacts/</code> one page, <code>/shop/*</code> a section with everything inside it.', 'aiworker-chat'), ['code' => []]), 'aiwc-pages', 'aiwc-pages-only');
            aiworker_chat_row(__('Show the chat', 'aiworker-chat'), $sel('load', ['immediate' => __('When the page opens', 'aiworker-chat'), 'interaction' => __('After the visitor\'s first action (scroll, tap, key)', 'aiworker-chat'), 'delay' => __('After a delay', 'aiworker-chat')], (string) $o['load'], 'aiwc-load')
                .' <span class="aiwc-delay"><input id="aiwc-delay" type="number" min="1" max="120" name="'.$name.'[delay]" value="'.esc_attr((string) $o['delay']).'"> '.esc_html__('seconds', 'aiworker-chat').'</span>',
                esc_html__('A later start does not slow the page down.', 'aiworker-chat'), 'aiwc-load');
            $langs = ['auto' => __('Auto: the language of the page', 'aiworker-chat')] + aiworker_chat_site_languages();
            if (! isset($langs[$o['lang']])) $langs[(string) $o['lang']] = (string) $o['lang'];
            aiworker_chat_row(__('Language', 'aiworker-chat'), $sel('lang', $langs, (string) $o['lang'], 'aiwc-lang'),
                esc_html__('«Auto» speaks the language of the page the visitor opened (its URL prefix or language subdomain, then its lang attribute), otherwise the browser\'s. A chosen language is used on every page.', 'aiworker-chat'), 'aiwc-lang');
            ?>
            <?php if ($oauth) aiworker_chat_lazy('channels'); else aiworker_chat_channels_block(); // the channel states come from kra.name ?>
          </section>

          <section class="aiwc-card">
            <h2><span class="aiwc-num">4</span><?php esc_html_e('Features', 'aiworker-chat'); ?></h2>
            <?php
            $voiceEditable = $voiceAllowed || $unknown;
            $voice = '<label class="aiwc-check'.($voiceEditable ? '' : ' is-off').'"><input type="checkbox" name="'.$name.'[voice]" value="1" '.checked($o['voice'] && $voiceEditable, true, false).' '.disabled(! $voiceEditable, true, false).'> '.esc_html__('Show the microphone: visitors dictate instead of typing', 'aiworker-chat').'</label>';
            if (! $voiceEditable) $voice .= '<span class="aiwc-paid">'.esc_html__('Paid add-on', 'aiworker-chat').'</span>';
            aiworker_chat_row(__('Voice input', 'aiworker-chat'), $voice, $voiceEditable ? '' : wp_kses(sprintf(/* translators: %s: link to the kra.name billing page */ __('Not included in your plan. Voice input is a paid add-on of the chat: turn it on in %s.', 'aiworker-chat'), '<a href="https://kra.name/app/panel/billing" target="_blank" rel="noopener">'.esc_html__('«Subscription»', 'aiworker-chat').'</a>'), ['a' => ['href' => [], 'target' => [], 'rel' => []]]));
            aiworker_chat_row(__('Dialog rating', 'aiworker-chat'), '<label class="aiwc-check"><input type="checkbox" name="'.$name.'[rating]" value="1" '.checked($o['rating'], 1, false).'> '.esc_html__('Ask for 1–5 stars when the consultant has answered every question', 'aiworker-chat').'</label>', esc_html__('If the rating is off for the chat at kra.name, this box does not turn it on.', 'aiworker-chat'));
            $privacyHint = $privacyUrl ? '<a href="'.esc_url($privacyUrl).'" target="_blank" rel="noopener">'.esc_html($privacyUrl).'</a>' : esc_html__('No privacy page is chosen: set it in Settings → Privacy, otherwise the link does not appear.', 'aiworker-chat');
            $stepNo = 4;
            aiworker_chat_row(__('Privacy policy', 'aiworker-chat'), '<label class="aiwc-check"><input type="checkbox" name="'.$name.'[privacy]" value="1" '.checked($o['privacy'], 1, false).'> '.esc_html__('Show a link to this site\'s privacy policy in the chat', 'aiworker-chat').'</label>', $privacyHint);
            ?>
          </section>

          <?php if ($linkedMode) { aiworker_chat_lazy('chat', ++$stepNo, true); aiworker_chat_lazy('payment', ++$stepNo, true); } ?>

          <div class="aiwc-save"><?php submit_button(__('Save settings', 'aiworker-chat'), 'primary large', 'submit', false); ?><button type="button" class="button aiwc-reset" hidden><?php esc_html_e('Reset preview', 'aiworker-chat'); ?></button><span class="aiwc-reset-done description" role="status" hidden><?php esc_html_e('Preview reset to the saved settings.', 'aiworker-chat'); ?></span><span class="description"><?php esc_html_e('The preview changes at once; the site gets the changes after saving.', 'aiworker-chat'); ?></span></div>
        </div>
        <aside class="aiwc-side"><?php if ($oauth) aiworker_chat_lazy('usage', 0, true); ?><?php aiworker_chat_preview($o, (array) ($check['persona'] ?? []), $voiceAllowed); ?></aside>
      </form>
      <?php aiworker_chat_pick_forms(); ?>
      <?php aiworker_chat_assistant_forms(); ?>
      <?php Aiworker_Chat_Profile::support_box(__('NeedToMe AI Chat', 'aiworker-chat')); ?>
      <?php elseif ($activeTab === 'kb') : ?>
        <?php aiworker_chat_kb_tab(); ?>
      <?php elseif ($activeTab === 'assistant') : ?>
        <?php aiworker_chat_assistant_tab(); ?>
      <?php else : ?>
        <?php Aiworker_Chat_Profile::tab($activeTab, 'aiworker-chat'); ?>
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
    $attrs = [
        // the preview always takes the current widget: kra.name serves it as immutable, so a daily version kept a widget
        // loaded before an update of the preview until the next day
        'src' => aiworker_chat_base().'/widget.js?v='.time(), 'data-demo' => '1', 'data-base' => aiworker_chat_base(), 'data-greet' => '1',
        'data-name' => (string) ($persona['name'] ?? '') ?: __('Anna', 'aiworker-chat'), 'data-title' => (string) ($persona['title'] ?? '') ?: __('Consultant', 'aiworker-chat'), 'data-avatar' => (string) ($persona['avatar'] ?? ''),
        'data-tab-label' => (string) ($persona['tab_label'] ?? ''), 'data-lang' => $lang ?: 'en',
        'data-demo-note' => __('This is a preview in the WordPress settings: AI answers, voice and files work in the real chat on the site.', 'aiworker-chat'),
        'data-mount' => 'aiwc-inline', 'data-height' => (string) $o['height'],
        'data-theme' => (string) $o['theme'], 'data-scheme' => (string) ($o['scheme'] ?: $chatScheme), 'data-colors' => implode(';', $colors),
        'data-color' => (string) $o['color'], 'data-position' => (string) $o['position'], 'data-voice' => $o['voice'] && $voiceAllowed ? '1' : '0', 'data-rating' => $o['rating'] ? '1' : '0',
    ];
    if (! empty($persona['greeting'])) $attrs['data-greeting'] = (string) wp_json_encode($persona['greeting']);
    if (! empty($persona['suggestions'])) $attrs['data-suggestions'] = (string) wp_json_encode($persona['suggestions']);
    if ($o['offset_x'] !== '') $attrs['data-offset-x'] = (string) $o['offset_x'];
    if ($o['offset_y'] !== '') $attrs['data-offset-y'] = (string) $o['offset_y'];
    $attrs['defer'] = true;
    $cfg = wp_json_encode(['option' => AIWORKER_CHAT_OPTION, 'chatScheme' => $chatScheme, 'keys' => AIWORKER_CHAT_COLOR_KEYS, 'autoLang' => $autoLang, 'voiceAllowed' => $voiceAllowed], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    ?>
      <div class="aiwc-preview" data-config="<?php echo esc_attr((string) $cfg); ?>">
      <h2><?php esc_html_e('Live preview', 'aiworker-chat'); ?></h2>
      <p class="aiwc-preview__actions"><button type="button" class="button" id="aiwc-open" data-open-label="<?php esc_attr_e('Open the chat window', 'aiworker-chat'); ?>" data-close-label="<?php esc_attr_e('Close the chat window', 'aiworker-chat'); ?>"><?php esc_html_e('Open the chat window', 'aiworker-chat'); ?></button></p>
      <p><?php echo $persona ? esc_html__('The chat button in the corner of this page is your chat with the look from this form, exactly as visitors will see it on the site. Every change, including the position and the offset, appears at once, before saving.', 'aiworker-chat') : esc_html__('The chat button in the corner of this page shows the look from this form with a sample consultant; after the key is saved it shows your chat\'s name and avatar. Every change, including the position and the offset, appears at once, before saving.', 'aiworker-chat'); ?></p>
      <div class="aiwc-inline-only aiwc-inline-preview"><p class="description"><?php esc_html_e('«Inside a page block»: this is how the chat stands in the block of a page.', 'aiworker-chat'); ?></p><div id="aiwc-inline"></div></div>
    </div>
    <?php echo wp_get_script_tag($attrs); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_script_tag escapes each HTML attribute. ?>
    <script>
    (function () {
      var preview = document.querySelector('.aiwc-preview'), form = document.querySelector('form.aiwc-grid'); if (!preview || !form) return;
      var c = JSON.parse(preview.getAttribute('data-config') || '{}');
      var field = function (n) { return form.querySelector('[name="' + c.option + '[' + n + ']"]'); };
      var hex = function (v) { return /^#[0-9a-fA-F]{6}$/.test(v || '') ? v.toLowerCase() : ''; };
      var px = function (n) { var v = field(n) && field(n).value.trim(); return /^-?\d{1,3}$/.test(v || '') ? parseInt(v, 10) : null; };
      var queued = false;
      function push() {
        if (queued) return; queued = true;
        requestAnimationFrame(function () {
          queued = false;
          var colors = {}; c.keys.forEach(function (k) { var i = form.querySelector('[name="' + c.option + '[colors][' + k + ']"]'), v = i && hex(i.value); if (v) colors[k] = v; });
          var lang = (field('lang') && field('lang').value) || 'auto', l = lang === 'auto' ? c.autoLang : lang, extra = {};
          // the greeting and quick questions as typed in «Chat texts», in the preview's language, before saving
          var g = document.querySelector('[data-cs-field="texts.' + l + '.greeting"]'), q = document.querySelectorAll('[data-cs-sugg="' + l + '"]');
          if (g && g.value.trim()) extra.greeting = g.value.trim(); // empty: the chat's own greeting stays
          var an = document.querySelector('[data-cs-field="ai_notice"]'), gp = document.querySelector('[data-cs-field="greet_popup"]');
          if (an) extra.aiNotice = an.checked; if (gp) extra.greet = gp.checked;
          if (q.length) extra.suggestions = Array.prototype.map.call(q, function (f) { return f.value.trim(); }).filter(Boolean);
          window.dispatchEvent(new CustomEvent('aiworker-preview-options', { detail: Object.assign(extra, {
            theme: field('theme').value, scheme: field('scheme').value || c.chatScheme, colors: colors, color: hex(field('color').value) || '#4f46e5',
            position: field('position').value, offsetX: px('offset_x'), offsetY: px('offset_y'), voice: c.voiceAllowed && field('voice').checked, rating: field('rating').checked,
            height: parseInt(field('height').value, 10) || 560, lang: l }) }));
        });
      }
      var rows = function () {
        document.querySelectorAll('.aiwc-inline-only').forEach(function (r) { r.style.display = field('theme').value === 'inline' ? '' : 'none'; });
        document.querySelectorAll('.aiwc-pages-only').forEach(function (r) { r.style.display = field('pages_mode').value === 'all' ? 'none' : ''; });
        var d = document.querySelector('.aiwc-delay'); if (d) d.style.display = field('load').value === 'delay' ? '' : 'none';
      };
      rows(); form.addEventListener('change', rows);
      form.addEventListener('input', push); form.addEventListener('change', push);
      // the «Chat texts» card loads after the page and may sit outside the form
      document.addEventListener('input', function (e) { if (e.target.closest && e.target.closest('[data-cs-field$=".greeting"], [data-cs-sugg], [data-cs-field="ai_notice"], [data-cs-field="greet_popup"]')) push(); });
      document.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('[data-cs-field="ai_notice"], [data-cs-field="greet_popup"]')) push(); });
      document.addEventListener('aiwc-lazy-put', function (e) { if (e.detail === 'chat') push(); });
      // One button opens and closes the preview window; its label follows the window state.
      var toggleBtn = document.getElementById('aiwc-open');
      var chatWindow = function () { return document.querySelector('.aiw-win:not(.aiw-inline)'); };
      var syncLabel = function () {
        var w = chatWindow(), on = !!(w && w.classList.contains('on')), label = on ? toggleBtn.dataset.closeLabel : toggleBtn.dataset.openLabel;
        if (toggleBtn.textContent !== label) toggleBtn.textContent = label; // only real changes, so the observer does not re-trigger itself
        toggleBtn.setAttribute('aria-expanded', on ? 'true' : 'false');
      };
      toggleBtn.addEventListener('click', function () {
        var w = chatWindow(), close = w && w.classList.contains('on') && w.querySelector('.aiw-close');
        if (close) close.click(); else window.dispatchEvent(new Event('aiworker:open'));
      });
      new MutationObserver(syncLabel).observe(document.body, { subtree: true, attributes: true, attributeFilter: ['class'] });
      syncLabel();
      // Desktop: the save card sits at the top of the right column; narrow screens keep it as a sticky bar under the form.
      var save = form.querySelector('.aiwc-save'), main = save && save.parentNode, side = form.querySelector('.aiwc-side'), wide = window.matchMedia('(min-width: 1101px)');
      var placeSave = function () { if (!save || !side) return; if (wide.matches) { side.insertBefore(save, side.firstChild); save.classList.add('is-side'); } else { main.appendChild(save); save.classList.remove('is-side'); } };
      placeSave(); if (wide.addEventListener) wide.addEventListener('change', placeSave); else wide.addListener(placeSave);
      window.aiwcPreviewPush = push; // the WordPress colour picker reports its changes through this
      // «Reset preview»: unsaved edits in the form and the preview go back to the saved settings; nothing is saved.
      // The button shows only while the form differs from what was loaded.
      var reset = form.querySelector('.aiwc-reset'), resetDone = form.querySelector('.aiwc-reset-done');
      var saved = Array.prototype.map.call(form.querySelectorAll('input[name], select[name], textarea[name]'), function (f) {
        return { f: f, v: f.value, on: f.checked };
      });
      var differs = function () { return saved.some(function (s) { return (s.f.type === 'checkbox' || s.f.type === 'radio') ? s.f.checked !== s.on : s.f.value !== s.v; }); };
      var syncReset = function () {
        if (!reset) return; var d = differs(); reset.hidden = !d;
        if (d && resetDone) resetDone.hidden = true;
      };
      if (reset) {
        form.addEventListener('input', syncReset); form.addEventListener('change', syncReset);
        window.aiwcPreviewPush = function () { push(); syncReset(); };
        reset.addEventListener('click', function () {
          saved.forEach(function (s) {
            if (s.f.type === 'checkbox' || s.f.type === 'radio') { s.f.checked = s.on; return; }
            s.f.value = s.v;
            if (window.jQuery && s.f.classList.contains('aiwc-color') && window.jQuery(s.f).data('wpWpColorPicker')) {
              if (s.v) window.jQuery(s.f).wpColorPicker('color', s.v); else window.jQuery(s.f).trigger('change');
            }
          });
          rows(); push(); syncReset();
          if (resetDone) resetDone.hidden = false;
          form.querySelector('[type="submit"][name="submit"]').focus();
        });
      }
    })();
    </script>
    <?php
}

/** The settings page's own look: cards, a highlighted connection block, a sticky preview column. */
function aiworker_chat_admin_styles(): void {
    ?>
    <style>
      .aiwc{max-width:1320px}.aiwc-head{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:14px 0 18px}.aiwc-head h1{margin:0;padding:0;font-size:24px;font-weight:700}.aiwc-head h1 span{font-weight:400;color:#646970}.aiwc-connect-row{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px;align-items:stretch;margin-bottom:14px}.aiwc-connect-row .aiwc-key{margin:0}.aiwc-pick{padding:12px 14px;border:1px dashed #c3c4c7;border-radius:8px}.aiwc-pick h3{margin:0 0 6px;font-size:14px}.aiwc-pick p{margin:0 0 8px}.aiwc-pick p:last-child{margin-bottom:0}.aiwc-connect-row--linked{grid-template-columns:minmax(0,1fr)}@media (max-width:1100px){.aiwc-connect-row{grid-template-columns:minmax(0,1fr)}}.aiwc-pick__error{color:#b32d2e}.aiwc-pick__account{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.aiwc-pick__account .aiwc-aw__account{color:#50575e}
      .wrap.aiwc h2.aiwc-tabs{margin:0 0 18px}
      .aiwc-pill{display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;font-size:12px;font-weight:600;line-height:1.6}
      .aiwc-pill--ok{background:#e7f6ec;color:#116329}.aiwc-pill--warn{background:#fcf3dc;color:#7a4b00}.aiwc-pill--err{background:#fbe9e9;color:#a1191b}.aiwc-pill--off{background:#fff;color:#50575e;box-shadow:inset 0 0 0 1px #c3c4c7}
      .aiwc-grid{display:grid;grid-template-columns:minmax(0,1fr) 340px;gap:24px;align-items:start}
      .aiwc-main{display:grid;grid-template-columns:minmax(0,1fr);gap:18px;min-width:0}
      .aiwc-card{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:20px 22px;box-shadow:0 1px 2px rgba(0,0,0,.03)}
      .aiwc-card h2{display:flex;align-items:center;gap:10px;margin:0 0 14px;font-size:16px}
      .aiwc-num{display:inline-flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:50%;background:#f0f0f1;color:#1d2327;font-size:12px;font-weight:700}
      .aiwc-chatset textarea,.aiwc-chatset .aiwc-chatset__trio input{width:100%}.aiwc-chatset__label{display:block;font-weight:600;margin:10px 0 4px}.aiwc-chatset__texts>.aiwc-chatset__label:first-child{margin-top:0}.aiwc-chatset__sugg{display:block;width:100%;max-width:520px;margin:0 0 6px}.aiwc-chatset__trio{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:6px}@media (max-width:900px){.aiwc-chatset__trio{grid-template-columns:minmax(0,1fr)}}.aiwc-chatset__langs{display:flex;gap:6px;margin:0 0 12px}.aiwc-chatset__lang{border:1px solid #dcdcde;background:#fff;border-radius:999px;padding:5px 14px;cursor:pointer;font:inherit}.aiwc-chatset__lang.is-active{background:#2271b1;border-color:#2271b1;color:#fff}.aiwc-chatset__err{display:block;color:#d63638;margin:2px 0 6px}.aiwc-chatset__err[hidden]{display:none}.aiwc-chatset .aiwc-chatset__radio{display:flex;margin:0 0 6px}.aiwc-chatset--single .aiwc-row{grid-template-columns:minmax(0,1fr)}.aiwc-chatset--single .aiwc-row__label{display:none}.aiwc-chatset__default{margin:12px 0 0}.aiwc-chatset__default select{margin-left:8px}.aiwc-chatset__actions{display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin:16px 0 0;padding-top:14px;border-top:1px solid #f0f0f1}.aiwc-chatset__panel{margin-left:auto}.aiwc-chatset__notice[hidden]{display:none}.aiwc-lazy.aiwc-card{margin-bottom:16px}.aiwc-aw__loading{display:flex;align-items:center;gap:8px;color:#3858e9;margin:6px 0}.aiwc-aw__spin{display:inline-block;width:12px;height:12px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:aiwc-spin .7s linear infinite}@keyframes aiwc-spin{to{transform:rotate(360deg)}}.aiwc-ld[hidden]{display:none}.aiwc-save__error{color:#d63638;margin:6px 0 0;font-weight:600}.aiwc-ld{margin-top:14px;padding:14px 16px;border:1px solid #dcdcde;border-radius:10px}.aiwc-ld__head{display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin:0 0 10px}.aiwc-ld__chips{display:flex;flex-wrap:wrap;gap:8px;margin-bottom:10px}.aiwc-ld__chip{display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border:1px solid #dcdcde;border-radius:999px;cursor:pointer;font-weight:600}.aiwc-ld__chip:has(input:checked){border-color:#2271b1;background:#f0f6fc}.aiwc-ld__fields{display:grid;gap:8px;margin:0 0 10px;padding:10px 12px;background:#f6f7f7;border-radius:8px}.aiwc-ld__fields[hidden]{display:none}.aiwc-ld__field{display:grid;gap:4px}.aiwc-ld__field>span:first-child{font-weight:600}.aiwc-ld__results{margin:6px 0 0}.aiwc-ld__results .is-ok{color:#00a32a}.aiwc-ld__results .is-err{color:#d63638}.aiwc-chatset__translate{display:inline-flex;flex-wrap:wrap;align-items:center;gap:6px;margin-left:auto;color:#646970;font-size:12px}.aiwc-chatset__langs{flex-wrap:wrap;align-items:center}.aiwc-kbr__list{list-style:none;margin:12px 0;padding:0;display:grid;gap:8px}.aiwc-kbr__item{display:grid;grid-template-columns:130px minmax(0,1fr) auto auto auto;gap:12px;align-items:center;padding:10px 14px;border:1px solid #dcdcde;border-radius:10px;background:#fcfcfd}.aiwc-kbr__type{color:#646970;font-size:12px}.aiwc-kbr__title{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:600}.aiwc-kbr__title a{text-decoration:none}.aiwc-kbr__err{display:block;color:#d63638;font-weight:400;white-space:normal}.aiwc-kbr__date{color:#646970;font-size:12px;white-space:nowrap}.aiwc-kbr__actions{display:flex;gap:10px}.aiwc-kbr__del{color:#b32d2e}.aiwc-kbr__add{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-start}.aiwc-kbr__add textarea{flex:1 1 100%}.aiwc-kbr__add [hidden]{display:none}.aiwc-kbr__add .description{flex:1 1 100%;margin:0}.aiwc-kbr__faq{display:grid;gap:10px}.aiwc-kbr__qa{display:grid;gap:6px;padding:12px 14px;border:1px solid #dcdcde;border-radius:10px}.aiwc-kbr__qa input,.aiwc-kbr__qa textarea{width:100%}.aiwc-kbr__qa input{font-weight:600}.aiwc-kbr__qa--new{border-style:dashed}.aiwc-kbr__qa-actions{display:flex;gap:12px;align-items:center}.aiwc-kbr__notice[hidden]{display:none}@media (max-width:900px){.aiwc-kbr__item{grid-template-columns:minmax(0,1fr) auto}.aiwc-kbr__type,.aiwc-kbr__date{grid-column:1/-1}}.aiwc-card--pay{border:2px solid #dba617;background:linear-gradient(180deg,#fff8e5 0,#fff 120px)}.aiwc-card--pay .aiwc-num{background:#dba617;color:#fff}.aiwc-card--pay .aiwc-pay__plan{font-size:14px;margin:0 0 12px}.aiwc-card--connect{border:2px solid #4f46e5;background:linear-gradient(180deg,#f5f5ff 0,#fff 120px)}.aiwc-card--connect .aiwc-num{background:#4f46e5;color:#fff}
      .aiwc-lead{margin:0 0 10px;font-size:14px}.aiwc-steps{margin:0 0 16px 20px;color:#3c434a}.aiwc-steps li{margin:4px 0}.aiwc-steps--check{list-style:none;margin-left:0;counter-reset:aiwc-step}.aiwc-steps--check li{counter-increment:aiwc-step;position:relative;padding-left:28px}.aiwc-steps--check li::before{content:counter(aiwc-step) ".";position:absolute;left:0;top:0;width:20px;text-align:center;color:#646970}.aiwc-steps--check li.is-done::before{content:"\2713";color:#00a32a;font-weight:700}.aiwc-steps--check li.is-missing::before{content:"\2717";color:#d63638;font-weight:700}.aiwc-steps--check li.is-done{color:#646970}.aiwc-steps-done{margin:0 0 16px}.aiwc-steps-done summary{cursor:pointer;list-style:none;display:inline-flex;align-items:center;gap:8px;font-weight:600;color:#00a32a}.aiwc-steps-done summary::-webkit-details-marker{display:none}.aiwc-steps-done summary::before{content:"\2713";font-weight:700}.aiwc-steps-done summary::after{content:"";width:0;height:0;border-top:5px solid #646970;border-left:4px solid transparent;border-right:4px solid transparent;transition:transform .15s}.aiwc-steps-done[open] summary::after{transform:rotate(180deg)}.aiwc-steps-done summary:hover{text-decoration:underline}.aiwc-steps-done .aiwc-steps{margin-top:10px}
      .aiwc-key label{display:block;font-weight:600;margin-bottom:6px}.aiwc-key__row{display:flex;gap:8px;flex-wrap:wrap}.aiwc-key__row [hidden]{display:none}
      .aiwc-key input{flex:1 1 220px;min-width:0;font:15px/1.4 ui-monospace,SFMono-Regular,Menlo,monospace;padding:8px 12px;border:1.5px solid #8c8f94;border-radius:8px}.aiwc-key input:focus{border-color:#4f46e5;box-shadow:0 0 0 1px #4f46e5}
      .aiwc-key .button{height:auto;padding:6px 14px;border-radius:8px}
      .aiwc-status{margin:12px 0 0;padding:10px 12px;border-radius:8px;border-left:4px solid;font-size:13px}.aiwc-status a{margin-left:6px;white-space:nowrap}
      .aiwc-status--success{background:#edf7ef;border-color:#00a32a}.aiwc-status--warning{background:#fcf7e8;border-color:#dba617}.aiwc-status--error{background:#fcf0f1;border-color:#d63638}
      .aiwc-toggle{display:flex;align-items:center;gap:12px;margin-top:16px;padding:12px 14px;border:1px solid #dcdcde;border-radius:10px;background:#fff;cursor:pointer}
      .aiwc-toggle input{position:absolute;opacity:0;width:1px;height:1px}.aiwc-toggle small{display:block;color:#646970;font-size:12px}
      .aiwc-toggle__ui{flex:none;position:relative;width:40px;height:22px;border-radius:11px;background:#c3c4c7;transition:background .15s}
      .aiwc-toggle__ui:after{content:"";position:absolute;top:3px;left:3px;width:16px;height:16px;border-radius:50%;background:#fff;transition:left .15s}
      .aiwc-toggle input:checked+.aiwc-toggle__ui{background:#00a32a}.aiwc-toggle input:checked+.aiwc-toggle__ui:after{left:21px}
      .aiwc-toggle input:focus-visible+.aiwc-toggle__ui{outline:2px solid #4f46e5;outline-offset:2px}.aiwc-toggle.is-off{cursor:not-allowed;opacity:.7}
      .aiwc-row{display:grid;grid-template-columns:190px minmax(0,1fr);gap:6px 18px;padding:12px 0;border-top:1px solid #f0f0f1}.aiwc-row:first-of-type{border-top:0}
      .aiwc-row__label{font-weight:600;padding-top:6px}.aiwc-row__control{min-width:0}.aiwc-row__control select,.aiwc-row__control textarea,.aiwc-row__control input[type=text]{max-width:100%}.aiwc-row__control .description{margin-top:6px}
      .aiwc-check{display:inline-flex;gap:8px;align-items:flex-start;line-height:1.5}.aiwc-check input{margin-top:3px}.aiwc-check.is-off{color:#8c8f94}
      .aiwc-paid{display:inline-block;margin-left:8px;padding:1px 8px;border-radius:999px;background:#fcf3dc;color:#7a4b00;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.03em}
      .aiwc-row:has(.aiwc-color--accent) .wp-picker-clear{display:none}.aiwc-colors summary{cursor:pointer;color:#2271b1}.aiwc-colors__grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:10px 16px;margin-top:8px}.aiwc-colors__name{display:block;font-size:13px;color:#1d2327;margin-bottom:4px}
      .aiwc-delay{margin-left:8px;white-space:nowrap}.aiwc-delay input{width:70px}
      .aiwc-save{position:sticky;bottom:12px;z-index:5;justify-self:end;max-width:100%;display:flex;flex-direction:row-reverse;align-items:center;gap:12px;padding:12px 16px;background:#fff;border:1px solid #dcdcde;border-radius:12px;box-shadow:0 4px 16px rgba(0,0,0,.08)}.aiwc-save .button-primary{min-width:160px}.aiwc-save .aiwc-reset{border-radius:8px}.aiwc-save [hidden]{display:none!important}.aiwc-save.is-side .aiwc-reset{width:100%;text-align:center}
      .aiwc-save.is-side{position:static;justify-self:auto;flex-direction:column;align-items:stretch;gap:8px;margin-bottom:16px;padding:16px 18px;box-shadow:none}.aiwc-save.is-side .button-primary{width:100%;text-align:center}.aiwc-save.is-side .description{margin:0}
      .aiwc-side{position:sticky;top:46px}
      .aiwc-preview{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px 18px}.aiwc-usage{background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:16px 18px;margin-bottom:16px;display:grid;gap:12px}.aiwc-usage h2{margin:0;font-size:15px}.aiwc-meter__row{display:flex;justify-content:space-between;gap:12px;font-size:13px}.aiwc-meter__label{color:#646970}.aiwc-meter__value{font-weight:600;text-align:right;font-variant-numeric:tabular-nums}.aiwc-meter__bar{height:6px;border-radius:3px;background:#f0f0f1;margin-top:6px;overflow:hidden}.aiwc-meter__bar span{display:block;height:100%;border-radius:3px;background:#3858e9}.aiwc-meter.is-warn .aiwc-meter__bar span{background:#dba617}.aiwc-meter.is-bad .aiwc-meter__bar span{background:#d63638}.aiwc-meter__hint{margin-top:4px;font-size:12px;color:#646970}.aiwc-meter__cta{margin:6px 0 0}.aiwc-preview h2{margin:0 0 8px;font-size:16px}.aiwc-preview p{margin:0 0 10px}
      .aiwc-preview__actions .button{border-radius:8px}.aiwc-inline-preview{margin-top:12px;padding-top:12px;border-top:1px solid #f0f0f1}
      .aiwc-offset{display:inline-flex;gap:14px;flex-wrap:wrap}.aiwc-offset label{display:inline-flex;align-items:center;gap:6px;color:#50575e}.aiwc-offset input{width:84px}
      .aiwc-kb__types{display:grid;gap:8px;margin:12px 0}.aiwc-kb__more{margin:22px 0 4px;font-size:14px}.aiwc-channels{margin-top:20px;padding-top:16px;border-top:1px solid #f0f0f1}.aiwc-channels h3{margin:0 0 4px;font-size:14px}.aiwc-channels__tabs{display:flex;flex-wrap:wrap;gap:6px;margin:12px 0 10px}.aiwc-channels__tab{border:1px solid #dcdcde;background:#fff;border-radius:999px;padding:6px 14px;cursor:pointer;font:inherit;color:#1d2327}.aiwc-channels__tab.is-active{background:#2271b1;border-color:#2271b1;color:#fff}.aiwc-channels__dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#c3c4c7;margin-left:4px;vertical-align:middle}.aiwc-channels__dot--connected{background:#00a32a}.aiwc-channels__dot--error{background:#d63638}.aiwc-channels__ok{color:#00a32a;font-weight:700;margin-left:2px}.aiwc-channels__tab.is-active .aiwc-channels__ok{color:#fff}.aiwc-channels__panel{border:1px solid #dcdcde;border-radius:10px;padding:10px 16px;background:#fcfcfd}.aiwc-channels__panel .aiwc-steps{margin-bottom:8px}.aiwc-kb__type{border:1px solid #dcdcde;border-radius:10px;padding:10px 14px;background:#fcfcfd}
      .aiwc-kb__type summary{display:flex;align-items:center;justify-content:space-between;gap:12px;cursor:pointer}.aiwc-kb__fields{display:grid;gap:10px;margin-top:10px}
      .aiwc-kb__fields>div{display:flex;flex-wrap:wrap;gap:6px 16px;align-items:baseline}.aiwc-kb__fields b{min-width:120px;font-weight:600}
      .aiwc-kb__preview{margin-top:0}.aiwc-modal{width:min(860px,calc(100vw - 32px));max-height:calc(100vh - 64px);padding:0;border:0;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,.3)}.aiwc-modal::backdrop{background:rgba(0,0,0,.45)}.aiwc-modal__head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 18px;border-bottom:1px solid #dcdcde;position:sticky;top:0;background:#fff}.aiwc-modal__head h2{margin:0;font-size:16px}.aiwc-modal__close{border:0;background:none;font-size:26px;line-height:1;cursor:pointer;color:#50575e}.aiwc-modal__body{padding:14px 18px}.aiwc-kb__preview pre{max-height:320px;overflow:auto;background:#f6f7f7;padding:10px;border-radius:8px;white-space:pre-wrap}
      .aiwc-mcp__plan{margin:0 0 12px;padding:8px 12px;border-radius:8px;background:#fcf3dc;color:#5c3b00}.aiwc-mcp__list{margin:0 0 8px 20px;list-style:disc}.aiwc-mcp__list li{margin:2px 0}
      .aiwc-mcp__steps{margin:10px 0 6px 20px}.aiwc-mcp__copy{margin-top:6px}.aiwc-mcp__manual{margin-top:10px}.aiwc-mcp__manual summary{cursor:pointer;display:inline-flex;align-items:center;gap:6px;padding:5px 12px;border:1px solid #c3c4c7;border-radius:6px;background:#f6f7f7;color:#2271b1;font-weight:600;list-style:none}.aiwc-mcp__manual summary::-webkit-details-marker{display:none}.aiwc-mcp__manual summary::before{content:'';width:0;height:0;border-left:5px solid currentColor;border-top:4px solid transparent;border-bottom:4px solid transparent;transition:transform .15s}.aiwc-mcp__manual[open] summary::before{transform:rotate(90deg)}.aiwc-mcp__manual summary:hover{border-color:#2271b1;background:#fff}.aiwc-mcp__manual p{margin:10px 0 0}.aiwc-mcp__prompt{display:block;width:100%;margin-top:6px;font:12px/1.5 ui-monospace,SFMono-Regular,Menlo,monospace}
      @media (max-width:1100px){.aiwc-grid{grid-template-columns:minmax(0,1fr)}.aiwc-side{position:static}}
      @media (max-width:640px){.aiwc-row{grid-template-columns:minmax(0,1fr)}.aiwc-row__label{padding-top:0}.aiwc-card{padding:16px}}
    </style>
    <?php
}

add_action('admin_enqueue_scripts', static function (string $hook): void {
    if ($hook !== 'needtome_page_aiworker-chat') return;
    // WordPress's own colour picker: it accepts an empty value, which means «the scheme's colour».
    wp_enqueue_style('wp-color-picker');
    wp_enqueue_script('wp-color-picker');
    // The picker changes the input without an input event: tell the live preview (aiworker_chat_preview) explicitly.
    wp_add_inline_script('wp-color-picker', 'jQuery(function($){ var p = function(){ setTimeout(function(){ window.aiwcPreviewPush && window.aiwcPreviewPush(); }, 0); }; $(".aiwc-color").wpColorPicker({ change: p, clear: p }); });');
});

add_action('wp_enqueue_scripts', static function (): void {
    $o = aiworker_chat_options();
    if (empty($o['enabled']) || ! aiworker_chat_valid_key((string) $o['key']) || ! aiworker_chat_on_this_page($o)) return;
    $options = aiworker_chat_sanitize($o);
    unset($options['pages'], $options['pages_mode']);
    $options['base'] = aiworker_chat_base();
    $options['privacy_url'] = ! empty($o['privacy']) ? (string) get_privacy_policy_url() : '';
    // the file's time in the version too, so visitors' browsers do not keep an old loader of the same version
    wp_enqueue_script('aiworker-chat-loader', plugins_url('loader.js', __FILE__), [], AIWORKER_CHAT_VERSION.'.'.(int) @filemtime(__DIR__.'/loader.js'), true);
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
add_shortcode('aiworker_chat', static function (): string {
    $o = aiworker_chat_options();
    $mount = (string) ($o['mount'] ?? 'aiworker-chat');
    return substr($mount, 0, 1) === '.' ? '<div class="'.esc_attr(substr($mount, 1)).'"></div>' : '<div id="'.esc_attr($mount).'"></div>';
});

register_deactivation_hook(__FILE__, static function (): void {
    wp_clear_scheduled_hook('aiworker_chat_kb_sync');
    wp_clear_scheduled_hook('aiworker_chat_kb_daily');
});
