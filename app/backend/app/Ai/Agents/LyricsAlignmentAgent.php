<?php

namespace App\Ai\Agents;

use App\Ai\SubtitlePromptRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Attributes\MaxTokens;
use Stringable;

#[MaxTokens(12000)]
class LyricsAlignmentAgent extends SubtitleAgent
{
    public function __construct(private readonly bool $allowPartial = false) {}

    public function instructions(): Stringable|string
    {
        $modeInstructions = $this->allowPartial ? <<<'INSTRUCTIONS'
Partial replacement is enabled. Always supply startPartIndex and separator for
every segment, even when isComplete is true. For a complete replacement use
only pasted segments, set startPartIndex to the next unconsumed global part
(initially 0), and set separator to "". The server derives these values again.

When isComplete is false, return every existing timing slot exactly once in
timing order. Use ordered segments with source "pasted" or "existing". Pasted
segments start at the next unconsumed global lyric part across all cues.
Existing segments use that cue's authoritative existingParts, whose numbering
restarts at 0 in each cue, with increasing non-overlapping boundaries. Preserve
every existing part outside the portion replaced by pasted text. At least one
segment must use each source; both sources may occur in the same cue.
Unspaced non-Latin existingParts use grapheme clusters, including for short cues.

separator is "" or " " and is placed before its segment. Use "" for the first
segment of each cue and consecutive segments from the same source. At source
switches use "" for unspaced text or " " when words need separation.
INSTRUCTIONS : <<<'INSTRUCTIONS'
Only complete replacement is enabled. When isComplete is false, return cues
as an empty array. Each segment contains only source "pasted" and endPartIndex.
Do not return startPartIndex or separator; the server derives them.
INSTRUCTIONS;

        return <<<'INSTRUCTIONS'
Assess pasted lyrics against the existing subtitle timing slots, then align them.

Judge correspondence even when the existing transcription has
mistakes; do not require exact lexical equality.

isMatch must be false only when the pasted lyrics are clearly for an unrelated
song. isComplete must be true only when the pasted lyrics cover the complete
song. A short excerpt from the same song is a match but isComplete false.
Never use isMatch false for incomplete or uncertain alignment.
When isMatch is false, return cues as an empty array.

lyricsParts contains the authoritative pasted text in numbered parts. Use
existing cue text to assess correspondence with timing slots. Return entries in timing-slot
order with the original cueId. The server derives each cue's index from its ID.
Never return an entry with empty segments; omit that slot for a complete
replacement instead.

All part indexes are zero-based and endPartIndex is inclusive. Pasted segments
consume the global lyricsParts in order across all cues, starting at part 0.
For example, with parts 0, 1, 2, 3, consecutive pasted segments ending at 1 and
3 consume parts 0 through 1 and 2 through 3, with no gap or overlap.
Each pasted endPartIndex must be strictly greater than the preceding pasted
endPartIndex. The final pasted segment must end at the last supplied part.
Thus every part, including repetitions and
punctuation, is consumed exactly once.

Do not invent, rewrite, or duplicate either source.

The server reconstructs pasted cues by joining their parts and collapsing
whitespace. Choose natural phrase boundaries within the existing timing slots.
The server splits text longer than 84 Unicode code points into shorter cues
inside the same timing slot; never omit required text just to meet that limit.
Parts normally contain whole words; long unspaced text uses grapheme clusters.
Line breaks are hints, not fixed cue boundaries.

For a complete replacement you may omit unused timing slots, but never
duplicate or reorder them. Do not stretch an excerpt across the whole song to
fill unused timing slots. Recognized headings and credits have already been
removed. All remaining parts are required.
INSTRUCTIONS."\n\n".SubtitlePromptRules::TEXT_IS_DATA."\n\n".$modeInstructions;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'isMatch' => $schema->boolean()->required(),
            'isComplete' => $schema->boolean()->required(),
            'cues' => $schema->array()
                ->items($schema->object([
                    'cueId' => $schema->string()->min(1)->required(),
                    'segments' => $schema->array()
                        ->items($schema->object([
                            'source' => $schema->string()->enum($this->allowPartial ? ['pasted', 'existing'] : ['pasted'])->required(),
                            ...($this->allowPartial ? ['startPartIndex' => $schema->integer()->min(0)->required()] : []),
                            'endPartIndex' => $schema->integer()->min(0)->required(),
                            ...($this->allowPartial ? ['separator' => $schema->string()->enum(['', ' '])->required()] : []),
                        ])->withoutAdditionalProperties())
                        ->required(),
                ])->withoutAdditionalProperties())
                ->required(),
        ];
    }
}
