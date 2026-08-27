const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', 'flexible-product-customizer');
const languageDir = path.join(root, 'languages');
const domain = 'flexible-product-customizer';

const es = {
	'A design image is no longer available.': 'Una imagen del diseño ya no está disponible.',
	'Add another customization': 'Añadir otra personalización',
	'Add an image or text before saving the customization.': 'Añade una imagen o texto antes de guardar la personalización.',
	'Add selected fonts': 'Añadir fuentes seleccionadas',
	'A price increment is charged only when the customer adds content to that surface. Use zero for the base surface.': 'El incremento de precio se cobra únicamente cuando el cliente añade contenido a esa superficie. Usa cero para la superficie base.',
	'Add color': 'Añadir color',
	'Add customization template': 'Añadir plantilla de personalización',
	'Add surface': 'Añadir superficie',
	'Add text': 'Añadir texto',
	'Align bottom': 'Alinear abajo',
	'Align left': 'Alinear a la izquierda',
	'Align right': 'Alinear a la derecha',
	'Align top': 'Alinear arriba',
	'An expired customization and its files were removed from your cart.': 'Se eliminó del carrito una personalización caducada junto con sus archivos.',
	'An image could not be loaded.': 'No se pudo cargar una imagen.',
	'Angle label': 'Etiqueta del ángulo',
	'Available fonts': 'Fuentes disponibles',
	'Available color attributes': 'Atributos de color disponibles',
	'Available for this attribute': 'Disponible para este atributo',
	'Available for: %s': 'Disponible para: %s',
	'Available surfaces and price increments': 'Superficies disponibles e incrementos de precio',
	'Available until': 'Disponible hasta',
	'Available in your cart until %s. After that date the design and files are deleted automatically.': 'Disponible en tu carrito hasta el %s. Después de esa fecha, el diseño y los archivos se eliminan automáticamente.',
	'Base image': 'Imagen base',
	'Base image position': 'Posición de la imagen base',
	'Bold': 'Negrita',
	'Bottom diameter (%)': 'Diámetro inferior (%)',
	'Cancel': 'Cancelar',
	'Canvas height (px)': 'Alto del lienzo (px)',
	'Canvas width (px)': 'Ancho del lienzo (px)',
	'Canvas': 'Lienzo',
	'Change alignment': 'Cambiar alineación',
	'Choose': 'Elegir',
	'Choose which template color attributes can be purchased with this product.': 'Elige qué atributos de color de la plantilla se pueden comprar con este producto.',
	'Choose the product options before opening the editor.': 'Elige las opciones del producto antes de abrir el editor.',
	'Choose valid product options before opening the editor.': 'Elige opciones válidas del producto antes de abrir el editor.',
	'Clear': 'Limpiar',
	'Close': 'Cerrar',
	'Close editor': 'Cerrar editor',
	'Color': 'Color',
	'Color attributes': 'Atributos de color',
	'Circle': 'Circulo',
	'Rectangle': 'Rectangulo',
	'Surface shape': 'Forma de superficie',
	'Colors': 'Colores',
	'Controls all plugin interfaces and customer-facing messages.': 'Controla todas las interfaces del plugin y los mensajes que ve el cliente.',
	'Center': 'Centrar',
	'Choose the product type first. Flat products use an editing area over the mockup; cylindrical products use a separate print map and projection frame.': 'Elige primero el tipo de producto. Los productos planos usan un área editable sobre el mockup; los cilíndricos usan un mapa de impresión y un marco de proyección separados.',
	'Choose flat or cylindrical to continue configuring the template.': 'Elige plano o cilíndrico para continuar configurando la plantilla.',
	'Choose whether the product is flat or cylindrical before saving the template.': 'Elige si el producto es plano o cilíndrico antes de guardar la plantilla.',
	'Customer editing area': 'Área editable por el cliente',
	'Customization': 'Personalización',
	'Customization controls': 'Controles de personalización',
	'Customization files could not be attached to the order.': 'Los archivos de personalización no pudieron adjuntarse al pedido.',
	'Customization files were preserved, but could not be moved to their final order directory. Check server file permissions.': 'Los archivos de personalización se conservaron, pero no pudieron moverse al directorio final del pedido. Revisa los permisos de archivos del servidor.',
	'Customization preview': 'Vista previa de la personalización',
	'Customization previews': 'Vistas previas de la personalización',
	'Customization ready': 'Personalización lista',
	'Customization surcharge': 'Incremento por personalización',
	'Customization template': 'Plantilla de personalización',
	'Customization templates': 'Plantillas de personalización',
	'Customization:': 'Personalización:',
	'Curvature shading (%)': 'Sombreado de curvatura (%)',
	'Cylindrical': 'Cilíndrico',
	'Cylindrical projection': 'Proyección cilíndrica',
	'Customize product': 'Personalizar producto',
	'Customize this product before adding it to the cart.': 'Personaliza este producto antes de añadirlo al carrito.',
	'Customizable product': 'Producto personalizable',
	'Customizer settings': 'Ajustes del personalizador',
	'Customizer navigation': 'Navegación del personalizador',
	'Default': 'Predeterminado',
	'Defines product colors, printable areas, fonts, and limits.': 'Define los colores del producto, las áreas imprimibles, las fuentes y los límites.',
	'Design': 'Diseño',
	'Display the visual editor on this product.': 'Muestra el editor visual en este producto.',
	'Drag either box to move it. Drag an edge or corner handle to resize it.': 'Arrastra cualquiera de los cuadros para moverlo. Arrastra un tirador lateral o de esquina para cambiar su tamaño.',
	'Duplicate surface': 'Duplicar superficie',
	'Edit': 'Editar',
	'Edit customization': 'Editar personalización',
	'Edit customization template': 'Editar plantilla de personalización',
	'Editor view': 'Vista del editor',
	'Editing area': 'Área editable',
	'Element limits': 'Límites de elementos',
	'Enabled': 'Activa',
	'File service is unavailable.': 'El servicio de archivos no está disponible.',
	'Flat': 'Plano',
	'Fit': 'Ajustar',
	'Fit to canvas': 'Ajustar al lienzo',
	'Flexible Product Customizer': 'Personalizador flexible de productos',
	'Flexible Product Customizer accepts images up to 10 MB, but this server currently allows only %s. Increase upload_max_filesize and post_max_size to use the full limit.': 'Flexible Product Customizer acepta imágenes de hasta 10 MB, pero este servidor actualmente solo permite %s. Aumenta upload_max_filesize y post_max_size para utilizar el límite completo.',
	'Flexible Product Customizer requires WooCommerce to be installed and active.': 'Flexible Product Customizer requiere que WooCommerce esté instalado y activo.',
	'Font': 'Fuente',
	'Font library': 'Biblioteca de fuentes',
	'Front': 'Frente',
	'Front view': 'Vista frontal',
	'Full wrap': 'Envolvente completa',
	'General': 'General',
	'Height': 'Alto',
	'ID': 'ID',
	'Image': 'Imagen',
	'Image dimensions cannot exceed %1$s x %1$s pixels.': 'Las dimensiones de la imagen no pueden superar %1$s x %1$s píxeles.',
	'Images': 'Imágenes',
	'Images must be no larger than %s MB.': 'Las imágenes no pueden superar los %s MB.',
	'Italic': 'Cursiva',
	'Loading...': 'Cargando...',
	'Lighting overlay': 'Capa de iluminación',
	'Left side': 'Lado izquierdo',
	'Maximum dimensions are 10,000 x 10,000 pixels.': 'Las dimensiones máximas son 10,000 x 10,000 píxeles.',
	'Maximum images': 'Máximo de imágenes',
	'Maximum texts': 'Máximo de textos',
	'Mockup height (px)': 'Alto del mockup (px)',
	'Mockup width (px)': 'Ancho del mockup (px)',
	'Name': 'Nombre',
	'New color': 'Nuevo color',
	'New surface': 'Nueva superficie',
	'No image': 'Sin imagen',
	'No image selected': 'Ninguna imagen seleccionada',
	'Optional WooCommerce attribute slug synchronized with template color variation values.': 'Slug opcional del atributo de WooCommerce sincronizado con los valores de variación de color de la plantilla.',
	'Optional projection layers': 'Capas de proyección opcionales',
	'Outline': 'Delineado',
	'Outline thickness': 'Grosor del delineado',
	'PNG recommended. PNG, JPEG or WebP; maximum 10 MB and 10,000 x 10,000 px.': 'Se recomienda PNG. PNG, JPEG o WebP; máximo 10 MB y 10,000 x 10,000 px.',
	'Plugin language': 'Idioma del plugin',
	'Preview attribute': 'Atributo de vista previa',
	'Preview angles': 'Ángulos de vista previa',
	'Price increment when used': 'Incremento de precio al utilizarla',
	'Printable surfaces': 'Superficies imprimibles',
	'Printable wrap angle (degrees)': 'Ángulo envolvente imprimible (grados)',
	'Print design': 'Diseño de impresión',
	'Print map': 'Mapa de impresión',
	'Print map height (px)': 'Alto del mapa de impresión (px)',
	'Print map width (px)': 'Ancho del mapa de impresión (px)',
	'Product customization': 'Personalización del producto',
	'Product preview': 'Vista previa del producto',
	'Product type': 'Tipo de producto',
	'Production PNG files': 'Archivos PNG de producción',
	'Projection frame': 'Marco de proyección',
	'Projection frame position': 'Posición del marco de proyección',
	'Projection mask': 'Máscara de proyección',
	'Remove': 'Eliminar',
	'Remove surface': 'Eliminar superficie',
	'Remove the item from the cart before changing its product color.': 'Elimina el artículo del carrito antes de cambiar el color del producto.',
	'Remove the item from the cart before changing its product options.': 'Elimina el artículo del carrito antes de cambiar sus opciones de producto.',
	'Remove the selected element?': '¿Eliminar el elemento seleccionado?',
	'Right side': 'Lado derecho',
	'Rotate 90 degrees': 'Girar 90 grados',
	'Rotation (degrees)': 'Rotación (grados)',
	'Save customization': 'Guardar personalización',
	'Sample white mug': 'Taza blanca de muestra',
	'Sample: Cylindrical mug': 'Muestra: Taza cilíndrica',
	'Save the customization again for the selected variation.': 'Guarda de nuevo la personalización para la variación seleccionada.',
	'Save your customization to enable adding this product to the cart.': 'Guarda tu personalización para habilitar la opción de añadir este producto al carrito.',
	'Save the customization before adding this product to the cart.': 'Guarda la personalización antes de añadir este producto al carrito.',
	'Saving...': 'Guardando...',
	'Saving template...': 'Guardando plantilla...',
	'Select a product base image': 'Seleccionar una imagen base del producto',
	'Select font files': 'Seleccionar archivos de fuentes',
	'Select a template': 'Seleccionar una plantilla',
	'Select a template to configure its colors and surfaces.': 'Selecciona una plantilla para configurar sus colores y superficies.',
	'Select all': 'Seleccionar todas',
	'Select an image to upload.': 'Selecciona una imagen para subir.',
	'Settings': 'Ajustes',
	'Shared options for the template editor, product editor, cart, orders, and integrations.': 'Opciones compartidas para el editor de plantillas, el editor de productos, el carrito, los pedidos y las integraciones.',
	'Show': 'Mostrar',
	'Size': 'Tamaño',
	'Source files': 'Archivos originales',
	'Surfaces': 'Superficies',
	'Surface images for this attribute': 'Imágenes de superficie para este atributo',
	'Swatch': 'Muestra',
	'Template': 'Plantilla',
	'Template builder': 'Constructor de plantillas',
	'Template saved.': 'Plantilla guardada.',
	'Templates': 'Plantillas',
	'Text': 'Texto',
	'Text must contain between 1 and 300 characters.': 'El texto debe contener entre 1 y 300 caracteres.',
	'Text options': 'Opciones de texto',
	'Text outline': 'Delineado del texto',
	'Text style': 'Estilo del texto',
	'The customization could not be saved.': 'No se pudo guardar la personalización.',
	'The customization could not be saved. Please try again.': 'No se pudo guardar la personalización. Inténtalo de nuevo.',
	'The customization does not match the selected product options.': 'La personalización no coincide con las opciones seleccionadas del producto.',
	'The customization session could not be created.': 'No se pudo crear la sesión de personalización.',
	'The customization session no longer exists.': 'La sesión de personalización ya no existe.',
	'The design color does not match the selected WooCommerce variation.': 'El color del diseño no coincide con la variación seleccionada de WooCommerce.',
	'The design data is invalid.': 'Los datos del diseño no son válidos.',
	'The generated image could not be attached to the customization.': 'La imagen generada no pudo adjuntarse a la personalización.',
	'The generated image could not be exported.': 'No se pudo exportar la imagen generada.',
	'The generated surface image is invalid.': 'La imagen generada de la superficie no es válida.',
	'The image could not be uploaded.': 'No se pudo subir la imagen.',
	'The image limit for %s was exceeded.': 'Se superó el límite de imágenes de %s.',
	'The image upload did not complete.': 'La carga de la imagen no se completó.',
	'The preview for %s is missing.': 'Falta la vista previa de %s.',
	'The requested file does not exist.': 'El archivo solicitado no existe.',
	'The security token has expired. Refresh the page and try again.': 'El token de seguridad caducó. Actualiza la página e inténtalo de nuevo.',
	'The selected product color is not available.': 'El color de producto seleccionado no está disponible.',
	'The selected product options are invalid.': 'Las opciones seleccionadas del producto no son válidas.',
	'The selected template has no colors.': 'La plantilla seleccionada no tiene colores.',
	'The selected template has no surfaces.': 'La plantilla seleccionada no tiene superficies.',
	'The server could not store the image.': 'El servidor no pudo almacenar la imagen.',
	'The template image is unavailable.': 'La imagen de la plantilla no está disponible.',
	'The surface %s is not available for the selected color.': 'La superficie %s no está disponible para el color seleccionado.',
	'The template could not be identified.': 'No se pudo identificar la plantilla.',
	'The template could not be saved. The page was not submitted to prevent data loss.': 'No se pudo guardar la plantilla. La página no se envió para evitar la pérdida de datos.',
	'The template data is invalid.': 'Los datos de la plantilla no son válidos.',
	'The text limit for %s was exceeded.': 'Se superó el límite de textos de %s.',
	'The uploaded image could not be attached to the customization.': 'La imagen subida no pudo adjuntarse a la personalización.',
	'The wrapped preview is not available in this browser.': 'La vista envolvente no está disponible en este navegador.',
	'This customization has expired. Create a new design.': 'Esta personalización caducó. Crea un diseño nuevo.',
	'This customization has expired. Please create a new one.': 'Esta personalización caducó. Crea una nueva.',
	'This customization has reached its temporary upload quota.': 'Esta personalización alcanzó su cuota temporal de carga.',
	'This product cannot be customized.': 'Este producto no se puede personalizar.',
	'This product is not available for customization.': 'Este producto no está disponible para personalización.',
	'This surface has reached its image limit.': 'Esta superficie alcanzó su límite de imágenes.',
	'This surface has reached its text limit.': 'Esta superficie alcanzó su límite de textos.',
	'Top diameter (%)': 'Diámetro superior (%)',
	'Underline': 'Subrayado',
	'Upload image': 'Subir imagen',
	'Upload fonts': 'Subir fuentes',
	'Upload WOFF2, WOFF, TTF, or OTF files. These fonts become selectable pills in every template and are preselected in new templates.': 'Sube archivos WOFF2, WOFF, TTF u OTF. Estas fuentes se convierten en pills seleccionables en cada plantilla y vienen preseleccionadas en las plantillas nuevas.',
	'Use 0 for a surface included in the product price.': 'Usa 0 para una superficie incluida en el precio del producto.',
	'Use a PNG, JPEG, or WebP image. PNG is recommended for transparency and print quality.': 'Usa una imagen PNG, JPEG o WebP. Se recomienda PNG para transparencias y calidad de impresión.',
	'Use PNG, JPEG, or WebP up to 10 MB.': 'Usa PNG, JPEG o WebP de hasta 10 MB.',
	'Use WOFF2, WOFF, TTF, or OTF font files.': 'Usa archivos de fuente WOFF2, WOFF, TTF u OTF.',
	'Use this image': 'Usar esta imagen',
	'Used surfaces': 'Superficies utilizadas',
	'Variation color attribute': 'Atributo de variación de color',
	'Variation value': 'Valor de variación',
	'Width': 'Ancho',
	'White': 'Blanco',
	'Wrapped preview': 'Vista envolvente',
	'copy': 'copia',
	'extra': 'extra',
	'Wrapped product preview': 'Vista envolvente del producto',
	'X position': 'Posición X',
	'Y position': 'Posición Y',
	'You are not allowed to access this file.': 'No tienes permiso para acceder a este archivo.',
	'You are not allowed to edit this template.': 'No tienes permiso para editar esta plantilla.',
	'You cannot modify this customization.': 'No puedes modificar esta personalización.',
	'You have too many open customizations. Wait for old sessions to expire or complete an existing design.': 'Tienes demasiadas personalizaciones abiertas. Espera a que caduquen las sesiones anteriores o termina un diseño existente.',
	'<p>When customers customize a product, the store temporarily saves uploaded images, entered text, generated previews, product identifiers, and an anonymous browser ownership token. Unordered customization data expires seven days after creation. When an order is created, these files and design data are retained with the order according to the store retention policy.</p>': '<p>Cuando los clientes personalizan un producto, la tienda guarda temporalmente las imágenes subidas, el texto introducido, las vistas previas generadas, los identificadores del producto y un token anónimo de propiedad del navegador. Los datos de personalizaciones sin pedido caducan siete días después de su creación. Cuando se crea un pedido, estos archivos y los datos del diseño se conservan con el pedido según la política de retención de la tienda.</p>',
};

function phpFiles(directory) {
	const result = [];
	for (const name of fs.readdirSync(directory)) {
		const file = path.join(directory, name);
		const stat = fs.statSync(file);
		if (stat.isDirectory()) result.push(...phpFiles(file));
		else if (file.endsWith('.php')) result.push(file);
	}
	return result;
}

function sourceMessages() {
	const messages = new Set();
	const pattern = /(?:^|[^A-Za-z0-9_])(?:[a-z_]*__|[a-z_]*_e)\(\s*'((?:\\.|[^'\\])*)'\s*,\s*'flexible-product-customizer'/gm;
	for (const file of phpFiles(root)) {
		const source = fs.readFileSync(file, 'utf8');
		let match;
		while ((match = pattern.exec(source))) messages.add(match[1].replace(/\\'/g, "'"));
	}
	return [...messages].sort((a, b) => Buffer.compare(Buffer.from(a), Buffer.from(b)));
}

function poEscape(value) {
	return value.replace(/\\/g, '\\\\').replace(/"/g, '\\"').replace(/\n/g, '\\n');
}

function buildPo(messages) {
	const header = [
		'Project-Id-Version: Flexible Product Customizer 1.6.3',
		'Language: es_ES',
		'MIME-Version: 1.0',
		'Content-Type: text/plain; charset=UTF-8',
		'Content-Transfer-Encoding: 8bit',
		'Plural-Forms: nplurals=2; plural=(n != 1);',
	].join('\\n') + '\\n';
	const entries = [`msgid ""\nmsgstr "${poEscape(header)}"`];
	for (const message of messages) entries.push(`msgid "${poEscape(message)}"\nmsgstr "${poEscape(es[message])}"`);
	return entries.join('\n\n') + '\n';
}

function buildMo(messages) {
	const header = [
		'Project-Id-Version: Flexible Product Customizer 1.6.3',
		'Language: es_ES',
		'MIME-Version: 1.0',
		'Content-Type: text/plain; charset=UTF-8',
		'Content-Transfer-Encoding: 8bit',
		'Plural-Forms: nplurals=2; plural=(n != 1);',
	].join('\n') + '\n';
	const pairs = [['', header], ...messages.map((message) => [message, es[message]])];
	const count = pairs.length;
	const originalTableOffset = 28;
	const translationTableOffset = originalTableOffset + count * 8;
	let stringOffset = translationTableOffset + count * 8;
	const originals = pairs.map((pair) => Buffer.from(pair[0], 'utf8'));
	const translations = pairs.map((pair) => Buffer.from(pair[1], 'utf8'));
	const originalData = Buffer.concat(originals.map((buffer) => Buffer.concat([buffer, Buffer.from([0])])));
	const translationStart = stringOffset + originalData.length;
	const translationData = Buffer.concat(translations.map((buffer) => Buffer.concat([buffer, Buffer.from([0])])));
	const output = Buffer.alloc(translationStart + translationData.length);
	output.writeUInt32LE(0x950412de, 0);
	output.writeUInt32LE(0, 4);
	output.writeUInt32LE(count, 8);
	output.writeUInt32LE(originalTableOffset, 12);
	output.writeUInt32LE(translationTableOffset, 16);
	output.writeUInt32LE(0, 20);
	output.writeUInt32LE(0, 24);
	let originalCursor = stringOffset;
	let translationCursor = translationStart;
	for (let index = 0; index < count; index += 1) {
		output.writeUInt32LE(originals[index].length, originalTableOffset + index * 8);
		output.writeUInt32LE(originalCursor, originalTableOffset + index * 8 + 4);
		output.writeUInt32LE(translations[index].length, translationTableOffset + index * 8);
		output.writeUInt32LE(translationCursor, translationTableOffset + index * 8 + 4);
		originalCursor += originals[index].length + 1;
		translationCursor += translations[index].length + 1;
	}
	originalData.copy(output, stringOffset);
	translationData.copy(output, translationStart);
	return output;
}

const messages = sourceMessages();
const missing = messages.filter((message) => !Object.prototype.hasOwnProperty.call(es, message));
const unused = Object.keys(es).filter((message) => !messages.includes(message));
if (missing.length || unused.length) {
	if (missing.length) process.stderr.write(`Missing Spanish translations:\n${missing.map((item) => `- ${item}`).join('\n')}\n`);
	if (unused.length) process.stderr.write(`Unused Spanish translations:\n${unused.map((item) => `- ${item}`).join('\n')}\n`);
	process.exit(1);
}

fs.mkdirSync(languageDir, { recursive: true });
fs.writeFileSync(path.join(languageDir, `${domain}-es_ES.po`), buildPo(messages));
fs.writeFileSync(path.join(languageDir, `${domain}-es_ES.mo`), buildMo(messages));
process.stdout.write(`Built Spanish translation with ${messages.length} messages.\n`);
