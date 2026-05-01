const YOUTUBE_VIDEO_ID_PATTERN = /^[A-Za-z0-9_-]{11}$/;

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
  let url: URL;

  try {
    url = input instanceof URL ? input : new URL(input);
  } catch {
    return {
      supported: false,
      reason: 'invalid_url',
      url: String(input),
    };
  }

  const canonicalUrl = url.toString();
  const hostname = url.hostname.toLowerCase();

  if (hostname !== 'youtube.com' && !hostname.endsWith('.youtube.com')) {
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

  if (!YOUTUBE_VIDEO_ID_PATTERN.test(videoId)) {
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
