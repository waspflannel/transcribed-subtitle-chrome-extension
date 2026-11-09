<?php

namespace App\Ai\Agents;

use App\Ai\SubtitleModel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\ObjectSchema;
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

    /** Set per request from the job's selection; only the Claude API reads it. */
    public bool $fastMode = false;

    public function providerOptions(Lab|string $provider): array
    {
        $name = $provider instanceof Lab ? $provider->value : $provider;
        $options = config("ai.providers.{$name}.provider_options", []);
        if ($name !== 'anthropic') {
            return $options;
        }

        // Replaces the SDK's output_config (options merge over the body): Anthropic structured
        // outputs reject length and range limits, and the app validates every response itself.
        return [
            ...$options,
            'output_config' => [
                'format' => ['type' => 'json_schema', 'schema' => self::withoutLimits((new ObjectSchema($this->schema(new JsonSchemaTypeFactory)))->toSchema())],
                // Subtitle batches need little reasoning, same as the Claude Code path.
                'effort' => 'low',
            ],
            ...($this->fastMode ? ['speed' => 'fast'] : []),
        ];
    }

    private static function withoutLimits(array $schema): array
    {
        $limits = ['minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'minLength', 'maxLength', 'minItems', 'maxItems', 'uniqueItems'];
        foreach ($schema as $key => $value) {
            if (in_array($key, $limits, true)) {
                unset($schema[$key]);
            } elseif ($key === 'properties' && is_array($value)) {
                $schema[$key] = array_map(fn (mixed $property): mixed => is_array($property) ? self::withoutLimits($property) : $property, $value);
            } elseif (is_array($value)) {
                $schema[$key] = self::withoutLimits($value);
            }
        }

        return $schema;
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
