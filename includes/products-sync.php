<?php
/**
 * Product catalog from WooCommerce: every visible published product (with its variations, attributes, prices and
 * stock) is sent to the chat's catalog at kra.name, where it is searchable by exact characteristics and as knowledge.
 * It rides the knowledge-sync queue and worker (same token, same lock); nothing is sent without WooCommerce.
 * Contract: docs/wordpress-product-catalog-contract.md in the kra.name repository.
 */

if (! defined('ABSPATH')) exit;

const AIWORKER_CHAT_PRODUCTS_BATCH = 20;          // products per cron run
const AIWORKER_CHAT_PRODUCTS_RECORDS = 50;        // server: products and variations together per request
const AIWORKER_CHAT_PRODUCTS_TEXT_CHARS = 19000;  // server keeps 20000 characters of description

function aiworker_chat_products_available(): bool {
    return class_exists('WooCommerce') && function_exists('wc_get_product');
}

/** The catalog is on: knowledge sync on, the catalog box ticked, WooCommerce active. */
function aiworker_chat_products_on(?array $kb = null): bool {
    $kb = $kb ?? aiworker_chat_kb_options();
    return ! empty($kb['enabled']) && ! empty($kb['products']) && aiworker_chat_products_available();
}

/** IDs of every product the catalog exports: published, no password, not hidden from the catalog. */
function aiworker_chat_products_all_ids(?array $kb = null): array {
    if (! aiworker_chat_products_on($kb)) return [];
    $ids = array_map('intval', get_posts(['post_type' => 'product', 'post_status' => 'publish', 'has_password' => false,
        'posts_per_page' => -1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => false, 'lang' => '']));
    return array_values(array_filter($ids, static function (int $id): bool {
        $p = wc_get_product($id);
        return $p && $p->get_catalog_visibility() !== 'hidden';
    }));
}

/** WooCommerce product dates use post_modified_gmt; include variation dates because their data is in the parent record. */
function aiworker_chat_products_markers(array $ids): array {
    $rows = aiworker_chat_kb_marker_rows($ids, true);
    $children = [];
    foreach ($rows as $row) if ($row->post_type === 'product_variation') $children[(int) $row->post_parent][] = [(int) $row->ID, (string) $row->post_status, (string) $row->post_modified_gmt];
    $markers = [];
    foreach ($ids as $id) {
        $id = (int) $id; $row = $rows[$id] ?? null;
        if (! $row || $row->post_type !== 'product') continue;
        $variations = $children[$id] ?? [];
        usort($variations, static function (array $a, array $b): int { return $a[0] <=> $b[0]; });
        $markers[(string) $id] = aiworker_chat_kb_hash_value(['modified_gmt' => (string) $row->post_modified_gmt, 'status' => (string) $row->post_status,
            'password' => (string) $row->post_password, 'variations' => $variations]);
    }
    return $markers;
}

/** A WooCommerce amount as a plain decimal string, or null when there is none. */
function aiworker_chat_products_amount($value): ?string {
    if ($value === '' || $value === null || ! is_numeric($value)) return null;
    return wc_format_decimal($value, 4, true);
}

function aiworker_chat_products_stock(WC_Product $p): string {
    $map = ['instock' => 'in_stock', 'outofstock' => 'out_of_stock', 'onbackorder' => 'backorder'];
    return $map[$p->get_stock_status()] ?? 'unknown';
}

/** Prices and stock shared by products and variations. */
function aiworker_chat_products_offer(WC_Product $p): array {
    $out = ['sku' => (string) $p->get_sku(), 'price' => aiworker_chat_products_amount($p->get_price()),
        'regular_price' => aiworker_chat_products_amount($p->get_regular_price()), 'sale_price' => $p->is_on_sale() ? aiworker_chat_products_amount($p->get_sale_price()) : null,
        'stock_status' => aiworker_chat_products_stock($p)];
    if ($p->managing_stock() && $p->get_stock_quantity() !== null) $out['stock_qty'] = (int) $p->get_stock_quantity();
    $image = $p->get_image_id() ? wp_get_attachment_image_url((int) $p->get_image_id(), 'woocommerce_single') : '';
    if ($image) $out['image_url'] = $image;
    return array_filter($out, static function ($v) { return $v !== null && $v !== ''; });
}

/** The product's own attributes (global pa_* and local) that are shown on the product page or make its variations. */
function aiworker_chat_products_attributes(WC_Product $p): array {
    $out = [];
    foreach ($p->get_attributes() as $attr) {
        if (! $attr instanceof WC_Product_Attribute || (! $attr->get_visible() && ! $attr->get_variation())) continue;
        $values = $attr->is_taxonomy() ? wp_list_pluck($attr->get_terms() ?: [], 'name') : $attr->get_options();
        $values = array_values(array_filter(array_map('aiworker_chat_kb_text', (array) $values), 'strlen'));
        if ($values) $out[wc_attribute_label($attr->get_name(), $p)] = $values;
    }
    return $out;
}

/** A variation's own attribute values (an «any» value is left out: the parent lists the options). */
function aiworker_chat_products_variation_attributes(WC_Product_Variation $v, WC_Product $parent): array {
    $out = [];
    foreach ($v->get_attributes() as $name => $value) {
        if ($value === '' || $value === null) continue;
        $term = taxonomy_exists($name) ? get_term_by('slug', $value, $name) : null;
        $out[wc_attribute_label($name, $parent)] = [$term ? $term->name : (string) $value];
    }
    return $out;
}

/** Characteristics from the fields chosen for the «product» type on the knowledge card (taxonomies, ACF, custom fields). */
function aiworker_chat_products_extra(WP_Post $post, array $cfg): array {
    $out = [];
    foreach ((array) ($cfg['tax'] ?? []) as $tax) {
        if (in_array($tax, ['product_cat', 'product_tag'], true) || strpos($tax, 'pa_') === 0) continue;   // sent as categories, tags, attributes
        $terms = get_the_terms($post, $tax);
        if (! $terms || is_wp_error($terms)) continue;
        $taxObj = get_taxonomy($tax);
        $out[$taxObj ? $taxObj->labels->name : $tax] = array_values(wp_list_pluck($terms, 'name'));
    }
    foreach ((array) ($cfg['meta'] ?? []) as $key) {
        if (aiworker_chat_kb_secret_name((string) $key)) continue;
        $v = aiworker_chat_kb_text(get_post_meta($post->ID, $key, true));
        if ($v !== '') $out[$key] = [$v];
    }
    if (! empty($cfg['acf']) && function_exists('get_field_object')) {
        $labels = aiworker_chat_kb_acf_fields('product');
        foreach ((array) $cfg['acf'] as $name) {
            $object = get_field_object($name, $post->ID);
            foreach ($object ? aiworker_chat_kb_acf_lines($object, (string) ($labels[$name] ?? $name)) : [] as $line) {
                [$label, $value] = explode(': ', $line, 2) + [1 => ''];
                if ($value !== '') $out[$label][] = $value;
            }
        }
    }
    return $out;
}

/**
 * The catalog record for one product with its variations, or null when the product is not exported (not published,
 * protected, hidden from the catalog, or not a product).
 */
function aiworker_chat_products_record(int $id, ?array $kb = null): ?array {
    $kb = $kb ?? aiworker_chat_kb_options();
    $p = wc_get_product($id);
    $post = get_post($id);
    if (! $p || ! $post || $p->is_type('variation') || $post->post_status !== 'publish' || $post->post_password !== '' || $p->get_catalog_visibility() === 'hidden') return null;
    // the «Products» row on the knowledge card picks the texts and extra fields; without it, both descriptions go
    $cfg = $kb['types']['product'] ?? null;
    $fields = $cfg ? (array) $cfg['fields'] : ['excerpt', 'content'];
    $short = in_array('excerpt', $fields, true) ? aiworker_chat_products_text((string) $p->get_short_description()) : '';
    $description = in_array('content', $fields, true) ? aiworker_chat_products_text(do_blocks(strip_shortcodes((string) $p->get_description()))) : '';
    $record = ['id' => (string) $id, 'title' => aiworker_chat_kb_text($p->get_name()), 'url' => get_permalink($id), 'lang' => aiworker_chat_kb_post_lang($id),
        'currency' => get_woocommerce_currency(),
        'categories' => array_values(array_map('aiworker_chat_kb_text', wp_list_pluck(get_the_terms($id, 'product_cat') ?: [], 'name'))),
        'tags' => array_values(array_map('aiworker_chat_kb_text', wp_list_pluck(get_the_terms($id, 'product_tag') ?: [], 'name'))),
        'attributes' => array_merge($cfg ? aiworker_chat_products_extra($post, $cfg) : [], aiworker_chat_products_attributes($p)),
        'short_text' => $short, 'description' => $description, 'modified_at' => get_post_modified_time('c', true, $post)] + aiworker_chat_products_offer($p);
    if ($record['title'] === '') return null;
    $record['variations'] = [];
    $record['variation_ids'] = [];   // the full list: kra.name removes the product's variations that are not in it
    if ($p->is_type('variable')) {
        foreach ($p->get_children() as $childId) {
            $v = wc_get_product($childId);
            if (! $v instanceof WC_Product_Variation || $v->get_status() !== 'publish') continue;
            $record['variations'][] = array_filter(['id' => (string) $childId, 'url' => $v->get_permalink(), 'attributes' => aiworker_chat_products_variation_attributes($v, $p),
                'description' => aiworker_chat_products_text((string) $v->get_description())] + aiworker_chat_products_offer($v), static function ($x) { return $x !== '' && $x !== []; });
            $record['variation_ids'][] = (string) $childId;
        }
    }
    return array_filter($record, static function ($v) { return $v !== null && $v !== ''; });
}

/** Product HTML as plain text within the server's description limit. */
function aiworker_chat_products_text(string $html): string {
    $text = aiworker_chat_kb_html_to_text($html);
    return aiworker_chat_kb_chars($text) > AIWORKER_CHAT_PRODUCTS_TEXT_CHARS ? rtrim(wp_html_excerpt($text, AIWORKER_CHAT_PRODUCTS_TEXT_CHARS)).'…' : $text;
}

/** One server request: [result, rejected ids => reason]. The result is '' or an error code as in aiworker_chat_kb_send_docs, or 'too_large' (413). */
function aiworker_chat_products_post(string $path, array $body, string $token): array {
    if (! aiworker_chat_kb_lock_touch($token)) return ['lost', []];
    // a catalog request can take longer at kra.name than a document batch; a timeout is retried with the same records (the requests are idempotent)
    [$code, $data, $err] = aiworker_chat_kb_request('POST', $path, ['site' => home_url('/')] + $body, 60);
    if ($code === 401) return ['auth', []];
    if ($code === 429) return ['retry:'.max(30, (int) ($data['retry_after'] ?? 60)), []];
    if ($code === 413) return ['too_large', []];
    if ($err !== '') return ['error:'.$err, []];
    $rejected = [];
    foreach ((array) ($data['results'] ?? []) as $r) if (($r['status'] ?? '') === 'rejected') $rejected[(string) ($r['id'] ?? '')] = (string) ($r['reason'] ?? '');
    return ['', $rejected];
}

/** Size of a record in a request body, as the request encodes it. */
function aiworker_chat_products_size(array $record): int {
    return strlen((string) wp_json_encode($record));
}

/**
 * A record that fits $limit bytes on its own: its description, then its short text, are cut by characters until it
 * fits. Null when it still does not fit (then it is reported as too large and skipped, not retried for ever).
 */
function aiworker_chat_products_fit(array $record, int $limit): ?array {
    foreach (['description', 'short_text'] as $field) {
        while (aiworker_chat_products_size($record) > $limit && ($record[$field] ?? '') !== '') {
            $chars = aiworker_chat_kb_chars($record[$field]);
            $record[$field] = $chars > 200 ? rtrim(wp_html_excerpt($record[$field], (int) ($chars * 0.6))).'…' : '';
        }
    }
    return aiworker_chat_products_size($record) > $limit ? null : $record;
}

/** Prepares records as they will be sent: oversized variations are skipped and the others go in bounded pieces. */
function aiworker_chat_products_prepare(array $records, array &$rejected): array {
    $limit = AIWORKER_CHAT_KB_REQUEST_BYTES;
    $pieces = [];
    foreach ($records as $record) {
        $parent = aiworker_chat_products_fit(array_merge($record, ['variations' => []]), (int) ($limit * 0.5));
        if (! $parent) { $rejected[$record['id']] = 'too_large'; continue; }
        $parentSize = aiworker_chat_products_size($parent);
        $variations = []; $varBytes = 0;
        foreach ((array) ($record['variations'] ?? []) as $original) {
            $v = aiworker_chat_products_fit($original, $limit - $parentSize - 64);
            if (! $v) { $rejected[$record['id'].'#'.$original['id']] = 'too_large'; continue; }
            $vSize = aiworker_chat_products_size($v) + 1;
            if ($variations && (count($variations) >= AIWORKER_CHAT_PRODUCTS_RECORDS - 1 || $parentSize + $varBytes + $vSize > $limit)) {
                $pieces[] = array_merge($parent, ['variations' => $variations]);
                $variations = []; $varBytes = 0;
            }
            $variations[] = $v; $varBytes += $vSize;
        }
        $pieces[] = array_merge($parent, ['variations' => $variations]);
    }
    return $pieces;
}

/** Sends the already normalized wire records; hashing these records matches what the endpoint receives. */
function aiworker_chat_products_send(array $pieces, array &$rejected, string $token): string {
    $limit = AIWORKER_CHAT_KB_REQUEST_BYTES;
    $batch = []; $count = 0; $bytes = 0;
    $flush = static function () use (&$batch, &$count, &$bytes, &$rejected, $token): string {
        if (! $batch) return '';
        [$r, $bad] = aiworker_chat_products_post('products', ['products' => $batch], $token);
        if ($r === 'too_large') {
            foreach ($batch as $piece) {
                // kra.name's own limit: unlike the plugin's «too_large», this holds back the pass's reconcile
                $rejected[$piece['id']] = 'batch_too_large';
                foreach ($piece['variations'] as $v) $rejected[$piece['id'].'#'.$v['id']] = 'batch_too_large';
            }
            $r = '';
        }
        $batch = []; $count = 0; $bytes = 0;
        $rejected = array_replace($rejected, $bad);   // product ids are numeric: keep the keys
        return $r;
    };
    $add = static function (array $piece) use (&$batch, &$count, &$bytes, $flush, $limit): string {
        $size = aiworker_chat_products_size($piece) + 1;
        if ($batch && ($count + 1 + count($piece['variations']) > AIWORKER_CHAT_PRODUCTS_RECORDS || $bytes + $size > $limit)) { $r = $flush(); if ($r !== '') return $r; }
        $batch[] = $piece; $count += 1 + count($piece['variations']); $bytes += $size;
        return '';
    };
    foreach ($pieces as $piece) { $r = $add($piece); if ($r !== '') return $r; }
    return $flush();
}

/**
 * The worker's catalog step, run after the documents step with the same lock. Sends the next products (their full
 * variation_ids drop variations that are gone), removes the ones that are no longer exported, and after a full pass in which kra.name
 * rejected nothing (records skipped here as too large do not count),
 * removes from kra.name every product the site no longer exports. Returns the queue fields to keep, or null when
 * the run failed (the failure is recorded and retried; the queue is left as it was).
 */
function aiworker_chat_products_step(array $kb, array $q, string $token): ?array {
    if (! aiworker_chat_products_available()) return ['products' => [], 'preconcile' => 0, 'pclean' => 1, 'product_force' => 0, 'product_force_ids' => []];
    if (! $q['products'] && ! $q['preconcile']) return ['products' => [], 'preconcile' => 0, 'pclean' => 1, 'product_force' => 0, 'product_force_ids' => []];
    $batch = array_slice($q['products'], 0, AIWORKER_CHAT_PRODUCTS_BATCH);
    $records = []; $gone = [];
    $on = aiworker_chat_products_on($kb);
    $hashes = aiworker_chat_kb_hashes();
    $productMarkers = $on ? aiworker_chat_products_markers($batch) : [];
    $forceAllProducts = ! empty($q['force']) || ! empty($q['product_force']);
    $forceProductIds = array_fill_keys(array_map('strval', (array) ($q['product_force_ids'] ?? [])), true);
    foreach ($batch as $id) {
        $idString = (string) $id;
        if (! $forceAllProducts && empty($q['preconcile']) && ! isset($forceProductIds[$idString]) && ! empty($productMarkers[$idString]) && ! empty($hashes['products'][$idString])
            && ($hashes['markers']['products'][$idString] ?? '') === $productMarkers[$idString]) continue;
        $record = $on ? aiworker_chat_products_record((int) $id, $kb) : null;
        if (! $record) { $gone[] = (string) $id; continue; }
        $records[] = $record;
    }
    $state = aiworker_chat_kb_state();
    $rejected = (array) ($state['p_rejected'] ?? []);
    $tooLarge = array_fill_keys(array_map('strval', (array) ($state['p_too_large'] ?? [])), true);
    foreach ($records as $r) {
        unset($rejected[$r['id']], $tooLarge[$r['id']]);
        foreach ((array) ($r['variations'] ?? []) as $v) unset($rejected[$r['id'].'#'.$v['id']], $tooLarge[$r['id'].'#'.$v['id']]);
    }
    foreach ($gone as $id) foreach (array_keys($tooLarge) as $key) if (explode('#', $key)[0] === $id) unset($tooLarge[$key]);
    $preparedRejected = [];
    $pieces = aiworker_chat_products_prepare($records, $preparedRejected);
    $byProduct = [];
    foreach ($pieces as $piece) $byProduct[(string) $piece['id']][] = $piece;
    $productHashes = (array) $hashes['products'];
    $expected = []; $sendIds = []; $verified = [];
    foreach ($records as $record) {
        $id = (string) $record['id'];
        $productPieces = $byProduct[$id] ?? [];
        $hasPrepareReject = aiworker_chat_products_has_rejection($id, $preparedRejected);
        if ($productPieces && ! $hasPrepareReject) $expected[$id] = aiworker_chat_kb_hash_value($productPieces);
        if (! $forceAllProducts && ! isset($forceProductIds[$id]) && isset($expected[$id]) && ($productHashes[$id] ?? '') === $expected[$id]) {
            if (! empty($productMarkers[$id])) $verified[$id] = $productMarkers[$id];
            continue;
        }
        $sendIds[$id] = true;
    }
    $sendPieces = array_values(array_filter($pieces, static function (array $piece) use ($sendIds): bool { return isset($sendIds[(string) $piece['id']]); }));
    $fresh = $preparedRejected;
    if ($sendPieces) {
        foreach (array_keys($sendIds) as $id) unset($hashes['markers']['products'][$id]);
        aiworker_chat_kb_hashes_save($hashes); // a forced event must not keep an old marker if a request fails
        $res = aiworker_chat_products_send($sendPieces, $fresh, $token);
        if ($res !== '') { aiworker_chat_products_fail($res); return null; }
    }
    foreach (array_keys($sendIds) as $id) {
        unset($hashes['products'][$id]);
        if (isset($expected[$id]) && ! aiworker_chat_products_has_rejection($id, $fresh)) {
            $hashes['products'][$id] = $expected[$id];
            if (! empty($productMarkers[$id])) $verified[$id] = $productMarkers[$id];
        } else unset($hashes['markers']['products'][$id]);
    }
    foreach ($verified as $id => $marker) if (! empty($hashes['products'][$id])) $hashes['markers']['products'][$id] = $marker;
    aiworker_chat_kb_hashes_save($hashes);
    [$fresh, $tooLarge] = aiworker_chat_products_split_rejected($fresh, $tooLarge);
    $deleted = 0;
    if ($gone && empty($q['preconcile'])) {
        [$res] = aiworker_chat_products_post('products/delete', ['ids' => $gone], $token);
        if ($res !== '') { aiworker_chat_products_fail($res); return null; }
        aiworker_chat_products_hashes_drop($gone);
        $deleted = count($gone);
    }
    $rest = array_slice($q['products'], count($batch));
    $clean = $q['pclean'] && ! $fresh;
    $reconcile = $q['preconcile'];
    $note = (string) ($state['p_note'] ?? '');
    if (! $rest && $q['preconcile']) {
        // a full pass ended: remove what the site no longer exports, but only if kra.name accepted every record it was sent
        // (records skipped here as too large keep their previous version there and do not hold this back)
        if ($clean) {
            $all = aiworker_chat_products_all_ids($kb);
            [$res] = aiworker_chat_products_post('products/reconcile', ['ids' => array_map('strval', $all)], $token);
            if ($res !== '') { aiworker_chat_products_fail($res); return null; }
            if (! aiworker_chat_kb_lock_touch($token)) return null;
            $hashes = aiworker_chat_kb_hashes();
            $exported = array_fill_keys(array_map('strval', $all), true);
            foreach (array_keys((array) $hashes['products']) as $id) if (! isset($exported[(string) $id])) {
                unset($hashes['products'][$id], $hashes['markers']['products'][$id]);
                $deleted++;
            }
            foreach (array_keys((array) $hashes['markers']['products']) as $id) if (! isset($exported[(string) $id])) unset($hashes['markers']['products'][$id]);
            aiworker_chat_kb_hashes_save($hashes);
            $exported = array_fill_keys(array_map('strval', $all), true);
            foreach (array_keys($tooLarge) as $key) if (! isset($exported[explode('#', (string) $key)[0]])) unset($tooLarge[$key]);
            $note = '';
            $state['product_reconcile_at'] = time();
        } else {
            $note = __('Products that are no longer on the site were not removed from kra.name this time, because some products were not accepted.', 'needtome-ai-chat');
        }
        $reconcile = 0; $clean = true;
    }
    aiworker_chat_kb_state(['p_sent' => (int) ($state['p_sent'] ?? 0) + count($sendIds), 'p_deleted' => (int) ($state['p_deleted'] ?? 0) + $deleted,
        'product_reconcile_at' => (int) ($state['product_reconcile_at'] ?? 0),
        'p_rejected' => array_slice(array_replace($rejected, $fresh), -50, null, true), 'p_too_large' => array_map('strval', array_keys($tooLarge)), 'p_note' => $note]);
    delete_transient(aiworker_chat_option_name('aiworker_chat_products_status'));
    $forceIdsRest = array_values(array_intersect((array) ($q['product_force_ids'] ?? []), $rest));
    $forceAllRest = $rest || $reconcile ? (int) ! empty($q['product_force']) : 0;
    return ['products' => $rest, 'preconcile' => $reconcile, 'pclean' => $clean ? 1 : 0,
        'product_force' => $forceAllRest, 'product_force_ids' => $forceIdsRest];
}

/**
 * Splits a run's rejections: records the plugin skipped as too large stay at kra.name in their last version and have
 * their own status line; they do not hold back the reconcile (their ids are exported), any other rejection does.
 *
 * @return array [other rejections id => reason, too-large ids => true]
 */
function aiworker_chat_products_split_rejected(array $fresh, array $tooLarge): array {
    foreach ($fresh as $key => $reason) if ($reason === 'too_large') { $tooLarge[(string) $key] = true; unset($fresh[$key]); }
    return [$fresh, $tooLarge];
}

function aiworker_chat_products_has_rejection(string $id, array $rejected): bool {
    foreach ($rejected as $key => $reason) if ((string) $key === $id || strpos((string) $key, $id.'#') === 0) return true;
    return false;
}

function aiworker_chat_products_hashes_drop(array $ids): void {
    $hashes = aiworker_chat_kb_hashes();
    foreach ($ids as $id) unset($hashes['products'][(string) $id], $hashes['markers']['products'][(string) $id]);
    aiworker_chat_kb_hashes_save($hashes);
}

/** Records a failed catalog request and schedules a retry (none for a bad token). */
function aiworker_chat_products_fail(string $result): void {
    if ($result === 'too_large') $result = 'error:HTTP 413';   // a delete or reconcile list over the server's limit
    $message = strpos($result, 'error:') === 0 ? substr($result, 6) : '';
    aiworker_chat_kb_fail(strpos($result, 'error:') === 0 ? 'error' : $result, $message);
}

/** Queue product IDs (a variation queues its product) for sending or removing; $reconcile marks a full pass. */
function aiworker_chat_products_enqueue(array $ids, bool $reconcile = false, bool $force = false, bool $changedOnly = false): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (! $ids && ! $reconcile) return;
    add_option(aiworker_chat_option_name(AIWORKER_CHAT_KB_INBOX).str_replace('-', '', wp_generate_uuid4()), ['ids' => [], 'reconcile' => 0,
        'product_force' => $force && ! $changedOnly ? 1 : 0, 'product_force_ids' => $force && $changedOnly ? $ids : [], 'products' => $ids, 'preconcile' => $reconcile ? 1 : 0], '', false);
    if (! wp_next_scheduled('aiworker_chat_kb_sync', [aiworker_chat_current_id()])) wp_schedule_single_event(time() + 30, 'aiworker_chat_kb_sync', [aiworker_chat_current_id()]);
}

/** The catalog's state at kra.name for this site, cached for five minutes. */
function aiworker_chat_products_remote_status(): ?array {
    $cached = get_transient(aiworker_chat_option_name('aiworker_chat_products_status'));
    if (is_array($cached)) return $cached;
    [$code, $data] = aiworker_chat_kb_request('GET', 'products/status?site='.rawurlencode(home_url('/')));
    $status = $code === 200 && is_array($data) ? $data : ['ok' => false, 'code' => $code];
    set_transient(aiworker_chat_option_name('aiworker_chat_products_status'), $status, 5 * MINUTE_IN_SECONDS);
    return $status;
}

/** The status line of the catalog row on the knowledge card. */
function aiworker_chat_products_status_text(array $kb, array $state, int $queued): string {
    if (! aiworker_chat_products_on($kb)) return '';
    $text = sprintf(/* translators: 1: products sent, 2: products removed */ __('Catalog: sent %1$d, removed %2$d.', 'needtome-ai-chat'), (int) ($state['p_sent'] ?? 0), (int) ($state['p_deleted'] ?? 0));
    if ($queued) $text .= ' '.sprintf(/* translators: %d: products waiting */ _n('%d product is waiting to be sent.', '%d products are waiting to be sent.', $queued, 'needtome-ai-chat'), $queued);
    $rejected = (array) ($state['p_rejected'] ?? []);
    if ($rejected) $text .= ' '.sprintf(/* translators: 1: number of products, 2: reasons */ __('Products not accepted by kra.name: %1$d (%2$s).', 'needtome-ai-chat'), count($rejected), implode(', ', array_unique(array_values($rejected))));
    $tooLarge = array_map('strval', (array) ($state['p_too_large'] ?? []));
    if ($tooLarge) $text .= ' '.sprintf(/* translators: 1: number of products and variations, 2: their ids */ __('Not updated because of their size: %1$d (%2$s); kra.name keeps their previous version.', 'needtome-ai-chat'), count($tooLarge), implode(', ', array_slice($tooLarge, 0, 20)).(count($tooLarge) > 20 ? ', …' : ''));
    if (! empty($state['p_note'])) $text .= ' '.$state['p_note'];
    $remote = aiworker_chat_products_remote_status();
    if ($remote && ! empty($remote['ok'])) $text .= ' '.sprintf(/* translators: 1: products and variations, 2: characteristics */ __('In the chat\'s catalog: %1$d products and variations, %2$d characteristics.', 'needtome-ai-chat'), (int) ($remote['items'] ?? 0), (int) ($remote['attributes'] ?? 0));
    return $text;
}

// Product changes, including stock and price changes made by orders and imports (no save_post then).
add_action('init', static function (): void {
    if (! aiworker_chat_products_available()) return;
    $product = static function ($id): void { aiworker_chat_each(static function () use ($id): void { if (aiworker_chat_products_on()) aiworker_chat_products_enqueue([(int) $id], false, true, true); }); };
    $byObject = static function ($p) use ($product): void { if ($p instanceof WC_Product) $product($p->is_type('variation') ? $p->get_parent_id() : $p->get_id()); };
    $variation = static function ($id) use ($product): void { $parent = wp_get_post_parent_id((int) $id); if ($parent) $product($parent); };
    foreach (['woocommerce_new_product', 'woocommerce_update_product'] as $hook) add_action($hook, $product);
    foreach (['woocommerce_new_product_variation', 'woocommerce_update_product_variation'] as $hook) add_action($hook, $variation);
    foreach (['woocommerce_product_set_stock', 'woocommerce_variation_set_stock'] as $hook) add_action($hook, $byObject);
    foreach (['woocommerce_product_set_stock_status', 'woocommerce_variation_set_stock_status'] as $hook) add_action($hook, static function ($id) use ($product, $variation): void {
        get_post_type((int) $id) === 'product_variation' ? $variation($id) : $product($id);
    });
});
foreach (['untrashed_post', 'before_delete_post'] as $aiworker_chat_product_action) {
    aiworker_chat_on_each($aiworker_chat_product_action, static function (int $postId) use ($aiworker_chat_product_action): void {
        $type = get_post_type($postId);
        if ($type === 'product' && $aiworker_chat_product_action === 'untrashed_post' && aiworker_chat_products_on()) aiworker_chat_products_enqueue([$postId], false, true, true);
        elseif ($type === 'product_variation' && aiworker_chat_products_on() && ($parent = wp_get_post_parent_id($postId))) aiworker_chat_products_enqueue([$parent], false, true, true);
    });
}
aiworker_chat_on_each('save_post_product', static function (int $postId): void {
    if (! wp_is_post_revision($postId) && ! wp_is_post_autosave($postId) && aiworker_chat_products_on()) aiworker_chat_products_enqueue([$postId], false, true, true);
}, 20);
