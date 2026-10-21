/**
 * Tool Manager - Dashboard Logic
 * Project Creation, Search, In-Place Editing, Member Roster, Profile Settings, and Live Badges
 */

document.addEventListener('DOMContentLoaded', () => {
    initProjectSearch();
    initProjectCardInteractions();
    initNewProjectModal();
    initEditProjectModal();
    initInviteMemberModal();
    initTeamRosterModal();
    initUserProfileModal();
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
 * Handle clicks and keyboard events on project cards safely
 */
function initProjectCardInteractions() {
    const grid = document.getElementById('projects-grid');
    if (!grid) return;

    // Click handler with delegation
    grid.addEventListener('click', async (e) => {
        // 1. Delete Project Button
        const delBtn = e.target.closest('.btn-delete-project');
        if (delBtn) {
            e.stopPropagation();
            const pid = parseInt(delBtn.dataset.projectId, 10);
            const name = delBtn.dataset.projectName || 'this project';
            handleDeleteProject(pid, name);
            return;
        }

        // 2. Edit Project Button
        const editBtn = e.target.closest('.btn-edit-project');
        if (editBtn) {
            e.stopPropagation();
            const pid = parseInt(editBtn.dataset.projectId, 10);
            const name = editBtn.dataset.projectName || '';
            const desc = editBtn.dataset.projectDesc || '';
            if (window.openEditProjectModal) {
                window.openEditProjectModal(pid, name, desc);
            }
            return;
        }

        // 3. Team Roster Button
        const rosterBtn = e.target.closest('.btn-manage-roster');
        if (rosterBtn) {
            e.stopPropagation();
            const pid = parseInt(rosterBtn.dataset.projectId, 10);
            const name = rosterBtn.dataset.projectName || '';
            if (window.openTeamRosterModal) {
                window.openTeamRosterModal(pid, name);
            }
            return;
        }

        // 4. Invite Button Trigger
        const inviteBtn = e.target.closest('.btn-invite-trigger');
        if (inviteBtn) {
            e.stopPropagation();
            const pid = parseInt(inviteBtn.dataset.projectId, 10);
            const name = inviteBtn.dataset.projectName || '';
            if (window.openInviteModal) {
                window.openInviteModal(pid, name);
            }
            return;
        }

        // 5. Card click -> Navigate to project board
        const card = e.target.closest('.project-card');
        if (card && card.dataset.projectId) {
            window.location.href = `project.php?id=${encodeURIComponent(card.dataset.projectId)}`;
        }
    });

    // Keyboard navigation (Enter / Space)
    grid.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
            const card = e.target.closest('.project-card');
            if (card && card.dataset.projectId && !e.target.closest('button')) {
                e.preventDefault();
                window.location.href = `project.php?id=${encodeURIComponent(card.dataset.projectId)}`;
            }
        }
    });
}

/**
 * Handle Project Deletion with Custom Confirmation Modal
 */
async function handleDeleteProject(projectId, projectName) {
    const confirmed = await showConfirmDialog(
        'Delete Project',
        `Are you sure you want to permanently delete "${projectName}"? All columns, tasks, assignees, and comments will be completely erased. This action cannot be undone.`,
        'Delete Project',
        true
    );

    if (confirmed) {
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

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('active')) closeModal();
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
                if (res.success && res.data) {
                    showToast('Project created successfully!', 'success');
                    closeModal();
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
 * Handle Edit Project Modal (Title & Description)
 */
function initEditProjectModal() {
    const modal = document.getElementById('modal-edit-project');
    const closeBtn = document.getElementById('close-modal-edit-project');
    const cancelBtn = document.getElementById('cancel-modal-edit-project');
    const form = document.getElementById('form-edit-project');
    const idInput = document.getElementById('edit-project-id');
    const nameInput = document.getElementById('edit-proj-name');
    const descInput = document.getElementById('edit-proj-desc');

    if (!modal) return;

    const closeModal = () => {
        modal.classList.remove('active');
        if (form) form.reset();
    };

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('active')) closeModal();
    });

    window.openEditProjectModal = function (projectId, currentName, currentDesc) {
        if (idInput) idInput.value = projectId;
        if (nameInput) nameInput.value = currentName;
        if (descInput) descInput.value = currentDesc;
        modal.classList.add('active');
        if (nameInput) nameInput.focus();
    };

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = form.querySelector('button[type="submit"]');
            const projectId = parseInt(idInput.value, 10);
            const name = nameInput.value.trim();
            const description = descInput.value.trim();

            if (!name) {
                showToast('Project name cannot be empty.', 'danger');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';

            try {
                const res = await API.projects.update({ id: projectId, name, description });
                if (res.success) {
                    showToast('Project updated successfully!', 'success');
                    closeModal();

                    // Update DOM card
                    const card = document.getElementById(`project-card-${projectId}`);
                    if (card) {
                        const titleEl = card.querySelector('.project-title');
                        const descEl = card.querySelector('.project-desc');
                        if (titleEl) titleEl.textContent = name;
                        if (descEl) descEl.textContent = description || 'No description provided.';

                        // Update data attributes
                        const editBtn = card.querySelector('.btn-edit-project');
                        if (editBtn) {
                            editBtn.dataset.projectName = name;
                            editBtn.dataset.projectDesc = description;
                        }
                    }
                } else {
                    showToast(res.error || 'Failed to update project', 'danger');
                }
            } catch (err) {
                showToast(err.message || 'Error updating project', 'danger');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Save Changes';
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

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('active')) closeModal();
    });

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
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    showToast(res.error || 'Failed to add member', 'danger');
                }
            } catch (err) {
                showToast(err.message || 'Error inviting member', 'danger');
            }
        });
    }
}

/**
 * Handle Team Roster & Role Management Modal
 */
function initTeamRosterModal() {
    const modal = document.getElementById('modal-team-roster');
    const closeBtn = document.getElementById('close-modal-roster');
    const closeBtnFooter = document.getElementById('close-btn-roster');
    const nameEl = document.getElementById('roster-project-name');
    const listContainer = document.getElementById('roster-list-container');

    if (!modal) return;

    let activeProjectId = null;

    const closeModal = () => {
        modal.classList.remove('active');
        if (listContainer) listContainer.innerHTML = '';
        activeProjectId = null;
    };

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (closeBtnFooter) closeBtnFooter.addEventListener('click', closeModal);

    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('active')) closeModal();
    });

    window.openTeamRosterModal = async function (projectId, projectName) {
        activeProjectId = projectId;
        if (nameEl) nameEl.textContent = projectName;
        modal.classList.add('active');
        await loadRoster(projectId);
    };

    async function loadRoster(projectId) {
        if (!listContainer) return;
        listContainer.innerHTML = '<div style="padding:1.5rem; text-align:center; color:var(--text-muted);">Loading team roster...</div>';

        try {
            const res = await API.projects.getMembers(projectId);
            if (res.success && res.data) {
                renderRoster(res.data);
            } else {
                listContainer.innerHTML = `<div style="padding:1rem; color:var(--danger);">${escapeHtml(res.error || 'Failed to load roster')}</div>`;
            }
        } catch (err) {
            listContainer.innerHTML = `<div style="padding:1rem; color:var(--danger);">${escapeHtml(err.message || 'Error loading roster')}</div>`;
        }
    }

    function renderRoster(members) {
        if (!listContainer) return;
        listContainer.innerHTML = '';

        if (members.length === 0) {
            listContainer.innerHTML = '<div style="padding:1rem; color:var(--text-muted); text-align:center;">No members in this project.</div>';
            return;
        }

        members.forEach(m => {
            const row = document.createElement('div');
            row.style.cssText = 'display:flex; align-items:center; justify-content:space-between; padding:0.85rem 1rem; background:var(--bg-secondary); border:1px solid var(--border-color); border-radius:var(--radius-md); gap:1rem; flex-wrap:wrap;';

            const isOwner = (parseInt(m.is_owner, 10) === 1);

            let roleControlHtml = '';
            if (isOwner) {
                roleControlHtml = '<span class="badge" style="background:rgba(99,102,241,0.2); color:var(--primary); font-size:0.75rem;">Owner</span>';
            } else {
                roleControlHtml = `
                    <div style="display:flex; align-items:center; gap:0.5rem;">
                        <select class="form-select roster-role-select" data-user-id="${m.id}" style="padding:0.35rem 1.8rem 0.35rem 0.65rem; font-size:0.8rem; width:110px;">
                            <option value="member" ${m.role === 'member' ? 'selected' : ''}>Member</option>
                            <option value="admin" ${m.role === 'admin' ? 'selected' : ''}>Admin</option>
                        </select>
                        <button type="button" class="btn btn-ghost btn-sm btn-remove-member" data-user-id="${m.id}" data-username="${escapeHtml(m.username)}" title="Remove member" style="color:var(--danger);">
                            ✕
                        </button>
                    </div>
                `;
            }

            row.innerHTML = `
                <div style="display:flex; align-items:center; gap:0.75rem;">
                    <div class="avatar" style="background-color:${m.avatar_color || '#6366f1'};">
                        ${m.username.substring(0, 2).toUpperCase()}
                    </div>
                    <div>
                        <div style="font-weight:700; color:var(--text-primary); font-size:0.9rem;">
                            ${escapeHtml(m.username)}
                            ${isOwner ? '<span style="font-size:0.7rem; color:var(--accent-cyan); margin-left:0.3rem;">★ Lead</span>' : ''}
                        </div>
                        <div style="font-size:0.775rem; color:var(--text-muted);">${escapeHtml(m.email)}</div>
                    </div>
                </div>
                ${roleControlHtml}
            `;

            // Event Listeners on role change and remove
            const select = row.querySelector('.roster-role-select');
            if (select) {
                select.addEventListener('change', async (e) => {
                    const newRole = e.target.value;
                    try {
                        const res = await API.projects.updateMemberRole(activeProjectId, m.id, newRole);
                        if (res.success) {
                            showToast(`Updated ${m.username}'s role to ${newRole}.`, 'success');
                        } else {
                            showToast(res.error || 'Failed to update role', 'danger');
                            select.value = m.role;
                        }
                    } catch (err) {
                        showToast(err.message || 'Error updating role', 'danger');
                        select.value = m.role;
                    }
                });
            }

            const removeBtn = row.querySelector('.btn-remove-member');
            if (removeBtn) {
                removeBtn.addEventListener('click', async () => {
                    const confirmed = await showConfirmDialog(
                        'Remove Member',
                        `Remove "${m.username}" from this project? They will lose access to board tasks.`,
                        'Remove',
                        true
                    );
                    if (confirmed) {
                        try {
                            const res = await API.projects.removeMember(activeProjectId, m.id);
                            if (res.success) {
                                showToast(`Removed ${m.username} from project.`, 'info');
                                row.remove();
                            } else {
                                showToast(res.error || 'Failed to remove member', 'danger');
                            }
                        } catch (err) {
                            showToast(err.message || 'Error removing member', 'danger');
                        }
                    }
                });
            }

            listContainer.appendChild(row);
        });
    }
}

/**
 * Handle User Profile & Settings Modal
 */
function initUserProfileModal() {
    const triggerBtn = document.getElementById('btn-user-profile');
    const modal = document.getElementById('modal-user-profile');
    const closeBtn = document.getElementById('close-modal-profile');
    const cancelBtn = document.getElementById('cancel-modal-profile');
    const form = document.getElementById('form-user-profile');
    const swatches = document.querySelectorAll('.color-swatch');

    if (!modal) return;

    const openModal = () => modal.classList.add('active');
    const closeModal = () => modal.classList.remove('active');

    if (triggerBtn) triggerBtn.addEventListener('click', openModal);
    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('active')) closeModal();
    });

    // Swatch selection styling
    swatches.forEach(swatch => {
        swatch.addEventListener('click', () => {
            swatches.forEach(s => s.style.borderColor = 'transparent');
            swatch.style.borderColor = '#ffffff';
            const radio = swatch.previousElementSibling;
            if (radio) radio.checked = true;
        });
    });

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = document.getElementById('btn-save-profile');
            const username = document.getElementById('profile-username').value.trim();
            const email = document.getElementById('profile-email').value.trim();
            const avatarColor = form.querySelector('[name="avatar_color"]:checked')?.value || '#6366f1';
            const currentPass = document.getElementById('profile-current-pass').value;
            const newPass = document.getElementById('profile-new-pass').value;

            if (!username || !email) {
                showToast('Username and email cannot be empty.', 'danger');
                return;
            }

            if (newPass && !currentPass) {
                showToast('Please enter your current password to set a new password.', 'danger');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Updating...';

            try {
                const res = await API.projects.updateProfile({
                    username,
                    email,
                    avatar_color: avatarColor,
                    current_password: currentPass,
                    new_password: newPass
                });

                if (res.success && res.data) {
                    showToast('Profile updated successfully!', 'success');
                    closeModal();

                    // Update Navbar UI
                    const navName = document.getElementById('nav-user-name');
                    const navAvatar = document.getElementById('nav-user-avatar');
                    if (navName) navName.textContent = res.data.username;
                    if (navAvatar) {
                        navAvatar.style.backgroundColor = res.data.avatar_color;
                        navAvatar.textContent = res.data.username.substring(0, 2).toUpperCase();
                    }

                    // Clear password fields
                    document.getElementById('profile-current-pass').value = '';
                    document.getElementById('profile-new-pass').value = '';
                } else {
                    showToast(res.error || 'Failed to update profile', 'danger');
                }
            } catch (err) {
                showToast(err.message || 'Error updating profile', 'danger');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Update Profile';
            }
        });
    }
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

    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && menu.classList.contains('active')) {
            menu.classList.remove('active');
        }
    });

    async function loadNotifications() {
        if (!list) return;
        try {
            const res = await API.projects.getNotifications();
            if (res.success && res.data) {
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
            list.innerHTML = '<div style="padding:1.5rem; text-align:center; color:var(--text-muted); font-size:0.85rem;">No notifications yet.</div>';
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

    window.updateNotificationBadge = updateBadge;
}
