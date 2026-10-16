<?php
/**
 * El cliente HTTP de la v1 de NexoCPE, con la API HTTP de WordPress
 * (wp_remote_*): sin dependencias.
 *
 * Nunca escribe la clave en un log ni la devuelve en un error: el mensaje que
 * vuelve es el de NexoCPE (`message` y `errors`), que no la contiene.
 *
 * @package NexoCPE_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class NexoCPE_Cliente {

	/** @var string */
	private $base;

	/** @var string */
	private $ruc;

	/** @var string */
	private $clave;

	public function __construct( $base, $ruc, $clave ) {
		$this->base  = rtrim( (string) $base, '/' );
		$this->ruc   = (string) $ruc;
		$this->clave = (string) $clave;
	}

	public function configurado() {
		return '' !== $this->base && 1 === preg_match( '/^\d{11}$/', $this->ruc ) && '' !== $this->clave;
	}

	/**
	 * POST /comprobantes con su Idempotency-Key: reintentar con la misma clave
	 * nunca emite dos veces.
	 *
	 * @return array{estado: int, cuerpo: array, error: ?string} estado 0 = no hubo respuesta (red).
	 */
	public function emitir( array $cuerpo, $clave_idempotencia ) {
		return $this->pedir(
			'POST',
			'/comprobantes',
			array(
				'body'    => wp_json_encode( $cuerpo ),
				'headers' => array( 'Idempotency-Key' => $clave_idempotencia ),
			)
		);
	}

	/** @return array{estado: int, cuerpo: array, error: ?string} */
	public function comprobante( $id ) {
		return $this->pedir( 'GET', '/comprobantes/' . rawurlencode( (string) $id ) );
	}

	/** Para «Probar conexión»: lista las series del emisor. */
	public function series() {
		return $this->pedir( 'GET', '/series' );
	}

	/** @return array{estado: int, cuerpo: array, error: ?string} */
	private function pedir( $metodo, $ruta, array $opciones = array() ) {
		$respuesta = wp_remote_request(
			$this->base . '/api/v1/' . $this->ruc . $ruta,
			array_merge_recursive(
				array(
					'method'  => $metodo,
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Bearer ' . $this->clave,
						'Accept'        => 'application/json',
						'Content-Type'  => 'application/json',
						'User-Agent'    => 'Comprobia-WooCommerce/' . NEXOCPE_WC_VERSION,
					),
				),
				$opciones
			)
		);

		if ( is_wp_error( $respuesta ) ) {
			// El mensaje de WP_Error es de la red (cURL), no lleva la clave: va en la cabecera.
			return array( 'estado' => 0, 'cuerpo' => array(), 'error' => 'No se pudo conectar con Comprobia: ' . $respuesta->get_error_message() );
		}

		$estado = (int) wp_remote_retrieve_response_code( $respuesta );
		$cuerpo = json_decode( (string) wp_remote_retrieve_body( $respuesta ), true );
		$cuerpo = is_array( $cuerpo ) ? $cuerpo : array();

		return array(
			'estado' => $estado,
			'cuerpo' => $cuerpo,
			'error'  => $estado >= 200 && $estado < 300 ? null : self::mensaje( $estado, $cuerpo ),
		);
	}

	/** El mensaje de NexoCPE con sus errores por campo, legible para quien atiende la tienda. */
	private static function mensaje( $estado, array $cuerpo ) {
		$mensaje = isset( $cuerpo['message'] ) ? (string) $cuerpo['message'] : "Comprobia respondió {$estado}.";
		$detalle = array();

		foreach ( isset( $cuerpo['errors'] ) && is_array( $cuerpo['errors'] ) ? $cuerpo['errors'] : array() as $error ) {
			if ( is_array( $error ) && isset( $error['message'] ) ) {
				$detalle[] = ( isset( $error['field'] ) ? $error['field'] . ': ' : '' ) . $error['message']
					. ( isset( $error['sunat_code'] ) ? ' (SUNAT ' . $error['sunat_code'] . ')' : '' );
			}
		}

		return array() === $detalle ? $mensaje : $mensaje . ' — ' . implode( ' · ', array_slice( $detalle, 0, 5 ) );
	}
}
