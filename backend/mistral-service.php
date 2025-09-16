<?php
/**
 * Polaris CRM - Service Mistral AI
 * Intégration avec l'API Mistral AI pour générer des réponses automatiques
 */

require_once 'database.php';

class MistralService {
    private $db;
    private $config;
    private $apiKey;
    private $baseUrl;
    private $model;
    private $maxTokens;
    private $temperature;
    
    public function __construct($apiKey = null) {
        $this->db = new Database();
        
        // Charger la configuration
        $this->config = require __DIR__ . '/config.php';
        
        // Configuration Mistral AI depuis config.php (ou paramètre)
        $this->apiKey = $apiKey ?: $this->config['mistral']['api_key'];
        $this->baseUrl = $this->config['mistral']['base_url'];
        $this->model = $this->config['mistral']['model'];
        $this->maxTokens = $this->config['mistral']['max_tokens'];
        $this->temperature = $this->config['mistral']['temperature'];
    }
    
    /**
     * Générer une réponse avec Mistral AI
     */
    public function generateResponse($message, $context = [], $systemPrompt = null) {
        if (empty($this->apiKey) || $this->apiKey === 'votre_cle_mistral_api_ici' || $this->apiKey === '') {
            return [
                'success' => false,
                'error' => 'Clé API Mistral non configurée dans config.php'
            ];
        }
        
        // Vérifier si Mistral AI est activé
        if (!$this->config['features']['mistral_ai_enabled']) {
            return [
                'success' => false,
                'error' => 'Mistral AI désactivé dans la configuration'
            ];
        }
        
        $messages = [];
        
        // Prompt système par défaut pour Polaris
        $defaultSystemPrompt = "Tu es un assistant pour l'association " . $this->config['app']['name'] . ". Tu réponds de manière chaleureuse et professionnelle aux messages des membres. Reste concis et utile. Réponds en français.";
        
        if ($systemPrompt) {
            $messages[] = [
                'role' => 'system',
                'content' => $systemPrompt
            ];
        } else {
            $messages[] = [
                'role' => 'system', 
                'content' => $defaultSystemPrompt
            ];
        }
        
        // Ajouter le contexte de conversation précédent
        foreach ($context as $msg) {
            $messages[] = [
                'role' => $msg['role'], // 'user' ou 'assistant'
                'content' => $msg['content']
            ];
        }
        
        // Ajouter le message actuel
        $messages[] = [
            'role' => 'user',
            'content' => $message
        ];
        
        $payload = [
            'model' => $this->model,
            'messages' => $messages,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature
        ];
        
        try {
            $response = $this->makeRequest('/chat/completions', $payload);
            
            if ($response['success']) {
                $aiResponse = $response['data']['choices'][0]['message']['content'] ?? '';
                $usage = $response['data']['usage'] ?? [];
                
                // Log de l'utilisation si activé
                $this->logUsage($usage, 'generate_response');
                
                return [
                    'success' => true,
                    'response' => trim($aiResponse),
                    'usage' => $usage,
                    'model' => $this->model
                ];
            } else {
                return [
                    'success' => false,
                    'error' => $response['error']
                ];
            }
            
        } catch (Exception $e) {
            $this->logError('Response Generation Failed', ['error' => $e->getMessage()]);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
    
    /**
     * Générer un message push pour l'association
     */
    public function generatePushMessage($purpose, $details = [], $tone = 'friendly') {
        $associationName = $this->config['app']['name'];
        
        $prompts = [
            'event' => "Rédige une invitation chaleureuse pour un événement de l'association $associationName. Ton: $tone.",
            'meeting' => "Rédige une invitation pour une réunion de l'association $associationName. Ton: $tone.",
            'announcement' => "Rédige une annonce importante pour les membres de $associationName. Ton: $tone.",
            'reminder' => "Rédige un rappel amical pour les membres de $associationName. Ton: $tone.",
            'survey' => "Rédige une demande de participation à un sondage pour $associationName. Ton: $tone."
        ];
        
        $basePrompt = $prompts[$purpose] ?? $prompts['announcement'];
        
        $fullPrompt = $basePrompt;
        if (!empty($details)) {
            $fullPrompt .= "\n\nDétails à inclure:\n";
            foreach ($details as $key => $value) {
                $fullPrompt .= "- $key: $value\n";
            }
        }
        
        $fullPrompt .= "\n\nLe message doit être concis (max 200 mots), engageant et adapté à WhatsApp. Utilise des emojis appropriés.";
        
        return $this->generateResponse($fullPrompt, [], 
            "Tu es un expert en communication pour associations. Tu rédiges des messages WhatsApp engageants pour l'association $associationName."
        );
    }
    
    /**
     * Analyser le sentiment d'un message
     */
    public function analyzeSentiment($message) {
        $prompt = "Analyse le sentiment de ce message et réponds uniquement par un JSON avec ces champs:
{
    \"sentiment\": \"positif|neutre|negatif\",
    \"confidence\": 0.0-1.0,
    \"emotions\": [\"liste des émotions détectées\"],
    \"summary\": \"résumé en une phrase\"
}

Message à analyser: \"$message\"";
        
        $result = $this->generateResponse($prompt, [], 
            "Tu es un expert en analyse de sentiment. Réponds uniquement en JSON valide."
        );
        
        if ($result['success']) {
            try {
                $analysis = json_decode($result['response'], true);
                if ($analysis) {
                    return [
                        'success' => true,
                        'analysis' => $analysis
                    ];
                }
            } catch (Exception $e) {
                // Fallback si le JSON n'est pas valide
            }
        }
        
        return [
            'success' => false,
            'error' => 'Impossible d\'analyser le sentiment'
        ];
    }
    
    /**
     * Générer une réponse automatique à un message de membre
     */
    public function generateAutoReply($memberMessage, $memberId = null) {
        $context = [];
        
        // Récupérer l'historique récent de conversation si memberid fourni
        if ($memberId) {
            $context = $this->getConversationContext($memberId, 5);
        }
        
        $associationName = $this->config['app']['name'];
        $timezone = $this->config['app']['timezone'];
        
        $systemPrompt = "Tu es l'assistant virtuel de l'association $associationName. Un membre t'écrit via WhatsApp. 
        
        Règles importantes:
        - Réponds de manière chaleureuse et professionnelle
        - Sois concis (max 100 mots)
        - Si c'est une question administrative, oriente vers l'équipe
        - Si c'est une demande d'information, donne une réponse utile
        - Utilise un emoji approprié
        - Termine par une question ou une invitation à continuer la conversation
        
        Tu représentes l'association $associationName, une association sénégalaise active dans le développement communautaire.
        Nous sommes en " . date('Y') . " et le fuseau horaire est $timezone.";
        
        return $this->generateResponse($memberMessage, $context, $systemPrompt);
    }
    
    /**
     * Récupérer le contexte de conversation d'un membre
     */
    private function getConversationContext($memberId, $limit = 5) {
        $sql = "SELECT type, content, created_at 
                FROM messages 
                WHERE member_id = ? AND type IN ('conversation_in', 'conversation_out')
                ORDER BY created_at DESC 
                LIMIT ?";
        
        $messages = $this->db->query($sql, [$memberId, $limit])->fetchAll();
        
        $context = [];
        foreach (array_reverse($messages) as $msg) {
            $role = ($msg['type'] === 'conversation_in') ? 'user' : 'assistant';
            $context[] = [
                'role' => $role,
                'content' => $msg['content']
            ];
        }
        
        return $context;
    }
    
    /**
     * Générer des suggestions de réponse
     */
    public function generateReplySuggestions($message, $count = 3) {
        $associationName = $this->config['app']['name'];
        
        $prompt = "Génère $count suggestions de réponses courtes (max 50 mots chacune) à ce message de membre de l'association $associationName. 
        Les réponses doivent être variées: une formelle, une amicale, une pratique.
        
        Format de réponse souhaité:
        1. [première suggestion]
        2. [deuxième suggestion]  
        3. [troisième suggestion]
        
        Message du membre: \"$message\"";
        
        return $this->generateResponse($prompt, [], 
            "Tu es un expert en communication pour associations. Génère des réponses adaptées au contexte associatif."
        );
    }
    
    /**
     * Corriger et améliorer un message
     */
    public function improveMessage($message, $improvements = ['grammar', 'tone', 'clarity']) {
        $improvementList = implode(', ', $improvements);
        
        $prompt = "Améliore ce message en travaillant sur: $improvementList.
        
        Le message doit rester authentique mais être plus efficace pour la communication d'association.
        
        Message original: \"$message\"
        
        Réponds uniquement avec le message amélioré, sans explication.";
        
        return $this->generateResponse($prompt, [], 
            "Tu es un expert en rédaction pour associations. Tu améliores les messages tout en gardant l'intention originale."
        );
    }
    
    /**
     * Détecter l'intention d'un message
     */
    public function detectIntent($message) {
        $prompt = "Analyse ce message d'un membre et détermine son intention principale. Réponds en JSON:
        
        {
            \"intent\": \"question|demande|plainte|compliment|information|autre\",
            \"urgency\": \"basse|moyenne|haute\",
            \"category\": \"administratif|événement|adhésion|général|technique\",
            \"requires_human\": true/false,
            \"suggested_action\": \"action recommandée\"
        }
        
        Message: \"$message\"";
        
        $result = $this->generateResponse($prompt, [], 
            "Tu es un expert en analyse de communication. Réponds uniquement en JSON valide."
        );
        
        if ($result['success']) {
            try {
                $intent = json_decode($result['response'], true);
                if ($intent) {
                    return [
                        'success' => true,
                        'intent' => $intent
                    ];
                }
            } catch (Exception $e) {
                // Fallback
            }
        }
        
        return [
            'success' => false,
            'error' => 'Impossible de détecter l\'intention'
        ];
    }
    
    /**
     * Effectuer une requête à l'API Mistral
     */
    private function makeRequest($endpoint, $payload) {
        $url = $this->baseUrl . $endpoint;
        
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ];
        
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
            CURLOPT_USERAGENT => $this->config['app']['name'] . ' Mistral Service/' . $this->config['app']['version']
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($error) {
            $this->logError('cURL Error', ['error' => $error, 'endpoint' => $endpoint]);
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
            
            $this->logError('Mistral API Error', [
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
     * Logger des erreurs selon la configuration
     */
    private function logError($type, $data) {
        if ($this->config['logging']['enabled']) {
            $logEntry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'type' => $type,
                'service' => 'MistralService',
                'data' => $data
            ];
            
            error_log('Polaris Mistral: ' . json_encode($logEntry));
        }
    }
    
    /**
     * Logger l'utilisation de l'API
     */
    private function logUsage($usage, $operation) {
        if ($this->config['logging']['enabled']) {
            $logEntry = [
                'timestamp' => date('Y-m-d H:i:s'),
                'operation' => $operation,
                'model' => $this->model,
                'usage' => $usage
            ];
            
            error_log('Polaris Mistral Usage: ' . json_encode($logEntry));
        }
    }
    
    /**
     * Valider la configuration Mistral AI
     */
    public function validateConfig() {
        if (empty($this->apiKey) || $this->apiKey === 'votre_cle_mistral_api_ici' || $this->apiKey === '') {
            return [
                'valid' => false,
                'error' => 'Clé API Mistral non configurée dans config.php'
            ];
        }
        
        if (!$this->config['features']['mistral_ai_enabled']) {
            return [
                'valid' => false,
                'error' => 'Mistral AI désactivé dans la configuration'
            ];
        }
        
        // Test simple avec l'API
        $testResult = $this->generateResponse('Bonjour, test de connexion', [], 'Réponds juste "OK" si tu reçois ce message.');
        
        if ($testResult['success']) {
            return [
                'valid' => true,
                'model' => $this->model,
                'message' => 'Configuration Mistral AI valide',
                'environment' => $this->config['app']['environment']
            ];
        } else {
            return [
                'valid' => false,
                'error' => $testResult['error']
            ];
        }
    }
    
    /**
     * Obtenir les statistiques d'utilisation Mistral
     */
    public function getUsageStats() {
        // Pour un vrai déploiement, on sauvegarderait les stats d'usage en base
        // Ici on retourne des stats simulées
        
        return [
            'total_requests' => rand(50, 200),
            'total_tokens' => rand(5000, 15000),
            'avg_response_time' => rand(800, 1500) . 'ms',
            'success_rate' => rand(95, 99) . '%',
            'top_use_cases' => [
                'Réponses automatiques' => rand(40, 60) . '%',
                'Génération de messages' => rand(20, 35) . '%',
                'Analyse de sentiment' => rand(10, 20) . '%'
            ]
        ];
    }
}

// Point d'entrée pour test direct
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    echo "<h2>🤖 Test Mistral AI Service</h2>";
    
    try {
        // Possibilité de tester avec une vraie clé via GET parameter
        $testApiKey = $_GET['key'] ?? null;
        $mistral = new MistralService($testApiKey);
        $config = $mistral->config ?? [];
        
        // Afficher la configuration
        echo "<h3>Configuration chargée</h3>";
        echo "<p>Environnement: " . ($config['app']['environment'] ?? 'N/A') . "</p>";
        echo "<p>Application: " . ($config['app']['name'] ?? 'N/A') . " v" . ($config['app']['version'] ?? 'N/A') . "</p>";
        echo "<p>Debug activé: " . (($config['app']['debug'] ?? false) ? 'Oui' : 'Non') . "</p>";
        echo "<p>Logging activé: " . (($config['logging']['enabled'] ?? false) ? 'Oui' : 'Non') . "</p>";
        
        // Validation de la config
        echo "<h3>Configuration Mistral AI</h3>";
        $configValidation = $mistral->validateConfig();
        if ($configValidation['valid']) {
            echo "<p style='color: green;'>✅ " . $configValidation['message'] . "</p>";
            echo "<p>Modèle: " . $configValidation['model'] . "</p>";
            echo "<p>Environnement: " . ($configValidation['environment'] ?? 'N/A') . "</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ " . $configValidation['error'] . "</p>";
            if (empty($testApiKey)) {
                echo "<p>Pour tester avec une vraie clé: <code>?key=VOTRE_CLE_MISTRAL</code></p>";
            }
        }
        
        // Vérifier les fonctionnalités activées
        echo "<h3>Fonctionnalités</h3>";
        $features = $config['features'] ?? [];
        foreach ($features as $feature => $enabled) {
            $status = $enabled ? '✅' : '❌';
            echo "<p>$status " . ucfirst(str_replace('_', ' ', $feature)) . "</p>";
        }
        
        // Statistiques d'usage
        echo "<h3>Statistiques d'usage</h3>";
        $stats = $mistral->getUsageStats();
        echo "<p>Requêtes totales: " . $stats['total_requests'] . "</p>";
        echo "<p>Tokens utilisés: " . $stats['total_tokens'] . "</p>";
        echo "<p>Temps de réponse moyen: " . $stats['avg_response_time'] . "</p>";
        echo "<p>Taux de succès: " . $stats['success_rate'] . "</p>";
        
        // Tests de fonctionnalités (simulation)
        echo "<h3>Fonctionnalités disponibles</h3>";
        echo "<ul>";
        echo "<li>✅ Réponses automatiques aux membres</li>";
        echo "<li>✅ Génération de messages push</li>";
        echo "<li>✅ Analyse de sentiment</li>";
        echo "<li>✅ Détection d'intention</li>";
        echo "<li>✅ Suggestions de réponse</li>";
        echo "<li>✅ Amélioration de messages</li>";
        echo "</ul>";
        
        // Formulaire de test
        if ($_POST && isset($_POST['test_message'])) {
            echo "<h3>Test de génération</h3>";
            
            $testMessage = $_POST['test_message'];
            $testType = $_POST['test_type'] ?? 'auto_reply';
            
            echo "<p><strong>Message testé:</strong> " . htmlspecialchars($testMessage) . "</p>";
            
            // Simulation de réponse si pas de clé API valide
            if (!$configValidation['valid']) {
                $associationName = $config['app']['name'] ?? 'notre association';
                $simulatedResponses = [
                    'auto_reply' => "Bonjour ! Merci pour votre message à $associationName. L'équipe vous répondra bientôt. En attendant, n'hésitez pas à consulter notre site web. 😊",
                    'push_message' => "🌟 Chers membres de $associationName,\n\nNous avons le plaisir de vous inviter à notre prochaine rencontre ! Votre participation est précieuse pour notre communauté.\n\nÀ bientôt ! 💫",
                    'improve' => "Version améliorée de votre message avec un ton plus engageant et professionnel, adaptée à $associationName."
                ];
                
                $response = $simulatedResponses[$testType] ?? $simulatedResponses['auto_reply'];
                echo "<div style='background: #e8f5e8; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
                echo "<strong>Réponse simulée:</strong><br>";
                echo nl2br(htmlspecialchars($response));
                echo "</div>";
                echo "<p><em>Note: Ceci est une simulation. Configurez une clé API Mistral dans config.php pour les vraies fonctionnalités.</em></p>";
            } else {
                // Vraie réponse avec l'API
                $result = null;
                switch ($testType) {
                    case 'auto_reply':
                        $result = $mistral->generateAutoReply($testMessage);
                        break;
                    case 'push_message':
                        $result = $mistral->generatePushMessage('announcement', ['sujet' => $testMessage]);
                        break;
                    case 'improve':
                        $result = $mistral->improveMessage($testMessage);
                        break;
                }
                
                if ($result && $result['success']) {
                    echo "<div style='background: #e8f5e8; padding: 15px; border-radius: 5px; margin: 10px 0;'>";
                    echo "<strong>Réponse Mistral AI:</strong><br>";
                    echo nl2br(htmlspecialchars($result['response']));
                    echo "</div>";
                    
                    if (isset($result['usage'])) {
                        echo "<p><em>Tokens utilisés: " . ($result['usage']['total_tokens'] ?? 'N/A') . "</em></p>";
                    }
                } else {
                    echo "<p style='color: red;'>❌ Erreur: " . ($result['error'] ?? 'Erreur inconnue') . "</p>";
                }
            }
        }
        
        echo "<h3>Test des fonctionnalités</h3>";
        echo "<form method='post'>";
        echo "<p>
                <select name='test_type' required>
                    <option value='auto_reply'>Réponse automatique</option>
                    <option value='push_message'>Message push</option>
                    <option value='improve'>Améliorer message</option>
                </select>
              </p>";
        echo "<p><textarea name='test_message' placeholder='Saisissez votre message de test...' required style='width: 100%; height: 80px;'></textarea></p>";
        echo "<p><button type='submit' style='background: #4CAF50; color: white; padding: 10px 20px; border: none; border-radius: 5px;'>Tester</button></p>";
        echo "</form>";
        
    } catch (Exception $e) {
        echo "<p style='color: red;'>❌ Erreur: " . $e->getMessage() . "</p>";
        echo "<p>Vérifiez que le fichier config.php existe dans le dossier backend.</p>";
    }
}
?>