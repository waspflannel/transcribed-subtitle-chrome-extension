import { describe, expect, it } from 'vitest';

import {
  DEFAULT_KEYBOARD_SHORTCUTS,
  shortcutActionFromKeyboardEvent,
} from '../utils/keyboard-shortcuts';

describe('keyboard shortcuts', () => {
  it('maps the default modified key chords to shortcut actions', () => {
    for (const shortcut of DEFAULT_KEYBOARD_SHORTCUTS) {
      expect(
        shortcutActionFromKeyboardEvent(keyboardEvent(shortcut.code), {
          enabled: true,
        }),
      ).toBe(shortcut.action);
    }
  });

  it('matches physical keys when Option+Shift or the layout changes the typed character', () => {
    expect(shortcutActionFromKeyboardEvent(keyboardEvent('KeyR', { key: '‰' }), { enabled: true })).toBe('replay-current-cue');
    expect(shortcutActionFromKeyboardEvent(keyboardEvent('ArrowLeft', { key: 'ArrowLeft' }), { enabled: true })).toBe('previous-cue');
  });

  it('does not expose the removed save-cue action', () => {
    expect(DEFAULT_KEYBOARD_SHORTCUTS.map((shortcut) => shortcut.action)).not.toContain('save-current-cue');
  });

  it('does not dispatch when shortcuts are disabled', () => {
    expect(shortcutActionFromKeyboardEvent(keyboardEvent('KeyR'), { enabled: false })).toBeNull();
  });

  it('ignores editable targets', () => {
    expect(
      shortcutActionFromKeyboardEvent(
        keyboardEvent('KeyR', {
          path: [{ tagName: 'INPUT' }],
        }),
        { enabled: true },
      ),
    ).toBeNull();
    expect(
      shortcutActionFromKeyboardEvent(
        keyboardEvent('KeyR', {
          path: [
            {
              tagName: 'DIV',
              closest: () => ({ role: 'textbox' }),
            },
          ],
        }),
        { enabled: true },
      ),
    ).toBeNull();
    expect(
      shortcutActionFromKeyboardEvent(
        keyboardEvent('KeyR', {
          path: [{ tagName: 'DIV', isContentEditable: true }],
        }),
        { enabled: true },
      ),
    ).toBeNull();
    expect(
      shortcutActionFromKeyboardEvent(
        keyboardEvent('KeyR', {
          path: [
            { tagName: 'SPAN', isContentEditable: false },
            { tagName: 'DIV', isContentEditable: true },
          ],
        }),
        { enabled: true },
      ),
    ).toBe('replay-current-cue');
    expect(
      shortcutActionFromKeyboardEvent(
        keyboardEvent('KeyR', {
          path: [{ tagName: 'DIV', closest: () => ({}) }],
        }),
        { enabled: true },
      ),
    ).toBeNull();
  });

  it('ignores unmodified YouTube-owned keys', () => {
    expect(
      shortcutActionFromKeyboardEvent(
        keyboardEvent('KeyK', {
          altKey: false,
          shiftKey: false,
        }),
        { enabled: true },
      ),
    ).toBeNull();
  });
});

function keyboardEvent(
  code: string,
  overrides: Partial<{
    altKey: boolean;
    ctrlKey: boolean;
    metaKey: boolean;
    shiftKey: boolean;
    key: string;
    path: unknown[];
  }> = {},
): Pick<KeyboardEvent, 'altKey' | 'ctrlKey' | 'code' | 'key' | 'metaKey' | 'shiftKey' | 'target' | 'composedPath'> {
  const path = overrides.path ?? [{ tagName: 'BODY' }];

  return {
    altKey: overrides.altKey ?? true,
    ctrlKey: overrides.ctrlKey ?? false,
    code,
    key: overrides.key ?? code,
    metaKey: overrides.metaKey ?? false,
    shiftKey: overrides.shiftKey ?? true,
    target: path[0] as EventTarget,
    composedPath: () => path as EventTarget[],
  };
}
