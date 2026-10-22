<?php

session_start();

// Clear all session data
$_SESSION = [];

// Delete the session cookie
if (ini_get("session.use_cookies")) {

    $cookie_parameters = session_get_cookie_params();

    setcookie(
        session_name(),
        "",
        time() - 42000,
        $cookie_parameters["path"],
        $cookie_parameters["domain"],
        $cookie_parameters["secure"],
        $cookie_parameters["httponly"]
    );
}

// Destroy the session
session_destroy();

// Redirect to the login page
header("Location: login.php");
exit;

?>