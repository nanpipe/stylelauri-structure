<?php
/**
 * Functional test for stylelauri-order-flow against the wp-demo site.
 * Run from C:\wp-demo\site:  php ..\wp-cli.phar eval-file <this file>
 *
 * v1.9 model: saldo derives from the ORDER itself --
 *   saldo = abono-reserva fee (deferred) - sum of "... de cuota" fees.
 * Statuses are store-created; roles map via options; dispatch is
 * hardwired to 'processing' (Merch Lista).
 */

error_reporting( E_ALL & ~E_DEPRECATED );

$GLOBALS['slo_results'] = array();
function slo_check( $name, $cond, $detail = '' ) {
	$GLOBALS['slo_results'][] = array( 'name' => $name, 'pass' => (bool) $cond, 'detail' => $detail );
	echo ( $cond ? 'PASS' : 'FAIL' ) . "  $name" . ( $detail ? "  [$detail]" : '' ) . "\n";
}

// wp-cli runs with no user; act as admin (id 1) so capability checks
// (bulk action, cuotas, mark_saldo_paid) behave like the real screen.
wp_set_current_user( 1 );

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

// ---- Helpers ----
function slo_make_product( $name, $price ) {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( $price );
	$p->set_status( 'publish' );
	$p->save();
	return $p->get_id();
}

/** Simulate the checkout's Abono Reserva fee on an existing order. */
function slo_add_abono_fee( $order, $monto ) {
	$fee = new WC_Order_Item_Fee();
	$fee->set_name( SLO_Checkout_Abono::fee_label() );
	$fee->set_amount( (string) -$monto );
	$fee->set_total( (string) -$monto );
	$order->add_item( $fee );
	$order->calculate_totals( false );
	$order->update_meta_data( '_slo_abono_checkout', '1' );
	$order->update_meta_data( SLO_Order_Balance::META_DESCUENTO, $monto );
	SLO_Order_Balance::sync_meta_mirrors( $order );
	$order->save();
}

// ---- 0. Store-side statuses (simulating the user's status plugin) ----
foreach ( array(
	'wc-st-abono'    => 'Saldo Pendiente',
	'wc-st-prod'     => 'Abono Produccion',
	'wc-st-preventa' => 'Preventa',
	'wc-st-prep'     => 'Preparacion',
) as $key => $label ) {
	register_post_status( $key, array( 'label' => $label, 'public' => false ) );
}
add_filter( 'wc_order_statuses', function ( $statuses ) {
	$statuses['wc-st-abono']    = 'Saldo Pendiente';
	$statuses['wc-st-prod']     = 'Abono Produccion';
	$statuses['wc-st-preventa'] = 'Preventa';
	$statuses['wc-st-prep']     = 'Preparacion';
	return $statuses;
} );

update_option( 'slo_status_abono', 'wc-st-abono' );
update_option( 'slo_status_produccion', 'wc-st-prod' );
update_option( 'slo_status_preventa', 'wc-st-preventa' );
update_option( 'slo_status_listo', 'wc-st-prep' );

slo_check( 'plugin does NOT register slo-* statuses', ! isset( wc_get_order_statuses()['wc-slo-abono'] ) );
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
slo_check( 'term dates stored/read', '2026-08-10' === SLO_Taxonomy::get_term_dates( $jk_id )['despacho'] );

$p_jk    = slo_make_product( 'Jersey JK test', 100 );
$p_ck    = slo_make_product( 'Hoodie CK test', 80 );
$p_stock = slo_make_product( 'Ramen test', 10 );
wp_set_object_terms( $p_jk, array( $jk_id ), 'lote_preventa' );
wp_set_object_terms( $p_ck, array( $ck_id ), 'lote_preventa' );
slo_check( 'is_preventa_product', SLO_Taxonomy::is_preventa_product( $p_jk ) && ! SLO_Taxonomy::is_preventa_product( $p_stock ) );

// ---- 2. Snapshot: multi-lote + governing date ----
$order = wc_create_order();
$order->add_product( wc_get_product( $p_jk ), 1 );
$order->add_product( wc_get_product( $p_ck ), 1 );
$order->add_product( wc_get_product( $p_stock ), 2 ); // total 200
$order->set_billing_email( 'cliente@stylelauri.test' );
$order->set_billing_first_name( 'Lau' );
$order->set_status( 'pending' );
$order->calculate_totals();
$order->save();
$oid = $order->get_id();

SLO_Order_Snapshot::recompute_snapshot_from_order( $order );
$order = wc_get_order( $oid );
slo_check( 'snapshot: 2 lotes captured', 2 === count( SLO_Order_Snapshot::get_order_lotes( $order ) ) );
slo_check( 'snapshot: governing date = latest (CK)', '2026-09-05' === SLO_Order_Snapshot::get_order_fecha_despacho( $order ) );

$o2 = wc_create_order();
$o2->add_product( wc_get_product( $p_stock ), 1 );
$o2->set_billing_email( 'cliente2@stylelauri.test' );
$o2->calculate_totals();
$o2->save();
SLO_Order_Snapshot::recompute_snapshot( $o2->get_id() );
$o2 = wc_get_order( $o2->get_id() );
slo_check( 'stock-only order: no lotes, no fecha', array() === SLO_Order_Snapshot::get_order_lotes( $o2 ) && '' === SLO_Order_Snapshot::get_order_fecha_despacho( $o2 ) );

// ---- 3. Fee-based saldo model ----
slo_add_abono_fee( $order, 150 ); // pays 50 today, defers 150
$order = wc_get_order( $oid );
slo_check( 'saldo model: WC total = 50 (paid today)', 50.0 === (float) $order->get_total(), (string) $order->get_total() );
slo_check( 'saldo model: descuento = 150 (from fee)', 150.0 === SLO_Order_Balance::get_descuento( $order ) );
slo_check( 'saldo model: saldo = 150', 150.0 === SLO_Order_Balance::get_saldo_pendiente( $order ) );
slo_check( 'saldo model: venta real = 200', 200.0 === SLO_Order_Balance::get_total_real( $order ) );
slo_check( 'saldo model: mirrors synced', 150.0 === (float) $order->get_meta( '_slo_saldo_pendiente' ) && 50.0 === (float) $order->get_meta( '_slo_monto_abonado' ) );

// Plugin sends no mail of its own.
slo_check( 'plugin email classes NOT registered', ! isset( WC()->mailer()->get_emails()['WC_Email_SLO_Abono'] ) );
slo_mail_reset();
$order->update_status( 'st-abono' );
slo_check( 'no plugin email on abono transition', 0 === count( slo_mail_subjects() ) );

// Lotes JK/CK marcados Producido: los pedidos de flujo de aqui en
// adelante NO quedan atrapados por el candado de preventa (que se prueba
// aparte en la seccion 13 con un lote de fecha futura sin producir).
update_term_meta( $jk_id, SLO_Taxonomy::META_PRODUCIDO, '1' );
update_term_meta( $ck_id, SLO_Taxonomy::META_PRODUCIDO, '1' );

// ---- 4. Reminder hook + NEVER rules on main flow ----
global $slo_reminder_fired;
$slo_reminder_fired = 0;
add_action( 'slo_saldo_reminder', function () {
	$GLOBALS['slo_reminder_fired']++;
} );
$order->update_status( 'st-prep' );
slo_check( 'slo_saldo_reminder hook fires on Preparacion with saldo', 1 === $GLOBALS['slo_reminder_fired'] );

$order->update_status( 'processing' ); // exit attempt with saldo
$order = wc_get_order( $oid );
slo_check( 'NEVER rule: exit with saldo redirected to Saldo Pendiente', 'st-abono' === $order->get_status(), $order->get_status() );

// ---- 5. Cuotas: partial payments as real order lines ----
SLO_Order_Balance::add_cuota( $order, 100, 'manual' );
$order = wc_get_order( $oid );
slo_check( 'cuota: fee line added, total grows (150)', 150.0 === (float) $order->get_total(), (string) $order->get_total() );
slo_check( 'cuota: saldo drops to 50', 50.0 === SLO_Order_Balance::get_saldo_pendiente( $order ) );
slo_check( 'cuota: fee named "... de cuota"', 0 < SLO_Order_Balance::get_cuotas_total( $order ) );
slo_check( 'cuota: mirrors follow', 50.0 === (float) $order->get_meta( '_slo_saldo_pendiente' ), (string) $order->get_meta( '_slo_saldo_pendiente' ) );
slo_check( 'cuota: venta real stays 200', 200.0 === SLO_Order_Balance::get_total_real( $order ) );

// Mark saldo as paid -> final cuota + auto-advance to Merch Lista.
$order->update_meta_data( '_slo_guia_envio', 'GUIA-XYZ-123' );
$order->save();
$order = SLO_Order_Balance::mark_saldo_paid( $oid );
slo_check( 'mark_saldo_paid: final cuota, saldo 0', 0.0 === SLO_Order_Balance::get_saldo_pendiente( $order ) );
slo_check( 'mark_saldo_paid: total = venta completa (200)', 200.0 === (float) $order->get_total(), (string) $order->get_total() );
slo_check( 'mark_saldo_paid: auto-advance to Merch Lista', 'processing' === $order->get_status(), $order->get_status() );
slo_check( 'guia meta available for templates', 'GUIA-XYZ-123' === $order->get_meta( '_slo_guia_envio' ) );

// ---- 6. Snapshot locked only on terminal statuses ----
slo_check( 'locked list = terminal only', array( 'completed', 'cancelled', 'refunded' ) === array_values( SLO_Order_Statuses::locked_snapshot_statuses() ) );
$order->update_status( 'completed' );
$order  = wc_get_order( $oid );
$before = SLO_Order_Snapshot::get_order_fecha_despacho( $order );
update_term_meta( $ck_id, 'slo_fecha_despacho', '2026-12-31' );
SLO_Order_Snapshot::recompute_snapshot( $oid );
slo_check( 'snapshot locked once completed', $before === SLO_Order_Snapshot::get_order_fecha_despacho( wc_get_order( $oid ) ) );

// ---- 7. Settings ----
slo_check( 'settings: default percent 50', 50.0 === SLO_Settings::get_percent() );
slo_check( 'settings: titulo interpolates percent', false !== strpos( SLO_Settings::get_titulo(), '50%' ) );
update_option( 'slo_status_abono', 'wc-processing' ); // audit: reserved
slo_check( 'audit: role mapped to processing treated as unmapped', ! SLO_Order_Statuses::is_mapped( 'abono' ) );
update_option( 'slo_status_abono', 'wc-st-abono' );

// ---- 8. Fee extraction: exact label only (audit) ----
$a1 = wc_create_order();
$a1->add_product( wc_get_product( $p_jk ), 1 );
$fee_ok = new WC_Order_Item_Fee();
$fee_ok->set_name( SLO_Checkout_Abono::fee_label() );
$fee_ok->set_total( -50 );
$a1->add_item( $fee_ok );
$fee_evil = new WC_Order_Item_Fee();
$fee_evil->set_name( 'Regalo Abono Reserva especial' );
$fee_evil->set_total( -30 );
$a1->add_item( $fee_evil );
$a1->save();
slo_check( 'audit: fee extraction ignores substring-named fees', 50.0 === SLO_Checkout_Abono::extract_descuento( $a1 ) );

// ---- 9. Manual cuota input: strict format + bounds (audit) ----
$a2 = wc_create_order();
$a2->add_product( wc_get_product( $p_jk ), 1 ); // 100
$a2->set_billing_email( 'audit@stylelauri.test' );
$a2->calculate_totals();
$a2->save();
slo_add_abono_fee( $a2, 50 ); // saldo 50
$a2 = wc_get_order( $a2->get_id() );

$_POST['slo_nuevo_abono'] = '1.234,56'; // invalid format
SLO_Order_Balance::save_balance_field( $a2->get_id() );
slo_check( 'audit: malformed amount rejected', 50.0 === SLO_Order_Balance::get_saldo_pendiente( wc_get_order( $a2->get_id() ) ) );

$_POST['slo_nuevo_abono'] = '-20'; // negatives not allowed (edit the line instead)
SLO_Order_Balance::save_balance_field( $a2->get_id() );
slo_check( 'audit: negative amount rejected', 50.0 === SLO_Order_Balance::get_saldo_pendiente( wc_get_order( $a2->get_id() ) ) );

$_POST['slo_nuevo_abono'] = '999999'; // exceeds saldo
SLO_Order_Balance::save_balance_field( $a2->get_id() );
slo_check( 'audit: overpay beyond saldo rejected', 50.0 === SLO_Order_Balance::get_saldo_pendiente( wc_get_order( $a2->get_id() ) ) );

$_POST['slo_nuevo_abono'] = '40'; // valid cuota
SLO_Order_Balance::save_balance_field( $a2->get_id() );
$a2 = wc_get_order( $a2->get_id() );
slo_check( 'audit: valid cuota accepted (saldo 10)', 10.0 === SLO_Order_Balance::get_saldo_pendiente( $a2 ), (string) SLO_Order_Balance::get_saldo_pendiente( $a2 ) );
unset( $_POST['slo_nuevo_abono'] );

// Manual correction path: deleting the cuota line restores the saldo.
foreach ( $a2->get_fees() as $item_id => $fee ) {
	if ( ' de cuota' === substr( $fee->get_name(), -9 ) ) {
		$a2->remove_item( $item_id );
	}
}
$a2->calculate_totals( false );
$a2->save();
$a2 = wc_get_order( $a2->get_id() );
slo_check( 'cuota line deletion restores saldo (50)', 50.0 === SLO_Order_Balance::get_saldo_pendiente( $a2 ), (string) SLO_Order_Balance::get_saldo_pendiente( $a2 ) );

// ---- 10. Saldo total row (emails) ----
$rows = SLO_Order_Balance::add_saldo_total_row( array( 'order_total' => array( 'label' => 'Total:', 'value' => 'x' ) ), $a2 );
slo_check( 'totals: saldo row added for abono order', isset( $rows['slo_saldo'] ) );
$rows_plain = SLO_Order_Balance::add_saldo_total_row( array(), $o2 );
slo_check( 'totals: untouched for normal orders', array() === $rows_plain );

// ---- 11. Dispatch gate: universal funnel ----
slo_check( 'gate: always enabled (mandatory)', SLO_Dispatch_Gate::is_enabled() );

// Fully-paid STOCK order also enters the funnel.
$g1 = wc_create_order();
$g1->add_product( wc_get_product( $p_stock ), 1 );
$g1->set_billing_email( 'gate1@stylelauri.test' );
$g1->calculate_totals();
$g1->save();
SLO_Order_Snapshot::recompute_snapshot( $g1->get_id() );
$g1->update_status( 'processing' ); // what Wompi does
$g1 = wc_get_order( $g1->get_id() );
slo_check( 'gate: stock paid enters funnel (Abono Produccion)', 'st-prod' === $g1->get_status(), $g1->get_status() );

$g1->update_status( 'processing' ); // manual bypass, no Preparacion
$g1 = wc_get_order( $g1->get_id() );
slo_check( 'gate: manual bypass without Preparacion reverted', 'st-prod' === $g1->get_status(), $g1->get_status() );

$g1->update_status( 'st-prep' );
$g1->update_status( 'processing' );
$g1 = wc_get_order( $g1->get_id() );
slo_check( 'gate: after Preparacion reaches Merch Lista', 'processing' === $g1->get_status(), $g1->get_status() );

// Paid preventa (with saldo) -> funnel first.
$g2 = wc_create_order();
$g2->add_product( wc_get_product( $p_jk ), 1 );
$g2->set_billing_email( 'gate2@stylelauri.test' );
$g2->calculate_totals();
$g2->save();
SLO_Order_Snapshot::recompute_snapshot( $g2->get_id() );
slo_add_abono_fee( $g2, 50 );
$g2 = wc_get_order( $g2->get_id() );
$g2->update_status( 'processing' );
$g2 = wc_get_order( $g2->get_id() );
slo_check( 'gate: preventa with saldo enters funnel first', 'st-prod' === $g2->get_status(), $g2->get_status() );

// After Preparacion, exit with saldo -> Saldo Pendiente; pay -> Merch Lista.
$g2->update_status( 'st-prep' );
$g2->update_status( 'processing' );
$g2 = wc_get_order( $g2->get_id() );
slo_check( 'gate: post-prep exit with saldo -> Saldo Pendiente', 'st-abono' === $g2->get_status(), $g2->get_status() );
SLO_Order_Balance::mark_saldo_paid( $g2->get_id() );
$g2 = wc_get_order( $g2->get_id() );
slo_check( 'gate: saldo 0 releases to Merch Lista', 'processing' === $g2->get_status(), $g2->get_status() );

// From arbitrary manual origin (on-hold) with saldo, paso set.
$g3 = wc_create_order();
$g3->add_product( wc_get_product( $p_stock ), 2 );
$g3->set_billing_email( 'gate3@stylelauri.test' );
$g3->calculate_totals();
$g3->save();
SLO_Order_Snapshot::recompute_snapshot( $g3->get_id() );
slo_add_abono_fee( $g3, 10 );
$g3 = wc_get_order( $g3->get_id() );
$g3->update_status( 'st-prep' );
$g3->update_status( 'on-hold' );
$g3->update_status( 'processing' );
$g3 = wc_get_order( $g3->get_id() );
slo_check( 'NEVER rule: from on-hold with saldo redirected', 'st-abono' === $g3->get_status(), $g3->get_status() );

// Abono role unmapped -> reverts (still never lands in Merch Lista).
delete_option( 'slo_status_abono' );
$g3->update_status( 'processing' );
$g3 = wc_get_order( $g3->get_id() );
slo_check( 'NEVER rule: unmapped abono role -> reverted', 'processing' !== $g3->get_status(), $g3->get_status() );
update_option( 'slo_status_abono', 'wc-st-abono' );

// Unmapped produccion: guard still keeps unprepped orders out.
delete_option( 'slo_status_produccion' );
$g4 = wc_create_order();
$g4->add_product( wc_get_product( $p_jk ), 1 );
$g4->set_billing_email( 'gate4@stylelauri.test' );
$g4->calculate_totals();
$g4->save();
SLO_Order_Snapshot::recompute_snapshot( $g4->get_id() );
$g4->update_status( 'processing' );
$g4 = wc_get_order( $g4->get_id() );
slo_check( 'gate: unmapped produccion still kept out of Merch Lista', 'processing' !== $g4->get_status(), $g4->get_status() );
update_option( 'slo_status_produccion', 'wc-st-prod' );

// ---- 12. Snapshot at creation + bulk recompute backfill ----
// woocommerce_checkout_create_order path: order object WITH items, not
// yet saved -- snapshot meta must be set before the first save (fixes:
// YAYMail emails firing before the meta existed).
$o5 = wc_create_order();
$o5->add_product( wc_get_product( $p_jk ), 1 );
SLO_Order_Snapshot::compute_on_create( $o5 ); // what the checkout hook does
slo_check( 'snapshot set during order creation (pre-save)', 1 === count( $o5->get_meta( SLO_Order_Snapshot::META_LOTES ) ) && '' !== $o5->get_meta( SLO_Order_Snapshot::META_FECHA ), $o5->get_meta( SLO_Order_Snapshot::META_FECHA ) );
$o5->save();
$o5 = wc_get_order( $o5->get_id() );

// Simulate a true pre-plugin order by wiping the meta.
$o5->delete_meta_data( SLO_Order_Snapshot::META_LOTES );
$o5->delete_meta_data( SLO_Order_Snapshot::META_FECHA );
$o5->save();
$o5 = wc_get_order( $o5->get_id() );
slo_check( 'pre-plugin order: snapshot meta absent', ! $o5->meta_exists( SLO_Order_Snapshot::META_LOTES ) );
$redirect = SLO_Order_Admin_Columns::handle_bulk_action( 'http://x/wp-admin/admin.php?page=wc-orders', 'slo_recalcular_snapshot', array( $o5->get_id() ) );
$o5 = wc_get_order( $o5->get_id() );
slo_check( 'bulk recompute: snapshot backfilled', $o5->meta_exists( SLO_Order_Snapshot::META_LOTES ) && 1 === count( SLO_Order_Snapshot::get_order_lotes( $o5 ) ) );

// ---- 13. Preventa date-lock (no Preparacion before the lote is ready) ----
slo_check( 'preventa role mapped', 'st-preventa' === SLO_Order_Statuses::get_status( 'preventa' ) );

$fut    = wp_insert_term( 'FUT-lock-' . time(), 'lote_preventa' );
$fut_id = $fut['term_id'];
update_term_meta( $fut_id, 'slo_fecha_despacho', '2099-12-31' ); // far future
$p_fut  = slo_make_product( 'Preorder FUT test', 60 );
wp_set_object_terms( $p_fut, array( $fut_id ), 'lote_preventa' );

// Locked order sitting in Preventa: trying to pack early reverts to
// Preventa (stays put -- NOT dragged back to Abono Produccion).
$l1 = wc_create_order();
$l1->add_product( wc_get_product( $p_fut ), 1 );
$l1->set_billing_email( 'lock1@stylelauri.test' );
$l1->calculate_totals();
$l1->save();
SLO_Order_Snapshot::recompute_snapshot( $l1->get_id() );
$l1 = wc_get_order( $l1->get_id() );
slo_check( 'lock: preventa with future date is locked', SLO_Dispatch_Gate::is_preventa_locked( $l1 ) );
$l1->update_status( 'st-preventa' );
$l1->update_status( 'st-prep' ); // try to pack early
$l1 = wc_get_order( $l1->get_id() );
slo_check( 'lock: blocked, stays in Preventa (not dragged to Produccion)', 'st-preventa' === $l1->get_status(), $l1->get_status() );
slo_check( 'lock: paso_por_listo NOT set while locked', '1' !== $l1->get_meta( SLO_Dispatch_Gate::META_PASO_LISTO ) );

// Lock also blocks from Abono Produccion, staying there.
$l1b = wc_create_order();
$l1b->add_product( wc_get_product( $p_fut ), 1 );
$l1b->set_billing_email( 'lock1b@stylelauri.test' );
$l1b->calculate_totals();
$l1b->save();
SLO_Order_Snapshot::recompute_snapshot( $l1b->get_id() );
$l1b->update_status( 'st-prod' );
$l1b->update_status( 'st-prep' );
$l1b = wc_get_order( $l1b->get_id() );
slo_check( 'lock: blocked from Abono Produccion, stays there', 'st-prod' === $l1b->get_status(), $l1b->get_status() );

// Override A: mark lote Producido -> unlocks; reaches Preparacion.
update_term_meta( $fut_id, SLO_Taxonomy::META_PRODUCIDO, '1' );
$l1 = wc_get_order( $l1->get_id() );
slo_check( 'lock: Producido lote unlocks the order', ! SLO_Dispatch_Gate::is_preventa_locked( $l1 ) );
$l1->update_status( 'st-prep' );
$l1 = wc_get_order( $l1->get_id() );
slo_check( 'lock: after Producido reaches Preparacion', 'st-prep' === $l1->get_status(), $l1->get_status() );
slo_check( 'lock: paso_por_listo set once unlocked', '1' === $l1->get_meta( SLO_Dispatch_Gate::META_PASO_LISTO ) );
update_term_meta( $fut_id, SLO_Taxonomy::META_PRODUCIDO, '0' ); // reset for next case

// Override B: manual release FROM PREVENTA -> auto-advances to Preparacion.
$l2 = wc_create_order();
$l2->add_product( wc_get_product( $p_fut ), 1 );
$l2->set_billing_email( 'lock2@stylelauri.test' );
$l2->calculate_totals();
$l2->save();
SLO_Order_Snapshot::recompute_snapshot( $l2->get_id() );
$l2->update_status( 'st-preventa' );
$l2 = wc_get_order( $l2->get_id() );
slo_check( 'lock: still locked (Producido was reset)', SLO_Dispatch_Gate::is_preventa_locked( $l2 ) );
SLO_Dispatch_Gate::liberar_preventa( $l2->get_id() );
$l2 = wc_get_order( $l2->get_id() );
slo_check( 'lock: release from Preventa advances to Preparacion', 'st-prep' === $l2->get_status(), $l2->get_status() );
slo_check( 'lock: release flag persisted', '1' === $l2->get_meta( SLO_Dispatch_Gate::META_LIBERADO ) );

// Override B2: manual release FROM ABONO PRODUCCION -> only unlocks, does
// NOT auto-advance (etiqueta stage: jumping to Preparacion loses it).
$l4 = wc_create_order();
$l4->add_product( wc_get_product( $p_fut ), 1 );
$l4->set_billing_email( 'lock4@stylelauri.test' );
$l4->calculate_totals();
$l4->save();
SLO_Order_Snapshot::recompute_snapshot( $l4->get_id() );
$l4->update_status( 'st-prod' );
$l4 = wc_get_order( $l4->get_id() );
slo_check( 'lock: locked in Abono Produccion', SLO_Dispatch_Gate::is_preventa_locked( $l4 ) );
SLO_Dispatch_Gate::liberar_preventa( $l4->get_id() );
$l4 = wc_get_order( $l4->get_id() );
slo_check( 'lock: release from Abono Produccion does NOT auto-advance', 'st-prod' === $l4->get_status(), $l4->get_status() );
slo_check( 'lock: released order no longer locked', ! SLO_Dispatch_Gate::is_preventa_locked( $l4 ) );
$l4->update_status( 'st-prep' ); // now movable by hand
$l4 = wc_get_order( $l4->get_id() );
slo_check( 'lock: released order can then be moved to Preparacion', 'st-prep' === $l4->get_status(), $l4->get_status() );

// Override C: dispatch date already reached -> not locked.
$past    = wp_insert_term( 'PAST-lock-' . time(), 'lote_preventa' );
$past_id = $past['term_id'];
update_term_meta( $past_id, 'slo_fecha_despacho', '2020-01-01' );
$p_past  = slo_make_product( 'Past preorder test', 40 );
wp_set_object_terms( $p_past, array( $past_id ), 'lote_preventa' );
$l3 = wc_create_order();
$l3->add_product( wc_get_product( $p_past ), 1 );
$l3->set_billing_email( 'lock3@stylelauri.test' );
$l3->calculate_totals();
$l3->save();
SLO_Order_Snapshot::recompute_snapshot( $l3->get_id() );
$l3 = wc_get_order( $l3->get_id() );
slo_check( 'lock: past dispatch date not locked', ! SLO_Dispatch_Gate::is_preventa_locked( $l3 ) );
$l3->update_status( 'st-prod' );
$l3->update_status( 'st-prep' );
$l3 = wc_get_order( $l3->get_id() );
slo_check( 'lock: past-date order reaches Preparacion', 'st-prep' === $l3->get_status(), $l3->get_status() );

// Stock-only order is never locked.
slo_check( 'lock: stock-only order never locked', ! SLO_Dispatch_Gate::is_preventa_locked( $o2 ) );

// ---- Cleanup mapping options ----
delete_option( 'slo_status_abono' );
delete_option( 'slo_status_produccion' );
delete_option( 'slo_status_preventa' );
delete_option( 'slo_status_listo' );

// ---- Summary ----
$all  = $GLOBALS['slo_results'];
$fail = array_filter( $all, function ( $r ) { return ! $r['pass']; } );
echo "\n" . ( count( $fail ) ? count( $fail ) . ' FAILURES of ' . count( $all ) : 'ALL ' . count( $all ) . ' CHECKS PASSED' ) . "\n";
