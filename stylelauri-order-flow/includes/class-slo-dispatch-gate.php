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
 * CANDADO DE PREVENTA: un pedido de preventa no puede pasar a Preparacion
 * (empaque) antes de que su lote este disponible. "Disponible" = la fecha
 * de despacho prometida ya llego, el lote se marco Producido, o se libero
 * a mano con el boton del pedido. Mientras siga bloqueado, cualquier
 * intento de moverlo a Preparacion lo DEJA donde estaba (Preventa o Abono
 * Produccion). El boton "Liberar" solo autoadelanta a Preparacion cuando
 * el pedido esta en Preventa -- desde Abono Produccion (donde se imprime
 * la etiqueta) solo libera el candado, para no perder el pedido.
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
	// Liberacion manual del candado de preventa (boton del pedido).
	const META_LIBERADO   = '_slo_preventa_liberado';

	const LIBERAR_ACTION = 'slo_liberar_preventa';

	public static function init() {
		// Prioridad 5: el router corre antes que el guard de saldo (10) y
		// que el router de emails (20) en el mismo hook.
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'route_on_status_change' ), 5, 4 );

		// El email nativo de "Procesando" solo tiene sentido si el pedido
		// QUEDO en Procesando; si el router lo reubico, se suprime.
		add_filter( 'woocommerce_email_enabled_customer_processing_order', array( __CLASS__, 'suppress_if_routed' ), 20, 2 );

		// Panel + boton "Liberar a Preparacion" en la pantalla del pedido.
		add_action( 'woocommerce_admin_order_data_after_order_details', array( __CLASS__, 'render_preventa_lock_panel' ) );
		add_action( 'admin_post_' . self::LIBERAR_ACTION, array( __CLASS__, 'handle_liberar_preventa' ) );
	}

	/**
	 * La puerta de despacho es OBLIGATORIA: "processing" (Merch Lista)
	 * es la etapa de despacho, cableada. Sin interruptor en Ajustes --
	 * apagarla dejaria pedidos sin empacar o con saldo visibles para
	 * Skydrops. Queda un filtro como valvula de emergencia para codigo.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		return (bool) apply_filters( 'slo_dispatch_gate_enabled', true );
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
			// CANDADO DE PREVENTA: un pedido de preventa no se empaca
			// (Preparacion) antes de que su lote este disponible. "Disponible"
			// = fecha de despacho cumplida, lote marcado Producido, o
			// liberacion manual desde el pedido. Si sigue bloqueado, se
			// devuelve al embudo (Abono Produccion / Preventa) y NO se marca
			// el paso por Listo.
			if ( self::is_preventa_locked( $order ) ) {
				self::revert_preventa_lock( $order, $old_status );
				return;
			}

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

		// REGLA DE EMBUDO UNIVERSAL: a Merch Lista (Procesando) solo se
		// llega habiendo pasado por Preparacion (donde el pedido se
		// empaca fisicamente). TODO pago -- preventa o stock, con o sin
		// saldo -- entra primero a "Abono Produccion". El saldo se cobra
		// despues de Preparacion: ese caso lo captura SLO_Order_Balance
		// en el mismo hook (prioridad 10) y lo manda a Saldo Pendiente.
		if ( '1' !== $order->get_meta( self::META_PASO_LISTO )
			&& SLO_Order_Statuses::is_mapped( 'produccion' ) ) {
			$order->update_status(
				SLO_Order_Statuses::get_status( 'produccion' ),
				__( 'Puerta de despacho: pago recibido, entra al embudo. Llegara a Merch Lista despues de pasar por Preparacion (empaque) y con saldo en 0.', 'stylelauri-order-flow' )
			);
			return;
		}

		// Pedido que ya paso por Preparacion: se queda en Procesando
		// (Merch Lista) -- salvo que tenga saldo, caso que resuelve el
		// guard de SLO_Order_Balance justo despues.
	}

	/**
	 * Llamado por SLO_Order_Balance cuando un abono deja el saldo en 0:
	 * si el pedido estaba en "Preparacion" (rol listo) o en "Saldo
	 * Pendiente" (rol abono) -- es decir, esperando solo la plata --
	 * avanza solo a Procesando (Merch Lista) para que Skydrops lo recoja.
	 *
	 * @param WC_Order $order Pedido con saldo recien saldado.
	 */
	public static function maybe_advance_to_processing( $order ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		$advance_from = array_filter(
			array(
				SLO_Order_Statuses::get_status( 'listo' ),
				SLO_Order_Statuses::get_status( 'abono' ),
			)
		);

		if ( ! in_array( $order->get_status(), $advance_from, true ) ) {
			return;
		}

		$order->update_status(
			'processing',
			__( 'Saldo en 0: pasa a Merch Lista (Procesando), visible para despacho en Skydrops.', 'stylelauri-order-flow' )
		);
	}

	// ------------------------------------------------------------------
	// Candado de preventa (no empacar antes de que el lote llegue)
	// ------------------------------------------------------------------

	/**
	 * ¿El pedido esta bloqueado para pasar a Preparacion por ser preventa
	 * cuyo lote todavia no esta disponible?
	 *
	 * Bloqueado = es preventa Y no fue liberado a mano Y ningun/no todos
	 * sus lotes estan Producidos Y la fecha de despacho prometida aun no
	 * llega. Cualquiera de esas tres salidas (fecha cumplida, todos los
	 * lotes Producidos, liberacion manual) lo desbloquea.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	public static function is_preventa_locked( $order ) {
		if ( ! self::is_enabled() ) {
			return false;
		}

		// Stock inmediato nunca se bloquea.
		if ( ! SLO_Order_Snapshot::order_is_preventa( $order ) ) {
			return false;
		}

		// Liberacion manual desde el pedido.
		if ( '1' === $order->get_meta( self::META_LIBERADO ) ) {
			return false;
		}

		// Todos los lotes del pedido marcados Producido.
		if ( self::all_lotes_producidos( $order ) ) {
			return false;
		}

		// Fecha de despacho prometida (snapshot) ya cumplida. Sin fecha no
		// se puede liberar por tiempo -- queda a merced de Producido/manual.
		$fecha = SLO_Order_Snapshot::get_order_fecha_despacho( $order );
		if ( '' !== $fecha && $fecha <= current_time( 'Y-m-d' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * ¿TODOS los lotes que toca el pedido estan marcados Producido? Un
	 * pedido sin lotes (stock) devuelve false -- no aplica el candado.
	 *
	 * @param WC_Order $order Pedido.
	 * @return bool
	 */
	private static function all_lotes_producidos( $order ) {
		$lotes = SLO_Order_Snapshot::get_order_lotes( $order );

		if ( empty( $lotes ) ) {
			return false;
		}

		foreach ( $lotes as $slug ) {
			if ( ! SLO_Taxonomy::is_slug_producido( $slug ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Revierte un intento de pasar a Preparacion bloqueado: DEJA el pedido
	 * donde estaba (estado de origen). No lo fuerza a Abono Produccion --
	 * ahi se imprime la etiqueta, y arrastrarlo de vuelta reimprimiria /
	 * lo sacaria de Preventa. Deja nota explicando como adelantarlo.
	 *
	 * @param WC_Order $order      Pedido.
	 * @param string   $old_status Estado anterior (sin wc-).
	 */
	private static function revert_preventa_lock( $order, $old_status ) {
		$destino = $old_status;

		if ( '' === $destino || $destino === $order->get_status() ) {
			return;
		}

		$fecha = SLO_Order_Snapshot::get_order_fecha_despacho( $order );

		$nota = '' !== $fecha
			? sprintf(
				/* translators: %s: promised dispatch date */
				__( 'Bloqueado el paso a Preparacion: pedido de preventa cuya fecha de despacho (%s) aun no llega y el lote no esta marcado como Producido. Se mantiene en Preventa. Usa "Liberar a Preparacion" en el pedido, o marca el lote como Producido, para adelantarlo.', 'stylelauri-order-flow' ),
				$fecha
			)
			: __( 'Bloqueado el paso a Preparacion: pedido de preventa cuyo lote no esta marcado como Producido. Se mantiene en Preventa. Usa "Liberar a Preparacion" o marca el lote como Producido para adelantarlo.', 'stylelauri-order-flow' );

		$order->update_status( $destino, $nota );
	}

	// ------------------------------------------------------------------
	// Boton "Liberar a Preparacion"
	// ------------------------------------------------------------------

	/**
	 * Panel del candado dentro del panel nativo de datos del pedido. Solo
	 * se muestra en pedidos de preventa.
	 *
	 * @param WC_Order $order Pedido que se esta editando.
	 */
	public static function render_preventa_lock_panel( $order ) {
		if ( ! SLO_Order_Snapshot::order_is_preventa( $order ) ) {
			return;
		}

		$fecha    = SLO_Order_Snapshot::get_order_fecha_despacho( $order );
		$liberado = '1' === $order->get_meta( self::META_LIBERADO );
		$locked   = self::is_preventa_locked( $order );
		?>
		<div class="form-field form-field-wide slo-preventa-lock">
			<label><?php esc_html_e( 'Preventa', 'stylelauri-order-flow' ); ?></label>
			<?php if ( $locked ) : ?>
				<p class="description" style="margin-bottom:8px;">
					<?php
					printf(
						/* translators: %s: promised dispatch date (may be empty) */
						esc_html__( 'Bloqueado para Preparacion%s. No puede empacarse hasta que el lote este disponible.', 'stylelauri-order-flow' ),
						'' !== $fecha ? ' ' . sprintf( /* translators: %s: date */ esc_html__( '(despacho %s)', 'stylelauri-order-flow' ), esc_html( $fecha ) ) : ''
					);
					?>
				</p>
				<p>
					<a
						href="<?php echo esc_url( self::get_liberar_url( $order ) ); ?>"
						class="button button-secondary"
						onclick="return confirm( '<?php echo esc_js( __( '¿Liberar este pedido a Preparacion antes de que el lote este disponible?', 'stylelauri-order-flow' ) ); ?>' );"
					>
						<?php esc_html_e( 'Liberar a Preparación', 'stylelauri-order-flow' ); ?>
					</a>
				</p>
			<?php elseif ( $liberado ) : ?>
				<p class="description"><?php esc_html_e( 'Liberado manualmente a Preparacion.', 'stylelauri-order-flow' ); ?></p>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'Lote disponible (fecha cumplida o marcado como Producido). Puede pasar a Preparacion.', 'stylelauri-order-flow' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * URL firmada del boton "Liberar a Preparacion".
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	private static function get_liberar_url( $order ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::LIBERAR_ACTION . '&order_id=' . $order->get_id() ),
			self::LIBERAR_ACTION . '_' . $order->get_id()
		);
	}

	/**
	 * Handler del boton: valida nonce y capacidad, libera el candado y
	 * vuelve a la pantalla del pedido.
	 */
	public static function handle_liberar_preventa() {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		if ( ! $order_id
			|| ! isset( $_GET['_wpnonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::LIBERAR_ACTION . '_' . $order_id )
			|| ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Accion no autorizada.', 'stylelauri-order-flow' ) );
		}

		$order = self::liberar_preventa( $order_id );

		if ( ! $order ) {
			wp_die( esc_html__( 'Pedido no encontrado.', 'stylelauri-order-flow' ) );
		}

		wp_safe_redirect( $order->get_edit_order_url() );
		exit;
	}

	/**
	 * Marca el pedido como liberado (desbloquea el candado) y, si esta en
	 * el embudo de Abono Produccion, lo adelanta a Preparacion. Separado
	 * del handler HTTP para poder probarse y reutilizarse.
	 *
	 * @param int $order_id ID del pedido.
	 * @return WC_Order|false Pedido actualizado, o false si no existe.
	 */
	public static function liberar_preventa( $order_id ) {
		$order = wc_get_order( $order_id );

		if ( ! $order ) {
			return false;
		}

		$order->update_meta_data( self::META_LIBERADO, '1' );
		$order->add_order_note(
			__( 'Preventa liberada manualmente: habilitado el paso a Preparacion antes de la fecha de despacho / sin marcar el lote como Producido.', 'stylelauri-order-flow' )
		);
		$order->save();

		// Solo autoadelanta si el pedido esta en PREVENTA (esperando el
		// lote). Desde Abono Produccion NO: ahi se imprime la etiqueta, y
		// saltar a Preparacion (que asume etiqueta impresa) haria perder el
		// pedido. Desde cualquier otro estado el candado solo queda
		// liberado y el admin mueve el pedido a mano.
		$preventa = SLO_Order_Statuses::get_status( 'preventa' );
		$listo    = SLO_Order_Statuses::get_status( 'listo' );

		if ( '' !== $listo && '' !== $preventa && $preventa === $order->get_status() ) {
			$order->update_status(
				$listo,
				__( 'Liberado manualmente desde Preventa: pasa a Preparacion.', 'stylelauri-order-flow' )
			);
		}

		return wc_get_order( $order_id );
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
