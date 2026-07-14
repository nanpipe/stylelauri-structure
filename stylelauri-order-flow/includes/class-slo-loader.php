<?php
/**
 * Carga todas las piezas del plugin y las conecta a sus hooks.
 * Un solo punto de entrada: cada modulo se encarga de registrar
 * sus propios hooks en su metodo init(), este loader solo orquesta.
 *
 * @package StyleLauri_Order_Flow
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SLO_Loader {

	/**
	 * Instancia cada modulo y dispara su registro de hooks.
	 */
	public function run() {
		$this->load_dependencies();

		SLO_Settings::init();
		SLO_Taxonomy::init();
		SLO_Order_Statuses::init();
		SLO_Order_Snapshot::init();
		SLO_Order_Admin_Columns::init();
		SLO_Order_Balance::init();
		SLO_Dispatch_Gate::init();
		SLO_Checkout_Abono::init();
		SLO_Emails::init();
	}

	/**
	 * Requiere todos los archivos de clases del plugin.
	 * Admin-only classes tambien se cargan aqui (la clase decide
	 * internamente si engancha hooks de admin o no).
	 */
	private function load_dependencies() {
		$includes = SLO_PLUGIN_DIR . 'includes/';

		require_once $includes . 'class-slo-settings.php';
		require_once $includes . 'class-slo-taxonomy.php';
		require_once $includes . 'class-slo-order-statuses.php';
		require_once $includes . 'class-slo-order-snapshot.php';
		require_once $includes . 'class-slo-order-admin-columns.php';
		require_once $includes . 'class-slo-order-balance.php';
		require_once $includes . 'class-slo-dispatch-gate.php';
		require_once $includes . 'class-slo-checkout-abono.php';
		require_once $includes . 'class-slo-emails.php';
	}
}
