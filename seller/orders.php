
<?php
session_start();

require_once __DIR__ . "/../config/database.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Allow sellers only.
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "seller"
) {
    header("Location: ../auth/login.php");
    exit;
}

// Generate CSRF token for secure order-management forms.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

$sellerId = (int) $_SESSION["user_id"];
$orders = [];
$error = "";

// Verify that the seller account is active.
try {
    $stmt = $conn->prepare(
        "SELECT account_status
         FROM users
         WHERE user_id = ?
           AND role = 'seller'
         LIMIT 1"
    );

    $stmt->bind_param("i", $sellerId);
    $stmt->execute();

    $seller = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$seller || $seller["account_status"] !== "active") {
        session_destroy();
        header("Location: ../auth/login.php");
        exit;
    }

    // Fetch orders belonging to this seller.
 $stmt = $conn->prepare(
    "SELECT
        o.order_id,
        o.customer_id,
        u.full_name AS customer_name,
        u.email AS customer_email,
        u.phone AS customer_phone,
        o.total_amount,
        o.order_status,
        o.delivery_status,
        o.delivery_address,
        o.created_at
     FROM orders o
     INNER JOIN users u ON o.customer_id = u.user_id
     WHERE o.seller_id = ?
     ORDER BY o.created_at DESC"
);

    $stmt->bind_param("i", $sellerId);
    $stmt->execute();

    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $orders[] = $row;
    }

    $stmt->close();

    // Fetch items for each order.
    if (!empty($orders)) {
        $itemStmt = $conn->prepare(
            "SELECT
                product_id,
                product_name,
                unit_price,
                quantity,
                line_total
             FROM order_items
             WHERE order_id = ?
             ORDER BY order_item_id ASC"
        );

        foreach ($orders as $key => $order) {
            $orderId = (int) $order["order_id"];

            $itemStmt->bind_param("i", $orderId);
            $itemStmt->execute();

            $itemResult = $itemStmt->get_result();
            $orders[$key]["items"] = [];

            while ($item = $itemResult->fetch_assoc()) {
                $orders[$key]["items"][] = $item;
            }
        }

        $itemStmt->close();
    }

} catch (Throwable $e) {
    error_log("SolarGo seller orders error: " . $e->getMessage());
    $error = "Unable to load your orders right now. Please try again.";
}

// Escape output safely.
function sellerOrderEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

// Generate a CSS class for statuses.
function sellerOrderStatusClass(string $status): string
{
    switch ($status) {
        case "confirmed":
        case "completed":
        case "delivered":
            return "success";

        case "cancelled":
            return "danger";

        case "ready":
        case "shipped":
            return "info";

        default:
            return "pending";
    }
}

// Determine which order-management actions are available.
function sellerOrderCanCancel(array $order): bool
{
    return in_array(
        $order["order_status"],
        ["pending", "confirmed"],
        true
    ) && in_array(
        $order["delivery_status"],
        ["not_ready", "ready"],
        true
    );
}

// Dashboard statistics.
$totalOrders = count($orders);
$pendingOrders = 0;
$completedOrders = 0;
$totalSales = 0;

foreach ($orders as $order) {
    if ($order["order_status"] === "pending") {
        $pendingOrders++;
    }

    if (
        $order["order_status"] === "completed" ||
        $order["delivery_status"] === "delivered"
    ) {
        $completedOrders++;
    }

    $totalSales += (float) $order["total_amount"];
}

// Retrieve one-time status messages.
$orderMessage = $_SESSION["order_message"] ?? null;
unset($_SESSION["order_message"]);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Orders | SolarGo Seller</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f7f5;
            color: #26372c;
            line-height: 1.6;
        }

        header {
            background: #14532d;
            color: white;
            padding: 20px;
        }

        .header-inner {
            max-width: 1150px;
            margin: auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }

        header a {
            color: white;
            text-decoration: none;
        }

        main {
            max-width: 1150px;
            margin: 35px auto;
            padding: 0 18px;
        }

        h1 {
            margin-bottom: 8px;
        }

        .intro {
            color: #66756b;
            margin-bottom: 25px;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
        }

        .stat-card p {
            color: #66756b;
            font-size: 14px;
        }

        .stat-card h2 {
            margin-top: 8px;
            font-size: 23px;
            color: #14532d;
            overflow-wrap: anywhere;
        }

        .order-card {
            background: white;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 3px 14px rgba(0, 0, 0, 0.05);
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 15px;
            flex-wrap: wrap;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 17px;
            margin-bottom: 18px;
        }

        .order-header h2 {
            color: #14532d;
            font-size: 21px;
        }

        .muted {
            color: #66756b;
            font-size: 14px;
        }

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            margin: 3px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .danger {
            background: #fee2e2;
            color: #991b1b;
        }

        .info {
            background: #dbeafe;
            color: #1e40af;
        }

        .pending {
            background: #fef3c7;
            color: #92400e;
        }

        .customer-details {
            background: #f8faf8;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .customer-details p {
            margin: 6px 0;
            overflow-wrap: anywhere;
        }

        .section-title {
            font-size: 17px;
            margin-bottom: 10px;
        }

        .item-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            padding: 12px 0;
            border-bottom: 1px solid #edf0ed;
        }

        .item-name {
            font-weight: bold;
        }

        .item-price {
            text-align: right;
            white-space: nowrap;
        }

        .order-total {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            margin-top: 18px;
            padding-top: 15px;
            border-top: 2px solid #e5e7eb;
            font-size: 19px;
            font-weight: bold;
        }

        .order-message {
            padding: 15px;
            margin-bottom: 22px;
            border-radius: 8px;
            font-weight: bold;
        }

        .order-message.success-message {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .order-message.error-message {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .order-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 20px;
            padding-top: 17px;
            border-top: 1px solid #e5e7eb;
        }

        .order-actions form {
            margin: 0;
        }

        .order-actions button {
            padding: 10px 15px;
            border: none;
            border-radius: 6px;
            color: white;
            cursor: pointer;
            font-weight: bold;
            font-size: 13px;
        }

        .order-actions button:hover {
            opacity: 0.85;
        }

        .btn-confirm,
        .btn-deliver {
            background: #15803d;
        }

        .btn-prepare {
            background: #0d6efd;
        }

        .btn-ship {
            background: #6f42c1;
        }

        .btn-cancel {
            background: #dc3545;
        }

        .no-actions {
            color: #66756b;
            font-size: 14px;
            margin-top: 17px;
        }

        .empty,
        .error-box {
            background: white;
            border-radius: 10px;
            padding: 30px;
            text-align: center;
        }

        .error-box {
            background: #fee2e2;
            color: #991b1b;
            margin-bottom: 20px;
        }

        .btn {
            display: inline-block;
            margin-top: 18px;
            background: #15803d;
            color: white;
            padding: 11px 16px;
            border-radius: 7px;
            text-decoration: none;
            font-weight: bold;
        }

        @media (max-width: 850px) {
            .stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 550px) {
            .stats {
                grid-template-columns: 1fr;
            }

            .order-card {
                padding: 17px;
            }

            .item-row {
                flex-direction: column;
                gap: 4px;
            }

            .item-price {
                text-align: left;
            }

            .order-actions {
                flex-direction: column;
            }

            .order-actions form,
            .order-actions button {
                width: 100%;
            }
        }
    </style>
</head>

<body>

<header>
    <div class="header-inner">
        <h2><a href="../index.php">SolarGo Seller</a></h2>
        <a href="dashboard.php">Seller Dashboard</a>
    </div>
</header>

<main>

    <h1>My Customer Orders</h1>

    <p class="intro">
        View customer orders, confirm purchases, and manage delivery progress.
    </p>

    <?php if (is_array($orderMessage) && isset($orderMessage["text"])): ?>

        <?php
        $messageType = (
            isset($orderMessage["type"]) &&
            $orderMessage["type"] === "success"
        ) ? "success-message" : "error-message";
        ?>

        <div class="order-message <?= sellerOrderEscape($messageType) ?>">
            <?= sellerOrderEscape($orderMessage["text"]) ?>
        </div>

    <?php endif; ?>

    <?php if ($error !== ""): ?>

        <div class="error-box">
            <?= sellerOrderEscape($error) ?>
        </div>

    <?php else: ?>

        <section class="stats">

            <div class="stat-card">
                <p>Total Orders</p>
                <h2><?= $totalOrders ?></h2>
            </div>

            <div class="stat-card">
                <p>Pending Orders</p>
                <h2><?= $pendingOrders ?></h2>
            </div>

            <div class="stat-card">
                <p>Completed/Delivered</p>
                <h2><?= $completedOrders ?></h2>
            </div>

            <div class="stat-card">
                <p>Total Order Value</p>
                <h2><?= number_format($totalSales, 0) ?> FCFA</h2>
            </div>

        </section>

        <?php if (empty($orders)): ?>

            <section class="empty">
                <h2>No orders yet</h2>

                <p>
                    Orders containing your products will appear here
                    after customers make purchases.
                </p>

                <a href="dashboard.php" class="btn">
                    Back to Dashboard
                </a>
            </section>

        <?php else: ?>

            <?php foreach ($orders as $order): ?>

                <section class="order-card">

                    <div class="order-header">

                        <div>
                            <h2>
                                Order #<?= (int) $order["order_id"] ?>
                            </h2>

                            <p class="muted">
                                <?= sellerOrderEscape($order["created_at"]) ?>
                            </p>
                        </div>

                        <div>
                            <span class="status <?= sellerOrderStatusClass($order["order_status"]) ?>">
                                Order:
                                <?= sellerOrderEscape(ucfirst($order["order_status"])) ?>
                            </span>

                            <span class="status <?= sellerOrderStatusClass($order["delivery_status"]) ?>">
                                Delivery:
                                <?= sellerOrderEscape(
                                    ucwords(
                                        str_replace(
                                            "_",
                                            " ",
                                            $order["delivery_status"]
                                        )
                                    )
                                ) ?>
                            </span>
                        </div>

                    </div>

                    <div class="customer-details">

                        <h3 class="section-title">Delivery Information</h3>

<p>
    <strong>Customer Name:</strong>
    <?= sellerOrderEscape($order["customer_name"]) ?>
</p>

<p>
    <strong>Customer Email:</strong>
    <?= sellerOrderEscape($order["customer_email"]) ?>
</p>

<p>
    <strong>Customer Phone:</strong>
    <?= sellerOrderEscape(
        $order["customer_phone"] ?? "Not provided"
    ) ?>
</p>

                        <p>
                            <strong>Delivery Address:</strong>
                            <?= sellerOrderEscape($order["delivery_address"]) ?>
                        </p>

                    </div>

                    <h3 class="section-title">Products in This Order</h3>

                    <?php if (!empty($order["items"])): ?>

                        <?php foreach ($order["items"] as $item): ?>

                            <div class="item-row">

                                <div>
                                    <div class="item-name">
                                        <?= sellerOrderEscape($item["product_name"]) ?>
                                    </div>

                                    <p class="muted">
                                        <?= number_format((float) $item["unit_price"], 0) ?>
                                        FCFA × <?= (int) $item["quantity"] ?>
                                    </p>
                                </div>

                                <div class="item-price">
                                    <?= number_format((float) $item["line_total"], 0) ?>
                                    FCFA
                                </div>

                            </div>

                        <?php endforeach; ?>

                    <?php else: ?>

                        <p class="muted">
                            No product details were found for this order.
                        </p>

                    <?php endif; ?>

                    <div class="order-total">
                        <span>Order Total</span>

                        <span>
                            <?= number_format((float) $order["total_amount"], 0) ?>
                            FCFA
                        </span>
                    </div>

                    <!-- Order management buttons -->
                    <div class="order-actions">

                        <?php if (
                            $order["order_status"] === "pending" &&
                            $order["delivery_status"] === "not_ready"
                        ): ?>

                            <form action="update-order.php" method="POST">

                                <input type="hidden" name="csrf_token"
                                       value="<?= sellerOrderEscape($_SESSION["csrf_token"]) ?>">

                                <input type="hidden" name="order_id"
                                       value="<?= (int) $order["order_id"] ?>">

                                <input type="hidden" name="action" value="confirm">

                                <button type="submit" class="btn-confirm">
                                    Confirm Order
                                </button>

                            </form>

                        <?php endif; ?>

                        <?php if (
                            $order["order_status"] === "confirmed" &&
                            $order["delivery_status"] === "not_ready"
                        ): ?>

                            <form action="update-order.php" method="POST">

                                <input type="hidden" name="csrf_token"
                                       value="<?= sellerOrderEscape($_SESSION["csrf_token"]) ?>">

                                <input type="hidden" name="order_id"
                                       value="<?= (int) $order["order_id"] ?>">

                                <input type="hidden" name="action" value="prepare">

                                <button type="submit" class="btn-prepare">
                                    Mark Ready
                                </button>

                            </form>

                        <?php endif; ?>

                        <?php if (
                            $order["order_status"] === "confirmed" &&
                            $order["delivery_status"] === "ready"
                        ): ?>

                            <form action="update-order.php" method="POST">

                                <input type="hidden" name="csrf_token"
                                       value="<?= sellerOrderEscape($_SESSION["csrf_token"]) ?>">

                                <input type="hidden" name="order_id"
                                       value="<?= (int) $order["order_id"] ?>">

                                <input type="hidden" name="action" value="ship">

                                <button type="submit" class="btn-ship">
                                    Ship Order
                                </button>

                            </form>

                        <?php endif; ?>

                        <?php if (
                            $order["order_status"] === "confirmed" &&
                            $order["delivery_status"] === "shipped"
                        ): ?>

                            <form action="update-order.php" method="POST">

                                <input type="hidden" name="csrf_token"
                                       value="<?= sellerOrderEscape($_SESSION["csrf_token"]) ?>">

                                <input type="hidden" name="order_id"
                                       value="<?= (int) $order["order_id"] ?>">

                                <input type="hidden" name="action" value="deliver">

                                <button type="submit" class="btn-deliver">
                                    Mark Delivered
                                </button>

                            </form>

                        <?php endif; ?>

                        <?php if (sellerOrderCanCancel($order)): ?>

                            <form action="update-order.php" method="POST"
                                  onsubmit="return confirm('Are you sure you want to cancel this order?');">

                                <input type="hidden" name="csrf_token"
                                       value="<?= sellerOrderEscape($_SESSION["csrf_token"]) ?>">

                                <input type="hidden" name="order_id"
                                       value="<?= (int) $order["order_id"] ?>">

                                <input type="hidden" name="action" value="cancel">

                                <button type="submit" class="btn-cancel">
                                    Cancel Order
                                </button>

                            </form>

                        <?php endif; ?>

                    </div>

                    <?php
                    $canManageOrder =
                        (
                            $order["order_status"] === "pending" &&
                            $order["delivery_status"] === "not_ready"
                        ) ||
                        (
                            $order["order_status"] === "confirmed" &&
                            in_array(
                                $order["delivery_status"],
                                ["not_ready", "ready", "shipped"],
                                true
                            )
                        ) ||
                        sellerOrderCanCancel($order);
                    ?>

                    <?php if (!$canManageOrder): ?>
                        <p class="no-actions">
                            No further seller actions are available for this order.
                        </p>
                    <?php endif; ?>

                </section>

            <?php endforeach; ?>

        <?php endif; ?>

    <?php endif; ?>

</main>

</body>
</html>
