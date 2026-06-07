import { describe, expect, it } from 'vitest';

import {
  accountStateFromJobHistory,
  accountStateFromSummary,
  formatJobTiming,
  formatResetDate,
  publicJobTelemetry,
  stageTimeline,
} from '../utils/popup-saas-state';
import type { SubtitleJobHistoryItem } from '../utils/contracts';

describe('popup SaaS state helpers', () => {
  it('projects anonymous usage from public-safe completed and running job durations', () => {
    const account = accountStateFromJobHistory(
      [
        jobHistory({ status: 'completed', videoDurationSeconds: 121 }),
        jobHistory({ status: 'running', videoDurationSeconds: 60 }),
        jobHistory({ status: 'failed', videoDurationSeconds: 600 }),
      ],
      new Date('2026-05-21T12:00:00Z'),
    );

    expect(account).toMatchObject({
      status: 'anonymous',
      planName: 'Local beta',
      tierName: 'Base',
      monthlyMinuteLimit: 60,
      monthlyMinutesUsed: 3,
      monthlyMinutesPending: 1,
      monthlyMinutesRemaining: 56,
      resetAt: '2026-06-01T00:00:00.000Z',
    });
    expect(formatResetDate(account.resetAt)).toBe('Jun 1');
  });

  it('uses authenticated account summaries from the backend without local install identity', () => {
    expect(
      accountStateFromSummary({
        status: 'authenticated',
        id: '1',
        email: 'learner@example.com',
        name: 'Beta Learner',
        emailVerified: true,
        planName: 'Local beta',
        tierName: 'Base',
        tierSpeedLabel: 'Standard queue',
        monthlyMinuteLimit: 60,
        monthlyMinutesUsed: 10,
        monthlyMinutesPending: 2,
        monthlyMinutesRemaining: 48,
        resetAt: '2026-06-01T00:00:00.000Z',
        upgradeAvailable: true,
      }),
    ).toMatchObject({
      status: 'authenticated',
      email: 'learner@example.com',
      monthlyMinutesUsed: 10,
      monthlyMinutesPending: 2,
      monthlyMinutesRemaining: 48,
    });
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
