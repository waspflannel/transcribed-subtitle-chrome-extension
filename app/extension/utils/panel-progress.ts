import type { SubtitleJobHistoryItem } from './contracts';

export const GENERATION_STAGES = [
  'preparing',
  'acquiring-audio',
  'optimizing-audio',
  'transcribing',
  'tokenizing',
  'romanizing',
  'translating',
  'enriching',
  'finalizing',
] as const satisfies readonly SubtitleJobHistoryItem['stage'][];

export interface GenerationProgress {
  percent: number;
  stageLabel: string;
  activityLabel: string;
}

export function generationProgress(job?: Pick<SubtitleJobHistoryItem, 'progressPercent' | 'stage'> & Partial<Pick<SubtitleJobHistoryItem, 'status'>>): GenerationProgress {
  return {
    percent: Math.max(0, Math.min(100, job?.progressPercent ?? 0)),
    stageLabel: job?.stage ? stageLabel(job.stage) : 'Preparing request',
    activityLabel: job?.status === 'cancelled'
      ? 'Cancelled'
      : job?.status === 'queued'
      ? 'Waiting for a generation slot'
      : job?.progressPercent === 100 ? 'Completed' : 'Active now',
  };
}

export function formatHistoryTimestamp(value: string): string {
  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    throw new Error(`Invalid history timestamp: ${value}`);
  }

  return date.toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

export function stageLabel(stage: NonNullable<SubtitleJobHistoryItem['stage']>): string {
  switch (stage) {
    case 'preparing':
      return 'Preparing request';

    case 'acquiring-audio':
      return 'Acquiring audio';

    case 'optimizing-audio':
      return 'Optimizing audio';

    case 'transcribing':
      return 'Transcribing audio';

    case 'tokenizing':
      return 'Tokenizing subtitles';

    case 'romanizing':
      return 'Adding romanization';

    case 'translating':
      return 'Translating subtitles';

    case 'enriching':
      return 'Generating word cards';

    case 'finalizing':
      return 'Finalizing track';
  }
}
