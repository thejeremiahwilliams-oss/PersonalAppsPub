<?php
// public/api_upload.php
session_start();
require_once 'includes/auth_functions.php';

// Ensure user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $file = $_FILES['file'];
    
    // Set the upload directory inside the public folder
    $upload_dir = __DIR__ . '/uploads/';

    // Automatically create the uploads folder if it doesn't exist
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $filename = basename($file['name']);
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    // Security: Only allow specific safe file types
    $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt', 'csv', 'zip'];

    if (!in_array($ext, $allowed_exts)) {
        echo json_encode(['success' => false, 'error' => 'Invalid file type. Allowed: Image, PDF, Office Docs, TXT, ZIP.']);
        exit;
    }

    // Generate a unique file name to prevent overwriting existing files
    $new_filename = uniqid() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $filename);
    $destination = $upload_dir . $new_filename;

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        // Return the relative URL to access the file
        $url = 'uploads/' . $new_filename;
        
        // Determine if it is an image so the editor knows whether to embed it or link it
        $is_image = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
        
        echo json_encode([
            'success' => true, 
            'url' => $url, 
            'name' => $filename,
            'is_image' => $is_image
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Server failed to save the uploaded file.']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'No file received.']);
}