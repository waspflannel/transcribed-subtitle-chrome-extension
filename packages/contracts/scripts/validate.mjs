import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import SwaggerParser from '@apidevtools/swagger-parser';
import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const schemasDir = path.join(root, 'schemas');
const fixturesDir = path.join(root, 'fixtures');

const schemaFiles = fs
  .readdirSync(schemasDir)
  .filter((file) => file.endsWith('.schema.json'))
  .sort();

const ajv = new Ajv2020({
  allErrors: true,
  strict: true,
  schemas: [],
});

addFormats(ajv);

const schemas = new Map();

for (const file of schemaFiles) {
  const schemaPath = path.join(schemasDir, file);
  const schema = JSON.parse(fs.readFileSync(schemaPath, 'utf8'));
  schemas.set(file, schema);
  ajv.addSchema(schema, file);
}

for (const [file, schema] of schemas) {
  ajv.compile(schema);
  console.log(`compiled ${file}`);
}

const fixtures = [
  ['create-subtitle-job-request.schema.json', 'valid-create-subtitle-job-request.json'],
  ['job-response.schema.json', 'valid-job-response.json'],
  ['track-response.schema.json', 'valid-track-response.json'],
  ['api-error.schema.json', 'valid-api-error.json'],
];

for (const [schemaFile, fixtureFile] of fixtures) {
  const validate = ajv.getSchema(schemaFile);
  const fixture = JSON.parse(fs.readFileSync(path.join(fixturesDir, fixtureFile), 'utf8'));

  if (!validate(fixture)) {
    throw new Error(`${fixtureFile} failed ${schemaFile}: ${ajv.errorsText(validate.errors)}`);
  }

  console.log(`validated ${fixtureFile}`);
}

await SwaggerParser.validate(path.join(root, 'openapi.json'));
console.log('validated openapi.json');
