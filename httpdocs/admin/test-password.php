<?php
$stored_hash = '$2y$10$...'; // Replace with the hash from the database
$password = 'admin123';

if (password_verify($password, $stored_hash)) {
    echo "Password matches!";
} else {
    echo "Password does not match.";
}
?>