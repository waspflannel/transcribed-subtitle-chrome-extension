export type KeyboardShortcutAction =
  | 'replay-current-cue'
  | 'previous-cue'
  | 'next-cue'
  | 'toggle-translation'
  | 'toggle-source-blur'
  | 'toggle-auto-pause'
  | 'toggle-transcript'
  | 'copy-current-cue'
  | 'save-current-cue';

export interface KeyboardShortcutDefinition {
  action: KeyboardShortcutAction;
  label: string;
  description: string;
  display: string;
  key: string;
  altKey: boolean;
  shiftKey: boolean;
  ctrlKey: boolean;
  metaKey: boolean;
}

export const DEFAULT_KEYBOARD_SHORTCUTS: readonly KeyboardShortcutDefinition[] = [
  {
    action: 'replay-current-cue',
    label: 'Replay cue',
    description: 'Replay the active subtitle cue from its start.',
    display: 'Alt+Shift+R',
    key: 'r',
    altKey: true,
    shiftKey: true,
    ctrlKey: false,
    metaKey: false,
  },
  {
    action: 'previous-cue',
    label: 'Previous cue',
    description: 'Jump to the previous generated subtitle cue.',
    display: 'Alt+Shift+Left',
    key: 'arrowleft',
    altKey: true,
    shiftKey: true,
    ctrlKey: false,
    metaKey: false,
  },
  {
    action: 'next-cue',
    label: 'Next cue',
    description: 'Jump to the next generated subtitle cue.',
    display: 'Alt+Shift+Right',
    key: 'arrowright',
    altKey: true,
    shiftKey: true,
    ctrlKey: false,
    metaKey: false,
  },
  {
    action: 'toggle-translation',
    label: 'Translation',
    description: 'Show or hide cue translations.',
    display: 'Alt+Shift+T',
    key: 't',
    altKey: true,
    shiftKey: true,
    ctrlKey: false,
    metaKey: false,
  },
  {
    action: 'toggle-source-blur',
    label: 'Source blur',
    description: 'Blur or reveal source words.',
    display: 'Alt+Shift+B',
    key: 'b',
    altKey: true,
    shiftKey: true,
    ctrlKey: false,
    metaKey: false,
  },
  {
    action: 'toggle-auto-pause',
    label: 'Hover pause',
    description: 'Turn word-hover video pause on or off.',
    display: 'Alt+Shift+A',
    key: 'a',
    altKey: true,
    shiftKey: true,
    ctrlKey: false,
    metaKey: false,
  },
  {
    action: 'toggle-transcript',
    label: 'Transcript',
    description: 'Focus the side-panel Transcript view.',
    display: 'Alt+Shift+X',
    key: 'x',
    altKey: true,
    shiftKey: true,
    ctrlKey: false,
    metaKey: false,
  },
  {
    action: 'copy-current-cue',
    label: 'Copy cue',
    description: 'Copy the active cue text.',
    display: 'Alt+Shift+C',
    key: 'c',
    altKey: true,
    shiftKey: true,
    ctrlKey: false,
    metaKey: false,
  },
  {
    action: 'save-current-cue',
    label: 'Save cue',
    description: 'Reserve the current cue for the saved-items workflow.',
    display: 'Alt+Shift+S',
    key: 's',
    altKey: true,
    shiftKey: true,
    ctrlKey: false,
    metaKey: false,
  },
];

export function shortcutActionFromKeyboardEvent(
  event: Pick<KeyboardEvent, 'altKey' | 'ctrlKey' | 'key' | 'metaKey' | 'shiftKey' | 'target' | 'composedPath'>,
  options: { enabled: boolean },
): KeyboardShortcutAction | null {
  if (!options.enabled || isEditableShortcutTarget(event)) {
    return null;
  }

  const key = event.key.toLowerCase();
  const shortcut = DEFAULT_KEYBOARD_SHORTCUTS.find(
    (candidate) =>
      candidate.key === key &&
      candidate.altKey === event.altKey &&
      candidate.shiftKey === event.shiftKey &&
      candidate.ctrlKey === event.ctrlKey &&
      candidate.metaKey === event.metaKey,
  );

  return shortcut?.action ?? null;
}

export function isEditableShortcutTarget(
  event: Pick<KeyboardEvent, 'target' | 'composedPath'>,
): boolean {
  const path = typeof event.composedPath === 'function' ? event.composedPath() : [event.target];

  return path.some((candidate) => isElementLike(candidate) && isEditableElement(candidate));
}

interface ElementLike {
  tagName?: string;
  closest?: (selector: string) => unknown;
}

function isElementLike(value: unknown): value is ElementLike {
  return typeof value === 'object' && value !== null;
}

function isEditableElement(element: ElementLike): boolean {
  const tagName = element.tagName?.toLowerCase();

  if (tagName === 'input' || tagName === 'textarea' || tagName === 'select') {
    return true;
  }

  if (element.closest?.('[contenteditable="true"], [role="textbox"], [aria-multiline="true"]')) {
    return true;
  }

  return false;
}
