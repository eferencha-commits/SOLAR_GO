
<?php
session_start();

require_once __DIR__ . "/config/database.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Only logged-in customers can checkout.
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "customer"
) {
    header("Location: auth/login.php");
    exit;
}

$customerId = (int) $_SESSION["user_id"];

$errors = [];
$deliveryAddress = "";
$cartProducts = [];
$grandTotal = 0;

// CSRF protection.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function checkoutEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

// Confirm that the customer account is active.
$stmt = $conn->prepare(
    "SELECT user_id, account_status
     FROM users
     WHERE user_id = ?
       AND role = 'customer'
     LIMIT 1"
);

$stmt->bind_param("i", $customerId);
$stmt->execute();

$customer = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$customer || $customer["account_status"] !== "active") {
    session_destroy();
    header("Location: auth/login.php");
    exit;
}

// Read the cart from the session.
$cart = $_SESSION["cart"] ?? [];

if (!is_array($cart) || empty($cart)) {
    $cart = [];
    $errors[] = "Your shopping cart is empty.";
}

// Load products from the database.
if (!empty($cart)) {
    try {
        $stmt = $conn->prepare(
            "SELECT
                product_id,
                seller_id,
                product_name,
                price,
                stock_quantity,
                image_url
             FROM products
             WHERE product_id = ?
               AND listing_status = 'approved'
             LIMIT 1"
        );

        foreach ($cart as $productId => $cartItem) {
            $productId = (int) $productId;

            if ($productId <= 0) {
                $errors[] = "Your cart contains an invalid product.";
                continue;
            }

            $quantity = 0;

            if (is_array($cartItem) && isset($cartItem["quantity"])) {
                $quantity = filter_var(
                    $cartItem["quantity"],
                    FILTER_VALIDATE_INT
                );
            } else {
                $quantity = filter_var(
                    $cartItem,
                    FILTER_VALIDATE_INT
                );
            }

            if ($quantity === false || $quantity < 1) {
                $errors[] = "A product in your cart has an invalid quantity.";
                continue;
            }

            $stmt->bind_param("i", $productId);
            $stmt->execute();

            $product = $stmt->get_result()->fetch_assoc();

            if (!$product) {
                $errors[] = "A product in your cart is unavailable.";
                continue;
            }

            if ((int) $product["stock_quantity"] < $quantity) {
                $errors[] =
                    "Insufficient stock for " .
                    $product["product_name"] .
                    ". Available quantity: " .
                    (int) $product["stock_quantity"] . ".";

                continue;
            }

            if ((int) $product["seller_id"] <= 0) {
                $errors[] =
                    "The seller information for " .
                    $product["product_name"] .
                    " is invalid.";

                continue;
            }

            $product["quantity"] = $quantity;
            $product["line_total"] =
                (float) $product["price"] * $quantity;

            $cartProducts[] = $product;
            $grandTotal += $product["line_total"];
        }

        $stmt->close();

    } catch (Throwable $e) {
        error_log("SolarGo checkout product error: " . $e->getMessage());
        $errors[] = "We could not load your cart. Please try again.";
    }
}

// Place the order.
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $deliveryAddress = trim($_POST["delivery_address"] ?? "");

    $submittedToken = $_POST["csrf_token"] ?? "";

    if (
        !is_string($submittedToken) ||
        !hash_equals($_SESSION["csrf_token"], $submittedToken)
    ) {
        $errors[] =
            "Your session security token is invalid. Refresh the page and try again.";
    }

    if ($deliveryAddress === "") {
        $errors[] = "Please enter your delivery address.";
    } elseif (strlen($deliveryAddress) > 255) {
        $errors[] = "Your delivery address must not exceed 255 characters.";
    }

    if (empty($cartProducts) && empty($errors)) {
        $errors[] = "Your cart contains no available products.";
    }

    if (empty($errors)) {
        try {
            $conn->begin_transaction();

            // Group products by seller.
            $productsBySeller = [];

            foreach ($cartProducts as $product) {
                $sellerId = (int) $product["seller_id"];

                if (!isset($productsBySeller[$sellerId])) {
                    $productsBySeller[$sellerId] = [
                        "products" => [],
                        "total" => 0
                    ];
                }

                $productsBySeller[$sellerId]["products"][] = $product;

                $productsBySeller[$sellerId]["total"] +=
                    $product["line_total"];
            }

            // Prepare statements using your actual database columns.
            $lockProduct = $conn->prepare(
                "SELECT product_id, product_name, seller_id,
                        price, stock_quantity
                 FROM products
                 WHERE product_id = ?
                   AND listing_status = 'approved'
                 FOR UPDATE"
            );

            $insertOrder = $conn->prepare(
                "INSERT INTO orders
                    (customer_id, seller_id, total_amount,
                     order_status, delivery_status, delivery_address)
                 VALUES (?, ?, ?, 'pending', 'not_ready', ?)"
            );

            $insertOrderItem = $conn->prepare(
                "INSERT INTO order_items
                    (order_id, product_id, product_name,
                     unit_price, quantity, line_total)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );

            $updateStock = $conn->prepare(
                "UPDATE products
                 SET stock_quantity = stock_quantity - ?
                 WHERE product_id = ?
                   AND stock_quantity >= ?
                   AND listing_status = 'approved'"
            );

            $createdOrderIds = [];

            // Create one order per seller.
            foreach ($productsBySeller as $sellerId => $sellerOrder) {
                $sellerTotal = $sellerOrder["total"];

                $insertOrder->bind_param(
                    "iids",
                    $customerId,
                    $sellerId,
                    $sellerTotal,
                    $deliveryAddress
                );

                $insertOrder->execute();

                $orderId = (int) $conn->insert_id;

                // Add each product to this seller's order.
                foreach ($sellerOrder["products"] as $cartProduct) {
                    $productId = (int) $cartProduct["product_id"];
                    $quantity = (int) $cartProduct["quantity"];

                    // Lock and recheck the current product and stock.
                    $lockProduct->bind_param("i", $productId);
                    $lockProduct->execute();

                    $currentProduct =
                        $lockProduct->get_result()->fetch_assoc();

                    if (
                        !$currentProduct ||
                        (int) $currentProduct["seller_id"] !== (int) $sellerId
                    ) {
                        throw new RuntimeException(
                            "A product is no longer available from its seller."
                        );
                    }

                    if ((int) $currentProduct["stock_quantity"] < $quantity) {
                        throw new RuntimeException(
                            "Insufficient stock for " .
                            $currentProduct["product_name"] .
                            ". Please update your cart."
                        );
                    }

                    $unitPrice = (float) $currentProduct["price"];
                    $productName = $currentProduct["product_name"];
                    $lineTotal = $unitPrice * $quantity;

                    // Store the purchased product details.
                    $insertOrderItem->bind_param(
                        "iisdid",
                        $orderId,
                        $productId,
                        $productName,
                        $unitPrice,
                        $quantity,
                        $lineTotal
                    );

                    $insertOrderItem->execute();

                    // Reduce available stock.
                    $updateStock->bind_param(
                        "iii",
                        $quantity,
                        $productId,
                        $quantity
                    );

                    $updateStock->execute();

                    if ($updateStock->affected_rows !== 1) {
                        throw new RuntimeException(
                            "Stock could not be updated. Please try again."
                        );
                    }
                }

                $createdOrderIds[] = $orderId;
            }

            $conn->commit();

            // Clear the cart only after all orders succeed.
            unset($_SESSION["cart"]);

            // Store the order IDs for the confirmation page.
            $_SESSION["last_order_ids"] = $createdOrderIds;

            header("Location: order-success.php");
            exit;

        } catch (Throwable $e) {
            $conn->rollback();

            error_log("SolarGo checkout error: " . $e->getMessage());

            $errors[] = $e instanceof RuntimeException
                ? $e->getMessage()
                : "We could not place your order. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Checkout | SolarGo</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f7f5;
            color: #24352b;
            line-height: 1.6;
        }

        header {
            background: #14532d;
            color: white;
            padding: 20px;
        }

        .header-inner {
            max-width: 1100px;
            margin: auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        header a {
            color: white;
            text-decoration: none;
        }

        main {
            max-width: 1100px;
            margin: 35px auto;
            padding: 0 18px;
        }

        h1 {
            margin-bottom: 8px;
        }

        .intro {
            margin-bottom: 25px;
            color: #52645a;
        }

        .checkout-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            align-items: start;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 3px 15px rgba(0, 0, 0, 0.06);
        }

        .card h2 {
            margin-bottom: 20px;
            font-size: 21px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 8px;
        }

        textarea {
            width: 100%;
            min-height: 120px;
            padding: 12px;
            border: 1px solid #cbd5ce;
            border-radius: 7px;
            font: inherit;
            resize: vertical;
        }

        textarea:focus {
            outline: 2px solid #86efac;
            border-color: #15803d;
        }

        .btn {
            display: inline-block;
            width: 100%;
            border: none;
            border-radius: 7px;
            padding: 13px 18px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
        }

        .btn-primary {
            background: #15803d;
            color: white;
        }

        .btn-primary:hover {
            background: #166534;
        }

        .btn-secondary {
            background: #e5eee8;
            color: #14532d;
            margin-top: 12px;
        }

        .product {
            display: flex;
            gap: 14px;
            padding: 15px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .product img {
            width: 75px;
            height: 75px;
            object-fit: cover;
            border-radius: 8px;
            background: #edf2ee;
        }

        .product-info {
            flex: 1;
            min-width: 0;
        }

        .product-info h3 {
            font-size: 16px;
            overflow-wrap: anywhere;
        }

        .muted {
            color: #66756b;
            font-size: 14px;
        }

        .product-total {
            font-weight: bold;
            white-space: nowrap;
        }

        .grand-total {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            margin-top: 22px;
            padding-top: 18px;
            border-top: 2px solid #e5e7eb;
            font-size: 20px;
            font-weight: bold;
        }

        .notice {
            padding: 14px 16px;
            border-radius: 7px;
            margin-bottom: 20px;
        }

        .error {
            color: #842029;
            background: #f8d7da;
            border: 1px solid #f5c2c7;
        }

        .empty {
            padding: 15px 0;
            color: #66756b;
        }

        .seller-note {
            margin-top: 15px;
            font-size: 13px;
            color: #66756b;
        }

        @media (max-width: 750px) {
            .checkout-layout {
                grid-template-columns: 1fr;
            }

            main {
                margin-top: 22px;
            }
        }
    </style>
</head>

<body>

<header>
    <div class="header-inner">
        <h2><a href="index.php">SolarGo</a></h2>
        <a href="cart.php">Back to Cart</a>
    </div>
</header>

<main>
    <h1>Checkout</h1>

    <p class="intro">
        Review your products and provide the address where you want your
        solar products delivered.
    </p>

    <?php if (!empty($errors)): ?>
        <div class="notice error">
            <strong>Please check the following:</strong>
            <ul style="padding-left: 22px; margin-top: 8px;">
                <?php foreach (array_unique($errors) as $error): ?>
                    <li><?= checkoutEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (empty($cartProducts)): ?>

        <div class="card">
            <h2>Your cart is empty</h2>

            <p class="empty">
                Add some products before proceeding to checkout.
            </p>

            <a href="products.php" class="btn btn-primary">
                Browse Products
            </a>
        </div>

    <?php else: ?>

        <div class="checkout-layout">

            <section class="card">
                <h2>Delivery Information</h2>

                <form method="POST" action="checkout.php">

                    <input
                        type="hidden"
                        name="csrf_token"
                        value="<?= checkoutEscape($_SESSION["csrf_token"]) ?>"
                    >

                    <div class="form-group">
                        <label for="delivery_address">
                            Delivery Address
                        </label>

                        <textarea
                            id="delivery_address"
                            name="delivery_address"
                            maxlength="255"
                            placeholder="Enter your town, quarter, street, and other delivery details"
                            required
                        ><?= checkoutEscape($deliveryAddress) ?></textarea>
                    </div>

                    <button
                        type="submit"
                        class="btn btn-primary"
                        <?= !empty($errors) && $_SERVER["REQUEST_METHOD"] === "POST"
                            ? "disabled"
                            : "" ?>
                    >
                        Place Order
                    </button>

                    <?php if (
                        !empty($errors) &&
                        $_SERVER["REQUEST_METHOD"] === "POST"
                    ): ?>
                        <p class="seller-note">
                            Correct the errors above, then refresh the page
                            before trying to place your order again.
                        </p>
                    <?php endif; ?>

                    <a href="cart.php" class="btn btn-secondary">
                        Return to Cart
                    </a>

                </form>
            </section>

            <section class="card">
                <h2>Order Summary</h2>

                <?php foreach ($cartProducts as $product): ?>
                    <div class="product">

                        <?php
                        $imagePath = $product["image_url"] ?? "";
                        $imageFile = __DIR__ . "/" . $imagePath;

                        if (
                            $imagePath !== "" &&
                            is_file($imageFile) &&
                            strpos($imagePath, "uploads/") === 0
                        ):
                        ?>
                            <img
                                src="<?= checkoutEscape($imagePath) ?>"
                                alt="<?= checkoutEscape($product["product_name"]) ?>"
                            >
                        <?php else: ?>
                            <div
                                style="width:75px;height:75px;background:#edf2ee;border-radius:8px;"
                            ></div>
                        <?php endif; ?>

                        <div class="product-info">
                            <h3>
                                <?= checkoutEscape($product["product_name"]) ?>
                            </h3>

                            <p class="muted">
                                <?= (int) $product["quantity"] ?> ×
                                <?= number_format((float) $product["price"], 0) ?>
                                FCFA
                            </p>
                        </div>

                        <div class="product-total">
                            <?= number_format((float) $product["line_total"], 0) ?>
                            FCFA
                        </div>

                    </div>
                <?php endforeach; ?>

                <div class="grand-total">
                    <span>Total</span>
                    <span><?= number_format($grandTotal, 0) ?> FCFA</span>
                </div>

                <p class="seller-note">
                    Products from different sellers will be recorded as
                    separate orders. Delivery charges are not included yet.
                </p>
            </section>

        </div>

    <?php endif; ?>

</main>

</body>
</html>
