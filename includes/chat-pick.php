<?php
/**
 * «Choose the chat automatically»: instead of copying the wk_… key from kra.name, the owner signs in at kra.name
 * (the same OAuth connection as the wizard), picks one of the account's online chats and confirms. kra.name adds this
 * site to the chat's allowed sites and returns the chat's public key (POST /plugin/link), which is saved here.
 */

if (! defined('ABSPATH')) exit;

/** The legacy single-chat ID option, read by the migration into the first chat's settings. */
const AIWORKER_CHAT_LINKED_OPTION = 'aiworker_chat_linked_chat';

/** This site's host as kra.name's allowed sites store it. */
function aiworker_chat_site_host(): string {
    return strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
}

/** Settings screen with the chat choice open. */
function aiworker_chat_pick_url(?string $chatId = null): string {
    return add_query_arg('aiworker_chat_pick', '1', aiworker_chat_settings_url('', $chatId)).'#aiworker-chat-pick';
}

/** Sign-in links: random token => ['user', 'then', 'chat_id', 'until']. */
const AIWORKER_CHAT_SIGNIN_OPTION = 'aiworker_chat_signin_links';

/**
 * A plain link that starts the AI Worker sign-in, so it can be copied and opened in another browser that is signed
 * in to the right kra.name account. Its random token stands in for the WordPress session; it works once, for an hour.
 * $then = 'pick' comes back to the chat choice, '' to the wizard; both retain the current local chat tab.
 */
function aiworker_chat_signin_url(string $then, ?string $chatId = null): string {
    $links = (array) get_option(AIWORKER_CHAT_SIGNIN_OPTION, []);
    $user = get_current_user_id();
    $chatId = $chatId !== null && in_array($chatId, aiworker_chat_ids(), true) ? $chatId : aiworker_chat_current_id();
    $token = '';
    foreach ($links as $t => $l) {
        if (! is_array($l) || (int) ($l['until'] ?? 0) < time()) unset($links[$t]);
        elseif ((int) $l['user'] === $user && ($l['then'] ?? '') === $then && ($l['chat_id'] ?? '') === $chatId && (int) $l['until'] > time() + 10 * MINUTE_IN_SECONDS) $token = (string) $t;
    }
    if ($token === '') {
        $token = bin2hex(random_bytes(16));
        $links[$token] = ['user' => $user, 'then' => $then, 'chat_id' => $chatId, 'until' => time() + HOUR_IN_SECONDS];
    }
    update_option(AIWORKER_CHAT_SIGNIN_OPTION, $links, false);
    return add_query_arg(['action' => 'aiworker_chat_signin', 't' => $token], admin_url('admin-post.php'));
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
        wp_die('<p>'.esc_html(aiworker_chat_assistant_notices()['link_expired'][1]).'</p>', esc_html__('NeedToMe AI Chat', 'needtome-ai-chat'), ['response' => 410]);
    }
    if (aiworker_chat_is_connected() && current_user_can('manage_options')) {
        if ($popup) aiworker_chat_popup_done(aiworker_chat_signin_next((string) $link['then'], (string) ($link['chat_id'] ?? '')));
        wp_safe_redirect(aiworker_chat_signin_next((string) $link['then'], (string) ($link['chat_id'] ?? '')));
        exit;
    }
    aiworker_chat_oauth_begin((string) $link['then'], (int) $link['user'], true, $popup, (string) ($link['chat_id'] ?? ''));
}
add_action('admin_post_aiworker_chat_signin', 'aiworker_chat_signin_open');
add_action('admin_post_nopriv_aiworker_chat_signin', 'aiworker_chat_signin_open');

/** AJAX: has the sign-in finished (in this or another browser)? The settings screen asks while a link is in use. */
function aiworker_chat_signin_status(): void {
    if (! current_user_can('manage_options')) wp_send_json_error(null, 403);
    check_ajax_referer('aiworker_chat_signin_status', 'nonce');
    wp_send_json_success(['connected' => aiworker_chat_oauth_tokens(true) !== null]);
}
add_action('wp_ajax_aiworker_chat_signin_status', 'aiworker_chat_signin_status');

/** Links the chat to this site and saves its key; returns the notice to show. */
function aiworker_chat_link_chat(int $chat): string {
    $result = $chat > 0 ? aiworker_chat_api('POST', '/plugin/link', ['chat_id' => $chat, 'site' => aiworker_chat_site_host()]) : ['code' => 422, 'body' => [], 'error' => ''];
    $key = (string) ($result['body']['key'] ?? '');
    if ($result['code'] === 200 && aiworker_chat_valid_key($key)) {
        $id = aiworker_chat_add($key, $chat, sanitize_text_field((string) ($result['body']['chat']['name'] ?? '')));
        if ($id === '') return 'link_failed';
        // Learn this chat's greeting choice before its first public page; public requests never fetch it.
        $settings = aiworker_chat_api('GET', '/plugin/chat', ['chat_id' => $chat, 'lang' => aiworker_chat_wizard_lang()]);
        if ($settings['code'] === 200 && is_array($settings['body']['chat'] ?? null)) {
            aiworker_chat_with($id, static function () use ($settings): void { aiworker_chat_remember_remote($settings['body']['chat']); });
        }
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
    if (! current_user_can('manage_options')) wp_die(esc_html__('You are not allowed to do this.', 'needtome-ai-chat'), 403);
    check_admin_referer('aiworker_chat_chat_link');
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- IDs are individually converted to positive integers.
    $chosen = (array) wp_unslash($_POST['chat_ids'] ?? $_POST['chat_id'] ?? []);
    $notices = [];
    foreach (array_unique(array_map('absint', array_filter($chosen, 'is_scalar'))) as $chat) if ($chat > 0) $notices[$chat] = aiworker_chat_link_chat($chat);
    // results of an earlier attempt must not outlive this one
    delete_transient('aiworker_chat_link_results_'.get_current_user_id());
    // a chat added from the list opens at once; a failure comes back to the choice, which says why
    if (! empty($_POST['adding']) && $notices && ! array_diff($notices, ['linked'])) {
        foreach (aiworker_chat_chats() as $saved) {
            if ((int) $saved['chat_id'] !== (int) array_key_last($notices)) continue;
            wp_safe_redirect(add_query_arg('aiworker_chat_instance', $saved['id'], Aiworker_Chat_Profile::url('aiworker-chat')));
            exit;
        }
    }
    // one chat linked: its settings open with the result; a failure comes back to the choice, which says why
    if (count($notices) === 1 && reset($notices) === 'linked') {
        foreach (aiworker_chat_chats() as $saved) if ((int) $saved['chat_id'] === (int) array_key_last($notices)) {
            wp_safe_redirect(aiworker_chat_settings_url('linked', $saved['id']));
            exit;
        }
    }
    set_transient('aiworker_chat_link_results_'.get_current_user_id(), $notices, 10 * MINUTE_IN_SECONDS);
    wp_safe_redirect(add_query_arg('aiworker_chat_pick', '1', aiworker_chat_settings_url()));
    exit;
}
add_action('admin_post_aiworker_chat_chat_link', 'aiworker_chat_pick_link');

/** The connected kra.name account (/plugin/me, cached 5 minutes): ['account' => …, 'chats' => […]]; null when it cannot be read. */
function aiworker_chat_account_me(bool $fresh = false): ?array {
    // per language: the plan name and the chat labels come in the screen's language
    $key = 'aiworker_chat_me_'.aiworker_chat_wizard_lang();
    $cached = $fresh ? false : get_transient($key);
    if (is_array($cached) && isset($cached['chats'])) return $cached;
    $me = aiworker_chat_api('GET', '/plugin/me', ['lang' => aiworker_chat_wizard_lang()]);
    if ($me['code'] !== 200) return null;
    $data = ['account' => (array) ($me['body']['account'] ?? []), 'chats' => array_values(array_filter((array) ($me['body']['chats'] ?? []), 'is_array')), 'chat_slots' => is_array($me['body']['chat_slots'] ?? null) ? $me['body']['chat_slots'] : null];
    set_transient($key, $data, 5 * MINUTE_IN_SECONDS);
    return $data;
}

/** The account's online chats; null when they cannot be read. */
function aiworker_chat_account_chats(bool $fresh = false): ?array {
    $me = aiworker_chat_account_me($fresh);
    return $me === null ? null : $me['chats'];
}

/** Whether the chat choice is open: asked for (aiworker_chat_pick=1), or no key yet and not cancelled (aiworker_chat_pick=0). */
function aiworker_chat_pick_open(): bool {
    if (! aiworker_chat_is_connected()) return false;
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only switch from our own links.
    $pick = isset($_GET['aiworker_chat_pick']) ? sanitize_key(wp_unslash((string) $_GET['aiworker_chat_pick'])) : '';
    return $pick === '1' || ($pick === '' && (string) aiworker_chat_options()['key'] === '');
}

/**
 * A site whose chat key was entered by hand and which connected AI Worker later has no linked chat, so the chat's
 * settings blocks stay hidden. The account's chat that already serves this site's domain is linked when kra.name returns
 * the same key for it; another key is left alone and the owner chooses the chat. Tried once per ten minutes.
 */
function aiworker_chat_adopt_key(): void {
    if (! current_user_can('manage_options') || ! aiworker_chat_is_connected()) return;
    $key = (string) aiworker_chat_options()['key'];
    if ($key === '' || aiworker_chat_linked_id() > 0 || get_transient(aiworker_chat_option_name('aiworker_chat_adopt_tried'))) return;
    set_transient(aiworker_chat_option_name('aiworker_chat_adopt_tried'), 1, 10 * MINUTE_IN_SECONDS);
    $host = aiworker_chat_site_host();
    $mine = array_values(array_filter((array) aiworker_chat_account_chats(true), static function ($c) use ($host): bool {
        return is_array($c) && in_array($host, (array) ($c['site_domains'] ?? []), true);
    }));
    if (count($mine) !== 1) return;
    $result = aiworker_chat_api('POST', '/plugin/link', ['chat_id' => (int) $mine[0]['id'], 'site' => $host]);
    if ($result['code'] === 200 && (string) ($result['body']['key'] ?? '') === $key) {
        $o = aiworker_chat_options();
        $o['chat_id'] = (int) $mine[0]['id'];
        $o['name'] = sanitize_text_field((string) ($mine[0]['chat_name'] ?? $mine[0]['name'] ?? ''));
        aiworker_chat_store(AIWORKER_CHAT_OPTION, $o);
    }
}
add_action('load-needtome_page_aiworker-chat', 'aiworker_chat_adopt_key', 5);

/**
 * Under the key field: the offer, or — after the button and with a connection — the account's chats to choose from.
 * Its controls belong to the forms printed by aiworker_chat_pick_forms(), outside the settings form.
 */
function aiworker_chat_pick_html(bool $adding = false): string {
    $html = '';
    $key = $adding ? '' : (string) aiworker_chat_options()['key'];
    $connected = aiworker_chat_is_connected();
    if ($adding && ! $connected) return ''; // nothing to choose from: the new row takes a key by hand
    $open = $adding || aiworker_chat_pick_open();
    $html .= '<div class="aiworker-chat-pick" id="aiworker-chat-pick">';
    $label = static function (array $c): string {
        $name = (string) ($c['chat_name'] ?? $c['name'] ?? '');
        $persona = (string) ($c['persona']['name'] ?? '');
        /* translators: 1: chat name, 2: consultant name */
        return $persona !== '' ? sprintf(__('%1$s (consultant %2$s)', 'needtome-ai-chat'), $name, $persona) : $name;
    };
    if (! $open) {
        if (! $connected) {
            $then = $key === '' ? 'pick' : '';
            $html .= '<h3>'.esc_html($key === '' ? __('Connect automatically', 'needtome-ai-chat') : __('Setup wizard', 'needtome-ai-chat')).'</h3>';
            if ($key !== '') $html .= '<p>'.esc_html__('Sign in to AI Worker to use the setup wizard, statistics, chat settings, knowledge sync and catalog sync.', 'needtome-ai-chat').'</p>';
            $html .= '<p>'.aiworker_chat_signin_link($then, $key === '' ? __('Choose the chat automatically', 'needtome-ai-chat') : __('Sign in to AI Worker', 'needtome-ai-chat')).'</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
            if ($key === '') $html .= '<p class="description">'.esc_html__('Click the link and choose the chat you need from your kra.name chats (if you are not signed in, you will be asked to sign in).', 'needtome-ai-chat').'</p>';
        } else {
            // the linked chat by name
            $chats = aiworker_chat_account_chats();
            $linked = aiworker_chat_linked_id();
            foreach ((array) $chats as $c) {
                if ($key === '' || (int) ($c['id'] ?? 0) !== $linked) continue;
                $persona = (string) ($c['persona']['name'] ?? '');
                // with one chat its name says nothing: the consultant does
                if (count($chats) === 1 && $persona !== '') {
                    /* translators: %s: consultant name */
                    $html .= '<h3>'.esc_html(sprintf(__('This site\'s chat: consultant %s', 'needtome-ai-chat'), $persona)).'</h3>';
                } else {
                    /* translators: %s: chat name with its consultant */
                    $html .= '<h3>'.esc_html(sprintf(__('This site\'s chat: %s', 'needtome-ai-chat'), $label($c))).'</h3>';
                }
                $html .= '<p class="description">'.esc_html__('Connected through AI Worker. To enter a chat key by hand, disconnect AI Worker.', 'needtome-ai-chat').'</p>';
            }
            // another of the account's chats, or a new one (a key entered by hand that is not the account's: the chat to set up)
            $html .= '<p><a href="'.esc_url(aiworker_chat_pick_url()).'">'.esc_html($key === '' || $linked === 0 ? __('Choose the chat', 'needtome-ai-chat') : __('Choose another chat or create a new one', 'needtome-ai-chat')).'</a></p>';
            // the account it is (its email with the middle hidden, by kra.name), next to the way out of it
            $account = (array) (aiworker_chat_account_me()['account'] ?? []);
            $who = (string) ($account['email_masked'] ?? $account['name'] ?? '');
            $plan = (string) ($account['plan_name'] ?? '');
            $html .= '<p class="aiworker-chat-pick__account"><span class="aiworker-chat-pill aiworker-chat-pill--ok">'.esc_html__('AI Worker connected', 'needtome-ai-chat').'</span>'
                .($who !== '' ? ' <span class="aiworker-chat-aw__account">'.esc_html($who.($plan !== '' ? ' · '.$plan : '')).'</span>' : '')
                .' <button type="submit" form="aiworker-chat-disconnect-form" class="button">'.esc_html__('Disconnect AI Worker', 'needtome-ai-chat').'</button></p>';
            $html .= '<p class="description">'.esc_html__('The chat on the site keeps working after disconnecting. The setup wizard, statistics, chat settings blocks, knowledge sync and catalog sync need the AI Worker connection.', 'needtome-ai-chat').'</p>';
        }
        $html .= '</div>';
        return $html;
    }
    $chats = aiworker_chat_account_chats(true);
    $cancel = '<a href="'.esc_url(add_query_arg('aiworker_chat_pick', '0', aiworker_chat_settings_url())).'">'.esc_html__('Cancel', 'needtome-ai-chat').'</a>';
    // «Add another chat» opened an empty chat: cancelling takes it away again
    if ($key === '' && count(aiworker_chat_ids()) > 1) $cancel = '<button type="submit" class="button-link" form="aiworker-chat-list-form" name="remove" value="'.esc_attr(aiworker_chat_current_id()).'">'.esc_html__('Cancel', 'needtome-ai-chat').'</button>';
    // the new chat exists only on the page
    if ($adding) {
        $cancel = '<button type="button" class="button-link" data-aiworker-chat-add-cancel>'.esc_html__('Cancel', 'needtome-ai-chat').'</button>';
        $html .= '<input type="hidden" form="aiworker-chat-link-form" name="adding" value="1">';
    }
    // an empty chat can only take a chat that is not on this site yet
    $free = $chats;
    if ($key === '' && $chats) {
        $taken = array_column(aiworker_chat_chats(), 'chat_id');
        $free = array_values(array_filter($chats, static function (array $c) use ($taken): bool { return ! in_array((int) ($c['id'] ?? 0), $taken, true); }));
    }
    if ($chats === null) {
        $html .= '<p class="aiworker-chat-pick__error">'.esc_html__('The list of chats could not be loaded from kra.name. Try again in a minute.', 'needtome-ai-chat').' '.$cancel.'</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
        return $html.'</div>';
    }
    if ($chats && ! $free) {
        $html .= '<p>'.esc_html__('All chats of your AI Worker account are already on this site.', 'needtome-ai-chat').'</p>';
    } elseif (! $free) {
        $html .= '<p>'.esc_html__('Your AI Worker account has no online chat yet.', 'needtome-ai-chat').'</p>';
    } else {
        $html .= aiworker_chat_pick_list($free, $key);
    }
    $html .= aiworker_chat_create_box();
    $html .= '<p>'.$cancel.'</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped above.
    return $html.'</div>';
}

/**
 * The account's chats to choose from, each with its own «Connect this chat» button («Reconnect» for the current one).
 * Nothing is linked without that click: a single chat is only marked as the obvious choice.
 */
function aiworker_chat_pick_list(array $chats, string $key): string {
    $results = (array) get_transient('aiworker_chat_link_results_'.get_current_user_id());
    $notices = aiworker_chat_assistant_notices();
    $current = $key !== '' ? aiworker_chat_linked_id() : 0;
    $html = '<p><strong>'.esc_html__('Choose a chat for this site', 'needtome-ai-chat').'</strong></p><div class="aiworker-chat-pick__list">';
    foreach ($chats as $c) {
        $id = (int) ($c['id'] ?? 0);
        if ($id <= 0) continue;
        $name = (string) ($c['chat_name'] ?? $c['name'] ?? '') ?: '#'.$id;
        $preselected = $id === $current || ($current === 0 && count($chats) === 1);
        $domains = array_values(array_filter((array) ($c['site_domains'] ?? []), static function ($d): bool { return is_string($d) && $d !== ''; }));
        $persona = (string) ($c['persona']['name'] ?? '');
        $html .= '<div class="aiworker-chat-pick__chat'.($preselected ? ' is-selected' : '').'"><div class="aiworker-chat-pick__info"><p class="aiworker-chat-pick__title"><strong>'.esc_html($name).'</strong>';
        if ($id === $current) $html .= ' <span class="aiworker-chat-pill aiworker-chat-pill--ok">'.esc_html__('Connected to this site', 'needtome-ai-chat').'</span>';
        $html .= ' <span class="aiworker-chat-pill aiworker-chat-pill--'.(! empty($c['enabled']) ? 'ok' : 'off').'">'.esc_html(! empty($c['enabled']) ? __('Enabled', 'needtome-ai-chat') : __('Disabled', 'needtome-ai-chat')).'</span>';
        $html .= ' <span class="aiworker-chat-pill aiworker-chat-pill--off">'.esc_html(($c['owner'] ?? '') === 'platform' ? __('Platform', 'needtome-ai-chat') : __('Account', 'needtome-ai-chat')).'</span></p>';
        /* translators: %s: consultant name */
        $html .= '<p class="description">'.esc_html(sprintf(__('Consultant: %s', 'needtome-ai-chat'), $persona !== '' ? $persona : '—')).'<br>';
        /* translators: %s: comma-separated list of the chat's site domains */
        $html .= esc_html(sprintf(__('Sites: %s', 'needtome-ai-chat'), $domains ? implode(', ', $domains) : __('not set', 'needtome-ai-chat'))).'</p>';
        if (isset($results[$id], $notices[$results[$id]])) $html .= '<p class="'.($results[$id] === 'linked' ? 'description' : 'aiworker-chat-pick__error').'">'.esc_html($notices[$results[$id]][1]).'</p>';
        $html .= '</div>';
        // the current chat links again too: kra.name re-adds this site to its allowed sites and returns its key
        $html .= '<button type="submit" form="aiworker-chat-link-form" name="chat_id" value="'.esc_attr((string) $id).'" class="button'.($preselected && $id !== $current ? ' button-primary' : '').'">'.esc_html($id === $current ? __('Reconnect', 'needtome-ai-chat') : __('Connect this chat', 'needtome-ai-chat')).'</button>';
        $html .= '</div>';
    }
    return $html.'</div>';
}

/**
 * «Create a new chat»: the chat is made in the kra.name panel. When the plan has no free chat, the reason from kra.name,
 * the price of an extra chat and the way to buy it come first (/plugin/me chat_slots).
 */
function aiworker_chat_create_box(): string {
    $slots = (array) (aiworker_chat_account_me()['chat_slots'] ?? []);
    $panel = aiworker_chat_base().'/panel';
    $html = '<div class="aiworker-chat-pick__create"><p><strong>'.esc_html__('Create a new chat', 'needtome-ai-chat').'</strong></p>';
    $unavailable = ($slots['can_create'] ?? true) === false;
    // no free place in the plan, but a new chat can still be made and is billed on top of it
    $extra = ! $unavailable && (! empty($slots['needs_extra_chat']) || (array_key_exists('available', $slots) && $slots['available'] === 0));
    if ($unavailable || $extra) {
        $reason = is_string($slots['reason'] ?? null) && $slots['reason'] !== '' ? $slots['reason']
            : ($extra ? __('Your plan has no free website chat slot: a new chat is billed separately.', 'needtome-ai-chat') : '');
        if ($reason !== '') $html .= '<p class="'.($unavailable ? 'aiworker-chat-pick__error' : 'aiworker-chat-pick__note').'">'.esc_html($reason).'</p>';
        $price = $slots['extra_chat_price_usd'] ?? null;
        /* translators: %s: price in US dollars */
        $html .= '<p class="description">'.esc_html(is_int($price) || is_float($price) ? sprintf(__('An extra website chat costs +$%s per month.', 'needtome-ai-chat'), number_format_i18n((float) $price, floor((float) $price) == (float) $price ? 0 : 2)) : __('See the billing page for the current price of an extra website chat.', 'needtome-ai-chat')).'</p>';
        if ($unavailable) {
            $buy = is_string($slots['buy_url'] ?? null) && strpos($slots['buy_url'], $panel.'/') === 0 ? $slots['buy_url'] : $panel.'/billing?add_chat=1';
            $html .= '<p><a class="button button-primary" href="'.esc_url($buy).'" target="_blank" rel="noopener">'.esc_html__('Buy an extra chat or choose a higher plan', 'needtome-ai-chat').'</a></p>';
            return $html.'</div>';
        }
    }
    $html .= '<p><a class="button" href="'.esc_url($panel.'/chat?new=1').'" target="_blank" rel="noopener">'.esc_html__('Create a new chat', 'needtome-ai-chat').'</a> <span class="description">'.esc_html__('The chat is created in the kra.name panel; then come back and connect it here.', 'needtome-ai-chat').'</span></p>';
    return $html.'</div>';
}

function aiworker_chat_pick_panel(): void {
    echo aiworker_chat_pick_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
}

/** The form the chat choice submits; printed after the settings form, since forms cannot nest. */
function aiworker_chat_pick_forms(): void {
    foreach (['aiworker-chat-link-form' => 'aiworker_chat_chat_link'] as $id => $action) {
        echo '<form id="'.esc_attr($id).'" method="post" action="'.esc_url(admin_url('admin-post.php')).'" hidden><input type="hidden" name="action" value="'.esc_attr($action).'">';
        aiworker_chat_context_field();
        wp_nonce_field($action);
        echo '</form>';
    }
}

/** Where the settings screen goes once a sign-in started there has finished. */
function aiworker_chat_signin_next(string $then, string $chatId = ''): string {
    if (! in_array($chatId, aiworker_chat_ids(), true)) $chatId = aiworker_chat_current_id();
    if ($then === 'pick') return aiworker_chat_pick_url($chatId);
    if ($then === 'kb') return add_query_arg('tab', 'kb', aiworker_chat_settings_url('', $chatId));
    if ($then === 'telegram') return add_query_arg('aiworker_chat_sub', 'telegram', aiworker_chat_settings_url('', $chatId)).'#aiworker-chat-wizard';
    return aiworker_chat_settings_url('connected', $chatId);
}

/** The sign-in link as a button; assets/admin/signin.js handles waiting for a sign-in opened in another browser. */
function aiworker_chat_signin_link(string $then, string $label): string {
    $chatId = aiworker_chat_current_id();
    return '<a class="button button-primary aiworker-chat-signin" href="'.esc_url(aiworker_chat_signin_url($then, $chatId)).'" data-next="'.esc_url(aiworker_chat_signin_next($then, $chatId)).'">'.esc_html($label).'</a>';
}

/**
 * The end of a sign-in opened in a popup: the settings screen that opened it goes on to $next and the popup closes.
 * Without its opener (the popup was opened as a tab, or kra.name cut the link) the popup itself goes on to $next.
 */
function aiworker_chat_popup_done(string $next): void {
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>'.esc_html__('NeedToMe AI Chat', 'needtome-ai-chat').'</title>';
    wp_enqueue_script('aiworker-chat-popup', aiworker_chat_asset_url('assets/admin/popup.js'), [], aiworker_chat_asset_version('assets/admin/popup.js'), false);
    wp_add_inline_script('aiworker-chat-popup', 'window.aiworkerChatPopup = '.wp_json_encode(['next' => $next]).';', 'before');
    wp_print_scripts('aiworker-chat-popup');
    exit;
}
