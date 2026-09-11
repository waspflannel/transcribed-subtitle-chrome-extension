<?php

namespace App\Ai\Agents;

use App\Ai\SubtitleModel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;

abstract class SubtitleAgent implements Agent, HasProviderOptions, HasStructuredOutput
{
    use Promptable;

    public function provider(): string
    {
        return SubtitleModel::provider();
    }

    public function model(): string
    {
        return SubtitleModel::model();
    }

    public function providerOptions(Lab|string $provider): array
    {
        $name = $provider instanceof Lab ? $provider->value : $provider;

        return config("ai.providers.{$name}.provider_options", []);
    }

    public function timeout(): int
    {
        return (int) config('subtitles.enrichment.timeout_seconds', 120);
    }

    protected function cardSchema(JsonSchema $schema): array
    {
        return [
            'index' => $schema->integer()->min(0)->required(),
            'lemma' => $schema->string()->min(1)->nullable()->required(),
            'root' => $schema->string()->min(1)->nullable()->required(),
            'partOfSpeech' => $schema->string()->min(1)->nullable()->required(),
            'translation' => $schema->string()->min(1)->nullable()->required(),
            'gloss' => $schema->string()->min(1)->nullable()->required(),
            'usageNote' => $schema->string()->min(1)->nullable()->required(),
        ];
    }
}
