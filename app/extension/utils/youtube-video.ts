export function findActiveYoutubeVideo(document: Pick<Document, 'querySelectorAll'>): HTMLVideoElement | null {
  const videos = Array.from(document.querySelectorAll('video'));

  return videos.find((video) => isVisibleVideo(video) && isPlayingVideo(video))
    ?? videos.find(isVisibleVideo)
    ?? videos.find(isPlayingVideo)
    ?? videos[0]
    ?? null;
}

function isVisibleVideo(video: HTMLVideoElement): boolean {
  const rect = video.getBoundingClientRect();

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
