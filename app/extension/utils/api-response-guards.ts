import type {
  AccountSummary,
  ExtensionAccountResponse,
  ExtensionAuthResponse,
  JobResponse,
  LearningToken,
  LearningTokenResponse,
  LyricsCorrectionStatus,
  PartialTrackResponse,
  SubtitleCue,
  SubtitleJobHistoryItem,
  SubtitleJobHistoryResponse,
  TrackResponse,
} from './contracts';

export function guardExtensionAuthResponse(value: unknown): ExtensionAuthResponse {
  const response = record(value, 'extension auth response');
  const token = record(response.token, 'extension auth token');

  guardAccountSummary(response.account);
  requiredString(token, 'plainTextToken');
  literal(token, 'tokenType', 'Bearer');
  requiredString(token, 'expiresAt');
  nonEmptyArray(token.abilities, 'token abilities').forEach((ability) => {
    if (typeof ability !== 'string') {
      throw invalid('token ability must be a string');
    }
  });

  return response as unknown as ExtensionAuthResponse;
}

export function guardExtensionAccountResponse(value: unknown): ExtensionAccountResponse {
  const response = record(value, 'extension account response');

  guardAccountSummary(response.account);

  return response as unknown as ExtensionAccountResponse;
}

export function guardOkResponse(value: unknown): { ok: true } {
  const response = record(value, 'ok response');

  literal(response, 'ok', true);

  return response as { ok: true };
}

export function guardJobResponse(value: unknown): JobResponse {
  const response = record(value, 'job response');

  guardJobCore(response);
  requiredString(response, 'createdAt');
  requiredString(response, 'updatedAt');
  optionalString(response, 'expiresAt');
  optionalString(response, 'message');
  optionalString(response, 'errorCode');

  if ('track' in response && response.track !== undefined) {
    guardTrackResponse(response.track);
  }

  return response as JobResponse;
}

export function guardSubtitleJobHistoryResponse(value: unknown): SubtitleJobHistoryResponse {
  const response = record(value, 'subtitle job history response');
  const jobs = array(response.jobs, 'jobs');

  jobs.forEach(guardSubtitleJobHistoryItem);

  return response as unknown as SubtitleJobHistoryResponse;
}

export function guardTrackResponse(value: unknown): TrackResponse {
  const response = record(value, 'track response');
  const cues = nonEmptyArray(response.cues, 'track cues');

  requiredString(response, 'trackId');
  requiredString(response, 'jobId');
  requiredString(response, 'youtubeVideoId');
  requiredString(response, 'sourceLanguage');
  requiredString(response, 'targetLanguage');
  requiredString(response, 'generatedAt');
  requiredString(response, 'expiresAt');
  requiredString(response, 'webVtt');
  optionalString(response, 'detectedSourceLanguage');
  cues.forEach(guardSubtitleCue);

  return response as unknown as TrackResponse;
}

export function guardPartialTrackResponse(value: unknown): PartialTrackResponse {
  const response = record(value, 'partial track response');
  const cues = nonEmptyArray(response.cues, 'partial track cues');

  requiredString(response, 'jobId');
  requiredString(response, 'youtubeVideoId');
  requiredNumber(response, 'revision');
  cues.forEach(guardPartialSubtitleCue);

  return response as unknown as PartialTrackResponse;
}

export function guardLearningTokenResponse(value: unknown): LearningTokenResponse {
  const response = record(value, 'learning token response');

  requiredString(response, 'trackId');
  requiredString(response, 'cueId');
  guardLearningToken(response.token);

  return response as unknown as LearningTokenResponse;
}

export function guardLyricsCorrectionStatus(value: unknown): LyricsCorrectionStatus {
  const response = record(value, 'lyrics correction status');

  requiredString(response, 'attemptId');
  oneOf(response, 'status', ['queued', 'running', 'completed', 'failed']);
  requiredString(response, 'updatedAt');

  if (response.status === 'queued' || response.status === 'running') {
    forbidden(response, 'track', response.status);
    forbidden(response, 'errorCode', response.status);
    forbidden(response, 'message', response.status);
  }

  if (response.status === 'completed') {
    guardTrackResponse(response.track);
    forbidden(response, 'errorCode', response.status);
    forbidden(response, 'message', response.status);
  }

  if (response.status === 'failed') {
    oneOf(response, 'errorCode', ['lyrics_do_not_match', 'lyrics_correction_failed']);
    requiredString(response, 'message');
    forbidden(response, 'track', response.status);
  }

  return response as LyricsCorrectionStatus;
}

function guardAccountSummary(value: unknown): AccountSummary {
  const account = record(value, 'account summary');

  literal(account, 'status', 'authenticated');
  requiredString(account, 'id');
  requiredString(account, 'email');
  requiredString(account, 'name');
  requiredBoolean(account, 'emailVerified');
  requiredString(account, 'planName');
  requiredString(account, 'tierName');
  requiredString(account, 'tierSpeedLabel');
  requiredNumber(account, 'monthlyMinuteLimit');
  requiredNumber(account, 'monthlyMinutesUsed');
  requiredNumber(account, 'monthlyMinutesPending');
  requiredNumber(account, 'monthlyMinutesRemaining');
  requiredString(account, 'resetAt');
  requiredBoolean(account, 'upgradeAvailable');

  return account as unknown as AccountSummary;
}

function guardSubtitleJobHistoryItem(value: unknown): SubtitleJobHistoryItem {
  const item = record(value, 'subtitle job history item');

  guardJobCore(item);
  requiredString(item, 'youtubeUrl');
  requiredString(item, 'startedAt');
  requiredString(item, 'lastUpdatedAt');
  requiredString(item, 'jobId');
  optionalString(item, 'completedAt');
  optionalString(item, 'trackId');
  optionalString(item, 'expiresAt');
  optionalString(item, 'message');
  optionalString(item, 'errorCode');

  return item as SubtitleJobHistoryItem;
}

function guardJobCore(value: Record<string, unknown>): void {
  requiredString(value, 'youtubeVideoId');
  optionalNumber(value, 'videoDurationSeconds');
  requiredString(value, 'sourceLanguage');
  optionalString(value, 'detectedSourceLanguage');
  requiredString(value, 'targetLanguage');
  oneOf(value, 'enrichmentMode', ['on_demand', 'full']);
  requiredBoolean(value, 'includeRomanization');
  requiredBoolean(value, 'includeTranslation');
  oneOf(value, 'status', ['queued', 'running', 'completed', 'failed']);
  oneOf(value, 'stage', [
    'preparing',
    'acquiring-audio',
    'optimizing-audio',
    'transcribing',
    'tokenizing',
    'romanizing',
    'translating',
    'enriching',
    'finalizing',
  ]);
  requiredNumber(value, 'progressPercent');
}

function guardPartialSubtitleCue(value: unknown): void {
  const cue = record(value, 'partial subtitle cue');

  requiredString(cue, 'cueId');
  requiredNumber(cue, 'index');
  requiredNumber(cue, 'startMs');
  requiredNumber(cue, 'endMs');
  requiredString(cue, 'sourceText');
  optionalString(cue, 'translatedText');
  optionalString(cue, 'romanization');
}

function guardSubtitleCue(value: unknown): SubtitleCue {
  const cue = record(value, 'subtitle cue');
  const tokens = nonEmptyArray(cue.tokens, 'cue tokens');

  requiredString(cue, 'cueId');
  requiredNumber(cue, 'index');
  requiredNumber(cue, 'startMs');
  requiredNumber(cue, 'endMs');
  requiredString(cue, 'sourceText');
  requiredString(cue, 'translatedText');
  optionalString(cue, 'romanization');
  tokens.forEach(guardLearningToken);

  return cue as unknown as SubtitleCue;
}

function guardLearningToken(value: unknown): LearningToken {
  const token = record(value, 'learning token');

  requiredNumber(token, 'index');
  requiredString(token, 'text');
  requiredString(token, 'normalizedText');
  optionalString(token, 'lemma');
  optionalString(token, 'root');
  optionalString(token, 'partOfSpeech');
  optionalString(token, 'translation');
  optionalString(token, 'gloss');
  optionalString(token, 'romanization');
  optionalString(token, 'usageNote');

  return token as unknown as LearningToken;
}

function record(value: unknown, label: string): Record<string, unknown> {
  if (typeof value !== 'object' || value === null || Array.isArray(value)) {
    throw invalid(`Backend returned invalid ${label}.`);
  }

  return value as Record<string, unknown>;
}

function array(value: unknown, label: string): unknown[] {
  if (!Array.isArray(value)) {
    throw invalid(`Backend returned invalid ${label}.`);
  }

  return value;
}

function nonEmptyArray(value: unknown, label: string): unknown[] {
  const values = array(value, label);

  if (values.length === 0) {
    throw invalid(`Backend returned empty ${label}.`);
  }

  return values;
}

function requiredString(value: Record<string, unknown>, key: string): void {
  if (typeof value[key] !== 'string' || value[key] === '') {
    throw invalid(`Backend response field ${key} must be a non-empty string.`);
  }
}

function optionalString(value: Record<string, unknown>, key: string): void {
  if (key in value && value[key] !== undefined && typeof value[key] !== 'string') {
    throw invalid(`Backend response field ${key} must be a string.`);
  }
}

function requiredNumber(value: Record<string, unknown>, key: string): void {
  if (typeof value[key] !== 'number' || !Number.isFinite(value[key])) {
    throw invalid(`Backend response field ${key} must be a finite number.`);
  }
}

function optionalNumber(value: Record<string, unknown>, key: string): void {
  if (key in value && value[key] !== undefined && (typeof value[key] !== 'number' || !Number.isFinite(value[key]))) {
    throw invalid(`Backend response field ${key} must be a finite number.`);
  }
}

function requiredBoolean(value: Record<string, unknown>, key: string): void {
  if (typeof value[key] !== 'boolean') {
    throw invalid(`Backend response field ${key} must be a boolean.`);
  }
}

function literal<TValue extends string | boolean>(value: Record<string, unknown>, key: string, expected: TValue): void {
  if (value[key] !== expected) {
    throw invalid(`Backend response field ${key} must be ${String(expected)}.`);
  }
}

function forbidden(value: Record<string, unknown>, key: string, status: string): void {
  if (key in value) {
    throw invalid(`Backend response field ${key} must be absent when status is ${status}.`);
  }
}

function oneOf<TValue extends string>(value: Record<string, unknown>, key: string, allowed: readonly TValue[]): void {
  if (typeof value[key] !== 'string' || !allowed.includes(value[key] as TValue)) {
    throw invalid(`Backend response field ${key} has an unsupported value.`);
  }
}

function invalid(message: string): TypeError {
  return new TypeError(message);
}
