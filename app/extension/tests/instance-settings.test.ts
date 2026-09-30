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
  expect(form.querySelectorAll('[data-provider-fields] input')).toHaveLength(3);
  expect(form.querySelector('[data-provider-fields]')?.closest('.card')?.querySelectorAll('input')).toHaveLength(3);
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

it('uses anonymous settings endpoints and rejects any returned credential fields', async () => {
  const fetcher = vi.fn(async () => new Response(JSON.stringify(settings)));
  const api = new SubtitleApiClient('http://localhost:8001/v1', fetcher as typeof fetch);
  await expect(api.updateInstanceSettings('install_test', { providers: { openai: { apiKey: 'test-key' } }, retentionDays: null })).resolves.toEqual(settings);
  expect(fetcher).toHaveBeenCalledWith('http://localhost:8001/v1/settings', expect.objectContaining({ method: 'PUT', headers: expect.not.objectContaining({ Authorization: expect.anything() }) }));
  expect(() => guardInstanceSettings({ ...settings, providers: { ...settings.providers, openai: { ...settings.providers.openai, apiKey: 'must-not-return' } } })).toThrow();
  expect(() => guardInstanceSettings({ ...settings, retentionDays: 0 })).toThrow();
  expect(() => guardInstanceSettings({ ...settings, providers: { ...settings.providers, typesafe: { configured: false, model: 'retired' } } })).toThrow();
});
