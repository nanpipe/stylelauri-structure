<?php
/**
 * Se ejecuta SOLO cuando el plugin se elimina desde el admin (no en
 * desactivar). Por defecto no borra nada de los pedidos existentes --
 * el meta de lotes/fechas/saldo se queda en cada pedido aunque el
 * plugin se desinstale, para no perder historico por accidente.
 *
 * Si en algun momento quieren una limpieza total, se puede activar el
 * bloque comentado abajo.
 *
 * @package StyleLauri_Order_Flow
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Los ajustes del plugin si se borran siempre: son configuracion, no
// historico de pedidos.
delete_option( 'slo_abono_enabled' );
delete_option( 'slo_abono_percent' );
delete_option( 'slo_abono_titulo' );
delete_option( 'slo_abono_texto' );
delete_option( 'slo_dispatch_gate' );
delete_option( 'slo_status_abono' );
delete_option( 'slo_status_produccion' );
delete_option( 'slo_status_listo' );
delete_option( 'slo_status_enviado' );

// Limpieza opcional y destructiva -- descomentar solo si de verdad se
// quiere borrar todo el historico de lotes/fechas/saldo al desinstalar.
/*
global $wpdb;

// Terminos y term meta de la taxonomia lote_preventa.
$terms = get_terms(
	array(
		'taxonomy'   => 'lote_preventa',
		'hide_empty' => false,
		'fields'     => 'ids',
	)
);

if ( ! is_wp_error( $terms ) ) {
	foreach ( $terms as $term_id ) {
		wp_delete_term( $term_id, 'lote_preventa' );
	}
}
*/
