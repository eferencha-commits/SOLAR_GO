
<?php
session_start();

require_once __DIR__ . "/config/database.php";

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "customer"
) {
    header("Location: auth/login.php");
    exit;
}

$customerId = (int) $_SESSION["user_id"];

// Retrieve the order IDs created by checkout.
$orderIds = $_SESSION["last_order_ids"] ?? [];

if (!is_array($orderIds) || empty($orderIds)) {
    header("Location: customer/dashboard.php");
    exit;
}

// Fetch only orders belonging to the logged-in customer.
$orders = [];

$stmt = $conn->prepare(
    "SELECT order_id, total_amount, order_status,
            delivery_status, delivery_address, created_at
     FROM orders
     WHERE customer_id = ?
       AND order_id = ?
     LIMIT 1"
);

foreach ($orderIds as $orderId) {
    $orderId = (int) $orderId;

    if ($orderId <= 0) {
        continue;
    }

    $stmt->bind_param("ii", $customerId, $orderId);
    $stmt->execute();

    $result = $stmt->get_result();
    $order = $result->fetch_assoc();

    if ($order) {
        $orders[] = $order;
    }
}

$stmt->close();

function orderEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

if (empty($orders)) {
    header("Location: customer/dashboard.php");
    exit;
}

$totalAmount = 0;

foreach ($orders as $order) {
    $totalAmount += (float) $order["total_amount"];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Order Successful | SolarGo</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f2f7f3;
            color: #24352b;
            line-height: 1.6;
        }

        header {
            background: #14532d;
            color: white;
            padding: 20px;
        }

        .header-inner {
            max-width: 900px;
            margin: auto;
        }

        header a {
            color: white;
            text-decoration: none;
        }

        main {
            max-width: 850px;
            margin: 45px auto;
            padding: 0 18px;
        }

        .success-card {
            background: white;
            padding: 35px;
            border-radius: 14px;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.07);
            text-align: center;
        }

        .success-icon {
            width: 75px;
            height: 75px;
            margin: 0 auto 20px;
            border-radius: 50%;
            background: #dcfce7;
            color: #15803d;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 42px;
            font-weight: bold;
        }

        h1 {
            color: #166534;
            margin-bottom: 12px;
        }

        .intro {
            color: #647067;
            margin-bottom: 28px;
        }

        .order-list {
            text-align: left;
            margin-top: 25px;
        }

        .order-card {
            border: 1px solid #dce6de;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 18px;
        }

        .order-card h2 {
            font-size: 19px;
            margin-bottom: 12px;
            color: #14532d;
        }

        .order-card p {
            margin: 7px 0;
            overflow-wrap: anywhere;
        }

        .status {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            background: #fef3c7;
            color: #92400e;
            font-size: 13px;
            font-weight: bold;
        }

        .grand-total {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding-top: 20px;
            border-top: 2px solid #e5e7eb;
            margin-top: 25px;
            font-size: 21px;
            font-weight: bold;
        }

        .buttons {
            display: flex;
            justify-content: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 30px;
        }

        .btn {
            display: inline-block;
            text-decoration: none;
            padding: 12px 20px;
            border-radius: 7px;
            font-weight: bold;
        }

        .btn-primary {
            background: #15803d;
            color: white;
        }

        .btn-secondary {
            background: #e5eee8;
            color: #14532d;
        }

        @media (max-width: 550px) {
            .success-card {
                padding: 22px 16px;
            }

            .grand-total {
                font-size: 18px;
            }
        }
    </style>
</head>

<body>

<header>
    <div class="header-inner">
        <h2><a href="index.php">SolarGo</a></h2>
    </div>
</header>

<main>
    <section class="success-card">

        <div class="success-icon">&#10003;</div>

        <h1>Order Placed Successfully!</h1>

        <p class="intro">
            Thank you for shopping with SolarGo.
            Your order has been recorded successfully.
        </p>

        <div class="order-list">

            <?php foreach ($orders as $order): ?>

                <article class="order-card">

                    <h2>
                        Order #<?= (int) $order["order_id"] ?>
                    </h2>

                    <p>
                        <strong>Order total:</strong>
                        <?= number_format((float) $order["total_amount"], 0) ?>
                        FCFA
                    </p>

                    <p>
                        <strong>Order status:</strong>
                        <span class="status">
                            <?= orderEscape(ucfirst($order["order_status"])) ?>
                        </span>
                    </p>

                    <p>
                        <strong>Delivery status:</strong>
                        <?= orderEscape(
                            ucwords(str_replace("_", " ", $order["delivery_status"]))
                        ) ?>
                    </p>

                    <p>
                        <strong>Delivery address:</strong>
                        <?= orderEscape($order["delivery_address"]) ?>
                    </p>

                    <p>
                        <strong>Date:</strong>
                        <?= orderEscape($order["created_at"]) ?>
                    </p>

                </article>

            <?php endforeach; ?>

        </div>

        <div class="grand-total">
            <span>Total for all orders</span>
            <span><?= number_format($totalAmount, 0) ?> FCFA</span>
        </div>

        <div class="buttons">
            <a href="products.php" class="btn btn-primary">
                Continue Shopping
            </a>

            <a href="customer/dashboard.php" class="btn btn-secondary">
                My Dashboard
            </a>
        </div>

    </section>
</main>

</body>
</html>
