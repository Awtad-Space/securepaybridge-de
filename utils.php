<?php
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // --- CSRF Functions ---
        function generate_csrf_token() {
            if (empty($_SESSION['csrf_token'])) {
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            }
            return $_SESSION['csrf_token'];
        }

        function validate_csrf_token($token_from_form) {
            if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token_from_form)) {
                unset($_SESSION['csrf_token']); // Invalidate token on failure
                error_log("CSRF token validation failed.");
                return false;
            }
            // Regenerate token after successful validation for single-use tokens per request
            unset($_SESSION['csrf_token']);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            return true;
        }

        function csrf_input_field() {
            $token = generate_csrf_token();
            echo '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
        }

        // --- Security & Generation Functions ---
        function generate_secure_key($length = 32) {
            return bin2hex(random_bytes($length));
        }

        // --- Pagination Function ---
        function generate_pagination_links($base_url, $current_page, $total_pages, $query_params_str = '') {
            if ($total_pages <= 1) {
                return '';
            }

            parse_str($query_params_str, $query_params_arr); // Parse query string into array

            $pagination_html = '<nav aria-label="Page navigation"><ul class="pagination">';

            // Previous Button
            if ($current_page > 1) {
                $query_params_arr['page'] = $current_page - 1;
                $pagination_html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '?' . http_build_query($query_params_arr) . '">Previous</a></li>';
            } else {
                $pagination_html .= '<li class="page-item disabled"><span class="page-link">Previous</span></li>';
            }

            // Page Number Links (simplified logic for many pages)
            $max_links = 5;
            $start_page = max(1, $current_page - floor($max_links / 2));
            $end_page = min($total_pages, $start_page + $max_links - 1);
            $start_page = max(1, $end_page - $max_links + 1);

            if ($start_page > 1) {
                $query_params_arr['page'] = 1;
                $pagination_html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '?' . http_build_query($query_params_arr) . '">1</a></li>';
                if ($start_page > 2) {
                    $pagination_html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
            }

            for ($i = $start_page; $i <= $end_page; $i++) {
                if ($i == $current_page) {
                    $pagination_html .= '<li class="page-item active" aria-current="page"><span class="page-link">' . $i . '</span></li>';
                } else {
                    $query_params_arr['page'] = $i;
                    $pagination_html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '?' . http_build_query($query_params_arr) . '">' . $i . '</a></li>';
                }
            }

             if ($end_page < $total_pages) {
                if ($end_page < $total_pages - 1) {
                    $pagination_html .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
                $query_params_arr['page'] = $total_pages;
                $pagination_html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '?' . http_build_query($query_params_arr) . '">' . $total_pages . '</a></li>';
            }

            // Next Button
            if ($current_page < $total_pages) {
                 $query_params_arr['page'] = $current_page + 1;
                $pagination_html .= '<li class="page-item"><a class="page-link" href="' . $base_url . '?' . http_build_query($query_params_arr) . '">Next</a></li>';
            } else {
                $pagination_html .= '<li class="page-item disabled"><span class="page-link">Next</span></li>';
            }

            $pagination_html .= '</ul></nav>';
            return $pagination_html;
        }

        // --- Activity Logging Function ---
        function log_activity(SQLite3 $db, string $action_type, ?string $details = null): bool {
            try {
                $admin_username = $_SESSION['admin_username'] ?? 'System';
                $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

                $stmt = $db->prepare("INSERT INTO activity_log (admin_username, action_type, details, ip_address) VALUES (:admin_username, :action_type, :details, :ip_address)");
                $stmt->bindValue(':admin_username', $admin_username, SQLITE3_TEXT);
                $stmt->bindValue(':action_type', $action_type, SQLITE3_TEXT);
                $stmt->bindValue(':details', $details, $details ? SQLITE3_TEXT : SQLITE3_NULL);
                $stmt->bindValue(':ip_address', $ip_address, SQLITE3_TEXT);
                $stmt->execute();
                return true;
            } catch (Exception $e) {
                error_log("Activity Log Error: Failed to log action '$action_type'. Details: " . $e->getMessage());
                return false;
            }
        }

        // --- Settings Functions ---
        /**
         * Gets a setting value from the database.
         *
         * @param SQLite3 $db Database connection.
         * @param string $key The setting key.
         * @param mixed $default_value The value to return if the key is not found.
         * @return mixed The setting value or the default value.
         */
        function get_setting(SQLite3 $db, string $key, $default_value = null) {
            try {
                $stmt = $db->prepare("SELECT value FROM settings WHERE key = :key");
                $stmt->bindValue(':key', $key, SQLITE3_TEXT);
                $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
                return ($result !== false && isset($result['value'])) ? $result['value'] : $default_value;
            } catch (Exception $e) {
                error_log("Error getting setting '$key': " . $e->getMessage());
                return $default_value;
            }
        }

        /**
         * Sets a setting value in the database (inserts or updates).
         *
         * @param SQLite3 $db Database connection.
         * @param string $key The setting key.
         * @param mixed $value The value to set.
         * @return bool True on success, false on failure.
         */
        function set_setting(SQLite3 $db, string $key, $value): bool {
            try {
                // Use INSERT OR REPLACE for simplicity (requires key to be PRIMARY KEY)
                $stmt = $db->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES (:key, :value)");
                $stmt->bindValue(':key', $key, SQLITE3_TEXT);
                $stmt->bindValue(':value', $value, SQLITE3_TEXT); // Store all settings as text for simplicity
                $stmt->execute();
                return true;
            } catch (Exception $e) {
                error_log("Error setting setting '$key': " . $e->getMessage());
                return false;
            }
        }

        // --- Permission Check Function ---
        /**
         * Checks if a file path is writable.
         *
         * @param string $path The file path.
         * @return bool True if writable, false otherwise.
         */
        function check_writable(string $path): bool {
            clearstatcache(true, $path); // Clear cache before checking
            return is_writable($path);
        }
        ?>
