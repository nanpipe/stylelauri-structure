<?php
/**
 * Ciclo de vida del pedido -- el UNICO significado que debe tener el
 * "Estado". Metodo de entrega, tipo de producto y lote NO son estados,
 * son atributos (ver SLO_Taxonomy y SLO_Order_Snapshot).
 *
 * El plugin trabaja con ROLES de ciclo de vida, no con slugs fijos:
 *
 *   abono      -> pago parcial recibido, falta saldo
 *   produccion -> el lote esta en produccion (interno)
 *   listo      -> listo para despacho/retiro (interno)
 *   enviado    -> despachado / listo para retirar
 *
 * Cada rol se puede MAPEAR a cualquier estado ya existente en la tienda
 * (StyleLauri > Ajustes > Estados del pedido). Si un rol usa su estado
 * por defecto (los slo-*), el plugin lo registra; si se mapea a un
 * estado existente, el del plugin NO se registra -- asi no se acumulan
 * estados duplicados en el dropdown.
 *
 * @package StyleLauri_Order_Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLO_Order_Statuses {

	// Estados por defecto del plugin (los nativos pending/processing/
	// completed/cancelled ya existen en WooCommerce y no se tocan).
	const ABONO      = 'wc-slo-abono';
	const PRODUCCION = 'wc-slo-produccion';
	const LISTO      = 'wc-slo-listo';
	const ENVIADO    = 'wc-slo-enviado';

	/**
	 * Rol => estado por defecto (con prefijo wc-).
	 *
	 * @return array<string,string>
	 */
	public static function default_map() {
		return array(
			'abono'      => self::ABONO,
			'produccion' => self::PRODUCCION,
			'listo'      => self::LISTO,
			'enviado'    => self::ENVIADO,
		);
	}

	/**
	 * Etiquetas humanas de cada rol (para Ajustes y para el registro).
	 *
	 * @return array<string,string>
	 */
	public static function role_labels() {
		return array(
			'abono'      => _x( 'Abono parcial', 'Order status', 'stylelauri-order-flow' ),
			'produccion' => _x( 'En producción', 'Order status', 'stylelauri-order-flow' ),
			'listo'      => _x( 'Listo para despacho/retiro', 'Order status', 'stylelauri-order-flow' ),
			'enviado'    => _x( 'Enviado', 'Order status', 'stylelauri-order-flow' ),
		);
	}

	/**
	 * Estado mapeado a un rol, SIN prefijo wc- (formato de
	 * $order->get_status() y de los hooks de transicion).
	 *
	 * @param string $role abono|produccion|listo|enviado.
	 * @return string
	 */
	public static function get_status( $role ) {
		$defaults = self::default_map();

		if ( ! isset( $defaults[ $role ] ) ) {
			return '';
		}

		$stored = get_option( 'slo_status_' . $role, '' );
		$key    = ( is_string( $stored ) && 0 === strpos( $stored, 'wc-' ) ) ? $stored : $defaults[ $role ];

		return str_replace( 'wc-', '', $key );
	}

	/**
	 * Estado mapeado a un rol, CON prefijo wc- (formato de las keys de
	 * wc_get_order_statuses y de register_post_status).
	 *
	 * @param string $role Rol.
	 * @return string
	 */
	public static function get_status_key( $role ) {
		return 'wc-' . self::get_status( $role );
	}

	/**
	 * ¿El rol sigue usando el estado por defecto del plugin? Solo en ese
	 * caso el estado slo-* se registra; mapeado a otro, desaparece.
	 *
	 * @param string $role Rol.
	 * @return bool
	 */
	public static function uses_default( $role ) {
		$defaults = self::default_map();
		return isset( $defaults[ $role ] ) && self::get_status_key( $role ) === $defaults[ $role ];
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
		return array_unique( array( self::get_status( 'enviado' ), 'completed', 'cancelled', 'refunded' ) );
	}

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_statuses' ) );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'register_status_labels' ) );
		add_filter( 'woocommerce_valid_order_statuses_for_payment_complete', array( __CLASS__, 'allow_payment_complete_from_abono' ) );
	}

	/**
	 * Registra como post_status SOLO los estados por defecto cuyos roles
	 * no fueron remapeados. WC los usa igual bajo HPOS: el post_status
	 * sigue siendo la fuente de la etiqueta de estado.
	 */
	public static function register_statuses() {
		$labels = self::role_labels();

		foreach ( self::default_map() as $role => $status_key ) {
			if ( ! self::uses_default( $role ) ) {
				continue;
			}

			$label = $labels[ $role ];

			register_post_status(
				$status_key,
				array(
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					/* translators: %s: number of orders */
					'label_count'               => _n_noop( $label . ' <span class="count">(%s)</span>', $label . ' <span class="count">(%s)</span>', 'stylelauri-order-flow' ), // phpcs:ignore WordPress.WP.I18n
				)
			);
		}
	}

	/**
	 * Inserta en el dropdown de "Estado del pedido" SOLO los estados por
	 * defecto que siguen en uso. Los roles mapeados a estados existentes
	 * no agregan nada (ese estado ya esta en la lista).
	 *
	 * @param array $order_statuses Estados existentes.
	 * @return array
	 */
	public static function register_status_labels( $order_statuses ) {
		$labels       = self::role_labels();
		$new_statuses = array();

		foreach ( $order_statuses as $key => $label ) {
			$new_statuses[ $key ] = $label;

			// "Abono parcial" justo despues de "Pendiente de pago".
			if ( 'wc-pending' === $key && self::uses_default( 'abono' ) ) {
				$new_statuses[ self::ABONO ] = $labels['abono'];
			}

			// Produccion/listo/enviado justo despues de "Procesando".
			if ( 'wc-processing' === $key ) {
				if ( self::uses_default( 'produccion' ) ) {
					$new_statuses[ self::PRODUCCION ] = $labels['produccion'];
				}
				if ( self::uses_default( 'listo' ) ) {
					$new_statuses[ self::LISTO ] = $labels['listo'];
				}
				if ( self::uses_default( 'enviado' ) ) {
					$new_statuses[ self::ENVIADO ] = $labels['enviado'];
				}
			}
		}

		return $new_statuses;
	}

	/**
	 * Permite marcar el pago como completo (payment_complete) viniendo
	 * desde el estado mapeado al rol "abono" -- necesario para cuando se
	 * cobra el saldo restante.
	 *
	 * @param array $statuses Estados validos.
	 * @return array
	 */
	public static function allow_payment_complete_from_abono( $statuses ) {
		$statuses[] = self::get_status( 'abono' );
		return array_unique( $statuses );
	}
}
