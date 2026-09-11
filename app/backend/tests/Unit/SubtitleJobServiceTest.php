<?php

namespace Tests\Unit;

use App\Services\Subtitles\SubtitleJobService;
use PHPUnit\Framework\TestCase;

class SubtitleJobServiceTest extends TestCase
{
    public function test_current_processing_versions_preserve_all_mode_and_feature_combinations(): void
    {
        $this->assertSame([
            'scribe-v2-analysis-v14-progressive-on-demand',
            'scribe-v2-analysis-v14-progressive-on-demand-romanized',
            'scribe-v2-analysis-v14-progressive-on-demand-translated',
            'scribe-v2-analysis-v14-progressive-on-demand-romanized-translated',
            'scribe-v2-analysis-v14-progressive-full',
            'scribe-v2-analysis-v14-progressive-full-romanized',
            'scribe-v2-analysis-v14-progressive-full-translated',
            'scribe-v2-analysis-v14-progressive-full-romanized-translated',
        ], SubtitleJobService::currentProcessingVersions());
    }
}
