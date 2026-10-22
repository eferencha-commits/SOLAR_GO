<?php

session_start();

//require_once __DIR__ . "/database.php";

$errors = [];
$success = "";

$full_name = "";
$email = "";
$phone = "";
$role = "customer";

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Get and clean form inputs
    $full_name = trim($_POST["full_name"] ?? "");
    $email = trim($_POST["email"] ?? "");
    $phone = trim($_POST["phone"] ?? "");
    $role = $_POST["role"] ?? "customer";

    $password = $_POST["password"] ?? "";
    $confirm_password = $_POST["confirm_password"] ?? "";

    // Validate full name
    if ($full_name === "") {
        $errors[] = "Please enter your full name.";
    }

    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Please enter a valid email address.";
    }

    // Validate phone number
    if ($phone === "") {
        $errors[] = "Please enter your phone number.";
    }

    // Only these roles can register publicly
    $allowed_roles = ["customer", "seller", "technician"];

    if (!in_array($role, $allowed_roles, true)) {
        $errors[] = "Please select a valid account type.";
    }
    

    // Validate password
    if (strlen($password) < 8) {
        $errors[] = "Your password must contain at least 8 characters.";
    }

    // Confirm password
    if ($password !== $confirm_password) {
        $errors[] = "Your passwords do not match.";
    }

    // Check whether email already exists
    if (empty($errors)) {

        $check_sql = "SELECT user_id FROM users WHERE email = ? LIMIT 1";

        $check_stmt = $conn->prepare($check_sql);
        $check_stmt->bind_param("s", $email);
        $check_stmt->execute();
        $check_stmt->store_result();

        if ($check_stmt->num_rows > 0) {
            $errors[] = "This email address is already registered.";
        }

        $check_stmt->close();
    }

    // Register the user
    if (empty($errors)) {

        $password_hash = password_hash($password, PASSWORD_DEFAULT);

        // New accounts start as pending until reviewed
       // Set account status according to the user's role
if ($role === "customer") {
    $account_status = "active";
} else {
    $account_status = "pending";
}

        $insert_sql = "
            INSERT INTO users
            (full_name, email, phone, password_hash, role, account_status)
            VALUES (?, ?, ?, ?, ?, ?)
        ";

        $insert_stmt = $conn->prepare($insert_sql);

        $insert_stmt->bind_param(
            "ssssss",
            $full_name,
            $email,
            $phone,
            $password_hash,
            $role,
            $account_status
        );

        if ($insert_stmt->execute()) {

            $success = "Registration successful! Your account is awaiting approval.";

            $full_name = "";
            $email = "";
            $phone = "";
            $role = "customer";

        } else {

            error_log("SolarGo registration failed: " . $insert_stmt->error);

            $errors[] = "Registration could not be completed. Please try again.";
        }

        $insert_stmt->close();
    }
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>Create Account | SolarGo</title>

    <link rel="stylesheet" href="../assets/css/style.css">

    <style>

        body {
            margin: 0;
            font-family: Arial, sans-serif;
            color: #24352a;
        }
        .register-section {
            
            min-height: 85vh;
            padding: 60px 20px;
            background: #0B2545;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .register-card {
            width: 100%;
            max-width: 520px;
            background: #ffffff;
            padding: 35px;
            border-radius: 15px;
            box-shadow: 0 10px 35px #0000000d;
        }

        .register-card h1 {
            margin-bottom: 10px;
            font-size: 30px;
        }

        .register-description {
            color: #647067;
            margin-bottom: 25px;
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

        .form-group input,
        .form-group select {
            width: 100%;
            padding: 13px;
            border: 1px solid #dce6dd;
            border-radius: 7px;
            font-size: 15px;
            background: #ffffff;
        }

        .form-group input:focus,
        .form-group select:focus {
            outline: 2px solid #16834a;
            border-color: #16834a;
        }

        .register-submit {
            width: 40%;
            cursor: pointer;
            font-size: 15px;
            text-align: center;
            margin-left: 30%;
            padding: 10px;
            background-color: #0B2545;
            color: white;
            border: none;
            border-radius: 7px;
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

        .success-message {
            background: #eaf7ee;
            color: #075c35;
        }

        .form-footer {
            margin-top: 22px;
            text-align: center;
            color: #647067;
            font-size: 14px;
        }

        .form-footer a {
            color: #0B2545;
            font-weight: 700;
        }

        .back-home {
            display: inline-block;
            margin-bottom: 20px;
            color: #0B2545;
            font-weight: 700;
        }

        @media (max-width: 500px) {
            .register-card {
                padding: 25px 20px;
            }
        }
    </style>

</head>

<body>

    <section class="register-section">

        <div class="register-card">

            <a href="index.php" class="back-home">
                ← Back to SolarGo
            </a>

            <h1>Create Your Account</h1>

            <p class="register-description">
                Join SolarGo and discover smarter solar energy solutions.
            </p>

            <?php if (!empty($errors)): ?>

                <div class="form-message error-message">

                    <?php foreach ($errors as $error): ?>

                        <p>
                            <?= htmlspecialchars($error, ENT_QUOTES, "UTF-8") ?>
                        </p>

                    <?php endforeach; ?>

                </div>

            <?php endif; ?>


            <?php if ($success !== ""): ?>

                <div class="form-message success-message">

                    <?= htmlspecialchars($success, ENT_QUOTES, "UTF-8") ?>

                </div>

            <?php endif; ?>


            <form method="POST" action="register.php">

                <div class="form-group">

                    <label for="full_name">Full Name</label>

                    <input
                        type="text"
                        id="full_name"
                        name="full_name"
                        value="<?= htmlspecialchars($full_name, ENT_QUOTES, "UTF-8") ?>"
                        placeholder="Enter your full name"
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
                        value="<?= htmlspecialchars($email, ENT_QUOTES, "UTF-8") ?>"
                        placeholder="Enter your email"
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
                        value="<?= htmlspecialchars($phone, ENT_QUOTES, "UTF-8") ?>"
                        placeholder="Enter your phone number"
                        autocomplete="tel"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="role">Account Type</label>

                    <select id="role" name="role" required>

                        <option value="customer"
                            <?= $role === "customer" ? "selected" : "" ?>>
                            Customer
                        </option>

                        <option value="seller"
                            <?= $role === "seller" ? "selected" : "" ?>>
                            Seller
                        </option>

                        <option value="technician"
                            <?= $role === "technician" ? "selected" : "" ?>>
                            Technician
                        </option>

                    </select>

                </div>


                <div class="form-group">

                    <label for="password">Password</label>

                    <input
                        type="password"
                        id="password"
                        name="password"
                        placeholder="At least 8 characters"
                        autocomplete="new-password"
                        minlength="8"
                        required
                    >

                </div>


                <div class="form-group">

                    <label for="confirm_password">Confirm Password</label>

                    <input
                        type="password"
                        id="confirm_password"
                        name="confirm_password"
                        placeholder="Enter your password again"
                        autocomplete="new-password"
                        required
                    >

                </div>

            
                <button type="submit" class="btn register-submit" >
                    Create Account
                </button>

            </form>


            <div class="form-footer">

                Already have an account?

                <a href="login.php">Login</a>

            </div>

        </div>

    </section>

</body>

</html>