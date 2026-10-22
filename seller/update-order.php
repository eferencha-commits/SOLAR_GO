
<?php
session_start();

require_once __DIR__ . "/../config/database.php";

// Allow only logged-in sellers
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "seller"
) {
    header("Location: ../auth/login.php");
    exit;
}

$seller_id = (int) $_SESSION["user_id"];

// Confirm that the seller account is active
$accountQuery = $conn->prepare(
    "SELECT user_id FROM users
     WHERE user_id = ? AND role = 'seller'
     AND account_status = 'active'"
);

if (!$accountQuery) {
    die("Database error: " . htmlspecialchars($conn->error));
}

$accountQuery->bind_param("i", $seller_id);
$accountQuery->execute();

$accountResult = $accountQuery->get_result();

if ($accountResult->num_rows !== 1) {
    session_destroy();
    header("Location: ../auth/login.php");
    exit;
}

$accountQuery->close();

// Accept POST requests only
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: orders.php");
    exit;
}

// Validate CSRF token
$submittedToken = $_POST["csrf_token"] ?? "";
$sessionToken = $_SESSION["csrf_token"] ?? "";

if (
    empty($sessionToken) ||
    !is_string($submittedToken) ||
    !hash_equals($sessionToken, $submittedToken)
) {
    $_SESSION["order_message"] = [
        "type" => "error",
        "text" => "Invalid security token. Please refresh the orders page and try again."
    ];

    header("Location: orders.php");
    exit;
}

// Get submitted order information
$order_id = filter_input(INPUT_POST, "order_id", FILTER_VALIDATE_INT);
$action = $_POST["action"] ?? "";

// Allowed seller actions
$allowedActions = [
    "confirm",
    "prepare",
    "ship",
    "deliver",
    "cancel"
];

if (
    !$order_id ||
    !is_string($action) ||
    !in_array($action, $allowedActions, true)
) {
    $_SESSION["order_message"] = [
        "type" => "error",
        "text" => "Invalid order information."
    ];

    header("Location: orders.php");
    exit;
}

// Start a transaction
$conn->begin_transaction();

try {
    // Lock and retrieve the seller's own order
    $orderQuery = $conn->prepare(
        "SELECT order_id, order_status, delivery_status
         FROM orders
         WHERE order_id = ? AND seller_id = ?
         FOR UPDATE"
    );

    if (!$orderQuery) {
        throw new Exception("Could not prepare order query.");
    }

    $orderQuery->bind_param("ii", $order_id, $seller_id);
    $orderQuery->execute();

    $orderResult = $orderQuery->get_result();
    $order = $orderResult->fetch_assoc();

    $orderQuery->close();

    if (!$order) {
        throw new Exception(
            "Order not found or you do not have permission to manage it."
        );
    }

    $currentOrderStatus = $order["order_status"];
    $currentDeliveryStatus = $order["delivery_status"];

    $newOrderStatus = $currentOrderStatus;
    $newDeliveryStatus = $currentDeliveryStatus;
    $message = "";

    // Confirm an order
    if ($action === "confirm") {
        if (
            $currentOrderStatus !== "pending" ||
            $currentDeliveryStatus === "cancelled"
        ) {
            throw new Exception(
                "Only pending orders can be confirmed."
            );
        }

        $newOrderStatus = "confirmed";
        $message = "Order confirmed successfully.";
    }

    // Mark an order as ready for delivery
    elseif ($action === "prepare") {
        if (
            $currentOrderStatus !== "confirmed" ||
            $currentDeliveryStatus !== "not_ready"
        ) {
            throw new Exception(
                "Confirm the order first before preparing it."
            );
        }

        $newDeliveryStatus = "ready";
        $message = "Order marked as ready for delivery.";
    }

    // Mark an order as shipped
    elseif ($action === "ship") {
        if (
            $currentOrderStatus !== "confirmed" ||
            $currentDeliveryStatus !== "ready"
        ) {
            throw new Exception(
                "The order must be confirmed and ready before shipping."
            );
        }

        $newDeliveryStatus = "shipped";
        $message = "Order marked as shipped.";
    }

    // Mark an order as delivered
    elseif ($action === "deliver") {
        if (
            $currentOrderStatus !== "confirmed" ||
            $currentDeliveryStatus !== "shipped"
        ) {
            throw new Exception(
                "Only shipped orders can be marked as delivered."
            );
        }

        $newDeliveryStatus = "delivered";
        $newOrderStatus = "completed";
        $message = "Order marked as delivered and completed.";
    }

    // Cancel an order before shipping
    elseif ($action === "cancel") {
        if (
            !in_array($currentOrderStatus, ["pending", "confirmed"], true) ||
            !in_array($currentDeliveryStatus, ["not_ready", "ready"], true)
        ) {
            throw new Exception(
                "This order can no longer be cancelled through this page."
            );
        }

        $newOrderStatus = "cancelled";
        $newDeliveryStatus = "cancelled";
        $message = "Order cancelled successfully.";
    }

    // Save the new statuses
    $updateQuery = $conn->prepare(
        "UPDATE orders
         SET order_status = ?, delivery_status = ?
         WHERE order_id = ? AND seller_id = ?"
    );

    if (!$updateQuery) {
        throw new Exception("Could not prepare the update query.");
    }

    $updateQuery->bind_param(
        "ssii",
        $newOrderStatus,
        $newDeliveryStatus,
        $order_id,
        $seller_id
    );

    if (!$updateQuery->execute()) {
        throw new Exception("Could not update the order.");
    }

    $updateQuery->close();

    $conn->commit();

    $_SESSION["order_message"] = [
        "type" => "success",
        "text" => $message
    ];

} catch (Throwable $e) {
    $conn->rollback();

    $_SESSION["order_message"] = [
        "type" => "error",
        "text" => $e->getMessage()
    ];
}

// Return to the seller's orders page
header("Location: orders.php");
exit;
