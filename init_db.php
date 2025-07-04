<?php
        try {
            $db = new SQLite3(__DIR__ . '/license_manager.db');
            $db->enableExceptions(true);

            echo "Connected to database successfully.\n";

            // --- Admins Table ---
            $db->exec("CREATE TABLE IF NOT EXISTS admins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )");
            echo "- 'admins' table checked/created.\n";

            // --- Licenses Table ---
            $db->exec("CREATE TABLE IF NOT EXISTS licenses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                domain TEXT UNIQUE NOT NULL,
                secondary_domain TEXT,
                client_name TEXT,
                license_key TEXT UNIQUE NOT NULL,
                token TEXT UNIQUE NOT NULL,
                email TEXT,
                status TEXT DEFAULT 'inactive',
                expires_at DATETIME,
                license_type TEXT DEFAULT 'Trial',
                site_limit TEXT DEFAULT 'Single', -- Added site_limit column with default
                server_name TEXT,
                ip_address TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME
            )");
             echo "- 'licenses' table checked/created.\n";

            // Check and add missing columns to licenses (idempotent)
            $licenses_cols_info = $db->query("PRAGMA table_info(licenses)");
            $existing_licenses_cols = [];
            while ($col = $licenses_cols_info->fetchArray(SQLITE3_ASSOC)) {
                $existing_licenses_cols[] = $col['name'];
            }

            $required_licenses_cols = [
                'id' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
                'domain' => 'TEXT UNIQUE NOT NULL',
                'secondary_domain' => 'TEXT',
                'client_name' => 'TEXT',
                'license_key' => 'TEXT UNIQUE NOT NULL',
                'token' => 'TEXT UNIQUE NOT NULL',
                'email' => 'TEXT',
                'status' => 'TEXT DEFAULT \'inactive\'',
                'expires_at' => 'DATETIME',
                'license_type' => 'TEXT DEFAULT \'Trial\'',
                'site_limit' => 'TEXT DEFAULT \'Single\'', // Added site_limit definition
                'server_name' => 'TEXT',
                'ip_address' => 'TEXT',
                'created_at' => 'DATETIME DEFAULT CURRENT_TIMESTAMP',
                'updated_at' => 'DATETIME'
            ];

            foreach ($required_licenses_cols as $col_name => $col_def) {
                if (!in_array($col_name, $existing_licenses_cols)) {
                    try {
                        // Extract type and constraints correctly
                        $parts = explode(' ', $col_def);
                        $type_and_constraints = implode(' ', array_slice($parts, 1)); // Get everything after the first word

                        $db->exec("ALTER TABLE licenses ADD COLUMN $col_name $type_and_constraints");
                        echo "- Added '$col_name' column to 'licenses'.\n";
                    } catch (Exception $e) {
                         if (strpos($e->getMessage(), 'duplicate column name') === false) {
                            echo "- WARNING: Could not add '$col_name' column to 'licenses'. Error: " . $e->getMessage() . "\n";
                         } else {
                             echo "- '$col_name' column likely already exists in 'licenses' (ignored error).\n";
                         }
                    }
                } else {
                    echo "- '$col_name' column already exists in 'licenses'.\n";
                }
            }

            // --- Activity Log Table ---
            $db->exec("CREATE TABLE IF NOT EXISTS activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                admin_username TEXT,
                action_type TEXT NOT NULL,
                details TEXT,
                ip_address TEXT
            )");
            echo "- 'activity_log' table checked/created.\n";

            // --- Settings Table ---
            $db->exec("CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY NOT NULL,
                value TEXT
            )");
            echo "- 'settings' table checked/created.\n";

            // --- Insert Default Settings (if not exist) ---
            $default_settings = [
                'rate_limit_timeframe' => 60,
                'rate_limit_max_requests' => 10,
                'default_license_type' => 'Trial'
                // No default for site_limit setting needed here, it's per-license
            ];

            $stmt_check_setting = $db->prepare("SELECT COUNT(*) as count FROM settings WHERE key = :key");
            $stmt_insert_setting = $db->prepare("INSERT INTO settings (key, value) VALUES (:key, :value)");

            foreach ($default_settings as $key => $value) {
                $stmt_check_setting->bindValue(':key', $key, SQLITE3_TEXT);
                $exists = $stmt_check_setting->execute()->fetchArray(SQLITE3_ASSOC)['count'] > 0;
                $stmt_check_setting->reset(); // Reset bindings for next iteration

                if (!$exists) {
                    $stmt_insert_setting->bindValue(':key', $key, SQLITE3_TEXT);
                    $stmt_insert_setting->bindValue(':value', $value, SQLITE3_TEXT);
                    $stmt_insert_setting->execute();
                    $stmt_insert_setting->reset(); // Reset bindings
                    echo "- Inserted default setting: '$key' = '$value'.\n";
                } else {
                    echo "- Default setting '$key' already exists.\n";
                }
            }

            echo "\nDatabase initialization/update complete.\n";

        } catch (Exception $e) {
            echo "An error occurred: " . $e->getMessage() . "\n";
        } finally {
            if (isset($db)) {
                $db->close();
                echo "Database connection closed.\n";
            }
        }
        ?>
