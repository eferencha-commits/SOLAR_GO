
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

$csrf_token = $_SESSION["csrf_token"];

$success = "";
$error = "";

// Handle account status actions.
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $submitted_token = $_POST["csrf_token"] ?? "";

    if (
        !is_string($submitted_token) ||
        !hash_equals($csrf_token, $submitted_token)
    ) {
        $error = "Security verification failed. Please try again.";
    } else {

        $target_id = filter_var(
            $_POST["user_id"] ?? "",
            FILTER_VALIDATE_INT
        );

        $action = $_POST["action"] ?? "";

        $allowed_actions = [
            "approve",
            "suspend",
            "reactivate"
        ];

        if (
            !$target_id ||
            $target_id < 1 ||
            !in_array($action, $allowed_actions, true)
        ) {
            $error = "Invalid account action.";
        } elseif ((int)$target_id === (int)$_SESSION["user_id"]) {
            $error = "You cannot change your own account status here.";
        } else {

            // Never allow this page to change another administrator's status.
            $check = $conn->prepare(
                "SELECT user_id, role, account_status
                 FROM users
                 WHERE user_id = ?
                 LIMIT 1"
            );

            if (!$check) {
                $error = "Unable to verify the selected account.";
            } else {

                $check->bind_param("i", $target_id);
                $check->execute();

                $result = $check->get_result();
                $target = $result->fetch_assoc();

                $check->close();

                if (!$target) {
                    $error = "User account not found.";
                } elseif ($target["role"] === "admin") {
                    $error = "Administrator accounts cannot be changed here.";
                } else {

                    $new_status = "";

                    if ($action === "approve") {

                        if (
                            $target["role"] !== "seller" ||
                            $target["account_status"] !== "pending"
                        ) {
                            $error = "Only pending seller accounts can be approved.";
                        } else {
                            $new_status = "active";
                        }

                    } elseif ($action === "suspend") {

                        if ($target["account_status"] === "suspended") {
                            $error = "This account is already suspended.";
                        } else {
                            $new_status = "suspended";
                        }

                    } elseif ($action === "reactivate") {

                        if ($target["account_status"] !== "suspended") {
                            $error = "Only suspended accounts can be reactivated.";
                        } else {
                            $new_status = "active";
                        }
                    }

                    if ($new_status !== "" && $error === "") {

                        $update = $conn->prepare(
                            "UPDATE users
                             SET account_status = ?
                             WHERE user_id = ?
                               AND role <> 'admin'"
                        );

                        if (!$update) {
                            $error = "Unable to prepare the account update.";
                        } else {

                            $update->bind_param(
                                "si",
                                $new_status,
                                $target_id
                            );

                            if ($update->execute()) {

                                if ($update->affected_rows > 0) {

                                    if ($action === "approve") {
                                        $success = "Seller account approved successfully.";
                                    } elseif ($action === "suspend") {
                                        $success = "User account suspended successfully.";
                                    } else {
                                        $success = "User account reactivated successfully.";
                                    }

                                } else {
                                    $error = "No account status was changed.";
                                }

                            } else {
                                $error = "Failed to update the account status.";
                            }

                            $update->close();
                        }
                    }
                }
            }
        }
    }
}

// Search and filters.
$search = trim($_GET["search"] ?? "");
$role_filter = $_GET["role"] ?? "";
$status_filter = $_GET["status"] ?? "";

$allowed_roles = [
    "customer",
    "seller",
    "technician",
    "admin"
];

$allowed_statuses = [
    "pending",
    "active",
    "suspended"
];

if (!in_array($role_filter, $allowed_roles, true)) {
    $role_filter = "";
}

if (!in_array($status_filter, $allowed_statuses, true)) {
    $status_filter = "";
}

// Dashboard statistics.
$stats_sql = "
    SELECT
        COUNT(*) AS total_users,
        COALESCE(SUM(role = 'customer'), 0) AS customers,
        COALESCE(SUM(role = 'seller'), 0) AS sellers,
        COALESCE(SUM(role = 'technician'), 0) AS technicians,
        COALESCE(SUM(account_status = 'pending'), 0) AS pending_users,
        COALESCE(SUM(account_status = 'active'), 0) AS active_users,
        COALESCE(SUM(account_status = 'suspended'), 0) AS suspended_users
    FROM users
";

$stats_result = $conn->query($stats_sql);

$stats = $stats_result
    ? $stats_result->fetch_assoc()
    : [
        "total_users" => 0,
        "customers" => 0,
        "sellers" => 0,
        "technicians" => 0,
        "pending_users" => 0,
        "active_users" => 0,
        "suspended_users" => 0
    ];

// Build the user listing query.
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
    WHERE 1 = 1
";

$types = "";
$params = [];

if ($search !== "") {
    $sql .= " AND (full_name LIKE ? OR email LIKE ?)";

    $search_term = "%" . $search . "%";

    $types .= "ss";
    $params[] = $search_term;
    $params[] = $search_term;
}

if ($role_filter !== "") {
    $sql .= " AND role = ?";

    $types .= "s";
    $params[] = $role_filter;
}

if ($status_filter !== "") {
    $sql .= " AND account_status = ?";

    $types .= "s";
    $params[] = $status_filter;
}

$sql .= "
    ORDER BY
        CASE
            WHEN account_status = 'pending' THEN 0
            ELSE 1
        END,
        created_at DESC
";

$users = [];
$list_error = "";

$stmt = $conn->prepare($sql);

if (!$stmt) {

    $list_error = "Unable to load users. Check your database structure.";

} else {

    if ($types !== "") {

        // Bind each parameter by reference.
        $bind_values = [$types];

        foreach ($params as $key => $value) {
            $bind_values[] = &$params[$key];
        }

        if (!call_user_func_array(
            [$stmt, "bind_param"],
            $bind_values
        )) {
            $list_error = "Unable to apply the selected filters.";
        }
    }

    if ($list_error === "") {

        if ($stmt->execute()) {

            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $users[] = $row;
            }

        } else {
            $list_error = "Unable to retrieve user accounts.";
        }
    }

    $stmt->close();
}

function h($value) {
    return htmlspecialchars(
        (string)($value ?? ""),
        ENT_QUOTES,
        "UTF-8"
    );
}

function statusClass($status) {
    switch ($status) {
        case "active":
            return "active";

        case "pending":
            return "pending";

        case "suspended":
            return "suspended";

        default:
            return "default";
    }
}

function roleLabel($role) {
    return ucfirst((string)$role);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">

    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <title>User Management | SolarGo Admin</title>

    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: Arial, sans-serif;
            background: #f4f7f6;
            color: #263238;
        }

        .topbar {
            background: #123d2b;
            color: white;
            padding: 20px 5%;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }

        .brand {
            font-size: 25px;
            font-weight: bold;
        }

        .brand span {
            color: #b7f36b;
        }

        .topbar a {
            color: white;
            text-decoration: none;
            margin-left: 15px;
            font-size: 14px;
        }

        .container {
            width: 94%;
            max-width: 1450px;
            margin: 30px auto;
        }

        .page-heading {
            margin-bottom: 25px;
        }

        .page-heading h1 {
            margin-bottom: 8px;
            color: #123d2b;
        }

        .page-heading p {
            color: #68756e;
        }

        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(155px, 1fr));
            gap: 16px;
            margin-bottom: 28px;
        }

        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
            border-left: 4px solid #16804a;
        }

        .stat-card h3 {
            font-size: 13px;
            color: #68756e;
            margin-bottom: 12px;
        }

        .stat-card strong {
            font-size: 27px;
            color: #123d2b;
        }

        .panel {
            background: white;
            padding: 22px;
            border-radius: 12px;
            box-shadow: 0 3px 12px rgba(0, 0, 0, 0.04);
        }

        .panel-heading {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 20px;
        }

        .panel-heading h2 {
            font-size: 20px;
        }

        .filters {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            gap: 12px;
            margin-bottom: 22px;
        }

        input,
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #d6dfd9;
            border-radius: 7px;
            font-size: 14px;
            background: white;
        }

        button,
        .button {
            border: none;
            border-radius: 7px;
            padding: 11px 14px;
            cursor: pointer;
            text-decoration: none;
            font-size: 13px;
            font-weight: bold;
            display: inline-block;
            text-align: center;
        }

        .filter-button {
            background: #123d2b;
            color: white;
        }

        .reset-button {
            background: #e8eeea;
            color: #263238;
        }

        .table-wrap {
            overflow-x: auto;
        }

        table {
            width: 100%;
            min-width: 950px;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 15px 12px;
            text-align: left;
            border-bottom: 1px solid #edf0ed;
            font-size: 13px;
            vertical-align: middle;
        }

        th {
            background: #f7faf8;
            color: #56645b;
            font-size: 12px;
            text-transform: uppercase;
        }

        .user-name {
            font-weight: bold;
            color: #20372a;
            margin-bottom: 5px;
        }

        .user-email {
            color: #77847b;
            overflow-wrap: anywhere;
        }

        .badge {
            display: inline-block;
            padding: 6px 10px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: bold;
            text-transform: capitalize;
            white-space: nowrap;
        }

        .active {
            background: #d9f7e4;
            color: #146b36;
        }

        .pending {
            background: #fff0c7;
            color: #8b5b00;
        }

        .suspended {
            background: #ffe0e0;
            color: #a32121;
        }

        .default {
            background: #e8ece9;
            color: #44534a;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 7px;
            min-width: 150px;
        }

        .approve {
            background: #16804a;
            color: white;
        }

        .suspend {
            background: #c83d3d;
            color: white;
        }

        .reactivate {
            background: #e2f2e8;
            color: #145b32;
        }

        .no-action {
            color: #7c877f;
            font-size: 12px;
        }

        .message {
            padding: 14px 16px;
            border-radius: 8px;
            margin-bottom: 18px;
        }

        .message.success {
            background: #dcf7e6;
            color: #145b32;
        }

        .message.error {
            background: #ffe4e4;
            color: #9c2525;
        }

        .empty {
            text-align: center;
            padding: 40px 15px;
            color: #758078;
        }

        .footer {
            text-align: center;
            padding: 25px;
            color: #758078;
            font-size: 13px;
        }

        @media (max-width: 800px) {
            .filters {
                grid-template-columns: 1fr 1fr;
            }

            .filters input {
                grid-column: 1 / -1;
            }

            .container {
                width: 96%;
            }

            .panel {
                padding: 14px;
            }
        }

        @media (max-width: 480px) {
            .filters {
                grid-template-columns: 1fr;
            }

            .filters input {
                grid-column: auto;
            }

            .topbar {
                padding: 18px;
            }

            .topbar a {
                margin-left: 0;
                margin-right: 12px;
            }
        }
    </style>
</head>

<body>

<header class="topbar">

    <div class="brand">
        Solar<span>Go</span> Admin
    </div>

    <nav>
        <a href="dashboard.php">Dashboard</a>
        <a href="orders.php">Orders</a>
        <a href="users.php">Users</a>
        <a href="../auth/logout.php">Logout</a>
    </nav>

</header>

<main class="container">

    <section class="page-heading">
        <h1>User Management</h1>

        <p>
            Manage SolarGo accounts, approve sellers, and control account access.
        </p>
    </section>

    <?php if ($success !== ""): ?>
        <div class="message success">
            <?= h($success) ?>
        </div>
    <?php endif; ?>

    <?php if ($error !== ""): ?>
        <div class="message error">
            <?= h($error) ?>
        </div>
    <?php endif; ?>

    <?php if ($list_error !== ""): ?>
        <div class="message error">
            <?= h($list_error) ?>
        </div>
    <?php endif; ?>

    <section class="stats">

        <div class="stat-card">
            <h3>Total Users</h3>
            <strong><?= (int)$stats["total_users"] ?></strong>
        </div>

        <div class="stat-card">
            <h3>Customers</h3>
            <strong><?= (int)$stats["customers"] ?></strong>
        </div>

        <div class="stat-card">
            <h3>Sellers</h3>
            <strong><?= (int)$stats["sellers"] ?></strong>
        </div>

        <div class="stat-card">
            <h3>Technicians</h3>
            <strong><?= (int)$stats["technicians"] ?></strong>
        </div>

        <div class="stat-card">
            <h3>Pending Accounts</h3>
            <strong><?= (int)$stats["pending_users"] ?></strong>
        </div>

        <div class="stat-card">
            <h3>Active Accounts</h3>
            <strong><?= (int)$stats["active_users"] ?></strong>
        </div>

        <div class="stat-card">
            <h3>Suspended Accounts</h3>
            <strong><?= (int)$stats["suspended_users"] ?></strong>
        </div>

    </section>

    <section class="panel">

        <div class="panel-heading">
            <h2>Registered Users</h2>

            <span>
                <?= count($users) ?> account(s) found
            </span>
        </div>

        <form method="GET" action="users.php" class="filters">

            <input
                type="search"
                name="search"
                placeholder="Search name or email..."
                value="<?= h($search) ?>"
            >

            <select name="role">
                <option value="">All Roles</option>

                <?php foreach ($allowed_roles as $role): ?>
                    <option
                        value="<?= h($role) ?>"
                        <?= $role_filter === $role ? "selected" : "" ?>
                    >
                        <?= h(roleLabel($role)) ?>
                    </option>
                <?php endforeach; ?>

            </select>

            <select name="status">
                <option value="">All Statuses</option>

                <?php foreach ($allowed_statuses as $status): ?>
                    <option
                        value="<?= h($status) ?>"
                        <?= $status_filter === $status ? "selected" : "" ?>
                    >
                        <?= h(ucfirst($status)) ?>
                    </option>
                <?php endforeach; ?>

            </select>

            <button type="submit" class="filter-button">
                Search
            </button>

            <a href="users.php" class="button reset-button">
                Reset
            </a>

        </form>

        <div class="table-wrap">

            <table>

                <thead>
                    <tr>
                        <th>User</th>
                        <th>Phone</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Registered</th>
                        <th>Actions</th>
                    </tr>
                </thead>

                <tbody>

                <?php if (empty($users)): ?>

                    <tr>
                        <td colspan="6" class="empty">
                            No users found.
                        </td>
                    </tr>

                <?php else: ?>

                    <?php foreach ($users as $user): ?>

                        <tr>

                            <td>
                                <div class="user-name">
                                    <?= h($user["full_name"]) ?>
                                </div>

                                <div class="user-email">
                                    <?= h($user["email"]) ?>
                                </div>

                                <small>
                                    ID: <?= (int)$user["user_id"] ?>
                                </small>
                            </td>

                            <td>
                                <?= h($user["phone"] ?: "Not provided") ?>
                            </td>

                            <td>
                                <?= h(roleLabel($user["role"])) ?>
                            </td>

                            <td>
                                <span class="badge <?= h(statusClass($user["account_status"])) ?>">
                                    <?= h($user["account_status"]) ?>
                                </span>
                            </td>

                            <td>
                                <?= h(
                                    !empty($user["created_at"])
                                        ? date("d M Y", strtotime($user["created_at"]))
                                        : "Unknown"
                                ) ?>
                            </td>

                            <td>

                                <?php if ($user["role"] === "admin"): ?>

                                    <span class="no-action">
                                        Protected account
                                    </span>

                                <?php elseif (
                                    $user["role"] === "seller" &&
                                    $user["account_status"] === "pending"
                                ): ?>

                                    <div class="actions">

                                        <form method="POST" action="users.php">
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= h($csrf_token) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="user_id"
                                                value="<?= (int)$user["user_id"] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="approve"
                                            >

                                            <button
                                                type="submit"
                                                class="approve"
                                                onclick="return confirm('Approve this seller account?');"
                                            >
                                                Approve Seller
                                            </button>
                                        </form>

                                        <form method="POST" action="users.php">
                                            <input
                                                type="hidden"
                                                name="csrf_token"
                                                value="<?= h($csrf_token) ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="user_id"
                                                value="<?= (int)$user["user_id"] ?>"
                                            >

                                            <input
                                                type="hidden"
                                                name="action"
                                                value="suspend"
                                            >

                                            <button
                                                type="submit"
                                                class="suspend"
                                                onclick="return confirm('Suspend this account?');"
                                            >
                                                Suspend
                                            </button>
                                        </form>

                                    </div>

                                <?php elseif ($user["account_status"] === "suspended"): ?>

                                    <form method="POST" action="users.php">
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= h($csrf_token) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="user_id"
                                            value="<?= (int)$user["user_id"] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="reactivate"
                                        >

                                        <button
                                            type="submit"
                                            class="reactivate"
                                            onclick="return confirm('Reactivate this account?');"
                                        >
                                            Reactivate
                                        </button>
                                    </form>

                                <?php else: ?>

                                    <form method="POST" action="users.php">
                                        <input
                                            type="hidden"
                                            name="csrf_token"
                                            value="<?= h($csrf_token) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="user_id"
                                            value="<?= (int)$user["user_id"] ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="action"
                                            value="suspend"
                                        >

                                        <button
                                            type="submit"
                                            class="suspend"
                                            onclick="return confirm('Suspend this account?');"
                                        >
                                            Suspend
                                        </button>
                                    </form>

                                <?php endif; ?>

                            </td>

                        </tr>

                    <?php endforeach; ?>

                <?php endif; ?>

                </tbody>

            </table>

        </div>

    </section>

</main>

<footer class="footer">
    &copy; <?= date("Y") ?> SolarGo. Admin User Management.
</footer>

</body>
</html>