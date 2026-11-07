<?php
// Removing the plugin removes its settings, the knowledge sync state and the cached checks; nothing else is stored on the site.
defined('WP_UNINSTALL_PLUGIN') || exit;

$aiworker_chat_uninstall_site = static function (): void {
    // Revoke this site's AI Worker connection at kra.name before its OAuth option is removed.
    require_once plugin_dir_path(__FILE__).'includes/connect.php';
    aiworker_chat_oauth_revoke();
    delete_option('aiworker_chat_oauth');
    delete_option('aiworker_chat_oauth_lock');
    delete_option('aiworker_chat_signin_links');
    delete_option('aiworker_chat_linked_chat');
    delete_option('aiworker_chat_options');
    delete_option('aiworker_chat_widget_version');
    delete_transient('aiworker_chat_widget_version_fresh');
    wp_clear_scheduled_hook('aiworker_chat_widget_version_refresh');
    delete_option('aiworker_chat_kb');
    delete_option('aiworker_chat_kb_queue');
    delete_option('aiworker_chat_kb_state');
    delete_option('aiworker_chat_kb_hashes');
    delete_option('aiworker_chat_kb_lock');
    delete_option('aiworker_chat_kb_running');
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall removes this plugin's queued inbox options by prefix; the Options API cannot query a prefix.
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like('aiworker_chat_kb_in_').'%'));
    delete_transient('aiworker_chat_check');
    delete_transient('aiworker_chat_csp');
    delete_transient('aiworker_chat_kb_status');
    wp_clear_scheduled_hook('aiworker_chat_kb_sync');
    wp_clear_scheduled_hook('aiworker_chat_kb_daily');
    // All argument variants (one cron job per chat), including removed chats.
    wp_unschedule_hook('aiworker_chat_kb_sync');
    wp_unschedule_hook('aiworker_chat_kb_remove');
    wp_unschedule_hook('aiworker_chat_kb_daily');
    wp_unschedule_hook('aiworker_chat_widget_version_refresh');
    // Registry, schema, per-chat settings/state/inboxes, and every language/chat/user cache.
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall must discover this plugin's dynamic option and transient names.
    $aiworker_chat_names = $wpdb->get_col($wpdb->prepare(
        "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
        $wpdb->esc_like('aiworker_chat_').'%', $wpdb->esc_like('_transient_aiworker_chat_').'%', $wpdb->esc_like('_transient_timeout_aiworker_chat_').'%'
    ));
    foreach (['ru', 'en', 'uk'] as $aiworker_chat_lang) {
        delete_transient('aiworker_chat_account_chats_'.$aiworker_chat_lang);
        delete_transient('aiworker_chat_me_'.$aiworker_chat_lang);
    }
    foreach ($aiworker_chat_names as $aiworker_chat_name) {
        if (! preg_match('/^aiworker_chat_instance_([a-z0-9]+)_options$/', $aiworker_chat_name, $aiworker_chat_match)) continue;
        $aiworker_chat_local = $aiworker_chat_match[1];
        $aiworker_chat_saved = (array) get_option($aiworker_chat_name, []);
        $aiworker_chat_remote = (int) ($aiworker_chat_saved['chat_id'] ?? 0);
        $aiworker_chat_prefix = $aiworker_chat_local === 'chat1' ? 'aiworker_chat_' : 'aiworker_chat_instance_'.$aiworker_chat_local.'_';
        foreach (['check', 'kb_status', 'products_status', 'adopt_tried'] as $aiworker_chat_suffix) delete_transient($aiworker_chat_prefix.$aiworker_chat_suffix);
        delete_transient('aiworker_chat_channels_'.$aiworker_chat_remote);
        foreach (['ru', 'en', 'uk'] as $aiworker_chat_lang) delete_transient('aiworker_chat_usage_'.$aiworker_chat_remote.'_'.$aiworker_chat_lang);
    }
    foreach ($aiworker_chat_names as $aiworker_chat_name) {
        if (strpos($aiworker_chat_name, '_transient_timeout_') === 0) delete_transient(substr($aiworker_chat_name, 19));
        elseif (strpos($aiworker_chat_name, '_transient_') === 0) delete_transient(substr($aiworker_chat_name, 11));
        else delete_option($aiworker_chat_name);
    }
};

if (is_multisite()) {
    foreach (get_sites(['number' => 0, 'fields' => 'ids']) as $aiworker_chat_blog_id) {
        switch_to_blog((int) $aiworker_chat_blog_id);
        try { $aiworker_chat_uninstall_site(); } finally { restore_current_blog(); }
    }
} else {
    $aiworker_chat_uninstall_site();
}
