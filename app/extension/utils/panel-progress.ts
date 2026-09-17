import { t, interfaceLocale } from './i18n';
import type { SubtitleJobHistoryItem } from './contracts';

export interface GenerationProgress {
  percent: number;
  stageLabel: string;
  activityLabel: string;
}

export function generationProgress(job?: Pick<SubtitleJobHistoryItem, 'progressPercent' | 'stage'> & Partial<Pick<SubtitleJobHistoryItem, 'status'>>): GenerationProgress {
  return {
    percent: Math.max(0, Math.min(100, job?.progressPercent ?? 0)),
    stageLabel: job?.stage ? stageLabel(job.stage) : t("Preparing request"),
    activityLabel: job?.status === 'cancelled'
      ? t("Cancelled")
      : job?.status === 'queued'
      ? t("Waiting for a generation slot")
      : job?.progressPercent === 100 ? t("Completed") : t("Active now"),
  };
}

export function formatHistoryTimestamp(value: string): string {
  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    throw new Error(`Invalid history timestamp: ${value}`);
  }

  return date.toLocaleString(interfaceLocale(), {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

export function stageLabel(stage: NonNullable<SubtitleJobHistoryItem['stage']>): string {
  switch (stage) {
    case 'preparing':
      return t("Preparing request");

    case 'acquiring-audio':
      return t("Acquiring audio");

    case 'optimizing-audio':
      return t("Optimizing audio");

    case 'transcribing':
      return t("Transcribing audio");

    case 'tokenizing':
      return t("Analyzing subtitles");

    case 'romanizing':
      return t("Adding romanization");

    case 'translating':
      return t("Translating subtitles");


    case 'finalizing':
      return t("Finalizing track");
  }
}
