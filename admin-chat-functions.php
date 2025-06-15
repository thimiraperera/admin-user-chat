<?php
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
        echo "<h2>Chat with: " . esc_html($user_info->display_name) . " (" . esc_html($user_info->user_email) . ")</h2>";
        echo '<a href="' . esc_url(admin_url('admin.php?page=auc-user-chats')) . '" class="button">← Back to All Users</a>';
        
        // Chat container with preloaded messages
        echo '<div id="auc-admin-messages" style="max-height:400px; overflow:auto; margin:20px 0; padding:15px;">';
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
                
                echo '<div class="msg ' . esc_attr($sender) . '" data-id="' . esc_attr($msg->id) . '">';
                echo '<strong>' . ($sender == 'admin' ? 'Admin' : 'User') . ':</strong> ';
                echo $escaped_message;
                echo '<br><small>' . esc_html($timeStr) . '</small>';
                echo '</div>';
            }
        }
        echo '</div>';
        
        // Reply area
        echo '<div style="display: flex; flex-wrap: wrap; gap: 10px;">
            <textarea id="auc-admin-reply" rows="3" style="width:100%;" placeholder="Type your reply..."></textarea>
            <button id="auc-admin-send" class="button button-primary">Send</button>
            <button id="auc-admin-delete" class="button button-secondary">Delete Chat History</button>
            <a href="' . esc_url(admin_url('admin-ajax.php?action=auc_export_chat&user_id=' . $user_id . '&nonce=' . wp_create_nonce('export_chat_' . $user_id))) . '" class="button">Export Chat</a>
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
                        <a class='button' href='" . esc_url(admin_url("admin.php?page=auc-user-chats&user_id={$user['ID']}")) . "'>Open Chat</a>
                        <a class='button' href='" . esc_url(admin_url("admin-ajax.php?action=auc_export_chat&user_id={$user['ID']}&nonce=" . wp_create_nonce('export_chat_' . $user['ID']))) . "'>Export</a>
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
    
    // SECURITY FIX: Use esc_html for output
    echo esc_html("Chat History with {$user_info->display_name} ({$user_info->user_email})") . "\n";
    echo esc_html("Exported on: " . date('F j, Y \a\t g:i a')) . "\n\n";
    
    $last_date = '';
    foreach ($messages as $msg) {
        $sender = ($msg->sender_id == $admin_id) ? 'Admin' : $user_info->display_name;
        $time = date('M j, g:i a', strtotime($msg->created_at));
        $clean_msg = wp_strip_all_tags($msg->message);
        
        // Add date header if it's a new day
        $current_date = date('M j, Y', strtotime($msg->created_at));
        if ($last_date !== $current_date) {
            echo "\n[" . esc_html($current_date) . "]\n";
            $last_date = $current_date;
        }
        
        // Format: [Time] Sender: Message (single line)
        $formatted_line = '[' . esc_html($time) . '] ' . esc_html($sender) . ': ' . esc_html($clean_msg);
        echo wordwrap($formatted_line, 80, "\n    ") . "\n";
    }
    
    exit;
}