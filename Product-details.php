
<?php
session_start();

require_once __DIR__ . "/config/database.php";

// Escape output before displaying it in HTML.
function escapeHTML($value)
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

// Format prices in FCFA.
function formatPrice($price)
{
    return number_format((float) $price, 0, ".", ",") . " FCFA";
}

// Get the product ID from the URL.
$product_id = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT
);

// Validate the product ID.
if (!$product_id || $product_id < 1) {
    http_response_code(400);
    exit("Invalid product ID. Please return to the marketplace.");
}

// Retrieve an approved product that is in stock.
$sql = "SELECT
            p.product_id,
            p.product_name,
            p.category,
            p.description,
            p.price,
            p.stock_quantity,
            p.image_url,
            p.installments_available,
            p.created_at,
            u.full_name AS seller_name,
            u.phone AS seller_phone,
            u.email AS seller_email
        FROM products p
        LEFT JOIN users u
            ON p.seller_id = u.user_id
        WHERE p.product_id = ?
          AND p.listing_status = 'approved'
          AND p.stock_quantity > 0
        LIMIT 1";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log("Product details query error: " . $conn->error);
    http_response_code(500);
    exit("Unable to load this product right now. Please try again later.");
}

$stmt->bind_param("i", $product_id);
$stmt->execute();

$result = $stmt->get_result();
$product = $result->fetch_assoc();

$stmt->close();

// Show an error if the product does not exist,
// is not approved, or is out of stock.
if (!$product) {
    http_response_code(404);
    $product_not_found = true;
} else {
    $product_not_found = false;
}

// Load all saved product images, validating each stored path.
$product_images = [];
$image_src = "";

if (!$product_not_found) {
    $images_sql = "SELECT image_url, is_primary
                   FROM product_images
                   WHERE product_id = ?
                   ORDER BY is_primary DESC, image_id ASC";
    $images_stmt = $conn->prepare($images_sql);

    if ($images_stmt) {
        $images_stmt->bind_param("i", $product_id);
        if ($images_stmt->execute()) {
            $images_result = $images_stmt->get_result();
            while ($image_row = $images_result->fetch_assoc()) {
                $saved_image = trim((string) ($image_row["image_url"] ?? ""));
                if (
                    preg_match('/\Auploads\/products\/[a-f0-9]{32}\.(jpg|png|webp)\z/i', $saved_image)
                    && is_file(__DIR__ . "/" . $saved_image)
                ) {
                    $product_images[] = $saved_image;
                }
            }
        }
        $images_stmt->close();
    } else {
        // Log the issue but still allow older single-image products to display.
        error_log("Product images query error: " . $conn->error);
    }

    // Backward compatibility: use the original products.image_url if needed.
    $saved_image = trim((string) ($product["image_url"] ?? ""));
    if (
        preg_match('/\Auploads\/products\/[a-f0-9]{32}\.(jpg|png|webp)\z/i', $saved_image)
        && is_file(__DIR__ . "/" . $saved_image)
        && !in_array($saved_image, $product_images, true)
    ) {
        array_unshift($product_images, $saved_image);
    }

    if (!empty($product_images)) {
        $image_src = $product_images[0];
    }
}
?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>
        <?php if (!$product_not_found): ?>
            <?= escapeHTML($product["product_name"]) ?> | SolarGo
        <?php else: ?>
            Product Not Found | SolarGo
        <?php endif; ?>
    </title>

    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f5f7f5;
            color: #26352b;
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
            font-size: 27px;
            font-weight: bold;
        }

        .logo span {
            color: #f4c542;
        }

        nav {
            display: flex;
            gap: 20px;
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

        .breadcrumb {
            width: 90%;
            max-width: 1150px;
            margin: 25px auto;
            font-size: 14px;
            color: #68766c;
        }

        .breadcrumb a {
            color: #16834a;
            text-decoration: none;
        }

        .product-container {
            width: 90%;
            max-width: 1150px;
            margin: 0 auto 60px;
        }

        .product-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06);
        }

        .image-container {
            width: 100%;
            min-height: 350px;
            max-height: 500px;
            background: #edf2ee;
            border-radius: 10px;
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
        }

        .image-container img {
            width: 100%;
            height: 100%;
            max-height: 500px;
            object-fit: contain;
            padding: 15px;
        }

        .image-placeholder {
            text-align: center;
            color: #68766c;
            padding: 30px;
        }

        .product-gallery {
            min-width: 0;
        }

        .thumbnail-list {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-top: 12px;
        }

        .thumbnail-button {
            width: 76px;
            height: 76px;
            padding: 3px;
            border: 2px solid #dce5dc;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            overflow: hidden;
        }

        .thumbnail-button.active,
        .thumbnail-button:focus-visible {
            border-color: #16834a;
            outline: 2px solid #16834a;
            outline-offset: 2px;
        }

        .thumbnail-button img {
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .image-placeholder .icon {
            font-size: 65px;
            display: block;
            margin-bottom: 10px;
        }

        .category {
            display: inline-block;
            background: #e3f3e8;
            color: #176534;
            padding: 6px 13px;
            border-radius: 20px;
            font-size: 13px;
            margin-bottom: 15px;
        }

        .product-info h1 {
            font-size: 32px;
            line-height: 1.3;
            margin-bottom: 15px;
            overflow-wrap: anywhere;
        }

        .price {
            color: #16834a;
            font-size: 30px;
            font-weight: bold;
            margin-bottom: 20px;
        }

        .stock {
            display: inline-block;
            color: #176534;
            background: #e3f3e8;
            padding: 7px 12px;
            border-radius: 5px;
            font-size: 14px;
            margin-bottom: 20px;
        }

        .description-section {
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e4e9e5;
        }

        .description-section h2 {
            font-size: 20px;
            margin-bottom: 10px;
        }

        .description-section p {
            color: #58665d;
            overflow-wrap: anywhere;
            white-space: pre-line;
        }

        .seller-card {
            background: #f5f8f5;
            padding: 20px;
            border-radius: 8px;
            margin-top: 25px;
        }

        .seller-card h2 {
            font-size: 19px;
            margin-bottom: 12px;
        }

        .seller-card p {
            font-size: 14px;
            margin-bottom: 8px;
            overflow-wrap: anywhere;
        }

        .seller-card strong {
            color: #123c2a;
        }

        .installments {
            margin-top: 18px;
            color: #176534;
            font-size: 14px;
        }

        .buttons {
            display: flex;
            gap: 12px;
            margin-top: 25px;
            flex-wrap: wrap;
        }

        .btn {
            display: inline-block;
            padding: 12px 20px;
            border-radius: 6px;
            text-align: center;
            text-decoration: none;
            font-size: 14px;
            font-weight: bold;
        }

        .btn-primary {
            background: #16834a;
            color: white;
        }

        .btn-primary:hover {
            background: #116839;
        }

        .btn-secondary {
            background: #e9eee9;
            color: #26352b;
        }

        .btn-secondary:hover {
            background: #dce5dc;
        }

        .not-found {
            background: white;
            max-width: 650px;
            margin: 60px auto;
            padding: 45px 25px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.06);
        }

        .not-found h1 {
            margin-bottom: 15px;
        }

        .not-found p {
            color: #68766c;
            margin-bottom: 25px;
        }

        footer {
            background: #123c2a;
            color: white;
            text-align: center;
            padding: 25px;
        }

        footer p {
            color: #d6e4da;
            font-size: 14px;
        }

        @media (max-width: 800px) {
            .product-layout {
                grid-template-columns: 1fr;
                gap: 25px;
                padding: 20px;
            }

            .image-container {
                min-height: 280px;
            }

            .product-info h1 {
                font-size: 27px;
            }

            .price {
                font-size: 25px;
            }
        }

        @media (max-width: 500px) {
            header {
                align-items: flex-start;
                flex-direction: column;
            }

            nav {
                gap: 12px;
            }

            .product-layout {
                padding: 15px;
            }

            .buttons {
                flex-direction: column;
            }

            .btn {
                width: 100%;
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

            <a href="auth/logout.php">Logout</a>

        <?php else: ?>

            <a href="auth/login.php">Login</a>
            <a href="auth/register.php">Register</a>

        <?php endif; ?>
    </nav>
</header>

<?php if ($product_not_found): ?>

    <main class="not-found">
        <h1>Product Not Found</h1>

        <p>
            This product may have been removed, may not have been approved,
            or may currently be out of stock.
        </p>

        <a href="products.php" class="btn btn-primary">
            Return to Marketplace
        </a>
    </main>

<?php else: ?>

    <div class="breadcrumb">
        <a href="index.php">Home</a>
        &nbsp; / &nbsp;
        <a href="products.php">Marketplace</a>
        &nbsp; / &nbsp;
        <?= escapeHTML($product["product_name"]) ?>
    </div>

    <main class="product-container">

        <div class="product-layout">

            <div class="product-gallery">
                <div class="image-container">
                    <?php if ($image_src !== ""): ?>
                        <img
                            id="mainProductImage"
                            src="<?= escapeHTML($image_src) ?>"
                            alt="<?= escapeHTML($product["product_name"]) ?>"
                        >
                    <?php else: ?>
                        <div class="image-placeholder">
                            <span class="icon" aria-hidden="true">☀️</span>
                            <p>Product image unavailable</p>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (count($product_images) > 1): ?>
                    <div class="thumbnail-list" aria-label="More product images">
                        <?php foreach ($product_images as $index => $gallery_image): ?>
                            <button
                                type="button"
                                class="thumbnail-button<?= $index === 0 ? ' active' : '' ?>"
                                data-image="<?= escapeHTML($gallery_image) ?>"
                                aria-label="View product image <?= (int) ($index + 1) ?>"
                                aria-pressed="<?= $index === 0 ? 'true' : 'false' ?>"
                            >
                                <img
                                    src="<?= escapeHTML($gallery_image) ?>"
                                    alt=""
                                    loading="lazy"
                                >
                            </button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <section class="product-info">

                <span class="category">
                    <?= escapeHTML($product["category"]) ?>
                </span>

                <h1>
                    <?= escapeHTML($product["product_name"]) ?>
                </h1>

                <p class="price">
                    <?= escapeHTML(formatPrice($product["price"])) ?>
                </p>

                <span class="stock">
                    ✓ In stock:
                    <?= (int) $product["stock_quantity"] ?>
                    available
                </span>

                <div class="description-section">
                    <h2>Product Description</h2>

                    <p>
                        <?php
                        $description = trim(
                            (string) ($product["description"] ?? "")
                        );

                        echo $description !== ""
                            ? escapeHTML($description)
                            : "No description has been provided for this product.";
                        ?>
                    </p>
                </div>

                <?php if ((int) $product["installments_available"] === 1): ?>

                    <p class="installments">
                        ✓ Instalment payments are available for this product.
                    </p>

                <?php endif; ?>

                <div class="seller-card">

                    <h2>Seller Information</h2>

                    <p>
                        <strong>Seller:</strong>
                        <?= escapeHTML($product["seller_name"] ?? "SolarGo Seller") ?>
                    </p>

                    <?php if (!empty($product["seller_phone"])): ?>
                        <p>
                            <strong>Phone:</strong>
                            <?= escapeHTML($product["seller_phone"]) ?>
                        </p>
                    <?php endif; ?>

                    <?php if (!empty($product["seller_email"])): ?>
                        <p>
                            <strong>Email:</strong>
                            <?= escapeHTML($product["seller_email"]) ?>
                        </p>
                    <?php endif; ?>

                </div>

                <div class="buttons">

                    <a href="products.php" class="btn btn-secondary">
                        Back to Marketplace
                    </a>

                    <?php if (!isset($_SESSION["user_id"])): ?>

                        <a href="auth/login.php" class="btn btn-primary">
                            Login to Purchase
                        </a>

                    <?php elseif (($_SESSION["role"] ?? "") === "customer"): ?>

                        <a
                            href="customer/dashboard.php"
                            class="btn btn-primary"
                        >
                            Customer Dashboard
                        </a>

                    <?php else: ?>

                        <a href="products.php" class="btn btn-primary">
                            Continue Browsing
                        </a>

                    <?php endif; ?>

                </div>

            </section>

        </div>

    </main>

<?php endif; ?>

<footer>
    <p>
        &copy; <?= date("Y") ?> SolarGo.
        Power That Does Not Cut Out.
    </p>
</footer>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const mainImage = document.getElementById("mainProductImage");
    const thumbnailButtons = document.querySelectorAll(".thumbnail-button");

    if (!mainImage || thumbnailButtons.length === 0) return;

    thumbnailButtons.forEach(function (button) {
        button.addEventListener("click", function () {
            const newImage = button.getAttribute("data-image");
            if (!newImage) return;

            mainImage.src = newImage;
            thumbnailButtons.forEach(function (item) {
                item.classList.remove("active");
                item.setAttribute("aria-pressed", "false");
            });
            button.classList.add("active");
            button.setAttribute("aria-pressed", "true");
        });
    });
});
</script>

</body>
</html>
