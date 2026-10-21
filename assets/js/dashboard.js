/**
 * Tool Manager - Dashboard Logic - Project Creation, Search, Member Invitations, and Stats
 */

document.addEventListener('DOMContentLoaded', () => {
    initProjectSearch();
    initNewProjectModal();
    initInviteMemberModal();
    initNotificationDropdown();
});

/**
 * Filter projects dynamically in the dashboard grid
 */
function initProjectSearch() {
    const searchInput = document.getElementById('project-search');
    if (!searchInput) return;

    searchInput.addEventListener('input', (e) => {
        const query = e.target.value.toLowerCase().trim();
        const cards = document.querySelectorAll('.project-card');

        cards.forEach(card => {
            const title = card.querySelector('.project-title')?.textContent.toLowerCase() || '';
            const desc = card.querySelector('.project-desc')?.textContent.toLowerCase() || '';

            if (title.includes(query) || desc.includes(query)) {
                card.style.display = 'flex';
            } else {
                card.style.display = 'none';
            }
        });
    });
}

/**
 * Handle Create Project Modal
 */
function initNewProjectModal() {
    const openBtn = document.getElementById('btn-new-project');
    const modal = document.getElementById('modal-new-project');
    const closeBtn = document.getElementById('close-modal-project');
    const cancelBtn = document.getElementById('cancel-modal-project');
    const form = document.getElementById('form-new-project');

    if (!modal) return;

    const openModal = () => modal.classList.add('active');
    const closeModal = () => {
        modal.classList.remove('active');
        if (form) form.reset();
    };

    if (openBtn) openBtn.addEventListener('click', openModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = form.querySelector('button[type="submit"]');
            const name = form.querySelector('[name="name"]').value.trim();
            const description = form.querySelector('[name="description"]').value.trim();

            if (!name) {
                showToast('Please enter a project name.', 'danger');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Creating...';

            try {
                const res = await API.projects.create({ name, description });
                if (res.success) {
                    showToast('Project created successfully!', 'success');
                    closeModal();
                    // Navigate to the newly created project board
                    window.location.href = `project.php?id=${res.data.id}`;
                } else {
                    showToast(res.error || 'Failed to create project', 'danger');
                }
            } catch (err) {
                showToast(err.message || 'Error creating project', 'danger');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Create Project';
            }
        });
    }
}

/**
 * Handle Invite / Add Member Modal
 */
function initInviteMemberModal() {
    const modal = document.getElementById('modal-invite-member');
    const closeBtn = document.getElementById('close-modal-invite');
    const cancelBtn = document.getElementById('cancel-modal-invite');
    const form = document.getElementById('form-invite-member');
    const searchInput = document.getElementById('invite-user-search');
    const resultsContainer = document.getElementById('user-search-results');
    const selectedUserIdInput = document.getElementById('invite-selected-user-id');
    const selectedUserBadge = document.getElementById('invite-selected-user-badge');

    if (!modal) return;

    const closeModal = () => {
        modal.classList.remove('active');
        if (form) form.reset();
        if (resultsContainer) resultsContainer.innerHTML = '';
        if (selectedUserBadge) selectedUserBadge.style.display = 'none';
        if (selectedUserIdInput) selectedUserIdInput.value = '';
    };

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    // Global trigger function to open invite modal for a specific project
    window.openInviteModal = function (projectId, projectName) {
        const projIdInput = document.getElementById('invite-project-id');
        const projNameEl = document.getElementById('invite-project-name');
        if (projIdInput) projIdInput.value = projectId;
        if (projNameEl) projNameEl.textContent = projectName;
        modal.classList.add('active');
        if (searchInput) searchInput.focus();
    };

    // User Search Input with Debounce
    let searchTimeout = null;
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            clearTimeout(searchTimeout);
            const query = e.target.value.trim();
            const projectId = document.getElementById('invite-project-id')?.value;

            if (query.length < 2) {
                if (resultsContainer) resultsContainer.innerHTML = '';
                return;
            }

            searchTimeout = setTimeout(async () => {
                try {
                    const res = await API.projects.searchUsers(query, projectId);
                    renderSearchResults(res.data || []);
                } catch (err) {
                    console.error('Search error:', err);
                }
            }, 300);
        });
    }

    function renderSearchResults(users) {
        if (!resultsContainer) return;
        resultsContainer.innerHTML = '';

        if (users.length === 0) {
            resultsContainer.innerHTML = '<div style="padding:0.75rem; color:var(--text-muted); font-size:0.85rem;">No users found matching query.</div>';
            return;
        }

        users.forEach(u => {
            const item = document.createElement('div');
            item.className = 'user-result-row';
            item.style.cssText = 'display:flex; align-items:center; justify-content:space-between; padding:0.65rem 0.85rem; border-radius:var(--radius-md); background:var(--bg-secondary); margin-bottom:0.4rem; cursor:pointer;';
            item.innerHTML = `
                <div style="display:flex; align-items:center; gap:0.65rem;">
                    <div class="avatar avatar-sm" style="background-color:${u.avatar_color};">
                        ${u.username.substring(0, 2).toUpperCase()}
                    </div>
                    <div>
                        <div style="font-weight:600; font-size:0.85rem; color:var(--text-primary);">${escapeHtml(u.username)}</div>
                        <div style="font-size:0.75rem; color:var(--text-muted);">${escapeHtml(u.email)}</div>
                    </div>
                </div>
                <button type="button" class="btn btn-secondary btn-sm">Select</button>
            `;

            item.addEventListener('click', () => {
                if (selectedUserIdInput) selectedUserIdInput.value = u.id;
                if (selectedUserBadge) {
                    selectedUserBadge.innerHTML = `Selected: <strong>${escapeHtml(u.username)}</strong> (${escapeHtml(u.email)})`;
                    selectedUserBadge.style.display = 'block';
                }
                if (resultsContainer) resultsContainer.innerHTML = '';
                if (searchInput) searchInput.value = '';
            });

            resultsContainer.appendChild(item);
        });
    }

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const projectId = document.getElementById('invite-project-id')?.value;
            const userId = selectedUserIdInput?.value;
            const role = form.querySelector('[name="role"]')?.value || 'member';

            if (!userId) {
                showToast('Please search and select a user to invite.', 'danger');
                return;
            }

            try {
                const res = await API.projects.addMember(projectId, userId, role);
                if (res.success) {
                    showToast(res.message || 'Member added!', 'success');
                    closeModal();
                    setTimeout(() => window.location.reload(), 600);
                } else {
                    showToast(res.error || 'Failed to add member', 'danger');
                }
            } catch (err) {
                showToast(err.message || 'Error inviting member', 'danger');
            }
        });
    }

    // Global project delete
    window.confirmDeleteProject = async function (e, projectId, projectName) {
        e.stopPropagation();
        e.preventDefault();

        if (confirm(`Are you sure you want to permanently delete the project "${projectName}"? All columns, tasks, and comments will be erased.`)) {
            try {
                const res = await API.projects.delete(projectId);
                if (res.success) {
                    showToast('Project deleted successfully.', 'success');
                    const card = document.getElementById(`project-card-${projectId}`);
                    if (card) {
                        card.style.opacity = '0';
                        card.style.transform = 'scale(0.95)';
                        setTimeout(() => card.remove(), 300);
                    }
                } else {
                    showToast(res.error || 'Failed to delete project', 'danger');
                }
            } catch (err) {
                showToast(err.message || 'Error deleting project', 'danger');
            }
        }
    };
}

/**
 * Initialize Notification Dropdown & Live Unread Badges
 */
function initNotificationDropdown() {
    const trigger = document.getElementById('notif-dropdown-btn');
    const menu = document.getElementById('notif-dropdown-menu');
    const badge = document.getElementById('notif-badge');
    const list = document.getElementById('notif-list-container');
    const markReadBtn = document.getElementById('btn-mark-notifs-read');

    if (!trigger || !menu) return;

    trigger.addEventListener('click', async (e) => {
        e.stopPropagation();
        const isOpen = menu.classList.contains('active');

        if (!isOpen) {
            menu.classList.add('active');
            loadNotifications();
        } else {
            menu.classList.remove('active');
        }
    });

    document.addEventListener('click', (e) => {
        if (!menu.contains(e.target) && !trigger.contains(e.target)) {
            menu.classList.remove('active');
        }
    });

    async function loadNotifications() {
        if (!list) return;
        try {
            const res = await API.projects.getNotifications();
            if (res.success) {
                renderNotifications(res.data.notifications || []);
                updateBadge(res.data.unread_count || 0);
            }
        } catch (err) {
            console.error('Failed to load notifications:', err);
        }
    }

    function renderNotifications(notifs) {
        if (!list) return;
        list.innerHTML = '';

        if (notifs.length === 0) {
            list.innerHTML = '<div style="padding:1rem; text-align:center; color:var(--text-muted); font-size:0.85rem;">No notifications yet.</div>';
            return;
        }

        notifs.forEach(n => {
            const item = document.createElement('a');
            item.href = n.link || '#';
            item.className = `notification-item ${n.is_read ? '' : 'unread'}`;
            item.innerHTML = `
                <div>${escapeHtml(n.message)}</div>
                <div class="notification-time">${new Date(n.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} • ${new Date(n.created_at).toLocaleDateString()}</div>
            `;
            list.appendChild(item);
        });
    }

    function updateBadge(count) {
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count > 99 ? '99+' : count;
            badge.style.display = 'flex';
        } else {
            badge.style.display = 'none';
        }
    }

    if (markReadBtn) {
        markReadBtn.addEventListener('click', async (e) => {
            e.preventDefault();
            e.stopPropagation();
            try {
                await API.projects.markNotificationsRead();
                updateBadge(0);
                document.querySelectorAll('.notification-item.unread').forEach(el => el.classList.remove('unread'));
                showToast('Marked all as read', 'info');
            } catch (err) {
                console.error(err);
            }
        });
    }

    // Expose global notification updater for real_time.js
    window.updateNotificationBadge = updateBadge;
}
