<?php
require_once __DIR__ . '/includes/security.php';
require_once __DIR__ . '/config.php';

app_start_session();
clear_remember_token($conn);

session_unset();
session_destroy();

header('Location: login.php');
exit;
