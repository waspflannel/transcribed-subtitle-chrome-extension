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

  it('does not prefer an offscreen playing video over an onscreen paused player', () => {
    const offscreenPlayingVideo = fakeVideo({ paused: false, width: 320, height: 568, top: 900 });
    const onscreenPausedVideo = fakeVideo({ paused: true, width: 320, height: 568 });

    expect(findActiveYoutubeVideo(fakeDocument([offscreenPlayingVideo, onscreenPausedVideo], { width: 1280, height: 720 }))).toBe(
      onscreenPausedVideo,
    );
  });

  it('returns null when the page has no videos', () => {
    expect(findActiveYoutubeVideo(fakeDocument([]))).toBeNull();
  });
});

function fakeDocument(videos: HTMLVideoElement[], viewport?: { width: number; height: number }): Pick<Document, 'querySelectorAll'> {
  const document = {
    querySelectorAll: () => videos as unknown as NodeListOf<HTMLVideoElement>,
  } as Pick<Document, 'querySelectorAll'>;
  if (viewport) {
    Object.defineProperty(document, 'defaultView', {
      value: { innerWidth: viewport.width, innerHeight: viewport.height, getComputedStyle: () => ({ display: 'block', visibility: 'visible', opacity: '1' }) },
    });
    for (const video of videos) Object.defineProperty(video, 'ownerDocument', { value: document });
  }

  return {
    querySelectorAll: () => videos as unknown as NodeListOf<HTMLVideoElement>,
  };
}

function fakeVideo(options: { paused: boolean; width: number; height: number; top?: number }): HTMLVideoElement {
  const top = options.top ?? 0;
  return {
    paused: options.paused,
    ended: false,
    getBoundingClientRect: () =>
      ({
        top,
        bottom: top + options.height,
        left: 0,
        right: options.width,
        width: options.width,
        height: options.height,
      }) as DOMRect,
  } as HTMLVideoElement;
}
