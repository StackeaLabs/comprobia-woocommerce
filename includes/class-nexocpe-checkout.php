<?php
/**
 * «¿Boleta o factura?» en el checkout: tipo de comprobante, DNI o RUC y razón
 * social. En el checkout CLÁSICO (shortcode) y en el de BLOQUES, que es el que
 * trae una tienda nueva desde WooCommerce 8.3.
 *
 * El RUC se comprueba con el dígito verificador antes de cerrar la compra: una
 * factura a un RUC mal tecleado es una factura que el cliente no puede usar.
 *
 * Se guarda en el pedido como `_nexocpe_comprobante`, `_nexocpe_documento` y
 * `_nexocpe_razon_social`; lo del checkout de bloques, donde lo deja
 * WooCommerce (dato() lee los dos).
 *
 * @package NexoCPE_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class NexoCPE_Checkout {

	const CAMPOS = array( 'comprobante', 'documento', 'razon_social' );

	public static function registrar() {
		// Clásico.
		add_filter( 'woocommerce_billing_fields', array( __CLASS__, 'campos_clasicos' ) );
		add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validar_clasico' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'guardar_clasico' ), 10, 2 );

		// Bloques (WooCommerce 8.9+).
		add_action( 'woocommerce_init', array( __CLASS__, 'campos_de_bloques' ) );
		add_action( 'woocommerce_blocks_validate_location_order_fields', array( __CLASS__, 'validar_de_bloques' ), 10, 3 );
	}

	/** El dato del pedido, venga del checkout clásico, del de bloques o de la caja del admin. */
	public static function dato( WC_Order $order, $campo ) {
		$valor = (string) $order->get_meta( '_nexocpe_' . $campo );

		if ( '' === $valor ) {
			$valor = (string) $order->get_meta( '_wc_other/nexocpe/' . str_replace( '_', '-', $campo ) );
		}

		return trim( $valor );
	}

	/** @return string|null El problema, o null si está bien. */
	public static function problema( $comprobante, $documento, $razon_social ) {
		$documento = preg_replace( '/\D/', '', (string) $documento );

		if ( 'factura' === $comprobante ) {
			if ( ! NexoCPE_Mapeador::ruc_valido( $documento ) ) {
				return 'Para la factura escribe un RUC válido (11 dígitos).';
			}

			if ( '' === trim( (string) $razon_social ) ) {
				return 'Para la factura escribe la razón social.';
			}

			return null;
		}

		return '' === $documento || 8 === strlen( $documento ) ? null : 'El DNI tiene 8 dígitos.';
	}

	// ── Clásico ─────────────────────────────────────────────────────────────

	public static function campos_clasicos( $campos ) {
		$campos['billing_nexocpe_comprobante']  = array(
			'type'     => 'select',
			'label'    => 'Comprobante',
			'options'  => array(
				'boleta'  => 'Boleta',
				'factura' => 'Factura',
			),
			'default'  => 'boleta',
			'required' => true,
			'priority' => 25,
			'class'    => array( 'form-row-wide' ),
		);
		$campos['billing_nexocpe_documento']    = array(
			'label'       => 'DNI (boleta) o RUC (factura)',
			'required'    => false,
			'priority'    => 26,
			'class'       => array( 'form-row-wide' ),
			'description' => 'En boletas de más de S/ 700 el DNI es obligatorio.',
		);
		$campos['billing_nexocpe_razon_social'] = array(
			'label'    => 'Razón social (sólo factura)',
			'required' => false,
			'priority' => 27,
			'class'    => array( 'form-row-wide' ),
		);

		return $campos;
	}

	public static function validar_clasico( $datos, $errores ) {
		$problema = self::problema(
			isset( $datos['billing_nexocpe_comprobante'] ) ? $datos['billing_nexocpe_comprobante'] : 'boleta',
			isset( $datos['billing_nexocpe_documento'] ) ? $datos['billing_nexocpe_documento'] : '',
			isset( $datos['billing_nexocpe_razon_social'] ) ? $datos['billing_nexocpe_razon_social'] : ''
		);

		if ( null !== $problema ) {
			$errores->add( 'nexocpe', $problema );
		}
	}

	public static function guardar_clasico( $order, $datos ) {
		foreach ( self::CAMPOS as $campo ) {
			if ( isset( $datos[ 'billing_nexocpe_' . $campo ] ) ) {
				$order->update_meta_data( '_nexocpe_' . $campo, sanitize_text_field( (string) $datos[ 'billing_nexocpe_' . $campo ] ) );
			}
		}
	}

	// ── Bloques ─────────────────────────────────────────────────────────────

	public static function campos_de_bloques() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'nexocpe/comprobante',
				'label'    => 'Comprobante',
				'location' => 'order',
				'type'     => 'select',
				'required' => true,
				'options'  => array(
					array(
						'value' => 'boleta',
						'label' => 'Boleta',
					),
					array(
						'value' => 'factura',
						'label' => 'Factura',
					),
				),
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'nexocpe/documento',
				'label'    => 'DNI (boleta) o RUC (factura)',
				'location' => 'order',
				'type'     => 'text',
				'required' => false,
			)
		);

		woocommerce_register_additional_checkout_field(
			array(
				'id'       => 'nexocpe/razon-social',
				'label'    => 'Razón social (sólo factura)',
				'location' => 'order',
				'type'     => 'text',
				'required' => false,
			)
		);
	}

	/**
	 * Todos los campos del pedido juntos (el RUC y la razón social dependen
	 * del tipo): `woocommerce_blocks_validate_location_order_fields`.
	 */
	public static function validar_de_bloques( $errores, $campos, $grupo ) {
		$campos   = is_array( $campos ) ? $campos : array();
		$problema = self::problema(
			isset( $campos['nexocpe/comprobante'] ) ? (string) $campos['nexocpe/comprobante'] : 'boleta',
			isset( $campos['nexocpe/documento'] ) ? (string) $campos['nexocpe/documento'] : '',
			isset( $campos['nexocpe/razon-social'] ) ? (string) $campos['nexocpe/razon-social'] : ''
		);

		if ( null !== $problema ) {
			$errores->add( 'nexocpe_documento', $problema );
		}
	}
}
