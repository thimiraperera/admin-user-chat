# WordPress Admin-User Chat Plugin

A secure and efficient chat system that enables direct communication between administrators and registered users within WordPress.

## Features

- 💬 **Real-time Messaging**  
  Admin-to-user and user-to-admin chat functionality
- 🔔 **Email Notifications**  
  Configurable email alerts for unread messages
- 📤 **Chat Export**  
  Export full conversation history as text files
- 📱 **Responsive Design**  
  Mobile-friendly interface for all devices
- ⚙️ **Customizable Settings**  
  Adjust notification intervals and email preferences
- 🛡️ **Security Focused**  
  XSS protection, input validation, and nonce verification

## Installation

1. Download the plugin ZIP file
2. Go to WordPress Admin → Plugins → Add New → Upload Plugin
3. Install and activate
4. Plugin will automatically:
   - Create necessary database tables
   - Set default notification settings
   - Schedule notification cron jobs

## Usage

### For Users
1. Add `[user_chat]` shortcode to any page/post
2. Logged-in users will see the chat interface
3. Users can only chat with administrators

### For Administrators
1. Go to **User Chats** in admin sidebar
2. View list of users with unread message counts
3. Click "Open Chat" to start conversation
4. Available actions:
   - Send messages
   - Export chat history
   - Delete conversation
   - Configure settings

### Configuration
Go to **User Chats → Settings** to:
- Set notification email address
- Adjust notification frequency (1-1440 minutes)
- Configure email format

## Requirements

- WordPress 5.0+
- PHP 7.4+
- MySQL 5.6+
- jQuery (included with WordPress)

## Security Features

- Input sanitization for all user-generated content
- Nonce verification for all AJAX requests
- HTML escaping in message rendering
- Role-based access control
- SQL injection prevention
- Email address validation
- CSRF protection on forms
