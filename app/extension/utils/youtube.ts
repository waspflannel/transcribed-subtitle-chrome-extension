const YOUTUBE_VIDEO_ID_PATTERN = /^[A-Za-z0-9_-]{11}$/;

export type YoutubeMediaKind = 'video' | 'short';

export type UnsupportedYoutubePageReason =
  | 'invalid_url'
  | 'not_youtube'
  | 'unsupported_page'
  | 'missing_video_id'
  | 'invalid_video_id';

export type YoutubePageInfo =
  | {
      supported: true;
      videoId: string;
      url: string;
      mediaKind: YoutubeMediaKind;
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

  if (url.pathname === '/watch') {
    return pageInfoForVideoId({
      videoId: url.searchParams.get('v')?.trim() ?? '',
      url: canonicalUrl,
      mediaKind: 'video',
    });
  }

  const pathSegments = url.pathname.split('/').filter((segment) => segment !== '');

  if (pathSegments[0] === 'shorts') {
    return pageInfoForVideoId({
      videoId: pathSegments.length === 2 ? pathSegments[1]?.trim() ?? '' : '',
      url: canonicalUrl,
      mediaKind: 'short',
    });
  }

  return {
    supported: false,
    reason: 'unsupported_page',
    url: canonicalUrl,
  };
}

function pageInfoForVideoId(options: {
  videoId: string;
  url: string;
  mediaKind: YoutubeMediaKind;
}): YoutubePageInfo {
  if (options.videoId.length === 0) {
    return {
      supported: false,
      reason: 'missing_video_id',
      url: options.url,
    };
  }

  if (!YOUTUBE_VIDEO_ID_PATTERN.test(options.videoId)) {
    return {
      supported: false,
      reason: 'invalid_video_id',
      url: options.url,
      videoId: options.videoId,
    };
  }

  return {
    supported: true,
    videoId: options.videoId,
    url: options.url,
    mediaKind: options.mediaKind,
  };
}
