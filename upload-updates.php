<?php
        require_once 'auth.php';
        require_once 'db.php';
        require_once 'utils.php';

        // Helper function for file size formatting (similar to WordPress size_format)
        // MOVED TO THE TOP to be defined before use
        if (!function_exists('size_format')) {
            function size_format($bytes, $decimals = 2) {
                $units = array('B', 'KB', 'MB', 'GB', 'TB', 'PB', 'EB', 'ZB', 'YB');
                $factor = floor((strlen($bytes) - 1) / 3);
                return sprintf("%.{$decimals}f", $bytes / (1024 ** $factor)) . ' ' . $units[$factor];
            }
        }

        require_login(); // Ensure only logged-in admins can access

        $page_title = 'Upload Plugin Updates';
        $upload_dir = __DIR__ . '/downloads/'; // Directory to store update files

        // Ensure the upload directory exists and is writable
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true); // Create directory recursively with permissions
        }
        $is_upload_dir_writable = is_writable($upload_dir);

        // --- Handle File Upload POST Request ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $local_error_message = '';
            $local_success_message = '';

            // Validate CSRF token
            if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
                 $_SESSION['error_message'] = 'Invalid request token. Please refresh and try again.';
                 header('Location: upload-updates.php');
                 exit;
            }

            if (!$is_upload_dir_writable) {
                 $local_error_message = "Upload directory is not writable: " . htmlspecialchars($upload_dir) . ". Please check server permissions.";
            } elseif (isset($_FILES['plugin_zip']) && $_FILES['plugin_zip']['error'] === UPLOAD_ERR_OK) {
                $file_tmp_path = $_FILES['plugin_zip']['tmp_name'];
                $file_name = $_FILES['plugin_zip']['name'];
                $file_size = $_FILES['plugin_zip']['size'];
                $file_type = $_FILES['plugin_zip']['type'];
                $allowed_types = ['application/zip', 'application/x-zip-compressed']; // Common ZIP MIME types

                // Basic file validation
                if (!in_array($file_type, $allowed_types) && !str_ends_with(strtolower($file_name), '.zip')) {
                    $local_error_message = "Invalid file type. Only ZIP files are allowed.";
                } elseif ($file_size > 10 * 1024 * 1024) { // Example: 10MB limit
                    $local_error_message = "File size exceeds the maximum limit (10MB).";
                } else {
                    // Validate filename format: expected format is [slug]-[version].zip
                    // Example: woocommerce-rest-b-1.2.3.zip or woocommerce-rest-a-2.0.0.zip
                    if (preg_match('/^([a-z0-9_-]+)-([0-9\.]+)\.zip$/i', $file_name, $matches)) {
                        $slug_part = $matches[1];
                        $version_part = $matches[2];
                        $target_filename = strtolower($slug_part) . '-' . $version_part . '.zip'; // Standardize filename case

                        $target_file_path = $upload_dir . $target_filename;

                        $file_existed = file_exists($target_file_path); // Check if file exists BEFORE moving

                        // Move the uploaded file - this will overwrite if it exists
                        if (move_uploaded_file($file_tmp_path, $target_file_path)) {
                            if ($file_existed) {
                                $local_success_message = "✅ File '" . htmlspecialchars($file_name) . "' uploaded successfully, overwriting existing file '" . htmlspecialchars($target_filename) . "'.";
                                log_activity($db, 'UPDATE_OVERWRITE_SUCCESS', "Overwritten: " . $target_filename);
                            } else {
                                $local_success_message = "✅ File '" . htmlspecialchars($file_name) . "' uploaded successfully as '" . htmlspecialchars($target_filename) . "'.";
                                log_activity($db, 'UPDATE_UPLOAD_SUCCESS', "Uploaded: " . $target_filename);
                            }
                        } else {
                            $local_error_message = "Error moving uploaded file. Check directory permissions.";
                            log_activity($db, 'UPDATE_UPLOAD_FAIL', "Failed to move: " . $file_name);
                        }
                    } else {
                        // Filename format is incorrect - REJECT UPLOAD
                        $local_error_message = "Invalid filename format. Expected format: <code>plugin-slug-version.zip</code> (e.g., <code>woocommerce-rest-b-1.2.3.zip</code>). File not uploaded.";
                        // No log_activity here as the file wasn't moved/processed
                    }
                }
            } elseif (isset($_FILES['plugin_zip']) && $_FILES['plugin_zip']['error'] !== UPLOAD_ERR_NO_FILE) {
                 $local_error_message = "File upload error: Code " . $_FILES['plugin_zip']['error'];
            } else {
                 // No file uploaded or other non-critical error (e.g., form submitted without file)
                 // $local_notice_message = "Please select a file to upload."; // Optional notice
            }

            // Store messages in session for display after redirect
            if ($local_success_message) $_SESSION['success_message'] = $local_success_message;
            if ($local_error_message) $_SESSION['error_message'] = $local_error_message;
            // if ($local_notice_message) $_SESSION['notice_message'] = $local_notice_message;

            // Redirect to prevent form re-submission
            header('Location: upload-updates.php');
            exit;
        }

        // --- Display Uploaded Files ---
        $uploaded_files = [];
        error_log("DEBUG: Starting scan of upload directory: " . $upload_dir); // Debug log start
        if (is_dir($upload_dir)) {
            $files = scandir($upload_dir);
            if ($files !== false) {
                error_log("DEBUG: scandir found " . count($files) . " items."); // Debug log count
                foreach ($files as $file) {
                    error_log("DEBUG: Processing file: " . $file); // Debug log filename
                    $file_path = $upload_dir . $file;
                    if ($file !== '.' && $file !== '..' && is_file($file_path)) {
                        error_log("DEBUG: '" . $file . "' is a valid file."); // Debug log is_file check
                        // Try to parse slug and version from filename
                        $slug = 'N/A';
                        $version = 'N/A';
                        // ONLY list files that match the expected format
                        if (preg_match('/^([a-z0-9_-]+)-([0-9\.]+)\.zip$/i', $file, $matches)) {
                            $slug = $matches[1];
                            $version = $matches[2];
                            error_log("DEBUG: '" . $file . "' matched pattern. Slug: " . $slug . ", Version: " . $version); // Debug log match success
                             $uploaded_files[] = [
                                'name' => $file,
                                'slug' => $slug,
                                'version' => $version,
                                'size' => filesize($file_path), // Use $file_path
                                'modified' => filemtime($file_path), // Use $file_path
                            ];
                             error_log("DEBUG: Added '" . $file . "' to uploaded_files list."); // Debug log add to list
                        } else {
                             error_log("DEBUG: '" . $file . "' did NOT match pattern."); // Debug log match failure
                        }
                        // Files that don't match the format are ignored in this list
                    } else {
                         error_log("DEBUG: '" . $file . "' is not a valid file or is '.' or '..'."); // Debug log is_file failure
                    }
                }
            } else {
                 error_log("DEBUG: scandir returned false for directory: " . $upload_dir); // Debug log scandir failure
            }
        } else {
             error_log("DEBUG: Upload directory is not a directory or does not exist: " . $upload_dir); // Debug log is_dir failure
        }
        error_log("DEBUG: Finished scan. Found " . count($uploaded_files) . " files matching the pattern."); // Debug log end and count


        // Sort files by slug, then version (descending)
        usort($uploaded_files, function($a, $b) {
            $slug_cmp = strcmp($a['slug'], $b['slug']);
            if ($slug_cmp !== 0) return $slug_cmp;
            // Compare versions numerically (descending)
            return version_compare($b['version'], $a['version']);
        });


        include 'header.php';
        ?>

        <div class="settings-section">
            <h2>⬆️ Upload Plugin Updates</h2>
            <p>Upload new versions of the plugin ZIP files here. Files should be named using the format <code>[slug]-[version].zip</code> (e.g., <code>woocommerce-rest-b-1.2.3.zip</code>).</p>
            <p>The update server will automatically detect the latest version for each plugin based on the filenames in the <code>/auth/downloads/</code> directory.</p>

            <?php if (!$is_upload_dir_writable): ?>
                 <div class="message error-message persistent">
                     <strong>Error:</strong> The upload directory <code><?php echo htmlspecialchars($upload_dir); ?></code> is not writable. Please ensure the server has write permissions for this directory. Updates cannot be uploaded until this is fixed.
                 </div>
            <?php endif; ?>

            <form method="POST" action="upload-updates.php" enctype="multipart/form-data" class="styled-form form-condensed">
                <?php csrf_input_field(); ?>
                <div class="form-group">
                    <label for="plugin_zip">Select Plugin ZIP File</label>
                    <input type="file" id="plugin_zip" name="plugin_zip" accept=".zip" required <?php echo $is_upload_dir_writable ? '' : 'disabled'; ?>>
                    <small>Max file size: 10MB. Accepted format: <code>[slug]-[version].zip</code></small>
                </div>
                <button type="submit" class="btn btn-primary" <?php echo $is_upload_dir_writable ? '' : 'disabled'; ?>>Upload File</button>
            </form>

            <hr class="compact-divider">

            <h3>Uploaded Files (<code>/auth/downloads/</code>)</h3>
            <?php if (empty($uploaded_files)): ?>
                <p>No update files matching the <code>[slug]-[version].zip</code> format have been uploaded yet.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table>
                        <thead>
                            <tr>
                                <th>Filename</th>
                                <th>Slug</th>
                                <th>Version</th>
                                <th>Size</th>
                                <th>Last Modified</th>
                                <!-- Add Delete action later if needed -->
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($uploaded_files as $file): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($file['name']); ?></td>
                                    <td><?php echo htmlspecialchars($file['slug']); ?></td> <?php // Added echo ?>
                                    <td><?php echo htmlspecialchars($file['version']); ?></td> <?php // Added echo ?>
                                    <td><?php echo size_format($file['size']); ?></td>
                                    <td><?php echo htmlspecialchars(date('Y-m-d H:i', $file['modified'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php include 'footer.php'; ?>
