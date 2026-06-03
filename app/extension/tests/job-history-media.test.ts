import { describe, expect, it } from 'vitest';

import { groupJobHistoryByMediaKind, jobHistoryMediaKind } from '../utils/job-history-media';

describe('job history media grouping', () => {
  it('classifies stored Shorts URLs separately from standard videos', () => {
    expect(jobHistoryMediaKind({ youtubeUrl: 'https://www.youtube.com/shorts/c2x4mVfB9H8' })).toBe('short');
    expect(jobHistoryMediaKind({ youtubeUrl: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ' })).toBe('video');
  });

  it('groups jobs into Videos and Shorts while preserving order within each group', () => {
    const firstVideo = { youtubeUrl: 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', label: 'first-video' };
    const firstShort = { youtubeUrl: 'https://www.youtube.com/shorts/c2x4mVfB9H8', label: 'first-short' };
    const secondVideo = { youtubeUrl: 'https://youtu.be/kJQP7kiw5Fk', label: 'second-video' };
    const secondShort = { youtubeUrl: 'https://www.youtube.com/shorts/aqz-KE-bpKQ?feature=share', label: 'second-short' };

    expect(groupJobHistoryByMediaKind([firstVideo, firstShort, secondVideo, secondShort])).toEqual({
      videos: [firstVideo, secondVideo],
      shorts: [firstShort, secondShort],
    });
  });
});
