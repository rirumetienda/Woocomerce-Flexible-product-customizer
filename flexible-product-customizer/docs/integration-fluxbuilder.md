# Integración FluxBuilder y WebView

El editor funciona como una página del mismo WordPress dentro de WebView. La app no necesita reproducir el editor: abre la URL, recibe eventos y agrega el token guardado al carrito mediante Store API.

## Opción directa

Consulta la configuración pública:

```http
GET /wp-json/fpcw/v1/products/123/configuration
```

La respuesta incluye `configuration` y `editor_url`. Para productos variables, la app debe seleccionar la variación en la página o usar un puente firmado.

`configuration.schema_version` es `6`. `configuration.product_type` vale `flat` o `cylindrical`. `configuration.surfaces` contiene la geometría central de cada cara; en productos planos, `shape` puede ser `rect` o `circle` para recortar superficies redondas. En plantillas cilíndricas, `print_area` define el tamaño del PNG plano de producción y `projection` incluye `wrap_angle`, `top_scale`, `bottom_scale`, `shading`, `frame`, `mask_image_url` y `overlay_image_url`. `frame` usa coordenadas del canvas del mockup y no modifica las coordenadas del diseño. Cada elemento de `configuration.colors` representa un atributo de color con un mapa `surfaces`. Cada asignación indica `enabled`, `image_id` e `image_url`; una app debe ofrecer únicamente las superficies activas del color seleccionado. `font_faces` expone las fuentes personalizadas elegidas por la plantilla.

## Puente firmado

Un backend de confianza autenticado con WordPress Application Passwords puede solicitar una URL válida por 15 minutos:

```http
POST /wp-json/fpcw/v1/bridge-tokens
Authorization: Basic <application-password>
Content-Type: application/json

{
  "product_id": 123,
  "variation_id": 456,
  "external_reference": "flux-cart-line-abc"
}
```

La respuesta contiene `editor_url`, `token` y `expires_at`. No se deben incluir credenciales administrativas dentro de la aplicación móvil; esta llamada corresponde al backend.

## Eventos WebView

En React Native/FluxBuilder el plugin usa `window.ReactNativeWebView.postMessage(JSON.stringify(message))`. También emite eventos DOM `fpcw:<evento>` y `postMessage` al parent del mismo origen.

Eventos disponibles:

- `ready`: editor cargado.
- `opened`: sesión temporal abierta.
- `saved`: diseño guardado; entrega `token`, producto, variación, caducidad y previews.
- `closed`: modal cerrado.
- `error`: error validado para mostrar al usuario.

Ejemplo de mensaje guardado:

```json
{
  "source": "flexible-product-customizer",
  "event": "saved",
  "payload": {
	"token": "64-caracteres-hexadecimales",
	"proof": "prueba-firmada-de-64-caracteres",
    "product_id": 123,
    "variation_id": 456,
    "expires_at": "2026-08-31T18:30:00+00:00",
    "previews": [{ "surface_id": "front", "url": "https://store.test/...png" }]
  },
  "bridge": { "external_reference": "flux-cart-line-abc" }
}
```

## Comandos hacia el editor

Envía un objeto con `source: flexible-product-customizer-host` desde el mismo origen:

```js
window.postMessage({
  source: 'flexible-product-customizer-host',
  command: 'setColor',
  color_id: 'black'
}, window.location.origin);
```

Comandos: `open`, `save`, `setColor`, `setSurface` y `setView`. `setSurface` solo acepta una superficie habilitada para el color activo. `setView` acepta `edit` o `wrapped`; el segundo solo tiene efecto en plantillas cilíndricas.

La página también expone `window.FlexibleProductCustomizer` con los métodos `open()`, `close()`, `save()`, `setColor(id)`, `setSurface(id)` y `setView(mode)`.

## Agregar a Store API

Después de recibir `saved`, agrega el producto usando la extensión del carrito:

```json
{
  "id": 456,
  "quantity": 1,
	"extensions": {
	  "flexible-product-customizer": {
	    "token": "64-caracteres-hexadecimales",
	    "proof": "prueba-firmada-de-64-caracteres"
	  }
  }
}
```

La prueba firmada permite agregar el diseño desde un cliente HTTP que no comparte la cookie del WebView. Los artículos devueltos por Store API incluyen `extensions.flexible-product-customizer` con `token`, `expires_at` y `previews`. La imagen principal del artículo se reemplaza por la primera vista previa.

Referencias del contrato oficial: [Cart API](https://developer.woocommerce.com/docs/apis/store-api/resources-endpoints/cart/), [extensión de CartItemSchema](https://developer.woocommerce.com/docs/apis/store-api/extending-store-api/available-endpoints-to-extend) y [filtro de imagen de Cart/Checkout Blocks](https://developer.woocommerce.com/2025/01/16/new-woocommerce-blocks-cart-item-image-filter/).

## Rutas de sesión usadas por WebView

- `POST /wp-json/fpcw/v1/sessions`: crea la sesión.
- `GET /wp-json/fpcw/v1/sessions/{token}`: recupera un borrador propio.
- `POST /wp-json/fpcw/v1/sessions/{token}/files`: sube un original.
- `POST /wp-json/fpcw/v1/sessions/{token}/renders`: sube un render generado por cara.
- `POST /wp-json/fpcw/v1/sessions/{token}/save`: valida y activa el documento final.

Estas rutas requieren el nonce de la página y la cookie propietaria; no son una API administrativa abierta.
