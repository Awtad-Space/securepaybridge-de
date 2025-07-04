<?php
        require_once 'auth.php';
        require_once 'db.php';
        require_once 'utils.php';

        require_login();

        $page_title = 'Dashboard';

        // --- Data Fetching ---
        $stats = [
            'total_licenses' => 0, 'active_licenses' => 0, 'inactive_licenses' => 0,
            'expiring_soon_count' => 0, 'lifetime_licenses' => 0,
        ];
        $expiring_licenses = [];
        $activity_log = [];
        $license_type_counts = [];
        $monthly_additions = []; // Data for the last 12 months

        try {
            // Basic Stats
            $stats['total_licenses'] = $db->querySingle("SELECT COUNT(*) FROM licenses") ?: 0;
            $stats['active_licenses'] = $db->querySingle("SELECT COUNT(*) FROM licenses WHERE status = 'active'") ?: 0;
            $stats['inactive_licenses'] = $stats['total_licenses'] - $stats['active_licenses'];
            $stats['lifetime_licenses'] = $db->querySingle("SELECT COUNT(*) FROM licenses WHERE license_type = 'Lifetime'") ?: 0;

            // Expiring Soon (Count and List)
            $thirty_days_from_now = date('Y-m-d H:i:s', strtotime('+30 days'));
            $expiring_sql = "SELECT domain, client_name, expires_at FROM licenses
                             WHERE status = 'active' AND license_type != 'Lifetime' AND expires_at IS NOT NULL AND expires_at <= :thirty_days
                             ORDER BY expires_at ASC LIMIT 5"; // Limit list size for dashboard
            $expiring_stmt = $db->prepare($expiring_sql);
            $expiring_stmt->bindValue(':thirty_days', $thirty_days_from_now, SQLITE3_TEXT);
            $expiring_result = $expiring_stmt->execute();
            while ($row = $expiring_result->fetchArray(SQLITE3_ASSOC)) {
                $expiring_licenses[] = $row;
            }
            // Get the total count separately if needed (more efficient than counting the limited result)
            $expiring_count_sql = "SELECT COUNT(*) FROM licenses WHERE status = 'active' AND license_type != 'Lifetime' AND expires_at IS NOT NULL AND expires_at <= :thirty_days";
            $expiring_count_stmt = $db->prepare($expiring_count_sql);
            $expiring_count_stmt->bindValue(':thirty_days', $thirty_days_from_now, SQLITE3_TEXT);
            $stats['expiring_soon_count'] = $expiring_count_stmt->execute()->fetchArray(SQLITE3_ASSOC)['COUNT(*)'] ?? 0;


            // Activity Log (Last 5 entries)
            $activity_sql = "SELECT timestamp, admin_username, action_type, details FROM activity_log ORDER BY timestamp DESC LIMIT 5";
            $activity_result = $db->query($activity_sql);
            while ($row = $activity_result->fetchArray(SQLITE3_ASSOC)) {
                $activity_log[] = $row;
            }

            // License Type Counts (for Pie Chart)
            $type_sql = "SELECT license_type, COUNT(*) as count FROM licenses GROUP BY license_type";
            $type_result = $db->query($type_sql);
            while ($row = $type_result->fetchArray(SQLITE3_ASSOC)) {
                $license_type_counts[$row['license_type']] = $row['count'];
            }

            // Monthly Additions (for Bar Chart - Last 12 Months)
            $monthly_sql = "SELECT strftime('%Y-%m', created_at) as month, COUNT(*) as count
                            FROM licenses
                            WHERE created_at >= date('now', '-12 months')
                            GROUP BY month
                            ORDER BY month ASC";
            $monthly_result = $db->query($monthly_sql);
            $temp_monthly = [];
             while ($row = $monthly_result->fetchArray(SQLITE3_ASSOC)) {
                $temp_monthly[$row['month']] = $row['count'];
            }
            // Ensure all last 12 months are present, even if count is 0
            for ($i = 11; $i >= 0; $i--) {
                $month_key = date('Y-m', strtotime("-$i months"));
                $monthly_additions[$month_key] = $temp_monthly[$month_key] ?? 0;
            }


        } catch (Exception $e) {
            error_log("Dashboard DB Error: " . $e->getMessage());
            $_SESSION['error_message'] = "Error loading dashboard data. Check logs.";
        }

        // Prepare data for Chart.js
        $chart_license_types_labels = json_encode(array_keys($license_type_counts));
        $chart_license_types_data = json_encode(array_values($license_type_counts));
        $chart_monthly_labels = json_encode(array_keys($monthly_additions));
        $chart_monthly_data = json_encode(array_values($monthly_additions));


        include 'header.php'; // Header now includes DB status check
        ?>

        <h2>Welcome, <?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?>!</h2>
        <p>Here's a quick overview of your license system.</p>

        <!-- Quick Search Form -->
        <div class="dashboard-section">
             <form method="GET" action="view-licenses.php" class="search-container dashboard-search">
                <input type="text" class="search-input" name="search" placeholder="Quick search license (domain, name, email)...">
                <button type="submit" class="btn btn-search">🔍 Search</button>
            </form>
        </div>


        <!-- Stats Grid -->
        <div class="dashboard-grid">
            <div class="dashboard-card">
                <h3>Total Licenses</h3>
                <span class="count"><?php echo $stats['total_licenses']; ?></span>
                <span class="description">All registered licenses</span>
            </div>
            <div class="dashboard-card">
                <h3>Active Licenses</h3>
                <span class="count"><?php echo $stats['active_licenses']; ?></span>
                <span class="description">Currently active licenses</span>
            </div>
             <div class="dashboard-card">
                <h3>Inactive Licenses</h3>
                <span class="count"><?php echo $stats['inactive_licenses']; ?></span>
                <span class="description">Manually disabled licenses</span>
            </div>
             <div class="dashboard-card">
                <h3>Lifetime Licenses</h3>
                <span class="count"><?php echo $stats['lifetime_licenses']; ?></span>
                <span class="description">Licenses with no expiration</span>
            </div>
            <div class="dashboard-card">
                <h3>Expiring Soon</h3>
                <span class="count"><?php echo $stats['expiring_soon_count']; ?></span>
                <span class="description">Active licenses expiring in next 30 days</span>
                 <a href="#expiring-soon-list" class="card-link">View List</a>
            </div>
            <div class="dashboard-card quick-actions">
                <h3>Quick Actions</h3>
                 <a href="add-license.php" class="btn btn-primary">➕ Add New License</a>
                 <a href="view-licenses.php" class="btn btn-secondary">📄 View All Licenses</a>
                 <a href="export-licenses.php" class="btn btn-secondary">📤 Export Licenses</a>
                 <a href="settings.php#import-section" class="btn btn-secondary">📥 Import Licenses</a>
            </div>
        </div>

        <!-- Charts Row -->
        <div class="dashboard-grid chart-grid">
             <div class="dashboard-card chart-card">
                <h3>License Types Distribution</h3>
                <div class="chart-container">
                    <canvas id="licenseTypeChart"></canvas>
                </div>
            </div>
             <div class="dashboard-card chart-card">
                <h3>Monthly License Additions (Last 12 Months)</h3>
                 <div class="chart-container">
                    <canvas id="monthlyAdditionsChart"></canvas>
                 </div>
            </div>
        </div>


        <!-- Expiring Soon & Activity Log Row -->
        <div class="dashboard-grid list-grid">
            <div id="expiring-soon-list" class="dashboard-card list-card">
                <h3>Expiring Soon (Next 30 Days)</h3>
                <?php if (!empty($expiring_licenses)): ?>
                    <div class="table-responsive-mini">
                        <table>
                            <thead>
                                <tr><th>Domain</th><th>Client</th><th>Expires</th><th>Action</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($expiring_licenses as $license): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($license['domain']); ?></td>
                                    <td><?php echo htmlspecialchars($license['client_name']); ?></td>
                                    <td><?php echo date('Y-m-d', strtotime($license['expires_at'])); ?></td>
                                    <td><a href="add-license.php?edit_domain=<?php echo urlencode($license['domain']); ?>" class="btn btn-edit btn-sm" title="Edit">✏️</a></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                     <?php if ($stats['expiring_soon_count'] > count($expiring_licenses)): ?>
                         <p style="text-align: center; margin-top: 10px;"><a href="view-licenses.php?search=&filter_expiring=1">View all <?php echo $stats['expiring_soon_count']; ?> expiring licenses...</a></p>
                     <?php endif; ?>
                <?php else: ?>
                    <p>No licenses expiring in the next 30 days.</p>
                <?php endif; ?>
            </div>

            <div class="dashboard-card list-card">
                <h3>Recent Activity</h3>
                <?php if (!empty($activity_log)): ?>
                    <ul class="activity-list">
                        <?php foreach ($activity_log as $log): ?>
                            <li>
                                <span class="timestamp"><?php echo date('M d, H:i', strtotime($log['timestamp'])); ?></span>
                                <span class="user"><?php echo htmlspecialchars($log['admin_username']); ?></span>
                                <span class="action"><?php echo htmlspecialchars(ucwords(strtolower(str_replace('_', ' ', $log['action_type'])))); ?></span>
                                <?php if ($log['details']): ?>
                                <span class="details" title="<?php echo htmlspecialchars($log['details']); ?>">(<?php echo htmlspecialchars(substr($log['details'], 0, 50)) . (strlen($log['details']) > 50 ? '...' : ''); ?>)</span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p>No recent activity recorded.</p>
                <?php endif; ?>
            </div>
        </div>


        <?php include 'footer.php'; ?>

        <!-- Chart Initialization Script -->
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // License Type Pie Chart
            const licenseTypeCtx = document.getElementById('licenseTypeChart');
            if (licenseTypeCtx) {
                new Chart(licenseTypeCtx, {
                    type: 'pie',
                    data: {
                        labels: <?php echo $chart_license_types_labels; ?>,
                        datasets: [{
                            label: 'License Count',
                            data: <?php echo $chart_license_types_data; ?>,
                            backgroundColor: [ // Add more colors if needed
                                'rgba(79, 50, 230, 0.7)', // Primary
                                'rgba(54, 162, 235, 0.7)', // Blue
                                'rgba(255, 206, 86, 0.7)', // Yellow
                                'rgba(75, 192, 192, 0.7)', // Green
                                'rgba(153, 102, 255, 0.7)', // Purple
                                'rgba(255, 159, 64, 0.7)' // Orange
                            ],
                            borderColor: [
                                'rgba(79, 50, 230, 1)',
                                'rgba(54, 162, 235, 1)',
                                'rgba(255, 206, 86, 1)',
                                'rgba(75, 192, 192, 1)',
                                'rgba(153, 102, 255, 1)',
                                'rgba(255, 159, 64, 1)'
                            ],
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                            }
                        }
                    }
                });
            }

            // Monthly Additions Bar Chart
            const monthlyAdditionsCtx = document.getElementById('monthlyAdditionsChart');
            if (monthlyAdditionsCtx) {
                new Chart(monthlyAdditionsCtx, {
                    type: 'bar',
                    data: {
                        labels: <?php echo $chart_monthly_labels; ?>,
                        datasets: [{
                            label: 'Licenses Added',
                            data: <?php echo $chart_monthly_data; ?>,
                            backgroundColor: 'rgba(75, 192, 192, 0.6)',
                            borderColor: 'rgba(75, 192, 192, 1)',
                            borderWidth: 1
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        scales: {
                            y: {
                                beginAtZero: true,
                                ticks: {
                                    stepSize: 1 // Ensure integer steps for counts
                                }
                            }
                        },
                        plugins: {
                            legend: {
                                display: false // Hide legend for single dataset bar chart
                            }
                        }
                    }
                });
            }
        });
        </script>
