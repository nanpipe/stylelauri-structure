<?php
/**
 * Plugin Name:       StyleLauri Order Flow
 * Plugin URI:        https://stylelauri.com/
 * Description:       Organiza el ciclo de vida de pedidos de StyleLauri: lotes de preventa, fechas de despacho, saldos por abono y notificaciones segun metodo de envio. Requiere WooCommerce con HPOS activo.
 * Version:           1.9.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            StyleLauri
 * Text Domain:       stylelauri-order-flow
 *
 * @package StyleLauri_Order_Flow
 */

// Salir si se accede directamente.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SLO_VERSION', '1.9.0' );
define( 'SLO_PLUGIN_FILE', __FILE__ );
define( 'SLO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SLO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Verifica que WooCommerce este activo antes de cargar nada.
 * Se revisa en 'plugins_loaded' para que WooCommerce ya este disponible.
 */
function slo_check_dependencies() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'StyleLauri Order Flow requiere que WooCommerce este activo.', 'stylelauri-order-flow' );
				echo '</p></div>';
			}
		);
		return false;
	}
	return true;
}

/**
 * Declara compatibilidad con HPOS (High-Performance Order Storage).
 * Este plugin esta construido asumiendo HPOS activo; declarar compatibilidad
 * evita el aviso de "incompatible" en el admin de WooCommerce.
 */
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				SLO_PLUGIN_FILE,
				true
			);
		}
	}
);

/**
 * Bootstrap principal. No hay side-effects pesados a nivel de archivo:
 * todo se carga colgado de 'plugins_loaded' / 'init' segun corresponda.
 */
function slo_init_plugin() {
	if ( ! slo_check_dependencies() ) {
		return;
	}

	require_once SLO_PLUGIN_DIR . 'includes/class-slo-loader.php';

	$loader = new SLO_Loader();
	$loader->run();
}
add_action( 'plugins_loaded', 'slo_init_plugin' );

/**
 * Activacion: registrado a nivel top del archivo (no dentro de otro hook),
 * como exige el guardrail de lifecycle. Deja las rewrite rules listas
 * despues de registrar la taxonomia de lotes.
 */
function slo_activate_plugin() {
	require_once SLO_PLUGIN_DIR . 'includes/class-slo-taxonomy.php';
	SLO_Taxonomy::register_taxonomy();
	flush_rewrite_rules();
}
register_activation_hook( SLO_PLUGIN_FILE, 'slo_activate_plugin' );

/**
 * Desactivacion: solo limpia rewrite rules. No borra datos -- eso es
 * responsabilidad de uninstall.php, y solo si el usuario lo confirma.
 */
function slo_deactivate_plugin() {
	flush_rewrite_rules();
}
register_deactivation_hook( SLO_PLUGIN_FILE, 'slo_deactivate_plugin' );
