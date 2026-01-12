<?php
require_once '../backend/includes/config.php';

session_destroy();
redirect('login.php');
?>
