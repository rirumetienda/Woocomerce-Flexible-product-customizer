const fs = require('fs');
const path = require('path');
const Parser = require('php-parser');

const parser = new Parser({ parser: { extractDoc: true, php7: true }, ast: { withPositions: true } });
const files = [];

function walk(directory) {
	for (const name of fs.readdirSync(directory)) {
		const file = path.join(directory, name);
		const stat = fs.statSync(file);
		if (stat.isDirectory()) walk(file);
		else if (file.endsWith('.php')) files.push(file);
	}
}

walk('flexible-product-customizer');
for (const file of files) parser.parseCode(fs.readFileSync(file, 'utf8'), file);
process.stdout.write(`PHP syntax parsed: ${files.length} files.\n`);

