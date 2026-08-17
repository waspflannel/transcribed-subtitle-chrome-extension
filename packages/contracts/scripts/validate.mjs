import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import SwaggerParser from '@apidevtools/swagger-parser';
import Ajv2020 from 'ajv/dist/2020.js';
import addFormats from 'ajv-formats';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const schemasDir = path.join(root, 'schemas');
const fixturesDir = path.join(root, 'fixtures');
const languageCatalog = JSON.parse(fs.readFileSync(path.join(root, 'languages.json'), 'utf8'));

const schemaFiles = fs
  .readdirSync(schemasDir)
  .filter((file) => file.endsWith('.schema.json'))
  .sort();

const ajv = new Ajv2020({
  allErrors: true,
  strict: true,
  strictRequired: false,
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

validateLanguageCatalog(languageCatalog);
validateLanguageSchemaEnums(languageCatalog, schemas);

for (const [file, schema] of schemas) {
  ajv.compile(schema);
  console.log(`compiled ${file}`);
}

const fixtures = [
  ['create-subtitle-job-request.schema.json', 'valid-create-subtitle-job-request.json'],
  ['extension-login-request.schema.json', 'valid-extension-login-request.json'],
  ['extension-auth-response.schema.json', 'valid-extension-auth-response.json'],
  ['extension-account-response.schema.json', 'valid-extension-account-response.json'],
  ['create-subtitle-job-request.schema.json', 'valid-create-subtitle-job-request-full.json'],
  ['learning-token-request.schema.json', 'valid-learning-token-request.json'],
  ['learning-token-response.schema.json', 'valid-learning-token-response.json'],
  ['lyrics-correction-request.schema.json', 'valid-lyrics-correction-request.json'],
  ['lyrics-correction-status.schema.json', 'valid-lyrics-correction-status.json'],
  ['job-response.schema.json', 'valid-job-response.json'],
  ['subtitle-job-history-response.schema.json', 'valid-subtitle-job-history-response.json'],
  ['track-response.schema.json', 'valid-track-response.json'],
  ['partial-track-response.schema.json', 'valid-partial-track-response.json'],
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

const createSubtitleJobRequest = ajv.getSchema('create-subtitle-job-request.schema.json');
assertInvalid(createSubtitleJobRequest, {
  youtubeVideoId: 'dQw4w9WgXcQ',
  sourceLanguage: 'not-a-language',
  targetLanguage: 'eng',
}, 'invalid source language');
assertInvalid(createSubtitleJobRequest, {
  youtubeVideoId: 'dQw4w9WgXcQ',
  sourceLanguage: 'eng',
  targetLanguage: 'auto',
}, 'target language auto');
assertInvalid(createSubtitleJobRequest, {
  youtubeVideoId: 'dQw4w9WgXcQ',
  sourceLanguage: 'eng',
  targetLanguage: 'not-a-language',
}, 'invalid target language');

const lyricsCorrectionStatus = ajv.getSchema('lyrics-correction-status.schema.json');
assertInvalid(lyricsCorrectionStatus, {
  attemptId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3010',
  status: 'queued',
  updatedAt: '2026-08-13T00:00:00Z',
  track: {},
}, 'queued correction with track');
assertInvalid(lyricsCorrectionStatus, {
  attemptId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3010',
  status: 'completed',
  updatedAt: '2026-08-13T00:00:00Z',
  errorCode: 'lyrics_correction_failed',
  message: 'failed',
}, 'completed correction with error');

await SwaggerParser.validate(path.join(root, 'openapi.json'));
console.log('validated openapi.json');

function validateLanguageCatalog(catalog) {
  const languages = catalog.languages;

  if (!Array.isArray(languages) || languages.length !== 94) {
    throw new Error('languages.json must define Auto detect plus the 93 WER-ranked language choices.');
  }

  const codes = new Set();
  const tierCounts = new Map([
    ['excellent', 0],
    ['high', 0],
    ['good', 0],
    ['moderate', 0],
  ]);

  for (const language of languages) {
    if (
      typeof language?.code !== 'string' ||
      typeof language?.label !== 'string' ||
      !['auto', 'excellent', 'high', 'good', 'moderate'].includes(language?.tier)
    ) {
      throw new Error('languages.json contains an invalid language entry.');
    }

    if (codes.has(language.code)) {
      throw new Error(`languages.json contains duplicate language code ${language.code}.`);
    }

    codes.add(language.code);

    if (language.code === 'auto') {
      if (language.tier !== 'auto' || language.sourceOnly !== true) {
        throw new Error('languages.json auto detection must be source-only with tier auto.');
      }

      continue;
    }

    if (language.sourceOnly === true) {
      throw new Error(`languages.json only auto can be source-only, found ${language.code}.`);
    }

    tierCounts.set(language.tier, (tierCounts.get(language.tier) ?? 0) + 1);
  }

  const expectedTierCounts = new Map([
    ['excellent', 36],
    ['high', 21],
    ['good', 18],
    ['moderate', 18],
  ]);

  for (const [tier, expectedCount] of expectedTierCounts) {
    if (tierCounts.get(tier) !== expectedCount) {
      throw new Error(`languages.json ${tier} tier must contain ${expectedCount} languages.`);
    }
  }

  if (!codes.has('auto')) {
    throw new Error('languages.json must include source-only auto detection.');
  }
}

function validateLanguageSchemaEnums(catalog, schemas) {
  const sourceLanguageCodes = catalog.languages.map((language) => language.code);
  const targetLanguageCodes = catalog.languages
    .filter((language) => language.sourceOnly !== true)
    .map((language) => language.code);

  assertEnum(
    schemas.get('create-subtitle-job-request.schema.json').properties.sourceLanguage.enum,
    sourceLanguageCodes,
    'create request sourceLanguage',
  );
  assertEnum(
    schemas.get('create-subtitle-job-request.schema.json').properties.targetLanguage.enum,
    targetLanguageCodes,
    'create request targetLanguage',
  );
  assertEnum(schemas.get('job-response.schema.json').properties.sourceLanguage.enum, sourceLanguageCodes, 'job sourceLanguage');
  assertEnum(schemas.get('job-response.schema.json').properties.targetLanguage.enum, targetLanguageCodes, 'job targetLanguage');
  assertEnum(
    schemas.get('job-response.schema.json').properties.detectedSourceLanguage.enum,
    targetLanguageCodes,
    'job detectedSourceLanguage',
  );
  assertEnum(schemas.get('track-response.schema.json').properties.sourceLanguage.enum, sourceLanguageCodes, 'track sourceLanguage');
  assertEnum(schemas.get('track-response.schema.json').properties.targetLanguage.enum, targetLanguageCodes, 'track targetLanguage');
  assertEnum(
    schemas.get('track-response.schema.json').properties.detectedSourceLanguage.enum,
    targetLanguageCodes,
    'track detectedSourceLanguage',
  );

  const historyItem = schemas.get('subtitle-job-history-response.schema.json').$defs.SubtitleJobHistoryItem;

  assertEnum(historyItem.properties.sourceLanguage.enum, sourceLanguageCodes, 'history sourceLanguage');
  assertEnum(historyItem.properties.targetLanguage.enum, targetLanguageCodes, 'history targetLanguage');
  assertEnum(historyItem.properties.detectedSourceLanguage.enum, targetLanguageCodes, 'history detectedSourceLanguage');
}

function assertEnum(actual, expected, label) {
  if (JSON.stringify(actual) !== JSON.stringify(expected)) {
    throw new Error(`${label} enum is not synced with languages.json. Run npm run sync:languages.`);
  }
}

function assertInvalid(validate, value, label) {
  if (validate(value)) {
    throw new Error(`Expected ${label} fixture to fail validation.`);
  }

  console.log(`rejected ${label}`);
}
