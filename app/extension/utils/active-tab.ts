import type { Browser } from 'wxt/browser';

export function activeTabQuery(windowId?: number): Browser.tabs.QueryInfo {
  return typeof windowId === 'number'
    ? { active: true, windowId }
    : { active: true, currentWindow: true };
}
