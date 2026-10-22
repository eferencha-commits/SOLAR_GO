<?php
session_start();

require_once __DIR__ . "/../config/database.php";

// ========================================
// 1. SECURITY: ADMIN ACCESS ONLY
// ========================================

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../auth/login.php");
    exit;
}

// ========================================
// 2. DATE FILTERS
// ========================================

$today = date("Y-m-d");
$defaultStart = date("Y-m-01");

$startDate = $_GET["start_date"] ?? $defaultStart;
$endDate = $_GET["end_date"] ?? $today;

$datePattern = '/^\d{4}-\d{2}-\d{2}$/';

if (
    !preg_match($datePattern, $startDate) ||
    !preg_match($datePattern, $endDate) ||
    !strtotime($startDate) ||
    !strtotime($endDate) ||
    $startDate > $endDate
) {
    $startDate = $defaultStart;
    $endDate = $today;
}

// Include the complete ending day in date comparisons.
$startDateTime = $startDate . " 00:00:00";
$endDateTime = date(
    "Y-m-d H:i:s",
    strtotime($endDate . " +1 day")
);

// ========================================
// 3. GENERAL STATISTICS
// ========================================

$stats = [
    "total_sales" => 0,
    "total_orders" => 0,
    "completed_orders" => 0,
    "pending_orders" => 0,
    "confirmed_orders" => 0,
    "cancelled_orders" => 0
];

$sql = "
    SELECT
        COALESCE(
            SUM(
                CASE
                    WHEN order_status = 'completed'
                    THEN total_amount
                    ELSE 0
                END
            ),
            0
        ) AS total_sales,

        COUNT(*) AS total_orders,

        SUM(
            CASE WHEN order_status = 'completed'
            THEN 1 ELSE 0 END
        ) AS completed_orders,

        SUM(
            CASE WHEN order_status = 'pending'
            THEN 1 ELSE 0 END
        ) AS pending_orders,

        SUM(
            CASE WHEN order_status = 'confirmed'
            THEN 1 ELSE 0 END
        ) AS confirmed_orders,

        SUM(
            CASE WHEN order_status = 'cancelled'
            THEN 1 ELSE 0 END
        ) AS cancelled_orders

    FROM orders
    WHERE created_at >= ?
      AND created_at < ?
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    die("Could not prepare the statistics query. Check your database structure.");
}

$stmt->bind_param("ss", $startDateTime, $endDateTime);
$stmt->execute();

$result = $stmt->get_result();

if ($row = $result->fetch_assoc()) {
    $stats = array_merge($stats, $row);
}

$stmt->close();

// ========================================
// 4. DAILY SALES DATA
// ========================================

$dailySales = [];

$sql = "
    SELECT
        DATE(created_at) AS sale_date,
        COUNT(*) AS order_count,
        SUM(total_amount) AS daily_sales

    FROM orders

    WHERE order_status = 'completed'
      AND created_at >= ?
      AND created_at < ?

    GROUP BY DATE(created_at)
    ORDER BY sale_date ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $startDateTime, $endDateTime);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $dailySales[$row["sale_date"]] = [
        "orders" => (int) $row["order_count"],
        "sales" => (float) $row["daily_sales"]
    ];
}

$stmt->close();

// Fill in dates with zero sales.
$chartLabels = [];
$chartSales = [];
$chartOrders = [];

$currentDate = new DateTime($startDate);
$lastDate = new DateTime($endDate);
$lastDate->modify("+1 day");

while ($currentDate < $lastDate) {
    $date = $currentDate->format("Y-m-d");

    $chartLabels[] = $date;
    $chartSales[] = $dailySales[$date]["sales"] ?? 0;
    $chartOrders[] = $dailySales[$date]["orders"] ?? 0;

    $currentDate->modify("+1 day");
}

// ========================================
// 5. ORDER STATUS BREAKDOWN
// ========================================

$statusLabels = [];
$statusCounts = [];

$sql = "
    SELECT order_status, COUNT(*) AS total
    FROM orders
    WHERE created_at >= ?
      AND created_at < ?
    GROUP BY order_status
    ORDER BY total DESC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $startDateTime, $endDateTime);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $statusLabels[] = ucfirst(
        str_replace("_", " ", $row["order_status"])
    );

    $statusCounts[] = (int) $row["total"];
}

$stmt->close();

// ========================================
// 6. TOP-SELLING PRODUCTS
// ========================================

$topProducts = [];

$sql = "
    SELECT
        oi.product_name,
        SUM(oi.quantity) AS quantity_sold,
        SUM(oi.line_total) AS revenue

    FROM order_items oi

    INNER JOIN orders o
        ON oi.order_id = o.order_id

    WHERE o.order_status = 'completed'
      AND o.created_at >= ?
      AND o.created_at < ?

    GROUP BY oi.product_id, oi.product_name

    ORDER BY quantity_sold DESC
    LIMIT 10
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $startDateTime, $endDateTime);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $topProducts[] = $row;
}

$stmt->close();

// ========================================
// 7. RECENT ORDERS
// ========================================

$recentOrders = [];

$sql = "
    SELECT
        o.order_id,
        o.total_amount,
        o.order_status,
        o.created_at,

        customer.full_name AS customer_name,
        seller.full_name AS seller_name

    FROM orders o

    LEFT JOIN users customer
        ON o.customer_id = customer.user_id

    LEFT JOIN users seller
        ON o.seller_id = seller.user_id

    WHERE o.created_at >= ?
      AND o.created_at < ?

    ORDER BY o.created_at DESC
    LIMIT 15
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ss", $startDateTime, $endDateTime);
$stmt->execute();

$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $recentOrders[] = $row;
}

$stmt->close();

// ========================================
// 8. CSV EXPORT
// ========================================

if (isset($_GET["export"]) && $_GET["export"] === "csv") {

    header("Content-Type: text/csv; charset=UTF-8");

    header(
        'Content-Disposition: attachment; filename="solargo-report-' .
        $startDate . '-to-' . $endDate . '.csv"'
    );

    $output = fopen("php://output", "w");

    // UTF-8 BOM helps spreadsheet programs display text correctly.
    fwrite($output, "\xEF\xBB\xBF");

    fputcsv($output, ["SOLARGO SALES REPORT"]);
    fputcsv($output, ["Start Date", $startDate]);
    fputcsv($output, ["End Date", $endDate]);
    fputcsv($output, []);

    fputcsv($output, ["SUMMARY"]);
    fputcsv($output, ["Total Completed Sales (FCFA)", $stats["total_sales"]]);
    fputcsv($output, ["Total Orders", $stats["total_orders"]]);
    fputcsv($output, ["Completed Orders", $stats["completed_orders"]]);
    fputcsv($output, ["Pending Orders", $stats["pending_orders"]]);
    fputcsv($output, ["Confirmed Orders", $stats["confirmed_orders"]]);
    fputcsv($output, ["Cancelled Orders", $stats["cancelled_orders"]]);

    fputcsv($output, []);
    fputcsv($output, ["DAILY SALES"]);
    fputcsv($output, ["Date", "Completed Orders", "Sales (FCFA)"]);

    foreach ($chartLabels as $index => $date) {
        fputcsv($output, [
            $date,
            $chartOrders[$index],
            $chartSales[$index]
        ]);
    }

    fputcsv($output, []);
    fputcsv($output, ["TOP-SELLING PRODUCTS"]);
    fputcsv($output, ["Product", "Quantity Sold", "Revenue (FCFA)"]);

    foreach ($topProducts as $product) {
        fputcsv($output, [
            $product["product_name"],
            $product["quantity_sold"],
            $product["revenue"]
        ]);
    }

    fclose($output);
    exit;
}

// ========================================
// 9. HELPER FUNCTIONS
// ========================================

function escape($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

function money($amount): string
{
    return number_format((float) $amount, 0) . " FCFA";
}

function statusClass($status): string
{
    return strtolower(
        preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $status)
    );
}

$chartLabelsJson = json_encode(
    $chartLabels,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);

$chartSalesJson = json_encode($chartSales);
$chartOrdersJson = json_encode($chartOrders);

$statusLabelsJson = json_encode(
    $statusLabels,
    JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
);

$statusCountsJson = json_encode($statusCounts);

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>SolarGo Admin Reports</title>

    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f3f6f9;
            color: #253047;
        }

        header {
            background: #12372a;
            color: white;
            padding: 22px 5%;
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
            margin-left: 15px;
        }

        .container {
            max-width: 1450px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .page-heading {
            margin-bottom: 22px;
        }

        .page-heading h2 {
            margin-bottom: 8px;
        }

        .page-heading p {
            color: #64748b;
        }

        .filter-box {
            background: white;
            padding: 22px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
        }

        .filter-form {
            display: flex;
            align-items: end;
            gap: 15px;
            flex-wrap: wrap;
        }

        .form-group {
            display: flex;
            flex-direction: column;
            gap: 7px;
        }

        label {
            font-size: 14px;
            font-weight: bold;
        }

        input {
            padding: 11px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
        }

        button,
        .button {
            padding: 12px 17px;
            border: none;
            border-radius: 6px;
            background: #15803d;
            color: white;
            text-decoration: none;
            cursor: pointer;
            font-weight: bold;
            display: inline-block;
        }

        button:hover,
        .button:hover {
            background: #166534;
        }

        .export-button {
            background: #1d4ed8;
        }

        .export-button:hover {
            background: #1e40af;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 25px;
        }

        .stat-card {
            background: white;
            padding: 23px;
            border-radius: 12px;
            border-left: 5px solid #15803d;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
        }

        .stat-card h3 {
            color: #64748b;
            font-size: 14px;
            margin-bottom: 12px;
        }

        .stat-card .number {
            font-size: 25px;
            font-weight: bold;
            overflow-wrap: anywhere;
        }

        .sales-card {
            border-left-color: #0d9488;
        }

        .pending-card {
            border-left-color: #d97706;
        }

        .completed-card {
            border-left-color: #16a34a;
        }

        .cancelled-card {
            border-left-color: #dc2626;
        }

        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 25px;
        }

        .panel {
            background: white;
            border-radius: 12px;
            padding: 23px;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
            min-width: 0;
        }

        .panel h2 {
            font-size: 19px;
            margin-bottom: 20px;
        }

        .chart-container {
            position: relative;
            width: 100%;
            height: 320px;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 650px;
        }

        th,
        td {
            padding: 14px 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            font-size: 14px;
        }

        th {
            background: #f8fafc;
            color: #475569;
        }

        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            background: #e2e8f0;
        }

        .status-completed {
            background: #dcfce7;
            color: #166534;
        }

        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }

        .status-confirmed {
            background: #dbeafe;
            color: #1d4ed8;
        }

        .status-cancelled {
            background: #fee2e2;
            color: #b91c1c;
        }

        .empty {
            text-align: center;
            color: #64748b;
            padding: 25px;
        }

        footer {
            text-align: center;
            padding: 25px;
            color: #64748b;
        }

        @media (max-width: 900px) {
            .charts-grid {
                grid-template-columns: 1fr;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 550px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            header {
                align-items: flex-start;
            }

            header a {
                margin-left: 0;
                margin-right: 12px;
            }

            .container {
                padding: 0 12px;
            }

            .chart-container {
                height: 270px;
            }
        }
    </style>
</head>

<body>

<header>
    <h1>☀ SolarGo Admin</h1>

    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="users.php">Users</a>
        <a href="orders.php">Orders</a>
        <a href="reports.php">Reports</a>
    </nav>
</header>

<main class="container">

    <section class="page-heading">
        <h2>Sales & Performance Reports</h2>

        <p>
            Monitor SolarGo sales, orders and top-selling products.
        </p>
    </section>

    <!-- DATE FILTER -->

    <section class="filter-box">

        <form method="GET" action="reports.php" class="filter-form">

            <div class="form-group">
                <label for="start_date">Start Date</label>

                <input
                    type="date"
                    id="start_date"
                    name="start_date"
                    value="<?= escape($startDate) ?>"
                    required
                >
            </div>

            <div class="form-group">
                <label for="end_date">End Date</label>

                <input
                    type="date"
                    id="end_date"
                    name="end_date"
                    value="<?= escape($endDate) ?>"
                    required
                >
            </div>

            <button type="submit">
                Apply Filters
            </button>

            <a
                class="button export-button"
                href="reports.php?start_date=<?= escape($startDate) ?>&end_date=<?= escape($endDate) ?>&export=csv"
            >
                Download CSV
            </a>

        </form>

    </section>

    <!-- STATISTICS CARDS -->

    <section class="stats-grid">

        <div class="stat-card sales-card">
            <h3>Total Completed Sales</h3>

            <div class="number">
                <?= escape(money($stats["total_sales"])) ?>
            </div>
        </div>

        <div class="stat-card">
            <h3>Total Orders</h3>

            <div class="number">
                <?= number_format((int) $stats["total_orders"]) ?>
            </div>
        </div>

        <div class="stat-card completed-card">
            <h3>Completed Orders</h3>

            <div class="number">
                <?= number_format((int) $stats["completed_orders"]) ?>
            </div>
        </div>

        <div class="stat-card pending-card">
            <h3>Pending Orders</h3>

            <div class="number">
                <?= number_format((int) $stats["pending_orders"]) ?>
            </div>
        </div>

        <div class="stat-card">
            <h3>Confirmed Orders</h3>

            <div class="number">
                <?= number_format((int) $stats["confirmed_orders"]) ?>
            </div>
        </div>

        <div class="stat-card cancelled-card">
            <h3>Cancelled Orders</h3>

            <div class="number">
                <?= number_format((int) $stats["cancelled_orders"]) ?>
            </div>
        </div>

    </section>

    <!-- CHARTS -->

    <section class="charts-grid">

        <div class="panel">

            <h2>Daily Sales Performance</h2>

            <div class="chart-container">
                <canvas id="salesChart"></canvas>
            </div>

        </div>

        <div class="panel">

            <h2>Order Status Breakdown</h2>

            <div class="chart-container">
                <canvas id="statusChart"></canvas>
            </div>

        </div>

    </section>

    <!-- DAILY SALES TABLE -->

    <section class="panel">

        <h2>Daily Sales Summary</h2>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Completed Orders</th>
                        <th>Total Sales</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($chartLabels)): ?>

                        <tr>
                            <td colspan="3" class="empty">
                                No daily sales data available.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($chartLabels as $index => $date): ?>

                            <tr>
                                <td><?= escape($date) ?></td>

                                <td>
                                    <?= number_format($chartOrders[$index]) ?>
                                </td>

                                <td>
                                    <?= escape(money($chartSales[$index])) ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <!-- TOP PRODUCTS -->

    <section class="panel">

        <h2>Top-Selling Products</h2>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Quantity Sold</th>
                        <th>Revenue</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($topProducts)): ?>

                        <tr>
                            <td colspan="3" class="empty">
                                No completed product sales for this period.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($topProducts as $product): ?>

                            <tr>
                                <td>
                                    <?= escape($product["product_name"]) ?>
                                </td>

                                <td>
                                    <?= number_format((int) $product["quantity_sold"]) ?>
                                </td>

                                <td>
                                    <?= escape(money($product["revenue"])) ?>
                                </td>
                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

    <!-- RECENT ORDERS -->

    <section class="panel">

        <h2>Recent Orders</h2>

        <div class="table-wrapper">

            <table>

                <thead>
                    <tr>
                        <th>Order ID</th>
                        <th>Customer</th>
                        <th>Seller</th>
                        <th>Amount</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>

                <tbody>

                    <?php if (empty($recentOrders)): ?>

                        <tr>
                            <td colspan="6" class="empty">
                                No orders found for the selected period.
                            </td>
                        </tr>

                    <?php else: ?>

                        <?php foreach ($recentOrders as $order): ?>

                            <?php
                            $status = strtolower(
                                (string) $order["order_status"]
                            );

                            $class = statusClass($status);
                            ?>

                            <tr>

                                <td>
                                    #<?= (int) $order["order_id"] ?>
                                </td>

                                <td>
                                    <?= escape($order["customer_name"] ?? "Unknown") ?>
                                </td>

                                <td>
                                    <?= escape($order["seller_name"] ?? "Unknown") ?>
                                </td>

                                <td>
                                    <?= escape(money($order["total_amount"])) ?>
                                </td>

                                <td>
                                    <span class="status status-<?= escape($class) ?>">
                                        <?= escape(ucfirst($status)) ?>
                                    </span>
                                </td>

                                <td>
                                    <?= escape($order["created_at"]) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

<footer>
    &copy; <?= date("Y") ?> SolarGo Admin Reports.
    All rights reserved.
</footer>

<script>
    const dailyLabels = <?= $chartLabelsJson ?: "[]" ?>;
    const dailySales = <?= $chartSalesJson ?: "[]" ?>;
    const dailyOrders = <?= $chartOrdersJson ?: "[]" ?>;

    const statusLabels = <?= $statusLabelsJson ?: "[]" ?>;
    const statusCounts = <?= $statusCountsJson ?: "[]" ?>;

    // DAILY SALES CHART

    new Chart(document.getElementById("salesChart"), {
        type: "line",

        data: {
            labels: dailyLabels,

            datasets: [{
                label: "Sales (FCFA)",
                data: dailySales,
                borderWidth: 3,
                tension: 0.3,
                fill: false
            }]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,

            interaction: {
                intersect: false,
                mode: "index"
            },

            plugins: {
                legend: {
                    display: true
                }
            },

            scales: {
                y: {
                    beginAtZero: true,

                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString() + " FCFA";
                        }
                    }
                }
            }
        }
    });

    // ORDER STATUS CHART

    new Chart(document.getElementById("statusChart"), {
        type: "doughnut",

        data: {
            labels: statusLabels,

            datasets: [{
                label: "Orders",
                data: statusCounts,
                borderWidth: 2
            }]
        },

        options: {
            responsive: true,
            maintainAspectRatio: false,

            plugins: {
                legend: {
                    position: "bottom"
                }
            }
        }
    });
</script>

</body>
</html>