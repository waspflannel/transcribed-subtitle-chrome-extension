<?php

return [
    'locales' => json_decode(file_get_contents(base_path('../../packages/localization/locales.json')), true, flags: JSON_THROW_ON_ERROR),
    'prefixes' => [
        'en' => '',
        'es' => 'es',
        'pt-BR' => 'pt-br',
        'fr' => 'fr',
        'de' => 'de',
        'ja' => 'ja',
        'ko' => 'ko',
        'id' => 'id',
        'zh-CN' => 'zh-hans',
    ],
];
