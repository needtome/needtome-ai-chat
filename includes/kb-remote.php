<?php
/**
 * The knowledge base of the linked chat's consultant at kra.name, on the Knowledge base tab: its sources (site, pages,
 * Google Docs, texts, files, questions and answers) with refresh and delete, adding a source, and the questions and
 * answers themselves (GET/POST/PATCH/DELETE /plugin/sources and /plugin/faq). Files and Google Sheets are added in the
 * kra.name panel. The block loads after the page (lazy-blocks.php) and reloads itself after each change.
 */

if (! defined('ABSPATH')) exit;

/** AJAX: one change to the sources or the questions and answers; the block is reloaded by the page afterwards. */
function aiworker_chat_kb_remote_ajax(): void {
    if (! current_user_can('manage_options')) wp_send_json(['code' => 403, 'body' => []], 403);
    check_ajax_referer('aiwc_kb_remote', 'nonce');
    $chat = (int) get_option(AIWORKER_CHAT_LINKED_OPTION, 0);
    $op = isset($_POST['op']) ? sanitize_key(wp_unslash((string) $_POST['op'])) : '';
    $id = isset($_POST['id']) ? sanitize_text_field(wp_unslash((string) $_POST['id'])) : '';
    $q = '?chat_id='.$chat.'&lang='.aiworker_chat_wizard_lang();
    $text = static function (string $k): string { return isset($_POST[$k]) ? sanitize_textarea_field(wp_unslash((string) $_POST[$k])) : ''; }; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
    if ($chat <= 0) wp_send_json(['code' => 404, 'body' => []]);
    switch ($op) {
        case 'source_add':
            $type = sanitize_key($text('type'));
            $body = ['type' => $type, 'title' => $text('title')];
            if ($type === 'text') $body['text'] = $text('text'); else $body['url'] = esc_url_raw($text('url'), ['http', 'https']);
            $r = aiworker_chat_api('POST', '/plugin/sources'.$q, $body, 60);
            break;
        case 'source_refresh':
            $r = aiworker_chat_api('POST', '/plugin/sources/'.absint($id).'/refresh'.$q, [], 30);
            break;
        case 'source_delete':
            $r = aiworker_chat_api('DELETE', '/plugin/sources/'.absint($id).$q, [], 30);
            break;
        case 'faq_save':
            $body = ['question' => $text('question'), 'answer' => $text('answer')];
            $r = $id === '' ? aiworker_chat_api('POST', '/plugin/faq'.$q, $body, 30) : aiworker_chat_api('PATCH', '/plugin/faq'.$q, $body + ['id' => $id], 30);
            break;
        case 'faq_delete':
            $r = aiworker_chat_api('DELETE', '/plugin/faq'.$q.'&id='.rawurlencode($id), [], 30);
            break;
        default:
            wp_send_json(['code' => 400, 'body' => []], 400);
    }
    wp_send_json(['code' => $r['code'], 'body' => $r['body'], 'error' => $r['error'] !== '' ? __('kra.name cannot be reached from this site.', 'needtome-ai-chat') : '']);
}
add_action('wp_ajax_aiwc_kb_remote', 'aiworker_chat_kb_remote_ajax');

/** The card: sources, the add form and the questions and answers. Nothing when kra.name cannot be read. */
function aiworker_chat_kb_remote_block(): void {
    $chat = (int) get_option(AIWORKER_CHAT_LINKED_OPTION, 0);
    if ($chat <= 0 || ! aiworker_chat_is_connected()) return;
    $lang = aiworker_chat_wizard_lang();
    $res = aiworker_chat_api('GET', '/plugin/sources', ['chat_id' => $chat, 'lang' => $lang], 15);
    if ($res['code'] !== 200) return;
    $data = $res['body'];
    $faq = aiworker_chat_api('GET', '/plugin/faq', ['chat_id' => $chat, 'lang' => $lang], 15);
    $items = $faq['code'] === 200 ? array_values(array_filter((array) ($faq['body']['items'] ?? []), 'is_array')) : null;
    $can = (array) ($data['can_add'] ?? []);
    $limits = (array) ($can['limits'] ?? []);
    $panel = preg_match('#^https://kra\.name/#', (string) ($data['panel_url'] ?? '')) ? (string) $data['panel_url'] : 'https://kra.name/app/panel';
    $types = array_filter(['site' => __('Site', 'needtome-ai-chat'), 'page' => __('Page', 'needtome-ai-chat'), 'gdoc' => __('Google Doc', 'needtome-ai-chat'), 'text' => __('Text', 'needtome-ai-chat')], static function ($k) use ($can) { return ! empty($can[$k]); }, ARRAY_FILTER_USE_KEY);
    $date = static function (string $iso): string { $t = strtotime($iso); return $t ? wp_date(get_option('date_format').' H:i', $t) : ''; };
    ?>
    <section class="aiwc-card aiwc-kbr" id="aiwc-kbr">
      <h2><?php esc_html_e('Knowledge base at kra.name', 'needtome-ai-chat'); ?></h2>
      <p class="aiwc-lead"><?php echo esc_html(sprintf(
          /* translators: 1: consultant name, 2: note from kra.name, e.g. «Sources for this persona are shared by all of its chats» */
          __('The consultant %1$s answers from these sources. %2$s.', 'needtome-ai-chat'), (string) ($data['persona']['name'] ?? ''), rtrim((string) ($data['note'] ?? ''), '.'))); ?></p>
      <div class="aiwc-kbr__notice notice inline" data-kbr="notice" hidden><p></p></div>

      <ul class="aiwc-kbr__list">
        <?php foreach ((array) ($data['sources'] ?? []) as $s) : if (! is_array($s)) continue;
            $status = (string) ($s['status'] ?? '');
            $tone = $status === 'ready' ? 'ok' : ($status === 'error' ? 'err' : 'off');
            $url = (string) ($s['url'] ?? '');
            $title = (string) ($s['title'] ?? '');
            $show = $url !== '' && ($title === '' || $title === (string) ($s['type_label'] ?? '')) ? (string) preg_replace('#^https?://#', '', $url) : $title; ?>
          <li class="aiwc-kbr__item">
            <span class="aiwc-kbr__type"><?php echo esc_html((string) ($s['type_label'] ?? '')); ?></span>
            <span class="aiwc-kbr__title"><?php if ($url !== '') : ?><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><?php echo esc_html($show); ?></a><?php else : echo esc_html($show); endif; ?>
              <?php if (! empty($s['error'])) : ?><span class="aiwc-kbr__err"><?php echo esc_html((string) $s['error']); ?></span><?php endif; ?></span>
            <span class="aiwc-pill aiwc-pill--<?php echo esc_attr($tone); ?>"><?php echo esc_html((string) ($s['status_label'] ?? $status)); ?></span>
            <span class="aiwc-kbr__date"><?php echo esc_html($date((string) ($s['updated_at'] ?? ''))); ?></span>
            <span class="aiwc-kbr__actions">
              <?php if (in_array($s['type'] ?? '', ['site', 'page', 'gdoc', 'gsheet'], true)) : ?><button type="button" class="button-link" data-kbr-op="source_refresh" data-id="<?php echo esc_attr((string) (int) $s['id']); ?>"><?php esc_html_e('Refresh', 'needtome-ai-chat'); ?></button><?php endif; ?>
              <?php if (($s['type'] ?? '') !== 'faq') : ?><button type="button" class="button-link aiwc-kbr__del" data-kbr-op="source_delete" data-id="<?php echo esc_attr((string) (int) $s['id']); ?>" data-confirm="<?php echo esc_attr(sprintf(/* translators: %s: source name */ __('Delete «%s» from the knowledge base? Its text is removed for every chat of this consultant.', 'needtome-ai-chat'), $show)); ?>"><?php esc_html_e('Delete', 'needtome-ai-chat'); ?></button><?php endif; ?>
            </span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php $refreshHours = (int) ($limits['manual_refresh_hours'] ?? 6); $sitePages = (int) ($limits['site_pages'] ?? 20); // a plain variable: make-pot skips _n() whose count is a cast of a «??» expression ?>
      <?php if (isset($limits['manual_refreshes_left_today'])) : ?><p class="description"><?php echo esc_html(sprintf(
          /* translators: 1: refreshes left today, 2: hours between refreshes of one source */
          _n('Manual refreshes left today: %1$d. One source can be refreshed once every %2$d hour.', 'Manual refreshes left today: %1$d. One source can be refreshed once every %2$d hours.', $refreshHours, 'needtome-ai-chat'), (int) $limits['manual_refreshes_left_today'], $refreshHours)); ?></p><?php endif; ?>

      <?php if ($types) : ?>
      <h3><?php esc_html_e('Add a source', 'needtome-ai-chat'); ?></h3>
      <div class="aiwc-kbr__add" data-kbr="add">
        <select data-kbr-field="type"><?php foreach ($types as $k => $label) : ?><option value="<?php echo esc_attr($k); ?>"><?php echo esc_html($label); ?></option><?php endforeach; ?></select>
        <input type="url" class="regular-text" data-kbr-field="url" maxlength="<?php echo esc_attr((string) (int) ($limits['url_chars'] ?? 500)); ?>" placeholder="https://">
        <textarea data-kbr-field="text" rows="4" maxlength="<?php echo esc_attr((string) (int) ($limits['text_chars'] ?? 50000)); ?>" placeholder="<?php esc_attr_e('The text the consultant should know', 'needtome-ai-chat'); ?>" hidden></textarea>
        <input type="text" class="regular-text" data-kbr-field="title" maxlength="<?php echo esc_attr((string) (int) ($limits['title_chars'] ?? 120)); ?>" placeholder="<?php esc_attr_e('Title (optional)', 'needtome-ai-chat'); ?>">
        <button type="button" class="button button-primary" data-kbr-op="source_add"><?php esc_html_e('Add', 'needtome-ai-chat'); ?></button>
        <?php /* translators: %d: how many pages of a site are read */ ?>
        <p class="description" data-kbr-hint="site"><?php echo esc_html(sprintf(/* translators: %d: number of pages */ _n('The site is read from this address: up to %d page.', 'The site is read from this address: up to %d pages.', $sitePages, 'needtome-ai-chat'), $sitePages)); ?></p>
      </div>
      <?php endif; ?>
      <p class="description"><?php echo wp_kses(sprintf(
          /* translators: %s: link to the consultant's knowledge in the kra.name panel */
          __('Files and Google Sheets are added in the %s.', 'needtome-ai-chat'),
          '<a href="'.esc_url($panel).'" target="_blank" rel="noopener">'.esc_html__('kra.name panel', 'needtome-ai-chat').'</a>'), ['a' => ['href' => [], 'target' => [], 'rel' => []]]); ?></p>

      <?php if ($items !== null) : ?>
      <h3><?php esc_html_e('Questions and answers', 'needtome-ai-chat'); ?></h3>
      <p class="description"><?php esc_html_e('Exact answers to frequent questions: the consultant uses them as they are.', 'needtome-ai-chat'); ?></p>
      <div class="aiwc-kbr__faq">
        <?php foreach (array_merge($items, [['id' => '', 'question' => '', 'answer' => '']]) as $it) : $new = (string) $it['id'] === ''; ?>
          <div class="aiwc-kbr__qa<?php echo $new ? ' aiwc-kbr__qa--new' : ''; ?>" data-id="<?php echo esc_attr((string) $it['id']); ?>">
            <?php if ($new) : ?><b><?php esc_html_e('New question', 'needtome-ai-chat'); ?></b><?php endif; ?>
            <input type="text" data-kbr-field="question" value="<?php echo esc_attr((string) $it['question']); ?>" placeholder="<?php esc_attr_e('Question', 'needtome-ai-chat'); ?>">
            <textarea data-kbr-field="answer" rows="2" placeholder="<?php esc_attr_e('Answer', 'needtome-ai-chat'); ?>"><?php echo esc_textarea((string) $it['answer']); ?></textarea>
            <span class="aiwc-kbr__qa-actions">
              <button type="button" class="button" data-kbr-op="faq_save"><?php echo esc_html($new ? __('Add', 'needtome-ai-chat') : __('Save', 'needtome-ai-chat')); ?></button>
              <?php if (! $new) : ?><button type="button" class="button-link aiwc-kbr__del" data-kbr-op="faq_delete" data-confirm="<?php esc_attr_e('Delete this question and answer?', 'needtome-ai-chat'); ?>"><?php esc_html_e('Delete', 'needtome-ai-chat'); ?></button><?php endif; ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </section>
    <script>
    (function (root) {
      var c = <?php echo wp_json_encode(['ajax' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('aiwc_kb_remote'), 'saving' => __('Saving…', 'needtome-ai-chat'), 'done' => __('Done.', 'needtome-ai-chat'), 'failed' => __('Not saved: kra.name answered with an error. Try again a bit later.', 'needtome-ai-chat'), 'lang' => aiworker_chat_wizard_lang()]); ?>;
      var notice = root.querySelector('[data-kbr="notice"]'), add = root.querySelector('[data-kbr="add"]');
      function say(type, text) { notice.className = 'aiwc-kbr__notice notice inline notice-' + type; notice.hidden = !text; notice.firstChild.textContent = text || ''; }
      // the add form: a text source has a text field instead of an address
      if (add) {
        var type = add.querySelector('[data-kbr-field="type"]'), sync = function () {
          var t = type.value === 'text';
          add.querySelector('[data-kbr-field="url"]').hidden = t; add.querySelector('[data-kbr-field="text"]').hidden = !t;
          add.querySelector('[data-kbr-hint="site"]').hidden = type.value !== 'site';
        };
        type.addEventListener('change', sync); sync();
      }
      root.addEventListener('click', function (e) {
        var b = e.target.closest('[data-kbr-op]'); if (!b) return;
        if (b.hasAttribute('data-confirm') && !window.confirm(b.getAttribute('data-confirm'))) return;
        var op = b.getAttribute('data-kbr-op'), scope = b.closest('.aiwc-kbr__qa') || b.closest('[data-kbr="add"]') || b;
        var data = new FormData();
        data.append('action', 'aiwc_kb_remote'); data.append('nonce', c.nonce); data.append('op', op); data.append('lang', c.lang);
        data.append('id', b.getAttribute('data-id') || (scope.getAttribute && scope.getAttribute('data-id')) || '');
        scope.querySelectorAll && scope.querySelectorAll('[data-kbr-field]').forEach(function (f) { data.append(f.getAttribute('data-kbr-field'), f.value); });
        b.disabled = true; say('info', c.saving);
        fetch(c.ajax, { method: 'POST', body: data, credentials: 'same-origin' }).then(function (r) { return r.json(); }).catch(function () { return { code: 0, body: {} }; }).then(function (res) {
          b.disabled = false;
          var body = res.body || {}, ok = res.code >= 200 && res.code < 300;
          if (!ok) {
            var msg = body.message || body.error || (body.errors ? Object.keys(body.errors).map(function (k) { return [].concat(body.errors[k]).join(' '); }).join(' ') : '') || res.error || c.failed;
            say('error', String(msg)); return;
          }
          // the list changed at kra.name: show it as it is now
          if (window.aiwcLazyReload) window.aiwcLazyReload(root, 'sources'); else say('success', c.done);
        });
      });
    })(document.getElementById('aiwc-kbr'));
    </script>
    <?php
}
