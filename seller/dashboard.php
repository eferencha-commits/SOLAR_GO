
<?php

session_start();

require_once __DIR__ . "/../config/database.php";

// 1. Check whether the user is logged in
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

// 2. Only sellers can access this dashboard
if (($_SESSION["role"] ?? "") !== "seller") {
    http_response_code(403);
    exit("Access denied. This page is for sellers only.");
}

// 3. Retrieve the seller's current information from the database
$seller_id = (int) $_SESSION["user_id"];

$user_sql = "
    SELECT full_name, role, account_status
    FROM users
    WHERE user_id = ?
    LIMIT 1
";

$user_stmt = $conn->prepare($user_sql);

if (!$user_stmt) {
    error_log("SolarGo seller account query preparation failed: " . $conn->error);
    http_response_code(500);
    exit("Something went wrong. Please try again later.");
}

$user_stmt->bind_param("i", $seller_id);

if (!$user_stmt->execute()) {
    error_log("SolarGo seller account query execution failed: " . $user_stmt->error);
    $user_stmt->close();

    http_response_code(500);
    exit("Something went wrong. Please try again later.");
}

$user_result = $user_stmt->get_result();
$seller = $user_result->fetch_assoc();

$user_stmt->close();

// 4. Reject accounts that no longer exist
if (!$seller) {
    $_SESSION = [];
    session_destroy();

    header("Location: ../auth/login.php");
    exit;
}

// 5. Verify the role using the database
if ($seller["role"] !== "seller") {
    http_response_code(403);
    exit("Access denied. Your account does not have seller permissions.");
}

// 6. Prevent inactive sellers from accessing the dashboard
if ($seller["account_status"] !== "active") {
    $_SESSION = [];
    session_destroy();

    header("Location: ../auth/login.php");
    exit;
}

// 7. Use the seller's current name from the database
$seller_name = $seller["full_name"];

$_SESSION["full_name"] = $seller_name;

// 8. Retrieve products belonging to this seller
$sql = "
    SELECT
        product_id,
        product_name,
        category,
        price,
        stock_quantity,
        listing_status,
        created_at
    FROM products
    WHERE seller_id = ?
    ORDER BY created_at DESC
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log("SolarGo seller products query preparation failed: " . $conn->error);
    http_response_code(500);
    exit("Unable to load your products. Please try again later.");
}

$stmt->bind_param("i", $seller_id);

if (!$stmt->execute()) {
    error_log("SolarGo seller products query execution failed: " . $stmt->error);
    $stmt->close();

    http_response_code(500);
    exit("Unable to load your products. Please try again later.");
}

$result = $stmt->get_result();

// 9. Prepare product statistics
$products = [];
$pending_products = 0;
$approved_products = 0;
$rejected_products = 0;

while ($row = $result->fetch_assoc()) {
    $products[] = $row;

    if ($row["listing_status"] === "pending") {
        $pending_products++;
    } elseif ($row["listing_status"] === "approved") {
        $approved_products++;
    } elseif ($row["listing_status"] === "rejected") {
        $rejected_products++;
    }
}

$total_products = count($products);

$stmt->close();

// 10. Escape output before displaying it in HTML
function escapeHTML($value)
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Seller Dashboard | SolarGo</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f7f5;
            color: #24332b;
        }

        header {
            background: #123c2a;
            color: white;
            padding: 20px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .logo {
            font-size: 25px;
            font-weight: bold;
        }

        .logo span {
            color: #f4c542;
        }

        nav {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
            align-items: center;
        }

        nav a {
            color: white;
            text-decoration: none;
            font-size: 14px;
        }

        nav a:hover {
            color: #f4c542;
        }

        .container {
            width: 90%;
            max-width: 1200px;
            margin: 35px auto;
        }

        .welcome {
            margin-bottom: 25px;
        }

        .welcome h1 {
            font-size: 30px;
            margin-bottom: 10px;
        }

        .welcome p {
            color: #647269;
            line-height: 1.6;
        }

        .notice {
            background: #e5f5e9;
            color: #176534;
            border: 1px solid #b8e4c4;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .cards {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 20px;
            margin-bottom: 35px;
        }

        .card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
            border-left: 4px solid #16834a;
        }

        .card h3 {
            color: #66736b;
            font-size: 14px;
            margin-bottom: 15px;
        }

        .card p {
            font-size: 30px;
            font-weight: bold;
            color: #123c2a;
        }

        .section-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }

        .section-heading h2 {
            font-size: 23px;
        }

        .btn {
            display: inline-block;
            background: #16834a;
            color: white;
            text-decoration: none;
            padding: 12px 18px;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 14px;
        }

        .btn:hover {
            background: #116839;
        }

        .table-container {
            background: white;
            border-radius: 10px;
            overflow-x: auto;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.05);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 750px;
        }

        th,
        td {
            padding: 16px;
            text-align: left;
            border-bottom: 1px solid #e9eee9;
        }

        th {
            background: #eaf2ec;
            color: #244a34;
            font-size: 13px;
        }

        td {
            font-size: 14px;
        }

        .status {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .pending {
            background: #fff0c2;
            color: #815900;
        }

        .approved {
            background: #d7f5df;
            color: #176534;
        }

        .rejected {
            background: #ffe0e0;
            color: #a12525;
        }

        .empty {
            text-align: center;
            padding: 45px 20px;
            background: white;
            border-radius: 10px;
        }

        .empty h3 {
            margin-bottom: 10px;
        }

        .empty p {
            color: #66736b;
            margin-bottom: 20px;
            line-height: 1.6;
        }

        footer {
            text-align: center;
            padding: 25px;
            color: #66736b;
            margin-top: 40px;
        }

        @media (max-width: 900px) {
            .cards {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 500px) {
            header {
                padding: 18px 5%;
            }

            .cards {
                grid-template-columns: 1fr;
            }

            .welcome h1 {
                font-size: 24px;
            }

            .section-heading h2 {
                font-size: 20px;
            }
        }
    </style>
</head>

<body>

<header>

    <div class="logo">
        Solar<span>Go</span>
    </div>

    <nav>
        <a href="../index.php">Home</a>
        <a href="../products.php">Marketplace</a>
        <a href="dashboard.php">Dashboard</a>
        <a href="add-product.php">Add Product</a>
        <a href="../auth/logout.php">Logout</a>
        <a href="orders.php">Customer Orders</a>
    </nav>

</header>

<main class="container">

    <section class="welcome">

        <h1>
            Welcome, <?= escapeHTML($seller_name) ?>!
        </h1>

        <p>
            Manage your solar products, monitor their approval status,
            and keep your SolarGo marketplace listings up to date.
        </p>

    </section>

    <?php if (isset($_GET["submitted"]) && $_GET["submitted"] === "1"): ?>

        <div class="notice" role="status">
            Your product was submitted successfully.
            It will appear in the public marketplace after approval.
        </div>

    <?php endif; ?>

    <section class="cards">

        <div class="card">
            <h3>Total Products</h3>
            <p><?= $total_products ?></p>
        </div>

        <div class="card">
            <h3>Approved Products</h3>
            <p><?= $approved_products ?></p>
        </div>

        <div class="card">
            <h3>Pending Approval</h3>
            <p><?= $pending_products ?></p>
        </div>

        <div class="card">
            <h3>Rejected Products</h3>
            <p><?= $rejected_products ?></p>
        </div>

    </section>

    <section>

        <div class="section-heading">

            <h2>My Products</h2>

            <a href="add-product.php" class="btn">
                + Add New Product
            </a>

        </div>

        <?php if (!empty($products)): ?>

            <div class="table-container">

                <table>

                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Category</th>
                            <th>Price (FCFA)</th>
                            <th>Stock</th>
                            <th>Status</th>
                            <th>Date Added</th>
                        </tr>
                    </thead>

                    <tbody>

                        <?php foreach ($products as $product): ?>

                            <tr>

                                <td>
                                    <?= escapeHTML($product["product_name"]) ?>
                                </td>

                                <td>
                                    <?= escapeHTML($product["category"]) ?>
                                </td>

                                <td>
                                    <?= number_format(
                                        (float) $product["price"],
                                        0,
                                        ".",
                                        ","
                                    ) ?>
                                </td>

                                <td>
                                    <?= (int) $product["stock_quantity"] ?>
                                </td>

                                <td>

                                    <?php
                                    $status = $product["listing_status"];

                                    $allowed_statuses = [
                                        "pending",
                                        "approved",
                                        "rejected"
                                    ];

                                    if (!in_array($status, $allowed_statuses, true)) {
                                        $status = "pending";
                                    }
                                    ?>

                                    <span class="status <?= escapeHTML($status) ?>">
                                        <?= escapeHTML($status) ?>
                                    </span>

                                </td>

                                <td>
                                    <?= escapeHTML($product["created_at"]) ?>
                                </td>

                            </tr>

                        <?php endforeach; ?>

                    </tbody>

                </table>

            </div>

        <?php else: ?>

            <div class="empty">

                <h3>You have not added any products yet.</h3>

                <p>
                    Start selling solar panels, batteries, inverters,
                    and other solar equipment on SolarGo.
                </p>

                <a href="add-product.php" class="btn">
                    Add Your First Product
                </a>

            </div>

        <?php endif; ?>

    </section>

</main>

<footer>
    &copy; <?= date("Y") ?> SolarGo.
    Power That Does Not Cut Out.
</footer>

</body>
</html>