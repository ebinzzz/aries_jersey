<?php

// includes/migration_runner.php
require_once __DIR__ . '/db_config.php';

class MigrationRunner
{
    private $conn;
    private $migrationsDir;

    public function __construct()
    {
        global $conn;
        $this->conn = $conn;
        $this->migrationsDir = dirname(__DIR__) . '/database/migrations';
    }

    /**
     * Check if MySQL server connection is alive
     */
    public function isConnected()
    {
        return $this->conn !== null && !$this->conn->connect_error;
    }

    /**
     * Check if the specific database exists
     */
    public function databaseExists()
    {
        if (!$this->isConnected()) {
            return false;
        }
        return @$this->conn->select_db(DB_NAME);
    }

    /**
     * Create the database
     */
    public function createDatabase()
    {
        if (!$this->isConnected()) {
            throw new Exception("Cannot create database: Not connected to MySQL server.");
        }
        $sql = "CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci";
        if ($this->conn->query($sql)) {
            $this->conn->select_db(DB_NAME);
            return true;
        } else {
            throw new Exception("Error creating database: " . $this->conn->error);
        }
    }

    /**
     * Ensure the migrations table exists
     */
    public function ensureMigrationsTable()
    {
        if (!$this->databaseExists()) {
            $this->createDatabase();
        }

        $sql = "CREATE TABLE IF NOT EXISTS `migrations` (
            `id` INT AUTO_INCREMENT PRIMARY KEY,
            `migration_name` VARCHAR(255) NOT NULL UNIQUE,
            `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        if (!$this->conn->query($sql)) {
            throw new Exception("Error creating migrations table: " . $this->conn->error);
        }
    }

    /**
     * Get list of applied migrations
     */
    public function getAppliedMigrations()
    {
        try {
            $this->ensureMigrationsTable();
            $applied = [];
            $result = $this->conn->query("SELECT `migration_name` FROM `migrations` ORDER BY `id` ASC");
            if ($result) {
                while ($row = $result->fetch_assoc()) {
                    $applied[] = $row['migration_name'];
                }
                $result->free();
            }
            return $applied;
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Get list of migration files in directory
     */
    public function getMigrationFiles()
    {
        if (!is_dir($this->migrationsDir)) {
            return [];
        }
        $files = scandir($this->migrationsDir);
        $migrations = [];
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'sql') {
                $migrations[] = $file;
            }
        }
        sort($migrations);
        return $migrations;
    }

    /**
     * Get pending migrations
     */
    public function getPendingMigrations()
    {
        $applied = $this->getAppliedMigrations();
        $files = $this->getMigrationFiles();
        return array_diff($files, $applied);
    }

    /**
     * Run a single migration file
     */
    public function runMigration($fileName)
    {
        $filePath = $this->migrationsDir . '/' . $fileName;
        if (!file_exists($filePath)) {
            throw new Exception("Migration file not found: " . $fileName);
        }

        $sqlContent = file_get_contents($filePath);

        $this->ensureMigrationsTable();

        $this->conn->begin_transaction();
        try {
            // Execute multi queries
            if ($this->conn->multi_query($sqlContent)) {
                do {
                    if ($result = $this->conn->store_result()) {
                        $result->free();
                    }
                } while ($this->conn->more_results() && $this->conn->next_result());
            }

            // Check for multi query execution errors
            if ($this->conn->error) {
                throw new Exception("SQL execution error in " . $fileName . ": " . $this->conn->error);
            }

            // Record migration
            $stmt = $this->conn->prepare("INSERT INTO `migrations` (`migration_name`) VALUES (?)");
            $stmt->bind_param("s", $fileName);
            if (!$stmt->execute()) {
                throw new Exception("Failed to record migration: " . $stmt->error);
            }
            $stmt->close();

            $this->conn->commit();
            return true;
        } catch (Exception $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    /**
     * Run all pending migrations
     */
    public function runPendingMigrations()
    {
        $pending = $this->getPendingMigrations();
        $executed = [];
        foreach ($pending as $migration) {
            $this->runMigration($migration);
            $executed[] = $migration;
        }
        return $executed;
    }

    /**
     * Run arbitrary SQL query
     */
    public function runArbitraryQuery($sql)
    {
        $this->ensureMigrationsTable();

        $result = $this->conn->query($sql);
        if (!$result) {
            throw new Exception($this->conn->error);
        }

        if ($result === true) {
            return [
                'type' => 'success',
                'affected_rows' => $this->conn->affected_rows
            ];
        } else {
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $result->free();
            return [
                'type' => 'select',
                'rows' => $rows,
                'num_rows' => count($rows)
            ];
        }
    }
}
