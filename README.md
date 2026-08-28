# Flexible Product Customizer for WooCommerce

## ZIP instalable

No uses el ZIP automatico de GitHub (`Code > Download ZIP`) para instalar en WordPress. Ese archivo es el codigo fuente del proyecto y WordPress puede dejar el plugin en una ruta incorrecta.

Para instalar o actualizar desde WordPress usa este archivo del repositorio:

`flexible-product-customizer-1.6.5-wordpress.zip`

Ese ZIP contiene la carpeta exacta:

`flexible-product-customizer/flexible-product-customizer.php`

Flujo recomendado:

1. En WordPress ve a `Plugins > Añadir plugin > Subir plugin`.
2. Sube `flexible-product-customizer-1.6.5-wordpress.zip`.
3. Cuando WordPress pregunte, reemplaza la version instalada.
4. Activa el plugin si no queda activo automaticamente.

Si WordPress quedo con una referencia rota de un intento anterior, elimina solo la carpeta incorrecta del plugin desde FTP/File Manager y vuelve a subir el ZIP instalable. La carpeta correcta debe ser `wp-content/plugins/flexible-product-customizer/`.

## Desarrollo

El codigo fuente del plugin vive en `flexible-product-customizer/`. Las pruebas y herramientas de desarrollo viven en la raiz del repositorio.