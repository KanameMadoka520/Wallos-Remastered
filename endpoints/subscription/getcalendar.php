<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/getdbkeys.php';
require_once '../../includes/markdown.php';
require_once '../../includes/subscription_trash.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    die(json_encode([
        "success" => false,
        "message" => translate('session_expired', $i18n)
    ]));
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $postData = file_get_contents("php://input");
    $data = json_decode($postData, true);

    $id = $data['id'];

    $stmt = $db->prepare('SELECT * FROM subscriptions WHERE id = :id AND user_id = :userId AND lifecycle_status = :lifecycle_status');
    $stmt->bindParam(':id', $id, SQLITE3_INTEGER);
    $stmt->bindParam(':userId', $_SESSION['userId'], SQLITE3_INTEGER); // Assuming $_SESSION['userId'] holds the logged-in user's ID
    $stmt->bindValue(':lifecycle_status', WALLOS_SUBSCRIPTION_STATUS_ACTIVE, SQLITE3_TEXT);
    $result = $stmt->execute();

    if ($result === false) {
        die(json_encode([
            'success' => false,
            'message' => "Subscription not found"
        ]));
    }

    $subscription = $result->fetchArray(SQLITE3_ASSOC); // Fetch the subscription details as an associative array

    if ($subscription) {
        // get payer name from household object
        $subscription['payer_user'] = $members[$subscription['payer_user_id']]['name'];
        $subscription['category'] = $categories[$subscription['category_id']]['name'];
        $subscription['payment_method'] = $payment_methods[$subscription['payment_method_id']]['name'];
        $subscription['currency'] = $currencies[$subscription['currency_id']]['symbol'];
        $subscription['price'] = number_format($subscription['price'], 2);
        $subscription['notes_html'] = wallos_render_markdown($subscription['notes'] ?? '');

        echo json_encode([
            'success' => true,
            'data' => $subscription
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => "Subscription not found"
        ]);
    }
}
?>
