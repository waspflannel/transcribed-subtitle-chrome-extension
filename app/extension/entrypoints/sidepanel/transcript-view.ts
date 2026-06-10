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
  let youtubeVideoId: string | null = null;
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

  function ackButton(button: HTMLButtonElement, label?: string): void {
    button.classList.add('acted');
    window.setTimeout(() => button.classList.remove('acted'), 300);
    if (label) {
      if (button.dataset.label === undefined) button.dataset.label = button.textContent ?? '';
      button.textContent = label;
      window.setTimeout(() => { button.textContent = button.dataset.label ?? ''; }, 1000);
    }
  }

  dom.transcriptSearch.addEventListener('input', render);
  dom.transcriptList.addEventListener('click', (event) => {
    const button = (event.target as Element)?.closest<HTMLButtonElement>('[data-transcript-action]');
    const cueId = button?.dataset.cueId;
    if (!button || !cueId) return;
    const action = button.dataset.transcriptAction;
    if (action === 'jump') {
      if (youtubeVideoId) {
        void browser.runtime.sendMessage({ type: 'panel.seekToCue', youtubeVideoId, cueId, mode: 'jump' }).catch(() => {});
      }
      ackButton(button);
    } else if (action === 'copy') {
      const text = cues.find((c) => c.cueId === cueId)?.sourceText;
      if (text && navigator.clipboard) {
        void navigator.clipboard.writeText(text)
          .then(() => ackButton(button, 'Copied'))
          .catch(() => ackButton(button, 'Failed'));
      } else {
        ackButton(button, 'Failed');
      }
    } else if (action === 'save') {
      // Save is the existing Phase-02 placeholder — acknowledge the click only.
      ackButton(button, 'Soon');
    }
  });

  return {
    setData(nextYoutubeVideoId: string | null, nextCues: readonly SubtitleCue[], nextSettings: ExtensionSettings) {
      youtubeVideoId = nextYoutubeVideoId; cues = nextCues; settings = nextSettings; render();
    },
    setActiveCue(cueId: string | null) { if (cueId === activeCueId) return; activeCueId = cueId; render(); },
    focus() { dom.transcriptSearch.focus(); },
  };
}
