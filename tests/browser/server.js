const http = require('http');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..', '..');
const mime = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript; charset=utf-8', '.css': 'text/css; charset=utf-8' };

http.createServer((request, response) => {
	const pathname = decodeURIComponent(new URL(request.url, 'http://127.0.0.1').pathname);
	process.stdout.write(`${request.method} ${pathname}\n`);
	const relative = pathname === '/' ? 'tests/browser/cylindrical-preview.html' : pathname.replace(/^\/+/, '');
	const file = path.resolve(root, relative);
	if (!file.startsWith(root + path.sep) || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
		response.writeHead(404).end('Not found');
		return;
	}
	response.writeHead(200, { 'Content-Type': mime[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-store' });
	fs.createReadStream(file).on('error', (error) => {
		process.stderr.write(`${pathname}: ${error.message}\n`);
		if (!response.headersSent) response.writeHead(500);
		response.end();
	}).pipe(response);
}).listen(4179, '127.0.0.1', () => process.stdout.write('Cylindrical fixture: http://127.0.0.1:4179\n'));
