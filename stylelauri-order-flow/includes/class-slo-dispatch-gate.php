<?php
/**
 * Puerta de despacho: "Procesando" = listo para despachar, y nada mas.
 *
 * Contexto operativo: la transportadora se gestiona con Skydrops, que
 * SOLO ve pedidos en estado "Procesando" -- si un pedido sale de ese
 * estado antes de generarse la guia, Skydrops no lo vuelve a encontrar.
 * Ademas, la pasarela (Wompi) pasa todo pedido pagado a "Procesando"
 * automaticamente, aunque sea una preventa que no existe todavia.
 *
 * Solucion: todo el embudo del ciclo de vida vive ANTES de Procesando,
 * y Procesando queda reservado para "pagado completo + despachable YA".
 *
 * El router intercepta cada entrada a Procesando que venga de un flujo
 * de pago (pending / on-hold / failed / abono) y lo reubica:
 *
 *   saldo > 0                          -> Abono parcial
 *   preventa que no ha pasado por Listo -> En produccion
 *   stock inmediato pagado completo     -> se queda en Procesando
 *
 * Cuando el lote llega, el flujo es: En produccion -> Listo (se cobran
 * saldos) -> al quedar el saldo en 0 el pedido pasa SOLO a Procesando,
 * donde Skydrops lo recoge. Despues de generada la guia, se pasa a
 * "Enviado" (dispara el correo con la guia).
 *
 * Los movimientos manuales entre estados internos NO se tocan: el router
 * solo actua cuando el origen es un estado de pago. Mover un pedido de
 * Listo a Procesando a mano si funciona (esa es la salida del embudo),
 * pero SLO_Order_Balance lo bloquea si aun hay saldo.
 *
 * Todo esto se puede apagar en StyleLauri > Ajustes (si algun dia se
 * cambia de transportadora/integracion).
 *
 * @package StyleLauri_Order_Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLO_Dispatch_Gate {

	const META_PASO_LISTO = '_slo_paso_por_listo';

	public static function init() {
		// Prioridad 5: el router corre antes que el guard de saldo (10) y
		// que el router de emails (20) en el mismo hook.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'route_on_status_change' ), 5, 4 );

		// El email nativo de "Procesando" solo tiene sentido si el pedido
		// QUEDO en Procesando; si el router lo reubico, se suprime.
		add_filter( 'woocommerce_email_enabled_customer_processing_order', array( __CLASS__, 'suppress_if_routed' ), 20, 2 );
	}

	/**
	 * ¿La puerta de despacho esta activa? (Ajustes, default si.)
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return 'no' !== get_option( SLO_Settings::OPT_DISPATCH_GATE, 'yes' );
	}

	/**
	 * Router principal + marca de "paso por Listo".
	 *
	 * @param int      $order_id   ID del pedido.
	 * @param string   $old_status Estado anterior (sin wc-).
	 * @param string   $new_status Estado nuevo (sin wc-).
	 * @param WC_Order $order      Pedido.
	 */
	public static function route_on_status_change( $order_id, $old_status, $new_status, $order ) {
		// Registrar que el pedido ya paso por "Listo/Preparacion": es la
		// senal de que su lote llego y esta fisicamente despachable.
		$listo_status = SLO_Order_Statuses::get_status( 'listo' );

		if ( '' !== $listo_status && $listo_status === $new_status ) {
			$order->update_meta_data( self::META_PASO_LISTO, '1' );
			$order->save();
		}

		if ( ! self::is_enabled() || 'processing' !== $new_status ) {
			return;
		}

		// Solo interceptar entradas que vienen de un flujo de PAGO (la
		// pasarela o el cobro del saldo). Un movimiento manual desde un
		// estado interno es una decision del admin y se respeta.
		$abono_status = SLO_Order_Statuses::get_status( 'abono' );

		$payment_origins = array( 'pending', 'on-hold', 'failed' );
		if ( '' !== $abono_status ) {
			$payment_origins[] = $abono_status;
		}

		if ( ! in_array( $old_status, $payment_origins, true ) ) {
			return;
		}

		$saldo = SLO_Order_Balance::get_saldo_pendiente( $order );

		// Con saldo pendiente el pedido no debe quedar despachable; solo
		// se puede reubicar si el rol "abono" tiene un estado asignado.
		if ( $saldo > 0 && '' !== $abono_status ) {
			$order->update_status(
				$abono_status,
				__( 'Puerta de despacho: pago parcial recibido, queda saldo pendiente. El pedido no entra a Procesando hasta estar pagado y despachable.', 'stylelauri-order-flow' )
			);
			return;
		}

		if ( SLO_Order_Snapshot::order_is_preventa( $order )
			&& '1' !== $order->get_meta( self::META_PASO_LISTO )
			&& SLO_Order_Statuses::is_mapped( 'produccion' ) ) {
			$order->update_status(
				SLO_Order_Statuses::get_status( 'produccion' ),
				__( 'Puerta de despacho: preventa pagada, pasa a produccion. Entrara a Procesando cuando el lote este listo para despachar.', 'stylelauri-order-flow' )
			);
			return;
		}

		// Stock inmediato pagado completo (o preventa cuyo lote ya llego):
		// se queda en Procesando -- despachable, visible para Skydrops.
	}

	/**
	 * Llamado por SLO_Order_Balance cuando un abono deja el saldo en 0:
	 * si el pedido esta en "Listo" (lote llego, estaba cobrandose el
	 * saldo), avanza solo a Procesando para que Skydrops lo recoja.
	 *
	 * @param WC_Order $order Pedido con saldo recien saldado.
	 */
	public static function maybe_advance_to_processing( $order ) {
		if ( ! self::is_enabled() || ! SLO_Order_Statuses::is_mapped( 'listo' ) ) {
			return;
		}

		if ( SLO_Order_Statuses::get_status( 'listo' ) !== $order->get_status() ) {
			return;
		}

		$order->update_status(
			'processing',
			__( 'Saldo en 0 y lote listo: pasa a Procesando para despacho (visible en Skydrops).', 'stylelauri-order-flow' )
		);
	}

	/**
	 * Suprime el email nativo de "Procesando" si el router ya movio el
	 * pedido a otro estado (el correo diria "estamos procesando tu
	 * pedido" para un pedido que en realidad esta en abono/produccion).
	 *
	 * @param bool          $enabled Si el email esta habilitado.
	 * @param WC_Order|null $order   Pedido.
	 * @return bool
	 */
	public static function suppress_if_routed( $enabled, $order ) {
		if ( self::is_enabled()
			&& $order instanceof WC_Order
			&& 'processing' !== $order->get_status() ) {
			return false;
		}

		return $enabled;
	}
}
