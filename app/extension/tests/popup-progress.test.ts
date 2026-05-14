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
    expect(generationProgress({ stage: 'translating', progressPercent: 88 }).stageLabel).toBe('Translating subtitles');
    expect(generationProgress({ stage: 'enriching', progressPercent: 75 }).stageLabel).toBe('Generating word cards');
  });

  it('fails loudly for invalid history timestamps', () => {
    expect(() => formatHistoryTimestamp('not-a-date')).toThrow('Invalid history timestamp');
  });
});
