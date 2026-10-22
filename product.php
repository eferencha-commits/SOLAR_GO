
<?php
session_start();

require_once __DIR__ . "/config/database.php";

/* -----------------------------
   Helper functions
----------------------------- */

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

/* -----------------------------
   CSRF token for Add to Cart
----------------------------- */

if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

/* -----------------------------
   Read search and filter inputs
----------------------------- */

$search = trim($_GET["search"] ?? "");
$category = trim($_GET["category"] ?? "");
$minPrice = trim($_GET["min_price"] ?? "");
$maxPrice = trim($_GET["max_price"] ?? "");
$sort = $_GET["sort"] ?? "newest";

$errors = [];

/* Validate price inputs */

if (
    $minPrice !== "" &&
    (!is_numeric($minPrice) || (float) $minPrice < 0)
) {
    $errors[] = "Enter a valid minimum price.";
    $minPrice = "";
}

if (
    $maxPrice !== "" &&
    (!is_numeric($maxPrice) || (float) $maxPrice < 0)
) {
    $errors[] = "Enter a valid maximum price.";
    $maxPrice = "";
}

if (
    $minPrice !== "" &&
    $maxPrice !== "" &&
    (float) $minPrice > (float) $maxPrice
) {
    $errors[] = "Minimum price cannot be greater than maximum price.";
}

/* Allowed sorting options */

$allowedSorts = [
    "newest",
    "price_low",
    "price_high",
    "name_asc"
];

if (!in_array($sort, $allowedSorts, true)) {
    $sort = "newest";
}

/* -----------------------------
   Load available categories
----------------------------- */

$categories = [];

$categorySql = "
    SELECT DISTINCT category
    FROM products
    WHERE listing_status = 'approved'
      AND stock_quantity > 0
      AND category IS NOT NULL
      AND category <> ''
    ORDER BY category ASC
";

$categoryResult = $conn->query($categorySql);

if ($categoryResult) {
    while ($categoryRow = $categoryResult->fetch_assoc()) {
        $categories[] = $categoryRow["category"];
    }
}

/* -----------------------------
   Build marketplace query
----------------------------- */

$sql = "
    SELECT
        p.product_id,
        p.product_name,
        p.category,
        p.description,
        p.price,
        p.stock_quantity,
        p.image_url,
        p.installments_available,
        p.created_at,
        u.full_name AS seller_name
    FROM products p
    LEFT JOIN users u
        ON p.seller_id = u.user_id
    WHERE p.listing_status = 'approved'
      AND p.stock_quantity > 0
";

$types = "";
$params = [];

/* Search by product name or description */

if ($search !== "") {
    $sql .= "
        AND (
            p.product_name LIKE ?
            OR p.description LIKE ?
            OR p.category LIKE ?
        )
    ";

    $searchTerm = "%" . $search . "%";

    $types .= "sss";

    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

/* Filter by category */

if ($category !== "" && in_array($category, $categories, true)) {
    $sql .= " AND p.category = ? ";

    $types .= "s";
    $params[] = $category;
}

/* Minimum price */

if ($minPrice !== "") {
    $sql .= " AND p.price >= ? ";

    $types .= "d";
    $params[] = (float) $minPrice;
}

/* Maximum price */

if ($maxPrice !== "") {
    $sql .= " AND p.price <= ? ";

    $types .= "d";
    $params[] = (float) $maxPrice;
}

/* Sorting */

switch ($sort) {
    case "price_low":
        $sql .= " ORDER BY p.price ASC, p.created_at DESC";
        break;

    case "price_high":
        $sql .= " ORDER BY p.price DESC, p.created_at DESC";
        break;

    case "name_asc":
        $sql .= " ORDER BY p.product_name ASC";
        break;

    default:
        $sql .= " ORDER BY p.created_at DESC";
        break;
}

/* -----------------------------
   Execute query safely
----------------------------- */

$products = [];
$queryError = false;

if (empty($errors)) {
    $stmt = $conn->prepare($sql);

    if ($stmt === false) {
        error_log("SolarGo marketplace error: " . $conn->error);
        $queryError = true;
    } else {
        if ($types !== "") {
            $stmt->bind_param($types, ...$params);
        }

        if (!$stmt->execute()) {
            error_log("SolarGo marketplace execution error: " . $stmt->error);
            $queryError = true;
        } else {
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $products[] = $row;
            }
        }

        $stmt->close();
    }
}

$totalProducts = count($products);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Solar Marketplace | SolarGo</title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
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
            gap: 20px;
            flex-wrap: wrap;
        }

        .logo {
            font-size: 26px;
            font-weight: bold;
        }

        .logo span {
            color: #f4c542;
        }

        nav {
            display: flex;
            gap: 20px;
            align-items: center;
            flex-wrap: wrap;
        }

        nav a {
            color: white;
            text-decoration: none;
            font-size: 14px;
        }

        nav a:hover {
            color: #f4c542;
        }

        .hero {
            background: linear-gradient(135deg, #123c2a, #1e7046);
            color: white;
            padding: 60px 5%;
            text-align: center;
        }

        .hero h1 {
            font-size: 38px;
            margin-bottom: 15px;
        }

        .hero p {
            max-width: 650px;
            margin: 0 auto;
            color: #e1eee5;
        }

        .container {
            width: 90%;
            max-width: 1250px;
            margin: 35px auto;
        }

        /* Search and filter panel */

        .filter-panel {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.05);
            margin-bottom: 35px;
        }

        .filter-panel h2 {
            font-size: 22px;
            margin-bottom: 20px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: 2fr 1.2fr 1fr 1fr 1.2fr;
            gap: 15px;
            align-items: end;
        }

        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: bold;
            margin-bottom: 7px;
        }

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 12px;
            border: 1px solid #d7e0d9;
            border-radius: 6px;
            font-size: 14px;
            background: white;
            color: #24332b;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: 2px solid #16834a;
            border-color: #16834a;
        }

        .filter-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            background: #16834a;
            color: white;
            text-align: center;
            padding: 12px 16px;
            border-radius: 6px;
            text-decoration: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: bold;
        }

        .btn:hover {
            background: #116839;
        }

        .btn-reset {
            background: #e8eee9;
            color: #24332b;
        }

        .btn-reset:hover {
            background: #d9e3db;
        }

        .section-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-bottom: 25px;
        }

        .section-heading h2 {
            font-size: 26px;
        }

        .product-count {
            color: #647269;
            font-size: 14px;
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 25px;
        }

        .product-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
            transition: transform 0.2s, box-shadow 0.2s;
            display: flex;
            flex-direction: column;
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 22px rgba(0, 0, 0, 0.1);
        }

        .product-image {
            height: 230px;
            background: #edf2ee;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
        }

        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            padding: 10px;
        }

        .image-placeholder {
            text-align: center;
            color: #78877c;
            padding: 20px;
        }

        .image-placeholder span {
            display: block;
            font-size: 45px;
            margin-bottom: 8px;
        }

        .product-content {
            padding: 22px;
            display: flex;
            flex-direction: column;
            flex: 1;
        }

        .category {
            display: inline-block;
            align-self: flex-start;
            background: #e3f3e8;
            color: #176534;
            padding: 5px 10px;
            border-radius: 20px;
            font-size: 12px;
            margin-bottom: 12px;
        }

        .product-content h3 {
            font-size: 19px;
            margin-bottom: 10px;
            overflow-wrap: anywhere;
        }

        .description {
            font-size: 14px;
            color: #647269;
            margin-bottom: 15px;
            overflow-wrap: anywhere;
            white-space: pre-line;
        }

        .product-meta {
            color: #647269;
            font-size: 13px;
            margin-bottom: 8px;
        }

        .price {
            color: #16834a;
            font-size: 22px;
            font-weight: bold;
            margin: 12px 0;
        }

        .installments {
            color: #176534;
            font-size: 13px;
            margin-bottom: 15px;
        }

        .product-actions {
            margin-top: auto;
        }

        .product-actions .btn {
            width: 100%;
            display: block;
        }

        .cart-form {
            margin-top: 10px;
        }

        .error-message {
            background: #ffe9e9;
            color: #a51d1d;
            padding: 15px;
            border-radius: 7px;
            margin-bottom: 20px;
        }

        .empty-state {
            background: white;
            padding: 55px 20px;
            text-align: center;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.04);
        }

        .empty-state h3 {
            font-size: 22px;
            margin-bottom: 12px;
        }

        .empty-state p {
            color: #647269;
            margin-bottom: 18px;
        }

        footer {
            margin-top: 60px;
            background: #123c2a;
            color: white;
            text-align: center;
            padding: 25px;
        }

        footer p {
            font-size: 14px;
            color: #d6e4da;
        }

        @media (max-width: 1050px) {
            .filter-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .products-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 600px) {
            .filter-grid,
            .products-grid {
                grid-template-columns: 1fr;
            }

            .hero {
                padding: 45px 5%;
            }

            .hero h1 {
                font-size: 27px;
            }

            .section-heading h2 {
                font-size: 23px;
            }

            .filter-panel {
                padding: 18px;
            }

            nav {
                gap: 12px;
            }
        }
    </style>
</head>

<body>

<header>
    <div class="logo">Solar<span>Go</span></div>

    <nav>
        <a href="index.php">Home</a>
        <a href="products.php">Marketplace</a>

        <?php if (isset($_SESSION["user_id"])): ?>

            <?php if (($_SESSION["role"] ?? "") === "seller"): ?>
                <a href="seller/dashboard.php">Seller Dashboard</a>

            <?php elseif (($_SESSION["role"] ?? "") === "customer"): ?>
                <a href="customer/dashboard.php">My Dashboard</a>

            <?php elseif (($_SESSION["role"] ?? "") === "admin"): ?>
                <a href="admin/dashboard.php">Admin Dashboard</a>
            <?php endif; ?>

            <?php if (($_SESSION["role"] ?? "") === "customer"): ?>
                <a href="cart.php">My Cart</a>
            <?php endif; ?>

            <a href="auth/logout.php">Logout</a>

        <?php else: ?>

            <a href="auth/login.php">Login</a>
            <a href="auth/register.php">Register</a>

        <?php endif; ?>
    </nav>
</header>

<section class="hero">
    <h1>Find the Right Solar Solution</h1>

    <p>
        Discover solar panels, batteries, inverters and other
        renewable energy products offered by sellers on SolarGo.
        Search, compare prices and choose the right solution.
    </p>
</section>

<main class="container">

    <section class="filter-panel">
        <h2>Search and Filter Products</h2>

        <form action="products.php" method="GET">

            <div class="filter-grid">

                <div class="form-group">
                    <label for="search">Search products</label>

                    <input
                        type="search"
                        id="search"
                        name="search"
                        placeholder="Solar panel, battery..."
                        value="<?= escapeHTML($search) ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="category">Category</label>

                    <select id="category" name="category">
                        <option value="">All Categories</option>

                        <?php foreach ($categories as $cat): ?>
                            <option
                                value="<?= escapeHTML($cat) ?>"
                                <?= $category === $cat ? "selected" : "" ?>
                            >
                                <?= escapeHTML($cat) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="min_price">Minimum price</label>

                    <input
                        type="number"
                        id="min_price"
                        name="min_price"
                        min="0"
                        step="1"
                        placeholder="0 FCFA"
                        value="<?= escapeHTML($minPrice) ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="max_price">Maximum price</label>

                    <input
                        type="number"
                        id="max_price"
                        name="max_price"
                        min="0"
                        step="1"
                        placeholder="500000 FCFA"
                        value="<?= escapeHTML($maxPrice) ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="sort">Sort by</label>

                    <select id="sort" name="sort">
                        <option
                            value="newest"
                            <?= $sort === "newest" ? "selected" : "" ?>
                        >
                            Newest First
                        </option>

                        <option
                            value="price_low"
                            <?= $sort === "price_low" ? "selected" : "" ?>
                        >
                            Price: Low to High
                        </option>

                        <option
                            value="price_high"
                            <?= $sort === "price_high" ? "selected" : "" ?>
                        >
                            Price: High to Low
                        </option>

                        <option
                            value="name_asc"
                            <?= $sort === "name_asc" ? "selected" : "" ?>
                        >
                            Name: A to Z
                        </option>
                    </select>
                </div>

            </div>

            <div class="filter-actions">
                <button type="submit" class="btn">
                    Search Products
                </button>

                <a href="products.php" class="btn btn-reset">
                    Clear Filters
                </a>
            </div>

        </form>
    </section>

    <?php if (!empty($errors)): ?>

        <div class="error-message">
            <?php foreach ($errors as $error): ?>
                <p><?= escapeHTML($error) ?></p>
            <?php endforeach; ?>
        </div>

    <?php endif; ?>

    <?php if ($queryError): ?>

        <div class="empty-state">
            <h3>Marketplace Temporarily Unavailable</h3>
            <p>Please check your database connection and try again.</p>
        </div>

    <?php else: ?>

        <div class="section-heading">
            <h2>Available Solar Products</h2>

            <p class="product-count">
                <?= $totalProducts ?>
                <?= $totalProducts === 1 ? "product" : "products" ?>
                found
            </p>
        </div>

        <?php if ($totalProducts > 0 && empty($errors)): ?>

            <div class="products-grid">

                <?php foreach ($products as $product): ?>

                    <article class="product-card">

                        <div class="product-image">

                            <?php
                            /*
                             * Only display image files saved using
                             * the expected product-upload filename.
                             */

                            $savedImage = trim(
                                (string) ($product["image_url"] ?? "")
                            );

                            $imageSrc = "";

                            if (
                                preg_match(
                                    '~\Auploads/products/[a-f0-9]{32}\.(?:jpg|png|webp)\z~i',
                                    $savedImage
                                )
                            ) {
                                $imageFile = __DIR__ . "/" . $savedImage;

                                if (is_file($imageFile)) {
                                    $imageSrc = $savedImage;
                                }
                            }
                            ?>

                            <?php if ($imageSrc !== ""): ?>

                                <img
                                    src="<?= escapeHTML($imageSrc) ?>"
                                    alt="<?= escapeHTML($product["product_name"]) ?>"
                                    loading="lazy"
                                >

                            <?php else: ?>

                                <div class="image-placeholder">
                                    <span aria-hidden="true">☀️</span>
                                    <p>Product image unavailable</p>
                                </div>

                            <?php endif; ?>

                        </div>

                        <div class="product-content">

                            <span class="category">
                                <?= escapeHTML($product["category"]) ?>
                            </span>

                            <h3>
                                <?= escapeHTML($product["product_name"]) ?>
                            </h3>

                            <p class="description">
                                <?php
                                $description = (string) (
                                    $product["description"] ?? ""
                                );

                                if (strlen($description) > 150) {
                                    $description = substr(
                                        $description,
                                        0,
                                        147
                                    ) . "...";
                                }

                                echo escapeHTML($description);
                                ?>
                            </p>

                            <p class="product-meta">
                                Seller:
                                <?= escapeHTML(
                                    $product["seller_name"] ?? "SolarGo Seller"
                                ) ?>
                            </p>

                            <p class="product-meta">
                                Available quantity:
                                <?= (int) $product["stock_quantity"] ?>
                            </p>

                            <p class="price">
                                <?= escapeHTML(formatPrice($product["price"])) ?>
                            </p>

                            <?php if ((int) $product["installments_available"] === 1): ?>

                                <p class="installments">
                                    ✓ Instalment payments available
                                </p>

                            <?php endif; ?>

                            <div class="product-actions">

                                <a
                                    class="btn"
                                    href="product-details.php?id=<?= (int) $product["product_id"] ?>"
                                >
                                    View Product
                                </a>

                                <?php if (
                                    isset($_SESSION["user_id"]) &&
                                    ($_SESSION["role"] ?? "") === "customer"
                                ): ?>

                                    <form
                                        action="add-to-cart.php"
                                        method="POST"
                                        class="cart-form"
                                    >

                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= escapeHTML($_SESSION["csrf_token"]) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="product_id"
                                            value="<?= (int) $product["product_id"] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="quantity"
                                            value="1"
                                        >

                                        <button
                                            type="submit"
                                            class="btn"
                                        >
                                            Add to Cart
                                        </button>

                                    </form>

                                <?php endif; ?>

                            </div>

                        </div>

                    </article>

                <?php endforeach; ?>

            </div>

        <?php elseif (empty($errors)): ?>

            <div class="empty-state">
                <h3>No Products Found</h3>

                <p>
                    No available products match your search or filters.
                    Try a different keyword, category or price range.
                </p>

                <a href="products.php" class="btn">
                    View All Products
                </a>
            </div>

        <?php endif; ?>

    <?php endif; ?>

</main>

<footer>
    <p>
        &copy; <?= date("Y") ?> SolarGo.
        Power That Does Not Cut Out.
    </p>
</footer>

</body>
</html>
