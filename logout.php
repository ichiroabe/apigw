<?php
require_once __DIR__ . '/includes/auth.php';
$u = current_user();
if ($u) audit_log(db(), $u, 'logout');
start_session();
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
