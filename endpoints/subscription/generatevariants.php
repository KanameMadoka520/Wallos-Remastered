<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/subscription_media.php';

set_time_limit(0);

try {
    $result = wallos_generate_missing_subscription_image_variants($db, $userId, __DIR__ . '/../../', false);

    echo json_encode([
        'success' => true,
        'message' => sprintf(
            translate('subscription_image_generate_variants_success', $i18n),
            (int) $result['generated_count'],
            (int) $result['skipped_count'],
            (int) $result['failed_count']
        ),
        'generatedCount' => (int) $result['generated_count'],
        'skippedCount' => (int) $result['skipped_count'],
        'failedCount' => (int) $result['failed_count'],
    ]);
} catch (Throwable $throwable) {
    echo json_encode([
        'success' => false,
        'message' => translate('error', $i18n),
    ]);
}
