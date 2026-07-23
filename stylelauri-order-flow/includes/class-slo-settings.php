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

		// Mapeo de roles del ciclo de vida a estados de pedido. Vacio =
		// rol desactivado (sin automatismos). Solo se aceptan keys de
		// estado validas ('wc-...').
		foreach ( SLO_Order_Statuses::roles() as $role ) {
			register_setting(
				'slo_settings_group',
				'slo_status_' . $role,
				array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => function ( $value ) {
						$value = sanitize_text_field( (string) $value );

						// "Procesando" esta reservado: es la salida de la
						// puerta de despacho, no puede ser un rol.
						if ( 'wc-processing' === $value ) {
							return '';
						}

						return 0 === strpos( $value, 'wc-' ) ? $value : '';
					},
				)
			);
		}
	}

	/**
	 * Opciones para los dropdowns de estado: todos los estados que la
	 * tienda tiene registrados (nativos + los creados por la tienda).
	 *
	 * @return array<string,string> key wc-... => label.
	 */
	private static function status_choices() {
		$choices = wc_get_order_statuses();

		// "Procesando" (Merch Lista) es la salida de la puerta de
		// despacho: no se ofrece como rol. [Hallazgo de auditoria]
		unset( $choices['wc-processing'] );

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

				<h2><?php esc_html_e( 'Estados del pedido (roles)', 'stylelauri-order-flow' ); ?></h2>
				<p class="description" style="max-width:640px;">
					<strong><?php esc_html_e( 'Despacho = "Procesando" (Merch Lista), cableado y obligatorio.', 'stylelauri-order-flow' ); ?></strong>
					<?php esc_html_e( 'Es lo unico que Skydrops ve. Solo se llega con el pedido empacado (paso por Preparacion) y saldo en 0; todo pago de pasarela entra primero a la entrada del embudo. Puedes renombrar la etiqueta de "Procesando" con tu plugin de estados -- la clave interna wc-processing no se toca.', 'stylelauri-order-flow' ); ?>
				</p>
				<p class="description" style="max-width:640px;">
					<?php esc_html_e( 'El plugin NO crea estados: los estados los administra la tienda. Aqui se le indica al plugin que estado cumple cada rol del flujo. Un rol "Sin asignar" desactiva sus automatismos (correo, bloqueo, transicion) sin romper nada.', 'stylelauri-order-flow' ); ?>
				</p>
				<p class="description" style="max-width:640px;">
					<?php esc_html_e( 'Los correos los maneja la tienda (plugin de estados + YAYMail). Este plugin solo aporta los datos: abonos y saldo salen como filas en la tabla de totales de todo correo, y como metadatos del pedido (_slo_saldo_pendiente, _slo_fecha_despacho, _slo_guia_envio, _slo_monto_abonado) insertables en las plantillas.', 'stylelauri-order-flow' ); ?>
				</p>

				<table class="form-table" role="presentation">
					<?php
					$role_help = array(
						'abono'      => __( 'Mapear a "Saldo Pendiente" (slug: saldo-pendiente). REGLA: un pedido con saldo sin pagar NUNCA queda en Merch Lista -- se redirige aqui automaticamente, venga de donde venga. Al quedar el saldo en 0, avanza solo a Merch Lista.', 'stylelauri-order-flow' ),
						'produccion' => __( 'Mapear a "Abono Produccion" (slug: abono-produccion). TODO pago de pasarela entra aqui primero (preventa o stock, con o sin saldo). Aqui se imprime la etiqueta; luego se mueve a mano a Preventa.', 'stylelauri-order-flow' ),
						'preventa'   => __( 'Mapear a "Preventa" (slug: preventa). Donde espera el pedido a que llegue su lote. El candado de preventa lo mantiene aqui: no puede pasar a Preparacion hasta que la fecha de despacho llegue, el lote se marque Producido, o se use el boton "Liberar a Preparacion" (que solo autoadelanta desde este estado).', 'stylelauri-order-flow' ),
						'listo'      => __( 'Mapear a "Preparacion" (slug: preparacion). Marca que el pedido se empaco (habilita la salida a Merch Lista); al quedar saldo 0 avanza solo a Merch Lista.', 'stylelauri-order-flow' ),
					);

					foreach ( SLO_Order_Statuses::role_labels() as $role => $label ) :
						$option_name = 'slo_status_' . $role;
						$current     = SLO_Order_Statuses::get_status_key( $role );
						?>
						<tr>
							<th scope="row"><label for="<?php echo esc_attr( $option_name ); ?>"><?php echo esc_html( $label ); ?></label></th>
							<td>
								<select name="<?php echo esc_attr( $option_name ); ?>" id="<?php echo esc_attr( $option_name ); ?>">
									<option value="" <?php selected( '', $current ); ?>><?php esc_html_e( '— Sin asignar —', 'stylelauri-order-flow' ); ?></option>
									<?php foreach ( self::status_choices() as $key => $status_label ) : ?>
										<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $current, $key ); ?>>
											<?php echo esc_html( $status_label ); ?>
										</option>
									<?php endforeach; ?>
								</select>
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
