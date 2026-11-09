<?php

return [
    'disk' => env('DOCUMENT_DISK', 'local'),

    'max_upload_kb' => (int) env('DOCUMENT_MAX_UPLOAD_KB', 10240),

    'attachment_max_upload_kb' => (int) env('DOCUMENT_ATTACHMENT_MAX_UPLOAD_KB', env('DOCUMENT_MAX_UPLOAD_KB', 10240)),

    'attachment_types' => ['pdf', 'docx'],
];
