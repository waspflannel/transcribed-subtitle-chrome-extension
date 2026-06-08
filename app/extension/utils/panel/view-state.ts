import type { PopupState } from '../messages';

export type PanelView = 'generate' | 'study' | 'transcript' | 'jobs' | 'account';

export function selectDefaultView(state: PopupState): PanelView {
  if (state.accountState.status !== 'authenticated') {
    return 'account';
  }

  if (state.subtitleState.type === 'ready') {
    return 'study';
  }

  return 'generate';
}
