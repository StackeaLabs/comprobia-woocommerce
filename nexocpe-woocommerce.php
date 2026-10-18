<?php
/**
 * Plugin Name:       Comprobia para WooCommerce
 * Plugin URI:        https://comprobia.stackealabs.com/desarrolladores
 * Description:       Emite la boleta o la factura electrónica de cada pedido ante SUNAT con Comprobia.
 * Version:           0.1.2
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * Author:            Victor Llerena
 * Author URI:        https://victorllerena.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nexocpe-woocommerce
 * WC requires at least: 8.9
 * WC tested up to:   11.1
 *
 * @package NexoCPE_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'NEXOCPE_WC_VERSION', '0.1.2' );
define( 'NEXOCPE_WC_ARCHIVO', __FILE__ );

require_once __DIR__ . '/includes/class-nexocpe-mapeador.php';
require_once __DIR__ . '/includes/class-nexocpe-cliente.php';
require_once __DIR__ . '/includes/class-nexocpe-checkout.php';
require_once __DIR__ . '/includes/class-nexocpe-pedidos.php';

final class NexoCPE_Plugin {

	public static function iniciar() {
		// Pedidos en tablas propias (HPOS) y checkout de bloques: el plugin usa
		// sólo la API de WC_Order y los campos adicionales del checkout.
		add_action(
			'before_woocommerce_init',
			static function () {
				if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', NEXOCPE_WC_ARCHIVO, true );
					\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', NEXOCPE_WC_ARCHIVO, true );
				}
			}
		);

		add_action( 'plugins_loaded', array( __CLASS__, 'cargar' ) );
	}

	public static function cargar() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>Comprobia para WooCommerce necesita WooCommerce activo.</p></div>';
				}
			);

			return;
		}

		require_once __DIR__ . '/includes/class-nexocpe-integracion.php';

		add_filter(
			'woocommerce_integrations',
			static function ( $integraciones ) {
				$integraciones[] = 'NexoCPE_Integracion';

				return $integraciones;
			}
		);

		NexoCPE_Checkout::registrar();
		NexoCPE_Pedidos::registrar();
	}

	/** @return array<string, string> */
	public static function ajustes() {
		$guardados = get_option( 'woocommerce_nexocpe_settings', array() );
		$guardados = is_array( $guardados ) ? $guardados : array();

		return array_merge(
			array(
				'url'           => 'https://comprobia.stackealabs.com',
				'ruc'           => '',
				'clave'         => '',
				'serie_factura' => 'F001',
				'serie_boleta'  => 'B001',
				'disparador'    => 'completed',
				'afectacion'    => '10',
			),
			array_filter( $guardados, 'is_string' )
		);
	}

	public static function ajuste( $clave, $defecto = '' ) {
		$ajustes = self::ajustes();

		return isset( $ajustes[ $clave ] ) && '' !== $ajustes[ $clave ] ? $ajustes[ $clave ] : $defecto;
	}

	public static function cliente() {
		$a = self::ajustes();

		return new NexoCPE_Cliente( $a['url'], $a['ruc'], $a['clave'] );
	}
}

NexoCPE_Plugin::iniciar();
