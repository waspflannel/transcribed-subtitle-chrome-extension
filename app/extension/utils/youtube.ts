export const YOUTUBE_VIDEO_ID_LENGTH = 11;
export const YOUTUBE_VIDEO_ID_PATTERN = /^[A-Za-z0-9_-]{11}$/;

export type UnsupportedYoutubePageReason =
  | 'invalid_url'
  | 'not_youtube'
  | 'not_watch_page'
  | 'missing_video_id'
  | 'invalid_video_id';

export type YoutubePageInfo =
  | {
      supported: true;
      videoId: string;
      url: string;
    }
  | {
      supported: false;
      reason: UnsupportedYoutubePageReason;
      url: string;
      videoId?: string;
    };

export function parseYoutubePage(input: string | URL): YoutubePageInfo {
  const url = toUrl(input);

  if (!isVideoUrlValid(url)) {
    return {
      supported: false,
      reason: 'invalid_url',
      url: String(input),
    };
  }

  const canonicalUrl = url.toString();

  if (!isYoutubeUrl(url)) {
    return {
      supported: false,
      reason: 'not_youtube',
      url: canonicalUrl,
    };
  }

  if (!isYoutubeWatchPage(url)) {
    return {
      supported: false,
      reason: 'not_watch_page',
      url: canonicalUrl,
    };
  }

  const videoId = url.searchParams.get('v')?.trim() ?? '';

  if (!hasVideoId(videoId)) {
    return {
      supported: false,
      reason: 'missing_video_id',
      url: canonicalUrl,
    };
  }

  if (!isVideoIdValid(videoId)) {
    return {
      supported: false,
      reason: 'invalid_video_id',
      url: canonicalUrl,
      videoId,
    };
  }

  return {
    supported: true,
    videoId,
    url: canonicalUrl,
  };
}

function isVideoUrlValid(url: URL | null): url is URL {
  return url !== null;
}

function isYoutubeUrl(url: URL): boolean {
  const hostname = url.hostname.toLowerCase();

  return hostname === 'youtube.com' || hostname.endsWith('.youtube.com');
}

function isYoutubeWatchPage(url: URL): boolean {
  return url.pathname === '/watch';
}

function hasVideoId(videoId: string): boolean {
  return videoId.length > 0;
}

function isVideoIdValid(videoId: string): boolean {
  return YOUTUBE_VIDEO_ID_PATTERN.test(videoId);
}

function toUrl(input: string | URL): URL | null {
  if (input instanceof URL) {
    return input;
  }

  try {
    return new URL(input);
  } catch {
    return null;
  }
}

export function createYoutubeWatchUrl(videoId: string): string {
  return `https://www.youtube.com/watch?v=${encodeURIComponent(videoId)}`;
}
