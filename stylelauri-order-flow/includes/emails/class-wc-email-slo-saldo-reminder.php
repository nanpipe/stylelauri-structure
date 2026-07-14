<?php
/**
 * Email: recordatorio de saldo pendiente.
 *
 * Se dispara cuando el LOTE del pedido pasa a "Listo" y el pedido
 * todavia tiene saldo (ver SLO_Order_Balance::guard_and_notify()).
 *
 * @package StyleLauri_Order_Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Email' ) ) {
	return;
}

class WC_Email_SLO_Saldo_Reminder extends WC_Email {

	public function __construct() {
		$this->id             = 'slo_saldo_reminder';
		$this->customer_email = true;
		$this->title          = __( 'StyleLauri: Recordatorio de saldo', 'stylelauri-order-flow' );
		$this->description    = __( 'Se envia cuando el lote del pedido esta listo y todavia queda saldo por cobrar.', 'stylelauri-order-flow' );
		$this->heading        = __( 'Tu pedido esta casi listo -- falta el saldo', 'stylelauri-order-flow' );
		$this->subject        = __( '{site_title} - Falta tu saldo para despachar el pedido #{order_number}', 'stylelauri-order-flow' );
		$this->placeholders   = array( '{order_number}' => '' );

		add_action( 'slo_email_trigger_saldo_reminder', array( $this, 'trigger' ), 10, 2 );

		parent::__construct();
	}

	/**
	 * @param int           $order_id ID del pedido.
	 * @param bool|WC_Order $order    Pedido (opcional, ya resuelto).
	 */
	public function trigger( $order_id, $order = false ) {
		$this->setup_locale();

		if ( $order_id && ! is_a( $order, 'WC_Order' ) ) {
			$order = wc_get_order( $order_id );
		}

		if ( is_a( $order, 'WC_Order' ) ) {
			$this->object                          = $order;
			$this->recipient                       = $order->get_billing_email();
			$this->placeholders['{order_number}']  = $order->get_order_number();
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	public function get_content_html() {
		// wrap_message() vive en el mailer (WC_Emails), no en WC_Email.
		return WC()->mailer()->wrap_message( $this->get_heading(), $this->build_body() );
	}

	public function get_content_plain() {
		return wc_strip_all_tags( $this->build_body() );
	}

	private function build_body() {
		$order = $this->object;
		$saldo = class_exists( 'SLO_Order_Balance' ) ? SLO_Order_Balance::get_saldo_pendiente( $order ) : 0;

		ob_start();
		?>
		<p>
			<?php
			printf(
				/* translators: %s: customer first name */
				esc_html__( 'Hola %s,', 'stylelauri-order-flow' ),
				esc_html( $order->get_billing_first_name() )
			);
			?>
		</p>
		<p><?php esc_html_e( 'Tu pedido ya esta listo de nuestro lado. Antes de despacharlo necesitamos que completes el saldo pendiente:', 'stylelauri-order-flow' ); ?></p>
		<p style="font-size: 1.2em;"><strong><?php echo wp_kses_post( wc_price( $saldo ) ); ?></strong></p>
		<p><?php esc_html_e( 'Apenas confirmemos el pago, programamos el envio o retiro.', 'stylelauri-order-flow' ); ?></p>
		<?php
		return ob_get_clean();
	}
}
