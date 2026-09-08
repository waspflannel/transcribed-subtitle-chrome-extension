// @vitest-environment jsdom
import { readFileSync } from 'node:fs';
import { afterEach, expect, it, vi } from 'vitest';
import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import type { PanelState } from '../utils/messages';
import type { TrackResponse } from '../utils/contracts';

const transport = vi.hoisted(() => ({ sendMessage: vi.fn() }));
vi.mock('wxt/browser', () => ({ browser: {
  runtime: {
    sendMessage: transport.sendMessage,
    connect: () => ({ onDisconnect: { addListener: vi.fn() } }),
    onMessage: { addListener: vi.fn() },
  },
  windows: { getCurrent: async () => ({ id: 1 }) },
  tabs: { onActivated: { addListener: vi.fn() }, onUpdated: { addListener: vi.fn() } },
} }));

afterEach(() => { vi.useRealTimers(); });

it('shows a failure recovered on opening, preserves dismissal across navigation, and shows a newer outcome', async () => {
  vi.useFakeTimers();
  document.documentElement.innerHTML = readFileSync('entrypoints/sidepanel/index.html', 'utf8');
  const track = JSON.parse(readFileSync('../../packages/contracts/fixtures/valid-track-response.json', 'utf8')) as TrackResponse;
  const state: PanelState = {
    installId: 'install_test', settings: DEFAULT_EXTENSION_SETTINGS, activeTabId: 1,
    pageStatus: { supported: true, videoId: track.youtubeVideoId, url: `https://www.youtube.com/watch?v=${track.youtubeVideoId}`, mediaKind: 'video' },
    accountState: {
      status: 'authenticated', id: 'user', email: 'test@example.com', name: 'Test', emailVerified: true,
      planName: 'Pro', tierName: 'pro', tierSpeedLabel: 'Fast', monthlyMinuteLimit: 100,
      monthlyMinutesUsed: 0, monthlyMinutesPending: 0, monthlyMinutesRemaining: 100,
      resetAt: '2026-10-01T00:00:00Z', upgradeAvailable: false,
    },
    subtitleState: { type: 'ready', track }, jobHistory: [],
    lyricsCorrection: { attemptId: 'attempt-1', status: 'failed', stage: 'failed', errorCode: 'lyrics_correction_failed', message: 'Replacement failed.', updatedAt: '2026-09-07T13:50:43Z' },
  };
  transport.sendMessage.mockImplementation(async () => structuredClone(state));
  await import('../entrypoints/sidepanel/main');
  await vi.advanceTimersByTimeAsync(0);
  const notice = document.querySelector<HTMLElement>('[data-correction-terminal-status]')!;
  const click = (action: string) => document.querySelector<HTMLButtonElement>(`[data-action="${action}"]`)!.click();
  expect(notice.hidden).toBe(false);
  expect(notice.textContent).toContain('Replacement failed');

  click('toggle-lyrics-edit');
  click('back-transcript');
  expect(notice.hidden).toBe(false);
  click('dismiss-correction-status');
  click('toggle-lyrics-edit');
  click('back-transcript');
  expect(notice.hidden).toBe(true);

  state.lyricsCorrection = { ...state.lyricsCorrection!, attemptId: 'attempt-2' };
  state.lyricsCorrectionSyncError = 'Could not refresh replacement status. Retrying automatically.';
  document.dispatchEvent(new Event('visibilitychange'));
  await vi.advanceTimersByTimeAsync(0);
  expect(notice.hidden).toBe(false);
  const syncError = document.querySelector<HTMLElement>('[data-correction-sync-error]')!;
  expect(syncError.hidden).toBe(false);
  expect(syncError.textContent).toContain('Retrying');
  delete state.lyricsCorrectionSyncError;
  document.dispatchEvent(new Event('visibilitychange'));
  await vi.advanceTimersByTimeAsync(0);
  expect(syncError.hidden).toBe(true);
  expect(document.querySelector<HTMLElement>('[data-watch-ready]')!.hidden).toBe(false);
});
