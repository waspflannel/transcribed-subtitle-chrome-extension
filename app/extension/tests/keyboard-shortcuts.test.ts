import { describe, expect, it } from 'vitest';

import {
  DEFAULT_KEYBOARD_SHORTCUTS,
  shortcutActionFromKeyboardEvent,
} from '../utils/keyboard-shortcuts';

describe('keyboard shortcuts', () => {
  it('maps the default modified key chords to shortcut actions', () => {
    for (const shortcut of DEFAULT_KEYBOARD_SHORTCUTS) {
      expect(
        shortcutActionFromKeyboardEvent(keyboardEvent(shortcut.key), {
          enabled: true,
        }),
      ).toBe(shortcut.action);
    }
  });

  it('does not expose the removed save-cue action', () => {
    expect(DEFAULT_KEYBOARD_SHORTCUTS.map((shortcut) => shortcut.action)).not.toContain('save-current-cue');
  });

  it('does not dispatch when shortcuts are disabled', () => {
    expect(shortcutActionFromKeyboardEvent(keyboardEvent('r'), { enabled: false })).toBeNull();
  });

  it('ignores editable targets', () => {
    expect(
      shortcutActionFromKeyboardEvent(
        keyboardEvent('r', {
          path: [{ tagName: 'INPUT' }],
        }),
        { enabled: true },
      ),
    ).toBeNull();
    expect(
      shortcutActionFromKeyboardEvent(
        keyboardEvent('r', {
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
        keyboardEvent('r', {
          path: [{ tagName: 'DIV', isContentEditable: true }],
        }),
        { enabled: true },
      ),
    ).toBeNull();
    expect(
      shortcutActionFromKeyboardEvent(
        keyboardEvent('r', {
          path: [{ tagName: 'DIV', closest: () => ({}) }],
        }),
        { enabled: true },
      ),
    ).toBeNull();
  });

  it('ignores unmodified YouTube-owned keys', () => {
    expect(
      shortcutActionFromKeyboardEvent(
        keyboardEvent('k', {
          altKey: false,
          shiftKey: false,
        }),
        { enabled: true },
      ),
    ).toBeNull();
  });
});

function keyboardEvent(
  key: string,
  overrides: Partial<{
    altKey: boolean;
    ctrlKey: boolean;
    metaKey: boolean;
    shiftKey: boolean;
    path: unknown[];
  }> = {},
): Pick<KeyboardEvent, 'altKey' | 'ctrlKey' | 'key' | 'metaKey' | 'shiftKey' | 'target' | 'composedPath'> {
  const path = overrides.path ?? [{ tagName: 'BODY' }];

  return {
    altKey: overrides.altKey ?? true,
    ctrlKey: overrides.ctrlKey ?? false,
    key,
    metaKey: overrides.metaKey ?? false,
    shiftKey: overrides.shiftKey ?? true,
    target: path[0] as EventTarget,
    composedPath: () => path as EventTarget[],
  };
}
