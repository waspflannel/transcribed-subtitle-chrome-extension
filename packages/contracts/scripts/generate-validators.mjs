import fs from 'node:fs/promises';
import Ajv from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';
import { createRequire } from 'node:module';
import standaloneCode from 'ajv/dist/standalone/index.js';

const root = new URL('../', import.meta.url);
const ajv = new Ajv({ strict: true, strictRequired: false, code: { source: true, esm: true, lines: true } });
addFormats(ajv, { mode: 'fast' });
const exports = {};
for (const file of (await fs.readdir(new URL('schemas/', root))).filter(file => file.endsWith('.schema.json')).sort()) {
  const schema = JSON.parse(await fs.readFile(new URL(`schemas/${file}`, root), 'utf8'));
  ajv.addSchema(schema, file);
  exports[file.replace('.schema.json', '').replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())] = file;
}
ajv.addSchema({ type: 'object', required: ['ok'], properties: { ok: { const: true } }, additionalProperties: false }, 'ok');
exports.okResponse = 'ok';
const require = createRequire(import.meta.url);
let code = standaloneCode(ajv, exports);
code = code.replace(/require\("ajv\/dist\/runtime\/ucs2length"\).default/g, () => `(${require('ajv/dist/runtime/ucs2length').default.toString()})`);
code = code.replace(/require\("ajv-formats\/dist\/formats"\).fastFormats\["date-time"\]/g, () => `{ validate: ${require('ajv-formats/dist/formats').fastFormats['date-time'].validate.toString()} }`);
if (/require\(|new Function|eval\(/.test(code)) throw new Error('Validator output must be self contained and MV3 safe');
await fs.writeFile(new URL('dist/validators.js', root), code);
await fs.writeFile(new URL('dist/validators.d.ts', root), Object.keys(exports).map(name => `export declare const ${name}: (value: unknown) => boolean;`).join('\n') + '\n');
