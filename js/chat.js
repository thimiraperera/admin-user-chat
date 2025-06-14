jQuery(document).ready(function ($) {

    let isInitialLoad = true;

    // Configuration variables from PHP
    const ajaxUrl = auc_ajax.ajax_url;
    const nonce = auc_ajax.nonce;
    const userId = auc_ajax.user_id;
    const adminId = auc_ajax.admin_id;
    
    // DOM elements
    const messagesDiv = $('#auc-messages');
    const inputField = $('#auc-input');
    const sendButton = $('#auc-send');
    const statusDiv = $('#auc-status');
    
    // Function to fetch messages
    function fetchMessages() {
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: {
                action: 'auc_fetch_messages',
                user_id: userId,
                admin_id: adminId,
                nonce: nonce
            },
            dataType: 'json',
            beforeSend: function() {
                if (isInitialLoad) statusDiv.show();
            },
            success: function (data) {
                if (data && !data.error) {
                    if (isInitialLoad) {
                        messagesDiv.empty();
                        isInitialLoad = false;
                    }
                    renderMessages(data);
                }
            },
            error: function (xhr, status, error) {
                console.error('Error fetching messages:', error);
            },
            complete: function() {
                statusDiv.hide();
            }
        });
    }
    
    // Function to render messages
    function renderMessages(messages) {
        messagesDiv.html('');
        let lastDate = null;
        
        messages.forEach(msg => {
            const cls = msg.sender_id == adminId ? 'admin' : 'user';
            const msgDate = new Date(msg.created_at);
            const dateStr = msgDate.toLocaleDateString();
            const timeStr = msgDate.toLocaleTimeString([], { 
                hour: '2-digit', 
                minute: '2-digit' 
            });
            
            // Add date separator if needed
            if (lastDate !== dateStr) {
                messagesDiv.append(`<div class="msg-date">${dateStr}</div>`);
                lastDate = dateStr;
            }
            
            // Add message
            messagesDiv.append(`
                <div class="msg ${cls}">
                    ${msg.message}
                    <br>
                    <small>${timeStr}</small>
                </div>
            `);
        });
        
        // Scroll to bottom
        messagesDiv.scrollTop(messagesDiv[0].scrollHeight);
    }
    
    // Function to send message
    function sendMessage() {
        const message = inputField.val().trim();
        if (!message) return;
        
        $.ajax({
            url: ajaxUrl,
            method: 'POST',
            data: {
                action: 'auc_send_message',
                message: message,
                nonce: nonce
            },
            dataType: 'json',
            beforeSend: function() {
                sendButton.prop('disabled', true);
                statusDiv.show();
            },
            success: function (data) {
                if (data.success) {
                    inputField.val('');
                    fetchMessages();
                } else {
                    console.error('Error sending message:', data.error);
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX error:', error);
            },
            complete: function() {
                sendButton.prop('disabled', false);
                statusDiv.hide();
            }
        });
    }
    
    // Event handlers
    sendButton.on('click', function () {
        sendMessage();
    });
    
    inputField.on('keydown', function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage();
        }
    });
    
    // Initial load and polling
    fetchMessages();
    setInterval(fetchMessages, 10000); // 10 seconds
});