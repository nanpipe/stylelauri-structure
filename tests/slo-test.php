<?php
/**
 * Functional test for stylelauri-order-flow against the wp-demo site.
 * Run: wp eval-file slo-test.php
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

// ---- 1. Taxonomy exists ----
slo_check( 'taxonomy lote_preventa registered', taxonomy_exists( 'lote_preventa' ) );

// ---- 2. Create two lotes with dates (JK earlier, CK later) ----
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

// ---- 3. Products: one JK, one CK, one stock ----
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

// ---- 4. Order touching JK + CK + stock -> snapshot ----
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

// Simulate Checkout Block hook path (adapter takes WC_Order).
SLO_Order_Snapshot::recompute_snapshot_from_order( $order );

$order = wc_get_order( $oid );
$lotes = SLO_Order_Snapshot::get_order_lotes( $order );
$fecha = SLO_Order_Snapshot::get_order_fecha_despacho( $order );
slo_check( 'snapshot: 2 lotes captured', 2 === count( $lotes ), implode( ',', $lotes ) );
slo_check( 'snapshot: governing date = latest (CK)', '2026-09-05' === $fecha, $fecha );
slo_check( 'order_is_preventa', SLO_Order_Snapshot::order_is_preventa( $order ) );

// ---- 5. Stock-only order -> empty snapshot ----
$o2 = wc_create_order();
$o2->add_product( wc_get_product( $p_stock ), 1 );
$o2->set_billing_email( 'cliente2@stylelauri.test' );
$o2->calculate_totals();
$o2->save();
SLO_Order_Snapshot::recompute_snapshot( $o2->get_id() );
$o2 = wc_get_order( $o2->get_id() );
slo_check( 'stock-only order: no lotes, no fecha', array() === SLO_Order_Snapshot::get_order_lotes( $o2 ) && '' === SLO_Order_Snapshot::get_order_fecha_despacho( $o2 ) );

// ---- 6. Abono -> abono email fires ----
slo_mail_reset();
$order->update_meta_data( '_slo_monto_abonado', 50 ); // total = 200
$order->save();
$order->update_status( 'slo-abono' );
$subjects = slo_mail_subjects();
slo_check( 'abono email sent on -> slo-abono', 1 === count( $subjects ) && false !== stripos( $subjects[0], 'abono' ), implode( ' | ', $subjects ) );
slo_check( 'subject placeholder {order_number} replaced', 1 === count( $subjects ) && false === strpos( $subjects[0], '{order_number}' ) && false !== strpos( $subjects[0], (string) $oid ), $subjects[0] ?? '' );

$saldo = SLO_Order_Balance::get_saldo_pendiente( $order );
slo_check( 'saldo pendiente = 150', 150.0 === $saldo, (string) $saldo );

// ---- 7. Listo with saldo -> reminder email ----
slo_mail_reset();
$order->update_status( 'slo-listo' );
$subjects = slo_mail_subjects();
slo_check( 'saldo reminder sent on -> slo-listo', 1 === count( $subjects ) && false !== stripos( $subjects[0], 'saldo' ), implode( ' | ', $subjects ) );

// ---- 8. Enviado with saldo -> BLOCKED, no email at all ----
slo_mail_reset();
$order->update_status( 'slo-enviado' );
$order = wc_get_order( $oid );
$subjects = slo_mail_subjects();
slo_check( 'enviado blocked: status reverted to slo-listo', 'slo-listo' === $order->get_status(), $order->get_status() );
slo_check( 'enviado blocked: ZERO emails sent', 0 === count( $subjects ), implode( ' | ', $subjects ) );

// ---- 8b. Revert-to-abono path: abono order tries enviado -> no re-abono email ----
$o3 = wc_create_order();
$o3->add_product( wc_get_product( $p_jk ), 1 );
$o3->set_billing_email( 'cliente3@stylelauri.test' );
$o3->set_billing_first_name( 'Rev' );
$o3->calculate_totals();
$o3->save();
SLO_Order_Snapshot::recompute_snapshot( $o3->get_id() );
$o3->update_meta_data( '_slo_monto_abonado', 10 );
$o3->save();
$o3->update_status( 'slo-abono' ); // legit abono email here
slo_mail_reset();
$o3->update_status( 'slo-enviado' ); // guard reverts enviado -> slo-abono
$o3 = wc_get_order( $o3->get_id() );
$subjects = slo_mail_subjects();
slo_check( 'revert enviado->abono: status back to slo-abono', 'slo-abono' === $o3->get_status(), $o3->get_status() );
slo_check( 'revert enviado->abono: NO abono re-email', 0 === count( $subjects ), implode( ' | ', $subjects ) );

// ---- 9. Pay saldo, set guia, -> enviado passes + email has guia + fecha logic ----
$order->update_meta_data( '_slo_monto_abonado', $order->get_total() );
$order->update_meta_data( '_slo_guia_envio', 'GUIA-XYZ-123' );
$order->save();
slo_mail_reset();
$order->update_status( 'slo-enviado' );
$order = wc_get_order( $oid );
$subjects = slo_mail_subjects();
global $slo_mail;
$body = count( $slo_mail ) ? ( is_array( $slo_mail[0]['message'] ) ? implode( '', $slo_mail[0]['message'] ) : $slo_mail[0]['message'] ) : '';
slo_check( 'enviado passes with saldo 0', 'slo-enviado' === $order->get_status(), $order->get_status() );
slo_check( 'enviado email sent', 1 === count( $subjects ), implode( ' | ', $subjects ) );
slo_check( 'enviado email contains guia', false !== strpos( $body, 'GUIA-XYZ-123' ) );
slo_check( 'delivery type default domicilio', 'domicilio' === SLO_Emails::detect_delivery_type( $order ) );

// ---- 10. Snapshot locked after enviado ----
$before = SLO_Order_Snapshot::get_order_fecha_despacho( $order );
SLO_Order_Snapshot::recompute_snapshot( $oid );
update_term_meta( $ck_id, 'slo_fecha_despacho', '2026-12-31' );
SLO_Order_Snapshot::recompute_snapshot( $oid );
$after = SLO_Order_Snapshot::get_order_fecha_despacho( wc_get_order( $oid ) );
slo_check( 'snapshot locked once enviado (date unchanged)', $before === $after, "$before vs $after" );

// ---- 10b. Abono Reserva model (checkout integration, v1.1.0) ----
slo_check( 'settings: default percent 50', 50.0 === SLO_Settings::get_percent() );
slo_check( 'settings: abono enabled default', SLO_Settings::is_abono_enabled() );
slo_check( 'settings: titulo interpolates percent', false !== strpos( SLO_Settings::get_titulo(), '50%' ), SLO_Settings::get_titulo() );

// Simulate an order that came from checkout with Abono Reserva:
// product 100, fee -50 => WC total 50 (paid today), descuento 50 deferred.
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

// What SLO_Checkout_Abono::attach_order_meta would store (session not
// available in CLI, so set the same meta directly and verify the model).
$o4->update_meta_data( '_slo_abono_checkout', '1' );
$o4->update_meta_data( SLO_Order_Balance::META_DESCUENTO, 50 );
$o4->update_meta_data( SLO_Order_Balance::META_ABONADO, $o4->get_total() );
$o4->save();
$o4 = wc_get_order( $o4->get_id() );

slo_check( 'abono model: WC total = 50 (paid today)', 50.0 === (float) $o4->get_total(), (string) $o4->get_total() );
slo_check( 'abono model: total real = 100', 100.0 === SLO_Order_Balance::get_total_real( $o4 ), (string) SLO_Order_Balance::get_total_real( $o4 ) );
slo_check( 'abono model: saldo = 50 (deferred)', 50.0 === SLO_Order_Balance::get_saldo_pendiente( $o4 ), (string) SLO_Order_Balance::get_saldo_pendiente( $o4 ) );

// Native processing email suppressed for abono orders with saldo.
$suppressed = SLO_Checkout_Abono::suppress_processing_email( true, $o4 );
slo_check( 'processing email suppressed for abono order', false === $suppressed );

// Payment confirmed -> auto-move to slo-abono, abono email fires once.
slo_mail_reset();
$o4->update_status( 'processing' );
$o4 = wc_get_order( $o4->get_id() );
$subjects = slo_mail_subjects();
slo_check( 'abono order auto-moves processing -> slo-abono', 'slo-abono' === $o4->get_status(), $o4->get_status() );
// Expected mail on this transition: customer abono email + admin "new order"
// notice (owner-facing, must stay). NOT expected: native customer processing.
$has_abono_mail   = false;
$has_processing   = false;
foreach ( $subjects as $s ) {
	if ( false !== stripos( $s, 'abono' ) ) { $has_abono_mail = true; }
	if ( false !== stripos( $s, 'procesando' ) || false !== stripos( $s, 'processing' ) || false !== stripos( $s, 'recibido' ) ) { $has_processing = true; }
}
slo_check( 'abono email fired on auto-move', $has_abono_mail, implode( ' | ', $subjects ) );
slo_check( 'native customer processing email suppressed', ! $has_processing, implode( ' | ', $subjects ) );

// Enviado still blocked while saldo > 0 under the new model.
slo_mail_reset();
$o4->update_status( 'slo-enviado' );
$o4 = wc_get_order( $o4->get_id() );
slo_check( 'abono order: enviado blocked with deferred saldo', 'slo-abono' === $o4->get_status(), $o4->get_status() );

// "Marcar saldo como pagado" button core logic (now a ledger entry).
$o4 = SLO_Order_Balance::mark_saldo_paid( $o4->get_id() );
slo_check( 'mark_saldo_paid: saldo -> 0', 0.0 === SLO_Order_Balance::get_saldo_pendiente( $o4 ), (string) SLO_Order_Balance::get_saldo_pendiente( $o4 ) );
$notes = wc_get_order_notes( array( 'order_id' => $o4->get_id(), 'limit' => 3 ) );
$note_found = false;
foreach ( $notes as $n ) {
	if ( false !== strpos( $n->content, 'Abono registrado' ) ) { $note_found = true; }
}
slo_check( 'mark_saldo_paid: audit note added', $note_found );
// Ledger: legacy abonado (50, set directly as meta) seeded as "previo",
// then the saldo payment entry -> 2 entries summing 100.
$ledger = SLO_Order_Balance::get_abonos( $o4 );
slo_check( 'mark_saldo_paid: ledger has previo + saldo entries', 2 === count( $ledger ) && 'previo' === $ledger[0]['origen'] && 'saldo' === $ledger[1]['origen'], wp_json_encode( wp_list_pluck( $ledger, 'origen' ) ) );
slo_check( 'mark_saldo_paid: saldo entry has date', ! empty( $ledger[1]['fecha'] ), $ledger[1]['fecha'] ?? '' );

// Now enviado passes.
$o4->update_status( 'slo-enviado' );
$o4 = wc_get_order( $o4->get_id() );
slo_check( 'abono order ships after saldo paid', 'slo-enviado' === $o4->get_status(), $o4->get_status() );

// ---- 10b2. Ledger: manual abonos accumulate with dates ----
$o6 = wc_create_order();
$o6->add_product( wc_get_product( $p_jk ), 2 ); // 200
$o6->set_billing_email( 'ledger@stylelauri.test' );
$o6->calculate_totals();
$o6->save();
SLO_Order_Balance::add_abono( $o6, 60, 'manual' );
SLO_Order_Balance::add_abono( $o6, 40, 'manual' );
$o6 = wc_get_order( $o6->get_id() );
$ledger6 = SLO_Order_Balance::get_abonos( $o6 );
slo_check( 'ledger: 2 manual abonos recorded', 2 === count( $ledger6 ) );
slo_check( 'ledger: total abonado = sum (100)', 100.0 === (float) $o6->get_meta( '_slo_monto_abonado' ), (string) $o6->get_meta( '_slo_monto_abonado' ) );
slo_check( 'ledger: saldo = 100', 100.0 === SLO_Order_Balance::get_saldo_pendiente( $o6 ), (string) SLO_Order_Balance::get_saldo_pendiente( $o6 ) );
slo_check( 'ledger: entries carry dates', ! empty( $ledger6[0]['fecha'] ) && ! empty( $ledger6[1]['fecha'] ) );
// Negative correction entry.
SLO_Order_Balance::add_abono( $o6, -10, 'manual' );
$o6 = wc_get_order( $o6->get_id() );
slo_check( 'ledger: negative correction works (total 90)', 90.0 === (float) $o6->get_meta( '_slo_monto_abonado' ), (string) $o6->get_meta( '_slo_monto_abonado' ) );

// ---- 10b3. Status role mapping ----
slo_check( 'mapping: defaults in place', 'slo-produccion' === SLO_Order_Statuses::get_status( 'produccion' ) && SLO_Order_Statuses::uses_default( 'produccion' ) );
update_option( 'slo_status_produccion', 'wc-processing' );
slo_check( 'mapping: produccion remapped to processing', 'processing' === SLO_Order_Statuses::get_status( 'produccion' ) && ! SLO_Order_Statuses::uses_default( 'produccion' ) );
$dropdown = SLO_Order_Statuses::register_status_labels( array( 'wc-pending' => 'P', 'wc-processing' => 'Pr' ) );
slo_check( 'mapping: plugin produccion status removed from dropdown', ! isset( $dropdown['wc-slo-produccion'] ) && isset( $dropdown['wc-slo-listo'], $dropdown['wc-slo-enviado'], $dropdown['wc-slo-abono'] ), wp_json_encode( array_keys( $dropdown ) ) );
// Remap enviado -> completed: guard and lock must follow the mapping.
update_option( 'slo_status_enviado', 'wc-completed' );
slo_check( 'mapping: locked statuses follow enviado mapping', in_array( 'completed', SLO_Order_Statuses::locked_snapshot_statuses(), true ) && ! in_array( 'slo-enviado', SLO_Order_Statuses::locked_snapshot_statuses(), true ), implode( ',', SLO_Order_Statuses::locked_snapshot_statuses() ) );
$o7 = wc_create_order();
$o7->add_product( wc_get_product( $p_jk ), 1 );
$o7->set_billing_email( 'map@stylelauri.test' );
$o7->calculate_totals();
$o7->save();
$o7->update_meta_data( '_slo_monto_abonado', 10 ); // saldo 90
$o7->save();
$o7->update_status( 'completed' ); // mapped "enviado" with saldo -> must revert
$o7 = wc_get_order( $o7->get_id() );
slo_check( 'mapping: guard blocks mapped enviado (completed) with saldo', 'completed' !== $o7->get_status(), $o7->get_status() );
delete_option( 'slo_status_produccion' );
delete_option( 'slo_status_enviado' );
slo_check( 'mapping: options restored to defaults', 'slo-enviado' === SLO_Order_Statuses::get_status( 'enviado' ) );

// ---- 10c. Bulk recompute backfill + badge distinction ----
$o5 = wc_create_order(); // "pre-plugin" order: no snapshot meta at all
$o5->add_product( wc_get_product( $p_jk ), 1 );
$o5->save();
$o5 = wc_get_order( $o5->get_id() );
slo_check( 'pre-plugin order: snapshot meta absent', ! $o5->meta_exists( SLO_Order_Snapshot::META_LOTES ) );
$redirect = SLO_Order_Admin_Columns::handle_bulk_action( 'http://x/wp-admin/admin.php?page=wc-orders', 'slo_recalcular_snapshot', array( $o5->get_id() ) );
$o5 = wc_get_order( $o5->get_id() );
slo_check( 'bulk recompute: snapshot backfilled', $o5->meta_exists( SLO_Order_Snapshot::META_LOTES ) && 1 === count( SLO_Order_Snapshot::get_order_lotes( $o5 ) ) );
slo_check( 'bulk recompute: redirect carries count', false !== strpos( $redirect, 'slo_recalculados=1' ), $redirect );

// ---- 10d. Dispatch gate (Skydrops): processing = despachable ----
slo_check( 'gate: enabled by default', SLO_Dispatch_Gate::is_enabled() );

// Stock order, fully paid via gateway (no abono data) -> STAYS processing.
$g1 = wc_create_order();
$g1->add_product( wc_get_product( $p_stock ), 1 );
$g1->set_billing_email( 'gate1@stylelauri.test' );
$g1->calculate_totals();
$g1->save();
SLO_Order_Snapshot::recompute_snapshot( $g1->get_id() );
$g1->update_status( 'processing' ); // what Wompi does
$g1 = wc_get_order( $g1->get_id() );
slo_check( 'gate: stock paid order stays in processing (Skydrops sees it)', 'processing' === $g1->get_status(), $g1->get_status() );
slo_check( 'gate: no-abono order has saldo 0', 0.0 === SLO_Order_Balance::get_saldo_pendiente( $g1 ) );

// Preventa order, fully paid (no abono data) -> routed to produccion.
$g2 = wc_create_order();
$g2->add_product( wc_get_product( $p_jk ), 1 );
$g2->set_billing_email( 'gate2@stylelauri.test' );
$g2->calculate_totals();
$g2->save();
SLO_Order_Snapshot::recompute_snapshot( $g2->get_id() );
$g2->update_status( 'processing' );
$g2 = wc_get_order( $g2->get_id() );
slo_check( 'gate: paid preventa routed to produccion', 'slo-produccion' === $g2->get_status(), $g2->get_status() );

// Preventa with partial abono -> routed to abono.
$g3 = wc_create_order();
$g3->add_product( wc_get_product( $p_jk ), 1 ); // 100
$g3->set_billing_email( 'gate3@stylelauri.test' );
$g3->set_billing_first_name( 'Gate' );
$g3->calculate_totals();
$g3->save();
SLO_Order_Snapshot::recompute_snapshot( $g3->get_id() );
SLO_Order_Balance::add_abono( $g3, 40, 'manual' ); // saldo 60
$g3->update_status( 'processing' );
$g3 = wc_get_order( $g3->get_id() );
slo_check( 'gate: order with saldo routed to abono', 'slo-abono' === $g3->get_status(), $g3->get_status() );

// Native processing email suppressed for routed order.
slo_check( 'gate: processing email suppressed when routed', false === SLO_Dispatch_Gate::suppress_if_routed( true, $g3 ) );
slo_check( 'gate: processing email kept when it stays', true === SLO_Dispatch_Gate::suppress_if_routed( true, $g1 ) );

// Lote arrives: produccion -> listo (flag), manual jump to processing with saldo BLOCKED.
$g3->update_status( 'slo-produccion' );
$g3->update_status( 'slo-listo' );
$g3 = wc_get_order( $g3->get_id() );
slo_check( 'gate: paso-por-listo flag set', '1' === $g3->get_meta( '_slo_paso_por_listo' ) );
$g3->update_status( 'processing' ); // manual, saldo 60 -> must revert
$g3 = wc_get_order( $g3->get_id() );
slo_check( 'gate: manual listo->processing blocked with saldo', 'slo-listo' === $g3->get_status(), $g3->get_status() );

// Saldo paid -> auto-advance listo -> processing.
SLO_Order_Balance::mark_saldo_paid( $g3->get_id() );
$g3 = wc_get_order( $g3->get_id() );
slo_check( 'gate: saldo 0 auto-advances listo -> processing', 'processing' === $g3->get_status(), $g3->get_status() );

// Paid preventa whose lote already arrived: a PAYMENT-flow entry to
// processing (old=pending) must now stick because paso_por_listo is set.
$g2->update_status( 'slo-listo' );
$g2->update_status( 'pending' );
$g2 = wc_get_order( $g2->get_id() );
$g2->update_status( 'processing' );
$g2 = wc_get_order( $g2->get_id() );
slo_check( 'gate: preventa after listo stays in processing (router path)', 'processing' === $g2->get_status(), $g2->get_status() );

// ---- 11. Statuses registered in WC dropdown ----
$statuses = wc_get_order_statuses();
slo_check( 'custom statuses in wc_get_order_statuses', isset( $statuses['wc-slo-abono'], $statuses['wc-slo-produccion'], $statuses['wc-slo-listo'], $statuses['wc-slo-enviado'] ) );

// ---- 12. Email classes registered with WC mailer ----
$emails = WC()->mailer()->get_emails();
slo_check( 'custom email classes registered', isset( $emails['WC_Email_SLO_Abono'], $emails['WC_Email_SLO_Saldo_Reminder'], $emails['WC_Email_SLO_Enviado'] ) );

// ---- Summary ----
$all  = $GLOBALS['slo_results'];
$fail = array_filter( $all, function ( $r ) { return ! $r['pass']; } );
echo "\n" . ( count( $fail ) ? count( $fail ) . ' FAILURES of ' . count( $all ) : 'ALL ' . count( $all ) . ' CHECKS PASSED' ) . "\n";
