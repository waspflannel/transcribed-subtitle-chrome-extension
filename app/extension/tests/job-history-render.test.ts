import { describe, expect, it } from 'vitest';
import { JSDOM } from 'jsdom';

import { renderJobHistory } from '../entrypoints/sidepanel/render/job-history';
import { anonymousAccountState } from '../utils/account-state';
import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import type { PanelState } from '../utils/messages';
import type { SubtitleJobHistoryItem } from '../utils/contracts';

function makeJob(overrides: Partial<SubtitleJobHistoryItem> = {}): SubtitleJobHistoryItem {
  return {
    jobId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3001',
    trackId: '018f9e2f-0d8c-7500-8f38-9f4c5d1b3002',
    youtubeVideoId: 'dQw4w9WgXcQ',
    youtubeUrl: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    status: 'completed',
    startedAt: '2026-05-21T00:00:00Z',
    lastUpdatedAt: '2026-05-21T00:02:00Z',
    completedAt: '2026-05-21T00:02:00Z',
    stage: 'finalizing',
    progressPercent: 100,
    sourceLanguage: 'auto',
    detectedSourceLanguage: 'spa',
    targetLanguage: 'eng',
    enrichmentMode: 'on_demand',
    includeRomanization: true,
    includeTranslation: false,
    ...overrides,
  };
}

function stateWithJob(): PanelState {
  const jobs = [makeJob()];
  return {
    installId: 'i',
    settings: DEFAULT_EXTENSION_SETTINGS,
    accountState: anonymousAccountState(),
    subtitleState: { type: 'no-track' },
    jobHistory: jobs,
  };
}

describe('renderJobHistory links', () => {
  it('renders each job as a link to its YouTube URL', () => {
    const dom = new JSDOM('<div id="list"></div><p id="err"></p>');
    const jobsList = dom.window.document.getElementById('list')!;
    const jobsError = dom.window.document.getElementById('err')!;

    renderJobHistory(stateWithJob(), { jobsList, jobsError });

    const link = jobsList.querySelector('a.job-open-link') as HTMLAnchorElement | null;
    expect(link).not.toBeNull();
    expect(link!.getAttribute('href')).toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
    expect(link!.getAttribute('target')).toBe('_blank');
  });
});
