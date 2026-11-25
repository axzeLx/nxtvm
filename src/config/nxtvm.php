<?php

return [
    // Python interpreter (venv with pyVmomi, baked into the image) and the collector script
    'python' => env('NXTVM_PYTHON', '/opt/venv/bin/python'),
    'collector' => resource_path('collector/nxtvm_collect.py'),

    // Timeouts (seconds)
    'test_timeout' => (int) env('NXTVM_TEST_TIMEOUT', 30),
    'collect_timeout' => (int) env('NXTVM_COLLECT_TIMEOUT', 600),

    // Collection interval limits (minutes) and default for new vCenters
    'interval' => ['default' => 60, 'min' => 15, 'max' => 1440],

    // Audit log retention (days)
    'audit_days' => (int) env('NXTVM_AUDIT_DAYS', 365),

    // Certificate expiry thresholds (days)
    'cert_warn_days' => (int) env('NXTVM_CERT_WARN_DAYS', 60),
    'cert_crit_days' => (int) env('NXTVM_CERT_CRIT_DAYS', 14),

    // vSphere per-core licensing: every physical CPU counts at least this many cores
    // product version (About page); set on release, not per installation
    'version' => '1.0.0',

    // shown on the Checkup activation page: where to send the machine code for a license key (e-mail or text)
    'license_contact' => env('NXTVM_LICENSE_CONTACT'),

    'license_min_cores' => (int) env('NXTVM_LICENSE_MIN_CORES', 16),

    // Colour palettes (each has a light and a dark variant in resources/css/app.css).
    // Value: swatch colour shown in the picker.
    'themes' => [
        'teal' => '#0f8b8d',
        'ocean' => '#1d6fd1',
        'forest' => '#2f7d4a',
        'graphite' => '#3f3f46',
        'amber' => '#b45f06',
        'rose' => '#c2185b',
        'violet' => '#6d4bd8',
        'nord' => '#5e81ac',
    ],
];
