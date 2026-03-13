<?php
require 'config/db.php';

try {
    $conn->query("ALTER TABLE users ADD COLUMN is_active TINYINT(1) DEFAULT 1");
    echo "is_active column added to users table\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>