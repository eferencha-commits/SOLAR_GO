
<?php

session_start();

require_once __DIR__ . "/../config/database.php";

// Prevent PHP from displaying internal errors to visitors.
mysqli_report(MYSQLI_REPORT_OFF);

// Only logged-in administrators may continue.
if (
    empty($_SESSION["user_id"]) ||
    empty($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../auth/login.php");
    exit;
}

// Accept POST requests only.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: dashboard.php");
    exit;
}

// Verify that the submitted CSRF token matches the session token.
if (
    empty($_SESSION["csrf_token"]) ||
    empty($_POST["csrf_token"]) ||
    !is_string($_POST["csrf_token"]) ||
    !hash_equals(
        $_SESSION["csrf_token"],
        $_POST["csrf_token"]
    )
) {
    http_response_code(403);
    exit("Invalid security token. Return to the dashboard and try again.");
}

// Verify the administrator's current account in the database.
$userId = filter_var(
    $_SESSION["user_id"],
    FILTER_VALIDATE_INT
);

if (!$userId || $userId < 1) {
    session_unset();
    session_destroy();

    header("Location: ../auth/login.php");
    exit;
}

$adminStmt = $conn->prepare(
    "SELECT role, account_status
     FROM users
     WHERE user_id = ?
     LIMIT 1"
);

if (!$adminStmt) {
    error_log("Admin verification error: " . $conn->error);
    http_response_code(500);
    exit("Unable to verify your account. Please try again later.");
}

$adminStmt->bind_param("i", $userId);

if (!$adminStmt->execute()) {
    error_log("Admin verification execution error: " . $adminStmt->error);
    $adminStmt->close();

    http_response_code(500);
    exit("Unable to verify your account. Please try again later.");
}

$result = $adminStmt->get_result();
$admin = $result->fetch_assoc();
$adminStmt->close();

if (
    !$admin ||
    $admin["role"] !== "admin" ||
    $admin["account_status"] !== "active"
) {
    session_unset();
    session_destroy();

    header("Location: ../auth/login.php?error=account_not_active");
    exit;
}

// Validate the product ID.
$productId = filter_input(
    INPUT_POST,
    "product_id",
    FILTER_VALIDATE_INT
);

// Validate the requested action.
$action = $_POST["action"] ?? "";

if (
    !$productId ||
    $productId < 1 ||
    !is_string($action) ||
    !in_array($action, ["approve", "reject"], true)
) {
    header("Location: dashboard.php?error=invalid_product_request");
    exit;
}

// Determine the new product status.
$newStatus = ($action === "approve")
    ? "approved"
    : "rejected";

// Update only products that are still awaiting approval.
$stmt = $conn->prepare(
    "UPDATE products
     SET listing_status = ?
     WHERE product_id = ?
       AND listing_status = 'pending'"
);

if (!$stmt) {
    error_log("Product processing preparation error: " . $conn->error);

    header("Location: dashboard.php?error=product_database");
    exit;
}

$stmt->bind_param("si", $newStatus, $productId);

if (!$stmt->execute()) {
    error_log("Product processing execution error: " . $stmt->error);
    $stmt->close();

    header("Location: dashboard.php?error=product_database");
    exit;
}

$updated = ($stmt->affected_rows === 1);

$stmt->close();

// If no row changed, the product may not exist or may already
// have been approved or rejected.
if (!$updated) {
    header("Location: dashboard.php?error=product_not_pending");
    exit;
}

// Redirect with a success message.
$message = ($action === "approve")
    ? "product_approved"
    : "product_rejected";

header("Location: dashboard.php?message=" . $message);
exit;
