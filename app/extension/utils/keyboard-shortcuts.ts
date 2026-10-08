export type KeyboardShortcutAction =
  | 'replay-current-cue'
  | 'previous-cue'
  | 'next-cue'
  | 'toggle-translation'
  | 'toggle-source-blur'
  | 'toggle-auto-pause'
  | 'toggle-transcript'
  | 'copy-current-cue';

export interface KeyboardShortcutDefinition {
  action: KeyboardShortcutAction;
  label: string;
  description: string;
  display: string;
  /** Physical key (KeyboardEvent.code). Option+Shift on macOS and non-US layouts change `key`, not `code`. */
  code: string;
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
    code: 'KeyR',
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
    code: 'ArrowLeft',
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
    code: 'ArrowRight',
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
    code: 'KeyT',
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
    code: 'KeyB',
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
    code: 'KeyA',
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
    code: 'KeyX',
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
    code: 'KeyC',
    altKey: true,
    shiftKey: true,
    ctrlKey: false,
    metaKey: false,
  },
];

export function shortcutActionFromKeyboardEvent(
  event: Pick<KeyboardEvent, 'altKey' | 'ctrlKey' | 'code' | 'metaKey' | 'shiftKey' | 'target' | 'composedPath'>,
  options: { enabled: boolean },
): KeyboardShortcutAction | null {
  if (!options.enabled || isEditableShortcutTarget(event)) {
    return null;
  }

  const shortcut = DEFAULT_KEYBOARD_SHORTCUTS.find(
    (candidate) =>
      candidate.code === event.code &&
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
  const target = path.find((candidate) => isElementLike(candidate));

  return target !== undefined && (isEditableElement(target) || isRoleTextbox(target));
}

interface ElementLike {
  tagName?: string;
  isContentEditable?: boolean;
  closest?: (selector: string) => unknown;
}

function isElementLike(value: unknown): value is ElementLike {
  if (typeof value !== 'object' || value === null) {
    return false;
  }

  const candidate = value as Record<string, unknown>;
  return typeof candidate.tagName === 'string'
    || 'isContentEditable' in candidate
    || typeof candidate.closest === 'function';
}

function isEditableElement(element: ElementLike): boolean {
  if (element.isContentEditable === true) {
    return true;
  }

  const tagName = element.tagName?.toLowerCase();

  if (tagName === 'input' || tagName === 'textarea' || tagName === 'select') {
    return true;
  }

  return false;
}

function isRoleTextbox(element: ElementLike): boolean {
  return Boolean(element.closest?.('[role="textbox"]'));
}
