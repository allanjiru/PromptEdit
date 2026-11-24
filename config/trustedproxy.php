<?php

$configured = (string) env('TRUSTED_PROXIES', '');

return [
    'proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', $configured)
    ))),
];
