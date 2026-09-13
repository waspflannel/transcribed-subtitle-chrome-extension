import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const catalog = JSON.parse(await fs.readFile(path.join(root, 'languages.json'), 'utf8'));
const sourceLanguageCodes = catalog.languages.map((language) => language.code);
const targetLanguageCodes = catalog.languages
  .filter((language) => language.sourceOnly !== true)
  .map((language) => language.code);

const schemaUpdates = {
  'create-subtitle-job-request.schema.json': (schema) => {
    schema.properties.sourceLanguage.enum = sourceLanguageCodes;
    schema.properties.sourceLanguage.description =
      'Learning/source language requested by the extension. Auto detect is source-only. Language choices are defined by languages.json.';
    delete schema.properties.targetLanguage.const;
    schema.properties.targetLanguage.enum = targetLanguageCodes;
    schema.properties.targetLanguage.description =
      'Translation/target language requested by the extension. Auto detect is not allowed for target language.';
  },
  'job-response.schema.json': (schema) => {
    schema.properties.sourceLanguage.enum = sourceLanguageCodes;
    delete schema.properties.targetLanguage.const;
    schema.properties.targetLanguage.enum = targetLanguageCodes;
    schema.properties.detectedSourceLanguage = detectedSourceLanguageProperty();
  },
  'track-response.schema.json': (schema) => {
    schema.properties.sourceLanguage.enum = sourceLanguageCodes;
    delete schema.properties.targetLanguage.const;
    schema.properties.targetLanguage.enum = targetLanguageCodes;
    schema.properties.detectedSourceLanguage = detectedSourceLanguageProperty();
  },
  'subtitle-job-history-response.schema.json': (schema) => {
    const item = schema.$defs.SubtitleJobHistoryItem;
    for (const field of ['sourceLanguage', 'targetLanguage']) {
      item.properties[field] = {
        type: 'string',
        enum: field === 'sourceLanguage' ? sourceLanguageCodes : targetLanguageCodes,
      };
    }
    item.properties.detectedSourceLanguage = detectedSourceLanguageProperty();
  },
};

for (const [schemaFile, updateSchema] of Object.entries(schemaUpdates)) {
  const schemaPath = path.join(root, 'schemas', schemaFile);
  const schema = JSON.parse(await fs.readFile(schemaPath, 'utf8'));

  updateSchema(schema);

  await fs.writeFile(schemaPath, `${JSON.stringify(schema, null, 2)}\n`, 'utf8');
  console.log(`synced ${schemaFile}`);
}

function detectedSourceLanguageProperty() {
  return {
    type: 'string',
    enum: targetLanguageCodes,
    description: 'Provider-detected source language when sourceLanguage was auto and detection produced a catalog language.',
  };
}
