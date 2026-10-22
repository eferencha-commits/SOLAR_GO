
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

// Create CSRF token.
if (empty($_SESSION["csrf_token"])) {
    $_SESSION["csrf_token"] = bin2hex(random_bytes(32));
}

// Escape output.
function e($value): string
{
    return htmlspecialchars(
        (string) ($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

$search = trim($_GET["search"] ?? "");
$roleFilter = $_GET["role"] ?? "";
$statusFilter = $_GET["status"] ?? "";

$allowedRoles = [
    "customer",
    "seller",
    "technician",
    "admin"
];

$allowedStatuses = [
    "pending",
    "active",
    "suspended"
];

$conditions = [];
$params = [];
$types = "";

// Search by name, email, or phone.
if ($search !== "") {
    $conditions[] = "(
        full_name LIKE ?
        OR email LIKE ?
        OR phone LIKE ?
    )";

    $searchTerm = "%" . $search . "%";

    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;

    $types .= "sss";
}

// Filter by role.
if (in_array($roleFilter, $allowedRoles, true)) {
    $conditions[] = "role = ?";
    $params[] = $roleFilter;
    $types .= "s";
}

// Filter by account status.
if (in_array($statusFilter, $allowedStatuses, true)) {
    $conditions[] = "account_status = ?";
    $params[] = $statusFilter;
    $types .= "s";
}

$sql = "
    SELECT
        user_id,
        full_name,
        email,
        phone,
        role,
        account_status,
        created_at
    FROM users
";

if (!empty($conditions)) {
    $sql .= " WHERE " . implode(" AND ", $conditions);
}

$sql .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);

$users = [];
$databaseError = "";

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    if ($stmt->execute()) {
        $result = $stmt->get_result();

        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }

        $result->free();
    } else {
        $databaseError = "Unable to load users. Please try again.";
        error_log("User management query error: " . $stmt->error);
    }

    $stmt->close();
} else {
    $databaseError = "Unable to prepare the user query.";
    error_log("User management prepare error: " . $conn->error);
}

// Messages returned by process-user.php.
$messages = [
    "activated" => "User account activated successfully.",
    "suspended" => "User account suspended successfully."
];

$errors = [
    "invalid_request" => "Invalid user management request.",
    "database" => "A database error occurred. Please try again.",
    "not_updated" => "The account was not changed. It may already have that status.",
    "protected" => "You cannot change your own account or manage another administrator here."
];

$message = $messages[$_GET["message"] ?? ""] ?? "";
$error = $errors[$_GET["error"] ?? ""] ?? "";

$adminName = $_SESSION["full_name"] ?? "Administrator";
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>User Management | SolarGo</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, Helvetica, sans-serif;
            background: #f4f7f5;
            color: #1f2937;
            line-height: 1.6;
        }

        a {
            text-decoration: none;
        }

        .navbar {
            background: #ffffff;
            padding: 16px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            border-bottom: 1px solid #e5e7eb;
        }

        .logo {
            color: #15803d;
            font-size: 26px;
            font-weight: 800;
        }

        .logo span {
            color: #f59e0b;
        }

        .back-link {
            color: #15803d;
            font-weight: bold;
        }

        .container {
            width: 92%;
            max-width: 1400px;
            margin: 30px auto;
        }

        h1 {
            margin-bottom: 6px;
        }

        .subtitle {
            color: #6b7280;
            margin-bottom: 25px;
        }

        .panel {
            background: #ffffff;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            margin-bottom: 25px;
            overflow: hidden;
        }

        .panel-header {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
        }

        .filters {
            padding: 20px;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto auto;
            gap: 12px;
        }

        input,
        select {
            width: 100%;
            padding: 11px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            background: #ffffff;
            font-size: 14px;
        }

        .btn {
            display: inline-block;
            padding: 10px 14px;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            font-size: 13px;
            text-align: center;
        }

        .search-btn {
            background: #15803d;
            color: #ffffff;
        }

        .reset-btn {
            background: #e5e7eb;
            color: #1f2937;
        }

        .table-wrapper {
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 950px;
        }

        th,
        td {
            padding: 14px 16px;
            border-bottom: 1px solid #e5e7eb;
            text-align: left;
            font-size: 14px;
        }

        th {
            background: #f9fafb;
            color: #4b5563;
            font-size: 12px;
            text-transform: uppercase;
        }

        tr:last-child td {
            border-bottom: none;
        }

        .badge {
            display: inline-block;
            padding: 5px 9px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            text-transform: capitalize;
        }

        .active {
            background: #dcfce7;
            color: #166534;
        }

        .pending {
            background: #fef3c7;
            color: #92400e;
        }

        .suspended {
            background: #fee2e2;
            color: #991b1b;
        }

        .role {
            text-transform: capitalize;
        }

        .actions form {
            display: inline-block;
            margin: 3px;
        }

        .activate-btn {
            background: #15803d;
            color: #ffffff;
        }

        .suspend-btn {
            background: #fee2e2;
            color: #991b1b;
        }

        .protected-text {
            color: #6b7280;
            font-size: 12px;
        }

        .notice {
            padding: 14px 16px;
            margin-bottom: 20px;
            border-radius: 7px;
            font-weight: bold;
        }

        .success {
            background: #dcfce7;
            color: #166534;
        }

        .error {
            background: #fee2e2;
            color: #991b1b;
        }

        .empty {
            padding: 35px;
            text-align: center;
            color: #6b7280;
        }

        footer {
            padding: 25px;
            text-align: center;
            color: #6b7280;
            font-size: 13px;
        }

        @media (max-width: 850px) {
            .filters {
                grid-template-columns: 1fr 1fr;
            }

            .filters input {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 500px) {
            .container {
                width: 95%;
            }

            .navbar {
                padding: 14px 4%;
            }

            .logo {
                font-size: 22px;
            }

            .filters {
                grid-template-columns: 1fr;
            }

            .filters input {
                grid-column: auto;
            }
        }
    </style>
</head>

<body>

<nav class="navbar">
    <a href="dashboard.php" class="logo">
        Solar<span>Go</span>
    </a>

    <a href="dashboard.php" class="back-link">
        &larr; Admin Dashboard
    </a>
</nav>

<main class="container">

    <h1>User Management</h1>

    <p class="subtitle">
        Welcome, <?= e($adminName) ?>.
        Search registered users and manage eligible customer,
        seller, and technician accounts.
    </p>

    <?php if ($message !== ""): ?>
        <div class="notice success">
            <?= e($message) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="notice error">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($databaseError !== ""): ?>
        <div class="notice error">
            <?= e($databaseError) ?>
        </div>
    <?php endif; ?>

    <section class="panel">

        <div class="panel-header">
            <h2>Search and Filter Users</h2>
        </div>

        <form method="GET" action="users.php" class="filters">

            <input
                type="search"
                name="search"
                placeholder="Search name, email, or phone"
                value="<?= e($search) ?>"
            >

            <select name="role">
                <option value="">All Roles</option>

                <?php foreach ($allowedRoles as $role): ?>
                    <option
                        value="<?= e($role) ?>"
                        <?= $roleFilter === $role ? "selected" : "" ?>
                    >
                        <?= e(ucfirst($role)) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status">
                <option value="">All Statuses</option>

                <?php foreach ($allowedStatuses as $status): ?>
                    <option
                        value="<?= e($status) ?>"
                        <?= $statusFilter === $status ? "selected" : "" ?>
                    >
                        <?= e(ucfirst($status)) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn search-btn">
                Search
            </button>

            <a href="users.php" class="btn reset-btn">
                Reset
            </a>

        </form>

    </section>

    <section class="panel">

        <div class="panel-header">
            <h2>Registered Users (<?= count($users) ?>)</h2>
        </div>

        <?php if (!empty($users)): ?>

            <div class="table-wrapper">

                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Full Name</th>
                            <th>Email</th>
                            <th>Phone</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Registered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>

                    <tbody>

                    <?php foreach ($users as $user): ?>

                        <tr>
                            <td><?= (int) $user["user_id"] ?></td>

                            <td><?= e($user["full_name"]) ?></td>

                            <td><?= e($user["email"]) ?></td>

                            <td><?= e($user["phone"]) ?></td>

                            <td class="role">
                                <?= e($user["role"]) ?>
                            </td>

                            <td>
                                <span class="badge <?= e($user["account_status"]) ?>">
                                    <?= e($user["account_status"]) ?>
                                </span>
                            </td>

                            <td><?= e($user["created_at"]) ?></td>

                            <td class="actions">

                                <?php
                                $isProtected =
                                    (int) $user["user_id"] === (int) $_SESSION["user_id"]
                                    || $user["role"] === "admin";
                                ?>

                                <?php if ($isProtected): ?>

                                    <span class="protected-text">
                                        Protected account
                                    </span>

                                <?php elseif ($user["account_status"] === "active"): ?>

                                    <form
                                        method="POST"
                                        action="process-user.php"
                                        onsubmit="return confirm('Suspend this account? The user may lose access to the platform.');"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($_SESSION["csrf_token"]) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="user_id"
                                            value="<?= (int) $user["user_id"] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="suspend"
                                        >

                                        <button
                                            type="submit"
                                            class="btn suspend-btn"
                                        >
                                            Suspend
                                        </button>
                                    </form>

                                <?php elseif ($user["account_status"] === "suspended"): ?>

                                    <form
                                        method="POST"
                                        action="process-user.php"
                                        onsubmit="return confirm('Reactivate this account?');"
                                    >
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= e($_SESSION["csrf_token"]) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="user_id"
                                            value="<?= (int) $user["user_id"] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="activate"
                                        >

                                        <button
                                            type="submit"
                                            class="btn activate-btn"
                                        >
                                            Activate
                                        </button>
                                    </form>

                                <?php else: ?>

                                    <span class="protected-text">
                                        Use seller review for pending accounts.
                                    </span>

                                <?php endif; ?>

                            </td>
                        </tr>

                    <?php endforeach; ?>

                    </tbody>
                </table>

            </div>

        <?php else: ?>

            <div class="empty">
                <h3>No users found</h3>
                <p>Try a different search term or filter.</p>
            </div>

        <?php endif; ?>

    </section>

</main>

<footer>
    &copy; <?= date("Y") ?> SolarGo. All rights reserved.
</footer>

</body>
</html>
