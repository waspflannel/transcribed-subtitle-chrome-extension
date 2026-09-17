import { t, interfaceLocale } from '../../../utils/i18n';
import { escapeHtml } from '../../../utils/html';
import { resolveBackendApiBaseUrl } from '../../../utils/api-config';
import type { AccountState } from '../../../utils/messages';
import type { ExtensionSettings } from '../../../utils/settings-model';

export function accountFeatureListHtml(accountState: AccountState, settings: ExtensionSettings): string {
  const authenticated = accountState.status === 'authenticated';

  if (!authenticated) {
    return featureRows([[t("Account"), t("Available after sign-in")]]);
  }

  return featureRows([
    [t("Generation access"), t("Checked when you generate")],
    [t("Cue translation"), settings.showTranslation ? t("Selected") : t("Not selected")],
    [t("Romanization"), settings.showRomanization ? t("Selected") : t("Not selected")],
    [t("Word cards"), t("On click")],
    [t("Queue speed"), t(accountState.tierSpeedLabel)],
  ]);
}

export function accountBillingLinkHtml(baseUrl?: string): string {
  const origin = new URL(resolveBackendApiBaseUrl(baseUrl ?? import.meta.env.WXT_BACKEND_API_BASE_URL)).origin;

  return `<a class="btn-ghost" href="${escapeHtml(origin + '/dashboard?lang=' + interfaceLocale())}" target="_blank" rel="noopener noreferrer">${escapeHtml(t("Account and billing"))}</a>`;
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
