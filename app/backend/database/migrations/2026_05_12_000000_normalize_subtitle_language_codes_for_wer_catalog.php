<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * @var array<string, string>
     */
    private const UP_MAP = [
        'ar' => 'ara',
        'de' => 'deu',
        'en' => 'eng',
        'es' => 'spa',
        'fr' => 'fra',
        'it' => 'ita',
        'ja' => 'jpn',
        'pt' => 'por',
        'sw' => 'swa',
        'zh' => 'cmn',
    ];

    /**
     * @var array<string, string>
     */
    private const DOWN_MAP = [
        'ara' => 'ar',
        'cmn' => 'zh',
        'deu' => 'de',
        'eng' => 'en',
        'fra' => 'fr',
        'ita' => 'it',
        'jpn' => 'ja',
        'por' => 'pt',
        'spa' => 'es',
        'swa' => 'sw',
    ];

    public function up(): void
    {
        $this->normalize(self::UP_MAP);
    }

    public function down(): void
    {
        $this->normalize(self::DOWN_MAP);
    }

    /**
     * @param  array<string, string>  $map
     */
    private function normalize(array $map): void
    {
        foreach (['subtitle_jobs', 'subtitle_tracks'] as $table) {
            foreach (['source_language', 'detected_source_language', 'target_language'] as $column) {
                foreach ($map as $from => $to) {
                    DB::table($table)
                        ->where($column, $from)
                        ->update([$column => $to]);
                }
            }
        }
    }
};
