#!/bin/sh
# Deja el WordPress de pruebas listo: WooCommerce en soles, el plugin activo y
# dos productos. Idempotente: se puede correr otra vez.
set -eu

cd /var/www/html

until wp db check >/dev/null 2>&1; do sleep 2; done

if ! wp core is-installed 2>/dev/null; then
  wp core install --url=http://localhost:8092 --title='Tienda de pruebas NexoCPE' \
    --admin_user=admin --admin_password="${WP_ADMIN_PASSWORD:-nexocpe-pruebas}" \
    --admin_email=admin@tienda.test --skip-email
fi

wp plugin is-installed woocommerce || wp plugin install woocommerce
wp plugin activate woocommerce nexocpe-woocommerce

wp option update woocommerce_currency PEN
wp option update woocommerce_default_country PE:LIM
wp option update woocommerce_calc_taxes no
wp option update woocommerce_prices_include_tax yes

if [ -z "$(wp wc product list --user=admin --sku=POLO-N --field=id 2>/dev/null)" ]; then
  wp wc product create --user=admin --name='Polo negro' --sku=POLO-N --regular_price=59.00
  wp wc product create --user=admin --name='Laptop' --sku=LAP-1 --regular_price=2500.00
fi

echo "Listo: http://localhost:8092/wp-admin"
