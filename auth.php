<?php
        if (session_status() === PHP_SESSION_NONE) {
            $cookieParams = session_get_cookie_params();
            session_set_cookie_params([
                'lifetime' => $cookieParams['lifetime'],
                'path' => $cookieParams['path'],
                'domain' => $cookieParams['domain'],
                'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            session_start();
        }
        require_once 'db.php'; // Ensures $db is available globally in functions below

        // --- Session & Authentication Checks ---
        function is_logged_in() {
            return isset($_SESSION['admin_id']);
        }

        function require_login() {
            if (!is_logged_in()) {
                if (basename($_SERVER['PHP_SELF']) !== 'index.php') {
                    $_SESSION['redirect_url'] = $_SERVER['REQUEST_URI'];
                }
                header('Location: index.php');
                exit;
            }
        }

        // --- Login/Logout ---
        function login($username, $password) {
            global $db;
            try {
                $stmt = $db->prepare("SELECT id, username, password_hash FROM admins WHERE username = :username");
                $stmt->bindValue(':username', $username, SQLITE3_TEXT);
                $result = $stmt->execute();
                $admin = $result->fetchArray(SQLITE3_ASSOC);

                if ($admin && password_verify($password, $admin['password_hash'])) {
                    session_regenerate_id(true); // Prevent session fixation
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_username'] = $admin['username']; // Store username too

                    $redirect_url = $_SESSION['redirect_url'] ?? 'dashboard.php';
                    unset($_SESSION['redirect_url']);

                    // Log successful login
                    log_activity($db, 'ADMIN_LOGIN', "User: " . $admin['username']);

                    header('Location: ' . $redirect_url);
                    exit;
                }
                // Log failed login attempt (optional, be careful about logging usernames)
                // log_activity($db, 'ADMIN_LOGIN_FAIL', "Attempted user: " . $username);
                return false;
            } catch (Exception $e) {
                error_log("Login DB Error: " . $e->getMessage());
                return false;
            }
        }

        function logout() {
            global $db; // Need $db for logging
            $username = $_SESSION['admin_username'] ?? 'Unknown';
            log_activity($db, 'ADMIN_LOGOUT', "User: " . $username);

            $_SESSION = array(); // Clear session array

            // Delete session cookie
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000,
                    $params["path"], $params["domain"],
                    $params["secure"], $params["httponly"]
                );
            }
            session_destroy(); // Destroy session data on server
        }

        // --- Admin Management Functions ---
        /**
         * Creates a new admin user.
         *
         * @param string $username
         * @param string $password
         * @return bool|string True on success, error message string on failure.
         */
        function create_admin($username, $password) {
            global $db;
            try {
                // Check if username already exists
                $stmt_check = $db->prepare("SELECT COUNT(*) as count FROM admins WHERE username = :username");
                $stmt_check->bindValue(':username', $username, SQLITE3_TEXT);
                $exists = $stmt_check->execute()->fetchArray(SQLITE3_ASSOC)['count'];

                if ($exists > 0) {
                    return "Username already exists.";
                }

                // Hash the password
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                if ($password_hash === false) {
                    error_log("Password hashing failed for user: " . $username);
                    return "Error creating admin account (hashing failed).";
                }

                // Insert the new admin
                $stmt_insert = $db->prepare("INSERT INTO admins (username, password_hash) VALUES (:username, :password_hash)");
                $stmt_insert->bindValue(':username', $username, SQLITE3_TEXT);
                $stmt_insert->bindValue(':password_hash', $password_hash, SQLITE3_TEXT);
                $stmt_insert->execute();

                return true; // Success
            } catch (Exception $e) {
                error_log("Error creating admin '$username': " . $e->getMessage());
                return "Error creating admin account (database error).";
            }
        }

        /**
         * Gets a list of all admin users.
         *
         * @return array List of admin users (id, username, created_at) or empty array on error.
         */
        function get_all_admins() {
            global $db;
            $admins = [];
            try {
                $result = $db->query("SELECT id, username, created_at FROM admins ORDER BY username ASC");
                while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
                    $admins[] = $row;
                }
            } catch (Exception $e) {
                error_log("Error fetching admins: " . $e->getMessage());
            }
            return $admins;
        }

        /**
         * Updates an admin's password.
         *
         * @param int $admin_id
         * @param string $new_password
         * @return bool|string True on success, error message string on failure.
         */
        function update_admin_password(int $admin_id, string $new_password) {
            global $db;
            try {
                $password_hash = password_hash($new_password, PASSWORD_DEFAULT);
                if ($password_hash === false) {
                    error_log("Password hashing failed for admin ID: " . $admin_id);
                    return "Password hashing failed.";
                }

                $stmt = $db->prepare("UPDATE admins SET password_hash = :password_hash WHERE id = :id");
                $stmt->bindValue(':password_hash', $password_hash, SQLITE3_TEXT);
                $stmt->bindValue(':id', $admin_id, SQLITE3_INTEGER);
                $stmt->execute();

                // Check if any row was actually updated
                return ($db->changes() > 0);

            } catch (Exception $e) {
                error_log("Error updating password for admin ID $admin_id: " . $e->getMessage());
                return "Database error during password update.";
            }
        }

        /**
         * Deletes an admin user. Prevents deletion of the last admin.
         *
         * @param int $admin_id_to_delete The ID of the admin to delete.
         * @param int $current_admin_id The ID of the currently logged-in admin.
         * @return bool|string True on success, error message string on failure.
         */
        function delete_admin(int $admin_id_to_delete, int $current_admin_id) {
            global $db;
            try {
                // Prevent deleting self (optional, but maybe good practice)
                // if ($admin_id_to_delete === $current_admin_id) {
                //     return "You cannot delete your own account.";
                // }

                // Check if this is the last admin
                $count = $db->querySingle("SELECT COUNT(*) FROM admins");
                if ($count <= 1) {
                    return "Cannot delete the last administrator account.";
                }

                // Proceed with deletion
                $stmt = $db->prepare("DELETE FROM admins WHERE id = :id");
                $stmt->bindValue(':id', $admin_id_to_delete, SQLITE3_INTEGER);
                $stmt->execute();

                return ($db->changes() > 0); // Return true if a row was deleted

            } catch (Exception $e) {
                error_log("Error deleting admin ID $admin_id_to_delete: " . $e->getMessage());
                return "Database error during admin deletion.";
            }
        }
        ?>
