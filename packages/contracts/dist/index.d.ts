// Source: schemas/codex-account.schema.json
export interface CodexAccount {
  available: boolean;
  connected: boolean;
  models: {
    id: string;
    name: string;
    supportsFastMode: boolean;
  }[];
  login: null | {
    status: 'pending' | 'awaiting_authorization' | 'failed';
    authUrl?: string;
  };
  error?: string;
}

// Source: schemas/instance-settings.schema.json
export interface InstanceSettings {
  providers: {
    openai: {
      configured: boolean;
      model: string;
    };
    cerebras: {
      configured: boolean;
      model: string;
    };
    elevenlabs: {
      configured: boolean;
      model: string;
    };
    claude: {
      configured: boolean;
      model: string;
      available: boolean;
    };
  };
  retentionDays: number | null;
}

// Source: schemas/update-instance-settings.schema.json
export interface UpdateInstanceSettings {
  providers?: {
    openai?: {
      apiKey?: string | null;
    };
    cerebras?: {
      apiKey?: string | null;
    };
    elevenlabs?: {
      apiKey?: string | null;
    };
    claude?: {
      apiKey?: string | null;
    };
  };
  retentionDays?: number | null;
}

// Source: schemas/create-subtitle-job-request.schema.json
export type CreateSubtitleJobRequest = {
  [k: string]: unknown;
} & {
  /**
   * Canonical 11-character YouTube video ID.
   */
  youtubeVideoId: string;
  /**
   * YouTube watch or Shorts URL captured by the extension for diagnostics and validation.
   */
  youtubeUrl: string;
  /**
   * Video duration in seconds.
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
   * When true, backend may add cue and token romanization for non-Latin source text. When false, generation skips romanization.
   */
  includeRomanization: boolean;
  /**
   * When true, backend translates cue text into the selected target language. When false, translatedText remains the source text.
   */
  includeTranslation: boolean;
  /**
   * When true, rebuild a compatible completed track from the original transcription with fresh tokens, romanization, and translation. Original transcription may be cached; active jobs are still reused. Regeneration uses normal plan minutes.
   */
  forceRegenerate?: boolean;
  /**
   * The selected analysis provider stays pinned for this generation.
   */
  aiProvider?: 'openai' | 'cerebras' | 'codex' | 'claude';
  /**
   * Model from the connected Codex account. Required for Codex; omitted for other providers.
   */
  aiModel?: string;
  /**
   * Use Codex fast service tier. Omitted for API providers.
   */
  aiFastMode?: boolean;
};

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
export type LyricsCorrectionRequest = {
  [k: string]: unknown;
} & {
  /**
   * Identity of the track the learner intends to replace.
   */
  expectedTrackId: string;
  /**
   * Pasted plain-text lyrics, at most 25,000 raw Unicode code points. The backend requires a Unicode letter, rejects C0/C1 controls except tab/LF/CR, and rejects HTTP(S)/www link-only input before whitespace normalization.
   */
  lyrics: string;
  /**
   * Optional provider override for this correction only. Omit all AI fields to inherit the generation’s saved provider, model, and fast mode.
   */
  aiProvider?: 'openai' | 'cerebras' | 'codex' | 'claude';
  /**
   * Model from the connected Codex account. Required for Codex; omitted for other providers.
   */
  aiModel?: string;
  /**
   * Use Codex fast service tier. Omitted for API providers.
   */
  aiFastMode?: boolean;
};

// Source: schemas/lyrics-correction-status.schema.json
/**
 * AI selection is pinned per correction. Older backend responses can omit all three AI fields.
 */
export type LyricsCorrectionStatus =
  | {
      aiProvider?: 'openai' | 'cerebras' | 'codex' | 'claude';
      aiModel?: string;
      aiFastMode?: boolean;
      attemptId: string;
      status: 'queued';
      stage: 'queued';
      updatedAt: string;
    }
  | {
      aiProvider?: 'openai' | 'cerebras' | 'codex' | 'claude';
      aiModel?: string;
      aiFastMode?: boolean;
      attemptId: string;
      status: 'running';
      stage: 'aligning' | 'rebuilding' | 'romanizing' | 'finalizing';
      updatedAt: string;
    }
  | {
      aiProvider?: 'openai' | 'cerebras' | 'codex' | 'claude';
      aiModel?: string;
      aiFastMode?: boolean;
      attemptId: string;
      status: 'completed';
      stage: 'completed';
      updatedAt: string;
      track: TrackResponse;
    }
  | {
      aiProvider?: 'openai' | 'cerebras' | 'codex' | 'claude';
      aiModel?: string;
      aiFastMode?: boolean;
      attemptId: string;
      status: 'failed';
      stage: 'failed';
      updatedAt: string;
      errorCode: 'lyrics_incomplete' | 'lyrics_do_not_match' | 'lyrics_correction_failed';
      message: string;
    }
  | {
      aiProvider?: 'openai' | 'cerebras' | 'codex' | 'claude';
      aiModel?: string;
      aiFastMode?: boolean;
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
   * Video duration in seconds.
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
    | 'finalizing';
  progressPercent: number;
  track?: TrackResponse;
  createdAt: string;
  updatedAt: string;
  expiresAt?: string | null;
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
    | 'unauthorized'
    | 'unsupported_video'
    | 'audio_unavailable'
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
    | 'internal_error'
    | 'provider_not_configured'
    | 'provider_unavailable';
  /**
   * The selected analysis provider stays pinned for this generation.
   */
  aiProvider: 'openai' | 'cerebras' | 'codex' | 'claude';
  /**
   * Exact text model saved on this job.
   */
  aiModel: string;
  partialTrack?: PartialTrackResponse;
  /**
   * Pinned Codex fast mode. Absent on older jobs means false.
   */
  aiFastMode?: boolean;
};

// Source: schemas/subtitle-job-history-response.schema.json
export type SubtitleJobHistoryItem = {
  [k: string]: unknown;
} & {
  youtubeVideoId: string;
  /**
   * Video duration in seconds.
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
   * Whether the request asked the backend to add romanization where available.
   */
  includeRomanization: boolean;
  /**
   * Whether the request asked the backend to translate cue text.
   */
  includeTranslation: boolean;
  jobId: string;
  trackId?: string;
  expiresAt?: string | null;
  message?: string;
  errorCode?:
    | 'validation_failed'
    | 'unauthorized'
    | 'unsupported_video'
    | 'audio_unavailable'
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
    | 'internal_error'
    | 'provider_not_configured'
    | 'provider_unavailable';
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
   * The selected analysis provider stays pinned for this generation.
   */
  aiProvider: 'openai' | 'cerebras' | 'codex' | 'claude';
  /**
   * Exact text model saved on this job.
   */
  aiModel: string;
  /**
   * Pinned Codex fast mode. Absent on older jobs means false.
   */
  aiFastMode?: boolean;
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
  expiresAt: string | null;
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
    | 'unauthorized'
    | 'unsupported_video'
    | 'audio_unavailable'
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
    | 'internal_error'
    | 'provider_not_configured'
    | 'provider_unavailable';
  message: string;
  details?: {
    reason?: 'stale_track';
    [k: string]: unknown;
  };
}
