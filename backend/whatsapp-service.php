<?php
/**
 * Polaris CRM - Service WhatsApp Business API
 * Gestion de l'envoi de messages via l'API WhatsApp Business
 */

require_once 'database.php';

class WhatsAppService {
    private $db;
    private $config;
    private $accessToken;
    private $phoneNumberId;
    private $apiVersion;
    private $baseUrl;
    
    public function __construct() {
        $this->db = new Database();
        
        // Charger la configuration
        $this->config = require __DIR__ . '/config.php';
        
        // Configuration WhatsApp depuis config.php
        $this->accessToken = $this->config['whatsapp']['access_token'];
        $this->phoneNumberId = $this->config['whatsapp']['phone_number_id'];
        $this->apiVersion = $this->config['whatsapp']['api_version'];
        $this->baseUrl = $this->config['whatsapp']['base_url'];
    }
    
    /**
     * Envoyer un message template WhatsApp
     */
    public function sendTemplate($to, $templateName, $languageCode = 'en_US', $parameters = []) {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->cleanPhoneNumber($to),
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => [
                    'code' => $languageCode
                ]
            ]
        ];
        
        // Ajouter des paramètres si fournis
        if (!empty($parameters)) {
            $payload['template']['components'] = [
                [
                    'type' => 'body',
                    'parameters' => array_map(function($param) {
                        return ['type' => 'text', 'text' => $param];
                    }, $parameters)
                ]
            ];
        }
        
        return $this->sendMessage($payload, $to);
    }
    
    /**
     * Envoyer un message texte libre
     */
    public function sendText($to, $message) {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->cleanPhoneNumber($to),
            'type' => 'text',
            'text' => [
                'body' => $message
            ]
        ];
        
        return $this->sendMessage($payload, $to);
    }
    
    /**
     * Envoyer un message interactif avec boutons
     */
    public function sendInteractive($to, $text, $buttons) {
        $payload = [
            'messaging_product' => 'whatsapp',
            'to' => $this->cleanPhoneNumber($to),
            'type' => 'interactive',
            'interactive' => [
                'type' => 'button',
                'body' => [
                    'text' => $text
                ],
                'action' => [
                    'buttons' => array_map(function($button, $index) {
                        return [
                            'type' => 'reply',
                            'reply' => [
                                'id' => 'btn_' . $index,
                                'title' => substr($button, 0, 20) // Max 20 caractères
                            ]
                        ];
                    }, $buttons, array_keys($buttons))
                ]
            ]
        ];
        
        return $this->sendMessage($payload, $to);
    }
    
    /**
     * Envoyer un message à plusieurs destinataires
     */
    public function sendBulkMessage($recipients, $messageType, $messageData) {
        $results = [];
        $successCount = 0;
        $errorCount = 0;
        
        foreach ($recipients as $recipient) {
            try {
                $result = null;
                
                switch ($messageType) {
                    case 'template':
                        $result = $this->sendTemplate(
                            $recipient,
                            $messageData['template'],
                            $messageData['language'] ?? 'en_US',
                            $messageData['parameters'] ?? []
                        );
                        break;
                        
                    case 'text':
                        $result = $this->sendText($recipient, $messageData['text']);
                        break;
                        
                    case 'interactive':
                        $result = $this->sendInteractive(
                            $recipient,
                            $messageData['text'],
                            $messageData['buttons']
                        );
                        break;
                        
                    default:
                        throw new Exception('Type de message non supporté');
                }
                
                if ($result['success']) {
                    $successCount++;
                } else {
                    $errorCount++;
                }
                
                $results[] = [
                    'recipient' => $recipient,
                    'success' => $result['success'],
                    'message_id' => $result['message_id'] ?? null,
                    'error' => $result['error'] ?? null
                ];
                
                // Petit délai pour éviter le rate limiting
                usleep(100000); // 100ms
                
            } catch (Exception $e) {
                $errorCount++;
                $results[] = [
                    'recipient' => $recipient,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return [
            'success' => $errorCount === 0,
            'total' => count($recipients),
            'success_count' => $successCount,
            'error_count' => $errorCount,
            'results' => $results
        ];
    }
    
    /**
     * Fonction principale d'envoi de message
     */
    private function sendMessage($payload, $to) {
        $url = $this->baseUrl . '/' . $this->apiVersion . '/' . $this->phoneNumberId . '/messages';
        
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json'
        ];
        
        try {
            // Vérifier si le membre existe, sinon le créer ou ignorer la sauvegarde
            $memberId = $this->getMemberIdByPhone($to);
            
            if (!$memberId) {
                // Option 1: Créer un membre temporaire si activé
                if ($this->config['features']['member_auto_creation']) {
                    $memberId = $this->createTemporaryMember($to);
                }
                // Option 2: Alternative - ne pas sauvegarder en base pour les numéros inconnus
                // Dans ce cas, on envoie quand même le message mais on ne le sauvegarde pas
            }
            
            // Enregistrer le message en base comme "pending" seulement si on a un membre
            $messageId = null;
            if ($memberId) {
                $messageId = $this->saveMessage($memberId, 'push_out', $payload, 'pending');
            }
            
            // Envoyer via cURL
            $response = $this->makeRequest($url, $payload, $headers);
            
            if ($response['success']) {
                // Mettre à jour le statut en "sent" si on a sauvegardé le message
                $whatsappMessageId = $response['data']['messages'][0]['id'] ?? null;
                if ($messageId) {
                    $this->updateMessageStatus($messageId, 'sent', $whatsappMessageId);
                }
                
                return [
                    'success' => true,
                    'message_id' => $whatsappMessageId,
                    'local_id' => $messageId
                ];
            } else {
                // Marquer comme "failed" si on a sauvegardé le message
                if ($messageId) {
                    $this->updateMessageStatus($messageId, 'failed', null, $response['error']);
                }
                
                return [
                    'success' => false,
                    'error' => $response['error'],
                    'local_id' => $messageId
                ];
            }
            
        } catch (Exception $e) {
            $this->logError('WhatsApp Send Error', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Effectuer une requête cURL
     */
    private function makeRequest($url, $payload, $headers) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => !$this->config['app']['debug'], // SSL selon environnement
            CURLOPT_SSL_VERIFYHOST => !$this->config['app']['debug'],
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => $this->config['app']['name'] . ' WhatsApp Service/' . $this->config['app']['version']
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            $this->logError('cURL Error', ['error' => $error, 'url' => $url]);
            throw new Exception('Erreur cURL: ' . $error);
        }
        
        $data = json_decode($response, true);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            return [
                'success' => true,
                'data' => $data
            ];
        } else {
            $errorMessage = 'Erreur HTTP ' . $httpCode;
            if (isset($data['error']['message'])) {
                $errorMessage .= ': ' . $data['error']['message'];
            }
            
            $this->logError('API Error', [
                'http_code' => $httpCode,
                'error' => $errorMessage,
                'response' => $data
            ]);
            
            return [
                'success' => false,
                'error' => $errorMessage,
                'data' => $data
            ];
        }
    }
    
    /**
     * Nettoyer un numéro de téléphone
     */
    private function cleanPhoneNumber($phone) {
        return preg_replace('/[^\d]/', '', $phone);
    }
    
    /**
     * Logger des erreurs selon la configuration
     */
    private function logError($type, $data) {
        if ($this->config['logging']['enabled']) {
            $logEntry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'type' => $type,
                'service' => 'WhatsAppService',
                'data' => $data
            ];
            
            error_log('Polaris WhatsApp: ' . json_encode($logEntry));
        }
    }
    
    /**
     * Créer un membre temporaire pour les tests
     */
    private function createTemporaryMember($phone) {
        $cleanPhone = $this->cleanPhoneNumber($phone);
        
        // Vérifier si la création automatique est activée
        if (!$this->config['features']['member_auto_creation']) {
            return null;
        }
        
        $sql = "INSERT INTO members (prenom, nom, telephone) VALUES (?, ?, ?)";
        return $this->db->insert($sql, [
            'Test',
            'Utilisateur',
            $cleanPhone
        ]);
    }
    
    /**
     * Obtenir l'ID d'un membre par son numéro
     */
    private function getMemberIdByPhone($phone) {
        $cleanPhone = $this->cleanPhoneNumber($phone);
        $sql = "SELECT id FROM members WHERE telephone = ?";
        $result = $this->db->query($sql, [$cleanPhone])->fetch();
        return $result ? $result['id'] : null;
    }
    
    /**
     * Sauvegarder un message en base
     */
    private function saveMessage($memberId, $type, $content, $status = 'pending', $whatsappId = null) {
        $sql = "INSERT INTO messages (member_id, type, content, status, whatsapp_message_id, metadata) 
                VALUES (?, ?, ?, ?, ?, ?)";
        
        $metadata = json_encode([
            'sent_at' => date('Y-m-d H:i:s'),
            'api_payload' => $content
        ]);
        
        return $this->db->insert($sql, [
            $memberId,
            $type,
            is_array($content) ? json_encode($content) : $content,
            $status,
            $whatsappId,
            $metadata
        ]);
    }
    
    /**
     * Mettre à jour le statut d'un message
     */
    private function updateMessageStatus($messageId, $status, $whatsappId = null, $error = null) {
        $sql = "UPDATE messages SET status = ?, updated_at = datetime('now')";
        $params = [$status];
        
        if ($whatsappId) {
            $sql .= ", whatsapp_message_id = ?";
            $params[] = $whatsappId;
        }
        
        if ($error) {
            $sql .= ", metadata = json_set(COALESCE(metadata, '{}'), '$.error', ?)";
            $params[] = $error;
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $messageId;
        
        $this->db->query($sql, $params);
    }
    
    /**
     * Obtenir l'historique des messages d'un membre
     */
    public function getMessageHistory($memberId, $limit = 50) {
        $sql = "SELECT * FROM messages 
                WHERE member_id = ? 
                ORDER BY created_at DESC 
                LIMIT ?";
        
        return $this->db->query($sql, [$memberId, $limit])->fetchAll();
    }
    
    /**
     * Obtenir les statistiques des messages
     */
    public function getMessageStats() {
        $stats = [];
        
        $statuses = ['pending', 'sent', 'delivered', 'read', 'failed'];
        foreach ($statuses as $status) {
            $sql = "SELECT COUNT(*) as count FROM messages WHERE status = ?";
            $result = $this->db->query($sql, [$status])->fetch();
            $stats[$status] = $result['count'];
        }
        
        // Messages des dernières 24h
        $sql = "SELECT COUNT(*) as count FROM messages 
                WHERE created_at >= datetime('now', '-24 hours')";
        $result = $this->db->query($sql)->fetch();
        $stats['last_24h'] = $result['count'];
        
        return $stats;
    }
    
    /**
     * Valider la configuration WhatsApp
     */
    public function validateConfig() {
        if (empty($this->accessToken) || empty($this->phoneNumberId)) {
            return [
                'valid' => false,
                'error' => 'Token d\'accès ou Phone Number ID manquant dans config.php'
            ];
        }
        
        // Test avec un appel à l'API (récupérer les infos du numéro)
        $url = $this->baseUrl . '/' . $this->apiVersion . '/' . $this->phoneNumberId;
        $headers = ['Authorization: Bearer ' . $this->accessToken];
        
        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => !$this->config['app']['debug'], // SSL selon environnement
                CURLOPT_SSL_VERIFYHOST => !$this->config['app']['debug']
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            
            if ($curlError) {
                return [
                    'valid' => false,
                    'error' => 'Erreur cURL: ' . $curlError
                ];
            }
            
            if ($httpCode === 200) {
                $data = json_decode($response, true);
                return [
                    'valid' => true,
                    'phone_number' => $data['display_phone_number'] ?? 'N/A',
                    'status' => $data['verified_name'] ?? 'N/A',
                    'environment' => $this->config['app']['environment']
                ];
            } else {
                $data = json_decode($response, true);
                $error = isset($data['error']['message']) ? $data['error']['message'] : 'Erreur HTTP ' . $httpCode;
                return [
                    'valid' => false,
                    'error' => $error
                ];
            }
            
        } catch (Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Traiter les webhooks WhatsApp (statuts de messages)
     */
    public function processWebhook($webhookData) {
        try {
            if (!isset($webhookData['entry'])) {
                throw new Exception('Données webhook invalides');
            }
            
            foreach ($webhookData['entry'] as $entry) {
                if (!isset($entry['changes'])) continue;
                
                foreach ($entry['changes'] as $change) {
                    if ($change['field'] !== 'messages') continue;
                    
                    $value = $change['value'];
                    
                    // Traiter les statuts de messages
                    if (isset($value['statuses'])) {
                        foreach ($value['statuses'] as $status) {
                            $this->updateMessageStatusFromWebhook($status);
                        }
                    }
                    
                    // Traiter les messages entrants (pour plus tard avec Mistral AI)
                    if (isset($value['messages'])) {
                        foreach ($value['messages'] as $message) {
                            $this->processIncomingMessage($message);
                        }
                    }
                }
            }
            
            return ['success' => true];
            
        } catch (Exception $e) {
            $this->logError('Webhook Processing Error', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Mettre à jour le statut d'un message depuis le webhook
     */
    private function updateMessageStatusFromWebhook($statusData) {
        $whatsappId = $statusData['id'];
        $status = $statusData['status']; // sent, delivered, read, failed
        
        $sql = "UPDATE messages 
                SET status = ?, updated_at = datetime('now') 
                WHERE whatsapp_message_id = ?";
        
        $this->db->query($sql, [$status, $whatsappId]);
    }
    
    /**
     * Traiter un message entrant (pour Mistral AI plus tard)
     */
    private function processIncomingMessage($messageData) {
        $from = $messageData['from'];
        $messageType = $messageData['type'];
        $content = '';
        
        switch ($messageType) {
            case 'text':
                $content = $messageData['text']['body'];
                break;
            case 'button':
                $content = $messageData['button']['text'];
                break;
            default:
                $content = 'Message de type: ' . $messageType;
        }
        
        // Sauvegarder le message entrant
        $memberId = $this->getMemberIdByPhone($from);
        if ($memberId) {
            $this->saveMessage($memberId, 'conversation_in', $content, 'read', $messageData['id']);
        }
        
        // TODO: Intégrer Mistral AI ici pour générer une réponse automatique
    }
}

// Point d'entrée pour test direct
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    echo "<h2>🔧 Test WhatsApp Service</h2>";
    
    try {
        $whatsapp = new WhatsAppService();
        $config = $whatsapp->config ?? [];
        
        // Afficher la configuration
        echo "<h3>Configuration chargée</h3>";
        echo "<p>Environnement: " . ($config['app']['environment'] ?? 'N/A') . "</p>";
        echo "<p>Version: " . ($config['app']['name'] ?? 'N/A') . " v" . ($config['app']['version'] ?? 'N/A') . "</p>";
        echo "<p>Debug activé: " . (($config['app']['debug'] ?? false) ? 'Oui' : 'Non') . "</p>";
        
        // Validation de la config
        echo "<h3>Configuration WhatsApp</h3>";
        $configTest = $whatsapp->validateConfig();
        if ($configTest['valid']) {
            echo "<p style='color: green;'>✅ Configuration valide</p>";
            echo "<p>Numéro: " . $configTest['phone_number'] . "</p>";
            echo "<p>Statut: " . $configTest['status'] . "</p>";
            echo "<p>Environnement: " . ($configTest['environment'] ?? 'N/A') . "</p>";
        } else {
            echo "<p style='color: red;'>❌ " . $configTest['error'] . "</p>";
        }
        
        // Statistiques des messages
        echo "<h3>Statistiques</h3>";
        $stats = $whatsapp->getMessageStats();
        foreach ($stats as $key => $value) {
            echo "<p>" . ucfirst(str_replace('_', ' ', $key)) . ": " . $value . "</p>";
        }
        
        // Formulaire de test (optionnel)
        if ($_POST && isset($_POST['test_number']) && isset($_POST['test_message'])) {
            echo "<h3>Test d'envoi</h3>";
            $result = $whatsapp->sendText($_POST['test_number'], $_POST['test_message']);
            if ($result['success']) {
                echo "<p style='color: green;'>✅ Message envoyé ! ID: " . $result['message_id'] . "</p>";
            } else {
                echo "<p style='color: red;'>❌ Erreur: " . $result['error'] . "</p>";
            }
        }
        
        // Vérifier les fonctionnalités activées
        echo "<h3>Fonctionnalités</h3>";
        $features = $config['features'] ?? [];
        foreach ($features as $feature => $enabled) {
            $status = $enabled ? '✅' : '❌';
            echo "<p>$status " . ucfirst(str_replace('_', ' ', $feature)) . "</p>";
        }
        
        echo "<h3>Test d'envoi</h3>";
        echo "<form method='post'>";
        echo "<p><input type='tel' name='test_number' placeholder='221771035832' required></p>";
        echo "<p><textarea name='test_message' placeholder='Message de test...' required></textarea></p>";
        echo "<p><button type='submit'>Envoyer test</button></p>";
        echo "</form>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erreur: " . $e->getMessage() . "</p>";
        echo "<p>Vérifiez que le fichier config.php existe dans le dossier backend.</p>";
    }
}
?>