<?php

/*
 * V2 is deliberately dark-launched. Turning any UI or scheduler on is a
 * separate, reviewed release decision; the mirror is always read-only.
 */
return [
    'queue' => env('ZOHO_V2_QUEUE', 'zoho'),
    'queue_connection' => env('ZOHO_V2_QUEUE_CONNECTION', 'zoho'),
    'reporting_timezone' => env('ZOHO_V2_REPORTING_TIMEZONE', 'Europe/Paris'),
    'overlap_minutes' => (int) env('ZOHO_V2_OVERLAP_MINUTES', 15),
    'lease_seconds' => (int) env('ZOHO_V2_LEASE_SECONDS', 14400),
    'module' => [
        'delivery_timeout_seconds' => (int) env('ZOHO_V2_MODULE_DELIVERY_TIMEOUT_SECONDS', 1200),
        'lease_seconds' => (int) env('ZOHO_V2_MODULE_LEASE_SECONDS', 1500),
        'continuation_delay_seconds' => (int) env('ZOHO_V2_MODULE_CONTINUATION_DELAY_SECONDS', 5),
        'hydration_chunk_size' => (int) env('ZOHO_V2_MODULE_HYDRATION_CHUNK_SIZE', 100),
        'capacity_deferral_seconds' => (int) env('ZOHO_V2_MODULE_CAPACITY_DEFERRAL_SECONDS', 60),
        'lease_conflict_max_delay_seconds' => (int) env('ZOHO_V2_MODULE_LEASE_CONFLICT_MAX_DELAY_SECONDS', 3600),
        // Standard outbox recovery may reclaim a lost running delivery quickly,
        // but must never duplicate a framework retry that is still in backoff.
        'recovery_stale_seconds' => (int) env('ZOHO_V2_MODULE_RECOVERY_STALE_SECONDS', 60),
        'retrying_recovery_stale_seconds' => (int) env('ZOHO_V2_MODULE_RETRYING_RECOVERY_STALE_SECONDS', 1860),
    ],
    'reconciliation' => [
        'quote_chunk_size' => (int) env('ZOHO_V2_QUOTE_RECONCILIATION_CHUNK_SIZE', 500),
    ],
    'bulk' => [
        'poll_delay_seconds' => (int) env('ZOHO_V2_BULK_POLL_DELAY_SECONDS', 30),
        'lock_retry_seconds' => (int) env('ZOHO_V2_BULK_LOCK_RETRY_SECONDS', 30),
        'failure_retry_seconds' => (int) env('ZOHO_V2_BULK_FAILURE_RETRY_SECONDS', 300),
        'work_batch_size' => (int) env('ZOHO_V2_BULK_WORK_BATCH_SIZE', 100),
        'staging_batch_size' => (int) env('ZOHO_V2_BULK_STAGING_BATCH_SIZE', 1000),
        'delivery_timeout_seconds' => (int) env('ZOHO_V2_BULK_DELIVERY_TIMEOUT_SECONDS', 900),
        'lease_seconds' => (int) env('ZOHO_V2_BULK_LEASE_SECONDS', 1500),
        'max_retry_after_seconds' => (int) env('ZOHO_V2_BULK_MAX_RETRY_AFTER_SECONDS', 3600),
        'max_inline_retry_after_seconds' => (int) env('ZOHO_V2_BULK_MAX_INLINE_RETRY_AFTER_SECONDS', 30),
        'capacity_deferral_seconds' => (int) env('ZOHO_V2_BULK_CAPACITY_DEFERRAL_SECONDS', 60),
        'run_horizon_hours' => (int) env('ZOHO_V2_BULK_RUN_HORIZON_HOURS', 72),
        'termination_chunk_size' => (int) env('ZOHO_V2_BULK_TERMINATION_CHUNK_SIZE', 500),
        'downloads_per_minute' => (int) env('ZOHO_V2_BULK_DOWNLOADS_PER_MINUTE', 10),
        // Empty until a module-specific Bulk Read export is empirically
        // reconciled against Records API coverage in this organization.
        'verified_modules' => array_values(array_filter(array_map(
            static fn (string $module): string => strtolower(trim($module)),
            explode(',', (string) env('ZOHO_V2_BULK_VERIFIED_MODULES', '')),
        ))),
        'max_archive_bytes' => (int) env('ZOHO_V2_BULK_MAX_ARCHIVE_BYTES', 67108864),
        'max_compressed_entry_bytes' => (int) env('ZOHO_V2_BULK_MAX_COMPRESSED_ENTRY_BYTES', 67108864),
        'max_uncompressed_bytes' => (int) env('ZOHO_V2_BULK_MAX_UNCOMPRESSED_BYTES', 536870912),
        'max_csv_line_bytes' => (int) env('ZOHO_V2_BULK_MAX_CSV_LINE_BYTES', 4096),
        'max_records_per_export' => 200000,
    ],
    'throttle' => [
        'requests_per_minute' => (int) env('ZOHO_V2_REQUESTS_PER_MINUTE', 90),
        'max_concurrent_requests' => (int) env('ZOHO_V2_MAX_CONCURRENT_REQUESTS', 2),
        // Bound queue admission; a failed shared-cache gate never permits an
        // unbounded request burst. Values <= 0 disable the individual bound.
        'acquire_timeout_milliseconds' => (int) env('ZOHO_V2_THROTTLE_ACQUIRE_TIMEOUT_MS', 5000),
        'poll_milliseconds' => (int) env('ZOHO_V2_THROTTLE_POLL_MS', 100),
        // Must exceed the largest individual Zoho HTTP timeout.
        'lock_ttl_seconds' => (int) env('ZOHO_V2_THROTTLE_LOCK_TTL_SECONDS', 120),
    ],
    'retry' => [
        'max_attempts' => (int) env('ZOHO_V2_RETRY_MAX_ATTEMPTS', 5),
        'batch_size' => (int) env('ZOHO_V2_RETRY_BATCH_SIZE', 100),
        'backoff_seconds' => [60, 300, 900, 1800],
        'retry_window_hours' => (int) env('ZOHO_V2_RETRY_WINDOW_HOURS', 12),
    ],
    'freshness' => [
        'hourly_warning_minutes' => (int) env('ZOHO_V2_HOURLY_WARNING_MINUTES', 90),
        'hourly_critical_minutes' => (int) env('ZOHO_V2_HOURLY_CRITICAL_MINUTES', 180),
        'nightly_warning_hours' => (int) env('ZOHO_V2_NIGHTLY_WARNING_HOURS', 30),
        'nightly_critical_hours' => (int) env('ZOHO_V2_NIGHTLY_CRITICAL_HOURS', 48),
    ],
    'marketing' => [
        'cache_seconds' => (int) env('ZOHO_V2_MARKETING_CACHE_SECONDS', 60),
    ],
];
