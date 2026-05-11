import type { LearningToken, TrackResponse } from './contracts';

export function tokenKey(cueId: string, tokenIndex: number): string {
  return `${cueId}:${tokenIndex}`;
}

export function trackWithLearningToken(track: TrackResponse, cueId: string, token: LearningToken): TrackResponse {
  return {
    ...track,
    cues: track.cues.map((cue) => {
      if (cue.cueId !== cueId) {
        return cue;
      }

      return {
        ...cue,
        tokens: cue.tokens.map((candidate) => (candidate.index === token.index ? token : candidate)),
      };
    }) as TrackResponse['cues'],
  };
}

export function hasLearningMetadata(token: LearningToken): boolean {
  return [token.lemma, token.root, token.partOfSpeech, token.translation, token.gloss, token.usageNote].some(
    (value) => typeof value === 'string' && value.trim() !== '',
  );
}
