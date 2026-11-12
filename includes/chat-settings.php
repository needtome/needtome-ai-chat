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
    static $all = [];
    $chat = aiworker_chat_linked_id();
    $cache = $chat.':'.aiworker_chat_wizard_lang();
    if (array_key_exists($cache, $all)) return $all[$cache];
    if ($chat <= 0 || ! aiworker_chat_is_connected()) return $all[$cache] = null;
    $res = aiworker_chat_api('GET', '/plugin/chat', ['chat_id' => $chat, 'lang' => aiworker_chat_wizard_lang()], 10);
    if ($res['code'] === 200 && is_array($res['body']['chat'] ?? null)) aiworker_chat_remember_remote($res['body']['chat']);
    return $all[$cache] = $res['code'] === 200 && is_array($res['body']['chat'] ?? null) ? $res['body'] : null;
}

/** AJAX: save the changed fields to kra.name. */
function aiworker_chat_remote_chat_save(): void {
    if (! current_user_can('manage_options')) wp_send_json(['code' => 403, 'body' => []], 403);
    check_ajax_referer('aiworker_chat_assistant', 'nonce');
    $chat = aiworker_chat_linked_id();
    $raw = json_decode(isset($_POST['chat']) ? (string) wp_unslash($_POST['chat']) : '', true); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, each value is sanitized below.
    if ($chat <= 0 || ! is_array($raw)) wp_send_json(['code' => 422, 'body' => []]);
    $out = [];
    if (isset($raw['persona_id'])) $out['persona_id'] = absint($raw['persona_id']);
    if (isset($raw['default_lang'])) $out['default_lang'] = sanitize_key((string) $raw['default_lang']);
    foreach (['leads_enabled', 'use_company_knowledge', 'ai_notice', 'greet_popup'] as $k) if (isset($raw[$k])) $out[$k] = (bool) $raw[$k];
    if (isset($raw['knowledge_mode'])) $out['knowledge_mode'] = sanitize_key((string) $raw['knowledge_mode']);
    if (isset($raw['launcher_icon'])) $out['launcher_icon'] = sanitize_key((string) $raw['launcher_icon']);
    foreach ((array) ($raw['texts'] ?? []) as $lang => $texts) {
        $lang = sanitize_key((string) $lang);
        if ($lang === '' || ! is_array($texts)) continue;
        foreach (['title', 'name', 'tab_label'] as $k) if (isset($texts[$k])) $out['texts'][$lang][$k] = sanitize_text_field((string) $texts[$k]);
        if (isset($texts['greeting'])) $out['texts'][$lang]['greeting'] = sanitize_textarea_field((string) $texts['greeting']);
        if (isset($texts['suggestions'])) $out['texts'][$lang]['suggestions'] = array_slice(array_values(array_filter(array_map('sanitize_text_field', array_map('strval', (array) $texts['suggestions'])), 'strlen')), 0, 10);
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
    if ($result['code'] === 200) { aiworker_chat_remember_remote((array) ($result['body']['chat'] ?? [])); delete_transient(aiworker_chat_option_name('aiworker_chat_check')); aiworker_chat_forget_me(); }
    wp_send_json(['code' => $result['code'], 'body' => $result['body'], 'error' => $result['error'] !== '' ? __('kra.name cannot be reached from this site.', 'needtome-ai-chat') : '']);
}
add_action('wp_ajax_aiworker_chat_chat_save', 'aiworker_chat_remote_chat_save');

/** AJAX: «Test delivery» (a test lead to every saved channel) and «Translate» (the empty fields of the other languages). */
function aiworker_chat_remote_chat_tool(): void {
    if (! current_user_can('manage_options')) wp_send_json(['code' => 403, 'body' => []], 403);
    check_ajax_referer('aiworker_chat_assistant', 'nonce');
    $chat = aiworker_chat_linked_id();
    $q = '?chat_id='.$chat.'&lang='.aiworker_chat_wizard_lang();
    $op = isset($_POST['op']) ? sanitize_key(wp_unslash((string) $_POST['op'])) : '';
    if ($op === 'test') $result = aiworker_chat_api('POST', '/plugin/chat/leads/test'.$q, [], 60);
    elseif ($op === 'icon_delete') $result = aiworker_chat_api('DELETE', '/plugin/chat/launcher-icon'.$q, [], 30);
    elseif ($op === 'icon_upload') {
        $file = $_FILES['file'] ?? null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- An uploaded file: its type and size are checked below and kra.name decodes it.
        $type = is_array($file) && is_uploaded_file((string) $file['tmp_name']) ? (string) wp_check_filetype_and_ext((string) $file['tmp_name'], (string) $file['name'], ['png' => 'image/png', 'jpg|jpeg' => 'image/jpeg', 'webp' => 'image/webp'])['type'] : '';
        if ($type === '' || (int) $file['size'] > MB_IN_BYTES) wp_send_json(['code' => 422, 'body' => ['message' => __('Choose a PNG, JPEG or WebP image up to 1 MB.', 'needtome-ai-chat')], 'error' => '']);
        $boundary = 'aiworker-chat'.wp_generate_password(24, false);
        $data = '--'.$boundary."\r\n".'Content-Disposition: form-data; name="file"; filename="icon.'.(['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'][$type]).'"'."\r\n"
            .'Content-Type: '.$type."\r\n\r\n".file_get_contents((string) $file['tmp_name'])."\r\n--".$boundary."--\r\n"; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local uploaded file.
        $result = aiworker_chat_api('POST', '/plugin/chat/launcher-icon'.$q, [], 60, ['type' => 'multipart/form-data; boundary='.$boundary, 'data' => $data]);
    }
    elseif ($op === 'translate') $result = aiworker_chat_api('POST', '/plugin/chat/translate'.$q, ['source_language' => isset($_POST['source']) ? sanitize_key(wp_unslash((string) $_POST['source'])) : ''], 90);
    else wp_send_json(['code' => 400, 'body' => []], 400);
    if (strpos($op, 'icon_') === 0 && $result['code'] === 200) delete_transient(aiworker_chat_option_name('aiworker_chat_check')); // the preview reads the icon from the check
    wp_send_json(['code' => $result['code'], 'body' => $result['body'], 'error' => $result['error'] !== '' ? __('kra.name cannot be reached from this site.', 'needtome-ai-chat') : '']);
}
add_action('wp_ajax_aiworker_chat_chat_tool', 'aiworker_chat_remote_chat_tool');

/** «What the consultant answers from» on the Knowledge base tab: the same block with only that row. */
function aiworker_chat_answer_mode_block(): string {
    return aiworker_chat_chat_step(0, ['knowledge'], 'aiworker-chat-chatset-kb');
}

/**
 * The step itself, or the parts of it ($parts: persona, texts, leads, knowledge) shown elsewhere; nothing when the
 * settings cannot be read (the kra.name panel still has them). $number 0: a block without a step number.
 */
function aiworker_chat_chat_step(int $number, array $parts = ['persona', 'texts', 'leads'], string $id = 'aiworker-chat-chatset'): string {
    $html = '';

    $has = static function (string $part) use ($parts): bool { return in_array($part, $parts, true); };
    $data = aiworker_chat_remote_chat();
    if (! $data) return '';
    $chat = $data['chat'];
    $limits = (array) ($data['limits'] ?? []);
    $langs = array_slice(array_values(array_filter(array_map('strval', (array) ($chat['languages'] ?? [])), 'strlen')), 0, 20);
    $names = ['ru' => 'Русский', 'en' => 'English', 'uk' => 'Українська'];
    // Limits come from the kra.name API; each is clamped to 1..$cap so a bad response cannot inflate the form.
    $max = static function (string $k, int $d, int $cap = 5000) use ($limits): int {
        return max(1, min($cap, (int) ($limits[$k] ?? $d)));
    };
    $panel = (string) ($data['panel_url'] ?? '');
    $html .= '
    <section class="aiworker-chat-card aiworker-chat-chatset';
 $html .= count($parts) === 1 ? ' aiworker-chat-chatset--single' : ''; $html .= '" id="';
 $html .= esc_attr($id); $html .= '" data-updated="';
 $html .= esc_attr((string) ($data['updated_at'] ?? '')); $html .= '">
      ';
 if ($has('knowledge') && count($parts) === 1) : $html .= '
      <h2>';
 $html .= esc_html__('What the consultant answers from', 'needtome-ai-chat'); $html .= '</h2>
      ';
 else : $html .= '
      <h2>';
 if ($number > 0) : $html .= '<span class="aiworker-chat-num">';
 $html .= esc_html((string) $number); $html .= '</span>';
 endif;  $html .= esc_html__('Consultant and chat texts', 'needtome-ai-chat'); $html .= '</h2>
      ';
 endif; $html .= '
      <p class="aiworker-chat-lead">';
 $html .= esc_html__('The same settings as in the chat\'s settings at kra.name: a change saved here is there at once, and a change made there shows here.', 'needtome-ai-chat'); $html .= '</p>
      <div class="aiworker-chat-chatset__notice notice inline" data-cs="notice" hidden><p></p></div>

      ';
 if ($has('persona') && count((array) ($data['personas'] ?? [])) > 1) : // with one consultant there is nothing to choose
 $html .= '
      <div class="aiworker-chat-row"><div class="aiworker-chat-row__label"><label for="aiworker-chat-cs-persona">';
 $html .= esc_html__('Consultant', 'needtome-ai-chat'); $html .= '</label></div><div class="aiworker-chat-row__control">
        <select id="aiworker-chat-cs-persona" data-cs-field="persona_id">
          ';
 foreach ((array) ($data['personas'] ?? []) as $p) : $html .= '
            <option value="';
 $html .= esc_attr((string) (int) $p['id']); $html .= '"';
 $html .= selected((int) $p['id'], (int) ($chat['persona_id'] ?? 0), false); $html .= '>';
 $html .= esc_html(trim((string) $p['name'].((string) ($p['title'] ?? '') !== '' ? ' — '.$p['title'] : ''))); $html .= '</option>
          ';
 endforeach; $html .= '
        </select>
        <p class="description">';
 $html .= esc_html__('The consultant\'s role and instructions are edited in the kra.name panel or by the setup wizard.', 'needtome-ai-chat'); $html .= '</p>
      </div></div>
      ';
 endif; $html .= '
      ';
 if ($has('texts')) : $html .= '

      <div class="aiworker-chat-row"><div class="aiworker-chat-row__label">';
 $html .= esc_html__('Texts by language', 'needtome-ai-chat'); $html .= '</div><div class="aiworker-chat-row__control">
        ';
 if (count($langs) > 1) : $html .= '
        <div class="aiworker-chat-chatset__langs" role="tablist">
          ';
 foreach ($langs as $i => $l) : $html .= '<button type="button" role="tab" class="aiworker-chat-chatset__lang';
 $html .= $i === 0 ? ' is-active' : ''; $html .= '" data-cs-lang="';
 $html .= esc_attr($l); $html .= '">';
 $html .= esc_html($names[$l] ?? strtoupper($l)); $html .= '</button>';
 endforeach; $html .= '
          <span class="aiworker-chat-chatset__translate">';
 $html .= esc_html__('Fill the empty fields of the other languages by translating from', 'needtome-ai-chat'); $html .= '
            <select data-cs="source">';
 foreach ($langs as $l) : $html .= '<option value="';
 $html .= esc_attr($l); $html .= '"';
 $html .= selected($l, (string) ($chat['default_lang'] ?? ''), false); $html .= '>';
 $html .= esc_html($names[$l] ?? strtoupper($l)); $html .= '</option>';
 endforeach; $html .= '</select>
            <button type="button" class="button" data-cs="translate">';
 $html .= esc_html__('Translate', 'needtome-ai-chat'); $html .= '</button></span>
        </div>
        ';
 endif; $html .= '
        ';
 foreach ($langs as $i => $l) : $tx = (array) ($chat['texts'][$l] ?? []); $sugg = array_values((array) ($tx['suggestions'] ?? [])); $html .= '
        <div class="aiworker-chat-chatset__texts" data-cs-pane="';
 $html .= esc_attr($l); $html .= '"';
 $html .= $i === 0 ? '' : ' hidden'; $html .= '>
          <label class="aiworker-chat-chatset__label" for="aiworker-chat-cs-';
 $html .= esc_attr($l); $html .= '-greeting">';
 $html .= esc_html__('Greeting', 'needtome-ai-chat'); $html .= '</label>
          <textarea id="aiworker-chat-cs-';
 $html .= esc_attr($l); $html .= '-greeting" rows="3" maxlength="';
 $html .= esc_attr((string) $max('greeting_max', 300)); $html .= '" data-cs-field="texts.';
 $html .= esc_attr($l); $html .= '.greeting">';
 $html .= esc_textarea((string) ($tx['greeting'] ?? '')); $html .= '</textarea>
          <span class="aiworker-chat-chatset__err" data-cs-error="texts.';
 $html .= esc_attr($l); $html .= '.greeting" hidden></span>
          <span class="aiworker-chat-chatset__label">';
 $html .= esc_html__('Quick questions', 'needtome-ai-chat'); $html .= '</span>
          <p class="description">';
 $html .= esc_html__('Buttons under the greeting; a visitor asks with one click. Empty lines are not shown.', 'needtome-ai-chat'); $html .= '</p>
          ';
 $suggMax = $max('suggestions_max', 4, 10); for ($k = 0; $k < $suggMax; $k++) : $html .= '
            <input type="text" class="regular-text aiworker-chat-chatset__sugg" value="';
 $html .= esc_attr((string) ($sugg[$k] ?? '')); $html .= '" data-cs-sugg="';
 $html .= esc_attr($l); $html .= '" aria-label="';
 $html .= esc_attr(sprintf(/* translators: %d: number of the quick question */ __('Quick question %d', 'needtome-ai-chat'), $k + 1)); $html .= '">
          ';
 endfor; $html .= '
          <span class="aiworker-chat-chatset__err" data-cs-error="texts.';
 $html .= esc_attr($l); $html .= '.suggestions" hidden></span>
          <div class="aiworker-chat-chatset__trio">
            <label><span class="aiworker-chat-chatset__label">';
 $html .= esc_html__('Name in the chat header', 'needtome-ai-chat'); $html .= '</span><input type="text" maxlength="';
 $html .= esc_attr((string) $max('name_max', 60)); $html .= '" value="';
 $html .= esc_attr((string) ($tx['name'] ?? '')); $html .= '" placeholder="';
 $html .= esc_attr((string) ($tx['name_fallback'] ?? '') !== '' ? (string) $tx['name_fallback'] : __('The consultant\'s name', 'needtome-ai-chat')); $html .= '" data-cs-field="texts.';
 $html .= esc_attr($l); $html .= '.name"></label>
            <label><span class="aiworker-chat-chatset__label">';
 $html .= esc_html__('Caption under the name', 'needtome-ai-chat'); $html .= '</span><input type="text" maxlength="';
 $html .= esc_attr((string) $max('title_max', 80)); $html .= '" value="';
 $html .= esc_attr((string) ($tx['title'] ?? '')); $html .= '" placeholder="';
 $html .= esc_attr((string) ($tx['title_fallback'] ?? '') !== '' ? (string) $tx['title_fallback'] : __('The consultant\'s role', 'needtome-ai-chat')); $html .= '" data-cs-field="texts.';
 $html .= esc_attr($l); $html .= '.title"></label>
            <label><span class="aiworker-chat-chatset__label">';
 $html .= esc_html__('Side tab label', 'needtome-ai-chat'); $html .= '</span><input type="text" maxlength="';
 $html .= esc_attr((string) $max('tab_label_max', 60)); $html .= '" value="';
 $html .= esc_attr((string) ($tx['tab_label'] ?? '')); $html .= '" placeholder="';
 $html .= esc_attr((string) ($tx['name_fallback'] ?? '') !== '' ? (string) $tx['name_fallback'] : __('The consultant\'s name', 'needtome-ai-chat')); $html .= '" data-cs-field="texts.';
 $html .= esc_attr($l); $html .= '.tab_label"></label>
          </div>
          <p class="description">';
 $html .= esc_html__('An empty field shows what is greyed out in it: the consultant\'s own name and role.', 'needtome-ai-chat'); $html .= '</p>
        </div>
        ';
 endforeach; $html .= '
        ';
 if (count($langs) > 1) : $html .= '
        <p class="aiworker-chat-chatset__default"><label>';
 $html .= esc_html__('Default language', 'needtome-ai-chat'); $html .= '
          <select data-cs-field="default_lang">';
 foreach ($langs as $l) : $html .= '<option value="';
 $html .= esc_attr($l); $html .= '"';
 $html .= selected($l, (string) ($chat['default_lang'] ?? ''), false); $html .= '>';
 $html .= esc_html($names[$l] ?? strtoupper($l)); $html .= '</option>';
 endforeach; $html .= '</select></label>
          <span class="description">';
 $html .= esc_html__('when the page\'s language is not one of the chat\'s', 'needtome-ai-chat'); $html .= '</span></p>
        ';
 endif; $html .= '
      </div></div>
      ';
 $icons = array_filter((array) ($data['options']['launcher_icons'] ?? []), 'is_array'); if ($icons) : $icon = (string) ($chat['launcher_icon'] ?? 'chat'); $iconUrl = (string) ($chat['launcher_icon_url'] ?? ''); $html .= '
      <div class="aiworker-chat-row"><div class="aiworker-chat-row__label">';
 $html .= esc_html__('Chat button icon', 'needtome-ai-chat'); $html .= '</div><div class="aiworker-chat-row__control">
        <div class="aiworker-chat-icons" role="radiogroup" aria-label="';
 $html .= esc_attr__('Chat button icon', 'needtome-ai-chat'); $html .= '">
          ';
 foreach ($icons as $ic) : $v = sanitize_key((string) ($ic['value'] ?? '')); if ($v === '') continue; $html .= '
          <label class="aiworker-chat-icon" title="';
 $html .= esc_attr((string) ($ic['label'] ?? $v)); $html .= '"><input type="radio" name="';
 $html .= esc_attr($id); $html .= '_launcher_icon" value="';
 $html .= esc_attr($v); $html .= '" data-cs-field="launcher_icon"';
 $html .= checked($v, $icon, false); $html .= '><span class="aiworker-chat-icon__face" aria-hidden="true">';
 $html .= wp_kses((string) ($ic['svg'] ?? ''), aiworker_chat_svg_kses()); $html .= '</span><span class="screen-reader-text">';
 $html .= esc_html((string) ($ic['label'] ?? $v)); $html .= '</span></label>
          ';
 endforeach; $html .= '
          <label class="aiworker-chat-icon" data-cs="icon-custom" title="';
 $html .= esc_attr__('Your image', 'needtome-ai-chat'); $html .= '"';
 $html .= $iconUrl === '' ? ' hidden' : ''; $html .= '><input type="radio" name="';
 $html .= esc_attr($id); $html .= '_launcher_icon" value="custom" data-cs-field="launcher_icon" data-url="';
 $html .= esc_url($iconUrl); $html .= '"';
 $html .= checked('custom', $icon, false); $html .= '><span class="aiworker-chat-icon__face aiworker-chat-icon__face--img" aria-hidden="true"><img src="';
 $html .= esc_url($iconUrl); $html .= '" alt=""></span><span class="screen-reader-text">';
 $html .= esc_html__('Your image', 'needtome-ai-chat'); $html .= '</span></label>
        </div>
        <p class="aiworker-chat-icons__own"><label class="button"><input type="file" accept="image/png,image/jpeg,image/webp" data-cs="icon-file" hidden> ';
 $html .= esc_html__('Upload your own', 'needtome-ai-chat'); $html .= '</label>
          <button type="button" class="button-link button-link-delete" data-cs="icon-delete"';
 $html .= $iconUrl === '' ? ' hidden' : ''; $html .= '>';
 $html .= esc_html__('Remove your image', 'needtome-ai-chat'); $html .= '</button>
          <span class="description" data-cs="icon-status" role="status">';
 $html .= esc_html__('PNG, JPEG or WebP up to 1 MB; it is cropped to a square. It is uploaded to kra.name at once.', 'needtome-ai-chat'); $html .= '</span></p>
      </div></div>
      ';
 endif; $html .= '
      <div class="aiworker-chat-row"><div class="aiworker-chat-row__label">';
 $html .= esc_html__('In the chat', 'needtome-ai-chat'); $html .= '</div><div class="aiworker-chat-row__control">
        <label class="aiworker-chat-check"><input type="checkbox" data-cs-field="ai_notice"';
 $html .= checked(($chat['ai_notice'] ?? true) !== false, true, false); $html .= '> <span>';
 $html .= esc_html__('Say that an AI answers and its answers may be inaccurate', 'needtome-ai-chat'); $html .= '</span></label>
        <p class="description">';
 $html .= esc_html__('A short note under the chat\'s input: an AI assistant answers, answers may be inaccurate, check what matters with the staff. It lowers the risk of claims over a wrong answer; we recommend keeping it on.', 'needtome-ai-chat'); $html .= '</p>
        <label class="aiworker-chat-check"><input type="checkbox" data-cs-field="greet_popup"';
 $html .= checked(! empty($chat['greet_popup']), true, false); $html .= '> <span>';
 $html .= esc_html__('Show the AI\'s greeting when a page opens', 'needtome-ai-chat'); $html .= '</span></label>
        <p class="description">';
 $html .= esc_html__('The consultant\'s greeting, the same as at the start of the dialog, appears next to the chat button for a few seconds. Once per visitor session; not shown when the visitor has already written.', 'needtome-ai-chat'); $html .= '</p>
      </div></div>

      ';
 endif; $html .= '
      ';
 if ($has('leads')) : $html .= '
      <div class="aiworker-chat-row"><div class="aiworker-chat-row__label">';
 $html .= esc_html__('Leads', 'needtome-ai-chat'); $html .= '</div><div class="aiworker-chat-row__control">
        <label class="aiworker-chat-check"><input type="checkbox" data-cs-field="leads_enabled"';
 $html .= checked(! empty($chat['leads_enabled']), true, false); $html .= '> <span>';
 $html .= esc_html__('Collect visitors\' contacts: name, phone or email and what they need', 'needtome-ai-chat'); $html .= '</span></label>
        <p class="description">';
 $html .= esc_html__('When a visitor wants to get in touch, order or book, the consultant asks for a contact and passes the lead on.', 'needtome-ai-chat'); $html .= '</p>
        ';
 $ld = is_array($data['leads_delivery'] ?? null) ? $data['leads_delivery'] : null; if ($ld) : $html .= '
        <div class="aiworker-chat-ld" data-cs="delivery"';
 $html .= empty($chat['leads_enabled']) ? ' hidden' : ''; $html .= '>
          <p class="aiworker-chat-ld__head"><label class="aiworker-chat-check"><input type="checkbox" data-ld="enabled"';
 $html .= checked(! empty($ld['enabled']), true, false); $html .= '> <b>';
 $html .= esc_html__('Send leads', 'needtome-ai-chat'); $html .= '</b></label>
            <span class="description">';
 $html .= esc_html((string) ($ld['current'] ?? '')); $html .= '</span></p>
          <div class="aiworker-chat-ld__chips">
            ';
 foreach ((array) ($ld['channels'] ?? []) as $chn) : if (! is_array($chn)) continue; $html .= '
              <label class="aiworker-chat-ld__chip"><input type="checkbox" data-ld-channel="';
 $html .= esc_attr((string) $chn['type']); $html .= '"';
 $html .= checked(! empty($chn['selected']), true, false); $html .= '> ';
 $html .= esc_html((string) $chn['label']); $html .= '</label>
            ';
 endforeach; $html .= '
          </div>
          ';

          // an empty email field sends to the owner's email, which the field shows greyed out (older kra.name only named it in the fallback line)
          $ownerEmail = sanitize_email((string) ($ld['owner_email'] ?? ''));
          if ($ownerEmail === '' && preg_match('/[^\s:<>]+@[^\s<>]+\.[a-z]{2,}/i', (string) ($ld['fallback'] ?? ''), $em)) $ownerEmail = sanitize_email($em[0]);
          $html .= '
          ';
 foreach ((array) ($ld['channels'] ?? []) as $chn) : if (! is_array($chn)) continue; $type = (string) $chn['type']; $connect = (string) ($chn['connect_url'] ?? ''); $html .= '
            <div class="aiworker-chat-ld__fields" data-ld-fields="';
 $html .= esc_attr($type); $html .= '"';
 $html .= empty($chn['selected']) ? ' hidden' : ''; $html .= '>
              ';
 foreach ((array) ($chn['fields'] ?? []) as $f) : if (! is_array($f)) continue; $key = (string) $f['key']; $ft = (string) ($f['type'] ?? 'text');
                // a list with one choice is no choice: it is not shown, its value is still sent
                $opts = (array) ($f['options'] ?? []);
                if ($ft === 'select' && count($opts) <= 1) : $html .= '
                <input type="hidden" data-ld-field="';
 $html .= esc_attr($key); $html .= '" value="';
 $html .= esc_attr((string) ($f['value'] ?? ($opts[0]['value'] ?? ''))); $html .= '">
                ';
 continue; endif; $html .= '
                <label class="aiworker-chat-ld__field"><span>';
 $html .= esc_html((string) ($f['label'] ?? $key)); $html .= '</span>
                  ';
 if ($ft === 'select') : $html .= '
                    <select data-ld-field="';
 $html .= esc_attr($key); $html .= '">';
 foreach ((array) ($f['options'] ?? []) as $o) : $html .= '<option value="';
 $html .= esc_attr((string) $o['value']); $html .= '"';
 $html .= selected((string) $o['value'], (string) ($f['value'] ?? ''), false); $html .= '>';
 $html .= esc_html((string) $o['label']); $html .= '</option>';
 endforeach; $html .= '</select>
                  ';
 else : $html .= '
                    <input type="';
 $html .= esc_attr($ft === 'secret' ? 'password' : ($ft === 'email' ? 'email' : ($ft === 'url' ? 'url' : 'text'))); $html .= '" class="regular-text" autocomplete="';
 $html .= $ft === 'secret' ? 'new-password' : 'off'; $html .= '" data-ld-field="';
 $html .= esc_attr($key); $html .= '" data-ld-secret="';
 $html .= $ft === 'secret' ? '1' : '0'; $html .= '" value="';
 $html .= esc_attr($ft === 'secret' ? '' : (string) ($f['value'] ?? '')); $html .= '"';
 if ($ft === 'secret' && ! empty($f['set'])) : $html .= ' placeholder="';
 $html .= esc_attr__('Saved — leave empty to keep it', 'needtome-ai-chat'); $html .= '"';
 elseif ((string) ($f['placeholder'] ?? '') !== '') : $html .= ' placeholder="';
 $html .= esc_attr((string) $f['placeholder']); $html .= '"';
 elseif ($ft === 'email' && $ownerEmail !== '') : $html .= ' placeholder="';
 $html .= esc_attr($ownerEmail); $html .= '"';
 endif; $html .= '>
                  ';
 endif; $html .= '
                  ';
 if (! empty($f['hint'])) : $html .= '<span class="description">';
 $html .= esc_html((string) $f['hint']); $html .= '</span>';
 endif; $html .= '
                  <span class="aiworker-chat-chatset__err" data-cs-error="leads_delivery.';
 $html .= esc_attr($type.'.'.$key); $html .= '" hidden></span>
                </label>
              ';
 endforeach; $html .= '
              ';
 if ($connect !== '' && preg_match('#^https://kra\.name/#', $connect)) : $html .= '<p><a class="button" href="';
 $html .= esc_url($connect); $html .= '" target="_blank" rel="noopener">';
 $html .= esc_html(sprintf(/* translators: %s: channel name */ __('Connect %s in the kra.name panel', 'needtome-ai-chat'), (string) $chn['label'])); $html .= '</a></p>';
 endif; $html .= '
            </div>
          ';
 endforeach; $html .= '
          <p class="description">';
 $html .= esc_html((string) ($ld['fallback'] ?? '')); $html .= '</p>
          <p><button type="button" class="button" data-cs="test">';
 $html .= esc_html__('Test delivery', 'needtome-ai-chat'); $html .= '</button> <span class="description">';
 $html .= esc_html__('A short test message to every saved channel.', 'needtome-ai-chat'); $html .= '</span></p>
          <ul class="aiworker-chat-ld__results" data-cs="results" hidden></ul>
        </div>
        ';
 endif; $html .= '
      </div></div>

      ';
 endif; $html .= '
      ';
 if ($has('knowledge')) : $html .= '
      <div class="aiworker-chat-row"><div class="aiworker-chat-row__label">';
 $html .= esc_html__('What the consultant answers from', 'needtome-ai-chat'); $html .= '</div><div class="aiworker-chat-row__control">
        ';
 foreach ((array) ($data['options']['knowledge_mode'] ?? []) as $opt) : $html .= '
          <label class="aiworker-chat-check aiworker-chat-chatset__radio"><input type="radio" name="';
 $html .= esc_attr($id); $html .= '_knowledge_mode" value="';
 $html .= esc_attr((string) $opt['value']); $html .= '" data-cs-field="knowledge_mode"';
 $html .= checked((string) $opt['value'], (string) ($chat['knowledge_mode'] ?? ''), false); $html .= '> <span>';
 $html .= esc_html((string) $opt['label']); $html .= '</span></label>
        ';
 endforeach; $html .= '
        <p class="description">';
 $html .= esc_html__('With general knowledge the consultant answers general questions on the topic too, but prices, terms and contacts come only from your knowledge base.', 'needtome-ai-chat'); $html .= '</p>
        <label class="aiworker-chat-check"><input type="checkbox" data-cs-field="use_company_knowledge"';
 $html .= checked(! empty($chat['use_company_knowledge']), true, false); $html .= '> <span>';
 $html .= esc_html__('Use the company information from the kra.name panel', 'needtome-ai-chat'); $html .= '</span></label>
      </div></div>
      ';
 endif; $html .= '

      <p class="aiworker-chat-chatset__actions">
        <span class="aiworker-chat-chatset__status" data-cs="status" role="status" aria-live="polite"></span>
        ';
 if (preg_match('#^https://kra\.name/#', $panel)) : $html .= '<a class="aiworker-chat-chatset__panel" href="';
 $html .= esc_url($panel); $html .= '" target="_blank" rel="noopener">';
 $html .= esc_html__('All settings in the kra.name panel', 'needtome-ai-chat'); $html .= '</a>';
 endif; $html .= '
      </p>
    </section>

    ';


    return $html;
}

/** What the ready launcher icons from kra.name may contain: plain SVG shapes, no scripts, links or styles. */
function aiworker_chat_svg_kses(): array {
    $common = ['fill' => true, 'stroke' => true, 'stroke-width' => true, 'stroke-linecap' => true, 'stroke-linejoin' => true, 'transform' => true, 'opacity' => true];
    return [
        'svg' => ['viewbox' => true, 'xmlns' => true, 'width' => true, 'height' => true, 'aria-hidden' => true, 'focusable' => true] + $common,
        'g' => $common, 'path' => ['d' => true] + $common, 'circle' => ['cx' => true, 'cy' => true, 'r' => true] + $common,
        'ellipse' => ['cx' => true, 'cy' => true, 'rx' => true, 'ry' => true] + $common, 'rect' => ['x' => true, 'y' => true, 'width' => true, 'height' => true, 'rx' => true, 'ry' => true] + $common,
        'line' => ['x1' => true, 'y1' => true, 'x2' => true, 'y2' => true] + $common, 'polyline' => ['points' => true] + $common, 'polygon' => ['points' => true] + $common,
    ];
}
