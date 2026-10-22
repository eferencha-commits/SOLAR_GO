<?php

session_start();

// Ensure the user is logged in
if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit;
}

// Only customers can access this dashboard
if (($_SESSION["role"] ?? "") !== "customer") {
    http_response_code(403);
    exit("Access denied. You do not have permission to access this page.");
}

// Get the customer's name
$customer_name = $_SESSION["full_name"] ?? "Customer";

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Customer Dashboard | SolarGo</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        body {
            margin: 0;
            background: #f4faf5;
            font-family: Arial, sans-serif;
            color: #24352a;
        }

        .dashboard-header {
            background: #126b3a;
            color: white;
            padding: 20px 6%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .dashboard-header h2 {
            margin: 0;
        }

        .dashboard-header a {
            color: white;
            text-decoration: none;
            font-weight: bold;
        }

        .dashboard-container {
            width: 88%;
            max-width: 1200px;
            margin: 35px auto;
        }

        .welcome-section {
            background: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 30px;
            box-shadow: 0 5px 20px #00000008;
        }

        .welcome-section h1 {
            margin-top: 0;
            color: #126b3a;
        }

        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 20px;
        }

        .dashboard-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 5px 20px #00000008;
        }

        .dashboard-card h3 {
            margin-top: 0;
            color: #126b3a;
        }

        .dashboard-card p {
            line-height: 1.6;
            color: #647067;
        }

        .dashboard-card a {
            display: inline-block;
            margin-top: 10px;
            color: #126b3a;
            font-weight: bold;
            text-decoration: none;
        }

        .dashboard-footer {
            text-align: center;
            padding: 25px;
            color: #647067;
        }

        @media (max-width: 800px) {
            .dashboard-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 550px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }

            .dashboard-container {
                width: 92%;
            }
        }
    </style>
</head>

<body>

    <header class="dashboard-header">

        <h2>SolarGo</h2>

        <nav>
            <a href="../index.php">Home</a>
            &nbsp; | &nbsp;
            <a href="logout.php">Logout</a>
        </nav>
        <ul class="nav-links" id="navLinks">
                <li><a href="index.php" class="active">Home</a></li>
                <li><a href="products.php">Products</a></li>
                <li><a href="services.php">Services</a></li>
                <li><a href="about.php">About Us</a></li>
                <li><a href="contact.php">Contact</a></li>
            </ul>

    </header>

    <main class="dashboard-container">

        <section class="welcome-section">

            <h1>
                Welcome, <?= htmlspecialchars(
                    $customer_name,
                    ENT_QUOTES,
                    "UTF-8"
                ) ?>!
            </h1>

            <p>
                Welcome to SolarGo — Power That Does Not Cut Out.
            </p>

            <p>
                Manage your solar energy needs, explore products,
                track orders, and discover reliable solar services.
            </p>

        </section>

        <section class="dashboard-grid">

            <article class="dashboard-card">

                <h3>🛒 Solar Products</h3>

                <p>
                    Explore solar panels, batteries, inverters,
                    and other solar energy products.
                </p>

                <a href="../products.php">
                    Browse Products &rarr;
                </a>

            </article>

            <article class="dashboard-card">

                <h3>📦 My Orders</h3>

                <p>
                    View your orders and check their current status.
                </p>

                <a href="#">
                    View Orders &rarr;
                </a>

            </article>

            <article class="dashboard-card">

                <h3>💳 Instalment Payments</h3>

                <p>
                    View instalment applications and payment information.
                </p>

                <a href="#">
                    Manage Payments &rarr;
                </a>

            </article>

            <article class="dashboard-card">

                <h3>🛠️ Solar Services</h3>

                <p>
                    Request solar installation, repair, or maintenance.
                </p>

                <a href="../services.php">
                    Explore Services &rarr;
                </a>

            </article>

            <article class="dashboard-card">

                <h3>⭐ Reviews and Feedback</h3>

                <p>
                    Share your experience with products and services.
                </p>

                <a href="#">
                    Give Feedback &rarr;
                </a>

            </article>

            <article class="dashboard-card">

                <h3>👤 My Account</h3>

                <p>
                    View your registered account information.
                </p>

                <a href="#">
                    View Profile &rarr;
                </a>

            </article>

        </section>

    </main>

    <footer class="dashboard-footer">
        <p>&copy; <?= date("Y") ?> SolarGo. All rights reserved.</p>
    </footer>

</body>

</html>