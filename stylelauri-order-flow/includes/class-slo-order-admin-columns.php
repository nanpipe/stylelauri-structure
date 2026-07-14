<?php
/**
 * Columnas "Lote(s)" y "Despacho" + filtro por lote en el listado de
 * pedidos del admin.
 *
 * Registrado para HPOS (hooks 'woocommerce_page_wc-orders' /
 * 'woocommerce_order_list_table_*') Y para el listado legacy de
 * shop_order (por si en algun momento se desactiva HPOS) -- el mismo
 * callback de contenido sirve para ambos, recibiendo siempre un
 * WC_Order ya resuelto.
 *
 * El filtro busca "pedidos que CONTIENEN este lote" (LIKE sobre el
 * meta serializado _slo_lotes_pedido), no igualdad exacta, porque un
 * pedido puede tocar varios lotes a la vez (ver SLO_Order_Snapshot).
 *
 * @package StyleLauri_Order_Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLO_Order_Admin_Columns {

	const FILTER_KEY = 'slo_lote_filter';

	public static function init() {
		// --- HPOS ---
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_columns' ), 20 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_column_hpos' ), 10, 2 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'render_filter_dropdown' ) );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( __CLASS__, 'apply_filter_hpos' ) );

		// --- Legacy (shop_order como CPT), por si HPOS no esta activo ---
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'add_columns' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'render_column_legacy' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'render_filter_dropdown_legacy' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_filter_legacy' ) );

		// --- Bulk action "Recalcular lote(s)": backfill de pedidos que
		// existian antes del plugin o antes de asignar los lotes ---
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'register_bulk_action' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'register_bulk_action' ) );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( __CLASS__, 'handle_bulk_action' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'bulk_action_notice' ) );

		add_action( 'admin_head', array( __CLASS__, 'print_badge_styles' ) );
	}

	/**
	 * Agrega "Recalcular lote(s)" al dropdown de acciones en lote.
	 *
	 * @param array $actions Acciones existentes.
	 * @return array
	 */
	public static function register_bulk_action( $actions ) {
		$actions['slo_recalcular_snapshot'] = __( 'Recalcular lote(s) y fecha de despacho', 'stylelauri-order-flow' );
		return $actions;
	}

	/**
	 * Ejecuta el recalculo del snapshot sobre los pedidos seleccionados.
	 * Los pedidos en estado cerrado (enviado/completado/cancelado) se
	 * saltan solos -- recompute_snapshot ya respeta ese candado.
	 *
	 * @param string $redirect_url URL de retorno del listado.
	 * @param string $action       Accion seleccionada.
	 * @param array  $ids          IDs de pedidos marcados.
	 * @return string
	 */
	public static function handle_bulk_action( $redirect_url, $action, $ids ) {
		if ( 'slo_recalcular_snapshot' !== $action ) {
			return $redirect_url;
		}

		foreach ( $ids as $order_id ) {
			SLO_Order_Snapshot::recompute_snapshot( (int) $order_id );
		}

		return add_query_arg( 'slo_recalculados', count( $ids ), $redirect_url );
	}

	/**
	 * Aviso de confirmacion despues del bulk de recalculo.
	 */
	public static function bulk_action_notice() {
		if ( empty( $_GET['slo_recalculados'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo pinta un aviso.
			return;
		}

		$count = absint( $_GET['slo_recalculados'] );

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: %d: number of orders */
					_n( 'Lote y fecha recalculados en %d pedido.', 'Lote y fecha recalculados en %d pedidos.', $count, 'stylelauri-order-flow' ),
					$count
				)
			)
		);
	}

	/**
	 * Inserta las columnas nuevas justo despues de la de Estado.
	 *
	 * @param array $columns Columnas existentes del listado.
	 * @return array
	 */
	public static function add_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'order_status' === $key ) {
				$new_columns['slo_lotes']    = __( 'Lote(s)', 'stylelauri-order-flow' );
				$new_columns['slo_despacho'] = __( 'Despacho', 'stylelauri-order-flow' );
			}
		}

		// Si por algun motivo no existia la columna de estado, se agrega al final.
		if ( ! isset( $new_columns['slo_lotes'] ) ) {
			$new_columns['slo_lotes']    = __( 'Lote(s)', 'stylelauri-order-flow' );
			$new_columns['slo_despacho'] = __( 'Despacho', 'stylelauri-order-flow' );
		}

		return $new_columns;
	}

	/**
	 * Contenido de columna bajo HPOS -- $item ya es un WC_Order.
	 *
	 * @param string   $column Nombre de la columna.
	 * @param WC_Order $order  Pedido.
	 */
	public static function render_column_hpos( $column, $order ) {
		self::render_column_content( $column, $order );
	}

	/**
	 * Contenido de columna bajo el listado legacy -- solo llega el post ID.
	 *
	 * @param string $column  Nombre de la columna.
	 * @param int    $post_id ID del post del pedido.
	 */
	public static function render_column_legacy( $column, $post_id ) {
		$order = wc_get_order( $post_id );

		if ( $order ) {
			self::render_column_content( $column, $order );
		}
	}

	/**
	 * Logica compartida de renderizado, una vez que ya se tiene el
	 * WC_Order sin importar de donde vino.
	 *
	 * @param string   $column Nombre de la columna.
	 * @param WC_Order $order  Pedido.
	 */
	private static function render_column_content( $column, $order ) {
		if ( 'slo_lotes' === $column ) {
			// Pedido anterior al plugin (o a la asignacion de lotes): el
			// snapshot nunca se calculo. No es lo mismo que stock inmediato
			// -- mostrar guion y dejar que el bulk "Recalcular" lo resuelva.
			if ( ! $order->meta_exists( SLO_Order_Snapshot::META_LOTES ) ) {
				echo '&#8212;';
				return;
			}

			$lotes = SLO_Order_Snapshot::get_order_lotes( $order );

			if ( empty( $lotes ) ) {
				echo '<span class="slo-badge slo-badge-stock">' . esc_html__( 'Stock inmediato', 'stylelauri-order-flow' ) . '</span>';
				return;
			}

			foreach ( $lotes as $slug ) {
				$term  = get_term_by( 'slug', $slug, SLO_Taxonomy::TAXONOMY );
				$label = $term ? $term->name : $slug;
				echo '<span class="slo-badge">' . esc_html( $label ) . '</span>';
			}
			return;
		}

		if ( 'slo_despacho' === $column ) {
			$fecha = SLO_Order_Snapshot::get_order_fecha_despacho( $order );

			if ( ! $fecha ) {
				echo '&#8212;';
				return;
			}

			echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $fecha ) ) );
		}
	}

	/**
	 * Dropdown de filtro (version HPOS).
	 */
	public static function render_filter_dropdown() {
		$selected = isset( $_GET[ self::FILTER_KEY ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::FILTER_KEY ] ) ) : '';
		self::render_dropdown_markup( $selected );
	}

	/**
	 * Dropdown de filtro (version legacy) -- solo se pinta en la pantalla
	 * de listado de shop_order.
	 */
	public static function render_filter_dropdown_legacy() {
		global $typenow;

		if ( 'shop_order' !== $typenow ) {
			return;
		}

		$selected = isset( $_GET[ self::FILTER_KEY ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::FILTER_KEY ] ) ) : '';
		self::render_dropdown_markup( $selected );
	}

	/**
	 * HTML del select, poblado con los lotes existentes (terminos de la
	 * taxonomia). No hace falta nonce: es un filtro GET de solo lectura
	 * sobre un listado, no una accion que modifique datos.
	 *
	 * @param string $selected Slug actualmente seleccionado.
	 */
	private static function render_dropdown_markup( $selected ) {
		$terms = get_terms(
			array(
				'taxonomy'   => SLO_Taxonomy::TAXONOMY,
				'hide_empty' => false,
			)
		);

		echo '<select name="' . esc_attr( self::FILTER_KEY ) . '">';
		echo '<option value="">' . esc_html__( 'Todos los lotes', 'stylelauri-order-flow' ) . '</option>';

		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				printf(
					'<option value="%1$s"%2$s>%3$s</option>',
					esc_attr( $term->slug ),
					selected( $selected, $term->slug, false ),
					esc_html( $term->name )
				);
			}
		}

		echo '</select>';
	}

	/**
	 * Inyecta el meta_query en la query de HPOS. Sin SQL: al ser meta del
	 * pedido, meta_query lo resuelve sobre las tablas nuevas de HPOS.
	 *
	 * @param array $args Argumentos de la query del listado.
	 * @return array
	 */
	public static function apply_filter_hpos( $args ) {
		if ( empty( $_GET[ self::FILTER_KEY ] ) ) {
			return $args;
		}

		$slug = sanitize_text_field( wp_unslash( $_GET[ self::FILTER_KEY ] ) );

		$args['meta_query'][] = array(
			'key'     => SLO_Order_Snapshot::META_LOTES,
			'value'   => '"' . $slug . '"',
			'compare' => 'LIKE',
		);

		return $args;
	}

	/**
	 * Misma logica de filtro para el listado legacy via pre_get_posts.
	 *
	 * @param WP_Query $query Query del listado de posts.
	 */
	public static function apply_filter_legacy( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		global $typenow;

		if ( 'shop_order' !== $typenow || empty( $_GET[ self::FILTER_KEY ] ) ) {
			return;
		}

		$slug = sanitize_text_field( wp_unslash( $_GET[ self::FILTER_KEY ] ) );

		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = array(
			'key'     => SLO_Order_Snapshot::META_LOTES,
			'value'   => '"' . $slug . '"',
			'compare' => 'LIKE',
		);

		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Estilos minimos para los badges de lote, inline para no depender
	 * de un archivo CSS aparte.
	 */
	public static function print_badge_styles() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$is_hpos_screen   = false !== strpos( (string) $screen->id, 'wc-orders' );
		$is_legacy_screen = 'shop_order' === $screen->post_type;

		if ( ! $is_hpos_screen && ! $is_legacy_screen ) {
			return;
		}
		?>
		<style>
			.slo-badge {
				display: inline-block;
				background: #f0f0f1;
				border: 1px solid #dcdcde;
				border-radius: 3px;
				padding: 2px 6px;
				margin: 1px 2px 1px 0;
				font-size: 11px;
				line-height: 1.4;
			}
			.slo-badge-stock {
				background: #edfaef;
				border-color: #b8e6c1;
			}
		</style>
		<?php
	}
}
