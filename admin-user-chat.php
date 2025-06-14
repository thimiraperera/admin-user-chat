<?php
/*
Plugin Name: Admin User Chat
Description: Simple chat between admin and logged-in users.
Version: 1.0
Author: Thimira Perera
*/

register_activation_hook(__FILE__, 'auc_install');
function auc_install() {
    global $wpdb;
    $table = $wpdb->prefix . 'auc_messages';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        sender_id BIGINT NOT NULL,
        receiver_id BIGINT NOT NULL,
        message TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) $charset;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Load assets
add_action('wp_enqueue_scripts', function () {
    if (is_user_logged_in()) {
        wp_enqueue_style('auc-style', plugin_dir_url(__FILE__) . 'css/chat.css');
        wp_enqueue_script('auc-script', plugin_dir_url(__FILE__) . 'js/chat.js', ['jquery'], null, true);
        wp_localize_script('auc-script', 'auc_ajax', ['ajax_url' => admin_url('admin-ajax.php')]);
    }
});

add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_auc-user-chats') return;

    wp_enqueue_script('auc-admin-chat', plugin_dir_url(__FILE__) . 'js/admin-chat.js', ['jquery'], null, true);
    wp_localize_script('auc-admin-chat', 'auc_admin_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'user_id' => isset($_GET['user_id']) ? intval($_GET['user_id']) : 0,
    ]);
});

// AJAX Handler for Admin Chat
add_action('wp_ajax_auc_fetch_admin_messages', 'auc_fetch_admin_messages');
function auc_fetch_admin_messages() {
    global $wpdb;
    $user_id = intval($_POST['user_id']);
    $admin_id = 1; // Optional: Replace with auc_get_admin_id() if dynamic
    $table = $wpdb->prefix . 'auc_messages';

    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE 
        (sender_id = %d AND receiver_id = %d) OR 
        (sender_id = %d AND receiver_id = %d) 
        ORDER BY created_at ASC", $user_id, $admin_id, $admin_id, $user_id
    ));
    wp_send_json($messages);
}


// Chat box shortcode
add_shortcode('admin_user_chat', function () {
    if (!is_user_logged_in()) return 'Please log in to use the chat.';
    ob_start(); ?>
    <div id="auc-chat-box">
        <div id="auc-messages"></div>
        <textarea id="auc-input" placeholder="Type your message..."></textarea>
        <button id="auc-send">Send</button>
    </div>
    <?php return ob_get_clean();
});

// Handle AJAX fetch messages
add_action('wp_ajax_auc_fetch_messages', 'auc_fetch_messages');
function auc_fetch_messages() {
    global $wpdb;
    $user_id = get_current_user_id();
    $admin_id = 1; // Admin user ID
    $table = $wpdb->prefix . 'auc_messages';
    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE 
        (sender_id = %d AND receiver_id = %d) OR 
        (sender_id = %d AND receiver_id = %d) 
        ORDER BY created_at ASC", $user_id, $admin_id, $admin_id, $user_id
    ));
    wp_send_json($messages);
}

// Handle AJAX send message
add_action('wp_ajax_auc_send_message', 'auc_send_message');
function auc_send_message() {
    global $wpdb;
    $sender_id = get_current_user_id();
    $receiver_id = $sender_id === 1 ? intval($_POST['receiver_id']) : 1;
    $message = sanitize_text_field($_POST['message']);
    $table = $wpdb->prefix . 'auc_messages';
    $wpdb->insert($table, [
        'sender_id' => $sender_id,
        'receiver_id' => $receiver_id,
        'message' => $message,
    ]);
    wp_send_json(['success' => true]);
}

// Handle AJAX admin message
add_action('wp_ajax_auc_send_admin_message', 'auc_send_admin_message');
function auc_send_admin_message() {
    global $wpdb;
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    $sender_id = get_current_user_id(); // admin
    $receiver_id = intval($_POST['receiver_id']);
    $message = sanitize_text_field($_POST['message']);

    $wpdb->insert($wpdb->prefix . 'auc_messages', [
        'sender_id' => $sender_id,
        'receiver_id' => $receiver_id,
        'message' => $message,
    ]);

    wp_send_json_success();
}

// Delete Chat History
add_action('wp_ajax_auc_delete_chat', 'auc_delete_chat');
function auc_delete_chat() {
    if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');

    global $wpdb;
    $user_id = intval($_POST['user_id']);
    $table = $wpdb->prefix . 'auc_messages';

    $wpdb->query($wpdb->prepare(
        "DELETE FROM $table WHERE (sender_id = %d AND receiver_id = 1) OR (sender_id = 1 AND receiver_id = %d)",
        $user_id, $user_id
    ));

    wp_send_json_success();
}

// Add admin menu
add_action('admin_menu', function () {
    add_menu_page('User Chats', 'User Chats', 'manage_options', 'auc-user-chats', 'auc_admin_chat_page', 'dashicons-format-chat', 25);
});

// Admin chat page content
function auc_admin_chat_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'auc_messages';

    // Get all unique user IDs who messaged admin
    $all_users = get_users([
        'exclude' => [1], // Exclude admin (user ID 1)
        'orderby' => 'display_name',
        'order' => 'ASC',
    ]);

    // Prepare array like the previous $users
    $users = [];

    foreach ($all_users as $user) {
        $last_msg_time = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(created_at) FROM $table WHERE sender_id = %d OR receiver_id = %d",
            $user->ID, $user->ID
        ));
        $users[] = (object)[
            'sender_id' => $user->ID,
            'last_msg_time' => $last_msg_time ?: '',
        ];
    }

    // Sort after loop
    usort($users, function ($a, $b) {
        return strtotime($b->last_msg_time ?: '1970-01-01') - strtotime($a->last_msg_time ?: '1970-01-01');
    });

    echo "<div class='wrap'><h1>User Chats</h1>";

    if (isset($_GET['user_id'])) {
        $user_id = intval($_GET['user_id']);
        $user_info = get_userdata($user_id);

        // Properly get first and last name from user meta
        $first_name = get_user_meta($user_id, 'first_name', true);
        $last_name  = get_user_meta($user_id, 'last_name', true);
        $user_name  = esc_html(trim($first_name . ' ' . $last_name));
        $user_email = esc_html($user_info->user_email);

        // Mark all messages from this user as read
        $wpdb->update(
            $table,
            ['is_read' => 1],
            ['sender_id' => $user_id, 'receiver_id' => 1]
        );

        // Fetch chat messages
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE 
            (sender_id = %d AND receiver_id = 1) OR 
            (sender_id = 1 AND receiver_id = %d) 
            ORDER BY created_at ASC", $user_id, $user_id
        ));

        // Header with full name and email
        echo "<h2 style='margin-bottom: 5px;'>Chat with: <strong>$user_name</strong></h2>";
        echo "<p>Email: <a href='mailto:$user_email'>$user_email</a></p>";

        // Message display area
        echo "<div id='auc-admin-messages' style='max-height:300px; overflow:auto; background:#fff; padding:10px; border:1px solid #ccc;'>";
        foreach ($messages as $msg) {
            $from = $msg->sender_id == 1 ? 'Admin' : 'User';
            echo "<p><strong>$from:</strong> " . esc_html($msg->message) . "</p>";
        }
        echo "</div>";

        // Admin reply box
        echo '<div style="margin-top:15px;">
            <textarea id="auc-admin-reply" rows="3" cols="50" placeholder="Type your reply..."></textarea><br>
            <button id="auc-admin-send" class="button button-primary">Send Reply</button>
            <button id="auc-admin-delete" class="button button-secondary" style="margin-left: 10px; background: #dc3545; color: white;">Delete Chat History</button>
            <button id="auc-admin-export" class="button button-secondary" style="margin-left: 10px;">Download Chat</button>
        </div>';

        // Optional manual form handler
        if (isset($_POST['send_reply'])) {
            $message = sanitize_text_field($_POST['admin_reply']);
            $wpdb->insert($table, [
                'sender_id' => 1,
                'receiver_id' => $user_id,
                'message' => $message,
            ]);
            echo "<div class='updated'><p>Message sent!</p></div>";
            echo "<script>setTimeout(() => location.reload(), 1000);</script>";
        }
    } else {
    
    echo '<input type="text" id="auc-search" placeholder="Search users by name or email..." style="margin-bottom:10px; padding:5px; width: 300px;">';
    echo "<table class='widefat'>
        <thead><tr><th>Name</th><th>Email</th><th>Action</th></tr></thead>
        <tbody>";

    foreach ($users as $user) {
        $user_id = $user->sender_id;
        $user_info = get_userdata($user_id);

        // Count unread messages from this user
        $unread_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE sender_id = %d AND receiver_id = 1 AND is_read = 0",
            $user_id
        ));

        if ($user_info) {
            $first_name = get_user_meta($user_id, 'first_name', true);
            $last_name = get_user_meta($user_id, 'last_name', true);
            $name = esc_html(trim("$first_name $last_name")) ?: esc_html($user_info->display_name);
            $email = esc_html($user_info->user_email);

            $email_link = "<a href='mailto:$email'>$email</a>";
            $copy_button = "<button class='button button-small auc-copy-btn' data-email='$email'>Copy</button>";

            $badge = $unread_count > 0 ? "<span style='color:red;'>($unread_count new)</span>" : "";
            
            echo "<tr>
                    <td>$name $badge</td>
                    <td>$email_link $copy_button</td>
                    <td><a class='button' href='?page=auc-user-chats&user_id={$user_id}'>Open Chat</a></td>
                </tr>";
        }
    }

    echo "</tbody></table>";
    }

    // Add copy-to-clipboard functionality
    echo "<script>
    jQuery(document).ready(function($) {
        // Copy button
        $('.auc-copy-btn').on('click', function() {
            const email = $(this).data('email');
            navigator.clipboard.writeText(email);
        });

        // Live Search Filter
        $('#auc-search').on('keyup', function() {
            const value = $(this).val().toLowerCase();
            $('.widefat tbody tr').each(function() {
                const rowText = $(this).text().toLowerCase();
                $(this).toggle(rowText.indexOf(value) > -1);
            });
        });
    });
    </script>";

    echo "<script>
    jQuery(document).ready(function($) {
        $('#auc-admin-delete').on('click', function () {
            if (!confirm('Are you sure you want to delete this entire chat history?')) return;

            $.post(ajaxurl, {
                action: 'auc_delete_chat',
                user_id: " . (isset($_GET['user_id']) ? intval($_GET['user_id']) : 0) . "
            }, function (response) {
                if (response.success) {
                    alert('Chat history deleted!');
                    location.reload();
                } else {
                    alert('Error deleting chat history.');
                }
            });
        });
    });
    </script>";

    echo "<script>
    jQuery(document).ready(function($) {
        $('#auc-admin-export').on('click', function () {
            const userId = " . json_encode(intval($_GET['user_id'] ?? 0)) . ";
            if (!userId) return;

            window.location.href = ajaxurl + '?action=auc_export_chat&user_id=' + userId;
        });
    });
    </script>";

    echo "</div>";

}

add_action('admin_init', 'auc_export_chat');
function auc_export_chat() {
    if (!is_admin() || !current_user_can('manage_options')) return;
    if (!isset($_GET['action']) || $_GET['action'] !== 'auc_export_chat') return;

    global $wpdb;
    $user_id = intval($_GET['user_id']);
    if (!$user_id) return;

    $table = $wpdb->prefix . 'auc_messages';
    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE 
        (sender_id = %d AND receiver_id = 1) OR 
        (sender_id = 1 AND receiver_id = %d)
        ORDER BY created_at ASC", $user_id, $user_id
    ));

    $user_info = get_userdata($user_id);
    $filename = 'chat_with_' . $user_info->user_nicename . '.txt';

    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    echo "Chat with {$user_info->display_name} (" . $user_info->user_email . ")\n\n";
    foreach ($messages as $msg) {
        $sender = $msg->sender_id == 1 ? 'Admin' : $user_info->display_name;
        $time = date('Y-m-d H:i', strtotime($msg->created_at));
        echo "[$time] $sender: " . $msg->message . "\n";
    }
    exit;

}
