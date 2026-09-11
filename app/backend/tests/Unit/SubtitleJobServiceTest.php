<?php

namespace Tests\Unit;

use App\Services\Subtitles\SubtitleJobService;
use PHPUnit\Framework\TestCase;

class SubtitleJobServiceTest extends TestCase
{
    public function test_current_processing_versions_preserve_all_mode_and_feature_combinations(): void
    {
        $this->assertSame([
            'scribe-v2-analysis-v12-async-on-demand',
            'scribe-v2-analysis-v12-async-on-demand-romanized',
            'scribe-v2-analysis-v12-async-on-demand-translated',
            'scribe-v2-analysis-v12-async-on-demand-romanized-translated',
            'scribe-v2-analysis-v12-async-full',
            'scribe-v2-analysis-v12-async-full-romanized',
            'scribe-v2-analysis-v12-async-full-translated',
            'scribe-v2-analysis-v12-async-full-romanized-translated',
        ], SubtitleJobService::currentProcessingVersions());
    }
}
