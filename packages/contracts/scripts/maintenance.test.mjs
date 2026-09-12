import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

test('language synchronization preserves unrelated schema properties and requirements', async () => {
  const temporary = await fs.mkdtemp(path.join(os.tmpdir(), 'subtitle-contracts-'));
  try {
    await fs.cp(path.join(root, 'schemas'), path.join(temporary, 'schemas'), { recursive: true });
    await fs.mkdir(path.join(temporary, 'scripts'));
    await fs.copyFile(path.join(root, 'languages.json'), path.join(temporary, 'languages.json'));
    await fs.copyFile(path.join(root, 'scripts/sync-language-schemas.mjs'), path.join(temporary, 'scripts/sync-language-schemas.mjs'));
    execFileSync(process.execPath, [path.join(temporary, 'scripts/sync-language-schemas.mjs')]);

    for (const file of await fs.readdir(path.join(root, 'schemas'))) {
      const before = JSON.parse(await fs.readFile(path.join(root, 'schemas', file), 'utf8'));
      const after = JSON.parse(await fs.readFile(path.join(temporary, 'schemas', file), 'utf8'));
      for (const schema of [before, after]) {
        const properties = schema.$defs?.SubtitleJobHistoryItem?.properties ?? schema.properties;
        for (const field of ['sourceLanguage', 'targetLanguage', 'detectedSourceLanguage']) {
          delete properties?.[field];
        }
      }
      assert.deepEqual(after, before, file);
    }
  } finally {
    await fs.rm(temporary, { recursive: true, force: true });
  }
});
