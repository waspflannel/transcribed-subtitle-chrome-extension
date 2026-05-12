import { describe, expect, it } from 'vitest';

import { parseYoutubePage } from '../utils/youtube';

describe('parseYoutubePage', () => {
  it('extracts a valid YouTube watch video ID', () => {
    expect(parseYoutubePage('https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=abc')).toMatchObject({
      supported: true,
      videoId: 'dQw4w9WgXcQ',
    });
  });

  it('rejects non-watch YouTube pages', () => {
    expect(parseYoutubePage('https://www.youtube.com/results?search_query=spanish')).toMatchObject({
      supported: false,
      reason: 'not_watch_page',
    });
  });

  it('rejects non-YouTube pages', () => {
    expect(parseYoutubePage('https://example.com/watch?v=dQw4w9WgXcQ')).toMatchObject({
      supported: false,
      reason: 'not_youtube',
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
});
