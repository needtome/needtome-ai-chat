<?php
/**
 * The «MCP» and «Telegram» sub-tabs of the setup wizard (step 1, while AI Worker is connected): other ways to set up the chat. MCP: from ChatGPT, claude.ai, Codex or Claude Code
 * through the AI Worker MCP server (steps as on kra.name/integrations/). Telegram: @KraNameBot, the setup wizard and the
 * owner's notifications in Telegram, connected with a one-time link. The chat's own messenger channels are in step 3.
 */

if (! defined('ABSPATH')) exit;

const AIWORKER_CHAT_MCP_URL = 'https://kra.name/app/api/mcp';

/** kra.name integrations page in the language of this settings screen. */
function aiworker_chat_integrations_url(string $anchor = ''): string {
    $locale = aiworker_chat_polylang_admin_locale() ?: get_user_locale();
    $prefix = strpos($locale, 'ru') === 0 ? '/ru' : (strpos($locale, 'uk') === 0 ? '/ua' : '');

    return 'https://kra.name'.$prefix.'/integrations/'.($anchor !== '' ? '#'.$anchor : '');
}

/** The chat's page in the kra.name panel (the chat linked here by «Connect», otherwise the list of chats). */
function aiworker_chat_panel_chat_url(string $tab = ''): string {
    $chat = (int) get_option(AIWORKER_CHAT_LINKED_OPTION, 0);
    return 'https://kra.name/app/panel/chat'.($chat > 0 ? '?c='.$chat : '').($tab !== '' ? '#'.$tab : '');
}

/** $embedded: inside the setup wizard's card in step 1 (MCP is one of its sub-tabs). */
function aiworker_chat_mcp_tab(bool $embedded = false): void {
    $host = (string) wp_parse_url(home_url('/'), PHP_URL_HOST);
    $link = ['a' => ['href' => [], 'target' => [], 'rel' => []], 'code' => [], 'b' => []];
    $url = '<code>'.esc_html(AIWORKER_CHAT_MCP_URL).'</code>';
    $panel = '<a href="https://kra.name/app/panel" target="_blank" rel="noopener">'.esc_html__('kra.name panel', 'needtome-ai-chat').'</a>';
    /* translators: %s: this site's domain */
    $examplePrompt = sprintf(__('Create an online chat for my site %s: a consultant persona with its role, the site\'s pages in the knowledge base, a greeting and quick questions.', 'needtome-ai-chat'), $host);
    $claudeCode = 'claude mcp add --transport http aiworker '.AIWORKER_CHAT_MCP_URL.' --header "Authorization: Bearer $AIWORKER_API_KEY"';
    $claudePlugin = "/plugin marketplace add needtome/aiworker-skills\n/plugin install aiworker@aiworker-skills\n/reload-plugins";
    // the one request the owner sends to their Codex or Claude Code, as on kra.name's integrations page
    $installPrompt = __('Install the AI Worker MCP server and the aiworker skill from the repository https://github.com/needtome/aiworker-skills. The API key is in the file ~/Downloads/aiworker-key.txt: move it into the environment variable AIWORKER_API_KEY without showing the key on the screen, then delete that file.', 'needtome-ai-chat');
        $codex = "[mcp_servers.aiworker]\nurl = \"".AIWORKER_CHAT_MCP_URL."\"\nbearer_token_env_var = \"AIWORKER_API_KEY\"";
    ?>
    <div class="aiwc-main">
    <section class="aiwc-card" id="aiwc-mcp">
      <h2><?php esc_html_e('Set up the chat from ChatGPT, Claude or Codex', 'needtome-ai-chat'); ?></h2>
      <p class="aiwc-lead"><?php esc_html_e('Instead of the kra.name panel, you can manage the chat from your own AI assistant: connect your AI Worker account to it over MCP and ask in plain words.', 'needtome-ai-chat'); ?></p>

      <p><b><?php esc_html_e('What the assistant can do with the chat:', 'needtome-ai-chat'); ?></b></p>
      <ul class="aiwc-mcp__list">
        <li><?php esc_html_e('create an online chat and its consultant: name, role, instructions;', 'needtome-ai-chat'); ?></li>
        <li><?php esc_html_e('fill the knowledge base: the site, single pages, Google Sheets and Docs, FAQ;', 'needtome-ai-chat'); ?></li>
        <li><?php esc_html_e('find what the knowledge base is missing and turn your short answers into FAQ;', 'needtome-ai-chat'); ?></li>
        <li><?php esc_html_e('write the greeting and the quick questions, change the chat\'s look and languages;', 'needtome-ai-chat'); ?></li>
        <li><?php esc_html_e('connect the chat to Telegram, Messenger, Instagram, WhatsApp or VK;', 'needtome-ai-chat'); ?></li>
        <li><?php esc_html_e('show the requests that visitors left in the chat.', 'needtome-ai-chat'); ?></li>
      </ul>
      <p class="description"><?php esc_html_e('The assistant first only proposes each change and shows what it will do; the change is applied after you agree in the conversation.', 'needtome-ai-chat'); ?></p>
      <p><?php esc_html_e('For example, ask:', 'needtome-ai-chat'); ?> <q><?php echo esc_html($examplePrompt); ?></q></p>

      <div class="aiwc-kb__types">
        <details class="aiwc-kb__type" open>
          <summary><b><?php esc_html_e('ChatGPT — in the browser', 'needtome-ai-chat'); ?></b></summary>
          <ol class="aiwc-steps aiwc-mcp__steps">
            <li><?php echo wp_kses(sprintf(/* translators: %s: link to chatgpt.com/plugins */ __('Open %s → «Add» → custom MCP server.', 'needtome-ai-chat'), '<a href="https://chatgpt.com/plugins" target="_blank" rel="noopener">chatgpt.com/plugins</a>'), $link); ?></li>
            <li><?php echo wp_kses(sprintf(/* translators: 1: server name, 2: server URL */ __('Name — %1$s, server URL — %2$s, authentication — OAuth. Leave «Advanced OAuth settings» as they are: ChatGPT discovers them itself.', 'needtome-ai-chat'), '<code>AI Worker</code>', $url), $link); ?></li>
            <li><?php esc_html_e('Tick «I understand and want to continue» and create the plugin.', 'needtome-ai-chat'); ?></li>
            <li><?php esc_html_e('The AI Worker page opens: sign in to the panel (password or Google) and press «Allow access».', 'needtome-ai-chat'); ?></li>
            <li><?php esc_html_e('Done: pick the AI Worker plugin in a chat and ask what you need.', 'needtome-ai-chat'); ?></li>
          </ol>
        </details>
        <details class="aiwc-kb__type">
          <summary><b><?php esc_html_e('Claude (claude.ai)', 'needtome-ai-chat'); ?></b></summary>
          <ol class="aiwc-steps aiwc-mcp__steps">
            <li><?php echo wp_kses(sprintf(/* translators: %s: server URL */ __('Open «Settings → Connectors → Add custom connector» and enter the address %s.', 'needtome-ai-chat'), $url), $link); ?></li>
            <li><?php esc_html_e('Sign in to AI Worker and press «Allow access».', 'needtome-ai-chat'); ?></li>
          </ol>
        </details>
        <details class="aiwc-kb__type">
          <summary><b><?php esc_html_e('Claude Code or Codex', 'needtome-ai-chat'); ?></b></summary>
          <ol class="aiwc-steps aiwc-mcp__steps">
            <li><?php echo wp_kses(sprintf(/* translators: %s: link to the kra.name panel */ __('In the %s open «Settings → Integrations», press «Issue key» and then «Download as a file»: the file aiworker-key.txt appears in your Downloads folder.', 'needtome-ai-chat'), $panel), $link); ?></li>
            <li><?php esc_html_e('Copy this and send it to your Codex or Claude Code:', 'needtome-ai-chat'); ?>
              <textarea class="aiwc-mcp__prompt" rows="5" readonly onclick="this.select();"><?php echo esc_textarea($installPrompt); ?></textarea>
              <button type="button" class="button aiwc-mcp__copy" data-copy-prev><?php esc_html_e('Copy', 'needtome-ai-chat'); ?></button></li>
          </ol>
          <p class="description"><?php esc_html_e('The AI saves the key itself and connects the MCP server and the skill. Restart Codex or Claude Code when it asks.', 'needtome-ai-chat'); ?></p>
          <details class="aiwc-mcp__manual">
            <summary><?php esc_html_e('Connect by hand', 'needtome-ai-chat'); ?></summary>
            <p><?php echo wp_kses(__('Keep the key in the environment variable <code>AIWORKER_API_KEY</code> of your computer, not in a file of a project.', 'needtome-ai-chat'), $link); ?></p>
            <p><?php esc_html_e('Claude Code: the plugin with the AI Worker skill and server', 'needtome-ai-chat'); ?></p>
            <textarea class="aiwc-mcp__prompt" rows="3" readonly onclick="this.select();"><?php echo esc_textarea($claudePlugin); ?></textarea>
            <p><?php esc_html_e('or only the MCP server:', 'needtome-ai-chat'); ?></p>
            <textarea class="aiwc-mcp__prompt" rows="2" readonly onclick="this.select();"><?php echo esc_textarea($claudeCode); ?></textarea>
            <p><?php echo wp_kses(__('Codex: add these lines to <code>~/.codex/config.toml</code> and restart Codex.', 'needtome-ai-chat'), $link); ?></p>
            <textarea class="aiwc-mcp__prompt" rows="3" readonly onclick="this.select();"><?php echo esc_textarea($codex); ?></textarea>
          </details>
          <p class="description"><?php esc_html_e('Do not paste the key into the chat with the AI.', 'needtome-ai-chat'); ?></p>
        </details>
      </div>

      <p class="description"><?php echo wp_kses(sprintf(
          /* translators: %s: link to the kra.name integrations page */
          __('You can disconnect any time in the panel: «Settings → Integrations». Full instructions with screenshots: %s.', 'needtome-ai-chat'),
          '<a href="'.esc_url(aiworker_chat_integrations_url('connect')).'" target="_blank" rel="noopener">'.esc_html__('kra.name integrations', 'needtome-ai-chat').'</a>'), $link); ?></p>
    </section>
    </div>
    <script>
    // «Copy»: the text field just before the button; the button says it was copied
    document.querySelectorAll('#aiwc-mcp [data-copy-prev]').forEach(function (b) {
      b.addEventListener('click', function () {
        var f = b.previousElementSibling, label = b.textContent, done = function () { b.textContent = <?php echo wp_json_encode(__('Copied', 'needtome-ai-chat')); ?>; setTimeout(function () { b.textContent = label; }, 1800); };
        if (navigator.clipboard && window.isSecureContext) navigator.clipboard.writeText(f.value).then(done, function () { f.select(); document.execCommand('copy'); done(); });
        else { f.select(); document.execCommand('copy'); done(); }
      });
    });
    </script>
    <?php
}

/** The panel's «Settings → Notifications» tab, where the owner connects @KraNameBot by hand. */
const AIWORKER_CHAT_PANEL_SETTINGS_URL = 'https://kra.name/app/panel/settings?tab=notifications';

/**
 * admin-post: «Connect Telegram» — a one-time @KraNameBot link (10 minutes) for the connected account; opening it in
 * Telegram links the account at once. A kra.name without this call sends the owner to the panel's own button.
 */
function aiworker_chat_tg_link(): void {
    if (! current_user_can('manage_options')) wp_die(esc_html__('You are not allowed to do this.', 'needtome-ai-chat'), 403);
    check_admin_referer('aiwc_tg_link');
    $res = aiworker_chat_api('GET', '/plugin/telegram-link');
    $url = (string) ($res['body']['url'] ?? '');
    if ($res['code'] === 200 && preg_match('#^https://t\.me/[A-Za-z0-9_]{5,64}(\?start=[A-Za-z0-9_-]{1,64})?$#', $url)) {
        wp_redirect($url); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Checked above: only a t.me bot link from kra.name.
        exit;
    }
    if (in_array($res['code'], [404, 405], true) && empty($res['body']['code'])) {
        wp_redirect(AIWORKER_CHAT_PANEL_SETTINGS_URL); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The fixed kra.name panel page.
        exit;
    }
    wp_safe_redirect(add_query_arg(['aiwc_sub' => 'telegram', 'aiw_notice' => $res['code'] === 401 ? 'failed' : 'tg_failed'], aiworker_chat_settings_url()));
    exit;
}
add_action('admin_post_aiwc_tg_link', 'aiworker_chat_tg_link');

/** $embedded: inside the setup wizard's card in step 1 (the page's notices are shown above it already). */
function aiworker_chat_telegram_tab(bool $embedded = false): void {
    $link = ['a' => ['href' => [], 'target' => [], 'rel' => []], 'code' => [], 'b' => []];
    $bot = '<a href="https://t.me/KraNameBot" target="_blank" rel="noopener">@KraNameBot</a>';
    $panel = '<a href="'.esc_url(AIWORKER_CHAT_PANEL_SETTINGS_URL).'" target="_blank" rel="noopener">'.esc_html__('kra.name panel', 'needtome-ai-chat').'</a>';
    if (! $embedded) aiworker_chat_assistant_notice();
    ?>
    <div class="aiwc-main">
    <section class="aiwc-card" id="aiwc-telegram">
      <h2><?php esc_html_e('The setup wizard in your Telegram', 'needtome-ai-chat'); ?></h2>
      <p class="aiwc-lead"><?php echo wp_kses(sprintf(/* translators: %s: @KraNameBot link */ __('%s is the same setup wizard as on the «Setup wizard» tab, in your Telegram. Write or say what to change in the online chat, and the bot does it: it shows each change with «Apply» and «Cancel» buttons.', 'needtome-ai-chat'), $bot), $link); ?></p>
      <p><b><?php esc_html_e('What you can ask:', 'needtome-ai-chat'); ?></b></p>
      <ul class="aiwc-mcp__list">
        <li><?php esc_html_e('change the consultant: name, role, instructions;', 'needtome-ai-chat'); ?></li>
        <li><?php esc_html_e('add pages, documents and FAQ to the knowledge base, fill what it is missing;', 'needtome-ai-chat'); ?></li>
        <li><?php esc_html_e('rewrite the greeting and the quick questions;', 'needtome-ai-chat'); ?></li>
        <li><?php esc_html_e('show the leads and the dialogs;', 'needtome-ai-chat'); ?></li>
        <li><?php esc_html_e('connect the chat to messengers.', 'needtome-ai-chat'); ?></li>
      </ul>
      <p><?php echo wp_kses(__('Commands: <code>/status</code> — what waits for your approval, <code>/account</code>, <code>/tariff</code>, <code>/new</code> — a new conversation, <code>/help</code>.', 'needtome-ai-chat'), $link); ?></p>
      <p><?php esc_html_e('The same bot sends you notifications: new leads, requests to talk to a person, questions the consultant could not answer, problems with channels and a daily digest.', 'needtome-ai-chat'); ?></p>

      <h3><?php esc_html_e('Connect', 'needtome-ai-chat'); ?></h3>
      <?php if (aiworker_chat_is_connected()) : ?>
        <p><a class="button button-primary" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=aiwc_tg_link'), 'aiwc_tg_link')); ?>" target="_blank" rel="noopener"><?php esc_html_e('Connect Telegram', 'needtome-ai-chat'); ?></a></p>
        <p class="description"><?php esc_html_e('Telegram opens the bot: press «Start», and your AI Worker account is connected at once. The link works once, for 10 minutes.', 'needtome-ai-chat'); ?></p>
      <?php else : ?>
        <p><?php esc_html_e('Sign in to AI Worker here, and the button gives you a link that connects the bot at once.', 'needtome-ai-chat'); ?></p>
        <p><?php echo wp_kses(aiworker_chat_signin_link('telegram', __('Sign in to AI Worker', 'needtome-ai-chat')), aiworker_chat_admin_html_allowed()); ?></p>
      <?php endif; ?>
      <p class="description"><?php echo wp_kses(sprintf(/* translators: %s: link to the kra.name panel settings */ __('Or in the %s: «Settings → Notifications» → «Connect Telegram».', 'needtome-ai-chat'), $panel), $link); ?></p>
    </section>
    </div>
    <?php
}
