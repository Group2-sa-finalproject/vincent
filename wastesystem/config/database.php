<?php

$conn = mysqli_connect(
    "localhost",
    "root",
    "",
    "waste_system"
);

if (!$conn) {
    die("Database connection failed: " . mysqli_connect_error());
}
?>