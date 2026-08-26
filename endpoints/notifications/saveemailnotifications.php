<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint.php';
require_once '../../includes/ssrf_helper.php';

$postData = file_get_contents("php://input");
$data = json_decode($postData, true);

if (
    !isset($data["smtpaddress"]) || $data["smtpaddress"] == "" ||
    !isset($data["smtpport"]) || $data["smtpport"] == ""
) {
    $response = [
        "success" => false,
        "message" => translate('fill_mandatory_fields', $i18n)
    ];
    echo json_encode($response);
} else {
    $enabled = $data["enabled"];
    $smtpAddress = $data["smtpaddress"];
    $smtpPort = $data["smtpport"];
    $encryption = "tls";
    if (isset($data["encryption"])) {
        $encryption = $data["encryption"];
    }
    $smtpUsername = $data["smtpusername"];
    $smtpPassword = $data["smtppassword"];
    $fromEmail = $data["fromemail"];
    $otherEmails = $data["otheremails"];

    $smtpPortInt = filter_var($smtpPort, FILTER_VALIDATE_INT);
    if ($smtpPortInt === false || $smtpPortInt < 1 || $smtpPortInt > 65535) {
        die(json_encode([
            "success" => false,
            "message" => translate('fill_mandatory_fields', $i18n)
        ]));
    }

    $existingStmt = $db->prepare('SELECT smtp_address, smtp_port FROM email_notifications WHERE user_id = :userId LIMIT 1');
    $existingStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $existingResult = $existingStmt->execute();
    $existingSettings = $existingResult ? $existingResult->fetchArray(SQLITE3_ASSOC) : false;
    $isDisablingUnchangedTarget = (int) $enabled === 0
        && $existingSettings !== false
        && trim((string) ($existingSettings['smtp_address'] ?? '')) === trim((string) $smtpAddress)
        && (int) ($existingSettings['smtp_port'] ?? 0) === $smtpPortInt;

    if (!$isDisablingUnchangedTarget && !validate_smtp_host($smtpAddress, $smtpPortInt, $db)) {
        die(json_encode([
            "success" => false,
            "message" => "Security Error: SMTP host must not target link-local or loopback addresses."
        ]));
    }

    $query = "SELECT COUNT(*) FROM email_notifications WHERE user_id = :userId";
    $stmt = $db->prepare($query);
    $stmt->bindParam(":userId", $userId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($result === false) {
        $response = [
            "success" => false,
            "message" => translate('error_saving_notifications', $i18n)
        ];
        echo json_encode($response);
    } else {
        $row = $result->fetchArray();
        $count = $row[0];
        if ($count == 0) {
            $query = "INSERT INTO email_notifications (enabled, smtp_address, smtp_port, smtp_username, smtp_password, from_email, other_emails, encryption, user_id)
                              VALUES (:enabled, :smtpAddress, :smtpPort, :smtpUsername, :smtpPassword, :fromEmail, :otherEmails, :encryption, :userId)";
        } else {
            $query = "UPDATE email_notifications
                              SET enabled = :enabled, smtp_address = :smtpAddress, smtp_port = :smtpPort,
                                  smtp_username = :smtpUsername, smtp_password = :smtpPassword, from_email = :fromEmail, other_emails = :otherEmails, encryption = :encryption WHERE user_id = :userId";
        }

        $stmt = $db->prepare($query);
        $stmt->bindValue(':enabled', $enabled, SQLITE3_INTEGER);
        $stmt->bindValue(':smtpAddress', $smtpAddress, SQLITE3_TEXT);
        $stmt->bindValue(':smtpPort', $smtpPortInt, SQLITE3_INTEGER);
        $stmt->bindValue(':smtpUsername', $smtpUsername, SQLITE3_TEXT);
        $stmt->bindValue(':smtpPassword', $smtpPassword, SQLITE3_TEXT);
        $stmt->bindValue(':fromEmail', $fromEmail, SQLITE3_TEXT);
        $stmt->bindValue(':otherEmails', $otherEmails, SQLITE3_TEXT);
        $stmt->bindValue(':encryption', $encryption, SQLITE3_TEXT);
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);

        if ($stmt->execute()) {
            $response = [
                "success" => true,
                "message" => translate('notifications_settings_saved', $i18n)
            ];
            echo json_encode($response);
        } else {
            $response = [
                "success" => false,
                "message" => translate('error_saving_notifications', $i18n)
            ];
            echo json_encode($response);
        }
    }
}
