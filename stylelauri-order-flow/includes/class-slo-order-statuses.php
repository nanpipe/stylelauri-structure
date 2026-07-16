<?php
/**
 * Roles del ciclo de vida -- SIN estados propios.
 *
 * El plugin NO registra ningun estado de pedido. Los estados los crea y
 * administra la tienda (con el plugin de estados que prefiera); aqui
 * solo se define QUE ROL cumple cada estado dentro del flujo:
 *
 *   abono      -> pago parcial recibido, falta saldo
 *   produccion -> entrada del embudo tras el pago (etiqueta/reserva)
 *   listo      -> preparacion/empaque; con saldo dispara el recordatorio
 *                 y bloquea la salida a Merch Lista hasta saldo 0
 *
 * El DESPACHO no es un rol configurable: es SIEMPRE el estado nativo
 * "processing" (Merch Lista, lo que Skydrops ve). Cableado a proposito
 * -- mapearlo mal anularia la puerta de despacho.
 *
 * El mapeo se configura en StyleLauri > Ajustes > Estados del pedido.
 * Un rol SIN mapear simplemente desactiva sus automatismos -- nada se
 * rompe, solo no ocurre esa transicion/correo.
 *
 * @package StyleLauri_Order_Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLO_Order_Statuses {

	/**
	 * Roles que el plugin entiende.
	 *
	 * @return string[]
	 */
	public static function roles() {
		return array( 'abono', 'produccion', 'listo' );
	}

	/**
	 * Etiquetas humanas de cada rol (para la pantalla de Ajustes).
	 *
	 * @return array<string,string>
	 */
	public static function role_labels() {
		return array(
			'abono'      => __( 'Saldo Pendiente', 'stylelauri-order-flow' ),
			'produccion' => __( 'Abono Producción (entrada del embudo)', 'stylelauri-order-flow' ),
			'listo'      => __( 'Preparación', 'stylelauri-order-flow' ),
		);
	}

	/**
	 * Estado mapeado a un rol, SIN prefijo wc- (formato de
	 * $order->get_status() y de los hooks de transicion). Cadena vacia si
	 * el rol no esta mapeado -- los consumidores deben tratar vacio como
	 * "rol desactivado".
	 *
	 * @param string $role abono|produccion|listo.
	 * @return string
	 */
	public static function get_status( $role ) {
		if ( ! in_array( $role, self::roles(), true ) ) {
			return '';
		}

		$stored = get_option( 'slo_status_' . $role, '' );

		if ( ! is_string( $stored ) || 0 !== strpos( $stored, 'wc-' ) ) {
			return '';
		}

		$status = str_replace( 'wc-', '', $stored );

		// "Procesando" es la SALIDA de la puerta de despacho (Merch Lista,
		// lo que Skydrops ve): mapearle un rol anularia el guard en
		// silencio (update_status hacia el mismo estado es un no-op en
		// WooCommerce). Se trata como no mapeado. [Hallazgo de auditoria]
		if ( 'processing' === $status ) {
			return '';
		}

		return $status;
	}

	/**
	 * Estado mapeado a un rol, CON prefijo wc- (formato de las keys de
	 * wc_get_order_statuses). Cadena vacia si no esta mapeado.
	 *
	 * @param string $role Rol.
	 * @return string
	 */
	public static function get_status_key( $role ) {
		$status = self::get_status( $role );
		return '' === $status ? '' : 'wc-' . $status;
	}

	/**
	 * ¿El rol tiene un estado asignado?
	 *
	 * @param string $role Rol.
	 * @return bool
	 */
	public static function is_mapped( $role ) {
		return '' !== self::get_status( $role );
	}

	/**
	 * Estados en los que el pedido ya se considera "en camino final" y
	 * por lo tanto el snapshot de lote/fecha ya NO se debe recalcular
	 * automaticamente aunque se editen los items (evita cambiar la
	 * promesa hecha al cliente por una correccion administrativa tardia).
	 *
	 * @return string[] Sin prefijo wc-.
	 */
	public static function locked_snapshot_statuses() {
		// Solo estados terminales. "processing" (Merch Lista) NO se
		// congela: la accion masiva de "Recalcular lote(s)" debe poder
		// backfillear pedidos viejos que estan sentados ahi.
		return array( 'completed', 'cancelled', 'refunded' );
	}

	public static function init() {
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( __CLASS__, 'allow_payment_complete_from_abono' ) );
	}

	/**
	 * Permite marcar el pago como completo (payment_complete) viniendo
	 * desde el estado mapeado al rol "abono" -- necesario para cuando se
	 * cobra el saldo restante por pasarela.
	 *
	 * @param array $statuses Estados validos.
	 * @return array
	 */
	public static function allow_payment_complete_from_abono( $statuses ) {
		if ( self::is_mapped( 'abono' ) ) {
			$statuses[] = self::get_status( 'abono' );
		}

		return array_unique( $statuses );
	}
}
