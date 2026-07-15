<?php
/**
 * Saldo pendiente por abono, y el bloqueo de "Enviado" hasta que se
 * pague. La reserva ya no es un estado que colisiona con lo demas:
 * es un monto (_slo_monto_abonado) que vive junto al total del pedido.
 *
 * Flujo:
 *  1. Admin marca el pedido como "Abono parcial" y registra cuanto pago
 *     el cliente en el campo que este modulo agrega al panel de datos
 *     del pedido.
 *  2. Cuando el lote pasa a "Listo", se dispara 'slo_saldo_reminder'
 *     para cualquier pedido de ese lote que aun tenga saldo -- el
 *     modulo de emails (SLO_Emails) escucha esa accion.
 *  3. El pedido NO puede quedar en "Enviado" mientras tenga saldo > 0:
 *     si alguien intenta ese cambio, se revierte automaticamente al
 *     estado anterior con una nota interna explicando por que.
 *
 * @package StyleLauri_Order_Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLO_Order_Balance {

	const META_ABONADO   = '_slo_monto_abonado';
	const META_ABONOS    = '_slo_abonos';
	const META_DESCUENTO = '_slo_descuento_abono';
	const META_GUIA      = '_slo_guia_envio';

	const PAY_ACTION = 'slo_marcar_saldo_pagado';

	public static function init() {
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'render_balance_field' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'save_balance_field' ), 10, 1 );

		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'guard_and_notify' ), 10, 4 );

		// Boton "Marcar saldo como pagado" del panel del pedido.
		add_action( 'admin_post_' . self::PAY_ACTION, array( __CLASS__, 'handle_mark_saldo_paid' ) );

		// Filas de abonos/saldo en la tabla de totales del pedido: se ven
		// en los correos de WooCommerce y en la pagina "pedido recibido" /
		// "mi cuenta" del cliente, SIN tocar el total real del pedido.
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'add_abono_total_rows' ), 20, 2 );
	}

	/**
	 * Agrega cada abono (con su fecha) y el saldo pendiente como filas
	 * informativas al final de la tabla de totales. Es la forma correcta
	 * de mostrarlos en correos: un fee positivo "de cuota" INFLARIA el
	 * total del pedido.
	 *
	 * @param array    $rows  Filas existentes (subtotal, envio, total...).
	 * @param WC_Order $order Pedido.
	 * @return array
	 */
	public static function add_abono_total_rows( $rows, $order ) {
		if ( ! self::has_abono_data( $order ) ) {
			return $rows;
		}

		$abonos      = self::get_abonos( $order );
		$date_format = get_option( 'date_format' );

		foreach ( $abonos as $i => $abono ) {
			$label = self::origen_label( $abono['origen'] );

			if ( ! empty( $abono['fecha'] ) ) {
				$label .= ' — ' . date_i18n( $date_format, strtotime( $abono['fecha'] ) );
			}

			$rows[ 'slo_abono_' . $i ] = array(
				'label' => $label . ':',
				'value' => wc_price( (float) $abono['monto'], array( 'currency' => $order->get_currency() ) ),
			);
		}

		$rows['slo_saldo'] = array(
			'label' => __( 'Saldo pendiente:', 'stylelauri-order-flow' ),
			'value' => wc_price( self::get_saldo_pendiente( $order ), array( 'currency' => $order->get_currency() ) ),
		);

		return $rows;
	}

	/**
	 * Valor REAL del pedido: lo que WooCommerce tiene como total mas el
	 * descuento diferido del Abono Reserva (si lo hubo). Cuando el cliente
	 * paga con abono en el checkout, el total del pedido queda rebajado
	 * por el fee negativo -- el valor completo de la venta es este.
	 *
	 * @param WC_Order $order Pedido.
	 * @return float
	 */
	public static function get_total_real( $order ) {
		return (float) $order->get_total() + (float) $order->get_meta( self::META_DESCUENTO );
	}

	/**
	 * Saldo pendiente de un pedido: total real menos lo abonado. Nunca
	 * negativo. Cubre los dos flujos con la misma formula:
	 *
	 *  - Abono manual (WhatsApp): descuento = 0, el admin registra el
	 *    monto abonado a mano -> saldo = total - abonado.
	 *  - Abono Reserva del checkout: abonado = lo cobrado por la pasarela,
	 *    descuento = lo diferido -> saldo = ese descuento.
	 *
	 * @param WC_Order|int $order Pedido o su ID.
	 * @return float
	 */
	public static function get_saldo_pendiente( $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );

		if ( ! $order ) {
			return 0.0;
		}

		// Un pedido SIN datos de abono no es un pedido "con saldo": es un
		// pedido normal cuyo pago completo lo maneja WooCommerce/la
		// pasarela (incluida la contraentrega). Sin este corte, todo
		// pedido normal daria saldo = total y quedaria bloqueado.
		if ( ! self::has_abono_data( $order ) ) {
			return 0.0;
		}

		$abonado = (float) $order->get_meta( self::META_ABONADO );

		return max( 0.0, self::get_total_real( $order ) - $abonado );
	}

	/**
	 * ¿El pedido participa del sistema de abonos? Cierto si alguna vez se
	 * registro un abono (el meta de abonado existe) o si vino del checkout
	 * con Abono Reserva (descuento diferido > 0).
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	public static function has_abono_data( $order ) {
		return $order->meta_exists( self::META_ABONADO )
			|| (float) $order->get_meta( self::META_DESCUENTO ) > 0;
	}

	/**
	 * Historial de abonos del pedido. Cada entrada:
	 * array{fecha:string,monto:float,origen:string,usuario:string}
	 *
	 * @param WC_Order $order Pedido.
	 * @return array[]
	 */
	public static function get_abonos( $order ) {
		$abonos = $order->get_meta( self::META_ABONOS );
		return is_array( $abonos ) ? $abonos : array();
	}

	/**
	 * Registra un abono en el historial y recalcula el total abonado.
	 *
	 * Si el pedido tenia un monto abonado de la version anterior (sin
	 * historial), esa cifra se preserva como primera entrada "previo"
	 * para que la suma nunca pierda plata ya registrada.
	 *
	 * @param WC_Order $order  Pedido.
	 * @param float    $monto  Monto del abono (negativo = correccion).
	 * @param string   $origen manual|checkout|saldo|previo.
	 * @param bool     $save   Guardar el pedido (false durante el checkout,
	 *                         donde WooCommerce guarda despues del hook).
	 * @param bool     $note   Dejar nota en el pedido (requiere ID, no
	 *                         disponible durante el checkout).
	 */
	public static function add_abono( $order, $monto, $origen = 'manual', $save = true, $note = true ) {
		$monto  = (float) wc_format_decimal( $monto );
		$abonos = self::get_abonos( $order );

		// Migracion: abonado viejo sin historial -> primera entrada.
		$abonado_previo = (float) $order->get_meta( self::META_ABONADO );
		if ( empty( $abonos ) && $abonado_previo > 0 ) {
			$abonos[] = array(
				'fecha'   => '',
				'monto'   => $abonado_previo,
				'origen'  => 'previo',
				'usuario' => '',
			);
		}

		$user = wp_get_current_user();

		$abonos[] = array(
			'fecha'   => current_time( 'mysql' ),
			'monto'   => $monto,
			'origen'  => $origen,
			'usuario' => $user && $user->exists() ? $user->user_login : '',
		);

		$total = 0.0;
		foreach ( $abonos as $abono ) {
			$total += (float) $abono['monto'];
		}

		$order->update_meta_data( self::META_ABONOS, $abonos );
		$order->update_meta_data( self::META_ABONADO, wc_format_decimal( $total ) );

		if ( $note ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: payment amount, 2: total paid so far, 3: remaining balance */
					__( 'Abono registrado: %1$s. Total abonado: %2$s. Saldo pendiente: %3$s.', 'stylelauri-order-flow' ),
					wp_strip_all_tags( wc_price( $monto ) ),
					wp_strip_all_tags( wc_price( $total ) ),
					wp_strip_all_tags( wc_price( max( 0.0, self::get_total_real( $order ) - $total ) ) )
				)
			);
		}

		if ( $save ) {
			$order->save();

			// Saldo saldado y el lote ya llego (pedido en "Listo"): la
			// puerta de despacho lo avanza sola a Procesando (Skydrops).
			if ( $total >= self::get_total_real( $order ) && class_exists( 'SLO_Dispatch_Gate' ) ) {
				SLO_Dispatch_Gate::maybe_advance_to_processing( $order );
			}
		}
	}

	/**
	 * Etiqueta humana del origen de un abono.
	 *
	 * @param string $origen Clave de origen.
	 * @return string
	 */
	public static function origen_label( $origen ) {
		$labels = array(
			'manual'   => __( 'Abono', 'stylelauri-order-flow' ),
			'checkout' => __( 'Abono Reserva (checkout)', 'stylelauri-order-flow' ),
			'saldo'    => __( 'Pago de saldo', 'stylelauri-order-flow' ),
			'previo'   => __( 'Abono previo', 'stylelauri-order-flow' ),
		);

		return isset( $labels[ $origen ] ) ? $labels[ $origen ] : $origen;
	}

	/**
	 * Panel de abonos dentro del panel nativo de datos del pedido (no se
	 * crea un meta box aparte, se inyecta en el existente via el hook
	 * oficial de WooCommerce para esto).
	 *
	 * @param WC_Order $order Pedido que se esta editando.
	 */
	public static function render_balance_field( $order ) {
		$abonado = (float) $order->get_meta( self::META_ABONADO );
		$abonos  = self::get_abonos( $order );
		$saldo   = self::get_saldo_pendiente( $order );
		$guia    = $order->get_meta( self::META_GUIA );

		$date_format = get_option( 'date_format' );
		?>
		<div class="form-field form-field-wide slo-balance-field">
			<label><?php esc_html_e( 'Abonos', 'stylelauri-order-flow' ); ?></label>

			<?php if ( empty( $abonos ) && $abonado > 0 ) : ?>
				<p style="margin:4px 0;">
					<?php
					printf(
						/* translators: %s: previously recorded amount */
						esc_html__( 'Abono previo (sin fecha registrada): %s', 'stylelauri-order-flow' ),
						wp_kses_post( wc_price( $abonado ) )
					);
					?>
				</p>
			<?php elseif ( ! empty( $abonos ) ) : ?>
				<ul style="margin:4px 0 8px;padding-left:0;list-style:none;">
					<?php foreach ( $abonos as $abono ) : ?>
						<li style="padding:3px 0;border-bottom:1px dotted #dcdcde;">
							<?php
							$fecha = ! empty( $abono['fecha'] )
								? date_i18n( $date_format, strtotime( $abono['fecha'] ) )
								: __( 'sin fecha', 'stylelauri-order-flow' );

							printf(
								'%1$s &mdash; %2$s &mdash; <strong>%3$s</strong>%4$s',
								esc_html( self::origen_label( $abono['origen'] ) ),
								esc_html( $fecha ),
								wp_kses_post( wc_price( (float) $abono['monto'] ) ),
								! empty( $abono['usuario'] ) ? esc_html( ' (' . $abono['usuario'] . ')' ) : ''
							);
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p style="margin:4px 0;color:#787c82;"><?php esc_html_e( 'Sin abonos registrados.', 'stylelauri-order-flow' ); ?></p>
			<?php endif; ?>

			<p class="description" style="margin-bottom:8px;">
				<?php
				printf(
					/* translators: 1: real order total, 2: total paid, 3: formatted balance amount */
					esc_html__( 'Venta: %1$s · Abonado: %2$s · Saldo: %3$s', 'stylelauri-order-flow' ),
					wp_kses_post( wc_price( self::get_total_real( $order ) ) ),
					wp_kses_post( wc_price( $abonado ) ),
					wp_kses_post( wc_price( $saldo ) )
				);
				?>
			</p>

			<?php if ( $saldo > 0 ) : ?>
				<div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
					<input
						type="number"
						step="0.01"
						name="slo_nuevo_abono"
						id="slo_nuevo_abono"
						value=""
						placeholder="<?php esc_attr_e( 'Monto del abono', 'stylelauri-order-flow' ); ?>"
						style="flex:1;"
					/>
					<button type="submit" name="slo_abonar" value="1" class="button">
						<?php esc_html_e( 'Abonar', 'stylelauri-order-flow' ); ?>
					</button>
				</div>
				<p class="description" style="margin-top:0;">
					<?php esc_html_e( 'El monto queda en el historial con fecha y usuario. Un valor negativo corrige un abono mal registrado.', 'stylelauri-order-flow' ); ?>
				</p>
				<p>
					<a
						href="<?php echo esc_url( self::get_pay_button_url( $order ) ); ?>"
						class="button button-secondary"
						onclick="return confirm( '<?php echo esc_js( sprintf( /* translators: %s: plain balance amount */ __( '¿Confirmas que el cliente ya pago el saldo de %s? Se registrara un abono por ese valor y el saldo pasara a 0.', 'stylelauri-order-flow' ), wp_strip_all_tags( wc_price( $saldo ) ) ) ); ?>' );"
					>
						<?php esc_html_e( 'Marcar saldo como pagado', 'stylelauri-order-flow' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>
		<div class="form-field form-field-wide slo-guia-field">
			<label for="slo_guia_envio"><?php esc_html_e( 'Número de guía (envío a domicilio)', 'stylelauri-order-flow' ); ?></label>
			<input
				type="text"
				name="slo_guia_envio"
				id="slo_guia_envio"
				value="<?php echo esc_attr( $guia ); ?>"
				style="width: 100%;"
			/>
			<p class="description">
				<?php esc_html_e( 'Se incluye en el correo de "Enviado". Registrarla ANTES de pasar el pedido a Enviado.', 'stylelauri-order-flow' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Guarda el monto abonado. Se cuelga de 'woocommerce_process_shop_order_meta',
	 * que WooCommerce solo dispara despues de verificar su propio nonce
	 * ('woocommerce_meta_nonce') y la capacidad de editar el pedido --
	 * por eso no se repite esa verificacion aqui, solo se sanitiza el
	 * valor antes de guardarlo.
	 *
	 * @param int $order_id ID del pedido.
	 */
	public static function save_balance_field( $order_id ) {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		if ( ! isset( $_POST['slo_nuevo_abono'] ) && ! isset( $_POST['slo_guia_envio'] ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return;
		}

		// Nuevo abono: se registra en el historial (con fecha y usuario),
		// no se edita el total a mano. Funciona tanto con el boton
		// "Abonar" como con el "Actualizar" general del pedido.
		if ( isset( $_POST['slo_nuevo_abono'] ) && '' !== trim( wp_unslash( $_POST['slo_nuevo_abono'] ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$monto = (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['slo_nuevo_abono'] ) ) );

			if ( 0.0 !== $monto ) {
				self::add_abono( $order, $monto, 'manual', false );
			}
		}

		if ( isset( $_POST['slo_guia_envio'] ) ) {
			$guia = sanitize_text_field( wp_unslash( $_POST['slo_guia_envio'] ) );
			$order->update_meta_data( self::META_GUIA, $guia );
		}

		$order->save();
	}

	/**
	 * URL firmada del boton "Marcar saldo como pagado".
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
	 * Handler del boton: valida nonce y capacidad, marca el saldo como
	 * pagado y vuelve a la pantalla del pedido.
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
	 * Marca el saldo de un pedido como pagado: abonado = total real,
	 * saldo = 0, con nota de auditoria. Separado del handler HTTP para
	 * poder probarse y reutilizarse (ej. desde WP-CLI).
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
			// El pago del saldo tambien es un abono: entra al historial con
			// fecha, usuario y origen "saldo", y deja su nota de auditoria.
			self::add_abono( $order, $saldo, 'saldo' );
		}

		return $order;
	}

	/**
	 * Se ejecuta en cada cambio de estado. Hace dos cosas:
	 *
	 *  - Si el pedido entra a "Listo" y todavia tiene saldo, dispara
	 *    'slo_saldo_reminder' para que el modulo de emails mande el
	 *    recordatorio de saldo.
	 *  - Si el pedido intenta entrar a "Enviado" con saldo pendiente,
	 *    revierte al estado anterior y deja una nota interna. WooCommerce
	 *    no ofrece un filtro "antes de guardar" confiable para transiciones
	 *    de estado, asi que el patron estandar es revertir justo despues.
	 *
	 * @param int      $order_id   ID del pedido.
	 * @param string   $old_status Estado anterior (sin prefijo wc-).
	 * @param string   $new_status Estado nuevo (sin prefijo wc-).
	 * @param WC_Order $order      Pedido.
	 */
	public static function guard_and_notify( $order_id, $old_status, $new_status, $order ) {
		$listo_status   = SLO_Order_Statuses::get_status( 'listo' );
		$enviado_status = SLO_Order_Statuses::get_status( 'enviado' );

		$saldo = self::get_saldo_pendiente( $order );

		// El recordatorio solo aplica cuando el pedido ENTRA a "Listo" de
		// forma normal. Si viene DESDE "Enviado" o "Procesando" es la
		// reversion de este mismo guard (bloqueo de despacho) -- el
		// cliente ya recibio su recordatorio cuando el lote paso a Listo,
		// no duplicar.
		if ( $listo_status === $new_status
			&& $saldo > 0
			&& ! in_array( $old_status, array( $enviado_status, 'processing' ), true ) ) {
			do_action( 'slo_saldo_reminder', $order, $saldo );
		}

		if ( $enviado_status === $new_status && $saldo > 0 ) {
			$order->set_status( $old_status );
			$order->add_order_note(
				sprintf(
					/* translators: %s: formatted balance amount */
					__( 'Bloqueado el paso a "Enviado": queda un saldo pendiente de %s. Registra el pago del saldo antes de despachar.', 'stylelauri-order-flow' ),
					wp_strip_all_tags( wc_price( $saldo ) )
				)
			);
			$order->save();
			return;
		}

		// Con la puerta de despacho activa, "Procesando" significa
		// "despachable YA" (Skydrops lo ve). Un movimiento MANUAL desde un
		// estado interno hacia Procesando con saldo pendiente se bloquea
		// igual que Enviado. (Las entradas desde estados de pago las
		// reubica el router de SLO_Dispatch_Gate, no este guard.)
		$produccion_status = SLO_Order_Statuses::get_status( 'produccion' );

		if ( 'processing' === $new_status
			&& $saldo > 0
			&& class_exists( 'SLO_Dispatch_Gate' )
			&& SLO_Dispatch_Gate::is_enabled()
			&& in_array( $old_status, array_filter( array( $produccion_status, $listo_status, $enviado_status ) ), true ) ) {
			$order->set_status( $old_status );
			$order->add_order_note(
				sprintf(
					/* translators: %s: formatted balance amount */
					__( 'Bloqueado el paso a "Procesando" (despacho): queda un saldo pendiente de %s. Con la puerta de despacho activa, Procesando significa pagado completo y listo para Skydrops.', 'stylelauri-order-flow' ),
					wp_strip_all_tags( wc_price( $saldo ) )
				)
			);
			$order->save();
		}
	}
}
