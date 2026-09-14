<?php
// config/database.php
// Ensure this file is stored outside your public web root

class Database {
    private $host = 'localhost';
    private $db_name = 'brokwxrm_personalapps';
    private $username = 'brokwxrm_lifeosadmin';
    private $password = '30H8xPSPXLBtj9HT';
    private $conn;

    public function getConnection() {
        $this->conn = null;

        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4";
            
            $options = [
                // Throw exceptions on error so they can be caught and logged
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                // Fetch rows as associative arrays by default
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Disable emulation for true prepared statements
                PDO::ATTR_EMULATE_PREPARES   => false, 
            ];
            
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            
        } catch(PDOException $exception) {
            // Write to the server's error log instead of outputting to the browser
            // to prevent information disclosure vulnerabilities
            error_log("Database Connection Error: " . $exception->getMessage());
            die("A database connection error occurred. Please try again later.");
        }

        return $this->conn;
    }
}