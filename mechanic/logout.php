<?php
// htdocs/mechanic/logout.php
session_start();
unset($_SESSION['mechanic_id']);
unset($_SESSION['mechanic_name']);
header("Location: index.php");
exit;
?>