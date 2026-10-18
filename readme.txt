=== Comprobia para WooCommerce ===
Contributors: vllerena
Tags: facturacion electronica, sunat, peru, boleta, factura
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.1.2
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
  negativo, una boleta de más de S/ 700 sin DNI) no lo adivina: lo dice en el
  pedido y se corrige desde la caja «Comprobante electrónico».

Necesitas una cuenta de Comprobia. Pruébalo gratis con un sandbox, que no llega a
SUNAT: https://comprobia.stackealabs.com/desarrolladores/sandbox

== Installation ==

1. Sube el plugin y actívalo (necesita WooCommerce).
2. WooCommerce → Ajustes → Integración → Comprobia: la URL, el RUC del emisor, la
   clave de API y las series. Al guardar se prueba la conexión.
3. Elige cuándo emitir: al completarse el pedido (recomendado), al pagarse, o
   sólo a mano.

== Changelog ==

= 0.1.2 =
* El correo en texto plano ya no lleva entidades HTML (&amp;) en el número del
  comprobante.

= 0.1.1 =
* La marca Comprobia y su dominio, comprobia.stackealabs.com.

= 0.1.0 =
* Primera versión: boleta y factura al contado, checkout clásico y de bloques,
  reintentos, estado ante SUNAT y enlace al comprobante para el cliente.
