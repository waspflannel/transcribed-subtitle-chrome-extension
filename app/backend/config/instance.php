<?php

return [
    // A personal instance has no account boundary. Remote access must be on a trusted network.
    'allowed_networks' => array_filter(array_map('trim', explode(',', env('INSTANCE_ALLOWED_NETWORKS', '127.0.0.1/32,::1/128')))),
];
