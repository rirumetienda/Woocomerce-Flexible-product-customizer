const { spawnSync } = require('child_process');

const tests = [
	'tests/build-translations.js',
	'tests/translation-smoke.js',
	'tests/php-syntax.js',
	'tests/js-syntax.js',
	'tests/admin-template-smoke.js',
	'tests/admin-fonts-smoke.js',
	'tests/admin-product-smoke.js',
	'tests/schema-v6-smoke.js',
	'tests/editor-smoke.js',
	'tests/editor-layout-smoke.js',
];
for (const test of tests) {
	const result = spawnSync(process.execPath, [test], { stdio: 'inherit' });
	if (result.status !== 0) process.exit(result.status || 1);
}

process.stdout.write('All local checks passed.\n');
