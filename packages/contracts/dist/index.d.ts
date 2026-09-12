// Source: schemas/account-summary.schema.json
export interface AccountSummary {
  /**
   * Currently configured backend analysis model; not the provenance of saved tracks.
   */
  aiModel?: string;
  status: 'authenticated';
  id: string;
  email: string;
  name: string;
  emailVerified: boolean;
  planName: string;
  tierName: string;
  tierSpeedLabel: string;
  monthlyMinuteLimit: number;
  monthlyMinutesUsed: number;
  monthlyMinutesPending: number;
  monthlyMinutesRemaining: number;
  resetAt: string;
  upgradeAvailable: boolean;
}

// Source: schemas/create-subtitle-job-request.schema.json
export interface CreateSubtitleJobRequest {
  /**
   * Optional names or terms for this generation only. Each term has at most five words and 49 characters.
   *
   * @maxItems 20
   */
  vocabularyHints?: string[];
  /**
   * Canonical 11-character YouTube video ID.
   */
  youtubeVideoId: string;
  /**
   * YouTube watch or Shorts URL captured by the extension for diagnostics and validation.
   */
  youtubeUrl: string;
  /**
   * Known YouTube video duration. Backend must still enforce the 60 minute limit.
   */
  videoDurationSeconds?: number;
  /**
   * Learning/source language requested by the extension. Auto detect is source-only. Language choices are defined by languages.json.
   */
  sourceLanguage:
    | 'auto'
    | 'bel'
    | 'bos'
    | 'bul'
    | 'cat'
    | 'hrv'
    | 'ces'
    | 'dan'
    | 'nld'
    | 'eng'
    | 'est'
    | 'fin'
    | 'fra'
    | 'glg'
    | 'deu'
    | 'ell'
    | 'hun'
    | 'isl'
    | 'ind'
    | 'ita'
    | 'jpn'
    | 'kan'
    | 'lav'
    | 'mkd'
    | 'msa'
    | 'mal'
    | 'nor'
    | 'pol'
    | 'por'
    | 'ron'
    | 'rus'
    | 'slk'
    | 'spa'
    | 'swe'
    | 'tur'
    | 'ukr'
    | 'vie'
    | 'hye'
    | 'aze'
    | 'ben'
    | 'yue'
    | 'fil'
    | 'kat'
    | 'guj'
    | 'hin'
    | 'kaz'
    | 'lit'
    | 'mlt'
    | 'cmn'
    | 'mar'
    | 'nep'
    | 'ori'
    | 'fas'
    | 'srp'
    | 'slv'
    | 'swa'
    | 'tam'
    | 'tel'
    | 'afr'
    | 'ara'
    | 'asm'
    | 'ast'
    | 'mya'
    | 'hau'
    | 'heb'
    | 'jav'
    | 'kor'
    | 'kir'
    | 'ltz'
    | 'mri'
    | 'oci'
    | 'pan'
    | 'tgk'
    | 'tha'
    | 'uzb'
    | 'cym'
    | 'amh'
    | 'lug'
    | 'ibo'
    | 'gle'
    | 'khm'
    | 'kur'
    | 'lao'
    | 'mon'
    | 'nso'
    | 'pus'
    | 'sna'
    | 'snd'
    | 'som'
    | 'urd'
    | 'wol'
    | 'xho'
    | 'yor'
    | 'zul';
  /**
   * Translation/target language requested by the extension. Auto detect is not allowed for target language.
   */
  targetLanguage:
    | 'bel'
    | 'bos'
    | 'bul'
    | 'cat'
    | 'hrv'
    | 'ces'
    | 'dan'
    | 'nld'
    | 'eng'
    | 'est'
    | 'fin'
    | 'fra'
    | 'glg'
    | 'deu'
    | 'ell'
    | 'hun'
    | 'isl'
    | 'ind'
    | 'ita'
    | 'jpn'
    | 'kan'
    | 'lav'
    | 'mkd'
    | 'msa'
    | 'mal'
    | 'nor'
    | 'pol'
    | 'por'
    | 'ron'
    | 'rus'
    | 'slk'
    | 'spa'
    | 'swe'
    | 'tur'
    | 'ukr'
    | 'vie'
    | 'hye'
    | 'aze'
    | 'ben'
    | 'yue'
    | 'fil'
    | 'kat'
    | 'guj'
    | 'hin'
    | 'kaz'
    | 'lit'
    | 'mlt'
    | 'cmn'
    | 'mar'
    | 'nep'
    | 'ori'
    | 'fas'
    | 'srp'
    | 'slv'
    | 'swa'
    | 'tam'
    | 'tel'
    | 'afr'
    | 'ara'
    | 'asm'
    | 'ast'
    | 'mya'
    | 'hau'
    | 'heb'
    | 'jav'
    | 'kor'
    | 'kir'
    | 'ltz'
    | 'mri'
    | 'oci'
    | 'pan'
    | 'tgk'
    | 'tha'
    | 'uzb'
    | 'cym'
    | 'amh'
    | 'lug'
    | 'ibo'
    | 'gle'
    | 'khm'
    | 'kur'
    | 'lao'
    | 'mon'
    | 'nso'
    | 'pus'
    | 'sna'
    | 'snd'
    | 'som'
    | 'urd'
    | 'wol'
    | 'xho'
    | 'yor'
    | 'zul';
  /**
   * on_demand returns transcript-first tracks with tokenizer-agent clickable boundaries when valid. full enriches every cue before returning the track.
   */
  enrichmentMode: 'on_demand' | 'full';
  /**
   * When true, backend may add cue and token romanization for non-Latin source text. When false, generation skips romanization.
   */
  includeRomanization: boolean;
  /**
   * When true, backend translates cue text into the selected target language. When false, translatedText remains the source text.
   */
  includeTranslation: boolean;
  /**
   * AI provider for this generation: openai selects Luna; cerebras selects Cerebras. The backend resolves and pins the exact model.
   */
  aiProvider?: 'openai' | 'cerebras';
}

// Source: schemas/extension-login-request.schema.json
export interface ExtensionLoginRequest {
  email: string;
  password: string;
}

// Source: schemas/extension-auth-response.schema.json
export interface ExtensionAuthResponse {
  account: AccountSummary;
  token: {
    plainTextToken: string;
    tokenType: 'Bearer';
    expiresAt: string;
    /**
     * @minItems 1
     */
    abilities: [
      'extension:account:read' | 'extension:subtitles:write' | 'extension:tokens:revoke',
      ...('extension:account:read' | 'extension:subtitles:write' | 'extension:tokens:revoke')[]
    ];
  };
}

// Source: schemas/extension-account-response.schema.json
export interface ExtensionAccountResponse {
  account: AccountSummary;
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

// Source: schemas/lyrics-correction-request.schema.json
export interface LyricsCorrectionRequest {
  /**
   * Identity of the track the learner intends to replace.
   */
  expectedTrackId: string;
  /**
   * Pasted plain-text lyrics, complete or partial. The backend applies a Unicode character limit after validation.
   */
  lyrics: string;
  /**
   * Allow the backend to continue after suspected incomplete lyrics and ask the AI to merge the supplied lyrics with the generated transcript.
   */
  allowPartial?: boolean;
}

// Source: schemas/lyrics-correction-status.schema.json
export type LyricsCorrectionStatus =
  | {
      attemptId: string;
      status: 'queued';
      stage: 'queued';
      updatedAt: string;
    }
  | {
      attemptId: string;
      status: 'running';
      stage: 'aligning' | 'rebuilding' | 'romanizing' | 'enriching' | 'finalizing';
      updatedAt: string;
    }
  | {
      attemptId: string;
      status: 'completed';
      stage: 'completed';
      updatedAt: string;
      track: TrackResponse;
    }
  | {
      attemptId: string;
      status: 'failed';
      stage: 'failed';
      updatedAt: string;
      errorCode: 'lyrics_incomplete' | 'lyrics_do_not_match' | 'lyrics_correction_failed';
      message: string;
    }
  | {
      attemptId: string;
      status: 'cancelled';
      stage: 'cancelled';
      updatedAt: string;
    };

// Source: schemas/lyrics-correction-cancel-request.schema.json
export interface LyricsCorrectionCancelRequest {
  attemptId: string;
}

// Source: schemas/quick-fix-token-request.schema.json
export interface QuickFixTokenRequest {
  expectedTrackId: string;
  text: string;
}

// Source: schemas/job-response.schema.json
export type JobResponse = {
  [k: string]: unknown;
} & {
  jobId: string;
  youtubeVideoId: string;
  /**
   * Known video duration in seconds when measured or supplied by the extension. Safe for usage and timing displays.
   */
  videoDurationSeconds?: number;
  sourceLanguage:
    | 'auto'
    | 'bel'
    | 'bos'
    | 'bul'
    | 'cat'
    | 'hrv'
    | 'ces'
    | 'dan'
    | 'nld'
    | 'eng'
    | 'est'
    | 'fin'
    | 'fra'
    | 'glg'
    | 'deu'
    | 'ell'
    | 'hun'
    | 'isl'
    | 'ind'
    | 'ita'
    | 'jpn'
    | 'kan'
    | 'lav'
    | 'mkd'
    | 'msa'
    | 'mal'
    | 'nor'
    | 'pol'
    | 'por'
    | 'ron'
    | 'rus'
    | 'slk'
    | 'spa'
    | 'swe'
    | 'tur'
    | 'ukr'
    | 'vie'
    | 'hye'
    | 'aze'
    | 'ben'
    | 'yue'
    | 'fil'
    | 'kat'
    | 'guj'
    | 'hin'
    | 'kaz'
    | 'lit'
    | 'mlt'
    | 'cmn'
    | 'mar'
    | 'nep'
    | 'ori'
    | 'fas'
    | 'srp'
    | 'slv'
    | 'swa'
    | 'tam'
    | 'tel'
    | 'afr'
    | 'ara'
    | 'asm'
    | 'ast'
    | 'mya'
    | 'hau'
    | 'heb'
    | 'jav'
    | 'kor'
    | 'kir'
    | 'ltz'
    | 'mri'
    | 'oci'
    | 'pan'
    | 'tgk'
    | 'tha'
    | 'uzb'
    | 'cym'
    | 'amh'
    | 'lug'
    | 'ibo'
    | 'gle'
    | 'khm'
    | 'kur'
    | 'lao'
    | 'mon'
    | 'nso'
    | 'pus'
    | 'sna'
    | 'snd'
    | 'som'
    | 'urd'
    | 'wol'
    | 'xho'
    | 'yor'
    | 'zul';
  targetLanguage:
    | 'bel'
    | 'bos'
    | 'bul'
    | 'cat'
    | 'hrv'
    | 'ces'
    | 'dan'
    | 'nld'
    | 'eng'
    | 'est'
    | 'fin'
    | 'fra'
    | 'glg'
    | 'deu'
    | 'ell'
    | 'hun'
    | 'isl'
    | 'ind'
    | 'ita'
    | 'jpn'
    | 'kan'
    | 'lav'
    | 'mkd'
    | 'msa'
    | 'mal'
    | 'nor'
    | 'pol'
    | 'por'
    | 'ron'
    | 'rus'
    | 'slk'
    | 'spa'
    | 'swe'
    | 'tur'
    | 'ukr'
    | 'vie'
    | 'hye'
    | 'aze'
    | 'ben'
    | 'yue'
    | 'fil'
    | 'kat'
    | 'guj'
    | 'hin'
    | 'kaz'
    | 'lit'
    | 'mlt'
    | 'cmn'
    | 'mar'
    | 'nep'
    | 'ori'
    | 'fas'
    | 'srp'
    | 'slv'
    | 'swa'
    | 'tam'
    | 'tel'
    | 'afr'
    | 'ara'
    | 'asm'
    | 'ast'
    | 'mya'
    | 'hau'
    | 'heb'
    | 'jav'
    | 'kor'
    | 'kir'
    | 'ltz'
    | 'mri'
    | 'oci'
    | 'pan'
    | 'tgk'
    | 'tha'
    | 'uzb'
    | 'cym'
    | 'amh'
    | 'lug'
    | 'ibo'
    | 'gle'
    | 'khm'
    | 'kur'
    | 'lao'
    | 'mon'
    | 'nso'
    | 'pus'
    | 'sna'
    | 'snd'
    | 'som'
    | 'urd'
    | 'wol'
    | 'xho'
    | 'yor'
    | 'zul';
  /**
   * Requested word-card mode. This is public-safe generation-control telemetry, not billing or provider output.
   */
  enrichmentMode: 'on_demand' | 'full';
  /**
   * Whether the request asked the backend to add romanization where available.
   */
  includeRomanization: boolean;
  /**
   * Whether the request asked the backend to translate cue text.
   */
  includeTranslation: boolean;
  status: 'queued' | 'running' | 'completed' | 'failed' | 'cancelled';
  stage:
    | 'preparing'
    | 'acquiring-audio'
    | 'optimizing-audio'
    | 'transcribing'
    | 'tokenizing'
    | 'romanizing'
    | 'translating'
    | 'enriching'
    | 'finalizing';
  progressPercent: number;
  track?: TrackResponse;
  createdAt: string;
  updatedAt: string;
  expiresAt?: string;
  /**
   * Provider-detected source language when sourceLanguage was auto and detection produced a catalog language.
   */
  detectedSourceLanguage?:
    | 'bel'
    | 'bos'
    | 'bul'
    | 'cat'
    | 'hrv'
    | 'ces'
    | 'dan'
    | 'nld'
    | 'eng'
    | 'est'
    | 'fin'
    | 'fra'
    | 'glg'
    | 'deu'
    | 'ell'
    | 'hun'
    | 'isl'
    | 'ind'
    | 'ita'
    | 'jpn'
    | 'kan'
    | 'lav'
    | 'mkd'
    | 'msa'
    | 'mal'
    | 'nor'
    | 'pol'
    | 'por'
    | 'ron'
    | 'rus'
    | 'slk'
    | 'spa'
    | 'swe'
    | 'tur'
    | 'ukr'
    | 'vie'
    | 'hye'
    | 'aze'
    | 'ben'
    | 'yue'
    | 'fil'
    | 'kat'
    | 'guj'
    | 'hin'
    | 'kaz'
    | 'lit'
    | 'mlt'
    | 'cmn'
    | 'mar'
    | 'nep'
    | 'ori'
    | 'fas'
    | 'srp'
    | 'slv'
    | 'swa'
    | 'tam'
    | 'tel'
    | 'afr'
    | 'ara'
    | 'asm'
    | 'ast'
    | 'mya'
    | 'hau'
    | 'heb'
    | 'jav'
    | 'kor'
    | 'kir'
    | 'ltz'
    | 'mri'
    | 'oci'
    | 'pan'
    | 'tgk'
    | 'tha'
    | 'uzb'
    | 'cym'
    | 'amh'
    | 'lug'
    | 'ibo'
    | 'gle'
    | 'khm'
    | 'kur'
    | 'lao'
    | 'mon'
    | 'nso'
    | 'pus'
    | 'sna'
    | 'snd'
    | 'som'
    | 'urd'
    | 'wol'
    | 'xho'
    | 'yor'
    | 'zul';
  message?: string;
  errorCode?:
    | 'validation_failed'
    | 'invalid_credentials'
    | 'unauthenticated'
    | 'unauthorized'
    | 'email_not_verified'
    | 'insecure_transport'
    | 'payment_required'
    | 'usage_exhausted'
    | 'feature_unavailable'
    | 'queue_full'
    | 'unsupported_video'
    | 'audio_unavailable'
    | 'video_too_long'
    | 'audio_acquisition_failed'
    | 'queue_publication_failed'
    | 'generation_cancelled'
    | 'generation_not_cancellable'
    | 'transcription_failed'
    | 'enrichment_failed'
    | 'rate_limited'
    | 'not_found'
    | 'expired'
    | 'lyrics_correction_in_progress'
    | 'lyrics_incomplete'
    | 'lyrics_do_not_match'
    | 'lyrics_correction_failed'
    | 'internal_error';
  /**
   * AI provider for this generation: openai selects Luna; cerebras selects Cerebras. The backend resolves and pins the exact model.
   */
  aiProvider: 'openai' | 'cerebras';
  /**
   * Exact text model saved on this job.
   */
  aiModel: string;
  partialTrack?: PartialTrackResponse;
};

// Source: schemas/subtitle-job-history-response.schema.json
export type SubtitleJobHistoryItem = {
  [k: string]: unknown;
} & {
  youtubeVideoId: string;
  /**
   * Known video duration in seconds when measured or supplied by the extension. Safe for usage and timing displays.
   */
  videoDurationSeconds?: number;
  youtubeUrl: string;
  status: 'queued' | 'running' | 'completed' | 'failed' | 'cancelled';
  startedAt: string;
  lastUpdatedAt: string;
  completedAt?: string;
  stage:
    | 'preparing'
    | 'acquiring-audio'
    | 'optimizing-audio'
    | 'transcribing'
    | 'tokenizing'
    | 'romanizing'
    | 'translating'
    | 'enriching'
    | 'finalizing';
  progressPercent: number;
  sourceLanguage:
    | 'auto'
    | 'bel'
    | 'bos'
    | 'bul'
    | 'cat'
    | 'hrv'
    | 'ces'
    | 'dan'
    | 'nld'
    | 'eng'
    | 'est'
    | 'fin'
    | 'fra'
    | 'glg'
    | 'deu'
    | 'ell'
    | 'hun'
    | 'isl'
    | 'ind'
    | 'ita'
    | 'jpn'
    | 'kan'
    | 'lav'
    | 'mkd'
    | 'msa'
    | 'mal'
    | 'nor'
    | 'pol'
    | 'por'
    | 'ron'
    | 'rus'
    | 'slk'
    | 'spa'
    | 'swe'
    | 'tur'
    | 'ukr'
    | 'vie'
    | 'hye'
    | 'aze'
    | 'ben'
    | 'yue'
    | 'fil'
    | 'kat'
    | 'guj'
    | 'hin'
    | 'kaz'
    | 'lit'
    | 'mlt'
    | 'cmn'
    | 'mar'
    | 'nep'
    | 'ori'
    | 'fas'
    | 'srp'
    | 'slv'
    | 'swa'
    | 'tam'
    | 'tel'
    | 'afr'
    | 'ara'
    | 'asm'
    | 'ast'
    | 'mya'
    | 'hau'
    | 'heb'
    | 'jav'
    | 'kor'
    | 'kir'
    | 'ltz'
    | 'mri'
    | 'oci'
    | 'pan'
    | 'tgk'
    | 'tha'
    | 'uzb'
    | 'cym'
    | 'amh'
    | 'lug'
    | 'ibo'
    | 'gle'
    | 'khm'
    | 'kur'
    | 'lao'
    | 'mon'
    | 'nso'
    | 'pus'
    | 'sna'
    | 'snd'
    | 'som'
    | 'urd'
    | 'wol'
    | 'xho'
    | 'yor'
    | 'zul';
  /**
   * Requested word-card mode. This is public-safe generation-control telemetry, not billing or provider output.
   */
  enrichmentMode: 'on_demand' | 'full';
  /**
   * Whether the request asked the backend to add romanization where available.
   */
  includeRomanization: boolean;
  /**
   * Whether the request asked the backend to translate cue text.
   */
  includeTranslation: boolean;
  jobId: string;
  trackId?: string;
  expiresAt?: string;
  message?: string;
  errorCode?:
    | 'validation_failed'
    | 'invalid_credentials'
    | 'unauthenticated'
    | 'unauthorized'
    | 'email_not_verified'
    | 'insecure_transport'
    | 'payment_required'
    | 'usage_exhausted'
    | 'feature_unavailable'
    | 'queue_full'
    | 'unsupported_video'
    | 'audio_unavailable'
    | 'video_too_long'
    | 'audio_acquisition_failed'
    | 'queue_publication_failed'
    | 'generation_cancelled'
    | 'generation_not_cancellable'
    | 'transcription_failed'
    | 'enrichment_failed'
    | 'rate_limited'
    | 'not_found'
    | 'expired'
    | 'lyrics_correction_in_progress'
    | 'lyrics_incomplete'
    | 'lyrics_do_not_match'
    | 'lyrics_correction_failed'
    | 'internal_error';
  targetLanguage:
    | 'bel'
    | 'bos'
    | 'bul'
    | 'cat'
    | 'hrv'
    | 'ces'
    | 'dan'
    | 'nld'
    | 'eng'
    | 'est'
    | 'fin'
    | 'fra'
    | 'glg'
    | 'deu'
    | 'ell'
    | 'hun'
    | 'isl'
    | 'ind'
    | 'ita'
    | 'jpn'
    | 'kan'
    | 'lav'
    | 'mkd'
    | 'msa'
    | 'mal'
    | 'nor'
    | 'pol'
    | 'por'
    | 'ron'
    | 'rus'
    | 'slk'
    | 'spa'
    | 'swe'
    | 'tur'
    | 'ukr'
    | 'vie'
    | 'hye'
    | 'aze'
    | 'ben'
    | 'yue'
    | 'fil'
    | 'kat'
    | 'guj'
    | 'hin'
    | 'kaz'
    | 'lit'
    | 'mlt'
    | 'cmn'
    | 'mar'
    | 'nep'
    | 'ori'
    | 'fas'
    | 'srp'
    | 'slv'
    | 'swa'
    | 'tam'
    | 'tel'
    | 'afr'
    | 'ara'
    | 'asm'
    | 'ast'
    | 'mya'
    | 'hau'
    | 'heb'
    | 'jav'
    | 'kor'
    | 'kir'
    | 'ltz'
    | 'mri'
    | 'oci'
    | 'pan'
    | 'tgk'
    | 'tha'
    | 'uzb'
    | 'cym'
    | 'amh'
    | 'lug'
    | 'ibo'
    | 'gle'
    | 'khm'
    | 'kur'
    | 'lao'
    | 'mon'
    | 'nso'
    | 'pus'
    | 'sna'
    | 'snd'
    | 'som'
    | 'urd'
    | 'wol'
    | 'xho'
    | 'yor'
    | 'zul';
  /**
   * Provider-detected source language when sourceLanguage was auto and detection produced a catalog language.
   */
  detectedSourceLanguage?:
    | 'bel'
    | 'bos'
    | 'bul'
    | 'cat'
    | 'hrv'
    | 'ces'
    | 'dan'
    | 'nld'
    | 'eng'
    | 'est'
    | 'fin'
    | 'fra'
    | 'glg'
    | 'deu'
    | 'ell'
    | 'hun'
    | 'isl'
    | 'ind'
    | 'ita'
    | 'jpn'
    | 'kan'
    | 'lav'
    | 'mkd'
    | 'msa'
    | 'mal'
    | 'nor'
    | 'pol'
    | 'por'
    | 'ron'
    | 'rus'
    | 'slk'
    | 'spa'
    | 'swe'
    | 'tur'
    | 'ukr'
    | 'vie'
    | 'hye'
    | 'aze'
    | 'ben'
    | 'yue'
    | 'fil'
    | 'kat'
    | 'guj'
    | 'hin'
    | 'kaz'
    | 'lit'
    | 'mlt'
    | 'cmn'
    | 'mar'
    | 'nep'
    | 'ori'
    | 'fas'
    | 'srp'
    | 'slv'
    | 'swa'
    | 'tam'
    | 'tel'
    | 'afr'
    | 'ara'
    | 'asm'
    | 'ast'
    | 'mya'
    | 'hau'
    | 'heb'
    | 'jav'
    | 'kor'
    | 'kir'
    | 'ltz'
    | 'mri'
    | 'oci'
    | 'pan'
    | 'tgk'
    | 'tha'
    | 'uzb'
    | 'cym'
    | 'amh'
    | 'lug'
    | 'ibo'
    | 'gle'
    | 'khm'
    | 'kur'
    | 'lao'
    | 'mon'
    | 'nso'
    | 'pus'
    | 'sna'
    | 'snd'
    | 'som'
    | 'urd'
    | 'wol'
    | 'xho'
    | 'yor'
    | 'zul';
  /**
   * AI provider for this generation: openai selects Luna; cerebras selects Cerebras. The backend resolves and pins the exact model.
   */
  aiProvider: 'openai' | 'cerebras';
  /**
   * Exact text model saved on this job.
   */
  aiModel: string;
};

export interface SubtitleJobHistoryResponse {
  jobs: SubtitleJobHistoryItem[];
}

// Source: schemas/track-response.schema.json
export interface TrackResponse {
  trackId: string;
  jobId: string;
  youtubeVideoId: string;
  sourceLanguage:
    | 'auto'
    | 'bel'
    | 'bos'
    | 'bul'
    | 'cat'
    | 'hrv'
    | 'ces'
    | 'dan'
    | 'nld'
    | 'eng'
    | 'est'
    | 'fin'
    | 'fra'
    | 'glg'
    | 'deu'
    | 'ell'
    | 'hun'
    | 'isl'
    | 'ind'
    | 'ita'
    | 'jpn'
    | 'kan'
    | 'lav'
    | 'mkd'
    | 'msa'
    | 'mal'
    | 'nor'
    | 'pol'
    | 'por'
    | 'ron'
    | 'rus'
    | 'slk'
    | 'spa'
    | 'swe'
    | 'tur'
    | 'ukr'
    | 'vie'
    | 'hye'
    | 'aze'
    | 'ben'
    | 'yue'
    | 'fil'
    | 'kat'
    | 'guj'
    | 'hin'
    | 'kaz'
    | 'lit'
    | 'mlt'
    | 'cmn'
    | 'mar'
    | 'nep'
    | 'ori'
    | 'fas'
    | 'srp'
    | 'slv'
    | 'swa'
    | 'tam'
    | 'tel'
    | 'afr'
    | 'ara'
    | 'asm'
    | 'ast'
    | 'mya'
    | 'hau'
    | 'heb'
    | 'jav'
    | 'kor'
    | 'kir'
    | 'ltz'
    | 'mri'
    | 'oci'
    | 'pan'
    | 'tgk'
    | 'tha'
    | 'uzb'
    | 'cym'
    | 'amh'
    | 'lug'
    | 'ibo'
    | 'gle'
    | 'khm'
    | 'kur'
    | 'lao'
    | 'mon'
    | 'nso'
    | 'pus'
    | 'sna'
    | 'snd'
    | 'som'
    | 'urd'
    | 'wol'
    | 'xho'
    | 'yor'
    | 'zul';
  targetLanguage:
    | 'bel'
    | 'bos'
    | 'bul'
    | 'cat'
    | 'hrv'
    | 'ces'
    | 'dan'
    | 'nld'
    | 'eng'
    | 'est'
    | 'fin'
    | 'fra'
    | 'glg'
    | 'deu'
    | 'ell'
    | 'hun'
    | 'isl'
    | 'ind'
    | 'ita'
    | 'jpn'
    | 'kan'
    | 'lav'
    | 'mkd'
    | 'msa'
    | 'mal'
    | 'nor'
    | 'pol'
    | 'por'
    | 'ron'
    | 'rus'
    | 'slk'
    | 'spa'
    | 'swe'
    | 'tur'
    | 'ukr'
    | 'vie'
    | 'hye'
    | 'aze'
    | 'ben'
    | 'yue'
    | 'fil'
    | 'kat'
    | 'guj'
    | 'hin'
    | 'kaz'
    | 'lit'
    | 'mlt'
    | 'cmn'
    | 'mar'
    | 'nep'
    | 'ori'
    | 'fas'
    | 'srp'
    | 'slv'
    | 'swa'
    | 'tam'
    | 'tel'
    | 'afr'
    | 'ara'
    | 'asm'
    | 'ast'
    | 'mya'
    | 'hau'
    | 'heb'
    | 'jav'
    | 'kor'
    | 'kir'
    | 'ltz'
    | 'mri'
    | 'oci'
    | 'pan'
    | 'tgk'
    | 'tha'
    | 'uzb'
    | 'cym'
    | 'amh'
    | 'lug'
    | 'ibo'
    | 'gle'
    | 'khm'
    | 'kur'
    | 'lao'
    | 'mon'
    | 'nso'
    | 'pus'
    | 'sna'
    | 'snd'
    | 'som'
    | 'urd'
    | 'wol'
    | 'xho'
    | 'yor'
    | 'zul';
  generatedAt: string;
  expiresAt: string;
  webVtt: string;
  /**
   * @minItems 1
   */
  cues: [SubtitleCue, ...SubtitleCue[]];
  /**
   * Provider-detected source language when sourceLanguage was auto and detection produced a catalog language.
   */
  detectedSourceLanguage?:
    | 'bel'
    | 'bos'
    | 'bul'
    | 'cat'
    | 'hrv'
    | 'ces'
    | 'dan'
    | 'nld'
    | 'eng'
    | 'est'
    | 'fin'
    | 'fra'
    | 'glg'
    | 'deu'
    | 'ell'
    | 'hun'
    | 'isl'
    | 'ind'
    | 'ita'
    | 'jpn'
    | 'kan'
    | 'lav'
    | 'mkd'
    | 'msa'
    | 'mal'
    | 'nor'
    | 'pol'
    | 'por'
    | 'ron'
    | 'rus'
    | 'slk'
    | 'spa'
    | 'swe'
    | 'tur'
    | 'ukr'
    | 'vie'
    | 'hye'
    | 'aze'
    | 'ben'
    | 'yue'
    | 'fil'
    | 'kat'
    | 'guj'
    | 'hin'
    | 'kaz'
    | 'lit'
    | 'mlt'
    | 'cmn'
    | 'mar'
    | 'nep'
    | 'ori'
    | 'fas'
    | 'srp'
    | 'slv'
    | 'swa'
    | 'tam'
    | 'tel'
    | 'afr'
    | 'ara'
    | 'asm'
    | 'ast'
    | 'mya'
    | 'hau'
    | 'heb'
    | 'jav'
    | 'kor'
    | 'kir'
    | 'ltz'
    | 'mri'
    | 'oci'
    | 'pan'
    | 'tgk'
    | 'tha'
    | 'uzb'
    | 'cym'
    | 'amh'
    | 'lug'
    | 'ibo'
    | 'gle'
    | 'khm'
    | 'kur'
    | 'lao'
    | 'mon'
    | 'nso'
    | 'pus'
    | 'sna'
    | 'snd'
    | 'som'
    | 'urd'
    | 'wol'
    | 'xho'
    | 'yor'
    | 'zul';
}

// Source: schemas/partial-track-response.schema.json
/**
 * Cues available for a still-running subtitle job. Stable source cues append as contiguous transcription chunks arrive; translations and romanization fill in per batch as the pipeline progresses. Tokens are never included: word cards need the finalized track.
 */
export interface PartialTrackResponse {
  jobId: string;
  youtubeVideoId: string;
  /**
   * Revision of the source prefix plus completed annotation batches. Monotonically increasing for a given run; re-render when it changes.
   */
  revision: number;
  /**
   * @minItems 1
   */
  cues: [PartialSubtitleCue, ...PartialSubtitleCue[]];
  /**
   * End of the contiguous prefix with completed analysis, in video milliseconds. Later completed batches do not advance this value across an unfinished batch.
   */
  readyThroughMs?: number;
}
export interface PartialSubtitleCue {
  cueId: string;
  index: number;
  startMs: number;
  endMs: number;
  sourceText: string;
  translatedText?: string;
  romanization?: string;
}

// Source: schemas/cue.schema.json
export interface SubtitleCue {
  cueId: string;
  index: number;
  startMs: number;
  endMs: number;
  sourceText: string;
  /**
   * Cue translation. Empty when requested translation is unavailable; source text is retained for translation-disabled or same-language generation.
   */
  translatedText: string;
  romanization?: string;
  /**
   * @minItems 1
   */
  tokens: [LearningToken, ...LearningToken[]];
}

// Source: schemas/token.schema.json
export interface LearningToken {
  index: number;
  text: string;
  normalizedText: string;
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

// Source: schemas/error-object.schema.json
export interface ErrorObject {
  code:
    | 'validation_failed'
    | 'invalid_credentials'
    | 'unauthenticated'
    | 'unauthorized'
    | 'email_not_verified'
    | 'insecure_transport'
    | 'payment_required'
    | 'usage_exhausted'
    | 'feature_unavailable'
    | 'queue_full'
    | 'unsupported_video'
    | 'audio_unavailable'
    | 'video_too_long'
    | 'audio_acquisition_failed'
    | 'queue_publication_failed'
    | 'generation_cancelled'
    | 'generation_not_cancellable'
    | 'transcription_failed'
    | 'enrichment_failed'
    | 'rate_limited'
    | 'not_found'
    | 'expired'
    | 'lyrics_correction_in_progress'
    | 'lyrics_incomplete'
    | 'lyrics_do_not_match'
    | 'lyrics_correction_failed'
    | 'internal_error';
  message: string;
  details?: {
    reason?: 'stale_track';
    [k: string]: unknown;
  };
}
