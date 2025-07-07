const express = require('express');
const sqlite3 = require('sqlite3').verbose();
const bcrypt = require('bcryptjs');
const rateLimit = require('express-rate-limit');
const multer = require('multer');
const cors = require('cors');
const path = require('path');
const fs = require('fs');
require('dotenv').config();

const app = express();
const PORT = process.env.PORT || 3000;

// Middleware
app.use(cors());
app.use(express.json());
app.use(express.urlencoded({ extended: true }));
app.use(express.static('public'));

// Rate limiting
const limiter = rateLimit({
  windowMs: 15 * 60 * 1000, // 15 minutes
  max: 100 // limit each IP to 100 requests per windowMs
});
app.use(limiter);

// Database initialization
const db = new sqlite3.Database('./license_manager.db');

// Initialize database tables
db.serialize(() => {
  // Users table
  db.run(`CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT UNIQUE NOT NULL,
    password TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
  )`);

  // Licenses table
  db.run(`CREATE TABLE IF NOT EXISTS licenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    license_key TEXT UNIQUE NOT NULL,
    product_name TEXT NOT NULL,
    customer_email TEXT NOT NULL,
    customer_name TEXT,
    expiry_date DATE,
    status TEXT DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
  )`);

  // Settings table
  db.run(`CREATE TABLE IF NOT EXISTS settings (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    setting_key TEXT UNIQUE NOT NULL,
    setting_value TEXT,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
  )`);

  // Create default admin user if not exists
  db.get("SELECT * FROM users WHERE username = 'admin'", (err, row) => {
    if (!row) {
      const hashedPassword = bcrypt.hashSync('admin123', 10);
      db.run("INSERT INTO users (username, password) VALUES (?, ?)", ['admin', hashedPassword]);
      console.log('Default admin user created (username: admin, password: admin123)');
    }
  });
});

// Session management (simple in-memory for demo)
const sessions = new Map();

// Authentication middleware
const requireAuth = (req, res, next) => {
  const sessionId = req.headers.authorization;
  if (!sessionId || !sessions.has(sessionId)) {
    return res.status(401).json({ error: 'Authentication required' });
  }
  req.user = sessions.get(sessionId);
  next();
};

// Routes
app.get('/', (req, res) => {
  res.sendFile(path.join(__dirname, 'public', 'index.html'));
});

// Authentication routes
app.post('/api/login', (req, res) => {
  const { username, password } = req.body;
  
  db.get("SELECT * FROM users WHERE username = ?", [username], (err, user) => {
    if (err || !user || !bcrypt.compareSync(password, user.password)) {
      return res.status(401).json({ error: 'Invalid credentials' });
    }
    
    const sessionId = Math.random().toString(36).substring(2);
    sessions.set(sessionId, { id: user.id, username: user.username });
    
    res.json({ success: true, sessionId, user: { id: user.id, username: user.username } });
  });
});

app.post('/api/logout', (req, res) => {
  const sessionId = req.headers.authorization;
  if (sessionId) {
    sessions.delete(sessionId);
  }
  res.json({ success: true });
});

// License management routes
app.get('/api/licenses', requireAuth, (req, res) => {
  db.all("SELECT * FROM licenses ORDER BY created_at DESC", (err, licenses) => {
    if (err) {
      return res.status(500).json({ error: 'Database error' });
    }
    res.json(licenses);
  });
});

app.post('/api/licenses', requireAuth, (req, res) => {
  const { license_key, product_name, customer_email, customer_name, expiry_date } = req.body;
  
  db.run(
    "INSERT INTO licenses (license_key, product_name, customer_email, customer_name, expiry_date) VALUES (?, ?, ?, ?, ?)",
    [license_key, product_name, customer_email, customer_name, expiry_date],
    function(err) {
      if (err) {
        return res.status(400).json({ error: 'License key already exists or invalid data' });
      }
      res.json({ success: true, id: this.lastID });
    }
  );
});

app.put('/api/licenses/:id', requireAuth, (req, res) => {
  const { id } = req.params;
  const { product_name, customer_email, customer_name, expiry_date, status } = req.body;
  
  db.run(
    "UPDATE licenses SET product_name = ?, customer_email = ?, customer_name = ?, expiry_date = ?, status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
    [product_name, customer_email, customer_name, expiry_date, status, id],
    function(err) {
      if (err) {
        return res.status(500).json({ error: 'Database error' });
      }
      res.json({ success: true });
    }
  );
});

app.delete('/api/licenses/:id', requireAuth, (req, res) => {
  const { id } = req.params;
  
  db.run("DELETE FROM licenses WHERE id = ?", [id], function(err) {
    if (err) {
      return res.status(500).json({ error: 'Database error' });
    }
    res.json({ success: true });
  });
});

// License verification endpoint (public)
app.post('/api/verify-license', (req, res) => {
  const { license_key, product_name } = req.body;
  
  db.get(
    "SELECT * FROM licenses WHERE license_key = ? AND product_name = ? AND status = 'active'",
    [license_key, product_name],
    (err, license) => {
      if (err) {
        return res.status(500).json({ error: 'Database error' });
      }
      
      if (!license) {
        return res.json({ valid: false, message: 'Invalid license key' });
      }
      
      // Check expiry
      if (license.expiry_date && new Date(license.expiry_date) < new Date()) {
        return res.json({ valid: false, message: 'License expired' });
      }
      
      res.json({ 
        valid: true, 
        message: 'License is valid',
        license: {
          product_name: license.product_name,
          customer_name: license.customer_name,
          expiry_date: license.expiry_date
        }
      });
    }
  );
});

// Settings routes
app.get('/api/settings', requireAuth, (req, res) => {
  db.all("SELECT * FROM settings", (err, settings) => {
    if (err) {
      return res.status(500).json({ error: 'Database error' });
    }
    res.json(settings);
  });
});

app.post('/api/settings', requireAuth, (req, res) => {
  const { setting_key, setting_value } = req.body;
  
  db.run(
    "INSERT OR REPLACE INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)",
    [setting_key, setting_value],
    function(err) {
      if (err) {
        return res.status(500).json({ error: 'Database error' });
      }
      res.json({ success: true });
    }
  );
});

app.listen(PORT, () => {
  console.log(`License Manager Server running on port ${PORT}`);
  console.log(`Access the application at http://localhost:${PORT}`);
});