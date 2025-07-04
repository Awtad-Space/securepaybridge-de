const sqlite3 = require('sqlite3').verbose();
        const path = require('path');

        const dbPath = path.join(__dirname, 'auth', 'license_manager.db');
        const db = new sqlite3.Database(dbPath, (err) => {
            if (err) {
                console.error('Error opening database:', err.message);
                process.exitCode = 1;
                return;
            }
            console.log('Connected to the SQLite database.');
        });

        // Function to execute SQL commands sequentially
        const runSqlCommands = (commands, callback) => {
            db.serialize(() => {
                commands.forEach((cmd, index) => {
                    db.exec(cmd, (err) => {
                        if (err) {
                            // Ignore "duplicate column name" errors as they mean the column already exists
                            if (err.message.includes('duplicate column name')) {
                                console.log(`- Column likely already exists (ignored error): ${err.message}`);
                            } else {
                                console.error(`Error executing command ${index + 1}: ${cmd}`, err.message);
                                db.close();
                                process.exitCode = 1;
                                callback(err); // Pass error to callback
                                return; // Stop execution on other errors
                            }
                        } else {
                            console.log(`- Successfully executed: ${cmd.split('\n')[0]}...`); // Log first line of command
                        }
                        // If this is the last command, call the final callback
                        if (index === commands.length - 1) {
                            callback(null); // Success
                        }
                    });
                });
            });
        };

        // --- SQL Commands ---
        const sqlCommands = [
            // Admins Table
            `CREATE TABLE IF NOT EXISTS admins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                password_hash TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )`,
            // Licenses Table (Initial creation with all columns)
            `CREATE TABLE IF NOT EXISTS licenses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                domain TEXT UNIQUE NOT NULL,
                secondary_domain TEXT,
                client_name TEXT,
                license_key TEXT UNIQUE NOT NULL,
                token TEXT UNIQUE NOT NULL,
                email TEXT,
                status TEXT DEFAULT 'inactive',
                expires_at DATETIME,
                license_type TEXT DEFAULT 'Trial',
                site_limit TEXT DEFAULT 'Single',
                server_name TEXT,
                ip_address TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME
            )`,
            // Add columns to licenses if they don't exist (using ALTER TABLE for older setups)
            // Note: sqlite3 doesn't directly support "ADD COLUMN IF NOT EXISTS" in older versions handled by exec.
            // We rely on the error handling above to ignore "duplicate column" errors.
            `ALTER TABLE licenses ADD COLUMN secondary_domain TEXT`,
            `ALTER TABLE licenses ADD COLUMN license_type TEXT DEFAULT 'Trial'`,
            `ALTER TABLE licenses ADD COLUMN site_limit TEXT DEFAULT 'Single'`,
            `ALTER TABLE licenses ADD COLUMN server_name TEXT`,
            `ALTER TABLE licenses ADD COLUMN ip_address TEXT`,
            `ALTER TABLE licenses ADD COLUMN updated_at DATETIME`,
            // Activity Log Table
            `CREATE TABLE IF NOT EXISTS activity_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                admin_username TEXT,
                action_type TEXT NOT NULL,
                details TEXT,
                ip_address TEXT
            )`,
            // Settings Table
            `CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY NOT NULL,
                value TEXT
            )`,
            // Insert Default Settings (using INSERT OR IGNORE)
            `INSERT OR IGNORE INTO settings (key, value) VALUES ('rate_limit_timeframe', '60')`,
            `INSERT OR IGNORE INTO settings (key, value) VALUES ('rate_limit_max_requests', '10')`,
            `INSERT OR IGNORE INTO settings (key, value) VALUES ('default_license_type', 'Trial')`
        ];

        console.log('Starting database initialization/update using Node.js...');

        runSqlCommands(sqlCommands, (err) => {
            if (err) {
                console.error('Database initialization failed.');
            } else {
                console.log('\nDatabase initialization/update complete.');
            }

            // Close the database connection
            db.close((closeErr) => {
                if (closeErr) {
                    console.error('Error closing database:', closeErr.message);
                    if (!process.exitCode) process.exitCode = 1; // Ensure exit code reflects error
                } else {
                    console.log('Database connection closed.');
                }
            });
        });
