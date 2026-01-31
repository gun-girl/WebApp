<?php
// API endpoint for admins to update the policy box content
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/helper.php';

header('Content-Type: application/json');

// Verify user is authenticated and is an admin
$user = current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

if (!is_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required']);
    exit;
}

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$action = $input['action'] ?? '';

// Handle different actions
switch ($action) {
    case 'update_content':
        $title = $input['title'] ?? '';
        $content = $input['content'] ?? '';
        $lang = $input['lang'] ?? current_lang();
        
        if (empty($content)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Content is required']);
            exit;
        }
        
        // Update settings
        $titleKey = "policy_box_title_$lang";
        
        $success = true;
        if (!empty($title)) {
            $success = $success && set_setting($titleKey, $title);
        }
        // Store content once and keep per-language values in sync for backward compatibility
        $success = $success && set_setting('policy_box_content', $content);
        $success = $success && set_setting('policy_box_content_en', $content);
        $success = $success && set_setting('policy_box_content_it', $content);
        
        if ($success) {
            echo json_encode([
                'success' => true,
                'message' => 'Policy box updated successfully'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to update settings']);
        }
        break;
        
    case 'toggle_visibility':
        $visible = $input['visible'] ?? true;
        $visibleValue = $visible ? '1' : '0';
        
        $success = set_setting('policy_box_visible', $visibleValue);
        
        if ($success) {
            echo json_encode([
                'success' => true,
                'visible' => $visible,
                'message' => 'Visibility updated'
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to update visibility']);
        }
        break;
        
    case 'get_content':
        $lang = $input['lang'] ?? current_lang();
        $titleKey = "policy_box_title_$lang";
        
        $title = get_setting($titleKey, '');
        $content = get_setting('policy_box_content', '');
        if ($content === '') {
            $content = get_setting("policy_box_content_$lang", '');
        }
        $visible = get_setting('policy_box_visible', '1') === '1';
        
        echo json_encode([
            'success' => true,
            'title' => $title,
            'content' => $content,
            'visible' => $visible
        ]);
        break;
        
    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid action']);
        break;
}
