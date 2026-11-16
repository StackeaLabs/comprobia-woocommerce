# Comprobia para WooCommerce

Plugin de WordPress que emite la boleta o la factura electrónica de cada pedido
de WooCommerce con la API v1 de [Comprobia](https://comprobia.stackealabs.com).
Bloque C5c del backlog de Comprobia.

## Cómo funciona

```
pedido → Completado ─(prioridad 5, antes del correo)─→ NexoCPE_Mapeador → POST /api/v1/{ruc}/comprobantes
                                                                              │ 202
            correo «pedido completado» con el enlace ←── meta del pedido ←────┘
            sin respuesta / 5xx / 429 → Action Scheduler reintenta (1, 5, 15, 45, 135 min), misma Idempotency-Key
            422 → nota en el pedido; se corrige en la caja «Comprobante electrónico» y «Guardar y emitir»
```

| Pieza | Qué hace |
|---|---|
| `includes/class-nexocpe-mapeador.php` | **Puro** (sin WordPress): pedido → JSON de la v1. Precio con IGV = (total + impuesto) / cantidad, en enteros con 10 decimales |
| `includes/class-nexocpe-cliente.php` | HTTP con `wp_remote_*`, sin dependencias |
| `includes/class-nexocpe-pedidos.php` | Ganchos de estado, reintentos, estado ante SUNAT, caja del admin, enlace para el cliente |
| `includes/class-nexocpe-checkout.php` | «¿Boleta o factura?» en el checkout clásico y en el de bloques |
| `includes/class-nexocpe-integracion.php` | Ajustes (WooCommerce → Ajustes → Integración); la clave no se vuelve a pintar |

Decisiones que no se cambian a la ligera:

- **La fecha de emisión es la de hoy en Lima**, nunca la UTC del servidor: desde
  las 19:00 de Lima la UTC ya es mañana y Comprobia la rechaza (RN-G4).
- **Lo que no sabe emitir bien no lo adivina** (líneas gratis, cargos negativos,
  más de 3 decimales en la cantidad, boleta > S/ 700 sin DNI): se detiene con un
  mensaje. Un comprobante distinto de lo cobrado es peor que ninguno.
- **Una Idempotency-Key fija por pedido y tienda**: ningún reintento duplica.
- Compatible con HPOS (pedidos en tablas propias) y con el checkout de bloques.

## Pruebas

```bash
php tests/mapeador-test.php
```

De punta a punta, con un WordPress local en Docker (volúmenes con nombre, sin
montar la carpeta del proyecto):

```bash
docker compose -f docker/docker-compose.yml up -d --build
docker compose -f docker/docker-compose.yml run --rm -T cli sh -s < docker/preparar.sh
```

## Empaquetar

```bash
git archive --format=zip --prefix=comprobia-woocommerce/ -o comprobia-woocommerce.zip HEAD
```

`.gitattributes` deja fuera `tests/` y `docker/`.

## Lo que lleva «nexocpe» y NO se renombra

Desde la 0.1.5 el slug es `comprobia-woocommerce`, pero lo que ya está guardado
en la base de una tienda conserva su nombre: los ajustes
(`woocommerce_nexocpe_settings`, id de integración `nexocpe`), los meta de los
pedidos (`_nexocpe_*`) y los campos del checkout (`billing_nexocpe_*`,
`nexocpe/…`). Renombrarlos dejaría a una tienda sin su clave de API y sin el
comprobante de los pedidos ya emitidos. Las clases `NexoCPE_*` son internas y
tampoco se tocan.

Licencia GPL-2.0-or-later, como WordPress.
