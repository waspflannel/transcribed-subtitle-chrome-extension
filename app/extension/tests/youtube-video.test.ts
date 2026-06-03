import { describe, expect, it } from 'vitest';

import { findActiveYoutubeVideo } from '../utils/youtube-video';

describe('findActiveYoutubeVideo', () => {
  it('prefers a visible playing Shorts video when multiple video elements exist', () => {
    const hiddenPlayingVideo = fakeVideo({ paused: false, width: 0, height: 0 });
    const visiblePausedVideo = fakeVideo({ paused: true, width: 320, height: 568 });
    const visiblePlayingVideo = fakeVideo({ paused: false, width: 320, height: 568 });

    expect(findActiveYoutubeVideo(fakeDocument([hiddenPlayingVideo, visiblePausedVideo, visiblePlayingVideo]))).toBe(
      visiblePlayingVideo,
    );
  });

  it('falls back to a visible paused video before an invisible playing video', () => {
    const hiddenPlayingVideo = fakeVideo({ paused: false, width: 0, height: 0 });
    const visiblePausedVideo = fakeVideo({ paused: true, width: 320, height: 568 });

    expect(findActiveYoutubeVideo(fakeDocument([hiddenPlayingVideo, visiblePausedVideo]))).toBe(visiblePausedVideo);
  });

  it('returns null when the page has no videos', () => {
    expect(findActiveYoutubeVideo(fakeDocument([]))).toBeNull();
  });
});

function fakeDocument(videos: HTMLVideoElement[]): Pick<Document, 'querySelectorAll'> {
  return {
    querySelectorAll: () => videos as unknown as NodeListOf<HTMLVideoElement>,
  };
}

function fakeVideo(options: { paused: boolean; width: number; height: number }): HTMLVideoElement {
  return {
    paused: options.paused,
    ended: false,
    getBoundingClientRect: () =>
      ({
        width: options.width,
        height: options.height,
      }) as DOMRect,
  } as HTMLVideoElement;
}
