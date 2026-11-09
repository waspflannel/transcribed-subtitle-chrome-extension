import { DEFAULT_BACKEND_API_BASE_URL } from './api';
import { getOrCreateInstallId } from './settings';
export interface InstanceContext {
    sessionId: string;
}
export async function getInstanceContext(): Promise<InstanceContext> {
    return { sessionId: await getOrCreateInstallId() };
}
