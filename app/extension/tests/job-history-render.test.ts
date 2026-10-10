import { describe, expect, it } from 'vitest';
import { JSDOM } from 'jsdom';

import { renderJobHistory } from '../entrypoints/sidepanel/render/job-history';
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
    aiProvider: 'openai',
    aiModel: 'gpt-6-luna',
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
    backendUrl: 'http://127.0.0.1:8001/v1',
    subtitleState: { type: 'no-track' },
    jobHistory: jobs,
  };
}

describe('renderJobHistory links', () => {
  it('labels saved Codex model and fast mode independently from next-generation API settings', () => {
    const dom = new JSDOM('<div id="list"></div><p id="err"></p>');
    const jobsList = dom.window.document.getElementById('list')!;
    renderJobHistory({ ...stateWithJob(), jobHistory: [makeJob({ aiProvider: 'codex', aiModel: 'saved-model', aiFastMode: true })] }, {
      jobsList, jobsError: dom.window.document.getElementById('err')!,
    });
    expect(jobsList.textContent).toContain('Codex · saved-model · Fast mode');
    expect(jobsList.textContent).not.toContain('OpenAI');
    dom.window.close();
  });
  it('labels Claude jobs without an API suffix', () => {
    const dom = new JSDOM('<div id="list"></div><p id="err"></p>');
    const jobsList = dom.window.document.getElementById('list')!;
    renderJobHistory({ ...stateWithJob(), jobHistory: [makeJob({ aiProvider: 'claude', aiModel: 'sonnet' })] }, {
      jobsList, jobsError: dom.window.document.getElementById('err')!,
    });
    expect(jobsList.textContent).toContain('Claude · sonnet');
    expect(jobsList.textContent).not.toContain('Claude API');
    dom.window.close();
  });
  it.each(['dQw4w9WgXcQ', 'other000001'])('only opens the selected video when the active video is %s', (activeVideoId) => {
    const dom = new JSDOM('<div id="list"></div><p id="err"></p>');
    const jobsList = dom.window.document.getElementById('list')!;
    const jobsError = dom.window.document.getElementById('err')!;
    const failed = { status: 'failed' as const, errorCode: 'transcription_failed' as const, message: 'Failed.' };
    const state: PanelState = {
      ...stateWithJob(),
      pageStatus: parseYoutubePage(`https://www.youtube.com/watch?v=${activeVideoId}`),
      settings: { ...DEFAULT_EXTENSION_SETTINGS, sourceLanguage: 'spa', targetLanguage: 'fra', aiProvider: 'cerebras' },
      jobHistory: [
        makeJob({ ...failed, sourceLanguage: 'jpn', includeTranslation: true }),
        makeJob({ ...failed, sourceLanguage: 'kor', youtubeVideoId: 'other000001', youtubeUrl: 'https://www.youtube.com/watch?v=other000001' }),
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
    expect(jobsList.textContent).toContain('OpenAI');
    expect(jobsList.textContent).not.toContain('Cerebras');
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

  it('shows cancellation progress and an action for owned active jobs', () => {
    const dom = new JSDOM('<div id="list"></div><p id="err"></p>');
    const jobsList = dom.window.document.getElementById('list')!;
    const jobsError = dom.window.document.getElementById('err')!;
    const job = makeJob({ status: 'running', progressPercent: 42, stage: 'transcribing' });

    renderJobHistory({ ...stateWithJob(), jobHistory: [job] }, { jobsList, jobsError, cancellationBusy: true });

    const cancelButton = jobsList.querySelector('[data-action="cancel-generation"]') as HTMLButtonElement | null;
    expect(cancelButton?.disabled).toBe(true);
    expect(cancelButton?.textContent).toContain('Cancelling');
    expect(jobsList.textContent).toContain('Generating · 42%');

    renderJobHistory({ ...stateWithJob(), jobHistory: [makeJob({
      status: 'cancelled', progressPercent: 42, stage: 'transcribing', errorCode: 'generation_cancelled',
      message: 'Generation cancelled.',
    })] }, { jobsList, jobsError });
    expect(jobsList.textContent).toContain('Cancelled');
    expect(jobsList.textContent).toContain('Generation cancelled.');
    expect(jobsList.querySelector('[data-action="cancel-generation"]')).toBeNull();
  });
});
