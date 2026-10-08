// @vitest-environment jsdom
import { readFileSync } from 'node:fs';
import { beforeEach, expect, it, vi } from 'vitest';
import { bindLyricsAiOptions, correctionAiLabel } from '../entrypoints/sidepanel/lyrics-ai-options';
import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import type { PanelState } from '../utils/messages';
import type { SubtitleJobHistoryItem, TrackResponse } from '../utils/contracts';

const track = JSON.parse(readFileSync('../../packages/contracts/fixtures/valid-track-response.json', 'utf8')) as TrackResponse;
const originalJob = JSON.parse(readFileSync('../../packages/contracts/fixtures/valid-subtitle-job-history-response.json', 'utf8')).jobs[0] as SubtitleJobHistoryItem;
let state: PanelState;
const field = <T extends HTMLElement>(selector: string) => document.querySelector<T>(selector)!;
const change = (element: HTMLElement) => element.dispatchEvent(new Event('change'));

beforeEach(() => {
  document.documentElement.innerHTML = readFileSync('entrypoints/sidepanel/index.html', 'utf8');
  state = {
    installId: 'install_test', backendUrl: 'http://127.0.0.1:8001/v1', activeTabId: 1,
    settings: { ...DEFAULT_EXTENSION_SETTINGS, aiProvider: 'codex', codexModel: 'other-global-model', codexFastMode: true },
    subtitleState: { type: 'ready', track }, jobHistory: [],
    codexAccount: { available: true, connected: true, login: null, models: [
      { id: 'codex-model', name: 'Codex Model', supportsFastMode: true },
      { id: 'standard-model', name: 'Standard Model', supportsFastMode: false },
    ] },
  };
});

it('loads original job defaults outside global history and preserves a correction draft through polls and refreshes', () => {
  const onChange = vi.fn();
  const controls = bindLyricsAiOptions(field('[data-lyrics-ai-options]'), onChange);
  controls.render(state, false);
  expect(field('[data-lyrics-ai-summary]').textContent).toContain('saved AI settings');
  expect(controls.payload()).toEqual({});
  controls.setSavedJobs([{ ...originalJob, jobId: track.jobId, aiProvider: 'cerebras', aiModel: 'saved-api-model' }]);
  expect(field<HTMLSelectElement>('[data-lyrics-ai-provider]').value).toBe('cerebras');
  expect(field('[data-lyrics-ai-summary]').textContent).toBe('Cerebras API · saved-api-model');
  expect(controls.payload()).toEqual({});
  field<HTMLInputElement>('input[name="lyricsAiSource"][value="codex"]').click();
  const fast = field<HTMLInputElement>('[data-lyrics-ai-fast]');
  fast.click();
  controls.render(structuredClone(state), false);
  controls.setSavedJobs([{ ...originalJob, jobId: track.jobId }]);
  expect(controls.payload()).toEqual({ aiProvider: 'codex', aiModel: 'codex-model', aiFastMode: true });
  expect(fast.checked).toBe(true);
  expect(state.settings.codexModel).toBe('other-global-model');
  expect(correctionAiLabel({ aiProvider: 'codex', aiModel: 'codex-model', aiFastMode: true })).toBe('Codex · codex-model · Fast mode');
  controls.render({ ...state, subtitleState: { type: 'ready', track: { ...track, trackId: 'next-track' } } }, false);
  expect(controls.payload()).toEqual({});
  expect(field<HTMLInputElement>('input[name="lyricsAiSource"][value="api"]').checked).toBe(true);
});

it('defaults to the saved Codex model and fast mode, then sends only API provider when overridden', () => {
  state.jobHistory = [{ ...originalJob, jobId: track.jobId, aiProvider: 'codex', aiModel: 'codex-model', aiFastMode: true }];
  const controls = bindLyricsAiOptions(field('[data-lyrics-ai-options]'), vi.fn());
  controls.render(state, false);
  expect(field<HTMLSelectElement>('[data-lyrics-ai-model]').value).toBe('codex-model');
  expect(field<HTMLInputElement>('[data-lyrics-ai-fast]').checked).toBe(true);
  expect(controls.ready()).toBe(true);
  expect(controls.payload()).toEqual({});
  field<HTMLInputElement>('input[name="lyricsAiSource"][value="api"]').click();
  expect(controls.payload()).toEqual({ aiProvider: 'openai' });
  const provider = field<HTMLSelectElement>('[data-lyrics-ai-provider]');
  provider.value = 'cerebras';
  change(provider);
  expect(controls.payload()).toEqual({ aiProvider: 'cerebras' });
});

it('blocks disconnected, missing-model and unsupported-fast selections without silently choosing another provider', () => {
  state.jobHistory = [{ ...originalJob, jobId: track.jobId, aiProvider: 'codex', aiModel: 'standard-model', aiFastMode: true }];
  const controls = bindLyricsAiOptions(field('[data-lyrics-ai-options]'), vi.fn());
  controls.render(state, false);
  expect(controls.ready()).toBe(false);
  expect(field('[data-lyrics-ai-readiness]').textContent).toContain('Fast mode is unavailable');
  field<HTMLInputElement>('[data-lyrics-ai-fast]').click();
  expect(controls.ready()).toBe(true);
  const model = field<HTMLSelectElement>('[data-lyrics-ai-model]');
  model.value = '';
  change(model);
  expect(controls.ready()).toBe(false);
  expect(controls.payload()).toMatchObject({ aiProvider: 'codex', aiModel: '', aiFastMode: false });
  model.value = 'codex-model';
  change(model);
  expect(controls.ready()).toBe(true);
  controls.render({ ...state, codexAccount: { ...state.codexAccount!, connected: false } }, false);
  expect(controls.ready()).toBe(false);
  expect(field('[data-lyrics-ai-readiness]').textContent).toContain('Connect Codex in Settings');
  expect(controls.payload().aiProvider).toBe('codex');
  controls.render(state, true);
  expect(model.disabled).toBe(true);
  expect(field<HTMLInputElement>('input[name="lyricsAiSource"][value="api"]').disabled).toBe(true);
});
