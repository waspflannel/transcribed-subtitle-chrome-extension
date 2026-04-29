export function findActiveVideoElement(root: ParentNode = document): HTMLVideoElement | null {
  const video = root.querySelector('video.html5-main-video');

  return video instanceof HTMLVideoElement ? video : null;
}
