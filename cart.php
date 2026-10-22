
<?php

session_start();

require_once __DIR__ . "/config/database.php";

function escapeHTML($value)
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

function formatPrice($price)
{
    return number_format((float) $price, 0, ".", ",") . " FCFA";
}

// Require a logged-in customer.
if (
    empty($_SESSION["user_id"]) ||
    ($_SESSION["role"] ?? "") !== "customer"
) {
    header("Location: auth/login.php");
    exit;
}

$cart = $_SESSION["cart"] ?? [];

$items = [];
$subtotal = 0;

// Retrieve current product details from the database.
if (is_array($cart) && !empty($cart)) {
    $stmt = $conn->prepare(
        "SELECT product_id, product_name, price, stock_quantity, image_url
         FROM products
         WHERE product_id = ?
           AND listing_status = 'approved'
         LIMIT 1"
    );

    if (!$stmt) {
        error_log("Cart query error: " . $conn->error);
        http_response_code(500);
        exit("Unable to load your cart right now.");
    }

    foreach ($cart as $productId => $cartItem) {
        $productId = filter_var(
            $productId,
            FILTER_VALIDATE_INT
        );

        if (!$productId || $productId < 1) {
            continue;
        }

        $quantity = filter_var(
            $cartItem["quantity"] ?? 0,
            FILTER_VALIDATE_INT
        );

        if (!$quantity || $quantity < 1) {
            continue;
        }

        $stmt->bind_param("i", $productId);

        if (!$stmt->execute()) {
            error_log("Cart item query error: " . $stmt->error);
            continue;
        }

        $result = $stmt->get_result();
        $product = $result->fetch_assoc();

        if (!$product) {
            unset($_SESSION["cart"][$productId]);
            continue;
        }

        $stock = (int) $product["stock_quantity"];

        // Remove products that are no longer in stock.
        if ($stock < 1) {
            unset($_SESSION["cart"][$productId]);
            continue;
        }

        // Reduce the quantity if the available stock has changed.
        if ($quantity > $stock) {
            $quantity = $stock;
            $_SESSION["cart"][$productId]["quantity"] = $quantity;
        }

        $lineTotal = (float) $product["price"] * $quantity;

        $product["quantity"] = $quantity;
        $product["line_total"] = $lineTotal;

        $items[] = $product;
        $subtotal += $lineTotal;
    }

    $stmt->close();
}

// Generate a CSRF token if one does not exist.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Shopping Cart | SolarGo</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f5f7f5;
            color: #24332b;
            line-height: 1.6;
        }

        header {
            background: #123c2a;
            color: white;
            padding: 18px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        header h1 {
            font-size: 25px;
        }

        header a {
            color: white;
            text-decoration: none;
        }

        main {
            width: 90%;
            max-width: 1000px;
            margin: 40px auto;
        }

        .cart-item {
            background: white;
            padding: 20px;
            margin-bottom: 15px;
            border-radius: 10px;
            display: grid;
            grid-template-columns: 1fr auto;
            gap: 15px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
        }

        .cart-item h2 {
            font-size: 18px;
            margin-bottom: 5px;
        }

        .cart-item p {
            color: #647269;
            margin-bottom: 5px;
        }

        .line-total {
            color: #16834a;
            font-weight: bold;
        }

        .summary {
            background: white;
            padding: 25px;
            margin-top: 25px;
            border-radius: 10px;
        }

        .summary h2 {
            margin-bottom: 15px;
        }

        .subtotal {
            font-size: 24px;
            color: #16834a;
            font-weight: bold;
            margin-bottom: 20px;
        }

        .btn {
            display: inline-block;
            background: #16834a;
            color: white;
            border: none;
            padding: 12px 18px;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
        }

        .btn:hover {
            background: #116839;
        }

        .empty-cart {
            background: white;
            padding: 45px 20px;
            text-align: center;
            border-radius: 10px;
        }

        .empty-cart p {
            margin: 10px 0 20px;
            color: #647269;
        }

        @media (max-width: 600px) {
            .cart-item {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<header>
    <h1>
        <a href="products.php">SolarGo</a>
    </h1>

    <a href="products.php">Continue Shopping</a>
</header>

<main>

    <h1>My Shopping Cart</h1>

    <p style="margin: 8px 0 25px; color: #647269;">
        Review your selected solar products before checkout.
    </p>

    <?php if (empty($items)): ?>

        <div class="empty-cart">
            <h2>Your cart is empty</h2>

            <p>
                You haven't added any solar products to your cart yet.
            </p>

            <a href="products.php" class="btn">
                Browse Products
            </a>
        </div>

    <?php else: ?>

        <?php foreach ($items as $item): ?>

            <article class="cart-item">

                <div>
                    <h2>
                        <?= escapeHTML($item["product_name"]) ?>
                    </h2>

                    <p>
                        Unit price:
                        <?= escapeHTML(formatPrice($item["price"])) ?>
                    </p>

                    <p>
                        Quantity:
                        <?= (int) $item["quantity"] ?>
                    </p>

                    <p>
                        Available stock:
                        <?= (int) $item["stock_quantity"] ?>
                    </p>
                </div>

                <div>
                    <p class="line-total">
                        <?= escapeHTML(formatPrice($item["line_total"])) ?>
                    </p>
                </div>

            </article>

        <?php endforeach; ?>

        <section class="summary">

            <h2>Order Summary</h2>

            <p class="subtotal">
                Subtotal: <?= escapeHTML(formatPrice($subtotal)) ?>
            </p>

            <p style="margin-bottom: 20px; color: #647269;">
                Delivery charges and final order details will be
                calculated during checkout.
            </p>

            <a href="products.php" class="btn">
                Continue Shopping
            </a>

            <button
                type="button"
                class="btn"
                onclick="alert('Checkout will be implemented in the next step.')"
            >
                Proceed to Checkout
            </button>

        </section>

    <?php endif; ?>

</main>

</body>
</html>
