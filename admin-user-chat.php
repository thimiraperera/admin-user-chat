<?php
/*
Plugin Name: Admin User Chat
Description: Simple chat between admin and logged-in users.
Version: 1.5
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

// Include admin functions only in admin area
if (is_admin()) {
    require_once plugin_dir_path(__FILE__) . 'admin-chat-functions.php';
}

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
    $last_id = isset($_POST['last_id']) ? intval($_POST['last_id']) : 0;
    $table = $wpdb->prefix . 'auc_messages';

    // Mark messages as read
    $wpdb->query($wpdb->prepare(
        "UPDATE $table SET is_read = 1 
        WHERE receiver_id = %d AND sender_id = %d",
        $admin_id, $user_id
    ));

    // Get only NEW messages
    $messages = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table 
        WHERE id > %d
        AND (
            (sender_id = %d AND receiver_id = %d) OR 
            (sender_id = %d AND receiver_id = %d)
        )
        ORDER BY created_at ASC", 
        $last_id, 
        $user_id, $admin_id, 
        $admin_id, $user_id
    ));
    
    wp_send_json($messages);
}

// Shortcode for user chat
add_shortcode('user_chat', function () {
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
    
    // Validate message length
    if (empty(trim($message))) {
        wp_send_json_error('Message cannot be empty');
    }
    
    if (strlen($message) > 500) {
        wp_send_json_error('Message too long (max 500 characters)');
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
    
    // Validate message length
    if (empty(trim($message))) {
        wp_send_json_error('Message cannot be empty');
    }
    
    if (strlen($message) > 500) {
        wp_send_json_error('Message too long (max 500 characters)');
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