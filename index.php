<?php
        require_once 'auth.php';
        require_once 'utils.php';

        if (is_logged_in()) {
            unset($_SESSION['redirect_url']);
            header('Location: dashboard.php');
            exit;
        }

        $error_message = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';

            if (login($username, $password)) {
                header('Location: dashboard.php');
                exit;
            } else {
                $error_message = 'Invalid username or password.';
            }
        }

        $page_title = 'Admin Login';
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo htmlspecialchars($page_title); ?></title>
            <link rel="stylesheet" href="style.css?v=<?php echo time(); ?>">
        </head>
        <body>
            <div class="page-wrapper">
                <div class="main-content no-sidebar">
                    <div class="content-body">
                        <div class="container login-container">
                             <header class="login-header">
                                 <img src="logo.png" alt="Logo" class="logo" onerror="this.style.display='none'; this.onerror=null;">
                                 <h1>Admin Login</h1>
                             </header>
                             <main>
                                <?php if ($error_message): ?>
                                    <div class="message error-message"><?php echo htmlspecialchars($error_message); ?><span class="close-message" onclick="this.parentElement.style.display='none';">&times;</span></div>
                                <?php endif; ?>

                                <form method="POST" action="index.php" class="login-form">
                                    <div class="form-group">
                                        <label for="username">Username</label>
                                        <input type="text" id="username" name="username" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="password">Password</label>
                                        <input type="password" id="password" name="password" required>
                                    </div>
                                    <button type="submit" class="btn btn-primary">Login</button>
                                </form>
                             </main>
                        </div>
                    </div>
                </div>
            </div>
            <script src="script.js?v=<?php echo time(); ?>"></script>
        </body>
        </html>
