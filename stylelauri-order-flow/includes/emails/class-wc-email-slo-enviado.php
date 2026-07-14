<?php
/**
 * Email: "Enviado / listo para retiro".
 *
 * Es UN solo email para los tres casos (antes se manejaba con tres
 * estados distintos). El contenido se arma segun
 * SLO_Emails::detect_delivery_type(), que lee el metodo de envio nativo
 * del pedido -- no se crea ningun campo ni estado nuevo para esto.
 *
 * @package StyleLauri_Order_Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WC_Email' ) ) {
	return;
}

class WC_Email_SLO_Enviado extends WC_Email {

	public function __construct() {
		$this->id             = 'slo_enviado';
		$this->customer_email = true;
		$this->title          = __( 'StyleLauri: Enviado / listo para retiro', 'stylelauri-order-flow' );
		$this->description    = __( 'Se envia cuando el pedido pasa a "Enviado". El contenido varia segun el metodo de envio (domicilio, retiro, contraentrega).', 'stylelauri-order-flow' );
		$this->heading        = __( 'Tu pedido va en camino', 'stylelauri-order-flow' );
		$this->subject        = __( '{site_title} - Tu pedido #{order_number} esta en camino', 'stylelauri-order-flow' );
		$this->placeholders   = array( '{order_number}' => '' );

		add_action( 'slo_email_trigger_enviado', array( $this, 'trigger' ), 10, 2 );

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
		$tipo  = SLO_Emails::detect_delivery_type( $order );

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

		<?php if ( 'retiro' === $tipo ) : ?>
			<p><?php esc_html_e( 'Tu pedido ya esta listo para retirar en tienda.', 'stylelauri-order-flow' ); ?></p>
			<p><?php esc_html_e( 'Puedes pasar a recogerlo cuando quieras dentro de nuestro horario de atencion. Trae este correo o el numero de pedido.', 'stylelauri-order-flow' ); ?></p>

		<?php elseif ( 'contraentrega' === $tipo ) : ?>
			<p><?php esc_html_e( 'Tu pedido ya salio hacia tu direccion.', 'stylelauri-order-flow' ); ?></p>
			<p><?php esc_html_e( 'Recuerda tener el pago listo para el momento de la entrega (contraentrega).', 'stylelauri-order-flow' ); ?></p>

		<?php else : // domicilio ?>
			<p><?php esc_html_e( 'Tu pedido ya salio hacia tu direccion.', 'stylelauri-order-flow' ); ?></p>
			<?php
			$tracking = $order->get_meta( class_exists( 'SLO_Order_Balance' ) ? SLO_Order_Balance::META_GUIA : '_slo_guia_envio' );
			if ( $tracking ) :
				?>
				<p>
					<?php
					printf(
						/* translators: %s: numero de guia */
						esc_html__( 'Numero de guia: %s', 'stylelauri-order-flow' ),
						esc_html( $tracking )
					);
					?>
				</p>
			<?php endif; ?>
		<?php endif; ?>

		<p><?php esc_html_e( 'Gracias por comprar en StyleLauri.', 'stylelauri-order-flow' ); ?></p>
		<?php
		return ob_get_clean();
	}
}
