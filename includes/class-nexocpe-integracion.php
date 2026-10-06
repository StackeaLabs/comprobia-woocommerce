<?php
/**
 * Los ajustes, en WooCommerce → Ajustes → Integración → NexoCPE.
 *
 * La clave de API no se vuelve a pintar en el formulario (ni en el HTML):
 * el campo queda vacío y, si se guarda vacío, se conserva la que había. Al
 * guardar se prueba la conexión pidiendo las series del emisor.
 *
 * @package NexoCPE_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class NexoCPE_Integracion extends WC_Integration {

	public function __construct() {
		$this->id                 = 'nexocpe';
		$this->method_title       = 'NexoCPE';
		$this->method_description = 'Emite la boleta o la factura electrónica de cada pedido con NexoCPE. '
			. 'Prueba primero con un sandbox (gratis, no llega a SUNAT): crea uno en /desarrolladores/sandbox.';

		$this->init_form_fields();
		$this->init_settings();

		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'process_admin_options' ) );
		add_action( 'woocommerce_update_options_integration_' . $this->id, array( $this, 'probar_conexion' ), 20 );
	}

	public function init_form_fields() {
		$this->form_fields = array(
			'url'           => array(
				'title'       => 'URL de NexoCPE',
				'type'        => 'text',
				'default'     => 'https://nexocpe.victorllerena.com',
				'description' => 'Sin barra al final.',
			),
			'ruc'           => array(
				'title'       => 'RUC del emisor',
				'type'        => 'text',
				'description' => 'El RUC que emite (el de tu tienda, o el de tu sandbox).',
			),
			'clave'         => array(
				'title'       => 'Clave de API',
				'type'        => 'clave',
				'description' => 'nx_beta_… o nx_produccion_…, con permiso de emitir y consultar. Por seguridad no se vuelve a mostrar: déjalo vacío para conservar la guardada.',
			),
			'serie_factura' => array(
				'title'   => 'Serie de factura',
				'type'    => 'text',
				'default' => 'F001',
			),
			'serie_boleta'  => array(
				'title'   => 'Serie de boleta',
				'type'    => 'text',
				'default' => 'B001',
			),
			'disparador'    => array(
				'title'       => 'Emitir cuando el pedido pase a',
				'type'        => 'select',
				'default'     => 'completed',
				'options'     => array(
					'completed'  => 'Completado (recomendado)',
					'processing' => 'Procesando (al pagarse)',
					'manual'     => 'Sólo a mano, desde el pedido',
				),
				'description' => 'Con «Completado», el comprobante sale en el correo de pedido completado.',
			),
			'afectacion'    => array(
				'title'       => 'Productos sin impuesto en WooCommerce',
				'type'        => 'select',
				'default'     => '10',
				'options'     => array(
					'10' => 'Gravados: el precio ya incluye el IGV',
					'20' => 'Exonerados',
					'30' => 'Inafectos',
				),
				'description' => 'Si cobras el IGV con los impuestos de WooCommerce, esas líneas van gravadas siempre.',
			),
		);
	}

	/** Un campo de contraseña que nunca lleva el valor guardado. */
	public function generate_clave_html( $key, $data ) {
		$campo    = $this->get_field_key( $key );
		$guardada = '' !== (string) $this->get_option( $key );

		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label for="<?php echo esc_attr( $campo ); ?>"><?php echo esc_html( $data['title'] ); ?></label></th>
			<td class="forminp">
				<input class="input-text regular-input" type="password" autocomplete="new-password" name="<?php echo esc_attr( $campo ); ?>" id="<?php echo esc_attr( $campo ); ?>" value="" placeholder="<?php echo esc_attr( $guardada ? '•••••••• (guardada)' : '' ); ?>" />
				<p class="description"><?php echo esc_html( $data['description'] ); ?></p>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/** Vacío = se conserva la que había. */
	public function validate_clave_field( $key, $value ) {
		$value = trim( (string) $value );

		return '' === $value ? (string) $this->get_option( $key ) : $value;
	}

	public function validate_ruc_field( $key, $value ) {
		return preg_replace( '/\D/', '', (string) $value );
	}

	public function validate_serie_factura_field( $key, $value ) {
		return strtoupper( trim( (string) $value ) );
	}

	public function validate_serie_boleta_field( $key, $value ) {
		return strtoupper( trim( (string) $value ) );
	}

	public function probar_conexion() {
		$cliente = NexoCPE_Plugin::cliente();

		if ( ! $cliente->configurado() ) {
			WC_Admin_Settings::add_error( 'NexoCPE: falta la URL, el RUC o la clave de API.' );

			return;
		}

		$r = $cliente->series();

		if ( 200 === $r['estado'] ) {
			$series = array_map(
				static function ( $s ) {
					return is_array( $s ) && isset( $s['serie'] ) ? $s['serie'] : '';
				},
				isset( $r['cuerpo']['data'] ) && is_array( $r['cuerpo']['data'] ) ? $r['cuerpo']['data'] : array()
			);

			WC_Admin_Settings::add_message( 'NexoCPE: conexión correcta. Series del emisor: ' . implode( ', ', array_unique( array_filter( $series ) ) ) . '.' );

			return;
		}

		WC_Admin_Settings::add_error( 'NexoCPE: ' . $r['error'] );
	}
}
