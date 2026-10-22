
<?php
session_start();

require_once __DIR__ . "/../config/database.php";

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (
    !isset($_SESSION["user_id"]) ||
    !isset($_SESSION["role"]) ||
    $_SESSION["role"] !== "customer"
) {
    header("Location: ../auth/login.php");
    exit;
}

$customerId = (int) $_SESSION["user_id"];

$errors = [];
$success = "";

// Generate a CSRF token.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Retrieve the customer's current information.
$stmt = $conn->prepare("
    SELECT user_id, full_name, email, phone, password_hash, account_status
    FROM users
    WHERE user_id = ?
      AND role = 'customer'
    LIMIT 1
");

$stmt->bind_param("i", $customerId);
$stmt->execute();

$result = $stmt->get_result();
$customer = $result->fetch_assoc();
$stmt->close();

if (!$customer) {
    session_unset();
    session_destroy();

    header("Location: ../auth/login.php");
    exit;
}

// Only active accounts may update their profile.
if ($customer["account_status"] !== "active") {
    http_response_code(403);
    exit("Your account is not active. Please contact the administrator.");
}

$fullName = $customer["full_name"];
$email = $customer["email"];
$phone = $customer["phone"] ?? "";

function profileEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, "UTF-8");
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($token) ||
        !hash_equals($_SESSION["csrf_token"], $token)
    ) {
        $errors[] = "Invalid request. Please refresh the page and try again.";
    } else {

        $action = $_POST["action"] ?? "";

        // Update profile information.
        if ($action === "update_profile") {

            $fullName = trim($_POST["full_name"] ?? "");
            $email = trim($_POST["email"] ?? "");
            $phone = trim($_POST["phone"] ?? "");

            if ($fullName === "" || strlen($fullName) > 150) {
                $errors[] = "Enter a name containing no more than 150 characters.";
            }

            if (
                $email === "" ||
                !filter_var($email, FILTER_VALIDATE_EMAIL) ||
                strlen($email) > 190
            ) {
                $errors[] = "Enter a valid email address.";
            }

            if (strlen($phone) > 30) {
                $errors[] = "The phone number is too long.";
            }

            // Check whether another account uses this email.
            if (empty($errors)) {

                $check = $conn->prepare("
                    SELECT user_id
                    FROM users
                    WHERE email = ?
                      AND user_id != ?
                    LIMIT 1
                ");

                $check->bind_param("si", $email, $customerId);
                $check->execute();

                if ($check->get_result()->num_rows > 0) {
                    $errors[] = "This email address is already in use.";
                }

                $check->close();
            }

            // Save changes.
            if (empty($errors)) {

                $update = $conn->prepare("
                    UPDATE users
                    SET full_name = ?,
                        email = ?,
                        phone = ?
                    WHERE user_id = ?
                      AND role = 'customer'
                      AND account_status = 'active'
                ");

                $update->bind_param(
                    "sssi",
                    $fullName,
                    $email,
                    $phone,
                    $customerId
                );

                $update->execute();
                $update->close();

                $_SESSION["full_name"] = $fullName;

                $success = "Your profile has been updated successfully.";

                // Refresh the token after a successful update.
                $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
            }
        }

        // Change password.
        elseif ($action === "change_password") {

            $currentPassword = $_POST["current_password"] ?? "";
            $newPassword = $_POST["new_password"] ?? "";
            $confirmPassword = $_POST["confirm_password"] ?? "";

            if (
                !is_string($currentPassword) ||
                !is_string($newPassword) ||
                !is_string($confirmPassword)
            ) {
                $errors[] = "Invalid password information.";
            } else {

                if (
                    $currentPassword === "" ||
                    $newPassword === "" ||
                    $confirmPassword === ""
                ) {
                    $errors[] = "Complete all password fields.";
                }

                if (
                    strlen($newPassword) < 8 ||
                    strlen($newPassword) > 72
                ) {
                    $errors[] = "Your new password must contain 8–72 characters.";
                }

                if ($newPassword !== $confirmPassword) {
                    $errors[] = "The new passwords do not match.";
                }

                if (
                    $currentPassword !== "" &&
                    !password_verify(
                        $currentPassword,
                        $customer["password_hash"]
                    )
                ) {
                    $errors[] = "Your current password is incorrect.";
                }

                if (empty($errors)) {

                    $newHash = password_hash(
                        $newPassword,
                        PASSWORD_DEFAULT
                    );

                    $update = $conn->prepare("
                        UPDATE users
                        SET password_hash = ?
                        WHERE user_id = ?
                          AND role = 'customer'
                          AND account_status = 'active'
                    ");

                    $update->bind_param(
                        "si",
                        $newHash,
                        $customerId
                    );

                    $update->execute();
                    $update->close();

                    $customer["password_hash"] = $newHash;

                    $success = "Your password has been changed successfully.";

                    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
                }
            }
        }

        else {
            $errors[] = "Invalid form action.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>My Profile | SolarGo</title>

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
            padding: 18px 5%;
            color: white;
        }

        .header-inner {
            max-width: 1100px;
            margin: auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .logo {
            color: white;
            font-size: 26px;
            font-weight: bold;
            text-decoration: none;
        }

        nav {
            display: flex;
            gap: 18px;
            flex-wrap: wrap;
        }

        nav a {
            color: white;
            text-decoration: none;
            font-weight: bold;
        }

        main {
            max-width: 850px;
            margin: 35px auto;
            padding: 0 20px;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            color: #14532d;
            font-size: 30px;
        }

        .page-heading p {
            color: #647067;
        }

        .profile-card {
            background: white;
            padding: 28px;
            border-radius: 12px;
            border: 1px solid #dce6de;
            margin-bottom: 25px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
        }

        .profile-card h2 {
            color: #14532d;
            font-size: 21px;
            margin-bottom: 8px;
        }

        .card-description {
            color: #647067;
            font-size: 14px;
            margin-bottom: 22px;
        }

        .form-group {
            margin-bottom: 18px;
        }

        label {
            display: block;
            font-weight: bold;
            margin-bottom: 7px;
        }

        input {
            width: 100%;
            padding: 12px;
            border: 1px solid #cbd5ce;
            border-radius: 7px;
            font-size: 15px;
        }

        input:focus {
            outline: 2px solid #86c89b;
            border-color: #15803d;
        }

        .btn {
            display: inline-block;
            padding: 12px 20px;
            border: none;
            border-radius: 7px;
            background: #15803d;
            color: white;
            font-size: 15px;
            font-weight: bold;
            text-decoration: none;
            cursor: pointer;
        }

        .btn:hover {
            background: #166534;
        }

        .message {
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 22px;
        }

        .success {
            background: #dcfce7;
            color: #166534;
            border: 1px solid #86efac;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        .error ul {
            padding-left: 20px;
        }

        .account-info {
            background: #f0fdf4;
            padding: 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
        }

        footer {
            margin-top: 45px;
            padding: 22px;
            text-align: center;
            background: #e8efe9;
            color: #647067;
        }

        @media (max-width: 600px) {
            .profile-card {
                padding: 20px;
            }

            .page-heading h1 {
                font-size: 25px;
            }
        }
    </style>
</head>

<body>

<header>
    <div class="header-inner">

        <a href="../index.php" class="logo">SolarGo</a>

        <nav>
            <a href="../index.php">Home</a>
            <a href="../products.php">Products</a>
            <a href="orders.php">My Orders</a>
            <a href="dashboard.php">Dashboard</a>
        </nav>

    </div>
</header>

<main>

    <section class="page-heading">
        <h1>My Profile</h1>
        <p>Manage your personal information and account security.</p>
    </section>

    <?php if ($success !== ""): ?>
        <div class="message success">
            <?= profileEscape($success) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="message error">
            <strong>Please correct the following:</strong>

            <ul>
                <?php foreach ($errors as $error): ?>
                    <li><?= profileEscape($error) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <section class="profile-card">

        <h2>Personal Information</h2>

        <p class="card-description">
            Keep your contact details up to date.
        </p>

        <div class="account-info">
            <strong>Account type:</strong> Customer
            <br>
            <strong>Account status:</strong>
            <?= profileEscape(ucfirst($customer["account_status"])) ?>
        </div>

        <form method="POST" action="profile.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= profileEscape($_SESSION["csrf_token"]) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="update_profile"
            >

            <div class="form-group">
                <label for="full_name">Full Name</label>

                <input
                    type="text"
                    id="full_name"
                    name="full_name"
                    maxlength="150"
                    value="<?= profileEscape($fullName) ?>"
                    autocomplete="name"
                    required
                >
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>

                <input
                    type="email"
                    id="email"
                    name="email"
                    maxlength="190"
                    value="<?= profileEscape($email) ?>"
                    autocomplete="email"
                    required
                >
            </div>

            <div class="form-group">
                <label for="phone">Phone Number</label>

                <input
                    type="tel"
                    id="phone"
                    name="phone"
                    maxlength="30"
                    value="<?= profileEscape($phone) ?>"
                    autocomplete="tel"
                >
            </div>

            <button type="submit" class="btn">
                Save Changes
            </button>

        </form>

    </section>

    <section class="profile-card">

        <h2>Change Password</h2>

        <p class="card-description">
            Enter your current password before choosing a new one.
        </p>

        <form method="POST" action="profile.php">

            <input
                type="hidden"
                name="csrf_token"
                value="<?= profileEscape($_SESSION["csrf_token"]) ?>"
            >

            <input
                type="hidden"
                name="action"
                value="change_password"
            >

            <div class="form-group">
                <label for="current_password">Current Password</label>

                <input
                    type="password"
                    id="current_password"
                    name="current_password"
                    autocomplete="current-password"
                    required
                >
            </div>

            <div class="form-group">
                <label for="new_password">New Password</label>

                <input
                    type="password"
                    id="new_password"
                    name="new_password"
                    minlength="8"
                    maxlength="72"
                    autocomplete="new-password"
                    required
                >

                <small>Use at least 8 characters.</small>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm New Password</label>

                <input
                    type="password"
                    id="confirm_password"
                    name="confirm_password"
                    minlength="8"
                    maxlength="72"
                    autocomplete="new-password"
                    required
                >
            </div>

            <button type="submit" class="btn">
                Change Password
            </button>

        </form>

    </section>

</main>

<footer>
    <p>&copy; <?= date("Y") ?> SolarGo. Powering a brighter future.</p>
</footer>

</body>
</html>
