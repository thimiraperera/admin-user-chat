jQuery(document).ready(function ($) {
    function fetchAdminMessages() {
        const userId = auc_admin_ajax.user_id;
        if (!userId) return;

        $.post(auc_admin_ajax.ajax_url, {
            action: 'auc_fetch_admin_messages',
            user_id: userId
        }, function (data) {
            const container = $('#auc-admin-messages');
            if (container.length) {
                container.html('');
                data.forEach(msg => {
                    const sender = msg.sender_id == 1 ? 'Admin' : 'User';
                    container.append(`<p><strong>${sender}:</strong> ${msg.message}</p>`);
                });
                container.scrollTop(container[0].scrollHeight);
            }
        });
    }

    function sendAdminMessage(message, userId) {
        $.post(auc_admin_ajax.ajax_url, {
            action: 'auc_send_admin_message',
            message: message,
            receiver_id: userId
        }, function () {
            $('#auc-admin-reply').val('');
            fetchAdminMessages();
        });
    }

	function sendAdminMessageWrapper() {
		const message = $('#auc-admin-reply').val();
		if (!message.trim()) return;
		sendAdminMessage(message, auc_admin_ajax.user_id);
	}

	$('#auc-admin-send').on('click', function (e) {
		e.preventDefault();
		sendAdminMessageWrapper();
	});

	$('#auc-admin-reply').on('keydown', function (e) {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			sendAdminMessageWrapper();
		}
	});

    fetchAdminMessages();
    setInterval(fetchAdminMessages, 3000);
});
