<?php
/**
 * Snapshot de lote(s) y fecha de despacho en el pedido.
 *
 * Al crear/editar el pedido, se recorren los line items, se detectan los
 * lotes que tocan (via SLO_Taxonomy), y se guarda en el PEDIDO (no en
 * referencia viva al producto):
 *
 *  - _slo_lotes_pedido      => array de slugs de TODOS los lotes tocados.
 *    Sirve para produccion: un pedido con JK+CK debe seguir apareciendo
 *    en el filtro de produccion de CK aunque JK despache mas tarde.
 *
 *  - _slo_fecha_despacho    => la fecha MAS TARDIA entre los lotes
 *    tocados (formato Y-m-d, comparable como string). Es la fecha que
 *    se le promete al cliente y la que bloquea el paso a "Enviado".
 *
 * Es snapshot deliberado: si despues cambian las fechas del termino
 * para la SIGUIENTE campana, este pedido no debe moverse solo.
 *
 * @package StyleLauri_Order_Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLO_Order_Snapshot {

	const META_LOTES   = '_slo_lotes_pedido';
	const META_FECHA   = '_slo_fecha_despacho';

	public static function init() {
		// Pedido creado desde el checkout clasico (shortcode).
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'recompute_snapshot' ), 10, 1 );

		// Pedido creado desde el Checkout Block (Store API) -- el checkout
		// por defecto de WooCommerce moderno NO dispara el hook clasico.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'recompute_snapshot_from_order' ), 10, 1 );

		// Pedido creado o editado manualmente desde el admin.
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'recompute_snapshot' ), 20, 1 );

		// Items editados via AJAX en la pantalla de edicion de pedido.
		add_action( 'woocommerce_saved_order_items', array( __CLASS__, 'recompute_snapshot' ), 10, 1 );
	}

	/**
	 * Adaptador para el hook del Checkout Block, que entrega el WC_Order
	 * ya resuelto en vez del ID.
	 *
	 * @param WC_Order $order Pedido recien creado por la Store API.
	 */
	public static function recompute_snapshot_from_order( $order ) {
		if ( $order instanceof WC_Order ) {
			self::recompute_snapshot( $order->get_id() );
		}
	}

	/**
	 * Recalcula y guarda el snapshot de lotes/fecha para un pedido.
	 * No hace nada si el pedido ya esta en un estado "cerrado" (ver
	 * SLO_Order_Statuses::locked_snapshot_statuses()) -- una vez enviado,
	 * la promesa ya se cumplio o esta en curso, no se debe recalcular
	 * por una edicion administrativa tardia.
	 *
	 * @param int $order_id ID del pedido.
	 */
	public static function recompute_snapshot( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		if ( in_array( $order->get_status(), SLO_Order_Statuses::locked_snapshot_statuses(), true ) ) {
			return;
		}

		$lotes_tocados = array(); // slug => fecha_despacho.

		foreach ( $order->get_items( 'line_item' ) as $item ) {
			/** @var WC_Order_Item_Product $item */
			// get_product_id() devuelve siempre el producto padre (para
			// variaciones tambien) -- y los terminos de lote viven en el
			// padre, asi que es exactamente el ID que se necesita.
			$product_id = $item->get_product_id();

			$terms = SLO_Taxonomy::get_product_lotes( $product_id );

			foreach ( $terms as $term ) {
				$dates = SLO_Taxonomy::get_term_dates( $term->term_id );
				$lotes_tocados[ $term->slug ] = $dates['despacho'];
			}
		}

		$slugs = array_keys( $lotes_tocados );
		$order->update_meta_data( self::META_LOTES, $slugs );

		$fecha_gobernante = self::latest_date( array_values( $lotes_tocados ) );
		$order->update_meta_data( self::META_FECHA, $fecha_gobernante );

		$order->save();
	}

	/**
	 * La fecha mas tardia de una lista (formato Y-m-d, comparacion como
	 * string es valida en ISO 8601). Ignora vacios.
	 *
	 * @param string[] $dates Fechas en formato Y-m-d.
	 * @return string Fecha mas tardia, o cadena vacia si no hay ninguna.
	 */
	private static function latest_date( array $dates ) {
		$dates = array_filter( $dates );

		if ( empty( $dates ) ) {
			return '';
		}

		sort( $dates );
		return end( $dates );
	}

	/**
	 * Lotes que toca un pedido.
	 *
	 * @param WC_Order|int $order Pedido o su ID.
	 * @return string[] Slugs de lote.
	 */
	public static function get_order_lotes( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );

		if ( ! $order ) {
			return array();
		}

		$lotes = $order->get_meta( self::META_LOTES );
		return is_array( $lotes ) ? $lotes : array();
	}

	/**
	 * Fecha de despacho gobernante de un pedido (la mas tardia entre sus
	 * lotes). Cadena vacia si el pedido es 100% stock inmediato.
	 *
	 * @param WC_Order|int $order Pedido o su ID.
	 * @return string
	 */
	public static function get_order_fecha_despacho( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );

		if ( ! $order ) {
			return '';
		}

		return (string) $order->get_meta( self::META_FECHA );
	}

	/**
	 * Un pedido es "de preventa" si toca al menos un lote. Igual que con
	 * el producto, no hay bandera booleana separada -- se infiere de si
	 * la lista de lotes esta vacia o no.
	 *
	 * @param WC_Order|int $order Pedido o su ID.
	 * @return bool
	 */
	public static function order_is_preventa( $order ) {
		return count( self::get_order_lotes( $order ) ) > 0;
	}
}
