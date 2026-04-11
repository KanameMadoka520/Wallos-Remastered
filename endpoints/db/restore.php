<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/backup_manager.php';

set_time_limit(0);

if (isset($_FILES['file'])) {
    $file = $_FILES['file'];
    $fileTmpName = $file['tmp_name'];
    $fileError = $file['error'];

    if ($fileError === 0) {
        $restoreTempDirectory = __DIR__ . '/../../.tmp';
        if (!is_dir($restoreTempDirectory)) {
            mkdir($restoreTempDirectory, 0755, true);
        }

        $fileDestination = $restoreTempDirectory . '/restore-' . bin2hex(random_bytes(6)) . '.zip';

        if (!move_uploaded_file($fileTmpName, $fileDestination)) {
            echo json_encode([
                "success" => false,
                "message" => translate('restore_failed', $i18n)
            ]);
            exit;
        }

        try {
            $verification = wallos_verify_backup_archive($fileDestination);
            if (!$verification['is_valid']) {
                echo json_encode([
                    "success" => false,
                    "message" => translate('backup_verification_failed', $i18n)
                ]);
                exit;
            }

            $db->close();
            wallos_restore_backup_archive($fileDestination, __DIR__ . '/../../');

            echo json_encode([
                "success" => true,
                "message" => translate("success", $i18n)
            ]);
        } catch (Throwable $throwable) {
            echo json_encode([
                "success" => false,
                "message" => translate('restore_failed', $i18n)
            ]);
        } finally {
            if (file_exists($fileDestination)) {
                @unlink($fileDestination);
            }
        }
    } else {
        echo json_encode([
            "success" => false,
            "message" => "Failed to upload file"
        ]);
    }
} else {
    echo json_encode([
        "success" => false,
        "message" => "No file uploaded"
    ]);
}
