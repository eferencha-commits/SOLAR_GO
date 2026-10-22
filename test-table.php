<?php

require_once __DIR__ . "/database.php";

$sql = "SHOW TABLES";

$result = $conn->query($sql);

if ($result) {

    echo "<h1>SolarGo Database Tables</h1>";

    if ($result->num_rows > 0) {

        echo "<ul>";

        while ($row = $result->fetch_array()) {
            echo "<li>" .
                htmlspecialchars($row[0], ENT_QUOTES, "UTF-8") .
                "</li>";
        }

        echo "</ul>";

    } else {

        echo "The database exists, but no tables were found.";

    }

} else {

    echo "Could not retrieve the database tables.";

}

$conn->close();

?>