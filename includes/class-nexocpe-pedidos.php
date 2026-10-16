<?php
/**
 * Lo que pasa con un pedido: emitir su comprobante, reintentar si NexoCPE no
 * contestó, refrescar el estado ante SUNAT y enseñarlo en el pedido, en «Mi
 * cuenta» y en el correo.
 *
 * Emite EN EL MOMENTO en que el pedido cambia de estado (prioridad 5, antes
 * del correo de WooCommerce, que así sale con el comprobante) y, si NexoCPE no
 * responde, reintenta en segundo plano con Action Scheduler. La
 * Idempotency-Key es fija por pedido: ningún reintento emite dos veces.
 *
 * @package NexoCPE_WooCommerce
 */

defined( 'ABSPATH' ) || exit;

final class NexoCPE_Pedidos {

	const ACCION_EMITIR   = 'nexocpe_emitir_pedido';
	const ACCION_ESTADO   = 'nexocpe_actualizar_estado';
	const GRUPO           = 'nexocpe';
	const MAX_INTENTOS    = 6;
	const MAX_CONSULTAS   = 8;

	/** Estados de NexoCPE que ya no cambian solos. */
	const FINALES = array( 'ACEPTADO', 'ACEPTADO_CON_OBS', 'RECHAZADO', 'ANULADO', 'VENCIDO' );

	public static function registrar() {
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'al_completarse' ), 5 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'al_procesarse' ), 5 );
		add_action( self::ACCION_EMITIR, array( __CLASS__, 'emitir' ), 10, 2 );
		add_action( self::ACCION_ESTADO, array( __CLASS__, 'actualizar_estado' ), 10, 2 );

		add_action( 'add_meta_boxes', array( __CLASS__, 'caja' ) );
		add_action( 'woocommerce_process_shop_order_meta', array( __CLASS__, 'guardar_caja' ) );

		add_action( 'woocommerce_order_details_after_order_table', array( __CLASS__, 'para_el_cliente' ) );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'en_el_correo' ), 10, 3 );
	}

	public static function al_completarse( $order_id ) {
		if ( 'completed' === NexoCPE_Plugin::ajuste( 'disparador', 'completed' ) ) {
			self::emitir( $order_id, 1 );
		}
	}

	public static function al_procesarse( $order_id ) {
		if ( 'processing' === NexoCPE_Plugin::ajuste( 'disparador', 'completed' ) ) {
			self::emitir( $order_id, 1 );
		}
	}

	/** Emite el comprobante del pedido, si no lo tiene ya. */
	public static function emitir( $order_id, $intento = 1 ) {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order || '' !== (string) $order->get_meta( '_nexocpe_id' ) ) {
			return;
		}

		$cliente = NexoCPE_Plugin::cliente();

		if ( ! $cliente->configurado() ) {
			self::fallo( $order, 'Falta configurar Comprobia (WooCommerce → Ajustes → Integración).' );

			return;
		}

		try {
			$cuerpo = NexoCPE_Mapeador::cuerpo( self::datos( $order ), NexoCPE_Plugin::ajustes() );
		} catch ( NexoCPE_Pedido_No_Emitible $e ) {
			self::fallo( $order, $e->getMessage() );

			return;
		}

		$r = $cliente->emitir( $cuerpo, self::clave_idempotencia( $order ) );

		if ( 202 === $r['estado'] && isset( $r['cuerpo']['id'] ) ) {
			self::anotar_emitido( $order, $r['cuerpo'] );

			return;
		}

		// Sin respuesta, NexoCPE caído, demasiadas peticiones, o la misma clave
		// todavía en curso: se reintenta con la MISMA clave.
		$transitorio = 0 === $r['estado'] || $r['estado'] >= 500 || 429 === $r['estado']
			|| ( 409 === $r['estado'] && isset( $r['cuerpo']['code'] ) && 'IDEMPOTENCY_CONFLICT' === $r['cuerpo']['code'] );

		if ( $transitorio && $intento < self::MAX_INTENTOS && function_exists( 'as_schedule_single_action' ) ) {
			$esperas = array( 60, 300, 900, 2700, 8100 ); // 1, 5, 15, 45 y 135 minutos
			$espera  = $esperas[ min( $intento, count( $esperas ) ) - 1 ];
			as_schedule_single_action( time() + $espera, self::ACCION_EMITIR, array( $order->get_id(), $intento + 1 ), self::GRUPO );
			self::fallo( $order, $r['error'] . ' Se reintentará solo.', false );

			return;
		}

		self::fallo( $order, (string) $r['error'] );
	}

	/** Pregunta a NexoCPE por el estado ante SUNAT (una boleta se acepta con el resumen de la madrugada). */
	public static function actualizar_estado( $order_id, $vez = 1 ) {
		$order = wc_get_order( $order_id );
		$id    = $order instanceof WC_Order ? (string) $order->get_meta( '_nexocpe_id' ) : '';

		if ( '' === $id ) {
			return;
		}

		$r = NexoCPE_Plugin::cliente()->comprobante( $id );

		if ( 200 === $r['estado'] ) {
			$datos = isset( $r['cuerpo']['data'] ) && is_array( $r['cuerpo']['data'] ) ? $r['cuerpo']['data'] : $r['cuerpo'];

			if ( isset( $datos['estado'] ) ) {
				$order->update_meta_data( '_nexocpe_estado', (string) $datos['estado'] );
				$order->save();
			}

			if ( isset( $datos['estado'] ) && in_array( $datos['estado'], self::FINALES, true ) ) {
				return;
			}
		}

		if ( $vez < self::MAX_CONSULTAS && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 3 * HOUR_IN_SECONDS, self::ACCION_ESTADO, array( $order->get_id(), $vez + 1 ), self::GRUPO );
		}
	}

	/**
	 * El pedido como lo pide el mapeador.
	 *
	 * @return array
	 */
	public static function datos( WC_Order $order ) {
		$lineas = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$producto = $item->get_product();
			$sku      = $producto ? (string) $producto->get_sku() : '';

			$lineas[] = array(
				'nombre'   => $item->get_name(),
				'sku'      => '' !== $sku ? $sku : 'WC-' . $item->get_product_id(),
				'cantidad' => (string) $item->get_quantity(),
				'total'    => (string) $item->get_total(),
				'impuesto' => (string) $item->get_total_tax(),
				'servicio' => $producto ? $producto->is_virtual() : false,
			);
		}

		$cargos = array();

		foreach ( $order->get_fees() as $fee ) {
			$cargos[] = array(
				'nombre'   => $fee->get_name(),
				'total'    => (string) $fee->get_total(),
				'impuesto' => (string) $fee->get_total_tax(),
			);
		}

		$direccion = trim( implode( ', ', array_filter( array( $order->get_billing_address_1(), $order->get_billing_city() ) ) ) );

		return array(
			'id'      => $order->get_order_number(),
			'moneda'  => $order->get_currency(),
			// La fecha de HOY en Lima, no la del servidor ni la UTC: desde las
			// 19:00 de Lima, la UTC ya es mañana y NexoCPE la rechaza.
			'fecha'   => ( new DateTime( 'now', new DateTimeZone( 'America/Lima' ) ) )->format( 'Y-m-d' ),
			'lineas'  => $lineas,
			'envio'   => array(
				'nombre'   => $order->get_shipping_method(),
				'total'    => (string) $order->get_shipping_total(),
				'impuesto' => (string) $order->get_shipping_tax(),
			),
			'cargos'  => $cargos,
			'cliente' => array(
				'ruc'          => 'factura' === NexoCPE_Checkout::dato( $order, 'comprobante' ) ? NexoCPE_Checkout::dato( $order, 'documento' ) : '',
				'dni'          => 'factura' === NexoCPE_Checkout::dato( $order, 'comprobante' ) ? '' : NexoCPE_Checkout::dato( $order, 'documento' ),
				'razon_social' => NexoCPE_Checkout::dato( $order, 'razon_social' ),
				'nombre'       => trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ),
				'direccion'    => $direccion,
				'email'        => $order->get_billing_email(),
			),
		);
	}

	/** Fija por pedido y por tienda: dos tiendas con el mismo n.º de pedido no chocan. */
	public static function clave_idempotencia( WC_Order $order ) {
		return 'wc-' . substr( md5( home_url() ), 0, 10 ) . '-' . $order->get_id();
	}

	private static function anotar_emitido( WC_Order $order, array $c ) {
		$order->update_meta_data( '_nexocpe_id', (string) $c['id'] );
		$order->update_meta_data( '_nexocpe_numero', (string) ( isset( $c['numero_completo'] ) ? $c['numero_completo'] : '' ) );
		$order->update_meta_data( '_nexocpe_tipo', (string) ( isset( $c['tipo_documento'] ) ? $c['tipo_documento'] : '' ) );
		$order->update_meta_data( '_nexocpe_estado', (string) ( isset( $c['estado'] ) ? $c['estado'] : '' ) );
		$order->update_meta_data( '_nexocpe_consulta', (string) ( isset( $c['consulta_publica'] ) ? $c['consulta_publica'] : '' ) );
		$order->update_meta_data( '_nexocpe_importe', (string) ( isset( $c['totales']['importe_total'] ) ? $c['totales']['importe_total'] : '' ) );
		$order->delete_meta_data( '_nexocpe_error' );
		$order->save();

		$order->add_order_note( sprintf( '%s %s emitida en Comprobia.', '01' === $c['tipo_documento'] ? 'Factura' : 'Boleta', $c['numero_completo'] ) );

		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + 15 * MINUTE_IN_SECONDS, self::ACCION_ESTADO, array( $order->get_id(), 1 ), self::GRUPO );
		}
	}

	private static function fallo( WC_Order $order, $mensaje, $nota = true ) {
		$order->update_meta_data( '_nexocpe_error', $mensaje );
		$order->save();

		if ( $nota ) {
			$order->add_order_note( 'Comprobia: no se emitió el comprobante. ' . $mensaje );
		}
	}

	// ── La caja del pedido en el admin ──────────────────────────────────────

	public static function caja() {
		$pantalla = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

		add_meta_box( 'nexocpe', 'Comprobante electrónico', array( __CLASS__, 'pintar_caja' ), $pantalla, 'side', 'high' );
	}

	/** @param WP_Post|WC_Order $objeto */
	public static function pintar_caja( $objeto ) {
		$order = $objeto instanceof WC_Order ? $objeto : wc_get_order( $objeto->ID );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		wp_nonce_field( 'nexocpe_caja', 'nexocpe_nonce' );

		$numero = (string) $order->get_meta( '_nexocpe_numero' );

		if ( '' !== $numero ) {
			$consulta = (string) $order->get_meta( '_nexocpe_consulta' );
			echo '<p><strong>' . esc_html( $numero ) . '</strong><br>Estado: ' . esc_html( (string) $order->get_meta( '_nexocpe_estado' ) ) . '</p>';

			if ( '' !== $consulta ) {
				echo '<p><a href="' . esc_url( $consulta ) . '" target="_blank" rel="noopener">Ver el comprobante</a></p>';
			}

			$importe = (string) $order->get_meta( '_nexocpe_importe' );

			if ( '' !== $importe && abs( (float) $importe - (float) $order->get_total() ) >= 0.01 ) {
				echo '<p style="color:#b32d2e">El comprobante suma ' . esc_html( $importe ) . ' y el pedido ' . esc_html( (string) $order->get_total() ) . ': revisa los impuestos de la tienda.</p>';
			}

			echo '<button type="submit" class="button" name="nexocpe_accion" value="estado">Actualizar estado</button>';

			return;
		}

		$error = (string) $order->get_meta( '_nexocpe_error' );

		if ( '' !== $error ) {
			echo '<p style="color:#b32d2e">' . esc_html( $error ) . '</p>';
		}

		$tipo = NexoCPE_Checkout::dato( $order, 'comprobante' );
		?>
		<p>
			<label for="nexocpe_comprobante">Comprobante</label><br>
			<select name="nexocpe_comprobante" id="nexocpe_comprobante" style="width:100%">
				<option value="boleta" <?php selected( 'factura' !== $tipo ); ?>>Boleta</option>
				<option value="factura" <?php selected( 'factura' === $tipo ); ?>>Factura</option>
			</select>
		</p>
		<p>
			<label for="nexocpe_documento">DNI o RUC</label><br>
			<input type="text" name="nexocpe_documento" id="nexocpe_documento" value="<?php echo esc_attr( NexoCPE_Checkout::dato( $order, 'documento' ) ); ?>" style="width:100%">
		</p>
		<p>
			<label for="nexocpe_razon_social">Razón social (factura)</label><br>
			<input type="text" name="nexocpe_razon_social" id="nexocpe_razon_social" value="<?php echo esc_attr( NexoCPE_Checkout::dato( $order, 'razon_social' ) ); ?>" style="width:100%">
		</p>
		<button type="submit" class="button button-primary" name="nexocpe_accion" value="emitir">Guardar y emitir</button>
		<?php
	}

	public static function guardar_caja( $order_id ) {
		if ( ! isset( $_POST['nexocpe_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nexocpe_nonce'] ) ), 'nexocpe_caja' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$accion = isset( $_POST['nexocpe_accion'] ) ? sanitize_key( wp_unslash( $_POST['nexocpe_accion'] ) ) : '';

		if ( 'estado' === $accion ) {
			self::actualizar_estado( $order->get_id(), self::MAX_CONSULTAS );

			return;
		}

		if ( '' !== (string) $order->get_meta( '_nexocpe_id' ) ) {
			return;
		}

		foreach ( array( 'comprobante', 'documento', 'razon_social' ) as $campo ) {
			if ( isset( $_POST[ 'nexocpe_' . $campo ] ) ) {
				$order->update_meta_data( '_nexocpe_' . $campo, sanitize_text_field( wp_unslash( $_POST[ 'nexocpe_' . $campo ] ) ) );
			}
		}

		$order->save();

		if ( 'emitir' === $accion ) {
			self::emitir( $order->get_id(), 1 );
		}
	}

	// ── Lo que ve el cliente ────────────────────────────────────────────────

	public static function para_el_cliente( $order ) {
		$linea = $order instanceof WC_Order ? self::enlace( $order ) : '';

		if ( '' !== $linea ) {
			echo '<section class="nexocpe-comprobante"><h2>Comprobante electrónico</h2><p>' . wp_kses_post( $linea ) . '</p></section>';
		}
	}

	public static function en_el_correo( $order, $para_admin, $texto_plano ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$numero   = (string) $order->get_meta( '_nexocpe_numero' );
		$consulta = (string) $order->get_meta( '_nexocpe_consulta' );

		if ( '' === $numero || '' === $consulta ) {
			return;
		}

		if ( $texto_plano ) {
			echo "\nComprobante electrónico: " . esc_html( $numero ) . "\n" . esc_url_raw( $consulta ) . "\n";

			return;
		}

		echo '<p>' . wp_kses_post( self::enlace( $order ) ) . '</p>';
	}

	private static function enlace( WC_Order $order ) {
		$numero   = (string) $order->get_meta( '_nexocpe_numero' );
		$consulta = (string) $order->get_meta( '_nexocpe_consulta' );

		if ( '' === $numero || '' === $consulta ) {
			return '';
		}

		$tipo = '01' === (string) $order->get_meta( '_nexocpe_tipo' ) ? 'Factura' : 'Boleta';

		return sprintf( '%s electrónica %s: <a href="%s" target="_blank" rel="noopener">ver y descargar</a>', esc_html( $tipo ), esc_html( $numero ), esc_url( $consulta ) );
	}
}
