<?php
require_once "config/database.php";
require_once "includes/email_functions.php";

// Send daily reports
sendDailyReports();

echo "Daily reports sent successfully at " . date('Y-m-d H:i:s');
?>