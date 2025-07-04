<?php
        require_once 'auth.php';
        require_once 'utils.php';

        $current_page = basename($_SERVER['PHP_SELF']);
        $db_status_ok = true; // Assume OK initially
        try {
            // Simple check to see if DB connection is alive (already established in db.php)
            global $db;
            $db->querySingle("SELECT 1");
        } catch (Exception $e) {
            $db_status_ok = false;
            error_log("DB Connection Check Failed in header: " . $e->getMessage());
        }

        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - License Manager' : 'License Manager'; ?></title>
            <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
            <!-- Include Chart.js CDN -->
            <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
        </head>
        <body>
            <div class="page-wrapper">
                <?php if (is_logged_in()): ?>
                <aside class="sidebar">
                    <div class="sidebar-header">
                        <img src="logo.png" alt="Logo" class="logo" onerror="this.style.display='none'; this.onerror=null;">
                        <h2>License Manager</h2>
                    </div>
                    <nav class="main-nav">
                        <ul>
                            <li <?php echo ($current_page === 'dashboard.php') ? 'class="active"' : ''; ?>>
                                <a href="dashboard.php">📊 <span>Dashboard</span></a>
                            </li>
                            <li <?php echo ($current_page === 'view-licenses.php') ? 'class="active"' : ''; ?>>
                                <a href="view-licenses.php">📄 <span>View Licenses</span></a>
                            </li>
                            <li <?php echo ($current_page === 'add-license.php') ? 'class="active"' : ''; ?>>
                                <a href="add-license.php">➕ <span>Add/Edit License</span></a>
                            </li>
                             <li <?php echo ($current_page === 'upload-updates.php') ? 'class="active"' : ''; ?>>
                                <a href="upload-updates.php">⬆️ <span>Upload Updates</span></a>
                            </li>
                            <li <?php echo ($current_page === 'settings.php') ? 'class="active"' : ''; ?>>
                                <a href="settings.php">⚙️ <span>Settings</span></a>
                            </li>
                            <li>
                                <a href="logout.php" class="logout-link">🚪 <span>Logout (<?php echo htmlspecialchars($_SESSION['admin_username'] ?? ''); ?>)</span></a>
                            </li>
                        </ul>
                    </nav>
                     <div class="sidebar-footer" style="padding: 15px; text-align: center; border-top: 1px solid rgba(255, 255, 255, 0.1); margin-top: auto;">
                        <span style="font-size: 12px; color: <?php echo $db_status_ok ? '#2ecc71' : '#e74c3c'; ?>;">
                            <?php echo $db_status_ok ? '🟢 Database Connected' : '🔴 Database Error'; ?>
                        </span>
                    </div>
                </aside>
                <?php endif; ?>

                <div class="main-content <?php echo is_logged_in() ? '' : 'no-sidebar'; ?>">
                    <header class="content-header">
                        <h1><?php echo isset($page_title) ? htmlspecialchars($page_title) : 'License Manager'; ?></h1>
                    </header>
                    <main class="content-body">
                    <div id="messages-container">
                        <?php
                        // Display session messages
                        if (isset($_SESSION['success_message'])) {
                            echo '<div class="message success-message">' . htmlspecialchars($_SESSION['success_message']) . '<span class="close-message">&times;</span></div>';
                            unset($_SESSION['success_message']);
                        }
                        if (isset($_SESSION['error_message'])) {
                            echo '<div class="message error-message">' . htmlspecialchars($_SESSION['error_message']) . '<span class="close-message">&times;</span></div>';
                            unset($_SESSION['error_message']);
                        }
                        if (isset($_SESSION['notice_message'])) {
                            echo '<div class="message notice">' . htmlspecialchars($_SESSION['notice_message']) . '<span class="close-message">&times;</span></div>';
                            unset($_SESSION['notice_message']);
                        }
                        ?>
                    </div>
