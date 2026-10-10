import type { LyricsCorrectionRequest, SubtitleJobHistoryItem } from '../../utils/contracts';
import type { PanelState } from '../../utils/messages';
import { aiProviderLabel } from '../../utils/settings-model';
import { interfaceLocale, t } from '../../utils/i18n';

type Selection = Pick<SubtitleJobHistoryItem, 'aiProvider' | 'aiModel' | 'aiFastMode'>;

export function correctionAiLabel(selection: Partial<Selection>): string {
  if (!selection.aiProvider || !selection.aiModel) return '';
  return `${aiProviderLabel(selection.aiProvider)}${selection.aiProvider === 'codex' || selection.aiProvider === 'claude' ? '' : ' API'} · ${selection.aiModel}${selection.aiFastMode ? ` · ${t('Fast mode')}` : ''}`;
}

export function bindLyricsAiOptions(root: HTMLElement, onChange: () => void) {
  const sources = [...root.querySelectorAll<HTMLInputElement>('input[name="lyricsAiSource"]')];
  const apiOptions = root.querySelector<HTMLElement>('[data-lyrics-api-options]')!;
  const codexOptions = root.querySelector<HTMLElement>('[data-lyrics-codex-options]')!;
  const provider = root.querySelector<HTMLSelectElement>('[data-lyrics-ai-provider]')!;
  const model = root.querySelector<HTMLSelectElement>('[data-lyrics-ai-model]')!;
  const fast = root.querySelector<HTMLInputElement>('[data-lyrics-ai-fast]')!;
  const summary = root.querySelector<HTMLElement>('[data-lyrics-ai-summary]')!;
  const readiness = root.querySelector<HTMLElement>('[data-lyrics-ai-readiness]')!;
  let latest: PanelState | null = null;
  let context = '';
  let selection: Selection | null = null;
  let changed = false;
  let busy = false;
  let savedJobs: SubtitleJobHistoryItem[] = [];
  let modelOptionsKey = '';

  const error = (): string => {
    const selected = selection;
    if (selected?.aiProvider === 'claude') {
      if (latest?.instanceSettings?.providers.claude.available === false) return t('Install Claude Code CLI 2.1.280 or newer on the backend.');
      return latest?.instanceSettings?.providers.claude.configured ? '' : t('Add a Claude Code token in Settings before replacing lyrics.');
    }
    if (selected?.aiProvider !== 'codex') return '';
    if (!latest?.codexAccount?.available || !latest.codexAccount.connected) return t('Connect Codex in Settings before replacing lyrics.');
    const selectedModel = latest.codexAccount.models.find(candidate => candidate.id === selected.aiModel);
    if (!selectedModel) return t('Select an available Codex model before replacing lyrics.');
    return selected.aiFastMode && !selectedModel.supportsFastMode ? t('Fast mode is unavailable for this Codex model.') : '';
  };
  const draw = (): void => {
    const codex = selection?.aiProvider === 'codex';
    const models = latest?.codexAccount?.models ?? [];
    const source = codex ? 'codex' : selection?.aiProvider === 'claude' ? 'claude' : 'api';
    for (const input of sources) {
      input.checked = selection !== null && input.value === source;
      input.disabled = busy;
    }
    apiOptions.hidden = !selection || source !== 'api';
    codexOptions.hidden = !codex;
    if (selection && source === 'api') provider.value = selection.aiProvider;
    provider.disabled = busy;
    const optionsKey = JSON.stringify([interfaceLocale(), models]);
    if (optionsKey !== modelOptionsKey) {
      modelOptionsKey = optionsKey;
      model.replaceChildren(new Option(t('Select a Codex model'), ''));
      for (const item of models) model.add(new Option(item.name === item.id ? item.id : `${item.name} (${item.id})`, item.id));
    }
    model.value = codex ? selection?.aiModel ?? '' : '';
    model.disabled = busy || !latest?.codexAccount?.connected;
    fast.checked = codex && Boolean(selection?.aiFastMode);
    fast.disabled = busy || (!fast.checked && (!latest?.codexAccount?.connected || !models.find(item => item.id === selection?.aiModel)?.supportsFastMode));
    readiness.textContent = error();
    readiness.hidden = !readiness.textContent;
    summary.textContent = selection ? correctionAiLabel(selection) : t('Uses this generation’s saved AI settings.');
  };
  const update = (next: Selection): void => {
    selection = next;
    changed = true;
    draw();
    onChange();
  };
  const selectApi = (): void => {
    const aiProvider = provider.value === 'cerebras' ? 'cerebras' : 'openai';
    update({ aiProvider, aiModel: latest?.instanceSettings?.providers[aiProvider]?.model ?? '', aiFastMode: false });
  };
  for (const source of sources) source.addEventListener('change', () => {
    if (!source.checked || busy) return;
    if (source.value === 'api') selectApi();
    else if (source.value === 'claude') update({ aiProvider: 'claude', aiModel: latest?.instanceSettings?.providers.claude.model ?? '', aiFastMode: false });
    else update({ aiProvider: 'codex', aiModel: latest?.codexAccount?.models[0]?.id ?? '', aiFastMode: false });
  });
  provider.addEventListener('change', selectApi);
  model.addEventListener('change', () => {
    const selectedModel = latest?.codexAccount?.models.find(item => item.id === model.value);
    update({ aiProvider: 'codex', aiModel: selectedModel?.id ?? '', aiFastMode: Boolean(selectedModel?.supportsFastMode && selection?.aiFastMode) });
  });
  fast.addEventListener('change', () => { if (selection) update({ ...selection, aiFastMode: fast.checked }); });

  const render = (state: PanelState, disabled: boolean): void => {
    latest = state;
    busy = disabled;
    const track = state.subtitleState.type === 'ready' ? state.subtitleState.track : null;
    const nextContext = track ? `${state.backendUrl}:${state.activeTabId}:${track.jobId}:${track.trackId}` : '';
    if (nextContext !== context) {
      context = nextContext;
      selection = null;
      changed = false;
    }
    if (!changed && track) {
      const job = savedJobs.find(job => job.jobId === track.jobId) ?? state.jobHistory.find(job => job.jobId === track.jobId);
      if (job) selection = { aiProvider: job.aiProvider, aiModel: job.aiModel, aiFastMode: job.aiFastMode ?? false };
    }
    draw();
  };
  return {
    render,
    ready: () => !error(),
    setSavedJobs(jobs: SubtitleJobHistoryItem[]): void {
      savedJobs = jobs;
      if (latest) render(latest, busy);
      onChange();
    },
    payload(): Pick<LyricsCorrectionRequest, 'aiProvider' | 'aiModel' | 'aiFastMode'> {
      if (!changed || !selection) return {};
      return { aiProvider: selection.aiProvider, ...(selection.aiProvider === 'codex' ? { aiModel: selection.aiModel, aiFastMode: selection.aiFastMode ?? false } : {}) };
    },
  };
}
