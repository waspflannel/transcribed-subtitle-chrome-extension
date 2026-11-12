import { t, interfaceLocale } from '../../utils/i18n';
import { aiSelectionLabel } from '../../utils/settings-model';
import { browser } from 'wxt/browser';
import type { PanelState, PanelRequest } from '../../utils/messages';
import type { SubtitleJobHistoryItem, TrackResponse } from '../../utils/contracts';
import { languageLabel } from '../../utils/languages';
import { escapeHtml } from '../../utils/html';
import { icon } from '../../utils/panel/icons';
import { closeMenu } from './menus';

export interface SavedGenerationsOptions {
  /** The track button in the transcript toolbar. Its labels show the current track. */
  button: HTMLButtonElement;
  pairLabel: HTMLElement;
  modelLabel: HTMLElement;
  /** Popover menu that lists the saved tracks for this video. */
  menu: HTMLElement;
  /** Screen-reader status for loading and errors. */
  status: HTMLElement;
  selectGeneration: (request: PanelRequest) => Promise<boolean>;
  windowId: () => number | undefined;
  applyPanelState: (state: PanelState) => void;
  onJobsLoaded?: (jobs: SubtitleJobHistoryItem[]) => void;
  /** Delete one saved track. The row has already animated out. */
  onDelete?: (job: SubtitleJobHistoryItem) => void;
  onGenerateNew?: () => void;
  onSwitching?: (busy: boolean) => void;
  /** Jobs waiting out their undo window; the menu hides them. */
  hiddenJobIds?: () => ReadonlySet<string>;
}

const LEAVE_MS = 240;

export function pairText(job: Pick<SubtitleJobHistoryItem, 'sourceLanguage' | 'targetLanguage'> & { detectedSourceLanguage?: string | null }): string {
  const source = job.detectedSourceLanguage ?? job.sourceLanguage;
  return `${source === 'auto' ? t("Auto") : languageLabel(source)} → ${languageLabel(job.targetLanguage)}`;
}

export function modelText(job: Pick<SubtitleJobHistoryItem, 'aiProvider' | 'aiModel' | 'aiFastMode'>): string {
  return `${aiSelectionLabel(job.aiProvider, job.aiModel)}${job.aiFastMode ? ` · ${t('Fast mode')}` : ''}`;
}

export function bindSavedGenerations(options: SavedGenerationsOptions) {
  const { button, menu, status } = options;
  let latest: PanelState | undefined;
  let context = '';
  let revision = 0;
  let jobs: SubtitleJobHistoryItem[] = [];
  let renderedKey = '';
  let loading = false;
  let switching = false;
  let error = '';

  const currentTrack = (): TrackResponse | null => latest?.subtitleState.type === 'ready' ? latest.subtitleState.track : null;
  const visibleJobs = (): SubtitleJobHistoryItem[] => {
    const hidden = options.hiddenJobIds?.() ?? new Set<string>();
    return jobs.filter(job => !hidden.has(job.jobId));
  };
  const showError = (message: string): void => {
    error = t(message);
    status.textContent = error;
    draw();
  };

  const draw = (): void => {
    const track = currentTrack();
    const job = track ? jobs.find(item => item.jobId === track.jobId)
      ?? latest?.jobHistory.find(item => item.jobId === track.jobId) : undefined;
    options.pairLabel.textContent = track ? pairText(job ?? track) : '—';
    options.modelLabel.textContent = job ? modelText(job) : '';
    const correcting = latest?.lyricsCorrection?.status === 'queued' || latest?.lyricsCorrection?.status === 'running';
    button.disabled = !track || switching || correcting;

    const list = visibleJobs();
    const key = JSON.stringify([interfaceLocale(), track?.jobId, list.map(item => item.jobId), loading, error]);
    if (key === renderedKey) return;
    renderedKey = key;
    const rows = list.map(item => `
      <div class="gen-row" data-gen-row="${escapeHtml(item.jobId)}"><div>
        <button type="button" class="mi" role="menuitemradio" aria-checked="${item.jobId === track?.jobId}" data-gen-pick="${escapeHtml(item.jobId)}">
          <span class="gen-mark">${item.jobId === track?.jobId ? icon('check', 16) : ''}</span>
          <span class="mi-text"><b>${escapeHtml(pairText(item))}</b><small>${escapeHtml(modelText(item))}</small></span>
        </button>
        <button type="button" class="icon-btn sm danger" data-gen-delete="${escapeHtml(item.jobId)}" aria-label="${escapeHtml(t('Delete {name}', { name: `${pairText(item)} · ${modelText(item)}` }))}" title="${escapeHtml(t('Delete'))}">${icon('trash', 16)}</button>
      </div></div>`).join('');
    menu.innerHTML = `<span class="micro">${escapeHtml(t('Saved for this video'))}</span>
      ${rows}
      <p class="menu-status">${escapeHtml(loading ? t("Loading saved generations…") : error || (list.length ? '' : t('No saved tracks.')))}</p>
      <hr>
      <button type="button" class="mi" role="menuitem" data-gen-new>${icon('plus')}<span>${escapeHtml(t('Generate new track'))}</span></button>
      <button type="button" class="mi" role="menuitem" data-gen-refresh ${loading || switching ? 'disabled' : ''}>${icon('refresh')}<span>${escapeHtml(t("Refresh saved generations"))}</span></button>`;
  };

  const load = async (): Promise<void> => {
    const requestRevision = ++revision;
    const page = latest?.pageStatus;
    if (!page?.supported) return;
    loading = true;
    error = '';
    status.textContent = t("Loading saved generations…");
    draw();
    try {
      const response = await browser.runtime.sendMessage({ type: 'panel.listGenerations', youtubeVideoId: page.videoId, windowId: options.windowId() });
      if (requestRevision !== revision) return;
      if (!response || !Array.isArray(response.jobs)) throw new Error(response?.error ?? t("Unable to load saved generations. Try refreshing."));
      jobs = response.jobs.filter((job: SubtitleJobHistoryItem) => job.youtubeVideoId === page.videoId && job.status === 'completed');
      options.onJobsLoaded?.(jobs);
      status.textContent = '';
      if (response.panelState) options.applyPanelState(response.panelState);
    } catch (failure) {
      if (requestRevision === revision) showError(failure instanceof Error ? failure.message : t("Unable to load saved generations."));
    } finally {
      if (requestRevision === revision) { loading = false; draw(); }
    }
  };

  const select = (jobId: string): void => {
    const state = latest;
    const track = currentTrack();
    if (!state || !track || switching || state.activeTabId === undefined || jobId === track.jobId) return;
    closeMenu(menu);
    switching = true;
    error = '';
    status.textContent = t("Loading transcript…");
    options.onSwitching?.(true);
    draw();
    void options.selectGeneration({ type: 'panel.selectGeneration', jobId, currentJobId: track.jobId,
      trackId: track.trackId, youtubeVideoId: track.youtubeVideoId, tabId: state.activeTabId }).finally(() => {
      switching = false;
      options.onSwitching?.(false);
      if (status.textContent === t("Loading transcript…")) status.textContent = '';
      draw();
    });
  };

  menu.addEventListener('click', event => {
    const target = event.target as Element;
    const pick = target.closest<HTMLElement>('[data-gen-pick]');
    const remove = target.closest<HTMLElement>('[data-gen-delete]');
    if (pick) select(pick.dataset.genPick!);
    else if (remove) {
      const job = jobs.find(item => item.jobId === remove.dataset.genDelete);
      const row = remove.closest<HTMLElement>('[data-gen-row]');
      if (!job || !row || row.classList.contains('leaving')) return;
      row.classList.add('leaving');
      setTimeout(() => {
        if (!visibleJobs().some(item => item.jobId !== job.jobId)) closeMenu(menu);
        options.onDelete?.(job);
      }, LEAVE_MS);
    } else if (target.closest('[data-gen-new]')) {
      closeMenu(menu);
      options.onGenerateNew?.();
    } else if (target.closest('[data-gen-refresh]')) {
      void load();
    }
  });

  return {
    showError,
    /** Saved tracks for this video, minus pending deletes. */
    jobs: visibleJobs,
    refresh: load,
    render(state: PanelState): void {
      latest = state;
      const track = currentTrack();
      const nextContext = track ? `${state.backendUrl}:${state.activeTabId}:${track.youtubeVideoId}:${track.trackId}` : '';
      if (nextContext !== context) {
        context = nextContext;
        revision += 1;
        jobs = [];
        options.onJobsLoaded?.(jobs);
        loading = false;
        error = '';
        status.textContent = '';
        if (context) void load();
      }
      draw();
    },
  };
}
