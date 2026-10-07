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
  ['learning-token-request.schema.json', 'valid-learning-token-request.json'],
  ['learning-token-response.schema.json', 'valid-learning-token-response.json'],
  ['lyrics-correction-request.schema.json', 'valid-lyrics-correction-request.json'],
  ['lyrics-correction-request.schema.json', 'valid-lyrics-correction-request-partial.json'],
  ['lyrics-correction-status.schema.json', 'valid-lyrics-correction-status.json'],
  ['lyrics-correction-cancel-request.schema.json', 'valid-lyrics-correction-cancel-request.json'],
  ['quick-fix-token-request.schema.json', 'valid-quick-fix-token-request.json'],
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

const lyricsCorrectionStatus = ajv.getSchema('lyrics-correction-status.schema.json');

const generationRequest = ajv.getSchema('create-subtitle-job-request.schema.json');
const generationFixture = JSON.parse(fs.readFileSync(path.join(fixturesDir, 'valid-create-subtitle-job-request.json'), 'utf8'));
for (const aiProvider of ['openai', 'cerebras']) {
  if (!generationRequest({ ...generationFixture, aiProvider })) throw new Error('Valid AI provider rejected.');
}
for (const aiProvider of ['auto', 'hybrid', 'invalid', null, 1]) {
  if (generationRequest({ ...generationFixture, aiProvider })) throw new Error('Invalid AI provider accepted.');
}
if (!generationRequest({ ...generationFixture, aiProvider: 'codex', aiModel: 'test-model', aiFastMode: true })) throw new Error('Valid Codex request rejected.');
assertInvalid(generationRequest, { ...generationFixture, aiProvider: 'codex' }, 'Codex request without a model');
assertInvalid(generationRequest, { ...generationFixture, aiProvider: 'openai', aiModel: 'test-model' }, 'API request with a Codex model');
assertInvalid(generationRequest, { ...generationFixture, aiProvider: 'cerebras', aiFastMode: true }, 'API request with Codex fast mode');
assertInvalid(generationRequest, { ...generationFixture, aiProvider: 'codex', aiModel: '../config' }, 'unsafe model id');
const codexAccount = ajv.getSchema('codex-account.schema.json');
const account = { available: true, connected: false, models: [], login: { status: 'awaiting_authorization', authUrl: 'https://auth.openai.com/oauth/authorize?client_id=test' } };
if (!codexAccount(account)) throw new Error('Valid Codex account rejected.');
assertInvalid(codexAccount, { ...account, accessToken: 'secret' }, 'Codex credential leak');
for (const authUrl of ['https://auth.openai.com/oauth/authorize?client_id=test', 'https://chatgpt.com/auth/login']) {
  if (!codexAccount({ ...account, login: { ...account.login, authUrl } })) throw new Error('Valid OAuth URL rejected.');
}
for (const authUrl of ['https://example.com/sign-in', 'https://auth.openai.com:443/', 'https://user@auth.openai.com/', 'https://chatgpt.com\\@evil.com/', 'https://chatgpt.com/\n', 'https://chatgpt.com/'+ 'a'.repeat(4096)]) {
  assertInvalid(codexAccount, { ...account, login: { ...account.login, authUrl } }, 'untrusted OAuth URL');
}
assertInvalid(codexAccount, { ...account, login: { status: 'awaiting_authorization', verificationUrl: 'https://auth.openai.com/codex/device', userCode: 'ABCD-EFGH' } }, 'retired device login response');
const unavailableTranslation = JSON.parse(fs.readFileSync(path.join(fixturesDir, 'valid-track-response.json'), 'utf8'));
unavailableTranslation.cues[0].translatedText = '';
if (!ajv.getSchema('track-response.schema.json')(unavailableTranslation)) throw new Error('Unavailable translation was rejected.');

const invalidLyricsCorrectionFixtures = [
  'invalid-lyrics-correction-queued-aligning.json',
  'invalid-lyrics-correction-running-queued.json',
  'invalid-lyrics-correction-completed-failed.json',
  'invalid-lyrics-correction-failed-completed.json',
  'invalid-lyrics-correction-cancelled-aligning.json',
];

for (const fixtureFile of invalidLyricsCorrectionFixtures) {
  const fixture = JSON.parse(fs.readFileSync(path.join(fixturesDir, fixtureFile), 'utf8'));

  if (lyricsCorrectionStatus(fixture)) {
    throw new Error(`${fixtureFile} unexpectedly passed lyrics correction status validation.`);
  }

  console.log(`rejected ${fixtureFile}`);
}

const quickFixTokenRequest = ajv.getSchema('quick-fix-token-request.schema.json');
assertInvalid(
  quickFixTokenRequest,
  JSON.parse(fs.readFileSync(path.join(fixturesDir, 'invalid-quick-fix-token-request-whitespace.json'), 'utf8')),
  'whitespace-only quick fix text',
);

const lyricsCorrectionRequest = ajv.getSchema('lyrics-correction-request.schema.json');
assertInvalid(lyricsCorrectionRequest, {
  expectedTrackId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3041',
  lyrics: 'First line',
  allowPartial: 'true',
}, 'non-boolean allowPartial');

const correctionStatuses = ['queued', 'running', 'completed', 'failed', 'cancelled'];
const correctionStages = ['queued', 'aligning', 'rebuilding', 'romanizing', 'finalizing', 'completed', 'failed', 'cancelled'];
const validCorrectionStageByStatus = {
  queued: ['queued'],
  running: ['aligning', 'rebuilding', 'romanizing', 'finalizing'],
  completed: ['completed'],
  failed: ['failed'],
  cancelled: ['cancelled'],
};

for (const status of correctionStatuses) {
  for (const stage of correctionStages) {
    if (validCorrectionStageByStatus[status].includes(stage)) continue;

    assertInvalid(lyricsCorrectionStatus, {
      attemptId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3040',
      status,
      stage,
      updatedAt: '2026-08-13T00:00:00Z',
      ...(status === 'failed' ? { errorCode: 'lyrics_correction_failed', message: 'failed' } : {}),
    }, `invalid correction status/stage pair ${status}/${stage}`);
  }
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

assertInvalid(lyricsCorrectionStatus, {
  attemptId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3010',
  status: 'queued',
  stage: 'queued',
  updatedAt: '2026-08-13T00:00:00Z',
  track: {},
}, 'queued correction with track');
assertInvalid(lyricsCorrectionStatus, {
  attemptId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3010',
  status: 'completed',
  stage: 'completed',
  updatedAt: '2026-08-13T00:00:00Z',
  errorCode: 'lyrics_correction_failed',
  message: 'failed',
}, 'completed correction with error');
assertInvalid(lyricsCorrectionStatus, {
  attemptId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3010',
  status: 'cancelled',
  stage: 'cancelled',
  updatedAt: '2026-08-13T00:00:00Z',
  message: 'cancelled',
}, 'cancelled correction with error');

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
