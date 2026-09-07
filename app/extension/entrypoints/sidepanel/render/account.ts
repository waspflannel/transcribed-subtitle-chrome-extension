import { escapeHtml } from '../../../utils/html';
import { resolveBackendApiBaseUrl } from '../../../utils/api-config';
import type { AccountState } from '../../../utils/messages';
import type { ExtensionSettings } from '../../../utils/settings-model';

export function accountFeatureListHtml(accountState: AccountState, settings: ExtensionSettings): string {
  const authenticated = accountState.status === 'authenticated';

  if (!authenticated) {
    return featureRows([['Account', 'Available after sign-in']]);
  }

  return featureRows([
    ['Generation access', 'Checked when you generate'],
    ['Cue translation', settings.showTranslation ? 'Selected' : 'Not selected'],
    ['Romanization', settings.showRomanization ? 'Selected' : 'Not selected'],
    ['Full word cards', settings.fullTrackEnrichment ? 'Selected' : 'Not selected'],
    ['Queue speed', accountState.tierSpeedLabel],
  ]);
}

export function accountBillingLinkHtml(baseUrl?: string): string {
  const origin = new URL(resolveBackendApiBaseUrl(baseUrl ?? import.meta.env.WXT_BACKEND_API_BASE_URL)).origin;

  return `<a class="btn-ghost" href="${escapeHtml(origin + '/dashboard')}" target="_blank" rel="noopener noreferrer">Account and billing</a>`;
}

function featureRows(rows: readonly [string, string][]): string {
  return rows
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
