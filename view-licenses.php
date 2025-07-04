<?php
        require_once 'auth.php';
        require_once 'db.php';
        require_once 'utils.php';

        require_login();

        // --- Handle Actions (Delete, Toggle Status) ---
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'], $_POST['csrf_token'])) {
            // Validate CSRF Token
            if (!validate_csrf_token($_POST['csrf_token'])) {
                $_SESSION['error_message'] = 'Invalid request token. Please try again.';
                // Redirect back to the same page with existing query parameters
                header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
                exit;
            }

            $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
            $action = $_POST['action'];

            if ($id === false || $id <= 0) {
                $_SESSION['error_message'] = 'Invalid ID provided for action.';
            } else {
                try {
                    // Fetch domain for logging before potential deletion
                    $stmt_get_domain = $db->prepare("SELECT domain FROM licenses WHERE id = :id");
                    $stmt_get_domain->bindValue(':id', $id, SQLITE3_INTEGER);
                    $domain_result = $stmt_get_domain->execute()->fetchArray(SQLITE3_ASSOC);
                    $domain_for_log = $domain_result ? $domain_result['domain'] : "ID: $id";

                    $db->exec('BEGIN'); // Start transaction

                    if ($action === 'delete') {
                        $stmt = $db->prepare("DELETE FROM licenses WHERE id = :id");
                        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
                        $stmt->execute();
                        log_activity($db, 'LICENSE_DELETE', "Deleted: " . $domain_for_log);
                        $_SESSION['success_message'] = '🗑️ License deleted successfully.';

                    } elseif ($action === 'toggle') {
                        $stmt_get = $db->prepare("SELECT status FROM licenses WHERE id = :id");
                        $stmt_get->bindValue(':id', $id, SQLITE3_INTEGER);
                        $current_status = $stmt_get->execute()->fetchArray(SQLITE3_ASSOC)['status'] ?? null;

                        if ($current_status) {
                            $new_status = ($current_status === 'active') ? 'inactive' : 'active';
                            $stmt_update = $db->prepare("UPDATE licenses SET status = :status, updated_at = :updated_at WHERE id = :id");
                            $stmt_update->bindValue(':status', $new_status, SQLITE3_TEXT);
                            $stmt_update->bindValue(':id', $id, SQLITE3_INTEGER);
                            $stmt_update->bindValue(':updated_at', date('Y-m-d H:i:s'), SQLITE3_TEXT);
                            $stmt_update->execute();
                            log_activity($db, 'LICENSE_TOGGLE', "Toggled $domain_for_log to $new_status");
                            $_SESSION['success_message'] = '🔄 License status updated to ' . htmlspecialchars($new_status) . '.';
                        } else {
                            $_SESSION['error_message'] = 'Could not find license to toggle status.';
                        }
                    }
                    $db->exec('COMMIT'); // Commit transaction
                } catch (Exception $e) {
                    $db->exec('ROLLBACK'); // Rollback on error
                    error_log("Error performing action ($action) on license ID $id: " . $e->getMessage());
                    $_SESSION['error_message'] = 'An error occurred while performing the action.';
                }
            }
             // Redirect back to the same page with existing query parameters to prevent re-submission
             header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
             exit;
        }

        // --- Fetch Data for Display ---
        $search = trim($_GET['search'] ?? '');
        $filter_expiring = isset($_GET['filter_expiring']) && $_GET['filter_expiring'] == '1'; // Check for expiring filter
        $search_like = '%' . $search . '%';
        $items_per_page = 15;
        $current_page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['default' => 1, 'min_range' => 1]]);
        $offset = ($current_page - 1) * $items_per_page;

        // Build WHERE clause
        $where_clauses = [];
        $params = [];
        if ($search) {
            // MODIFIED: Search in primary and secondary domains, client name, email
            $where_clauses[] = "(domain LIKE :s OR secondary_domain LIKE :s OR client_name LIKE :s OR email LIKE :s)";
            $params[':s'] = $search_like;
        }
        if ($filter_expiring) {
             $thirty_days_from_now = date('Y-m-d H:i:s', strtotime('+30 days'));
             $where_clauses[] = "(status = 'active' AND license_type != 'Lifetime' AND expires_at IS NOT NULL AND expires_at <= :thirty_days)";
             $params[':thirty_days'] = $thirty_days_from_now;
        }
        $where_sql = !empty($where_clauses) ? ' WHERE ' . implode(' AND ', $where_clauses) : '';

        // Count total items matching filters
        $count_sql = "SELECT COUNT(*) as total FROM licenses" . $where_sql;
        $count_stmt = $db->prepare($count_sql);
        foreach ($params as $key => $val) { $count_stmt->bindValue($key, $val, SQLITE3_TEXT); }
        $total_items = $count_stmt->execute()->fetchArray(SQLITE3_ASSOC)['total'] ?? 0;
        $total_pages = ceil($total_items / $items_per_page);

        // Adjust current page if out of bounds
        if ($current_page > $total_pages && $total_pages > 0) {
            $current_page = $total_pages; $offset = ($current_page - 1) * $items_per_page;
        } elseif ($current_page < 1) {
            $current_page = 1; $offset = 0;
        }

        // Fetch data for the current page
        $data_sql = "SELECT * FROM licenses" . $where_sql . " ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
        $stmt = $db->prepare($data_sql);
        foreach ($params as $key => $val) {
             $stmt->bindValue($key, $val, SQLITE3_TEXT);
        }
        $stmt->bindValue(':limit', $items_per_page, SQLITE3_INTEGER);
        $stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
        $result = $stmt->execute();

        $page_title = 'View Licenses';
        include 'header.php';
        ?>

        <!-- Search and Filter Form -->
        <form method="GET" action="view-licenses.php" class="search-container">
            <input type="text" class="search-input" name="search" placeholder="Search by domain, name, or email..." value="<?php echo htmlspecialchars($search); ?>">
             <input type="hidden" name="filter_expiring" value="<?php echo $filter_expiring ? '1' : '0'; ?>">
            <button type="submit" class="btn btn-search">🔍 Search</button>
             <a href="view-licenses.php?filter_expiring=1" class="btn btn-warning <?php echo $filter_expiring ? 'active' : ''; ?>" title="Show licenses expiring in 30 days">⚠️ Expiring Soon</a>
             <?php if ($search || $filter_expiring): ?>
                <a href="view-licenses.php" class="btn btn-secondary">Clear Filters</a>
             <?php endif; ?>
        </form>

        <div class="table-responsive">
            <table class="sortable-table">
                <thead>
                    <tr>
                        <th class="sortable">🌐 Primary Domain</th>
                        <th class="sortable">🔗 Secondary Domain</th>
                        <th class="sortable">👤 Name</th>
                        <th class="sortable">📧 Email</th>
                        <th>🔑 Key</th>
                        <th>🧪 Token</th>
                        <th class="sortable">🎫 Type</th>
                        <th class="sortable">🌍 Limit</th> <!-- Added Site Limit Header -->
                        <th class="sortable">📅 Expiry</th>
                        <th class="sortable">⚙️ Status</th>
                        <th class="sortable">Created</th>
                        <th class="sortable">Updated</th>
                        <th>🛠️ Actions</th>
                        <th>📡 Test / Copy</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $row_count = 0; ?>
                    <?php while ($row = $result->fetchArray(SQLITE3_ASSOC)) : ?>
                        <?php
                        $row_count++;
                        $domain_esc = htmlspecialchars($row['domain']);
                        $secondary_domain_esc = htmlspecialchars($row['secondary_domain'] ?? '-');
                        $client_name_esc = htmlspecialchars($row['client_name']);
                        $site_limit_esc = htmlspecialchars($row['site_limit'] ?? 'Single'); // Display site limit
                        // Confirmation messages for JS confirms
                        $confirm_toggle_msg = "Are you sure you want to toggle the status for client '$client_name_esc' ($domain_esc)?";
                        $confirm_delete_msg = "Are you sure you want to DELETE the license for client '$client_name_esc' ($domain_esc)? This action cannot be undone.";
                        ?>
                        <tr>
                            <td><?php echo $domain_esc; ?></td>
                            <td><?php echo $secondary_domain_esc; ?></td>
                            <td><?php echo $client_name_esc; ?></td>
                            <td><?php echo htmlspecialchars($row['email'] ?: '-'); ?></td>
                            <td class="code"><?php echo htmlspecialchars($row['license_key']); ?></td>
                            <td class="code"><?php echo htmlspecialchars($row['token']); ?></td>
                            <td><?php echo htmlspecialchars($row['license_type'] ?? 'N/A'); ?></td>
                            <td><?php echo $site_limit_esc; ?></td> <!-- Added Site Limit Data -->
                            <td><?php echo htmlspecialchars($row['expires_at'] ? date('Y-m-d', strtotime($row['expires_at'])) : ($row['license_type'] === 'Lifetime' ? 'Lifetime' : 'N/A')); ?></td>
                            <td><span class="status-<?php echo htmlspecialchars($row['status']); ?>"><?php echo htmlspecialchars(ucfirst($row['status'])); ?></span></td>
                             <td><?php echo htmlspecialchars($row['created_at'] ? date('Y-m-d H:i', strtotime($row['created_at'])) : '-'); ?></td>
                             <td><?php echo htmlspecialchars($row['updated_at'] ? date('Y-m-d H:i', strtotime($row['updated_at'])) : '-'); ?></td>
                            <td class="actions">
                                <!-- Edit Button -->
                                <a href="add-license.php?edit_domain=<?php echo urlencode($row['domain']); ?>" class="btn btn-edit btn-sm" title="Edit">✏️</a>
                                <!-- Toggle Status Form -->
                                <form method="POST" action="<?php echo $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET); ?>" style="display: inline;" onsubmit="return confirm('<?php echo addslashes($confirm_toggle_msg); ?>');">
                                    <?php csrf_input_field(); ?>
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" class="btn btn-toggle btn-sm" title="Toggle Status">🔄</button>
                                </form>
                                <!-- Delete Form -->
                                <form method="POST" action="<?php echo $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET); ?>" style="display: inline;" onsubmit="return confirm('<?php echo addslashes($confirm_delete_msg); ?>');">
                                    <?php csrf_input_field(); ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo $row['id']; ?>">
                                    <button type="submit" class="btn btn-delete btn-sm" title="Delete">🗑️</button>
                                </form>
                            </td>
                            <td class="actions test-action">
                                <!-- Test Button (Tests primary domain by default) -->
                                <button type="button" class="btn btn-test btn-sm"
                                        data-domain="<?php echo htmlspecialchars($row['domain']); ?>"
                                        data-key="<?php echo htmlspecialchars($row['license_key']); ?>"
                                        data-token="<?php echo htmlspecialchars($row['token']); ?>"
                                        title="Test Primary Domain (<?php echo $domain_esc; ?>)">🧪 P</button>
                                <!-- Test Button for Secondary Domain (if exists and site limit is Single) -->
                                <?php if (!empty($row['secondary_domain']) && $site_limit_esc === 'Single'): ?>
                                <button type="button" class="btn btn-test btn-sm"
                                        data-domain="<?php echo htmlspecialchars($row['secondary_domain']); ?>"
                                        data-key="<?php echo htmlspecialchars($row['license_key']); ?>"
                                        data-token="<?php echo htmlspecialchars($row['token']); ?>"
                                        title="Test Secondary Domain (<?php echo htmlspecialchars($row['secondary_domain']); ?>)">🧪 S</button>
                                <?php endif; ?>
                                <!-- Copy Button (initially hidden) -->
                                <button type="button" class="btn btn-copy btn-sm" title="Copy Raw JSON Response" style="display: none;">📋 Copy</button>
                                <!-- Result Display Area -->
                                <div class="test-result-display"></div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                    <?php if ($row_count === 0): ?>
                        <tr>
                            <td colspan="14" style="text-align: center; padding: 20px;">No licenses found<?php echo ($search || $filter_expiring) ? ' matching your criteria' : ''; ?>.</td> <!-- Increased colspan -->
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php
        // Generate pagination links, passing current search/filter state
        $pagination_params = [];
        if ($search) $pagination_params['search'] = $search;
        if ($filter_expiring) $pagination_params['filter_expiring'] = '1';
        echo generate_pagination_links('view-licenses.php', $current_page, $total_pages, http_build_query($pagination_params));
        ?>

        <?php include 'footer.php'; ?>
