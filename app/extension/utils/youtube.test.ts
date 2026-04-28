import { describe, expect, it } from 'vitest';

import { createYoutubeWatchUrl, parseYoutubePage } from './youtube';

describe('parseYoutubePage', () => {
  it('extracts a valid YouTube watch video ID', () => {
    expect(parseYoutubePage('https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=abc')).toMatchObject({
      supported: true,
      videoId: 'dQw4w9WgXcQ',
    });
  });

  it('rejects non-watch YouTube pages', () => {
    expect(parseYoutubePage('https://www.youtube.com/results?search_query=arabic')).toMatchObject({
      supported: false,
      reason: 'not_watch_page',
    });
  });

  it('rejects missing and malformed video IDs', () => {
    expect(parseYoutubePage('https://www.youtube.com/watch')).toMatchObject({
      supported: false,
      reason: 'missing_video_id',
    });

    expect(parseYoutubePage('https://www.youtube.com/watch?v=bad')).toMatchObject({
      supported: false,
      reason: 'invalid_video_id',
      videoId: 'bad',
    });
  });

  it('creates canonical watch URLs', () => {
    expect(createYoutubeWatchUrl('dQw4w9WgXcQ')).toBe('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
  });
});
