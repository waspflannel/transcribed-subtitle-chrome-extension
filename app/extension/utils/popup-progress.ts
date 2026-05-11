import type { SubtitleJobHistoryItem } from './contracts';

export interface GenerationProgress {
  percent: number;
  stageLabel: string;
  activityLabel: string;
}

export function generationProgress(job?: Pick<SubtitleJobHistoryItem, 'progressPercent' | 'stage'>): GenerationProgress {
  return {
    percent: Math.max(0, Math.min(100, job?.progressPercent ?? 0)),
    stageLabel: job?.stage ? stageLabel(job.stage) : 'Preparing request',
    activityLabel: job?.progressPercent === 100 ? 'Completed' : 'Active now',
  };
}

export function formatHistoryTimestamp(value: string): string {
  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return 'Unknown time';
  }

  return date.toLocaleString(undefined, {
    month: 'short',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

function stageLabel(stage: NonNullable<SubtitleJobHistoryItem['stage']>): string {
  switch (stage) {
    case 'preparing':
      return 'Preparing request';

    case 'acquiring-audio':
      return 'Acquiring audio';

    case 'transcribing':
      return 'Transcribing audio';

    case 'romanizing':
      return 'Adding romanization';

    case 'enriching':
      return 'Generating word cards';

    case 'finalizing':
      return 'Finalizing track';
  }
}
