<?php
// /pages/admin/_admin_gate.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['permission']) || $_SESSION['permission'] < 2) {
    header('Location: /pages/index.php');
    exit;
}
