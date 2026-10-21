/**
 * Tool Manager - Centralized Fetch API Client & Global UI Utilities
 * Secure CSRF Token Injection, Standard Error Handling, Custom Modals & Toasts
 */

const API = {
    csrfToken: null,

    /**
     * Retrieve CSRF token from meta tag or memory
     */
    getCsrfToken() {
        if (this.csrfToken) return this.csrfToken;
        const meta = document.querySelector('meta[name="csrf-token"]');
        if (meta && meta.content) {
            this.csrfToken = meta.content;
            return this.csrfToken;
        }
        return '';
    },

    /**
     * Core fetch wrapper with JSON serialization, CSRF injection & error parsing
     */
    async request(url, options = {}) {
        const method = (options.method || 'GET').toUpperCase();
        const csrfToken = API.getCsrfToken();

        const defaultHeaders = {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        };

        if (csrfToken && ['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
            defaultHeaders['X-CSRF-Token'] = csrfToken;
        }

        const config = {
            ...options,
            headers: {
                ...defaultHeaders,
                ...(options.headers || {})
            }
        };

        try {
            const response = await fetch(url, config);
            const data = await response.json().catch(() => null);

            if (!response.ok) {
                const errorMsg = data?.error || `HTTP error ${response.status}`;
                throw new Error(errorMsg);
            }

            return data;
        } catch (err) {
            console.error(`API Request Error [${url}]:`, err);
            throw err;
        }
    },

    // ------------------------------------------------------------------------
    // Projects API
    // ------------------------------------------------------------------------
    projects: {
        async getAll() {
            return API.request('api/projects.php');
        },
        async getById(id) {
            return API.request(`api/projects.php?id=${encodeURIComponent(id)}`);
        },
        async create(payload) {
            return API.request('api/projects.php', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
        },
        async update(payload) {
            return API.request('api/projects.php', {
                method: 'PUT',
                body: JSON.stringify(payload)
            });
        },
        async delete(id) {
            return API.request('api/projects.php', {
                method: 'DELETE',
                body: JSON.stringify({ id })
            });
        },
        async getMembers(projectId) {
            return API.request(`api/projects.php?action=get_members&project_id=${encodeURIComponent(projectId)}`);
        },
        async addMember(projectId, userId, role = 'member') {
            return API.request('api/projects.php?action=add_member', {
                method: 'POST',
                body: JSON.stringify({ project_id: projectId, user_id: userId, role })
            });
        },
        async updateMemberRole(projectId, userId, role) {
            return API.request('api/projects.php?action=update_member_role', {
                method: 'PUT',
                body: JSON.stringify({ project_id: projectId, user_id: userId, role })
            });
        },
        async removeMember(projectId, userId) {
            return API.request('api/projects.php?action=remove_member', {
                method: 'DELETE',
                body: JSON.stringify({ project_id: projectId, user_id: userId })
            });
        },
        async searchUsers(query, projectId = null) {
            let url = `api/projects.php?action=search_users&query=${encodeURIComponent(query)}`;
            if (projectId) url += `&project_id=${encodeURIComponent(projectId)}`;
            return API.request(url);
        },
        async getNotifications() {
            return API.request('api/projects.php?action=notifications');
        },
        async markNotificationsRead() {
            return API.request('api/projects.php?action=mark_notifications_read', {
                method: 'POST',
                body: JSON.stringify({})
            });
        },
        async updateProfile(payload) {
            return API.request('api/projects.php?action=update_profile', {
                method: 'PUT',
                body: JSON.stringify(payload)
            });
        }
    },

    // ------------------------------------------------------------------------
    // Columns API
    // ------------------------------------------------------------------------
    columns: {
        async getByProject(projectId) {
            return API.request(`api/columns.php?project_id=${encodeURIComponent(projectId)}`);
        },
        async create(payload) {
            return API.request('api/columns.php', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
        },
        async rename(id, name) {
            return API.request('api/columns.php', {
                method: 'PUT',
                body: JSON.stringify({ id, name })
            });
        },
        async reorder(projectId, columnIds) {
            return API.request('api/columns.php', {
                method: 'PUT',
                body: JSON.stringify({ project_id: projectId, column_ids: columnIds })
            });
        },
        async delete(id) {
            return API.request('api/columns.php', {
                method: 'DELETE',
                body: JSON.stringify({ id })
            });
        }
    },

    // ------------------------------------------------------------------------
    // Tasks API
    // ------------------------------------------------------------------------
    tasks: {
        async getById(id) {
            return API.request(`api/tasks.php?id=${encodeURIComponent(id)}`);
        },
        async create(payload) {
            return API.request('api/tasks.php', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
        },
        async update(payload) {
            return API.request('api/tasks.php', {
                method: 'PUT',
                body: JSON.stringify(payload)
            });
        },
        async move(taskId, newColId, newPos) {
            return API.request('api/tasks.php', {
                method: 'PATCH',
                body: JSON.stringify({
                    task_id: taskId,
                    new_column_id: newColId,
                    new_position: newPos
                })
            });
        },
        async reorderColumn(columnId, taskIds) {
            return API.request('api/tasks.php', {
                method: 'PATCH',
                body: JSON.stringify({
                    action: 'reorder_column',
                    column_id: columnId,
                    task_ids: taskIds
                })
            });
        },
        async delete(id) {
            return API.request('api/tasks.php', {
                method: 'DELETE',
                body: JSON.stringify({ id })
            });
        }
    },

    // ------------------------------------------------------------------------
    // Comments API
    // ------------------------------------------------------------------------
    comments: {
        async getByTask(taskId) {
            return API.request(`api/comments.php?task_id=${encodeURIComponent(taskId)}`);
        },
        async create(taskId, commentText) {
            return API.request('api/comments.php', {
                method: 'POST',
                body: JSON.stringify({ task_id: taskId, comment_text: commentText })
            });
        },
        async delete(id) {
            return API.request('api/comments.php', {
                method: 'DELETE',
                body: JSON.stringify({ id })
            });
        }
    }
};

/**
 * Toast Notification Dispatcher
 */
function showToast(message, type = 'info', duration = 3500) {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.className = 'toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;

    let icon = 'ℹ️';
    if (type === 'success') icon = '✓';
    if (type === 'danger') icon = '✕';
    if (type === 'warning') icon = '⚠️';

    toast.innerHTML = `
        <span class="toast-icon">${icon}</span>
        <div class="toast-message">${escapeHtml(message)}</div>
    `;

    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
        setTimeout(() => toast.remove(), 250);
    }, duration);
}

/**
 * Modern Accessible Confirmation Modal replacing window.confirm()
 * Returns a Promise<boolean>
 */
function showConfirmDialog(title, message, confirmText = 'Confirm', isDanger = true) {
    return new Promise((resolve) => {
        let modal = document.getElementById('global-confirm-modal');
        if (!modal) {
            modal = document.createElement('div');
            modal.id = 'global-confirm-modal';
            modal.className = 'modal-backdrop';
            modal.innerHTML = `
                <div class="modal-container" style="max-width: 440px;">
                    <div class="modal-header">
                        <h3 class="modal-title" id="confirm-modal-title">Confirm Action</h3>
                        <button type="button" class="modal-close" id="confirm-modal-close">✕</button>
                    </div>
                    <div class="modal-body">
                        <p id="confirm-modal-message" style="color:var(--text-secondary); line-height:1.5; font-size:0.925rem;"></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" id="confirm-modal-cancel">Cancel</button>
                        <button type="button" class="btn" id="confirm-modal-ok">Confirm</button>
                    </div>
                </div>
            `;
            document.body.appendChild(modal);
        }

        const titleEl = document.getElementById('confirm-modal-title');
        const msgEl = document.getElementById('confirm-modal-message');
        const okBtn = document.getElementById('confirm-modal-ok');
        const cancelBtn = document.getElementById('confirm-modal-cancel');
        const closeBtn = document.getElementById('confirm-modal-close');

        titleEl.textContent = title;
        msgEl.textContent = message;
        okBtn.textContent = confirmText;
        okBtn.className = isDanger ? 'btn btn-danger' : 'btn btn-primary';

        const cleanup = (confirmed) => {
            modal.classList.remove('active');
            okBtn.onclick = null;
            cancelBtn.onclick = null;
            closeBtn.onclick = null;
            modal.onclick = null;
            resolve(confirmed);
        };

        okBtn.onclick = () => cleanup(true);
        cancelBtn.onclick = () => cleanup(false);
        closeBtn.onclick = () => cleanup(false);
        modal.onclick = (e) => {
            if (e.target === modal) cleanup(false);
        };

        modal.classList.add('active');
        okBtn.focus();
    });
}

/**
 * Utility: Escape HTML string to prevent XSS
 */
function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(str).replace(/[&<>"']/g, m => map[m]);
}
