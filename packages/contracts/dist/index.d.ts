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
   * Source language requested by the extension. Arabic is the first polished path.
   */
  sourceLanguage: 'auto' | 'ar';
  /**
   * English is the first release translation target.
   */
  targetLanguage: 'en';
  options: {
    includeRomanization: boolean;
    includeGloss: boolean;
  };
}

// Source: schemas/job-response.schema.json
export interface JobResponse {
  jobId: string;
  status: 'completed';
  youtubeVideoId: string;
  sourceLanguage: 'auto' | 'ar';
  targetLanguage: 'en';
  track: TrackResponse;
  createdAt: string;
  updatedAt: string;
  expiresAt?: string;
}
export interface TrackResponse {
  trackId: string;
  jobId: string;
  youtubeVideoId: string;
  sourceLanguage: 'auto' | 'ar';
  targetLanguage: 'en';
  detectedDialect?: {
    label: string;
    confidence: number;
  };
  generatedAt: string;
  expiresAt: string;
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
  partOfSpeech?: string;
  translation?: string;
  gloss?: string;
  romanization?: string;
}

// Source: schemas/track-response.schema.json
export interface TrackResponse {
  trackId: string;
  jobId: string;
  youtubeVideoId: string;
  sourceLanguage: 'auto' | 'ar';
  targetLanguage: 'en';
  detectedDialect?: {
    label: string;
    confidence: number;
  };
  generatedAt: string;
  expiresAt: string;
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
  partOfSpeech?: string;
  translation?: string;
  gloss?: string;
  romanization?: string;
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
  partOfSpeech?: string;
  translation?: string;
  gloss?: string;
  romanization?: string;
}

// Source: schemas/token.schema.json
export interface LearningToken {
  index: number;
  text: string;
  normalizedText?: string;
  lemma?: string;
  partOfSpeech?: string;
  translation?: string;
  gloss?: string;
  romanization?: string;
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
