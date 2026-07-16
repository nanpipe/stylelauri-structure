<?php
/**
 * Abono Reserva en el checkout (integracion del snippet original).
 *
 * El cliente puede pagar solo un porcentaje (configurable en
 * StyleLauri > Ajustes) del subtotal de los productos de PREVENTA;
 * el resto queda registrado como saldo pendiente en el pedido.
 *
 * Cambios respecto al snippet suelto que vivia en staging:
 *
 *  - La deteccion de preventa ya NO usa la categoria "preventa": usa la
 *    misma fuente de verdad del resto del plugin -- el producto tiene un
 *    lote asignado (SLO_Taxonomy). Un solo criterio para todo.
 *
 *  - El porcentaje ya no esta cableado al 50%: sale de SLO_Settings.
 *
 *  - El calculo del saldo del snippet original estaba roto
 *    (total_con_fees - abono daba el costo del envio, no el saldo real).
 *    Ahora el modelo es explicito:
 *        total real   = total del pedido + descuento del abono
 *        abonado hoy  = total del pedido (lo que cobro la pasarela)
 *        saldo        = total real - abonado = el descuento diferido
 *    y lo calcula SLO_Order_Balance::get_saldo_pendiente() para que el
 *    guard de "Enviado", el recordatorio y los emails usen el mismo numero.
 *
 *  - El pedido pagado con abono pasa automaticamente a "Abono parcial"
 *    cuando la pasarela lo confirma, y se suprime el email nativo de
 *    "Procesando" para ese caso (el email de Abono ya cubre esa noticia).
 *
 * NOTA: el checkbox se renderiza con los hooks del checkout CLASICO
 * (shortcode [woocommerce_checkout]); el Checkout Block no ejecuta estos
 * hooks. La tienda actual usa el clasico.
 *
 * @package StyleLauri_Order_Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLO_Checkout_Abono {

	const SESSION_KEY = 'slo_abono_reserva';
	const META_FLAG   = '_slo_abono_checkout';

	public static function init() {
		// Checkbox en el checkout, antes de los metodos de pago.
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'render_checkbox' ) );

		// Fee negativo (el descuento diferido) sobre el subtotal de preventa.
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'apply_fee' ) );

		// Persistir el estado del checkbox en la sesion en cada recalculo AJAX.
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'save_session_from_review' ) );

		// JS: disparar update_checkout al marcar/desmarcar y conservar el estado.
		add_action( 'wp_footer', array( __CLASS__, 'print_js' ) );

		// Registrar el abono en el pedido al crearse.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'attach_order_meta' ), 20, 2 );

		// Limpiar la sesion una vez creado el pedido.
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'clear_session' ) );

		// No mandar el email nativo de "Procesando" cuando el de Abono ya aplica.
		add_filter( 'woocommerce_email_enabled_customer_processing_order', array( __CLASS__, 'suppress_processing_email' ), 10, 2 );
	}

	/**
	 * ¿El carrito actual toca al menos un producto de preventa?
	 * Misma fuente de verdad que el snapshot: tener lote asignado.
	 *
	 * @return bool
	 */
	public static function cart_has_preventa() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( SLO_Taxonomy::is_preventa_product( $item['product_id'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Subtotal de los items de preventa del carrito.
	 *
	 * @return float
	 */
	private static function cart_preventa_subtotal() {
		$subtotal = 0.0;

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( SLO_Taxonomy::is_preventa_product( $item['product_id'] ) ) {
				$subtotal += (float) $item['line_subtotal'];
			}
		}

		return $subtotal;
	}

	/**
	 * ¿El abono esta activo en la sesion actual?
	 *
	 * @return bool
	 */
	private static function session_active() {
		return function_exists( 'WC' ) && WC()->session && '1' === WC()->session->get( self::SESSION_KEY );
	}

	/**
	 * Checkbox del abono. Solo se pinta si la funcion esta activada en
	 * Ajustes y el carrito tiene preventa.
	 */
	public static function render_checkbox() {
		if ( ! SLO_Settings::is_abono_enabled() || ! self::cart_has_preventa() ) {
			return;
		}
		?>
		<div id="slo-abono-wrap" style="margin:20px 0;padding:18px 20px;border:2.5px solid #D4537E;border-radius:12px;background:#FEF0F5;cursor:pointer;">
			<label style="display:flex;align-items:flex-start;gap:14px;cursor:pointer;">
				<input
					type="checkbox"
					id="slo_abono_reserva"
					name="slo_abono_reserva"
					value="1"
					<?php checked( self::session_active() ); ?>
					style="width:22px;height:22px;margin-top:2px;accent-color:#993556;cursor:pointer;flex-shrink:0;"
				/>
				<span>
					<strong style="font-size:17px;color:#72243E;display:block;margin-bottom:4px;">
						<?php echo esc_html( SLO_Settings::get_titulo() ); ?>
					</strong>
					<span style="font-size:13px;color:#993556;line-height:1.5;display:block;">
						<?php echo esc_html( SLO_Settings::get_texto() ); ?>
					</span>
				</span>
			</label>
		</div>
		<?php
	}

	/**
	 * Aplica el fee negativo del abono sobre el subtotal de preventa.
	 * El cliente paga hoy el {percent}% de la preventa; el fee resta el
	 * complemento (lo que queda como saldo).
	 *
	 * @param WC_Cart $cart Carrito en calculo.
	 */
	public static function apply_fee( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! WC()->session ) {
			return;
		}

		if ( ! SLO_Settings::is_abono_enabled() || ! self::cart_has_preventa() ) {
			WC()->session->set( self::SESSION_KEY, '0' );
			return;
		}

		// El estado puede venir del POST del recalculo AJAX (post_data) o
		// de la sesion (navegacion normal / submit final).
		$activo = self::session_active();

		if ( isset( $_POST['post_data'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- estado de UI, no accion.
			parse_str( wp_unslash( $_POST['post_data'] ), $post_data ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$activo = ! empty( $post_data['slo_abono_reserva'] );
		}

		WC()->session->set( self::SESSION_KEY, $activo ? '1' : '0' );

		if ( ! $activo ) {
			return;
		}

		$subtotal_preventa = self::cart_preventa_subtotal();

		if ( $subtotal_preventa <= 0 ) {
			return;
		}

		$percent   = SLO_Settings::get_percent();
		$descuento = $subtotal_preventa * ( 1 - $percent / 100 );

		$cart->add_fee( self::fee_label(), -$descuento, false );
	}

	/**
	 * Etiqueta EXACTA del fee del abono. Centralizada para que
	 * extract_descuento() haga match exacto contra ella -- un match por
	 * substring permitiria que otro fee (de otro plugin, con texto
	 * controlable) contaminara el descuento registrado. [Auditoria]
	 *
	 * @return string
	 */
	public static function fee_label() {
		return sprintf(
			/* translators: %s: percent paid today */
			__( 'Abono Reserva (pagas %s%% hoy)', 'stylelauri-order-flow' ),
			SLO_Settings::format_percent()
		);
	}

	/**
	 * Descuento del abono leido de los fees del pedido, solo del fee con
	 * la etiqueta exacta del plugin.
	 *
	 * @param WC_Order $order Pedido.
	 * @return float Monto diferido (positivo), 0 si no hay fee de abono.
	 */
	public static function extract_descuento( $order ) {
		$expected  = self::fee_label();
		$descuento = 0.0;

		foreach ( $order->get_fees() as $fee ) {
			if ( $fee->get_name() === $expected ) {
				$descuento += -(float) $fee->get_total();
			}
		}

		return $descuento;
	}

	/**
	 * Persistir el estado del checkbox cada vez que el checkout recalcula
	 * (update_order_review manda todos los campos serializados).
	 *
	 * @param string $posted_data Datos serializados del formulario.
	 */
	public static function save_session_from_review( $posted_data ) {
		if ( ! WC()->session ) {
			return;
		}

		parse_str( $posted_data, $data );
		WC()->session->set( self::SESSION_KEY, empty( $data['slo_abono_reserva'] ) ? '0' : '1' );
	}

	/**
	 * JS minimo: recalcular el checkout al cambiar el checkbox y restaurar
	 * su estado despues de que WooCommerce re-renderice el fragmento.
	 */
	public static function print_js() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		?>
		<script>
		jQuery( function ( $ ) {
			var checked = $( '#slo_abono_reserva' ).is( ':checked' );

			$( document ).on( 'change', '#slo_abono_reserva', function () {
				checked = $( this ).is( ':checked' );
				$( 'body' ).trigger( 'update_checkout' );
			} );

			// update_checkout reemplaza el fragmento donde vive el checkbox;
			// restaurar el estado que el usuario habia elegido.
			$( document.body ).on( 'updated_checkout', function () {
				$( '#slo_abono_reserva' ).prop( 'checked', checked );
			} );
		} );
		</script>
		<?php
	}

	/**
	 * Registra el abono en el pedido recien creado:
	 *
	 *  - _slo_abono_checkout   -> bandera de que vino del checkout con abono.
	 *  - _slo_descuento_abono  -> el monto diferido (el fee negativo, en positivo).
	 *  - _slo_monto_abonado    -> lo que el cliente paga hoy (el total del pedido).
	 *
	 * Con eso, SLO_Order_Balance::get_saldo_pendiente() = descuento diferido,
	 * exactamente lo que falta por cobrar.
	 *
	 * @param WC_Order $order Pedido en creacion (aun sin guardar).
	 * @param array    $data  Datos del checkout.
	 */
	public static function attach_order_meta( $order, $data ) {
		if ( ! self::session_active() ) {
			return;
		}

		// Leer el descuento del fee real del pedido (no recalcular): asi el
		// numero guardado coincide al centavo con lo que vio el cliente.
		// Match EXACTO de etiqueta -- ver extract_descuento().
		$descuento = self::extract_descuento( $order );

		if ( $descuento <= 0 ) {
			return;
		}

		$order->update_meta_data( self::META_FLAG, '1' );
		$order->update_meta_data( SLO_Order_Balance::META_DESCUENTO, wc_format_decimal( $descuento ) );

		// Lo pagado hoy entra al historial de abonos como primera entrada.
		// Sin save (WooCommerce guarda despues de este hook) y sin nota
		// (el pedido aun no tiene ID para colgar la nota).
		SLO_Order_Balance::add_abono( $order, (float) $order->get_total(), 'checkout', false, false );
	}

	/**
	 * Limpia la bandera de sesion despues de crear el pedido, para que el
	 * proximo carrito arranque sin abono.
	 */
	public static function clear_session() {
		if ( WC()->session ) {
			WC()->session->set( self::SESSION_KEY, '0' );
		}
	}

	/**
	 * El email nativo de "Procesando" no aplica a un pedido con abono
	 * pendiente (el email de Abono es el que informa el estado real);
	 * mandarlo ademas seria la doble notificacion que el proyecto prohibe.
	 *
	 * @param bool          $enabled Si el email esta habilitado.
	 * @param WC_Order|null $order   Pedido.
	 * @return bool
	 */
	public static function suppress_processing_email( $enabled, $order ) {
		if ( $order instanceof WC_Order
			&& '1' === $order->get_meta( self::META_FLAG )
			&& SLO_Order_Balance::get_saldo_pendiente( $order ) > 0 ) {
			return false;
		}

		return $enabled;
	}
}
