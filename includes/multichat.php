<?php
/** Multiple chats: stable local IDs, independent options and explicit request/worker context. */
defined('ABSPATH') || exit;

const AIWORKER_CHAT_CHATS = 'aiworker_chat_chats';
const AIWORKER_CHAT_SCHEMA = 'aiworker_chat_schema_version';

/** Deterministic, insert-only migration. Old options and the first chat's pending inbox remain intact. */
function aiworker_chat_migrate(): void {
    if ((int) get_option(AIWORKER_CHAT_SCHEMA, 0) >= 1) return;
    $old = (array) get_option(AIWORKER_CHAT_OPTION, []);
    $old += ['chat_id' => (int) get_option(AIWORKER_CHAT_LINKED_OPTION, 0), 'name' => '', 'stack_order' => 0];
    aiworker_chat_option_insert('aiworker_chat_instance_chat1_options', $old);
    aiworker_chat_option_insert('aiworker_chat_instance_chat1_kb', (array) get_option(AIWORKER_CHAT_KB_OPTION, []));
    aiworker_chat_option_insert(AIWORKER_CHAT_CHATS, ['chat1']);
    // Never mark a partially failed migration complete. A later request retries the missing inserts.
    if (is_array(get_option('aiworker_chat_instance_chat1_options', null)) && is_array(get_option('aiworker_chat_instance_chat1_kb', null)) && is_array(get_option(AIWORKER_CHAT_CHATS, null))) {
        update_option(AIWORKER_CHAT_SCHEMA, 1, false);
    }
}
add_action('init', 'aiworker_chat_migrate', 1);

function aiworker_chat_ids(): array {
    $ids = get_option(AIWORKER_CHAT_CHATS, null);
    return is_array($ids) ? array_values(array_filter($ids, static function ($id): bool { return is_string($id) && (bool) preg_match('/^[a-z0-9]{1,40}$/', $id); })) : ['chat1'];
}

/** Explicit invalid IDs fail closed; a stale tab must never save into the first chat. */
function aiworker_chat_current_id(): string {
    if (isset($GLOBALS['aiworker_chat_instance'])) return (string) $GLOBALS['aiworker_chat_instance'];
    $ids = aiworker_chat_ids();
    if (is_admin() && current_user_can('manage_options')) {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Routing only; every mutation retains its nonce and capability check.
        $id = null;
        if (isset($_POST['aiworker_chat_instance'])) $id = is_string($_POST['aiworker_chat_instance']) ? sanitize_key(wp_unslash($_POST['aiworker_chat_instance'])) : '';
        elseif (isset($_GET['aiworker_chat_instance'])) $id = is_string($_GET['aiworker_chat_instance']) ? sanitize_key(wp_unslash($_GET['aiworker_chat_instance'])) : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing
        if ($id !== null) {
            if (! in_array($id, $ids, true)) wp_die(esc_html__('This chat no longer exists. Reload the settings page.', 'needtome-ai-chat'), '', ['response' => 404]);
            return $id;
        }
    }
    return $ids[0] ?? '';
}

/** Run a worker/hook for one chat, restoring context even when it throws. */
function aiworker_chat_with(string $id, callable $callback) {
    $before = $GLOBALS['aiworker_chat_instance'] ?? null;
    $GLOBALS['aiworker_chat_instance'] = $id;
    try { return $callback(); }
    finally {
        if ($before === null) unset($GLOBALS['aiworker_chat_instance']);
        else $GLOBALS['aiworker_chat_instance'] = $before;
    }
}

function aiworker_chat_each(callable $callback): void {
    foreach (aiworker_chat_ids() as $id) aiworker_chat_with($id, $callback);
}

/** Settings and KB are separate rows: a worker cannot overwrite a form, nor another chat. */
function aiworker_chat_option_name(string $name, ?string $id = null): string {
    $id = $id ?? aiworker_chat_current_id();
    if ($name === AIWORKER_CHAT_OPTION) return 'aiworker_chat_instance_'.$id.'_options';
    if ($name === AIWORKER_CHAT_KB_OPTION) return 'aiworker_chat_instance_'.$id.'_kb';
    // Retain the legacy runtime namespace for chat1, including inbox rows created before the update.
    return $id === 'chat1' ? $name : 'aiworker_chat_instance_'.$id.'_'.substr($name, strlen('aiworker_chat_'));
}

function aiworker_chat_read_options(string $name, ?string $id = null): array {
    $id = $id ?? aiworker_chat_current_id();
    $value = get_option(aiworker_chat_option_name($name, $id), null);
    if (! is_array($value) && $id === 'chat1' && (int) get_option(AIWORKER_CHAT_SCHEMA, 0) < 1) {
        $value = (array) get_option($name, []);
        if ($name === AIWORKER_CHAT_OPTION) $value += ['chat_id' => (int) get_option(AIWORKER_CHAT_LINKED_OPTION, 0)];
    }
    return is_array($value) ? $value : [];
}

/** Hydrated list; runtime state is kept in separate rows to avoid cross-chat lost updates. */
function aiworker_chat_chats(): array {
    $chats = [];
    foreach (aiworker_chat_ids() as $id) $chats[] = ['id' => $id] + wp_parse_args(aiworker_chat_read_options(AIWORKER_CHAT_OPTION, $id), aiworker_chat_defaults());
    return $chats;
}

function aiworker_chat_linked_id(): int { return (int) (aiworker_chat_options()['chat_id'] ?? 0); }

function aiworker_chat_admin_ajax_url(): string {
    return add_query_arg('aiworker_chat_instance', aiworker_chat_current_id(), admin_url('admin-ajax.php'));
}

function aiworker_chat_context_field(): void {
    echo '<input type="hidden" name="aiworker_chat_instance" value="'.esc_attr(aiworker_chat_current_id()).'">';
}

function aiworker_chat_label(array $chat): string {
    return ! empty($chat['name']) ? (string) $chat['name'] : (! empty($chat['key']) ? '…'.substr((string) $chat['key'], -8) : __('New chat', 'needtome-ai-chat'));
}

function aiworker_chat_tabs(string $tab = 'settings'): void {
    $chats = aiworker_chat_chats();
    if (count($chats) < 2) return;
    echo '<nav class="nav-tab-wrapper" aria-label="'.esc_attr__('Chats', 'needtome-ai-chat').'">';
    foreach ($chats as $chat) {
        $url = Aiworker_Chat_Profile::url('aiworker-chat', ['tab' => $tab, 'aiworker_chat_instance' => $chat['id']]);
        echo '<a class="nav-tab'.($chat['id'] === aiworker_chat_current_id() ? ' nav-tab-active' : '').'" href="'.esc_url($url).'">'.esc_html(aiworker_chat_label($chat)).'</a>';
    }
    echo '</nav>';
}

/** Add/remove operations serialize only the registry; ordinary per-chat saves never rewrite it. */
function aiworker_chat_registry_lock(): string {
    $name = 'aiworker_chat_registry_lock';
    $token = bin2hex(random_bytes(16)).':'.time();
    if (aiworker_chat_option_insert($name, $token)) return $token;
    global $wpdb;
    $held = (string) get_option($name, '');
    if ((int) substr(strrchr($held, ':') ?: ':0', 1) < time() - 60) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-delete only a stale registry lock.
        $wpdb->delete($wpdb->options, ['option_name' => $name, 'option_value' => $held]);
        wp_cache_delete($name, 'options');
        if (aiworker_chat_option_insert($name, $token)) return $token;
    }
    return '';
}

function aiworker_chat_registry_unlock(string $token): void {
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Release only this request's lock.
    $wpdb->delete($wpdb->options, ['option_name' => 'aiworker_chat_registry_lock', 'option_value' => $token]);
    wp_cache_delete('aiworker_chat_registry_lock', 'options');
}

/** Add a linked/manual chat without replacing an existing chat's settings or KB. */
function aiworker_chat_add(string $key, int $chatId = 0, string $name = ''): string {
    aiworker_chat_migrate();
    $lock = aiworker_chat_registry_lock();
    if ($lock === '') return '';
    try {
        $found = '';
        foreach (aiworker_chat_chats() as $chat) {
            if (($key !== '' && $chat['key'] === $key) || ($chatId > 0 && (int) $chat['chat_id'] === $chatId)) {
                $found = $chat['id'];
                break;
            }
            // the empty chat left by «Add another chat» takes the next one, so no empty chat stays behind
            if ($found === '' && $chat['key'] === '') $found = $chat['id'];
        }
        if ($found !== '') {
            $saved = aiworker_chat_read_options(AIWORKER_CHAT_OPTION, $found);
            aiworker_chat_store(AIWORKER_CHAT_OPTION, array_merge($saved, ['key' => $key, 'chat_id' => $chatId, 'name' => $name]), $found);
            return $found;
        }
        $id = 'c'.bin2hex(random_bytes(8));
        $ids = aiworker_chat_ids();
        $settings = array_merge(aiworker_chat_defaults(), ['key' => $key, 'chat_id' => $chatId, 'name' => $name, 'stack_order' => count($ids), 'mount' => 'aiworker-'.$id]);
        if (! aiworker_chat_option_insert(aiworker_chat_option_name(AIWORKER_CHAT_OPTION, $id), $settings)) return '';
        aiworker_chat_option_insert(aiworker_chat_option_name(AIWORKER_CHAT_KB_OPTION, $id), aiworker_chat_kb_defaults());
        $ids[] = $id;
        update_option(AIWORKER_CHAT_CHATS, $ids, false);
        return $id;
    } finally { aiworker_chat_registry_unlock($lock); }
}

/** One row of the list: the name, the key and the button line up with the other rows through the grid in admin.css. */
function aiworker_chat_list_row(string $name, string $id, string $key, string $button): string {
    return '<p class="aiworker-chat-list__row"><label><span class="aiworker-chat-list__name" title="'.esc_attr($name).'">'.esc_html($name).'</span> <input type="text" form="aiworker-chat-list-form" name="keys['.esc_attr($id).']" value="'.esc_attr($key).'" pattern="wk_[A-Za-z0-9]{24}" placeholder="wk_…" aria-label="'.esc_attr__('Public chat key', 'needtome-ai-chat').'"></label> '.$button.'</p>';
}

/**
 * Connection list controls use a separate form, outside the existing settings form. With $adding every chat is a row,
 * plus a new one that exists only on the page: nothing is stored until a chat is chosen or its key is saved.
 */
function aiworker_chat_list_html(bool $adding = false): string {
    $chats = aiworker_chat_chats();
    $rows = $adding || count($chats) > 1;
    $button = static function (array $chat): string {
        return '<button type="submit" class="button aiworker-chat-button--danger" form="aiworker-chat-list-form" name="remove" value="'.esc_attr($chat['id']).'">'.esc_html__('Remove', 'needtome-ai-chat').'</button>';
    };
    $html = '<div id="aiworker-chat-chat-list">';
    $remove = '';
    if ($rows) {
        $html .= '<div class="aiworker-chat-list__rows">';
        foreach ($chats as $chat) $html .= aiworker_chat_list_row(aiworker_chat_label($chat), (string) $chat['id'], (string) $chat['key'], $button($chat));
        if ($adding) $html .= aiworker_chat_list_row(__('New chat', 'needtome-ai-chat'), 'new', '', '<button type="button" class="button aiworker-chat-button--danger" data-aiworker-chat-add-cancel>'.esc_html__('Remove', 'needtome-ai-chat').'</button>');
        $html .= '</div>';
    } elseif ($chats) $remove = $button($chats[0]).' ';
    $html .= '<p class="aiworker-chat-list__actions">'.$remove;
    if (! $adding) $html .= '<button type="submit" class="button" form="aiworker-chat-list-form" name="add" value="1">'.esc_html__('Add another chat', 'needtome-ai-chat').'</button>';
    if ($rows) $html .= ' <button type="submit" class="button" form="aiworker-chat-list-form">'.esc_html__('Save chat keys', 'needtome-ai-chat').'</button>';
    return $html.'</p></div>';
}

function aiworker_chat_list_controls(): void {
    echo aiworker_chat_list_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Built from escaped parts.
}

/** AJAX, «Add another chat»: the list and the chat choice for a new chat. Stores nothing, so Cancel has nothing to undo. */
function aiworker_chat_add_open(): void {
    if (! current_user_can('manage_options')) wp_send_json_error(null, 403);
    check_ajax_referer('aiworker_chat_chat_list');
    foreach (aiworker_chat_chats() as $chat) {
        // an empty chat left by an older version is the place for the new one
        if ((string) $chat['key'] === '') wp_send_json_success(['url' => add_query_arg('aiworker_chat_instance', $chat['id'], Aiworker_Chat_Profile::url('aiworker-chat'))]);
    }
    // admin-ajax does not follow the screen's language by itself: the screen sends it
    switch_to_locale(['ru' => 'ru_RU', 'uk' => 'uk', 'en' => 'en_US'][aiworker_chat_wizard_lang()]);
    wp_send_json_success(['list' => aiworker_chat_list_html(true), 'pick' => aiworker_chat_pick_html(true)]);
}
add_action('wp_ajax_aiworker_chat_add_open', 'aiworker_chat_add_open');

function aiworker_chat_list_form(): void {
    echo '<form id="aiworker-chat-list-form" method="post" action="'.esc_url(admin_url('admin-post.php')).'" hidden><input type="hidden" name="action" value="aiworker_chat_chat_list">';
    aiworker_chat_context_field();
    wp_nonce_field('aiworker_chat_chat_list');
    echo '</form>';
}

function aiworker_chat_list_save(): void {
    if (! current_user_can('manage_options')) wp_die('', '', ['response' => 403]);
    check_admin_referer('aiworker_chat_chat_list');
    $current = aiworker_chat_current_id();
    // Parse and validate the submitted values before taking either lock, so malformed input cannot strand a lock.
    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Keys are sanitized and validated below.
    $rawKeys = isset($_POST['keys']) && is_array($_POST['keys']) ? wp_unslash($_POST['keys']) : [];
    $keys = [];
    foreach ($rawKeys as $id => $key) {
        if (! is_string($key)) wp_die(esc_html__('Enter valid, different chat keys.', 'needtome-ai-chat'));
        $keys[(string) $id] = sanitize_text_field($key);
    }
    $remove = isset($_POST['remove']) && is_string($_POST['remove']) ? sanitize_key(wp_unslash($_POST['remove'])) : '';
    $new = $keys['new'] ?? '';
    unset($keys['new']);
    if ($new !== '' && ! aiworker_chat_valid_key($new)) wp_die(esc_html__('Enter valid, different chat keys.', 'needtome-ai-chat'));

    $lock = aiworker_chat_registry_lock();
    if ($lock === '') wp_die(esc_html__('Another chat change is in progress. Try again.', 'needtome-ai-chat'));
    $failure = '';
    $workerLocks = [];
    try {
        $ids = aiworker_chat_ids();
        $finalKeys = [];
        $seen = [];
        foreach ($ids as $id) {
            if ($id === $remove) continue;
            $saved = aiworker_chat_read_options(AIWORKER_CHAT_OPTION, $id);
            $key = array_key_exists($id, $keys) ? $keys[$id] : (string) ($saved['key'] ?? '');
            if (($key !== '' && ! aiworker_chat_valid_key($key)) || ($key !== '' && isset($seen[$key]))) {
                $failure = __('Enter valid, different chat keys.', 'needtome-ai-chat');
                break;
            }
            if ($key !== '') $seen[$key] = true;
            $finalKeys[$id] = $key;
        }
        if ($failure === '' && $new !== '' && isset($seen[$new])) $failure = __('Enter valid, different chat keys.', 'needtome-ai-chat');

        $wantsAdd = isset($_POST['add']) || ! array_diff($ids, [$remove]);
        $target = '';
        foreach ($finalKeys as $id => $key) if ($key === '') { $target = $id; break; }
        if ($failure === '' && $new !== '' && $target !== '') $finalKeys[$target] = $new;
        $createChat = $failure === '' && ($new !== '' || $wantsAdd) && $target === '';

        // Acquire every affected worker lock before writing any chat, so a busy later chat cannot leave a partial save.
        if ($failure === '') foreach ($finalKeys as $id => $key) {
            $saved = aiworker_chat_read_options(AIWORKER_CHAT_OPTION, $id);
            if ($key === (string) ($saved['key'] ?? '')) continue;
            $worker = aiworker_chat_with($id, 'aiworker_chat_kb_lock_take');
            if ($worker === '') { $failure = __('This chat is syncing. Try changing its key again shortly.', 'needtome-ai-chat'); break; }
            $workerLocks[$id] = $worker;
        }

        $newId = '';
        if ($failure === '' && $createChat) {
            $newId = 'c'.bin2hex(random_bytes(8));
            $settings = array_merge(aiworker_chat_defaults(), ['key' => $new, 'chat_id' => 0, 'name' => '', 'stack_order' => count($ids), 'mount' => 'aiworker-'.$newId]);
            if (! aiworker_chat_option_insert(aiworker_chat_option_name(AIWORKER_CHAT_OPTION, $newId), $settings)
                || ! aiworker_chat_option_insert(aiworker_chat_option_name(AIWORKER_CHAT_KB_OPTION, $newId), aiworker_chat_kb_defaults())) {
                delete_option(aiworker_chat_option_name(AIWORKER_CHAT_OPTION, $newId));
                delete_option(aiworker_chat_option_name(AIWORKER_CHAT_KB_OPTION, $newId));
                $failure = __('Another chat change is in progress. Try again.', 'needtome-ai-chat');
            }
        }

        if ($failure === '') {
            foreach ($finalKeys as $id => $key) {
                $saved = aiworker_chat_read_options(AIWORKER_CHAT_OPTION, $id);
                if ($key === (string) ($saved['key'] ?? '')) continue;
                // A different key is a new remote destination; never carry its predecessor's sync token or queue.
                aiworker_chat_with($id, static function () use ($id, $key, $saved): void {
                    aiworker_chat_reset_destination($id);
                    aiworker_chat_store(AIWORKER_CHAT_OPTION, array_merge($saved, ['key' => $key, 'chat_id' => 0, 'name' => '', 'enabled' => 0, 'remote_greet_popup' => null]), $id);
                });
            }
            if (in_array($remove, $ids, true)) {
                $ids = array_values(array_diff($ids, [$remove]));
                delete_option(aiworker_chat_option_name(AIWORKER_CHAT_KB_HASHES, $remove));
                wp_clear_scheduled_hook('aiworker_chat_kb_sync', [$remove]);
                wp_clear_scheduled_hook('aiworker_chat_kb_remove', [$remove]);
                // Settings retained as an unlisted record until uninstall; a worker already running cannot recreate a chat.
                if ($current === $remove) $current = $ids[0] ?? '';
            }
            if ($newId !== '') $ids[] = $newId;
            if ($ids !== aiworker_chat_ids()) update_option(AIWORKER_CHAT_CHATS, array_values($ids), false);
            if ($new !== '' && $target !== '') $current = $target;
            elseif ($newId !== '') $current = $newId;
            elseif ($wantsAdd && $target !== '') $current = $target;
        }
    } finally {
        foreach ($workerLocks as $id => $worker) aiworker_chat_with($id, static function () use ($worker): void { aiworker_chat_kb_lock_release($worker); });
        aiworker_chat_registry_unlock($lock);
    }
    if ($failure !== '') wp_die(esc_html($failure));
    wp_safe_redirect(add_query_arg('aiworker_chat_instance', $current, Aiworker_Chat_Profile::url('aiworker-chat')));
    exit;
}
add_action('admin_post_aiworker_chat_chat_list', 'aiworker_chat_list_save');

function aiworker_chat_reset_destination(string $id): void {
    $kb = aiworker_chat_read_options(AIWORKER_CHAT_KB_OPTION, $id);
    $kb['enabled'] = 0;
    $kb['token'] = '';
    aiworker_chat_store(AIWORKER_CHAT_KB_OPTION, $kb, $id);
    global $wpdb;
    foreach ([AIWORKER_CHAT_KB_INBOX, 'aiworker_chat_kb_delete_in_'] as $prefix) {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Discard only this destination's pending inbox while holding its worker lock.
        $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like(aiworker_chat_option_name($prefix, $id)).'%'));
        foreach ((array) $names as $name) delete_option($name);
    }
    // Old state belongs to the old destination. New sync starts only after explicit re-enabling.
    foreach ([AIWORKER_CHAT_KB_QUEUE, AIWORKER_CHAT_KB_STATE, AIWORKER_CHAT_KB_HASHES] as $name) delete_option(aiworker_chat_option_name($name, $id));
}

/** One public payload, with page rules evaluated independently and no KB credentials exposed. */
function aiworker_chat_loader_options(): array {
    $chats = [];
    foreach (aiworker_chat_chats() as $chat) {
        if (empty($chat['enabled']) || ! aiworker_chat_valid_key((string) $chat['key']) || ! aiworker_chat_on_this_page($chat)) continue;
        $o = aiworker_chat_sanitize($chat);
        if ($o['greet_popup'] === null) $o['greet_popup'] = $chat['remote_greet_popup'] ?? null;
        unset($o['pages'], $o['pages_mode'], $o['chat_id'], $o['name'], $o['remote_greet_popup']);
        $o['privacy_url'] = ! empty($chat['privacy']) ? ((string) ($chat['privacy_url'] ?? '') ?: (string) get_privacy_policy_url()) : '';
        $chats[] = $o;
    }
    return ['base' => aiworker_chat_base(), 'widget_version' => (string) get_option('aiworker_chat_widget_version', ''), 'chats' => $chats];
}

/** Settings API sanitizer: preserve metadata and settings absent from this form. */
function aiworker_chat_sanitize_saved($input): array {
    $input = is_array($input) ? $input : [];
    $current = aiworker_chat_options();
    foreach (['stack_order', 'stack', 'greet_popup', 'privacy_url'] as $key) if (! array_key_exists($key, $input)) $input[$key] = $current[$key];
    $clean = aiworker_chat_sanitize($input);
    foreach (aiworker_chat_chats() as $chat) if ($chat['id'] !== aiworker_chat_current_id() && $clean['key'] !== '' && $chat['key'] === $clean['key']) {
        add_settings_error(AIWORKER_CHAT_OPTION, 'duplicate_key', __('This key is already used by another chat.', 'needtome-ai-chat'));
        return $current;
    }
    $out = array_merge($current, $clean);
    $out['chat_id'] = $current['chat_id'];
    $out['name'] = $current['name'];
    $out['remote_greet_popup'] = $current['remote_greet_popup'];
    if ($out['key'] !== $current['key']) {
        $token = aiworker_chat_kb_lock_take();
        if ($token === '') {
            add_settings_error(AIWORKER_CHAT_OPTION, 'sync_busy', __('This chat is syncing. Try changing its key again shortly.', 'needtome-ai-chat'));
            return $current;
        }
        // Settings API writes after sanitizing; keep the lock until that write (or request shutdown).
        $id = aiworker_chat_current_id();
        $release = static function () use ($id, $token): void { aiworker_chat_with($id, static function () use ($token): void { aiworker_chat_kb_lock_release($token); }); };
        add_action('update_option_'.aiworker_chat_option_name(AIWORKER_CHAT_OPTION), $release, 100);
        add_action('shutdown', $release);
        $out['chat_id'] = 0; $out['name'] = ''; $out['remote_greet_popup'] = null;
        aiworker_chat_reset_destination(aiworker_chat_current_id());
    }
    return $out;
}

/** Remember the server's greeting flag without fetching configuration on public page requests. */
function aiworker_chat_remember_remote(array $chat): void {
    $saved = aiworker_chat_read_options(AIWORKER_CHAT_OPTION);
    $patch = [];
    if (array_key_exists('greet_popup', $chat)) $patch['remote_greet_popup'] = (int) ! empty($chat['greet_popup']);
    if (isset($chat['name'])) $patch['name'] = sanitize_text_field((string) $chat['name']);
    $next = array_merge($saved, $patch);
    if ($next !== $saved) aiworker_chat_store(AIWORKER_CHAT_OPTION, $next);
}

/** Site content events fan out, whereas settings/admin requests stay within their explicit tab. */
function aiworker_chat_on_each(string $hook, callable $callback, int $priority = 10, int $accepted = 1): void {
    add_action($hook, static function (...$args) use ($callback): void {
        aiworker_chat_each(static function () use ($callback, $args): void { call_user_func_array($callback, $args); });
    }, $priority, $accepted);
}

/** Removals must still run after an owner disabled sync or deselected a post type. */
function aiworker_chat_queue_removal(int $postId): void {
    aiworker_chat_each(static function () use ($postId): void {
        if (! aiworker_chat_valid_key((string) aiworker_chat_options()['key'])) return;
        $kb = aiworker_chat_kb_options();
        $hashes = aiworker_chat_kb_hashes();
        $docId = 'post:'.$postId;
        $documentWasSent = ! empty($hashes['documents'][$docId]);
        $productWasSent = aiworker_chat_products_on($kb) && ! empty($hashes['products'][(string) $postId]);
        // Only queue records that this chat actually received; unrelated types and never-exported posts are ignored.
        if (! $documentWasSent && ! $productWasSent) return;
        add_option(aiworker_chat_option_name('aiworker_chat_kb_delete_in_').str_replace('-', '', wp_generate_uuid4()), ['id' => $postId], '', false);
        if (! wp_next_scheduled('aiworker_chat_kb_remove', [aiworker_chat_current_id()])) wp_schedule_single_event(time() + 30, 'aiworker_chat_kb_remove', [aiworker_chat_current_id()]);
    });
}
add_action('deleted_post', 'aiworker_chat_queue_removal');
add_action('transition_post_status', static function (string $new, string $old, WP_Post $post): void {
    if ($old === 'publish' && $new !== 'publish') aiworker_chat_queue_removal((int) $post->ID);
}, 10, 3);
add_action('save_post', static function (int $id, WP_Post $post): void {
    if ($post->post_password !== '' && ! wp_is_post_revision($id)) aiworker_chat_queue_removal($id);
}, 30, 2);

/** Reschedules eligible removal inboxes after activation or reconnection, including chats with sync turned off. */
function aiworker_chat_restore_removal_jobs(?string $onlyId = null, bool $unblockAuth = false): void {
    if (! function_exists('aiworker_chat_kb_can_sync')) return;
    global $wpdb;
    $ids = $onlyId !== null ? [$onlyId] : aiworker_chat_ids();
    foreach ($ids as $id) {
        if (! in_array($id, aiworker_chat_ids(), true)) continue;
        $pending = aiworker_chat_with($id, static function () use ($id, $unblockAuth, $wpdb): bool {
            $kb = aiworker_chat_kb_options();
            if (! aiworker_chat_valid_key((string) aiworker_chat_options()['key']) || ! aiworker_chat_kb_can_sync($kb)) return false;
            $prefix = aiworker_chat_option_name('aiworker_chat_kb_delete_in_', $id);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Find only this chat's pending removal rows by prefix.
            $names = $wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like($prefix).'%'));
            $found = false;
            foreach ((array) $names as $name) {
                $entry = (array) get_option($name, []);
                if (! empty($entry['blocked'])) {
                    if ($unblockAuth && $entry['blocked'] === 'auth' && empty($kb['token'])) {
                        unset($entry['blocked']);
                        update_option($name, $entry, false);
                    } else continue;
                }
                $found = true;
            }
            return $found;
        });
        if ($pending && ! wp_next_scheduled('aiworker_chat_kb_remove', [$id])) wp_schedule_single_event(time() + 30, 'aiworker_chat_kb_remove', [$id]);
    }
}

/** Cleanup shares the ordinary worker's per-chat lock. Every inbox row is acknowledged only after both deletes. */
function aiworker_chat_remove_process(string $id): void {
    if (! in_array($id, aiworker_chat_ids(), true)) return;
    aiworker_chat_with($id, static function (): void {
        $kb = aiworker_chat_kb_options();
        if (! aiworker_chat_valid_key((string) aiworker_chat_options()['key']) || ! aiworker_chat_kb_can_sync($kb)) return;
        $token = aiworker_chat_kb_lock_take();
        $retry = false;
        if ($token === '') $retry = true;
        else {
            try {
                global $wpdb;
                $prefix = aiworker_chat_option_name('aiworker_chat_kb_delete_in_');
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Unique inbox entries, acknowledged individually under this chat's worker lock.
                $rows = $wpdb->get_results($wpdb->prepare("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value NOT LIKE %s ORDER BY option_id LIMIT 25", $wpdb->esc_like($prefix).'%', '%blocked%'));
                $rejected = '';
                foreach ((array) $rows as $row) {
                    $entry = (array) maybe_unserialize($row->option_value);
                    if (! empty($entry['blocked'])) continue;
                    $postId = (int) ($entry['id'] ?? 0);
                    $post = get_post($postId);
                    // A post restored before this worker runs is exported by the normal save hook.
                    $doc = $post ? aiworker_chat_kb_document($post, $kb) : null;
                    $product = $post && aiworker_chat_products_on($kb) && $post->post_type === 'product' ? aiworker_chat_products_record($postId, $kb) : null;
                    $hashes = aiworker_chat_kb_hashes();
                    $docId = 'post:'.$postId;
                    $docDelete = ! empty($hashes['documents'][$docId]) && ! $doc ? [$docId] : [];
                    $productDelete = aiworker_chat_products_on($kb) && ! empty($hashes['products'][(string) $postId]) && ! $product ? [(string) $postId] : [];
                    foreach (['documents/delete' => $docDelete, 'products/delete' => $productDelete] as $path => $ids) {
                        if (! $ids) continue;
                        if (! aiworker_chat_kb_lock_touch($token)) { $retry = true; break 2; }
                        [$code, , $error] = aiworker_chat_kb_request('POST', $path, ['site' => home_url('/'), 'ids' => $ids], 60);
                        if ($error !== '') {
                            if ($code >= 400 && $code < 500 && $code !== 401 && $code !== 429) {
                                // Only this record was rejected: park it and carry on with the rest of the batch.
                                $entry['blocked'] = 'permanent';
                                update_option($row->option_name, $entry, false);
                                $rejected = $error;
                                continue 2;
                            }
                            // Authorization and temporary failures affect every record, so stop; kb_fail schedules the retry.
                            if ($code === 401) {
                                $entry['blocked'] = 'auth';
                                update_option($row->option_name, $entry, false);
                            }
                            aiworker_chat_kb_fail($code === 401 ? 'auth' : ($code === 429 ? 'retry:60' : 'error'), $error, 'aiworker_chat_kb_remove');
                            $rejected = '';
                            break 2;
                        }
                        if ($path === 'documents/delete') aiworker_chat_kb_hashes_drop_documents($ids);
                        else aiworker_chat_products_hashes_drop($ids);
                    }
                    if (! aiworker_chat_kb_lock_touch($token)) { $retry = true; break; }
                    delete_option($row->option_name);
                }
                if ($rejected !== '') aiworker_chat_kb_fail('permanent', $rejected, 'aiworker_chat_kb_remove');
                if (count((array) $rows) >= 25) $retry = true;
            } finally { aiworker_chat_kb_lock_release($token); }
        }
        if ($retry && ! wp_next_scheduled('aiworker_chat_kb_remove', [aiworker_chat_current_id()])) wp_schedule_single_event(time() + 60, 'aiworker_chat_kb_remove', [aiworker_chat_current_id()]);
    });
}
add_action('aiworker_chat_kb_remove', 'aiworker_chat_remove_process');

/** Internal writes already validated by the API/UI; do not run form-checkbox sanitizers on them. */
function aiworker_chat_store(string $name, array $value, ?string $id = null): void {
    $option = aiworker_chat_option_name($name, $id);
    $callback = $name === AIWORKER_CHAT_OPTION ? 'aiworker_chat_sanitize_saved' : 'aiworker_chat_kb_sanitize';
    $priority = has_filter('sanitize_option_'.$option, $callback);
    if ($priority !== false) remove_filter('sanitize_option_'.$option, $callback, $priority);
    try { update_option($option, $value, false); }
    finally { if ($priority !== false) add_filter('sanitize_option_'.$option, $callback, $priority); }
}
