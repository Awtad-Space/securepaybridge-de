<?php
        require_once 'auth.php'; // Provides user management functions now
        require_once 'db.php';
        require_once 'utils.php'; // Provides settings and other utils

        require_login(); // Ensure user is logged in

        $page_title = 'Settings';

        // --- Variable Initialization ---
        $import_results = null;
        $rate_limit_log_content = '';
        $rate_limit_log_error = '';
        $rate_limit_log_file = __DIR__ . '/rate_limit_log.txt';
        $db_file = __DIR__ . '/license_manager.db';
        $submitted_username = ''; // For admin creation form repopulation

        // --- Handle POST Actions ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // CSRF validation is crucial for all POST actions
            if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
                $_SESSION['error_message'] = 'Invalid request token. Please refresh and try again.';
                header('Location: settings.php');
                exit;
            }

            // Determine action based on hidden input 'action'
            $action = $_POST['action'] ?? '';

            try {
                $db->exec('BEGIN'); // Use transactions for settings changes

                switch ($action) {
                    // --- Import CSV Action ---
                    case 'import_csv':
                        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                            throw new Exception('File upload error. Code: ' . ($_FILES['csv_file']['error'] ?? 'Unknown'));
                        }
                        $file_path = $_FILES['csv_file']['tmp_name'];
                        $file_mime_type = mime_content_type($file_path);
                        $allowed_mime_types = ['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'];

                        if (!in_array($file_mime_type, $allowed_mime_types)) {
                             throw new Exception('Invalid file type. Please upload a valid CSV file.');
                        }

                        // --- Start CSV Processing ---
                        $required_columns = ['domain', 'license_key', 'token'];
                        // Added 'site_limit' to optional columns
                        $optional_columns = ['secondary_domain', 'client_name', 'email', 'status', 'expires_at', 'license_type', 'site_limit'];
                        $all_expected_columns = array_merge($required_columns, $optional_columns);
                        $import_results = ['processed' => 0, 'imported' => 0, 'skipped' => 0, 'errors' => 0, 'details' => []];
                        $file_handle = fopen($file_path, 'r');

                        if ($file_handle === false) throw new Exception('Could not open uploaded CSV file.');

                        $header_row_raw = fgetcsv($file_handle);
                        $row_number = 1;

                        if ($header_row_raw === false || empty($header_row_raw)) throw new Exception('Invalid or empty CSV file.');

                        $header_row = array_map('strtolower', array_map('trim', $header_row_raw));
                        $column_map = array_flip($header_row);
                        $missing_required = array_filter($required_columns, fn($col) => !isset($column_map[strtolower($col)]));

                        if (!empty($missing_required)) throw new Exception('CSV file is missing required columns: ' . implode(', ', $missing_required));

                        while (($row_data = fgetcsv($file_handle)) !== false) {
                            $row_number++;
                            $import_results['processed']++;
                            $data_to_insert = [];
                            foreach ($all_expected_columns as $col_name) {
                                $col_index = $column_map[strtolower($col_name)] ?? -1;
                                $data_to_insert[$col_name] = ($col_index !== -1 && isset($row_data[$col_index])) ? trim($row_data[$col_index]) : null;
                            }

                            $domain = $data_to_insert['domain'];
                            $secondary_domain = $data_to_insert['secondary_domain'] ?? null;
                            $license_key = $data_to_insert['license_key'];
                            $token = $data_to_insert['token'];

                            if (empty($domain) || empty($license_key) || empty($token)) {
                                $import_results['errors']++;
                                $import_results['details'][] = "Row $row_number: Skipped (Error) - Missing required domain, key, or token.";
                                continue;
                            }

                            // Validation: Secondary domain cannot be same as primary
                            if (!empty($secondary_domain) && $secondary_domain == $domain) {
                                $import_results['errors']++;
                                $import_results['details'][] = "Row $row_number ($domain): Skipped (Error) - Secondary domain cannot be the same as the primary domain.";
                                continue;
                            }

                            $client_name = $data_to_insert['client_name'] ?? '';
                            $email = filter_var($data_to_insert['email'] ?? '', FILTER_SANITIZE_EMAIL) ?: null;
                            $status = in_array(strtolower($data_to_insert['status'] ?? ''), ['active', 'inactive']) ? strtolower($data_to_insert['status']) : 'inactive';
                            $license_type = in_array(ucfirst(strtolower($data_to_insert['license_type'] ?? '')), ['Trial', 'Monthly', 'Yearly', 'Lifetime']) ? ucfirst(strtolower($data_to_insert['license_type'])) : 'Trial';
                            $site_limit = in_array(ucfirst(strtolower($data_to_insert['site_limit'] ?? '')), ['Single', 'Unlimited']) ? ucfirst(strtolower($data_to_insert['site_limit'])) : 'Single'; // Validate and default site_limit
                            $expires_at_str = $data_to_insert['expires_at'] ?? '';
                            $expires_at_db = null;

                            if ($license_type === 'Lifetime') {
                                $expires_at_db = null;
                            } elseif (!empty($expires_at_str) && strtolower($expires_at_str) !== 'lifetime') {
                                try {
                                    $date = new DateTime($expires_at_str);
                                    if ($date->format('Y') < 1900) throw new Exception('Invalid year');
                                    $expires_at_db = $date->format('Y-m-d');
                                } catch (Exception $e) {
                                    $import_results['errors']++;
                                    $import_results['details'][] = "Row $row_number ($domain): Skipped (Error) - Invalid expiration date format ('$expires_at_str'). Use YYYY-MM-DD.";
                                    continue;
                                }
                            }

                            // Check if primary domain exists
                            $stmt_check = $db->prepare("SELECT COUNT(*) as count FROM licenses WHERE domain = :domain");
                            $stmt_check->bindValue(':domain', $domain, SQLITE3_TEXT);
                            $exists = $stmt_check->execute()->fetchArray(SQLITE3_ASSOC)['count'] > 0;

                            if ($exists) {
                                $import_results['skipped']++;
                                $import_results['details'][] = "Row $row_number ($domain): Skipped (Exists) - Primary domain already exists.";
                                continue;
                            }

                            // Check if secondary domain exists as a primary domain elsewhere
                            if (!empty($secondary_domain)) {
                                $stmt_check_sec = $db->prepare("SELECT COUNT(*) as count FROM licenses WHERE domain = :secondary_domain");
                                $stmt_check_sec->bindValue(':secondary_domain', $secondary_domain, SQLITE3_TEXT);
                                $sec_exists_as_primary = $stmt_check_sec->execute()->fetchArray(SQLITE3_ASSOC)['count'] > 0;
                                if ($sec_exists_as_primary) {
                                    $import_results['errors']++;
                                    $import_results['details'][] = "Row $row_number ($domain): Skipped (Error) - Secondary domain '$secondary_domain' is already used as a primary domain for another license.";
                                    continue;
                                }
                            }

                            $current_time = date('Y-m-d H:i:s');
                            // Added site_limit to INSERT statement
                            $stmt_insert = $db->prepare("INSERT INTO licenses (domain, secondary_domain, client_name, license_key, token, email, status, expires_at, license_type, site_limit, created_at, updated_at, server_name, ip_address) VALUES (:domain, :secondary_domain, :client_name, :license_key, :token, :email, :status, :expires_at, :license_type, :site_limit, :created_at, :updated_at, :server_name, :ip_address)");
                            // Bind values...
                            $stmt_insert->bindValue(':domain', $domain, SQLITE3_TEXT);
                            $stmt_insert->bindValue(':secondary_domain', $secondary_domain, $secondary_domain ? SQLITE3_TEXT : SQLITE3_NULL);
                            $stmt_insert->bindValue(':client_name', $client_name, SQLITE3_TEXT);
                            $stmt_insert->bindValue(':license_key', $license_key, SQLITE3_TEXT);
                            $stmt_insert->bindValue(':token', $token, SQLITE3_TEXT);
                            $stmt_insert->bindValue(':email', $email, $email ? SQLITE3_TEXT : SQLITE3_NULL);
                            $stmt_insert->bindValue(':status', $status, SQLITE3_TEXT);
                            $stmt_insert->bindValue(':expires_at', $expires_at_db, $expires_at_db ? SQLITE3_TEXT : SQLITE3_NULL);
                            $stmt_insert->bindValue(':license_type', $license_type, SQLITE3_TEXT);
                            $stmt_insert->bindValue(':site_limit', $site_limit, SQLITE3_TEXT); // Bind site_limit
                            $stmt_insert->bindValue(':created_at', $current_time, SQLITE3_TEXT);
                            $stmt_insert->bindValue(':updated_at', $current_time, SQLITE3_TEXT);
                            $stmt_insert->bindValue(':server_name', 'Imported', SQLITE3_TEXT);
                            $stmt_insert->bindValue(':ip_address', 'Imported', SQLITE3_TEXT);
                            $stmt_insert->execute();
                            $import_results['imported']++;
                            $import_results['details'][] = "Row $row_number ($domain): Imported successfully.";
                        } // End while loop
                        fclose($file_handle);
                        log_activity($db, 'LICENSE_IMPORT', "Processed: {$import_results['processed']}, Imported: {$import_results['imported']}, Skipped: {$import_results['skipped']}, Errors: {$import_results['errors']}");
                        $_SESSION['success_message'] = "CSV Import processed.";
                        $_SESSION['import_results'] = $import_results; // Store results for display
                        break; // Break from switch

                    // --- Create Admin Action ---
                    case 'create_admin':
                        $username = trim($_POST['username'] ?? '');
                        $password = $_POST['password'] ?? '';
                        $confirm_password = $_POST['confirm_password'] ?? '';

                        if (empty($username) || empty($password) || empty($confirm_password)) {
                            throw new Exception('All fields are required for creating an admin.');
                        } elseif ($password !== $confirm_password) {
                            throw new Exception('Passwords do not match.');
                        } elseif (strlen($password) < 8) {
                             throw new Exception('Password must be at least 8 characters long.');
                        }

                        $result = create_admin($username, $password);
                        if ($result !== true) {
                            throw new Exception($result); // Throw the error message from create_admin
                        }
                        log_activity($db, 'ADMIN_CREATE', "Created admin: " . $username);
                        $_SESSION['success_message'] = '✅ Admin user "' . htmlspecialchars($username) . '" created successfully.';
                        break;

                    // --- Update Admin Password Action ---
                    case 'update_admin_password':
                        $admin_id = filter_input(INPUT_POST, 'admin_id', FILTER_VALIDATE_INT);
                        $new_password = $_POST['new_password'] ?? '';
                        $confirm_new_password = $_POST['confirm_new_password'] ?? '';

                        if (!$admin_id || $admin_id <= 0) {
                            throw new Exception('Invalid Admin ID for password update.');
                        }
                        if (empty($new_password) || empty($confirm_new_password)) {
                            throw new Exception('New password and confirmation are required.');
                        } elseif ($new_password !== $confirm_new_password) {
                            throw new Exception('New passwords do not match.');
                        } elseif (strlen($new_password) < 8) {
                             throw new Exception('New password must be at least 8 characters long.');
                        }

                        $result = update_admin_password($admin_id, $new_password);
                         if ($result !== true) {
                             // If update_admin_password returns false or error string
                             throw new Exception(is_string($result) ? $result : 'Failed to update password.');
                         }

                        // Fetch username for logging
                        $stmt_get_user = $db->prepare("SELECT username FROM admins WHERE id = :id");
                        $stmt_get_user->bindValue(':id', $admin_id, SQLITE3_INTEGER);
                        $admin_user = $stmt_get_user->execute()->fetchArray(SQLITE3_ASSOC);
                        $log_detail = $admin_user ? "Updated password for admin: " . $admin_user['username'] : "Updated password for admin ID: $admin_id";

                        log_activity($db, 'ADMIN_PWD_UPDATE', $log_detail);
                        $_SESSION['success_message'] = '✅ Admin password updated successfully.';
                        break;

                    // --- Delete Admin Action ---
                    case 'delete_admin':
                        $admin_id_to_delete = filter_input(INPUT_POST, 'admin_id', FILTER_VALIDATE_INT);
                        $current_admin_id = $_SESSION['admin_id'] ?? 0;

                        if (!$admin_id_to_delete || $admin_id_to_delete <= 0) {
                            throw new Exception('Invalid Admin ID for deletion.');
                        }

                         // Fetch username before deleting for logging
                        $stmt_get_user_del = $db->prepare("SELECT username FROM admins WHERE id = :id");
                        $stmt_get_user_del->bindValue(':id', $admin_id_to_delete, SQLITE3_INTEGER);
                        $admin_user_del = $stmt_get_user_del->execute()->fetchArray(SQLITE3_ASSOC);
                        $deleted_username = $admin_user_del ? $admin_user_del['username'] : "ID: $admin_id_to_delete";


                        $result = delete_admin($admin_id_to_delete, $current_admin_id);
                        if ($result !== true) {
                            throw new Exception(is_string($result) ? $result : 'Failed to delete admin.');
                        }
                        log_activity($db, 'ADMIN_DELETE', "Deleted admin: " . $deleted_username);
                        $_SESSION['success_message'] = '🗑️ Admin user deleted successfully.';
                        break;

                    // --- Update Rate Limit Settings ---
                    case 'update_rate_limit':
                        $timeframe = filter_input(INPUT_POST, 'rate_limit_timeframe', FILTER_VALIDATE_INT);
                        $max_requests = filter_input(INPUT_POST, 'rate_limit_max_requests', FILTER_VALIDATE_INT);

                        if ($timeframe === false || $timeframe < 0 || $max_requests === false || $max_requests < 0) {
                            throw new Exception('Invalid input for rate limit settings. Please enter non-negative integers.');
                        }

                        set_setting($db, 'rate_limit_timeframe', $timeframe);
                        set_setting($db, 'rate_limit_max_requests', $max_requests);
                        log_activity($db, 'SETTINGS_UPDATE', "Rate Limit updated: $max_requests requests / $timeframe seconds");
                        $_SESSION['success_message'] = '⚙️ Rate limit settings updated.';
                        break;

                    // --- Update General Settings ---
                     case 'update_general_settings':
                        $default_type = $_POST['default_license_type'] ?? 'Trial';
                        $allowed_types = ['Trial', 'Monthly', 'Yearly', 'Lifetime'];
                        if (!in_array($default_type, $allowed_types)) {
                            throw new Exception('Invalid default license type selected.');
                        }
                        set_setting($db, 'default_license_type', $default_type);
                        log_activity($db, 'SETTINGS_UPDATE', "Default License Type set to: $default_type");
                        $_SESSION['success_message'] = '⚙️ General settings updated.';
                        break;

                    default:
                        // Optional: Handle unknown action?
                        break;
                }

                $db->exec('COMMIT'); // Commit transaction if no exceptions were thrown

            } catch (Exception $e) {
                $db->exec('ROLLBACK'); // Rollback on error
                error_log("Settings Page Error (Action: $action): " . $e->getMessage());
                $_SESSION['error_message'] = "An error occurred: " . $e->getMessage();
                 // Store submitted username if creating admin failed
                 if ($action === 'create_admin') { $_SESSION['submitted_username'] = $_POST['username'] ?? ''; }
            }

            // Redirect back to settings page to show messages and clear POST data
            $redirect_anchor = '';
            if ($action === 'import_csv') $redirect_anchor = '#import-export-section';
            elseif (in_array($action, ['create_admin', 'update_admin_password', 'delete_admin'])) $redirect_anchor = '#admin-management-section';
            elseif ($action === 'update_rate_limit') $redirect_anchor = '#rate-limit-section';
            elseif ($action === 'update_general_settings') $redirect_anchor = '#general-settings-section';

            header('Location: settings.php' . $redirect_anchor);
            exit;
        } // End POST handling

        // --- Prepare Data for Display ---

        // Retrieve import results from session if they exist
        if (isset($_SESSION['import_results'])) {
            $import_results = $_SESSION['import_results'];
            unset($_SESSION['import_results']);
        }
        // Retrieve submitted username on create admin error
        if (isset($_SESSION['submitted_username'])) {
            $submitted_username = $_SESSION['submitted_username'];
            unset($_SESSION['submitted_username']);
        }


        // Get current settings
        $current_rate_limit_timeframe = get_setting($db, 'rate_limit_timeframe', 60);
        $current_rate_limit_max_requests = get_setting($db, 'rate_limit_max_requests', 10);
        $current_default_license_type = get_setting($db, 'default_license_type', 'Trial');

        // Get admin list
        $admins = get_all_admins();

        // Read rate limit log file
        if (file_exists($rate_limit_log_file)) {
            $rate_limit_log_content = @file_get_contents($rate_limit_log_file);
            if ($rate_limit_log_content === false) {
                $rate_limit_log_error = "Error reading rate limit log file. Check permissions.";
            } elseif (empty(trim($rate_limit_log_content))) {
                 $rate_limit_log_content = "Log file is empty.";
            }
        } else {
            $rate_limit_log_content = "Rate limit log file does not exist.";
        }

        // Check file permissions
        $db_permission = check_writable($db_file) ? '<span class="perm-ok">Writable ✅</span>' : '<span class="perm-error">Not Writable ❌</span>';
        $log_permission = file_exists($rate_limit_log_file)
            ? (check_writable($rate_limit_log_file) ? '<span class="perm-ok">Writable ✅</span>' : '<span class="perm-error">Not Writable ❌</span>')
            : '<span class="perm-warn">Log file does not exist</span>';


        // --- Include Header ---
        include 'header.php';
        ?>

        <!-- =======================
             ADMIN MANAGEMENT SECTION
             ======================= -->
        <div id="admin-management-section" class="settings-section">
            <h2>👤 Admin Management</h2>

            <!-- List Admins Table -->
            <div class="table-responsive">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($admins)): ?>
                            <tr><td colspan="3">No admin users found.</td></tr>
                        <?php else: ?>
                            <?php foreach ($admins as $admin): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($admin['username']); ?></td>
                                    <td><?php echo htmlspecialchars($admin['created_at'] ? date('Y-m-d H:i', strtotime($admin['created_at'])) : 'N/A'); ?></td>
                                    <td class="actions">
                                        <!-- Update Password Form (Inline for simplicity, consider modal later) -->
                                        <form method="POST" action="settings.php" class="inline-form" onsubmit="return confirm('Update password for <?php echo htmlspecialchars(addslashes($admin['username'])); ?>?');">
                                            <?php csrf_input_field(); ?>
                                            <input type="hidden" name="action" value="update_admin_password">
                                            <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>">
                                            <input type="password" name="new_password" placeholder="New Password" required minlength="8" class="input-sm">
                                            <input type="password" name="confirm_new_password" placeholder="Confirm Password" required minlength="8" class="input-sm">
                                            <button type="submit" class="btn btn-warning btn-sm" title="Update Password">🔑 Update</button>
                                        </form>
                                        <!-- Delete Admin Form -->
                                        <?php if (count($admins) > 1): // Show delete only if not the last admin ?>
                                            <form method="POST" action="settings.php" class="inline-form" onsubmit="return confirm('DELETE admin <?php echo htmlspecialchars(addslashes($admin['username'])); ?>? This cannot be undone!');">
                                                <?php csrf_input_field(); ?>
                                                <input type="hidden" name="action" value="delete_admin">
                                                <input type="hidden" name="admin_id" value="<?php echo $admin['id']; ?>">
                                                <button type="submit" class="btn btn-delete btn-sm" title="Delete Admin" <?php echo ($admin['id'] === ($_SESSION['admin_id'] ?? 0)) ? 'disabled title="Cannot delete self (currently)"' : ''; ?>>🗑️ Delete</button>
                                            </form>
                                        <?php else: ?>
                                             <button class="btn btn-delete btn-sm" disabled title="Cannot delete the last admin">🗑️ Delete</button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <hr class="compact-divider">

            <!-- Create New Admin Form -->
            <h3>Create New Admin</h3>
             <form method="POST" action="settings.php" class="styled-form form-condensed">
                 <?php csrf_input_field(); ?>
                 <input type="hidden" name="action" value="create_admin">
                 <div class="form-group">
                     <label for="username">New Username</label>
                     <input type="text" id="username" name="username" required value="<?php echo htmlspecialchars($submitted_username); ?>">
                 </div>
                 <div class="form-group">
                     <label for="password">Password (min 8 chars)</label>
                     <input type="password" id="password" name="password" required minlength="8">
                 </div>
                 <div class="form-group">
                     <label for="confirm_password">Confirm Password</label>
                     <input type="password" id="confirm_password" name="confirm_password" required minlength="8">
                 </div>
                 <button type="submit" class="btn btn-primary">Create Admin</button>
             </form>
        </div>

        <hr class="settings-divider">

        <!-- =======================
             RATE LIMITING SECTION
             ======================= -->
        <div id="rate-limit-section" class="settings-section">
            <h2>⏱️ Rate Limiting (License Check Endpoint)</h2>
            <p>Configure limits for the public <code>license-check.php</code> endpoint to prevent abuse.</p>

            <form method="POST" action="settings.php" class="styled-form form-condensed">
                <?php csrf_input_field(); ?>
                <input type="hidden" name="action" value="update_rate_limit">
                <div class="form-group">
                    <label for="rate_limit_max_requests">Max Requests</label>
                    <input type="number" id="rate_limit_max_requests" name="rate_limit_max_requests" min="0" required value="<?php echo htmlspecialchars($current_rate_limit_max_requests); ?>">
                    <small>Maximum number of requests allowed per IP address within the timeframe (0 to disable).</small>
                </div>
                 <div class="form-group">
                    <label for="rate_limit_timeframe">Timeframe (seconds)</label>
                    <input type="number" id="rate_limit_timeframe" name="rate_limit_timeframe" min="0" required value="<?php echo htmlspecialchars($current_rate_limit_timeframe); ?>">
                     <small>The duration in seconds for the request limit (0 to disable).</small>
                </div>
                <button type="submit" class="btn btn-primary">Update Rate Limit Settings</button>
            </form>

            <hr class="compact-divider">

            <h3>Rate Limit Log</h3>
            <p>Shows recent request timestamps per IP (stored in <code><?php echo basename($rate_limit_log_file); ?></code>).</p>
            <?php if ($rate_limit_log_error): ?>
                <p class="error-message"><?php echo htmlspecialchars($rate_limit_log_error); ?></p>
            <?php else: ?>
                <pre class="log-display"><?php echo htmlspecialchars($rate_limit_log_content); ?></pre>
            <?php endif; ?>
        </div>

        <hr class="settings-divider">

        <!-- =======================
             IMPORT / EXPORT SECTION
             ======================= -->
        <div id="import-export-section" class="settings-section">
             <h2>🔄 Import / Export Licenses</h2>

             <!-- Export -->
             <h3>Export Licenses</h3>
             <p>Download all current license data as a CSV file.</p>
             <a href="export-licenses.php" class="btn btn-secondary">Export All Licenses to CSV</a>

             <hr class="compact-divider">

             <!-- Import -->
             <h3 id="import-section">Import Licenses</h3>
             <p>Upload a CSV file with license data. <a href="download-template.php" class="link-inline">Download Template CSV</a></p>
             <p>Required columns (case-insensitive): <code>domain</code>, <code>license_key</code>, <code>token</code>.<br>
                Optional: <code>secondary_domain</code>, <code>client_name</code>, <code>email</code>, <code>status</code> (active/inactive), <code>expires_at</code> (YYYY-MM-DD), <code>license_type</code> (Trial/Monthly/Yearly/Lifetime), <code>site_limit</code> (Single/Unlimited).</p>
             <p><strong>Important:</strong> Licenses with primary domains that already exist will be skipped. Secondary domains cannot be the same as the primary domain and cannot already exist as a primary domain for another license.</p>

             <form method="POST" action="settings.php" enctype="multipart/form-data" class="styled-form form-condensed">
                 <?php csrf_input_field(); ?>
                 <input type="hidden" name="action" value="import_csv">
                 <div class="form-group">
                     <label for="csv_file">Select CSV File</label>
                     <input type="file" id="csv_file" name="csv_file" accept=".csv, text/csv" required>
                 </div>
                 <button type="submit" class="btn btn-primary">Import Licenses</button>
             </form>

             <?php if ($import_results): ?>
                 <div class="import-results" style="margin-top: 20px;">
                     <h4>Import Results:</h4>
                     <p>
                         Processed: <?php echo (int)$import_results['processed']; ?> |
                         Imported: <span class="imported"><?php echo (int)$import_results['imported']; ?></span> |
                         Skipped (Exists/Error): <span class="skipped"><?php echo (int)$import_results['skipped'] + (int)$import_results['errors']; ?></span>
                     </p>
                     <?php if (!empty($import_results['details'])): ?>
                         <h5>Details:</h5>
                         <ul class="import-details-list">
                             <?php foreach ($import_results['details'] as $detail): ?>
                                 <?php
                                     $class = '';
                                     if (strpos($detail, 'Skipped (Exists)') !== false) $class = 'skipped';
                                     elseif (strpos($detail, 'Skipped (Error)') !== false || strpos($detail, 'ABORTED') !== false) $class = 'error';
                                     elseif (strpos($detail, 'Imported') !== false) $class = 'imported';
                                 ?>
                                 <li class="<?php echo $class; ?>"><?php echo htmlspecialchars($detail); ?></li>
                             <?php endforeach; ?>
                         </ul>
                     <?php endif; ?>
                 </div>
             <?php endif; ?>
        </div>

        <hr class="settings-divider">

        <!-- =======================
             SYSTEM INFO SECTION
             ======================= -->
        <div id="system-info-section" class="settings-section">
            <h2>ℹ️ System Information & Checks</h2>
            <ul class="system-info-list">
                <li>PHP Version: <span><?php echo htmlspecialchars(phpversion()); ?></span></li>
                <li>Database File: <span><?php echo htmlspecialchars($db_file); ?></span></li>
                <li>Database Permissions: <?php echo $db_permission; ?></li>
                <li>Rate Limit Log File: <span><?php echo htmlspecialchars($rate_limit_log_file); ?></span></li>
                <li>Log File Permissions: <?php echo $log_permission; ?></li>
            </ul>
        </div>

        <hr class="settings-divider">

        <!-- =======================
             GENERAL SETTINGS SECTION
             ======================= -->
        <div id="general-settings-section" class="settings-section">
            <h2>⚙️ General Settings</h2>
            <form method="POST" action="settings.php" class="styled-form form-condensed">
                <?php csrf_input_field(); ?>
                <input type="hidden" name="action" value="update_general_settings">
                <div class="form-group">
                    <label for="default_license_type">Default License Type for New Licenses</label>
                    <select id="default_license_type" name="default_license_type">
                        <option value="Trial" <?php echo ($current_default_license_type === 'Trial') ? 'selected' : ''; ?>>Trial</option>
                        <option value="Monthly" <?php echo ($current_default_license_type === 'Monthly') ? 'selected' : ''; ?>>Monthly</option>
                        <option value="Yearly" <?php echo ($current_default_license_type === 'Yearly') ? 'selected' : ''; ?>>Yearly</option>
                        <option value="Lifetime" <?php echo ($current_default_license_type === 'Lifetime') ? 'selected' : ''; ?>>Lifetime</option>
                    </select>
                    <small>This will be the pre-selected type when adding a new license manually.</small>
                </div>
                <button type="submit" class="btn btn-primary">Save General Settings</button>
            </form>
        </div>

        <?php include 'footer.php'; ?>
