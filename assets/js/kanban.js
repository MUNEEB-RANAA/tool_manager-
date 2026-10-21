/**
 * Tool Manager - Advanced Flow Kanban Board Engine
 * Native HTML5 Drag and Drop API, Optimistic UI Updates, Task Modals, Multi-Filter, and Collaboration
 */

let currentProjectId = null;
let currentProjectData = null;
let currentlyOpenTaskId = null;
let draggedTaskCard = null;
let dragPlaceholder = null;

document.addEventListener('DOMContentLoaded', () => {
    const boardContainer = document.getElementById('kanban-board-container');
    if (!boardContainer) return;

    currentProjectId = parseInt(boardContainer.dataset.projectId, 10);
    if (!currentProjectId) return;

    initKanbanEventListeners();
    initTaskDetailModal();
    initNewTaskModal();
    initNewColumnModal();
    initFilterBar();
    initProjectBoardHeaderButtons();
});

/**
 * Initialize Drag and Drop + Board Event Delegations
 */
function initKanbanEventListeners() {
    const boardContainer = document.getElementById('kanban-board-container');
    if (!boardContainer) return;

    // Create reusable drag placeholder
    dragPlaceholder = document.createElement('div');
    dragPlaceholder.className = 'drag-placeholder';

    // 1. Drag Start (Delegated on Task Cards)
    boardContainer.addEventListener('dragstart', (e) => {
        const card = e.target.closest('.task-card');
        if (!card) return;

        draggedTaskCard = card;
        e.dataTransfer.setData('text/plain', card.dataset.taskId);
        e.dataTransfer.effectAllowed = 'move';

        setTimeout(() => {
            if (draggedTaskCard) {
                draggedTaskCard.classList.add('dragging');
            }
        }, 0);
    });

    // 2. Drag End
    boardContainer.addEventListener('dragend', (e) => {
        const card = e.target.closest('.task-card');
        if (card) {
            card.classList.remove('dragging');
        }
        if (dragPlaceholder && dragPlaceholder.parentNode) {
            dragPlaceholder.remove();
        }
        document.querySelectorAll('.kanban-column').forEach(col => {
            col.classList.remove('drag-over-column');
        });
        updateEmptyStates();
        draggedTaskCard = null;
    });

    // 3. Drag Over & Enter on Columns
    boardContainer.addEventListener('dragover', (e) => {
        const column = e.target.closest('.kanban-column');
        if (!column || !draggedTaskCard) return;

        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';

        column.classList.add('drag-over-column');

        const container = column.querySelector('.column-tasks-container');
        if (!container) return;

        // Hide empty state if present while hovering
        const emptyState = container.querySelector('.column-empty-state');
        if (emptyState) {
            emptyState.style.display = 'none';
        }

        const afterElement = getDragAfterElement(container, e.clientY);
        if (afterElement == null) {
            container.appendChild(dragPlaceholder);
        } else {
            container.insertBefore(dragPlaceholder, afterElement);
        }
    });

    boardContainer.addEventListener('dragleave', (e) => {
        const column = e.target.closest('.kanban-column');
        if (column && !column.contains(e.relatedTarget)) {
            column.classList.remove('drag-over-column');
            const container = column.querySelector('.column-tasks-container');
            if (container) {
                const emptyState = container.querySelector('.column-empty-state');
                const hasCards = container.querySelectorAll('.task-card:not(.dragging)').length > 0;
                if (emptyState && !hasCards) {
                    emptyState.style.display = '';
                }
            }
        }
    });

    // 4. Drop Event (Optimistic DOM Reordering + Persist via API)
    boardContainer.addEventListener('drop', async (e) => {
        const column = e.target.closest('.kanban-column');
        if (!column || !draggedTaskCard) return;

        e.preventDefault();
        column.classList.remove('drag-over-column');

        const container = column.querySelector('.column-tasks-container');
        if (!container) return;

        const taskId = parseInt(draggedTaskCard.dataset.taskId, 10);
        const sourceColId = parseInt(draggedTaskCard.dataset.columnId, 10);
        const targetColId = parseInt(column.dataset.columnId, 10);

        // Optimistic UI placement: replace placeholder with actual dragged card
        if (dragPlaceholder && dragPlaceholder.parentNode === container) {
            container.insertBefore(draggedTaskCard, dragPlaceholder);
            dragPlaceholder.remove();
        } else {
            container.appendChild(draggedTaskCard);
        }

        // Calculate new 0-indexed position
        const taskCardsInContainer = Array.from(container.querySelectorAll('.task-card'));
        const newPos = taskCardsInContainer.indexOf(draggedTaskCard);

        // Update dataset on card
        draggedTaskCard.dataset.columnId = targetColId;

        // Sync empty states and column counters across board
        updateEmptyStates();
        updateColumnTaskCounts();

        // Persist change via PATCH API call
        try {
            await API.tasks.move(taskId, targetColId, newPos);
        } catch (err) {
            console.error('Failed to persist task drag & drop:', err);
            showToast('Failed to save task move. Reverting...', 'danger');
            // Re-fetch board state from server on conflict
            refreshBoardFromServer();
        }
    });

    // 5. Card Click -> Open Detail Modal
    boardContainer.addEventListener('click', (e) => {
        // Prevent opening modal if clicking dropdowns or action buttons
        if (e.target.closest('.btn') || e.target.closest('.dropdown-menu') || e.target.closest('button')) {
            return;
        }

        const card = e.target.closest('.task-card');
        if (card) {
            const taskId = parseInt(card.dataset.taskId, 10);
            if (taskId) {
                openTaskDetailModal(taskId);
            }
        }
    });
}

/**
 * Calculates which sibling element to insert before based on vertical cursor position
 */
function getDragAfterElement(container, y) {
    const draggableElements = [...container.querySelectorAll('.task-card:not(.dragging)')];

    return draggableElements.reduce((closest, child) => {
        const box = child.getBoundingClientRect();
        const offset = y - box.top - box.height / 2;

        if (offset < 0 && offset > closest.offset) {
            return { offset: offset, element: child };
        } else {
            return closest;
        }
    }, { offset: Number.NEGATIVE_INFINITY }).element;
}

/**
 * Ensure empty state is properly displayed for columns without tasks
 */
function updateEmptyStates() {
    document.querySelectorAll('.kanban-column').forEach(col => {
        const container = col.querySelector('.column-tasks-container');
        if (!container) return;
        const taskCards = container.querySelectorAll('.task-card');
        let emptyState = container.querySelector('.column-empty-state');

        if (taskCards.length === 0) {
            if (!emptyState) {
                const div = document.createElement('div');
                div.className = 'column-empty-state';
                div.innerHTML = `
                    <div style="font-size:1.5rem; opacity:0.6; margin-bottom:0.25rem;">📋</div>
                    <div style="font-size:0.8rem; color:var(--text-muted);">No tasks in this stage</div>
                `;
                container.appendChild(div);
            } else {
                emptyState.style.display = '';
            }
        } else if (emptyState) {
            emptyState.remove();
        }
    });
}

/**
 * Recount tasks per column in the UI
 */
function updateColumnTaskCounts() {
    document.querySelectorAll('.kanban-column').forEach(col => {
        const count = col.querySelectorAll('.task-card').length;
        const counter = col.querySelector('.column-counter');
        if (counter) counter.textContent = count;
    });
}

/**
 * Move Column Position Left (-1) or Right (+1) and persist via API
 */
window.moveColumnOrder = async function (columnId, direction) {
    if (!currentProjectData || !currentProjectData.columns) return;
    const cols = [...currentProjectData.columns];
    const idx = cols.findIndex(c => parseInt(c.id, 10) === parseInt(columnId, 10));
    if (idx === -1) return;

    const targetIdx = idx + direction;
    if (targetIdx < 0 || targetIdx >= cols.length) return;

    // Swap columns in array
    const temp = cols[idx];
    cols[idx] = cols[targetIdx];
    cols[targetIdx] = temp;

    // Optimistically update and render
    currentProjectData.columns = cols;
    renderBoard(currentProjectData);

    try {
        const colIds = cols.map(c => c.id);
        await API.columns.reorder(currentProjectId, colIds);
        showToast('Column order updated', 'success');
    } catch (err) {
        showToast(err.message || 'Failed to reorder columns', 'danger');
        refreshBoardFromServer();
    }
};

/**
 * Render complete Board state from server snapshot (used by Initial Load and SSE events)
 */
function renderBoard(boardData) {
    if (!boardData || !boardData.columns) return;
    currentProjectData = boardData;

    const boardContainer = document.getElementById('kanban-board-container');
    if (!boardContainer) return;

    // Preserve open modal state or active dragging without interruption
    if (draggedTaskCard) {
        return; // Skip re-rendering during an active drag operation
    }

    const totalCols = boardData.columns.length;

    // Build columns HTML
    let html = '';
    boardData.columns.forEach((col, idx) => {
        const taskCount = col.tasks ? col.tasks.length : 0;
        const isFirst = (idx === 0);
        const isLast = (idx === totalCols - 1);

        html += `
            <div class="kanban-column" id="column-${col.id}" data-column-id="${col.id}">
                <div class="column-header">
                    <div style="display:flex; align-items:center; gap:0.35rem; min-width:0; flex:1;">
                        <!-- Column Reorder Arrows -->
                        <div style="display:inline-flex; align-items:center; gap:0.1rem; flex-shrink:0;">
                            <button type="button" class="col-move-btn" title="Move column left" onclick="moveColumnOrder(${col.id}, -1)" ${isFirst ? 'disabled style="opacity:0.25; cursor:not-allowed;"' : ''}>◀</button>
                            <button type="button" class="col-move-btn" title="Move column right" onclick="moveColumnOrder(${col.id}, 1)" ${isLast ? 'disabled style="opacity:0.25; cursor:not-allowed;"' : ''}>▶</button>
                        </div>

                        <div class="column-title-group" onclick="editColumnTitle(${col.id}, '${escapeHtml(col.name)}')" title="Click to rename stage">
                            <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">${escapeHtml(col.name)}</span>
                            <span class="column-counter">${taskCount}</span>
                        </div>
                    </div>

                    <div class="column-actions">
                        <button type="button" class="btn btn-ghost btn-sm btn-icon" title="Add Task" onclick="openNewTaskModal(${col.id}, '${escapeHtml(col.name)}')">
                            <span style="font-size:1.1rem; line-height:1;">+</span>
                        </button>
                        <button type="button" class="btn btn-ghost btn-sm btn-icon" title="Delete Column" onclick="deleteColumn(${col.id}, '${escapeHtml(col.name)}')">
                            <span style="font-size:0.9rem; line-height:1; color:var(--text-muted);">✕</span>
                        </button>
                    </div>
                </div>

                <div class="column-tasks-container" data-column-id="${col.id}">
                    ${renderTaskCards(col.tasks || [])}
                </div>

                <button type="button" class="add-task-btn" onclick="openNewTaskModal(${col.id}, '${escapeHtml(col.name)}')">
                    <span>+ Add Task</span>
                </button>
            </div>
        `;
    });

    // Add Column Placeholder Card at the end
    html += `
        <div class="add-column-card" id="btn-add-column-card" onclick="openNewColumnModal()" title="Add another stage">
            <div style="width:26px; height:26px; border-radius:var(--radius-sm); background:rgba(255,255,255,0.05); display:flex; align-items:center; justify-content:center; color:var(--text-secondary); font-size:0.9rem; font-weight:600;">+</div>
            <span style="font-weight:500; font-size:0.8rem; color:var(--text-secondary);">New Column</span>
        </div>
    `;

    boardContainer.innerHTML = html;
    updateEmptyStates();
}

/**
 * Render task cards HTML inside a column
 */
function renderTaskCards(tasks) {
    if (!tasks || tasks.length === 0) return '';

    return tasks.map(task => {
        const priority = task.priority || 'medium';
        const assignees = task.assignees || [];
        const commentsCount = task.comments_count || 0;
        const assigneeIds = assignees.map(a => a.id).join(',');

        // Due date formatting & badge
        let dueStatus = 'none';
        let dueDateHtml = '';
        if (task.due_date) {
            const due = new Date(task.due_date);
            const today = new Date();
            today.setHours(0, 0, 0, 0);

            const dueDay = new Date(due);
            dueDay.setHours(0, 0, 0, 0);

            const dueFormatted = due.toLocaleDateString([], { month: 'short', day: 'numeric' });

            if (dueDay < today) {
                dueStatus = 'overdue';
                dueDateHtml = `<span class="task-due-date overdue">${dueFormatted}</span>`;
            } else if (dueDay.getTime() === today.getTime()) {
                dueStatus = 'today';
                dueDateHtml = `<span class="task-due-date today">Today</span>`;
            } else {
                dueStatus = 'upcoming';
                dueDateHtml = `<span class="task-due-date">${dueFormatted}</span>`;
            }
        }

        // Assignees Stack
        let assigneesHtml = '';
        if (assignees.length > 0) {
            assigneesHtml = '<div class="avatar-group">';
            assignees.slice(0, 3).forEach(u => {
                assigneesHtml += `
                    <div class="avatar avatar-sm" style="background-color:${u.avatar_color || '#4f46e5'};" title="${escapeHtml(u.username)}">
                        ${u.username.substring(0, 2).toUpperCase()}
                    </div>
                `;
            });
            if (assignees.length > 3) {
                assigneesHtml += `<div class="avatar avatar-sm" style="background:#272b3d;">+${assignees.length - 3}</div>`;
            }
            assigneesHtml += '</div>';
        }

        // Comments Indicator (only if > 0)
        let commentsHtml = '';
        if (commentsCount > 0) {
            commentsHtml = `
                <span class="card-indicator-item" title="${commentsCount} comments">
                    <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="opacity:0.7;"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
                    <span>${commentsCount}</span>
                </span>
            `;
        }

        let metaBottomHtml = '';
        if (commentsCount > 0 || assignees.length > 0) {
            metaBottomHtml = `
                <div class="task-card-footer card-meta-bottom">
                    <div class="task-card-indicators card-indicators">
                        ${commentsHtml}
                    </div>
                    ${assigneesHtml}
                </div>
            `;
        }

        return `
            <div class="task-card" id="task-card-${task.id}" data-task-id="${task.id}" data-column-id="${task.column_id}" data-priority="${priority}" data-assignee-ids="${assigneeIds}" data-due-status="${dueStatus}" draggable="true">
                <div class="task-card-header card-meta-top">
                    <span class="priority-badge priority-${priority}">${priority}</span>
                    ${dueDateHtml}
                </div>

                <div class="task-card-title task-title">${escapeHtml(task.title)}</div>

                ${task.description ? `<div class="task-card-desc task-desc-preview">${escapeHtml(task.description)}</div>` : ''}

                ${metaBottomHtml}
            </div>
        `;
    }).join('');
}

/**
 * Fetch and re-render board from server
 */
async function refreshBoardFromServer() {
    if (!currentProjectId) return;
    try {
        const res = await API.projects.getById(currentProjectId);
        if (res.success && res.data) {
            renderBoard(res.data);
        }
    } catch (err) {
        console.error('Error fetching board:', err);
    }
}

// ============================================================================
// Task Detail Modal & Live Commenting Flow
// ============================================================================
function initTaskDetailModal() {
    const modal = document.getElementById('modal-task-detail');
    const closeBtn = document.getElementById('close-modal-task-detail');
    const commentForm = document.getElementById('form-add-comment');
    const deleteTaskBtn = document.getElementById('btn-delete-task');
    const saveTaskBtn = document.getElementById('btn-save-task-detail');

    if (!modal) return;

    const closeModal = () => {
        modal.classList.remove('active');
        currentlyOpenTaskId = null;
    };

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => {
        if (e.target === modal) closeModal();
    });

    // Close on Escape key
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && modal.classList.contains('active')) closeModal();
    });

    // Post Comment Form Handler
    if (commentForm) {
        commentForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const textarea = commentForm.querySelector('[name="comment_text"]');
            const submitBtn = commentForm.querySelector('button[type="submit"]');
            const text = textarea.value.trim();

            if (!text || !currentlyOpenTaskId) return;

            submitBtn.disabled = true;
            try {
                const res = await API.comments.create(currentlyOpenTaskId, text);
                if (res.success && res.data) {
                    appendCommentToModal(res.data);
                    textarea.value = '';

                    // Update comment count on task card
                    const card = document.getElementById(`task-card-${currentlyOpenTaskId}`);
                    if (card) {
                        const countEl = card.querySelector('.card-indicators span span');
                        if (countEl) {
                            countEl.textContent = parseInt(countEl.textContent || '0', 10) + 1;
                        }
                    }
                }
            } catch (err) {
                showToast(err.message || 'Failed to post comment', 'danger');
            } finally {
                submitBtn.disabled = false;
            }
        });
    }

    // Save Task Changes
    if (saveTaskBtn) {
        saveTaskBtn.addEventListener('click', async () => {
            if (!currentlyOpenTaskId) return;

            const titleInput = document.getElementById('task-detail-title');
            const descInput = document.getElementById('task-detail-desc');
            const priorityInput = document.getElementById('task-detail-priority');
            const dueInput = document.getElementById('task-detail-due');

            // Collect selected assignees
            const selectedAssignees = [];
            document.querySelectorAll('.task-assignee-checkbox:checked').forEach(cb => {
                selectedAssignees.push(parseInt(cb.value, 10));
            });

            try {
                saveTaskBtn.disabled = true;
                saveTaskBtn.textContent = 'Saving...';

                await API.tasks.update({
                    task_id: currentlyOpenTaskId,
                    title: titleInput.value.trim(),
                    description: descInput.value.trim(),
                    priority: priorityInput.value,
                    due_date: dueInput.value || null,
                    assignee_ids: selectedAssignees
                });

                showToast('Task updated successfully!', 'success');
                closeModal();
                refreshBoardFromServer();
            } catch (err) {
                showToast(err.message || 'Failed to update task', 'danger');
            } finally {
                saveTaskBtn.disabled = false;
                saveTaskBtn.textContent = 'Save Changes';
            }
        });
    }

    // Delete Task with Accessible Dialog
    if (deleteTaskBtn) {
        deleteTaskBtn.addEventListener('click', async () => {
            if (!currentlyOpenTaskId) return;

            const confirmed = await showConfirmDialog(
                'Delete Task',
                'Are you sure you want to permanently delete this task and all of its comments?',
                'Delete Task',
                true
            );
            if (!confirmed) return;

            try {
                await API.tasks.delete(currentlyOpenTaskId);
                showToast('Task deleted', 'info');
                const card = document.getElementById(`task-card-${currentlyOpenTaskId}`);
                if (card) card.remove();
                closeModal();
                updateEmptyStates();
                updateColumnTaskCounts();
            } catch (err) {
                showToast(err.message || 'Failed to delete task', 'danger');
            }
        });
    }
}

/**
 * Open Task Detail Modal and Populate Info & Comments
 */
async function openTaskDetailModal(taskId) {
    currentlyOpenTaskId = taskId;
    const modal = document.getElementById('modal-task-detail');
    if (!modal) return;

    modal.classList.add('active');

    try {
        const [taskRes, commentsRes] = await Promise.all([
            API.tasks.getById(taskId),
            API.comments.getByTask(taskId)
        ]);

        if (!taskRes.success || !taskRes.data) {
            showToast('Failed to load task details', 'danger');
            return;
        }

        const task = taskRes.data;

        // Populate fields
        document.getElementById('task-detail-title').value = task.title || '';
        document.getElementById('task-detail-desc').value = task.description || '';
        document.getElementById('task-detail-priority').value = task.priority || 'medium';
        document.getElementById('task-detail-due').value = task.due_date || '';
        document.getElementById('task-detail-column-name').textContent = task.column_name || '';

        // Populate Assignees Selection
        renderAssigneeCheckboxes(task.assignees || []);

        // Populate Comments
        renderCommentsList(commentsRes.data || []);
    } catch (err) {
        console.error('Failed to open task modal:', err);
        showToast('Error opening task', 'danger');
    }
}

/**
 * Render Assignee Checkboxes inside Task Modal Sidebar
 */
function renderAssigneeCheckboxes(currentAssignees) {
    const container = document.getElementById('task-detail-assignees-list');
    if (!container || !currentProjectData || !currentProjectData.members) return;

    const assignedIds = new Set(currentAssignees.map(a => parseInt(a.id, 10)));

    let html = '';
    currentProjectData.members.forEach(member => {
        const isChecked = assignedIds.has(parseInt(member.id, 10)) ? 'checked' : '';
        html += `
            <label style="display:flex; align-items:center; gap:0.6rem; font-size:0.85rem; cursor:pointer; padding:0.25rem 0;">
                <input type="checkbox" class="task-assignee-checkbox" value="${member.id}" ${isChecked} style="accent-color:var(--primary);">
                <div class="avatar avatar-sm" style="background-color:${member.avatar_color || '#6366f1'}; width:22px; height:22px; font-size:0.6rem;">
                    ${member.username.substring(0, 2).toUpperCase()}
                </div>
                <span style="color:var(--text-primary); font-weight:500;">${escapeHtml(member.username)}</span>
            </label>
        `;
    });

    container.innerHTML = html;
}

/**
 * Render Comments Thread in Modal
 */
function renderCommentsList(comments) {
    const container = document.getElementById('task-comments-feed');
    if (!container) return;

    if (comments.length === 0) {
        container.innerHTML = '<div style="color:var(--text-muted); font-size:0.85rem; text-align:center; padding:1rem;">No comments yet. Start the discussion!</div>';
        return;
    }

    container.innerHTML = comments.map(c => buildCommentBubble(c)).join('');
    container.scrollTop = container.scrollHeight;
}

function buildCommentBubble(c) {
    const timeFormatted = new Date(c.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    return `
        <div class="comment-bubble" id="comment-${c.id}">
            <div class="avatar avatar-sm" style="background-color:${c.avatar_color || '#6366f1'};">
                ${c.username.substring(0, 2).toUpperCase()}
            </div>
            <div class="comment-content">
                <div class="comment-author-row">
                    <span class="comment-author-name">${escapeHtml(c.username)}</span>
                    <span class="comment-timestamp">${timeFormatted}</span>
                </div>
                <div class="comment-body">${escapeHtml(c.comment_text)}</div>
            </div>
        </div>
    `;
}

function appendCommentToModal(comment) {
    const container = document.getElementById('task-comments-feed');
    if (!container) return;

    if (container.textContent.includes('No comments yet')) {
        container.innerHTML = '';
    }

    if (document.getElementById(`comment-${comment.id}`)) return;

    const div = document.createElement('div');
    div.innerHTML = buildCommentBubble(comment);
    container.appendChild(div.firstElementChild);
    container.scrollTop = container.scrollHeight;
}

// ============================================================================
// Create New Task Modal Logic
// ============================================================================
function initNewTaskModal() {
    const modal = document.getElementById('modal-new-task');
    const closeBtn = document.getElementById('close-modal-new-task');
    const cancelBtn = document.getElementById('cancel-modal-new-task');
    const form = document.getElementById('form-new-task');

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

    window.openNewTaskModal = function (columnId, columnName) {
        document.getElementById('new-task-column-id').value = columnId;
        document.getElementById('new-task-column-name').textContent = columnName;

        // Render project members checkboxes for new task assignment
        const assigneesWrap = document.getElementById('new-task-assignees-wrap');
        if (assigneesWrap && currentProjectData && currentProjectData.members) {
            let html = '';
            currentProjectData.members.forEach(m => {
                html += `
                    <label style="display:inline-flex; align-items:center; gap:0.4rem; font-size:0.8rem; background:var(--bg-secondary); padding:0.35rem 0.65rem; border-radius:var(--radius-sm); border:1px solid var(--border-color); cursor:pointer;">
                        <input type="checkbox" name="new_task_assignees" value="${m.id}" style="accent-color:var(--primary);">
                        <span>${escapeHtml(m.username)}</span>
                    </label>
                `;
            });
            assigneesWrap.innerHTML = html;
        }

        modal.classList.add('active');
        const titleInput = form.querySelector('[name="title"]');
        if (titleInput) titleInput.focus();
    };

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = form.querySelector('button[type="submit"]');
            const colId = parseInt(document.getElementById('new-task-column-id').value, 10);
            const title = form.querySelector('[name="title"]').value.trim();
            const description = form.querySelector('[name="description"]').value.trim();
            const priority = form.querySelector('[name="priority"]').value;
            const due_date = form.querySelector('[name="due_date"]').value || null;

            const selectedAssignees = [];
            form.querySelectorAll('[name="new_task_assignees"]:checked').forEach(cb => {
                selectedAssignees.push(parseInt(cb.value, 10));
            });

            if (!title) {
                showToast('Please enter a task title', 'danger');
                return;
            }

            submitBtn.disabled = true;
            submitBtn.textContent = 'Creating...';

            try {
                const res = await API.tasks.create({
                    column_id: colId,
                    title,
                    description,
                    priority,
                    due_date,
                    assignee_ids: selectedAssignees
                });

                if (res.success && res.data) {
                    showToast('Task created successfully!', 'success');
                    closeModal();

                    // Optimistically append card to column
                    const colContainer = document.querySelector(`.column-tasks-container[data-column-id="${colId}"]`);
                    if (colContainer) {
                        const tempDiv = document.createElement('div');
                        tempDiv.innerHTML = renderTaskCards([res.data]);
                        colContainer.appendChild(tempDiv.firstElementChild);
                        updateEmptyStates();
                        updateColumnTaskCounts();
                    }
                }
            } catch (err) {
                showToast(err.message || 'Failed to create task', 'danger');
            } finally {
                submitBtn.disabled = false;
                submitBtn.textContent = 'Create Task';
            }
        });
    }
}

// ============================================================================
// Column Operations (Create, Rename, Delete)
// ============================================================================
function initNewColumnModal() {
    const modal = document.getElementById('modal-new-column');
    const closeBtn = document.getElementById('close-modal-new-column');
    const cancelBtn = document.getElementById('cancel-modal-new-column');
    const form = document.getElementById('form-new-column');

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

    window.openNewColumnModal = function () {
        modal.classList.add('active');
        const input = form.querySelector('[name="name"]');
        if (input) input.focus();
    };

    if (form) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = form.querySelector('button[type="submit"]');
            const name = form.querySelector('[name="name"]').value.trim();

            if (!name || !currentProjectId) return;

            submitBtn.disabled = true;
            try {
                const res = await API.columns.create({
                    project_id: currentProjectId,
                    name
                });

                if (res.success) {
                    showToast('Column added!', 'success');
                    closeModal();
                    refreshBoardFromServer();
                }
            } catch (err) {
                showToast(err.message || 'Failed to create column', 'danger');
            } finally {
                submitBtn.disabled = false;
            }
        });
    }
}

window.editColumnTitle = async function (columnId, oldName) {
    const newName = prompt('Enter new column name:', oldName);
    if (newName && newName.trim() !== '' && newName.trim() !== oldName) {
        try {
            await API.columns.rename(columnId, newName.trim());
            showToast('Column renamed', 'success');
            const col = document.getElementById(`column-${columnId}`);
            if (col) {
                const titleSpan = col.querySelector('.column-title-group span:first-child');
                if (titleSpan) titleSpan.textContent = newName.trim();
            }
        } catch (err) {
            showToast(err.message || 'Failed to rename column', 'danger');
        }
    }
};

window.deleteColumn = async function (columnId, columnName) {
    const confirmed = await showConfirmDialog(
        'Delete Column',
        `Are you sure you want to delete column "${columnName}" and all of its tasks? This action cannot be undone.`,
        'Delete Column',
        true
    );
    if (!confirmed) return;

    try {
        await API.columns.delete(columnId);
        showToast('Column deleted', 'info');
        const col = document.getElementById(`column-${columnId}`);
        if (col) col.remove();

        // Update in-memory project data
        if (currentProjectData && currentProjectData.columns) {
            currentProjectData.columns = currentProjectData.columns.filter(c => parseInt(c.id, 10) !== parseInt(columnId, 10));
            renderBoard(currentProjectData);
        }
    } catch (err) {
        showToast(err.message || 'Failed to delete column', 'danger');
    }
};

// ============================================================================
// Multi-Dimensional Board Search & Filter Bar
// ============================================================================
function initFilterBar() {
    const searchInput = document.getElementById('board-search-input');
    const priorityFilter = document.getElementById('board-priority-filter');
    const assigneeFilter = document.getElementById('board-assignee-filter');
    const dueFilter = document.getElementById('board-due-filter');
    const clearBtn = document.getElementById('btn-clear-filters');
    const boardContainer = document.getElementById('kanban-board-container');

    if (!searchInput && !priorityFilter && !assigneeFilter && !dueFilter) return;

    const filterTasks = () => {
        const query = (searchInput?.value || '').toLowerCase().trim();
        const selectedPriority = priorityFilter?.value || 'all';
        const selectedAssignee = assigneeFilter?.value || 'all';
        const selectedDue = dueFilter?.value || 'all';
        const currentUserId = boardContainer ? boardContainer.dataset.currentUserId : null;

        const hasActiveFilters = (query !== '' || selectedPriority !== 'all' || selectedAssignee !== 'all' || selectedDue !== 'all');
        if (clearBtn) {
            clearBtn.style.display = hasActiveFilters ? 'inline-flex' : 'none';
        }

        document.querySelectorAll('.task-card').forEach(card => {
            const title = card.querySelector('.task-title')?.textContent.toLowerCase() || '';
            const desc = card.querySelector('.task-desc-preview')?.textContent.toLowerCase() || '';
            const cardPriority = (card.dataset.priority || 'medium').toLowerCase();
            const assigneeIds = (card.dataset.assigneeIds || '').split(',').map(s => s.trim()).filter(Boolean);
            const cardDueStatus = card.dataset.dueStatus || 'none';

            // Text match
            const matchesText = !query || title.includes(query) || desc.includes(query);

            // Priority match
            const matchesPriority = (selectedPriority === 'all') || (cardPriority === selectedPriority.toLowerCase());

            // Assignee match
            let matchesAssignee = true;
            if (selectedAssignee === 'me') {
                matchesAssignee = currentUserId ? assigneeIds.includes(String(currentUserId)) : false;
            } else if (selectedAssignee !== 'all') {
                matchesAssignee = assigneeIds.includes(String(selectedAssignee));
            }

            // Due status match
            let matchesDue = true;
            if (selectedDue !== 'all') {
                matchesDue = (cardDueStatus === selectedDue);
            }

            if (matchesText && matchesPriority && matchesAssignee && matchesDue) {
                card.style.display = 'flex';
            } else {
                card.style.display = 'none';
            }
        });

        updateColumnTaskCounts();
    };

    if (searchInput) searchInput.addEventListener('input', filterTasks);
    if (priorityFilter) priorityFilter.addEventListener('change', filterTasks);
    if (assigneeFilter) assigneeFilter.addEventListener('change', filterTasks);
    if (dueFilter) dueFilter.addEventListener('change', filterTasks);

    if (clearBtn) {
        clearBtn.addEventListener('click', () => {
            if (searchInput) searchInput.value = '';
            if (priorityFilter) priorityFilter.value = 'all';
            if (assigneeFilter) assigneeFilter.value = 'all';
            if (dueFilter) dueFilter.value = 'all';
            filterTasks();
        });
    }
}

/**
 * Connect Project Header Navigation Controls (Edit Project, Team Roster, Invite)
 */
function initProjectBoardHeaderButtons() {
    // 1. Edit Project Details
    const editHeaderBtn = document.getElementById('btn-edit-project-header');
    if (editHeaderBtn) {
        editHeaderBtn.addEventListener('click', () => {
            if (window.openEditProjectModal && currentProjectData && currentProjectData.project) {
                window.openEditProjectModal(
                    currentProjectId,
                    currentProjectData.project.name,
                    currentProjectData.project.description || ''
                );
            }
        });
    }

    // 2. Team Roster & Role Manager
    const rosterBtn = document.getElementById('btn-manage-team-roster');
    if (rosterBtn) {
        rosterBtn.addEventListener('click', () => {
            if (window.openTeamRosterModal && currentProjectData && currentProjectData.project) {
                window.openTeamRosterModal(currentProjectId, currentProjectData.project.name);
            }
        });
    }

    // 3. Invite Member
    const inviteBtn = document.getElementById('btn-open-invite-board');
    if (inviteBtn) {
        inviteBtn.addEventListener('click', () => {
            if (window.openInviteModal && currentProjectData && currentProjectData.project) {
                window.openInviteModal(currentProjectId, currentProjectData.project.name);
            }
        });
    }
}

// Global hooks for SSE Real-Time Manager
window.onRealTimeBoardUpdate = function (boardData) {
    renderBoard(boardData);
};

window.onRealTimeCommentAdded = function (comment) {
    // If modal is open for this task, append live
    if (currentlyOpenTaskId && parseInt(comment.task_id, 10) === currentlyOpenTaskId) {
        appendCommentToModal(comment);
    }

    // Update comment counter on card
    const card = document.getElementById(`task-card-${comment.task_id}`);
    if (card) {
        const counterEl = card.querySelector('.card-indicators span span');
        if (counterEl) {
            counterEl.textContent = parseInt(counterEl.textContent || '0', 10) + 1;
        }
    }
};
