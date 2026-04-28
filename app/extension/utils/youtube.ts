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

  if (!url) {
    return {
      supported: false,
      reason: 'invalid_url',
      url: String(input),
    };
  }

  const canonicalUrl = url.toString();

  if (!isYoutubeHost(url.hostname)) {
    return {
      supported: false,
      reason: 'not_youtube',
      url: canonicalUrl,
    };
  }

  if (url.pathname !== '/watch') {
    return {
      supported: false,
      reason: 'not_watch_page',
      url: canonicalUrl,
    };
  }

  const videoId = url.searchParams.get('v')?.trim() ?? '';

  if (videoId.length === 0) {
    return {
      supported: false,
      reason: 'missing_video_id',
      url: canonicalUrl,
    };
  }

  if (!isYoutubeVideoId(videoId)) {
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

export function isYoutubeVideoId(value: string): boolean {
  return YOUTUBE_VIDEO_ID_PATTERN.test(value);
}

export function createYoutubeWatchUrl(videoId: string): string {
  return `https://www.youtube.com/watch?v=${encodeURIComponent(videoId)}`;
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

function isYoutubeHost(hostname: string): boolean {
  const normalizedHostname = hostname.toLowerCase();

  return normalizedHostname === 'youtube.com' || normalizedHostname.endsWith('.youtube.com');
}
