const express = require('express');
const sqlite3 = require('sqlite3').verbose();
const bodyParser = require('body-parser');
const session = require('express-session');
const bcrypt = require('bcryptjs');
const multer = require('multer');
const rateLimit = require('express-rate-limit');
const path = require('path');
const fs = require('fs');

const app = express();
const PORT = process.env.PORT || 3000;

// Database setup
const db = new sqlite3.Database('./license_manager.db');

// Middleware
app.use(bodyParser.urlencoded({ extended: true }));
app.use(bodyParser.json());
app.use(express.static('public'));
app.use(session({
    secret: 'your-secret-key',
    resave: false,
    saveUninitialized: true,
    cookie: { secure: false }
}));

// Rate limiting
const limiter = rateLimit({
    windowMs: 15 * 60 * 1000, // 15 minutes
    max: 100 // limit each IP to 100 requests per windowMs
});
app.use(limiter);

// File upload setup
const upload = multer({ dest: 'uploads/' });

// Initialize database
function initDatabase() {
    db.serialize(() => {
        // Create users table
        db.run(`CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            role TEXT DEFAULT 'user',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )`);

        // Create licenses table
        db.run(`CREATE TABLE IF NOT EXISTS licenses (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            license_key TEXT UNIQUE NOT NULL,
            product_name TEXT NOT NULL,
            customer_email TEXT NOT NULL,
            status TEXT DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME,
            max_activations INTEGER DEFAULT 1,
            current_activations INTEGER DEFAULT 0
        )`);

        // Create settings table
        db.run(`CREATE TABLE IF NOT EXISTS settings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            setting_key TEXT UNIQUE NOT NULL,
            setting_value TEXT NOT NULL
        )`);

        // Create default admin user if not exists
        db.get("SELECT * FROM users WHERE username = 'admin'", (err, row) => {
            if (err) {
                console.error('Error checking for admin user:', err);
                return;
            }
            if (!row) {
                const hashedPassword = bcrypt.hashSync('admin123', 10);
                db.run("INSERT INTO users (username, password, role) VALUES (?, ?, ?)", 
                    ['admin', hashedPassword, 'admin'], function(err) {
                        if (err) {
                            console.error('Error creating admin user:', err);
                        } else {
                            console.log('Default admin user created: admin/admin123');
                        }
                    });
            } else {
                console.log('Admin user already exists');
            }
        });
    });
}

// Authentication middleware
function requireAuth(req, res, next) {
    if (req.session.userId) {
        next();
    } else {
        res.redirect('/login');
    }
}

function requireAdmin(req, res, next) {
    if (req.session.userId && req.session.userRole === 'admin') {
        next();
    } else {
        res.status(403).send('Access denied');
    }
}

// Routes
app.get('/', (req, res) => {
    if (req.session.userId) {
        res.redirect('/dashboard');
    } else {
        res.redirect('/login');
    }
});

app.get('/login', (req, res) => {
    res.send(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>License Manager - Login</title>
            <link rel="stylesheet" href="/style.css">
        </head>
        <body>
            <div class="container">
                <h2>Login</h2>
                <form method="POST" action="/login">
                    <div class="form-group">
                        <label>Username:</label>
                        <input type="text" name="username" required>
                    </div>
                    <div class="form-group">
                        <label>Password:</label>
                        <input type="password" name="password" required>
                    </div>
                    <button type="submit">Login</button>
                </form>
            </div>
        </body>
        </html>
    `);
});

app.post('/login', (req, res) => {
    const { username, password } = req.body;
    
    console.log('Login attempt:', { username, password: '***' });
    
    db.get("SELECT * FROM users WHERE username = ?", [username], (err, user) => {
        if (err) {
            console.error('Database error during login:', err);
            res.status(500).send('Database error');
            return;
        }
        
        console.log('User found:', user ? 'Yes' : 'No');
        
        if (user) {
            console.log('Comparing passwords...');
            const passwordMatch = bcrypt.compareSync(password, user.password);
            console.log('Password match:', passwordMatch);
            
            if (passwordMatch) {
                req.session.userId = user.id;
                req.session.username = user.username;
                req.session.userRole = user.role;
                console.log('Login successful, redirecting to dashboard');
                res.redirect('/dashboard');
            } else {
                console.log('Password mismatch');
                res.send(`
                    <!DOCTYPE html>
                    <html>
                    <head>
                        <title>Login Failed</title>
                        <link rel="stylesheet" href="/style.css">
                    </head>
                    <body>
                        <div class="container">
                            <div class="alert alert-error">
                                Invalid credentials. Please check your username and password.
                            </div>
                            <a href="/login" class="btn">Try again</a>
                        </div>
                    </body>
                    </html>
                `);
            }
        } else {
            console.log('User not found');
            res.send(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>Login Failed</title>
                    <link rel="stylesheet" href="/style.css">
                </head>
                <body>
                    <div class="container">
                        <div class="alert alert-error">
                            User not found. Please check your username.
                        </div>
                        <a href="/login" class="btn">Try again</a>
                    </div>
                </body>
                </html>
            `);
        }
    });
});

app.get('/dashboard', requireAuth, (req, res) => {
    db.all("SELECT COUNT(*) as total FROM licenses", (err, totalResult) => {
        db.all("SELECT COUNT(*) as active FROM licenses WHERE status = 'active'", (err2, activeResult) => {
            const total = totalResult[0].total;
            const active = activeResult[0].active;
            
            res.send(`
                <!DOCTYPE html>
                <html>
                <head>
                    <title>License Manager - Dashboard</title>
                    <link rel="stylesheet" href="/style.css">
                </head>
                <body>
                    <div class="container">
                        <h1>License Manager Dashboard</h1>
                        <p>Welcome, ${req.session.username}!</p>
                        
                        <div class="stats">
                            <div class="stat-card">
                                <h3>Total Licenses</h3>
                                <p>${total}</p>
                            </div>
                            <div class="stat-card">
                                <h3>Active Licenses</h3>
                                <p>${active}</p>
                            </div>
                        </div>
                        
                        <div class="menu">
                            <a href="/licenses" class="btn">View Licenses</a>
                            ${req.session.userRole === 'admin' ? '<a href="/add-license" class="btn">Add License</a>' : ''}
                            <a href="/logout" class="btn">Logout</a>
                        </div>
                    </div>
                </body>
                </html>
            `);
        });
    });
});

app.get('/licenses', requireAuth, (req, res) => {
    db.all("SELECT * FROM licenses ORDER BY created_at DESC", (err, licenses) => {
        if (err) {
            res.status(500).send('Database error');
            return;
        }
        
        let licensesHtml = licenses.map(license => `
            <tr>
                <td>${license.license_key}</td>
                <td>${license.product_name}</td>
                <td>${license.customer_email}</td>
                <td>${license.status}</td>
                <td>${license.created_at}</td>
                <td>${license.expires_at || 'Never'}</td>
            </tr>
        `).join('');
        
        res.send(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>License Manager - Licenses</title>
                <link rel="stylesheet" href="/style.css">
            </head>
            <body>
                <div class="container">
                    <h1>Licenses</h1>
                    <a href="/dashboard" class="btn">Back to Dashboard</a>
                    
                    <table>
                        <thead>
                            <tr>
                                <th>License Key</th>
                                <th>Product</th>
                                <th>Customer Email</th>
                                <th>Status</th>
                                <th>Created</th>
                                <th>Expires</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${licensesHtml}
                        </tbody>
                    </table>
                </div>
            </body>
            </html>
        `);
    });
});

app.get('/add-license', requireAdmin, (req, res) => {
    res.send(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>License Manager - Add License</title>
            <link rel="stylesheet" href="/style.css">
        </head>
        <body>
            <div class="container">
                <h1>Add New License</h1>
                <form method="POST" action="/add-license">
                    <div class="form-group">
                        <label>License Key:</label>
                        <input type="text" name="license_key" required>
                    </div>
                    <div class="form-group">
                        <label>Product Name:</label>
                        <input type="text" name="product_name" required>
                    </div>
                    <div class="form-group">
                        <label>Customer Email:</label>
                        <input type="email" name="customer_email" required>
                    </div>
                    <div class="form-group">
                        <label>Max Activations:</label>
                        <input type="number" name="max_activations" value="1" min="1">
                    </div>
                    <div class="form-group">
                        <label>Expires At (optional):</label>
                        <input type="datetime-local" name="expires_at">
                    </div>
                    <button type="submit">Add License</button>
                    <a href="/dashboard" class="btn">Cancel</a>
                </form>
            </div>
        </body>
        </html>
    `);
});

app.post('/add-license', requireAdmin, (req, res) => {
    const { license_key, product_name, customer_email, max_activations, expires_at } = req.body;
    
    db.run("INSERT INTO licenses (license_key, product_name, customer_email, max_activations, expires_at) VALUES (?, ?, ?, ?, ?)",
        [license_key, product_name, customer_email, max_activations || 1, expires_at || null],
        function(err) {
            if (err) {
                res.status(500).send('Error adding license: ' + err.message);
                return;
            }
            res.redirect('/licenses');
        }
    );
});

app.get('/logout', (req, res) => {
    req.session.destroy();
    res.redirect('/login');
});

// API endpoint for license validation
app.post('/api/validate-license', (req, res) => {
    const { license_key, product_name } = req.body;
    
    if (!license_key || !product_name) {
        return res.json({ valid: false, message: 'Missing license key or product name' });
    }
    
    db.get("SELECT * FROM licenses WHERE license_key = ? AND product_name = ?", 
        [license_key, product_name], (err, license) => {
        if (err) {
            return res.json({ valid: false, message: 'Database error' });
        }
        
        if (!license) {
            return res.json({ valid: false, message: 'License not found' });
        }
        
        if (license.status !== 'active') {
            return res.json({ valid: false, message: 'License is not active' });
        }
        
        if (license.expires_at && new Date(license.expires_at) < new Date()) {
            return res.json({ valid: false, message: 'License has expired' });
        }
        
        if (license.current_activations >= license.max_activations) {
            return res.json({ valid: false, message: 'Maximum activations reached' });
        }
        
        res.json({ 
            valid: true, 
            message: 'License is valid',
            license: {
                product_name: license.product_name,
                expires_at: license.expires_at,
                max_activations: license.max_activations,
                current_activations: license.current_activations
            }
        });
    });
});

// Initialize database and start server
initDatabase();

// Add a route to reset admin password for debugging
app.get('/reset-admin', (req, res) => {
    const hashedPassword = bcrypt.hashSync('admin123', 10);
    db.run("UPDATE users SET password = ? WHERE username = 'admin'", [hashedPassword], function(err) {
        if (err) {
            res.send('Error resetting password: ' + err.message);
        } else {
            res.send('Admin password reset to: admin123');
        }
    });
});

app.listen(PORT, () => {
    console.log(`License Manager running on port ${PORT}`);
    console.log(`Access the application at http://localhost:${PORT}`);
    console.log('Default admin credentials: admin/admin123');
    console.log('If login fails, visit /reset-admin to reset the password');
});