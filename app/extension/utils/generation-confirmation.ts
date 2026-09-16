import type { ExtensionSettings } from './settings-model';

/** Identifies exactly the generation details the learner confirmed, without credentials. */
export function generationConfirmationContext(
  tabId: number,
  youtubeVideoId: string,
  accountId: string,
  videoDurationSeconds: number | undefined,
  settings: ExtensionSettings,
): string {
  return JSON.stringify([tabId, youtubeVideoId, accountId, videoDurationSeconds ?? null,
    settings.sourceLanguage, settings.targetLanguage, settings.aiProvider,
    settings.showRomanization, settings.showTranslation]);
}
