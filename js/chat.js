jQuery(document).ready(function ($) {
    function fetchMessages() {
        $.post(auc_ajax.ajax_url, { action: 'auc_fetch_messages' }, function (data) {
            const messagesDiv = $('#auc-messages');
            messagesDiv.html('');
			let lastDate = null;
			data.forEach(msg => {
				const cls = msg.sender_id == 1 ? 'admin' : 'user';
				const msgDate = new Date(msg.created_at);
				const dateStr = msgDate.toLocaleDateString();
				const timeStr = msgDate.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

				if (lastDate !== dateStr) {
					messagesDiv.append(`<div class="msg-date">${dateStr}</div>`);
					lastDate = dateStr;
				}

				messagesDiv.append(`<div class="msg ${cls}">${msg.message}<br><small>${timeStr}</small></div>`);
			});
            messagesDiv.scrollTop(messagesDiv[0].scrollHeight);
        });
    }

	function sendMessage() {
		const msg = $('#auc-input').val();
		if (!msg.trim()) return;
		$.post(auc_ajax.ajax_url, {
			action: 'auc_send_message',
			message: msg
		}, function () {
			$('#auc-input').val('');
			fetchMessages();
		});
	}

	$('#auc-send').on('click', function () {
		sendMessage();
	});

	$('#auc-input').on('keydown', function (e) {
		if (e.key === 'Enter' && !e.shiftKey) {
			e.preventDefault();
			sendMessage();
		}
	});

    fetchMessages();
    setInterval(fetchMessages, 2000);
});
