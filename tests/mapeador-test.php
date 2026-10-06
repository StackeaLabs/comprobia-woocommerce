<?php
/**
 * Pruebas del mapeador, sin WordPress ni dependencias: `php tests/mapeador-test.php`.
 * Sale con código 1 si alguna falla.
 *
 * @package NexoCPE_WooCommerce
 */

define( 'NEXOCPE_PRUEBAS', true );
require __DIR__ . '/../includes/class-nexocpe-mapeador.php';

$fallos = 0;
$total  = 0;

function prueba( $nombre, callable $fn ) {
	global $fallos, $total;
	++$total;

	try {
		$fn();
		echo "  ✓ {$nombre}\n";
	} catch ( Throwable $e ) {
		++$fallos;
		echo "  ✗ {$nombre}\n      " . $e->getMessage() . "\n";
	}
}

function igual( $esperado, $real, $que = '' ) {
	if ( $esperado !== $real ) {
		throw new RuntimeException( "{$que}: se esperaba " . var_export( $esperado, true ) . ', llegó ' . var_export( $real, true ) );
	}
}

function lanza( callable $fn, $fragmento ) {
	try {
		$fn();
	} catch ( NexoCPE_Pedido_No_Emitible $e ) {
		if ( false === strpos( $e->getMessage(), $fragmento ) ) {
			throw new RuntimeException( "El mensaje no dice «{$fragmento}»: " . $e->getMessage() );
		}

		return;
	}

	throw new RuntimeException( "No se detuvo (esperaba «{$fragmento}»)." );
}

function pedido( array $cambios = array() ) {
	return array_replace(
		array(
			'id'      => '1001',
			'moneda'  => 'PEN',
			'fecha'   => '2026-10-06',
			'lineas'  => array(
				array( 'nombre' => 'Polo negro', 'sku' => 'POLO-N', 'cantidad' => '2', 'total' => '118.00', 'impuesto' => '0', 'servicio' => false ),
				array( 'nombre' => 'Stickers', 'sku' => '', 'cantidad' => '3', 'total' => '10', 'impuesto' => '', 'servicio' => false ),
			),
			'envio'   => array( 'nombre' => 'Olva', 'total' => '15.00', 'impuesto' => '0' ),
			'cargos'  => array(),
			'cliente' => array( 'nombre' => 'Ana Pérez', 'email' => 'ana@correo.pe' ),
		),
		$cambios
	);
}

$ajustes = array( 'serie_factura' => 'F001', 'serie_boleta' => 'B001', 'afectacion' => '10' );

echo "Mapeador\n";

prueba(
	'boleta sin cliente: precio con IGV por línea, envío como servicio',
	function () use ( $ajustes ) {
		$c = NexoCPE_Mapeador::cuerpo( pedido(), $ajustes );

		igual( '03', $c['tipo_documento'], 'tipo' );
		igual( 'B001', $c['serie'], 'serie' );
		igual( array( 'tipo_documento' => '0', 'numero_documento' => '-', 'razon_social' => 'CLIENTES VARIOS' ), $c['cliente'], 'cliente' );
		igual( '59', $c['items'][0]['precio_unitario'], 'precio del polo' );
		igual( '2', $c['items'][0]['cantidad'], 'cantidad' );
		igual( 'WC', $c['items'][1]['codigo_interno'], 'código sin SKU' );
		igual( '3.3333333333', $c['items'][1]['precio_unitario'], '10 / 3' );
		igual( array( 'Envío: Olva', 'ZZ', '15' ), array( $c['items'][2]['descripcion'], $c['items'][2]['unidad'], $c['items'][2]['precio_unitario'] ), 'envío' );
		igual( '10', $c['items'][0]['tipo_afectacion'], 'afectación' );
		igual( '2026-10-06', $c['fecha_emision'], 'fecha' );
		igual( array( 'tipo' => 'Contado' ), $c['forma_pago'], 'forma de pago' );
	}
);

prueba(
	'cantidad × precio vuelve al céntimo cobrado',
	function () {
		foreach ( array( array( 1000, 3 ), array( 1, 7 ), array( 99999, 13 ), array( 12345, 1 ), array( 200, 6 ) ) as list( $centimos, $cantidad ) ) {
			$precio = NexoCPE_Mapeador::dividir( $centimos * 10, $cantidad * 1000, 10 );
			$vuelta = (int) round( (float) $precio * $cantidad * 100 );
			igual( $centimos, $vuelta, "{$centimos} céntimos / {$cantidad}" );
		}

		igual( '0.6666666667', NexoCPE_Mapeador::dividir( 2, 3, 10 ), 'redondeo hacia arriba' );
		igual( '1', NexoCPE_Mapeador::dividir( 9999999999999, 9999999999999, 10 ), 'exacto' );
	}
);

prueba(
	'factura a un RUC válido, con su razón social y dirección',
	function () use ( $ajustes ) {
		$c = NexoCPE_Mapeador::cuerpo( pedido( array( 'cliente' => array( 'ruc' => '20100066603', 'razon_social' => 'EMPRESA SAC', 'direccion' => 'AV. LIMA 123', 'email' => 'pagos@empresa.pe' ) ) ), $ajustes );

		igual( '01', $c['tipo_documento'], 'tipo' );
		igual( 'F001', $c['serie'], 'serie' );
		igual( '6', $c['cliente']['tipo_documento'], 'tipo de documento' );
		igual( 'EMPRESA SAC', $c['cliente']['razon_social'], 'razón social' );
	}
);

prueba(
	'factura: RUC con el dígito malo o sin razón social se detiene',
	function () use ( $ajustes ) {
		lanza( fn () => NexoCPE_Mapeador::cuerpo( pedido( array( 'cliente' => array( 'ruc' => '20100066604', 'razon_social' => 'X' ) ) ), $ajustes ), 'no es válido' );
		lanza( fn () => NexoCPE_Mapeador::cuerpo( pedido( array( 'cliente' => array( 'ruc' => '20100066603' ) ) ), $ajustes ), 'razón social' );
	}
);

prueba(
	'boleta de más de S/ 700 sin DNI se detiene; con DNI sale a su nombre',
	function () use ( $ajustes ) {
		$cara = pedido( array( 'lineas' => array( array( 'nombre' => 'Laptop', 'sku' => 'L1', 'cantidad' => '1', 'total' => '2500', 'impuesto' => '0' ) ) ) );

		lanza( fn () => NexoCPE_Mapeador::cuerpo( $cara, $ajustes ), 'S/ 700' );

		$cara['cliente']['dni'] = '12345678';
		$c                      = NexoCPE_Mapeador::cuerpo( $cara, $ajustes );
		igual( array( '1', '12345678', 'Ana Pérez' ), array( $c['cliente']['tipo_documento'], $c['cliente']['numero_documento'], $c['cliente']['razon_social'] ), 'cliente' );

		$cara['cliente']['dni'] = '1234';
		lanza( fn () => NexoCPE_Mapeador::cuerpo( $cara, $ajustes ), 'ocho dígitos' );
	}
);

prueba(
	'con los impuestos de WooCommerce: precio = total + impuesto, siempre gravado',
	function () {
		$c = NexoCPE_Mapeador::cuerpo(
			pedido( array( 'lineas' => array( array( 'nombre' => 'Libro', 'sku' => 'B', 'cantidad' => '1', 'total' => '100.00', 'impuesto' => '18.00' ) ), 'envio' => array() ) ),
			array( 'serie_factura' => 'F001', 'serie_boleta' => 'B001', 'afectacion' => '20' )
		);

		igual( '118', $c['items'][0]['precio_unitario'], 'precio' );
		igual( '10', $c['items'][0]['tipo_afectacion'], 'afectación' );
		igual( 1, count( $c['items'] ), 'sin envío' );
	}
);

prueba(
	'lo que no sabe emitir bien no lo adivina',
	function () use ( $ajustes ) {
		lanza( fn () => NexoCPE_Mapeador::cuerpo( pedido( array( 'lineas' => array( array( 'nombre' => 'Regalo', 'sku' => 'R', 'cantidad' => '1', 'total' => '0', 'impuesto' => '0' ) ) ) ), $ajustes ), 'no tiene precio' );
		lanza( fn () => NexoCPE_Mapeador::cuerpo( pedido( array( 'cargos' => array( array( 'nombre' => 'Descuento', 'total' => '-5', 'impuesto' => '0' ) ) ) ), $ajustes ), 'negativo' );
		lanza( fn () => NexoCPE_Mapeador::cuerpo( pedido( array( 'moneda' => 'EUR' ) ), $ajustes ), 'PEN' );
		lanza( fn () => NexoCPE_Mapeador::cuerpo( pedido( array( 'lineas' => array( array( 'nombre' => 'Tela', 'sku' => 'T', 'cantidad' => '1.2345', 'total' => '10', 'impuesto' => '0' ) ) ) ), $ajustes ), 'cantidad' );
		lanza( fn () => NexoCPE_Mapeador::cuerpo( pedido(), array( 'serie_factura' => 'F001', 'serie_boleta' => 'F002', 'afectacion' => '10' ) ), 'serie' );
	}
);

prueba(
	'cantidades con decimales y cargos positivos',
	function () use ( $ajustes ) {
		$c = NexoCPE_Mapeador::cuerpo(
			pedido(
				array(
					'lineas' => array( array( 'nombre' => 'Tela', 'sku' => 'T', 'cantidad' => '1.5', 'total' => '30', 'impuesto' => '0' ) ),
					'cargos' => array( array( 'nombre' => 'Embalaje', 'total' => '2.50', 'impuesto' => '0' ) ),
					'envio'  => array(),
				)
			),
			$ajustes
		);

		igual( array( '1.5', '20' ), array( $c['items'][0]['cantidad'], $c['items'][0]['precio_unitario'] ), 'tela' );
		igual( array( 'Embalaje', '2.5', 'ZZ' ), array( $c['items'][1]['descripcion'], $c['items'][1]['precio_unitario'], $c['items'][1]['unidad'] ), 'cargo' );
	}
);

prueba(
	'el módulo 11 coincide con el de NexoCPE',
	function () {
		igual( true, NexoCPE_Mapeador::ruc_valido( '20100066603' ), 'manual del programador' );
		igual( true, NexoCPE_Mapeador::ruc_valido( '20480072872' ), 'REST de la GRE' );
		igual( false, NexoCPE_Mapeador::ruc_valido( '20480072873' ), 'dígito malo' );
		igual( false, NexoCPE_Mapeador::ruc_valido( '2048007287' ), 'diez dígitos' );
	}
);

echo "\n" . ( $total - $fallos ) . " de {$total} pasaron.\n";
exit( $fallos > 0 ? 1 : 0 );
