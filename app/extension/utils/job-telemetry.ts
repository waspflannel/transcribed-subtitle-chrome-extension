import { t } from './i18n';
import type { SubtitleJobHistoryItem } from './contracts';
import { publicSubtitleErrorMessage, SubtitleApiError } from './api';

export interface PublicJobTelemetry {
  videoDurationSeconds?: number;
  errorMessage?: string;
}



export function publicJobTelemetry(job: SubtitleJobHistoryItem): PublicJobTelemetry {
  const telemetry: PublicJobTelemetry = {
    errorMessage: failedJobPublicErrorMessage(job),
  };

  if (isPositiveInteger(job.videoDurationSeconds)) {
    telemetry.videoDurationSeconds = job.videoDurationSeconds;
  }

  return telemetry;
}

export function formatDurationSeconds(seconds: number | undefined): string {
  if (!isPositiveInteger(seconds)) {
    return t("Duration pending");
  }

  const minutes = Math.floor(seconds / 60);
  const remainder = seconds % 60;

  if (minutes === 0) {
    return `${remainder}s`;
  }

  return remainder === 0 ? `${minutes}m` : `${minutes}m ${remainder}s`;
}

export function formatJobTiming(job: Pick<SubtitleJobHistoryItem, 'startedAt' | 'lastUpdatedAt' | 'completedAt' | 'status'>): string {
  const startedAt = Date.parse(job.startedAt);
  const endedAt = Date.parse(job.completedAt ?? job.lastUpdatedAt);

  if (Number.isNaN(startedAt) || Number.isNaN(endedAt) || endedAt < startedAt) {
    throw new Error(t("Job timing contains invalid timestamps."));
  }

  const duration = formatDurationSeconds(Math.max(1, Math.round((endedAt - startedAt) / 1000)));
  return job.status === 'running' || job.status === 'queued'
    ? t('{duration} elapsed', { duration })
    : job.status === 'failed' ? t('{duration} until failure', { duration }) : t('{duration} total', { duration });
}


function failedJobPublicErrorMessage(job: SubtitleJobHistoryItem): string | undefined {
  if (job.status !== 'failed') {
    return undefined;
  }

  if (!job.errorCode || !job.message) {
    throw new Error(t("Failed job history item is missing public error fields."));
  }

  return publicSubtitleErrorMessage(new SubtitleApiError(job.errorCode, job.message, 500));
}


function isPositiveInteger(value: unknown): value is number {
  return typeof value === 'number' && Number.isInteger(value) && value > 0;
}
