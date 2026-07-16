<?php
/**
 * Functional test for stylelauri-order-flow against the wp-demo site.
 * Run from C:\wp-demo\site:  php ..\wp-cli.phar eval-file <this file>
 *
 * v1.4.0: the plugin no longer registers its own statuses. This test
 * registers four store-side statuses (simulating the user's own status
 * plugin) and maps the plugin roles to them via options.
 */

error_reporting( E_ALL & ~E_DEPRECATED );

$GLOBALS['slo_results'] = array();
function slo_check( $name, $cond, $detail = '' ) {
	$GLOBALS['slo_results'][] = array( 'name' => $name, 'pass' => (bool) $cond, 'detail' => $detail );
	echo ( $cond ? 'PASS' : 'FAIL' ) . "  $name" . ( $detail ? "  [$detail]" : '' ) . "\n";
}

// ---- Capture all outgoing mail ----
global $slo_mail;
$slo_mail = array();
add_filter( 'pre_wp_mail', function ( $null, $atts ) {
	global $slo_mail;
	$slo_mail[] = $atts;
	return true;
}, 10, 2 );
function slo_mail_subjects() {
	global $slo_mail;
	return array_map( function ( $m ) { return $m['subject']; }, $slo_mail );
}
function slo_mail_reset() {
	global $slo_mail;
	$slo_mail = array();
}

// ---- 0. Store-side statuses (simulating the user's status plugin) ----
foreach ( array(
	'wc-st-abono'   => 'Abono pendiente',
	'wc-st-prod'    => 'Produccion/Reserva',
	'wc-st-prep'    => 'Preparacion',
	'wc-st-enviado' => 'Enviado (tienda)',
) as $key => $label ) {
	register_post_status( $key, array( 'label' => $label, 'public' => false ) );
}
add_filter( 'wc_order_statuses', function ( $statuses ) {
	$statuses['wc-st-abono']   = 'Abono pendiente';
	$statuses['wc-st-prod']    = 'Produccion/Reserva';
	$statuses['wc-st-prep']    = 'Preparacion';
	$statuses['wc-st-enviado'] = 'Enviado (tienda)';
	return $statuses;
} );

// Map plugin roles to those statuses (what the user does in Ajustes).
update_option( 'slo_status_abono', 'wc-st-abono' );
update_option( 'slo_status_produccion', 'wc-st-prod' );
update_option( 'slo_status_listo', 'wc-st-prep' );
update_option( 'slo_status_enviado', 'wc-st-enviado' );

slo_check( 'plugin does NOT register slo-* statuses', ! isset( wc_get_order_statuses()['wc-slo-abono'] ) && ! isset( wc_get_order_statuses()['wc-slo-enviado'] ) );
slo_check( 'roles mapped to store statuses', 'st-abono' === SLO_Order_Statuses::get_status( 'abono' ) && 'st-prep' === SLO_Order_Statuses::get_status( 'listo' ) );

// ---- 1. Taxonomy + lote dates ----
slo_check( 'taxonomy lote_preventa registered', taxonomy_exists( 'lote_preventa' ) );

$jk = wp_insert_term( 'JK-Agosto-' . time(), 'lote_preventa' );
$ck = wp_insert_term( 'CK-Sept-' . time(), 'lote_preventa' );
$jk_id = $jk['term_id'];
$ck_id = $ck['term_id'];
update_term_meta( $jk_id, 'slo_fecha_cierre', '2026-07-15' );
update_term_meta( $jk_id, 'slo_fecha_despacho', '2026-08-10' );
update_term_meta( $ck_id, 'slo_fecha_cierre', '2026-08-01' );
update_term_meta( $ck_id, 'slo_fecha_despacho', '2026-09-05' );

$jk_dates = SLO_Taxonomy::get_term_dates( $jk_id );
slo_check( 'term dates stored/read', '2026-08-10' === $jk_dates['despacho'] );

// ---- 2. Products ----
function slo_make_product( $name, $price ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( $price );
	$p->set_status( 'publish' );
	$p->save();
	return $p->get_id();
}
$p_jk    = slo_make_product( 'Jersey JK test', 100 );
$p_ck    = slo_make_product( 'Hoodie CK test', 80 );
$p_stock = slo_make_product( 'Ramen test', 10 );
wp_set_object_terms( $p_jk, array( $jk_id ), 'lote_preventa' );
wp_set_object_terms( $p_ck, array( $ck_id ), 'lote_preventa' );

slo_check( 'is_preventa_product', SLO_Taxonomy::is_preventa_product( $p_jk ) && ! SLO_Taxonomy::is_preventa_product( $p_stock ) );

// ---- 3. Snapshot: multi-lote + governing date ----
$order = wc_create_order();
$order->add_product( wc_get_product( $p_jk ), 1 );
$order->add_product( wc_get_product( $p_ck ), 1 );
$order->add_product( wc_get_product( $p_stock ), 2 );
$order->set_billing_email( 'cliente@stylelauri.test' );
$order->set_billing_first_name( 'Lau' );
$order->set_status( 'pending' );
$order->calculate_totals();
$order->save();
$oid = $order->get_id();

SLO_Order_Snapshot::recompute_snapshot_from_order( $order );
$order = wc_get_order( $oid );
$lotes = SLO_Order_Snapshot::get_order_lotes( $order );
$fecha = SLO_Order_Snapshot::get_order_fecha_despacho( $order );
slo_check( 'snapshot: 2 lotes captured', 2 === count( $lotes ), implode( ',', $lotes ) );
slo_check( 'snapshot: governing date = latest (CK)', '2026-09-05' === $fecha, $fecha );

$o2 = wc_create_order();
$o2->add_product( wc_get_product( $p_stock ), 1 );
$o2->set_billing_email( 'cliente2@stylelauri.test' );
$o2->calculate_totals();
$o2->save();
SLO_Order_Snapshot::recompute_snapshot( $o2->get_id() );
$o2 = wc_get_order( $o2->get_id() );
slo_check( 'stock-only order: no lotes, no fecha', array() === SLO_Order_Snapshot::get_order_lotes( $o2 ) && '' === SLO_Order_Snapshot::get_order_fecha_despacho( $o2 ) );

// ---- 4. Plugin sends NO emails of its own (YAYMail/status plugin owns mail) ----
slo_check( 'plugin email classes NOT registered', ! isset( WC()->mailer()->get_emails()['WC_Email_SLO_Abono'] ) && ! class_exists( 'WC_Email_SLO_Enviado' ) );

slo_mail_reset();
$order->update_meta_data( '_slo_monto_abonado', 50 ); // total = 200
$order->save();
$order->update_status( 'st-abono' );
slo_check( 'no plugin email on abono transition', 0 === count( slo_mail_subjects() ), implode( ' | ', slo_mail_subjects() ) );

$saldo = SLO_Order_Balance::get_saldo_pendiente( $order );
slo_check( 'saldo pendiente = 150', 150.0 === $saldo, (string) $saldo );

// ---- 5. Reminder HOOK on mapped listo, block on mapped enviado ----
global $slo_reminder_fired;
$slo_reminder_fired = 0;
add_action( 'slo_saldo_reminder', function () {
	$GLOBALS['slo_reminder_fired']++;
} );
slo_mail_reset();
$order->update_status( 'st-prep' );
slo_check( 'slo_saldo_reminder hook fires on mapped listo', 1 === $GLOBALS['slo_reminder_fired'], (string) $GLOBALS['slo_reminder_fired'] );
slo_check( 'no plugin email on listo transition', 0 === count( slo_mail_subjects() ) );

slo_mail_reset();
$order->update_status( 'st-enviado' );
$order = wc_get_order( $oid );
$subjects = slo_mail_subjects();
slo_check( 'mapped enviado blocked with saldo, reverted', 'st-prep' === $order->get_status(), $order->get_status() );
slo_check( 'blocked enviado: zero emails', 0 === count( $subjects ), implode( ' | ', $subjects ) );

// NEVER rule: attempt to reach Merch Lista (processing) with saldo ->
// REDIRECTED to Saldo Pendiente (mapped abono role), any origin.
$order->update_status( 'processing' );
$order = wc_get_order( $oid );
slo_check( 'NEVER rule: listo->processing with saldo redirected to Saldo Pendiente', 'st-abono' === $order->get_status(), $order->get_status() );

// ---- 6. Pay saldo -> auto-advance to processing; saldo meta persisted ----
$order->update_meta_data( '_slo_guia_envio', 'GUIA-XYZ-123' );
$order->save();
SLO_Order_Balance::mark_saldo_paid( $oid ); // registers ledger entry, saldo 0
$order = wc_get_order( $oid );
slo_check( 'saldo 0 auto-advances Saldo Pendiente -> Merch Lista', 'processing' === $order->get_status(), $order->get_status() );
slo_check( 'saldo meta persisted for YAYMail (_slo_saldo_pendiente = 0)', '0' === (string) (float) $order->get_meta( '_slo_saldo_pendiente' ), (string) $order->get_meta( '_slo_saldo_pendiente' ) );
slo_check( 'guia meta available for templates', 'GUIA-XYZ-123' === $order->get_meta( '_slo_guia_envio' ) );

$order->update_status( 'st-enviado' );
$order = wc_get_order( $oid );
slo_check( 'enviado passes with saldo 0', 'st-enviado' === $order->get_status(), $order->get_status() );

// ---- 7. Snapshot locked on mapped enviado ----
$before = SLO_Order_Snapshot::get_order_fecha_despacho( $order );
update_term_meta( $ck_id, 'slo_fecha_despacho', '2026-12-31' );
SLO_Order_Snapshot::recompute_snapshot( $oid );
$after = SLO_Order_Snapshot::get_order_fecha_despacho( wc_get_order( $oid ) );
slo_check( 'snapshot locked once mapped enviado', $before === $after, "$before vs $after" );
slo_check( 'locked list follows mapping', in_array( 'st-enviado', SLO_Order_Statuses::locked_snapshot_statuses(), true ) );

// ---- 8. Settings / abono checkout model ----
slo_check( 'settings: default percent 50', 50.0 === SLO_Settings::get_percent() );
slo_check( 'settings: titulo interpolates percent', false !== strpos( SLO_Settings::get_titulo(), '50%' ), SLO_Settings::get_titulo() );

$o4 = wc_create_order();
$o4->add_product( wc_get_product( $p_jk ), 1 ); // 100
$o4->set_billing_email( 'abono@stylelauri.test' );
$o4->set_billing_first_name( 'Abo' );
$fee = new WC_Order_Item_Fee();
$fee->set_name( 'Abono Reserva (pagas 50% hoy)' );
$fee->set_amount( -50 );
$fee->set_total( -50 );
$o4->add_item( $fee );
$o4->calculate_totals();
$o4->save();
SLO_Order_Snapshot::recompute_snapshot( $o4->get_id() );
$o4->update_meta_data( '_slo_abono_checkout', '1' );
$o4->update_meta_data( SLO_Order_Balance::META_DESCUENTO, 50 );
$o4->save();
SLO_Order_Balance::add_abono( $o4, (float) $o4->get_total(), 'checkout' );
$o4 = wc_get_order( $o4->get_id() );

slo_check( 'abono model: total real = 100', 100.0 === SLO_Order_Balance::get_total_real( $o4 ), (string) SLO_Order_Balance::get_total_real( $o4 ) );
slo_check( 'abono model: saldo = 50 (deferred)', 50.0 === SLO_Order_Balance::get_saldo_pendiente( $o4 ), (string) SLO_Order_Balance::get_saldo_pendiente( $o4 ) );

// Router: paid preventa with saldo -> FUNNEL FIRST (Abono Produccion),
// not Saldo Pendiente -- saldo is collected after Preparacion.
slo_mail_reset();
$o4->update_status( 'processing' );
$o4 = wc_get_order( $o4->get_id() );
slo_check( 'gate: preventa with saldo enters funnel first (produccion)', 'st-prod' === $o4->get_status(), $o4->get_status() );
$has_processing_mail = false;
foreach ( slo_mail_subjects() as $s ) {
	if ( false !== stripos( $s, 'procesando' ) || false !== stripos( $s, 'processing' ) ) { $has_processing_mail = true; }
}
slo_check( 'gate: native processing email suppressed when routed', ! $has_processing_mail, implode( ' | ', slo_mail_subjects() ) );

// ---- 9. Abono totals rows (emails / customer-facing tables) ----
$rows = SLO_Order_Balance::add_abono_total_rows( array( 'order_total' => array( 'label' => 'Total:', 'value' => '50' ) ), $o4 );
$row_keys = array_keys( $rows );
slo_check( 'totals rows: abono row added', in_array( 'slo_abono_0', $row_keys, true ), wp_json_encode( $row_keys ) );
slo_check( 'totals rows: saldo row added', isset( $rows['slo_saldo'] ) && false !== strpos( wp_strip_all_tags( $rows['slo_saldo']['value'] ), '50' ), isset( $rows['slo_saldo'] ) ? wp_strip_all_tags( $rows['slo_saldo']['value'] ) : '' );
$rows_plain = SLO_Order_Balance::add_abono_total_rows( array(), $o2 ); // order without abono data
slo_check( 'totals rows: untouched for normal orders', array() === $rows_plain );

// ---- 10. Ledger behavior ----
$o6 = wc_create_order();
$o6->add_product( wc_get_product( $p_jk ), 2 ); // 200
$o6->set_billing_email( 'ledger@stylelauri.test' );
$o6->calculate_totals();
$o6->save();
SLO_Order_Balance::add_abono( $o6, 60, 'manual' );
SLO_Order_Balance::add_abono( $o6, 40, 'manual' );
$o6 = wc_get_order( $o6->get_id() );
$ledger6 = SLO_Order_Balance::get_abonos( $o6 );
slo_check( 'ledger: 2 manual abonos, sum 100', 2 === count( $ledger6 ) && 100.0 === (float) $o6->get_meta( '_slo_monto_abonado' ) );
slo_check( 'ledger: entries carry dates', ! empty( $ledger6[0]['fecha'] ) && ! empty( $ledger6[1]['fecha'] ) );
SLO_Order_Balance::add_abono( $o6, -10, 'manual' );
$o6 = wc_get_order( $o6->get_id() );
slo_check( 'ledger: negative correction (total 90)', 90.0 === (float) $o6->get_meta( '_slo_monto_abonado' ), (string) $o6->get_meta( '_slo_monto_abonado' ) );

// Legacy seed: old order with abonado meta but no ledger.
$o8 = wc_create_order();
$o8->add_product( wc_get_product( $p_jk ), 1 );
$o8->save();
$o8->update_meta_data( '_slo_monto_abonado', 30 );
$o8->save();
SLO_Order_Balance::add_abono( $o8, 20, 'manual' );
$o8 = wc_get_order( $o8->get_id() );
$ledger8 = SLO_Order_Balance::get_abonos( $o8 );
slo_check( 'ledger: legacy abonado seeded as previo', 2 === count( $ledger8 ) && 'previo' === $ledger8[0]['origen'] && 50.0 === (float) $o8->get_meta( '_slo_monto_abonado' ), wp_json_encode( wp_list_pluck( $ledger8, 'origen' ) ) );

// ---- 11. Dispatch gate core paths ----
slo_check( 'gate: enabled by default', SLO_Dispatch_Gate::is_enabled() );

// Stock paid, no abono data -> stays processing.
$g1 = wc_create_order();
$g1->add_product( wc_get_product( $p_stock ), 1 );
$g1->set_billing_email( 'gate1@stylelauri.test' );
$g1->calculate_totals();
$g1->save();
SLO_Order_Snapshot::recompute_snapshot( $g1->get_id() );
$g1->update_status( 'processing' );
$g1 = wc_get_order( $g1->get_id() );
slo_check( 'gate: stock paid stays processing', 'processing' === $g1->get_status(), $g1->get_status() );
slo_check( 'gate: no-abono order saldo 0', 0.0 === SLO_Order_Balance::get_saldo_pendiente( $g1 ) );

// Paid preventa -> routed to mapped produccion.
$g2 = wc_create_order();
$g2->add_product( wc_get_product( $p_jk ), 1 );
$g2->set_billing_email( 'gate2@stylelauri.test' );
$g2->calculate_totals();
$g2->save();
SLO_Order_Snapshot::recompute_snapshot( $g2->get_id() );
$g2->update_status( 'processing' );
$g2 = wc_get_order( $g2->get_id() );
slo_check( 'gate: paid preventa routed to mapped produccion', 'st-prod' === $g2->get_status(), $g2->get_status() );

// After passing listo, a payment-flow re-entry sticks.
$g2->update_status( 'st-prep' );
$g2->update_status( 'pending' );
$g2 = wc_get_order( $g2->get_id() );
$g2->update_status( 'processing' );
$g2 = wc_get_order( $g2->get_id() );
slo_check( 'gate: preventa after listo stays processing', 'processing' === $g2->get_status(), $g2->get_status() );

// Unmapped roles disable automatisms safely.
delete_option( 'slo_status_produccion' );
$g3 = wc_create_order();
$g3->add_product( wc_get_product( $p_jk ), 1 );
$g3->set_billing_email( 'gate3@stylelauri.test' );
$g3->calculate_totals();
$g3->save();
SLO_Order_Snapshot::recompute_snapshot( $g3->get_id() );
$g3->update_status( 'processing' );
$g3 = wc_get_order( $g3->get_id() );
slo_check( 'gate: unmapped produccion -> preventa stays processing (no crash)', 'processing' === $g3->get_status(), $g3->get_status() );
update_option( 'slo_status_produccion', 'wc-st-prod' );

// ---- 11b. NEVER rule: saldo can't reach Merch Lista from ANY origin ----
// Stock order (not preventa) with partial abono, payment flow origin.
$n1 = wc_create_order();
$n1->add_product( wc_get_product( $p_stock ), 2 ); // 20
$n1->set_billing_email( 'never1@stylelauri.test' );
$n1->calculate_totals();
$n1->save();
SLO_Order_Snapshot::recompute_snapshot( $n1->get_id() );
SLO_Order_Balance::add_abono( $n1, 5, 'manual' ); // saldo 15
$n1->update_status( 'processing' ); // from pending
$n1 = wc_get_order( $n1->get_id() );
slo_check( 'NEVER rule: stock with saldo from pending -> Saldo Pendiente', 'st-abono' === $n1->get_status(), $n1->get_status() );

// From an arbitrary manual origin (on-hold).
$n1->update_status( 'on-hold' );
$n1->update_status( 'processing' );
$n1 = wc_get_order( $n1->get_id() );
slo_check( 'NEVER rule: from on-hold also redirected', 'st-abono' === $n1->get_status(), $n1->get_status() );

// Saldo paid in Saldo Pendiente -> auto-advances to Merch Lista.
SLO_Order_Balance::mark_saldo_paid( $n1->get_id() );
$n1 = wc_get_order( $n1->get_id() );
slo_check( 'NEVER rule: saldo 0 releases to Merch Lista', 'processing' === $n1->get_status(), $n1->get_status() );

// Abono role UNMAPPED -> reverts instead of redirecting (still never lands).
delete_option( 'slo_status_abono' );
$n2 = wc_create_order();
$n2->add_product( wc_get_product( $p_stock ), 2 );
$n2->set_billing_email( 'never2@stylelauri.test' );
$n2->calculate_totals();
$n2->save();
SLO_Order_Snapshot::recompute_snapshot( $n2->get_id() );
SLO_Order_Balance::add_abono( $n2, 5, 'manual' );
$n2->update_status( 'processing' );
$n2 = wc_get_order( $n2->get_id() );
slo_check( 'NEVER rule: unmapped abono role -> reverted, not processing', 'processing' !== $n2->get_status(), $n2->get_status() );
update_option( 'slo_status_abono', 'wc-st-abono' );

// ---- 12. Bulk recompute backfill ----
$o5 = wc_create_order();
$o5->add_product( wc_get_product( $p_jk ), 1 );
$o5->save();
$o5 = wc_get_order( $o5->get_id() );
slo_check( 'pre-plugin order: snapshot meta absent', ! $o5->meta_exists( SLO_Order_Snapshot::META_LOTES ) );
$redirect = SLO_Order_Admin_Columns::handle_bulk_action( 'http://x/wp-admin/admin.php?page=wc-orders', 'slo_recalcular_snapshot', array( $o5->get_id() ) );
$o5 = wc_get_order( $o5->get_id() );
slo_check( 'bulk recompute: snapshot backfilled', $o5->meta_exists( SLO_Order_Snapshot::META_LOTES ) && 1 === count( SLO_Order_Snapshot::get_order_lotes( $o5 ) ) );

// ---- 13. Saldo meta stays in sync through ledger changes ----
slo_check( 'saldo meta synced after abonos (o6: 200-90=110)', 110.0 === (float) $o6->get_meta( '_slo_saldo_pendiente' ), (string) $o6->get_meta( '_slo_saldo_pendiente' ) );

// ---- Cleanup mapping options (leave demo site as-was) ----
delete_option( 'slo_status_abono' );
delete_option( 'slo_status_produccion' );
delete_option( 'slo_status_listo' );
delete_option( 'slo_status_enviado' );

// ---- Summary ----
$all  = $GLOBALS['slo_results'];
$fail = array_filter( $all, function ( $r ) { return ! $r['pass']; } );
echo "\n" . ( count( $fail ) ? count( $fail ) . ' FAILURES of ' . count( $all ) : 'ALL ' . count( $all ) . ' CHECKS PASSED' ) . "\n";
