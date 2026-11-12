import { t } from '../../utils/i18n';
import type { PanelState } from '../../utils/messages';
import type { UpdateInstanceSettings } from '../../utils/contracts';

const providers = { elevenlabs: 'ElevenLabs', openai: 'OpenAI', cerebras: 'Cerebras', anthropic: 'Claude API', claude: 'Claude Code' } as const;
const roles: Record<keyof typeof providers, string> = {
  elevenlabs: 'Audio transcription', openai: 'Text analysis', cerebras: 'Text analysis', anthropic: 'Text analysis', claude: 'Subscription token',
};

export function bindInstanceSettings(root: Document, save: (patch: UpdateInstanceSettings) => Promise<boolean>): { render(state: PanelState): void } {
  const form = root.querySelector<HTMLFormElement>('[data-instance-settings-form]')!;
  const fields = root.querySelector<HTMLElement>('[data-provider-fields]')!;
  const status = root.querySelector<HTMLElement>('[data-instance-settings-status]')!;
  const retention = form.elements.namedItem('retentionDays') as HTMLInputElement;
  const address = root.querySelector<HTMLElement>('[data-backend-url]')!;
  let busy = false;
  let loadError: string | undefined;
  const dirty = new Set<string>();
  const removed = new Set<string>();
  for (const [name, label] of Object.entries(providers) as Array<[keyof typeof providers, string]>) {
    // One expandable row per provider: status in the summary, key field inside.
    const row = root.createElement('details');
    row.className = 'prow provider-field';
    row.innerHTML = `<summary class="row"><span class="row-key"><b>${label}</b><small data-i18n="${roles[name]}">${t(roles[name])}</small></span><span class="spacer"></span>
        <span class="row-status"><span data-provider-status="${name}" role="img">—</span><span data-provider-label="${name}"></span></span><span class="chev" data-icon="chevron" aria-hidden="true"></span></summary>
      <div class="prow-body">
        <label class="field" for="${name}Key"><span data-i18n="API key">${t('API key')}</span></label>
        <input id="${name}Key" type="password" name="${name}Key" autocomplete="new-password" maxlength="4096">
        <div class="hstack"><button type="submit" class="btn sm" data-i18n="Save key">${t('Save key')}</button>
          <button type="button" class="btn sm danger provider-remove" name="${name}Clear" aria-pressed="false" data-i18n="Remove saved key">${t('Remove saved key')}</button></div>
      </div>`;
    const remove = row.querySelector<HTMLButtonElement>('.provider-remove')!;
    remove.setAttribute('aria-label', `${t('Remove saved key')}: ${label}`);
    remove.addEventListener('click', () => {
      if (removed.has(name)) removed.delete(name);
      else removed.add(name);
      remove.setAttribute('aria-pressed', String(removed.has(name)));
      (form.elements.namedItem(`${name}Key`) as HTMLInputElement).value = '';
    });
    fields.append(row);
  }
  const cliMissing = root.createElement('p');
  cliMissing.className = 'microcopy';
  cliMissing.dataset.claudeCliMissing = '';
  cliMissing.hidden = true;
  cliMissing.textContent = t('Install Claude Code CLI 2.1.280 or newer on the backend.');
  const claudeRow = fields.querySelector('#claudeKey')!.parentElement!;
  claudeRow.append(cliMissing);
  form.addEventListener('input', event => {
    if (event.target instanceof HTMLInputElement) {
      dirty.add(event.target.name);
      const name = event.target.name.replace(/Key$/, '');
      removed.delete(name);
      fields.querySelector(`[name="${name}Clear"]`)?.setAttribute('aria-pressed', 'false');
    }
  });
  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (busy) return;
    busy = true;
    const patch: UpdateInstanceSettings = { providers: {}, retentionDays: retention.value === '' ? null : Number(retention.value) };
    for (const name of Object.keys(providers) as Array<keyof typeof providers>) {
      const key = form.elements.namedItem(`${name}Key`) as HTMLInputElement;
      patch.providers![name] = removed.has(name) ? { apiKey: null } : key.value.trim() ? { apiKey: key.value.trim() } : {};
      key.value = '';
    }
    const buttons = form.querySelectorAll<HTMLButtonElement>('button');
    for (const button of buttons) button.disabled = true;
    try {
      if (await save(patch)) {
        dirty.clear();
        removed.clear();
        for (const remove of fields.querySelectorAll('.provider-remove')) remove.setAttribute('aria-pressed', 'false');
        status.textContent = t('Settings saved.');
      } else status.textContent = t('Could not save settings. Re-enter keys to try again.');
    } finally {
      // Credentials only exist in this submission and never enter extension storage.
      for (const provider of Object.values(patch.providers ?? {})) if (provider) delete provider.apiKey;
      for (const button of buttons) button.disabled = false;
      busy = false;
    }
  });
  return { render(state): void {
    address.textContent = state.backendUrl;
    if (state.instanceSettingsError) status.textContent = t(state.instanceSettingsError);
    else if (loadError) status.textContent = '';
    loadError = state.instanceSettingsError;
    const settings = state.instanceSettings;
    if (!settings) return;
    if (!dirty.has('retentionDays')) retention.value = settings.retentionDays === null ? '' : String(settings.retentionDays);
    for (const name of Object.keys(providers) as Array<keyof typeof providers>) {
      const indicator = fields.querySelector<HTMLElement>(`[data-provider-status="${name}"]`)!;
      const configured = settings.providers[name].configured;
      indicator.textContent = configured ? '✓' : '✕';
      indicator.dataset.configured = String(configured);
      fields.querySelector<HTMLElement>(`[data-provider-label="${name}"]`)!.textContent = configured ? t('Key set') : t('No key');
      indicator.setAttribute('aria-label', configured ? t('Configured') : t('Not configured'));
      indicator.title = configured ? t('Configured') : t('Not configured');
    }
    cliMissing.hidden = settings.providers.claude.available;
  } };
}
