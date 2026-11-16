FROM wordpress:php8.3-apache

# El plugin dentro de la imagen; el entrypoint lo copia al volumen la primera vez.
COPY --chown=www-data:www-data comprobia-woocommerce.php readme.txt /usr/src/wordpress/wp-content/plugins/comprobia-woocommerce/
COPY --chown=www-data:www-data includes /usr/src/wordpress/wp-content/plugins/comprobia-woocommerce/includes
