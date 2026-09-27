import { readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
// Development metadata can be skipped. Deployment state must never be silently
// skipped: its presence is a release-blocking error, even when Git ignores it.
const excludedDirectories = new Set(['.git', '.codex', 'node_modules']);
const privateStateDirectories = new Set(['data', 'sessions', 'locks', 'state', 'backups', 'output']);
const forbiddenNames = new Set(['config.php', 'routes.json']);
const forbiddenText = [/y-tec[ _-]*forge/i, /ytec\.cloudfree/i, /\/forge\b/i, /\/ytb\b/i];
let checked = 0;

function walk(directory) {
  for (const entry of readdirSync(directory)) {
    const full = path.join(directory, entry);
    const relative = path.relative(root, full).replaceAll('\\', '/');
    const info = statSync(full);
    if (info.isDirectory()) {
      if (excludedDirectories.has(entry)) continue;
      if (privateStateDirectories.has(entry)) {
        throw new Error(`Private deployment directory is present: ${relative}`);
      }
      walk(full);
      continue;
    }
    const syntheticFixture = relative === 'tests/fixtures/access.log';
    if (!syntheticFixture && (forbiddenNames.has(entry) || /\.(sqlite|log)(\.gz)?$/i.test(entry))) {
      throw new Error(`Private deployment file is present: ${relative}`);
    }
    if (relative === 'bin/verify-public-package.mjs') {
      checked++;
      continue;
    }
    const content = readFileSync(full, 'utf8');
    for (const pattern of forbiddenText) {
      if (pattern.test(content)) throw new Error(`Project-specific text is present in ${relative}: ${pattern}`);
    }
    checked++;
  }
}

walk(root);
console.log(`PASS: checked ${checked} public-package files`);
