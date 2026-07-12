import type { PanelState } from '../messages';

export type PanelView = 'watch' | 'study' | 'history' | 'account';

/**
 * The panel always opens on Watch: it is state-driven and morphs through
 * sign-in prompt, setup, progress, and transcript, so users keep one stable
 * mental map instead of being teleported between tabs.
 */
export function selectDefaultView(_state: PanelState): PanelView {
  return 'watch';
}
