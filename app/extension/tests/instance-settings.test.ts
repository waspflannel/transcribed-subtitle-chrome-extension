// @vitest-environment jsdom
import { expect, it, vi } from 'vitest';
import markup from '../entrypoints/sidepanel/index.html?raw';
import { bindInstanceSettings } from '../entrypoints/sidepanel/instance-settings';
import { guardInstanceSettings } from '../utils/api-response-guards';
import { DEFAULT_EXTENSION_SETTINGS } from '../utils/settings-model';
import type { InstanceSettings, UpdateInstanceSettings } from '../utils/contracts';
import { SubtitleApiClient } from '../utils/api';

const settings: InstanceSettings = {
  providers: {
    openai: { configured: true, model: 'gpt-6-luna' },
    cerebras: { configured: false, model: 'gpt-oss-120b' },
    elevenlabs: { configured: true, model: 'scribe_v2' },
    claude: { configured: false, model: 'sonnet', available: false },
  },
  retentionDays: null,
};

it('submits keys only to the backend, clears inputs, preserves drafts on refresh and supports removing a key', async () => {
  document.documentElement.innerHTML = markup;
  const submissions: UpdateInstanceSettings[] = [];
  const save = vi.fn(async (patch: UpdateInstanceSettings) => { submissions.push(structuredClone(patch)); return true; });
  const view = bindInstanceSettings(document, save);
  const state = { installId: 'install_test', backendUrl: 'http://127.0.0.1:8001/v1', settings: DEFAULT_EXTENSION_SETTINGS, instanceSettings: settings, subtitleState: { type: 'no-track' as const }, jobHistory: [] };
  view.render(state);
  const form = document.querySelector<HTMLFormElement>('[data-instance-settings-form]')!;
  expect(form.querySelectorAll('[data-provider-fields] input')).toHaveLength(4);
  expect(form.querySelector('[data-provider-fields]')?.closest('.card')?.querySelectorAll('input')).toHaveLength(4);
  expect(form.querySelectorAll('[data-provider-fields] fieldset')).toHaveLength(0);
  expect(form.querySelector('[name="openaiModel"]')).toBeNull();
  expect(form.querySelector('[data-provider-status="openai"]')?.textContent).toBe('✓');
  expect(form.querySelector('[data-provider-status="cerebras"]')?.textContent).toBe('✕');
  expect(form.querySelector('[data-provider-status="cerebras"]')?.getAttribute('aria-label')).toBe('Not configured');
  const key = form.elements.namedItem('openaiKey') as HTMLInputElement;
  key.value = 'test-secret-key';
  key.dispatchEvent(new Event('input', { bubbles: true }));
  view.render(state);
  expect(key.value).toBe('test-secret-key');
  (form.elements.namedItem('cerebrasClear') as HTMLButtonElement).click();
  form.dispatchEvent(new Event('submit', { cancelable: true }));
  await vi.waitFor(() => expect(document.querySelector('[data-instance-settings-status]')?.textContent).toBe('Settings saved.'));
  expect(submissions[0]).toMatchObject({ providers: { openai: { apiKey: 'test-secret-key' }, cerebras: { apiKey: null } }, retentionDays: null });
  expect(submissions[0]?.providers?.openai).not.toHaveProperty('model');
  view.render({ ...state, instanceSettings: { ...settings, providers: { ...settings.providers, openai: { ...settings.providers.openai, configured: false }, cerebras: { ...settings.providers.cerebras, configured: true } } } });
  expect(form.querySelector('[data-provider-status="openai"]')?.textContent).toBe('✕');
  expect(form.querySelector('[data-provider-status="cerebras"]')?.textContent).toBe('✓');
  expect(key.value).toBe('');
  expect(JSON.stringify(save.mock.calls)).not.toContain('test-secret-key');
  expect(document.body.textContent).not.toContain('test-secret-key');
});

it('submits the Claude Code token and shows the missing CLI notice only when unavailable', async () => {
  document.documentElement.innerHTML = markup;
  const submissions: UpdateInstanceSettings[] = [];
  const view = bindInstanceSettings(document, async patch => { submissions.push(structuredClone(patch)); return true; });
  const state = { installId: 'install_test', backendUrl: 'http://127.0.0.1:8001/v1', settings: DEFAULT_EXTENSION_SETTINGS, instanceSettings: settings, subtitleState: { type: 'no-track' as const }, jobHistory: [] };
  view.render(state);
  const notice = document.querySelector<HTMLElement>('[data-claude-cli-missing]')!;
  expect(notice.hidden).toBe(false);
  view.render({ ...state, instanceSettings: { ...settings, providers: { ...settings.providers, claude: { configured: true, model: 'sonnet', available: true } } } });
  expect(notice.hidden).toBe(true);
  const form = document.querySelector<HTMLFormElement>('[data-instance-settings-form]')!;
  const key = form.elements.namedItem('claudeKey') as HTMLInputElement;
  key.value = 'claude-token';
  key.dispatchEvent(new Event('input', { bubbles: true }));
  form.dispatchEvent(new Event('submit', { cancelable: true }));
  await vi.waitFor(() => expect(submissions).toHaveLength(1));
  expect(submissions[0]?.providers?.claude).toEqual({ apiKey: 'claude-token' });
  expect(key.value).toBe('');
});

it('renders the Claude model dropdown, reflects the backend and keeps unsaved choices', async () => {
  document.documentElement.innerHTML = markup;
  const submissions: UpdateInstanceSettings[] = [];
  const view = bindInstanceSettings(document, async patch => { submissions.push(structuredClone(patch)); return true; });
  const withClaude = (model: string) => ({
    installId: 'install_test', backendUrl: 'http://127.0.0.1:8001/v1', settings: DEFAULT_EXTENSION_SETTINGS, subtitleState: { type: 'no-track' as const }, jobHistory: [],
    instanceSettings: { ...settings, providers: { ...settings.providers, claude: { configured: true, model, available: true } } },
  });
  view.render(withClaude('opus'));
  const form = document.querySelector<HTMLFormElement>('[data-instance-settings-form]')!;
  const model = form.elements.namedItem('claudeModel') as HTMLSelectElement;
  expect(model.closest('.provider-field')?.querySelector('#claudeKey')).not.toBeNull();
  expect([...model.options].map(option => option.value)).toEqual(['opus', 'sonnet', 'haiku']);
  expect(form.elements.namedItem('claudeThinking')).toBeNull();
  expect(model.value).toBe('opus');
  expect(form.querySelectorAll('[data-provider-fields] input')).toHaveLength(4);
  model.value = 'haiku';
  model.dispatchEvent(new Event('input', { bubbles: true }));
  view.render(withClaude('opus'));
  expect(model.value).toBe('haiku');
  form.dispatchEvent(new Event('submit', { cancelable: true }));
  await vi.waitFor(() => expect(submissions).toHaveLength(1));
  expect(submissions[0]?.providers?.claude).toEqual({ model: 'haiku' });
  expect(submissions[0]?.providers?.openai).not.toHaveProperty('model');
  await vi.waitFor(() => expect(document.querySelector('[data-instance-settings-status]')?.textContent).toBe('Settings saved.'));
  view.render(withClaude('sonnet'));
  expect(model.value).toBe('sonnet');
});

it('uses anonymous settings endpoints and rejects any returned credential fields', async () => {
  const fetcher = vi.fn(async () => new Response(JSON.stringify(settings)));
  const api = new SubtitleApiClient('http://localhost:8001/v1', fetcher as typeof fetch);
  await expect(api.updateInstanceSettings('install_test', { providers: { openai: { apiKey: 'test-key' } }, retentionDays: null })).resolves.toEqual(settings);
  expect(fetcher).toHaveBeenCalledWith('http://localhost:8001/v1/settings', expect.objectContaining({ method: 'PUT', headers: expect.not.objectContaining({ Authorization: expect.anything() }) }));
  expect(() => guardInstanceSettings({ ...settings, providers: { ...settings.providers, openai: { ...settings.providers.openai, apiKey: 'must-not-return' } } })).toThrow();
  expect(() => guardInstanceSettings({ ...settings, retentionDays: 0 })).toThrow();
  expect(guardInstanceSettings({ ...settings, providers: { ...settings.providers, claude: { configured: true, model: 'sonnet', available: true } } }).providers.claude.available).toBe(true);
  const { claude: _claude, ...withoutClaude } = settings.providers;
  expect(() => guardInstanceSettings({ ...settings, providers: withoutClaude })).toThrow();
  expect(() => guardInstanceSettings({ ...settings, providers: { ...settings.providers, claude: { ...settings.providers.claude, apiKey: 'must-not-return' } } })).toThrow();
  expect(() => guardInstanceSettings({ ...settings, providers: { ...settings.providers, openai: { ...settings.providers.openai, available: true } } })).toThrow();
  expect(() => guardInstanceSettings({ ...settings, providers: { ...settings.providers, typesafe: { configured: false, model: 'retired' } } })).toThrow();
});
