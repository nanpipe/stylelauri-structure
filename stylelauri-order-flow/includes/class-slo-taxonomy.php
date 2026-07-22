<?php
/**
 * Taxonomia "Lote de preventa".
 *
 * Se registra como taxonomia jerarquica (estilo Categorias) y NO como
 * Atributo nativo de WooCommerce: un atributo es una opcion que el
 * cliente elige (talla, color); el lote no lo elige el cliente, es una
 * clasificacion fija del producto. Al ser jerarquica, el editor elige
 * de una lista existente (checklist) en vez de escribir texto libre --
 * eso evita variaciones de nombre/typos entre productos del mismo lote.
 *
 * Un producto SIN termino asignado = stock inmediato. Un producto CON
 * termino = preventa. No existe un campo booleano aparte: la
 * disponibilidad se infiere de si tiene o no un lote asignado.
 *
 * Las fechas de cierre y despacho viven en el TERMINO (term meta), no en
 * cada producto: si un lote tiene 8 productos, las 8 comparten la misma
 * fecha porque la fecha vive en un solo lugar.
 *
 * @package StyleLauri_Order_Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLO_Taxonomy {

	const TAXONOMY = 'lote_preventa';

	const META_CIERRE    = 'slo_fecha_cierre';
	const META_DESPACHO  = 'slo_fecha_despacho';
	// Bandera "Producido": el lote ya llego / esta empacable aunque la
	// fecha de despacho aun no llegue. Libera el candado de preventa de
	// TODOS los pedidos que tocan este lote (ver SLO_Dispatch_Gate).
	const META_PRODUCIDO = 'slo_lote_producido';
	const NONCE_ACTION   = 'slo_save_lote_dates';
	const NONCE_FIELD    = 'slo_lote_dates_nonce';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );

		// Campos de fecha al crear un lote nuevo.
		add_action( self::TAXONOMY . '_add_form_fields', array( __CLASS__, 'render_add_fields' ) );
		// Campos de fecha al editar un lote existente.
		add_action( self::TAXONOMY . '_edit_form_fields', array( __CLASS__, 'render_edit_fields' ), 10, 1 );

		// Guardar en ambos casos.
		add_action( 'created_' . self::TAXONOMY, array( __CLASS__, 'save_dates' ) );
		add_action( 'edited_' . self::TAXONOMY, array( __CLASS__, 'save_dates' ) );

		// Columnas propias en el listado de terminos (Productos > Lote de preventa).
		add_filter( 'manage_edit-' . self::TAXONOMY . '_columns', array( __CLASS__, 'term_columns' ) );
		add_filter( 'manage_' . self::TAXONOMY . '_custom_column', array( __CLASS__, 'term_column_content' ), 10, 3 );
	}

	/**
	 * Registro de la taxonomia. Se llama en 'init' (cada carga) y tambien
	 * desde la activacion del plugin (para que exista antes del primer
	 * flush_rewrite_rules).
	 */
	public static function register_taxonomy() {
		$labels = array(
			'name'              => __( 'Lotes de preventa', 'stylelauri-order-flow' ),
			'singular_name'     => __( 'Lote de preventa', 'stylelauri-order-flow' ),
			'search_items'      => __( 'Buscar lotes', 'stylelauri-order-flow' ),
			'all_items'         => __( 'Todos los lotes', 'stylelauri-order-flow' ),
			'edit_item'         => __( 'Editar lote', 'stylelauri-order-flow' ),
			'update_item'       => __( 'Actualizar lote', 'stylelauri-order-flow' ),
			'add_new_item'      => __( 'Agregar nuevo lote', 'stylelauri-order-flow' ),
			'new_item_name'     => __( 'Nombre del nuevo lote (ej. JK-Agosto)', 'stylelauri-order-flow' ),
			'menu_name'         => __( 'Lotes de preventa', 'stylelauri-order-flow' ),
		);

		register_taxonomy(
			self::TAXONOMY,
			array( 'product' ),
			array(
				'labels'            => $labels,
				'hierarchical'      => true, // Checklist, no texto libre -- lista controlada.
				'public'            => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => array( 'slug' => 'lote-preventa' ),
			)
		);
	}

	/**
	 * Campos al crear un lote nuevo (Productos > Lotes de preventa > Agregar nuevo).
	 */
	public static function render_add_fields() {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
		?>
		<div class="form-field">
			<label for="slo_fecha_cierre"><?php esc_html_e( 'Fecha de cierre de pedidos', 'stylelauri-order-flow' ); ?></label>
			<input type="date" name="slo_fecha_cierre" id="slo_fecha_cierre" value="" />
			<p><?php esc_html_e( 'Hasta cuando se reciben pedidos de este lote.', 'stylelauri-order-flow' ); ?></p>
		</div>
		<div class="form-field">
			<label for="slo_fecha_despacho"><?php esc_html_e( 'Fecha estimada de despacho', 'stylelauri-order-flow' ); ?></label>
			<input type="date" name="slo_fecha_despacho" id="slo_fecha_despacho" value="" />
			<p><?php esc_html_e( 'Fecha que se comunica al cliente en el correo/WhatsApp de confirmacion.', 'stylelauri-order-flow' ); ?></p>
		</div>
		<div class="form-field">
			<label for="slo_lote_producido">
				<input type="checkbox" name="slo_lote_producido" id="slo_lote_producido" value="1" />
				<?php esc_html_e( 'Producido', 'stylelauri-order-flow' ); ?>
			</label>
			<p><?php esc_html_e( 'Marca el lote como llegado/empacable. Libera el paso a Preparacion de sus pedidos aunque la fecha de despacho no haya llegado.', 'stylelauri-order-flow' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Campos al editar un lote existente.
	 *
	 * @param WP_Term $term Termino que se esta editando.
	 */
	public static function render_edit_fields( $term ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );

		$cierre    = get_term_meta( $term->term_id, self::META_CIERRE, true );
		$despacho  = get_term_meta( $term->term_id, self::META_DESPACHO, true );
		$producido = self::is_lote_producido( $term->term_id );
		?>
		<tr class="form-field">
			<th scope="row"><label for="slo_fecha_cierre"><?php esc_html_e( 'Fecha de cierre de pedidos', 'stylelauri-order-flow' ); ?></label></th>
			<td>
				<input type="date" name="slo_fecha_cierre" id="slo_fecha_cierre" value="<?php echo esc_attr( $cierre ); ?>" />
				<p class="description"><?php esc_html_e( 'Hasta cuando se reciben pedidos de este lote.', 'stylelauri-order-flow' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="slo_fecha_despacho"><?php esc_html_e( 'Fecha estimada de despacho', 'stylelauri-order-flow' ); ?></label></th>
			<td>
				<input type="date" name="slo_fecha_despacho" id="slo_fecha_despacho" value="<?php echo esc_attr( $despacho ); ?>" />
				<p class="description"><?php esc_html_e( 'Fecha que se comunica al cliente en el correo/WhatsApp de confirmacion.', 'stylelauri-order-flow' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row"><label for="slo_lote_producido"><?php esc_html_e( 'Producido', 'stylelauri-order-flow' ); ?></label></th>
			<td>
				<label>
					<input type="checkbox" name="slo_lote_producido" id="slo_lote_producido" value="1" <?php checked( $producido ); ?> />
					<?php esc_html_e( 'El lote ya llego / esta empacable', 'stylelauri-order-flow' ); ?>
				</label>
				<p class="description"><?php esc_html_e( 'Libera el paso a Preparacion de los pedidos de este lote aunque la fecha de despacho no haya llegado.', 'stylelauri-order-flow' ); ?></p>
			</td>
		</tr>
		<?php
	}

	/**
	 * Guarda las fechas como term meta. Sanitiza en entrada, valida nonce
	 * y capacidad antes de escribir.
	 *
	 * @param int $term_id ID del termino recien creado/editado.
	 */
	public static function save_dates( $term_id ) {
		if ( ! isset( $_POST[ self::NONCE_FIELD ] ) ||
			! wp_verify_nonce( wp_unslash( $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_product_terms' ) ) {
			return;
		}

		if ( isset( $_POST['slo_fecha_cierre'] ) ) {
			$cierre = sanitize_text_field( wp_unslash( $_POST['slo_fecha_cierre'] ) );
			update_term_meta( $term_id, self::META_CIERRE, self::sanitize_date( $cierre ) );
		}

		if ( isset( $_POST['slo_fecha_despacho'] ) ) {
			$despacho = sanitize_text_field( wp_unslash( $_POST['slo_fecha_despacho'] ) );
			update_term_meta( $term_id, self::META_DESPACHO, self::sanitize_date( $despacho ) );
		}

		// Checkbox: presente = '1', ausente = '0'. Se guarda siempre para
		// permitir DESmarcar un lote que ya se habia dado por producido.
		update_term_meta( $term_id, self::META_PRODUCIDO, isset( $_POST['slo_lote_producido'] ) ? '1' : '0' );
	}

	/**
	 * Valida formato YYYY-MM-DD (lo que produce un <input type="date">).
	 * Si no matchea, guarda vacio en vez de un dato corrupto.
	 *
	 * @param string $date Fecha cruda del input.
	 * @return string Fecha valida en formato Y-m-d, o cadena vacia.
	 */
	private static function sanitize_date( $date ) {
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return $date;
		}
		return '';
	}

	/**
	 * Columnas extra en el listado de terminos, para ver las fechas
	 * sin entrar a editar cada lote.
	 *
	 * @param array $columns Columnas existentes.
	 * @return array
	 */
	public static function term_columns( $columns ) {
		$columns['slo_cierre']    = __( 'Cierre pedidos', 'stylelauri-order-flow' );
		$columns['slo_despacho']  = __( 'Despacho', 'stylelauri-order-flow' );
		$columns['slo_producido'] = __( 'Producido', 'stylelauri-order-flow' );
		return $columns;
	}

	/**
	 * Contenido de las columnas custom del listado de terminos.
	 *
	 * @param string $content     Contenido actual (vacio, se retorna).
	 * @param string $column_name Nombre de la columna.
	 * @param int    $term_id     ID del termino.
	 * @return string
	 */
	public static function term_column_content( $content, $column_name, $term_id ) {
		if ( 'slo_cierre' === $column_name ) {
			return esc_html( get_term_meta( $term_id, self::META_CIERRE, true ) );
		}
		if ( 'slo_despacho' === $column_name ) {
			return esc_html( get_term_meta( $term_id, self::META_DESPACHO, true ) );
		}
		if ( 'slo_producido' === $column_name ) {
			return self::is_lote_producido( $term_id )
				? esc_html__( 'Si', 'stylelauri-order-flow' )
				: '—';
		}
		return $content;
	}

	/**
	 * Fechas de un lote (termino), listas para usar.
	 *
	 * @param int $term_id ID del termino.
	 * @return array{cierre:string,despacho:string}
	 */
	public static function get_term_dates( $term_id ) {
		return array(
			'cierre'   => get_term_meta( $term_id, self::META_CIERRE, true ),
			'despacho' => get_term_meta( $term_id, self::META_DESPACHO, true ),
		);
	}

	/**
	 * Lotes (terminos) asignados a un producto.
	 *
	 * @param int $product_id ID del producto.
	 * @return WP_Term[]
	 */
	public static function get_product_lotes( $product_id ) {
		$terms = wp_get_post_terms( $product_id, self::TAXONOMY );
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Si el producto tiene al menos un lote asignado, es preventa.
	 * Sin lote = stock inmediato. Esta es la unica fuente de verdad
	 * de disponibilidad -- no hay campo booleano separado.
	 *
	 * @param int $product_id ID del producto.
	 * @return bool
	 */
	public static function is_preventa_product( $product_id ) {
		return count( self::get_product_lotes( $product_id ) ) > 0;
	}

	/**
	 * ¿El lote (termino) esta marcado como Producido?
	 *
	 * @param int $term_id ID del termino.
	 * @return bool
	 */
	public static function is_lote_producido( $term_id ) {
		return '1' === get_term_meta( $term_id, self::META_PRODUCIDO, true );
	}

	/**
	 * Igual que is_lote_producido() pero desde el slug guardado en el
	 * snapshot del pedido. Termino inexistente = no producido.
	 *
	 * @param string $slug Slug del lote.
	 * @return bool
	 */
	public static function is_slug_producido( $slug ) {
		$term = get_term_by( 'slug', $slug, self::TAXONOMY );

		return ( $term && ! is_wp_error( $term ) )
			? self::is_lote_producido( $term->term_id )
			: false;
	}
}
