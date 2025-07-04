<?php
        require_once 'auth.php'; // Ensure only logged-in admins can download

        require_login();

        $filename = "license_import_template.csv";
        // Added 'site_limit' to headers
        $headers = ['domain', 'secondary_domain', 'client_name', 'license_key', 'token', 'email', 'status', 'expires_at', 'license_type', 'site_limit'];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $output = fopen('php://output', 'w');

        // Write the header row
        fputcsv($output, $headers);

        // Optional: Add an example row (commented out by default)
        /*
        $example_data = [
            'example.com',          // domain
            'staging.example.com',  // secondary_domain (optional)
            'Example Client Inc.',  // client_name
            'YOUR_KEY_HERE',        // license_key (or leave blank if generating new)
            'YOUR_TOKEN_HERE',      // token (or leave blank if generating new)
            'contact@example.com',  // email
            'active',               // status
            '2025-12-31',           // expires_at (YYYY-MM-DD or blank for Lifetime)
            'Yearly',               // license_type (Trial, Monthly, Yearly, Lifetime)
            'Single'                // site_limit (Single or Unlimited)
        ];
        fputcsv($output, $example_data);
        */

        fclose($output);
        exit;
        ?>
