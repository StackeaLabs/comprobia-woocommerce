FROM wordpress:php8.3-apache

# El plugin dentro de la imagen; el entrypoint lo copia al volumen la primera vez.
COPY --chown=www-data:www-data nexocpe-woocommerce.php readme.txt /usr/src/wordpress/wp-content/plugins/nexocpe-woocommerce/
COPY --chown=www-data:www-data includes /usr/src/wordpress/wp-content/plugins/nexocpe-woocommerce/includes
