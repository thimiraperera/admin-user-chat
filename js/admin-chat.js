jQuery(document).ready(function ($) {
    // Configuration
    const ajaxUrl = auc_admin_ajax.ajax_url;
    const nonce = auc_admin_ajax.nonce;
    const userId = auc_admin_ajax.user_id;
    const adminId = auc_admin_ajax.admin_id;
    
    // Elements
    const container = $('#auc-admin-messages');
    const replyField = $('#auc-admin-reply');
    const sendBtn = $('#auc-admin-send');
    const deleteBtn = $('#auc-admin-delete');
    
    // State
    let lastMessageId = 0;
    
    // Initialize
    if (userId) {
        // Extract IDs from PHP-rendered messages
        container.find('.msg').each(function() {
            const id = $(this).data('id');
            if (id > lastMessageId) lastMessageId = id;
        });
        
        // Setup polling with incremental updates
        fetchNewMessages();
        setInterval(fetchNewMessages, 5000);
    }

    // Fetch only NEW messages
    function fetchNewMessages() {
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: {
                action: 'auc_fetch_admin_messages',
                user_id: userId,
                admin_id: adminId,
                last_id: lastMessageId,
                nonce: nonce
            },
            dataType: 'json',
            success: function(messages) {
                if (messages.length > 0) {
                    renderMessages(messages);
                    // Update last known ID
                    lastMessageId = messages[messages.length-1].id;
                }
            }
        });
    }
    
    // Render new messages (appends only)
    function renderMessages(messages) {
        let lastDate = container.find('.msg-date').last().text() || null;
        
        messages.forEach(msg => {
            const sender = msg.sender_id == adminId ? 'admin' : 'user';
            const msgDate = new Date(msg.created_at);
            const dateStr = msgDate.toLocaleDateString();
            const timeStr = msgDate.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
            
            // Add date header if new day
            if (lastDate !== dateStr) {
                container.append(`<div class="msg-date">${dateStr}</div>`);
                lastDate = dateStr;
            }
            
            // Add message
            const escapedMsg = $('<div>').text(msg.message).html();
            container.append(`
                <div class="msg ${sender}" data-id="${msg.id}">
                    <strong>${sender === 'admin' ? 'Admin' : 'User'}:</strong>
                    ${escapedMsg.replace(/\n/g, '<br>')}
                    <br><small>${timeStr}</small>
                </div>
            `);
        });
        
        // Auto-scroll to new messages
        container.scrollTop(container[0].scrollHeight);
    }
    
    // Send message function (unchanged)
    function sendAdminMessage() {
        const message = replyField.val().trim();
        if (!message) return;
        
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: {
                action: 'auc_send_admin_message',
                message: message,
                receiver_id: userId,
                nonce: nonce
            },
            beforeSend: () => sendBtn.prop('disabled', true),
            success: () => {
                replyField.val('');
                fetchNewMessages(); // Refresh after send
            },
            complete: () => sendBtn.prop('disabled', false)
        });
    }
    
    // Event handlers
    sendBtn.on('click', sendAdminMessage);
    deleteBtn.on('click', deleteChatHistory);
    replyField.on('keydown', e => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendAdminMessage();
        }
    });
    
    // Delete function (unchanged)
    function deleteChatHistory() {
        if (!confirm('Delete entire chat history?')) return;
        
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: {
                action: 'auc_delete_chat',
                user_id: userId,
                nonce: nonce
            },
            beforeSend: () => deleteBtn.prop('disabled', true),
            success: () => location.reload(),
            error: (xhr) => alert('Error: ' + xhr.responseText),
            complete: () => deleteBtn.prop('disabled', false)
        });
    }
});