<?php
require_once __DIR__ . '/../core/bootstrap.php';
\Core\Auth::logout();
header('Location: login.php');
exit;
