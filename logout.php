<?php
/**
 * Handler Logout Drive
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

unset($_SESSION['drive_user']);
unset($_SESSION['active_share_token'], $_SESSION['pending_share_token'], $_SESSION['drive_share_only']);
session_destroy();

header('Location: login.php');
exit;
