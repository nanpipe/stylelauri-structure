<?php
/**
 * Saldo del pedido basado en CUOTAS (fees del propio pedido).
 *
 * Modelo v1.9: no hay un historial paralelo en meta -- todo sale del
 * pedido mismo, que es lo que el cliente ve en sus correos:
 *
 *   descuento (diferido) = el fee negativo "Abono Reserva" del checkout
 *   cuotas               = fees positivos "... de cuota" (una linea por
 *                          cada pago recibido; editables/eliminables
 *                          como cualquier item del pedido)
 *   saldo pendiente      = max( 0, descuento - suma de cuotas )
 *   venta completa       = total del pedido + saldo
 *
 * Cada cuota SUMA al total del pedido: cuando el saldo llega a 0, el
 * total del pedido == la venta completa. Las cuotas aparecen en los
 * correos de WooCommerce/YAYMail automaticamente porque son lineas
 * reales del pedido.
 *
 * El boton "Abonar" agrega una cuota; "Marcar saldo como pagado"
 * agrega una cuota por el saldo restante. Para corregir una cuota mal
 * digitada se edita/elimina la linea en los articulos del pedido.
 *
 * Reglas absolutas de Merch Lista (processing): ver guard_and_notify().
 *
 * @package StyleLauri_Order_Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLO_Order_Balance {

	// Espejos PERSISTIDOS para YAYMail y plantillas (se refrescan en cada
	// operacion de cuota / guardado del pedido). La fuente de verdad para
	// la logica es siempre el calculo en vivo sobre los fees.
	const META_ABONADO   = '_slo_monto_abonado';
	const META_SALDO     = '_slo_saldo_pendiente';
	const META_DESCUENTO = '_slo_descuento_abono';
	const META_GUIA      = '_slo_guia_envio';

	const PAY_ACTION = 'slo_marcar_saldo_pagado';

	// Sufijo EXACTO de las lineas de cuota (ej. "$40,000 de cuota").
	const CUOTA_SUFFIX = ' de cuota';

	public static function init() {
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'render_balance_field' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_balance_field' ), 10, 1 );

		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'guard_and_notify' ), 10, 4 );

		// Boton "Marcar saldo como pagado" del panel del pedido.
		add_action( 'admin_post_' . self::PAY_ACTION, array( __CLASS__, 'handle_mark_saldo_paid' ) );

		// Fila de "Saldo pendiente" en la tabla de totales (correos,
		// pagina de gracias, mi cuenta). Las cuotas ya se ven solas por
		// ser lineas reales del pedido.
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'add_saldo_total_row' ), 20, 2 );
	}

	// ------------------------------------------------------------------
	// Calculo (fuente de verdad: los fees del pedido)
	// ------------------------------------------------------------------

	/**
	 * Monto diferido por el Abono Reserva: el fee negativo del checkout
	 * (match exacto de etiqueta), con fallback al meta persistido para
	 * pedidos donde el fee se haya eliminado.
	 *
	 * @param WC_Order $order Pedido.
	 * @return float Positivo, 0 si el pedido no tiene abono.
	 */
	public static function get_descuento( $order ) {
		$descuento = class_exists( 'SLO_Checkout_Abono' )
			? SLO_Checkout_Abono::extract_descuento( $order )
			: 0.0;

		if ( $descuento <= 0 ) {
			$descuento = (float) $order->get_meta( self::META_DESCUENTO );
		}

		return max( 0.0, $descuento );
	}

	/**
	 * Suma de las cuotas pagadas: fees positivos cuyo nombre termina en
	 * " de cuota" (los crea el boton Abonar, o el admin a mano con esa
	 * misma convencion).
	 *
	 * @param WC_Order $order Pedido.
	 * @return float
	 */
	public static function get_cuotas_total( $order ) {
		$total = 0.0;

		foreach ( $order->get_fees() as $fee ) {
			$name  = $fee->get_name();
			$monto = (float) $fee->get_total();

			if ( $monto > 0 && self::CUOTA_SUFFIX === substr( $name, -strlen( self::CUOTA_SUFFIX ) ) ) {
				$total += $monto;
			}
		}

		return $total;
	}

	/**
	 * Saldo pendiente: lo diferido por el Abono Reserva menos las cuotas
	 * ya registradas. Nunca negativo. Un pedido sin abono (sin descuento)
	 * siempre reporta 0 -- su pago completo lo maneja la pasarela.
	 *
	 * @param WC_Order|int $order Pedido o su ID.
	 * @return float
	 */
	public static function get_saldo_pendiente( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );

		if ( ! $order ) {
			return 0.0;
		}

		$descuento = self::get_descuento( $order );

		if ( $descuento <= 0 ) {
			return 0.0;
		}

		return max( 0.0, $descuento - self::get_cuotas_total( $order ) );
	}

	/**
	 * Valor REAL de la venta: lo ya facturado en el pedido (total, que
	 * incluye las cuotas) mas lo que falta por cobrar (saldo).
	 *
	 * @param WC_Order $order Pedido.
	 * @return float
	 */
	public static function get_total_real( $order ) {
		return (float) $order->get_total() + self::get_saldo_pendiente( $order );
	}

	/**
	 * ¿El pedido participa del sistema de abonos?
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	public static function has_abono_data( $order ) {
		return self::get_descuento( $order ) > 0;
	}

	/**
	 * Refresca los espejos en meta (_slo_monto_abonado = total facturado,
	 * _slo_saldo_pendiente) para YAYMail/plantillas. No guarda el pedido.
	 *
	 * @param WC_Order $order Pedido.
	 */
	public static function sync_meta_mirrors( $order ) {
		$order->update_meta_data( self::META_ABONADO, wc_format_decimal( (float) $order->get_total() ) );
		$order->update_meta_data( self::META_SALDO, wc_format_decimal( self::get_saldo_pendiente( $order ) ) );
	}

	// ------------------------------------------------------------------
	// Registrar cuotas
	// ------------------------------------------------------------------

	/**
	 * Agrega una CUOTA al pedido: una linea de fee positivo que suma al
	 * total y aparece en los correos. Deja nota de auditoria, refresca
	 * los espejos y, si el saldo queda en 0, deja que la puerta de
	 * despacho avance el pedido a Merch Lista.
	 *
	 * @param WC_Order $order  Pedido.
	 * @param float    $monto  Monto de la cuota (positivo).
	 * @param string   $origen manual|saldo (solo para la nota).
	 * @return bool Si se registro.
	 */
	public static function add_cuota( $order, $monto, $origen = 'manual' ) {
		$monto = (float) wc_format_decimal( $monto );

		if ( $monto <= 0 ) {
			return false;
		}

		$fee = new WC_Order_Item_Fee();
		$fee->set_name( wp_strip_all_tags( wc_price( $monto, array( 'currency' => $order->get_currency() ) ) ) . self::CUOTA_SUFFIX );
		$fee->set_amount( (string) $monto );
		$fee->set_total( (string) $monto );
		$fee->set_tax_status( 'none' );

		$order->add_item( $fee );
		$order->calculate_totals( false );

		self::sync_meta_mirrors( $order );

		$saldo = self::get_saldo_pendiente( $order );
		$user  = wp_get_current_user();

		$order->add_order_note(
			sprintf(
				/* translators: 1: installment amount, 2: remaining balance, 3: user login */
				'saldo' === $origen
					? __( 'Cuota final registrada: %1$s (saldo marcado como pagado por %3$s). Saldo pendiente: %2$s.', 'stylelauri-order-flow' )
					: __( 'Cuota registrada: %1$s. Saldo pendiente: %2$s. (%3$s)', 'stylelauri-order-flow' ),
				wp_strip_all_tags( wc_price( $monto ) ),
				wp_strip_all_tags( wc_price( $saldo ) ),
				$user && $user->exists() ? $user->user_login : __( 'sistema', 'stylelauri-order-flow' )
			)
		);

		$order->save();

		// Saldo saldado y el pedido esperando solo la plata (Preparacion
		// o Saldo Pendiente): avanza solo a Merch Lista.
		if ( $saldo <= 0 && class_exists( 'SLO_Dispatch_Gate' ) ) {
			SLO_Dispatch_Gate::maybe_advance_to_processing( $order );
		}

		return true;
	}

	// ------------------------------------------------------------------
	// Panel del pedido
	// ------------------------------------------------------------------

	/**
	 * Panel compacto dentro del panel nativo de datos del pedido.
	 *
	 * @param WC_Order $order Pedido que se esta editando.
	 */
	public static function render_balance_field( $order ) {
		$saldo = self::get_saldo_pendiente( $order );
		$guia  = $order->get_meta( self::META_GUIA );
		?>
		<div class="form-field form-field-wide slo-balance-field">
			<?php if ( self::has_abono_data( $order ) ) : ?>
				<label><?php esc_html_e( 'Abono Reserva', 'stylelauri-order-flow' ); ?></label>
				<p class="description" style="margin-bottom:8px;">
					<?php
					printf(
						/* translators: 1: full sale value, 2: billed so far, 3: pending balance */
						esc_html__( 'Venta: %1$s · Facturado: %2$s · Saldo: %3$s', 'stylelauri-order-flow' ),
						wp_kses_post( wc_price( self::get_total_real( $order ) ) ),
						wp_kses_post( wc_price( (float) $order->get_total() ) ),
						wp_kses_post( wc_price( $saldo ) )
					);
					?>
				</p>

				<?php if ( $saldo > 0 ) : ?>
					<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
						<input
							type="number"
							step="0.01"
							min="0.01"
							max="<?php echo esc_attr( $saldo ); ?>"
							name="slo_nuevo_abono"
							id="slo_nuevo_abono"
							value=""
							placeholder="<?php esc_attr_e( 'Monto de la cuota', 'stylelauri-order-flow' ); ?>"
							style="flex:1;"
						/>
						<button type="submit" name="slo_abonar" value="1" class="button">
							<?php esc_html_e( 'Abonar', 'stylelauri-order-flow' ); ?>
						</button>
					</div>
					<p class="description" style="margin-top:0;">
						<?php esc_html_e( 'Crea una linea "... de cuota" en el pedido (visible en los correos y suma al total). Para corregir una cuota, edita o elimina esa linea en los articulos.', 'stylelauri-order-flow' ); ?>
					</p>
					<p>
						<a
							href="<?php echo esc_url( self::get_pay_button_url( $order ) ); ?>"
							class="button button-secondary"
							onclick="return confirm( '<?php echo esc_js( sprintf( /* translators: %s: plain balance amount */ __( '¿Confirmas que el cliente ya pago el saldo de %s? Se registrara una cuota por ese valor y el saldo pasara a 0.', 'stylelauri-order-flow' ), wp_strip_all_tags( wc_price( $saldo ) ) ) ); ?>' );"
						>
							<?php esc_html_e( 'Marcar saldo como pagado', 'stylelauri-order-flow' ); ?>
						</a>
					</p>
				<?php endif; ?>
			<?php endif; ?>

			<label for="slo_guia_envio" style="margin-top:8px;"><?php esc_html_e( 'Número de guía (envío a domicilio)', 'stylelauri-order-flow' ); ?></label>
			<input
				type="text"
				name="slo_guia_envio"
				id="slo_guia_envio"
				value="<?php echo esc_attr( $guia ); ?>"
				style="width: 100%;"
			/>
			<p class="description">
				<?php esc_html_e( 'Queda como metadato del pedido (_slo_guia_envio) para insertar en las plantillas de correo. Registrarla ANTES de mover el pedido a la etapa de despacho.', 'stylelauri-order-flow' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Procesa el guardado del pedido. WooCommerce ya verifico su nonce
	 * ('woocommerce_meta_nonce') y la capacidad antes de disparar
	 * 'woocommerce_process_shop_order_meta'.
	 *
	 * @param int $order_id ID del pedido.
	 */
	public static function save_balance_field( $order_id ) {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		// Nueva cuota via boton "Abonar" (o guardando con monto puesto).
		if ( isset( $_POST['slo_nuevo_abono'] ) && '' !== trim( wp_unslash( $_POST['slo_nuevo_abono'] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$raw = sanitize_text_field( wp_unslash( $_POST['slo_nuevo_abono'] ) );

			// Formato estricto: numero positivo con punto decimal y max 2
			// decimales. Todo lo demas se rechaza con nota. [Auditoria]
			if ( ! preg_match( '/^\d+(\.\d{1,2})?$/', $raw ) ) {
				$order->add_order_note(
					sprintf(
						/* translators: %s: raw rejected input */
						__( 'Cuota NO registrada: monto "%s" con formato invalido. Usa numeros positivos con punto decimal (ej. 25000 o 25000.50). Para corregir una cuota, edita su linea en los articulos.', 'stylelauri-order-flow' ),
						$raw
					)
				);
			} else {
				$monto = (float) $raw;
				$saldo = self::get_saldo_pendiente( $order );

				if ( $monto > $saldo + 0.01 ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: rejected amount, 2: pending balance */
							__( 'Cuota NO registrada: %1$s excede el saldo pendiente (%2$s).', 'stylelauri-order-flow' ),
							wp_strip_all_tags( wc_price( $monto ) ),
							wp_strip_all_tags( wc_price( $saldo ) )
						)
					);
				} elseif ( $monto > 0 ) {
					self::add_cuota( $order, $monto, 'manual' );
				}
			}
		}

		if ( isset( $_POST['slo_guia_envio'] ) ) {
			$guia = sanitize_text_field( wp_unslash( $_POST['slo_guia_envio'] ) );
			$order->update_meta_data( self::META_GUIA, $guia );
		}

		// Refrescar espejos SIEMPRE al guardar: cubre ediciones manuales
		// de las lineas de cuota hechas en los articulos del pedido.
		self::sync_meta_mirrors( $order );
		$order->save();
	}

	// ------------------------------------------------------------------
	// Boton "Marcar saldo como pagado"
	// ------------------------------------------------------------------

	/**
	 * URL firmada del boton.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	private static function get_pay_button_url( $order ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::PAY_ACTION . '&order_id=' . $order->get_id() ),
			self::PAY_ACTION . '_' . $order->get_id()
		);
	}

	/**
	 * Handler del boton: valida nonce y capacidad, registra la cuota del
	 * saldo restante y vuelve a la pantalla del pedido.
	 */
	public static function handle_mark_saldo_paid() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		if ( ! $order_id
			|| ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::PAY_ACTION . '_' . $order_id )
			|| ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Accion no autorizada.', 'stylelauri-order-flow' ) );
		}

		$order = self::mark_saldo_paid( $order_id );

		if ( ! $order ) {
			wp_die( esc_html__( 'Pedido no encontrado.', 'stylelauri-order-flow' ) );
		}

		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * Registra una cuota por el saldo restante (saldo queda en 0).
	 * Separado del handler HTTP para poder probarse y reutilizarse.
	 *
	 * @param int $order_id ID del pedido.
	 * @return WC_Order|false Pedido actualizado, o false si no existe.
	 */
	public static function mark_saldo_paid( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return false;
		}

		$saldo = self::get_saldo_pendiente( $order );

		if ( $saldo > 0 ) {
			self::add_cuota( $order, $saldo, 'saldo' );
		}

		return wc_get_order( $order_id );
	}

	// ------------------------------------------------------------------
	// Fila de saldo en correos
	// ------------------------------------------------------------------

	/**
	 * Agrega la fila "Saldo pendiente" al final de la tabla de totales
	 * (correos, pagina de gracias, mi cuenta). Las cuotas no necesitan
	 * fila propia: son lineas reales del pedido y ya se muestran.
	 *
	 * @param array    $rows  Filas existentes.
	 * @param WC_Order $order Pedido.
	 * @return array
	 */
	public static function add_saldo_total_row( $rows, $order ) {
		if ( ! self::has_abono_data( $order ) ) {
			return $rows;
		}

		$rows['slo_saldo'] = array(
			'label' => __( 'Saldo pendiente:', 'stylelauri-order-flow' ),
			'value' => wc_price( self::get_saldo_pendiente( $order ), array( 'currency' => $order->get_currency() ) ),
		);

		return $rows;
	}

	// ------------------------------------------------------------------
	// Guard de Merch Lista + recordatorio de saldo
	// ------------------------------------------------------------------

	/**
	 * Se ejecuta en cada cambio de estado. Hace dos cosas:
	 *
	 *  - Si el pedido entra a "Preparacion" (rol listo) y todavia tiene
	 *    saldo, dispara 'slo_saldo_reminder' (hook de extension).
	 *  - Protege Merch Lista (processing) con las dos reglas absolutas
	 *    del despacho. WooCommerce no ofrece un filtro "antes de guardar"
	 *    confiable para transiciones de estado, asi que el patron
	 *    estandar es corregir justo despues.
	 *
	 * @param int      $order_id   ID del pedido.
	 * @param string   $old_status Estado anterior (sin prefijo wc-).
	 * @param string   $new_status Estado nuevo (sin prefijo wc-).
	 * @param WC_Order $order      Pedido.
	 */
	public static function guard_and_notify( $order_id, $old_status, $new_status, $order ) {
		$listo_status = SLO_Order_Statuses::get_status( 'listo' );

		$saldo = self::get_saldo_pendiente( $order );

		// El recordatorio solo aplica cuando el pedido ENTRA a
		// "Preparacion" de forma normal. Si viene DESDE "Procesando" es
		// una reversion de este mismo guard -- no duplicar. Y si el candado
		// de preventa (prioridad 5) ya lo devolvio al embudo, el estado en
		// vivo ya no es Preparacion -- no notificar.
		if ( '' !== $listo_status
			&& $listo_status === $new_status
			&& $listo_status === $order->get_status()
			&& $saldo > 0
			&& 'processing' !== $old_status ) {
			do_action( 'slo_saldo_reminder', $order, $saldo );
		}

		// REGLAS ABSOLUTAS de Merch Lista (Procesando = etapa de despacho,
		// cableada, lo que Skydrops ve), en orden:
		//
		//  1. Solo se llega habiendo pasado por Preparacion (el pedido
		//     empacado). Un salto manual sin pasar por ahi se revierte.
		//     (Las entradas desde pagos las reubica el router de
		//     SLO_Dispatch_Gate al embudo, prioridad 5 en este hook.)
		//  2. Con saldo sin pagar NUNCA se queda: se redirige a Saldo
		//     Pendiente (rol abono) o, sin mapear, se revierte.
		if ( 'processing' === $new_status && class_exists( 'SLO_Dispatch_Gate' ) ) {

			// El router pudo haberlo reubicado ya (embudo de produccion):
			// si el estado real ya no es processing, no hay nada que hacer.
			if ( 'processing' !== $order->get_status() ) {
				return;
			}

			// Regla 1: sin paso por Preparacion no hay Merch Lista.
			if ( '1' !== $order->get_meta( SLO_Dispatch_Gate::META_PASO_LISTO ) ) {
				$order->set_status( $old_status );
				$order->add_order_note(
					__( 'Bloqueado el paso a Merch Lista: el pedido no ha pasado por Preparacion (empaque). Muevelo por el embudo: Abono Produccion → (Preventa) → Preparacion.', 'stylelauri-order-flow' )
				);
				$order->save();
				return;
			}

			// Regla 2: sin saldo en 0 no hay Merch Lista.
			if ( $saldo > 0 ) {
				$abono_status = SLO_Order_Statuses::get_status( 'abono' );

				if ( '' !== $abono_status ) {
					$order->update_status(
						$abono_status,
						sprintf(
							/* translators: %s: formatted balance amount */
							__( 'No puede quedar en Merch Lista: saldo pendiente de %s. Pasa a Saldo Pendiente hasta completar el pago.', 'stylelauri-order-flow' ),
							wp_strip_all_tags( wc_price( $saldo ) )
						)
					);
				} else {
					$order->set_status( $old_status );
					$order->add_order_note(
						sprintf(
							/* translators: %s: formatted balance amount */
							__( 'Bloqueado el paso a Merch Lista: queda un saldo pendiente de %s y el rol "Saldo Pendiente" no esta mapeado.', 'stylelauri-order-flow' ),
							wp_strip_all_tags( wc_price( $saldo ) )
						)
					);
					$order->save();
				}
			}
		}
	}
}
