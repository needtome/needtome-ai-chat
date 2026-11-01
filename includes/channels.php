<?php
/**
 * Step 3 «Where to show the chat», at the bottom: the same online chat can answer in Telegram, Messenger, Instagram,
 * WhatsApp and VK. One sub-tab per channel with what it needs and the steps; the channel itself is connected in the
 * chat's «Channels» at kra.name. While AI Worker is connected, each tab shows whether the channel is connected.
 */

if (! defined('ABSPATH')) exit;

/** The channels a web chat can answer in: type => [label, what it needs, steps, note]. */
function aiworker_chat_channel_guides(): array {
    $botfather = '<a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a>';
    $meta = '<a href="https://developers.facebook.com/apps/" target="_blank" rel="noopener">developers.facebook.com</a>';
    // %1$s … %2$s around «Channels»: the link to the chat's «Channels» page at kra.name
    $panel = '<a href="'.esc_url(aiworker_chat_panel_chat_url('channels')).'" target="_blank" rel="noopener">';
    $webhook = __('Fields of the Meta app that the «Channels» page shows: the callback URL and the verify token. Paste them in the app\'s Webhooks settings and subscribe to messages.', 'needtome-ai-chat');
    return [
        'telegram' => ['Telegram', __('your own Telegram bot', 'needtome-ai-chat'), [
            /* translators: 1: @BotFather link, 2: the /newbot command */
            sprintf(__('In Telegram open %1$s, send %2$s, choose the bot\'s name and username and copy the token it gives.', 'needtome-ai-chat'), $botfather, '<code>/newbot</code>'),
            /* translators: 1: opening link tag, 2: closing link tag */
            sprintf(__('In %1$s«Channels»%2$s of the chat choose Telegram, paste the token and press «Connect». The webhook is set by itself.', 'needtome-ai-chat'), $panel, '</a>'),
            __('Write to your bot to check that the consultant answers.', 'needtome-ai-chat'),
        ], ''],
        'messenger' => ['Facebook Messenger', __('a Facebook page and a Meta app with Messenger', 'needtome-ai-chat'), [
            /* translators: %s: link to developers.facebook.com */
            sprintf(__('At %s create an app with the Messenger product and connect your Facebook page to it.', 'needtome-ai-chat'), $meta),
            /* translators: 1: opening link tag, 2: closing link tag */
            sprintf(__('In %1$s«Channels»%2$s of the chat choose Messenger and enter the page ID, the page access token and the app secret.', 'needtome-ai-chat'), $panel, '</a>'),
            $webhook,
        ], __('Meta lets a page answer only within 24 hours after the visitor\'s last message.', 'needtome-ai-chat')],
        'instagram' => ['Instagram Direct', __('a professional Instagram account linked to a Facebook page', 'needtome-ai-chat'), [
            /* translators: %s: link to developers.facebook.com */
            sprintf(__('At %s create an app with Instagram messaging and connect the Facebook page your Instagram account is linked to.', 'needtome-ai-chat'), $meta),
            /* translators: 1: opening link tag, 2: closing link tag */
            sprintf(__('In %1$s«Channels»%2$s of the chat choose Instagram and enter the page ID, the Instagram account ID, the access token and the app secret.', 'needtome-ai-chat'), $panel, '</a>'),
            $webhook,
        ], ''],
        'whatsapp' => ['WhatsApp Business', __('a number in the WhatsApp Business Cloud API', 'needtome-ai-chat'), [
            /* translators: %s: link to developers.facebook.com */
            sprintf(__('At %s create an app with WhatsApp, add your business number and create a permanent access token (a system user).', 'needtome-ai-chat'), $meta),
            /* translators: 1: opening link tag, 2: closing link tag */
            sprintf(__('In %1$s«Channels»%2$s of the chat choose WhatsApp and enter the phone number ID, the access token and the app secret.', 'needtome-ai-chat'), $panel, '</a>'),
            $webhook,
        ], __('Meta counts WhatsApp conversations; the «Channels» page shows how many are used.', 'needtome-ai-chat')],
        'vk' => ['VK', __('a VK community with messages turned on', 'needtome-ai-chat'), [
            __('In the community open «Manage → API usage» and create an access key with access to messages.', 'needtome-ai-chat'),
            /* translators: 1: opening link tag, 2: closing link tag */
            sprintf(__('In %1$s«Channels»%2$s of the chat choose VK and enter the community ID and the access key.', 'needtome-ai-chat'), $panel, '</a>'),
            __('Copy the callback URL, the confirmation code and the secret key from «Channels» into the community\'s Callback API settings and turn on the «Incoming message» event.', 'needtome-ai-chat'),
        ], ''],
    ];
}

/** Channel states of the linked chat from kra.name: type => connected|not_connected|error; empty when unknown. Cached 5 minutes. */
function aiworker_chat_channel_states(): array {
    $chat = (int) get_option(AIWORKER_CHAT_LINKED_OPTION, 0);
    if ($chat <= 0 || ! aiworker_chat_is_connected()) return [];
    $key = 'aiworker_chat_channels_'.$chat;
    $cached = get_transient($key);
    if (is_array($cached)) return $cached;
    $res = aiworker_chat_api('GET', '/plugin/channels', ['chat_id' => $chat], 8);
    $states = [];
    if ($res['code'] === 200) {
        $list = (array) ($res['body']['channels'] ?? $res['body']);
        foreach ($list as $k => $ch) {
            if (! is_array($ch)) continue;
            $type = (string) ($ch['type'] ?? $k);
            $status = (string) ($ch['status'] ?? '');
            if (in_array($status, ['connected', 'not_connected', 'error'], true)) $states[$type] = $status;
        }
    }
    // a kra.name without this call (404) or an error: no badges, asked again in 5 minutes
    set_transient($key, $states, 5 * MINUTE_IN_SECONDS);
    return $states;
}

function aiworker_chat_channels_block(): void {
    $guides = aiworker_chat_channel_guides();
    $states = aiworker_chat_channel_states();
    $allowed = ['a' => ['href' => [], 'target' => [], 'rel' => []], 'code' => []];
    $labels = ['connected' => __('connected', 'needtome-ai-chat'), 'not_connected' => __('not connected', 'needtome-ai-chat'), 'error' => __('needs attention', 'needtome-ai-chat')];
    ?>
    <div class="aiwc-channels">
      <h3><?php esc_html_e('Your online chat can be connected not only to the site', 'needtome-ai-chat'); ?></h3>
      <p class="description"><?php esc_html_e('The same consultant answers in messengers too: with the same knowledge base, and the leads arrive the same way as from the site. Available on every plan with online chats, the free one too.', 'needtome-ai-chat'); ?></p>
      <div class="aiwc-channels__tabs" role="tablist">
        <?php $first = true; foreach ($guides as $type => $g) : $state = $states[$type] ?? ''; ?>
          <button type="button" role="tab" class="aiwc-channels__tab<?php echo $first ? ' is-active' : ''; ?>" aria-selected="<?php echo $first ? 'true' : 'false'; ?>" aria-controls="aiwc-channel-<?php echo esc_attr($type); ?>"><?php echo esc_html($g[0]); ?><?php
            // marked only when it says something: connected, or connected with a problem (not connected is the usual case)
            if ($state === 'connected') : ?> <span class="aiwc-channels__ok" title="<?php echo esc_attr($labels[$state]); ?>">✓</span><?php elseif ($state === 'error') : ?> <span class="aiwc-channels__dot aiwc-channels__dot--error" title="<?php echo esc_attr($labels[$state]); ?>"></span><?php endif; ?></button>
        <?php $first = false; endforeach; ?>
      </div>
      <?php $first = true; foreach ($guides as $type => $g) : $state = $states[$type] ?? ''; ?>
        <div class="aiwc-channels__panel" id="aiwc-channel-<?php echo esc_attr($type); ?>" role="tabpanel"<?php echo $first ? '' : ' hidden'; ?>>
          <p><b><?php
            /* translators: %s: what the channel needs, e.g. «your own Telegram bot» */
            echo esc_html(sprintf(__('You need: %s.', 'needtome-ai-chat'), $g[1])); ?></b><?php if ($state === 'connected' || $state === 'error') : ?> <span class="aiwc-pill aiwc-pill--<?php echo $state === 'connected' ? 'ok' : ($state === 'error' ? 'err' : 'off'); ?>"><?php echo esc_html($labels[$state]); ?></span><?php endif; ?></p>
          <ol class="aiwc-steps">
            <?php foreach ($g[2] as $step) : ?><li><?php echo wp_kses($step, $allowed); ?></li><?php endforeach; ?>
          </ol>
          <?php if ($g[3] !== '') : ?><p class="description"><?php echo esc_html($g[3]); ?></p><?php endif; ?>
        </div>
      <?php $first = false; endforeach; ?>
    </div>
    <?php ob_start(); ?>
    (function () {
      var root = document.currentScript.previousElementSibling;
      root.querySelectorAll('.aiwc-channels__tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
          root.querySelectorAll('.aiwc-channels__tab').forEach(function (t) {
            var on = t === tab;
            t.classList.toggle('is-active', on); t.setAttribute('aria-selected', on ? 'true' : 'false');
            document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
          });
        });
      });
    })();
    <?php wp_print_inline_script_tag((string) ob_get_clean()); ?>
    <?php
}
