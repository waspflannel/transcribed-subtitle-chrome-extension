import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { compileFromFile } from 'json-schema-to-typescript';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const schemasDir = path.join(root, 'schemas');
const distDir = path.join(root, 'dist');

const schemaFiles = [
  'instance-settings.schema.json',
  'update-instance-settings.schema.json',
  'create-subtitle-job-request.schema.json',
  'learning-token-request.schema.json',
  'learning-token-response.schema.json',
  'lyrics-correction-request.schema.json',
  'lyrics-correction-status.schema.json',
  'lyrics-correction-cancel-request.schema.json',
  'quick-fix-token-request.schema.json',
  'job-response.schema.json',
  'subtitle-job-history-response.schema.json',
  'track-response.schema.json',
  'partial-track-response.schema.json',
  'cue.schema.json',
  'token.schema.json',
  'api-error.schema.json',
  'error-object.schema.json',
];

await fs.mkdir(distDir, { recursive: true });

const compiledTypes = [];

for (const schemaFile of schemaFiles) {
  const schemaPath = path.join(schemasDir, schemaFile);
  const typeDefinition = await compileFromFile(schemaPath, {
    bannerComment: '',
    cwd: schemasDir,
    enableConstEnums: false,
    // Local definitions have no separately compiled root schema.
    declareExternallyReferenced: ['subtitle-job-history-response.schema.json', 'partial-track-response.schema.json'].includes(schemaFile),
    // Request limits are enforced by schema validation; callers build ordinary arrays.
    ignoreMinAndMaxItems: schemaFile === 'create-subtitle-job-request.schema.json',
    style: {
      singleQuote: true,
      semi: true,
    },
  });

  compiledTypes.push(`// Source: schemas/${schemaFile}\n${typeDefinition.trim()}`);
}

const output = `${compiledTypes.join('\n\n')}\n`;
await fs.writeFile(path.join(distDir, 'index.d.ts'), output, 'utf8');
console.log('generated dist/index.d.ts');
