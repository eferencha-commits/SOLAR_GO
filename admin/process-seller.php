```php
<?php
session_start();

require_once __DIR__ . "/../config/database.php";

// Only administrators can process seller applications.
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../auth/login.php");
    exit;
}

// Only accept POST requests.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: dashboard.php");
    exit;
}

// Validate CSRF token.
if (
    empty($_SESSION["csrf_token"]) ||
    empty($_POST["csrf_token"]) ||
    !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
) {
    http_response_code(403);
    exit("Invalid security token. Return to the dashboard and try again.");
}

// Validate seller ID and requested action.
$sellerId = filter_input(INPUT_POST, "seller_id", FILTER_VALIDATE_INT);
$action = $_POST["action"] ?? "";

if (
    !$sellerId ||
    !in_array($action, ["approve", "reject"], true)
) {
    header("Location: dashboard.php?error=invalid_request");
    exit;
}

// Determine the new account status.
$newStatus = ($action === "approve") ? "active" : "suspended";

// Update only pending seller accounts.
$stmt = $conn->prepare(
    "UPDATE users
     SET account_status = ?
     WHERE user_id = ?
       AND role = 'seller'
       AND account_status = 'pending'"
);

if (!$stmt) {
    error_log("Seller processing error: " . $conn->error);
    header("Location: dashboard.php?error=database");
    exit;
}

$stmt->bind_param("si", $newStatus, $sellerId);

if (!$stmt->execute()) {
    error_log("Seller update error: " . $stmt->error);
    $stmt->close();

    header("Location: dashboard.php?error=database");
    exit;
}

$updated = ($stmt->affected_rows === 1);
$stmt->close();

if (!$updated) {
    header("Location: dashboard.php?error=not_pending");
    exit;
}

$message = ($action === "approve")
    ? "seller_approved"
    : "seller_rejected";

header("Location: dashboard.php?message=" . $message);
exit;

