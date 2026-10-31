<?php
/**
 * «Choose the chat automatically»: instead of copying the wk_… key from kra.name, the owner signs in at kra.name
 * (the same OAuth connection as the wizard), picks one of the account's online chats and confirms. kra.name adds this
 * site to the chat's allowed sites and returns the chat's public key (POST /plugin/link), which is saved here.
 */

if (! defined('ABSPATH')) exit;

/** The chat id linked here by «Connect», so the wizard works with that chat. */
const AIWORKER_CHAT_LINKED_OPTION = 'aiworker_chat_linked_chat';

/** This site's host as kra.name's allowed sites store it. */
function aiworker_chat_site_host(): string {
    return strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
}

/** Settings screen with the chat choice open. */
function aiworker_chat_pick_url(): string {
    return add_query_arg('aiwc_pick', '1', aiworker_chat_settings_url()).'#aiwc-pick';
}

/** Sign-in links: random token => ['user', 'then', 'until']. */
const AIWORKER_CHAT_SIGNIN_OPTION = 'aiworker_chat_signin_links';

/**
 * A plain link that starts the AI Worker sign-in, so it can be copied and opened in another browser that is signed
 * in to the right kra.name account. Its random token stands in for the WordPress session; it works once, for an hour.
 * $then = 'pick' comes back to the chat choice, '' to the wizard.
 */
function aiworker_chat_signin_url(string $then): string {
    $links = (array) get_option(AIWORKER_CHAT_SIGNIN_OPTION, []);
    $user = get_current_user_id();
    $token = '';
    foreach ($links as $t => $l) {
        if (! is_array($l) || (int) ($l['until'] ?? 0) < time()) unset($links[$t]);
        elseif ((int) $l['user'] === $user && $l['then'] === $then && (int) $l['until'] > time() + 10 * MINUTE_IN_SECONDS) $token = (string) $t;
    }
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        $links[$token] = ['user' => $user, 'then' => $then, 'until' => time() + HOUR_IN_SECONDS];
    }
    update_option(AIWORKER_CHAT_SIGNIN_OPTION, $links, false);
    return add_query_arg(['action' => 'aiwc_signin', 't' => $token], admin_url('admin-post.php'));
}

/** admin-post (with or without a WordPress session): open a sign-in link. */
function aiworker_chat_signin_open(): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The one-time random token stands in for the nonce.
    $token = isset($_GET['t']) ? sanitize_key(wp_unslash((string) $_GET['t'])) : '';
    $links = (array) get_option(AIWORKER_CHAT_SIGNIN_OPTION, []);
    $link = $token !== '' && isset($links[$token]) && is_array($links[$token]) ? $links[$token] : null;
    if ($link) {
        unset($links[$token]);
        update_option(AIWORKER_CHAT_SIGNIN_OPTION, $links, false);
    }
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only how the end is shown.
    $popup = ! empty($_GET['popup']);
    if (! $link || (int) $link['until'] < time() || ! user_can((int) $link['user'], 'manage_options')) {
        if (current_user_can('manage_options')) {
            if ($popup) aiworker_chat_popup_done(aiworker_chat_settings_url('link_expired'));
            wp_safe_redirect(aiworker_chat_settings_url('link_expired'));
            exit;
        }
        wp_die('<p>'.esc_html(aiworker_chat_assistant_notices()['link_expired'][1]).'</p>', esc_html__('NeedToMe AI Chat', 'aiworker-chat'), ['response' => 410]);
    }
    if (aiworker_chat_is_connected() && current_user_can('manage_options')) {
        if ((string) $link['then'] === 'pick') aiworker_chat_autolink_mark((int) $link['user']);
        if ($popup) aiworker_chat_popup_done(aiworker_chat_signin_next((string) $link['then']));
        wp_safe_redirect(aiworker_chat_signin_next((string) $link['then']));
        exit;
    }
    aiworker_chat_oauth_begin((string) $link['then'], (int) $link['user'], true, $popup);
}
add_action('admin_post_aiwc_signin', 'aiworker_chat_signin_open');
add_action('admin_post_nopriv_aiwc_signin', 'aiworker_chat_signin_open');

/** AJAX: has the sign-in finished (in this or another browser)? The settings screen asks while a link is in use. */
function aiworker_chat_signin_status(): void {
    if (! current_user_can('manage_options')) wp_send_json_error(null, 403);
    check_ajax_referer('aiwc_signin_status', 'nonce');
    wp_send_json_success(['connected' => aiworker_chat_oauth_tokens(true) !== null]);
}
add_action('wp_ajax_aiwc_signin_status', 'aiworker_chat_signin_status');

/** Links the chat to this site and saves its key; returns the notice to show. */
function aiworker_chat_link_chat(int $chat): string {
    $result = $chat > 0 ? aiworker_chat_api('POST', '/plugin/link', ['chat_id' => $chat, 'site' => aiworker_chat_site_host()]) : ['code' => 422, 'body' => [], 'error' => ''];
    $key = (string) ($result['body']['key'] ?? '');
    if ($result['code'] === 200 && aiworker_chat_valid_key($key)) {
        $o = aiworker_chat_options();
        $o['key'] = $key;
        update_option(AIWORKER_CHAT_OPTION, $o);
        update_option(AIWORKER_CHAT_LINKED_OPTION, $chat, false);   // the chat the wizard focuses on
        $notice = 'linked';
    } elseif (in_array($result['code'], [404, 405], true) && empty($result['body']['code'])) {
        $notice = 'link_unsupported';   // a kra.name without /plugin/link
    } elseif (($result['body']['code'] ?? '') === 'too_many_domains') {
        $notice = 'link_domains';
    } elseif ($result['code'] === 401) {
        $notice = 'failed';
    } else {
        $notice = 'link_failed';
    }
    return $notice;
}

/** admin-post: «Connect» — link the chosen chat to this site and save its key. */
function aiworker_chat_pick_link(): void {
    if (! current_user_can('manage_options')) wp_die(esc_html__('You are not allowed to do this.', 'aiworker-chat'), 403);
    check_admin_referer('aiwc_chat_link');
    $chat = isset($_POST['chat_id']) ? absint(wp_unslash((string) $_POST['chat_id'])) : 0;
    wp_safe_redirect(add_query_arg('aiw_notice', aiworker_chat_link_chat($chat), aiworker_chat_settings_url()));
    exit;
}
add_action('admin_post_aiwc_chat_link', 'aiworker_chat_pick_link');

/** Drops the cached /plugin/me in every language (after a connection, a disconnection or a change of the chat). */
function aiworker_chat_forget_me(): void {
    foreach (['ru', 'en', 'uk'] as $lang) delete_transient('aiworker_chat_me_'.$lang);
}

/** The connected kra.name account (/plugin/me, cached 5 minutes): ['account' => …, 'chats' => […]]; null when it cannot be read. */
function aiworker_chat_account_me(bool $fresh = false): ?array {
    // per language: the plan name and the chat labels come in the screen's language
    $key = 'aiworker_chat_me_'.aiworker_chat_wizard_lang();
    $cached = $fresh ? false : get_transient($key);
    if (is_array($cached) && isset($cached['chats'])) return $cached;
    $me = aiworker_chat_api('GET', '/plugin/me', ['lang' => aiworker_chat_wizard_lang()]);
    if ($me['code'] !== 200) return null;
    $data = ['account' => (array) ($me['body']['account'] ?? []), 'chats' => array_values(array_filter((array) ($me['body']['chats'] ?? []), 'is_array'))];
    set_transient($key, $data, 5 * MINUTE_IN_SECONDS);
    return $data;
}

/** The account's online chats; null when they cannot be read. */
function aiworker_chat_account_chats(bool $fresh = false): ?array {
    $me = aiworker_chat_account_me($fresh);
    return $me === null ? null : $me['chats'];
}

/** Whether the chat choice is open: asked for (aiwc_pick=1), or no key yet and not cancelled (aiwc_pick=0). */
function aiworker_chat_pick_open(): bool {
    if (! aiworker_chat_is_connected()) return false;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only switch from our own links.
    $pick = isset($_GET['aiwc_pick']) ? sanitize_key(wp_unslash((string) $_GET['aiwc_pick'])) : '';
    return $pick === '1' || ($pick === '' && (string) aiworker_chat_options()['key'] === '');
}

/**
 * A one-time mark that the administrator has just finished the sign-in started with «Choose the chat automatically»
 * (10 minutes). Only with it does the settings screen link the account's only chat by itself: a plain link to the
 * screen (aiwc_pick=1) cannot change the key; it only opens the choice, whose «Connect» is a POST with a nonce.
 */
function aiworker_chat_autolink_mark(int $user): void {
    if ($user > 0) set_transient('aiworker_chat_autolink_'.$user, 1, 10 * MINUTE_IN_SECONDS);
}

/**
 * Right after that sign-in, an account with only one online chat has nothing to choose, and the owner has just allowed
 * the connection, so that chat is linked at once instead of asking again. The mark is used up either way.
 */
function aiworker_chat_pick_single(): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Only opens the choice; linking needs the one-time mark below.
    if (isset($_GET['aiw_notice']) || ! current_user_can('manage_options') || ! aiworker_chat_pick_open()) return;
    $mark = 'aiworker_chat_autolink_'.get_current_user_id();
    if (! get_transient($mark)) return;
    delete_transient($mark);
    $chats = aiworker_chat_account_chats(true);
    if (! $chats || count($chats) !== 1) return;
    wp_safe_redirect(add_query_arg('aiw_notice', aiworker_chat_link_chat((int) $chats[0]['id']), aiworker_chat_settings_url()));
    exit;
}
add_action('load-needtome_page_aiworker-chat', 'aiworker_chat_pick_single');

/**
 * Under the key field: the offer, or — after the button and with a connection — the account's chats to choose from.
 * Its controls belong to the forms printed by aiworker_chat_pick_forms(), outside the settings form.
 */
function aiworker_chat_pick_panel(): void {
    $key = (string) aiworker_chat_options()['key'];
    $connected = aiworker_chat_is_connected();
    $open = aiworker_chat_pick_open();
    echo '<div class="aiwc-pick" id="aiwc-pick">';
    $label = static function (array $c): string {
        $name = (string) ($c['chat_name'] ?? $c['name'] ?? '');
        $persona = (string) ($c['persona']['name'] ?? '');
        /* translators: 1: chat name, 2: consultant name */
        return $persona !== '' ? sprintf(__('%1$s (consultant %2$s)', 'aiworker-chat'), $name, $persona) : $name;
    };
    if (! $open) {
        if (! $connected) {
            $then = $key === '' ? 'pick' : '';
            echo '<h3>'.esc_html($key === '' ? __('Connect automatically', 'aiworker-chat') : __('Setup wizard', 'aiworker-chat')).'</h3>';
            if ($key !== '') echo '<p>'.esc_html__('Sign in to AI Worker to set up this chat from the site with the setup wizard and see its statistics.', 'aiworker-chat').'</p>';
            echo '<p>'.aiworker_chat_signin_link($then, $key === '' ? __('Choose the chat automatically', 'aiworker-chat') : __('Sign in to AI Worker', 'aiworker-chat')).'</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
            if ($key === '') echo '<p class="description">'.esc_html__('Click the link and choose the chat you need from your kra.name chats (if you are not signed in, you will be asked to sign in).', 'aiworker-chat').'</p>';
        } else {
            // the linked chat by name; nothing to choose from with one chat; with none, the key field and the wizard say what to do
            $chats = aiworker_chat_account_chats();
            $linked = (int) get_option(AIWORKER_CHAT_LINKED_OPTION, 0);
            foreach ((array) $chats as $c) {
                if ($key === '' || (int) ($c['id'] ?? 0) !== $linked) continue;
                $persona = (string) ($c['persona']['name'] ?? '');
                // with one chat its name says nothing: the consultant does
                if (count($chats) === 1 && $persona !== '') {
                    /* translators: %s: consultant name */
                    echo '<h3>'.esc_html(sprintf(__('This site\'s chat: consultant %s', 'aiworker-chat'), $persona)).'</h3>';
                } else {
                    /* translators: %s: chat name with its consultant */
                    echo '<h3>'.esc_html(sprintf(__('This site\'s chat: %s', 'aiworker-chat'), $label($c))).'</h3>';
                }
                echo '<p class="description">'.esc_html__('Connected automatically through AI Worker. To enter a chat key by hand, disconnect AI Worker.', 'aiworker-chat').'</p>';
            }
            if ($chats === null || count($chats) > 1 || ($key === '' && $chats)) echo '<p><a href="'.esc_url(aiworker_chat_pick_url()).'">'.esc_html($key === '' ? __('Choose the chat', 'aiworker-chat') : __('Choose another chat', 'aiworker-chat')).'</a></p>';
            // the account it is (its email with the middle hidden, by kra.name), next to the way out of it
            $account = (array) (aiworker_chat_account_me()['account'] ?? []);
            $who = (string) ($account['email_masked'] ?? $account['name'] ?? '');
            $plan = (string) ($account['plan_name'] ?? '');
            echo '<p class="aiwc-pick__account"><span class="aiwc-pill aiwc-pill--ok">'.esc_html__('AI Worker connected', 'aiworker-chat').'</span>'
                .($who !== '' ? ' <span class="aiwc-aw__account">'.esc_html($who.($plan !== '' ? ' · '.$plan : '')).'</span>' : '')
                .' <button type="submit" form="aiwc-disconnect-form" class="button">'.esc_html__('Disconnect AI Worker', 'aiworker-chat').'</button></p>';
            echo '<p class="description">'.esc_html__('The chat on the site keeps working; only the wizard and the statistics need the connection.', 'aiworker-chat').'</p>';
        }
        echo '</div>';
        return;
    }
    $chats = aiworker_chat_account_chats(true);
    $host = aiworker_chat_site_host();
    $cancel = '<a href="'.esc_url(add_query_arg('aiwc_pick', '0', aiworker_chat_settings_url())).'">'.esc_html__('Cancel', 'aiworker-chat').'</a>';
    if ($chats === null) {
        echo '<p class="aiwc-pick__error">'.esc_html__('The list of chats could not be loaded from kra.name. Try again in a minute.', 'aiworker-chat').' '.$cancel.'</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
    } elseif (! $chats) {
        echo '<p>'.wp_kses(sprintf(
            /* translators: %s: link to the kra.name panel */
            __('Your AI Worker account has no online chat yet. Create one in the %s, then choose it here.', 'aiworker-chat'),
            '<a href="https://kra.name/app/panel" target="_blank" rel="noopener">'.esc_html__('kra.name panel', 'aiworker-chat').'</a>'), ['a' => ['href' => [], 'target' => [], 'rel' => []]]).' '.$cancel.'</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
    } elseif (count($chats) === 1) {
        echo '<input type="hidden" form="aiwc-link-form" name="chat_id" value="'.esc_attr((string) (int) $chats[0]['id']).'">';
        /* translators: 1: this site's domain, 2: chat name */
        echo '<p><strong>'.esc_html(sprintf(__('Connect %1$s to the chat «%2$s»?', 'aiworker-chat'), $host, $label($chats[0]))).'</strong></p>';
        echo '<p><button type="submit" form="aiwc-link-form" class="button button-primary">'.esc_html__('Connect', 'aiworker-chat').'</button> '.$cancel.'</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
    } else {
        /* translators: %s: this site's domain */
        echo '<p><label for="aiwc-pick-chat"><strong>'.esc_html(sprintf(__('Which chat should %s use?', 'aiworker-chat'), $host)).'</strong></label></p><p><select id="aiwc-pick-chat" form="aiwc-link-form" name="chat_id">';
        foreach ($chats as $c) {
            $domains = (array) ($c['site_domains'] ?? []);
            echo '<option value="'.esc_attr((string) (int) $c['id']).'"'.selected(in_array($host, $domains, true), true, false).'>'.esc_html($label($c)).'</option>';
        }
        echo '</select> <button type="submit" form="aiwc-link-form" class="button button-primary">'.esc_html__('Connect', 'aiworker-chat').'</button> '.$cancel.'</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
    }
    echo '</div>';
}

/** The form the chat choice submits; printed after the settings form, since forms cannot nest. */
function aiworker_chat_pick_forms(): void {
    foreach (['aiwc-link-form' => 'aiwc_chat_link'] as $id => $action) {
        echo '<form id="'.esc_attr($id).'" method="post" action="'.esc_url(admin_url('admin-post.php')).'" hidden><input type="hidden" name="action" value="'.esc_attr($action).'">';
        wp_nonce_field($action);
        echo '</form>';
    }
}

/** Where the settings screen goes once a sign-in started there has finished. */
function aiworker_chat_signin_next(string $then): string {
    if ($then === 'pick') return aiworker_chat_pick_url();
    if ($then === 'kb') return add_query_arg('tab', 'kb', aiworker_chat_settings_url());
    if ($then === 'telegram') return add_query_arg('aiwc_sub', 'telegram', aiworker_chat_settings_url()).'#aiwc-wizard';
    return aiworker_chat_settings_url('connected');
}

/** The sign-in link as a button; the first one on the screen also prints the script that waits for the sign-in. */
function aiworker_chat_signin_link(string $then, string $label): string {
    static $script = false;
    $html = '<a class="button button-primary aiwc-signin" href="'.esc_url(aiworker_chat_signin_url($then)).'" data-next="'.esc_url(aiworker_chat_signin_next($then)).'">'.esc_html($label).'</a>';
    if (! $script) {
        $script = true;
        add_action('admin_footer', 'aiworker_chat_signin_script');
    }
    return $html;
}

/**
 * The end of a sign-in opened in a popup: the settings screen that opened it goes on to $next and the popup closes.
 * Without its opener (the popup was opened as a tab, or kra.name cut the link) the popup itself goes on to $next.
 */
function aiworker_chat_popup_done(string $next): void {
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html><meta charset="utf-8"><title><?php esc_html_e('NeedToMe AI Chat', 'aiworker-chat'); ?></title>
    <script>
    (function (next) {
      try {
        var o = window.opener;
        if (o && ! o.closed && o.location.host === location.host) {
          o.location.href = next;
          if (next.split('#')[0] === o.location.href.split('#')[0]) o.location.reload();
          window.close();
          return;
        }
      } catch (e) {}
      location.replace(next);
    })(<?php echo wp_json_encode($next); ?>);
    </script>
    <?php
    exit;
}

/**
 * The sign-in opens in a popup so the settings screen stays where it is (a new tab if the browser refuses the popup).
 * While a link is in use (clicked, copied, right-clicked), ask every few seconds and go on once it is done.
 */
function aiworker_chat_signin_script(): void {
    $data = ['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('aiwc_signin_status')];
    ?>
    <script>
    (function (d) {
      var c = <?php echo wp_json_encode($data); ?>, timer = 0, until = 0, next = '';
      function ask() {
        if (Date.now() > until) { clearInterval(timer); timer = 0; return; }
        if (document.hidden) return;
        var b = new FormData(); b.append('action', 'aiwc_signin_status'); b.append('nonce', c.nonce);
        fetch(c.ajax, {method: 'POST', body: b, credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (r) {
          if (r && r.success && r.data.connected) { clearInterval(timer); location.href = next; if (next.split('#')[0] === location.href.split('#')[0]) location.reload(); }
        }).catch(function () {});
      }
      function watch(e) { next = e.currentTarget.getAttribute('data-next'); until = Date.now() + 60 * 60 * 1000; if (! timer) timer = setInterval(ask, 4000); }
      function open(e) {
        if (e.button || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;   // the owner's own new tab or window
        var w = 560, h = 760, x = Math.max(0, (screen.availWidth - w) / 2), y = Math.max(0, (screen.availHeight - h) / 2);
        var p = window.open(e.currentTarget.href + '&popup=1', 'aiwc-signin', 'popup,width=' + w + ',height=' + h + ',left=' + x + ',top=' + y);
        if (p) { e.preventDefault(); p.focus(); } else { e.currentTarget.target = '_blank'; }
      }
      d.querySelectorAll('.aiwc-signin').forEach(function (a) { ['click', 'contextmenu', 'copy', 'auxclick'].forEach(function (e) { a.addEventListener(e, watch); }); a.addEventListener('click', open); });
    })(document);
    </script>
    <?php
}
