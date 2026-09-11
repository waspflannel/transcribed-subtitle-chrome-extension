import { browser } from 'wxt/browser';
import type { PanelState, PanelRequest } from '../../utils/messages';
import type { SubtitleJobHistoryItem } from '../../utils/contracts';
import { languageLabel } from '../../utils/languages';

export function bindSavedGenerations(
  select: HTMLSelectElement,
  status: HTMLElement,
  refresh: HTMLButtonElement,
  selectGeneration: (request: PanelRequest) => Promise<boolean>,
  windowId: () => number | undefined,
): { render: (state: PanelState) => void; showError: (message: string) => void } {
  let latest: PanelState;
  let context = '';
  let revision = 0;
  let jobs: SubtitleJobHistoryItem[] = [];
  let renderedJobs: SubtitleJobHistoryItem[] | undefined;
  let renderedJobId: string | undefined;
  let loading = false;
  let switching = false;
  const showError = (message: string): void => { status.textContent = message; };
  const draw = (): void => {
    const track = latest?.subtitleState.type === 'ready' ? latest.subtitleState.track : null;
    if (renderedJobs !== jobs || renderedJobId !== track?.jobId) {
      renderedJobs = jobs;
      renderedJobId = track?.jobId;
      select.replaceChildren();
      if (track && !jobs.some(job => job.jobId === track.jobId)) {
        select.add(option('Current generation', track.jobId));
      }
      for (const job of jobs) {
        const source = job.sourceLanguage === 'auto' ? 'Auto' : languageLabel(job.sourceLanguage);
        const model = job.aiProvider === 'cerebras' ? 'Cerebras' : 'Luna';
        select.add(option(`${source} → ${languageLabel(job.targetLanguage)} (${model})`, job.jobId));
      }
    }
    if (select.value !== (track?.jobId ?? '')) select.value = track?.jobId ?? '';
    const correcting = latest?.lyricsCorrection?.status === 'queued' || latest?.lyricsCorrection?.status === 'running';
    select.disabled = !track || loading || switching || correcting || select.options.length < 2;
    refresh.disabled = loading || switching || !track;
  };
  const load = async (): Promise<void> => {
    const requestRevision = ++revision;
    const page = latest?.pageStatus;
    if (!page?.supported || latest.accountState.status !== 'authenticated') return;
    loading = true;
    status.textContent = 'Loading saved generations…';
    draw();
    try {
      const response = await browser.runtime.sendMessage({ type: 'panel.listGenerations', youtubeVideoId: page.videoId, windowId: windowId() });
      if (requestRevision !== revision) return;
      if (!response || !Array.isArray(response.jobs)) throw new Error(response?.error ?? 'Unable to load saved generations. Try refreshing.');
      jobs = response.jobs.filter((job: SubtitleJobHistoryItem) => job.youtubeVideoId === page.videoId && job.status === 'completed');
      status.textContent = '';
    } catch (error) {
      if (requestRevision === revision) showError(error instanceof Error ? error.message : 'Unable to load saved generations.');
    } finally {
      if (requestRevision === revision) { loading = false; draw(); }
    }
  };
  refresh.addEventListener('click', () => { void load(); });
  select.addEventListener('change', () => {
    const state = latest;
    if (switching || state.subtitleState.type !== 'ready' || state.activeTabId === undefined) return;
    const track = state.subtitleState.track;
    const jobId = select.value;
    if (jobId === track.jobId) return;
    switching = true;
    status.textContent = 'Loading transcript…';
    draw();
    void selectGeneration({ type: 'panel.selectGeneration', jobId, currentJobId: track.jobId,
      trackId: track.trackId, youtubeVideoId: track.youtubeVideoId, tabId: state.activeTabId }).finally(() => {
      switching = false;
      draw();
    });
  });
  return {
    showError,
    render(state): void {
      latest = state;
      const track = state.subtitleState.type === 'ready' ? state.subtitleState.track : null;
      const nextContext = track && state.accountState.status === 'authenticated'
        ? `${state.accountState.id}:${state.activeTabId}:${track.youtubeVideoId}:${track.trackId}` : '';
      if (nextContext !== context) {
        context = nextContext;
        revision += 1;
        jobs = [];
        loading = false;
        status.textContent = '';
        if (context) void load();
      }
      draw();
    },
  };
}

function option(label: string, value: string): HTMLOptionElement {
  const element = document.createElement('option');
  element.textContent = label;
  element.value = value;
  return element;
}
