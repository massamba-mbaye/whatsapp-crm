/**
 * Polaris CRM - Configuration Frontend
 * URLs des APIs et paramètres de l'application
 */

// Configuration des URLs
const API_CONFIG = {
    // URL de base de l'API locale
    baseUrl: 'http://localhost/polaris-crm/backend',
    
    // Endpoints de l'API
    endpoints: {
        // Membres
        members: '/api.php?endpoint=members',
        membersSearch: '/api.php?endpoint=members/search',
        
        // Segments  
        segments: '/api.php?endpoint=segments',
        segmentMembers: '/api.php?endpoint=segments/members',
        
        // Messages
        messages: '/api.php?endpoint=messages',
        messagesPush: '/api.php?endpoint=messages/push',
        
        // Statistiques
        stats: '/api.php?endpoint=stats',
        
        // Test
        test: '/api.php?endpoint=test'
    },
    
    // Configuration des requêtes
    timeout: 30000, // 30 secondes
    retries: 3
};

// Configuration WhatsApp Business API
const WHATSAPP_CONFIG = {
    // Vos identifiants WhatsApp (remplacez par les vôtres)
    accessToken: 'EAARrooPSpZCwBPSP0qwAFxwQVcwWZC1YU12cr2iH7gzuG5mvyzYZCWsZBZA5zFl43dBZAUGkAo5wQIcICwYdptQxaFI2nNMdZCsZC2CKyCsmqk5VW75tBYflL2tLZBtaoNTGW35ypc106YRe5ScZCPMBHmS8Dbw4SIuBTKups6FNauMs3DCshagpc72o2zYoUDZCWPjA8kIB1MeLaWMERSlrWZCyYELYK7N2RUHQ4GmrhHxiNIIZD',
    phoneNumberId: '756576417546292',
    
    // API Meta
    apiVersion: 'v22.0',
    baseUrl: 'https://graph.facebook.com',
    
    // Types de messages
    messageTypes: {
        template: 'template',
        text: 'text',
        interactive: 'interactive'
    },
    
    // Statuts des messages
    messageStatus: {
        pending: { label: '⏳ En cours', color: '#fbbf24' },
        sent: { label: '📤 Envoyé', color: '#3b82f6' },
        delivered: { label: '✅ Livré', color: '#10b981' },
        read: { label: '👀 Lu', color: '#059669' },
        failed: { label: '❌ Échec', color: '#ef4444' }
    }
};

// Configuration Mistral AI
const MISTRAL_CONFIG = {
    // À configurer plus tard
    apiKey: '', // Votre clé API Mistral
    model: 'mistral-small-latest',
    baseUrl: 'https://api.mistral.ai/v1',
    
    // Paramètres par défaut
    maxTokens: 500,
    temperature: 0.7,
    
    // Prompts système
    systemPrompts: {
        default: `Tu es un assistant pour l'association Polaris. Tu réponds de manière chaleureuse et professionnelle aux messages des membres. Reste concis et utile.`,
        
        support: `Tu es le support de l'association Polaris. Aide les membres avec leurs questions concernant les activités, événements et démarches de l'association.`,
        
        event: `Tu es responsable des événements de Polaris. Tu informes sur les prochains événements et recueilles les participations.`
    }
};

// Templates WhatsApp prédéfinis
const MESSAGE_TEMPLATES = {
    // Templates de base
    hello_world: {
        name: 'hello_world',
        displayName: 'Hello World',
        description: 'Message de test standard',
        language: 'en_US',
        category: 'test'
    },
    
    // Templates personnalisés pour Polaris (à créer dans Meta Business)
    welcome_member: {
        name: 'welcome_member',
        displayName: 'Bienvenue nouveau membre',
        description: 'Message d\'accueil pour les nouveaux membres',
        language: 'fr',
        category: 'welcome'
    },
    
    event_reminder: {
        name: 'event_reminder',
        displayName: 'Rappel événement',
        description: 'Rappel pour un événement à venir',
        language: 'fr',
        category: 'event'
    },
    
    survey_request: {
        name: 'survey_request',
        displayName: 'Demande de sondage',
        description: 'Invitation à participer à un sondage',
        language: 'fr',
        category: 'survey'
    },
    
    meeting_invitation: {
        name: 'meeting_invitation',
        displayName: 'Invitation réunion',
        description: 'Invitation à une réunion ou assemblée',
        language: 'fr',
        category: 'meeting'
    }
};

// Configuration de l'interface utilisateur
const UI_CONFIG = {
    // Pagination
    pagination: {
        membersPerPage: 20,
        messagesPerPage: 50
    },
    
    // Thèmes de couleurs
    colors: {
        primary: '#667eea',
        secondary: '#764ba2',
        success: '#10b981',
        warning: '#f59e0b',
        error: '#ef4444',
        info: '#3b82f6'
    },
    
    // Animation et UX
    animations: {
        fadeInDuration: 300,
        slideInDuration: 250,
        notificationDuration: 5000
    },
    
    // Formats d'affichage
    dateFormat: 'dd/mm/yyyy',
    timeFormat: 'HH:mm',
    datetimeFormat: 'dd/mm/yyyy HH:mm'
};

// Configuration de développement/production
const ENV_CONFIG = {
    // Mode développement
    isDevelopment: window.location.hostname === 'localhost',
    
    // Logs
    enableLogs: true,
    enableDebugMode: true,
    
    // Données de test
    enableTestData: true,
    
    // Intervalles de rafraîchissement
    refreshIntervals: {
        stats: 30000,        // 30 secondes
        messages: 10000,     // 10 secondes
        membersList: 60000   // 1 minute
    }
};

// Utilitaires de configuration
const ConfigUtils = {
    /**
     * Obtenir l'URL complète d'un endpoint
     */
    getApiUrl: (endpoint) => {
        return API_CONFIG.baseUrl + API_CONFIG.endpoints[endpoint];
    },
    
    /**
     * Obtenir les headers par défaut pour les requêtes API
     */
    getApiHeaders: () => {
        return {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        };
    },
    
    /**
     * Obtenir les headers pour WhatsApp API
     */
    getWhatsAppHeaders: () => {
        return {
            'Authorization': `Bearer ${WHATSAPP_CONFIG.accessToken}`,
            'Content-Type': 'application/json'
        };
    },
    
    /**
     * Formater un numéro de téléphone
     */
    formatPhoneNumber: (phone) => {
        // Supprimer tous les caractères non numériques
        const cleaned = phone.replace(/[^\d]/g, '');
        
        // Formater pour l'affichage (exemple sénégalais)
        if (cleaned.startsWith('221') && cleaned.length === 12) {
            return `+221 ${cleaned.slice(3, 5)} ${cleaned.slice(5, 8)} ${cleaned.slice(8, 10)} ${cleaned.slice(10, 12)}`;
        }
        
        // Format par défaut
        return `+${cleaned}`;
    },
    
    /**
     * Nettoyer un numéro pour l'API
     */
    cleanPhoneNumber: (phone) => {
        return phone.replace(/[^\d]/g, '');
    },
    
    /**
     * Valider un numéro de téléphone
     */
    validatePhoneNumber: (phone) => {
        const cleaned = ConfigUtils.cleanPhoneNumber(phone);
        return cleaned.length >= 9 && cleaned.length <= 15;
    },
    
    /**
     * Obtenir le statut d'un message avec style
     */
    getMessageStatus: (status) => {
        return WHATSAPP_CONFIG.messageStatus[status] || {
            label: '❓ Inconnu',
            color: '#6b7280'
        };
    },
    
    /**
     * Logger avec contrôle d'environnement
     */
    log: (...args) => {
        if (ENV_CONFIG.enableLogs && ENV_CONFIG.isDevelopment) {
            console.log('[Polaris CRM]', ...args);
        }
    },
    
    /**
     * Logger d'erreurs
     */
    error: (...args) => {
        if (ENV_CONFIG.enableLogs) {
            console.error('[Polaris CRM ERROR]', ...args);
        }
    }
};

// Données de test pour le développement
const TEST_DATA = {
    members: [
        {
            id: 1,
            prenom: 'Jean',
            nom: 'Dupont',
            telephone: '221771234567',
            created_at: '2024-01-15 10:30:00'
        },
        {
            id: 2,
            prenom: 'Marie',
            nom: 'Diallo',
            telephone: '221776543210',
            created_at: '2024-01-16 14:20:00'
        }
    ],
    
    segments: [
        {
            id: 1,
            nom: 'Comité directeur',
            description: 'Membres du comité directeur',
            member_count: 5
        },
        {
            id: 2,
            nom: 'Bénévoles événements',
            description: 'Bénévoles pour l\'organisation d\'événements',
            member_count: 12
        }
    ]
};

// Export pour les modules (si besoin)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        API_CONFIG,
        WHATSAPP_CONFIG,
        MISTRAL_CONFIG,
        MESSAGE_TEMPLATES,
        UI_CONFIG,
        ENV_CONFIG,
        ConfigUtils,
        TEST_DATA
    };
}

// Log de démarrage
ConfigUtils.log('Configuration Polaris CRM chargée', {
    mode: ENV_CONFIG.isDevelopment ? 'Développement' : 'Production',
    apiUrl: API_CONFIG.baseUrl
});