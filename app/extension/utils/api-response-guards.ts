import type {
  ClaudeAccount,
  CodexAccount,
  InstanceSettings,
  JobResponse,
  LearningTokenResponse,
  LyricsCorrectionStatus,
  PartialTrackResponse,
  SubtitleJobHistoryResponse,
  TrackResponse,
} from './contracts';



import * as validators from '../../../packages/contracts/dist/validators.js';

function validate<T>(value: unknown, validator: (value: unknown) => boolean): T {
  if (!validator(value)) throw new TypeError('Backend returned an invalid response.');
  return value as T;
}

export function guardOkResponse(value: unknown): { ok: true } {
  return validate(value, validators.okResponse);
}

export function guardJobResponse(value: unknown): JobResponse {
  const response = validate<JobResponse>(value, validators.jobResponse);
  if (response.partialTrack && (response.partialTrack.jobId !== response.jobId || response.partialTrack.youtubeVideoId !== response.youtubeVideoId)) {
    throw new TypeError('Partial track does not belong to the running job.');
  }
  return response;
}

export function guardSubtitleJobHistoryResponse(value: unknown): SubtitleJobHistoryResponse {
  const response = validate<SubtitleJobHistoryResponse>(value, validators.subtitleJobHistoryResponse);
  return response;
}

export function guardTrackResponse(value: unknown): TrackResponse {
  const response = validate<TrackResponse>(value, validators.trackResponse);
  return response;
}

export function guardPartialTrackResponse(value: unknown): PartialTrackResponse {
  const response = validate<PartialTrackResponse>(value, validators.partialTrackResponse);
  return response;
}

export function guardLearningTokenResponse(value: unknown): LearningTokenResponse {
  const response = validate<LearningTokenResponse>(value, validators.learningTokenResponse);
  return response;
}

export function guardLyricsCorrectionStatus(value: unknown): LyricsCorrectionStatus {
  const response = validate<LyricsCorrectionStatus>(value, validators.lyricsCorrectionStatus);
  return response;
}

export function guardInstanceSettings(value: unknown): InstanceSettings {
  const response = validate<InstanceSettings>(value, validators.instanceSettings);
  return response;
}

export function guardCodexAccount(value: unknown): CodexAccount {
  // Discard retired device-code links left by an older worker.
  if (value && typeof value === 'object' && 'login' in value && value.login && typeof value.login === 'object'
    && ('verificationUrl' in value.login || 'userCode' in value.login)) {
    value = { ...value, login: { status: 'failed' }, error: 'Restart the backend, then disconnect Codex and sign in again.' };
  }
  const response = validate<CodexAccount>(value, validators.codexAccount);
  return response;
}

export function guardClaudeAccount(value: unknown): ClaudeAccount {
  const response = validate<ClaudeAccount>(value, validators.claudeAccount);
  return response;
}
