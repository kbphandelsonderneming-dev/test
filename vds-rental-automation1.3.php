<?php
/**
 * Plugin Name: VDS Rental Automation (MU)
 * Description: Verhuur-WhatsApp & Trustpilot via Zapier met server-side planning, locks en robuuste betaaldetectie.
 * Version:     1.2.0
 */

/* ==== CONFIG ==== */
define('VDS_ZAP_DISPATCH', 'https://hooks.zapier.com/hooks/catch/21508957/u8o63uj/'); // Zapier webhook
define('VDS_RENTAL_PRODUCT_ID', 3173);
define('VDS_RENTAL_META_KEY',   'Verhuurperiode');

// Mollie API config (gebruik test key op staging/dev via wp_get_environment_type)
define('VDS_MOLLIE_API_LIVE_KEY', 'live_Hfpx3CEKVWwDBA2DkW3veewnzwp3bn');
define('VDS_MOLLIE_API_TEST_KEY', 'test_naq5FPqvUw9FfUsQATRwJ9fcjtSakd');
define('VDS_MOLLIE_PROFILE_ID',   'pfl_hmUuKEwzQ6');

// Uitsluiten: installatiepartner op telefoonnummer (raw, we normaliseren hieronder)
define('VDS_EXCLUDE_PHONE_RAW', '0627416359');

/* ==== DEBUG/DIAG ==== */

// Eenvoudige logger naar PHP error_log
if (!function_exists('vds_log')) {
  function vds_log($msg, $ctx = []) {
    if (!is_string($msg)) { $msg = json_encode($msg); }
    if (!empty($ctx)) { $msg .= ' | ' . json_encode($ctx); }
    error_log('[VDS] ' . $msg);
  }
}

// wp_remote_post wrapper met logging (2xx/ERROR)
if (!function_exists('vds_post')) {
  function vds_post($url, $args) {
    $res = wp_remote_post($url, $args);
    if (is_wp_error($res)) {
      vds_log('POST failed', ['url'=>$url, 'err'=>$res->get_error_message()]);
    } else {
      $code = (int) wp_remote_retrieve_response_code($res);
      if ($code < 200 || $code >= 300) {
        vds_log('POST non-2xx', ['url'=>$url, 'code'=>$code, 'body'=>wp_remote_retrieve_body($res)]);
      } else {
        vds_log('POST ok', ['code'=>$code]);
      }
    }
    return $res;
  }
}

/* ==== HELPERS ==== */

// Normaliseer naar digits (handig voor vergelijking en E.164)
function vds_digits($s){ return preg_replace('/\D+/', '', (string)$s); }

// Is dit het nummer van de installatiepartner?
function vds_is_partner_phone($phone){
  $a = vds_digits($phone);
  $b = vds_digits(VDS_EXCLUDE_PHONE_RAW);
  return $a !== '' && $b !== '' && substr($a, -strlen($b)) === $b; // match op eind (06.. of +316..)
}

// E.164 op basis van NL/BE
function vds_e164($phone, $country){
  $p = vds_digits($phone);
  if (!$p) return null;
  if (strpos($p, '00') === 0) $p = substr($p, 2);
  if ($country === 'NL') {
    if (strpos($p, '31') === 0) return '+'.$p;
    if (strpos($p, '0') === 0)  return '+31'.substr($p,1);
    return '+31'.$p;
  }
  if ($country === 'BE') {
    if (strpos($p, '32') === 0) return '+'.$p;
    if (strpos($p, '0') === 0)  return '+32'.substr($p,1);
    return '+32'.$p;
  }
  return '+'.$p;
}

// Bepaal welke Mollie mode gebruikt moet worden
function vds_mollie_mode(){
  $mode = apply_filters('vds_mollie_mode', null);
  if ($mode && in_array($mode, ['live','test'], true)) {
    return $mode;
  }
  if (function_exists('wp_get_environment_type')) {
    $env = wp_get_environment_type();
    if (in_array($env, ['development','local','staging'], true)) {
      return 'test';
    }
  }
  return 'live';
}

// Haal Mollie credentials (key/profile)
function vds_mollie_credentials(){
  return [
    'live' => [
      'key'     => defined('VDS_MOLLIE_API_LIVE_KEY') ? VDS_MOLLIE_API_LIVE_KEY : '',
      'profile' => defined('VDS_MOLLIE_PROFILE_ID') ? VDS_MOLLIE_PROFILE_ID : '',
    ],
    'test' => [
      'key'     => defined('VDS_MOLLIE_API_TEST_KEY') ? VDS_MOLLIE_API_TEST_KEY : '',
      'profile' => defined('VDS_MOLLIE_PROFILE_ID') ? VDS_MOLLIE_PROFILE_ID : '',
    ],
  ];
}

// API request helper voor Mollie
function vds_mollie_api_request($method, $path, $body = null, $mode = null){
  $mode = $mode ?: vds_mollie_mode();
  $creds = vds_mollie_credentials();
  if (empty($creds[$mode]['key'])) {
    return new WP_Error('vds_mollie_missing_key', 'Geen Mollie API key voor mode '.$mode);
  }

  $url = 'https://api.mollie.com'.$path;
  $args = [
    'method'  => $method,
    'headers' => [
      'Authorization' => 'Bearer '.$creds[$mode]['key'],
      'Content-Type'  => 'application/json',
      'Accept'        => 'application/json',
    ],
    'timeout' => 15,
  ];

  if ($body !== null) {
    $args['body'] = wp_json_encode($body);
  }

  $res = wp_remote_request($url, $args);
  if (is_wp_error($res)) {
    vds_log('mollie_api_error', ['path'=>$path, 'error'=>$res->get_error_message()]);
    return $res;
  }

  $code = (int) wp_remote_retrieve_response_code($res);
  $body_raw = wp_remote_retrieve_body($res);
  $json = $body_raw ? json_decode($body_raw, true) : null;

  if ($code >= 200 && $code < 300) {
    return $json;
  }

  vds_log('mollie_api_http_error', ['path'=>$path, 'code'=>$code, 'body'=>$body_raw]);
  return new WP_Error('vds_mollie_http_error', 'Mollie HTTP '.$code, $json);
}

// Haal recente Mollie betalingen op en filter op description
function vds_mollie_fetch_recent_payments($mode = null, $limit = 50){
  $mode = $mode ?: vds_mollie_mode();
  $creds = vds_mollie_credentials();
  if (empty($creds[$mode]['key'])) {
    return [];
  }

  $limit = max(1, min(250, (int)$limit));
  $query = [
    'limit' => $limit,
  ];
  if (!empty($creds[$mode]['profile'])) {
    $query['profileId'] = $creds[$mode]['profile'];
  }
  if ($mode === 'test') {
    $query['testmode'] = 'true';
  }

  $path = '/v2/payments?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
  $json = vds_mollie_api_request('GET', $path, null, $mode);
  if (is_wp_error($json) || empty($json['_embedded']['payments'])) {
    return [];
  }
  return $json['_embedded']['payments'];
}

function vds_mollie_get_cached_payments($force_refresh = false){
  $mode = vds_mollie_mode();
  $cache_key = 'vds_mollie_recent_'.$mode;
  $payments = ($force_refresh ? false : get_transient($cache_key));
  if ($payments === false || !is_array($payments)) {
    $payments = vds_mollie_fetch_recent_payments($mode, 100);
    set_transient($cache_key, $payments, MINUTE_IN_SECONDS);
  }
  return is_array($payments) ? $payments : [];
}
function vds_mollie_possible_modes($preferred = null){
  $modes = [];
  if ($preferred && in_array($preferred, ['live','test'], true)) {
    $modes[] = $preferred;
  }

  $current = vds_mollie_mode();
  if ($current && in_array($current, ['live','test'], true)) {
    $modes[] = $current;
  }

  if (!empty($modes)) {
    $first = reset($modes);
    $modes[] = ($first === 'live') ? 'test' : 'live';
  } else {
    $modes = ['live','test'];
  }

  return array_values(array_unique(array_filter($modes, function($mode){
    return in_array($mode, ['live','test'], true);
  })));
}

function vds_mollie_get_payment_by_id($payment_id, $mode = null){
  $payment_id = trim((string)$payment_id);
  if ($payment_id === '') {
    return new WP_Error('vds_mollie_missing_payment_id', 'Geen Mollie payment id opgegeven.');
  }

  $mode = $mode ?: vds_mollie_mode();
  $query = [];
  if ($mode === 'test') {
    $query['testmode'] = 'true';
  }

  $path = '/v2/payments/'.rawurlencode($payment_id);
  if (!empty($query)) {
    $path .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
  }

  return vds_mollie_api_request('GET', $path, null, $mode);
}

function vds_mollie_collect_payment_ids(WC_Order $order){
  $ids = [];
  foreach (['_vds_mollie_payment_request_id', '_mollie_payment_id', '_mollie_order_id', '_mollie_payment_id_order_complete'] as $meta_key) {
    $value = $order->get_meta($meta_key);
    if (!empty($value)) {
      $ids[] = (string)$value;
    }
  }

  if (method_exists($order, 'get_transaction_id')) {
    $tx = $order->get_transaction_id();
    if (!empty($tx)) {
      $ids[] = (string)$tx;
    }
  }

  $ids = array_map('trim', $ids);
  $ids = array_filter($ids, function($value){
    return $value !== '' && stripos($value, 'ord_') !== 0;
  });

  return array_values(array_unique($ids));
}

function vds_mollie_extract_order_id($description){
  if (!$description) return 0;
  if (preg_match('/order\s*(#|)(\d+)/i', $description, $m)) {
    return (int) $m[2];
  }
  return 0;
}

function vds_mollie_sync_order(WC_Order $order, ?array $payments = null, $allow_refresh = true){
  if (!$order) return false;

  $stored_id = $order->get_meta('_vds_mollie_payment_id');
  if (!empty($stored_id)) {
    return true;
  }

  if ($payments !== null) {
    if (vds_mollie_try_sync_order($order, $payments)) {
      return true;
    }
  } else {
    $cached = vds_mollie_get_cached_payments(false);
    if (!empty($cached) && vds_mollie_try_sync_order($order, $cached)) {
      return true;
    }

    if ($allow_refresh) {
      $fresh = vds_mollie_get_cached_payments(true);
      if (!empty($fresh) && vds_mollie_try_sync_order($order, $fresh)) {
        return true;
      }
    }
  }

  $mode_hints = [];
  foreach (['_vds_mollie_payment_request_mode', '_mollie_mode', '_mollie_payment_mode'] as $meta_key) {
    $value = $order->get_meta($meta_key);
    if ($value) {
      $mode_hints[] = $value;
    }
  }

  $candidate_modes = vds_mollie_possible_modes(reset($mode_hints));
  if (count($mode_hints) > 1) {
    foreach ($mode_hints as $hint) {
      if (in_array($hint, ['live','test'], true)) {
        $candidate_modes = array_merge([$hint], array_diff($candidate_modes, [$hint]));
      }
    }
    $candidate_modes = array_values(array_unique($candidate_modes));
  }

  $candidate_ids = vds_mollie_collect_payment_ids($order);
  if (empty($candidate_ids)) {
    return false;
  }

  foreach ($candidate_ids as $payment_id) {
    foreach ($candidate_modes as $mode) {
      $payment = vds_mollie_get_payment_by_id($payment_id, $mode);
      if (is_wp_error($payment) || empty($payment['id'])) {
        continue;
      }

      if (!empty($payment['metadata']['order_id']) && (int) $payment['metadata']['order_id'] !== (int) $order->get_id()) {
        continue;
      }

      if (strtolower($payment['status'] ?? '') === 'paid') {
        vds_mollie_mark_order_paid_from_payment($order, $payment);
        return true;
      }
    }
  }

  return false;
}

function vds_mollie_mark_order_paid_from_payment(WC_Order $order, array $payment){
  $order->update_meta_data('_vds_mollie_payment_id', $payment['id'] ?? '');
  if (!empty($payment['amount']['value'])) {
    $order->update_meta_data('_vds_mollie_payment_amount', $payment['amount']['value']);
  }
  if (!empty($payment['method'])) {
    $order->update_meta_data('_vds_mollie_payment_method', $payment['method']);
  }
  $order->delete_meta_data('_vds_mollie_payment_request_id');
  $order->delete_meta_data('_vds_mollie_payment_request_link');
  $order->delete_meta_data('_vds_mollie_payment_request_mode');
  $order->save();

  $resolved_status = vds_resolve_rental_status($order);
  if ($resolved_status) {
    vds_set_payment_origin_status($order, $resolved_status);
  }

  vds_stamp_paid($order);

  if ($order->get_status() !== 'huur-betal-ontv') {
    $order->update_status('huur-betal-ontv', 'Betaald via Mollie (VDS).');
  } else {
    if ($resolved_status) {
      vds_send_payment_ok($order, $resolved_status);
      vds_schedule_after_payment($order, $resolved_status, true);
    }
    vds_clear_payment_origin_status($order);
  }
}

function vds_mollie_try_sync_order(WC_Order $order, array $payments){
  $order_id = $order->get_id();
  if (!$order_id) return false;

  foreach ($payments as $payment) {
    if (!is_array($payment) || empty($payment['status']) || strtolower($payment['status']) !== 'paid') {
      continue;
    }

    $metadata_order_id = 0;
    if (!empty($payment['metadata']) && is_array($payment['metadata'])) {
      $metadata_order_id = isset($payment['metadata']['order_id']) ? (int) $payment['metadata']['order_id'] : 0;
    }

    $payment_order_id = vds_mollie_extract_order_id($payment['description'] ?? '');
    if ($metadata_order_id && $metadata_order_id !== $order_id) {
      continue;
    }
    if (!$metadata_order_id && $payment_order_id !== $order_id) {
      continue;
    }

    $payment_amount = isset($payment['amount']['value']) ? (float) $payment['amount']['value'] : null;
    $order_total = (float) $order->get_total();
    if ($payment_amount !== null && $order_total > 0 && abs($payment_amount - $order_total) > 0.02) {
      continue;
    }

    $stored_id = $order->get_meta('_vds_mollie_payment_id');
    if (!empty($stored_id) && $stored_id === ($payment['id'] ?? '')) {
      return true;
    }

    vds_mollie_mark_order_paid_from_payment($order, $payment);
    return true;
  }

  return false;
}

function vds_mollie_create_payment_request(WC_Order $order, $force_mode = null){
  $mode = $force_mode ?: vds_mollie_mode();
  $creds = vds_mollie_credentials();
  if (empty($creds[$mode]['key'])) {
    return new WP_Error('vds_mollie_missing_key', 'Geen Mollie API key beschikbaar voor '.$mode);
  }

  $amount_total = (float) $order->get_total();
  if ($amount_total <= 0) {
    return new WP_Error('vds_mollie_zero_total', 'Order heeft geen openstaand bedrag.');
  }

  $amount_value = number_format($amount_total, 2, '.', '');
  $payload = [
    'amount' => [
      'currency' => $order->get_currency(),
      'value'    => $amount_value,
    ],
    'description' => sprintf('Order %d', $order->get_id()),
    'redirectUrl' => $order->get_checkout_order_received_url(),
    'metadata'    => [
      'order_id' => $order->get_id(),
      'source'   => 'vds-rental-automation',
    ],
    'sequenceType' => 'oneoff',
  ];

  $locale = method_exists($order, 'get_locale') ? $order->get_locale() : null;
  if (!$locale && function_exists('get_user_locale')) {
    $locale = get_user_locale();
  }
  if (!$locale && function_exists('get_locale')) {
    $locale = get_locale();
  }
  if ($locale) {
    $payload['locale'] = $locale;
  }

  if (!empty($creds[$mode]['profile'])) {
    $payload['profileId'] = $creds[$mode]['profile'];
  }

  $payment_methods = apply_filters('vds_mollie_payment_methods', []);
  if (!empty($payment_methods)) {
    $payload['method'] = $payment_methods;
  }

  $webhook = apply_filters('vds_mollie_webhook_url', home_url('/?vds_mollie_webhook=1'), $order, $mode);
  if ($webhook) {
    $payload['webhookUrl'] = $webhook;
  }

  $json = vds_mollie_api_request('POST', '/v2/payments', $payload, $mode);
  if (is_wp_error($json)) {
    return $json;
  }

  $checkout_link = $json['_links']['checkout']['href'] ?? '';
  if (!$checkout_link) {
    return new WP_Error('vds_mollie_missing_checkout', 'Geen Mollie checkout link ontvangen.');
  }

  $order->update_meta_data('_vds_mollie_payment_request_id', $json['id'] ?? '');
  $order->update_meta_data('_vds_mollie_payment_request_link', $checkout_link);
  $order->update_meta_data('_vds_mollie_payment_request_mode', $mode);
  if (!empty($json['expiresAt'])) {
    $order->update_meta_data('_vds_mollie_payment_request_expires', $json['expiresAt']);
  }
  $order->save();

  $order->add_order_note(sprintf('Mollie betaallink aangemaakt (%s)', $checkout_link));

  return $checkout_link;
}

function vds_mollie_get_payment_link(WC_Order $order, $force_new = false){
  $link    = $order->get_meta('_vds_mollie_payment_request_link');
  $expires = $order->get_meta('_vds_mollie_payment_request_expires');
  $is_valid = true;
  if ($expires && strtotime($expires) < time()) {
    $is_valid = false;
  }

  if ($link && !$force_new && $is_valid) {
    return $link;
  }

  return vds_mollie_create_payment_request($order, null);
}

/* ==== MOLLIE SCHEDULED SYNC ==== */

add_filter('cron_schedules', function($schedules){
  if (!isset($schedules['vds_mollie_5min'])) {
    $schedules['vds_mollie_5min'] = [
      'interval' => 5 * MINUTE_IN_SECONDS,
      'display'  => __('VDS Mollie synchronisatie (5 minuten)', 'vds'),
    ];
  }
  return $schedules;
});

add_action('init', function(){
  if (!wp_next_scheduled('vds_sync_mollie_payments')) {
    wp_schedule_event(time() + MINUTE_IN_SECONDS, 'vds_mollie_5min', 'vds_sync_mollie_payments');
  }
});

add_action('vds_sync_mollie_payments', function(){
  if (!class_exists('WC_Order_Query')) return;

  $query = new WC_Order_Query([
    'status'   => ['pending','on-hold','processing','completed','leveren-ophalen','huur-afhalen'],
    'limit'    => 20,
    'orderby'  => 'date',
    'order'    => 'DESC',
    'meta_query' => [
      'relation' => 'OR',
      [
        'key'     => '_vds_mollie_payment_id',
        'compare' => 'NOT EXISTS',
      ],
      [
        'key'     => '_vds_mollie_payment_id',
        'value'   => '',
        'compare' => '=',
      ],
    ],
  ]);

  $orders = $query->get_orders();
  if (empty($orders)) {
    return;
  }

  $payments = vds_mollie_get_cached_payments(true);
  if (empty($payments)) {
    return;
  }

  foreach ($orders as $order) {
    if (!$order instanceof WC_Order) {
      continue;
    }

    if (in_array($order->get_status(), ['huur-betal-ontv','cancelled','refunded','failed'], true)) {
      continue;
    }

    if ((float)$order->get_total() <= 0) {
      continue;
    }

    vds_mollie_sync_order($order, $payments, false);
  }
});

// Mollie webhook endpoint – wordt aangeroepen door Mollie bij statuswijzigingen
add_action('init', function(){
  if (empty($_GET['vds_mollie_webhook'])) {
    return;
  }

  $payment_id = isset($_POST['id']) ? sanitize_text_field(wp_unslash($_POST['id'])) : '';
  if (!$payment_id) {
    status_header(400);
    echo 'missing id';
    exit;
  }

  $mode_hint = isset($_GET['mode']) ? sanitize_text_field(wp_unslash($_GET['mode'])) : '';
  $modes = vds_mollie_possible_modes($mode_hint);

  $handled = false;

  foreach ($modes as $mode) {
    $payment = vds_mollie_api_request('GET', '/v2/payments/'.rawurlencode($payment_id), null, $mode);
    if (is_wp_error($payment) || empty($payment['id'])) {
      continue;
    }

    $order_id = 0;
    if (!empty($payment['metadata']['order_id'])) {
      $order_id = (int) $payment['metadata']['order_id'];
    }
    if (!$order_id) {
      $order_id = vds_mollie_extract_order_id($payment['description'] ?? '');
    }

    if (!$order_id) {
      vds_log('mollie_webhook_no_order', ['payment'=>$payment_id, 'mode'=>$mode]);
      continue;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
      vds_log('mollie_webhook_missing_order', ['payment'=>$payment_id, 'order'=>$order_id]);
      continue;
    }

    vds_log('mollie_webhook_hit', ['payment'=>$payment_id, 'mode'=>$mode, 'order'=>$order_id, 'status'=>$payment['status'] ?? '']);

    if (!empty($payment['status']) && strtolower($payment['status']) === 'paid') {
      vds_mollie_mark_order_paid_from_payment($order, $payment);
      $handled = true;
    }
  }

  status_header($handled ? 200 : 202);
  echo $handled ? 'ok' : 'pending';
  exit;
});

// Admin tooling: betaallink opvragen of handmatige sync forceren
add_action('init', function(){
  if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) {
    return;
  }

  if (!empty($_GET['vds_mollie_link'])) {
    $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
    $force    = !empty($_GET['force']);
    $order    = $order_id ? wc_get_order($order_id) : null;
    if (!$order) {
      wp_die('Order niet gevonden');
    }

    $link = vds_mollie_get_payment_link($order, $force);
    if (is_wp_error($link)) {
      wp_die(esc_html($link->get_error_message()));
    }

    $escaped = esc_url($link);
    wp_die('Betaallink: <a href="'.$escaped.'" target="_blank" rel="noopener">'.$escaped.'</a>');
  }

  if (!empty($_GET['vds_mollie_sync'])) {
    $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
    $order    = $order_id ? wc_get_order($order_id) : null;
    if (!$order) {
      wp_die('Order niet gevonden');
    }

    $payments = vds_mollie_get_cached_payments(true);
    $matched  = vds_mollie_sync_order($order, $payments, false);
    wp_send_json([
      'matched' => (bool) $matched,
      'status'  => $order->get_status(),
      'paid_id' => $order->get_meta('_vds_mollie_payment_id'),
    ]);
  }
});

// Robuuste betaalcheck: dekt gateways, custom statussen en handmatig
function vds_is_paid(WC_Order $order){
  if (!$order) return false;

  // 1) Standaard Woo
  if (!empty($order->get_date_paid())) return true;
  if (method_exists($order, 'is_paid') && $order->is_paid()) return true;

  // 2) Meta die Woo soms zet
  $pd = $order->get_meta('_paid_date');
  if (!empty($pd)) return true;
	
// 3) Eigen Mollie synchronisatie
  if (!empty($order->get_meta('_vds_mollie_payment_id'))) {
    return true;
	  }
	
  if (vds_mollie_sync_order($order)) {
    return true;
  }
	
// 4) Gateway hints (legacy Mollie plugin data)
  $mol_status = $order->get_meta('_mollie_payment_status');
  if ($mol_status && strtolower($mol_status) === 'paid') return true;
  return false;
}

// Huurdatum uit item-meta van product 3173 (of variatie-parent)
function vds_order_contains_rental(WC_Order $order){
  foreach ($order->get_items() as $item){
    $prod   = is_callable([$item,'get_product']) ? $item->get_product() : null;
    $pid    = method_exists($item,'get_product_id') ? (int)$item->get_product_id() : ($prod ? (int)$prod->get_id() : 0);
    $parent = ($prod && method_exists($prod,'get_parent_id')) ? (int)$prod->get_parent_id() : 0;
    if ($pid === VDS_RENTAL_PRODUCT_ID || $parent === VDS_RENTAL_PRODUCT_ID) {
      return true;
    }
  }
  return false;
}

function vds_get_rental_date_from_order(WC_Order $order){
  foreach ($order->get_items() as $item){
    $prod   = is_callable([$item,'get_product']) ? $item->get_product() : null;
    $pid    = method_exists($item,'get_product_id') ? (int)$item->get_product_id() : ($prod ? (int)$prod->get_id() : 0);
    $parent = ($prod && method_exists($prod,'get_parent_id')) ? (int)$prod->get_parent_id() : 0;
    if ($pid !== VDS_RENTAL_PRODUCT_ID && $parent !== VDS_RENTAL_PRODUCT_ID) continue;

    $meta_data = is_callable([$item,'get_meta_data']) ? $item->get_meta_data() : [];
    foreach ($meta_data as $m){
      if (!is_object($m) || !method_exists($m,'get_data')) continue;
      $md = $m->get_data();
      if (empty($md['key']) || strtolower($md['key']) !== strtolower(VDS_RENTAL_META_KEY)) continue;
      $raw = (string)$md['value'];
      $ts  = strtotime(str_replace('/', '-', $raw));
      if ($ts === false) return ['raw'=>$raw,'iso'=>null,'nl'=>$raw,'ts'=>null];
      return ['raw'=>$raw,'iso'=>wp_date('Y-m-d', $ts), 'nl'=>wp_date('d-m-Y', $ts), 'ts'=>$ts];
    }
  }
  return ['raw'=>null,'iso'=>null,'nl'=>null,'ts'=>null];
}

// Zichtbare lock via ordernotitie; return true als lock zojuist is gezet
function vds_lock_once(WC_Order $order, $key){
  $marker = 'VDS:LOCK:'.$key;
  $notes = function_exists('wc_get_order_notes') ? wc_get_order_notes([
    'order_id' => $order->get_id(), 'type'=>'any', 'orderby'=>'date_created', 'order'=>'ASC'
  ]) : [];
  if (is_array($notes)){
    foreach ($notes as $n){
      $content = is_object($n) && method_exists($n,'get_content')
        ? (string)$n->get_content()
        : (string)($n->content ?? '');
      if ($content && strpos($content, $marker)!==false) return false;
    }
  }
  $order->add_order_note($marker.' @ '. wp_date('Y-m-d H:i:s'));
  return true;
}

function vds_set_last_rental_status(WC_Order $order, $status){
  if (!in_array($status, ['leveren-ophalen','huur-afhalen'], true)) return;
  if ($order->get_meta('_vds_last_rental_status') === $status) return;
  $order->update_meta_data('_vds_last_rental_status', $status);
  if (method_exists($order, 'save_meta_data')) {
    $order->save_meta_data();
  } else {
    $order->save();
  }
}

function vds_get_last_rental_status(WC_Order $order){
  $status = $order->get_meta('_vds_last_rental_status');
  return in_array($status, ['leveren-ophalen','huur-afhalen'], true) ? $status : null;
}

function vds_set_payment_origin_status(WC_Order $order, $status){
  if (!in_array($status, ['leveren-ophalen','huur-afhalen'], true)) return;
  $order->update_meta_data('_vds_payment_origin_status', $status);
  if (method_exists($order, 'save_meta_data')) {
    $order->save_meta_data();
  } else {
    $order->save();
  }
}

function vds_get_payment_origin_status(WC_Order $order){
  $status = $order->get_meta('_vds_payment_origin_status');
  return in_array($status, ['leveren-ophalen','huur-afhalen'], true) ? $status : null;
}

function vds_clear_payment_origin_status(WC_Order $order){
  $order->delete_meta_data('_vds_payment_origin_status');
  if (method_exists($order, 'save_meta_data')) {
    $order->save_meta_data();
  } else {
    $order->save();
  }
}

function vds_handle_gateway_payment(WC_Order $order, $previous_status = null, $note = ''){
  if (vds_is_partner_phone($order->get_billing_phone())) {
    return;
  }

	if (!vds_order_contains_rental($order)) {
    vds_log('gateway_payment_skip_non_rental', [
      'order' => $order->get_id(),
      'previous_status' => $previous_status,
    ]);
    return;
  }
	
  if ($previous_status && in_array($previous_status, ['leveren-ophalen','huur-afhalen'], true)) {
    vds_set_last_rental_status($order, $previous_status);
    vds_set_payment_origin_status($order, $previous_status);
  } else {
    $stored = vds_get_last_rental_status($order);
    if ($stored) {
      vds_set_payment_origin_status($order, $stored);
    }
  }

  vds_stamp_paid($order);

  if ($order->get_status() !== 'huur-betal-ontv') {
    $order->update_status('huur-betal-ontv', $note ?: 'Automatisch gezet na betaling (VDS).');
    return;
  }

  $rental_status = vds_resolve_rental_status($order);
  if ($rental_status) {
    vds_send_payment_ok($order, $rental_status);
    vds_schedule_after_payment($order, $rental_status, true);
  }

  vds_clear_payment_origin_status($order);
}

function vds_stamp_paid(WC_Order $order){
  $needs_save = false;

  $has_paid_dt = false;
  if (method_exists($order, 'get_date_paid')) {
    $has_paid_dt = (bool) $order->get_date_paid('edit');
  }

  if (!$has_paid_dt && method_exists($order, 'set_date_paid') && class_exists('WC_DateTime')) {
    $paid_dt = new WC_DateTime();
    $paid_dt->setTimestamp(current_time('timestamp', true));
    $order->set_date_paid($paid_dt);
    $needs_save = true;
  }

  if (!$order->get_meta('_paid_date')) {
    $order->update_meta_data('_paid_date', current_time('mysql'));
    $needs_save = true;
  }

  if ($needs_save) {
    $order->save();
  }
}

function vds_resolve_rental_status(WC_Order $order, $status_override = null){
	if (!vds_order_contains_rental($order)) {
    return null;
  }
	
  $statuses = ['leveren-ophalen','huur-afhalen'];
  if ($status_override && in_array($status_override, $statuses, true)) {
    return $status_override;
  }

  $current = $order->get_status();
  if (in_array($current, $statuses, true)) {
    return $current;
  }

  $stored = vds_get_last_rental_status($order);
  if ($stored) {
    return $stored;
  }

	$origin = vds_get_payment_origin_status($order);
  if ($origin) {
    return $origin;
  }

  $shipping_strings = [];

  $method = $order->get_shipping_method();
  if (!empty($method)) {
    $shipping_strings[] = strtolower((string)$method);
  }

  if (is_callable([$order, 'get_shipping_methods'])) {
    foreach ($order->get_shipping_methods() as $shipping_item) {
      if (is_object($shipping_item)) {
        if (method_exists($shipping_item, 'get_method_id')) {
          $shipping_strings[] = strtolower((string)$shipping_item->get_method_id());
        }
        if (method_exists($shipping_item, 'get_method_title')) {
          $shipping_strings[] = strtolower((string)$shipping_item->get_method_title());
        }
        if (method_exists($shipping_item, 'get_name')) {
          $shipping_strings[] = strtolower((string)$shipping_item->get_name());
        }
      } elseif (is_array($shipping_item)) {
        foreach (['method_id','method_title','name'] as $key) {
          if (!empty($shipping_item[$key])) {
            $shipping_strings[] = strtolower((string)$shipping_item[$key]);
          }
        }
      }
    }
  }

  foreach ($shipping_strings as $needle) {
    if ($needle === '') continue;
    if (strpos($needle, 'pickup') !== false || strpos($needle, 'afhaal') !== false) {
      return 'huur-afhalen';
    }
    if (strpos($needle, 'lever') !== false || strpos($needle, 'bezorg') !== false || strpos($needle, 'delivery') !== false) {
      return 'leveren-ophalen';
    }
  }

  $has_shipping_address = trim((string)$order->get_shipping_address_1().' '.(string)$order->get_shipping_postcode().' '.(string)$order->get_shipping_city()) !== '';
  if (!$has_shipping_address) {
    return 'huur-afhalen';
  }

  return 'leveren-ophalen';
}

// Eén dispatcher naar Zapier (WhatsApp/Trustpilot/etc.)
function vds_zap($topic, WC_Order $order, array $extra=[]){
  // Partner uitsluiten
  if (vds_is_partner_phone($order->get_billing_phone())) return;

  $payload = array_merge([
    'topic'         => $topic,  // whatsapp.payment_ok_delivery / whatsapp.install_success / review.invite / ...
    'order_id'      => $order->get_id(),
    'order_number'  => method_exists($order,'get_order_number') ? $order->get_order_number() : (string)$order->get_id(),
    'status'        => $order->get_status(),
    'rental'        => vds_get_rental_date_from_order($order),
    'payment_url'   => add_query_arg([
                          'pay_for_order' => '1',
                          'key'           => $order->get_order_key(),
                        ], wc_get_endpoint_url('order-pay', $order->get_id(), wc_get_checkout_url())),
    'customer'      => [
      'email'       => $order->get_billing_email(),
      'phone'       => $order->get_billing_phone(), // raw
      'phone_e164'  => vds_e164($order->get_billing_phone(), $order->get_billing_country()),
      'first_name'  => $order->get_billing_first_name(),
      'last_name'   => $order->get_billing_last_name(),
      'country'     => $order->get_billing_country(),
      'shipping'    => [
        'address_1' => $order->get_shipping_address_1(),
        'address_2' => $order->get_shipping_address_2(),
        'postcode'  => $order->get_shipping_postcode(),
        'city'      => $order->get_shipping_city(),
        'country'   => $order->get_shipping_country(),
      ],
    ],
  ], $extra);

  vds_post(VDS_ZAP_DISPATCH, [
    'headers' => ['Content-Type'=>'application/json'],
    'body'    => wp_json_encode($payload),
    'timeout' => 10,
  ]);
}

// Bereken geplande Unix-timestamp in NL-tijd
function vds_ts_nl($dateYmd, $timeHms){
  $tz = wp_timezone(); // WordPress tijdzone (Europe/Amsterdam)
  try {
    $dt = new DateTime("{$dateYmd} {$timeHms}", $tz);
    return $dt->getTimestamp();
  } catch (Throwable $e) {
    return false;
  }
}

// Verstuur 1x "betaling ontvangen" WhatsApp, afhankelijk van huidige status
function vds_send_payment_ok(WC_Order $order, $status_override = null){
  if (vds_is_partner_phone($order->get_billing_phone())) return;

  $status = vds_resolve_rental_status($order, $status_override);
  if (!$status) return;

  vds_set_last_rental_status($order, $status);
  if ($status === 'leveren-ophalen') {
    if (!vds_lock_once($order, 'payment_ok_delivery')) return;
    vds_zap('whatsapp.payment_ok_delivery', $order);
  } elseif ($status === 'huur-afhalen') {
    if (!vds_lock_once($order, 'payment_ok_pickup')) return;
    vds_zap('whatsapp.payment_ok_pickup', $order);
  }
}

// Plan WhatsApp-berichten obv status + huurdatum (alleen met lock en toekomst-check)
function vds_schedule_after_payment(WC_Order $order, $status_override = null, $force = false){
  // Betaalcheck: alleen plannen als betaald (tenzij geforceerd via status-trigger)
  if (!$force && !vds_is_paid($order)) return;

  $rental = vds_get_rental_date_from_order($order);
  if (empty($rental['iso'])) return;

  $status = vds_resolve_rental_status($order, $status_override);
  if (!$status) return;

  vds_set_last_rental_status($order, $status);

  // Lock: schedules maar 1x zetten
  if (!vds_lock_once($order, 'schedule_set')) return;

  $iso = $rental['iso']; // YYYY-MM-DD
  $now = time();

  if ($status === 'leveren-ophalen') {
    $install_ts   = vds_ts_nl($iso, '07:17:00'); // dag zelf
    $materials_ts = vds_ts_nl($iso, '07:19:00'); // direct daarna
    if ($install_ts && $install_ts > $now)    as_schedule_single_action($install_ts,   'vds_job_whatsapp', ['order_id'=>$order->get_id(),'topic'=>'whatsapp.install_success','when_ts'=>$install_ts]);
    if ($materials_ts && $materials_ts > $now)as_schedule_single_action($materials_ts, 'vds_job_whatsapp', ['order_id'=>$order->get_id(),'topic'=>'whatsapp.materials_check','when_ts'=>$materials_ts]);
  } else { // huur-afhalen
    $pickup_prev = date('Y-m-d', strtotime($iso.' -1 day'));
    $pickup_ts   = vds_ts_nl($pickup_prev, '15:07:00'); // dag ervoor
    $install_ts  = vds_ts_nl($iso, '07:17:00');         // dag zelf
    if ($pickup_ts && $pickup_ts > $now)  as_schedule_single_action($pickup_ts,  'vds_job_whatsapp', ['order_id'=>$order->get_id(),'topic'=>'whatsapp.pickup_reminder','when_ts'=>$pickup_ts]);
    if ($install_ts && $install_ts > $now)as_schedule_single_action($install_ts, 'vds_job_whatsapp', ['order_id'=>$order->get_id(),'topic'=>'whatsapp.install_success','when_ts'=>$install_ts]);
  }
}

/* ==== HOOKS ==== */

/**
 * A) Plannen/triggeren bij OF:
 *    - betaling ontvangen (gateway) OF
 *    - status handmatig op 'huur-betal-ontv' (overboeking).
 */

// 1) Bij statuswijziging: stuur/payment-ok en plan waar van toepassing
add_action('woocommerce_order_status_changed', function($order_id, $old, $new){
  
  $order = wc_get_order($order_id);
  if (!$order) return;

	 // Gateways (zoals Mollie) zetten vaak de status op processing/completed.
  // Vang dat moment af en stuur het alsnog naar huur-betal-ontv zodat de flow triggert.
  if (in_array($new, ['processing','completed'], true)) {
    vds_log('status_processing_to_paid', [
      'order'      => $order_id,
      'old'        => $old,
      'new'        => $new,
      'current'    => $order->get_status(),
      'is_partner' => vds_is_partner_phone($order->get_billing_phone()),
    ]);

    vds_handle_gateway_payment($order, $old, 'Automatisch gezet na gateway-betaling (VDS).');
    return;
  }

  if (!in_array($new, ['leveren-ophalen','huur-afhalen','verhuur-afgerond','verzonden-tgv','afgehaald','huur-betal-ontv'], true)) return;

  vds_log('status_changed', [
    'order' => $order_id,
    'old'   => $old,
    'new'   => $new,
    'paid'  => vds_is_paid($order),
    'is_partner' => vds_is_partner_phone($order->get_billing_phone()),
  ]);

  // Partner uitsluiten
  if (vds_is_partner_phone($order->get_billing_phone())) return;

if (!vds_order_contains_rental($order)) {
    vds_log('status_skip_non_rental', [
      'order' => $order_id,
      'old'   => $old,
      'new'   => $new,
    ]);
    return;
  }	
	
  // A) Naar verhuurstatus: alleen NA betaling -> payment-ok + planning
  if (in_array($new, ['leveren-ophalen','huur-afhalen'], true)) {
    vds_set_last_rental_status($order, $new);
    if (vds_is_paid($order)) {
      vds_send_payment_ok($order, $new);
      vds_schedule_after_payment($order, $new);
    }
    return;
  }

  // B) Review/Trustpilot (inclusief extra WhatsApp bij 'verhuur-afgerond')
  if (in_array($new, ['verzonden-tgv','verhuur-afgerond','afgehaald'], true)) {
    if (vds_lock_once($order, 'review_'.$new)) {
      vds_zap('review.invite', $order, ['reason'=>$new]);
      if ($new === 'verhuur-afgerond' && vds_lock_once($order, 'review_whatsapp_'.$new)) {
        vds_zap('whatsapp.review_after_rental', $order, ['reason'=>$new]);
      }
    }
    return;
  }

  // C) Handmatige betaalstatus: activeer flows op basis van laatste verhuurstatus (zonder betaalcheck)
  if ($new === 'huur-betal-ontv') {
   if (!vds_order_contains_rental($order)) {
      vds_log('manual_paid_skip_non_rental', [
        'order' => $order_id,
        'old'   => $old,
        'new'   => $new,
      ]);
      return;
    }
	  
	  $rental_status = null;
    if (in_array($old, ['leveren-ophalen','huur-afhalen'], true)) {
      $rental_status = $old;
    } else {
      $rental_status = vds_get_last_rental_status($order);
    }

	  if (!$rental_status) {
      $rental_status = vds_get_payment_origin_status($order);
    }

    if (!$rental_status) {
      $rental_status = vds_resolve_rental_status($order);
    }

    if ($rental_status) {
      // Betaalstempel zetten zodat WooCommerce de betaling herkent
      vds_stamp_paid($order);

      vds_send_payment_ok($order, $rental_status);
      vds_schedule_after_payment($order, $rental_status, true);
    }
	vds_clear_payment_origin_status($order);
    return;
  }
}, 10, 3);

// 2) Gateway route: zodra Woo betaling complete meldt
add_action('woocommerce_payment_complete', function($order_id){
  $order = wc_get_order($order_id);
   if (!$order) return;

  vds_log('payment_complete', [
    'order' => $order_id,
    'status'=> $order->get_status(),
    'paid'  => vds_is_paid($order),
    'is_partner' => vds_is_partner_phone($order->get_billing_phone()),
  ]);

    vds_handle_gateway_payment($order, $order->get_status(), 'Automatisch gezet na betaling (VDS).');
}, 10, 1);

// Worker voor geplande WhatsApps – skip als doelmoment te ver in het verleden ligt
add_action('vds_job_whatsapp', function($arg1 = null, $arg2 = null, $arg3 = null){
  // Action Scheduler levert de argumenten niet als één array aan maar als losse parameters.
  // Houd een fallback voor eventuele handmatige aanroepen met één array-argument.
  if (is_array($arg1) && $arg2 === null && $arg3 === null) {
    $args = $arg1;
  } else {
    $args = [
      'order_id' => $arg1,
      'topic'    => $arg2,
      'when_ts'  => $arg3,
    ];
  }

  $order_id = isset($args['order_id']) ? (int)$args['order_id'] : 0;
  $topic    = isset($args['topic']) ? (string)$args['topic'] : '';
  $when_ts  = isset($args['when_ts']) ? (int)$args['when_ts'] : 0;

  $order = wc_get_order($order_id);
  if (!$order) return;

  // Server-outage safety: niets sturen als doelmoment >5 min te laat is
  if ($when_ts && $when_ts < (time() - 300)) return;

  // Lock per geplande topic
  if (!vds_lock_once($order, 'scheduled_'.$topic)) return;

  vds_zap($topic, $order, ['when_ts'=>$when_ts]);
}, 10, 3);

/* ==== Payment permissies voor custom statussen (betaalpagina mag bij rental) ==== */
add_filter('woocommerce_valid_order_statuses_for_payment', function($st, $order){
  $extra = ['leveren-ophalen','huur-afhalen','wc-leveren-ophalen','wc-huur-afhalen'];
  return array_unique(array_merge($st, $extra));
}, 10, 2);

// Toon betaalbehoefte correct i.c.m. handmatige betalingen
add_filter('woocommerce_order_needs_payment', function($needs, $order){
  if (!$order) return $needs;
  $status = $order->get_status();
  if (in_array($status, ['leveren-ophalen','huur-afhalen'], true)) {
    return (float)$order->get_total() > 0 && !vds_is_paid($order);
  }
  return $needs;
}, 10, 2);

/* ==== DIAG/TEST ==== */

// Diagnose endpoint: snelle JSON dump van orderstatus, betaalchecks, rental en geplande jobs
add_action('init', function () {
  if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) return;
  if (empty($_GET['vds_diag'])) return;

  $order_id = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
  $o = $order_id ? wc_get_order($order_id) : null;
  if (!$o) wp_die('Order not found');

  $paid_date = $o->get_date_paid();
  $is_paid   = method_exists($o,'is_paid') ? $o->is_paid() : null;
  $pd_meta   = $o->get_meta('_paid_date');
  $mol_stat  = $o->get_meta('_mollie_payment_status');
  $notes_hit = false;
  if (function_exists('wc_get_order_notes')) {
    $notes = wc_get_order_notes(['order_id'=>$o->get_id(), 'type'=>'any', 'order'=>'DESC', 'per_page'=>10]);
    if (is_array($notes)) {
      foreach ($notes as $n) {
        $txt = is_object($n) && method_exists($n,'get_content') ? $n->get_content() : (string)($n->content ?? '');
        if ($txt && stripos($txt, 'ideal betaling') !== false && stripos($txt, 'afgerond') !== false) {
          $notes_hit = true; break;
        }
      }
    }
  }

  $rental = vds_get_rental_date_from_order($o);

  $scheduled = [];
  if (function_exists('as_get_scheduled_actions')) {
    $scheduled = as_get_scheduled_actions([
      'hook'     => 'vds_job_whatsapp',
      'args'     => ['order_id' => $o->get_id()],
      'status'   => (class_exists('ActionScheduler_Store') ? ActionScheduler_Store::STATUS_PENDING : 'pending'),
      'per_page' => 20,
    ], 'ids');
  }

  header('Content-Type: application/json; charset=utf-8');
  echo json_encode([
    'order_id'        => $o->get_id(),
    'status'          => $o->get_status(),
    'billing_phone'   => $o->get_billing_phone(),
    'partner_excluded'=> vds_is_partner_phone($o->get_billing_phone()) ? true : false,
    'paid_breakdown'  => [
      'get_date_paid' => $paid_date ? $paid_date->date('c') : null,
      'is_paid()'     => $is_paid,
      '_paid_date'    => $pd_meta,
      '_mollie_payment_status' => $mol_stat,
      'notes_hit_ideal_afgerond' => $notes_hit,
      'vds_is_paid()' => vds_is_paid($o),
    ],
    'rental'          => $rental,
    'scheduled_jobs'  => $scheduled,
  ], JSON_PRETTY_PRINT);
  exit;
});

// Tijdelijk test-endpoint (alleen voor admins): stuur losse topic naar Zapier
add_action('init', function () {
  if (!is_user_logged_in() || !current_user_can('manage_woocommerce')) return;
  if (empty($_GET['vds_send_test'])) return;

  $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
  $topic    = isset($_GET['topic']) ? sanitize_text_field($_GET['topic']) : 'whatsapp.install_success';

  $order = wc_get_order($order_id);
  if (!$order) wp_die('Order not found');

  vds_zap($topic, $order, ['debug' => true]);
  wp_die('Sent '.$topic.' for order '.$order_id);
});
