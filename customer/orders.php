
<?php
session_start();

require_once __DIR__ . "/../config/database.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Allow logged-in customers only.
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "customer"
) {
    header("Location: ../auth/login.php");
    exit;
}

$customerId = (int) $_SESSION["user_id"];

// Escape output safely.
function customerOrderEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

// Convert database status into readable text.
function customerOrderStatus($status): string
{
    return ucfirst(str_replace("_", " ", (string) $status));
}

// Load all orders belonging to this customer.
$sql = "
    SELECT
        order_id,
        seller_id,
        total_amount,
        order_status,
        delivery_status,
        delivery_address,
        created_at
    FROM orders
    WHERE customer_id = ?
    ORDER BY created_at DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $customerId);
$stmt->execute();

$result = $stmt->get_result();

$orders = [];

while ($row = $result->fetch_assoc()) {
    $orders[] = $row;
}

$stmt->close();

// Count order statuses.
$totalOrders = count($orders);
$pendingOrders = 0;
$completedOrders = 0;

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
}

// Load items for every order.
$orderItems = [];

if (!empty($orders)) {
    $itemStmt = $conn->prepare("
        SELECT
            order_id,
            product_name,
            unit_price,
            quantity,
            line_total
        FROM order_items
        WHERE order_id = ?
    ");

    foreach ($orders as $order) {
        $orderId = (int) $order["order_id"];

        $itemStmt->bind_param("i", $orderId);
        $itemStmt->execute();

        $itemResult = $itemStmt->get_result();

        $orderItems[$orderId] = [];

        while ($item = $itemResult->fetch_assoc()) {
            $orderItems[$orderId][] = $item;
        }
    }

    $itemStmt->close();
}

// Determine delivery progress.
function customerDeliveryProgress($order): int
{
    if (
        $order["order_status"] === "cancelled" ||
        $order["delivery_status"] === "cancelled"
    ) {
        return 0;
    }

    switch ($order["delivery_status"]) {
        case "delivered":
            return 4;

        case "shipped":
            return 3;

        case "ready":
            return 2;

        case "not_ready":
            return $order["order_status"] === "confirmed" ? 1 : 0;

        default:
            return 0;
    }
}

function customerDeliveryLabel($order): string
{
    if (
        $order["order_status"] === "cancelled" ||
        $order["delivery_status"] === "cancelled"
    ) {
        return "Cancelled";
    }

    return customerOrderStatus($order["delivery_status"]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Orders | SolarGo</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f7f4;
            color: #24352b;
            line-height: 1.6;
        }

        header {
            background: #14532d;
            color: white;
            padding: 18px 5%;
        }

        .header-inner {
            max-width: 1150px;
            margin: auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
        }

        .logo {
            color: white;
            text-decoration: none;
            font-size: 26px;
            font-weight: bold;
        }

        nav {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }

        nav a {
            color: white;
            text-decoration: none;
            font-weight: bold;
        }

        nav a:hover {
            text-decoration: underline;
        }

        main {
            max-width: 1150px;
            margin: 35px auto;
            padding: 0 20px;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            color: #14532d;
            font-size: 30px;
            margin-bottom: 5px;
        }

        .page-heading p {
            color: #647067;
        }

        .summary {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 30px;
        }

        .summary-card {
            background: white;
            padding: 22px;
            border-radius: 12px;
            border: 1px solid #e0e8e1;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
        }

        .summary-card p {
            color: #647067;
            font-size: 14px;
        }

        .summary-card h2 {
            color: #166534;
            font-size: 28px;
            margin-top: 5px;
        }

        .order-card {
            background: white;
            border: 1px solid #dce6de;
            border-radius: 13px;
            margin-bottom: 25px;
            overflow: hidden;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
        }

        .order-header {
            padding: 20px 24px;
            background: #edf5ee;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
            border-bottom: 1px solid #dce6de;
        }

        .order-header h2 {
            color: #14532d;
            font-size: 21px;
        }

        .order-date {
            font-size: 13px;
            color: #647067;
        }

        .order-content {
            padding: 24px;
        }

        .status-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 22px;
        }

        .status-badge {
            display: inline-block;
            padding: 7px 12px;
            border-radius: 20px;
            background: #fef3c7;
            color: #92400e;
            font-size: 13px;
            font-weight: bold;
        }

        .status-badge.delivered {
            background: #dcfce7;
            color: #166534;
        }

        .status-badge.cancelled {
            background: #fee2e2;
            color: #991b1b;
        }

        .tracking {
            margin: 25px 0;
        }

        .tracking h3 {
            margin-bottom: 20px;
            font-size: 17px;
        }

        .progress-track {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            position: relative;
            gap: 8px;
        }

        .progress-step {
            position: relative;
            text-align: center;
            color: #8a958d;
            font-size: 12px;
        }

        .progress-circle {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            border: 2px solid #cbd5ce;
            background: white;
            margin: 0 auto 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            position: relative;
            z-index: 1;
        }

        .progress-step.active {
            color: #166534;
            font-weight: bold;
        }

        .progress-step.active .progress-circle {
            background: #15803d;
            border-color: #15803d;
            color: white;
        }

        .progress-track.cancelled-track {
            display: block;
            padding: 15px;
            background: #fee2e2;
            color: #991b1b;
            border-radius: 8px;
            font-weight: bold;
        }

        .section-title {
            font-size: 17px;
            margin: 25px 0 12px;
            color: #14532d;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 480px;
        }

        th,
        td {
            text-align: left;
            padding: 12px 10px;
            border-bottom: 1px solid #e5e7eb;
            font-size: 14px;
        }

        th {
            background: #f8faf8;
            color: #47554b;
        }

        .order-details {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 18px;
            margin-top: 24px;
        }

        .detail-box {
            background: #f8faf8;
            border: 1px solid #e5ebe6;
            border-radius: 9px;
            padding: 15px;
            overflow-wrap: anywhere;
        }

        .detail-box h3 {
            font-size: 15px;
            margin-bottom: 5px;
            color: #14532d;
        }

        .total-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            border-top: 2px solid #e5e7eb;
            margin-top: 20px;
            padding-top: 18px;
            font-size: 19px;
            font-weight: bold;
        }

        .empty-state {
            background: white;
            padding: 45px 25px;
            text-align: center;
            border-radius: 13px;
            border: 1px solid #dce6de;
        }

        .empty-state h2 {
            color: #14532d;
            margin-bottom: 10px;
        }

        .empty-state p {
            color: #647067;
            margin-bottom: 22px;
        }

        .btn {
            display: inline-block;
            background: #15803d;
            color: white;
            padding: 12px 20px;
            text-decoration: none;
            border-radius: 7px;
            font-weight: bold;
        }

        .btn:hover {
            background: #166534;
        }

        footer {
            margin-top: 45px;
            padding: 22px;
            text-align: center;
            color: #647067;
            background: #e8efe9;
        }

        @media (max-width: 700px) {
            .summary {
                grid-template-columns: 1fr;
            }

            .order-details {
                grid-template-columns: 1fr;
            }

            .order-content {
                padding: 18px;
            }

            .order-header {
                padding: 18px;
            }

            .page-heading h1 {
                font-size: 25px;
            }

            .progress-step {
                font-size: 10px;
            }

            .progress-circle {
                width: 28px;
                height: 28px;
            }

            .header-inner {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>

<body>

<header>
    <div class="header-inner">
        <a class="logo" href="../index.php">SolarGo</a>

        <nav>
            <a href="../index.php">Home</a>
            <a href="../products.php">Products</a>
            <a href="dashboard.php">Dashboard</a>
        </nav>
    </div>
</header>

<main>

    <section class="page-heading">
        <h1>My Orders</h1>
        <p>View your purchases and follow the progress of your deliveries.</p>
    </section>

    <section class="summary">

        <article class="summary-card">
            <p>Total Orders</p>
            <h2><?= $totalOrders ?></h2>
        </article>

        <article class="summary-card">
            <p>Pending Orders</p>
            <h2><?= $pendingOrders ?></h2>
        </article>

        <article class="summary-card">
            <p>Completed Orders</p>
            <h2><?= $completedOrders ?></h2>
        </article>

    </section>

    <?php if (empty($orders)): ?>

        <section class="empty-state">
            <h2>You haven't placed any orders yet.</h2>

            <p>
                Explore our solar products and find the right solution
                for your home or business.
            </p>

            <a href="../products.php" class="btn">
                Explore Products
            </a>
        </section>

    <?php else: ?>

        <?php foreach ($orders as $order): ?>

            <?php
                $orderId = (int) $order["order_id"];
                $progress = customerDeliveryProgress($order);

                $isCancelled =
                    $order["order_status"] === "cancelled" ||
                    $order["delivery_status"] === "cancelled";

                $isDelivered =
                    $order["order_status"] === "completed" ||
                    $order["delivery_status"] === "delivered";
            ?>

            <article class="order-card">

                <div class="order-header">
                    <div>
                        <h2>Order #<?= $orderId ?></h2>

                        <p class="order-date">
                            Placed on
                            <?= customerOrderEscape($order["created_at"]) ?>
                        </p>
                    </div>

                    <strong>
                        <?= number_format((float) $order["total_amount"], 0) ?>
                        FCFA
                    </strong>
                </div>

                <div class="order-content">

                    <div class="status-row">

                        <span class="status-badge
                            <?= $isCancelled ? 'cancelled' : '' ?>
                            <?= $isDelivered ? 'delivered' : '' ?>">

                            Order:
                            <?= customerOrderEscape(
                                customerOrderStatus($order["order_status"])
                            ) ?>

                        </span>

                        <span class="status-badge
                            <?= $isCancelled ? 'cancelled' : '' ?>
                            <?= $isDelivered ? 'delivered' : '' ?>">

                            Delivery:
                            <?= customerOrderEscape(
                                customerDeliveryLabel($order)
                            ) ?>

                        </span>

                    </div>

                    <section class="tracking">

                        <h3>Delivery Progress</h3>

                        <?php if ($isCancelled): ?>

                            <div class="progress-track cancelled-track">
                                This order has been cancelled.
                            </div>

                        <?php else: ?>

                            <div class="progress-track">

                                <?php
                                $steps = [
                                    1 => "Confirmed",
                                    2 => "Ready",
                                    3 => "Shipped",
                                    4 => "Delivered"
                                ];
                                ?>

                                <?php foreach ($steps as $step => $label): ?>

                                    <div class="progress-step
                                        <?= $progress >= $step ? 'active' : '' ?>">

                                        <div class="progress-circle">
                                            <?= $progress >= $step ? '&#10003;' : $step ?>
                                        </div>

                                        <span>
                                            <?= customerOrderEscape($label) ?>
                                        </span>

                                    </div>

                                <?php endforeach; ?>

                            </div>

                            <?php if ($progress === 0): ?>
                                <p style="margin-top:15px;color:#647067;">
                                    Your order is awaiting confirmation from the seller.
                                </p>
                            <?php elseif ($progress === 4): ?>
                                <p style="margin-top:15px;color:#166534;">
                                    Your order has been delivered successfully.
                                </p>
                            <?php endif; ?>

                        <?php endif; ?>

                    </section>

                    <h3 class="section-title">Products Ordered</h3>

                    <div class="table-wrapper">
                        <table>
                            <thead>
                                <tr>
                                    <th>Product</th>
                                    <th>Unit Price</th>
                                    <th>Quantity</th>
                                    <th>Subtotal</th>
                                </tr>
                            </thead>

                            <tbody>

                                <?php if (!empty($orderItems[$orderId])): ?>

                                    <?php foreach ($orderItems[$orderId] as $item): ?>

                                        <tr>
                                            <td>
                                                <?= customerOrderEscape(
                                                    $item["product_name"]
                                                ) ?>
                                            </td>

                                            <td>
                                                <?= number_format(
                                                    (float) $item["unit_price"],
                                                    0
                                                ) ?> FCFA
                                            </td>

                                            <td>
                                                <?= (int) $item["quantity"] ?>
                                            </td>

                                            <td>
                                                <?= number_format(
                                                    (float) $item["line_total"],
                                                    0
                                                ) ?> FCFA
                                            </td>
                                        </tr>

                                    <?php endforeach; ?>

                                <?php else: ?>

                                    <tr>
                                        <td colspan="4">
                                            No product details are available for this order.
                                        </td>
                                    </tr>

                                <?php endif; ?>

                            </tbody>
                        </table>
                    </div>

                    <div class="order-details">

                        <div class="detail-box">
                            <h3>Delivery Address</h3>

                            <p>
                                <?= nl2br(customerOrderEscape(
                                    $order["delivery_address"]
                                )) ?>
                            </p>
                        </div>

                        <div class="detail-box">
                            <h3>Order Information</h3>

                            <p>
                                <strong>Order number:</strong>
                                #<?= $orderId ?>
                            </p>

                            <p>
                                <strong>Seller ID:</strong>
                                <?= (int) $order["seller_id"] ?>
                            </p>

                            <p>
                                <strong>Order date:</strong>
                                <?= customerOrderEscape($order["created_at"]) ?>
                            </p>
                        </div>

                    </div>

                    <div class="total-row">
                        <span>Order Total</span>

                        <span>
                            <?= number_format(
                                (float) $order["total_amount"],
                                0
                            ) ?> FCFA
                        </span>
                    </div>

                </div>

            </article>

        <?php endforeach; ?>

    <?php endif; ?>

</main>

<footer>
    <p>&copy; <?= date("Y") ?> SolarGo. Powering a brighter future.</p>
</footer>

</body>
</html>