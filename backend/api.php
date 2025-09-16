<?php
/**
 * Polaris CRM - API REST
 * Endpoints pour gérer membres, segments et messages
 */

// Désactiver l'affichage des erreurs PHP pour éviter les sorties HTML
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Headers pour JSON et CORS
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Gérer les requêtes OPTIONS (preflight CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Capturer les erreurs fatales
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && $error['type'] === E_ERROR) {
        if (!headers_sent()) {
            http_response_code(500);
        }
        echo json_encode([
            'error' => true,
            'message' => 'Erreur interne du serveur',
            'code' => 500
        ], JSON_UNESCAPED_UNICODE);
    }
});

// Gestion globale des exceptions
set_exception_handler(function($exception) {
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode([
        'error' => true,
        'message' => $exception->getMessage(),
        'code' => 500
    ], JSON_UNESCAPED_UNICODE);
    exit();
});

require_once 'database.php';

class PolarisAPI {
    private $db;
    
    public function __construct() {
        try {
            $this->db = new Database();
        } catch (Exception $e) {
            $this->sendError('Erreur de connexion à la base de données: ' . $e->getMessage(), 500);
        }
    }
    
    /**
     * Router principal
     */
    public function handleRequest() {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = $this->getPath();
        
        try {
            switch ($path) {
                // Membres
                case 'members':
                    $this->handleMembers($method);
                    break;
                    
                case 'members/search':
                    $this->searchMembers();
                    break;
                    
                // Segments
                case 'segments':
                    $this->handleSegments($method);
                    break;
                    
                // Relations segments-membres
                case 'segments/members':
                    $this->handleSegmentMembers($method);
                    break;
                    
                // Messages
                case 'messages':
                    $this->handleMessages($method);
                    break;
                    
                case 'messages/push':
                    $this->sendPushMessage();
                    break;
                    
                // Statistiques
                case 'stats':
                    $this->getStats();
                    break;
                    
                // Test de l'API
                case 'test':
                    $this->testAPI();
                    break;
                    
                default:
                    $this->sendError('Endpoint non trouvé', 404);
            }
            
        } catch (Exception $e) {
            error_log('API Error: ' . $e->getMessage());
            $this->sendError($e->getMessage(), 500);
        }
    }
    
    /**
     * Extraire le chemin de l'URL
     */
    private function getPath() {
        $path = $_GET['endpoint'] ?? '';
        return trim($path, '/');
    }
    
    /**
     * Obtenir les données JSON de la requête
     */
    private function getJsonInput() {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Données JSON invalides');
        }
        
        return $data;
    }
    
    /**
     * Gestion des membres
     */
    private function handleMembers($method) {
        switch ($method) {
            case 'GET':
                if (isset($_GET['id'])) {
                    $this->getMember($_GET['id']);
                } else {
                    $this->getAllMembers();
                }
                break;
                
            case 'POST':
                $this->createMember();
                break;
                
            case 'PUT':
                $this->updateMember();
                break;
                
            case 'DELETE':
                $this->deleteMember();
                break;
                
            default:
                $this->sendError('Méthode non autorisée', 405);
        }
    }
    
    /**
     * Obtenir tous les membres
     */
    private function getAllMembers() {
        $sql = "SELECT * FROM members ORDER BY nom, prenom";
        $stmt = $this->db->query($sql);
        $members = $stmt->fetchAll();
        
        $this->sendResponse($members);
    }
    
    /**
     * Obtenir un membre par ID
     */
    private function getMember($id) {
        $sql = "SELECT * FROM members WHERE id = ?";
        $stmt = $this->db->query($sql, [$id]);
        $member = $stmt->fetch();
        
        if (!$member) {
            $this->sendError('Membre non trouvé', 404);
            return;
        }
        
        $this->sendResponse($member);
    }
    
    /**
     * Créer un nouveau membre
     */
    private function createMember() {
        $data = $this->getJsonInput();
        
        // Validation
        if (empty($data['prenom']) || empty($data['nom']) || empty($data['telephone'])) {
            $this->sendError('Prénom, nom et téléphone sont obligatoires', 400);
            return;
        }
        
        // Nettoyer le numéro de téléphone
        $telephone = preg_replace('/[^\d]/', '', $data['telephone']);
        
        if (strlen($telephone) < 9 || strlen($telephone) > 15) {
            $this->sendError('Format de téléphone invalide', 400);
            return;
        }
        
        // Vérifier si le numéro existe déjà
        $existing = $this->db->query("SELECT id FROM members WHERE telephone = ?", [$telephone])->fetch();
        if ($existing) {
            $this->sendError('Ce numéro de téléphone existe déjà', 409);
            return;
        }
        
        // Insérer le membre
        $sql = "INSERT INTO members (prenom, nom, telephone) VALUES (?, ?, ?)";
        $memberId = $this->db->insert($sql, [
            trim($data['prenom']),
            trim($data['nom']),
            $telephone
        ]);
        
        // Retourner le membre créé
        $this->getMember($memberId);
    }
    
    /**
     * Mettre à jour un membre
     */
    private function updateMember() {
        $data = $this->getJsonInput();
        
        if (empty($data['id'])) {
            $this->sendError('ID du membre requis', 400);
            return;
        }
        
        // Vérifier que le membre existe
        $existing = $this->db->query("SELECT * FROM members WHERE id = ?", [$data['id']])->fetch();
        if (!$existing) {
            $this->sendError('Membre non trouvé', 404);
            return;
        }
        
        $updates = [];
        $params = [];
        
        if (!empty($data['prenom'])) {
            $updates[] = "prenom = ?";
            $params[] = trim($data['prenom']);
        }
        
        if (!empty($data['nom'])) {
            $updates[] = "nom = ?";
            $params[] = trim($data['nom']);
        }
        
        if (!empty($data['telephone'])) {
            $telephone = preg_replace('/[^\d]/', '', $data['telephone']);
            
            // Vérifier si le nouveau numéro n'est pas déjà utilisé par quelqu'un d'autre
            $duplicate = $this->db->query("SELECT id FROM members WHERE telephone = ? AND id != ?", 
                [$telephone, $data['id']])->fetch();
            if ($duplicate) {
                $this->sendError('Ce numéro de téléphone est déjà utilisé', 409);
                return;
            }
            
            $updates[] = "telephone = ?";
            $params[] = $telephone;
        }
        
        if (empty($updates)) {
            $this->sendError('Aucune donnée à mettre à jour', 400);
            return;
        }
        
        $updates[] = "updated_at = datetime('now')";
        $params[] = $data['id'];
        
        $sql = "UPDATE members SET " . implode(', ', $updates) . " WHERE id = ?";
        $this->db->query($sql, $params);
        
        $this->getMember($data['id']);
    }
    
    /**
     * Supprimer un membre
     */
    private function deleteMember() {
        $data = $this->getJsonInput();
        
        if (empty($data['id'])) {
            $this->sendError('ID du membre requis', 400);
            return;
        }
        
        // Vérifier que le membre existe
        $existing = $this->db->query("SELECT * FROM members WHERE id = ?", [$data['id']])->fetch();
        if (!$existing) {
            $this->sendError('Membre non trouvé', 404);
            return;
        }
        
        // Supprimer (les relations et messages seront supprimés automatiquement via CASCADE)
        $this->db->query("DELETE FROM members WHERE id = ?", [$data['id']]);
        
        $this->sendResponse(['message' => 'Membre supprimé avec succès']);
    }
    
    /**
     * Rechercher des membres
     */
    private function searchMembers() {
        $query = $_GET['q'] ?? '';
        
        if (empty($query)) {
            $this->getAllMembers();
            return;
        }
        
        $searchTerm = "%{$query}%";
        $sql = "SELECT * FROM members 
                WHERE prenom LIKE ? OR nom LIKE ? OR telephone LIKE ? 
                ORDER BY nom, prenom";
        
        $stmt = $this->db->query($sql, [$searchTerm, $searchTerm, $searchTerm]);
        $members = $stmt->fetchAll();
        
        $this->sendResponse($members);
    }
    
    /**
     * Gestion des segments
     */
    private function handleSegments($method) {
        switch ($method) {
            case 'GET':
                if (isset($_GET['id'])) {
                    $this->getSegment($_GET['id']);
                } else {
                    $this->getAllSegments();
                }
                break;
                
            case 'POST':
                $this->createSegment();
                break;
                
            case 'PUT':
                $this->updateSegment();
                break;
                
            case 'DELETE':
                $this->deleteSegment();
                break;
                
            default:
                $this->sendError('Méthode non autorisée', 405);
        }
    }
    
    /**
     * Obtenir tous les segments avec le nombre de membres
     */
    private function getAllSegments() {
        $sql = "SELECT s.*, 
                       COUNT(sm.member_id) as member_count
                FROM segments s
                LEFT JOIN segment_members sm ON s.id = sm.segment_id
                GROUP BY s.id
                ORDER BY s.nom";
        
        $stmt = $this->db->query($sql);
        $segments = $stmt->fetchAll();
        
        $this->sendResponse($segments);
    }
    
    /**
     * Obtenir un segment avec ses membres
     */
    private function getSegment($id) {
        // Informations du segment
        $sql = "SELECT s.*, COUNT(sm.member_id) as member_count 
                FROM segments s 
                LEFT JOIN segment_members sm ON s.id = sm.segment_id 
                WHERE s.id = ? 
                GROUP BY s.id";
        $stmt = $this->db->query($sql, [$id]);
        $segment = $stmt->fetch();
        
        if (!$segment) {
            $this->sendError('Segment non trouvé', 404);
            return;
        }
        
        // Membres du segment
        $membersSql = "SELECT m.* 
                       FROM members m
                       JOIN segment_members sm ON m.id = sm.member_id
                       WHERE sm.segment_id = ?
                       ORDER BY m.nom, m.prenom";
        
        $membersStmt = $this->db->query($membersSql, [$id]);
        $segment['members'] = $membersStmt->fetchAll();
        
        $this->sendResponse($segment);
    }
    
    /**
     * Créer un nouveau segment
     */
    private function createSegment() {
        $data = $this->getJsonInput();
        
        if (empty($data['nom'])) {
            $this->sendError('Nom du segment obligatoire', 400);
            return;
        }
        
        // Vérifier si le nom existe déjà
        $existing = $this->db->query("SELECT id FROM segments WHERE nom = ?", [trim($data['nom'])])->fetch();
        if ($existing) {
            $this->sendError('Ce nom de segment existe déjà', 409);
            return;
        }
        
        $sql = "INSERT INTO segments (nom, description) VALUES (?, ?)";
        $segmentId = $this->db->insert($sql, [
            trim($data['nom']),
            trim($data['description'] ?? '')
        ]);
        
        $this->getSegment($segmentId);
    }
    
    /**
     * Mettre à jour un segment
     */
    private function updateSegment() {
        $data = $this->getJsonInput();
        
        if (empty($data['id'])) {
            $this->sendError('ID du segment requis', 400);
            return;
        }
        
        // Vérifier que le segment existe
        $existing = $this->db->query("SELECT * FROM segments WHERE id = ?", [$data['id']])->fetch();
        if (!$existing) {
            $this->sendError('Segment non trouvé', 404);
            return;
        }
        
        $updates = [];
        $params = [];
        
        if (!empty($data['nom'])) {
            // Vérifier que le nouveau nom n'existe pas déjà
            $duplicate = $this->db->query("SELECT id FROM segments WHERE nom = ? AND id != ?", 
                [trim($data['nom']), $data['id']])->fetch();
            if ($duplicate) {
                $this->sendError('Ce nom de segment existe déjà', 409);
                return;
            }
            
            $updates[] = "nom = ?";
            $params[] = trim($data['nom']);
        }
        
        if (isset($data['description'])) {
            $updates[] = "description = ?";
            $params[] = trim($data['description']);
        }
        
        if (empty($updates)) {
            $this->sendError('Aucune donnée à mettre à jour', 400);
            return;
        }
        
        $params[] = $data['id'];
        $sql = "UPDATE segments SET " . implode(', ', $updates) . " WHERE id = ?";
        $this->db->query($sql, $params);
        
        $this->getSegment($data['id']);
    }
    
    /**
     * Supprimer un segment
     */
    private function deleteSegment() {
        $data = $this->getJsonInput();
        
        if (empty($data['id'])) {
            $this->sendError('ID du segment requis', 400);
            return;
        }
        
        // Vérifier que le segment existe
        $existing = $this->db->query("SELECT * FROM segments WHERE id = ?", [$data['id']])->fetch();
        if (!$existing) {
            $this->sendError('Segment non trouvé', 404);
            return;
        }
        
        // Supprimer (les relations seront supprimées automatiquement via CASCADE)
        $this->db->query("DELETE FROM segments WHERE id = ?", [$data['id']]);
        
        $this->sendResponse(['message' => 'Segment supprimé avec succès']);
    }
    
    /**
     * Gestion des relations segments-membres
     */
    private function handleSegmentMembers($method) {
        switch ($method) {
            case 'POST':
                $this->addMemberToSegment();
                break;
                
            case 'DELETE':
                $this->removeMemberFromSegment();
                break;
                
            default:
                $this->sendError('Méthode non autorisée', 405);
        }
    }
    
    /**
     * Ajouter un membre à un segment
     */
    private function addMemberToSegment() {
        $data = $this->getJsonInput();
        
        if (empty($data['segment_id']) || empty($data['member_id'])) {
            $this->sendError('ID du segment et du membre requis', 400);
            return;
        }
        
        // Vérifier que le segment et le membre existent
        $segment = $this->db->query("SELECT id FROM segments WHERE id = ?", [$data['segment_id']])->fetch();
        $member = $this->db->query("SELECT id FROM members WHERE id = ?", [$data['member_id']])->fetch();
        
        if (!$segment || !$member) {
            $this->sendError('Segment ou membre non trouvé', 404);
            return;
        }
        
        // Vérifier si la relation existe déjà
        $existing = $this->db->query("SELECT id FROM segment_members WHERE segment_id = ? AND member_id = ?", 
            [$data['segment_id'], $data['member_id']])->fetch();
        
        if ($existing) {
            $this->sendError('Le membre est déjà dans ce segment', 409);
            return;
        }
        
        // Ajouter la relation
        $sql = "INSERT INTO segment_members (segment_id, member_id) VALUES (?, ?)";
        $this->db->query($sql, [$data['segment_id'], $data['member_id']]);
        
        $this->sendResponse(['message' => 'Membre ajouté au segment avec succès']);
    }
    
    /**
     * Retirer un membre d'un segment
     */
    private function removeMemberFromSegment() {
        $data = $this->getJsonInput();
        
        if (empty($data['segment_id']) || empty($data['member_id'])) {
            $this->sendError('ID du segment et du membre requis', 400);
            return;
        }
        
        $result = $this->db->query("DELETE FROM segment_members WHERE segment_id = ? AND member_id = ?", 
            [$data['segment_id'], $data['member_id']]);
        
        if ($result->rowCount() === 0) {
            $this->sendError('Relation segment-membre non trouvée', 404);
            return;
        }
        
        $this->sendResponse(['message' => 'Membre retiré du segment avec succès']);
    }
    
    /**
     * Gestion des messages (placeholder)
     */
    private function handleMessages($method) {
        switch ($method) {
            case 'GET':
                $this->getMessages();
                break;
            default:
                $this->sendError('Méthode non autorisée', 405);
        }
    }
    
    /**
     * Obtenir les messages (placeholder)
     */
    private function getMessages() {
        $sql = "SELECT m.*, mem.prenom, mem.nom, mem.telephone 
                FROM messages m 
                JOIN members mem ON m.member_id = mem.id 
                ORDER BY m.created_at DESC 
                LIMIT 50";
        
        $stmt = $this->db->query($sql);
        $messages = $stmt->fetchAll();
        
        $this->sendResponse($messages);
    }
    
    /**
     * Envoyer un message push (placeholder)
     */
    private function sendPushMessage() {
        $data = $this->getJsonInput();
        
        // Pour l'instant, juste simuler
        $this->sendResponse([
            'success' => true,
            'message' => 'Message push simulé',
            'recipients_count' => count($data['recipients'] ?? [])
        ]);
    }
    
    /**
     * Statistiques générales
     */
    private function getStats() {
        $stats = $this->db->getStats();
        $this->sendResponse($stats);
    }
    
    /**
     * Test de l'API
     */
    private function testAPI() {
        $this->sendResponse([
            'status' => 'success',
            'message' => 'API Polaris CRM fonctionne',
            'timestamp' => date('Y-m-d H:i:s'),
            'endpoints' => [
                'GET /api.php?endpoint=members' => 'Lister les membres',
                'POST /api.php?endpoint=members' => 'Créer un membre',
                'PUT /api.php?endpoint=members' => 'Modifier un membre',
                'DELETE /api.php?endpoint=members' => 'Supprimer un membre',
                'GET /api.php?endpoint=segments' => 'Lister les segments',
                'POST /api.php?endpoint=segments' => 'Créer un segment',
                'GET /api.php?endpoint=stats' => 'Statistiques',
            ]
        ]);
    }
    
    /**
     * Envoyer une réponse JSON
     */
    private function sendResponse($data, $code = 200) {
        // S'assurer qu'on n'a pas déjà envoyé des headers
        if (!headers_sent()) {
            http_response_code($code);
        }
        
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }
    
    /**
     * Envoyer une erreur JSON
     */
    private function sendError($message, $code = 400) {
        $this->sendResponse([
            'error' => true,
            'message' => $message,
            'code' => $code
        ], $code);
    }
}

// Point d'entrée principal
try {
    $api = new PolarisAPI();
    $api->handleRequest();
} catch (Exception $e) {
    // Dernière chance de capturer les erreurs
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    
    echo json_encode([
        'error' => true,
        'message' => 'Erreur interne du serveur',
        'details' => $e->getMessage(),
        'code' => 500
    ], JSON_UNESCAPED_UNICODE);
}
?>