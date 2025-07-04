<?php
        require_once 'auth.php';
        logout();
        header('Location: index.php?logged_out=1');
        exit;
        ?>
