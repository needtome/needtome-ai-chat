<?php
// Removing the plugin removes its settings, the knowledge sync state and the cached checks; nothing else is stored on the site.
defined('WP_UNINSTALL_PLUGIN') || exit;
// Revoke the AI Worker connection at kra.name (the wizard's OAuth tokens) before its option is removed.
require_once __DIR__.'/includes/connect.php';
aiworker_chat_oauth_revoke();
delete_option('aiworker_chat_oauth');
delete_option('aiworker_chat_oauth_lock');
delete_option('aiworker_chat_signin_links');
delete_option('aiworker_chat_linked_chat');
delete_option('aiworker_chat_options');
delete_option('aiworker_chat_kb');
delete_option('aiworker_chat_kb_queue');
delete_option('aiworker_chat_kb_state');
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
