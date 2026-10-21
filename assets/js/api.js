/**
 * Tool Manager - Centralized Fetch API Client & Global UI Utilities
 */

const API = {
    /**
     * Core fetch wrapper with JSON serialization & error parsing
     */
    async request(url, options = {}) {
        const defaultHeaders = {
            'Content-Type': 'application/json',
            'Accept': 'application/json'
        };

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
        async addMember(projectId, userId, role = 'member') {
            return API.request('api/projects.php?action=add_member', {
                method: 'POST',
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

    toast.innerHTML = `
        <span style="font-weight:700; font-size:1.1rem;">${icon}</span>
        <div style="flex:1; font-size:0.875rem;">${escapeHtml(message)}</div>
    `;

    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(10px)';
        setTimeout(() => toast.remove(), 250);
    }, duration);
}

/**
 * Utility: Escape HTML string to prevent XSS
 */
function escapeHtml(str) {
    if (!str) return '';
    const map = {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    };
    return String(str).replace(/[&<>"']/g, m => map[m]);
}
