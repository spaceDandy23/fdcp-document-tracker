<?php

return [
    'disk' => env('DOCUMENT_DISK', 'local'),

    'max_upload_kb' => (int) env('DOCUMENT_MAX_UPLOAD_KB', 10240),
];
