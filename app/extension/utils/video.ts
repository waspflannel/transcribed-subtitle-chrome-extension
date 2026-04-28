export function findActiveVideoElement(root: ParentNode = document): HTMLVideoElement | null {
  const videos = Array.from(root.querySelectorAll('video')).filter(
    (element): element is HTMLVideoElement => element instanceof HTMLVideoElement,
  );

  if (videos.length === 0) {
    return null;
  }

  return videos
    .map((video) => ({
      video,
      score: scoreVideoElement(video),
    }))
    .filter((candidate) => candidate.score > 0)
    .sort((left, right) => right.score - left.score)[0]?.video ?? null;
}

function scoreVideoElement(video: HTMLVideoElement): number {
  const rect = video.getBoundingClientRect();
  const style = globalThis.getComputedStyle(video);

  if (style.display === 'none' || style.visibility === 'hidden' || rect.width === 0 || rect.height === 0) {
    return 0;
  }

  let score = rect.width * rect.height;

  if (video.classList.contains('html5-main-video')) {
    score += 10_000;
  }

  if (video.currentSrc || video.src) {
    score += 1_000;
  }

  if (!video.paused) {
    score += 500;
  }

  if (video.readyState > HTMLMediaElement.HAVE_NOTHING) {
    score += 250;
  }

  return score;
}
