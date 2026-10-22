<?php

session_start();

//require_once __DIR__ . "/auth/database.php";

// Store errors and success messages
$errors = [];
$email = "";

// Create a CSRF token for form security
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Process the form when submitted
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Validate the CSRF token
    $submitted_token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($submitted_token) ||
        !hash_equals($_SESSION["csrf_token"], $submitted_token)
    ) {
        $errors[] = "Invalid form submission. Please refresh the page.";
    }

    // Get form inputs
    $email = trim($_POST["email"] ?? "");
    $password = $_POST["password"] ?? "";

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    // Validate password
    if (!is_string($password) || $password === "") {
        $errors[] = "Please enter your password.";
    }

    // Find the user in the database
    if (empty($errors)) {

        $sql = "
            SELECT user_id, full_name, email, password_hash, role, account_status
            FROM users
            WHERE email = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);

        if (!$stmt) {
            error_log("SolarGo login query preparation failed.");
            $errors[] = "Something went wrong. Please try again.";
        } else {

            $stmt->bind_param("s", $email);
            $stmt->execute();

            $result = $stmt->get_result();
            $user = $result->fetch_assoc();

            $stmt->close();

            // Verify the user's password
            if (
                !$user ||
                !password_verify($password, $user["password_hash"])
            ) {

                $errors[] = "Incorrect email or password.";

            } elseif ($user["account_status"] !== "active") {

                if ($user["account_status"] === "pending") {
                    $errors[] = "Your account is awaiting administrator approval.";
                } else {
                    $errors[] = "Your account is not active. Please contact the administrator.";
                }

            } else {

                // Regenerate the session ID after login
                session_regenerate_id(true);

                // Store essential user information in the session
                $_SESSION["user_id"] = (int) $user["user_id"];
                $_SESSION["full_name"] = $user["full_name"];
                $_SESSION["email"] = $user["email"];
                $_SESSION["role"] = $user["role"];

                // Rotate the CSRF token
                $_SESSION["csrf_token"] = bin2hex(random_bytes(32));

                // Redirect according to the user's role
                switch ($user["role"]) {

                    case "customer":
                        header("Location: ../customer/dashboard.php");
                        exit;

                    case "seller":
                        header("Location: ../seller/dashboard.php");
                        exit;

                    case "technician":
                        header("Location: ../technician/dashboard.php");
                        exit;

                    case "admin":
                        header("Location: ../admin/dashboard.php");
                        exit;

                    default:
                        // Reject unexpected roles
                        $_SESSION = [];
                        session_destroy();

                        header("Location: login.php");
                        exit;
                }
            }
        }
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Login | SolarGo</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>
        .login-section {
            min-height: 85vh;
            padding: 60px 20px;
            background: #f4faf5;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .login-card {
            width: 100%;
            max-width: 450px;
            background: #ffffff;
            padding: 35px;
            border-radius: 15px;
            box-shadow: 0 10px 35px #0000000d;
        }

        .login-card h1 {
            margin-bottom: 10px;
            font-size: 30px;
        }

        .login-description {
            color: #647067;
            margin-bottom: 25px;
            line-height: 1.6;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            margin-bottom: 7px;
            font-size: 14px;
            font-weight: 700;
        }

        .form-group input {
            width: 100%;
            padding: 13px;
            border: 1px solid #dce6dd;
            border-radius: 7px;
            font-size: 15px;
            background: #ffffff;
        }

        .form-group input:focus {
            outline: 2px solid #16834a;
            border-color: #16834a;
        }

        .login-submit {
            width: 100%;
            cursor: pointer;
            font-size: 15px;
        }

        .form-message {
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 7px;
            font-size: 14px;
        }

        .error-message {
            background: #fff0f0;
            color: #a52020;
        }

        .form-footer {
            margin-top: 22px;
            text-align: center;
            color: #647067;
            font-size: 14px;
        }

        .form-footer a,
        .back-home {
            color: #16834a;
            font-weight: 700;
        }

        .back-home {
            display: inline-block;
            margin-bottom: 20px;
        }

        @media (max-width: 500px) {
            .login-card {
                padding: 25px 20px;
            }
        }
    </style>
</head>

<body>

    <section class="login-section">

        <div class="login-card">

            <a href="../index.php" class="back-home">
                &larr; Back to SolarGo
            </a>

            <h1>Welcome Back!</h1>

            <p class="login-description">
                Log in to your SolarGo account to access your dashboard
                and manage your solar energy activities.
            </p>

            <?php if (!empty($errors)): ?>

                <div class="form-message error-message">

                    <?php foreach ($errors as $error): ?>

                        <p>
                            <?= htmlspecialchars(
                                $error,
                                ENT_QUOTES,
                                "UTF-8"
                            ) ?>
                        </p>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>

            <form method="POST" action="login.php">

                <input
                    type="hidden"
                    name="csrf_token"
                    value="<?= htmlspecialchars(
                        $_SESSION["csrf_token"],
                        ENT_QUOTES,
                        "UTF-8"
                    ) ?>"
                >

                <div class="form-group">

                    <label for="email">Email Address</label>

                    <input
                        type="email"
                        id="email"
                        name="email"
                        value="<?= htmlspecialchars(
                            $email,
                            ENT_QUOTES,
                            "UTF-8"
                        ) ?>"
                        placeholder="Enter your email"
                        autocomplete="email"
                        required
                    >

                </div>

                <div class="form-group">

                    <label for="password">Password</label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password"
                        required
                    >

                </div>

                <button type="submit" class="btn login-submit">
                    Login
                </button>

            </form>

            <div class="form-footer">

                Don't have an account?

                <a href="register.php">Create an account</a>

            </div>

        </div>

    </section>

</body>

</html>