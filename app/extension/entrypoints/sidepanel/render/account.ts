import { escapeHtml } from '../../../utils/html';
import type { AccountState } from '../../../utils/messages';
import type { ExtensionSettings } from '../../../utils/settings-model';

export function accountFeatureListHtml(accountState: AccountState, settings: ExtensionSettings): string {
  const authenticated = accountState.status === 'authenticated';

  return [
    ['Subtitle generation', authenticated ? 'Enabled' : 'Sign in required'],
    ['Cue translation', settings.showTranslation ? 'On for next job' : 'Available'],
    ['Romanization', settings.showRomanization ? 'On for next job' : 'Available'],
    ['Full word cards', settings.fullTrackEnrichment ? 'On for next job' : 'Available'],
    ['Priority speed', accountState.upgradeAvailable ? 'Upgrade preview' : 'Included'],
  ]
    .map(
      ([label, value]) => `
        <div class="feature-row">
          <span>${escapeHtml(label)}</span>
          <strong>${escapeHtml(value)}</strong>
        </div>
      `,
    )
    .join('');
}
