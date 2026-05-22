import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { compileFromFile } from 'json-schema-to-typescript';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const schemasDir = path.join(root, 'schemas');
const distDir = path.join(root, 'dist');

const schemaFiles = [
  'account-summary.schema.json',
  'create-subtitle-job-request.schema.json',
  'extension-login-request.schema.json',
  'extension-auth-response.schema.json',
  'extension-account-response.schema.json',
  'learning-token-request.schema.json',
  'learning-token-response.schema.json',
  'job-response.schema.json',
  'subtitle-job-history-response.schema.json',
  'track-response.schema.json',
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
