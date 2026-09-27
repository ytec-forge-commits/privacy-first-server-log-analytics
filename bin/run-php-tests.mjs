import { existsSync } from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';

const probe = spawnSync('php', ['-r', 'echo extension_loaded("pdo_sqlite") ? "1" : "0";'], { encoding: 'utf8' });
if (probe.error || probe.status !== 0) throw new Error('PHP CLI is unavailable.');

const args = [];
if (probe.stdout.trim() !== '1') {
  if (process.platform !== 'win32') throw new Error('PDO SQLite is not enabled in PHP CLI.');
  const where = spawnSync('where.exe', ['php'], { encoding: 'utf8' });
  const phpPath = where.stdout.split(/\r?\n/).find(Boolean);
  const extension = phpPath ? path.join(path.dirname(phpPath), 'ext', 'php_pdo_sqlite.dll') : '';
  if (!extension || !existsSync(extension)) throw new Error('php_pdo_sqlite.dll was not found next to PHP.');
  args.push('-d', `extension=${extension}`);
}
args.push('tests/run.php');

const result = spawnSync('php', args, { stdio: 'inherit' });
process.exit(result.status ?? 1);
