import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const extensionRoot = path.join(repositoryRoot, 'extensions', 'Cowlender');

function fail(message) {
  console.error(`Cowlender validation failed: ${message}`);
  process.exitCode = 1;
}

function readJson(relativePath) {
  const absolutePath = path.join(extensionRoot, relativePath);
  try {
    return JSON.parse(fs.readFileSync(absolutePath, 'utf8'));
  } catch (error) {
    fail(`${relativePath} is not valid JSON: ${error.message}`);
    return {};
  }
}

function classFile(className) {
  const prefix = 'MediaWiki\\Extension\\Cowlender\\';
  if (!className.startsWith(prefix)) {
    return null;
  }
  return path.join(extensionRoot, 'includes', `${className.slice(prefix.length).replaceAll('\\', path.sep)}.php`);
}

const manifest = readJson('extension.json');
const englishMessages = readJson(path.join('i18n', 'en.json'));
readJson(path.join('i18n', 'qqq.json'));

if (manifest.name !== 'Cowlender' || manifest.manifest_version !== 2) {
  fail('extension.json has the wrong extension name or manifest version.');
}

const routeKeys = new Set();
for (const route of manifest.RestRoutes ?? []) {
  const key = `${route.method} ${route.path}`;
  if (routeKeys.has(key)) {
    fail(`duplicate REST route ${key}.`);
  }
  routeKeys.add(key);

  const handlerFile = classFile(route.class ?? '');
  if (!handlerFile || !fs.existsSync(handlerFile)) {
    fail(`REST handler ${route.class ?? '(missing)'} does not resolve to a PHP file.`);
  }
}

for (const className of Object.values(manifest.SpecialPages ?? {})) {
  const specialPageFile = classFile(className);
  if (!specialPageFile || !fs.existsSync(specialPageFile)) {
    fail(`special-page class ${className} does not resolve to a PHP file.`);
  }
}

for (const right of manifest.AvailableRights ?? []) {
  if (!englishMessages[`right-${right}`] || !englishMessages[`action-${right}`]) {
    fail(`right ${right} is missing an English right/action message.`);
  }
}

for (const databaseType of ['mysql', 'sqlite']) {
  for (const table of ['cowlender_event', 'cowlender_event_revision']) {
    const sqlFile = path.join(extensionRoot, 'sql', databaseType, `${table}.sql`);
    if (!fs.existsSync(sqlFile)) {
      fail(`missing ${databaseType} schema file for ${table}.`);
      continue;
    }
    const sql = fs.readFileSync(sqlFile, 'utf8');
    if (!sql.includes(`/*_*/${table}`)) {
      fail(`${path.relative(repositoryRoot, sqlFile)} does not use MediaWiki's table-prefix marker.`);
    }
  }
}

for (const phpFile of fs.readdirSync(path.join(extensionRoot, 'includes'), { recursive: true })) {
  if (!phpFile.endsWith('.php')) {
    continue;
  }
  const contents = fs.readFileSync(path.join(extensionRoot, 'includes', phpFile), 'utf8');
  if (!contents.startsWith('<?php\n\ndeclare( strict_types=1 );')) {
    fail(`${path.join('includes', phpFile)} does not enable strict types.`);
  }
}

if (!process.exitCode) {
  console.log(`Cowlender metadata validated (${routeKeys.size} REST routes).`);
}
