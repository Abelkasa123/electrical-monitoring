<?php if (isset($_SESSION["loggedin"]) && $_SESSION["loggedin"] === true): ?>
<nav class="navbar">
    <div class="nav-container">
        <a href="dashboard.php" class="nav-brand">Electrical Monitoring</a>
        <div class="nav-links">
            <a href="dashboard.php">Dashboard</a>
            <a href="data-entry.php">Enter Data</a>
            <a href="view-data.php">View Data</a>
            <a href="summaries.php">Summaries</a>
            <a href="import-export.php">Import/Export</a>
            <a href="logout.php" class="logout-btn">Logout (<?php echo get_current_username(); ?>)</a>
        </div>
    </div>
</nav>
<?php endif; ?>