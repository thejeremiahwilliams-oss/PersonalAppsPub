<?php
// public/setup.php
require_once 'includes/auth_functions.php';
require_once 'config/database.php';
require_login();

// Restrict to admins
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die("Unauthorized.");
}

$db = new Database();
$pdo = $db->getConnection();

echo "<h3>Running Database Schema Updates...</h3><ul>";

$queries = [
    "CREATE TABLE IF NOT EXISTS budget_plans (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, budget_year INT NOT NULL, budget_month INT NOT NULL, budget_data LONGTEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY unique_user_budget (user_id, budget_year, budget_month), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    "CREATE TABLE IF NOT EXISTS yearly_ledgers (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, ledger_year INT NOT NULL, ledger_data LONGTEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY unique_user_year (user_id, ledger_year), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    "CREATE TABLE IF NOT EXISTS daily_plans (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, plan_date DATE NOT NULL, plan_data LONGTEXT, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, UNIQUE KEY unique_user_date (user_id, plan_date), FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    "CREATE TABLE IF NOT EXISTS portfolio_scenarios (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT NOT NULL, scenario_name VARCHAR(255) NOT NULL, plan_data LONGTEXT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
    "ALTER TABLE notes ADD COLUMN category VARCHAR(100) DEFAULT 'Uncategorized'",
    "ALTER TABLE tasks ADD COLUMN task_category INT NULL DEFAULT NULL",
    "ALTER TABLE tasks ADD COLUMN completed_at DATETIME NULL DEFAULT NULL",
    "ALTER TABLE tasks ADD COLUMN reminder_date DATETIME NULL DEFAULT NULL",
    "ALTER TABLE tasks ADD COLUMN is_reminded TINYINT(1) NOT NULL DEFAULT 0",
    "ALTER TABLE meetings ADD COLUMN reminder_date DATETIME NULL DEFAULT NULL",
    "ALTER TABLE meetings ADD COLUMN is_reminded TINYINT(1) NOT NULL DEFAULT 0"
];

foreach ($queries as $sql) {
    try {
        $pdo->exec($sql);
        echo "<li style='color: green;'>Success: " . htmlspecialchars(substr($sql, 0, 50)) . "...</li>";
    } catch (Exception $e) {
        // Expected behavior for duplicate columns
        echo "<li style='color: gray;'>Skipped: " . htmlspecialchars($e->getMessage()) . "</li>";
    }
}
echo "</ul><p>Schema update complete.</p>";
?>