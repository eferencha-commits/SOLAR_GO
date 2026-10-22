
<?php

session_start();

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . "/../config/database.php";

// Check database connection
if (!isset($conn) || $conn->connect_error) {
    error_log("SolarGo database connection failed.");
    http_response_code(500);
    exit("Unable to connect to the database. Please check your database configuration.");
}

// Ensure the user is logged in
if (empty($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

// Ensure only customers can access this dashboard
if (($_SESSION["role"] ?? "") !== "customer") {
    http_response_code(403);
    exit("Access denied. Only customers can access this dashboard.");
}

// Get customer ID
$user_id = filter_var(
    $_SESSION["user_id"],
    FILTER_VALIDATE_INT
);

if (!$user_id || $user_id < 1) {
    $_SESSION = [];
    session_destroy();

    header("Location: ../auth/login.php");
    exit;
}

// Retrieve current customer information
$sql = "
    SELECT user_id, full_name, email, phone, role, account_status
    FROM users
    WHERE user_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log("Customer dashboard prepare failed: " . $conn->error);
    http_response_code(500);
    exit("Unable to load your dashboard. Please try again later.");
}

$stmt->bind_param("i", $user_id);

if (!$stmt->execute()) {
    error_log("Customer dashboard execute failed: " . $stmt->error);
    $stmt->close();

    http_response_code(500);
    exit("Unable to load your dashboard. Please try again later.");
}

$result = $stmt->get_result();
$customer = $result->fetch_assoc();

$stmt->close();

// Check whether the account exists
if (!$customer) {
    $_SESSION = [];
    session_destroy();

    header("Location: ../auth/login.php");
    exit;
}

// Confirm customer role
if ($customer["role"] !== "customer") {
    http_response_code(403);
    exit("Access denied. Your account is not registered as a customer.");
}

// Confirm account is active
if ($customer["account_status"] !== "active") {
    $_SESSION = [];
    session_destroy();

    header("Location: ../auth/login.php?error=account_inactive");
    exit;
}

// Synchronize session information
$_SESSION["full_name"] = $customer["full_name"];

// Initialize CSRF token if needed
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Escape output safely
function escapeHTML($value)
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

$customer_name = $customer["full_name"];
$customer_email = $customer["email"];
$customer_phone = $customer["phone"] ?? "";

// Count items currently in the shopping cart
$cart_count = 0;

if (!empty($_SESSION["cart"]) && is_array($_SESSION["cart"])) {
    foreach ($_SESSION["cart"] as $item) {
        $cart_count += max(0, (int) ($item["quantity"] ?? 0));
    }
}

// Count the customer's available approved products
$available_products = 0;

$product_sql = "
    SELECT COUNT(*) AS total
    FROM products
    WHERE listing_status = 'approved'
      AND stock_quantity > 0
";

$product_result = $conn->query($product_sql);

if ($product_result) {
    $product_row = $product_result->fetch_assoc();
    $available_products = (int) $product_row["total"];
    $product_result->free();
} else {
    error_log("SolarGo product count query failed: " . $conn->error);
}

// Optional success or error messages
$message = "";

if (isset($_GET["message"])) {
    if ($_GET["message"] === "added_to_cart") {
        $message = "Your product has been added to your shopping cart.";
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Customer Dashboard | SolarGo</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #f4faf5;
            font-family: Arial, Helvetica, sans-serif;
            color: #24352a;
        }

        a {
            transition: 0.2s ease;
        }

        .dashboard-header {
            background: #126b3a;
            color: white;
            padding: 18px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .brand {
            margin: 0;
            font-size: 27px;
            font-weight: bold;
        }

        .brand span {
            color: #d6f5a8;
        }

        .dashboard-nav {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .dashboard-nav a {
            color: white;
            text-decoration: none;
            font-weight: bold;
        }

        .dashboard-nav a:hover {
            color: #d6f5a8;
        }

        .logout-link {
            border: 1px solid white;
            padding: 9px 15px;
            border-radius: 6px;
        }

        .logout-link:hover {
            background: white;
            color: #126b3a !important;
        }

        .dashboard-container {
            width: 88%;
            max-width: 1200px;
            margin: 35px auto;
            min-height: 65vh;
        }

        .welcome-section {
            background: linear-gradient(120deg, #126b3a, #219653);
            color: white;
            padding: 35px;
            border-radius: 14px;
            margin-bottom: 28px;
            box-shadow: 0 5px 20px #0000000d;
        }

        .welcome-section h1 {
            margin-top: 0;
            margin-bottom: 12px;
            font-size: 32px;
        }

        .welcome-section p {
            line-height: 1.7;
            max-width: 750px;
        }

        .welcome-button {
            display: inline-block;
            margin-top: 12px;
            background: white;
            color: #126b3a;
            text-decoration: none;
            padding: 12px 20px;
            border-radius: 7px;
            font-weight: bold;
        }

        .welcome-button:hover {
            background: #e7f8e9;
            transform: translateY(-2px);
        }

        .notice {
            padding: 14px 18px;
            background: #d9f5df;
            color: #145c2d;
            border-radius: 8px;
            margin-bottom: 22px;
        }

        .section-heading {
            margin-bottom: 20px;
            color: #126b3a;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 22px;
        }

        .dashboard-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 5px 20px #00000008;
            border: 1px solid #e7eee8;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
        }

        .card-icon {
            font-size: 32px;
            margin-bottom: 12px;
        }

        .dashboard-card h3 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #126b3a;
        }

        .dashboard-card p {
            line-height: 1.7;
            color: #647067;
            flex-grow: 1;
        }

        .card-number {
            font-size: 27px;
            font-weight: bold;
            color: #126b3a;
            margin: 5px 0;
        }

        .dashboard-card a {
            display: inline-block;
            margin-top: 12px;
            background: #126b3a;
            color: white;
            padding: 11px 16px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: bold;
        }

        .dashboard-card a:hover {
            background: #0d512b;
        }

        .account-section {
            margin-top: 30px;
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 5px 20px #00000008;
        }

        .account-section h2 {
            margin-top: 0;
            color: #126b3a;
        }

        .account-details {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 15px;
        }

        .account-detail {
            padding: 15px;
            background: #f4faf5;
            border-radius: 8px;
            overflow-wrap: anywhere;
        }

        .account-detail strong {
            display: block;
            margin-bottom: 7px;
            color: #126b3a;
        }

        .dashboard-footer {
            text-align: center;
            padding: 25px;
            color: #647067;
            margin-top: 35px;
            background: white;
        }

        @media (max-width: 900px) {
            .dashboard-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {
            .dashboard-container {
                width: 92%;
                margin: 22px auto;
            }

            .dashboard-header {
                padding: 18px 4%;
            }

            .dashboard-nav {
                gap: 12px;
            }

            .welcome-section {
                padding: 25px 20px;
            }

            .welcome-section h1 {
                font-size: 25px;
            }

            .dashboard-grid,
            .account-details {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body>

<header class="dashboard-header">

    <h2 class="brand">Solar<span>Go</span></h2>

    <nav class="dashboard-nav">
        <a href="../index.php">Home</a>
        <a href="../products.php">Products</a>
        <a href="../cart.php">
            Cart (<?= $cart_count ?>)
        </a>
        <a href="../auth/logout.php" class="logout-link">Logout</a>
    </nav>

</header>

<main class="dashboard-container">

    <?php if ($message !== ""): ?>
        <div class="notice">
            <?= escapeHTML($message) ?>
        </div>
    <?php endif; ?>

    <section class="welcome-section">

        <h1>
            Welcome,
            <?= escapeHTML($customer_name) ?>!
        </h1>

        <p>
            Welcome to SolarGo — Power That Does Not Cut Out.
        </p>

        <p>
            Your customer dashboard is your starting point for exploring
            solar products, managing your shopping cart, and accessing
            solar energy services.
        </p>

        <a href="../products.php" class="welcome-button">
            Explore Solar Products
        </a>

    </section>

    <h2 class="section-heading">Your Dashboard</h2>

    <section class="dashboard-grid">

        <article class="dashboard-card">

            <div class="card-icon">🛒</div>

            <h3>Solar Products</h3>

            <div class="card-number">
                <?= $available_products ?>
            </div>

            <p>
                Approved products currently in stock are available
                for you to explore.
            </p>

            <a href="../products.php">
                Browse Products &rarr;
            </a>

        </article>

        <article class="dashboard-card">

            <div class="card-icon">🛍️</div>

            <h3>Shopping Cart</h3>

            <div class="card-number">
                <?= $cart_count ?>
            </div>

            <p>
                View the products you have added to your shopping cart.
            </p>

            <a href="../cart.php">
                View My Cart &rarr;
            </a>

        </article>

        <article class="dashboard-card">

            <div class="card-icon">📦</div>

            <a href="orders.php">My Orders</a>

            <p>
                When order management is implemented, you will be able
                to view your purchases and order statuses here.
            </p>

            <span>Order tracking coming soon.</span>

        </article>

        <article class="dashboard-card">

            <div class="card-icon">💳</div>

            <h3>Instalment Payments</h3>

            <p>
                Access instalment payment information when the
                payment management feature becomes available.
            </p>

            <span>Payment management coming soon.</span>

        </article>

        <article class="dashboard-card">

            <div class="card-icon">🛠️</div>

            <h3>Solar Services</h3>

            <p>
                Explore installation, repair, and maintenance services
                for your solar energy system.
            </p>

            <a href="../services.php">
                Explore Services &rarr;
            </a>

        </article>

        <article class="dashboard-card">

            <div class="card-icon">⭐</div>

            <h3>Reviews and Feedback</h3>

            <p>
                Share your experience with products and services
                when the review feature is available.
            </p>

            <span>Reviews coming soon.</span>

        </article>

    </section>

    <section class="account-section">

        <h2>My Account</h2>

        <div class="account-details">

            <div class="account-detail">
                <strong>Full Name</strong>
                <?= escapeHTML($customer_name) ?>
            </div>

            <div class="account-detail">
                <strong>Email Address</strong>
                <?= escapeHTML($customer_email) ?>
            </div>

            <div class="account-detail">
                <strong>Phone Number</strong>

                <?= $customer_phone !== ""
                    ? escapeHTML($customer_phone)
                    : "No phone number provided" ?>
            </div>

            <div class="account-detail">
                <strong>Account Status</strong>
                <?= escapeHTML(ucfirst($customer["account_status"])) ?>
            </div>

        </div>

    </section>

</main>

<footer class="dashboard-footer">
    <p>
        &copy; <?= date("Y") ?> SolarGo.
        All rights reserved.
    </p>
</footer>

</body>
</html>
