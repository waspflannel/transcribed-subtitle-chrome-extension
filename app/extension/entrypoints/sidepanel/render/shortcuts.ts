import { escapeHtml } from '../../../utils/html';
import { t } from '../../../utils/i18n';
import { DEFAULT_KEYBOARD_SHORTCUTS } from '../../../utils/keyboard-shortcuts';

export function shortcutHelpHtml(): string {
  return DEFAULT_KEYBOARD_SHORTCUTS.map(
    (shortcut) => `
      <div class="shortcut-row">
        <span title="${escapeHtml(t(shortcut.description))}">${escapeHtml(t(shortcut.label))}</span>
        <kbd>${escapeHtml(shortcut.display)}</kbd>
      </div>
    `,
  ).join('');
}
