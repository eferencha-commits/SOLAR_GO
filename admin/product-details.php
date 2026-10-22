
<?php
session_start();

require_once __DIR__ . "/../config/database.php";

// Only administrators can access this page.
if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "admin"
) {
    header("Location: ../auth/login.php");
    exit;
}

// Create CSRF token if necessary.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

// Validate product ID.
$productId = filter_input(
    INPUT_GET,
    "id",
    FILTER_VALIDATE_INT
);

if (!$productId) {
    header("Location: dashboard.php?error=invalid_product_request");
    exit;
}

// Retrieve product and seller information.
$stmt = $conn->prepare(
    "SELECT
        p.product_id,
        p.product_name,
        p.category,
        p.description,
        p.price,
        p.stock_quantity,
        p.image_url,
        p.listing_status,
        p.created_at,
        u.full_name AS seller_name,
        u.email AS seller_email,
        u.phone AS seller_phone
     FROM products p
     LEFT JOIN users u
        ON p.seller_id = u.user_id
     WHERE p.product_id = ?
     LIMIT 1"
);

if (!$stmt) {
    error_log("Product details query error: " . $conn->error);
    exit("Unable to load product details.");
}

$stmt->bind_param("i", $productId);
$stmt->execute();

$result = $stmt->get_result();
$product = $result->fetch_assoc();

$stmt->close();

if (!$product) {
    http_response_code(404);
    exit("Product not found.");
}

// Retrieve additional product images.
$images = [];

$imageStmt = $conn->prepare(
    "SELECT image_url, is_primary
     FROM product_images
     WHERE product_id = ?
     ORDER BY is_primary DESC, image_id ASC"
);

if ($imageStmt) {
    $imageStmt->bind_param("i", $productId);
    $imageStmt->execute();

    $imageResult = $imageStmt->get_result();

    while ($image = $imageResult->fetch_assoc()) {
        $images[] = $image;
    }

    $imageStmt->close();
}

// Include the main product image as a fallback.
$mainImage = trim((string) ($product["image_url"] ?? ""));

if ($mainImage !== "") {
    $alreadyIncluded = false;

    foreach ($images as $image) {
        if ($image["image_url"] === $mainImage) {
            $alreadyIncluded = true;
            break;
        }
    }

    if (!$alreadyIncluded) {
        array_unshift($images, [
            "image_url" => $mainImage,
            "is_primary" => 1
        ]);
    }
}

// Build a safe image URL for files stored in uploads/products.
function productImageUrl($path): ?string
{
    $path = trim((string) $path);

    if ($path === "") {
        return null;
    }

    $filename = basename(str_replace("\\", "/", $path));

    if ($filename === "" || $filename === "." || $filename === "..") {
        return null;
    }

    $filePath = __DIR__ . "/../uploads/products/" . $filename;

    if (!is_file($filePath)) {
        return null;
    }

    return "../uploads/products/" . rawurlencode($filename);
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Product Details | SolarGo Admin</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f7f5;
            color: #1f2937;
            line-height: 1.6;
        }

        .navbar {
            background: #fff;
            padding: 18px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #e5e7eb;
        }

        .logo {
            font-size: 26px;
            font-weight: 800;
            color: #15803d;
            text-decoration: none;
        }

        .logo span {
            color: #f59e0b;
        }

        .back-link {
            color: #15803d;
            font-weight: 600;
            text-decoration: none;
        }

        .container {
            width: 90%;
            max-width: 1150px;
            margin: 35px auto;
        }

        .page-title {
            margin-bottom: 25px;
        }

        .page-title h1 {
            font-size: 28px;
            margin-bottom: 5px;
        }

        .page-title p {
            color: #6b7280;
        }

        .product-layout {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 25px;
            align-items: start;
        }

        .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
        }

        .main-image {
            width: 100%;
            height: 350px;
            object-fit: contain;
            background: #f9fafb;
            border-radius: 8px;
        }

        .no-image {
            height: 300px;
            display: flex;
            justify-content: center;
            align-items: center;
            background: #f3f4f6;
            border-radius: 8px;
            color: #6b7280;
        }

        .thumbnail-grid {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 10px;
            margin-top: 15px;
        }

        .thumbnail {
            width: 100%;
            height: 85px;
            object-fit: cover;
            border: 2px solid transparent;
            border-radius: 6px;
            cursor: pointer;
        }

        .thumbnail.active {
            border-color: #15803d;
        }

        .product-title {
            font-size: 25px;
            margin-bottom: 12px;
        }

        .price {
            font-size: 27px;
            color: #15803d;
            font-weight: 800;
            margin-bottom: 20px;
        }

        .detail-row {
            padding: 12px 0;
            border-bottom: 1px solid #e5e7eb;
        }

        .detail-row strong {
            display: inline-block;
            min-width: 125px;
        }

        .description {
            margin-top: 22px;
        }

        .description h3 {
            margin-bottom: 8px;
        }

        .description p {
            color: #4b5563;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .status {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 20px;
            background: #fef3c7;
            color: #92400e;
            font-size: 13px;
            font-weight: 700;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 25px;
        }

        .actions form {
            flex: 1;
        }

        .actions button {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-weight: 700;
            cursor: pointer;
        }

        .approve {
            background: #15803d;
            color: white;
        }

        .reject {
            background: #fee2e2;
            color: #991b1b;
        }

        .notice {
            margin-top: 15px;
            color: #6b7280;
            font-size: 13px;
        }

        @media (max-width: 800px) {
            .product-layout {
                grid-template-columns: 1fr;
            }

            .container {
                width: 94%;
            }

            .main-image {
                height: 280px;
            }
        }

        @media (max-width: 450px) {
            .card {
                padding: 16px;
            }

            .thumbnail-grid {
                grid-template-columns: repeat(3, minmax(0, 1fr));
            }

            .detail-row strong {
                min-width: 100px;
            }
        }
    </style>
</head>

<body>

<nav class="navbar">
    <a href="dashboard.php" class="logo">Solar<span>Go</span></a>
    <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
</nav>

<main class="container">

    <div class="page-title">
        <h1>Review Product</h1>
        <p>Inspect the product information and images before making a decision.</p>
    </div>

    <div class="product-layout">

        <!-- PRODUCT IMAGES -->
        <section class="card">

            <?php
            $firstImageUrl = null;

            foreach ($images as $image) {
                $candidate = productImageUrl($image["image_url"]);

                if ($candidate !== null) {
                    $firstImageUrl = $candidate;
                    break;
                }
            }
            ?>

            <?php if ($firstImageUrl): ?>

                <img
                    id="mainProductImage"
                    class="main-image"
                    src="<?= e($firstImageUrl) ?>"
                    alt="<?= e($product["product_name"]) ?>"
                >

                <div class="thumbnail-grid">

                    <?php foreach ($images as $image): ?>

                        <?php $url = productImageUrl($image["image_url"]); ?>

                        <?php if ($url): ?>

                            <img
                                class="thumbnail"
                                src="<?= e($url) ?>"
                                alt="Product image"
                                onclick="changeImage(this)"
                            >

                        <?php endif; ?>

                    <?php endforeach; ?>

                </div>

            <?php else: ?>

                <div class="no-image">
                    No product images available
                </div>

            <?php endif; ?>

        </section>


        <!-- PRODUCT INFORMATION -->
        <section class="card">

            <h2 class="product-title">
                <?= e($product["product_name"]) ?>
            </h2>

            <div class="price">
                <?= number_format((float) $product["price"], 0) ?> FCFA
            </div>

            <div class="detail-row">
                <strong>Product ID:</strong>
                <?= (int) $product["product_id"] ?>
            </div>

            <div class="detail-row">
                <strong>Category:</strong>
                <?= e($product["category"]) ?>
            </div>

            <div class="detail-row">
                <strong>Stock:</strong>
                <?= (int) $product["stock_quantity"] ?>
            </div>

            <div class="detail-row">
                <strong>Seller:</strong>
                <?= e($product["seller_name"] ?? "Unknown seller") ?>
            </div>

            <div class="detail-row">
                <strong>Seller Email:</strong>
                <?= e($product["seller_email"] ?? "Not available") ?>
            </div>

            <div class="detail-row">
                <strong>Seller Phone:</strong>
                <?= e($product["seller_phone"] ?? "Not available") ?>
            </div>

            <div class="detail-row">
                <strong>Submitted:</strong>
                <?= e($product["created_at"]) ?>
            </div>

            <div class="detail-row">
                <strong>Status:</strong>
                <span class="status">
                    <?= e(ucfirst($product["listing_status"])) ?>
                </span>
            </div>

            <div class="description">
                <h3>Product Description</h3>

                <p>
                    <?= e(
                        $product["description"] ?: "No description provided."
                    ) ?>
                </p>
            </div>


            <?php if ($product["listing_status"] === "pending"): ?>

                <div class="actions">

                    <form
                        action="process-product.php"
                        method="POST"
                        onsubmit="return confirm('Approve this product?');"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($_SESSION["csrf_token"]) ?>"
                        >

                        <input
                            type="hidden"
                            name="product_id"
                            value="<?= (int) $product["product_id"] ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="approve"
                        >

                        <button class="approve" type="submit">
                            Approve Product
                        </button>

                    </form>


                    <form
                        action="process-product.php"
                        method="POST"
                        onsubmit="return confirm('Reject this product?');"
                    >

                        <input
                            type="hidden"
                            name="csrf_token"
                            value="<?= e($_SESSION["csrf_token"]) ?>"
                        >

                        <input
                            type="hidden"
                            name="product_id"
                            value="<?= (int) $product["product_id"] ?>"
                        >

                        <input
                            type="hidden"
                            name="action"
                            value="reject"
                        >

                        <button class="reject" type="submit">
                            Reject Product
                        </button>

                    </form>

                </div>

                <p class="notice">
                    Approving a product allows it to appear in the marketplace
                    if the marketplace query and stock conditions are satisfied.
                </p>

            <?php else: ?>

                <p class="notice">
                    This product has already been processed. Its current status
                    is <?= e($product["listing_status"]) ?>.
                </p>

            <?php endif; ?>

        </section>

    </div>

</main>

<script>
function changeImage(thumbnail) {
    const mainImage = document.getElementById("mainProductImage");

    if (!mainImage) {
        return;
    }

    mainImage.src = thumbnail.src;

    document.querySelectorAll(".thumbnail").forEach(function (image) {
        image.classList.remove("active");
    });

    thumbnail.classList.add("active");
}

document.querySelectorAll(".thumbnail").forEach(function (thumbnail, index) {
    if (index === 0) {
        thumbnail.classList.add("active");
    }
});
</script>

</body>
</html>
