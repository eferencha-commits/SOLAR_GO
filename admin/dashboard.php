
<?php
session_start();

require_once __DIR__ . "/../config/database.php";

/*
|--------------------------------------------------------------------------
| 1. ADMIN AUTHENTICATION
|--------------------------------------------------------------------------
*/

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../auth/login.php");
    exit;
}

/*
|--------------------------------------------------------------------------
| 2. CSRF TOKEN
|--------------------------------------------------------------------------
*/

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

/*
|--------------------------------------------------------------------------
| 3. HELPER FUNCTION
|--------------------------------------------------------------------------
*/

function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

$adminName = $_SESSION["full_name"] ?? "Administrator";
$databaseError = "";

/*
|--------------------------------------------------------------------------
| 4. DASHBOARD STATISTICS
|--------------------------------------------------------------------------
*/

$totalUsers = 0;
$totalSellers = 0;
$pendingSellers = 0;
$totalProducts = 0;
$pendingProducts = 0;

$statistics = [
    "total_users" => 0,
    "total_sellers" => 0,
    "pending_sellers" => 0,
    "total_products" => 0,
    "pending_products" => 0
];

$statsQuery = "
    SELECT
        (SELECT COUNT(*) FROM users)
            AS total_users,

        (SELECT COUNT(*)
         FROM users
         WHERE role = 'seller')
            AS total_sellers,

        (SELECT COUNT(*)
         FROM users
         WHERE role = 'seller'
           AND account_status = 'pending')
            AS pending_sellers,

        (SELECT COUNT(*) FROM products)
            AS total_products,

        (SELECT COUNT(*)
         FROM products
         WHERE listing_status = 'pending')
            AS pending_products
";

$statsResult = $conn->query($statsQuery);

if ($statsResult) {
    $statistics = $statsResult->fetch_assoc();
    $statsResult->free();
} else {
    error_log("Admin statistics error: " . $conn->error);
    $databaseError = "Some dashboard statistics could not be loaded.";
}

$totalUsers = (int) $statistics["total_users"];
$totalSellers = (int) $statistics["total_sellers"];
$pendingSellers = (int) $statistics["pending_sellers"];
$totalProducts = (int) $statistics["total_products"];
$pendingProducts = (int) $statistics["pending_products"];

/*
|--------------------------------------------------------------------------
| 5. GET PENDING SELLER APPLICATIONS
|--------------------------------------------------------------------------
*/

$sellers = [];

$sellerQuery = "
    SELECT
        user_id,
        full_name,
        email,
        phone,
        account_status,
        created_at
    FROM users
    WHERE role = 'seller'
      AND account_status = 'pending'
    ORDER BY created_at DESC
";

$sellerResult = $conn->query($sellerQuery);

if ($sellerResult) {
    while ($row = $sellerResult->fetch_assoc()) {
        $sellers[] = $row;
    }

    $sellerResult->free();
} else {
    error_log("Pending sellers query error: " . $conn->error);
    $databaseError = "Pending seller applications could not be loaded.";
}

/*
|--------------------------------------------------------------------------
| 6. GET PENDING PRODUCT LISTINGS
|--------------------------------------------------------------------------
*/

$products = [];
$productQuery = "
    SELECT
        p.product_id,
        p.product_name,
        p.category,
        p.description,
        p.price,
        p.stock_quantity,
        p.image_url,
        p.seller_id,
        p.created_at,
        u.full_name AS seller_name
    FROM products p
    LEFT JOIN users u ON p.seller_id = u.user_id
    WHERE p.listing_status = 'pending'
    ORDER BY p.created_at DESC
";

$productResult = $conn->query($productQuery);

if ($productResult) {
    while ($row = $productResult->fetch_assoc()) {
        $products[] = $row;
    }
    $productResult->free();
} else {
    error_log("Pending products query error: " . $conn->error);
    $databaseError = "Pending product listings could not be loaded.";
}

/*
|--------------------------------------------------------------------------
| 7. SUCCESS AND ERROR MESSAGES
|--------------------------------------------------------------------------
*/

$successMessages = [
    "seller_approved" => "Seller approved successfully!",
    "seller_rejected" => "Seller application rejected.",
    "product_approved" => "Product approved successfully!",
    "product_rejected" => "Product listing rejected."
];

$requestErrors = [
    "invalid_request" => "Invalid seller request.",
    "database" => "A database error occurred. Please try again.",
    "not_pending" => "This seller is no longer awaiting approval.",
    "invalid_product_request" => "Invalid product approval request.",
    "product_database" => "A database error occurred while processing the product.",
    "product_not_pending" => "This product is no longer awaiting review."
];

$messageKey = $_GET["message"] ?? "";
$errorKey = $_GET["error"] ?? "";

$successMessage = $successMessages[$messageKey] ?? "";
$requestError = $requestErrors[$errorKey] ?? "";

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Admin Dashboard | SolarGo</title>

    <style>
        :root {
            --primary: #15803d;
            --primary-dark: #166534;
            --primary-light: #dcfce7;
            --background: #f4f7f5;
            --white: #ffffff;
            --text: #1f2937;
            --muted: #6b7280;
            --border: #e5e7eb;
            --danger: #dc2626;
            --danger-light: #fee2e2;
            --warning: #b45309;
            --warning-light: #fef3c7;
            --success: #166534;
            --success-light: #dcfce7;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: var(--background);
            color: var(--text);
            line-height: 1.6;
        }

        a {
            text-decoration: none;
        }

        button {
            font-family: inherit;
        }

        /* NAVIGATION */

        .navbar {
            background: var(--white);
            padding: 16px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            border-bottom: 1px solid var(--border);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.03);
        }

        .logo {
            color: var(--primary);
            font-size: 27px;
            font-weight: 800;
            letter-spacing: -1px;
        }

        .logo span {
            color: #f59e0b;
        }

        .nav-right {
            display: flex;
            align-items: center;
            gap: 18px;
        }

        .admin-name {
            color: var(--text);
            font-weight: 600;
        }

        .logout-btn {
            display: inline-block;
            background: var(--danger);
            color: var(--white);
            padding: 9px 15px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 14px;
        }

        .logout-btn:hover {
            background: #b91c1c;
        }

        /* MAIN CONTENT */

        .container {
            width: 90%;
            max-width: 1400px;
            margin: 30px auto;
        }

        .welcome {
            margin-bottom: 25px;
        }

        .welcome h1 {
            font-size: 30px;
            margin-bottom: 6px;
        }

        .welcome p {
            color: var(--muted);
        }

        /* MESSAGES */

        .message {
            padding: 14px 17px;
            margin-bottom: 20px;
            border-radius: 7px;
            font-weight: 600;
        }

        .message.success {
            background: var(--success-light);
            color: var(--success);
            border: 1px solid #86efac;
        }

        .message.error {
            background: var(--danger-light);
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .message.warning {
            background: var(--warning-light);
            color: var(--warning);
            border: 1px solid #fde68a;
        }

        /* STATISTICS */

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 35px;
        }

        .stat-card {
            background: var(--white);
            padding: 22px;
            border-radius: 12px;
            border: 1px solid var(--border);
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.03);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 6px 18px rgba(0, 0, 0, 0.06);
        }

        .stat-card p {
            color: var(--muted);
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 10px;
        }

        .stat-card h2 {
            font-size: 32px;
            line-height: 1.2;
            color: var(--text);
        }

        .stat-card.pending {
            border-top: 4px solid #f59e0b;
        }

        .stat-card.green {
            border-top: 4px solid var(--primary);
        }

        .stat-card.blue {
            border-top: 4px solid #2563eb;
        }

        /* SECTIONS */

        .section {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 12px;
            margin-bottom: 30px;
            overflow: hidden;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.03);
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            padding: 22px 24px;
            border-bottom: 1px solid var(--border);
        }

        .section-header h2 {
            font-size: 20px;
        }

        .section-header p {
            color: var(--muted);
            font-size: 13px;
            margin-top: 4px;
        }

        .count-badge {
            background: var(--warning-light);
            color: var(--warning);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            font-weight: 700;
            white-space: nowrap;
        }

        /* TABLE */

        .table-wrapper {
            width: 100%;
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 850px;
        }

        thead {
            background: #f9fafb;
        }

        th,
        td {
            padding: 15px 18px;
            text-align: left;
            border-bottom: 1px solid var(--border);
            font-size: 14px;
        }

        th {
            color: #4b5563;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }

        tbody tr:hover {
            background: #fafafa;
        }

        tbody tr:last-child td {
            border-bottom: none;
        }

        .seller-name {
            font-weight: 700;
            color: var(--text);
        }

        .seller-email {
            color: var(--muted);
        }

        /* STATUS */

        .status {
            display: inline-block;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
        }

        .status.pending {
            color: var(--warning);
            background: var(--warning-light);
        }

        /* ACTION BUTTONS */

        .action-buttons {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .action-buttons form {
            margin: 0;
        }

        .action-buttons button {
            border: none;
            border-radius: 5px;
            padding: 9px 12px;
            font-size: 12px;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.2s;
        }

        .approve-btn {
            background: var(--primary);
            color: var(--white);
        }

        .approve-btn:hover {
            background: var(--primary-dark);
        }

        .reject-btn {
            background: var(--danger-light);
            color: #991b1b;
        }

        .reject-btn:hover {
            background: #fecaca;
        }

        /* EMPTY STATE */

        .empty-state {
            text-align: center;
            padding: 45px 20px;
        }

        .empty-icon {
            font-size: 42px;
            margin-bottom: 12px;
        }

        .empty-state h3 {
            font-size: 19px;
            margin-bottom: 7px;
        }

        .empty-state p {
            color: var(--muted);
            font-size: 14px;
        }

        /* QUICK LINKS */

        .quick-links {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 18px;
            margin-bottom: 30px;
        }

        .quick-link {
            background: var(--white);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 20px;
            color: var(--text);
            transition: border-color 0.2s, transform 0.2s;
        }

        .quick-link:hover {
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .quick-link h3 {
            font-size: 16px;
            margin-bottom: 6px;
        }

        .quick-link p {
            color: var(--muted);
            font-size: 13px;
        }

        /* FOOTER */

        footer {
            text-align: center;
            color: var(--muted);
            padding: 25px 15px;
            font-size: 13px;
        }

        /* RESPONSIVE DESIGN */

        @media (max-width: 1100px) {
            .stats-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }
        }

        @media (max-width: 750px) {
            .container {
                width: 94%;
                margin-top: 22px;
            }

            .navbar {
                padding: 14px 4%;
            }

            .logo {
                font-size: 23px;
            }

            .admin-name {
                display: none;
            }

            .welcome h1 {
                font-size: 25px;
            }

            .stats-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
            }

            .stat-card {
                padding: 17px;
            }

            .stat-card h2 {
                font-size: 27px;
            }

            .quick-links {
                grid-template-columns: 1fr;
            }

            .section-header {
                padding: 18px;
            }
        }

        @media (max-width: 420px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }

            .nav-right {
                gap: 10px;
            }

            .logout-btn {
                padding: 8px 10px;
                font-size: 12px;
            }
        }
    </style>
</head>

<body>

    <!-- NAVIGATION -->
    <nav class="navbar">

        <a href="dashboard.php" class="logo">
            Solar<span>Go</span>
        </a>

        <div class="nav-right">

            <span class="admin-name">
                Welcome, <?= e($adminName) ?>
            </span>

            <a
                href="../auth/logout.php"
                class="logout-btn"
                onclick="return confirm('Are you sure you want to log out?');"
            >
                Logout
            </a>

        </div>

    </nav>


    <main class="container">

        <!-- WELCOME -->
        <section class="welcome">

            <h1>Admin Dashboard</h1>

            <p>
                Manage SolarGo users, review seller applications,
                and monitor products on the platform.
            </p>

        </section>


        <!-- SUCCESS MESSAGE -->
        <?php if ($successMessage !== ""): ?>

            <div class="message success">
                <?= e($successMessage) ?>
            </div>

        <?php endif; ?>


        <!-- REQUEST ERROR -->
        <?php if ($requestError !== ""): ?>

            <div class="message error">
                <?= e($requestError) ?>
            </div>

        <?php endif; ?>


        <!-- DATABASE ERROR -->
        <?php if ($databaseError !== ""): ?>

            <div class="message warning">
                <?= e($databaseError) ?>
            </div>

        <?php endif; ?>


        <!-- DASHBOARD STATISTICS -->
        <section class="stats-grid">

            <div class="stat-card blue">

                <p>Total Registered Users</p>

                <h2><?= $totalUsers ?></h2>

            </div>


            <div class="stat-card green">

                <p>Total Sellers</p>

                <h2><?= $totalSellers ?></h2>

            </div>


            <div class="stat-card pending">

                <p>Pending Sellers</p>

                <h2><?= $pendingSellers ?></h2>

            </div>


            <div class="stat-card blue">

                <p>Total Products</p>

                <h2><?= $totalProducts ?></h2>

            </div>


            <div class="stat-card pending">

                <p>Pending Products</p>

                <h2><?= $pendingProducts ?></h2>

            </div>

        </section>


        <!-- QUICK LINKS -->
        <section class="quick-links">

            <a href="../products.php" class="quick-link">

                <h3>View Marketplace</h3>

                <p>
                    View the public SolarGo product marketplace.
                </p>

            </a>


            <a href="../seller/dashboard.php" class="quick-link">

                <h3>Seller Dashboard</h3>

                <p>
                    Open the seller dashboard to review its interface.
                </p>

            </a>


            <a href="dashboard.php" class="quick-link">

                <h3>Refresh Dashboard</h3>

                <p>
                    Reload the latest dashboard statistics and applications.
                </p>

            </a>
            <a href="users.php" class="quick-link">
    <h3>User Management</h3>
    <p>Search registered users and activate or suspend eligible accounts.</p>
</a>    

        </section>


        <!-- PENDING SELLER APPLICATIONS -->
        <section class="section">

            <div class="section-header">

                <div>

                    <h2>Pending Seller Applications</h2>

                    <p>
                        Review seller registrations before granting account access.
                    </p>

                </div>

                <span class="count-badge">
                    <?= $pendingSellers ?>
                    Pending
                </span>

            </div>


            <?php if (count($sellers) > 0): ?>

                <div class="table-wrapper">

                    <table>

                        <thead>

                            <tr>
                                <th>ID</th>
                                <th>Seller Name</th>
                                <th>Email</th>
                                <th>Phone</th>
                                <th>Date Registered</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>

                        </thead>


                        <tbody>

                            <?php foreach ($sellers as $seller): ?>

                                <tr>

                                    <td>
                                        <?= (int) $seller["user_id"] ?>
                                    </td>


                                    <td class="seller-name">
                                        <?= e($seller["full_name"]) ?>
                                    </td>


                                    <td class="seller-email">
                                        <?= e($seller["email"]) ?>
                                    </td>


                                    <td>
                                        <?= e($seller["phone"]) ?>
                                    </td>


                                    <td>
                                        <?= e($seller["created_at"]) ?>
                                    </td>


                                    <td>

                                        <span class="status pending">
                                            Pending
                                        </span>

                                    </td>


                                    <td>

                                        <div class="action-buttons">

                                            <!-- APPROVE SELLER -->
                                            <form
                                                action="process-seller.php"
                                                method="POST"
                                                onsubmit="return confirm('Are you sure you want to approve this seller?');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e($_SESSION["csrf_token"]) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="seller_id"
                                                    value="<?= (int) $seller["user_id"] ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="approve"
                                                >

                                                <button
                                                    type="submit"
                                                    class="approve-btn"
                                                >
                                                    Approve
                                                </button>

                                            </form>


                                            <!-- REJECT SELLER -->
                                            <form
                                                action="process-seller.php"
                                                method="POST"
                                                onsubmit="return confirm('Are you sure you want to reject this seller?');"
                                            >

                                                <input
                                                    type="hidden"
                                                    name="csrf_token"
                                                    value="<?= e($_SESSION["csrf_token"]) ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="seller_id"
                                                    value="<?= (int) $seller["user_id"] ?>"
                                                >

                                                <input
                                                    type="hidden"
                                                    name="action"
                                                    value="reject"
                                                >

                                                <button
                                                    type="submit"
                                                    class="reject-btn"
                                                >
                                                    Reject
                                                </button>

                                            </form>

                                        </div>

                                    </td>

                                </tr>

                            <?php endforeach; ?>

                        </tbody>

                    </table>

                </div>

            <?php else: ?>

                <div class="empty-state">

                    <div class="empty-icon">
                        &#10003;
                    </div>

                    <h3>No Pending Seller Applications</h3>

                    <p>
                        There are currently no seller applications
                        waiting for approval.
                    </p>

                </div>

            <?php endif; ?>

        </section>


        <!-- PENDING PRODUCT APPROVALS -->
        <section class="section">
            <div class="section-header">
                <div>
                    <h2>Product Management</h2>
                    <p>Review product information and images before publishing listings.</p>
                </div>
                <span class="count-badge"><?= $pendingProducts ?> Pending</span>
            </div>

            <?php if (count($products) > 0): ?>
                <div class="table-wrapper">
                    <table style="min-width: 1050px;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Image</th>
                                <th>Product</th>
                                <th>Category</th>
                                <th>Price</th>
                                <th>Stock</th>
                                <th>Seller</th>
                                <th>Date Added</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($products as $product): ?>
                                <?php
                                    $imagePath = trim((string) ($product["image_url"] ?? ""));
                                    $imageFile = basename(str_replace("\\", "/", $imagePath));
                                    $imageSrc = "../uploads/products/" . rawurlencode($imageFile);
                                    $imageExists = ($imageFile !== "" && is_file(__DIR__ . "/../uploads/products/" . $imageFile));
                                ?>
                                <tr>
                                    <td><?= (int) $product["product_id"] ?></td>
                                    <td>
                                        <?php if ($imageExists): ?>
                                            <img src="<?= e($imageSrc) ?>" alt="Product image" style="width:65px;height:55px;object-fit:cover;border-radius:6px;">
                                        <?php else: ?>
                                            <span style="color:#6b7280;font-size:12px;">No image</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?= e($product["product_name"]) ?></strong>
                                        <p style="margin-top:6px;">
                                            <a href="product-details.php?id=<?= (int) $product["product_id"] ?>" style="color:#15803d;font-size:12px;font-weight:bold;">View Details</a>
                                        </p>
                                    </td>
                                    <td><?= e($product["category"]) ?></td>
                                    <td><?= number_format((float) $product["price"], 0) ?> FCFA</td>
                                    <td><?= (int) $product["stock_quantity"] ?></td>
                                    <td><?= e($product["seller_name"] ?? "Unknown seller") ?></td>
                                    <td><?= e($product["created_at"]) ?></td>
                                    <td>
                                        <div class="action-buttons">
                                            <form action="process-product.php" method="POST" onsubmit="return confirm('Approve this product listing?');">
                                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                                                <input type="hidden" name="product_id" value="<?= (int) $product["product_id"] ?>">
                                                <input type="hidden" name="action" value="approve">
                                                <button type="submit" class="approve-btn">Approve</button>
                                            </form>
                                            <form action="process-product.php" method="POST" onsubmit="return confirm('Reject this product listing?');">
                                                <input type="hidden" name="csrf_token" value="<?= e($_SESSION["csrf_token"]) ?>">
                                                <input type="hidden" name="product_id" value="<?= (int) $product["product_id"] ?>">
                                                <input type="hidden" name="action" value="reject">
                                                <button type="submit" class="reject-btn">Reject</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">&#10003;</div>
                    <h3>No Pending Products</h3>
                    <p>There are currently no product listings waiting for approval.</p>
                </div>
            <?php endif; ?>
        </section>

    </main>


    <footer>

        &copy; <?= date("Y") ?> SolarGo.
        All rights reserved.

    </footer>

</body>
</html>
