<?php
        header('Content-Type: application/json');

        require_once 'db.php'; // Provides $db
        require_once 'utils.php'; // Provides get_setting()

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
                     error_log("Rate limit log file read error: " . $rate_limit_log_file);
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
                 error_log("Rate limit log file write error: " . $rate_limit_log_file);
             }
            return true; // Limit not exceeded
        }

        // --- Response Function ---
        function send_response($status, $message = '', $data = []) {
            echo json_encode(array_merge(['status' => $status, 'message' => $message], $data));
            exit;
        }

        // --- Request Handling ---
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            send_response('error', 'Invalid request method. Use POST.');
        }

        // Apply Rate Limiting
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        if ($enable_rate_limit && !check_rate_limit($ip)) {
             http_response_code(429); // Too Many Requests
             send_response('error', 'Rate limit exceeded. Please try again later.');
        }

        // Get POST parameters
        $domain_from_request = trim($_POST['domain'] ?? '');
        $license_key = trim($_POST['key'] ?? '');
        $token = trim($_POST['token'] ?? '');

        // Validate parameters
        if (empty($domain_from_request) || empty($license_key) || empty($token)) {
            send_response('error', 'Missing required parameters: domain, key, token.');
        }

        // --- Database Check ---
        try {
            // Fetch license based on key and token first
            $stmt = $db->prepare("SELECT status, expires_at, license_type, site_limit, domain, secondary_domain
                                  FROM licenses
                                  WHERE license_key = :key AND token = :token");
            $stmt->bindValue(':key', $license_key, SQLITE3_TEXT);
            $stmt->bindValue(':token', $token, SQLITE3_TEXT);
            $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

            if (!$result) {
                // Log invalid attempt (optional)
                // log_activity($db, 'LICENSE_CHECK_FAIL', "Invalid Key/Token attempt for Domain: $domain_from_request");
                send_response('invalid', 'License key or token mismatch.');
            }

            // Process result
            $status = $result['status'];
            $expires_at = $result['expires_at']; // Stored as YYYY-MM-DD or NULL
            $license_type = $result['license_type'];
            $site_limit = $result['site_limit'] ?? 'Single'; // Default to Single if missing
            $db_domain = $result['domain'];
            $db_secondary_domain = $result['secondary_domain'];
            $matched_domain = $domain_from_request; // Assume requested domain initially
            $is_expired = false;

            // --- Domain Matching Logic based on Site Limit ---
            if ($site_limit === 'Single') {
                // For Single site license, the requested domain MUST match primary or secondary
                if (strtolower($domain_from_request) !== strtolower($db_domain) && strtolower($domain_from_request) !== strtolower($db_secondary_domain)) {
                    // Log invalid domain attempt for single site license
                    // log_activity($db, 'LICENSE_CHECK_FAIL_DOMAIN', "Domain mismatch for Single Site License. Key: $license_key, Requested: $domain_from_request, Allowed: $db_domain / $db_secondary_domain");
                    send_response('invalid', 'Domain mismatch for this license key.');
                }
                // If it matches, $matched_domain remains $domain_from_request
            } elseif ($site_limit === 'Unlimited') {
                // For Unlimited license, domain match is NOT required.
                // We already found the license by key/token.
                $matched_domain = 'Unlimited License'; // Indicate this is an unlimited license validation
            } else {
                 // Unknown site_limit value - treat as invalid? Or default to Single? Let's treat as invalid for safety.
                 error_log("Unknown site_limit value '$site_limit' for license key $license_key.");
                 send_response('error', 'Internal license configuration error.');
            }

            // --- Status and Expiry Check (Common Logic) ---

            // Check status first
            if ($status !== 'active') {
                send_response('inactive', 'License is not active.', ['license_type' => $license_type, 'site_limit' => $site_limit, 'matched_domain' => $matched_domain]);
            }

            // Check expiration only if not Lifetime and expires_at is set
            if ($license_type !== 'Lifetime' && $expires_at) {
                try {
                    // Compare dates directly as strings (YYYY-MM-DD format)
                    $today = date('Y-m-d');
                    if ($today > $expires_at) {
                        $is_expired = true;
                    }
                } catch (Exception $e) {
                    error_log("Date comparison error for license key $license_key (matched: $matched_domain): $expires_at. Error: " . $e->getMessage());
                     send_response('error', 'Internal license data error.', ['license_type' => $license_type, 'site_limit' => $site_limit, 'matched_domain' => $matched_domain]);
                }
            }

            // Send response based on expiration
            if ($is_expired) {
                send_response('expired', 'License has expired.', [
                    'license_type' => $license_type,
                    'site_limit' => $site_limit,
                    'expires_at' => $expires_at, // Return the stored YYYY-MM-DD date
                    'matched_domain' => $matched_domain
                ]);
            } else {
                // Log successful check (optional)
                // log_activity($db, 'LICENSE_CHECK_OK', "Valid check. Key: $license_key, Requested Domain: $domain_from_request, Matched: $matched_domain, Limit: $site_limit");
                send_response('valid', 'License is valid and active.', [
                    'license_type' => $license_type,
                    'site_limit' => $site_limit,
                    'expires_at' => $expires_at ? $expires_at : ($license_type === 'Lifetime' ? 'Lifetime' : null), // Return YYYY-MM-DD or 'Lifetime'
                    'matched_domain' => $matched_domain
                ]);
            }

        } catch (Exception $e) {
            error_log("Database Error in license-check.php: " . $e->getMessage());
            // Send a generic error response in case of DB issues
            send_response('error', 'An internal server error occurred during license check.');
        }
        ?>
