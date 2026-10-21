/**
 * Tool Manager - Real-Time Collaboration Client via Server-Sent Events (SSE)
 * Connects to sse_stream.php and broadcasts live updates to the UI
 */

(function () {
    let eventSource = null;
    let reconnectAttempts = 0;
    const maxReconnectDelay = 10000;

    document.addEventListener('DOMContentLoaded', () => {
        initSSEConnection();
    });

    function initSSEConnection() {
        const boardContainer = document.getElementById('kanban-board-container');
        const projectId = boardContainer ? boardContainer.dataset.projectId : null;

        let streamUrl = 'sse_stream.php';
        if (projectId) {
            streamUrl += `?project_id=${encodeURIComponent(projectId)}`;
        }

        // Close any existing connection
        if (eventSource) {
            eventSource.close();
        }

        const livePill = document.getElementById('realtime-status-pill');

        try {
            eventSource = new EventSource(streamUrl);

            eventSource.onopen = () => {
                reconnectAttempts = 0;
                if (livePill) {
                    livePill.innerHTML = '<span class="pulse-dot"></span> Live Synced';
                    livePill.style.borderColor = 'rgba(16, 185, 129, 0.25)';
                    livePill.style.color = '#34d399';
                }
            };

            // 1. Board snapshot update event
            eventSource.addEventListener('board_update', (e) => {
                try {
                    const data = JSON.parse(e.data);
                    if (window.onRealTimeBoardUpdate) {
                        window.onRealTimeBoardUpdate(data);
                    }
                } catch (err) {
                    console.error('SSE board_update parse error:', err);
                }
            });

            // 2. New comment posted event
            eventSource.addEventListener('comment_added', (e) => {
                try {
                    const comment = JSON.parse(e.data);
                    if (window.onRealTimeCommentAdded) {
                        window.onRealTimeCommentAdded(comment);
                    }
                } catch (err) {
                    console.error('SSE comment_added parse error:', err);
                }
            });

            // 3. User notification event
            eventSource.addEventListener('notification', (e) => {
                try {
                    const data = JSON.parse(e.data);
                    if (window.updateNotificationBadge) {
                        window.updateNotificationBadge(data.unread_count || 0);
                    }
                    if (data.latest && data.latest.message) {
                        showToast(`🔔 ${data.latest.message}`, 'info');
                    }
                } catch (err) {
                    console.error('SSE notification parse error:', err);
                }
            });

            // Error & reconnection handling
            eventSource.onerror = (err) => {
                if (livePill) {
                    livePill.innerHTML = '<span class="pulse-dot" style="background:#f59e0b; box-shadow:none;"></span> Reconnecting...';
                    livePill.style.borderColor = 'rgba(245, 158, 11, 0.3)';
                    livePill.style.color = '#fbbf24';
                }

                eventSource.close();

                // Exponential backoff reconnect
                reconnectAttempts++;
                const delay = Math.min(1000 * Math.pow(1.5, reconnectAttempts), maxReconnectDelay);
                setTimeout(initSSEConnection, delay);
            };

        } catch (e) {
            console.error('Could not initialize EventSource:', e);
        }
    }

    // Clean up on page unload
    window.addEventListener('beforeunload', () => {
        if (eventSource) {
            eventSource.close();
        }
    });
})();
