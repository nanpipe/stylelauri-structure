<?php
/**
 * Email: confirmacion de abono parcial recibido.
 *
 * @package StyleLauri_Order_Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Email' ) ) {
	return;
}

class WC_Email_SLO_Abono extends WC_Email {

	public function __construct() {
		$this->id             = 'slo_abono';
		$this->customer_email = true;
		$this->title          = __( 'StyleLauri: Abono recibido', 'stylelauri-order-flow' );
		$this->description    = __( 'Se envia al cliente cuando su pedido pasa a "Abono parcial".', 'stylelauri-order-flow' );
		$this->heading        = __( 'Recibimos tu abono', 'stylelauri-order-flow' );
		$this->subject        = __( '{site_title} - Recibimos tu abono (pedido #{order_number})', 'stylelauri-order-flow' );
		$this->placeholders   = array( '{order_number}' => '' );

		add_action( 'slo_email_trigger_abono', array( $this, 'trigger' ), 10, 2 );

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
		// wrap_message() vive en el mailer (WC_Emails), no en WC_Email:
		// envuelve el cuerpo con el header/footer estandar de WooCommerce.
		return WC()->mailer()->wrap_message( $this->get_heading(), $this->build_body() );
	}

	public function get_content_plain() {
		return wc_strip_all_tags( $this->build_body() );
	}

	private function build_body() {
		$order = $this->object;
		$saldo = class_exists( 'SLO_Order_Balance' ) ? SLO_Order_Balance::get_saldo_pendiente( $order ) : 0;
		$fecha = class_exists( 'SLO_Emails' ) ? SLO_Emails::get_fecha_despacho_formateada( $order ) : '';

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
		<p><?php esc_html_e( 'Registramos tu abono y tu pedido quedo reservado.', 'stylelauri-order-flow' ); ?></p>
		<?php if ( $fecha ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: fecha de despacho */
					esc_html__( 'Lo enviaremos el %s.', 'stylelauri-order-flow' ),
					esc_html( $fecha )
				);
				?>
			</p>
		<?php endif; ?>
		<?php if ( $saldo > 0 ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: formatted balance amount */
					esc_html__( 'Queda un saldo pendiente de %s, te lo recordaremos antes del despacho.', 'stylelauri-order-flow' ),
					wp_kses_post( wc_price( $saldo ) )
				);
				?>
			</p>
		<?php endif; ?>
		<?php
		return ob_get_clean();
	}
}
