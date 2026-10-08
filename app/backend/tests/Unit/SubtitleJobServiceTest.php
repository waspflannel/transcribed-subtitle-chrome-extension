<?php

namespace Tests\Unit;

use App\Services\Subtitles\SubtitleJobService;
use PHPUnit\Framework\TestCase;

class SubtitleJobServiceTest extends TestCase
{
    public function test_processing_versions_fit_the_job_and_track_database_columns(): void
    {
        foreach (SubtitleJobService::currentProcessingVersions() as $version) {
            $this->assertLessThanOrEqual(64, strlen($version), $version);
        }
    }

    public function test_current_processing_versions_preserve_all_feature_combinations(): void
    {
        $this->assertSame([
            'scribe-v2-analysis-v19-on-demand',
            'scribe-v2-analysis-v19-on-demand-romanized',
            'scribe-v2-analysis-v19-on-demand-translated',
            'scribe-v2-analysis-v19-on-demand-romanized-translated',
        ], SubtitleJobService::currentProcessingVersions());
    }
}
