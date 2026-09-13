export function findActiveYoutubeVideo(document: Pick<Document, 'querySelectorAll'>, rectangles?: Map<HTMLVideoElement, DOMRect>): HTMLVideoElement | null {
  const videos = Array.from(document.querySelectorAll('video'));

  const visible = (video: HTMLVideoElement): boolean => isVisibleVideo(video, rectangles);
  return videos.find((video) => visible(video) && isPlayingVideo(video))
    ?? videos.find(visible)
    ?? videos.find(isPlayingVideo)
    ?? videos[0]
    ?? null;
}

function isVisibleVideo(video: HTMLVideoElement, rectangles?: Map<HTMLVideoElement, DOMRect>): boolean {
  const rect = rectangles?.get(video) ?? video.getBoundingClientRect();
  rectangles?.set(video, rect);

  if (rect.width <= 0 || rect.height <= 0) {
    return false;
  }

  const view = video.ownerDocument?.defaultView;
  if (view && view.innerWidth > 0 && view.innerHeight > 0
    && (rect.right <= 0 || rect.bottom <= 0 || rect.left >= view.innerWidth || rect.top >= view.innerHeight)) {
    return false;
  }

  if (view) {
    const style = view.getComputedStyle(video);
    if (style.display === 'none' || style.visibility === 'hidden' || style.opacity === '0') {
      return false;
    }
  }

  return true;
}

function isPlayingVideo(video: HTMLVideoElement): boolean {
  return !video.paused && !video.ended;
}
