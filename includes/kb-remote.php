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
    check_ajax_referer('aiworker_chat_kb_remote', 'nonce');
    $chat = aiworker_chat_linked_id();
    $op = isset($_POST['op']) ? sanitize_key(wp_unslash((string) $_POST['op'])) : '';
    $id = isset($_POST['id']) ? sanitize_text_field(wp_unslash((string) $_POST['id'])) : '';
    $q = '?chat_id='.$chat.'&lang='.aiworker_chat_wizard_lang();
    $text = static function (string $k): string { return isset($_POST[$k]) ? sanitize_textarea_field(wp_unslash((string) $_POST[$k])) : ''; }; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked above.
    if ($chat <= 0) wp_send_json(['code' => 404, 'body' => []]);
    switch ($op) {
        case 'source_add':
            $type = sanitize_key($text('type'));
            $body = ['chat_id' => $chat, 'type' => $type, 'title' => $text('title')];
            if ($type === 'text') $body['text'] = $text('text'); else $body['url'] = esc_url_raw($text('url'), ['http', 'https']);
            $r = aiworker_chat_api('POST', '/plugin/sources'.$q, $body, 60);
            break;
        case 'source_refresh':
            $r = aiworker_chat_api('POST', '/plugin/sources/'.absint($id).'/refresh'.$q, ['chat_id' => $chat], 30);
            break;
        case 'source_delete':
            $r = aiworker_chat_api('DELETE', '/plugin/sources/'.absint($id).$q, ['chat_id' => $chat], 30);
            break;
        case 'faq_save':
            $body = ['question' => $text('question'), 'answer' => $text('answer')];
            $r = $id === '' ? aiworker_chat_api('POST', '/plugin/faq'.$q, $body, 30) : aiworker_chat_api('PATCH', '/plugin/faq'.$q, $body + ['id' => $id], 30);
            break;
        case 'faq_delete':
            $r = aiworker_chat_api('DELETE', '/plugin/faq'.$q.'&id='.rawurlencode($id), [], 30);
            break;
        case 'faq_clear':
            // kra.name has no «delete all»: one by one, stopping at its limit of 30 changes a minute
            $r = aiworker_chat_api('GET', '/plugin/faq', ['chat_id' => $chat, 'lang' => aiworker_chat_wizard_lang()], 15);
            if ($r['code'] !== 200) break;
            $all = array_values(array_filter((array) ($r['body']['items'] ?? []), 'is_array'));
            foreach ($all as $n => $it) {
                $r = aiworker_chat_api('DELETE', '/plugin/faq'.$q.'&id='.rawurlencode((string) ($it['id'] ?? '')), [], 30);
                if ($r['code'] === 200) continue;
                if ($n > 0) $r = ['code' => 429, 'error' => '', 'body' => ['message' => sprintf(
                    /* translators: 1: questions deleted, 2: questions left */
                    __('Deleted: %1$d. Left: %2$d. Press Clear again in a minute.', 'needtome-ai-chat'), $n, count($all) - $n)]];
                break;
            }
            break;
        default:
            wp_send_json(['code' => 400, 'body' => []], 400);
    }
    wp_send_json(['code' => $r['code'], 'body' => $r['body'], 'error' => $r['error'] !== '' ? __('kra.name cannot be reached from this site.', 'needtome-ai-chat') : '']);
}
add_action('wp_ajax_aiworker_chat_kb_remote', 'aiworker_chat_kb_remote_ajax');

/** The card: sources, the add form and the questions and answers. Nothing when kra.name cannot be read. */
function aiworker_chat_kb_remote_block(): string {
    $html = '';

    $chat = aiworker_chat_linked_id();
    if ($chat <= 0 || ! aiworker_chat_is_connected()) return '';
    $lang = aiworker_chat_wizard_lang();
    $res = aiworker_chat_api('GET', '/plugin/sources', ['chat_id' => $chat, 'lang' => $lang], 15);
    if ($res['code'] !== 200) return '';
    $data = $res['body'];
    $faq = aiworker_chat_api('GET', '/plugin/faq', ['chat_id' => $chat, 'lang' => $lang], 15);
    $items = $faq['code'] === 200 ? array_values(array_filter((array) ($faq['body']['items'] ?? []), 'is_array')) : null;
    $can = (array) ($data['can_add'] ?? []);
    $limits = (array) ($can['limits'] ?? []);
    $panel = preg_match('#^https://kra\.name/#', (string) ($data['panel_url'] ?? '')) ? (string) $data['panel_url'] : 'https://kra.name/app/panel';
    $types = array_filter(['site' => __('Site', 'needtome-ai-chat'), 'page' => __('Page', 'needtome-ai-chat'), 'gdoc' => __('Google Doc', 'needtome-ai-chat'), 'text' => __('Text', 'needtome-ai-chat')], static function ($k) use ($can) { return ! empty($can[$k]); }, ARRAY_FILTER_USE_KEY);
    $date = static function (string $iso): string { $t = strtotime($iso); return $t ? wp_date(get_option('date_format').' H:i', $t) : ''; };
    $html .= '
    <section class="aiworker-chat-card aiworker-chat-kbr" id="aiworker-chat-kbr">
      <h2>';
 $html .= esc_html__('Knowledge base at kra.name', 'needtome-ai-chat'); $html .= '</h2>
      <p class="aiworker-chat-lead">';
 $html .= esc_html(sprintf(
          /* translators: 1: consultant name, 2: note from kra.name, e.g. «Sources for this persona are shared by all of its chats» */
          __('The consultant %1$s answers from these sources. %2$s.', 'needtome-ai-chat'), (string) ($data['persona']['name'] ?? ''), rtrim((string) ($data['note'] ?? ''), '.'))); $html .= '</p>
      <div class="aiworker-chat-kbr__notice notice inline" data-kbr="notice" hidden><p></p></div>
      <dialog class="aiworker-chat-modal aiworker-chat-modal--confirm" data-kbr="confirm" aria-labelledby="aiworker-chat-kbr-confirm-text">
      <div class="aiworker-chat-modal__body">
        <p id="aiworker-chat-kbr-confirm-text" data-kbr-confirm="text"></p>
        <div class="aiworker-chat-modal__actions">
          <button type="button" class="button" data-kbr-confirm="no">';
 $html .= esc_html__('Cancel', 'needtome-ai-chat'); $html .= '</button>
          <button type="button" class="button aiworker-chat-button--danger" data-kbr-confirm="yes"></button>
        </div>
      </div>
      </dialog>

      <ul class="aiworker-chat-kbr__list">
        ';

 // questions and answers always come first; the row is there even before the first question, to add it
 $sources = array_values(array_filter((array) ($data['sources'] ?? []), 'is_array'));
 $faqRows = array_values(array_filter($sources, static function (array $s): bool { return ($s['type'] ?? '') === 'faq'; }));
 if (! $faqRows && $items !== null) $faqRows = [['type' => 'faq', 'type_label' => __('Questions and answers', 'needtome-ai-chat'), 'title' => __('Questions and answers', 'needtome-ai-chat')]];
 $sources = array_merge($faqRows, array_values(array_filter($sources, static function (array $s): bool { return ($s['type'] ?? '') !== 'faq'; })));
 foreach ($sources as $s) :
            // an emptied list is no error, whatever kra.name says about a source without text
            if (($s['type'] ?? '') === 'faq' && $items === []) unset($s['status'], $s['status_label'], $s['error'], $s['updated_at']);
            $status = (string) ($s['status'] ?? '');
            $tone = $status === 'ready' ? 'ok' : ($status === 'error' ? 'err' : 'off');
            $url = (string) ($s['url'] ?? '');
            $title = (string) ($s['title'] ?? '');
            $show = $url !== '' && ($title === '' || $title === (string) ($s['type_label'] ?? '')) ? (string) preg_replace('#^https?://#', '', $url) : $title; $html .= '
          <li class="aiworker-chat-kbr__item">
            <span class="aiworker-chat-kbr__type">';
 $html .= esc_html((string) ($s['type_label'] ?? '')); $html .= '</span>
            <span class="aiworker-chat-kbr__title">';
 if ($url !== '') : $html .= '<a href="';
 $html .= esc_url($url); $html .= '" target="_blank" rel="noopener">';
 $html .= esc_html($show); $html .= '</a>';
 else : $html .= esc_html($show); endif;
 if (($s['type'] ?? '') === 'faq' && $items !== null) : $html .= '<span class="aiworker-chat-kbr__count">';
 /* translators: %d: number of questions in the knowledge base */
 $html .= esc_html(sprintf(_n('%d question', '%d questions', count($items), 'needtome-ai-chat'), count($items))); $html .= '</span>';
 endif; $html .= '
              ';
 if (! empty($s['error'])) : $html .= '<span class="aiworker-chat-kbr__err">';
 $html .= esc_html((string) $s['error']); $html .= '</span>';
 endif; $html .= '</span>
            ';
 if ($status !== '') : $html .= '<span class="aiworker-chat-pill aiworker-chat-pill--';
 $html .= esc_attr($tone); $html .= '">';
 $html .= esc_html((string) ($s['status_label'] ?? $status)); $html .= '</span>';
 endif; $html .= '
            <span class="aiworker-chat-kbr__date">';
 $html .= esc_html($date((string) ($s['updated_at'] ?? ''))); $html .= '</span>
            <span class="aiworker-chat-kbr__actions">
              ';
 if (in_array($s['type'] ?? '', ['site', 'page', 'gdoc', 'gsheet'], true)) : $html .= '<button type="button" class="button-link" data-kbr-op="source_refresh" data-id="';
 $html .= esc_attr((string) (int) $s['id']); $html .= '">';
 $html .= esc_html__('Refresh', 'needtome-ai-chat'); $html .= '</button>';
 endif; $html .= '
              ';
 if (($s['type'] ?? '') === 'faq' && $items !== null) : $html .= '<button type="button" class="button-link" data-kbr-faq-open>';
 $html .= esc_html__('Edit', 'needtome-ai-chat'); $html .= '</button>';
 if ($items) : $html .= ' <button type="button" class="button-link aiworker-chat-kbr__del" data-kbr-op="faq_clear" data-confirm="';
 $html .= esc_attr__('Delete all questions and answers? This cannot be undone.', 'needtome-ai-chat'); $html .= '">';
 $html .= esc_html__('Clear', 'needtome-ai-chat'); $html .= '</button>';
 endif;
 endif;
 if (($s['type'] ?? '') !== 'faq') : $html .= '<button type="button" class="button-link aiworker-chat-kbr__del" data-kbr-op="source_delete" data-id="';
 $html .= esc_attr((string) (int) $s['id']); $html .= '" data-confirm="';
 $html .= esc_attr(sprintf(/* translators: %s: source name */ __('Delete «%s» from the knowledge base? Its text is removed for every chat of this consultant.', 'needtome-ai-chat'), $show)); $html .= '">';
 $html .= esc_html__('Delete', 'needtome-ai-chat'); $html .= '</button>';
 endif; $html .= '
            </span>
          </li>
        ';
 endforeach; $html .= '
      </ul>
      ';
 $refreshHours = (int) ($limits['manual_refresh_hours'] ?? 6); $sitePages = (int) ($limits['site_pages'] ?? 20); // a plain variable: make-pot skips _n() whose count is a cast of a «??» expression
 $html .= '
      ';
 if (isset($limits['manual_refreshes_left_today'])) : $html .= '<p class="description">';
 $html .= esc_html(sprintf(
          /* translators: 1: refreshes left today, 2: hours between refreshes of one source */
          _n('Manual refreshes left today: %1$d. One source can be refreshed once every %2$d hour.', 'Manual refreshes left today: %1$d. One source can be refreshed once every %2$d hours.', $refreshHours, 'needtome-ai-chat'), (int) $limits['manual_refreshes_left_today'], $refreshHours)); $html .= '</p>';
 endif; $html .= '

      ';
 if ($types) : $html .= '
      <h3>';
 $html .= esc_html__('Add a source', 'needtome-ai-chat'); $html .= '</h3>
      <div class="aiworker-chat-kbr__add" data-kbr="add">
        <select data-kbr-field="type">';
 foreach ($types as $k => $label) : $html .= '<option value="';
 $html .= esc_attr($k); $html .= '">';
 $html .= esc_html($label); $html .= '</option>';
 endforeach; $html .= '</select>
        <input type="url" class="regular-text" data-kbr-field="url" maxlength="';
 $html .= esc_attr((string) (int) ($limits['url_chars'] ?? 500)); $html .= '" placeholder="https://">
        <textarea data-kbr-field="text" rows="4" maxlength="';
 $html .= esc_attr((string) (int) ($limits['text_chars'] ?? 50000)); $html .= '" placeholder="';
 $html .= esc_attr__('The text the consultant should know', 'needtome-ai-chat'); $html .= '" hidden></textarea>
        <input type="text" class="regular-text" data-kbr-field="title" maxlength="';
 $html .= esc_attr((string) (int) ($limits['title_chars'] ?? 120)); $html .= '" placeholder="';
 $html .= esc_attr__('Title (optional)', 'needtome-ai-chat'); $html .= '">
        <button type="button" class="button button-primary" data-kbr-op="source_add">';
 $html .= esc_html__('Add', 'needtome-ai-chat'); $html .= '</button>
        ';
 /* translators: %d: how many pages of a site are read */ $html .= '
        <p class="description" data-kbr-hint="site">';
 $html .= esc_html(sprintf(/* translators: %d: number of pages */ _n('The site is read from this address: up to %d page.', 'The site is read from this address: up to %d pages.', $sitePages, 'needtome-ai-chat'), $sitePages)); $html .= '</p>
      </div>
      ';
 endif; $html .= '
      <p class="description">';
 $html .= wp_kses(sprintf(
          /* translators: %s: link to the consultant's knowledge in the kra.name panel */
          __('Files and Google Sheets are added in the %s.', 'needtome-ai-chat'),
          '<a href="'.esc_url($panel).'" target="_blank" rel="noopener">'.esc_html__('kra.name panel', 'needtome-ai-chat').'</a>'), ['a' => ['href' => [], 'target' => [], 'rel' => []]]); $html .= '</p>

      ';
 if ($items !== null) : $html .= '
      <dialog class="aiworker-chat-modal" data-kbr="faq-dialog" aria-labelledby="aiworker-chat-kbr-faq-title">
      <div class="aiworker-chat-modal__head"><h2 id="aiworker-chat-kbr-faq-title">';
 $html .= esc_html__('Questions and answers', 'needtome-ai-chat'); $html .= '</h2>
        <button type="button" class="aiworker-chat-modal__close" data-kbr-faq-close aria-label="';
 $html .= esc_attr__('Close', 'needtome-ai-chat'); $html .= '">&times;</button></div>
      <div class="aiworker-chat-modal__body">
      <p class="description">';
 $html .= esc_html__('Exact answers to frequent questions: the consultant uses them as they are.', 'needtome-ai-chat'); $html .= '</p>
      <div class="aiworker-chat-kbr__notice notice inline" data-kbr="faq-notice" hidden><p></p></div>
      <div class="aiworker-chat-kbr__faq" data-kbr="faq">
        <div class="aiworker-chat-kbr__faq-tools" data-kbr-faq="top">
          <button type="button" class="button" data-kbr-faq-add>';
 $html .= esc_html__('Add a question', 'needtome-ai-chat'); $html .= '</button>
          <input type="search" data-kbr-faq="filter" placeholder="';
 $html .= esc_attr__('Filter by question or answer', 'needtome-ai-chat'); $html .= '" aria-label="';
 $html .= esc_attr__('Filter by question or answer', 'needtome-ai-chat'); $html .= '">
          <span class="description" data-kbr-faq="count"></span>
        </div>
        <div class="aiworker-chat-kbr__qa aiworker-chat-kbr__qa--new" data-id="" data-kbr-faq="form" hidden>
          <div class="aiworker-chat-kbr__qa-text">
            <b>';
 $html .= esc_html__('New question', 'needtome-ai-chat'); $html .= '</b>
            <input type="text" data-kbr-field="question" placeholder="';
 $html .= esc_attr__('Question', 'needtome-ai-chat'); $html .= '">
            <textarea data-kbr-field="answer" rows="5" placeholder="';
 $html .= esc_attr__('Answer', 'needtome-ai-chat'); $html .= '"></textarea>
          </div>
          <span class="aiworker-chat-kbr__qa-actions">
            <button type="button" class="button" data-kbr-op="faq_save">';
 $html .= esc_html__('Add', 'needtome-ai-chat'); $html .= '</button>
            <button type="button" class="button-link" data-kbr-faq-cancel>';
 $html .= esc_html__('Cancel', 'needtome-ai-chat'); $html .= '</button>
          </span>
        </div>
        <div data-kbr-faq="list">
        ';
 foreach ($items as $it) : $html .= '
          <div class="aiworker-chat-kbr__qa" data-id="';
 $html .= esc_attr((string) $it['id']); $html .= '">
            <div class="aiworker-chat-kbr__qa-text">
              <input type="text" data-kbr-field="question" value="';
 $html .= esc_attr((string) $it['question']); $html .= '" placeholder="';
 $html .= esc_attr__('Question', 'needtome-ai-chat'); $html .= '">
              <textarea data-kbr-field="answer" rows="5" placeholder="';
 $html .= esc_attr__('Answer', 'needtome-ai-chat'); $html .= '">';
 $html .= esc_textarea((string) $it['answer']); $html .= '</textarea>
            </div>
            <span class="aiworker-chat-kbr__qa-actions">
              <button type="button" class="button" data-kbr-op="faq_save">';
 $html .= esc_html__('Save', 'needtome-ai-chat'); $html .= '</button>
              <button type="button" class="button-link aiworker-chat-kbr__del" data-kbr-op="faq_delete" data-confirm="';
 $html .= esc_attr__('Delete this question and answer?', 'needtome-ai-chat'); $html .= '">';
 $html .= esc_html__('Delete', 'needtome-ai-chat'); $html .= '</button>
            </span>
          </div>
        ';
 endforeach; $html .= '
        </div>
        <p class="description" data-kbr-faq="none" hidden>';
 $html .= esc_html__('Nothing found.', 'needtome-ai-chat'); $html .= '</p>
        <div class="aiworker-chat-kbr__faq-tools" data-kbr-faq="bottom">
          <button type="button" class="button" data-kbr-faq-add>';
 $html .= esc_html__('Add a question', 'needtome-ai-chat'); $html .= '</button>
          <span class="aiworker-chat-kbr__pager" data-kbr-faq="pager" hidden>
            <button type="button" class="button" data-kbr-faq-page="-1">‹</button>
            <span data-kbr-faq="page"></span>
            <button type="button" class="button" data-kbr-faq-page="1">›</button>
          </span>
        </div>
      </div>
      </div>
      </dialog>
      ';
 endif; $html .= '
    </section>

    ';


    return $html;
}
