<?php
require_once 'db.php';

if (!isset($_SESSION['role'])) {
    header("Location: login.html");
    exit;
}
?>
<!DOCTYPE html>
<html>
<head><title>Dashboard</title></head>
<body>
    <h1>Welcome, <?php echo htmlspecialchars($_SESSION['fullName']); ?>!</h1>
    <p>Your role: <?php echo htmlspecialchars($_SESSION['role']); ?></p>
    <a href="logout.php">Logout</a>
</body>
</html>
