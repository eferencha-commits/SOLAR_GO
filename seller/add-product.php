
<?php

session_start();

require_once __DIR__ . "/../config/database.php";

// 1. Check whether the user is logged in
if (!isset($_SESSION["user_id"])) {
    header("Location: ../auth/login.php");
    exit;
}

// 2. Only sellers can add products
if (($_SESSION["role"] ?? "") !== "seller") {
    http_response_code(403);
    exit("Access denied. Only sellers can add products.");
}

$seller_id = (int) $_SESSION["user_id"];

// 3. Verify the seller's current database status
$user_sql = "
    SELECT full_name, role, account_status
    FROM users
    WHERE user_id = ?
    LIMIT 1
";

$user_stmt = $conn->prepare($user_sql);

if (!$user_stmt) {
    error_log("Seller verification preparation failed: " . $conn->error);
    http_response_code(500);
    exit("Unable to verify your account. Please try again later.");
}

$user_stmt->bind_param("i", $seller_id);

if (!$user_stmt->execute()) {
    error_log("Seller verification execution failed: " . $user_stmt->error);
    $user_stmt->close();

    http_response_code(500);
    exit("Unable to verify your account. Please try again later.");
}

$user_result = $user_stmt->get_result();
$seller = $user_result->fetch_assoc();
$user_stmt->close();

// Reject accounts that no longer exist
if (!$seller) {
    $_SESSION = [];
    session_destroy();

    header("Location: ../auth/login.php");
    exit;
}

// Verify role against the database
if ($seller["role"] !== "seller") {
    http_response_code(403);
    exit("Access denied. Your account does not have seller permissions.");
}

// Block pending and suspended sellers
if ($seller["account_status"] !== "active") {
    $_SESSION = [];
    session_destroy();

    header("Location: ../auth/login.php");
    exit;
}

// Use the current seller name
$_SESSION["full_name"] = $seller["full_name"];

// 4. Initialize form values
$errors = [];
$product_name = "";
$category = "";
$description = "";
$price = "";
$stock_quantity = "";
$installments_available = 0;

// 5. Create CSRF token
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// 6. Allowed product categories
$categories = [
    "Solar Panels",
    "Solar Batteries",
    "Solar Inverters",
    "Solar Generators",
    "Charge Controllers",
    "Solar Accessories",
    "Other"
];

// 7. Process form submission
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Validate CSRF token
    $submitted_token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($submitted_token) ||
        !hash_equals($_SESSION["csrf_token"], $submitted_token)
    ) {
        $errors[] = "Invalid form submission. Please refresh and try again.";
    }

    // Collect form data
    $product_name = trim($_POST["product_name"] ?? "");
    $category = trim($_POST["category"] ?? "");
    $description = trim($_POST["description"] ?? "");
    $price = trim($_POST["price"] ?? "");
    $stock_quantity = trim($_POST["stock_quantity"] ?? "");

    $installments_available =
        isset($_POST["installments_available"]) ? 1 : 0;

    // Validate product name
    if ($product_name === "") {
        $errors[] = "Product name is required.";
    } elseif (strlen($product_name) > 255) {
        $errors[] = "Product name must not exceed 255 characters.";
    }

    // Validate category
    if (!in_array($category, $categories, true)) {
        $errors[] = "Please select a valid product category.";
    }

    // Validate description
    if ($description === "") {
        $errors[] = "Product description is required.";
    }

    // Validate price
    if (
        !is_numeric($price) ||
        !is_finite((float) $price) ||
        (float) $price <= 0
    ) {
        $errors[] = "Enter a valid price greater than zero.";
    }

    // Validate stock quantity
    if (
        $stock_quantity === "" ||
        !ctype_digit($stock_quantity) ||
        (int) $stock_quantity > 2147483647
    ) {
        $errors[] = "Enter a valid stock quantity.";
    }

    // 8. Validate multiple image uploads
    $allowed_images = [
        "image/jpeg" => "jpg",
        "image/png"  => "png",
        "image/webp" => "webp"
    ];

    $valid_images = [];
    $max_images = 5;
    $max_image_size = 5 * 1024 * 1024;

    if (
        !isset($_FILES["product_images"]) ||
        !is_array($_FILES["product_images"]["name"] ?? null)
    ) {
        $errors[] = "Please select at least one product image.";

    } else {

        $file_count = count($_FILES["product_images"]["name"]);

        if ($file_count < 1) {
            $errors[] = "Please select at least one product image.";

        } elseif ($file_count > $max_images) {
            $errors[] = "You can upload a maximum of 5 images per product.";

        } else {

            $finfo = new finfo(FILEINFO_MIME_TYPE);

            for ($i = 0; $i < $file_count; $i++) {

                $upload_error =
                    $_FILES["product_images"]["error"][$i]
                    ?? UPLOAD_ERR_NO_FILE;

                if ($upload_error !== UPLOAD_ERR_OK) {

                    if ($upload_error === UPLOAD_ERR_NO_FILE) {
                        $errors[] = "Please select an image for each chosen file.";

                    } elseif (
                        $upload_error === UPLOAD_ERR_INI_SIZE ||
                        $upload_error === UPLOAD_ERR_FORM_SIZE
                    ) {
                        $errors[] = "One of the images exceeds the server upload limit.";

                    } else {
                        $errors[] = "One of the images failed to upload. Please try again.";
                    }

                    continue;
                }

                $tmp_name =
                    $_FILES["product_images"]["tmp_name"][$i] ?? "";

                $size =
                    (int) ($_FILES["product_images"]["size"][$i] ?? 0);

                if ($size <= 0 || $size > $max_image_size) {
                    $errors[] = "Each image must be no larger than 5 MB.";
                    continue;
                }

                if (!is_uploaded_file($tmp_name)) {
                    $errors[] = "An uploaded image could not be verified.";
                    continue;
                }

                $mime_type = $finfo->file($tmp_name);
                $image_info = @getimagesize($tmp_name);

                if (
                    !is_string($mime_type) ||
                    !isset($allowed_images[$mime_type]) ||
                    $image_info === false
                ) {
                    $errors[] = "All files must be valid JPG, PNG, or WEBP images.";
                    continue;
                }

                $valid_images[] = [
                    "tmp_name" => $tmp_name,
                    "extension" => $allowed_images[$mime_type]
                ];
            }
        }
    }

    if (empty($valid_images) && empty($errors)) {
        $errors[] = "Please select at least one valid product image.";
    }

    // 9. Save files and database records only after validation succeeds
    if (empty($errors)) {

        $upload_directory = __DIR__ . "/../uploads/products/";

        if (
            !is_dir($upload_directory) &&
            !mkdir($upload_directory, 0755, true)
        ) {
            $errors[] = "Unable to create the product image folder.";
        }

        if (
            empty($errors) &&
            !is_writable($upload_directory)
        ) {
            $errors[] = "The product image folder is not writable.";
        }

        $saved_files = [];
        $image_paths = [];

        // Upload image files
        if (empty($errors)) {

            foreach ($valid_images as $image) {

                $filename =
                    bin2hex(random_bytes(16)) .
                    "." .
                    $image["extension"];

                $destination = $upload_directory . $filename;

                $relative_path =
                    "uploads/products/" . $filename;

                if (
                    !move_uploaded_file(
                        $image["tmp_name"],
                        $destination
                    )
                ) {
                    $errors[] = "Unable to save one of the uploaded images.";
                    break;
                }

                $saved_files[] = $destination;
                $image_paths[] = $relative_path;
            }
        }

        // Save product and image paths in a single transaction
        if (empty($errors)) {

            $conn->begin_transaction();

            $product_stmt = null;
            $image_stmt = null;

            try {

                $product_sql = "
                    INSERT INTO products (
                        seller_id,
                        product_name,
                        category,
                        description,
                        price,
                        stock_quantity,
                        image_url,
                        installments_available,
                        listing_status
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ";

                $product_stmt = $conn->prepare($product_sql);

                if (!$product_stmt) {
                    throw new RuntimeException(
                        "Product insert preparation failed: " . $conn->error
                    );
                }

                $price_value = (float) $price;
                $stock_value = (int) $stock_quantity;
                $image_path = $image_paths[0];
                $listing_status = "pending";

                $product_stmt->bind_param(
                    "isssdisis",
                    $seller_id,
                    $product_name,
                    $category,
                    $description,
                    $price_value,
                    $stock_value,
                    $image_path,
                    $installments_available,
                    $listing_status
                );

                if (!$product_stmt->execute()) {
                    throw new RuntimeException(
                        "Product insert failed: " . $product_stmt->error
                    );
                }

                $product_id = (int) $conn->insert_id;

                $product_stmt->close();
                $product_stmt = null;

                // Insert all product images
                $image_sql = "
                    INSERT INTO product_images (
                        product_id,
                        image_url,
                        is_primary
                    )
                    VALUES (?, ?, ?)
                ";

                $image_stmt = $conn->prepare($image_sql);

                if (!$image_stmt) {
                    throw new RuntimeException(
                        "Product image insert preparation failed: " . $conn->error
                    );
                }

                foreach ($image_paths as $index => $path) {

                    $is_primary = ($index === 0) ? 1 : 0;

                    $image_stmt->bind_param(
                        "isi",
                        $product_id,
                        $path,
                        $is_primary
                    );

                    if (!$image_stmt->execute()) {
                        throw new RuntimeException(
                            "Product image insert failed: " . $image_stmt->error
                        );
                    }
                }

                $image_stmt->close();
                $image_stmt = null;

                // Commit only when every database insert succeeds
                $conn->commit();

                // Rotate CSRF token after successful submission
                $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                header("Location: dashboard.php?submitted=1");
                exit;

            } catch (Throwable $e) {

                $conn->rollback();

                error_log(
                    "SolarGo product submission failed: " . $e->getMessage()
                );

                if ($product_stmt instanceof mysqli_stmt) {
                    $product_stmt->close();
                }

                if ($image_stmt instanceof mysqli_stmt) {
                    $image_stmt->close();
                }

                $errors[] =
                    "Unable to save the product and its images. Please try again.";
            }
        }

        // Remove uploaded files if saving fails
        if (!empty($errors)) {

            foreach ($saved_files as $file_path) {

                if (is_file($file_path)) {
                    @unlink($file_path);
                }
            }
        }
    }
}

// 10. Helper for safe HTML output
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

    <title>Add Product | SolarGo</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f7f5;
            color: #24332b;
            line-height: 1.6;
        }

        header {
            background: #123c2a;
            color: white;
            padding: 20px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
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
            flex-wrap: wrap;
            gap: 18px;
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
            max-width: 800px;
            margin: 35px auto;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            font-size: 29px;
            margin-bottom: 8px;
        }

        .page-heading p {
            color: #647269;
        }

        .form-card {
            background: white;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.06);
        }

        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            margin-bottom: 8px;
            font-weight: bold;
            font-size: 14px;
        }

        .required {
            color: #c62828;
        }

        input,
        select,
        textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid #ccd5ce;
            border-radius: 6px;
            font-size: 15px;
            font-family: inherit;
        }

        input:focus,
        select:focus,
        textarea:focus {
            outline: 2px solid #16834a;
            border-color: #16834a;
        }

        textarea {
            min-height: 120px;
            resize: vertical;
        }

        input[type="file"] {
            background: #f8faf8;
            padding: 12px;
        }

        .help-text {
            display: block;
            font-size: 12px;
            color: #647269;
            margin-top: 6px;
        }

        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .checkbox-group input {
            width: auto;
        }

        .checkbox-group label {
            margin: 0;
            font-weight: normal;
        }

        .btn {
            display: inline-block;
            background: #16834a;
            color: white;
            padding: 13px 20px;
            border: none;
            border-radius: 6px;
            text-decoration: none;
            font-size: 15px;
            cursor: pointer;
        }

        .btn:hover {
            background: #116839;
        }

        .btn-secondary {
            background: #68756c;
        }

        .btn-secondary:hover {
            background: #4c5950;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-top: 25px;
        }

        .errors {
            background: #ffe8e8;
            color: #a12525;
            border: 1px solid #efb8b8;
            border-radius: 7px;
            padding: 15px 20px;
            margin-bottom: 22px;
        }

        .errors ul {
            padding-left: 20px;
        }

        .notice {
            background: #fff4cf;
            color: #795900;
            border: 1px solid #eed78b;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 13px;
        }

        #imagePreview {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(110px, 1fr));
            gap: 10px;
            margin-top: 15px;
        }

        #imagePreview img {
            width: 100%;
            height: 110px;
            object-fit: contain;
            border-radius: 8px;
            border: 1px solid #ddd;
            background: #f8faf8;
        }

        footer {
            text-align: center;
            padding: 25px;
            color: #647269;
        }

        @media (max-width: 600px) {
            .form-card {
                padding: 20px;
            }

            .page-heading h1 {
                font-size: 24px;
            }
        }
    </style>
</head>

<body>

<header>
    <div class="logo">Solar<span>Go</span></div>

    <nav>
        <a href="../index.php">Home</a>
        <a href="../products.php">Marketplace</a>
        <a href="dashboard.php">Dashboard</a>
        <a href="../auth/logout.php">Logout</a>
    </nav>
</header>

<main class="container">

    <div class="page-heading">
        <h1>Add a Solar Product</h1>

        <p>
            Enter your product details and upload a clear image
            so customers can see what you are selling.
        </p>
    </div>

    <div class="form-card">

        <div class="notice">
            New products must be approved by SolarGo's administrator
            before they appear in the public marketplace.
        </div>

        <?php if (!empty($errors)): ?>

            <div class="errors">
                <strong>Please correct the following:</strong>

                <ul>
                    <?php foreach ($errors as $error): ?>
                        <li><?= escapeHTML($error) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>

        <?php endif; ?>

        <form method="POST"
              action=""
              enctype="multipart/form-data">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= escapeHTML($_SESSION["csrf_token"]) ?>"
            >

            <div class="form-group">
                <label for="product_name">
                    Product Name <span class="required">*</span>
                </label>

                <input
                    type="text"
                    id="product_name"
                    name="product_name"
                    maxlength="255"
                    value="<?= escapeHTML($product_name) ?>"
                    placeholder="e.g. 200W Solar Panel"
                    required
                >
            </div>

            <div class="form-group">
                <label for="category">
                    Product Category <span class="required">*</span>
                </label>

                <select id="category" name="category" required>
                    <option value="">-- Select Category --</option>

                    <?php foreach ($categories as $item): ?>
                        <option
                            value="<?= escapeHTML($item) ?>"
                            <?= $category === $item ? "selected" : "" ?>
                        >
                            <?= escapeHTML($item) ?>
                        </option>
                    <?php endforeach; ?>

                </select>
            </div>

            <div class="form-group">
                <label for="description">
                    Product Description <span class="required">*</span>
                </label>

                <textarea
                    id="description"
                    name="description"
                    placeholder="Describe the product, its capacity, features, and benefits..."
                    required
                ><?= escapeHTML($description) ?></textarea>
            </div>

            <div class="form-group">
                <label for="price">
                    Price in FCFA <span class="required">*</span>
                </label>

                <input
                    type="number"
                    id="price"
                    name="price"
                    min="0.01"
                    step="0.01"
                    value="<?= escapeHTML($price) ?>"
                    placeholder="e.g. 75000"
                    required
                >
            </div>

            <div class="form-group">
                <label for="stock_quantity">
                    Available Quantity <span class="required">*</span>
                </label>

                <input
                    type="number"
                    id="stock_quantity"
                    name="stock_quantity"
                    min="0"
                    step="1"
                    value="<?= escapeHTML($stock_quantity) ?>"
                    placeholder="e.g. 10"
                    required
                >
            </div>

            <div class="form-group">
                <label for="product_images">
                    Product Images <span class="required">*</span>
                </label>

                <input
                    type="file"
                    id="product_images"
                    name="product_images[]"
                    accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp"
                    multiple
                    required
                >

                <small class="help-text">
                    Select 1 to 5 images. Accepted formats: JPG, PNG, and WEBP.
                    Maximum size: 5 MB per image. The first image will be the main image.
                </small>

                <div id="imagePreview" aria-live="polite"></div>
            </div>

            <div class="form-group checkbox-group">
                <input
                    type="checkbox"
                    id="installments_available"
                    name="installments_available"
                    value="1"
                    <?= $installments_available ? "checked" : "" ?>
                >

                <label for="installments_available">
                    Allow customers to pay in instalments
                </label>
            </div>

            <div class="form-actions">

                <button type="submit" class="btn">
                    Submit Product
                </button>

                <a href="dashboard.php" class="btn btn-secondary">
                    Cancel
                </a>

            </div>

        </form>

    </div>

</main>

<footer>
    &copy; <?= date("Y") ?> SolarGo.
    Power That Does Not Cut Out.
</footer>

<script>
const imageInput = document.getElementById("product_images");
const imagePreview = document.getElementById("imagePreview");

imageInput.addEventListener("change", function () {
    imagePreview.innerHTML = "";
    const files = Array.from(this.files);
    const allowedTypes = ["image/jpeg", "image/png", "image/webp"];

    if (files.length > 5) {
        alert("You can select a maximum of 5 images.");
        this.value = "";
        return;
    }

    for (const file of files) {
        if (!allowedTypes.includes(file.type)) {
            alert("Please select only JPG, PNG, or WEBP images.");
            this.value = "";
            imagePreview.innerHTML = "";
            return;
        }
        if (file.size > 5 * 1024 * 1024) {
            alert("Each image must not exceed 5 MB.");
            this.value = "";
            imagePreview.innerHTML = "";
            return;
        }
    }

    files.forEach((file, index) => {
        const reader = new FileReader();
        reader.onload = function (event) {
            const img = document.createElement("img");
            img.src = event.target.result;
            img.alt = index === 0 ? "Main product image preview" : "Product image preview " + (index + 1);
            img.title = index === 0 ? "Main image" : "Image " + (index + 1);
            imagePreview.appendChild(img);
        };
        reader.readAsDataURL(file);
    });
});
</script>

</body>
</html>

