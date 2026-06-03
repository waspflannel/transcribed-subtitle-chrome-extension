// Source: schemas/account-summary.schema.json
export interface AccountSummary {
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
export interface AccountSummary {
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

// Source: schemas/extension-account-response.schema.json
export interface ExtensionAccountResponse {
  account: AccountSummary;
}
export interface AccountSummary {
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
  normalizedText: string;
  lemma?: string;
  root?: string;
  partOfSpeech?: string;
  translation?: string;
  gloss?: string;
  romanization?: string;
  usageNote?: string;
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
  status: 'running' | 'completed' | 'failed';
  stage:
    | 'preparing'
    | 'acquiring-audio'
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
    | 'concurrency_exceeded'
    | 'unsupported_video'
    | 'audio_unavailable'
    | 'video_too_long'
    | 'audio_acquisition_failed'
    | 'transcription_failed'
    | 'enrichment_failed'
    | 'rate_limited'
    | 'queue_unavailable'
    | 'not_found'
    | 'expired'
    | 'internal_error';
};

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
export interface SubtitleCue {
  cueId: string;
  index: number;
  startMs: number;
  endMs: number;
  sourceText: string;
  translatedText: string;
  romanization?: string;
  /**
   * @minItems 1
   */
  tokens: [LearningToken, ...LearningToken[]];
}
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
  status: 'running' | 'completed' | 'failed';
  startedAt: string;
  lastUpdatedAt: string;
  completedAt?: string;
  stage:
    | 'preparing'
    | 'acquiring-audio'
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
    | 'concurrency_exceeded'
    | 'unsupported_video'
    | 'audio_unavailable'
    | 'video_too_long'
    | 'audio_acquisition_failed'
    | 'transcription_failed'
    | 'enrichment_failed'
    | 'rate_limited'
    | 'queue_unavailable'
    | 'not_found'
    | 'expired'
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
};

export interface SubtitleJobHistoryResponse {
  /**
   * @maxItems 25
   */
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
export interface SubtitleCue {
  cueId: string;
  index: number;
  startMs: number;
  endMs: number;
  sourceText: string;
  translatedText: string;
  romanization?: string;
  /**
   * @minItems 1
   */
  tokens: [LearningToken, ...LearningToken[]];
}
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

// Source: schemas/cue.schema.json
export interface SubtitleCue {
  cueId: string;
  index: number;
  startMs: number;
  endMs: number;
  sourceText: string;
  translatedText: string;
  romanization?: string;
  /**
   * @minItems 1
   */
  tokens: [LearningToken, ...LearningToken[]];
}
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
    | 'concurrency_exceeded'
    | 'unsupported_video'
    | 'audio_unavailable'
    | 'video_too_long'
    | 'audio_acquisition_failed'
    | 'transcription_failed'
    | 'enrichment_failed'
    | 'rate_limited'
    | 'queue_unavailable'
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
    | 'invalid_credentials'
    | 'unauthenticated'
    | 'unauthorized'
    | 'email_not_verified'
    | 'insecure_transport'
    | 'payment_required'
    | 'usage_exhausted'
    | 'feature_unavailable'
    | 'concurrency_exceeded'
    | 'unsupported_video'
    | 'audio_unavailable'
    | 'video_too_long'
    | 'audio_acquisition_failed'
    | 'transcription_failed'
    | 'enrichment_failed'
    | 'rate_limited'
    | 'queue_unavailable'
    | 'not_found'
    | 'expired'
    | 'internal_error';
  message: string;
  details?: {
    [k: string]: unknown;
  };
}
