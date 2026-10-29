<?php
/**
 * Arma el JSON de la v1 de NexoCPE a partir de un pedido.
 *
 * PURO a propósito: recibe un arreglo plano (lo arma NexoCPE_Pedidos desde el
 * WC_Order) y devuelve el cuerpo de POST /api/v1/{ruc}/comprobantes. Así se
 * prueba sin WordPress (tests/mapeador-test.php), y las reglas viven en un
 * solo sitio.
 *
 * Tres decisiones que no se cambian a la ligera:
 *
 * 1. Se manda el PRECIO CON IGV (`precio_unitario`): (total + impuesto) / cantidad
 *    de la línea, que es lo que pagó el cliente con los cupones ya aplicados.
 *    El IGV lo calcula NexoCPE por línea. Así da igual si la tienda tiene los
 *    impuestos de WooCommerce activados o no.
 * 2. La división se hace con enteros y 10 decimales, sin float: cantidad ×
 *    precio vuelve al céntimo exacto de lo cobrado.
 * 3. Lo que no sabe emitir bien —una línea gratis, un cargo negativo, una
 *    cantidad con más de 3 decimales— NO se adivina: se detiene con un mensaje
 *    y el comprobante se emite desde el panel. Un comprobante distinto de lo
 *    cobrado es peor que ninguno.
 *
 * @package NexoCPE_WooCommerce
 */

defined( 'ABSPATH' ) || defined( 'NEXOCPE_PRUEBAS' ) || exit;

/** El pedido no se puede emitir tal cual: el mensaje dice por qué. */
class NexoCPE_Pedido_No_Emitible extends RuntimeException {}

final class NexoCPE_Mapeador {

	/** Una boleta por encima de esto necesita el documento del cliente (RN-B1 de NexoCPE, regla 2514 de SUNAT). */
	const TOPE_BOLETA_SIN_CLIENTE = 70000; // céntimos

	/**
	 * @param array $pedido  id, moneda, fecha (Y-m-d, hora de Lima), lineas[], envio?, cargos[], cliente{}.
	 * @param array $ajustes serie_factura, serie_boleta, afectacion (10|20|30).
	 * @return array El cuerpo para la v1.
	 * @throws NexoCPE_Pedido_No_Emitible Si el pedido no se puede emitir tal cual.
	 */
	public static function cuerpo( array $pedido, array $ajustes ) {
		$cliente = isset( $pedido['cliente'] ) && is_array( $pedido['cliente'] ) ? $pedido['cliente'] : array();
		$moneda  = strtoupper( (string) ( isset( $pedido['moneda'] ) ? $pedido['moneda'] : 'PEN' ) );

		if ( ! in_array( $moneda, array( 'PEN', 'USD' ), true ) ) {
			throw new NexoCPE_Pedido_No_Emitible( "La moneda del pedido es {$moneda}: NexoCPE emite en soles (PEN) o dólares (USD)." );
		}

		$items = array();
		$total = 0;

		foreach ( isset( $pedido['lineas'] ) ? $pedido['lineas'] : array() as $i => $linea ) {
			$item    = self::item( $linea, $ajustes, $i + 1 );
			$total  += $item['_centimos'];
			unset( $item['_centimos'] );
			$items[] = $item;
		}

		if ( ! empty( $pedido['envio'] ) && self::centimos( $pedido['envio']['total'] ) + self::centimos( $pedido['envio']['impuesto'] ) > 0 ) {
			$envio   = self::item(
				array(
					'nombre'   => isset( $pedido['envio']['nombre'] ) && '' !== $pedido['envio']['nombre'] ? 'Envío: ' . $pedido['envio']['nombre'] : 'Envío',
					'sku'      => 'ENVIO',
					'cantidad' => '1',
					'total'    => $pedido['envio']['total'],
					'impuesto' => $pedido['envio']['impuesto'],
					'servicio' => true,
				),
				$ajustes,
				'envío'
			);
			$total  += $envio['_centimos'];
			unset( $envio['_centimos'] );
			$items[] = $envio;
		}

		foreach ( isset( $pedido['cargos'] ) ? $pedido['cargos'] : array() as $i => $cargo ) {
			$item = self::item(
				array(
					'nombre'   => $cargo['nombre'],
					'sku'      => 'CARGO-' . ( $i + 1 ),
					'cantidad' => '1',
					'total'    => $cargo['total'],
					'impuesto' => $cargo['impuesto'],
					'servicio' => true,
				),
				$ajustes,
				'cargo «' . $cargo['nombre'] . '»'
			);
			$total += $item['_centimos'];
			unset( $item['_centimos'] );
			$items[] = $item;
		}

		if ( array() === $items ) {
			throw new NexoCPE_Pedido_No_Emitible( 'El pedido no tiene líneas que facturar.' );
		}

		$ruc = self::solo_digitos( isset( $cliente['ruc'] ) ? $cliente['ruc'] : '' );

		if ( '' !== $ruc ) {
			if ( ! self::ruc_valido( $ruc ) ) {
				throw new NexoCPE_Pedido_No_Emitible( "El RUC «{$ruc}» no es válido: revisa los once dígitos." );
			}

			$razon = trim( (string) ( isset( $cliente['razon_social'] ) ? $cliente['razon_social'] : '' ) );

			if ( '' === $razon ) {
				throw new NexoCPE_Pedido_No_Emitible( 'Para una factura falta la razón social del cliente.' );
			}

			$tipo      = '01';
			$serie     = $ajustes['serie_factura'];
			$adquirente = array_filter(
				array(
					'tipo_documento'   => '6',
					'numero_documento' => $ruc,
					'razon_social'     => $razon,
					'direccion'        => isset( $cliente['direccion'] ) ? trim( (string) $cliente['direccion'] ) : '',
					'email'            => isset( $cliente['email'] ) ? trim( (string) $cliente['email'] ) : '',
				),
				'strlen'
			);
		} else {
			$dni    = self::solo_digitos( isset( $cliente['dni'] ) ? $cliente['dni'] : '' );
			$nombre = trim( (string) ( isset( $cliente['nombre'] ) ? $cliente['nombre'] : '' ) );
			$tipo   = '03';
			$serie  = $ajustes['serie_boleta'];

			if ( '' !== $dni && 8 !== strlen( $dni ) ) {
				throw new NexoCPE_Pedido_No_Emitible( "El DNI «{$dni}» no tiene ocho dígitos." );
			}

			if ( '' === $dni && 'PEN' === $moneda && $total > self::TOPE_BOLETA_SIN_CLIENTE ) {
				throw new NexoCPE_Pedido_No_Emitible( 'Una boleta de más de S/ 700 necesita el DNI del cliente: agrégalo al pedido y vuelve a emitir.' );
			}

			// En dólares el tope de S/ 700 se mide también convertido, con el tipo
			// de cambio del día, que aquí no se conoce: sin DNI no se adivina.
			if ( '' === $dni && 'USD' === $moneda ) {
				throw new NexoCPE_Pedido_No_Emitible( 'Una boleta en dólares necesita el DNI del cliente: el tope de S/ 700 depende del tipo de cambio del día. Agrégalo al pedido y vuelve a emitir.' );
			}

			$adquirente = '' === $dni
				? array(
					'tipo_documento'   => '0',
					'numero_documento' => '-',
					'razon_social'     => 'CLIENTES VARIOS',
				)
				: array_filter(
					array(
						'tipo_documento'   => '1',
						'numero_documento' => $dni,
						'razon_social'     => '' === $nombre ? 'CLIENTE' : $nombre,
						'email'            => isset( $cliente['email'] ) ? trim( (string) $cliente['email'] ) : '',
					),
					'strlen'
				);
		}

		if ( ! preg_match( '/^' . ( '01' === $tipo ? 'F' : 'B' ) . '[A-Z0-9]{3}$/', (string) $serie ) ) {
			throw new NexoCPE_Pedido_No_Emitible( "La serie «{$serie}» no es de " . ( '01' === $tipo ? 'factura (F…)' : 'boleta (B…)' ) . ': revísala en los ajustes del plugin.' );
		}

		return array(
			'tipo_documento' => $tipo,
			'serie'          => $serie,
			'fecha_emision'  => $pedido['fecha'],
			'moneda'         => $moneda,
			'cliente'        => $adquirente,
			'forma_pago'     => array( 'tipo' => 'Contado' ),
			'items'          => $items,
			'informativo'    => array( 'observaciones' => 'Pedido web n.º ' . $pedido['id'] ),
		);
	}

	/**
	 * Una línea de la v1, con sus céntimos cobrados en `_centimos` (se quita antes de mandar).
	 *
	 * @param array      $linea   nombre, sku, cantidad, total, impuesto, servicio?
	 * @param array      $ajustes afectacion.
	 * @param int|string $que     Para el mensaje de error.
	 * @return array
	 * @throws NexoCPE_Pedido_No_Emitible Si la línea no se puede emitir.
	 */
	private static function item( array $linea, array $ajustes, $que ) {
		$nombre   = trim( (string) $linea['nombre'] );
		$centimos = self::centimos( $linea['total'] ) + self::centimos( $linea['impuesto'] );
		$milesimas = self::milesimas( (string) $linea['cantidad'], $que );

		if ( $centimos <= 0 ) {
			throw new NexoCPE_Pedido_No_Emitible(
				is_int( $que )
					? "La línea {$que} («{$nombre}») no tiene precio. Los regalos y bonificaciones se emiten desde el panel de Comprobia."
					: "El {$que} es negativo o cero: los descuentos van en el precio de cada producto (un cupón)."
			);
		}

		$codigo = trim( (string) $linea['sku'] );

		return array(
			'codigo_interno'   => '' === $codigo ? 'WC' : substr( $codigo, 0, 60 ),
			'descripcion'      => '' === $nombre ? 'PRODUCTO' : mb_substr( $nombre, 0, 500 ),
			'unidad'           => empty( $linea['servicio'] ) ? 'NIU' : 'ZZ',
			'cantidad'         => self::decimal( (string) $milesimas, 3 ),
			// precio = céntimos / 100 / (milésimas / 1000) = céntimos × 10 / milésimas
			'precio_unitario'  => self::dividir( $centimos * 10, $milesimas, 10 ),
			'tipo_afectacion'  => self::afectacion( $linea, $ajustes ),
			'_centimos'        => $centimos,
		);
	}

	/** Con IGV si WooCommerce le cobró impuesto; si no, la del ajuste (precios con IGV incluido: 10). */
	private static function afectacion( array $linea, array $ajustes ) {
		if ( self::centimos( $linea['impuesto'] ) > 0 ) {
			return '10';
		}

		$afectacion = isset( $ajustes['afectacion'] ) ? (string) $ajustes['afectacion'] : '10';

		return in_array( $afectacion, array( '10', '20', '30' ), true ) ? $afectacion : '10';
	}

	/** «118.5» → 11850. WooCommerce guarda los totales como texto decimal; si llega un float, se redondea al céntimo. */
	public static function centimos( $valor ) {
		$texto = trim( (string) $valor );

		if ( '' === $texto ) {
			return 0;
		}

		if ( ! preg_match( '/^-?\d+(\.\d+)?$/', $texto ) ) {
			$texto = (string) round( (float) $valor, 2 );
		}

		return (int) round( (float) $texto * 100 );
	}

	/** «2» → 2000, «1.5» → 1500. Más de 3 decimales no se adivina. */
	private static function milesimas( $cantidad, $que ) {
		if ( ! preg_match( '/^\d+(\.(\d{1,3}))?$/', trim( $cantidad ), $m ) || (float) $cantidad <= 0 ) {
			throw new NexoCPE_Pedido_No_Emitible( "La cantidad «{$cantidad}» de la línea {$que} no es válida (hasta 3 decimales)." );
		}

		$decimales = isset( $m[2] ) ? str_pad( $m[2], 3, '0' ) : '000';

		return (int) ( strtok( trim( $cantidad ), '.' ) . $decimales );
	}

	/**
	 * División exacta de enteros con N decimales y redondeo al último, sin
	 * float ni bcmath (no todos los hostings lo traen).
	 */
	public static function dividir( $dividendo, $divisor, $decimales ) {
		$entero = intdiv( $dividendo, $divisor );
		$resto  = $dividendo % $divisor;
		$cifras = '';

		for ( $i = 0; $i <= $decimales; $i++ ) {
			$resto  *= 10;
			$cifras .= (string) intdiv( $resto, $divisor );
			$resto   = $resto % $divisor;
		}

		// La cifra de más decide el redondeo (mitad hacia arriba).
		$redondear = (int) substr( $cifras, -1 ) >= 5;
		$numero    = (string) $entero . substr( $cifras, 0, $decimales );

		if ( $redondear ) {
			$numero = self::sumar_uno( $numero );
		}

		$numero = str_pad( $numero, $decimales + 1, '0', STR_PAD_LEFT );

		return self::decimal( $numero, $decimales );
	}

	/** «11850», 2 → «118.5»: pone el punto y quita los ceros que sobran. */
	private static function decimal( $digitos, $decimales ) {
		$digitos = str_pad( ltrim( $digitos, '0' ), $decimales + 1, '0', STR_PAD_LEFT );
		$entero  = substr( $digitos, 0, -$decimales );
		$fraccion = rtrim( substr( $digitos, -$decimales ), '0' );

		return '' === $fraccion ? $entero : $entero . '.' . $fraccion;
	}

	private static function sumar_uno( $digitos ) {
		$i = strlen( $digitos ) - 1;

		while ( $i >= 0 && '9' === $digitos[ $i ] ) {
			$digitos[ $i ] = '0';
			--$i;
		}

		return $i < 0 ? '1' . $digitos : substr_replace( $digitos, (string) ( (int) $digitos[ $i ] + 1 ), $i, 1 );
	}

	private static function solo_digitos( $valor ) {
		return preg_replace( '/\D/', '', (string) $valor );
	}

	/** Módulo 11 de SUNAT: la misma red contra el dedazo que usa NexoCPE al dar de alta un emisor. */
	public static function ruc_valido( $ruc ) {
		if ( ! preg_match( '/^\d{11}$/', $ruc ) ) {
			return false;
		}

		$pesos = array( 5, 4, 3, 2, 7, 6, 5, 4, 3, 2 );
		$suma  = 0;

		foreach ( $pesos as $i => $peso ) {
			$suma += (int) $ruc[ $i ] * $peso;
		}

		$digito = 11 - ( $suma % 11 );
		$digito = 10 === $digito ? 0 : ( 11 === $digito ? 1 : $digito );

		return (int) $ruc[10] === $digito;
	}
}
