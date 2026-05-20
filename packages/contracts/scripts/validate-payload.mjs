import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';

const [schemaFile, payloadFile] = process.argv.slice(2);

if (!schemaFile || !payloadFile) {
  console.error('Usage: node scripts/validate-payload.mjs <schema-file> <payload-json-file>');
  process.exit(2);
}

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const schemasDir = path.join(root, 'schemas');
const schemaPath = path.join(schemasDir, schemaFile);
const payloadPath = path.resolve(payloadFile);

if (!schemaPath.startsWith(schemasDir + path.sep) || !fs.existsSync(schemaPath)) {
  console.error(`Unknown schema file: ${schemaFile}`);
  process.exit(2);
}

if (!fs.existsSync(payloadPath)) {
  console.error(`Payload file does not exist: ${payloadPath}`);
  process.exit(2);
}

const ajv = new Ajv2020({
  allErrors: true,
  strict: true,
});

addFormats(ajv);

for (const file of fs
  .readdirSync(schemasDir)
  .filter((candidate) => candidate.endsWith('.schema.json'))
  .sort()) {
  const schema = JSON.parse(fs.readFileSync(path.join(schemasDir, file), 'utf8'));
  ajv.addSchema(schema, file);
}

const validate = ajv.getSchema(schemaFile);

if (!validate) {
  console.error(`Schema was not registered: ${schemaFile}`);
  process.exit(2);
}

const payload = JSON.parse(fs.readFileSync(payloadPath, 'utf8'));

if (!validate(payload)) {
  console.error(`${path.basename(payloadPath)} failed ${schemaFile}: ${ajv.errorsText(validate.errors)}`);
  console.error(JSON.stringify(validate.errors, null, 2));
  process.exit(1);
}

console.log(`validated ${path.basename(payloadPath)} against ${schemaFile}`);
