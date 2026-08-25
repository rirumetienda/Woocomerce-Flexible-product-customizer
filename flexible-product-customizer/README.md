# Flexible Product Customizer for WooCommerce

Plugin genérico para crear productos personalizables mediante plantillas reutilizables. Incluye editor visual, archivos de producción, vistas previas en carrito/pedido, caducidad automática y un contrato REST/WebView para aplicaciones móviles.

## Requisitos

- WordPress 6.5 o posterior.
- WooCommerce 9.6 o posterior.
- PHP 7.4 o posterior, con `fileinfo` y GD o Imagick disponibles para el manejo normal de imágenes de WordPress.
- `upload_max_filesize` de al menos `10M` y `post_max_size` de al menos `12M`.
- HTTPS recomendado.

## Instalación

1. Instala el ZIP desde **Plugins > Añadir plugin > Subir plugin** y actívalo.
2. Ve a **WooCommerce > Customization** y crea una plantilla.
3. Elige primero si la plantilla es plana o cilíndrica, agrega atributos de color y caras, define el canvas en píxeles y asigna a cada color su imagen para cada superficie.
4. En superficies planas, mueve el área editable sobre el producto. En superficies cilíndricas, define por separado el mapa plano de impresión y el marco donde se proyectará sobre el mockup.
5. Publica la plantilla.
6. Edita un producto y abre **Datos del producto > Customization**.
7. Activa **Customizable product**, selecciona la plantilla y guarda el producto.

El idioma del plugin se elige en **WooCommerce > Customizer settings**. El panel, el editor y los mensajes del cliente están disponibles en español e inglés.

En cada producto se muestran directamente los atributos y superficies de la plantilla seleccionada. Puedes desactivar superficies concretas y asignar un incremento a cada una; el importe solo se suma cuando el cliente utiliza esa superficie. El botón de carrito permanece inactivo hasta guardar un diseño y, en productos variables, hasta que el diseño coincida con la variación elegida.

El editor del cliente se abre en la capa superior nativa del navegador para que ningún módulo del tema pueda cubrirlo. Su interfaz parte de móvil, respeta las áreas seguras del dispositivo y se convierte en un espacio de trabajo con barra lateral en escritorio. Los elementos pueden sobresalir del área editable y se recortan visualmente al renderizar, lo que permite cubrir áreas horizontales con imágenes cuadradas sin deformarlas.

Las plantillas cilíndricas permiten configurar el ángulo envolvente, el diámetro superior e inferior, la intensidad de sombreado, un marco de proyección independiente y vistas de preview por ángulo para cada superficie. También admiten una máscara alfa y una capa transparente de iluminación opcionales. En escritorio, el cliente ve el mapa imprimible y la proyección WebGL en vivo al mismo tiempo; en móvil alterna entre ambas vistas. Las previews guardadas usan las vistas configuradas, mientras que el PNG de producción permanece plano y transparente.

Al activarse, el plugin instala una plantilla publicada llamada **Sample: Cylindrical mug** con un mockup genérico sin marca. Puede duplicarse o utilizarse como referencia. Dentro de cualquier plantilla, **Duplicate surface** copia geometría, límites e imágenes de todos los atributos a una superficie nueva e independiente.

Las fuentes globales se administran desde la pestaña **Settings**. Admite WOFF2, WOFF, TTF y OTF; las fuentes disponibles aparecen como pills en las plantillas y vienen seleccionadas en las plantillas nuevas.

Las tallas, materiales y demás opciones comerciales continúan siendo atributos o variaciones nativas de WooCommerce. La opción **Variation color attribute** permite sincronizar un color de plantilla con un atributo como `pa_color`.

## Flujo de datos

- La primera apertura crea una sesión temporal con un token aleatorio de 256 bits.
- El vencimiento se fija a siete días y no se extiende al editar o guardar.
- PNG, JPEG y WebP están permitidos. Cada original se valida en servidor: máximo 10 MB y 10,000 x 10,000 px.
- Para imágenes mayores de 2,400 px se intenta generar una copia de trabajo liviana; el original permanece intacto para la orden.
- Los originales se guardan en un directorio protegido y con nombre aleatorio.
- Cada cara genera una o más vistas previas optimizadas y un PNG transparente de producción.
- Al crear el pedido, los archivos se trasladan al espacio permanente del pedido, el vencimiento se anula y la plantilla queda congelada dentro del artículo.
- Una tarea horaria elimina sesiones vencidas; el artículo desaparece del carrito en su siguiente carga si ya expiró.

## Seguridad y privacidad

- Las operaciones de escritura requieren nonce REST y propiedad de la sesión.
- Los originales y PNG de producción se entregan mediante URLs firmadas y comprobación de propietario/pedido.
- Las imágenes base pasan por un proxy del mismo origen para evitar exportaciones Canvas contaminadas por CDN.
- Hay límites por archivo, dimensiones, cuota temporal de 50 MB, máximo de 30 archivos y 20 sesiones abiertas por visitante.
- El plugin conserva sus datos al desactivarse o desinstalarse. Para una eliminación total deliberada, define `FPCW_REMOVE_DATA_ON_UNINSTALL` como `true` antes de desinstalar.

## Integración móvil

El contrato de FluxBuilder/WebView, los eventos JavaScript y las rutas REST están documentados en [docs/integration-fluxbuilder.md](docs/integration-fluxbuilder.md).

## Compatibilidad

- Usa CRUD de WooCommerce y declara compatibilidad con HPOS.
- Integra carrito y checkout clásicos.
- Expone vista previa y datos en Store API para Cart/Checkout Blocks y clientes móviles. El filtro de imagen de los bloques requiere WooCommerce 9.6 o posterior.
- No guarda imágenes en base64 ni duplica el documento de diseño durante el flujo temporal.
- Three.js se distribuye localmente bajo su licencia MIT; el plugin no carga motores gráficos desde CDN.
