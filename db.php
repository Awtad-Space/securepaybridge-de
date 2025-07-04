<?php
        try {
            $db = new SQLite3(__DIR__ . '/license_manager.db');
            $db->enableExceptions(true);
        } catch (Exception $e) {
            error_log("Database Connection Error: " . $e->getMessage());
            die("Database connection failed. Please check server logs or contact the administrator.");
        }
        ?>
