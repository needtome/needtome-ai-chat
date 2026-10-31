<?php
/**
 * The «AI Worker» tab: the setup wizard (requests in the owner's own words, proposed changes applied or discarded by
 * buttons) and the chat statistics, both from the owner's kra.name account over the plugin API (scope «chat»). Only
 * users with manage_options see it; the browser talks to this site's admin-ajax only (nonce + capability on every call),
 * and PHP talks to kra.name with the stored token (includes/connect.php). The interface and the chart are this plugin's
 * own code (admin-assistant.js); nothing is loaded from kra.name.
 */

if (! defined('ABSPATH')) exit;

/** AJAX: one entry point, a fixed list of operations mapped to the plugin API. */
function aiworker_chat_assistant_ajax(): void {
    if (! current_user_can('manage_options')) wp_send_json(['code' => 403, 'body' => ['message' => __('You are not allowed to do this.', 'aiworker-chat')]], 403);
    check_ajax_referer('aiwc_assistant', 'nonce');
    $op = isset($_POST['op']) ? sanitize_key(wp_unslash((string) $_POST['op'])) : '';
    $chat = isset($_POST['chat_id']) ? absint(wp_unslash((string) $_POST['chat_id'])) : 0;
    $chatArg = $chat > 0 ? ['chat_id' => $chat] : [];
    // The wizard answers in the language of this settings screen (kra.name: ?lang=ru|en|uk), which the page sends:
    // admin-ajax is not that screen, so Polylang's language chosen there is not seen here.
    $lang = isset($_POST['lang']) ? sanitize_key(wp_unslash((string) $_POST['lang'])) : '';
    if (! in_array($lang, ['ru', 'en', 'uk'], true)) $lang = aiworker_chat_wizard_lang();

    switch ($op) {
        case 'me':
            // labels such as the plan name come in the screen's language
            $result = aiworker_chat_api('GET', '/plugin/me', ['lang' => $lang]);
            break;
        case 'assistant':
            $result = aiworker_chat_api('GET', '/plugin/assistant', $chatArg + ['lang' => $lang]);
            break;
        case 'ask':
            $text = isset($_POST['text']) ? sanitize_textarea_field(wp_unslash((string) $_POST['text'])) : '';
            $text = function_exists('mb_substr') ? mb_substr($text, 0, 4000) : substr($text, 0, 4000);
            if (trim($text) === '') wp_send_json(['code' => 422, 'body' => ['message' => __('Write what you want to set up.', 'aiworker-chat')]]);
            $result = aiworker_chat_api('POST', '/plugin/assistant/ask?lang='.$lang, ['text' => $text] + $chatArg, 60);
            break;
        case 'apply':
        case 'discard':
        case 'clear':
            $result = aiworker_chat_api('POST', '/plugin/assistant/'.$op.'?lang='.$lang, $chatArg, 60);
            break;
        case 'voice':
            // dictation for the wizard: the recording goes on to kra.name's speech recognition, charged to the account's tokens
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- An uploaded file: its size and type are checked below, its bytes are passed on as they are.
            $file = $_FILES['audio'] ?? null;
            if (! is_array($file) || (int) ($file['error'] ?? 1) !== UPLOAD_ERR_OK || ! is_uploaded_file((string) $file['tmp_name']) || (int) $file['size'] > 25 * MB_IN_BYTES) wp_send_json(['code' => 422, 'body' => []]);
            $mime = (string) (wp_check_filetype((string) $file['name'], ['webm' => 'audio/webm', 'ogg' => 'audio/ogg', 'm4a' => 'audio/mp4'])['type'] ?: 'audio/webm');
            $boundary = 'aiwc'.wp_generate_password(24, false);
            $data = '--'.$boundary."\r\n".'Content-Disposition: form-data; name="audio"; filename="voice.'.(strpos($mime, 'mp4') !== false ? 'm4a' : (strpos($mime, 'ogg') !== false ? 'ogg' : 'webm')).'"'."\r\n"
                .'Content-Type: '.$mime."\r\n\r\n".file_get_contents((string) $file['tmp_name'])."\r\n--".$boundary."--\r\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local uploaded file.
            $result = aiworker_chat_api('POST', '/plugin/voice/transcribe?lang='.$lang, [], 60, ['type' => 'multipart/form-data; boundary='.$boundary, 'data' => $data]);
            break;
        case 'stats':
            $period = isset($_POST['period']) ? sanitize_key(wp_unslash((string) $_POST['period'])) : '7d';
            if (! in_array($period, ['7d', '30d', 'month'], true)) $period = '7d';
            $result = aiworker_chat_api('GET', '/plugin/stats', ['period' => $period, 'lang' => $lang] + $chatArg, 30);
            break;
        default:
            wp_send_json(['code' => 400, 'body' => []], 400);
    }
    if ($result['code'] === 401) $result['body']['connected'] = aiworker_chat_is_connected();
    // The status goes in the JSON body: admin-ajax answers 200 so the script can show the reason itself.
    wp_send_json(['code' => $result['code'], 'body' => $result['body'], 'error' => $result['error'] !== '' ? __('kra.name cannot be reached from this site.', 'aiworker-chat') : '']);
}
add_action('wp_ajax_aiwc_assistant', 'aiworker_chat_assistant_ajax');

/** The wizard's language for this screen: ru, uk or en. */
function aiworker_chat_wizard_lang(): string {
    // admin-ajax is not the settings screen: the screen sends its language with each request
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only picks a language; every handler checks its nonce.
    $sent = wp_doing_ajax() && isset($_POST['lang']) ? sanitize_key(wp_unslash((string) $_POST['lang'])) : '';
    if (in_array($sent, ['ru', 'en', 'uk'], true)) return $sent;
    $locale = aiworker_chat_polylang_admin_locale() ?: get_user_locale();
    return strpos($locale, 'ru') === 0 ? 'ru' : (strpos($locale, 'uk') === 0 ? 'uk' : 'en');
}

/** Script and texts for the tab (only on this screen and tab). */
function aiworker_chat_assistant_enqueue(string $hook): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab routing.
    $tab = isset($_GET['tab']) && is_string($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
    // the wizard is in step 1 of Settings (once a chat is linked), the statistics on their own tab
    if ($hook !== 'needtome_page_aiworker-chat' || ! aiworker_chat_is_connected()) return;
    // the Knowledge base tab needs it too: «What the consultant answers from» saves through admin-ajax with this nonce
    if ($tab !== 'assistant' && ! (in_array($tab, ['', 'settings', 'kb'], true) && aiworker_chat_options()['key'] !== '')) return;
    // the file's time in the version too: a browser keeps a cached script for the same version, also between releases
    $file = dirname(__DIR__).'/admin-assistant.js';
    wp_enqueue_script('aiworker-chat-assistant', plugins_url('admin-assistant.js', dirname(__DIR__).'/aiworker-chat.php'), [], AIWORKER_CHAT_VERSION.'.'.(int) @filemtime($file), true);
    wp_add_inline_script('aiworker-chat-assistant', 'window.aiwcAssistant = '.wp_json_encode([
        'ajax' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('aiwc_assistant'),
        'connectUrl' => aiworker_chat_settings_url(),
        'chatId' => (string) (int) get_option(AIWORKER_CHAT_LINKED_OPTION, 0),
        'lang' => aiworker_chat_wizard_lang(),
        't' => [
            'you' => __('You', 'aiworker-chat'),
            'assistant' => __('Wizard', 'aiworker-chat'),
            'send' => __('Send', 'aiworker-chat'),
            'sending' => __('The wizard is thinking…', 'aiworker-chat'),
            'pending' => __('Proposed changes', 'aiworker-chat'),
            'apply' => __('Apply', 'aiworker-chat'),
            'discard' => __('Cancel', 'aiworker-chat'),
            'clear' => __('Start a new conversation', 'aiworker-chat'),
            'clearConfirm' => __('Clear the conversation with the wizard?', 'aiworker-chat'),
            'mic' => __('Dictate', 'aiworker-chat'),
            'micStop' => __('Stop and recognize', 'aiworker-chat'),
            'micPrep' => __('Getting the microphone ready…', 'aiworker-chat'),
            'micSpeak' => __('Speak. Press again to recognize the speech.', 'aiworker-chat'),
            'micBusy' => __('Recognizing the speech…', 'aiworker-chat'),
            'micShort' => __('The recording is too short.', 'aiworker-chat'),
            'micDenied' => __('Allow access to the microphone.', 'aiworker-chat'),
            'micFailed' => __('The speech could not be recognized.', 'aiworker-chat'),
            'micNone' => __('Voice input is not available in this browser.', 'aiworker-chat'),
            'micSafari' => __('So that Safari stops asking about the microphone every time: Safari Settings → Websites → Microphone → this site → «Allow».', 'aiworker-chat'),
            'placeholder' => __('For example: create a chat for this site, add the delivery page to the knowledge base and write a greeting', 'aiworker-chat'),
            'e401' => __('The connection to AI Worker has ended. Connect again.', 'aiworker-chat'),
            'e403' => __('This is not available with the access the site has. Open the kra.name panel for it.', 'aiworker-chat'),
            'e403plan' => __('The account\'s plan no longer includes the online chat, so the wizard and the statistics are not available.', 'aiworker-chat'),
            'e404' => __('This chat is not in the connected account.', 'aiworker-chat'),
            /* translators: %s: seconds to wait */
            'e429' => __('Too many requests or the AI tokens are used up. Try again in %s s.', 'aiworker-chat'),
            'e5xx' => __('kra.name answered with an error. Try again a bit later.', 'aiworker-chat'),
            'e0' => __('kra.name cannot be reached from this site.', 'aiworker-chat'),
            'tokens' => __('AI tokens', 'aiworker-chat'),
            'loading' => __('Loading from kra.name…', 'aiworker-chat'),
            'of' => __('of', 'aiworker-chat'),
            'noLimit' => __('no limit', 'aiworker-chat'),
            'byDay' => __('By day', 'aiworker-chat'),
            'day' => __('Day', 'aiworker-chat'),
            'empty' => __('No dialogs in this period.', 'aiworker-chat'),
            'cols' => [
                'sessions' => __('Dialogs', 'aiworker-chat'),
                'messages_in' => __('Visitor messages', 'aiworker-chat'),
                'answers' => __('Answers', 'aiworker-chat'),
                'leads' => __('Leads', 'aiworker-chat'),
                'rated_high' => __('Rated 4–5', 'aiworker-chat'),
                'rated_low' => __('Rated 1–3', 'aiworker-chat'),
                'voice' => __('Voice input', 'aiworker-chat'),
            ],
        ],
    ]).';', 'before');
}
add_action('admin_enqueue_scripts', 'aiworker_chat_assistant_enqueue');

/** Notices after the OAuth round trip and the chat choice: code => [type, text]. */
function aiworker_chat_assistant_notices(): array {
    return [
        'connected' => ['success', __('AI Worker is connected. Once a chat is connected, the setup wizard is in step 1.', 'aiworker-chat')],
        'disconnected' => ['success', __('AI Worker is disconnected: the access was revoked and removed from this site. The chat on the site keeps working.', 'aiworker-chat')],
        'denied' => ['warning', __('Access was not allowed at kra.name, nothing changed.', 'aiworker-chat')],
        'state' => ['error', __('The connection link is outdated or was opened by another user. Start the connection again.', 'aiworker-chat')],
        'register_failed' => ['error', __('kra.name did not register this site. Check that the site can reach kra.name and try again.', 'aiworker-chat')],
        'failed' => ['error', __('The connection was not completed. Start it again.', 'aiworker-chat')],
        'linked' => ['success', __('The chat is connected: its key is saved and this site is in its allowed sites. Turn on «Show the chat on this site» below. The setup wizard is in step 1 below.', 'aiworker-chat')],
        'link_unsupported' => ['warning', __('kra.name cannot connect a chat automatically yet. Copy the public key wk_… from the chat\'s card and paste it above.', 'aiworker-chat')],
        'link_domains' => ['error', __('This chat already has the maximum of 20 allowed sites. Remove one in the chat\'s settings at kra.name, then connect again.', 'aiworker-chat')],
        'link_expired' => ['error', __('This sign-in link is outdated or was already used. Open the NeedToMe AI Chat settings and copy the link again.', 'aiworker-chat')],
        'tg_failed' => ['error', __('kra.name did not give a Telegram link. Try again, or connect the bot in the kra.name panel.', 'aiworker-chat')],
        'link_failed' => ['error', __('kra.name did not connect this chat. Try again, or copy the public key wk_… from the chat\'s card.', 'aiworker-chat')],
    ];
}

function aiworker_chat_assistant_notice(): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice code from our own redirect.
    $code = isset($_GET['aiw_notice']) ? sanitize_key(wp_unslash((string) $_GET['aiw_notice'])) : '';
    $notices = aiworker_chat_assistant_notices();
    if (isset($notices[$code])) echo '<div class="notice notice-'.esc_attr($notices[$code][0]).' inline"><p>'.esc_html($notices[$code][1]).'</p></div>';
}

/** The tab itself. */
/** The connected account. The wizard and the statistics work with the chat linked to this site. */
function aiworker_chat_assistant_bar(): void {
    ?>
      <div class="aiwc-aw__bar">
        <span class="aiwc-pill aiwc-pill--ok"><?php esc_html_e('AI Worker connected', 'aiworker-chat'); ?></span>
        <span class="aiwc-aw__account" data-aw="account"></span>
      </div>
    <?php
}

/**
 * The linked chat's usage and limits at kra.name, as the «This chat: usage and limits» block of the kra.name panel:
 * GET /plugin/usage (free tokens, AI tokens of the month, the knowledge base in MB), cached 5 minutes. A kra.name
 * without that call shows the account's AI tokens from /plugin/me.
 */
function aiworker_chat_usage_rows(): array {
    $chat = (int) get_option(AIWORKER_CHAT_LINKED_OPTION, 0);
    $lang = aiworker_chat_wizard_lang();
    $cacheKey = 'aiworker_chat_usage_'.$chat.'_'.$lang;
    $cached = get_transient($cacheKey);
    if (is_array($cached)) return $cached;
    $rows = [];
    $url = '';
    if ($chat > 0) {
        $res = aiworker_chat_api('GET', '/plugin/usage', ['chat_id' => $chat, 'lang' => $lang], 10);
        if ($res['code'] === 200) {
            $rows = array_values(array_filter((array) ($res['body']['rows'] ?? []), 'is_array'));
            // an account without a paid plan spends its free tokens; the plan's monthly AI tokens start only after payment
            if (in_array('free_tokens', array_column($rows, 'key'), true)) $rows = array_values(array_filter($rows, static function (array $r): bool { return ($r['key'] ?? '') !== 'tokens'; }));
            $url = (string) ($res['body']['url'] ?? '');
        }
    }
    if (! $rows) {
        $me = aiworker_chat_api('GET', '/plugin/me', [], 10);
        $tok = $me['code'] === 200 ? ($me['body']['wizard']['tokens'] ?? null) : null;
        if (is_array($tok)) $rows[] = ['key' => 'tokens', 'label' => (string) ($tok['label'] ?? __('AI tokens', 'aiworker-chat')), 'used' => $tok['used'] ?? 0, 'limit' => $tok['limit'] ?? 0, 'text' => (string) ($tok['text'] ?? '')];
    }
    $result = ['rows' => $rows, 'url' => $url];
    set_transient($cacheKey, $result, 5 * MINUTE_IN_SECONDS);
    return $result;
}

/** The usage card in the side column of Settings, between «Save settings» and the preview. */
function aiworker_chat_usage_block(): void {
    $usage = aiworker_chat_usage_rows();
    if (! $usage['rows']) return;
    $url = preg_match('#^https://kra\.name/#', $usage['url']) ? $usage['url'] : 'https://kra.name/app/panel/billing';
    ?>
    <div class="aiwc-usage">
      <h2><?php esc_html_e('Usage and limits', 'aiworker-chat'); ?></h2>
      <?php foreach ($usage['rows'] as $r) :
          $used = (float) ($r['used'] ?? 0); $limit = (float) ($r['limit'] ?? 0);
          $pct = $limit > 0 ? (int) min(100, round(100 * $used / $limit)) : null;
          $tone = $pct === null ? '' : ($pct >= 100 ? ' is-bad' : ($pct >= 80 ? ' is-warn' : ''));
          $text = (string) ($r['text'] ?? '') !== '' ? (string) $r['text'] : ($limit > 0 ? number_format_i18n($used).' / '.number_format_i18n($limit) : number_format_i18n($used)); ?>
        <div class="aiwc-meter<?php echo esc_attr($tone); ?>">
          <div class="aiwc-meter__row"><span class="aiwc-meter__label"><?php echo esc_html((string) ($r['label'] ?? '')); ?></span><span class="aiwc-meter__value"><?php echo esc_html($text); ?></span></div>
          <?php if ($pct !== null) : ?><div class="aiwc-meter__bar" role="progressbar" aria-valuenow="<?php echo esc_attr((string) $pct); ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?php echo esc_attr((string) max($pct, $used > 0 ? 2 : 0)); ?>%"></span></div><?php endif; ?>
          <?php if (! empty($r['hint'])) : ?><div class="aiwc-meter__hint"><?php echo esc_html((string) $r['hint']); ?></div><?php endif; ?>
          <?php if (! empty($r['cta']) && preg_match('#^https://kra\.name/#', (string) ($r['cta_url'] ?? ''))) : ?><p class="aiwc-meter__cta"><a class="button button-small" href="<?php echo esc_url((string) $r['cta_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html((string) $r['cta']); ?></a></p><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <p class="description"><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Details at kra.name', 'aiworker-chat'); ?></a></p>
    </div>
    <?php
}

/**
 * The last step while the connected account has no paid plan: what the free account gives and a button to the payment
 * page at kra.name (account.billing_url from /plugin/me, otherwise the panel's billing page). A paid account has no step.
 */
function aiworker_chat_payment_step(int $number): void {
    $account = (array) (aiworker_chat_account_me()['account'] ?? []);
    if (! $account) return;
    $paid = array_key_exists('paid', $account) ? ! empty($account['paid']) : empty($account['free']);
    if ($paid) return;
    $url = (string) ($account['billing_url'] ?? '');
    if (! preg_match('#^https://kra\.name/#', $url)) $url = 'https://kra.name/app/panel/billing';
    $plan = (string) ($account['plan_name'] ?? '');
    ?>
    <section class="aiwc-card aiwc-card--pay" id="aiwc-pay">
      <h2><span class="aiwc-num"><?php echo esc_html((string) $number); ?></span><?php esc_html_e('Choose a plan', 'aiworker-chat'); ?></h2>
      <?php /* translators: %s: the account's current plan, e.g. «No plan» */ ?>
      <?php if ($plan !== '') : ?><p class="aiwc-pay__plan"><b><?php echo esc_html(sprintf(__('Now: %s.', 'aiworker-chat'), $plan)); ?></b></p><?php endif; ?>
      <p><?php esc_html_e('The chat works on the free AI tokens until they run out; then it stops answering visitors. A plan gives monthly AI tokens, a larger knowledge base and paid add-ons such as voice input.', 'aiworker-chat'); ?></p>
      <p><a class="button button-primary button-large" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php esc_html_e('Go to payment', 'aiworker-chat'); ?></a></p>
    </section>
    <?php
}

/** «Disconnect» lives outside the settings form (forms cannot be nested); the button in the chat box submits it. */
function aiworker_chat_assistant_forms(): void {
    if (! aiworker_chat_is_connected()) return;
    ?>
    <form id="aiwc-disconnect-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" hidden>
      <input type="hidden" name="action" value="aiwc_oauth_disconnect">
      <?php wp_nonce_field('aiwc_oauth_disconnect'); ?>
    </form>
    <?php
}

/** Step 1 of Settings once AI Worker is connected: the setup wizard of the online chat, a conversation in plain words. */
/** $collapsed: only the tab row shows (everything is connected); a tab or the arrow opens the card. */
function aiworker_chat_wizard_block(bool $collapsed = false): void {
    // the three ways to set the chat up from where you are: here, from your AI assistant (MCP), from Telegram
    $subs = ['wizard' => __('Setup wizard', 'aiworker-chat'), 'mcp' => 'MCP', 'telegram' => 'Telegram'];
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sub-tab choice.
    $sub = isset($_GET['aiwc_sub']) ? sanitize_key(wp_unslash((string) $_GET['aiwc_sub'])) : 'wizard';
    if (! isset($subs[$sub])) $sub = 'wizard';
    // a link to MCP or Telegram (?aiwc_sub=mcp|telegram) opens the card; «wizard» is only the remembered tab
    if ($sub !== 'wizard') $collapsed = false;
    ?>
    <div class="aiwc-aw<?php echo $collapsed ? ' is-collapsed' : ''; ?>" id="aiwc-wizard">
      <div class="aiwc-aw__tabs" role="tablist">
        <?php foreach ($subs as $key => $label) : ?>
          <button type="button" role="tab" class="aiwc-aw__tab<?php echo $key === $sub ? ' is-active' : ''; ?>" aria-selected="<?php echo $key === $sub ? 'true' : 'false'; ?>" aria-controls="aiwc-sub-<?php echo esc_attr($key); ?>" data-sub="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></button>
        <?php endforeach; ?>
        <button type="button" class="aiwc-aw__fold" aria-controls="aiwc-wizard" aria-expanded="<?php echo $collapsed ? 'false' : 'true'; ?>" data-label-open="<?php esc_attr_e('Expand', 'aiworker-chat'); ?>" data-label-close="<?php esc_attr_e('Collapse', 'aiworker-chat'); ?>"><span><?php echo esc_html($collapsed ? __('Expand', 'aiworker-chat') : __('Collapse', 'aiworker-chat')); ?></span><i aria-hidden="true"></i></button>
      </div>
      <div class="aiwc-aw__pane" id="aiwc-sub-mcp" role="tabpanel"<?php echo $sub === 'mcp' ? '' : ' hidden'; ?>><?php aiworker_chat_mcp_tab(true); ?></div>
      <div class="aiwc-aw__pane" id="aiwc-sub-telegram" role="tabpanel"<?php echo $sub === 'telegram' ? '' : ' hidden'; ?>><?php aiworker_chat_telegram_tab(true); ?></div>
      <div class="aiwc-aw__pane" id="aiwc-sub-wizard" role="tabpanel"<?php echo $sub === 'wizard' ? '' : ' hidden'; ?>>
      <p class="description"><?php esc_html_e('Ask in plain words: the wizard sets up the persona, the knowledge base, the greeting and the quick questions of the chat. It first shows each change and applies it after you press «Apply».', 'aiworker-chat'); ?></p>
      <div class="aiwc-aw__log" data-aw="log" aria-live="polite"><p class="aiwc-aw__loading"><i class="aiwc-aw__spin" aria-hidden="true"></i> <?php esc_html_e('Loading from kra.name…', 'aiworker-chat'); ?></p></div>
      <div class="aiwc-aw__pending" data-aw="pending" hidden></div>
      <div class="aiwc-aw__error notice notice-error inline" data-aw="error" hidden><p></p></div>
      <div class="aiwc-aw__ask">
        <div class="aiwc-aw__field">
          <textarea data-aw="text" rows="3" maxlength="4000"></textarea>
          <span class="aiwc-aw__busy" data-aw="busy" role="status" aria-live="polite" hidden><i class="aiwc-aw__spin" aria-hidden="true"></i><span></span></span>
          <span class="aiwc-aw__wave" data-aw="wave" aria-hidden="true" hidden><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span>
          <button type="button" class="aiwc-aw__mic" data-aw="mic" hidden><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0M12 17v5M8 22h8"/></svg></button>
        </div>
        <p class="aiwc-aw__mic-status description" data-aw="micStatus" role="status" aria-live="polite" hidden></p>
        <div class="aiwc-aw__actions">
          <button type="button" class="button button-primary" data-aw="send"><?php esc_html_e('Send', 'aiworker-chat'); ?></button>
          <button type="button" class="button-link" data-aw="clear"><?php esc_html_e('Start a new conversation', 'aiworker-chat'); ?></button>
        </div>
      </div>
      </div>
      <script>
      (function (root) {
        var fold = root.querySelector('.aiwc-aw__fold');
        function setOpen(open) {
          root.classList.toggle('is-collapsed', !open); fold.setAttribute('aria-expanded', open ? 'true' : 'false');
          fold.firstChild.textContent = fold.getAttribute(open ? 'data-label-close' : 'data-label-open');
        }
        fold.addEventListener('click', function () {
          var open = root.classList.contains('is-collapsed');
          setOpen(open);
          // folded: forget the tab in the address, so a reload keeps the card folded
          if (!open) { try { var u = new URL(location.href); u.searchParams.delete('aiwc_sub'); history.replaceState(null, '', u); } catch (e) { /* old browser */ } }
        });
        root.querySelectorAll('.aiwc-aw__tab').forEach(function (tab) {
          tab.addEventListener('click', function () {
            setOpen(true);
            root.querySelectorAll('.aiwc-aw__tab').forEach(function (x) {
              var on = x === tab; x.classList.toggle('is-active', on); x.setAttribute('aria-selected', on ? 'true' : 'false');
              document.getElementById(x.getAttribute('aria-controls')).hidden = !on;
            });
            try { var u = new URL(location.href); u.searchParams.set('aiwc_sub', tab.getAttribute('data-sub')); history.replaceState(null, '', u); } catch (e) { /* old browser */ }
          });
        });
      })(document.getElementById('aiwc-wizard'));
      </script>
    </div>
    <?php aiworker_chat_assistant_styles(); ?>
    <?php
}

/** The «Statistics» tab: dialogs, leads and ratings of the account's chats. */
function aiworker_chat_assistant_tab(): void {
    aiworker_chat_assistant_notice();
    ?>
    <div class="aiwc-aw" id="aiwc-wizard">
      <?php aiworker_chat_assistant_bar(); ?>
      <section class="aiwc-card">
        <h2><?php esc_html_e('Statistics', 'aiworker-chat'); ?>
          <select data-aw="period">
            <option value="7d"><?php esc_html_e('7 days', 'aiworker-chat'); ?></option>
            <option value="30d"><?php esc_html_e('30 days', 'aiworker-chat'); ?></option>
            <option value="month"><?php esc_html_e('This month', 'aiworker-chat'); ?></option>
          </select>
        </h2>
        <div class="aiwc-aw__error notice notice-error inline" data-aw="error" hidden><p></p></div>
        <div data-aw="stats"><p class="aiwc-aw__loading"><i class="aiwc-aw__spin" aria-hidden="true"></i> <?php esc_html_e('Loading from kra.name…', 'aiworker-chat'); ?></p></div>
      </section>
    </div>
    <?php aiworker_chat_assistant_forms(); ?>
    <?php aiworker_chat_assistant_styles(); ?>
    <?php
}

function aiworker_chat_assistant_styles(): void {
    ?>
    <style>
      .aiwc-aw#aiwc-wizard{border:1px solid #dcdcde;border-radius:12px;background:#fff;padding:0 18px 18px;margin:18px 0}.aiwc-aw__fold{margin-left:auto;align-self:center;display:inline-flex;align-items:center;gap:6px;border:0;background:none;color:#2271b1;font:inherit;font-size:13px;cursor:pointer;padding:8px 4px}.aiwc-aw__fold i{width:0;height:0;border-left:5px solid transparent;border-right:5px solid transparent;border-bottom:6px solid currentColor;transition:transform .15s}.aiwc-aw.is-collapsed .aiwc-aw__fold i{transform:rotate(180deg)}.aiwc-aw.is-collapsed{padding-bottom:0!important}.aiwc-aw.is-collapsed .aiwc-aw__pane{display:none}.aiwc-aw.is-collapsed .aiwc-aw__tabs{margin-bottom:0;border-bottom:0}.aiwc-aw.is-collapsed .aiwc-aw__tab.is-active{color:#50575e;border-bottom-color:transparent}.aiwc-aw__tabs{display:flex;gap:4px;border-bottom:1px solid #dcdcde;margin:0 -18px 14px;padding:0 12px}.aiwc-aw__tab{border:0;background:none;padding:12px 12px 10px;font:inherit;font-size:14px;font-weight:600;color:#50575e;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px}.aiwc-aw__tab:hover{color:#1d2327}.aiwc-aw__tab.is-active{color:#3858e9;border-bottom-color:#3858e9}.aiwc-aw__pane .aiwc-main{display:block}.aiwc-aw__pane .aiwc-card{border:0;box-shadow:none;background:none;padding:0;margin:0}.aiwc-aw__pane .aiwc-card h2{font-size:15px;margin:0 0 8px}.aiwc-aw__bar{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin:14px 0}.aiwc-aw__account{color:#50575e}.aiwc-aw h3{margin:8px 0 4px}
      .aiwc-aw__grid{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:18px;align-items:start}
      .aiwc-aw__log{max-height:460px;overflow:auto;display:flex;flex-direction:column;gap:10px;padding:4px 2px 10px}
      .aiwc-aw__msg{max-width:88%;padding:10px 12px;border-radius:12px;white-space:pre-wrap;word-wrap:break-word;line-height:1.5}
      .aiwc-aw__msg--assistant{background:#f0f0f1;align-self:flex-start}.aiwc-aw__msg--user{background:#4f46e5;color:#fff;align-self:flex-end}
      .aiwc-aw__msg small{display:block;font-size:11px;opacity:.7;margin-bottom:2px}.aiwc-aw__msg--working{opacity:.75;font-style:italic}
      .aiwc-aw__pending{border:1px solid #dba617;background:#fcf7e8;border-radius:10px;padding:10px 14px;margin:8px 0}.aiwc-aw__pending ul{margin:6px 0 10px 18px;list-style:disc}
      .aiwc-aw__ask textarea{width:100%;border-radius:8px;padding-right:44px}.aiwc-aw__field{position:relative}.aiwc-aw__mic{position:absolute;right:8px;bottom:10px;width:32px;height:32px;border-radius:50%;border:1px solid #dcdcde;background:#fff;color:#3858e9;display:flex;align-items:center;justify-content:center;cursor:pointer;padding:0}.aiwc-aw__mic[hidden]{display:none}.aiwc-aw__mic:hover{border-color:#3858e9}.aiwc-aw__mic.is-rec{background:#d63638;border-color:#d63638;color:#fff;animation:aiwc-pulse 1.2s infinite}.aiwc-aw__mic.is-busy,.aiwc-aw__mic.is-prep{background:#eef1fd;border-color:#c5cff8;cursor:progress}.aiwc-aw__mic.is-busy svg,.aiwc-aw__mic.is-prep svg{display:none}.aiwc-aw__mic.is-busy::after,.aiwc-aw__mic.is-prep::after{content:"";width:14px;height:14px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:aiwc-spin .7s linear infinite}.aiwc-aw__busy{position:absolute;left:10px;bottom:12px;display:inline-flex;align-items:center;gap:6px;padding:3px 10px;border-radius:999px;background:#eef1fd;color:#3858e9;font-size:12px;font-weight:600;pointer-events:none}.aiwc-aw__busy[hidden]{display:none}.aiwc-aw__spin{width:12px;height:12px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:aiwc-spin .7s linear infinite}@keyframes aiwc-spin{to{transform:rotate(360deg)}}.aiwc-aw__field.is-working textarea{padding-bottom:38px}@media (prefers-reduced-motion:reduce){.aiwc-aw__spin,.aiwc-aw__mic::after{animation-duration:2s}}@keyframes aiwc-pulse{0%,100%{box-shadow:0 0 0 0 rgba(214,54,56,.4)}50%{box-shadow:0 0 0 6px rgba(214,54,56,0)}}.aiwc-aw__mic-status{margin:4px 0 0}.aiwc-aw__wave{position:absolute;right:48px;bottom:15px;width:48px;height:22px;display:inline-flex;align-items:center;justify-content:center;gap:3px;pointer-events:none;color:#d63638}.aiwc-aw__wave[hidden]{display:none}.aiwc-aw__wave i{display:block;width:3px;height:3px;border-radius:3px;background:currentColor;transition:height .08s linear}.aiwc-aw__wave.is-fallback i{animation:aiwc-wave .8s ease-in-out infinite alternate}.aiwc-aw__wave.is-fallback i:nth-child(2n){animation-delay:.2s}.aiwc-aw__wave.is-fallback i:nth-child(3n){animation-delay:.4s}@keyframes aiwc-wave{from{height:3px}to{height:16px}}.aiwc-aw__ask textarea{padding-right:104px}.aiwc-aw__actions{display:flex;justify-content:space-between;align-items:center;margin-top:6px}
      .aiwc-aw__cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;margin:22px 0 14px}.aiwc-aw__loading{display:flex;align-items:center;gap:8px;color:#3858e9;margin:6px 0}
      .aiwc-aw__cards div{border:1px solid #dcdcde;border-radius:10px;padding:8px 10px}.aiwc-aw__cards b{display:block;font-size:20px}.aiwc-aw__cards span{font-size:12px;color:#50575e}
      .aiwc-aw__meter{margin:0 0 12px}.aiwc-aw__meter i{display:block;height:6px;border-radius:3px;background:#dcdcde;overflow:hidden}.aiwc-aw__meter i b{display:block;height:100%;background:#00a32a}
      .aiwc-aw__meter.is-warn i b{background:#dba617}.aiwc-aw__meter.is-full i b{background:#d63638}
      .aiwc-aw__chart{width:100%;height:auto;max-height:200px;margin:6px 0 10px}.aiwc-aw__legend{font-size:12px;color:#50575e}.aiwc-aw__legend i{display:inline-block;width:10px;height:10px;border-radius:2px;margin:0 4px 0 10px}
      .aiwc-aw table{width:100%}.aiwc-aw h2 select{margin-left:auto;font-weight:400}
      @media (max-width:1100px){.aiwc-aw__grid{grid-template-columns:minmax(0,1fr)}}
    </style>
    <?php
}
