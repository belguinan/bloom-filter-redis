<?php

return [
    'key' => env('BLOOM_KEY', 'emails:bloom'),
    'capacity' => (int) env('BLOOM_CAPACITY', 1000000),
    'error_rate' => (float) env('BLOOM_ERROR_RATE', 0.01),
];
