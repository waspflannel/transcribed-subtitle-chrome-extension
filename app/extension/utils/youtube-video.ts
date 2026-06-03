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

  return rect.width > 0 && rect.height > 0;
}

function isPlayingVideo(video: HTMLVideoElement): boolean {
  return !video.paused && !video.ended;
}
