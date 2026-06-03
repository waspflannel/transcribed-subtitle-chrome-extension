import type { SubtitleJobHistoryItem } from './contracts';
import { parseYoutubePage, type YoutubeMediaKind } from './youtube';

export type JobHistoryMediaGroups<T> = {
  videos: T[];
  shorts: T[];
};

export function jobHistoryMediaKind(job: Pick<SubtitleJobHistoryItem, 'youtubeUrl'>): YoutubeMediaKind {
  const page = parseYoutubePage(job.youtubeUrl);

  return page.supported ? page.mediaKind : 'video';
}

export function groupJobHistoryByMediaKind<T extends Pick<SubtitleJobHistoryItem, 'youtubeUrl'>>(
  jobs: readonly T[],
): JobHistoryMediaGroups<T> {
  return jobs.reduce<JobHistoryMediaGroups<T>>(
    (groups, job) => {
      if (jobHistoryMediaKind(job) === 'short') {
        groups.shorts.push(job);
      } else {
        groups.videos.push(job);
      }

      return groups;
    },
    {
      videos: [],
      shorts: [],
    },
  );
}
