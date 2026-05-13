import { describe, expect, it } from 'vitest';

import { formatHistoryTimestamp, generationProgress } from '../utils/popup-progress';

describe('popup progress helpers', () => {
  it('maps backend stages to user-facing labels', () => {
    expect(generationProgress({ stage: 'romanizing', progressPercent: 82 })).toEqual({
      percent: 82,
      stageLabel: 'Adding romanization',
      activityLabel: 'Active now',
    });
    expect(generationProgress({ stage: 'tokenizing', progressPercent: 65 }).stageLabel).toBe('Tokenizing subtitles');
    expect(generationProgress({ stage: 'enriching', progressPercent: 75 }).stageLabel).toBe('Generating word cards');
  });

  it('formats invalid history timestamps defensively', () => {
    expect(formatHistoryTimestamp('not-a-date')).toBe('Unknown time');
  });
});
