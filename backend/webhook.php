<?php
/**
 * Polaris CRM - Webhook WhatsApp
 * Point d'entrée pour recevoir les messages WhatsApp et orchestrer les réponses automatiques
 */

require_once 'database.php';
require_once 'whatsapp-service.php';
require_once 'mistral-service.php';

class WebhookHandler {
    private $db;
    private $whatsapp;
    private $mistral;
    private $config;
    private $verifyToken;
    
    public function __construct() {
        // Charger la configuration
        $this->config = require __DIR__ . '/config.php';
        
        $this->db = new Database();
        $this->whatsapp = new WhatsAppService();
        $this->mistral = new MistralService();
        
        // Token de vérification webhook depuis config
        $this->verifyToken = $this->config['whatsapp']['webhook_verify_token'];
        
        // Logging pour debug
        $this->logRequest();
    }
    
    /**
     * Point d'entrée principal du webhook
     */
    public function handle() {
        $method = $_SERVER['REQUEST_METHOD'];
        
        // Vérifier si le webhook est activé
        if (!$this->config['features']['webhook_enabled']) {
            http_response_code(503);
            echo json_encode(['error' => 'Webhook disabled']);
            return;
        }
        
        try {
            switch ($method) {
                case 'GET':
                    $this->verifyWebhook();
                    break;
                    
                case 'POST':
                    $this->processWebhook();
                    break;
                    
                default:
                    http_response_code(405);
                    echo json_encode(['error' => 'Method not allowed']);
            }
            
        } catch (Exception $e) {
            $this->logError('Webhook Exception', ['error' => $e->getMessage()]);
            http_response_code(500);
            echo json_encode(['error' => 'Internal server error']);
        }
    }
    
    /**
     * Vérification du webhook (GET request from Meta)
     */
    private function verifyWebhook() {
        $mode = $_GET['hub_mode'] ?? '';
        $token = $_GET['hub_verify_token'] ?? '';
        $challenge = $_GET['hub_challenge'] ?? '';
        
        if ($mode === 'subscribe' && $token === $this->verifyToken) {
            // Vérification réussie
            http_response_code(200);
            echo $challenge;
            $this->log('Webhook verified successfully');
        } else {
            // Échec de vérification
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            $this->log('Webhook verification failed', ['mode' => $mode, 'token' => $token]);
        }
    }
    
    /**
     * Traitement des données webhook (POST request from Meta)
     */
    private function processWebhook() {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        
        if (!$data) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }
        
        $this->log('Webhook received', $data);
        
        // Répondre immédiatement à Meta (requis)
        http_response_code(200);
        echo json_encode(['status' => 'ok']);
        
        // Traiter les données en arrière-plan
        $this->processWebhookData($data);
    }
    
    /**
     * Traitement des données webhook
     */
    private function processWebhookData($data) {
        if (!isset($data['entry'])) {
            $this->log('No entry field in webhook data');
            return;
        }
        
        foreach ($data['entry'] as $entry) {
            if (!isset($entry['changes'])) {
                continue;
            }
            
            foreach ($entry['changes'] as $change) {
                if ($change['field'] !== 'messages') {
                    continue;
                }
                
                $this->processMessageChange($change['value']);
            }
        }
    }
    
    /**
     * Traitement des changements de messages
     */
    private function processMessageChange($value) {
        // Traiter les statuts de messages (delivered, read, failed)
        if (isset($value['statuses'])) {
            foreach ($value['statuses'] as $status) {
                $this->updateMessageStatus($status);
            }
        }
        
        // Traiter les messages entrants
        if (isset($value['messages'])) {
            foreach ($value['messages'] as $message) {
                $this->processIncomingMessage($message, $value['metadata'] ?? []);
            }
        }
    }
    
    /**
     * Mettre à jour le statut d'un message
     */
    private function updateMessageStatus($statusData) {
        $whatsappId = $statusData['id'];
        $status = $statusData['status']; // sent, delivered, read, failed
        $timestamp = $statusData['timestamp'] ?? time();
        
        $sql = "UPDATE messages 
                SET status = ?, 
                    updated_at = datetime('now'),
                    metadata = json_set(COALESCE(metadata, '{}'), '$.status_updated', ?)
                WHERE whatsapp_message_id = ?";
        
        $this->db->query($sql, [$status, date('Y-m-d H:i:s', $timestamp), $whatsappId]);
        
        $this->log('Message status updated', [
            'whatsapp_id' => $whatsappId,
            'status' => $status
        ]);
    }
    
    /**
     * Traitement d'un message entrant
     */
    private function processIncomingMessage($messageData, $metadata = []) {
        $from = $messageData['from'];
        $messageId = $messageData['id'];
        $timestamp = $messageData['timestamp'] ?? time();
        $type = $messageData['type'];
        
        // Extraire le contenu selon le type de message
        $content = $this->extractMessageContent($messageData);
        
        if (!$content) {
            $this->log('Unable to extract message content', $messageData);
            return;
        }
        
        // Trouver ou créer le membre
        $memberId = $this->findOrCreateMember($from, $metadata);
        
        // Sauvegarder le message entrant
        $localMessageId = $this->saveIncomingMessage($memberId, $content, $messageId, $type);
        
        // Générer et envoyer une réponse automatique
        $this->generateAndSendAutoReply($memberId, $from, $content, $localMessageId);
    }
    
    /**
     * Extraire le contenu d'un message selon son type
     */
    private function extractMessageContent($messageData) {
        $type = $messageData['type'];
        
        switch ($type) {
            case 'text':
                return $messageData['text']['body'] ?? null;
                
            case 'button':
                return $messageData['button']['text'] ?? 'Button: ' . ($messageData['button']['payload'] ?? '');
                
            case 'interactive':
                if (isset($messageData['interactive']['button_reply'])) {
                    return 'Button: ' . $messageData['interactive']['button_reply']['title'];
                } elseif (isset($messageData['interactive']['list_reply'])) {
                    return 'List: ' . $messageData['interactive']['list_reply']['title'];
                }
                return 'Interactive message';
                
            case 'image':
                return '[Image]' . ($messageData['image']['caption'] ?? '');
                
            case 'document':
                return '[Document: ' . ($messageData['document']['filename'] ?? 'fichier') . ']';
                
            case 'audio':
                return '[Message vocal]';
                
            case 'video':
                return '[Vidéo]' . ($messageData['video']['caption'] ?? '');
                
            case 'location':
                return '[Localisation partagée]';
                
            case 'contacts':
                return '[Contact partagé]';
                
            default:
                return "[Message de type: $type]";
        }
    }
    
    /**
     * Trouver ou créer un membre à partir du numéro WhatsApp
     */
    private function findOrCreateMember($phoneNumber, $metadata = []) {
        // Nettoyer le numéro
        $cleanPhone = preg_replace('/[^\d]/', '', $phoneNumber);
        
        // Chercher le membre existant
        $sql = "SELECT id FROM members WHERE telephone = ?";
        $result = $this->db->query($sql, [$cleanPhone])->fetch();
        
        if ($result) {
            return $result['id'];
        }
        
        // Vérifier si la création automatique est activée
        if (!$this->config['features']['member_auto_creation']) {
            $this->log('Auto member creation disabled', ['phone' => $cleanPhone]);
            return null;
        }
        
        // Créer un nouveau membre
        $profileName = $metadata['profile']['name'] ?? 'Utilisateur';
        $nameParts = explode(' ', $profileName, 2);
        $prenom = $nameParts[0] ?? 'Nouveau';
        $nom = $nameParts[1] ?? 'Membre';
        
        $sql = "INSERT INTO members (prenom, nom, telephone) VALUES (?, ?, ?)";
        $memberId = $this->db->insert($sql, [$prenom, $nom, $cleanPhone]);
        
        $this->log('New member created from WhatsApp', [
            'member_id' => $memberId,
            'phone' => $cleanPhone,
            'name' => $profileName
        ]);
        
        return $memberId;
    }
    
    /**
     * Sauvegarder un message entrant
     */
    private function saveIncomingMessage($memberId, $content, $whatsappId, $type) {
        $sql = "INSERT INTO messages (member_id, type, content, status, whatsapp_message_id, metadata) 
                VALUES (?, ?, ?, ?, ?, ?)";
        
        $metadata = json_encode([
            'message_type' => $type,
            'received_at' => date('Y-m-d H:i:s'),
            'processed_by_webhook' => true
        ]);
        
        return $this->db->insert($sql, [
            $memberId,
            'conversation_in',
            $content,
            'read',
            $whatsappId,
            $metadata
        ]);
    }
    
    /**
     * Générer et envoyer une réponse automatique
     */
    private function generateAndSendAutoReply($memberId, $phoneNumber, $incomingMessage, $incomingMessageId) {
        // Vérifier si les réponses automatiques sont activées
        if (!$this->config['features']['auto_reply_enabled']) {
            $this->log('Auto reply disabled', ['member_id' => $memberId]);
            return;
        }
        
        try {
            // Analyser l'intention du message (si Mistral AI activé)
            $intentAnalysis = null;
            $requiresHuman = false;
            $urgency = 'moyenne';
            
            if ($this->config['features']['mistral_ai_enabled']) {
                $intentAnalysis = $this->mistral->detectIntent($incomingMessage);
                
                if ($intentAnalysis['success']) {
                    $intent = $intentAnalysis['intent'];
                    $requiresHuman = $intent['requires_human'] ?? false;
                    $urgency = $intent['urgency'] ?? 'moyenne';
                    
                    $this->log('Message intent detected', [
                        'member_id' => $memberId,
                        'intent' => $intent['intent'] ?? 'unknown',
                        'urgency' => $urgency,
                        'requires_human' => $requiresHuman
                    ]);
                }
            }
            
            // Si urgence haute ou nécessite un humain, notifier l'équipe
            $urgencyThreshold = $this->config['notifications']['urgent_message_threshold'];
            if ($urgency === 'haute' || $requiresHuman || 
                ($urgency === $urgencyThreshold && $this->config['notifications']['enabled'])) {
                $this->notifyTeam($memberId, $incomingMessage, $urgency);
            }
            
            // Générer une réponse automatique (si Mistral AI activé)
            $response = null;
            if ($this->config['features']['mistral_ai_enabled']) {
                $autoReply = $this->mistral->generateAutoReply($incomingMessage, $memberId);
                
                if ($autoReply['success']) {
                    $response = $autoReply['response'];
                    
                    // Personnaliser la réponse selon l'analyse
                    if ($requiresHuman) {
                        $response .= "\n\nUn membre de notre équipe vous contactera bientôt. 👥";
                    }
                }
            }
            
            // Réponse de fallback si pas de Mistral AI ou échec
            if (!$response) {
                $associationName = $this->config['app']['name'];
                $response = "Bonjour ! Merci pour votre message à $associationName. Notre équipe vous répondra bientôt. 😊";
                
                $this->log('Using fallback response', [
                    'member_id' => $memberId,
                    'reason' => $this->config['features']['mistral_ai_enabled'] ? 'Mistral AI failed' : 'Mistral AI disabled'
                ]);
            }
            
            // Envoyer la réponse
            $sendResult = $this->whatsapp->sendText($phoneNumber, $response);
            
            if ($sendResult['success']) {
                $this->log('Auto reply sent successfully', [
                    'member_id' => $memberId,
                    'message_id' => $sendResult['message_id'],
                    'method' => $this->config['features']['mistral_ai_enabled'] ? 'mistral_ai' : 'fallback'
                ]);
            } else {
                $this->logError('Failed to send auto reply', [
                    'member_id' => $memberId,
                    'error' => $sendResult['error']
                ]);
            }
            
        } catch (Exception $e) {
            $this->logError('Error in auto reply generation', [
                'member_id' => $memberId,
                'error' => $e->getMessage()
            ]);
            
            // Réponse d'urgence en cas d'erreur critique
            $emergencyResponse = "Merci pour votre message ! Nous vous répondrons rapidement. 🙏";
            $this->whatsapp->sendText($phoneNumber, $emergencyResponse);
        }
    }
    
    /**
     * Notifier l'équipe pour les messages urgents
     */
    private function notifyTeam($memberId, $message, $urgency) {
        // Vérifier si les notifications sont activées
        if (!$this->config['features']['notifications_enabled']) {
            return;
        }
        
        // Obtenir les informations du membre
        $sql = "SELECT prenom, nom, telephone FROM members WHERE id = ?";
        $member = $this->db->query($sql, [$memberId])->fetch();
        
        if (!$member) return;
        
        // Créer une notification pour l'équipe
        $notification = [
            'type' => 'urgent_message',
            'urgency' => $urgency,
            'member' => $member,
            'message' => $message,
            'timestamp' => date('Y-m-d H:i:s'),
            'app' => $this->config['app']['name']
        ];
        
        // Sauvegarder la notification en base
        $sql = "INSERT INTO messages (member_id, type, content, status, metadata) 
                VALUES (?, ?, ?, ?, ?)";
        
        $this->db->insert($sql, [
            $memberId,
            'notification',
            "Message urgent: $message",
            'pending',
            json_encode($notification)
        ]);
        
        $this->log('Team notification created', $notification);
        
        // Traiter les méthodes de notification configurées
        $methods = $this->config['notifications']['team_notification_methods'];
        foreach ($methods as $method) {
            switch ($method) {
                case 'email':
                    $this->sendTeamEmail($notification);
                    break;
                case 'sms':
                    $this->sendTeamSMS($notification);
                    break;
                case 'database':
                    // Déjà fait ci-dessus
                    break;
            }
        }
    }
    
    /**
     * Logging des activités webhook selon la configuration
     */
    private function log($message, $data = null) {
        if (!$this->config['logging']['enabled']) {
            return;
        }
        
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'service' => 'WebhookHandler',
            'message' => $message
        ];
        
        if ($data) {
            $logEntry['data'] = $data;
        }
        
        error_log('Polaris Webhook: ' . json_encode($logEntry));
    }
    
    /**
     * Logger les erreurs selon la configuration
     */
    private function logError($type, $data) {
        if ($this->config['logging']['enabled']) {
            $logEntry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'type' => $type,
                'service' => 'WebhookHandler',
                'level' => 'ERROR',
                'data' => $data
            ];
            
            error_log('Polaris Webhook ERROR: ' . json_encode($logEntry));
        }
    }
    
    /**
     * Logger les requêtes pour debug
     */
    private function logRequest() {
        $logData = [
            'method' => $_SERVER['REQUEST_METHOD'],
            'uri' => $_SERVER['REQUEST_URI'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? ''
        ];
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $input = file_get_contents('php://input');
            $logData['body_size'] = strlen($input);
            // Ne pas logger le contenu complet pour éviter les logs trop volumineux
        }
        
        $this->log('Webhook request', $logData);
    }
    
    /**
     * Obtenir les statistiques du webhook
     */
    public function getStats() {
        $stats = [];
        
        // Messages reçus aujourd'hui
        $sql = "SELECT COUNT(*) as count FROM messages 
                WHERE type = 'conversation_in' 
                AND DATE(created_at) = DATE('now')";
        $result = $this->db->query($sql)->fetch();
        $stats['messages_today'] = $result['count'];
        
        // Réponses automatiques envoyées
        $sql = "SELECT COUNT(*) as count FROM messages 
                WHERE type = 'conversation_out' 
                AND DATE(created_at) = DATE('now')
                AND json_extract(metadata, '$.auto_reply') = 1";
        $result = $this->db->query($sql)->fetch();
        $stats['auto_replies_today'] = $result['count'] ?? 0;
        
        // Messages urgents
        $sql = "SELECT COUNT(*) as count FROM messages 
                WHERE type = 'notification' 
                AND DATE(created_at) = DATE('now')";
        $result = $this->db->query($sql)->fetch();
        $stats['urgent_messages_today'] = $result['count'];
        
        // Nouveaux membres créés via WhatsApp
        $sql = "SELECT COUNT(*) as count FROM members 
                WHERE DATE(created_at) = DATE('now')";
        $result = $this->db->query($sql)->fetch();
        $stats['new_members_today'] = $result['count'];
        
        return $stats;
    }
    
    /**
     * Interface de test du webhook
     */
    public function showTestInterface() {
        if (!isset($_GET['test'])) {
            return;
        }
        
        echo "<h2>🔗 Test Webhook Polaris</h2>";
        
        // Afficher la configuration
        echo "<h3>Configuration</h3>";
        echo "<p><strong>Application:</strong> " . $this->config['app']['name'] . " v" . $this->config['app']['version'] . "</p>";
        echo "<p><strong>Environnement:</strong> " . $this->config['app']['environment'] . "</p>";
        echo "<p><strong>URL du webhook:</strong> " . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'] . "</p>";
        echo "<p><strong>Token de vérification:</strong> " . $this->verifyToken . "</p>";
        
        // État des fonctionnalités
        echo "<h3>Fonctionnalités</h3>";
        $features = $this->config['features'];
        foreach ($features as $feature => $enabled) {
            $status = $enabled ? '✅' : '❌';
            echo "<p>$status " . ucfirst(str_replace('_', ' ', $feature)) . "</p>";
        }
        
        // Statistiques
        echo "<h3>Statistiques du jour</h3>";
        $stats = $this->getStats();
        foreach ($stats as $key => $value) {
            echo "<p>" . ucfirst(str_replace('_', ' ', $key)) . ": " . $value . "</p>";
        }
        
        // Simulateur de message entrant
        if ($_POST && isset($_POST['simulate'])) {
            echo "<h3>Simulation de message entrant</h3>";
            $testPhone = $_POST['test_phone'] ?? '221771234567';
            $testMessage = $_POST['test_message'] ?? 'Bonjour test';
            
            echo "<p><strong>Numéro:</strong> " . htmlspecialchars($testPhone) . "</p>";
            echo "<p><strong>Message:</strong> " . htmlspecialchars($testMessage) . "</p>";
            
            // Simuler les données webhook
            $simulatedData = [
                'entry' => [[
                    'changes' => [[
                        'field' => 'messages',
                        'value' => [
                            'messages' => [[
                                'from' => $testPhone,
                                'id' => 'test_' . time(),
                                'timestamp' => time(),
                                'type' => 'text',
                                'text' => ['body' => $testMessage]
                            ]],
                            'metadata' => [
                                'profile' => ['name' => 'Test User ' . date('H:i')]
                            ]
                        ]
                    ]]
                ]]
            ];
            
            try {
                $this->processWebhookData($simulatedData);
                echo "<p style='color: green;'>✅ Message simulé traité avec succès !</p>";
                
                if ($this->config['features']['member_auto_creation']) {
                    echo "<p>→ Membre créé ou trouvé automatiquement</p>";
                }
                if ($this->config['features']['auto_reply_enabled']) {
                    echo "<p>→ Réponse automatique générée</p>";
                }
                if ($this->config['features']['mistral_ai_enabled']) {
                    echo "<p>→ Analyse Mistral AI effectuée</p>";
                } else {
                    echo "<p>→ Réponse de fallback utilisée (Mistral AI désactivé)</p>";
                }
                
            } catch (Exception $e) {
                echo "<p style='color: red;'>❌ Erreur lors de la simulation: " . htmlspecialchars($e->getMessage()) . "</p>";
            }
        }
        
        echo "<h3>Simuler un message entrant</h3>";
        echo "<form method='post'>";
        echo "<p><label>Numéro WhatsApp:</label><br><input type='tel' name='test_phone' value='221771234567' style='width: 200px;'></p>";
        echo "<p><label>Message:</label><br><textarea name='test_message' style='width: 100%; height: 80px;'>Bonjour, j'ai une question sur l'association.</textarea></p>";
        echo "<p><button type='submit' name='simulate' value='1' style='background: #25D366; color: white; padding: 10px 20px; border: none; border-radius: 5px;'>📱 Simuler réception</button></p>";
        echo "</form>";
        
        // Informations de configuration pour Meta
        echo "<h3>Configuration Meta Developer Console</h3>";
        echo "<div style='background: #f0f0f0; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
        echo "<p><strong>Webhook URL:</strong></p>";
        echo "<code>https://votre-domaine.com" . str_replace('?test', '', $_SERVER['REQUEST_URI']) . "</code>";
        echo "<p style='margin-top: 10px;'><strong>Verify Token:</strong></p>";
        echo "<code>" . $this->verifyToken . "</code>";
        echo "</div>";
    }
}

// Point d'entrée
$webhook = new WebhookHandler();

// Interface de test si demandée
if (isset($_GET['test'])) {
    $webhook->showTestInterface();
} else {
    // Traitement normal du webhook
    $webhook->handle();
}
?>