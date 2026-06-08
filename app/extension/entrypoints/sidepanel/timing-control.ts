import { normalizeSubtitleTimingOffsetSeconds } from '../../utils/settings-model';

const TIMING_OFFSET_COMMIT_DELAY_MS = 250;

export interface TimingOffsetControl {
  showTimingOffset(value: number): void;
}

export function bindTimingOffsetControl(options: {
  rangeInput: HTMLInputElement;
  numberInput: HTMLInputElement;
  output: HTMLOutputElement;
  resetButton: HTMLButtonElement;
  onCommit: (value: number) => void | Promise<void>;
}): TimingOffsetControl {
  let commitTimer: ReturnType<typeof globalThis.setTimeout> | undefined;

  const commit = (value: number) => {
    const normalized = showTimingOffsetValue(options.rangeInput, options.numberInput, options.output, value);

    if (commitTimer !== undefined) {
      globalThis.clearTimeout(commitTimer);
      commitTimer = undefined;
    }

    void options.onCommit(normalized);
  };

  const scheduleCommit = (value: number) => {
    const normalized = showTimingOffsetValue(options.rangeInput, options.numberInput, options.output, value);

    if (commitTimer !== undefined) {
      globalThis.clearTimeout(commitTimer);
    }

    commitTimer = globalThis.setTimeout(() => {
      commitTimer = undefined;
      void options.onCommit(normalized);
    }, TIMING_OFFSET_COMMIT_DELAY_MS);
  };

  options.resetButton.addEventListener('click', () => commit(0));
  options.rangeInput.addEventListener('input', () => scheduleCommit(Number(options.rangeInput.value)));
  options.numberInput.addEventListener('change', () => commit(Number(options.numberInput.value)));

  return {
    showTimingOffset(value: number): void {
      showTimingOffsetValue(options.rangeInput, options.numberInput, options.output, value);
    },
  };
}

function showTimingOffsetValue(
  rangeInput: HTMLInputElement,
  numberInput: HTMLInputElement,
  output: HTMLOutputElement,
  value: number,
): number {
  const normalized = normalizeSubtitleTimingOffsetSeconds(value);
  const label = `${normalized >= 0 ? '+' : ''}${normalized.toFixed(1)}s`;

  rangeInput.value = String(normalized);
  numberInput.value = normalized.toFixed(1);
  output.value = label;
  output.textContent = label;

  return normalized;
}
