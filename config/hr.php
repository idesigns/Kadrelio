<?php

return [
    'mail_enabled' => (bool) env('HR_MAIL_ENABLED', false),
    'mail_mode' => env('HR_MAIL_MODE', 'test'),
    'mail_test_address' => env('HR_MAIL_TEST_ADDRESS', 'testy@kadrelio.pl'),
    'pdf_binary' => env('HR_PDF_BINARY', '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'),
    'docx_binary' => env('HR_DOCX_BINARY', PHP_OS_FAMILY === 'Darwin' ? '/Applications/LibreOffice.app/Contents/MacOS/soffice' : '/usr/bin/libreoffice'),
    'pdf_merge_binary' => env('HR_PDF_MERGE_BINARY', PHP_OS_FAMILY === 'Darwin' ? '/opt/homebrew/bin/pdfunite' : '/usr/bin/pdfunite'),
    'link_days' => 30,
    'demo_enabled' => (bool) env('HR_DEMO_ENABLED', false),
    'registration_enabled' => (bool) env('HR_REGISTRATION_ENABLED', false),
];
