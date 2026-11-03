const http = require('http');
const fs = require('fs');
const server = http.createServer(async (request, response) => {
    if (request.url === '/health') { response.end('ok'); return; }
    let body = '';
    for await (const chunk of request) body += chunk;
    if (request.url === '/slow') await new Promise(resolve => setTimeout(resolve, 22000));
    const previous = Number(fs.existsSync('/data/count') ? fs.readFileSync('/data/count', 'utf8') : 0);
    fs.writeFileSync('/data/count', String(previous + 1));
    response.setHeader('Content-Type', 'application/json');
    response.end(JSON.stringify({method: request.method, url: request.url, body, authorization: request.headers.authorization, cookie: request.headers.cookie, count: previous + 1}));
});
const delay = fs.existsSync('/data/wake-delay') ? Number(fs.readFileSync('/data/wake-delay', 'utf8')) : 0;
setTimeout(() => server.listen(8080, '0.0.0.0'), delay * 1000);

process.on('SIGTERM', () => server.close(() => process.exit(0)));
