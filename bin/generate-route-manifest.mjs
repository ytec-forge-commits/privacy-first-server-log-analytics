import { promises as fs } from 'node:fs';
import path from 'node:path';

const options = new Map();
for (const argument of process.argv.slice(2)) {
  const match = /^--([^=]+)=(.+)$/.exec(argument);
  if (match) options.set(match[1], match[2]);
}

const distRoot = path.resolve(options.get('dist') ?? 'dist');
const output = options.get('output');
const base = normalizeBase(options.get('base') ?? '/');
if (!output) throw new Error('Usage: node generate-route-manifest.mjs --dist=dist --base=/your-site --output=/absolute/routes.json');

const rootReal = await fs.realpath(distRoot);
const pages = [];
await walk(rootReal);
pages.sort((a, b) => a.path.localeCompare(b.path, 'en'));

const manifest = { version: 1, base, generatedAt: new Date().toISOString(), pages };
await fs.mkdir(path.dirname(path.resolve(output)), { recursive: true });
await fs.writeFile(path.resolve(output), `${JSON.stringify(manifest, null, 2)}\n`, { encoding: 'utf8', mode: 0o600 });
console.log(JSON.stringify({ status: 'ok', pages: pages.length, output: path.resolve(output) }));

async function walk(directory) {
  for (const entry of await fs.readdir(directory, { withFileTypes: true })) {
    const target = path.join(directory, entry.name);
    const real = await fs.realpath(target);
    if (real !== rootReal && !real.startsWith(`${rootReal}${path.sep}`)) throw new Error('Path escaped the dist root.');
    if (entry.isDirectory()) await walk(real);
    if (!entry.isFile() || entry.name !== 'index.html') continue;
    const relative = path.relative(rootReal, real).split(path.sep).join('/');
    const routePart = relative === 'index.html' ? '' : relative.slice(0, -'index.html'.length);
    const route = `${base}/${routePart}`.replace(/\/{2,}/g, '/');
    const html = await fs.readFile(real, 'utf8');
    const titleMatch = /<title>([\s\S]*?)<\/title>/i.exec(html);
    const label = decodeEntities((titleMatch?.[1] ?? route).replace(/<[^>]*>/g, '').trim()).slice(0, 160);
    pages.push({ path: route.endsWith('/') ? route : `${route}/`, label });
  }
}

function normalizeBase(value) {
  const normalized = `/${value}`.replace(/\/{2,}/g, '/').replace(/\/$/, '');
  if (!/^\/[a-z0-9/_-]*$/i.test(normalized) || normalized.includes('..')) throw new Error('Invalid base path.');
  return normalized || '/';
}

function decodeEntities(value) {
  return value.replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'");
}
