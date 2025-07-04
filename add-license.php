<?php
        require_once 'auth.php';
        require_once 'db.php';
        require_once 'utils.php'; // Provides get_setting()

        require_login();

        $edit_mode = false;
        $license_data_from_db = null;
        // Get default license type from settings for add mode
        $default_license_type = get_setting($db, 'default_license_type', 'Trial');
        $license_data_for_form = [
            'domain' => '', 'secondary_domain' => '', 'client_name' => '', 'license_key' => '', 'token' => '',
            'email' => '', 'status' => 'active', 'expires_at' => '',
            'license_type' => $default_license_type, // Use default here
            'site_limit' => 'Single', // Default site limit for new licenses
        ];
        $original_domain = null;

        // --- Edit Mode Logic ---
        if (isset($_GET['edit_domain'])) {
            $domain_to_edit = $_GET['edit_domain'];
            $stmt_get = $db->prepare("SELECT * FROM licenses WHERE domain = :domain");
            $stmt_get->bindValue(':domain', $domain_to_edit, SQLITE3_TEXT);
            $result = $stmt_get->execute()->fetchArray(SQLITE3_ASSOC);
            if ($result) {
                $edit_mode = true;
                $license_data_from_db = $result;
                // Merge DB data with defaults, ensuring all keys exist
                $license_data_for_form = array_merge($license_data_for_form, $result);
                $original_domain = $result['domain'];

                // Format date for the input field (YYYY-MM-DD)
                if ($license_data_for_form['expires_at'] && $license_data_for_form['license_type'] !== 'Lifetime') {
                     try {
                        // Assuming expires_at is stored as YYYY-MM-DD
                        $date = new DateTime($license_data_for_form['expires_at']);
                        $license_data_for_form['expires_at'] = $date->format('Y-m-d');
                     } catch (Exception $e) {
                         error_log("Error parsing date for domain {$original_domain}: " . $e->getMessage());
                         $license_data_for_form['expires_at'] = '';
                     }
                } else {
                    $license_data_for_form['expires_at'] = '';
                }
            } else {
                $_SESSION['error_message'] = "License for domain " . htmlspecialchars($domain_to_edit) . " not found.";
                $edit_mode = false;
            }
        }

        // --- POST Request Handling ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $local_error_message = '';

             if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
                 $_SESSION['error_message'] = 'Invalid request token. Please try again.';
                 header('Location: ' . $_SERVER['PHP_SELF'] . ($edit_mode ? '?edit_domain=' . urlencode($original_domain) : ''));
                 exit;
             }

            $submitted_domain = $edit_mode ? $original_domain : trim($_POST['domain'] ?? '');

            $submitted_data = [
                'domain' => $submitted_domain,
                'secondary_domain' => trim($_POST['secondary_domain'] ?? ''),
                'client_name' => trim($_POST['client_name'] ?? ''),
                'email' => filter_var(trim($_POST['email'] ?? ''), FILTER_SANITIZE_EMAIL),
                'status' => in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'inactive',
                'license_type' => in_array($_POST['license_type'] ?? '', ['Trial', 'Monthly', 'Yearly', 'Lifetime']) ? $_POST['license_type'] : $default_license_type, // Fallback to default
                'site_limit' => in_array($_POST['site_limit'] ?? '', ['Single', 'Unlimited']) ? $_POST['site_limit'] : 'Single', // Added site_limit
                'expires_at' => trim($_POST['expires_at'] ?? ''),
                'license_key' => $license_data_from_db['license_key'] ?? '',
                'token' => $license_data_from_db['token'] ?? '',
            ];
            // Update form data for repopulation, merging with existing data
            $license_data_for_form = array_merge($license_data_for_form, $submitted_data);

            // --- Validation ---
            $expires_at_db = null;
            if ($submitted_data['license_type'] === 'Lifetime') {
                $expires_at_db = null;
                 $license_data_for_form['expires_at'] = '';
            } elseif (!empty($submitted_data['expires_at'])) {
                try {
                    $date = new DateTime($submitted_data['expires_at']);
                    if ($date->format('Y') < 1900) throw new Exception("Year seems invalid.");
                    $expires_at_db = $date->format('Y-m-d'); // Store as YYYY-MM-DD
                } catch (Exception $e) {
                    $local_error_message = "Invalid expiration date format. Please use YYYY-MM-DD.";
                }
            } else {
                 $expires_at_db = null;
            }

            if (empty($submitted_data['domain'])) $local_error_message = "Primary Domain is required.";
            elseif (empty($submitted_data['client_name'])) $local_error_message = "Client Name is required.";
            if (!empty($submitted_data['secondary_domain']) && $submitted_data['secondary_domain'] == $submitted_data['domain']) {
                 $local_error_message = "Secondary domain cannot be the same as the primary domain.";
            }

            // --- Database Operation ---
            if (empty($local_error_message)) {
                try {
                    $db->exec('BEGIN');

                    if (!$edit_mode) {
                        // Check if primary domain already exists
                        $stmt_check = $db->prepare("SELECT COUNT(*) as count FROM licenses WHERE domain = :domain");
                        $stmt_check->bindValue(':domain', $submitted_data['domain'], SQLITE3_TEXT);
                        $exists = $stmt_check->execute()->fetchArray(SQLITE3_ASSOC)['count'] > 0;
                        if ($exists) {
                             throw new Exception("Cannot add: License for primary domain '" . htmlspecialchars($submitted_data['domain']) . "' already exists.");
                        }
                    }

                    // Check if secondary domain (if provided) is already used as a primary domain elsewhere
                    if (!empty($submitted_data['secondary_domain'])) {
                        $stmt_check_sec = $db->prepare("SELECT COUNT(*) as count FROM licenses WHERE domain = :secondary_domain AND domain != :original_domain");
                        $stmt_check_sec->bindValue(':secondary_domain', $submitted_data['secondary_domain'], SQLITE3_TEXT);
                        $stmt_check_sec->bindValue(':original_domain', $original_domain ?? '', SQLITE3_TEXT); // Exclude current record in edit mode
                        $sec_exists_as_primary = $stmt_check_sec->execute()->fetchArray(SQLITE3_ASSOC)['count'] > 0;
                        if ($sec_exists_as_primary) {
                            throw new Exception("Cannot save: The secondary domain '" . htmlspecialchars($submitted_data['secondary_domain']) . "' is already used as a primary domain for another license.");
                        }
                    }


                    $current_time = date('Y-m-d H:i:s');
                    $log_details = "Domain: " . $submitted_data['domain'] . ($submitted_data['secondary_domain'] ? ", Secondary: " . $submitted_data['secondary_domain'] : "") . ", Limit: " . $submitted_data['site_limit'];

                    if ($edit_mode) {
                        $stmt = $db->prepare("UPDATE licenses SET
                                                secondary_domain = :secondary_domain, client_name = :client_name, email = :email, status = :status,
                                                expires_at = :expires_at, license_type = :license_type, site_limit = :site_limit, updated_at = :updated_at
                                            WHERE domain = :original_domain");
                        $stmt->bindValue(':updated_at', $current_time, SQLITE3_TEXT);
                        $stmt->bindValue(':original_domain', $original_domain, SQLITE3_TEXT);
                        $log_action = 'LICENSE_UPDATE';
                    } else {
                        $license_key = generate_secure_key(16);
                        $token = generate_secure_key(24);
                        $license_data_for_form['license_key'] = $license_key; // Update for potential display/logging
                        $license_data_for_form['token'] = $token;

                        $stmt = $db->prepare("INSERT INTO licenses
                                                (domain, secondary_domain, client_name, license_key, token, email, status, expires_at, license_type, site_limit, server_name, ip_address, created_at, updated_at)
                                            VALUES
                                                (:domain, :secondary_domain, :client_name, :license_key, :token, :email, :status, :expires_at, :license_type, :site_limit, :server_name, :ip_address, :created_at, :updated_at)");
                        $stmt->bindValue(':license_key', $license_key, SQLITE3_TEXT);
                        $stmt->bindValue(':token', $token, SQLITE3_TEXT);
                        $stmt->bindValue(':server_name', $_SERVER['SERVER_NAME'] ?? 'N/A', SQLITE3_TEXT);
                        $stmt->bindValue(':ip_address', $_SERVER['REMOTE_ADDR'] ?? 'N/A', SQLITE3_TEXT);
                        $stmt->bindValue(':created_at', $current_time, SQLITE3_TEXT);
                        $stmt->bindValue(':updated_at', $current_time, SQLITE3_TEXT);
                        $log_action = 'LICENSE_ADD';
                    }

                    $stmt->bindValue(':domain', $submitted_data['domain'], SQLITE3_TEXT);
                    $stmt->bindValue(':secondary_domain', $submitted_data['secondary_domain'] ?: null, $submitted_data['secondary_domain'] ? SQLITE3_TEXT : SQLITE3_NULL);
                    $stmt->bindValue(':client_name', $submitted_data['client_name'], SQLITE3_TEXT);
                    $stmt->bindValue(':email', $submitted_data['email'] ?: null, $submitted_data['email'] ? SQLITE3_TEXT : SQLITE3_NULL);
                    $stmt->bindValue(':status', $submitted_data['status'], SQLITE3_TEXT);
                    $stmt->bindValue(':expires_at', $expires_at_db, $expires_at_db ? SQLITE3_TEXT : SQLITE3_NULL);
                    $stmt->bindValue(':license_type', $submitted_data['license_type'], SQLITE3_TEXT);
                    $stmt->bindValue(':site_limit', $submitted_data['site_limit'], SQLITE3_TEXT); // Bind site_limit

                    $stmt->execute();
                    log_activity($db, $log_action, $log_details);
                    $db->exec('COMMIT');

                    $_SESSION['success_message'] = '✅ License for ' . htmlspecialchars($submitted_data['domain']) . ($edit_mode ? ' updated' : ' saved') . ' successfully.';
                    header('Location: view-licenses.php');
                    exit;

                } catch (Exception $e) {
                    $db->exec('ROLLBACK');
                    error_log("Database error in add-license.php: " . $e->getMessage());
                    $local_error_message = "Database error occurred. Could not save license. Details: " . $e->getMessage();
                }
            }
             $_SESSION['error_message'] = $local_error_message;
             // Redirect back to the form on error to show messages and repopulated data
             header('Location: ' . $_SERVER['PHP_SELF'] . ($edit_mode ? '?edit_domain=' . urlencode($original_domain) : ''));
             exit;
        }

        // --- Page Setup ---
        $page_title = $edit_mode ? 'Edit License' : 'Add New License';
        include 'header.php';
        ?>

        <?php // Form Display Logic
        if (!$edit_mode || $license_data_from_db): ?>
        <form method="POST" action="add-license.php<?php echo $edit_mode ? '?edit_domain=' . urlencode($original_domain) : ''; ?>" class="styled-form">
            <?php csrf_input_field(); ?>

            <div class="form-group">
                <label for="domain">🌐 Primary Domain</label>
                <input type="text" id="domain" name="domain" placeholder="e.g., client.com or https://client.com" required value="<?php echo htmlspecialchars($license_data_for_form['domain']); ?>" <?php echo $edit_mode ? 'readonly' : ''; ?>>
                 <?php if ($edit_mode): ?>
                    <small>Primary domain cannot be changed after creation.</small>
                 <?php else: ?>
                     <small>Unique primary identifier for the license (e.g., the main website domain).</small>
                 <?php endif; ?>
            </div>

            <div class="form-group">
                <label for="secondary_domain">🔗 Secondary Domain (Optional)</label>
                <input type="text" id="secondary_domain" name="secondary_domain" placeholder="e.g., staging.client.com or client.net" value="<?php echo htmlspecialchars($license_data_for_form['secondary_domain'] ?? ''); ?>">
                <small>An alternative domain where this license can also be validated (only for 'Single' site limit).</small>
            </div>

            <div class="form-group">
                <label for="client_name">👤 Client Name</label>
                <input type="text" id="client_name" name="client_name" placeholder="Client Company or Name" required value="<?php echo htmlspecialchars($license_data_for_form['client_name']); ?>">
            </div>

            <?php if ($edit_mode): ?>
                <div class="form-group">
                    <label>🔑 License Key</label>
                    <input type="text" value="<?php echo htmlspecialchars($license_data_for_form['license_key']); ?>" readonly>
                </div>
                <div class="form-group">
                    <label>🧪 Token</label>
                    <input type="text" value="<?php echo htmlspecialchars($license_data_for_form['token']); ?>" readonly>
                </div>
            <?php else: ?>
                 <div class="form-group">
                    <label>🔑 License Key & 🧪 Token</label>
                    <p class="form-static-text"><em>Generated automatically upon saving.</em></p>
                </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="email">📧 Email (Optional)</label>
                <input type="email" id="email" name="email" placeholder="client@example.com" value="<?php echo htmlspecialchars($license_data_for_form['email']); ?>">
            </div>

             <div class="form-group">
                <label for="license_type">🎫 License Type (Billing/Duration)</label>
                <select id="license_type" name="license_type">
                    <option value="Trial" <?php echo ($license_data_for_form['license_type'] === 'Trial') ? 'selected' : ''; ?>>Trial</option>
                    <option value="Monthly" <?php echo ($license_data_for_form['license_type'] === 'Monthly') ? 'selected' : ''; ?>>Monthly</option>
                    <option value="Yearly" <?php echo ($license_data_for_form['license_type'] === 'Yearly') ? 'selected' : ''; ?>>Yearly</option>
                    <option value="Lifetime" <?php echo ($license_data_for_form['license_type'] === 'Lifetime') ? 'selected' : ''; ?>>Lifetime</option>
                </select>
                 <small>Determines billing cycle or if the license expires.</small>
            </div>

            <div class="form-group">
                <label for="expires_at">📅 Expiration Date</label>
                <input type="date" id="expires_at" name="expires_at" value="<?php echo htmlspecialchars($license_data_for_form['expires_at']); ?>" <?php echo ($license_data_for_form['license_type'] === 'Lifetime') ? 'disabled' : ''; ?>>
                 <small>Leave blank or select 'Lifetime' type for no expiration.</small>
            </div>

             <div class="form-group">
                <label for="site_limit">🌍 Site Limit</label>
                <select id="site_limit" name="site_limit">
                    <option value="Single" <?php echo ($license_data_for_form['site_limit'] === 'Single') ? 'selected' : ''; ?>>Single Site</option>
                    <option value="Unlimited" <?php echo ($license_data_for_form['site_limit'] === 'Unlimited') ? 'selected' : ''; ?>>Unlimited Sites</option>
                </select>
                 <small>'Single Site' validates against Primary/Secondary Domain. 'Unlimited Sites' validates Key/Token on any domain.</small>
            </div>

            <div class="form-group">
                <label for="status">⚙️ Status</label>
                <select id="status" name="status">
                    <option value="active" <?php echo ($license_data_for_form['status'] === 'active') ? 'selected' : ''; ?>>Active</option>
                    <option value="inactive" <?php echo ($license_data_for_form['status'] === 'inactive') ? 'selected' : ''; ?>>Inactive</option>
                </select>
                 <small>Inactive licenses will fail validation checks.</small>
            </div>

            <button type="submit" class="btn btn-primary">💾 <?php echo $edit_mode ? 'Update' : 'Save'; ?> License</button>
             <?php if ($edit_mode): ?>
                <a href="view-licenses.php" class="btn btn-secondary">Cancel Edit</a>
             <?php endif; ?>
        </form>
        <?php endif; ?>

        <?php include 'footer.php'; ?>
