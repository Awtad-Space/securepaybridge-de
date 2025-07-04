<?php
        require_once 'auth.php';
        require_once 'db.php';
        require_once 'utils.php';

        require_login();

        try {
            // Select all columns, including the new site_limit
            $stmt = $db->query("SELECT * FROM licenses ORDER BY domain ASC");
            $filename = "licenses_export_" . date('Y-m-d_H-i-s') . ".csv";

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');

            $output = fopen('php://output', 'w');
            $header_printed = false;

            while ($row = $stmt->fetchArray(SQLITE3_ASSOC)) {
                if (!$header_printed) {
                    fputcsv($output, array_keys($row)); // Headers will now include site_limit
                    $header_printed = true;
                }
                fputcsv($output, $row);
            }

            if (!$header_printed) {
                 // Fetch headers dynamically if table was empty
                 $cols_stmt = $db->query("PRAGMA table_info(licenses)");
                 $headers = [];
                 while($col = $cols_stmt->fetchArray(SQLITE3_ASSOC)) { $headers[] = $col['name']; }
                 if (!empty($headers)) { fputcsv($output, $headers); }
                 else { fputcsv($output, ['Error retrieving headers']); }
            }

            fclose($output);
            exit;

        } catch (Exception $e) {
            error_log("Export Error: " . $e->getMessage());
            $_SESSION['error_message'] = "Error exporting data: " . $e->getMessage();
            header('Location: settings.php'); // Redirect to settings on error
            exit;
        }
        ?>
