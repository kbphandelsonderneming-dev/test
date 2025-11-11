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

// Robuuste betaalcheck: dekt gateways, custom statussen en handmatig
function vds_is_paid(WC_Order $order){
  if (!$order) return false;

  // 1) Standaard Woo
  if (!empty($order->get_date_paid())) return true;
  if (method_exists($order, 'is_paid') && $order->is_paid()) return true;

  // 2) Meta die Woo soms zet
  $pd = $order->get_meta('_paid_date');
  if (!empty($pd)) return true;

  // 3) Gateway hints (Mollie varieert)
  $mol_status = $order->get_meta('_mollie_payment_status');
  if ($mol_status && strtolower($mol_status) === 'paid') return true;

  // 4) Fallback: herken Mollie notitie
  if (function_exists('wc_get_order_notes')) {
    $notes = wc_get_order_notes(['order_id'=>$order->get_id(), 'type'=>'any', 'order'=>'DESC', 'per_page'=>5]);
    if (is_array($notes)) {
      foreach ($notes as $n) {
        $txt = is_object($n) && method_exists($n,'get_content') ? $n->get_content() : (string)($n->content ?? '');
        if ($txt && stripos($txt, 'ideal betaling') !== false && stripos($txt, 'afgerond') !== false) {
          return true;
        }
      }
    }
  }
  return false;
}

// Huurdatum uit item-meta van product 3173 (of variatie-parent)
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
    $materials_ts = vds_ts_nl($iso, '07:18:00'); // direct daarna
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
 *    - status handmatig op 'huur-betalingen-ontvangen' (overboeking).
 */

// 1) Bij statuswijziging: stuur/payment-ok en plan waar van toepassing
add_action('woocommerce_order_status_changed', function($order_id, $old, $new){
  if (!in_array($new, ['leveren-ophalen','huur-afhalen','verhuur-afgerond','verzonden-tgv','afgehaald','huur-betalingen-ontvangen'], true)) return;

  $order = wc_get_order($order_id);
  if (!$order) return;

  vds_log('status_changed', [
    'order' => $order_id,
    'old'   => $old,
    'new'   => $new,
    'paid'  => vds_is_paid($order),
    'is_partner' => vds_is_partner_phone($order->get_billing_phone()),
  ]);

  // Partner uitsluiten
  if (vds_is_partner_phone($order->get_billing_phone())) return;

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
  if ($new === 'huur-betalingen-ontvangen') {
    $rental_status = null;
    if (in_array($old, ['leveren-ophalen','huur-afhalen'], true)) {
      $rental_status = $old;
    } else {
      $rental_status = vds_get_last_rental_status($order);
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

  if (vds_is_partner_phone($order->get_billing_phone())) return;

  // Zet status op huur-betalingen-ontvangen zodat de status-hook de flow start
  if ($order->get_status() !== 'huur-betalingen-ontvangen') {
    vds_stamp_paid($order);
    $order->update_status('huur-betalingen-ontvangen', 'Automatisch gezet na betaling (VDS).');
    return;
  }

  // Als hij al op huur-betalingen-ontvangen stond, verzeker alsnog dat de flow draait
  $rental_status = vds_resolve_rental_status($order);
  if ($rental_status) {
    vds_stamp_paid($order);
    vds_send_payment_ok($order, $rental_status);
    vds_schedule_after_payment($order, $rental_status, true);
  }
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
