const fs = require('fs');

const data = fs.readFileSync('flexible-product-customizer/languages/flexible-product-customizer-es_ES.mo');
if (data.readUInt32LE(0) !== 0x950412de) throw new Error('Invalid MO magic number.');
const count = data.readUInt32LE(8);
const originalOffset = data.readUInt32LE(12);
const translationOffset = data.readUInt32LE(16);
const messages = new Map();
for (let index = 0; index < count; index += 1) {
	const originalLength = data.readUInt32LE(originalOffset + index * 8);
	const originalPosition = data.readUInt32LE(originalOffset + index * 8 + 4);
	const translationLength = data.readUInt32LE(translationOffset + index * 8);
	const translationPosition = data.readUInt32LE(translationOffset + index * 8 + 4);
	messages.set(
		data.subarray(originalPosition, originalPosition + originalLength).toString('utf8'),
		data.subarray(translationPosition, translationPosition + translationLength).toString('utf8')
	);
}
if (messages.get('Customize product') !== 'Personalizar producto') throw new Error('Spanish storefront translation is missing.');
if (messages.get('Customizer settings') !== 'Ajustes del personalizador') throw new Error('Spanish settings translation is missing.');
if (messages.get('Outline thickness') !== 'Grosor del delineado') throw new Error('Spanish editor translation is missing.');
const poEntries = (fs.readFileSync('flexible-product-customizer/languages/flexible-product-customizer-es_ES.po', 'utf8').match(/^msgid /gm) || []).length;
if (messages.size !== poEntries) throw new Error(`MO and PO entry counts differ: ${messages.size} vs ${poEntries}.`);
process.stdout.write('Spanish MO translation smoke test passed.\n');
