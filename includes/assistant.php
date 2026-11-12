<?php
/**
 * The setup wizard in step 1 of Settings and the Statistics tab, for the chat linked to this site through AI Worker.
 * Requests and statistics come from the owner's kra.name account over the plugin API (scope «chat»). Only
 * users with manage_options see it; the browser talks to this site's admin-ajax only (nonce + capability on every call),
 * and PHP talks to kra.name with the stored token (includes/connect.php). The interface and the chart are this plugin's
 * own code (admin-assistant.js); nothing is loaded from kra.name.
 */

if (! defined('ABSPATH')) exit;

/** AJAX: one entry point, a fixed list of operations mapped to the plugin API. */
function aiworker_chat_assistant_ajax(): void {
    if (! current_user_can('manage_options')) wp_send_json(['code' => 403, 'body' => ['message' => __('You are not allowed to do this.', 'needtome-ai-chat')]], 403);
    check_ajax_referer('aiworker_chat_assistant', 'nonce');
    $op = isset($_POST['op']) ? sanitize_key(wp_unslash((string) $_POST['op'])) : '';
    $chat = aiworker_chat_linked_id();
    if ($chat <= 0) wp_send_json(['code' => 404, 'body' => []], 404);
    $chatArg = $chat > 0 ? ['chat_id' => $chat] : [];
    // The wizard answers in the language of this settings screen (kra.name: ?lang=ru|en|uk), which the page sends:
    // admin-ajax is not that screen, so Polylang's language chosen there is not seen here.
    $lang = isset($_POST['lang']) ? sanitize_key(wp_unslash((string) $_POST['lang'])) : '';
    if (! in_array($lang, ['ru', 'en', 'uk'], true)) $lang = aiworker_chat_wizard_lang();

    switch ($op) {
        case 'me':
            // labels such as the plan name come in the screen's language
            $result = aiworker_chat_api('GET', '/plugin/me', ['lang' => $lang] + $chatArg);
            break;
        case 'assistant':
            $result = aiworker_chat_api('GET', '/plugin/assistant', $chatArg + ['lang' => $lang]);
            break;
        case 'ask':
            $text = isset($_POST['text']) ? sanitize_textarea_field(wp_unslash((string) $_POST['text'])) : '';
            $text = function_exists('mb_substr') ? mb_substr($text, 0, 4000) : substr($text, 0, 4000);
            if (trim($text) === '') wp_send_json(['code' => 422, 'body' => ['message' => __('Write what you want to set up.', 'needtome-ai-chat')]]);
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
            $boundary = 'aiworker-chat'.wp_generate_password(24, false);
            $data = '--'.$boundary."\r\n".'Content-Disposition: form-data; name="audio"; filename="voice.'.(strpos($mime, 'mp4') !== false ? 'm4a' : (strpos($mime, 'ogg') !== false ? 'ogg' : 'webm')).'"'."\r\n"
                .'Content-Type: '.$mime."\r\n\r\n".file_get_contents((string) $file['tmp_name'])."\r\n--".$boundary."--\r\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local uploaded file.
            $result = aiworker_chat_api('POST', '/plugin/voice/transcribe?lang='.$lang, [], 60, ['type' => 'multipart/form-data; boundary='.$boundary, 'data' => $data]);
            break;
        case 'stats':
            $period = isset($_POST['period']) ? sanitize_key(wp_unslash((string) $_POST['period'])) : '7d';
            $from = isset($_POST['from']) ? sanitize_text_field(wp_unslash((string) $_POST['from'])) : '';
            $to = isset($_POST['to']) ? sanitize_text_field(wp_unslash((string) $_POST['to'])) : '';
            $result = aiworker_chat_api('GET', '/plugin/stats', aiworker_chat_stats_query($period, $from, $to) + ['lang' => $lang] + $chatArg, 30);
            break;
        default:
            wp_send_json(['code' => 400, 'body' => []], 400);
    }
    if ($result['code'] === 401) $result['body']['connected'] = aiworker_chat_is_connected();
    // The status goes in the JSON body: admin-ajax answers 200 so the script can show the reason itself.
    wp_send_json(['code' => $result['code'], 'body' => $result['body'], 'error' => $result['error'] !== '' ? __('kra.name cannot be reached from this site.', 'needtome-ai-chat') : '']);
}
add_action('wp_ajax_aiworker_chat_assistant', 'aiworker_chat_assistant_ajax');

/** The wizard's language for this screen: ru, uk or en. */
function aiworker_chat_wizard_lang(): string {
    // admin-ajax is not the settings screen: the screen sends its language with each request
    // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Only picks a language; every handler checks its nonce.
    $sent = wp_doing_ajax() && isset($_POST['lang']) ? sanitize_key(wp_unslash((string) $_POST['lang'])) : '';
    if (in_array($sent, ['ru', 'en', 'uk'], true)) return $sent;
    $locale = aiworker_chat_polylang_admin_locale() ?: get_user_locale();
    return strpos($locale, 'ru') === 0 ? 'ru' : (strpos($locale, 'uk') === 0 ? 'uk' : 'en');
}

/** The /plugin/stats query of a period: kra.name keeps daily totals and answers any range up to a year through from and to. */
function aiworker_chat_stats_query(string $period, string $from = '', string $to = ''): array {
    $today = wp_date('Y-m-d');
    if ($period === '90d') return ['from' => wp_date('Y-m-d', time() - 89 * DAY_IN_SECONDS), 'to' => $today];
    $date = static function (string $value): bool {
        return (bool) preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    };
    if ($period === 'custom' && $date($from) && $date($to)) return ['from' => $from, 'to' => $to];
    return ['period' => in_array($period, ['7d', '30d', 'month'], true) ? $period : '7d'];
}

/** Script and texts for the tab (only on this screen and tab). */
function aiworker_chat_assistant_enqueue(string $hook): void {
    $tab = aiworker_chat_admin_tab();
    // the wizard is in step 1 of Settings (once a chat is linked), the statistics on their own tab
    if ($hook !== 'needtome_page_aiworker-chat' || ! aiworker_chat_is_connected()) return;
    // the Knowledge base tab needs it too: «What the consultant answers from» saves through admin-ajax with this nonce
    if ($tab !== 'assistant' && ! (in_array($tab, ['settings', 'kb'], true) && aiworker_chat_options()['key'] !== '')) return;
    // the file's time in the version too: a browser keeps a cached script for the same version, also between releases
    wp_enqueue_script('aiworker-chat-assistant', aiworker_chat_asset_url('admin-assistant.js'), [], aiworker_chat_asset_version('admin-assistant.js'), true);
    wp_add_inline_script('aiworker-chat-assistant', 'window.aiworkerChatAssistant = '.wp_json_encode([
        'ajax' => aiworker_chat_admin_ajax_url(),
        'nonce' => wp_create_nonce('aiworker_chat_assistant'),
        'connectUrl' => aiworker_chat_settings_url(),
        'chatId' => (string) aiworker_chat_linked_id(),
        'lang' => aiworker_chat_wizard_lang(),
        't' => [
            'you' => __('You', 'needtome-ai-chat'),
            'assistant' => __('Wizard', 'needtome-ai-chat'),
            'send' => __('Send', 'needtome-ai-chat'),
            'sending' => __('The wizard is thinking…', 'needtome-ai-chat'),
            'pending' => __('Proposed changes', 'needtome-ai-chat'),
            'apply' => __('Apply', 'needtome-ai-chat'),
            'discard' => __('Cancel', 'needtome-ai-chat'),
            'clear' => __('Start a new conversation', 'needtome-ai-chat'),
            'clearConfirm' => __('Clear the conversation with the wizard?', 'needtome-ai-chat'),
            'mic' => __('Dictate', 'needtome-ai-chat'),
            'micStop' => __('Stop and recognize', 'needtome-ai-chat'),
            'micPrep' => __('Getting the microphone ready…', 'needtome-ai-chat'),
            'micSpeak' => __('Speak. Press again to recognize the speech.', 'needtome-ai-chat'),
            'micBusy' => __('Recognizing the speech…', 'needtome-ai-chat'),
            'micShort' => __('The recording is too short.', 'needtome-ai-chat'),
            'micDenied' => __('Allow access to the microphone.', 'needtome-ai-chat'),
            'micFailed' => __('The speech could not be recognized.', 'needtome-ai-chat'),
            'micNone' => __('Voice input is not available in this browser.', 'needtome-ai-chat'),
            'micSafari' => __('So that Safari stops asking about the microphone every time: Safari Settings → Websites → Microphone → this site → «Allow».', 'needtome-ai-chat'),
            'placeholder' => __('For example: create a chat for this site, add the delivery page to the knowledge base and write a greeting', 'needtome-ai-chat'),
            'e401' => __('The connection to AI Worker has ended. Connect again.', 'needtome-ai-chat'),
            'e403' => __('This is not available with the access the site has. Open the kra.name panel for it.', 'needtome-ai-chat'),
            'e403plan' => __('The account\'s plan no longer includes the online chat, so the wizard and the statistics are not available.', 'needtome-ai-chat'),
            'e404' => __('This chat is not in the connected account.', 'needtome-ai-chat'),
            /* translators: %s: seconds to wait */
            'e429' => __('Too many requests or the AI tokens are used up. Try again in %s s.', 'needtome-ai-chat'),
            'e5xx' => __('kra.name answered with an error. Try again a bit later.', 'needtome-ai-chat'),
            'e0' => __('kra.name cannot be reached from this site.', 'needtome-ai-chat'),
            'tokens' => __('AI tokens', 'needtome-ai-chat'),
            'loading' => __('Loading from kra.name…', 'needtome-ai-chat'),
            'of' => __('of', 'needtome-ai-chat'),
            'noLimit' => __('no limit', 'needtome-ai-chat'),
            'byDay' => __('By day', 'needtome-ai-chat'),
            'day' => __('Day', 'needtome-ai-chat'),
            'empty' => __('No dialogs in this period.', 'needtome-ai-chat'),
            'cols' => [
                'sessions' => __('Dialogs', 'needtome-ai-chat'),
                'messages_in' => __('Visitor messages', 'needtome-ai-chat'),
                'answers' => __('Answers', 'needtome-ai-chat'),
                'leads' => __('Leads', 'needtome-ai-chat'),
                'rated_high' => __('Rated 4–5', 'needtome-ai-chat'),
                'rated_low' => __('Rated 1–3', 'needtome-ai-chat'),
                'voice' => __('Voice input', 'needtome-ai-chat'),
            ],
        ],
    ]).';', 'before');
}
add_action('admin_enqueue_scripts', 'aiworker_chat_assistant_enqueue');

/** Notices after the OAuth round trip and the chat choice: code => [type, text]. */
function aiworker_chat_assistant_notices(): array {
    return [
        'connected' => ['success', __('AI Worker is connected. Once a chat is connected, the setup wizard is in step 1.', 'needtome-ai-chat')],
        'disconnected' => ['success', __('AI Worker is disconnected: the access was revoked and removed from this site. The chat on the site keeps working.', 'needtome-ai-chat')],
        'denied' => ['warning', __('Access was not allowed at kra.name, nothing changed.', 'needtome-ai-chat')],
        'state' => ['error', __('The connection link is outdated or was opened by another user. Start the connection again.', 'needtome-ai-chat')],
        'register_failed' => ['error', __('kra.name did not register this site. Check that the site can reach kra.name and try again.', 'needtome-ai-chat')],
        'failed' => ['error', __('The connection was not completed. Start it again.', 'needtome-ai-chat')],
        'linked' => ['success', __('The chat is connected: its key is saved and this site is in its allowed sites. Turn on «Show the chat on this site» below. The setup wizard is in step 1 below.', 'needtome-ai-chat')],
        'link_unsupported' => ['warning', __('kra.name cannot connect a chat automatically yet. Copy the public key wk_… from the chat\'s card and paste it above.', 'needtome-ai-chat')],
        'link_domains' => ['error', __('This chat already has the maximum of 20 allowed sites. Remove one in the chat\'s settings at kra.name, then connect again.', 'needtome-ai-chat')],
        'link_expired' => ['error', __('This sign-in link is outdated or was already used. Open the NeedToMe AI Chat settings and copy the link again.', 'needtome-ai-chat')],
        'tg_failed' => ['error', __('kra.name did not give a Telegram link. Try again, or connect the bot in the kra.name panel.', 'needtome-ai-chat')],
        'link_busy' => ['warning', __('This chat is syncing its knowledge. Try changing the chat again in a minute.', 'needtome-ai-chat')],
        'link_other_tab' => ['warning', __('This chat is already connected to another chat tab of this site. Open that tab instead.', 'needtome-ai-chat')],
        'link_failed' => ['error', __('kra.name did not connect this chat. Try again, or copy the public key wk_… from the chat\'s card.', 'needtome-ai-chat')],
    ];
}

function aiworker_chat_assistant_notice(): void {
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only notice code from our own redirect.
    $code = isset($_GET['aiworker_chat_notice']) ? sanitize_key(wp_unslash((string) $_GET['aiworker_chat_notice'])) : '';
    $notices = aiworker_chat_assistant_notices();
    if (isset($notices[$code])) echo '<div class="notice notice-'.esc_attr($notices[$code][0]).' inline"><p>'.esc_html($notices[$code][1]).'</p></div>';
}

/** The connected account. The wizard and the statistics work with the chat linked to this site. */
function aiworker_chat_assistant_bar(): void {
    ?>
      <div class="aiworker-chat-aw__bar">
        <span class="aiworker-chat-pill aiworker-chat-pill--ok"><?php esc_html_e('AI Worker connected', 'needtome-ai-chat'); ?></span>
        <span class="aiworker-chat-aw__account" data-aw="account"></span>
      </div>
    <?php
}

/**
 * The linked chat's usage and limits at kra.name, as the «This chat: usage and limits» block of the kra.name panel:
 * GET /plugin/usage (free tokens, AI tokens of the month, the knowledge base in MB), cached 5 minutes. A kra.name
 * without that call shows the account's AI tokens from /plugin/me.
 */
function aiworker_chat_usage_rows(): array {
    $chat = aiworker_chat_linked_id();
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
        if (is_array($tok)) $rows[] = ['key' => 'tokens', 'label' => (string) ($tok['label'] ?? __('AI tokens', 'needtome-ai-chat')), 'used' => $tok['used'] ?? 0, 'limit' => $tok['limit'] ?? 0, 'text' => (string) ($tok['text'] ?? '')];
    }
    $result = ['rows' => $rows, 'url' => $url];
    set_transient($cacheKey, $result, 5 * MINUTE_IN_SECONDS);
    return $result;
}

/** The usage card in the side column of Settings, between «Save settings» and the preview. */
function aiworker_chat_usage_block(): string {
    $html = '';

    $usage = aiworker_chat_usage_rows();
    if (! $usage['rows']) return '';
    $url = preg_match('#^https://kra\.name/#', $usage['url']) ? $usage['url'] : 'https://kra.name/app/panel/billing';
    $html .= '
    <div class="aiworker-chat-usage">
      <h2>';
 $html .= esc_html__('Usage and limits', 'needtome-ai-chat'); $html .= '</h2>
      ';
 foreach ($usage['rows'] as $r) :
          $used = (float) ($r['used'] ?? 0); $limit = (float) ($r['limit'] ?? 0);
          $pct = $limit > 0 ? (int) min(100, round(100 * $used / $limit)) : null;
          $tone = $pct === null ? '' : ($pct >= 100 ? ' is-bad' : ($pct >= 80 ? ' is-warn' : ''));
          $text = (string) ($r['text'] ?? '') !== '' ? (string) $r['text'] : ($limit > 0 ? number_format_i18n($used).' / '.number_format_i18n($limit) : number_format_i18n($used)); $html .= '
        <div class="aiworker-chat-meter';
 $html .= esc_attr($tone); $html .= '">
          <div class="aiworker-chat-meter__row"><span class="aiworker-chat-meter__label">';
 $html .= esc_html((string) ($r['label'] ?? '')); $html .= '</span><span class="aiworker-chat-meter__value">';
 $html .= esc_html($text); $html .= '</span></div>
          ';
 if ($pct !== null) : $html .= '<div class="aiworker-chat-meter__bar" role="progressbar" aria-valuenow="';
 $html .= esc_attr((string) $pct); $html .= '" aria-valuemin="0" aria-valuemax="100"><span style="width:';
 $html .= esc_attr((string) max($pct, $used > 0 ? 2 : 0)); $html .= '%"></span></div>';
 endif; $html .= '
          ';
 if (! empty($r['hint'])) : $html .= '<div class="aiworker-chat-meter__hint">';
 $html .= esc_html((string) $r['hint']); $html .= '</div>';
 endif; $html .= '
          ';
 if (! empty($r['cta']) && preg_match('#^https://kra\.name/#', (string) ($r['cta_url'] ?? ''))) : $html .= '<p class="aiworker-chat-meter__cta"><a class="button button-small" href="';
 $html .= esc_url((string) $r['cta_url']); $html .= '" target="_blank" rel="noopener">';
 $html .= esc_html((string) $r['cta']); $html .= '</a></p>';
 endif; $html .= '
        </div>
      ';
 endforeach; $html .= '
      <p class="description"><a href="';
 $html .= esc_url($url); $html .= '" target="_blank" rel="noopener">';
 $html .= esc_html__('Details at kra.name', 'needtome-ai-chat'); $html .= '</a></p>
    </div>
    ';


    return $html;
}

/**
 * The last step while the connected account has no paid plan: what the free account gives and a button to the payment
 * page at kra.name (account.billing_url from /plugin/me, otherwise the panel's billing page). A paid account has no step.
 */
function aiworker_chat_payment_step(int $number): string {
    $html = '';

    $account = (array) (aiworker_chat_account_me()['account'] ?? []);
    if (! $account) return '';
    $paid = array_key_exists('paid', $account) ? ! empty($account['paid']) : empty($account['free']);
    if ($paid) return '';
    $url = (string) ($account['billing_url'] ?? '');
    if (! preg_match('#^https://kra\.name/#', $url)) $url = 'https://kra.name/app/panel/billing';
    $plan = (string) ($account['plan_name'] ?? '');
    $html .= '
    <section class="aiworker-chat-card aiworker-chat-card--pay" id="aiworker-chat-pay">
      <h2><span class="aiworker-chat-num">';
 $html .= esc_html((string) $number); $html .= '</span>';
 $html .= esc_html__('Choose a plan', 'needtome-ai-chat'); $html .= '</h2>
      ';
 /* translators: %s: the account's current plan, e.g. «No plan» */ $html .= '
      ';
 if ($plan !== '') : $html .= '<p class="aiworker-chat-pay__plan"><b>';
 $html .= esc_html(sprintf(/* translators: %s: the plan name */ __('Now: %s.', 'needtome-ai-chat'), $plan)); $html .= '</b></p>';
 endif; $html .= '
      <p>';
 $html .= esc_html__('The chat works on the free AI tokens until they run out; then it stops answering visitors. A plan gives monthly AI tokens, a larger knowledge base and paid add-ons such as voice input.', 'needtome-ai-chat'); $html .= '</p>
      <p><a class="button button-primary button-large" href="';
 $html .= esc_url($url); $html .= '" target="_blank" rel="noopener">';
 $html .= esc_html__('Go to payment', 'needtome-ai-chat'); $html .= '</a></p>
    </section>
    ';


    return $html;
}

/** «Disconnect» lives outside the settings form (forms cannot be nested); the button in the chat box submits it. */
function aiworker_chat_assistant_forms(): void {
    if (! aiworker_chat_is_connected()) return;
    ?>
    <form id="aiworker-chat-disconnect-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" hidden>
      <input type="hidden" name="action" value="aiworker_chat_oauth_disconnect">
      <?php wp_nonce_field('aiworker_chat_oauth_disconnect'); ?>
    </form>
    <?php
}

/** Step 1 of Settings once AI Worker is connected: the setup wizard of the online chat, a conversation in plain words. */
/** $collapsed: only the tab row shows (everything is connected); a tab or the arrow opens the card. */
function aiworker_chat_wizard_block(bool $collapsed = false): void {
    // the three ways to set the chat up from where you are: here, from your AI assistant (MCP), from Telegram
    $subs = ['wizard' => __('Setup wizard', 'needtome-ai-chat'), 'mcp' => 'MCP', 'telegram' => 'Telegram'];
    // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only sub-tab choice.
    $sub = isset($_GET['aiworker_chat_sub']) ? sanitize_key(wp_unslash((string) $_GET['aiworker_chat_sub'])) : 'wizard';
    if (! isset($subs[$sub])) $sub = 'wizard';
    // a link to MCP or Telegram (?aiworker_chat_sub=mcp|telegram) opens the card; «wizard» is only the remembered tab
    if ($sub !== 'wizard') $collapsed = false;
    ?>
    <div class="aiworker-chat-aw<?php echo $collapsed ? ' is-collapsed' : ''; ?>" id="aiworker-chat-wizard">
      <div class="aiworker-chat-aw__tabs" role="tablist">
        <?php foreach ($subs as $key => $label) : ?>
          <button type="button" role="tab" class="aiworker-chat-aw__tab<?php echo $key === $sub ? ' is-active' : ''; ?>" aria-selected="<?php echo $key === $sub ? 'true' : 'false'; ?>" aria-controls="aiworker-chat-sub-<?php echo esc_attr($key); ?>" data-sub="<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></button>
        <?php endforeach; ?>
        <button type="button" class="aiworker-chat-aw__fold" aria-controls="aiworker-chat-wizard" aria-expanded="<?php echo $collapsed ? 'false' : 'true'; ?>" data-label-open="<?php esc_attr_e('Expand', 'needtome-ai-chat'); ?>" data-label-close="<?php esc_attr_e('Collapse', 'needtome-ai-chat'); ?>"><span><?php echo esc_html($collapsed ? __('Expand', 'needtome-ai-chat') : __('Collapse', 'needtome-ai-chat')); ?></span><i aria-hidden="true"></i></button>
      </div>
      <div class="aiworker-chat-aw__pane" id="aiworker-chat-sub-mcp" role="tabpanel"<?php echo $sub === 'mcp' ? '' : ' hidden'; ?>><?php aiworker_chat_mcp_tab(true); ?></div>
      <div class="aiworker-chat-aw__pane" id="aiworker-chat-sub-telegram" role="tabpanel"<?php echo $sub === 'telegram' ? '' : ' hidden'; ?>><?php aiworker_chat_telegram_tab(true); ?></div>
      <div class="aiworker-chat-aw__pane" id="aiworker-chat-sub-wizard" role="tabpanel"<?php echo $sub === 'wizard' ? '' : ' hidden'; ?>>
      <p class="description"><?php esc_html_e('Ask in plain words: the wizard sets up the persona, the knowledge base, the greeting and the quick questions of the chat. It first shows each change and applies it after you press «Apply».', 'needtome-ai-chat'); ?></p>
      <div class="aiworker-chat-aw__log" data-aw="log" aria-live="polite"><p class="aiworker-chat-aw__loading"><i class="aiworker-chat-aw__spin" aria-hidden="true"></i> <?php esc_html_e('Loading from kra.name…', 'needtome-ai-chat'); ?></p></div>
      <div class="aiworker-chat-aw__pending" data-aw="pending" hidden></div>
      <div class="aiworker-chat-aw__error notice notice-error inline" data-aw="error" hidden><p></p></div>
      <div class="aiworker-chat-aw__ask">
        <div class="aiworker-chat-aw__field">
          <textarea data-aw="text" rows="3" maxlength="4000"></textarea>
          <span class="aiworker-chat-aw__busy" data-aw="busy" role="status" aria-live="polite" hidden><i class="aiworker-chat-aw__spin" aria-hidden="true"></i><span></span></span>
          <span class="aiworker-chat-aw__wave" data-aw="wave" aria-hidden="true" hidden><i></i><i></i><i></i><i></i><i></i><i></i><i></i></span>
          <button type="button" class="aiworker-chat-aw__mic" data-aw="mic" hidden><svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 10a7 7 0 0 0 14 0M12 17v5M8 22h8"/></svg></button>
        </div>
        <p class="aiworker-chat-aw__mic-status description" data-aw="micStatus" role="status" aria-live="polite" hidden></p>
        <div class="aiworker-chat-aw__actions">
          <button type="button" class="button button-primary" data-aw="send"><?php esc_html_e('Send', 'needtome-ai-chat'); ?></button>
          <button type="button" class="button-link" data-aw="clear"><?php esc_html_e('Start a new conversation', 'needtome-ai-chat'); ?></button>
        </div>
      </div>
      </div>

    </div>
    <?php
}

/** The «Statistics» tab: dialogs, leads and ratings of the chat linked to this site. */
function aiworker_chat_assistant_tab(): void {
    aiworker_chat_assistant_notice();
    ?>
    <div class="aiworker-chat-aw" id="aiworker-chat-wizard">
      <?php aiworker_chat_assistant_bar(); ?>
      <section class="aiworker-chat-card">
        <h2><?php esc_html_e('Statistics', 'needtome-ai-chat'); ?>
        <span class="aiworker-chat-aw__range" data-aw="range" hidden>
          <label><?php esc_html_e('From', 'needtome-ai-chat'); ?> <input type="date" data-aw="from" max="<?php echo esc_attr(wp_date('Y-m-d')); ?>"></label>
          <label><?php esc_html_e('To', 'needtome-ai-chat'); ?> <input type="date" data-aw="to" max="<?php echo esc_attr(wp_date('Y-m-d')); ?>"></label>
          <span class="description"><?php esc_html_e('Up to one year.', 'needtome-ai-chat'); ?></span>
        </span>
          <select data-aw="period">
            <option value="7d"><?php esc_html_e('7 days', 'needtome-ai-chat'); ?></option>
            <option value="30d"><?php esc_html_e('30 days', 'needtome-ai-chat'); ?></option>
            <option value="90d"><?php esc_html_e('90 days', 'needtome-ai-chat'); ?></option>
            <option value="month"><?php esc_html_e('This month', 'needtome-ai-chat'); ?></option>
            <option value="custom"><?php esc_html_e('Custom range', 'needtome-ai-chat'); ?></option>
          </select>
        </h2>
        <div class="aiworker-chat-aw__error notice notice-error inline" data-aw="error" hidden><p></p></div>
        <div data-aw="stats"><p class="aiworker-chat-aw__loading"><i class="aiworker-chat-aw__spin" aria-hidden="true"></i> <?php esc_html_e('Loading from kra.name…', 'needtome-ai-chat'); ?></p></div>
      </section>
    </div>
    <?php aiworker_chat_assistant_forms(); ?>
    <?php
}
