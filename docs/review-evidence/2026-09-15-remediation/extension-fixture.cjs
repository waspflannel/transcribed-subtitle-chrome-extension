// Local browser fixture: actual side-panel entrypoint/CSS, entirely fake extension transport.
// Run from any directory: node docs/review-evidence/2026-09-15-remediation/extension-fixture.cjs
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const http = require('node:http');
const { pathToFileURL } = require('node:url');
const repo = path.resolve(__dirname, '../../..');
const extension = path.join(repo, 'app/extension');
const output = fs.mkdtempSync(path.join(os.tmpdir(), 'subtitle-remediation-ui-'));

(async () => {
  const { build } = await import(pathToFileURL(path.join(extension, 'node_modules/vite/dist/node/index.js')).href);
  await build({
    root: extension, configFile: false, envDir: false, publicDir: false,
    build: { outDir: output, emptyOutDir: false, lib: {
      entry: path.join(extension, 'entrypoints/sidepanel/main.ts'),
      name: 'ReviewPanel', formats: ['iife'], fileName: () => 'main.js', cssFileName: 'main',
    } },
    plugins: [{ name: 'fixture-browser', enforce: 'pre',
      resolveId(id) { if (id === 'wxt/browser') return '\0fixture-browser'; },
      load(id) { if (id === '\0fixture-browser') return 'export const browser = window.reviewBrowser;'; },
    }],
    define: { 'import.meta.env.WXT_BACKEND_API_BASE_URL': JSON.stringify('http://127.0.0.1:8772/v1') },
  });
  const html = fs.readFileSync(path.join(extension, 'entrypoints/sidepanel/index.html'), 'utf8')
    .replace(/<script[^>]+src="\.\/main.ts"[^>]*><\/script>/, '')
    .replace('</head>', '<link rel="stylesheet" href="/main.css"><script src="/fixture.js"></script><script defer src="/main.js"></script></head>');
  fs.writeFileSync(path.join(output, 'index.html'), html);
  fs.copyFileSync(path.join(__dirname, 'extension-fixture-data.js'), path.join(output, 'fixture.js'));
  http.createServer((req, res) => {
    const url = new URL(req.url, 'http://localhost');
    const requested = path.resolve(output, '.' + (url.pathname === '/' ? '/index.html' : url.pathname));
    if (!requested.startsWith(output + path.sep)) { res.writeHead(403); res.end(); return; }
    try {
      res.setHeader('Content-Type', ({ '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.woff2': 'font/woff2' })[path.extname(requested)] || 'text/plain');
      res.end(fs.readFileSync(requested));
    } catch { res.writeHead(404); res.end('Not found'); }
  }).listen(8772, '127.0.0.1', () => console.log('Fixture http://127.0.0.1:8772; build ' + output));
})();
