<?php
/**
 * Abono Reserva en el checkout (integracion del snippet original).
 *
 * El cliente puede pagar HOY solo el abono de los productos de PREVENTA;
 * el resto queda registrado como saldo pendiente en el pedido.
 *
 * Cuanto se paga hoy, por linea de preventa:
 *  - Producto con "Abono Reserva fijo" (meta _slo_abono_fijo en el
 *    producto padre): monto fijo x cantidad, el mismo para todas las
 *    variaciones aunque cada variacion tenga otro precio. Si el fijo
 *    alcanza o supera el precio, esa linea se paga completa.
 *  - Producto sin monto fijo: el porcentaje de StyleLauri > Ajustes.
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

	const SESSION_KEY     = 'slo_abono_reserva';
	const META_FLAG       = '_slo_abono_checkout';
	const META_ABONO_FIJO = '_slo_abono_fijo';

	/**
	 * Etiqueta del fee antes de v1.12 ("Abono Reserva (pagas 50% hoy)").
	 * Match EXACTO de ese formato -- no substring -- para que los pedidos
	 * viejos sigan leyendo su saldo, cualquiera que fuera el porcentaje.
	 */
	const FEE_LABEL_LEGACY_RE = '/^Abono Reserva \(pagas \d+(?:\.\d+)?% hoy\)$/';

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

		// Campo "Abono Reserva fijo" en la ficha del producto (pestana
		// General, visible tambien en productos variables).
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'render_product_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'save_product_field' ) );
	}

	// ------------------------------------------------------------------
	// Monto del abono por producto
	// ------------------------------------------------------------------

	/**
	 * Abono fijo por unidad del producto (el padre, si es variable).
	 *
	 * @param int $product_id ID del producto padre.
	 * @return float 0 si no tiene monto fijo (usa el porcentaje).
	 */
	public static function get_abono_fijo( $product_id ) {
		$monto = (float) get_post_meta( (int) $product_id, self::META_ABONO_FIJO, true );
		return $monto > 0 ? $monto : 0.0;
	}

	/**
	 * Lo que el cliente paga HOY por una linea de preventa.
	 *
	 * @param int   $product_id    ID del producto padre.
	 * @param int   $qty           Cantidad.
	 * @param float $line_subtotal Subtotal de la linea.
	 * @return float Entre 0 y $line_subtotal.
	 */
	public static function item_abono_hoy( $product_id, $qty, $line_subtotal ) {
		$line_subtotal = max( 0.0, (float) $line_subtotal );
		$fijo          = self::get_abono_fijo( $product_id );

		if ( $fijo > 0 ) {
			return min( $line_subtotal, $fijo * max( 1, (int) $qty ) );
		}

		return $line_subtotal * SLO_Settings::get_percent() / 100;
	}

	/**
	 * Abono del carrito: cuanto se paga hoy y cuanto queda diferido.
	 * Solo lineas de preventa; el stock se paga completo.
	 *
	 * @return array{hoy: float, diferido: float}
	 */
	public static function cart_abono_breakdown() {
		$out = array(
			'hoy'      => 0.0,
			'diferido' => 0.0,
		);

		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $out;
		}

		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! SLO_Taxonomy::is_preventa_product( $item['product_id'] ) ) {
				continue;
			}

			$subtotal = max( 0.0, (float) $item['line_subtotal'] );
			$hoy      = self::item_abono_hoy( $item['product_id'], $item['quantity'], $subtotal );

			$out['hoy']      += $hoy;
			$out['diferido'] += $subtotal - $hoy;
		}

		$decimals        = wc_get_price_decimals();
		$out['hoy']      = round( $out['hoy'], $decimals );
		$out['diferido'] = round( $out['diferido'], $decimals );

		return $out;
	}

	/**
	 * Convierte lo que escribe el admin en un monto. Con 0 decimales (COP)
	 * cualquier separador es de miles: "100.000", "100,000" y "100000"
	 * valen 100000.
	 *
	 * @param string $raw Valor del campo.
	 * @return float 0 si vacio o invalido.
	 */
	public static function parse_monto( $raw ) {
		$raw = trim( (string) $raw );

		if ( '' === $raw ) {
			return 0.0;
		}

		if ( 0 === wc_get_price_decimals() ) {
			$digits = preg_replace( '/[^0-9]/', '', $raw );
			return '' === $digits ? 0.0 : (float) $digits;
		}

		$raw = str_replace( array( ' ', wc_get_price_thousand_separator() ), '', $raw );
		$raw = str_replace( wc_get_price_decimal_separator(), '.', $raw );

		return preg_match( '/^\d+(\.\d+)?$/', $raw ) ? (float) $raw : 0.0;
	}

	/**
	 * Campo "Abono Reserva fijo" en la pestana General del producto.
	 */
	public static function render_product_field() {
		echo '<div class="options_group">';
		woocommerce_wp_text_input(
			array(
				'id'          => self::META_ABONO_FIJO,
				/* translators: %s: currency symbol */
				'label'       => sprintf( __( 'Abono Reserva fijo (%s)', 'stylelauri-order-flow' ), get_woocommerce_currency_symbol() ),
				'data_type'   => 'price',
				'desc_tip'    => true,
				'description' => __( 'Monto por UNIDAD que el cliente paga hoy al separar con Abono Reserva. Es el mismo para todas las variaciones, aunque cada una tenga otro precio. Vacio = usa el porcentaje de StyleLauri > Ajustes. Solo aplica si el producto tiene lote de preventa.', 'stylelauri-order-flow' ),
			)
		);
		echo '</div>';
	}

	/**
	 * Guarda el campo. WooCommerce ya verifico nonce y permisos antes de
	 * disparar woocommerce_admin_process_product_object.
	 *
	 * @param WC_Product $product Producto en guardado.
	 */
	public static function save_product_field( $product ) {
		if ( ! isset( $_POST[ self::META_ABONO_FIJO ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$monto = self::parse_monto( sanitize_text_field( wp_unslash( $_POST[ self::META_ABONO_FIJO ] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $monto > 0 ) {
			$product->update_meta_data( self::META_ABONO_FIJO, wc_format_decimal( $monto ) );
		} else {
			$product->delete_meta_data( self::META_ABONO_FIJO );
		}
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

		// Nada que diferir (ej. abono fijo >= precio): el checkbox sobra.
		$abono = self::cart_abono_breakdown();
		if ( $abono['diferido'] <= 0 ) {
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
						<?php echo esc_html( SLO_Settings::get_titulo( $abono['hoy'] ) ); ?>
					</strong>
					<span style="font-size:13px;color:#993556;line-height:1.5;display:block;">
						<?php echo esc_html( SLO_Settings::get_texto( $abono['hoy'] ) ); ?>
					</span>
				</span>
			</label>
		</div>
		<?php
	}

	/**
	 * Aplica el fee negativo del abono sobre las lineas de preventa.
	 * El cliente paga hoy el abono (fijo por producto o porcentaje); el
	 * fee resta el resto (lo que queda como saldo).
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

		$descuento = self::cart_abono_breakdown()['diferido'];

		if ( $descuento <= 0 ) {
			return;
		}

		$cart->add_fee( self::fee_label(), -$descuento, false );
	}

	/**
	 * Etiqueta EXACTA del fee del abono. Centralizada para que
	 * extract_descuento() haga match exacto contra ella -- un match por
	 * substring permitiria que otro fee (de otro plugin, con texto
	 * controlable) contaminara el descuento registrado. [Auditoria]
	 *
	 * Ya no lleva el porcentaje: con abonos fijos por producto no hay un
	 * solo %, y asi cambiar el % en Ajustes no "pierde" pedidos viejos.
	 *
	 * @return string
	 */
	public static function fee_label() {
		return __( 'Abono Reserva (saldo por pagar)', 'stylelauri-order-flow' );
	}

	/**
	 * ¿Este nombre de fee es el del abono? Etiqueta actual o la de antes
	 * de v1.12; siempre match exacto.
	 *
	 * @param string $name Nombre del fee.
	 * @return bool
	 */
	public static function is_abono_fee_name( $name ) {
		return self::fee_label() === $name || 1 === preg_match( self::FEE_LABEL_LEGACY_RE, (string) $name );
	}

	/**
	 * Descuento del abono leido de los fees del pedido, solo del fee con
	 * la etiqueta exacta del plugin.
	 *
	 * @param WC_Order $order Pedido.
	 * @return float Monto diferido (positivo), 0 si no hay fee de abono.
	 */
	public static function extract_descuento( $order ) {
		$descuento = 0.0;

		foreach ( $order->get_fees() as $fee ) {
			if ( self::is_abono_fee_name( $fee->get_name() ) ) {
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
		// Respaldo del descuento en meta (el calculo en vivo lee el fee;
		// esto cubre el caso de que alguien borre la linea despues).
		$order->update_meta_data( SLO_Order_Balance::META_DESCUENTO, wc_format_decimal( $descuento ) );

		// Espejos para YAYMail (saldo/abonado). Sin save: WooCommerce
		// guarda el pedido justo despues de este hook.
		SLO_Order_Balance::sync_meta_mirrors( $order );
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
