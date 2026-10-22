<?php

// Database connection settings
$host = "localhost";
$username = "root";
$password = "";
$database = "solargo_db";

// Create the database connection
$conn = new mysqli(
    $host,
    $username,
    $password,
    $database
);

// Check whether the connection succeeded
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);

    http_response_code(500);

    exit("Database connection failed. Please check your configuration.");
}

// Set the character encoding
$conn->set_charset("utf8mb4");

?>