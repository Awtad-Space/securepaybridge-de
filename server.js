const express = require('express');
const sqlite3 = require('sqlite3').verbose();
const bcrypt = require('bcrypt');
const session = require('express-session');
const SQLiteStore = require('connect-sqlite3')(session);
const crypto = require('crypto');
const multer = require('multer');
const path = require('path');
const fs = require('fs');

const app = express();
const PORT = process.env.PORT || 3000;

// Database setup
const db = new sqlite3.Database('./license_manager.db');

// Middleware
app.use(express.static('public'));
app.use(express.urlencoded({ extended: true }));
app.use(express.json());

// Session configuration
app.use(session({
    store: new SQLiteStore({ db: 'sessions.db' }),
    secret: process.env.SESSION_SECRET || 'your-secret-key-change-this',
    resave: false,
    saveUninitialized: false,
    cookie: {
        secure: false, // Set to true in production with HTTPS
        httpOnly: true,
        maxAge: 24 * 60 * 60 * 1000 // 24 hours
    }
}));

// View engine setup
app.set('view engine', 'ejs');
app.set('views', './views');

// Helper functions
function generateSecureKey(length = 32) {
    return crypto.randomBytes(length).toString('hex');
}

function generateCSRFToken() {
    return crypto.randomBytes(32).toString('hex');
}

function requireLogin(req, res, next) {
    if (!req.session.admin_id) {
        return res.redirect('/login');
    }
    next();
}

// Initialize database
function initializeDatabase() {
    const queries = [
        `CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )`,
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
        `CREATE TABLE IF NOT EXISTS activity_log (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
            admin_username TEXT,
            action_type TEXT NOT NULL,
            details TEXT,
            ip_address TEXT
        )`,
        `CREATE TABLE IF NOT EXISTS settings (
            key TEXT PRIMARY KEY NOT NULL,
            value TEXT
        )`
    ];

    queries.forEach(query => {
        db.run(query, (err) => {
            if (err) console.error('Database initialization error:', err);
        });
    });

    // Insert default settings
    const defaultSettings = [
        ['rate_limit_timeframe', '60'],
        ['rate_limit_max_requests', '10'],
        ['default_license_type', 'Trial']
    ];

    defaultSettings.forEach(([key, value]) => {
        db.run('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)', [key, value]);
    });
}

// Routes
app.get('/', (req, res) => {
    if (req.session.admin_id) {
        return res.redirect('/dashboard');
    }
    res.redirect('/login');
});

app.get('/login', (req, res) => {
    if (req.session.admin_id) {
        return res.redirect('/dashboard');
    }
    res.render('login', { error: null, csrfToken: generateCSRFToken() });
});

app.post('/login', (req, res) => {
    const { username, password } = req.body;
    
    db.get('SELECT * FROM admins WHERE username = ?', [username], async (err, admin) => {
        if (err || !admin) {
            return res.render('login', { error: 'Invalid credentials', csrfToken: generateCSRFToken() });
        }

        const validPassword = await bcrypt.compare(password, admin.password_hash);
        if (!validPassword) {
            return res.render('login', { error: 'Invalid credentials', csrfToken: generateCSRFToken() });
        }

        req.session.admin_id = admin.id;
        req.session.admin_username = admin.username;
        res.redirect('/dashboard');
    });
});

app.get('/logout', (req, res) => {
    req.session.destroy();
    res.redirect('/login');
});

app.get('/dashboard', requireLogin, (req, res) => {
    // Get dashboard statistics
    const stats = {};
    
    db.get('SELECT COUNT(*) as total FROM licenses', (err, result) => {
        stats.total_licenses = result ? result.total : 0;
        
        db.get('SELECT COUNT(*) as active FROM licenses WHERE status = "active"', (err, result) => {
            stats.active_licenses = result ? result.active : 0;
            stats.inactive_licenses = stats.total_licenses - stats.active_licenses;
            
            res.render('dashboard', { 
                stats, 
                user: req.session.admin_username,
                csrfToken: generateCSRFToken()
            });
        });
    });
});

app.get('/licenses', requireLogin, (req, res) => {
    const search = req.query.search || '';
    const page = parseInt(req.query.page) || 1;
    const limit = 15;
    const offset = (page - 1) * limit;

    let query = 'SELECT * FROM licenses';
    let params = [];

    if (search) {
        query += ' WHERE domain LIKE ? OR client_name LIKE ? OR email LIKE ?';
        const searchTerm = `%${search}%`;
        params = [searchTerm, searchTerm, searchTerm];
    }

    query += ' ORDER BY created_at DESC LIMIT ? OFFSET ?';
    params.push(limit, offset);

    db.all(query, params, (err, licenses) => {
        if (err) {
            console.error('Error fetching licenses:', err);
            licenses = [];
        }

        // Get total count for pagination
        let countQuery = 'SELECT COUNT(*) as total FROM licenses';
        let countParams = [];

        if (search) {
            countQuery += ' WHERE domain LIKE ? OR client_name LIKE ? OR email LIKE ?';
            const searchTerm = `%${search}%`;
            countParams = [searchTerm, searchTerm, searchTerm];
        }

        db.get(countQuery, countParams, (err, countResult) => {
            const totalItems = countResult ? countResult.total : 0;
            const totalPages = Math.ceil(totalItems / limit);

            res.render('licenses', {
                licenses,
                search,
                currentPage: page,
                totalPages,
                csrfToken: generateCSRFToken()
            });
        });
    });
});

app.get('/add-license', requireLogin, (req, res) => {
    res.render('add-license', { 
        license: null, 
        editMode: false,
        csrfToken: generateCSRFToken()
    });
});

app.post('/add-license', requireLogin, (req, res) => {
    const {
        domain,
        secondary_domain,
        client_name,
        email,
        status,
        license_type,
        site_limit,
        expires_at
    } = req.body;

    const license_key = generateSecureKey(16);
    const token = generateSecureKey(24);
    const current_time = new Date().toISOString();

    const query = `INSERT INTO licenses 
        (domain, secondary_domain, client_name, license_key, token, email, status, 
         expires_at, license_type, site_limit, server_name, ip_address, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`;

    const params = [
        domain,
        secondary_domain || null,
        client_name,
        license_key,
        token,
        email || null,
        status,
        expires_at || null,
        license_type,
        site_limit,
        req.get('host'),
        req.ip,
        current_time,
        current_time
    ];

    db.run(query, params, function(err) {
        if (err) {
            console.error('Error adding license:', err);
            return res.render('add-license', {
                license: req.body,
                editMode: false,
                error: 'Error adding license',
                csrfToken: generateCSRFToken()
            });
        }

        res.redirect('/licenses');
    });
});

// License check API endpoint
app.post('/license-check', (req, res) => {
    const { domain, key, token } = req.body;

    if (!domain || !key || !token) {
        return res.json({
            status: 'error',
            message: 'Missing required parameters: domain, key, token'
        });
    }

    const query = `SELECT status, expires_at, license_type, site_limit, domain, secondary_domain
                   FROM licenses WHERE license_key = ? AND token = ?`;

    db.get(query, [key, token], (err, license) => {
        if (err || !license) {
            return res.json({
                status: 'invalid',
                message: 'License key or token mismatch'
            });
        }

        // Check domain matching for Single site licenses
        if (license.site_limit === 'Single') {
            const domainMatch = domain.toLowerCase() === license.domain.toLowerCase() ||
                              (license.secondary_domain && domain.toLowerCase() === license.secondary_domain.toLowerCase());
            
            if (!domainMatch) {
                return res.json({
                    status: 'invalid',
                    message: 'Domain mismatch for this license key'
                });
            }
        }

        // Check if license is active
        if (license.status !== 'active') {
            return res.json({
                status: 'inactive',
                message: 'License is not active',
                license_type: license.license_type,
                site_limit: license.site_limit
            });
        }

        // Check expiration
        if (license.license_type !== 'Lifetime' && license.expires_at) {
            const today = new Date().toISOString().split('T')[0];
            if (today > license.expires_at) {
                return res.json({
                    status: 'expired',
                    message: 'License has expired',
                    license_type: license.license_type,
                    site_limit: license.site_limit,
                    expires_at: license.expires_at
                });
            }
        }

        // License is valid
        res.json({
            status: 'valid',
            message: 'License is valid and active',
            license_type: license.license_type,
            site_limit: license.site_limit,
            expires_at: license.expires_at || (license.license_type === 'Lifetime' ? 'Lifetime' : null)
        });
    });
});

// Initialize database and start server
initializeDatabase();

app.listen(PORT, () => {
    console.log(`License Manager server running on port ${PORT}`);
});