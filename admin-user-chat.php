<?php
/*
Plugin Name: Admin User Chat
Description: Simple chat between admin and logged-in users.
Version: 1.3
Author: Thimira Perera
*/

// Helper function to get admin ID
function auc_get_admin_id() {
    $admins = get_users([
        'role' => 'administrator',
        'number' => 1,
        'fields' => 'ID'
    ]);
    return $admins ? $admins[0] : 1;
}

// Plugin installation with improved database structure
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
        is_read TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX sender_receiver_idx (sender_id, receiver_id),
        INDEX created_at_idx (created_at),
        INDEX is_read_idx (is_read)
    ) $charset;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
    
    // Add is_read column if missing (for updates)
    $columns = $wpdb->get_col("DESC $table", 0);
    if (!in_array('is_read', $columns)) {
        $wpdb->query("ALTER TABLE $table ADD COLUMN is_read TINYINT(1) DEFAULT 0");
    }
}

// Load assets
add_action('wp_enqueue_scripts', function () {
    if (is_user_logged_in() && !current_user_can('manage_options')) {
        wp_enqueue_style('auc-style', plugin_dir_url(__FILE__) . 'css/chat.css');
        wp_enqueue_script('auc-script', plugin_dir_url(__FILE__) . 'js/chat.js', ['jquery'], null, true);
        
        wp_localize_script('auc-script', 'auc_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('auc_chat_nonce'),
            'admin_id' => auc_get_admin_id(),
            'user_id' => get_current_user_id()
        ]);
    }
});

// Admin scripts
add_action('admin_enqueue_scripts', function ($hook) {
    if ($hook !== 'toplevel_page_auc-user-chats') return;
    
    wp_enqueue_style('auc-admin-style', plugin_dir_url(__FILE__) . 'css/chat.css');
    wp_enqueue_script('auc-admin-chat', plugin_dir_url(__FILE__) . 'js/admin-chat.js', ['jquery'], null, true);
    
    wp_localize_script('auc-admin-chat', 'auc_admin_ajax', [
        'ajax_url' => admin_url('admin-ajax.php'),
        'user_id' => isset($_GET['user_id']) ? intval($_GET['user_id']) : 0,
        'nonce' => wp_create_nonce('auc_admin_nonce'),
        'admin_id' => get_current_user_id()
    ]);
});

// AJAX Handler for Admin Chat
add_action('wp_ajax_auc_fetch_admin_messages', 'auc_fetch_admin_messages');
function auc_fetch_admin_messages() {
    check_ajax_referer('auc_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized', 403);
    }
    
    global $wpdb;
    $user_id = intval($_POST['user_id']);
    $admin_id = intval($_POST['admin_id']);
    $table = $wpdb->prefix . 'auc_messages';

    // Mark messages as read
    $wpdb->query($wpdb->prepare(
        "UPDATE $table SET is_read = 1 
        WHERE receiver_id = %d AND sender_id = %d",
        $admin_id, $user_id
    ));

    // Get messages
    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE 
        (sender_id = %d AND receiver_id = %d) OR 
        (sender_id = %d AND receiver_id = %d) 
        ORDER BY created_at ASC", 
        $user_id, $admin_id, $admin_id, $user_id
    ));
    
    wp_send_json($messages);
}

// Chat box shortcode
add_shortcode('admin_user_chat', function () {
    if (!is_user_logged_in()) return '<p>Please log in to use the chat.</p>';
    if (current_user_can('manage_options')) return '<p>Admins must use the dashboard chat.</p>';
    
    ob_start(); ?>
    <div id="auc-chat-box">
        <div id="auc-messages">
            <div class="auc-loading">Loading messages...</div>
        </div>
        <textarea id="auc-input" placeholder="Type your message..."></textarea>
        <button id="auc-send">Send</button>
        <div id="auc-status" style="display:none; text-align:center;">
            <img src="<?php echo admin_url('images/spinner.gif'); ?>" alt="Loading...">
        </div>
    </div>
    <?php return ob_get_clean();
});

// AJAX fetch messages
add_action('wp_ajax_auc_fetch_messages', 'auc_fetch_messages');
function auc_fetch_messages() {
    check_ajax_referer('auc_chat_nonce', 'nonce');
    
    global $wpdb;
    $user_id = intval($_POST['user_id']);
    $admin_id = intval($_POST['admin_id']);
    $table = $wpdb->prefix . 'auc_messages';
    
    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE 
        (sender_id = %d AND receiver_id = %d) OR 
        (sender_id = %d AND receiver_id = %d) 
        ORDER BY created_at ASC", 
        $user_id, $admin_id, $admin_id, $user_id
    ));
    
    wp_send_json($messages);
}

// AJAX send message
add_action('wp_ajax_auc_send_message', 'auc_send_message');
function auc_send_message() {
    check_ajax_referer('auc_chat_nonce', 'nonce');
    
    global $wpdb;
    $sender_id = get_current_user_id();
    $message = isset($_POST['message']) ? wp_kses_post(wp_unslash($_POST['message'])) : '';
    
    if (empty(trim($message))) {
        wp_send_json_error('Message cannot be empty');
    }
    
    $admin_id = auc_get_admin_id();
    $receiver_id = ($sender_id == $admin_id) ? intval($_POST['receiver_id']) : $admin_id;
    
    $table = $wpdb->prefix . 'auc_messages';
    $result = $wpdb->insert($table, [
        'sender_id' => $sender_id,
        'receiver_id' => $receiver_id,
        'message' => $message,
    ]);
    
    if ($result) {
        wp_send_json_success();
    } else {
        wp_send_json_error('Failed to send message');
    }
}

// AJAX admin message
add_action('wp_ajax_auc_send_admin_message', 'auc_send_admin_message');
function auc_send_admin_message() {
    check_ajax_referer('auc_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized', 403);
    }
    
    global $wpdb;
    $sender_id = get_current_user_id();
    $receiver_id = intval($_POST['receiver_id']);
    $message = isset($_POST['message']) ? wp_kses_post(wp_unslash($_POST['message'])) : '';
    
    if (empty(trim($message))) {
        wp_send_json_error('Message cannot be empty');
    }
    
    $result = $wpdb->insert($wpdb->prefix . 'auc_messages', [
        'sender_id' => $sender_id,
        'receiver_id' => $receiver_id,
        'message' => $message,
    ]);
    
    if ($result) {
        wp_send_json_success();
    } else {
        wp_send_json_error('Failed to send message');
    }
}

// Delete Chat History
add_action('wp_ajax_auc_delete_chat', 'auc_delete_chat');
function auc_delete_chat() {
    check_ajax_referer('auc_admin_nonce', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Unauthorized', 403);
    }
    
    global $wpdb;
    $user_id = intval($_POST['user_id']);
    $admin_id = auc_get_admin_id();
    $table = $wpdb->prefix . 'auc_messages';

    $result = $wpdb->query($wpdb->prepare(
        "DELETE FROM $table WHERE (sender_id = %d AND receiver_id = %d) OR (sender_id = %d AND receiver_id = %d)",
        $user_id, $admin_id, $admin_id, $user_id
    ));
    
    if ($result !== false) {
        wp_send_json_success();
    } else {
        wp_send_json_error('Failed to delete chat history');
    }
}

// Admin menu
add_action('admin_menu', function () {
    add_menu_page('User Chats', 'User Chats', 'manage_options', 'auc-user-chats', 'auc_admin_chat_page', 'dashicons-format-chat', 25);
});

// Admin chat page
function auc_admin_chat_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'auc_messages';
    $admin_id = get_current_user_id();
    $primary_admin = auc_get_admin_id();

    // Get ALL non-admin users
    $all_users = get_users([
        'role__not_in' => ['administrator'],
        'orderby' => 'display_name',
        'order' => 'ASC'
    ]);
    
    $users = [];
    foreach ($all_users as $user) {
        $user_id = $user->ID;
        
        // Get first and last name
        $first_name = get_user_meta($user_id, 'first_name', true);
        $last_name = get_user_meta($user_id, 'last_name', true);
        $full_name = trim("$first_name $last_name");
        if (empty($full_name)) $full_name = $user->display_name;
        
        // Get last message time
        $last_msg = $wpdb->get_var($wpdb->prepare(
            "SELECT MAX(created_at) FROM $table 
            WHERE (sender_id = %d AND receiver_id = %d)
            OR (sender_id = %d AND receiver_id = %d)",
            $user_id, $primary_admin, $primary_admin, $user_id
        ));
        
        // Get unread message count
        $unread_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table 
            WHERE sender_id = %d 
            AND receiver_id = %d 
            AND is_read = 0",
            $user_id, $primary_admin
        ));
        
        $users[] = [
            'ID' => $user_id,
            'name' => $full_name,
            'email' => $user->user_email,
            'last_msg' => $last_msg,
            'unread' => $unread_count ? intval($unread_count) : 0
        ];
    }
    
    // Sort by last message time (most recent first)
    usort($users, function ($a, $b) {
        if ($a['last_msg'] && $b['last_msg']) {
            return strtotime($b['last_msg']) - strtotime($a['last_msg']);
        }
        // Put users with messages first
        if ($a['last_msg'] && !$b['last_msg']) return -1;
        if (!$a['last_msg'] && $b['last_msg']) return 1;
        // Then sort by name
        return strcmp($a['name'], $b['name']);
    });

    echo "<div class='wrap'><h1>User Chats</h1>";

    if (isset($_GET['user_id'])) {
        $user_id = intval($_GET['user_id']);
        $user_info = get_userdata($user_id);
        
        if (!$user_info) {
            echo '<div class="error"><p>User not found</p></div>';
            return;
        }
        
        // Mark messages as read
        $wpdb->query($wpdb->prepare(
            "UPDATE $table SET is_read = 1 
            WHERE receiver_id = %d 
            AND sender_id = %d",
            $admin_id, $user_id
        ));
        
        // Get messages immediately
        $messages = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE 
            (sender_id = %d AND receiver_id = %d) OR 
            (sender_id = %d AND receiver_id = %d) 
            ORDER BY created_at ASC", 
            $user_id, $admin_id, $admin_id, $user_id
        ));
        
        // Display chat header
        echo "<h2>Chat with: {$user_info->display_name} ({$user_info->user_email})</h2>";
        echo '<a href="' . admin_url('admin.php?page=auc-user-chats') . '" class="button">← Back to All Users</a>';
        
        // Chat container with preloaded messages
        echo '<div id="auc-admin-messages" style="max-height:400px; overflow:auto; margin:20px 0; padding:15px; border:1px solid #ddd; background:#fff;">';
        if (empty($messages)) {
            echo '<div class="auc-empty">No messages yet. Start the conversation!</div>';
        } else {
            $lastDate = null;
            foreach ($messages as $msg) {
                $sender = ($msg->sender_id == $admin_id) ? 'admin' : 'user';
                $msgDate = new DateTime($msg->created_at);
                $dateStr = $msgDate->format('M j, Y');
                $timeStr = $msgDate->format('g:i a');
                
                // Date separator
                if ($lastDate !== $dateStr) {
                    echo '<div class="msg-date">' . esc_html($dateStr) . '</div>';
                    $lastDate = $dateStr;
                }
                
                // Escape message for security
                $escaped_message = esc_html($msg->message);
                // Preserve line breaks
                $escaped_message = nl2br($escaped_message);
                
                echo '<div class="msg ' . esc_attr($sender) . '">';
                echo '<strong>' . ($sender == 'admin' ? 'Admin' : 'User') . ':</strong> ';
                echo $escaped_message;
                echo '<br><small>' . esc_html($timeStr) . '</small>';
                echo '</div>';
            }
        }
        echo '</div>';
        
        // Reply area
        echo '<div>
            <textarea id="auc-admin-reply" rows="3" style="width:100%;" placeholder="Type your reply..."></textarea>
            <button id="auc-admin-send" class="button button-primary">Send</button>
            <button id="auc-admin-delete" class="button button-secondary" style="background:#dc3545;color:white;">Delete Chat History</button>
            <a href="' . admin_url('admin-ajax.php?action=auc_export_chat&user_id=' . $user_id . '&nonce=' . wp_create_nonce('export_chat_' . $user_id)) . '" class="button">Export Chat</a>
        </div>';
        
    } else {
        // User list
        echo '<input type="text" id="auc-search" placeholder="Search users..." style="margin-bottom:20px; padding:8px; width:300px;">';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>User</th><th>Last Activity</th><th>Unread</th><th>Actions</th></tr></thead><tbody>';
        
        if (empty($users)) {
            echo '<tr><td colspan="4">No users found</td></tr>';
        } else {
            foreach ($users as $user) {
                $unread_badge = $user['unread'] ? '<span class="update-plugins"><span class="update-count">' . $user['unread'] . '</span></span>' : '';
                $last_msg = $user['last_msg'] ? date('M j, Y g:i a', strtotime($user['last_msg'])) : 'No messages';
                
                echo "<tr>
                    <td><strong>" . esc_html($user['name']) . "</strong><br>" . esc_html($user['email']) . "</td>
                    <td>" . esc_html($last_msg) . "</td>
                    <td>" . $unread_badge . "</td>
                    <td>
                        <a class='button' href='" . admin_url("admin.php?page=auc-user-chats&user_id={$user['ID']}") . "'>Open Chat</a>
                        <a class='button' href='" . admin_url("admin-ajax.php?action=auc_export_chat&user_id={$user['ID']}&nonce=" . wp_create_nonce('export_chat_' . $user['ID'])) . "'>Export</a>
                    </td>
                </tr>";
            }
        }
        
        echo '</tbody></table>';
    }

    // JavaScript functionality
    echo "<script>
    jQuery(document).ready(function($) {
        // User search
        $('#auc-search').on('keyup', function() {
            const search = $(this).val().toLowerCase();
            $('tbody tr').each(function() {
                const text = $(this).text().toLowerCase();
                $(this).toggle(text.indexOf(search) > -1);
            });
        });
    });
    </script>";
    
    echo "</div>"; // .wrap
}

// Chat export
add_action('admin_init', 'auc_export_chat');
function auc_export_chat() {
    if (!isset($_GET['action']) || $_GET['action'] !== 'auc_export_chat') return;
    
    check_admin_referer('export_chat_' . $_GET['user_id'], 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized', 403);
    }
    
    global $wpdb;
    $user_id = intval($_GET['user_id']);
    $admin_id = auc_get_admin_id();
    $table = $wpdb->prefix . 'auc_messages';
    
    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE 
        (sender_id = %d AND receiver_id = %d) OR 
        (sender_id = %d AND receiver_id = %d)
        ORDER BY created_at ASC", 
        $user_id, $admin_id, $admin_id, $user_id
    ));
    
    $user_info = get_userdata($user_id);
    $filename = 'chat-export-' . sanitize_title($user_info->display_name) . '-' . date('Y-m-d') . '.txt';
    
    header('Content-Type: text/plain');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    echo "Chat History with {$user_info->display_name} ({$user_info->user_email})\n";
    echo "Exported on: " . date('F j, Y \a\t g:i a') . "\n\n";
    echo str_repeat('=', 50) . "\n\n";
    
    foreach ($messages as $msg) {
        $sender = ($msg->sender_id == $admin_id) ? 'Admin' : $user_info->display_name;
        $time = date('M j, g:i a', strtotime($msg->created_at));
        $clean_msg = wp_strip_all_tags($msg->message);
        echo "[{$time}] {$sender}:\n{$clean_msg}\n\n";
    }
    
    exit;
}