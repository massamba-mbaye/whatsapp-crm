/**
 * Polaris CRM - Application JavaScript principale
 * Gestion de l'interface utilisateur et des interactions avec l'API
 */

class PolarisApp {
    constructor() {
        this.selectedMembers = new Set();
        this.currentSection = 'dashboard';
        this.members = [];
        this.segments = [];
        this.stats = {};
        
        this.init();
    }
    
    /**
     * Initialisation de l'application
     */
    init() {
        this.setupEventListeners();
        this.loadInitialData();
        this.setupAutoRefresh();
        
        ConfigUtils.log('Application Polaris CRM initialisée');
    }
    
    /**
     * Configuration des écouteurs d'événements
     */
    setupEventListeners() {
        // Navigation
        document.querySelectorAll('.nav-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const section = link.dataset.section;
                this.switchSection(section);
            });
        });
        
        // Recherche de membres
        document.getElementById('memberSearch')?.addEventListener('input', (e) => {
            this.searchMembers(e.target.value);
        });
        
        // Type de message
        document.getElementById('messageType')?.addEventListener('change', (e) => {
            this.toggleMessageSections(e.target.value);
        });
        
        // Fermeture des modals en cliquant à l'extérieur
        document.addEventListener('click', (e) => {
            if (e.target.classList.contains('modal')) {
                this.closeModal(e.target.id);
            }
        });
    }
    
    /**
     * Chargement des données initiales
     */
    async loadInitialData() {
        try {
            await Promise.all([
                this.loadStats(),
                this.loadMembers(),
                this.loadSegments()
            ]);
            
            this.populateTemplateSelects();
        } catch (error) {
            ConfigUtils.error('Erreur lors du chargement des données:', error);
            this.showNotification('Erreur de chargement des données', 'error');
        }
    }
    
    /**
     * Configuration du rafraîchissement automatique
     */
    setupAutoRefresh() {
        // Rafraîchir les stats toutes les 30 secondes
        setInterval(() => {
            this.loadStats();
        }, ENV_CONFIG.refreshIntervals.stats);
    }
    
    /**
     * Basculer entre les sections
     */
    switchSection(sectionName) {
        // Mettre à jour la navigation
        document.querySelectorAll('.nav-link').forEach(link => {
            link.classList.remove('active');
        });
        document.querySelector(`[data-section="${sectionName}"]`).classList.add('active');
        
        // Masquer toutes les sections
        document.querySelectorAll('.section').forEach(section => {
            section.classList.remove('active');
        });
        
        // Afficher la section active
        document.getElementById(`${sectionName}-section`).classList.add('active');
        
        // Mettre à jour le breadcrumb
        const breadcrumbText = {
            dashboard: 'Dashboard',
            members: 'Membres',
            segments: 'Segments',
            messages: 'Messages Push',
            conversations: 'Conversations'
        };
        document.getElementById('breadcrumb-text').textContent = breadcrumbText[sectionName];
        
        this.currentSection = sectionName;
        
        // Charger les données spécifiques à la section
        this.loadSectionData(sectionName);
    }
    
    /**
     * Charger les données spécifiques à une section
     */
    async loadSectionData(section) {
        switch (section) {
            case 'members':
                await this.loadMembers();
                break;
            case 'segments':
                await this.loadSegments();
                break;
            case 'messages':
                // Charger l'historique des messages
                break;
        }
    }
    
    /**
     * Effectuer une requête API
     */
    async apiRequest(endpoint, options = {}) {
        const url = ConfigUtils.getApiUrl(endpoint);
        const headers = ConfigUtils.getApiHeaders();
        
        const config = {
            headers,
            ...options
        };
        
        try {
            const response = await fetch(url, config);
            const data = await response.json();
            
            if (!response.ok) {
                throw new Error(data.message || 'Erreur API');
            }
            
            return data;
        } catch (error) {
            ConfigUtils.error('Erreur API:', error);
            throw error;
        }
    }
    
    /**
     * Charger les statistiques
     */
    async loadStats() {
        try {
            this.stats = await this.apiRequest('stats');
            this.updateStatsDisplay();
        } catch (error) {
            ConfigUtils.error('Erreur chargement stats:', error);
        }
    }
    
    /**
     * Mettre à jour l'affichage des statistiques
     */
    updateStatsDisplay() {
        document.getElementById('totalMembers').textContent = this.stats.members_count || 0;
        document.getElementById('totalSegments').textContent = this.stats.segments_count || 0;
        document.getElementById('pendingMessages').textContent = this.stats.pending_messages || 0;
        document.getElementById('failedMessages').textContent = this.stats.failed_messages || 0;
    }
    
    /**
     * Charger la liste des membres
     */
    async loadMembers() {
        try {
            this.members = await this.apiRequest('members');
            this.renderMembers();
            this.updateSelectionActions();
        } catch (error) {
            ConfigUtils.error('Erreur chargement membres:', error);
            this.showNotification('Erreur lors du chargement des membres', 'error');
        }
    }
    
    /**
     * Afficher la liste des membres
     */
    renderMembers(membersToRender = null) {
        const container = document.getElementById('membersGrid');
        const members = membersToRender || this.members;
        
        if (members.length === 0) {
            container.innerHTML = `
                <div style="grid-column: 1 / -1; text-align: center; padding: 3rem; color: var(--text-light);">
                    <i class="fas fa-users" style="font-size: 3rem; margin-bottom: 1rem;"></i>
                    <p>Aucun membre trouvé</p>
                    <button class="btn btn-primary" style="margin-top: 1rem;" onclick="app.openAddMemberModal()">
                        <i class="fas fa-plus"></i> Ajouter le premier membre
                    </button>
                </div>
            `;
            return;
        }
        
        container.innerHTML = members.map(member => `
            <div class="member-card ${this.selectedMembers.has(member.id) ? 'selected' : ''}" 
                 onclick="app.toggleMemberSelection(${member.id})">
                <input type="checkbox" class="checkbox" 
                       ${this.selectedMembers.has(member.id) ? 'checked' : ''}
                       onclick="event.stopPropagation()">
                
                <div class="member-name">${member.prenom} ${member.nom}</div>
                <div class="member-phone">
                    <i class="fas fa-phone"></i> ${ConfigUtils.formatPhoneNumber(member.telephone)}
                </div>
                <div class="member-date">
                    <i class="fas fa-calendar-plus"></i> Ajouté le ${new Date(member.created_at).toLocaleDateString('fr-FR')}
                </div>
                
                <div class="member-actions">
                    <button class="btn btn-secondary btn-small" onclick="event.stopPropagation(); app.editMember(${member.id})">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button class="btn btn-danger btn-small" onclick="event.stopPropagation(); app.deleteMember(${member.id})">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        `).join('');
    }
    
    /**
     * Rechercher des membres
     */
    async searchMembers(query) {
        if (!query.trim()) {
            this.renderMembers();
            return;
        }
        
        try {
            const results = await this.apiRequest('membersSearch', {
                method: 'GET'
            });
            // Note: L'API doit être modifiée pour accepter le paramètre de recherche
            const filteredMembers = this.members.filter(member => 
                member.prenom.toLowerCase().includes(query.toLowerCase()) ||
                member.nom.toLowerCase().includes(query.toLowerCase()) ||
                member.telephone.includes(query)
            );
            
            this.renderMembers(filteredMembers);
        } catch (error) {
            // Fallback: recherche locale
            const filteredMembers = this.members.filter(member => 
                member.prenom.toLowerCase().includes(query.toLowerCase()) ||
                member.nom.toLowerCase().includes(query.toLowerCase()) ||
                member.telephone.includes(query)
            );
            
            this.renderMembers(filteredMembers);
        }
    }
    
    /**
     * Basculer la sélection d'un membre
     */
    toggleMemberSelection(memberId) {
        if (this.selectedMembers.has(memberId)) {
            this.selectedMembers.delete(memberId);
        } else {
            this.selectedMembers.add(memberId);
        }
        
        this.renderMembers();
        this.updateSelectionActions();
    }
    
    /**
     * Sélectionner tous les membres
     */
    selectAllMembers() {
        this.members.forEach(member => {
            this.selectedMembers.add(member.id);
        });
        this.renderMembers();
        this.updateSelectionActions();
    }
    
    /**
     * Désélectionner tous les membres
     */
    clearSelection() {
        this.selectedMembers.clear();
        this.renderMembers();
        this.updateSelectionActions();
    }
    
    /**
     * Mettre à jour les actions de sélection
     */
    updateSelectionActions() {
        const count = this.selectedMembers.size;
        const selectionActions = document.getElementById('selectionActions');
        const selectedCount = document.getElementById('selectedCount');
        
        if (count > 0) {
            selectionActions.style.display = 'block';
            selectedCount.textContent = `${count} sélectionné(s)`;
        } else {
            selectionActions.style.display = 'none';
        }
        
        // Mettre à jour le compteur dans le panel de messages
        const messageSelectedCount = document.getElementById('messageSelectedCount');
        if (messageSelectedCount) {
            if (count > 0) {
                messageSelectedCount.style.display = 'inline-block';
                messageSelectedCount.textContent = `${count} destinataire(s)`;
            } else {
                messageSelectedCount.style.display = 'none';
            }
        }
    }
    
    /**
     * Ouvrir le modal d'ajout de membre
     */
    openAddMemberModal() {
        document.getElementById('memberPrenom').value = '';
        document.getElementById('memberNom').value = '';
        document.getElementById('memberTelephone').value = '';
        this.openModal('addMemberModal');
    }
    
    /**
     * Enregistrer un nouveau membre
     */
    async saveMember() {
        const prenom = document.getElementById('memberPrenom').value.trim();
        const nom = document.getElementById('memberNom').value.trim();
        const telephone = document.getElementById('memberTelephone').value.trim();
        
        if (!prenom || !nom || !telephone) {
            this.showNotification('Tous les champs sont obligatoires', 'warning');
            return;
        }
        
        if (!ConfigUtils.validatePhoneNumber(telephone)) {
            this.showNotification('Format de téléphone invalide', 'error');
            return;
        }
        
        try {
            const newMember = await this.apiRequest('members', {
                method: 'POST',
                body: JSON.stringify({
                    prenom,
                    nom,
                    telephone: ConfigUtils.cleanPhoneNumber(telephone)
                })
            });
            
            this.members.push(newMember);
            this.renderMembers();
            this.closeModal('addMemberModal');
            this.loadStats(); // Mettre à jour les statistiques
            
            this.showNotification('Membre ajouté avec succès', 'success');
        } catch (error) {
            this.showNotification(error.message || 'Erreur lors de l\'ajout du membre', 'error');
        }
    }
    
    /**
     * Supprimer un membre
     */
    async deleteMember(memberId) {
        const member = this.members.find(m => m.id === memberId);
        if (!member) return;
        
        if (!confirm(`Supprimer ${member.prenom} ${member.nom} ?`)) {
            return;
        }
        
        try {
            await this.apiRequest('members', {
                method: 'DELETE',
                body: JSON.stringify({ id: memberId })
            });
            
            this.members = this.members.filter(m => m.id !== memberId);
            this.selectedMembers.delete(memberId);
            this.renderMembers();
            this.updateSelectionActions();
            this.loadStats();
            
            this.showNotification('Membre supprimé avec succès', 'success');
        } catch (error) {
            this.showNotification(error.message || 'Erreur lors de la suppression', 'error');
        }
    }
    
    /**
     * Charger les segments
     */
    async loadSegments() {
        try {
            this.segments = await this.apiRequest('segments');
            this.renderSegments();
            this.populateSegmentSelects();
        } catch (error) {
            ConfigUtils.error('Erreur chargement segments:', error);
            this.showNotification('Erreur lors du chargement des segments', 'error');
        }
    }
    
    /**
     * Afficher la liste des segments
     */
    renderSegments() {
        const container = document.getElementById('segmentsList');
        
        if (this.segments.length === 0) {
            container.innerHTML = `
                <div style="text-align: center; padding: 2rem; color: var(--text-light);">
                    <i class="fas fa-tags" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                    <p>Aucun segment créé</p>
                </div>
            `;
            return;
        }
        
        container.innerHTML = this.segments.map(segment => `
            <div class="segment-item" onclick="app.selectSegment(${segment.id})">
                <div>
                    <strong>${segment.nom}</strong>
                    ${segment.description ? `<br><small>${segment.description}</small>` : ''}
                </div>
                <div class="segment-count">${segment.member_count || 0}</div>
            </div>
        `).join('');
    }
    
    /**
     * Ouvrir le modal de création de segment
     */
    openAddSegmentModal() {
        document.getElementById('segmentNom').value = '';
        document.getElementById('segmentDescription').value = '';
        this.openModal('addSegmentModal');
    }
    
    /**
     * Enregistrer un nouveau segment
     */
    async saveSegment() {
        const nom = document.getElementById('segmentNom').value.trim();
        const description = document.getElementById('segmentDescription').value.trim();
        
        if (!nom) {
            this.showNotification('Le nom du segment est obligatoire', 'warning');
            return;
        }
        
        try {
            const newSegment = await this.apiRequest('segments', {
                method: 'POST',
                body: JSON.stringify({
                    nom,
                    description
                })
            });
            
            this.segments.push(newSegment);
            this.renderSegments();
            this.populateSegmentSelects();
            this.closeModal('addSegmentModal');
            this.loadStats();
            
            this.showNotification('Segment créé avec succès', 'success');
        } catch (error) {
            this.showNotification(error.message || 'Erreur lors de la création du segment', 'error');
        }
    }
    
    /**
     * Populer les listes déroulantes de segments
     */
    populateSegmentSelects() {
        const selects = document.querySelectorAll('select[id*="segment"], select[id*="Recipients"]');
        
        selects.forEach(select => {
            // Garder les options existantes non-segment
            const existingOptions = Array.from(select.options).filter(option => 
                !option.value.startsWith('segment_')
            );
            
            select.innerHTML = '';
            
            // Remettre les options existantes
            existingOptions.forEach(option => {
                select.appendChild(option);
            });
            
            // Ajouter les segments
            this.segments.forEach(segment => {
                const option = document.createElement('option');
                option.value = `segment_${segment.id}`;
                option.textContent = `📁 ${segment.nom} (${segment.member_count || 0})`;
                select.appendChild(option);
            });
        });
    }
    
    /**
     * Populer les templates dans les sélecteurs
     */
    populateTemplateSelects() {
        const templateSelect = document.getElementById('templateSelect');
        if (!templateSelect) return;
        
        templateSelect.innerHTML = '';
        
        Object.values(MESSAGE_TEMPLATES).forEach(template => {
            const option = document.createElement('option');
            option.value = template.name;
            option.textContent = template.displayName;
            option.title = template.description;
            templateSelect.appendChild(option);
        });
    }
    
    /**
     * Basculer les sections de message selon le type
     */
    toggleMessageSections(messageType) {
        const templateSection = document.getElementById('templateSection');
        const textSection = document.getElementById('textSection');
        
        if (messageType === 'template') {
            templateSection.style.display = 'block';
            textSection.style.display = 'none';
        } else {
            templateSection.style.display = 'none';
            textSection.style.display = 'block';
        }
    }
    
    /**
     * Envoyer un message push
     */
    async sendPushMessage() {
        if (this.selectedMembers.size === 0) {
            this.showNotification('Veuillez sélectionner des membres', 'warning');
            return;
        }
        
        const messageType = document.getElementById('messageType').value;
        let messageData = {
            recipients: Array.from(this.selectedMembers),
            type: messageType
        };
        
        if (messageType === 'template') {
            messageData.template = document.getElementById('templateSelect').value;
        } else {
            const messageText = document.getElementById('messageText').value.trim();
            if (!messageText) {
                this.showNotification('Veuillez saisir un message', 'warning');
                return;
            }
            messageData.text = messageText;
        }
        
        if (!confirm(`Envoyer le message à ${this.selectedMembers.size} membre(s) ?`)) {
            return;
        }
        
        try {
            // Pour l'instant, simuler l'envoi
            this.showNotification(`Message envoyé à ${this.selectedMembers.size} membre(s)`, 'success');
            
            // Réinitialiser le formulaire
            this.clearSelection();
            document.getElementById('messageText').value = '';
            
        } catch (error) {
            this.showNotification(error.message || 'Erreur lors de l\'envoi du message', 'error');
        }
    }
    
    /**
     * Ouvrir un modal
     */
    openModal(modalId) {
        const modal = document.getElementById(modalId);
        modal.classList.add('show');
    }
    
    /**
     * Fermer un modal
     */
    closeModal(modalId) {
        const modal = document.getElementById(modalId);
        modal.classList.remove('show');
    }
    
    /**
     * Afficher une notification
     */
    showNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.className = `notification ${type}`;
        notification.textContent = message;
        
        document.body.appendChild(notification);
        
        // Supprimer après 5 secondes
        setTimeout(() => {
            notification.remove();
        }, UI_CONFIG.animations.notificationDuration);
    }
}

// Fonctions globales pour les événements inline
function openAddMemberModal() {
    app.openAddMemberModal();
}

function saveMember() {
    app.saveMember();
}

function openAddSegmentModal() {
    app.openAddSegmentModal();
}

function saveSegment() {
    app.saveSegment();
}

function closeModal(modalId) {
    app.closeModal(modalId);
}

function selectAllMembers() {
    app.selectAllMembers();
}

function clearSelection() {
    app.clearSelection();
}

function sendPushMessage() {
    app.sendPushMessage();
}

// Initialisation de l'application
let app;
document.addEventListener('DOMContentLoaded', () => {
    app = new PolarisApp();
});