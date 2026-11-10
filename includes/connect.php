<?php
/**
 * Connection to the owner's kra.name account for the setup wizard and the statistics: OAuth 2.1 with PKCE and dynamic
 * client registration (no password or API key in WordPress), scope «chat». The tokens are stored server-side only, in an
 * option encrypted with the site's salts, and every call to kra.name is made from PHP. Refresh tokens rotate (each works
 * once), so a refresh runs under an atomic lock and the new pair is saved before anything else.
 */

if (! defined('ABSPATH')) exit;

const AIWORKER_CHAT_OAUTH_OPTION = 'aiworker_chat_oauth';
const AIWORKER_CHAT_OAUTH_LOCK = 'aiworker_chat_oauth_lock';
const AIWORKER_CHAT_OAUTH_RESOURCE = 'https://kra.name/app/api/mcp';
const AIWORKER_CHAT_API = 'https://kra.name/app/api/v1';

/**
 * Where kra.name sends the owner back after the consent page. admin-post.php, not a wp-admin screen: a sign-in link
 * copied to another browser comes back there without a WordPress session (see aiworker_chat_oauth_return()).
 */
function aiworker_chat_oauth_redirect_uri(): string {
    return admin_url('admin-post.php?action=aiworker_chat_oauth_return');
}

/** The settings screen, optionally with a notice code. */
function aiworker_chat_settings_url(string $notice = '', ?string $chatId = null): string {
    $chatId = $chatId !== null && in_array($chatId, aiworker_chat_ids(), true) ? $chatId : aiworker_chat_current_id();
    $url = add_query_arg('aiworker_chat_instance', $chatId, Aiworker_Chat_Profile::url('aiworker-chat'));
    if ($notice === '') return $url;
    // after connecting, step 1 shows the chat choice or, with a chat linked, the setup wizard
    return add_query_arg('aiworker_chat_notice', $notice, $url).($notice === 'connected' ? '#aiworker-chat-wizard' : '');
}

/** Key for the token box: derived from the site's salts, never stored. */
function aiworker_chat_oauth_key(): string {
    return sodium_crypto_generichash(wp_salt('auth').'|'.wp_salt('secure_auth').'|aiworker-chat-oauth', '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
}

function aiworker_chat_oauth_seal(array $data): string {
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce.sodium_crypto_secretbox((string) wp_json_encode($data), $nonce, aiworker_chat_oauth_key()));
}

function aiworker_chat_oauth_open(string $box): ?array {
    $raw = base64_decode($box, true);
    if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) return null;
    try {
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), aiworker_chat_oauth_key());
    } catch (Throwable $e) {
        return null;
    }
    $data = $plain === false ? null : json_decode($plain, true);
    return is_array($data) ? $data : null;
}

/** Stored state: client registration (public) and the sealed tokens. Read fresh, bypassing the object cache. */
function aiworker_chat_oauth_state(bool $fresh = false): array {
    if ($fresh) wp_cache_delete(AIWORKER_CHAT_OAUTH_OPTION, 'options');
    $state = get_option(AIWORKER_CHAT_OAUTH_OPTION, []);
    return is_array($state) ? $state : [];
}

function aiworker_chat_oauth_tokens(bool $fresh = false): ?array {
    $state = aiworker_chat_oauth_state($fresh);
    return isset($state['tokens']) && is_string($state['tokens']) ? aiworker_chat_oauth_open($state['tokens']) : null;
}

function aiworker_chat_is_connected(): bool {
    $tokens = aiworker_chat_oauth_tokens();
    return $tokens !== null && ! empty($tokens['refresh_token']);
}

/** Saves a token pair; $clientId, from a new connection, is the client the pair was issued to (a second Connect may have registered another). */
function aiworker_chat_oauth_save_tokens(array $response, string $clientId = ''): void {
    $state = aiworker_chat_oauth_state(true);
    if ($clientId !== '') $state['client_id'] = $clientId;
    $old = isset($state['tokens']) && is_string($state['tokens']) ? (aiworker_chat_oauth_open($state['tokens']) ?? []) : [];
    $tokens = [
        'access_token' => (string) $response['access_token'],
        // a refresh response may omit a new refresh token: keep the old one then
        'refresh_token' => (string) ($response['refresh_token'] ?? ($old['refresh_token'] ?? '')),
        'expires_at' => time() + max(60, (int) ($response['expires_in'] ?? 3600)),
        'scope' => (string) ($response['scope'] ?? 'chat'),
    ];
    $state['tokens'] = aiworker_chat_oauth_seal($tokens);
    $state['connected_at'] = $state['connected_at'] ?? time();
    update_option(AIWORKER_CHAT_OAUTH_OPTION, $state, false);
}

function aiworker_chat_oauth_forget(): void {
    $state = aiworker_chat_oauth_state(true);
    unset($state['tokens'], $state['connected_at'], $state['account']);
    update_option(AIWORKER_CHAT_OAUTH_OPTION, $state, false);
    aiworker_chat_forget_me();
}

/** Drops the cached /plugin/me in every language after connection state changes. */
function aiworker_chat_forget_me(): void {
    foreach (['ru', 'en', 'uk'] as $lang) {
        delete_transient('aiworker_chat_me_'.$lang);
    }
}

/** A POST (form or JSON body) to kra.name without a token: the registration, token and revoke endpoints. */
function aiworker_chat_oauth_post(string $url, array $body, bool $json = false): array {
    $args = ['timeout' => 20, 'redirection' => 0, 'headers' => ['Accept' => 'application/json']];
    if ($json) {
        $args['headers']['Content-Type'] = 'application/json';
        $args['body'] = wp_json_encode($body);
    } else {
        $args['body'] = $body;
    }
    $response = wp_remote_post($url, $args);
    if (is_wp_error($response)) return ['code' => 0, 'body' => [], 'error' => $response->get_error_message()];
    $data = json_decode((string) wp_remote_retrieve_body($response), true);
    return ['code' => (int) wp_remote_retrieve_response_code($response), 'body' => is_array($data) ? $data : [], 'error' => ''];
}

/** The site's OAuth client at kra.name, registered once (again if the site address changed). */
function aiworker_chat_oauth_client_id(): string {
    $state = aiworker_chat_oauth_state(true);
    $redirect = aiworker_chat_oauth_redirect_uri();
    if (! empty($state['client_id']) && ($state['redirect_uri'] ?? '') === $redirect) return (string) $state['client_id'];

    $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    $result = aiworker_chat_oauth_post('https://kra.name/app/api/oauth/register', [
        'client_name' => 'WordPress: '.$host,
        'redirect_uris' => [$redirect],
        'grant_types' => ['authorization_code', 'refresh_token'],
        'response_types' => ['code'],
        'token_endpoint_auth_method' => 'none',
        'scope' => 'chat',
        // kra.name counts its CMS plugins by platform and version
        'software_id' => 'needtome-ai-chat-wordpress',
        'software_version' => AIWORKER_CHAT_VERSION,
    ], true);
    if (empty($result['body']['client_id']) || ! in_array($result['code'], [200, 201], true)) return '';
    $state['client_id'] = (string) $result['body']['client_id'];
    $state['redirect_uri'] = $redirect;
    update_option(AIWORKER_CHAT_OAUTH_OPTION, $state, false);
    return $state['client_id'];
}

/** «Connect AI Worker»: register if needed, remember the PKCE verifier under a random state, go to the consent page. */
function aiworker_chat_oauth_start(): void {
    if (! current_user_can('manage_options')) wp_die(esc_html__('You are not allowed to do this.', 'needtome-ai-chat'), 403);
    check_admin_referer('aiworker_chat_oauth_connect');
    aiworker_chat_oauth_begin();
}

/**
 * The consent round trip; $then = 'pick' comes back to the chat choice instead of the wizard. $remote: started from a
 * sign-in link, maybe in another browser, so the return does not need the WordPress session of $user. $popup: started in
 * a popup, which closes at the end and sends the screen that opened it on.
 */
function aiworker_chat_oauth_begin(string $then = '', int $user = 0, bool $remote = false, bool $popup = false, string $chatId = ''): void {
    if (! in_array($chatId, aiworker_chat_ids(), true)) $chatId = aiworker_chat_current_id();
    $clientId = aiworker_chat_oauth_client_id();
    if ($clientId === '') aiworker_chat_oauth_done(['then' => $then, 'popup' => $popup, 'chat_id' => $chatId], 'register_failed');
    $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    // lowercase hex: sanitize_key() in the callback keeps it as is, so the transient key matches in any object cache
    $stateKey = bin2hex(random_bytes(16));
    set_transient('aiworker_chat_oauth_'.$stateKey, ['verifier' => $verifier, 'client_id' => $clientId, 'user' => $user ?: get_current_user_id(), 'then' => $then, 'remote' => $remote, 'popup' => $popup, 'chat_id' => $chatId, 'redirect' => aiworker_chat_oauth_redirect_uri()], 15 * MINUTE_IN_SECONDS);

    $url = add_query_arg(array_map('rawurlencode', [
        'response_type' => 'code',
        'client_id' => $clientId,
        'redirect_uri' => aiworker_chat_oauth_redirect_uri(),
        'scope' => 'chat',
        'state' => $stateKey,
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'resource' => AIWORKER_CHAT_OAUTH_RESOURCE,
    ]), 'https://kra.name/app/oauth/authorize');
    // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The consent page is on kra.name, outside this site.
    wp_redirect($url);
    exit;
}
add_action('admin_post_aiworker_chat_oauth_connect', 'aiworker_chat_oauth_start');

/** The old return address (wp-admin/admin.php), for connections started before it moved to admin-post.php. */
function aiworker_chat_oauth_callback(): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only routes the request; aiworker_chat_oauth_return() checks the state.
    if (isset($_GET['page'], $_GET['aiworker_chat_oauth_route']) && $_GET['page'] === 'aiworker-chat' && $_GET['aiworker_chat_oauth_route'] === 'callback') aiworker_chat_oauth_return();
}
add_action('admin_init', 'aiworker_chat_oauth_callback', 0);

/**
 * The redirect back from kra.name: check the state, exchange the code (PKCE, no secret), store the tokens. The one-time
 * random state stands in for the session: a connection started on the settings screen must come back to the same
 * WordPress user; one started from a sign-in link may come back to a browser without a WordPress session.
 */
function aiworker_chat_oauth_return(): void {
    // phpcs:disable WordPress.Security.NonceVerification.Recommended -- OAuth callback: the one-time random state stands in for the nonce.
    $stateKey = isset($_GET['state']) ? sanitize_key(wp_unslash((string) $_GET['state'])) : '';
    $code = isset($_GET['code']) ? sanitize_text_field(wp_unslash((string) $_GET['code'])) : '';
    $error = isset($_GET['error']) ? sanitize_key(wp_unslash((string) $_GET['error'])) : '';
    // phpcs:enable WordPress.Security.NonceVerification.Recommended
    $pending = $stateKey !== '' ? get_transient('aiworker_chat_oauth_'.$stateKey) : false;
    if ($stateKey !== '') delete_transient('aiworker_chat_oauth_'.$stateKey);
    if (! is_array($pending)) aiworker_chat_oauth_done([], 'state');
    if (empty($pending['remote']) && (! current_user_can('manage_options') || (int) $pending['user'] !== get_current_user_id())) aiworker_chat_oauth_done([], 'state');
    if ($error !== '' || $code === '') aiworker_chat_oauth_done($pending, $error === 'access_denied' ? 'denied' : 'failed');
    $result = aiworker_chat_oauth_post('https://kra.name/app/api/oauth/token', [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => (string) ($pending['redirect'] ?? admin_url('admin.php?page=aiworker-chat&aiworker_chat_oauth_route=callback')),
        'client_id' => $pending['client_id'],
        'code_verifier' => $pending['verifier'],
        'resource' => AIWORKER_CHAT_OAUTH_RESOURCE,
    ]);
    if ($result['code'] !== 200 || empty($result['body']['access_token'])) aiworker_chat_oauth_done($pending, 'failed');
    // saved under the refresh lock, so a disconnect running now happens wholly before or after this connection;
    // if the lock stays busy, the new pair is revoked rather than saved without it
    $lock = aiworker_chat_oauth_lock();
    for ($i = 0; $i < 100 && $lock === ''; $i++) {
        usleep(250000);
        $lock = aiworker_chat_oauth_lock();
    }
    if ($lock === '') {
        aiworker_chat_oauth_revoke_pair($result['body'], (string) $pending['client_id']);
        aiworker_chat_oauth_done($pending, 'failed');
    }
    try {
        aiworker_chat_oauth_save_tokens($result['body'], (string) $pending['client_id']);
    } finally {
        aiworker_chat_oauth_lock_release($lock);
    }
    aiworker_chat_forget_me();
    aiworker_chat_oauth_done($pending, 'connected');
}
add_action('admin_post_aiworker_chat_oauth_return', 'aiworker_chat_oauth_return');
add_action('admin_post_nopriv_aiworker_chat_oauth_return', 'aiworker_chat_oauth_return');

/**
 * Where the owner lands after the round trip: the settings screen for a site administrator in this browser; in a
 * browser without one (a copied sign-in link) a short page that sends them back to the first browser.
 */
function aiworker_chat_oauth_done(array $pending, string $notice): void {
    if ($notice === 'connected' && function_exists('aiworker_chat_restore_removal_jobs')) aiworker_chat_restore_removal_jobs(null, true);
    if (current_user_can('manage_options')) {
        $next = $notice === 'connected' ? aiworker_chat_signin_next((string) ($pending['then'] ?? ''), (string) ($pending['chat_id'] ?? '')) : aiworker_chat_settings_url($notice, (string) ($pending['chat_id'] ?? ''));
        if (! empty($pending['popup'])) aiworker_chat_popup_done($next);
        wp_safe_redirect($next);
        exit;
    }
    $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    $text = $notice === 'connected'
        /* translators: %s: this site's domain */
        ? sprintf(__('AI Worker is connected to %s. Go back to the NeedToMe AI Chat settings in the browser where you are signed in to the site: they update by themselves.', 'needtome-ai-chat'), $host)
        : (aiworker_chat_assistant_notices()[$notice][1] ?? __('The connection was not completed. Start it again.', 'needtome-ai-chat'));
    wp_die('<p>'.esc_html($text).'</p>', esc_html__('NeedToMe AI Chat', 'needtome-ai-chat'), ['response' => 200]);
}

/** «Disconnect»: revoke at kra.name, then forget the tokens here even if kra.name cannot be reached. */
function aiworker_chat_oauth_disconnect(): void {
    if (! current_user_can('manage_options')) wp_die(esc_html__('You are not allowed to do this.', 'needtome-ai-chat'), 403);
    check_admin_referer('aiworker_chat_oauth_disconnect');
    aiworker_chat_oauth_revoke();
    wp_safe_redirect(aiworker_chat_settings_url('disconnected'));
    exit;
}
add_action('admin_post_aiworker_chat_oauth_disconnect', 'aiworker_chat_oauth_disconnect');

function aiworker_chat_oauth_revoke(): void {
    // Forget the tokens under the refresh lock, then revoke them at kra.name without it: a refresh running now finishes
    // and saves its pair first, which is then the one revoked, and the lock is never held through the slow revoke calls.
    // A refresh holds the lock for at most the token request's timeout, so waiting a little longer is enough; if the lock
    // is still busy, the tokens are forgotten anyway and that refresh sees it before saving (aiworker_chat_oauth_access_token).
    $lock = aiworker_chat_oauth_lock();
    for ($i = 0; $i < 100 && $lock === ''; $i++) {
        usleep(250000);
        $lock = aiworker_chat_oauth_lock();
    }
    try {
        $state = aiworker_chat_oauth_state(true);
        $tokens = aiworker_chat_oauth_tokens(true);
        aiworker_chat_oauth_forget();
    } finally {
        if ($lock !== '') aiworker_chat_oauth_lock_release($lock);
    }
    if ($tokens && ! empty($state['client_id'])) aiworker_chat_oauth_revoke_pair($tokens, (string) $state['client_id']);
}

function aiworker_chat_oauth_revoke_pair(array $tokens, string $clientId): void {
    foreach (['refresh_token', 'access_token'] as $type) {
        if (! empty($tokens[$type])) aiworker_chat_oauth_post('https://kra.name/app/api/oauth/revoke', ['token' => $tokens[$type], 'token_type_hint' => $type, 'client_id' => $clientId]);
    }
}

/**
 * Adds an option only when no row has that name yet, for locks: the OAuth lock here and the sync worker lock in
 * kb-sync.php (it lives here because uninstall.php loads only this file). add_option() is not enough: it checks first
 * and then upserts, so two requests between the check and the insert would both «add» it.
 */
function aiworker_chat_option_insert(string $name, $value): bool {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A conditional insert is the atomic part of the lock; the Options API has none.
    $added = $wpdb->query($wpdb->prepare("INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, maybe_serialize($value))) === 1;
    wp_cache_delete($name, 'options');
    wp_cache_delete('notoptions', 'options');
    return $added;
}

/** Delete the lock row only if it still holds $value (a conditional DELETE is atomic; the Options API is not). */
function aiworker_chat_oauth_lock_release(string $value): void {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases only this request's own lock; the Options API has no compare-and-delete.
    $wpdb->delete($wpdb->options, ['option_name' => AIWORKER_CHAT_OAUTH_LOCK, 'option_value' => $value]);
    wp_cache_delete(AIWORKER_CHAT_OAUTH_LOCK, 'options');
    wp_cache_delete('notoptions', 'options');
}

/**
 * Take the refresh lock atomically (a conditional insert of the lock row). The value names this request,
 * so it releases only its own lock; a lock older than 30 s is stale and is taken over only if it is still that same row.
 *
 * @return string The lock value when taken, '' when another request holds it.
 */
function aiworker_chat_oauth_lock(): string {
    $value = bin2hex(random_bytes(8)).':'.time();
    if (aiworker_chat_option_insert(AIWORKER_CHAT_OAUTH_LOCK, $value)) return $value;
    wp_cache_delete(AIWORKER_CHAT_OAUTH_LOCK, 'options');
    $held = (string) get_option(AIWORKER_CHAT_OAUTH_LOCK, '');
    $since = (int) substr((string) strrchr($held, ':'), 1);
    if ($held !== '' && $since > 0 && time() - $since > 30) {
        aiworker_chat_oauth_lock_release($held);
        if (aiworker_chat_option_insert(AIWORKER_CHAT_OAUTH_LOCK, $value)) return $value;
    }
    return '';
}

/**
 * A fresh access token. Refreshes when it expires within 30 s or when $force (after a 401). Only the request holding the
 * lock refreshes (the refresh token works once); the others wait up to 5 s and use the pair it saved, or give up.
 *
 * @param string $busy Set to 'renewing' when another request is still refreshing, or to 'unreachable' when kra.name
 *                     could not refresh the token now (network error, 5xx); the connection itself is kept then.
 * @return string|null '' when not connected or the connection was revoked at kra.name; null when the token cannot be had
 *                     right now ($busy says why; try again shortly).
 */
function aiworker_chat_oauth_access_token(bool $force = false, ?string &$busy = null): ?string {
    $busy = null;
    $tokens = aiworker_chat_oauth_tokens(true);
    if (! $tokens || empty($tokens['refresh_token'])) return '';
    if (! $force && (int) $tokens['expires_at'] - 30 > time()) return (string) $tokens['access_token'];
    $seen = (string) $tokens['access_token'];

    $lock = aiworker_chat_oauth_lock();
    for ($i = 0; $i < 20 && $lock === ''; $i++) {
        usleep(250000);
        $now = aiworker_chat_oauth_tokens(true);
        if (! $now || empty($now['refresh_token'])) return '';
        if ($now['access_token'] !== $seen && (int) $now['expires_at'] - 30 > time()) return (string) $now['access_token'];
        $lock = aiworker_chat_oauth_lock();
    }
    if ($lock === '') { $busy = 'renewing'; return null; }
    try {
        // another request may have refreshed just before we took the lock
        $tokens = aiworker_chat_oauth_tokens(true);
        if (! $tokens || empty($tokens['refresh_token'])) return '';
        if ($tokens['access_token'] !== $seen && (int) $tokens['expires_at'] - 30 > time()) return (string) $tokens['access_token'];
        $state = aiworker_chat_oauth_state(true);
        $result = aiworker_chat_oauth_post('https://kra.name/app/api/oauth/token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'client_id' => (string) ($state['client_id'] ?? ''),
            'resource' => AIWORKER_CHAT_OAUTH_RESOURCE,
        ]);
        if ($result['code'] === 200 && ! empty($result['body']['access_token'])) {
            // a disconnect that could not wait for this refresh has cleared the tokens meanwhile: do not bring them back
            $now = aiworker_chat_oauth_tokens(true);
            if (! $now || ($now['refresh_token'] ?? '') !== $tokens['refresh_token']) {
                aiworker_chat_oauth_revoke_pair($result['body'] + ['refresh_token' => ''], (string) ($state['client_id'] ?? ''));
                return '';
            }
            aiworker_chat_oauth_save_tokens($result['body']);
            return (string) $result['body']['access_token'];
        }
        // the grant was revoked or expired at kra.name: the owner has to connect again
        if (in_array($result['code'], [400, 401], true)) { aiworker_chat_oauth_forget(); return ''; }
        // a network error or a temporary failure at kra.name: keep the connection and try again later
        $busy = 'unreachable';
        return null;
    } finally {
        aiworker_chat_oauth_lock_release($lock);
    }
}

/**
 * GET answers fetched ahead, all at once (aiworker_chat_api_prefetch), by full URL; read once by aiworker_chat_api().
 * $set: store an answer; null: take the stored one out.
 */
function aiworker_chat_api_prefetched(string $url, ?array $set = null): ?array {
    static $answers = [];
    if ($set !== null) { $answers[$url] = $set; return null; }
    $answer = $answers[$url] ?? null;
    unset($answers[$url]);
    return $answer;
}

/** The URL aiworker_chat_api() requests for a GET. */
function aiworker_chat_api_get_url(string $path, array $query): string {
    return $query ? add_query_arg(array_map('rawurlencode', array_map('strval', $query)), AIWORKER_CHAT_API.$path) : AIWORKER_CHAT_API.$path;
}

/**
 * Several GETs to kra.name in parallel instead of one after another (each takes a second or two): [[path, query], …].
 * The answers wait for the aiworker_chat_api() calls that ask for the same URLs; a 401 is not kept, so that call
 * refreshes the token and asks again as usual.
 */
function aiworker_chat_api_prefetch(array $calls, int $timeout = 15): void {
    if (! $calls || ! class_exists('\WpOrg\Requests\Requests')) return;
    $token = aiworker_chat_oauth_access_token(false, $busy);
    if (! $token) return;
    $requests = [];
    foreach ($calls as $call) {
        $url = aiworker_chat_api_get_url((string) $call[0], (array) ($call[1] ?? []));
        $requests[$url] = ['url' => $url, 'type' => 'GET', 'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']];
    }
    $verify = (string) ABSPATH.WPINC.'/certificates/ca-bundle.crt';
    $responses = \WpOrg\Requests\Requests::request_multiple(array_values($requests), ['timeout' => $timeout, 'connect_timeout' => 10, 'follow_redirects' => false, 'verify' => is_readable($verify) ? $verify : true]);
    foreach (array_keys($requests) as $i => $url) {
        $r = $responses[$i] ?? null;
        if (! $r instanceof \WpOrg\Requests\Response || (int) $r->status_code === 401) continue;
        $data = json_decode((string) $r->body, true);
        aiworker_chat_api_prefetched($url, ['code' => (int) $r->status_code, 'body' => is_array($data) ? $data : [], 'error' => '']);
    }
}

/**
 * Calls kra.name's plugin API with the stored token; refreshes once and retries after a 401.
 * $raw is a body sent as it is instead of JSON, ['type' => content type, 'data' => bytes] (the voice upload).
 * @return array{code:int, body:array, error:string}
 */
function aiworker_chat_api(string $method, string $path, array $body = [], int $timeout = 20, array $raw = []): array {
    $stub = apply_filters('aiworker_chat_api_stub', null, $method, $path, $body);
    if (is_array($stub)) return $stub + ['code' => 200, 'body' => [], 'error' => ''];
    if ($method === 'GET') {
        $ready = aiworker_chat_api_prefetched(aiworker_chat_api_get_url($path, $body));
        if ($ready) return aiworker_chat_widget_version_seen($ready);
    }

    for ($attempt = 0; $attempt < 2; $attempt++) {
        $token = aiworker_chat_oauth_access_token($attempt > 0, $busy);
        if ($token === null) return ['code' => 503, 'body' => ['message' => $busy === 'unreachable' ? __('AI Worker cannot be reached right now. The connection is kept; try again in a minute.', 'needtome-ai-chat') : __('The connection to AI Worker is being renewed. Try again in a few seconds.', 'needtome-ai-chat')], 'error' => ''];
        if ($token === '') return ['code' => 401, 'body' => [], 'error' => 'not_connected'];
        $args = ['method' => $method, 'timeout' => $timeout, 'redirection' => 0, 'headers' => ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json']];
        $url = AIWORKER_CHAT_API.$path;
        if ($method === 'GET') {
            if ($body) $url = add_query_arg(array_map('rawurlencode', array_map('strval', $body)), $url);
        } elseif ($raw) {
            $args['headers']['Content-Type'] = (string) $raw['type'];
            $args['body'] = (string) $raw['data'];
        } else {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = wp_json_encode((object) $body);
        }
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) return ['code' => 0, 'body' => [], 'error' => $response->get_error_message()];
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 401 && $attempt === 0) continue;
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        return aiworker_chat_widget_version_seen(['code' => $code, 'body' => is_array($data) ? $data : [], 'error' => '']);
    }
    return ['code' => 401, 'body' => [], 'error' => 'not_connected'];
}

/**
 * /plugin/me and /plugin/chat name the current widget build; visitors load widget.js?v=<it>, so an update reaches them
 * at once and the browser cache holds otherwise (loader.js falls back to a daily version until one is known).
 */
function aiworker_chat_widget_version_seen(array $result): array {
    $v = $result['body']['widget_version'] ?? null;
    if ($result['code'] === 200 && (is_string($v) || is_int($v)) && preg_match('/^[A-Za-z0-9._-]{1,40}$/', (string) $v) && get_option('aiworker_chat_widget_version') !== (string) $v) {
        update_option('aiworker_chat_widget_version', (string) $v, true);
    }
    if ($result['code'] === 200 && is_array($result['body']) && array_key_exists('widget_version', $result['body'])) set_transient('aiworker_chat_widget_version_fresh', 1, HOUR_IN_SECONDS);
    return $result;
}

/**
 * kra.name updates the widget without telling the site, so a connected site asks for the build at most once an hour,
 * in a background WP-Cron run started by a page view (never inside the visitor's request).
 */
function aiworker_chat_widget_version_due(): void {
    if ((string) get_option('aiworker_chat_widget_version', '') === '' || get_transient('aiworker_chat_widget_version_fresh')) return;
    if (! wp_next_scheduled('aiworker_chat_widget_version_refresh')) wp_schedule_single_event(time(), 'aiworker_chat_widget_version_refresh');
}

add_action('aiworker_chat_widget_version_refresh', static function (): void {
    set_transient('aiworker_chat_widget_version_fresh', 1, HOUR_IN_SECONDS);   // one try an hour, also when kra.name is down
    if (aiworker_chat_is_connected()) aiworker_chat_api('GET', '/plugin/me', [], 10);
});
