const fs = require('fs');
const vm = require('vm');

const files = [
	'flexible-product-customizer/assets/js/editor.js',
	'flexible-product-customizer/assets/js/admin-template.js',
	'flexible-product-customizer/assets/js/admin-product.js',
	'flexible-product-customizer/assets/js/admin-fonts.js',
];

for (const file of files) new vm.Script(fs.readFileSync(file, 'utf8'), { filename: file });
const moduleFile = 'flexible-product-customizer/assets/js/cylindrical-preview.js';
const moduleSource = fs.readFileSync(moduleFile, 'utf8').replace(/^import .*;$/m, 'const THREE = {};');
new vm.Script(moduleSource, { filename: moduleFile });
process.stdout.write(`JavaScript syntax parsed: ${files.length + 1} files.\n`);
