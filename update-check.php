<?php
        /**
         * SecurePay Bridge Update Server Endpoint.
         * Handles requests from WordPress plugins for update checks and plugin information.
         * MODIFIED: License check is bypassed for update downloads.
         */

        header('Content-Type: application/json');

        require_once 'db.php'; // Provides $db
        require_once 'utils.php'; // Provides get_setting() and log_activity()

        // --- Rate Limiting Settings (Fetched from DB) ---
        $rate_limit_timeframe = (int) get_setting($db, 'rate_limit_timeframe', 60);
        $rate_limit_max_requests = (int) get_setting($db, 'rate_limit_max_requests', 10);
        $enable_rate_limit = ($rate_limit_max_requests > 0 && $rate_limit_timeframe > 0);
        $rate_limit_log_file = __DIR__ . '/rate_limit_log.txt';

        // --- Rate Limiting Logic ---
        function check_rate_limit($ip) {
            global $rate_limit_timeframe, $rate_limit_max_requests, $rate_limit_log_file;
            $current_time = time();
            $requests = [];

            if (file_exists($rate_limit_log_file)) {
                $log_content = @file_get_contents($rate_limit_log_file);
                if ($log_content !== false) {
                    $requests = json_decode($log_content, true) ?: [];
                } else {
                     error_log("Rate limit log file read error in update-check.php: " . $rate_limit_log_file);
                }
            }

            $requests[$ip] = array_filter($requests[$ip] ?? [], function($timestamp) use ($current_time, $rate_limit_timeframe) {
                return ($current_time - $timestamp) < $rate_limit_timeframe;
            });

            if (count($requests[$ip] ?? []) >= $rate_limit_max_requests) {
                return false; // Limit exceeded
            }

            $requests[$ip][] = $current_time;

            $write_result = @file_put_contents($rate_limit_log_file, json_encode($requests, JSON_PRETTY_PRINT));
             if ($write_result === false) {
                 error_log("Rate limit log file write error in update-check.php: " . $rate_limit_log_file);
             }
            return true; // Limit not exceeded
        }

        // --- Response Function ---
        function send_json_response($data, $status_code = 200) {
            error_log("[update-check - No License Check] Sending Response (Status $status_code): " . json_encode($data)); // Logging
            http_response_code($status_code);
            echo json_encode($data);
            exit;
        }

        // --- Function to get latest plugin info from uploaded files ---
        function get_latest_plugin_info_from_files(string $requested_slug): ?object {
            $updates_dir = __DIR__ . '/downloads/';
            if (!is_dir($updates_dir)) {
                error_log("[update-check] Update directory not found: " . $updates_dir);
                return null;
            }

            $latest_version = '0.0.0';
            $latest_file = null;
            $slug_parts = explode('/', $requested_slug);
            $filename_slug_part = strtolower($slug_parts[0]);
            $slug_pattern = preg_quote($filename_slug_part, '/');
            $filename_pattern = "/^" . $slug_pattern . "-([0-9\.]+)\.zip$/i";

            $files = scandir($updates_dir);
            if ($files === false) {
                 error_log("[update-check] Failed to scan update directory: " . $updates_dir);
                 return null;
            }

            foreach ($files as $file) {
                if ($file === '.' || $file === '..') continue;
                if (preg_match($filename_pattern, $file, $matches)) {
                    $version = $matches[1];
                    if (version_compare($version, $latest_version, '>')) {
                        $latest_version = $version;
                        $latest_file = $file;
                    }
                }
            }

            if ($latest_file) {
                // Use HTTPS if available, otherwise HTTP. Ensure HTTP_HOST is set.
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443 ? "https://" : "http://";
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost'; // Fallback to localhost if HTTP_HOST is not set
                $base_url = $protocol . $host;

                // *** IMPORTANT: Adjust the path based on your actual server setup ***
                // If 'downloads' is directly under the domain root (e.g., https://auth.securepaybridge.net/downloads/...)
                $download_path = '/downloads/' . $latest_file;
                // If 'downloads' is inside an 'auth' folder (e.g., https://auth.securepaybridge.net/auth/downloads/...)
                // $download_path = '/auth/downloads/' . $latest_file;

                $download_url = $base_url . $download_path;
                error_log("[update-check] Generated download URL for $latest_file: $download_url"); // Log generated URL

                // Determine basic details based on slug (same as before)
                $plugin_name = 'Unknown Plugin';
                $plugin_homepage = 'https://securepaybridge.net';
                $description = 'Plugin update.';
                $changelog = 'Updated to version ' . $latest_version;
                if ($requested_slug === 'woocommerce-rest-b/woocommerce-rest-b.php') {
                    $plugin_name = 'SecurePay Bridge – Low Risk Site';
                    // ... description and changelog ...
                } elseif ($requested_slug === 'woocommerce-rest-a/woocommerce-rest-a.php') {
                    $plugin_name = 'SecurePay Bridge – High Risk Site';
                    // ... description and changelog ...
                }

                $plugin_info = new stdClass();
                $plugin_info->slug = $requested_slug;
                $plugin_info->name = $plugin_name;
                $plugin_info->new_version = $latest_version;
                $plugin_info->version = $latest_version;
                $plugin_info->url = $plugin_homepage;
                $plugin_info->package = $download_url; // Use the generated download URL
                $plugin_info->author = '<a href="https://securepaybridge.net">SecurePay Bridge</a>';
                $plugin_info->homepage = $plugin_homepage;
                $plugin_info->requires = '5.0';
                $plugin_info->tested = '6.5';
                $plugin_info->requires_php = '7.4';
                $plugin_info->sections = [ /* ... sections ... */ ];
                $plugin_info->banners = [ /* ... banners ... */ ];

                // *** Set default 'valid' status for update purposes ***
                $plugin_info->license_status = 'valid'; // Assume valid for update check
                $plugin_info->license_type = 'Update Access'; // Generic type
                $plugin_info->expires_at = null; // No expiration relevant here

                error_log("[update-check] Found update file info for $requested_slug: Version $latest_version, Package: $download_url");
                return $plugin_info;
            }
            error_log("[update-check] No update file found matching pattern for slug $requested_slug");
            return null; // No update file found for this slug
        }


        // --- Request Handling ---
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            send_json_response(['message' => 'Invalid request method. Use POST.'], 405);
        }

        // Apply Rate Limiting
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if ($enable_rate_limit && !check_rate_limit($ip)) {
             send_json_response(['message' => 'Rate limit exceeded. Please try again later.'], 429);
        }

        // Get POST parameters
        $action = $_POST['action'] ?? '';
        $slug = $_POST['slug'] ?? '';
        $version = $_POST['version'] ?? '';
        // License parameters are received but will be ignored for the update check
        $domain = $_POST['domain'] ?? '';
        $license_key = $_POST['license_key'] ?? '';
        $license_token = $_POST['license_token'] ?? '';

        error_log("[update-check - No License Check] Received Request: Action=$action, Slug=$slug, Version=$version, Domain=$domain (ignored), Key=$license_key (ignored), Token=$license_token (ignored)");

        // Validate essential parameters
        if (empty($action) || empty($slug) || empty($version)) {
            error_log("[update-check - No License Check] Error: Missing required parameters (action, slug, version).");
            send_json_response(['message' => 'Missing required parameters (action, slug, version).'], 400);
        }

        // --- Get Plugin Info from Files ---
        $requested_plugin_data = get_latest_plugin_info_from_files($slug);
        error_log("[update-check - No License Check] Plugin Info from Files: " . ($requested_plugin_data ? 'Found' : 'Not Found'));

        // --- License Validation Bypassed ---
        // We no longer perform the database check here for updates.
        // We assume the license is valid for the purpose of checking/providing updates.
        $license_status = 'valid'; // Always assume valid for update check
        $license_type = 'Update Access'; // Generic type
        $expires_at = null; // Not relevant for bypassed check

        error_log("[update-check - No License Check] License check bypassed for $slug. Proceeding as 'valid'.");


        // --- Handle Actions ---

        if ($action === 'check_update') {
            $response_data = new stdClass();

            // Proceed ONLY if plugin data exists from uploaded files
            if ($requested_plugin_data) {
                // Check if a newer version exists
                if (version_compare($version, $requested_plugin_data->new_version, '<')) {
                    // An update is available
                    $response_data = $requested_plugin_data;
                    // Ensure license status info (even if generic) is included, as the client might expect it
                    $response_data->license_status = $license_status;
                    $response_data->license_type = $license_type;
                    $response_data->expires_at = $expires_at;

                    error_log("[update-check - No License Check] Update Available for $slug. Current: $version, New: {$response_data->new_version}. Sending update data.");
                    log_activity($db, 'UPDATE_CHECK_ALLOWED', "Slug: $slug, Domain: $domain (Info Only), Current: $version, New: {$response_data->new_version}"); // Log allowed check
                    send_json_response($response_data); // Send update info
                } else {
                    // No update available (current version is >= latest uploaded version)
                    error_log("[update-check - No License Check] No Update Needed for $slug. Current: $version, Latest: {$requested_plugin_data->new_version}. Sending 'false'.");
                    send_json_response(false);
                }
            } else {
                // No update file found on server
                error_log("[update-check - No License Check] Update Check Failed for $slug. No update file found. Sending 'false'.");
                log_activity($db, 'UPDATE_CHECK_NO_FILE', "Slug: $slug, Domain: $domain (Info Only), File Found: No"); // Log file not found
                send_json_response(false);
            }

        } elseif ($action === 'plugin_information') {
            // Respond to plugin information request (when user clicks "View details")
            // Proceed ONLY if plugin data exists from uploaded files
            if ($requested_plugin_data) {
                $response_data = $requested_plugin_data;
                // Add generic license status info
                $response_data->license_status = $license_status;
                $response_data->license_type = $license_type;
                $response_data->expires_at = $expires_at;

                error_log("[update-check - No License Check] Plugin Info Request for $slug. File Found. Sending details.");
                send_json_response($response_data); // Send plugin details
            } else {
                // No update file found
                error_log("[update-check - No License Check] Plugin Info Failed for $slug. No update file found.");
                send_json_response(['message' => 'Plugin information not available.'], 404); // Changed message
            }

        } else {
            // Unknown action
            error_log("[update-check - No License Check] Error: Invalid action specified: $action");
            send_json_response(['message' => 'Invalid action specified.'], 400);
        }

?>
