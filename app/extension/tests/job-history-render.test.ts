import { describe, expect, it } from 'vitest';
import { JSDOM } from 'jsdom';

import { renderJobHistory } from '../entrypoints/sidepanel/render/job-history';
import { anonymousAccountState } from '../utils/account-state';
import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import type { PanelState } from '../utils/messages';
import type { SubtitleJobHistoryItem } from '../utils/contracts';
import { parseYoutubePage } from '../utils/youtube';

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
  it.each(['dQw4w9WgXcQ', 'other000001'])('only opens the selected video when the active video is %s', (activeVideoId) => {
    const dom = new JSDOM('<div id="list"></div><p id="err"></p>');
    const jobsList = dom.window.document.getElementById('list')!;
    const jobsError = dom.window.document.getElementById('err')!;
    const failed = { status: 'failed' as const, errorCode: 'transcription_failed' as const, message: 'Failed.' };
    const state: PanelState = {
      ...stateWithJob(),
      pageStatus: parseYoutubePage(`https://www.youtube.com/watch?v=${activeVideoId}`),
      settings: { ...DEFAULT_EXTENSION_SETTINGS, sourceLanguage: 'spa', targetLanguage: 'fra' },
      jobHistory: [
        makeJob({ ...failed, sourceLanguage: 'jpn', includeTranslation: true }),
        makeJob({ ...failed, sourceLanguage: 'kor', enrichmentMode: 'full', youtubeVideoId: 'other000001', youtubeUrl: 'https://www.youtube.com/watch?v=other000001' }),
      ],
    };

    renderJobHistory(state, { jobsList, jobsError });

    const links = [...jobsList.querySelectorAll<HTMLAnchorElement>('.job-actions a')];
    expect(links.map((link) => link.href)).toEqual(state.jobHistory.map((job) => job.youtubeUrl));
    for (const link of links) {
      expect(link.textContent).toBe('Open video');
      expect(link.target).toBe('_blank');
      expect(link.rel).toContain('noopener');
    }
    expect(jobsList.querySelector('button, form, [data-action]')).toBeNull();
    expect(jobsList.textContent).not.toContain('Retry');
    expect(jobsList.textContent).toContain("This job's options are not restored.");
    expect(jobsList.textContent).toContain('review Watch settings');
  });

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

  it('keeps the last successful list visible beside a refresh error', () => {
    const dom = new JSDOM('<div id="list"></div><p id="err"></p>');
    const jobsList = dom.window.document.getElementById('list')!;
    const jobsError = dom.window.document.getElementById('err')!;

    renderJobHistory({ ...stateWithJob(), jobHistoryError: 'Unable to refresh jobs.' }, { jobsList, jobsError });

    expect(jobsError.textContent).toBe('Unable to refresh jobs.');
    expect(jobsList.textContent).toContain('Ready');
    expect(jobsList.textContent).not.toContain('Nothing generated yet');
  });
});
