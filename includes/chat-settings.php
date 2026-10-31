<?php
/**
 * Step «Consultant and chat texts» for a chat linked through AI Worker: the same values as «Online chat → Settings» in
 * the kra.name panel (GET/PATCH /plugin/chat, the panel's own validation). Read afresh on every page load, so a change
 * made at kra.name shows here; saved straight to kra.name with the version it was read at, so a newer change there is
 * not overwritten (409 «stale»). The fields have no name: the plugin's own settings form does not send them.
 */

if (! defined('ABSPATH')) exit;

/** The linked chat's settings at kra.name, or null when they cannot be read. */
function aiworker_chat_remote_chat(): ?array {
    static $data = false;   // read once per page: several blocks show parts of it
    if ($data !== false) return $data;
    $chat = (int) get_option(AIWORKER_CHAT_LINKED_OPTION, 0);
    if ($chat <= 0 || ! aiworker_chat_is_connected()) return $data = null;
    $res = aiworker_chat_api('GET', '/plugin/chat', ['chat_id' => $chat, 'lang' => aiworker_chat_wizard_lang()], 10);
    return $data = $res['code'] === 200 && is_array($res['body']['chat'] ?? null) ? $res['body'] : null;
}

/** AJAX: save the changed fields to kra.name. */
function aiworker_chat_remote_chat_save(): void {
    if (! current_user_can('manage_options')) wp_send_json(['code' => 403, 'body' => []], 403);
    check_ajax_referer('aiwc_assistant', 'nonce');
    $chat = (int) get_option(AIWORKER_CHAT_LINKED_OPTION, 0);
    $raw = json_decode(isset($_POST['chat']) ? (string) wp_unslash($_POST['chat']) : '', true); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, each value is sanitized below.
    if ($chat <= 0 || ! is_array($raw)) wp_send_json(['code' => 422, 'body' => []]);
    $out = [];
    if (isset($raw['persona_id'])) $out['persona_id'] = absint($raw['persona_id']);
    if (isset($raw['default_lang'])) $out['default_lang'] = sanitize_key((string) $raw['default_lang']);
    foreach (['leads_enabled', 'use_company_knowledge', 'ai_notice', 'greet_popup'] as $k) if (isset($raw[$k])) $out[$k] = (bool) $raw[$k];
    if (isset($raw['knowledge_mode'])) $out['knowledge_mode'] = sanitize_key((string) $raw['knowledge_mode']);
    foreach ((array) ($raw['texts'] ?? []) as $lang => $texts) {
        $lang = sanitize_key((string) $lang);
        if ($lang === '' || ! is_array($texts)) continue;
        foreach (['title', 'name', 'tab_label'] as $k) if (isset($texts[$k])) $out['texts'][$lang][$k] = sanitize_text_field((string) $texts[$k]);
        if (isset($texts['greeting'])) $out['texts'][$lang]['greeting'] = sanitize_textarea_field((string) $texts['greeting']);
        if (isset($texts['suggestions'])) $out['texts'][$lang]['suggestions'] = array_values(array_filter(array_map('sanitize_text_field', array_map('strval', (array) $texts['suggestions'])), 'strlen'));
    }
    // lead delivery: the channels chosen and their fields; an empty secret keeps the saved one at kra.name
    if (isset($raw['leads_delivery']) && is_array($raw['leads_delivery'])) {
        $ld = ['enabled' => ! empty($raw['leads_delivery']['enabled']), 'channels' => []];
        foreach ((array) ($raw['leads_delivery']['channels'] ?? []) as $type => $ch) {
            if (! is_array($ch)) continue;
            $fields = [];
            foreach ((array) ($ch['fields'] ?? []) as $k => $v) $fields[sanitize_key((string) $k)] = sanitize_text_field((string) $v);
            $ld['channels'][sanitize_key((string) $type)] = ['selected' => ! empty($ch['selected']), 'fields' => (object) $fields];
        }
        $out['leads_delivery'] = $ld;
    }
    $updated = isset($_POST['updated_at']) ? sanitize_text_field(wp_unslash((string) $_POST['updated_at'])) : '';
    $delivery = $out['leads_delivery'] ?? null;
    unset($out['leads_delivery']);
    $result = aiworker_chat_api('PATCH', '/plugin/chat?chat_id='.$chat.'&lang='.aiworker_chat_wizard_lang(), ['chat' => (object) $out, 'updated_at' => $updated] + ($delivery ? ['leads_delivery' => $delivery] : []), 20);
    // the consultant's name and the preview come from the key check and the account's chats: read them again
    if ($result['code'] === 200) { delete_transient('aiworker_chat_check'); aiworker_chat_forget_me(); }
    wp_send_json(['code' => $result['code'], 'body' => $result['body'], 'error' => $result['error'] !== '' ? __('kra.name cannot be reached from this site.', 'aiworker-chat') : '']);
}
add_action('wp_ajax_aiwc_chat_save', 'aiworker_chat_remote_chat_save');

/** AJAX: «Test delivery» (a test lead to every saved channel) and «Translate» (the empty fields of the other languages). */
function aiworker_chat_remote_chat_tool(): void {
    if (! current_user_can('manage_options')) wp_send_json(['code' => 403, 'body' => []], 403);
    check_ajax_referer('aiwc_assistant', 'nonce');
    $chat = (int) get_option(AIWORKER_CHAT_LINKED_OPTION, 0);
    $q = '?chat_id='.$chat.'&lang='.aiworker_chat_wizard_lang();
    $op = isset($_POST['op']) ? sanitize_key(wp_unslash((string) $_POST['op'])) : '';
    if ($op === 'test') $result = aiworker_chat_api('POST', '/plugin/chat/leads/test'.$q, [], 60);
    elseif ($op === 'translate') $result = aiworker_chat_api('POST', '/plugin/chat/translate'.$q, ['source_language' => isset($_POST['source']) ? sanitize_key(wp_unslash((string) $_POST['source'])) : ''], 90);
    else wp_send_json(['code' => 400, 'body' => []], 400);
    wp_send_json(['code' => $result['code'], 'body' => $result['body'], 'error' => $result['error'] !== '' ? __('kra.name cannot be reached from this site.', 'aiworker-chat') : '']);
}
add_action('wp_ajax_aiwc_chat_tool', 'aiworker_chat_remote_chat_tool');

/** «What the consultant answers from» on the Knowledge base tab: the same block with only that row. */
function aiworker_chat_answer_mode_block(): void {
    aiworker_chat_chat_step(0, ['knowledge'], 'aiwc-chatset-kb');
}

/**
 * The step itself, or the parts of it ($parts: persona, texts, leads, knowledge) shown elsewhere; nothing when the
 * settings cannot be read (the kra.name panel still has them). $number 0: a block without a step number.
 */
function aiworker_chat_chat_step(int $number, array $parts = ['persona', 'texts', 'leads'], string $id = 'aiwc-chatset'): void {
    static $script = false;
    $has = static function (string $part) use ($parts): bool { return in_array($part, $parts, true); };
    $data = aiworker_chat_remote_chat();
    if (! $data) return;
    $chat = $data['chat'];
    $limits = (array) ($data['limits'] ?? []);
    $langs = array_values(array_filter(array_map('strval', (array) ($chat['languages'] ?? [])), 'strlen'));
    $names = ['ru' => 'Русский', 'en' => 'English', 'uk' => 'Українська'];
    $max = static function (string $k, int $d) use ($limits): int { return (int) ($limits[$k] ?? $d); };
    $panel = (string) ($data['panel_url'] ?? '');
    ?>
    <section class="aiwc-card aiwc-chatset<?php echo count($parts) === 1 ? ' aiwc-chatset--single' : ''; ?>" id="<?php echo esc_attr($id); ?>" data-updated="<?php echo esc_attr((string) ($data['updated_at'] ?? '')); ?>">
      <?php if ($has('knowledge') && count($parts) === 1) : ?>
      <h2><?php esc_html_e('What the consultant answers from', 'aiworker-chat'); ?></h2>
      <?php else : ?>
      <h2><?php if ($number > 0) : ?><span class="aiwc-num"><?php echo esc_html((string) $number); ?></span><?php endif; ?><?php esc_html_e('Consultant and chat texts', 'aiworker-chat'); ?></h2>
      <?php endif; ?>
      <p class="aiwc-lead"><?php esc_html_e('The same settings as in the chat\'s settings at kra.name: a change saved here is there at once, and a change made there shows here.', 'aiworker-chat'); ?></p>
      <div class="aiwc-chatset__notice notice inline" data-cs="notice" hidden><p></p></div>

      <?php if ($has('persona') && count((array) ($data['personas'] ?? [])) > 1) : // with one consultant there is nothing to choose ?>
      <div class="aiwc-row"><div class="aiwc-row__label"><label for="aiwc-cs-persona"><?php esc_html_e('Consultant', 'aiworker-chat'); ?></label></div><div class="aiwc-row__control">
        <select id="aiwc-cs-persona" data-cs-field="persona_id">
          <?php foreach ((array) ($data['personas'] ?? []) as $p) : ?>
            <option value="<?php echo esc_attr((string) (int) $p['id']); ?>"<?php selected((int) $p['id'], (int) ($chat['persona_id'] ?? 0)); ?>><?php echo esc_html(trim((string) $p['name'].((string) ($p['title'] ?? '') !== '' ? ' — '.$p['title'] : ''))); ?></option>
          <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e('The consultant\'s role and instructions are edited in the kra.name panel or by the setup wizard.', 'aiworker-chat'); ?></p>
      </div></div>
      <?php endif; ?>
      <?php if ($has('texts')) : ?>

      <div class="aiwc-row"><div class="aiwc-row__label"><?php esc_html_e('Texts by language', 'aiworker-chat'); ?></div><div class="aiwc-row__control">
        <?php if (count($langs) > 1) : ?>
        <div class="aiwc-chatset__langs" role="tablist">
          <?php foreach ($langs as $i => $l) : ?><button type="button" role="tab" class="aiwc-chatset__lang<?php echo $i === 0 ? ' is-active' : ''; ?>" data-cs-lang="<?php echo esc_attr($l); ?>"><?php echo esc_html($names[$l] ?? strtoupper($l)); ?></button><?php endforeach; ?>
          <span class="aiwc-chatset__translate"><?php esc_html_e('Fill the empty fields of the other languages by translating from', 'aiworker-chat'); ?>
            <select data-cs="source"><?php foreach ($langs as $l) : ?><option value="<?php echo esc_attr($l); ?>"<?php selected($l, (string) ($chat['default_lang'] ?? '')); ?>><?php echo esc_html($names[$l] ?? strtoupper($l)); ?></option><?php endforeach; ?></select>
            <button type="button" class="button" data-cs="translate"><?php esc_html_e('Translate', 'aiworker-chat'); ?></button></span>
        </div>
        <?php endif; ?>
        <?php foreach ($langs as $i => $l) : $tx = (array) ($chat['texts'][$l] ?? []); $sugg = array_values((array) ($tx['suggestions'] ?? [])); ?>
        <div class="aiwc-chatset__texts" data-cs-pane="<?php echo esc_attr($l); ?>"<?php echo $i === 0 ? '' : ' hidden'; ?>>
          <label class="aiwc-chatset__label" for="aiwc-cs-<?php echo esc_attr($l); ?>-greeting"><?php esc_html_e('Greeting', 'aiworker-chat'); ?></label>
          <textarea id="aiwc-cs-<?php echo esc_attr($l); ?>-greeting" rows="3" maxlength="<?php echo esc_attr((string) $max('greeting_max', 300)); ?>" data-cs-field="texts.<?php echo esc_attr($l); ?>.greeting"><?php echo esc_textarea((string) ($tx['greeting'] ?? '')); ?></textarea>
          <span class="aiwc-chatset__err" data-cs-error="texts.<?php echo esc_attr($l); ?>.greeting" hidden></span>
          <span class="aiwc-chatset__label"><?php esc_html_e('Quick questions', 'aiworker-chat'); ?></span>
          <p class="description"><?php esc_html_e('Buttons under the greeting; a visitor asks with one click. Empty lines are not shown.', 'aiworker-chat'); ?></p>
          <?php for ($k = 0; $k < $max('suggestions_max', 4); $k++) : ?>
            <input type="text" class="regular-text aiwc-chatset__sugg" value="<?php echo esc_attr((string) ($sugg[$k] ?? '')); ?>" data-cs-sugg="<?php echo esc_attr($l); ?>" aria-label="<?php echo esc_attr(sprintf(/* translators: %d: number of the quick question */ __('Quick question %d', 'aiworker-chat'), $k + 1)); ?>">
          <?php endfor; ?>
          <span class="aiwc-chatset__err" data-cs-error="texts.<?php echo esc_attr($l); ?>.suggestions" hidden></span>
          <div class="aiwc-chatset__trio">
            <label><span class="aiwc-chatset__label"><?php esc_html_e('Name in the chat header', 'aiworker-chat'); ?></span><input type="text" maxlength="<?php echo esc_attr((string) $max('name_max', 60)); ?>" value="<?php echo esc_attr((string) ($tx['name'] ?? '')); ?>" placeholder="<?php echo esc_attr((string) ($tx['name_fallback'] ?? '') !== '' ? (string) $tx['name_fallback'] : __('The consultant\'s name', 'aiworker-chat')); ?>" data-cs-field="texts.<?php echo esc_attr($l); ?>.name"></label>
            <label><span class="aiwc-chatset__label"><?php esc_html_e('Caption under the name', 'aiworker-chat'); ?></span><input type="text" maxlength="<?php echo esc_attr((string) $max('title_max', 80)); ?>" value="<?php echo esc_attr((string) ($tx['title'] ?? '')); ?>" placeholder="<?php echo esc_attr((string) ($tx['title_fallback'] ?? '') !== '' ? (string) $tx['title_fallback'] : __('The consultant\'s role', 'aiworker-chat')); ?>" data-cs-field="texts.<?php echo esc_attr($l); ?>.title"></label>
            <label><span class="aiwc-chatset__label"><?php esc_html_e('Side tab label', 'aiworker-chat'); ?></span><input type="text" maxlength="<?php echo esc_attr((string) $max('tab_label_max', 60)); ?>" value="<?php echo esc_attr((string) ($tx['tab_label'] ?? '')); ?>" placeholder="<?php echo esc_attr((string) ($tx['name_fallback'] ?? '') !== '' ? (string) $tx['name_fallback'] : __('The consultant\'s name', 'aiworker-chat')); ?>" data-cs-field="texts.<?php echo esc_attr($l); ?>.tab_label"></label>
          </div>
          <p class="description"><?php esc_html_e('An empty field shows what is greyed out in it: the consultant\'s own name and role.', 'aiworker-chat'); ?></p>
        </div>
        <?php endforeach; ?>
        <?php if (count($langs) > 1) : ?>
        <p class="aiwc-chatset__default"><label><?php esc_html_e('Default language', 'aiworker-chat'); ?>
          <select data-cs-field="default_lang"><?php foreach ($langs as $l) : ?><option value="<?php echo esc_attr($l); ?>"<?php selected($l, (string) ($chat['default_lang'] ?? '')); ?>><?php echo esc_html($names[$l] ?? strtoupper($l)); ?></option><?php endforeach; ?></select></label>
          <span class="description"><?php esc_html_e('when the page\'s language is not one of the chat\'s', 'aiworker-chat'); ?></span></p>
        <?php endif; ?>
      </div></div>
      <div class="aiwc-row"><div class="aiwc-row__label"><?php esc_html_e('In the chat', 'aiworker-chat'); ?></div><div class="aiwc-row__control">
        <label class="aiwc-check"><input type="checkbox" data-cs-field="ai_notice"<?php checked(($chat['ai_notice'] ?? true) !== false); ?>> <span><?php esc_html_e('Say that an AI answers and its answers may be inaccurate', 'aiworker-chat'); ?></span></label>
        <p class="description"><?php esc_html_e('A short note under the chat\'s input: an AI assistant answers, answers may be inaccurate, check what matters with the staff. It lowers the risk of claims over a wrong answer; we recommend keeping it on.', 'aiworker-chat'); ?></p>
        <label class="aiwc-check"><input type="checkbox" data-cs-field="greet_popup"<?php checked(! empty($chat['greet_popup'])); ?>> <span><?php esc_html_e('Show the AI\'s greeting when a page opens', 'aiworker-chat'); ?></span></label>
        <p class="description"><?php esc_html_e('The consultant\'s greeting, the same as at the start of the dialog, appears next to the chat button for a few seconds. Once per visitor session; not shown when the visitor has already written.', 'aiworker-chat'); ?></p>
      </div></div>

      <?php endif; ?>
      <?php if ($has('leads')) : ?>
      <div class="aiwc-row"><div class="aiwc-row__label"><?php esc_html_e('Leads', 'aiworker-chat'); ?></div><div class="aiwc-row__control">
        <label class="aiwc-check"><input type="checkbox" data-cs-field="leads_enabled"<?php checked(! empty($chat['leads_enabled'])); ?>> <span><?php esc_html_e('Collect visitors\' contacts: name, phone or email and what they need', 'aiworker-chat'); ?></span></label>
        <p class="description"><?php esc_html_e('When a visitor wants to get in touch, order or book, the consultant asks for a contact and passes the lead on.', 'aiworker-chat'); ?></p>
        <?php $ld = is_array($data['leads_delivery'] ?? null) ? $data['leads_delivery'] : null; if ($ld) : ?>
        <div class="aiwc-ld" data-cs="delivery"<?php echo empty($chat['leads_enabled']) ? ' hidden' : ''; ?>>
          <p class="aiwc-ld__head"><label class="aiwc-check"><input type="checkbox" data-ld="enabled"<?php checked(! empty($ld['enabled'])); ?>> <b><?php esc_html_e('Send leads', 'aiworker-chat'); ?></b></label>
            <span class="description"><?php echo esc_html((string) ($ld['current'] ?? '')); ?></span></p>
          <div class="aiwc-ld__chips">
            <?php foreach ((array) ($ld['channels'] ?? []) as $chn) : if (! is_array($chn)) continue; ?>
              <label class="aiwc-ld__chip"><input type="checkbox" data-ld-channel="<?php echo esc_attr((string) $chn['type']); ?>"<?php checked(! empty($chn['selected'])); ?>> <?php echo esc_html((string) $chn['label']); ?></label>
            <?php endforeach; ?>
          </div>
          <?php
          // an empty email field sends to the owner's email: kra.name names it in the fallback line, the field shows it greyed out
          $ownerEmail = preg_match('/[^\s:<>]+@[^\s<>]+\.[a-z]{2,}/i', (string) ($ld['fallback'] ?? ''), $em) ? sanitize_email($em[0]) : '';
          ?>
          <?php foreach ((array) ($ld['channels'] ?? []) as $chn) : if (! is_array($chn)) continue; $type = (string) $chn['type']; $connect = (string) ($chn['connect_url'] ?? ''); ?>
            <div class="aiwc-ld__fields" data-ld-fields="<?php echo esc_attr($type); ?>"<?php echo empty($chn['selected']) ? ' hidden' : ''; ?>>
              <?php foreach ((array) ($chn['fields'] ?? []) as $f) : if (! is_array($f)) continue; $key = (string) $f['key']; $ft = (string) ($f['type'] ?? 'text');
                // a list with one choice is no choice: it is not shown, its value is still sent
                $opts = (array) ($f['options'] ?? []);
                if ($ft === 'select' && count($opts) <= 1) : ?>
                <input type="hidden" data-ld-field="<?php echo esc_attr($key); ?>" value="<?php echo esc_attr((string) ($f['value'] ?? ($opts[0]['value'] ?? ''))); ?>">
                <?php continue; endif; ?>
                <label class="aiwc-ld__field"><span><?php echo esc_html((string) ($f['label'] ?? $key)); ?></span>
                  <?php if ($ft === 'select') : ?>
                    <select data-ld-field="<?php echo esc_attr($key); ?>"><?php foreach ((array) ($f['options'] ?? []) as $o) : ?><option value="<?php echo esc_attr((string) $o['value']); ?>"<?php selected((string) $o['value'], (string) ($f['value'] ?? '')); ?>><?php echo esc_html((string) $o['label']); ?></option><?php endforeach; ?></select>
                  <?php else : ?>
                    <input type="<?php echo esc_attr($ft === 'secret' ? 'password' : ($ft === 'email' ? 'email' : ($ft === 'url' ? 'url' : 'text'))); ?>" class="regular-text" autocomplete="<?php echo $ft === 'secret' ? 'new-password' : 'off'; ?>" data-ld-field="<?php echo esc_attr($key); ?>" data-ld-secret="<?php echo $ft === 'secret' ? '1' : '0'; ?>" value="<?php echo esc_attr($ft === 'secret' ? '' : (string) ($f['value'] ?? '')); ?>"<?php if ($ft === 'secret' && ! empty($f['set'])) : ?> placeholder="<?php esc_attr_e('Saved — leave empty to keep it', 'aiworker-chat'); ?>"<?php elseif ((string) ($f['placeholder'] ?? '') !== '') : ?> placeholder="<?php echo esc_attr((string) $f['placeholder']); ?>"<?php elseif ($ft === 'email' && $ownerEmail !== '') : ?> placeholder="<?php echo esc_attr($ownerEmail); ?>"<?php endif; ?>>
                  <?php endif; ?>
                  <?php if (! empty($f['hint'])) : ?><span class="description"><?php echo esc_html((string) $f['hint']); ?></span><?php endif; ?>
                  <span class="aiwc-chatset__err" data-cs-error="leads_delivery.<?php echo esc_attr($type.'.'.$key); ?>" hidden></span>
                </label>
              <?php endforeach; ?>
              <?php if ($connect !== '' && preg_match('#^https://kra\.name/#', $connect)) : ?><p><a class="button" href="<?php echo esc_url($connect); ?>" target="_blank" rel="noopener"><?php echo esc_html(sprintf(/* translators: %s: channel name */ __('Connect %s in the kra.name panel', 'aiworker-chat'), (string) $chn['label'])); ?></a></p><?php endif; ?>
            </div>
          <?php endforeach; ?>
          <p class="description"><?php echo esc_html((string) ($ld['fallback'] ?? '')); ?></p>
          <p><button type="button" class="button" data-cs="test"><?php esc_html_e('Test delivery', 'aiworker-chat'); ?></button> <span class="description"><?php esc_html_e('A short test message to every saved channel.', 'aiworker-chat'); ?></span></p>
          <ul class="aiwc-ld__results" data-cs="results" hidden></ul>
        </div>
        <?php endif; ?>
      </div></div>

      <?php endif; ?>
      <?php if ($has('knowledge')) : ?>
      <div class="aiwc-row"><div class="aiwc-row__label"><?php esc_html_e('What the consultant answers from', 'aiworker-chat'); ?></div><div class="aiwc-row__control">
        <?php foreach ((array) ($data['options']['knowledge_mode'] ?? []) as $opt) : ?>
          <label class="aiwc-check aiwc-chatset__radio"><input type="radio" name="<?php echo esc_attr($id); ?>_knowledge_mode" value="<?php echo esc_attr((string) $opt['value']); ?>" data-cs-field="knowledge_mode"<?php checked((string) $opt['value'], (string) ($chat['knowledge_mode'] ?? '')); ?>> <span><?php echo esc_html((string) $opt['label']); ?></span></label>
        <?php endforeach; ?>
        <p class="description"><?php esc_html_e('With general knowledge the consultant answers general questions on the topic too, but prices, terms and contacts come only from your knowledge base.', 'aiworker-chat'); ?></p>
        <label class="aiwc-check"><input type="checkbox" data-cs-field="use_company_knowledge"<?php checked(! empty($chat['use_company_knowledge'])); ?>> <span><?php esc_html_e('Use the company information from the kra.name panel', 'aiworker-chat'); ?></span></label>
      </div></div>
      <?php endif; ?>

      <p class="aiwc-chatset__actions">
        <span class="aiwc-chatset__status" data-cs="status" role="status" aria-live="polite"></span>
        <?php if (preg_match('#^https://kra\.name/#', $panel)) : ?><a class="aiwc-chatset__panel" href="<?php echo esc_url($panel); ?>" target="_blank" rel="noopener"><?php esc_html_e('All settings in the kra.name panel', 'aiworker-chat'); ?></a><?php endif; ?>
      </p>
    </section>
    <?php if ($script) return; $script = true; ?>
    <script>
    document.querySelectorAll('.aiwc-chatset').forEach(function (root) {
      // the wizard's script config (nonce, admin-ajax) is printed in the footer, after this block: read it on click
      var t = <?php echo wp_json_encode([
          'saving' => __('Saving…', 'aiworker-chat'), 'saved' => __('Saved to kra.name.', 'aiworker-chat'), 'nothing' => __('Nothing has changed.', 'aiworker-chat'),
          'stale' => __('The chat was changed at kra.name after this page was opened. Reload the page to see those changes, then make yours again.', 'aiworker-chat'),
          'invalid' => __('kra.name did not accept some values: see the fields marked below.', 'aiworker-chat'), 'failed' => __('Not saved: kra.name answered with an error. Try again a bit later.', 'aiworker-chat'),
          'reload' => __('Reload the page', 'aiworker-chat'),
          'testing' => __('Sending a test message…', 'aiworker-chat'), 'noChannels' => __('Delivery is off or no channel is saved yet.', 'aiworker-chat'),
          'translating' => __('Translating…', 'aiworker-chat'), 'translated' => __('The empty fields are filled with the translation. Check them and press «Save settings».', 'aiworker-chat'),
          'nothingToTranslate' => __('Nothing to translate: the fields of the other languages are filled already.', 'aiworker-chat'),
      ]); ?>;
      var updated = root.getAttribute('data-updated'), status = root.querySelector('[data-cs="status"]');
      var notice = root.querySelector('[data-cs="notice"]');
      // language tabs of the texts
      root.querySelectorAll('[data-cs-lang]').forEach(function (b) {
        b.addEventListener('click', function () {
          root.querySelectorAll('[data-cs-lang]').forEach(function (x) { x.classList.toggle('is-active', x === b); });
          root.querySelectorAll('[data-cs-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-cs-pane') !== b.getAttribute('data-cs-lang'); });
        });
      });
      function read() {
        var v = { texts: {} };
        root.querySelectorAll('[data-cs-field]').forEach(function (f) {
          var path = f.getAttribute('data-cs-field').split('.');
          var val = f.type === 'checkbox' ? f.checked : f.type === 'radio' ? (f.checked ? f.value : undefined) : f.value;
          if (val === undefined) return;
          if (path[0] === 'texts') { (v.texts[path[1]] = v.texts[path[1]] || {})[path[2]] = val; } else v[path[0]] = path[0] === 'persona_id' ? Number(val) : val;
        });
        var ld = root.querySelector('[data-cs="delivery"]');
        if (ld && !ld.hidden) {
          v.leads_delivery = { enabled: ld.querySelector('[data-ld="enabled"]').checked, channels: {} };
          ld.querySelectorAll('[data-ld-channel]').forEach(function (cb) {
            var type = cb.getAttribute('data-ld-channel'), fields = {};
            if (cb.checked) ld.querySelectorAll('[data-ld-fields="' + type + '"] [data-ld-field]').forEach(function (f) {
              if (f.getAttribute('data-ld-secret') === '1' && !f.value) return;   // an empty secret keeps the saved one
              fields[f.getAttribute('data-ld-field')] = f.value;
            });
            v.leads_delivery.channels[type] = { selected: cb.checked, fields: fields };
          });
        }
        root.querySelectorAll('[data-cs-sugg]').forEach(function (f) { var l = f.getAttribute('data-cs-sugg'); var tx = v.texts[l] = v.texts[l] || {}; (tx.suggestions = tx.suggestions || []); if (f.value.trim()) tx.suggestions.push(f.value.trim()); });
        return v;
      }
      // only what differs from what was read is sent, so a field changed at kra.name meanwhile is left alone
      function diff(a, b) {
        var out = {};
        Object.keys(a).forEach(function (k) {
          if (k === 'texts') {
            Object.keys(a.texts).forEach(function (l) { Object.keys(a.texts[l]).forEach(function (f) {
              if (JSON.stringify(a.texts[l][f]) !== JSON.stringify((b.texts[l] || {})[f])) { out.texts = out.texts || {}; (out.texts[l] = out.texts[l] || {})[f] = a.texts[l][f]; }
            }); });
          } else if (JSON.stringify(a[k]) !== JSON.stringify(b[k])) out[k] = a[k];
        });
        return out;
      }
      var initial = read();
      // where leads go matters only while leads are collected
      var collect = root.querySelector('[data-cs-field="leads_enabled"]'), delivery = root.querySelector('[data-cs="delivery"]');
      if (collect && delivery) collect.addEventListener('change', function () { delivery.hidden = !collect.checked; });
      // a ticked channel shows its fields
      root.querySelectorAll('[data-ld-channel]').forEach(function (cb) {
        cb.addEventListener('change', function () { var box = root.querySelector('[data-ld-fields="' + cb.getAttribute('data-ld-channel') + '"]'); if (box) box.hidden = !cb.checked; });
      });
      function tool(op, extra) {
        var c = window.aiwcAssistant || {}, data = new FormData();
        data.append('action', 'aiwc_chat_tool'); data.append('nonce', c.nonce); data.append('op', op); if (c.lang) data.append('lang', c.lang);
        Object.keys(extra || {}).forEach(function (k) { data.append(k, extra[k]); });
        return fetch(c.ajax, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); }).catch(function () { return { code: 0, body: {} }; });
      }
      var test = root.querySelector('[data-cs="test"]');
      if (test) test.addEventListener('click', function () {
        var list = root.querySelector('[data-cs="results"]'); test.disabled = true; list.hidden = false; list.textContent = t.testing;
        tool('test').then(function (res) {
          test.disabled = false; list.textContent = '';
          var results = (res.body || {}).results;
          if (res.code !== 200 || !Array.isArray(results)) { list.textContent = (res.body && (res.body.message || res.body.error)) || res.error || t.failed; return; }
          if (!results.length) { list.textContent = t.noChannels; return; }
          results.forEach(function (r) { var li = document.createElement('li'); li.className = r.ok ? 'is-ok' : 'is-err'; li.textContent = (r.ok ? '✓ ' : '✗ ') + r.type + ': ' + (r.message || ''); list.appendChild(li); });
        });
      });
      var tr = root.querySelector('[data-cs="translate"]');
      if (tr) tr.addEventListener('click', function () {
        tr.disabled = true; status.textContent = t.translating;
        tool('translate', { source: root.querySelector('[data-cs="source"]').value }).then(function (res) {
          tr.disabled = false; status.textContent = '';
          var all = (res.body || {}).translations;
          if (res.code !== 200 || !all) { say('error', (res.body && (res.body.message || res.body.error)) || res.error || t.failed); return; }
          var filled = 0;
          // only empty fields are filled; nothing is saved until «Save settings»
          Object.keys(all).forEach(function (l) {
            var tx = all[l] || {};
            ['name', 'title', 'tab_label', 'greeting'].forEach(function (k) { var f = root.querySelector('[data-cs-field="texts.' + l + '.' + k + '"]'); if (f && !f.value && tx[k]) { f.value = tx[k]; filled++; } });
            if (Array.isArray(tx.suggestions)) { var queue = tx.suggestions.slice(); root.querySelectorAll('[data-cs-sugg="' + l + '"]').forEach(function (f) { if (!f.value && queue.length) { f.value = queue.shift(); filled++; } }); }
          });
          say(filled ? 'success' : 'info', filled ? t.translated : t.nothingToTranslate);
        });
      });
      function say(type, text, reload) {
        notice.className = 'aiwc-chatset__notice notice inline notice-' + type; notice.hidden = !text;
        var p = notice.firstChild; p.textContent = text || '';
        if (reload) { var a = document.createElement('a'); a.href = location.href; a.textContent = ' ' + t.reload; p.appendChild(a); }
      }
      // «Save settings» of the page saves this block to kra.name first (aiwcSaveAll below); resolves false on an error
      root.aiwcSave = function () {
        root.querySelectorAll('[data-cs-error]').forEach(function (e) { e.hidden = true; });
        var now = read(), changes = diff(now, initial);
        if (!Object.keys(changes).length) return Promise.resolve(true);
        var c = window.aiwcAssistant || {};
        status.textContent = t.saving; say('', '');
        var data = new FormData();
        data.append('action', 'aiwc_chat_save'); data.append('nonce', c.nonce); if (c.lang) data.append('lang', c.lang); data.append('chat', JSON.stringify(changes)); data.append('updated_at', updated || '');
        return fetch(c.ajax, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); }).catch(function () { return { code: 0, body: {} }; }).then(function (res) {
          status.textContent = '';
          var body = res.body || {};
          if (res.code === 200) { updated = body.updated_at || updated; initial = now; return true; }
          if (res.code === 409) { say('warning', t.stale, true); return false; }
          if (res.code === 422 && body.errors) {
            say('error', t.invalid);
            Object.keys(body.errors).forEach(function (k) {
              var e = root.querySelector('[data-cs-error="' + k.replace(/\.\d+$/, '') + '"]'); if (!e) return;
              e.textContent = [].concat(body.errors[k]).join(' '); e.hidden = false;
            });
            return false;
          }
          say('error', res.error || (body.message ? String(body.message) : t.failed));
          return false;
        });
      };
    });
    // One button saves everything: «Save settings» first sends what changed in these blocks to kra.name, then the
    // plugin's own settings; an error at kra.name stops the submit and is shown at its block.
    window.aiwcSaveFailed = <?php echo wp_json_encode(__('Not saved: kra.name did not accept the changes of a block above (see its message).', 'aiworker-chat')); ?>;
    if (!window.aiwcSaveHooked) {
      window.aiwcSaveHooked = true;
      document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.matches || !form.matches('form[action$="options.php"]') || form.dataset.aiwcSaved) return;
        var blocks = Array.prototype.filter.call(document.querySelectorAll('.aiwc-chatset'), function (b) { return typeof b.aiwcSave === 'function'; });
        if (!blocks.length) return;
        e.preventDefault();
        var button = form.querySelector('[type="submit"]'); if (button) button.disabled = true;
        var said = form.querySelector('.aiwc-save__error');
        if (said) said.remove();
        var stop = function (bad) {
          if (button) button.disabled = false;
          var msg = document.createElement('p'); msg.className = 'aiwc-save__error'; msg.setAttribute('role', 'alert'); msg.textContent = window.aiwcSaveFailed || '';
          var box = form.querySelector('.aiwc-save'); if (box) box.appendChild(msg);
          var notice = bad && bad.querySelector('[data-cs="notice"]:not([hidden]), .aiwc-chatset__err:not([hidden])');
          (notice || bad || msg).scrollIntoView({ behavior: 'smooth', block: 'center' });
        };
        Promise.all(blocks.map(function (b) { return b.aiwcSave(); })).then(function (ok) {
          if (ok.indexOf(false) !== -1) { stop(blocks[ok.indexOf(false)]); return; }
          form.dataset.aiwcSaved = '1';
          HTMLFormElement.prototype.submit.call(form);   // the button is named «submit» and hides form.submit()
        }).catch(function () { stop(null); });
      }, true);
    }
    </script>
    <?php
}
