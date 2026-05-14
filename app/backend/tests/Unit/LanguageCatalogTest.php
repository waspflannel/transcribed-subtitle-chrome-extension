<?php

namespace Tests\Unit;

use App\Services\Languages\LanguageCatalog;
use Tests\TestCase;

class LanguageCatalogTest extends TestCase
{
    public function test_it_lists_source_and_target_language_codes(): void
    {
        $sourceLanguageCodes = LanguageCatalog::sourceLanguageCodes();
        $targetLanguageCodes = LanguageCatalog::targetLanguageCodes();

        $this->assertContains('auto', $sourceLanguageCodes);
        $this->assertContains('eng', $sourceLanguageCodes);
        $this->assertContains('jpn', $sourceLanguageCodes);

        $this->assertNotContains('auto', $targetLanguageCodes);
        $this->assertContains('eng', $targetLanguageCodes);
        $this->assertContains('jpn', $targetLanguageCodes);
    }

    public function test_it_normalizes_known_language_codes_and_aliases(): void
    {
        $this->assertSame('eng', LanguageCatalog::normalizeCode('eng'));
        $this->assertSame('eng', LanguageCatalog::normalizeCode('en'));
        $this->assertSame('eng', LanguageCatalog::normalizeCode('en-US'));
        $this->assertSame('jpn', LanguageCatalog::normalizeCode('ja'));
        $this->assertSame('cmn', LanguageCatalog::normalizeCode('zh_CN'));
        $this->assertSame('ces', LanguageCatalog::normalizeCode('cze'));
    }

    public function test_it_rejects_empty_unknown_and_source_only_codes(): void
    {
        $this->assertNull(LanguageCatalog::normalizeCode(null));
        $this->assertNull(LanguageCatalog::normalizeCode(''));
        $this->assertNull(LanguageCatalog::normalizeCode('unknown'));
        $this->assertNull(LanguageCatalog::normalizeCode('auto'));
    }

    public function test_it_returns_language_labels(): void
    {
        $this->assertSame('English', LanguageCatalog::label('eng'));
        $this->assertSame('Japanese', LanguageCatalog::label('jpn'));
        $this->assertSame('unknown', LanguageCatalog::label('unknown'));
    }
}
