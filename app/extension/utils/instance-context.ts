import { DEFAULT_BACKEND_API_BASE_URL } from './api';
import { getOrCreateInstallId } from './settings';

export interface InstanceContext { instanceId: string; sessionId: string }

export async function getInstanceContext(): Promise<InstanceContext> {
  return { instanceId: DEFAULT_BACKEND_API_BASE_URL, sessionId: await getOrCreateInstallId() };
}
