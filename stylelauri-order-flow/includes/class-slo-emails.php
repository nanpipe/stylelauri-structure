<?php
/**
 * Orquestador de notificaciones.
 *
 * Regla del proyecto: se notifica SOLO en los cambios que le importan al
 * cliente (ver SLO_Order_Statuses::customer_facing_statuses()); los
 * movimientos internos (produccion, listo) son silenciosos salvo cuando
 * "listo" dispara el recordatorio de saldo.
 *
 * El correo de "Enviado" NO se bifurca creando un estado por metodo de
 * envio (ese fue el problema original) -- es UN solo email cuyo
 * CONTENIDO se arma segun el metodo de envio nativo de WooCommerce que
 * ya trae el pedido (domicilio / retiro / contraentrega).
 *
 * @package StyleLauri_Order_Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLO_Emails {

	public static function init() {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_email_classes' ) );

		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'route_status_change' ), 20, 4 );
		add_action( 'slo_saldo_reminder', array( __CLASS__, 'route_saldo_reminder' ), 10, 2 );
	}

	/**
	 * Da de alta las clases de email custom dentro del sistema de
	 * WooCommerce Settings > Emails, para que se puedan activar/editar
	 * el asunto y encabezado desde el admin como cualquier otro email.
	 *
	 * @param array $email_classes Clases de email registradas.
	 * @return array
	 */
	public static function register_email_classes( $email_classes ) {
		require_once SLO_PLUGIN_DIR . 'includes/emails/class-wc-email-slo-abono.php';
		require_once SLO_PLUGIN_DIR . 'includes/emails/class-wc-email-slo-saldo-reminder.php';
		require_once SLO_PLUGIN_DIR . 'includes/emails/class-wc-email-slo-enviado.php';

		$email_classes['WC_Email_SLO_Abono']          = new WC_Email_SLO_Abono();
		$email_classes['WC_Email_SLO_Saldo_Reminder']  = new WC_Email_SLO_Saldo_Reminder();
		$email_classes['WC_Email_SLO_Enviado']        = new WC_Email_SLO_Enviado();

		return $email_classes;
	}

	/**
	 * Traduce un cambio de estado del ciclo de vida a un disparo de email
	 * concreto. Solo dos estados de todo el ciclo disparan email desde
	 * aqui -- Abono y Enviado -- porque Pendiente/Procesando/Completado
	 * ya usan los emails nativos de WooCommerce, y Produccion/Listo son
	 * internos por definicion.
	 *
	 * @param int      $order_id   ID del pedido.
	 * @param string   $old_status Estado anterior.
	 * @param string   $new_status Estado nuevo.
	 * @param WC_Order $order      Pedido.
	 */
	public static function route_status_change( $order_id, $old_status, $new_status, $order ) {
		$abono_status   = SLO_Order_Statuses::get_status( 'abono' );
		$enviado_status = SLO_Order_Statuses::get_status( 'enviado' );

		if ( $abono_status !== $new_status && $enviado_status !== $new_status ) {
			return;
		}

		// WooCommerce instancia las clases de email de forma lazy: si el
		// mailer no se ha cargado aun, los listeners de los triggers custom
		// no existen y el do_action caeria al vacio. Forzar la carga antes.
		WC()->mailer();

		if ( $abono_status === $new_status ) {
			// Si el pedido viene DESDE "Enviado", esto es una reversion
			// (el guard de saldo bloqueo el despacho, o un admin deshizo
			// el envio), no un abono nuevo -- no confirmar de nuevo.
			if ( $enviado_status === $old_status ) {
				return;
			}

			do_action( 'slo_email_trigger_abono', $order_id, $order );
		}

		if ( $enviado_status === $new_status ) {
			// El guard de saldo (SLO_Order_Balance, prioridad 10 en este
			// mismo hook) pudo haber revertido la transicion a "Enviado".
			// Releer el estado real guardado: si ya no es "enviado", el
			// despacho fue bloqueado y NO se debe avisar al cliente.
			$current = wc_get_order( $order_id );

			if ( ! $current || $enviado_status !== $current->get_status() ) {
				return;
			}

			do_action( 'slo_email_trigger_enviado', $order_id, $current );
		}
	}

	/**
	 * Traduce la accion generica de "recordar saldo" (disparada por
	 * SLO_Order_Balance cuando el lote pasa a Listo) al trigger especifico
	 * del email correspondiente. Se mantiene desacoplado a proposito:
	 * el modulo de saldo no sabe nada de emails, solo anuncia el hecho.
	 *
	 * @param WC_Order $order Pedido con saldo pendiente.
	 * @param float    $saldo Monto pendiente.
	 */
	public static function route_saldo_reminder( $order, $saldo ) {
		// Igual que en route_status_change: asegurar que las clases de
		// email esten instanciadas antes de disparar el trigger.
		WC()->mailer();

		do_action( 'slo_email_trigger_saldo_reminder', $order->get_id(), $order );
	}

	/**
	 * Determina el tipo de entrega de un pedido a partir del metodo de
	 * envio nativo de WooCommerce, para ramificar el contenido del email
	 * de "Enviado" sin crear estados nuevos por metodo.
	 *
	 * Filtrable via 'slo_delivery_type' por si los titulos de los metodos
	 * de envio de la tienda no coinciden con las palabras clave por
	 * defecto (retiro / contraentrega / domicilio).
	 *
	 * @param WC_Order $order Pedido.
	 * @return string 'retiro' | 'contraentrega' | 'domicilio'
	 */
	public static function detect_delivery_type( $order ) {
		$method = '';

		foreach ( $order->get_shipping_methods() as $shipping_item ) {
			$method .= ' ' . $shipping_item->get_method_title();
		}

		if ( empty( trim( $method ) ) ) {
			$method = $order->get_shipping_method();
		}

		$method = strtolower( $method );
		$type   = 'domicilio'; // Default si no matchea nada mas especifico.

		if ( false !== strpos( $method, 'retiro' ) || false !== strpos( $method, 'pickup' ) ) {
			$type = 'retiro';
		} elseif ( false !== strpos( $method, 'contra' ) || false !== strpos( $method, 'cod' ) ) {
			$type = 'contraentrega';
		} elseif ( false !== strpos( $method, 'domicilio' ) || false !== strpos( $method, 'envio' ) || false !== strpos( $method, 'envío' ) ) {
			$type = 'domicilio';
		}

		/**
		 * Filtro para sobreescribir la deteccion si los nombres reales de
		 * los metodos de envio de la tienda no incluyen esas palabras.
		 */
		return apply_filters( 'slo_delivery_type', $type, $order );
	}

	/**
	 * Texto de la fecha de despacho, formateado y listo para insertar
	 * en un correo. Cadena vacia si el pedido es 100% stock inmediato.
	 *
	 * @param WC_Order $order Pedido.
	 * @return string
	 */
	public static function get_fecha_despacho_formateada( $order ) {
		$fecha = SLO_Order_Snapshot::get_order_fecha_despacho( $order );

		if ( ! $fecha ) {
			return '';
		}

		return date_i18n( get_option( 'date_format' ), strtotime( $fecha ) );
	}
}
