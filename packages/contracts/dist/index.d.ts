// Source: schemas/create-subtitle-job-request.schema.json
export interface CreateSubtitleJobRequest {
  /**
   * Canonical 11-character YouTube video ID.
   */
  youtubeVideoId: string;
  /**
   * Optional watch URL captured by the extension for diagnostics and validation.
   */
  youtubeUrl?: string;
  /**
   * Known YouTube video duration. Backend must still enforce the 60 minute limit.
   */
  videoDurationSeconds?: number;
  /**
   * Source language requested by the extension. The first multilingual set supports Auto, Arabic, English, Spanish, Portuguese, French, German, and Italian.
   */
  sourceLanguage: 'auto' | 'ar' | 'en' | 'es' | 'pt' | 'fr' | 'de' | 'it';
  /**
   * English is the first release translation target.
   */
  targetLanguage: 'en';
  /**
   * on_demand returns transcript-first tracks with clickable token stubs. full enriches every cue before returning the track.
   */
  enrichmentMode?: 'on_demand' | 'full';
}

// Source: schemas/learning-token-request.schema.json
export interface LearningTokenRequest {
  /**
   * Generated subtitle track containing the clicked token.
   */
  trackId: string;
  cueId: string;
  tokenIndex: number;
}

// Source: schemas/learning-token-response.schema.json
export interface LearningTokenResponse {
  trackId: string;
  cueId: string;
  token: LearningToken;
}
export interface LearningToken {
  index: number;
  text: string;
  normalizedText?: string;
  lemma?: string;
  root?: string;
  partOfSpeech?: string;
  translation?: string;
  gloss?: string;
  romanization?: string;
  usageNote?: string;
}

// Source: schemas/job-response.schema.json
export interface JobResponse {
  jobId: string;
  youtubeVideoId: string;
  sourceLanguage: 'auto' | 'ar' | 'en' | 'es' | 'pt' | 'fr' | 'de' | 'it';
  targetLanguage: 'en';
  track: TrackResponse;
  createdAt: string;
  updatedAt: string;
  expiresAt: string;
}
export interface TrackResponse {
  trackId: string;
  jobId: string;
  youtubeVideoId: string;
  sourceLanguage: 'auto' | 'ar' | 'en' | 'es' | 'pt' | 'fr' | 'de' | 'it';
  targetLanguage: 'en';
  generatedAt: string;
  expiresAt: string;
  webVtt: string;
  /**
   * @minItems 1
   */
  cues: [SubtitleCue, ...SubtitleCue[]];
}
export interface SubtitleCue {
  cueId: string;
  index: number;
  startMs: number;
  endMs: number;
  sourceText: string;
  translatedText: string;
  romanization?: string;
  tokens: LearningToken[];
}
export interface LearningToken {
  index: number;
  text: string;
  normalizedText?: string;
  lemma?: string;
  root?: string;
  partOfSpeech?: string;
  translation?: string;
  gloss?: string;
  romanization?: string;
  usageNote?: string;
}

// Source: schemas/subtitle-job-history-response.schema.json
export interface SubtitleJobHistoryResponse {
  /**
   * @maxItems 25
   */
  jobs: SubtitleJobHistoryItem[];
}
export interface SubtitleJobHistoryItem {
  youtubeVideoId: string;
  youtubeUrl: string;
  status: 'running' | 'completed' | 'failed';
  startedAt: string;
  lastUpdatedAt?: string;
  completedAt?: string;
  stage?: 'preparing' | 'acquiring-audio' | 'transcribing' | 'romanizing' | 'enriching' | 'finalizing';
  progressPercent?: number;
  sourceLanguage?: string;
  jobId?: string;
  trackId?: string;
  expiresAt?: string;
  message?: string;
}

// Source: schemas/track-response.schema.json
export interface TrackResponse {
  trackId: string;
  jobId: string;
  youtubeVideoId: string;
  sourceLanguage: 'auto' | 'ar' | 'en' | 'es' | 'pt' | 'fr' | 'de' | 'it';
  targetLanguage: 'en';
  generatedAt: string;
  expiresAt: string;
  webVtt: string;
  /**
   * @minItems 1
   */
  cues: [SubtitleCue, ...SubtitleCue[]];
}
export interface SubtitleCue {
  cueId: string;
  index: number;
  startMs: number;
  endMs: number;
  sourceText: string;
  translatedText: string;
  romanization?: string;
  tokens: LearningToken[];
}
export interface LearningToken {
  index: number;
  text: string;
  normalizedText?: string;
  lemma?: string;
  root?: string;
  partOfSpeech?: string;
  translation?: string;
  gloss?: string;
  romanization?: string;
  usageNote?: string;
}

// Source: schemas/cue.schema.json
export interface SubtitleCue {
  cueId: string;
  index: number;
  startMs: number;
  endMs: number;
  sourceText: string;
  translatedText: string;
  romanization?: string;
  tokens: LearningToken[];
}
export interface LearningToken {
  index: number;
  text: string;
  normalizedText?: string;
  lemma?: string;
  root?: string;
  partOfSpeech?: string;
  translation?: string;
  gloss?: string;
  romanization?: string;
  usageNote?: string;
}

// Source: schemas/token.schema.json
export interface LearningToken {
  index: number;
  text: string;
  normalizedText?: string;
  lemma?: string;
  root?: string;
  partOfSpeech?: string;
  translation?: string;
  gloss?: string;
  romanization?: string;
  usageNote?: string;
}

// Source: schemas/api-error.schema.json
export interface ApiError {
  error: ErrorObject;
  /**
   * Backend log correlation ID when available.
   */
  requestId?: string;
}
export interface ErrorObject {
  code:
    | 'validation_failed'
    | 'unsupported_video'
    | 'audio_unavailable'
    | 'video_too_long'
    | 'audio_acquisition_failed'
    | 'transcription_failed'
    | 'enrichment_failed'
    | 'rate_limited'
    | 'not_found'
    | 'expired'
    | 'internal_error';
  message: string;
  details?: {
    [k: string]: unknown;
  };
}

// Source: schemas/error-object.schema.json
export interface ErrorObject {
  code:
    | 'validation_failed'
    | 'unsupported_video'
    | 'audio_unavailable'
    | 'video_too_long'
    | 'audio_acquisition_failed'
    | 'transcription_failed'
    | 'enrichment_failed'
    | 'rate_limited'
    | 'not_found'
    | 'expired'
    | 'internal_error';
  message: string;
  details?: {
    [k: string]: unknown;
  };
}
