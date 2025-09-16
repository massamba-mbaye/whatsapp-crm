<?php
/**
 * Polaris CRM - Configuration centrale
 * Paramètres de configuration pour tous les services
 */

// Empêcher l'accès direct au fichier
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
    http_response_code(403);
    exit('Accès interdit');
}

return [
    // Configuration WhatsApp Business API
    'whatsapp' => [
        'access_token' => 'EAARrooPSpZCwBPSP0qwAFxwQVcwWZC1YU12cr2iH7gzuG5mvyzYZCWsZBZA5zFl43dBZAUGkAo5wQIcICwYdptQxaFI2nNMdZCsZC2CKyCsmqk5VW75tBYflL2tLZBtaoNTGW35ypc106YRe5ScZCPMBHmS8Dbw4SIuBTKups6FNauMs3DCshagpc72o2zYoUDZCWPjA8kIB1MeLaWMERSlrWZCyYELYK7N2RUHQ4GmrhHxiNIIZD',
        'phone_number_id' => '756576417546292',
        'api_version' => 'v22.0',
        'base_url' => 'https://graph.facebook.com',
        'webhook_verify_token' => 'polaris_webhook_token_2024'
    ],
    
    // Configuration Mistral AI
    'mistral' => [
        'api_key' => '7aV9xjUEL1QgGVS7BeyIKyhODYY52pxL', // À remplir avec votre clé Mistral AI
        'model' => 'mistral-small-latest',
        'base_url' => 'https://api.mistral.ai/v1',
        'max_tokens' => 500,
        'temperature' => 0.7
    ],
    
    // Configuration base de données
    'database' => [
        'path' => __DIR__ . '/../data/polaris.db',
        'backup_enabled' => true,
        'backup_interval' => 24 // heures
    ],
    
    // Configuration générale de l'application
    'app' => [
        'name' => 'Polaris CRM',
        'version' => '1.0.0',
        'environment' => 'development', // development | production
        'timezone' => 'Africa/Dakar',
        'language' => 'fr',
        'debug' => true
    ],
    
    // Configuration des fonctionnalités
    'features' => [
        'auto_reply_enabled' => true,
        'mistral_ai_enabled' => true,
        'webhook_enabled' => true,
        'notifications_enabled' => true,
        'member_auto_creation' => true
    ],
    
    // Configuration des notifications
    'notifications' => [
        'urgent_message_threshold' => 'haute', // basse | moyenne | haute
        'team_notification_methods' => ['database'], // database | email | sms
        'admin_email' => 'admin@polaris-asso.sn',
        'admin_phone' => '221771234567'
    ],
    
    // Configuration de sécurité
    'security' => [
        'allowed_ips' => [], // Liste d'IPs autorisées pour l'API (vide = toutes)
        'rate_limiting' => [
            'enabled' => true,
            'max_requests_per_minute' => 60,
            'max_requests_per_hour' => 1000
        ],
        'webhook_signature_verification' => false // À activer en production
    ],
    
    // Configuration des logs
    'logging' => [
        'enabled' => true,
        'level' => 'info', // debug | info | warning | error
        'file' => __DIR__ . '/../data/polaris.log',
        'max_file_size' => '10MB',
        'retention_days' => 30
    ]
];
?>