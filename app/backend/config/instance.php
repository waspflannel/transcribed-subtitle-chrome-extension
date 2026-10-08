<?php

return [
    // A personal instance has no account boundary. Remote access must be on a trusted network.
    'allowed_networks' => array_filter(array_map('trim', explode(',', env('INSTANCE_ALLOWED_NETWORKS', '127.0.0.1/32,::1/128')))),
    // Empty allows any Chrome extension origin; set the extension's ID to admit only it.
    'allowed_extension_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('INSTANCE_ALLOWED_EXTENSION_IDS', ''))))),
];
