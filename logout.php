<?php
/**
 * Handler Logout Drive
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

unset($_SESSION['drive_user']);
session_destroy();

header('Location: login.php');
exit;
