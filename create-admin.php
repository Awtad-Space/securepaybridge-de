<?php
        require_once 'auth.php';
        require_once 'utils.php';

        // IMPORTANT: Keep this active unless absolutely necessary to create the first admin.
        require_login();

        // Use session messages
        // $success_message, $error_message removed

        $submitted_username = ''; // To repopulate username field on error

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $local_error_message = ''; // Local error for immediate feedback

            // Validate CSRF token
            if (!isset($_POST['csrf_token']) || !validate_csrf_token($_POST['csrf_token'])) {
                 exit;
            }

            $username = trim($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';
            $confirm_password = $_POST['confirm_password'] ?? '';
            $submitted_username = $username; // Store for repopulation

            if (empty($username) || empty($password) || empty($confirm_password)) {
                $local_error_message = 'All fields are required.';
            } elseif ($password !== $confirm_password) {
                $local_error_message = 'Passwords do not match.';
            } elseif (strlen($password) < 8) {
                 $local_error_message = 'Password must be at least 8 characters long.';
            } else {
                $result = create_admin($username, $password);
                if ($result === true) {
                    $_SESSION['success_message'] = '✅ Admin user "' . htmlspecialchars($username) . '" created successfully.';
                    // Redirect to avoid re-submission and clear form
                    header('Location: create-admin.php');
                    exit;
                } else {
                    $local_error_message = htmlspecialchars($result); // Show error message from create_admin function
                }
            }
            // If error occurred, store it in session for display via header
            $_SESSION['error_message'] = $local_error_message;
        }

        $page_title = 'Create New Admin';
        include 'header.php'; // Header includes message display logic
        ?>

        <?php // Display local error message immediately if it was set during POST processing
            if (!empty($local_error_message)) {
                 echo '<div class="message error-message">' . htmlspecialchars($local_error_message) . '<span class="close-message" onclick="this.parentElement.style.display=\'none\';">&times;</span></div>';
                 unset($_SESSION['error_message']); // Clear session error if local one was shown
            }
        ?>

        <form method="POST" action="create-admin.php" class="styled-form">
            <?php csrf_input_field(); // Add CSRF token field ?>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required value="<?php echo htmlspecialchars($submitted_username); ?>">
            </div>

            <div class="form-group">
                <label for="password">Password (min 8 characters)</label>
                <input type="password" id="password" name="password" required>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>

            <button type="submit" class="btn btn-primary">Create Admin</button>
        </form>

        <?php include 'footer.php'; ?>
