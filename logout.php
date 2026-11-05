<?php
require_once 'includes/bootstrap.php';
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
