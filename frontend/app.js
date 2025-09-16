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
        this.currentSegment = null;
        this.editingMemberId = null;
        this.conversations = [];
        this.currentConversation = null;
        this.conversationFilter = 'all';
        
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
            case 'conversations':
                await this.loadConversations();
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
            
            // Vérifier si la réponse est du JSON
            const contentType = response.headers.get('content-type');
            if (!contentType || !contentType.includes('application/json')) {
                const text = await response.text();
                console.error('Réponse non-JSON reçue:', text);
                throw new Error('Réponse invalide du serveur (non-JSON)');
            }
            
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
        
        // Recherche locale
        const filteredMembers = this.members.filter(member => 
            member.prenom.toLowerCase().includes(query.toLowerCase()) ||
            member.nom.toLowerCase().includes(query.toLowerCase()) ||
            member.telephone.includes(query)
        );
        
        this.renderMembers(filteredMembers);
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
        
        // Réinitialiser le modal
        this.editingMemberId = null;
        document.querySelector('#addMemberModal .modal-title').textContent = 'Ajouter un membre';
        
        this.openModal('addMemberModal');
    }
    
    /**
     * Enregistrer un nouveau membre ou mettre à jour un existant
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
            const memberData = {
                prenom,
                nom,
                telephone: ConfigUtils.cleanPhoneNumber(telephone)
            };
            
            let result;
            if (this.editingMemberId) {
                // Mise à jour
                memberData.id = this.editingMemberId;
                result = await this.apiRequest('members', {
                    method: 'PUT',
                    body: JSON.stringify(memberData)
                });
                
                // Mettre à jour dans la liste locale
                const index = this.members.findIndex(m => m.id === this.editingMemberId);
                if (index !== -1) {
                    this.members[index] = result;
                }
                
                this.showNotification('Membre modifié avec succès', 'success');
            } else {
                // Création
                result = await this.apiRequest('members', {
                    method: 'POST',
                    body: JSON.stringify(memberData)
                });
                
                this.members.push(result);
                this.showNotification('Membre ajouté avec succès', 'success');
            }
            
            this.renderMembers();
            this.closeModal('addMemberModal');
            this.loadStats(); // Mettre à jour les statistiques
            
        } catch (error) {
            this.showNotification(error.message || 'Erreur lors de l\'opération', 'error');
        }
    }
    
    /**
     * Éditer un membre
     */
    editMember(memberId) {
        const member = this.members.find(m => m.id === memberId);
        if (!member) return;
        
        // Remplir le formulaire avec les données actuelles
        document.getElementById('memberPrenom').value = member.prenom;
        document.getElementById('memberNom').value = member.nom;
        document.getElementById('memberTelephone').value = member.telephone;
        
        // Stocker l'ID pour la mise à jour
        this.editingMemberId = memberId;
        
        // Changer le titre du modal
        document.querySelector('#addMemberModal .modal-title').textContent = 'Modifier le membre';
        
        this.openModal('addMemberModal');
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
            <div class="segment-item ${this.currentSegment?.id === segment.id ? 'active' : ''}" 
                 onclick="app.selectSegment(${segment.id})">
                <div>
                    <strong>${segment.nom}</strong>
                    ${segment.description ? `<br><small>${segment.description}</small>` : ''}
                </div>
                <div class="segment-count">${segment.member_count || 0}</div>
            </div>
        `).join('');
    }
    
    /**
     * Sélectionner un segment
     */
    async selectSegment(segmentId) {
        try {
            // Charger les détails du segment spécifique
            const response = await fetch(`${ConfigUtils.getApiUrl('segments')}&id=${segmentId}`, {
                headers: ConfigUtils.getApiHeaders()
            });
            
            if (!response.ok) {
                throw new Error('Erreur lors du chargement du segment');
            }
            
            this.currentSegment = await response.json();
            this.renderSegmentDetails();
            this.renderSegments(); // Re-render pour mettre à jour l'état actif
        } catch (error) {
            ConfigUtils.error('Erreur lors du chargement du segment:', error);
            this.showNotification('Erreur lors du chargement du segment', 'error');
        }
    }
    
    /**
     * Afficher les détails d'un segment
     */
    renderSegmentDetails() {
        const container = document.getElementById('segmentDetails');
        
        if (!this.currentSegment) {
            container.innerHTML = `
                <p style="text-align: center; color: var(--text-light); padding: 2rem;">
                    Sélectionnez un segment pour voir ses détails
                </p>
            `;
            return;
        }
        
        const membersHtml = this.currentSegment.members && this.currentSegment.members.length > 0 
            ? this.currentSegment.members.map(member => 
                `<div style="padding: 0.5rem; border-bottom: 1px solid var(--border);">
                    ${member.prenom} ${member.nom} - ${ConfigUtils.formatPhoneNumber(member.telephone)}
                </div>`
            ).join('')
            : '<p style="color: var(--text-light); padding: 1rem;">Aucun membre dans ce segment</p>';
        
        container.innerHTML = `
            <h3>${this.currentSegment.nom}</h3>
            <p><strong>Description:</strong> ${this.currentSegment.description || 'Aucune description'}</p>
            <p><strong>Nombre de membres:</strong> ${this.currentSegment.member_count || 0}</p>
            
            <div style="margin: 1rem 0;">
                <h4>Membres du segment:</h4>
                <div style="max-height: 200px; overflow-y: auto; border: 1px solid var(--border); border-radius: 5px;">
                    ${membersHtml}
                </div>
            </div>
            
            <div style="margin-top: 1rem;">
                <button class="btn btn-primary btn-small" onclick="app.addMembersToSegment()">
                    <i class="fas fa-plus"></i> Ajouter des membres
                </button>
                <button class="btn btn-secondary btn-small" onclick="app.editSegment()">
                    <i class="fas fa-edit"></i> Modifier
                </button>
                <button class="btn btn-danger btn-small" onclick="app.deleteSegment()">
                    <i class="fas fa-trash"></i> Supprimer
                </button>
            </div>
        `;
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
     * Afficher le modal pour ajouter des membres sélectionnés à un segment
     */
    showAddToSegmentModal() {
        if (this.selectedMembers.size === 0) {
            this.showNotification('Veuillez sélectionner des membres', 'warning');
            return;
        }
        
        if (this.segments.length === 0) {
            this.showNotification('Aucun segment disponible. Créez d\'abord un segment.', 'warning');
            return;
        }
        
        // Créer un modal simple pour sélectionner le segment
        const modalHtml = `
            <div id="addToSegmentModal" class="modal show">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 class="modal-title">Ajouter au segment</h2>
                        <button class="modal-close" onclick="app.closeModal('addToSegmentModal')">&times;</button>
                    </div>
                    
                    <p>Ajouter ${this.selectedMembers.size} membre(s) sélectionné(s) au segment :</p>
                    
                    <div class="form-group">
                        <label class="form-label">Choisir un segment</label>
                        <select class="form-control" id="targetSegment">
                            <option value="">Sélectionner un segment...</option>
                            ${this.segments.map(segment => 
                                `<option value="${segment.id}">${segment.nom} (${segment.member_count || 0} membres)</option>`
                            ).join('')}
                        </select>
                    </div>
                    
                    <div class="modal-actions">
                        <button class="btn btn-primary" onclick="app.addSelectedMembersToSegment()">
                            <i class="fas fa-plus"></i>
                            Ajouter
                        </button>
                        <button class="btn btn-secondary" onclick="app.closeModal('addToSegmentModal')">Annuler</button>
                    </div>
                </div>
            </div>
        `;
        
        // Supprimer le modal s'il existe déjà
        const existingModal = document.getElementById('addToSegmentModal');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Ajouter le nouveau modal
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }
    
    /**
     * Ajouter les membres sélectionnés au segment choisi
     */
    async addSelectedMembersToSegment() {
        const segmentId = document.getElementById('targetSegment').value;
        
        if (!segmentId) {
            this.showNotification('Veuillez sélectionner un segment', 'warning');
            return;
        }
        
        if (this.selectedMembers.size === 0) {
            this.showNotification('Aucun membre sélectionné', 'warning');
            return;
        }
        
        try {
            let successCount = 0;
            let errorCount = 0;
            
            // Ajouter chaque membre au segment
            for (const memberId of this.selectedMembers) {
                try {
                    await this.apiRequest('segmentMembers', {
                        method: 'POST',
                        body: JSON.stringify({
                            segment_id: parseInt(segmentId),
                            member_id: memberId
                        })
                    });
                    successCount++;
                } catch (error) {
                    console.error(`Erreur pour le membre ${memberId}:`, error);
                    errorCount++;
                }
            }
            
            // Fermer le modal
            this.closeModal('addToSegmentModal');
            
            // Recharger les segments pour mettre à jour les compteurs
            await this.loadSegments();
            
            // Afficher le résultat
            if (errorCount === 0) {
                this.showNotification(`${successCount} membre(s) ajouté(s) au segment avec succès`, 'success');
            } else {
                this.showNotification(`${successCount} réussi(s), ${errorCount} erreur(s). Certains membres étaient peut-être déjà dans le segment.`, 'warning');
            }
            
        } catch (error) {
            this.showNotification('Erreur lors de l\'ajout au segment', 'error');
            console.error('Erreur addSelectedMembersToSegment:', error);
        }
    }
    
    /**
     * === GESTION DES CONVERSATIONS ===
     */
    
    /**
     * Charger les conversations
     */
    async loadConversations() {
        try {
            // Simuler des conversations pour la démo
            this.conversations = this.generateMockConversations();
            this.renderConversations();
            this.populateManualReplyRecipients();
        } catch (error) {
            ConfigUtils.error('Erreur chargement conversations:', error);
            this.showNotification('Erreur lors du chargement des conversations', 'error');
        }
    }
    
    /**
     * Générer des conversations simulées
     */
    generateMockConversations() {
        const mockConversations = [];
        
        // Utiliser les membres existants ou créer des exemples
        const sampleMembers = this.members.length > 0 ? this.members.slice(0, 5) : [
            { id: 1, prenom: 'Marie', nom: 'Diallo', telephone: '221771234567' },
            { id: 2, prenom: 'Amadou', nom: 'Ba', telephone: '221776543210' },
            { id: 3, prenom: 'Fatou', nom: 'Sène', telephone: '221775555555' },
            { id: 4, prenom: 'Moussa', nom: 'Ndiaye', telephone: '221778888888' },
            { id: 5, prenom: 'Aïcha', nom: 'Fall', telephone: '221779999999' }
        ];
        
        const sampleMessages = [
            { text: "Bonjour ! J'aimerais savoir quand aura lieu la prochaine réunion ?", type: 'in', urgent: false },
            { text: "Salut, est-ce que l'événement de demain est maintenu ?", type: 'in', urgent: true },
            { text: "Merci pour l'information !", type: 'in', urgent: false },
            { text: "J'ai un problème avec mon adhésion, pouvez-vous m'aider ?", type: 'in', urgent: true },
            { text: "Bonne journée à toute l'équipe !", type: 'in', urgent: false }
        ];
        
        sampleMembers.forEach((member, index) => {
            const message = sampleMessages[index];
            const time = new Date(Date.now() - (index * 3600000)); // Heures échelonnées
            
            mockConversations.push({
                id: member.id,
                member: member,
                lastMessage: message.text,
                lastMessageTime: time,
                unread: index < 2,
                urgent: message.urgent,
                hasAiResponse: index === 0,
                messages: this.generateConversationMessages(member, message)
            });
        });
        
        return mockConversations.sort((a, b) => b.lastMessageTime - a.lastMessageTime);
    }
    
    /**
     * Générer l'historique d'une conversation
     */
    generateConversationMessages(member, lastMessage) {
        const messages = [
            {
                id: 1,
                content: "Bonjour " + member.prenom + " ! Comment allez-vous ?",
                type: 'out',
                timestamp: new Date(Date.now() - 7200000),
                status: 'read'
            },
            {
                id: 2,
                content: "Bonjour ! Ça va bien merci.",
                type: 'in',
                timestamp: new Date(Date.now() - 3600000)
            },
            {
                id: 3,
                content: lastMessage.text,
                type: 'in',
                timestamp: new Date(Date.now() - 1800000)
            }
        ];
        
        return messages;
    }
    
    /**
     * Afficher la liste des conversations
     */
    renderConversations() {
        const container = document.getElementById('conversationsList');
        
        if (this.conversations.length === 0) {
            container.innerHTML = `
                <div style="text-align: center; padding: 2rem; color: var(--text-light);">
                    <i class="fas fa-comments" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                    <p>Aucune conversation</p>
                </div>
            `;
            return;
        }
        
        const filteredConversations = this.getFilteredConversations();
        
        container.innerHTML = filteredConversations.map(conv => `
            <div class="conversation-item ${this.currentConversation?.id === conv.id ? 'active' : ''} 
                 ${conv.unread ? 'unread' : ''} ${conv.urgent ? 'urgent' : ''}"
                 onclick="app.selectConversation(${conv.id})">
                
                <div class="conversation-meta">
                    <div class="conversation-name">${conv.member.prenom} ${conv.member.nom}</div>
                    <div class="conversation-time">${this.formatTime(conv.lastMessageTime)}</div>
                </div>
                
                <div class="conversation-preview">${conv.lastMessage}</div>
                
                <div class="conversation-status">
                    ${conv.unread ? '<span class="status-badge status-unread">Non lu</span>' : ''}
                    ${conv.urgent ? '<span class="status-badge status-urgent">Urgent</span>' : ''}
                    ${conv.hasAiResponse ? '<span class="status-badge status-ai-response">IA</span>' : ''}
                </div>
            </div>
        `).join('');
    }
    
    /**
     * Filtrer les conversations
     */
    getFilteredConversations() {
        switch (this.conversationFilter) {
            case 'unread':
                return this.conversations.filter(conv => conv.unread);
            case 'urgent':
                return this.conversations.filter(conv => conv.urgent);
            default:
                return this.conversations;
        }
    }
    
    /**
     * Sélectionner une conversation
     */
    selectConversation(conversationId) {
        this.currentConversation = this.conversations.find(conv => conv.id === conversationId);
        
        if (!this.currentConversation) return;
        
        // Marquer comme lue
        this.currentConversation.unread = false;
        
        this.renderConversations();
        this.renderConversationHeader();
        this.renderMessages();
        this.showReplyArea();
    }
    
    /**
     * Afficher l'en-tête de la conversation
     */
    renderConversationHeader() {
        const headerInfo = document.getElementById('conversationInfo');
        const headerActions = document.getElementById('conversationActions');
        
        if (!this.currentConversation) {
            headerInfo.innerHTML = `
                <h2 class="card-title">Sélectionnez une conversation</h2>
                <p style="color: var(--text-light); margin: 0;">Choisissez un membre pour voir l'historique</p>
            `;
            headerActions.style.display = 'none';
            return;
        }
        
        const member = this.currentConversation.member;
        headerInfo.innerHTML = `
            <h2 class="card-title">${member.prenom} ${member.nom}</h2>
            <p style="color: var(--text-light); margin: 0;">
                <i class="fas fa-phone"></i> ${ConfigUtils.formatPhoneNumber(member.telephone)}
                <span style="margin-left: 1rem;">
                    <i class="fas fa-clock"></i> Dernière activité: ${this.formatTime(this.currentConversation.lastMessageTime)}
                </span>
            </p>
        `;
        headerActions.style.display = 'flex';
    }
    
    /**
     * Afficher les messages de la conversation
     */
    renderMessages() {
        const container = document.getElementById('messagesContainer');
        
        if (!this.currentConversation) {
            container.innerHTML = `
                <div style="text-align: center; color: var(--text-light); padding: 2rem;">
                    <i class="fas fa-comment-dots" style="font-size: 2rem; margin-bottom: 1rem;"></i>
                    <p>Sélectionnez une conversation pour voir les messages</p>
                </div>
            `;
            return;
        }
        
        const messages = this.currentConversation.messages;
        container.innerHTML = messages.map(message => `
            <div class="message ${message.type === 'in' ? 'incoming' : 'outgoing'}">
                <div class="message-bubble">
                    ${message.content}
                </div>
                <div class="message-meta">
                    <span>${this.formatTime(message.timestamp)}</span>
                    ${message.type === 'out' ? `<span class="message-status">${this.getMessageStatusIcon(message.status)}</span>` : ''}
                </div>
            </div>
        `).join('');
        
        // Scroll vers le bas
        container.scrollTop = container.scrollHeight;
    }
    
    /**
     * Afficher la zone de réponse
     */
    showReplyArea() {
        const replyArea = document.getElementById('replyArea');
        if (this.currentConversation) {
            replyArea.classList.remove('hidden');
        } else {
            replyArea.classList.add('hidden');
        }
    }
    
    /**
     * Formater l'heure
     */
    formatTime(date) {
        const now = new Date();
        const diffMinutes = Math.floor((now - date) / 60000);
        
        if (diffMinutes < 1) return 'À l\'instant';
        if (diffMinutes < 60) return `Il y a ${diffMinutes}min`;
        if (diffMinutes < 1440) return `Il y a ${Math.floor(diffMinutes / 60)}h`;
        return date.toLocaleDateString('fr-FR');
    }
    
    /**
     * Icône de statut de message
     */
    getMessageStatusIcon(status) {
        switch (status) {
            case 'sent': return '📤';
            case 'delivered': return '✅';
            case 'read': return '👀';
            case 'failed': return '❌';
            default: return '⏳';
        }
    }
    
    /**
     * Filtrer les conversations
     */
    filterConversations(filter) {
        this.conversationFilter = filter;
        this.renderConversations();
        
        // Mettre à jour l'apparence des boutons
        document.querySelectorAll('#conversations-section .btn-small').forEach(btn => {
            btn.classList.remove('btn-primary');
            btn.classList.add('btn-secondary');
        });
        
        // Trouver le bouton cliqué par le filtre
        const buttons = document.querySelectorAll('#conversations-section .btn-small');
        const filterMap = { 'all': 0, 'unread': 1, 'urgent': 2 };
        const buttonIndex = filterMap[filter];
        if (buttons[buttonIndex]) {
            buttons[buttonIndex].classList.remove('btn-secondary');
            buttons[buttonIndex].classList.add('btn-primary');
        }
    }
    
    /**
     * Actualiser les conversations
     */
    refreshConversations() {
        this.loadConversations();
        this.showNotification('Conversations actualisées', 'success');
    }
    
    /**
     * Ouvrir le modal de réponse manuelle
     */
    openManualReplyModal() {
        this.populateManualReplyRecipients();
        this.openModal('manualReplyModal');
    }
    
    /**
     * Populer la liste des destinataires
     */
    populateManualReplyRecipients() {
        const select = document.getElementById('manualReplyRecipient');
        if (!select) return;
        
        select.innerHTML = '<option value="">Choisir un membre...</option>';
        
        this.members.forEach(member => {
            const option = document.createElement('option');
            option.value = member.id;
            option.textContent = `${member.prenom} ${member.nom} - ${ConfigUtils.formatPhoneNumber(member.telephone)}`;
            select.appendChild(option);
        });
    }
    
    /**
     * Envoyer une réponse manuelle dans la conversation active
     */
    sendManualReply() {
        const messageText = document.getElementById('replyMessage').value.trim();
        
        if (!messageText) {
            this.showNotification('Veuillez saisir un message', 'warning');
            return;
        }
        
        if (!this.currentConversation) {
            this.showNotification('Aucune conversation sélectionnée', 'error');
            return;
        }
        
        // Ajouter le message à la conversation
        const newMessage = {
            id: Date.now(),
            content: messageText,
            type: 'out',
            timestamp: new Date(),
            status: 'sent'
        };
        
        this.currentConversation.messages.push(newMessage);
        this.currentConversation.lastMessage = messageText;
        this.currentConversation.lastMessageTime = new Date();
        
        // Mettre à jour l'affichage
        this.renderMessages();
        this.renderConversations();
        
        // Vider le champ
        document.getElementById('replyMessage').value = '';
        
        this.showNotification('Message envoyé !', 'success');
        
        // Simuler l'envoi réel via WhatsApp (à implémenter)
        // this.sendWhatsAppMessage(this.currentConversation.member.telephone, messageText);
    }
    
    /**
     * Envoyer un message manuel à un membre spécifique
     */
    sendManualMessage() {
        const recipientId = document.getElementById('manualReplyRecipient').value;
        const messageText = document.getElementById('manualReplyText').value.trim();
        
        if (!recipientId) {
            this.showNotification('Veuillez sélectionner un destinataire', 'warning');
            return;
        }
        
        if (!messageText) {
            this.showNotification('Veuillez saisir un message', 'warning');
            return;
        }
        
        // Trouver ou créer la conversation
        let conversation = this.conversations.find(conv => conv.id == recipientId);
        const member = this.members.find(m => m.id == recipientId);
        
        if (!conversation && member) {
            conversation = {
                id: member.id,
                member: member,
                lastMessage: messageText,
                lastMessageTime: new Date(),
                unread: false,
                urgent: false,
                hasAiResponse: false,
                messages: []
            };
            this.conversations.unshift(conversation);
        }
        
        if (conversation) {
            const newMessage = {
                id: Date.now(),
                content: messageText,
                type: 'out',
                timestamp: new Date(),
                status: 'sent'
            };
            
            conversation.messages.push(newMessage);
            conversation.lastMessage = messageText;
            conversation.lastMessageTime = new Date();
        }
        
        this.closeModal('manualReplyModal');
        this.renderConversations();
        
        // Vider les champs
        document.getElementById('manualReplyRecipient').value = '';
        document.getElementById('manualReplyText').value = '';
        
        this.showNotification('Message envoyé !', 'success');
    }
    
    /**
     * Générer des suggestions de réponse avec IA
     */
    generateSuggestions() {
        if (!this.currentConversation) return;
        
        const suggestionsContainer = document.getElementById('replySuggestions');
        const suggestionsList = document.getElementById('suggestionsContainer');
        
        // Simuler des suggestions IA
        const suggestions = [
            "Merci pour votre message ! Je vous réponds dans les plus brefs délais.",
            "Bonjour ! Pouvez-vous me donner plus de détails sur votre demande ?",
            "C'est noté, je fais le nécessaire et vous tiens informé(e)."
        ];
        
        suggestionsList.innerHTML = suggestions.map(suggestion => `
            <div class="suggestion-item" onclick="app.useSuggestion('${suggestion.replace(/'/g, "\\'")}')">
                ${suggestion}
            </div>
        `).join('');
        
        suggestionsContainer.style.display = 'block';
    }
    
    /**
     * Utiliser une suggestion
     */
    useSuggestion(suggestion) {
        document.getElementById('replyMessage').value = suggestion;
        document.getElementById('replySuggestions').style.display = 'none';
    }
    
    /**
     * Analyser le sentiment de la conversation
     */
    analyzeConversationSentiment() {
        if (!this.currentConversation) return;
        
        // Simulation d'analyse de sentiment
        const sentiments = ['😊 Positif', '😐 Neutre', '😟 Négatif'];
        const randomSentiment = sentiments[Math.floor(Math.random() * sentiments.length)];
        
        this.showNotification(`Sentiment détecté: ${randomSentiment}`, 'info');
        
        // Dans un vrai système, cela appellerait l'API Mistral AI
        // const result = await this.mistralService.analyzeSentiment(lastMessage);
    }
    
    /**
     * Générer une réponse automatique avec IA
     */
    generateAutoReply() {
        if (!this.currentConversation) return;
        
        // Simulation de génération IA
        const aiResponses = [
            "Merci pour votre message ! Notre équipe va examiner votre demande et vous répondra rapidement.",
            "Bonjour ! Je comprends votre préoccupation. Laissez-moi vérifier cela pour vous.",
            "C'est une excellente question ! Permettez-moi de vous fournir les informations nécessaires."
        ];
        
        const randomResponse = aiResponses[Math.floor(Math.random() * aiResponses.length)];
        document.getElementById('replyMessage').value = randomResponse;
        
        this.showNotification('Réponse générée par IA !', 'success');
    }
    
    /**
     * Générer un message avec IA pour le modal manuel
     */
    generateMessageWithAI() {
        const recipient = document.getElementById('manualReplyRecipient').value;
        if (!recipient) {
            this.showNotification('Veuillez d\'abord sélectionner un destinataire', 'warning');
            return;
        }
        
        const member = this.members.find(m => m.id == recipient);
        if (!member) return;
        
        // Simulation de génération IA
        const aiMessages = [
            `Bonjour ${member.prenom} ! J'espère que vous allez bien. Comment puis-je vous aider aujourd'hui ?`,
            `Salut ${member.prenom} ! Merci pour votre engagement dans notre association. Y a-t-il quelque chose que vous aimeriez partager ?`,
            `Cher(e) ${member.prenom}, nous avons des nouvelles importantes à partager avec vous concernant nos prochaines activités.`
        ];
        
        const randomMessage = aiMessages[Math.floor(Math.random() * aiMessages.length)];
        document.getElementById('manualReplyText').value = randomMessage;
        
        this.showNotification('Message généré par IA !', 'success');
    }
    
    /**
     * Améliorer un message avec IA
     */
    improveMessageWithAI() {
        const currentText = document.getElementById('manualReplyText').value.trim();
        if (!currentText) {
            this.showNotification('Veuillez d\'abord saisir un message à améliorer', 'warning');
            return;
        }
        
        // Simulation d'amélioration IA
        const improvedText = `${currentText} 😊\n\nCordialement,\nL'équipe Polaris`;
        document.getElementById('manualReplyText').value = improvedText;
        
        this.showNotification('Message amélioré par IA !', 'success');
    }
    
    /**
     * Ajouter des méthodes manquantes pour éviter les erreurs
     */
    addMembersToSegment() {
        this.showNotification('Fonctionnalité en développement', 'info');
    }
    
    editSegment() {
        this.showNotification('Fonctionnalité en développement', 'info');
    }
    
    deleteSegment() {
        if (!this.currentSegment) return;
        
        if (!confirm(`Supprimer le segment "${this.currentSegment.nom}" ?`)) {
            return;
        }
        
        this.showNotification('Fonctionnalité en développement', 'info');
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
        if (!modal) return;
        
        modal.classList.remove('show');
        
        // Pour les modals dynamiques, les supprimer complètement
        if (modalId === 'addToSegmentModal') {
            setTimeout(() => {
                modal.remove();
            }, 300); // Attendre la fin de l'animation
        }
        
        // Réinitialiser le formulaire de membre si c'est le modal d'ajout/modification
        if (modalId === 'addMemberModal') {
            this.editingMemberId = null;
            document.querySelector('#addMemberModal .modal-title').textContent = 'Ajouter un membre';
        }
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

function sendMessageToSelected() {
    if (app.selectedMembers.size === 0) {
        app.showNotification('Veuillez sélectionner des membres', 'warning');
        return;
    }
    
    // Basculer vers la section messages
    app.switchSection('messages');
}

function addToSegmentModal() {
    if (app.selectedMembers.size === 0) {
        app.showNotification('Veuillez sélectionner des membres', 'warning');
        return;
    }
    
    app.showAddToSegmentModal();
}

function addSelectedMembersToSegment() {
    app.addSelectedMembersToSegment();
}

// Fonctions pour les conversations
function refreshConversations() {
    app.refreshConversations();
}

function openManualReplyModal() {
    app.openManualReplyModal();
}

function filterConversations(filter) {
    app.filterConversations(filter);
}

function selectConversation(id) {
    app.selectConversation(id);
}

function analyzeConversationSentiment() {
    app.analyzeConversationSentiment();
}

function generateAutoReply() {
    app.generateAutoReply();
}

function sendManualReply() {
    app.sendManualReply();
}

function generateSuggestions() {
    app.generateSuggestions();
}

function useSuggestion(suggestion) {
    app.useSuggestion(suggestion);
}

function sendManualMessage() {
    app.sendManualMessage();
}

function generateMessageWithAI() {
    app.generateMessageWithAI();
}

function improveMessageWithAI() {
    app.improveMessageWithAI();
}

// Initialisation de l'application
let app;
document.addEventListener('DOMContentLoaded', () => {
    app = new PolarisApp();
});