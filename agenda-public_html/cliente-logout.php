<?php
require_once __DIR__ . '/includes/security.php';

app_start_session();
unset($_SESSION['cliente_id'], $_SESSION['cliente_nome'], $_SESSION['cliente_email'], $_SESSION['cliente_login_at']);
header('Location: cliente-login.php');
exit;
