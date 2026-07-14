<?php
/**
 * Menu propio del plugin + ajustes del Abono Reserva.
 *
 * Menu top-level "StyleLauri" con:
 *  - Ajustes: porcentaje del abono y textos del checkbox del checkout.
 *  - Lotes de preventa: acceso directo a la taxonomia (sin pasar por Productos).
 *  - Pedidos: acceso directo al listado de pedidos de WooCommerce.
 *
 * Los valores se guardan como options planas (get_option), con defaults
 * definidos aqui -- el resto del plugin los lee via los helpers estaticos
 * (get_percent, is_abono_enabled, etc.), nunca con get_option directo.
 *
 * @package StyleLauri_Order_Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLO_Settings {

	const MENU_SLUG = 'stylelauri-order-flow';

	const OPT_ABONO_ENABLED = 'slo_abono_enabled';
	const OPT_ABONO_PERCENT = 'slo_abono_percent';
	const OPT_ABONO_TITULO  = 'slo_abono_titulo';
	const OPT_ABONO_TEXTO   = 'slo_abono_texto';
	const OPT_DISPATCH_GATE = 'slo_dispatch_gate';

	const DEFAULT_PERCENT = 50;

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	// ------------------------------------------------------------------
	// Helpers de lectura (el resto del plugin consume estos, no get_option)
	// ------------------------------------------------------------------

	/**
	 * ¿El checkbox de Abono Reserva esta activo en el checkout?
	 *
	 * @return bool
	 */
	public static function is_abono_enabled() {
		return 'no' !== get_option( self::OPT_ABONO_ENABLED, 'yes' );
	}

	/**
	 * Porcentaje del abono (lo que el cliente paga HOY sobre el subtotal
	 * de preventa). Acotado a 1-99 para que nunca genere un fee de 0% o
	 * 100% por un dato mal guardado.
	 *
	 * @return float
	 */
	public static function get_percent() {
		$percent = (float) get_option( self::OPT_ABONO_PERCENT, self::DEFAULT_PERCENT );

		if ( $percent < 1 || $percent > 99 ) {
			$percent = self::DEFAULT_PERCENT;
		}

		return $percent;
	}

	/**
	 * Titulo del checkbox en el checkout. {percent} se reemplaza por el
	 * porcentaje configurado.
	 *
	 * @return string
	 */
	public static function get_titulo() {
		$titulo = get_option( self::OPT_ABONO_TITULO, '' );

		if ( '' === trim( (string) $titulo ) ) {
			$titulo = __( '💜 Abono Reserva — Paga solo el {percent}% hoy', 'stylelauri-order-flow' );
		}

		return str_replace( '{percent}', self::format_percent(), $titulo );
	}

	/**
	 * Texto secundario del checkbox.
	 *
	 * @return string
	 */
	public static function get_texto() {
		$texto = get_option( self::OPT_ABONO_TEXTO, '' );

		if ( '' === trim( (string) $texto ) ) {
			$texto = __( 'Separa tu producto pagando el {percent}% del valor. El saldo se cobra antes del despacho.', 'stylelauri-order-flow' );
		}

		return str_replace( '{percent}', self::format_percent(), $texto );
	}

	/**
	 * Porcentaje formateado sin decimales innecesarios (50, no 50.0).
	 *
	 * @return string
	 */
	public static function format_percent() {
		$percent = self::get_percent();
		return rtrim( rtrim( number_format( $percent, 2, '.', '' ), '0' ), '.' );
	}

	// ------------------------------------------------------------------
	// Menu
	// ------------------------------------------------------------------

	public static function register_menu() {
		add_menu_page(
			__( 'StyleLauri — Pedidos y preventas', 'stylelauri-order-flow' ),
			__( 'StyleLauri', 'stylelauri-order-flow' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( __CLASS__, 'render_settings_page' ),
			'dashicons-tag',
			56
		);

		// Renombrar el primer submenu (que WP duplica con el nombre del top-level).
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Ajustes', 'stylelauri-order-flow' ),
			__( 'Ajustes', 'stylelauri-order-flow' ),
			'manage_woocommerce',
			self::MENU_SLUG,
			array( __CLASS__, 'render_settings_page' )
		);

		// Acceso directo a la pantalla de lotes (taxonomia de producto).
		add_submenu_page(
			self::MENU_SLUG,
			__( 'Lotes de preventa', 'stylelauri-order-flow' ),
			__( 'Lotes de preventa', 'stylelauri-order-flow' ),
			'manage_product_terms',
			'edit-tags.php?taxonomy=' . SLO_Taxonomy::TAXONOMY . '&post_type=product'
		);

		// Acceso directo al listado de pedidos. add_submenu_page no acepta
		// URLs a otras pantallas de admin.php, pero el array global $submenu
		// si renderiza hrefs crudos -- patron estandar para links externos.
		global $submenu;
		if ( isset( $submenu[ self::MENU_SLUG ] ) ) {
			$submenu[ self::MENU_SLUG ][] = array(
				__( 'Pedidos', 'stylelauri-order-flow' ),
				'manage_woocommerce',
				admin_url( 'admin.php?page=wc-orders' ),
			);
		}
	}

	// ------------------------------------------------------------------
	// Ajustes
	// ------------------------------------------------------------------

	public static function register_settings() {
		register_setting(
			'slo_settings_group',
			self::OPT_ABONO_ENABLED,
			array(
				'type'              => 'string',
				'default'           => 'yes',
				'sanitize_callback' => function ( $value ) {
					return 'yes' === $value ? 'yes' : 'no';
				},
			)
		);

		register_setting(
			'slo_settings_group',
			self::OPT_ABONO_PERCENT,
			array(
				'type'              => 'number',
				'default'           => self::DEFAULT_PERCENT,
				'sanitize_callback' => function ( $value ) {
					$value = (float) $value;
					return ( $value >= 1 && $value <= 99 ) ? $value : self::DEFAULT_PERCENT;
				},
			)
		);

		register_setting(
			'slo_settings_group',
			self::OPT_ABONO_TITULO,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_setting(
			'slo_settings_group',
			self::OPT_ABONO_TEXTO,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

		register_setting(
			'slo_settings_group',
			self::OPT_DISPATCH_GATE,
			array(
				'type'              => 'string',
				'default'           => 'yes',
				'sanitize_callback' => function ( $value ) {
					return 'yes' === $value ? 'yes' : 'no';
				},
			)
		);

		// Mapeo de roles del ciclo de vida a estados de pedido. Vacio =
		// usar el estado por defecto del plugin. Solo se aceptan keys de
		// estado validas ('wc-...').
		foreach ( array_keys( SLO_Order_Statuses::default_map() ) as $role ) {
			register_setting(
				'slo_settings_group',
				'slo_status_' . $role,
				array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => function ( $value ) {
						$value = sanitize_text_field( (string) $value );
						return 0 === strpos( $value, 'wc-' ) ? $value : '';
					},
				)
			);
		}
	}

	/**
	 * Opciones para los dropdowns de estado: todos los estados que WC
	 * conoce, mas los del plugin (que pueden no estar registrados si su
	 * rol fue remapeado -- deben seguir apareciendo para poder volver).
	 *
	 * @return array<string,string> key wc-... => label.
	 */
	private static function status_choices() {
		$choices = wc_get_order_statuses();
		$labels  = SLO_Order_Statuses::role_labels();

		foreach ( SLO_Order_Statuses::default_map() as $role => $key ) {
			if ( ! isset( $choices[ $key ] ) ) {
				$choices[ $key ] = $labels[ $role ] . ' ' . __( '(del plugin)', 'stylelauri-order-flow' );
			}
		}

		return $choices;
	}

	public static function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'StyleLauri — Ajustes de pedidos y preventas', 'stylelauri-order-flow' ); ?></h1>

			<form method="post" action="options.php">
				<?php settings_fields( 'slo_settings_group' ); ?>

				<h2><?php esc_html_e( 'Abono Reserva (checkout)', 'stylelauri-order-flow' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'El checkbox de abono solo aparece cuando el carrito tiene al menos un producto con lote de preventa asignado. Los productos de stock inmediato nunca lo muestran.', 'stylelauri-order-flow' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Activar Abono Reserva', 'stylelauri-order-flow' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPT_ABONO_ENABLED ); ?>" value="yes" <?php checked( self::is_abono_enabled() ); ?> />
								<?php esc_html_e( 'Mostrar la opcion de abono en el checkout', 'stylelauri-order-flow' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slo_abono_percent"><?php esc_html_e( 'Porcentaje del abono (%)', 'stylelauri-order-flow' ); ?></label></th>
						<td>
							<input
								type="number"
								id="slo_abono_percent"
								name="<?php echo esc_attr( self::OPT_ABONO_PERCENT ); ?>"
								value="<?php echo esc_attr( self::get_percent() ); ?>"
								min="1"
								max="99"
								step="0.5"
							/>
							<p class="description"><?php esc_html_e( 'Lo que el cliente paga HOY sobre el subtotal de los productos de preventa. El resto queda como saldo pendiente en el pedido.', 'stylelauri-order-flow' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slo_abono_titulo"><?php esc_html_e( 'Titulo del checkbox', 'stylelauri-order-flow' ); ?></label></th>
						<td>
							<input
								type="text"
								id="slo_abono_titulo"
								name="<?php echo esc_attr( self::OPT_ABONO_TITULO ); ?>"
								value="<?php echo esc_attr( get_option( self::OPT_ABONO_TITULO, '' ) ); ?>"
								class="large-text"
								placeholder="<?php esc_attr_e( '💜 Abono Reserva — Paga solo el {percent}% hoy', 'stylelauri-order-flow' ); ?>"
							/>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="slo_abono_texto"><?php esc_html_e( 'Texto del checkbox', 'stylelauri-order-flow' ); ?></label></th>
						<td>
							<input
								type="text"
								id="slo_abono_texto"
								name="<?php echo esc_attr( self::OPT_ABONO_TEXTO ); ?>"
								value="<?php echo esc_attr( get_option( self::OPT_ABONO_TEXTO, '' ) ); ?>"
								class="large-text"
								placeholder="<?php esc_attr_e( 'Separa tu producto pagando el {percent}% del valor. El saldo se cobra antes del despacho.', 'stylelauri-order-flow' ); ?>"
							/>
							<p class="description"><?php esc_html_e( 'En ambos textos, {percent} se reemplaza por el porcentaje configurado.', 'stylelauri-order-flow' ); ?></p>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Puerta de despacho (Skydrops)', 'stylelauri-order-flow' ); ?></h2>
				<p class="description" style="max-width:640px;">
					<?php esc_html_e( 'Con esta opcion activa, "Procesando" queda reservado para pedidos pagados completos y despachables YA (los unicos que Skydrops ve). Los pagos que la pasarela mande a Procesando se reubican solos: con saldo pendiente van a Abono parcial; preventas pagadas van a En produccion; solo el stock inmediato pagado completo se queda en Procesando. Cuando el saldo de un pedido en Listo llega a 0, pasa solo a Procesando.', 'stylelauri-order-flow' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Activar puerta de despacho', 'stylelauri-order-flow' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPT_DISPATCH_GATE ); ?>" value="yes" <?php checked( 'no' !== get_option( self::OPT_DISPATCH_GATE, 'yes' ) ); ?> />
								<?php esc_html_e( '"Procesando" = pagado completo y listo para despachar', 'stylelauri-order-flow' ); ?>
							</label>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'Estados del pedido', 'stylelauri-order-flow' ); ?></h2>
				<p class="description" style="max-width:640px;">
					<?php esc_html_e( 'El plugin trabaja con cuatro roles del ciclo de vida. Cada rol puede usar el estado que trae el plugin o mapearse a un estado que ya exista en la tienda (por ejemplo, si ya tienes estados con sus propios correos). Cuando un rol se mapea a un estado existente, el estado del plugin desaparece del dropdown de pedidos: no se acumulan estados de mas.', 'stylelauri-order-flow' ); ?>
				</p>
				<p class="description" style="max-width:640px;">
					<strong><?php esc_html_e( 'Antes de remapear un rol:', 'stylelauri-order-flow' ); ?></strong>
					<?php esc_html_e( 'mueve los pedidos que esten en el estado del plugin hacia el nuevo estado (el estado viejo dejara de mostrarse y esos pedidos quedarian con un estado huerfano). Y si el estado elegido ya envia su propio correo, revisa WooCommerce > Ajustes > Correos para no notificar dos veces.', 'stylelauri-order-flow' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<?php
					$role_help = array(
						'abono'      => __( 'Pago parcial recibido, falta saldo. Dispara el correo de "Abono recibido".', 'stylelauri-order-flow' ),
						'produccion' => __( 'El lote esta en produccion. Interno: no notifica al cliente.', 'stylelauri-order-flow' ),
						'listo'      => __( 'Listo para despacho/retiro. Interno; dispara el recordatorio de saldo si falta plata.', 'stylelauri-order-flow' ),
						'enviado'    => __( 'Despachado. Dispara el correo de "Enviado" y esta bloqueado mientras haya saldo pendiente.', 'stylelauri-order-flow' ),
					);

					foreach ( SLO_Order_Statuses::role_labels() as $role => $label ) :
						$option_name = 'slo_status_' . $role;
						$stored      = get_option( $option_name, '' );
						$current     = SLO_Order_Statuses::get_status_key( $role );
						?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $option_name ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<select name="<?php echo esc_attr( $option_name ); ?>" id="<?php echo esc_attr( $option_name ); ?>">
									<?php foreach ( self::status_choices() as $key => $status_label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>>
											<?php echo esc_html( $status_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
								<?php if ( '' === $stored || SLO_Order_Statuses::uses_default( $role ) ) : ?>
									<span class="description"> <?php esc_html_e( '(estado del plugin)', 'stylelauri-order-flow' ); ?></span>
								<?php endif; ?>
								<p class="description"><?php echo esc_html( $role_help[ $role ] ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
