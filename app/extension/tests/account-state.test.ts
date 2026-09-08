import { describe, expect, it, vi } from 'vitest';
import { JSDOM } from 'jsdom';

import {
  anonymousAccountState,
  accountStateFromSummary,
  formatJobTiming,
  formatResetDate,
  publicJobTelemetry,
  stageTimeline,
} from '../utils/account-state';
import { accountFeatureListHtml, accountBillingLinkHtml } from '../entrypoints/sidepanel/render/account';
import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import type { AccountSummary, SubtitleJobHistoryItem } from '../utils/contracts';

describe('account and job-history state helpers', () => {
  it('uses the build-configured backend origin for billing without an override', () => {
    vi.stubEnv('WXT_BACKEND_API_BASE_URL', 'https://configured.example.test/v1');
    try {
      expect(accountBillingLinkHtml()).toContain('href="https://configured.example.test/dashboard"');
    } finally {
      vi.unstubAllEnvs();
    }
  });

  it.each(['http://localhost:8123/v1', 'https://account.example.test/api/v1'])('links only account and billing to the configured origin %s', (baseUrl) => {
    const dom = new JSDOM(accountBillingLinkHtml(baseUrl));
    const links = [...dom.window.document.querySelectorAll('a')];
    expect(links.map((link) => link.href)).toEqual(
      [new URL(baseUrl).origin + '/dashboard'],
    );
    expect(links.map((link) => link.textContent)).toEqual(['Account and billing']);
    for (const link of links) {
      expect(link.target).toBe('_blank');
      expect(link.rel).toBe('noopener noreferrer');
      expect(new URL(link.href).search).toBe('');
    }
  });

  it('describes selected preferences without promising entitlement to a signed-in account', () => {
    const account = accountStateFromSummary({
      ...accountSummary(), planName: 'No active plan', monthlyMinutesRemaining: 0, tierSpeedLabel: 'Generation paused',
    });
    const html = accountFeatureListHtml(account, {
      ...DEFAULT_EXTENSION_SETTINGS, showTranslation: true, showRomanization: false,
    });
    const rows = [...new JSDOM(html).window.document.querySelectorAll('.feature-row')];
    expect(rows.map((row) => row.querySelector('strong')!.textContent)).toEqual([
      'Checked when you generate', 'Selected', 'Not selected', 'On click', 'Generation paused',
    ]);
    expect(html).not.toMatch(/Enabled|Available|Included|Upgrade preview/);
  });

  it('uses an honest anonymous account state without fabricated usage', () => {
    expect(anonymousAccountState()).toEqual({ status: 'anonymous' });
  });

  it('shows a signed-out account state without implying that a plan is active', () => {
    expect(accountFeatureListHtml(anonymousAccountState(), DEFAULT_EXTENSION_SETTINGS)).toContain('Account');
    expect(accountFeatureListHtml(anonymousAccountState(), DEFAULT_EXTENSION_SETTINGS)).toContain('Available after sign-in');
    expect(accountFeatureListHtml(anonymousAccountState(), DEFAULT_EXTENSION_SETTINGS)).not.toContain('Free tier');
  });

  it('uses authenticated account summaries from the backend without local install identity', () => {
    const account = accountStateFromSummary(accountSummary());

    expect(account).toMatchObject({
      status: 'authenticated',
      email: 'learner@example.com',
      monthlyMinutesUsed: 10,
      monthlyMinutesPending: 2,
      monthlyMinutesRemaining: 48,
    });
    expect(account.status).toBe('authenticated');
    if (account.status !== 'authenticated') {
      throw new Error('Expected authenticated account state.');
    }
    expect(formatResetDate(account.resetAt)).toBe('Jun 1');
  });

  it('keeps job telemetry public-safe even when unexpected sensitive fields are present', () => {
    const telemetry = publicJobTelemetry({
      ...jobHistory({
        status: 'failed',
        errorCode: 'rate_limited',
        message: 'Provider limit exceeded.',
        videoDurationSeconds: 213,
        enrichmentMode: 'full',
        includeRomanization: true,
        includeTranslation: true,
      }),
      transcript: 'raw cue text',
      prompt: 'provider prompt',
      rawProviderPayload: { text: 'secret' },
      tokenPayload: [{ text: 'hola' }],
      installId: 'install_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
      rawAudioPath: 'C:/tmp/audio.wav',
    } as SubtitleJobHistoryItem);

    expect(JSON.stringify(telemetry)).not.toContain('raw cue text');
    expect(JSON.stringify(telemetry)).not.toContain('provider prompt');
    expect(JSON.stringify(telemetry)).not.toContain('install_');
    expect(JSON.stringify(telemetry)).not.toContain('audio.wav');
    expect(telemetry).toMatchObject({
      publicJobId: '018f9e2f...3001',
      errorMessage: 'Subtitle generation is temporarily rate limited. Wait a minute and try again.',
      videoDurationSeconds: 213,
    });
  });

  it('marks stage timelines and timing labels for running, completed, and failed jobs', () => {
    expect(stageTimeline(jobHistory({ status: 'running', stage: 'tokenizing' })).map((item) => item.state)).toEqual([
      'done',
      'done',
      'done',
      'done',
      'current',
      'pending',
      'pending',
      'pending',
      'pending',
    ]);
    expect(stageTimeline(jobHistory({ status: 'failed', stage: 'transcribing' }))[3]).toMatchObject({
      stage: 'transcribing',
      state: 'failed',
    });
    expect(formatJobTiming(jobHistory({ status: 'completed' }))).toBe('2m total');
  });
});

function jobHistory(overrides: Partial<SubtitleJobHistoryItem & {
  videoDurationSeconds: number;
  enrichmentMode: 'on_demand' | 'full';
  includeRomanization: boolean;
  includeTranslation: boolean;
}>): SubtitleJobHistoryItem {
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
    ...overrides,
    enrichmentMode: overrides.enrichmentMode ?? 'on_demand',
    includeRomanization: overrides.includeRomanization ?? true,
    includeTranslation: overrides.includeTranslation ?? false,
  };
}

function accountSummary(): AccountSummary {
  return {
    status: 'authenticated',
    id: '1',
    email: 'learner@example.com',
    name: 'Beta Learner',
    emailVerified: true,
    planName: 'Beta Base',
    tierName: 'Base',
    tierSpeedLabel: 'Standard queue',
    monthlyMinuteLimit: 60,
    monthlyMinutesUsed: 10,
    monthlyMinutesPending: 2,
    monthlyMinutesRemaining: 48,
    resetAt: '2026-06-01T00:00:00.000Z',
    upgradeAvailable: true,
  };
}
