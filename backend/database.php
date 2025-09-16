<?php
/**
 * Polaris CRM - Gestion de la base de données SQLite
 * Connexion, création des tables et fonctions utilitaires
 */

class Database {
    private $pdo;
    private $dbPath;
    
    public function __construct() {
        $this->dbPath = __DIR__ . '/../data/polaris.db';
        $this->connect();
        $this->createTables();
    }
    
    /**
     * Connexion à la base SQLite
     */
    private function connect() {
        try {
            // Créer le dossier data s'il n'existe pas
            $dataDir = dirname($this->dbPath);
            if (!file_exists($dataDir)) {
                mkdir($dataDir, 0755, true);
            }
            
            $this->pdo = new PDO('sqlite:' . $this->dbPath);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // Activer les clés étrangères
            $this->pdo->exec('PRAGMA foreign_keys = ON');
            
        } catch (PDOException $e) {
            die('Erreur de connexion à la base de données : ' . $e->getMessage());
        }
    }
    
    /**
     * Création des tables si elles n'existent pas
     */
    private function createTables() {
        $tables = [
            // Table des membres
            'members' => "
                CREATE TABLE IF NOT EXISTS members (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    prenom TEXT NOT NULL,
                    nom TEXT NOT NULL,
                    telephone TEXT UNIQUE NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ",
            
            // Table des segments
            'segments' => "
                CREATE TABLE IF NOT EXISTS segments (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    nom TEXT NOT NULL UNIQUE,
                    description TEXT,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                )
            ",
            
            // Table de liaison membres-segments
            'segment_members' => "
                CREATE TABLE IF NOT EXISTS segment_members (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    segment_id INTEGER NOT NULL,
                    member_id INTEGER NOT NULL,
                    added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (segment_id) REFERENCES segments(id) ON DELETE CASCADE,
                    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
                    UNIQUE(segment_id, member_id)
                )
            ",
            
            // Table des messages
            'messages' => "
                CREATE TABLE IF NOT EXISTS messages (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    member_id INTEGER NOT NULL,
                    type TEXT NOT NULL CHECK(type IN ('push_out', 'conversation_in', 'conversation_out')),
                    content TEXT NOT NULL,
                    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending', 'sent', 'delivered', 'read', 'failed')),
                    whatsapp_message_id TEXT,
                    metadata TEXT, -- JSON pour infos supplémentaires
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
                )
            "
        ];
        
        try {
            foreach ($tables as $tableName => $sql) {
                $this->pdo->exec($sql);
            }
            
            // Créer les index pour optimiser les requêtes
            $this->createIndexes();
            
        } catch (PDOException $e) {
            die('Erreur lors de la création des tables : ' . $e->getMessage());
        }
    }
    
    /**
     * Création des index pour optimiser les performances
     */
    private function createIndexes() {
        $indexes = [
            'CREATE INDEX IF NOT EXISTS idx_members_telephone ON members(telephone)',
            'CREATE INDEX IF NOT EXISTS idx_messages_member_id ON messages(member_id)',
            'CREATE INDEX IF NOT EXISTS idx_messages_type ON messages(type)',
            'CREATE INDEX IF NOT EXISTS idx_messages_status ON messages(status)',
            'CREATE INDEX IF NOT EXISTS idx_messages_created_at ON messages(created_at)',
            'CREATE INDEX IF NOT EXISTS idx_segment_members_segment ON segment_members(segment_id)',
            'CREATE INDEX IF NOT EXISTS idx_segment_members_member ON segment_members(member_id)'
        ];
        
        foreach ($indexes as $indexSql) {
            $this->pdo->exec($indexSql);
        }
    }
    
    /**
     * Obtenir l'instance PDO
     */
    public function getPdo() {
        return $this->pdo;
    }
    
    /**
     * Exécuter une requête et retourner les résultats
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log('Erreur SQL : ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Insérer des données et retourner l'ID
     */
    public function insert($sql, $params = []) {
        $this->query($sql, $params);
        return $this->pdo->lastInsertId();
    }
    
    /**
     * Compter les enregistrements
     */
    public function count($table, $where = '', $params = []) {
        $sql = "SELECT COUNT(*) as count FROM {$table}";
        if ($where) {
            $sql .= " WHERE {$where}";
        }
        
        $result = $this->query($sql, $params)->fetch();
        return $result['count'];
    }
    
    /**
     * Vérifier si la base de données fonctionne
     */
    public function testConnection() {
        try {
            $result = $this->query("SELECT datetime('now') as current_time")->fetch();
            return [
                'status' => 'success',
                'message' => 'Connexion réussie',
                'current_time' => $result['current_time'],
                'database_path' => $this->dbPath
            ];
        } catch (Exception $e) {
            return [
                'status' => 'error',
                'message' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Obtenir les statistiques générales
     */
    public function getStats() {
        return [
            'members_count' => $this->count('members'),
            'segments_count' => $this->count('segments'),
            'messages_count' => $this->count('messages'),
            'pending_messages' => $this->count('messages', "status = 'pending'"),
            'sent_messages' => $this->count('messages', "status IN ('sent', 'delivered', 'read')"),
            'failed_messages' => $this->count('messages', "status = 'failed'")
        ];
    }
    
    /**
     * Nettoyer et fermer la connexion
     */
    public function close() {
        $this->pdo = null;
    }
    
    /**
     * Débogage : afficher la structure de la base
     */
    public function getSchema() {
        $tables = [];
        $tablesQuery = $this->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
        
        while ($table = $tablesQuery->fetch()) {
            $tableName = $table['name'];
            $columns = $this->query("PRAGMA table_info({$tableName})")->fetchAll();
            $tables[$tableName] = $columns;
        }
        
        return $tables;
    }
}

// Test rapide si le fichier est appelé directement
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    echo "<h2>🗄️ Test de la base de données Polaris CRM</h2>";
    
    try {
        $db = new Database();
        $test = $db->testConnection();
        $stats = $db->getStats();
        
        echo "<h3>✅ Connexion</h3>";
        echo "<p>Status: " . $test['status'] . "</p>";
        echo "<p>Message: " . $test['message'] . "</p>";
        echo "<p>Heure actuelle: " . $test['current_time'] . "</p>";
        echo "<p>Chemin DB: " . $test['database_path'] . "</p>";
        
        echo "<h3>📊 Statistiques</h3>";
        foreach ($stats as $key => $value) {
            echo "<p>" . ucfirst(str_replace('_', ' ', $key)) . ": " . $value . "</p>";
        }
        
        echo "<h3>🔧 Structure de la base</h3>";
        $schema = $db->getSchema();
        foreach ($schema as $tableName => $columns) {
            echo "<h4>Table: {$tableName}</h4>";
            echo "<ul>";
            foreach ($columns as $column) {
                echo "<li>{$column['name']} ({$column['type']})</li>";
            }
            echo "</ul>";
        }
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erreur: " . $e->getMessage() . "</p>";
    }
}
?>