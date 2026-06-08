import { browser } from 'wxt/browser';

import type { SubtitleCue } from '../../utils/contracts';
import type { ExtensionSettings } from '../../utils/settings-model';
import { panelTranscriptListHtml } from '../../utils/panel/transcript';

export function bindTranscriptView(dom: {
  transcriptSearch: HTMLInputElement;
  transcriptList: HTMLElement;
  transcriptStatus: HTMLElement;
}) {
  let cues: readonly SubtitleCue[] = [];
  let settings: ExtensionSettings | null = null;
  let activeCueId: string | null = null;

  function render(): void {
    if (!settings) return;
    const total = cues.length;
    dom.transcriptList.innerHTML = total === 0
      ? '<p class="transcript-empty muted">Generate subtitles to see the transcript.</p>'
      : panelTranscriptListHtml({ cues, activeCueId, query: dom.transcriptSearch.value, settings });
    dom.transcriptStatus.textContent = total === 0 ? '' : `${total} cues`;
    const activeRow = dom.transcriptList.querySelector('.cue.on');
    activeRow?.scrollIntoView({ block: 'nearest' });
  }

  dom.transcriptSearch.addEventListener('input', render);
  dom.transcriptList.addEventListener('click', (event) => {
    const button = (event.target as Element)?.closest<HTMLButtonElement>('[data-transcript-action]');
    const cueId = button?.dataset.cueId;
    if (!button || !cueId) return;
    const action = button.dataset.transcriptAction;
    if (action === 'jump' || action === 'replay') {
      void browser.runtime.sendMessage({ type: 'popup.seekToCue', cueId, mode: action }).catch(() => {});
    } else if (action === 'copy') {
      const cue = cues.find((c) => c.cueId === cueId);
      if (cue) void navigator.clipboard?.writeText(cue.sourceText).catch(() => {});
    }
    // 'save' is the existing Phase-02 placeholder — no-op for now.
  });

  return {
    setData(nextCues: readonly SubtitleCue[], nextSettings: ExtensionSettings) {
      cues = nextCues; settings = nextSettings; render();
    },
    setActiveCue(cueId: string | null) { activeCueId = cueId; render(); },
    focus() { dom.transcriptSearch.focus(); },
  };
}
