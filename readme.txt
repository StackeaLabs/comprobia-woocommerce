=== Comprobia para WooCommerce ===
Contributors: vllerena
Tags: facturacion electronica, sunat, peru, boleta, factura
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Emite la boleta o la factura electrónica de cada pedido ante SUNAT con Comprobia.

== Description ==

Al completarse un pedido, el plugin emite su comprobante electrónico con la API
de Comprobia y lo deja en el pedido, en «Mi cuenta» del cliente y en el correo de
pedido completado, con el enlace para verlo y descargarlo.

* Boleta o factura: el cliente elige en el checkout (clásico o de bloques) y
  escribe su DNI o su RUC. El RUC se comprueba antes de cerrar la compra.
* Precios con IGV incluido o con los impuestos de WooCommerce: el plugin manda
  lo que pagó el cliente, con los cupones aplicados, y Comprobia calcula el IGV.
* Nunca emite dos veces: la clave de idempotencia es fija por pedido, y si
  Comprobia no responde se reintenta sola.
* Lo que no sabe emitir bien (una línea gratis, un descuento como cargo
  negativo, una boleta de más de S/ 700 sin DNI, una boleta en dólares sin DNI)
  no lo adivina: lo dice en el pedido y se corrige desde la caja «Comprobante
  electrónico».
* Los reembolsos NO generan la nota de crédito: el plugin sólo emite al
  completarse el pedido. Un reembolso se corrige en el panel de Comprobia
  («Nota de crédito» en la ficha del comprobante) o por la API.
* Si el pedido trae correo, va en el comprobante y Comprobia le manda al cliente
  el PDF, el XML y la CDR cuando SUNAT acepta.

Necesitas una cuenta de Comprobia. Pruébalo gratis con un sandbox, que no llega a
SUNAT: https://comprobia.stackealabs.com/desarrolladores/sandbox

== Installation ==

1. Sube el plugin y actívalo (necesita WooCommerce).
2. WooCommerce → Ajustes → Integración → Comprobia: la URL, el RUC del emisor, la
   clave de API (cmp_…) y las series. Las series por defecto son F001 y B001:
   cámbialas por las que tu emisor tiene en Comprobia. Al guardar se prueba la
   conexión.
3. Elige cuándo emitir: al completarse el pedido (recomendado), al pagarse, o
   sólo a mano.

== Changelog ==

= 0.1.5 =
* El plugin se llama ahora comprobia-woocommerce: carpeta, archivo principal y
  text domain. Si tenías instalada la versión anterior (carpeta
  nexocpe-woocommerce), desactívala y bórrala antes de subir ésta: los ajustes y
  los comprobantes ya guardados en los pedidos se conservan, porque sus claves
  no cambian.

= 0.1.4 =
* La versión que el plugin manda en su User-Agent es la del plugin (decía
  0.1.2).
* El aviso de moneda no admitida dice Comprobia, no la marca anterior.

= 0.1.3 =
* Una boleta en dólares sin DNI se detiene antes de llamar a la API: el tope
  de S/ 700 se mide también convertido.
* La clave de API se pide con el prefijo cmp_ (las nx_ siguen valiendo).
* El readme dice qué pasa con los reembolsos y con el correo del cliente.

= 0.1.2 =
* El correo en texto plano ya no lleva entidades HTML (&amp;) en el número del
  comprobante.

= 0.1.1 =
* La marca Comprobia y su dominio, comprobia.stackealabs.com.

= 0.1.0 =
* Primera versión: boleta y factura al contado, checkout clásico y de bloques,
  reintentos, estado ante SUNAT y enlace al comprobante para el cliente.
