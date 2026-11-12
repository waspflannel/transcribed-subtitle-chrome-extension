import { forgetRememberedTrack } from '../active-tracks';
import { SubtitleApiError } from '../api';
import { UNDO_DELETE_MS, type PendingJobDelete } from '../messages';
import { getOrCreateInstallId } from '../settings';
import { changeSavedGenerationFromPanel } from './generation';
import { runtime } from './runtime';

/*
 * Deletes wait here so the panel can offer Undo. The background owns the timer,
 * so closing the panel still deletes. If the worker restarts first, the delete is
 * lost and the job stays, which is the safe direction.
 */
const pending = new Map<string, { job: PendingJobDelete; timer: ReturnType<typeof setTimeout>; committing?: Promise<void> }>();

export function isPendingDelete(jobId: string): boolean {
  return pending.has(jobId);
}

export function scheduleJobDeletes(jobs: readonly PendingJobDelete[]): void {
  for (const job of jobs) {
    if (pending.has(job.jobId)) continue;
    const timer = setTimeout(() => void commitJobDelete(job.jobId), UNDO_DELETE_MS);
    pending.set(job.jobId, { job, timer });
  }
}

export function undoJobDeletes(jobIds: readonly string[]): void {
  for (const jobId of jobIds) {
    const entry = pending.get(jobId);
    if (!entry || entry.committing) continue; // Too late: the delete is already on its way.
    clearTimeout(entry.timer);
    pending.delete(jobId);
  }
}

export async function flushJobDeletes(): Promise<void> {
  await Promise.all([...pending.keys()].map(commitJobDelete));
}

function commitJobDelete(jobId: string): Promise<void> {
  const entry = pending.get(jobId);
  if (!entry) return Promise.resolve();
  clearTimeout(entry.timer);
  // Stay "pending" until the backend answers, so history never flashes the job back.
  entry.committing ??= sendJobDelete(entry.job).finally(() => pending.delete(jobId));
  return entry.committing;
}

async function sendJobDelete(job: PendingJobDelete): Promise<void> {
  const { jobId } = job;
  try {
    // A track still shown in its tab goes through the guarded delete, which also
    // unloads it and recovers another saved generation for that video.
    if (job.tabId !== undefined && job.trackId) {
      try {
        await changeSavedGenerationFromPanel({
          type: 'panel.deleteGeneration', jobId, currentJobId: jobId, trackId: job.trackId,
          youtubeVideoId: job.youtubeVideoId, tabId: job.tabId,
        });
        return;
      } catch {
        // The tab moved on; fall back to a plain delete. That tab revalidates on its next page entry.
      }
    }
    try {
      await runtime.subtitleApi.deleteSavedGeneration(await getOrCreateInstallId(), jobId);
    } catch (error) {
      if (!(error instanceof SubtitleApiError && error.code === 'not_found')) throw error;
    }
    if (job.trackId) await forgetRememberedTrack(job.youtubeVideoId, job.trackId);
    runtime.cachedPanelJobHistory = runtime.cachedPanelJobHistory.filter(item => item.jobId !== jobId);
  } catch (error) {
    console.warn('extension.pending_delete_failed', {
      jobId,
      youtubeVideoId: job.youtubeVideoId,
      error: error instanceof Error ? error.message : 'Unknown delete error',
    });
  }
}
