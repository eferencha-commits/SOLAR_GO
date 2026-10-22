
<?php

session_start();

require_once __DIR__ . "/../config/database.php";

// Only administrators can perform these actions.
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
    header("Location: users.php");
    exit;
}

// Verify CSRF token.
if (
    empty($_SESSION["csrf_token"]) ||
    empty($_POST["csrf_token"]) ||
    !hash_equals($_SESSION["csrf_token"], $_POST["csrf_token"])
) {
    http_response_code(403);
    exit("Invalid security token. Return to User Management and try again.");
}

// Validate user ID.
$userId = filter_input(INPUT_POST, "user_id", FILTER_VALIDATE_INT);
$action = $_POST["action"] ?? "";

if (
    !$userId ||
    !in_array($action, ["activate", "suspend"], true)
) {
    header("Location: users.php?error=invalid_request");
    exit;
}

// Prevent an administrator from changing their own account.
if ((int) $userId === (int) $_SESSION["user_id"]) {
    header("Location: users.php?error=protected");
    exit;
}

// Administrators cannot be managed through this page.
$check = $conn->prepare("
    SELECT role, account_status
    FROM users
    WHERE user_id = ?
    LIMIT 1
");

if (!$check) {
    error_log("User lookup prepare error: " . $conn->error);
    header("Location: users.php?error=database");
    exit;
}

$check->bind_param("i", $userId);

if (!$check->execute()) {
    error_log("User lookup error: " . $check->error);
    $check->close();

    header("Location: users.php?error=database");
    exit;
}

$result = $check->get_result();
$user = $result->fetch_assoc();
$check->close();

if (!$user || $user["role"] === "admin") {
    header("Location: users.php?error=protected");
    exit;
}

// Do not activate pending seller applications here.
// They must go through the seller approval workflow.
if (
    $action === "activate" &&
    $user["role"] === "seller" &&
    $user["account_status"] === "pending"
) {
    header("Location: users.php?error=invalid_request");
    exit;
}

$newStatus = ($action === "activate") ? "active" : "suspended";

// Only update eligible non-admin accounts.
$stmt = $conn->prepare("
    UPDATE users
    SET account_status = ?
    WHERE user_id = ?
      AND role IN ('customer', 'seller', 'technician')
      AND account_status <> ?
");

if (!$stmt) {
    error_log("User update prepare error: " . $conn->error);
    header("Location: users.php?error=database");
    exit;
}

$stmt->bind_param("sis", $newStatus, $userId, $newStatus);

if (!$stmt->execute()) {
    error_log("User update error: " . $stmt->error);
    $stmt->close();

    header("Location: users.php?error=database");
    exit;
}

$updated = ($stmt->affected_rows === 1);
$stmt->close();

if (!$updated) {
    header("Location: users.php?error=not_updated");
    exit;
}

$message = ($action === "activate") ? "activated" : "suspended";

header("Location: users.php?message=" . $message);
exit;
