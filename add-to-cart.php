
<?php

session_start();

require_once __DIR__ . "/config/database.php";

// Only customers can add products to their cart.
if (
    empty($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "customer"
) {
    header("Location: auth/login.php?error=login_required");
    exit;
}

// Accept POST requests only.
if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: products.php");
    exit;
}

// Validate CSRF token.
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
    exit("Invalid security token. Please return and try again.");
}

// Validate product ID.
$productId = filter_input(
    INPUT_POST,
    "product_id",
    FILTER_VALIDATE_INT
);

$quantity = filter_input(
    INPUT_POST,
    "quantity",
    FILTER_VALIDATE_INT
);

if (
    !$productId ||
    $productId < 1 ||
    !$quantity ||
    $quantity < 1
) {
    header("Location: products.php?error=invalid_product");
    exit;
}

// Confirm that the product is approved and available.
$stmt = $conn->prepare(
    "SELECT product_id, product_name, price, stock_quantity
     FROM products
     WHERE product_id = ?
       AND listing_status = 'approved'
       AND stock_quantity > 0
     LIMIT 1"
);

if (!$stmt) {
    error_log("Add-to-cart query error: " . $conn->error);
    header("Location: products.php?error=database");
    exit;
}

$stmt->bind_param("i", $productId);

if (!$stmt->execute()) {
    error_log("Add-to-cart execution error: " . $stmt->error);
    $stmt->close();

    header("Location: products.php?error=database");
    exit;
}

$result = $stmt->get_result();
$product = $result->fetch_assoc();
$stmt->close();

if (!$product) {
    header("Location: products.php?error=product_unavailable");
    exit;
}

// Check that the requested quantity is in stock.
$currentQuantity = (int) (
    $_SESSION["cart"][$productId]["quantity"] ?? 0
);

$newQuantity = $currentQuantity + $quantity;

if ($newQuantity > (int) $product["stock_quantity"]) {
    header("Location: product-details.php?id=" . $productId . "&error=insufficient_stock");
    exit;
}

// Create the cart if it doesn't exist.
if (!isset($_SESSION["cart"]) || !is_array($_SESSION["cart"])) {
    $_SESSION["cart"] = [];
}

// Store only the product ID and quantity.
// Prices and stock will be retrieved from the database when needed.
$_SESSION["cart"][$productId] = [
    "quantity" => $newQuantity
];

// Return the customer to the product page.
header("Location: product-details.php?id=" . $productId . "&message=added_to_cart");
exit;
