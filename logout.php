<?php
require __DIR__ . '/lib/bootstrap.php';
app_start_session();
$_SESSION = [];
session_destroy();
header('Location: login.php');
