<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TokenizationFixturesTest extends TestCase
{
    private const FIXTURE_DIR = 'tests/Fixtures/tokenization';

    /**
     * @return array<int, array{0: string}>
     */
    public static function languages(): array
    {
        return [
            ['jpn'],
            ['cmn'],
            ['tha'],
        ];
    }

    #[DataProvider('languages')]
    public function test_gold_fixtures_have_the_required_shape(string $lang): void
    {
        $path = base_path(self::FIXTURE_DIR.'/'.$lang.'.json');

        $this->assertFileExists($path, "Fixture file for [{$lang}] is missing.");

        $entries = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($entries, "Fixture file for [{$lang}] must be a JSON array.");
        $this->assertGreaterThanOrEqual(8, count($entries), "Fixture set for [{$lang}] should contain a usable starter set of cues.");

        foreach (array_values($entries) as $entry) {
            $this->assertIsArray($entry);
            $this->assertArrayHasKey('id', $entry);
            $this->assertIsString($entry['id']);
            $this->assertNotSame('', $entry['id'], 'Fixture ids must be non-empty.');

            $this->assertArrayHasKey('sourceText', $entry);
            $this->assertIsString($entry['sourceText']);
            $this->assertNotSame('', $entry['sourceText'], 'Fixture sourceText must be non-empty.');

            $this->assertArrayHasKey('goldTokens', $entry);
            $this->assertIsArray($entry['goldTokens']);
            $this->assertNotEmpty($entry['goldTokens'], 'Fixture goldTokens must contain at least one token.');

            foreach ($entry['goldTokens'] as $token) {
                $this->assertIsString($token);
                $this->assertNotSame('', $token, 'Gold tokens must be non-empty strings.');
            }

            $concatenated = implode('', $entry['goldTokens']);
            $isCorrupted = ($entry['tag'] ?? null) === 'transcription-corrupted';

            if ($isCorrupted) {
                $this->assertNotSame(
                    $entry['sourceText'],
                    $concatenated,
                    "Transcription-corrupted fixture [{$entry['id']}] must reference characters absent from sourceText so the harness can attribute the fault.",
                );
            } else {
                $this->assertSame(
                    $entry['sourceText'],
                    $concatenated,
                    "Fixture [{$entry['id']}] goldTokens must concatenate exactly to sourceText so gold spans cover the source.",
                );
            }
        }
    }
}
