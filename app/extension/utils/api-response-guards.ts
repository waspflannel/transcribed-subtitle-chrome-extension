import type {
  CodexAccount,
  InstanceSettings,
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



export function guardOkResponse(value: unknown): { ok: true } {
  const response = record(value, 'ok response');

  literal(response, 'ok', true);

  return response as { ok: true };
}

export function guardJobResponse(value: unknown): JobResponse {
  const response = record(value, 'job response');

  guardJobCore(response);
  requiredString(response, 'jobId');
  requiredString(response, 'createdAt');
  requiredString(response, 'updatedAt');
  if (response.expiresAt !== null) optionalString(response, 'expiresAt');
  optionalString(response, 'message');
  optionalString(response, 'errorCode');

  if (response.partialTrack !== undefined) {
    const partial = guardPartialTrackResponse(response.partialTrack);
    if (response.status !== 'running' || partial.jobId !== response.jobId || partial.youtubeVideoId !== response.youtubeVideoId) {
      throw invalid('Partial track does not belong to the running job');
    }
  }

  if (response.status === 'completed') {
    guardTrackResponse(response.track);
    if (response.expiresAt !== null) requiredString(response, 'expiresAt');
  } else {
    forbidden(response, 'track', String(response.status));
  }

  if (response.status === 'failed' || response.status === 'cancelled') {
    requiredString(response, 'errorCode');
    requiredString(response, 'message');
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
  if (response.expiresAt !== null) requiredString(response, 'expiresAt');
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
  const revision = requiredNumber(response, 'revision');
  if (!Number.isInteger(revision) || revision < 1) throw invalid('Invalid preview revision');
  if (response.readyThroughMs !== undefined) {
    const readyThroughMs = requiredNumber(response, 'readyThroughMs');
    if (!Number.isInteger(readyThroughMs) || readyThroughMs < 0) throw invalid('Invalid preview coverage');
  }
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
  const allowedKeys = ['attemptId', 'status', 'stage', 'updatedAt', 'track', 'errorCode', 'message', 'aiProvider', 'aiModel', 'aiFastMode'];

  if (Object.keys(response).some((key) => !allowedKeys.includes(key))) {
    throw invalid('Backend returned unknown lyrics correction status fields.');
  }

  requiredString(response, 'attemptId');
  oneOf(response, 'status', ['queued', 'running', 'completed', 'failed', 'cancelled']);
  requiredString(response, 'updatedAt');
  if (response.aiProvider !== undefined || response.aiModel !== undefined || response.aiFastMode !== undefined) {
    oneOf(response, 'aiProvider', ['openai', 'cerebras', 'codex', 'claude']);
    requiredString(response, 'aiModel');
    requiredBoolean(response, 'aiFastMode');
    if (response.aiProvider !== 'codex' && response.aiFastMode) throw invalid('Fast mode requires Codex.');
  }

  if (response.status === 'queued') oneOf(response, 'stage', ['queued']);
  if (response.status === 'running') oneOf(response, 'stage', ['aligning', 'rebuilding', 'romanizing', 'finalizing']);
  if (response.status === 'completed') oneOf(response, 'stage', ['completed']);
  if (response.status === 'failed') oneOf(response, 'stage', ['failed']);
  if (response.status === 'cancelled') oneOf(response, 'stage', ['cancelled']);

  if (response.status === 'queued' || response.status === 'running' || response.status === 'cancelled') {
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
    oneOf(response, 'errorCode', ['lyrics_incomplete', 'lyrics_do_not_match', 'lyrics_correction_failed']);
    requiredString(response, 'message');
    forbidden(response, 'track', response.status);
  }

  return response as LyricsCorrectionStatus;
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
  if (item.expiresAt !== null) optionalString(item, 'expiresAt');
  optionalString(item, 'message');
  optionalString(item, 'errorCode');

  if (item.status === 'cancelled') {
    requiredString(item, 'errorCode');
    requiredString(item, 'message');
  }

  return item as SubtitleJobHistoryItem;
}

function guardJobCore(value: Record<string, unknown>): void {
  requiredString(value, 'youtubeVideoId');
  optionalNumber(value, 'videoDurationSeconds');
  requiredString(value, 'sourceLanguage');
  optionalString(value, 'detectedSourceLanguage');
  requiredString(value, 'targetLanguage');
  oneOf(value, 'aiProvider', ['openai', 'cerebras', 'codex', 'claude']);
  requiredString(value, 'aiModel');
  if (value.aiFastMode !== undefined) requiredBoolean(value, 'aiFastMode');
  requiredBoolean(value, 'includeRomanization');
  requiredBoolean(value, 'includeTranslation');
  oneOf(value, 'status', ['queued', 'running', 'completed', 'failed', 'cancelled']);
  oneOf(value, 'stage', [
    'preparing',
    'acquiring-audio',
    'optimizing-audio',
    'transcribing',
    'tokenizing',
    'romanizing',
    'translating',
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
  requiredString(cue, 'translatedText', true);
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

function requiredString(value: Record<string, unknown>, key: string, allowEmpty = false): void {
  if (typeof value[key] !== 'string' || (!allowEmpty && value[key] === '')) {
    throw invalid(`Backend response field ${key} must be a non-empty string.`);
  }
}

function optionalString(value: Record<string, unknown>, key: string): void {
  if (key in value && value[key] !== undefined && typeof value[key] !== 'string') {
    throw invalid(`Backend response field ${key} must be a string.`);
  }
}

function requiredNumber(value: Record<string, unknown>, key: string): number {
  if (typeof value[key] !== 'number' || !Number.isFinite(value[key])) {
    throw invalid(`Backend response field ${key} must be a finite number.`);
  }
  return value[key] as number;
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

export function guardInstanceSettings(value: unknown): InstanceSettings {
  const settings = record(value, 'instance settings');
  const providers = record(settings.providers, 'providers');
  if (Object.keys(settings).some(key => key !== 'providers' && key !== 'retentionDays')) throw invalid('Unexpected settings fields');
  if (Object.keys(providers).some(key => !['openai', 'cerebras', 'elevenlabs', 'claude'].includes(key))) throw invalid('Unexpected provider');
  for (const name of ['openai', 'cerebras', 'elevenlabs', 'claude']) {
    const provider = record(providers[name], name);
    requiredBoolean(provider, 'configured');
    if (name === 'claude') requiredBoolean(provider, 'available');
    else requiredString(provider, 'model');
    const allowed = name === 'claude' ? ['configured', 'available'] : ['configured', 'model'];
    if (Object.keys(provider).some(key => !allowed.includes(key))) throw invalid('Provider response contains unexpected fields');
  }
  if (settings.retentionDays !== null && (!Number.isInteger(settings.retentionDays) || Number(settings.retentionDays) < 1)) throw invalid('Invalid retention');
  return settings as unknown as InstanceSettings;
}

export function guardCodexAccount(value: unknown): CodexAccount {
  const account = record(value, 'Codex account');
  if (Object.keys(account).some(key => !['available', 'connected', 'models', 'login', 'error'].includes(key))) throw invalid('Unexpected Codex account fields');
  requiredBoolean(account, 'available');
  requiredBoolean(account, 'connected');
  optionalString(account, 'error');
  for (const value of array(account.models, 'Codex models')) {
    const model = record(value, 'Codex model');
    requiredString(model, 'id');
    requiredString(model, 'name');
    requiredBoolean(model, 'supportsFastMode');
    if (!/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/.test(String(model.id)) || Object.keys(model).some(key => !['id', 'name', 'supportsFastMode'].includes(key))) throw invalid('Invalid Codex model');
  }
  if (account.login !== null) {
    const login = record(account.login, 'Codex login');
    oneOf(login, 'status', ['pending', 'awaiting_authorization', 'failed']);
    optionalString(login, 'authUrl');
    if (Object.keys(login).some(key => !['status', 'authUrl', 'verificationUrl', 'userCode'].includes(key))) throw invalid('Unexpected Codex login fields');
    // An old worker can finish a device-code attempt during an upgrade. Discard its link and allow a fresh login.
    if ('verificationUrl' in login || 'userCode' in login) return { ...account, login: { status: 'failed' }, error: 'Restart the backend, then disconnect Codex and sign in again.' } as unknown as CodexAccount;
    if (typeof login.authUrl === 'string') {
      if (login.authUrl.length > 4096 || /[\s\u0000-\u001f\u007f\\]/.test(login.authUrl)
        || !/^https:\/\/(?:auth\.openai\.com|chatgpt\.com)(?:[/?#]|$)/.test(login.authUrl)) throw invalid('Invalid Codex sign-in URL');
      const url = new URL(login.authUrl);
      if (url.protocol !== 'https:' || !['auth.openai.com', 'chatgpt.com'].includes(url.hostname) || url.username || url.password || url.port) throw invalid('Invalid Codex sign-in URL');
    }
  }
  return account as unknown as CodexAccount;
}
