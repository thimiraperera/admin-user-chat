jQuery(document).ready(function ($) {

    let isInitialLoad = true;

    // Configuration variables from PHP
    const ajaxUrl = auc_admin_ajax.ajax_url;
    const nonce = auc_admin_ajax.nonce;
    const userId = auc_admin_ajax.user_id;
    const adminId = auc_admin_ajax.admin_id;
    
    // DOM elements
    const messagesContainer = $('#auc-admin-messages');
    const replyField = $('#auc-admin-reply');
    const sendButton = $('#auc-admin-send');
    const deleteButton = $('#auc-admin-delete');
    
    // Function to fetch messages (for polling)
    function fetchAdminMessages() {
        if (!userId) return;
        
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: {
                action: 'auc_fetch_admin_messages',
                user_id: userId,
                admin_id: adminId,
                nonce: nonce
            },
            dataType: 'json',
            success: function (data) {
                if (data && !data.error) {
                    if (isInitialLoad) {
                        messagesContainer.empty();
                        isInitialLoad = false;
                    }
                    renderAdminMessages(data);
                }
            },
            error: function (xhr, status, error) {
                console.error('Error fetching messages:', error);
            }
        });
    }

    if (userId) {
        fetchAdminMessages(); // Initial load
        setInterval(fetchAdminMessages, 10000);
    }
    
    // Function to render new messages only (for polling)
    function renderAdminMessages(messages) {
        if (messages.length === 0) return;
        
        const existingIds = [];
        messagesContainer.find('.msg').each(function() {
            existingIds.push($(this).data('id'));
        });
        
        let lastDate = messagesContainer.find('.msg-date').last().text();
        let addedNew = false;
        
        messages.forEach(msg => {
            // Skip if message already exists
            if (existingIds.includes(msg.id)) return;
            
            addedNew = true;
            const sender = msg.sender_id == adminId ? 'admin' : 'user';
            const msgDate = new Date(msg.created_at);
            const dateStr = msgDate.toLocaleDateString();
            const timeStr = msgDate.toLocaleTimeString([], { 
                hour: '2-digit', 
                minute: '2-digit' 
            });
            
            // Add date separator if needed
            if (lastDate !== dateStr) {
                messagesContainer.append(`<div class="msg-date">${dateStr}</div>`);
                lastDate = dateStr;
            }
            
            // Add new message
            const escapedMessage = $('<div>').text(msg.message).html();
            const formattedMessage = escapedMessage.replace(/\n/g, '<br>');
            
            messagesContainer.append(`
                <div class="msg ${sender}" data-id="${msg.id}">
                    <strong>${sender === 'admin' ? 'Admin' : 'User'}:</strong> 
                    ${formattedMessage}
                    <br><small>${timeStr}</small>
                </div>
            `);
        });
        
        // Scroll to bottom if new messages were added
        if (addedNew) {
            messagesContainer.scrollTop(messagesContainer[0].scrollHeight);
        }
    }
    
    // Function to send message
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
            dataType: 'json',
            beforeSend: function() {
                sendButton.prop('disabled', true);
            },
            success: function (data) {
                if (data.success) {
                    replyField.val('');
                    fetchAdminMessages(); // Refresh to show new message
                } else {
                    console.error('Error sending message:', data.error);
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX error:', error);
            },
            complete: function() {
                sendButton.prop('disabled', false);
            }
        });
    }
    
    // Function to delete chat history
    function deleteChatHistory() {
        if (!confirm('Are you sure you want to delete this entire chat history?')) return;
        
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: {
                action: 'auc_delete_chat',
                user_id: userId,
                nonce: nonce
            },
            dataType: 'json',
            beforeSend: function() {
                deleteButton.prop('disabled', true);
            },
            success: function (data) {
                if (data.success) {
                    alert('Chat history deleted!');
                    location.reload();
                } else {
                    alert('Error: ' + (data.error || 'Failed to delete chat history.'));
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX error:', error);
                alert('Error: ' + error);
            },
            complete: function() {
                deleteButton.prop('disabled', false);
            }
        });
    }
    
    // Event handlers
    sendButton.on('click', function (e) {
        e.preventDefault();
        sendAdminMessage();
    });
    
    replyField.on('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendAdminMessage();
        }
    });
    
    deleteButton.on('click', function () {
        deleteChatHistory();
    });
    
    // Only set up polling if we're in a chat
    if (userId) {
        setInterval(fetchAdminMessages, 10000); // 10 seconds
    }
});