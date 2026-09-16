/** Keeps confirmation tied to the selected video and account, without credentials. */
export function generationConfirmationContext(
  tabId: number,
  youtubeVideoId: string,
  accountId: string,
): string {
  return JSON.stringify([tabId, youtubeVideoId, accountId]);
}
