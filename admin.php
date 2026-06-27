<?php
session_start();

$admin_password = 'tacc@admin';
$data_file = 'data.json';
$gallery_file = 'gallery.json';
$messages_file = 'messages.json';
$settings_file = 'settings.json';
$certificates_file = 'certificates.json';
$upload_dir = 'assets/images/';
$photos_dir = 'assets/photos/';

// Ensure upload directories exist
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);
if (!is_dir($photos_dir)) mkdir($photos_dir, 0755, true);

// Helper: return PHP upload error as readable string
function uploadErrorMsg($code) {
    $errors = [
        UPLOAD_ERR_INI_SIZE   => 'File too large (php.ini limit).',
        UPLOAD_ERR_FORM_SIZE  => 'File too large (form limit).',
        UPLOAD_ERR_PARTIAL    => 'File only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was selected.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by PHP extension.',
    ];
    return $errors[$code] ?? 'Unknown upload error (code: '.$code.')';
}

// ---- LOGIN / LOGOUT ----
if (isset($_POST['login'])) {
    if ($_POST['password'] === $admin_password) {
        $_SESSION['logged_in'] = true;
    } else {
        $login_error = "Invalid password!";
    }
}
if (isset($_GET['logout'])) {
    session_destroy();
    header("Location: admin.php");
    exit;
}
$is_logged_in = isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;

$message = "";
if (isset($_SESSION['message'])) {
    $message = $_SESSION['message'];
    unset($_SESSION['message']);
}

// ---- HELPERS ----
function getMembers() {
    global $data_file;
    return file_exists($data_file) ? json_decode(file_get_contents($data_file), true) : [];
}
function saveMembers($data) {
    global $data_file;
    return file_put_contents($data_file, json_encode(array_values($data), JSON_PRETTY_PRINT));
}
function getGallery() {
    global $gallery_file;
    return file_exists($gallery_file) ? json_decode(file_get_contents($gallery_file), true) : [];
}
function saveGallery($data) {
    global $gallery_file;
    return file_put_contents($gallery_file, json_encode(array_values($data), JSON_PRETTY_PRINT));
}
function getMessages() {
    global $messages_file;
    return file_exists($messages_file) ? json_decode(file_get_contents($messages_file), true) : [];
}
function getCertificates() {
    global $certificates_file;
    return file_exists($certificates_file) ? json_decode(file_get_contents($certificates_file), true) : [];
}
function saveCertificates($data) {
    global $certificates_file;
    return file_put_contents($certificates_file, json_encode(array_values($data), JSON_PRETTY_PRINT));
}
function getSettings() {
    global $settings_file;
    $defaults = ['qr_code_path' => 'assets/images/donate_qr_poster_1781000295180.png'];
    if (!file_exists($settings_file)) return $defaults;
    $s = json_decode(file_get_contents($settings_file), true);
    return $s ?: $defaults;
}
function saveSettings($data) {
    global $settings_file;
    return file_put_contents($settings_file, json_encode($data, JSON_PRETTY_PRINT));
}

// Generate next TACC Registration ID
function getNextTaccId() {
    $members = getMembers();
    $max = 0;
    foreach ($members as $m) {
        if (preg_match('/Tacc\/00\/(\d+)/i', $m['id'], $match)) {
            $num = intval($match[1]);
            if ($num > $max) $max = $num;
        }
    }
    $next = $max + 1;
    return 'Regd.no.Tacc/00/' . str_pad($next, 5, '0', STR_PAD_LEFT);
}

// Helper: Compress and resize uploaded images with transparent fallback
function compressImage($source, $destination, $max_width = 1200, $quality = 75) {
    if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
        return move_uploaded_file($source, $destination);
    }
    
    // Get image info
    $info = getimagesize($source);
    if ($info === false) {
        return move_uploaded_file($source, $destination);
    }
    
    $mime = $info['mime'];
    
    // Create image from source
    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg':
            $image = @imagecreatefromjpeg($source);
            break;
        case 'image/png':
            $image = @imagecreatefrompng($source);
            break;
        case 'image/gif':
            $image = @imagecreatefromgif($source);
            break;
        case 'image/webp':
            $image = @imagecreatefromwebp($source);
            break;
        default:
            $image = false;
    }
    
    if (!$image) {
        return move_uploaded_file($source, $destination);
    }
    
    // Get original dimensions
    $orig_width = imagesx($image);
    $orig_height = imagesy($image);
    
    // Calculate new dimensions if it exceeds max_width
    if ($orig_width > $max_width) {
        $new_width = $max_width;
        $new_height = floor($orig_height * ($max_width / $orig_width));
        
        $new_image = imagecreatetruecolor($new_width, $new_height);
        
        // Preserve transparency for PNG/GIF/WEBP
        if ($mime === 'image/png' || $mime === 'image/gif' || $mime === 'image/webp') {
            imagealphablending($new_image, false);
            imagesavealpha($new_image, true);
            $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
            imagefilledrectangle($new_image, 0, 0, $new_width, $new_height, $transparent);
        }
        
        imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);
        imagedestroy($image);
        $image = $new_image;
    }
    
    // Save image to destination
    $success = false;
    $ext = strtolower(pathinfo($destination, PATHINFO_EXTENSION));
    
    switch ($ext) {
        case 'png':
            $png_quality = 9 - round(($quality / 100) * 9);
            $success = @imagepng($image, $destination, $png_quality);
            break;
        case 'gif':
            $success = @imagegif($image, $destination);
            break;
        case 'webp':
            $success = @imagewebp($image, $destination, $quality);
            break;
        case 'jpg':
        case 'jpeg':
        default:
            $success = @imagejpeg($image, $destination, $quality);
            break;
    }
    
    imagedestroy($image);
    return $success;
}

// ---- HANDLE: DELETE MEMBER ----
if ($is_logged_in && isset($_GET['delete_member'])) {
    $delete_id = $_GET['delete_member'];
    $current_data = getMembers();
    $new_data = array_filter($current_data, fn($m) => strtoupper($m['id']) !== strtoupper($delete_id));
    if (saveMembers($new_data)) {
        $_SESSION['message'] = "<div class='msg success'>Member deleted successfully!</div>";
    } else {
        $_SESSION['message'] = "<div class='msg error'>Error deleting member.</div>";
    }
    header("Location: admin.php?tab=members");
    exit;
}

// ---- HANDLE: ADD/UPDATE MEMBER ----
$edit_data = null;
if ($is_logged_in && isset($_GET['edit'])) {
    foreach (getMembers() as $m) {
        if (strtoupper($m['id']) === strtoupper($_GET['edit'])) {
            $edit_data = $m; break;
        }
    }
}

if ($is_logged_in && isset($_POST['save_member'])) {
    $current_data = getMembers();
    $is_update = !empty($_POST['original_id']);
    $original_id = $_POST['original_id'] ?? '';

    $member_id = trim($_POST['id']);
    $member_name = trim($_POST['name']);
    $photo_path = $_POST['existing_photo'] ?? '';

    $upload_error = "";
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            // Sanitize ID: replace slashes, dots, spaces with underscore for safe filename
            $safe_id = preg_replace('/[^A-Za-z0-9_\-]/', '_', $member_id);
            $fname = $photos_dir . $safe_id . '_' . time() . '.' . $ext;
            if (compressImage($_FILES['photo']['tmp_name'], $fname, 800, 75)) {
                $photo_path = $fname;
            } else {
                $upload_error = "<div class='msg error'>Photo upload failed: could not move file to <code>$fname</code>. Check folder permissions.</div>";
            }
        } else {
            $upload_error = "<div class='msg error'>Invalid photo format. Use JPG, PNG, WEBP, or GIF.</div>";
        }
    } elseif (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
        $upload_error = "<div class='msg error'>Photo upload error: " . uploadErrorMsg($_FILES['photo']['error']) . "</div>";
    }
    if (empty($photo_path)) {
        $photo_path = "https://ui-avatars.com/api/?name=" . urlencode($member_name) . "&background=112240&color=fff&size=150";
    }

    $new_member = [
        "id" => $member_id,
        "name" => $member_name,
        "designation" => trim($_POST['designation']),
        "state" => trim($_POST['state']),
        "validity" => trim($_POST['validity']),
        "photo" => $photo_path,
        "show_on_website" => isset($_POST['show_on_website']) ? true : false
    ];

    $exists = false;
    foreach ($current_data as $m) {
        if (strtoupper($m['id']) === strtoupper($member_id)) {
            if (!$is_update || strtoupper($original_id) !== strtoupper($member_id)) {
                $exists = true; break;
            }
        }
    }

    if ($exists) {
        $message = $upload_error . "<div class='msg error'>Error: Member ID already exists!</div>";
    } else {
        if ($is_update) {
            foreach ($current_data as $k => $m) {
                if (strtoupper($m['id']) === strtoupper($original_id)) {
                    $current_data[$k] = $new_member; break;
                }
            }
            $msg_text = "Member profile updated successfully!";
        } else {
            $current_data[] = $new_member;
            $msg_text = "New member added successfully!";
        }
        saveMembers($current_data);
        $_SESSION['message'] = $upload_error . "<div class='msg success'>$msg_text</div>";
        header("Location: admin.php?tab=members");
        exit;
    }
}

// ---- HANDLE: UPLOAD GALLERY IMAGE ----
if ($is_logged_in && isset($_POST['upload_gallery'])) {
    if (isset($_FILES['gallery_image']) && $_FILES['gallery_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['gallery_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $fname = $upload_dir . 'gallery_' . time() . rand(100,999) . '.' . $ext;
            if (compressImage($_FILES['gallery_image']['tmp_name'], $fname, 1200, 75)) {
                $gallery = getGallery();
                $gallery[] = $fname;
                saveGallery($gallery);
                $_SESSION['message'] = "<div class='msg success'><i class='fas fa-check-circle'></i> Gallery image uploaded successfully! File: <code>$fname</code></div>";
            } else {
                $_SESSION['message'] = "<div class='msg error'><i class='fas fa-times-circle'></i> Failed to move file to <code>$fname</code>. Check that <strong>assets/images/</strong> folder is writable.</div>";
            }
        } else {
            $_SESSION['message'] = "<div class='msg error'><i class='fas fa-times-circle'></i> Invalid file type '<strong>$ext</strong>'. Only JPG, PNG, WEBP, GIF allowed.</div>";
        }
    } elseif (isset($_FILES['gallery_image'])) {
        $errCode = $_FILES['gallery_image']['error'];
        $_SESSION['message'] = "<div class='msg error'><i class='fas fa-times-circle'></i> Upload error: " . uploadErrorMsg($errCode) . "</div>";
    } else {
        $_SESSION['message'] = "<div class='msg error'><i class='fas fa-times-circle'></i> No file received by server. Ensure form has <code>enctype=multipart/form-data</code>.</div>";
    }
    header("Location: admin.php?tab=gallery");
    exit;
}

// ---- HANDLE: DELETE GALLERY IMAGE ----
if ($is_logged_in && isset($_GET['delete_gallery'])) {
    $del_idx = intval($_GET['delete_gallery']);
    $gallery = getGallery();
    if (isset($gallery[$del_idx])) {
        @unlink($gallery[$del_idx]);
        array_splice($gallery, $del_idx, 1);
        saveGallery($gallery);
        $_SESSION['message'] = "<div class='msg success'>Gallery image deleted!</div>";
    }
    header("Location: admin.php?tab=gallery");
    exit;
}

// ---- HANDLE: UPLOAD CERTIFICATE ----
if ($is_logged_in && isset($_POST['upload_certificate'])) {
    if (isset($_FILES['cert_image']) && $_FILES['cert_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['cert_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $fname = $upload_dir . 'cert_' . time() . rand(100,999) . '.' . $ext;
            if (compressImage($_FILES['cert_image']['tmp_name'], $fname, 1200, 75)) {
                $certificates = getCertificates();
                $certificates[] = [
                    'image' => $fname,
                    'title' => trim($_POST['cert_title']),
                    'caption' => trim($_POST['cert_caption'])
                ];
                saveCertificates($certificates);
                $_SESSION['message'] = "<div class='msg success'><i class='fas fa-check-circle'></i> Certificate uploaded successfully!</div>";
            } else {
                $_SESSION['message'] = "<div class='msg error'><i class='fas fa-times-circle'></i> Failed to upload certificate.</div>";
            }
        } else {
            $_SESSION['message'] = "<div class='msg error'><i class='fas fa-times-circle'></i> Invalid file type. Only JPG, PNG, WEBP, GIF allowed.</div>";
        }
    }
    header("Location: admin.php?tab=certificates");
    exit;
}

// ---- HANDLE: DELETE CERTIFICATE ----
if ($is_logged_in && isset($_GET['delete_certificate'])) {
    $del_idx = intval($_GET['delete_certificate']);
    $certificates = getCertificates();
    if (isset($certificates[$del_idx])) {
        @unlink($certificates[$del_idx]['image']);
        array_splice($certificates, $del_idx, 1);
        saveCertificates($certificates);
        $_SESSION['message'] = "<div class='msg success'>Certificate deleted!</div>";
    }
    header("Location: admin.php?tab=certificates");
    exit;
}

// ---- HANDLE: SAVE SETTINGS ----
if ($is_logged_in && isset($_POST['save_settings'])) {
    $settings = getSettings();
    $settings['email'] = trim($_POST['email']);
    $settings['phone1'] = trim($_POST['phone1']);
    $settings['phone2'] = trim($_POST['phone2']);
    $settings['address'] = trim($_POST['address']);
    $settings['bank_account_name'] = trim($_POST['bank_account_name']);
    $settings['bank_name'] = trim($_POST['bank_name']);
    $settings['bank_branch'] = trim($_POST['bank_branch']);
    $settings['bank_account_number'] = trim($_POST['bank_account_number']);
    $settings['bank_ifsc'] = trim($_POST['bank_ifsc']);
    saveSettings($settings);
    $_SESSION['message'] = "<div class='msg success'><i class='fas fa-check-circle'></i> Settings saved successfully!</div>";
    header("Location: admin.php?tab=settings");
    exit;
}

// ---- HANDLE: UPLOAD QR CODE ----
if ($is_logged_in && isset($_POST['upload_qr'])) {
    if (isset($_FILES['qr_image']) && $_FILES['qr_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['qr_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $fname = $upload_dir . 'donate_qr_' . time() . rand(100,999) . '.' . $ext;
            if (compressImage($_FILES['qr_image']['tmp_name'], $fname, 600, 80)) {
                $settings = getSettings();
                $settings['qr_code_path'] = $fname;
                saveSettings($settings);
                $_SESSION['message'] = "<div class='msg success'><i class='fas fa-check-circle'></i> QR Code updated successfully! File: <code>$fname</code></div>";
            } else {
                $_SESSION['message'] = "<div class='msg error'><i class='fas fa-times-circle'></i> Failed to save QR image to <code>$fname</code>. Check folder write permissions.</div>";
            }
        } else {
            $_SESSION['message'] = "<div class='msg error'><i class='fas fa-times-circle'></i> Invalid file type '<strong>$ext</strong>'. Only JPG, PNG, WEBP, GIF allowed.</div>";
        }
    } elseif (isset($_FILES['qr_image'])) {
        $errCode = $_FILES['qr_image']['error'];
        $_SESSION['message'] = "<div class='msg error'><i class='fas fa-times-circle'></i> Upload error: " . uploadErrorMsg($errCode) . "</div>";
    } else {
        $_SESSION['message'] = "<div class='msg error'><i class='fas fa-times-circle'></i> No file received. Please select a file.</div>";
    }
    header("Location: admin.php?tab=settings");
    exit;
}

// ---- HANDLE: MARK MESSAGE READ ----
if ($is_logged_in && isset($_GET['mark_read'])) {
    $msgs = getMessages();
    foreach ($msgs as &$msg) {
        if ($msg['id'] === $_GET['mark_read']) {
            $msg['status'] = 'read';
            break;
        }
    }
    file_put_contents($messages_file, json_encode($msgs, JSON_PRETTY_PRINT));
    $_SESSION['message'] = "<div class='msg success'>Message marked as read.</div>";
    header("Location: admin.php?tab=messages");
    exit;
}

// ---- HANDLE: DELETE MESSAGE ----
if ($is_logged_in && isset($_GET['delete_message'])) {
    $msgs = getMessages();
    $msgs = array_values(array_filter($msgs, fn($m) => $m['id'] !== $_GET['delete_message']));
    file_put_contents($messages_file, json_encode($msgs, JSON_PRETTY_PRINT));
    $_SESSION['message'] = "<div class='msg success'>Message deleted.</div>";
    header("Location: admin.php?tab=messages");
    exit;
}

// ---- READ DATA ----
$members  = getMembers();
$gallery  = getGallery();
$certificates = getCertificates();
$msgs     = getMessages();
$settings = getSettings();
$next_id  = getNextTaccId();

$active_tab = $_GET['tab'] ?? 'members';
$unread_count = count(array_filter($msgs, fn($m) => ($m['status'] ?? 'unread') === 'unread'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TACC Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f0f4f8; color: #333; }
        
        /* Login */
        .login-wrapper { display: flex; min-height: 100vh; align-items: center; justify-content: center; background: linear-gradient(135deg, #0A192F 60%, #D32F2F 100%); }
        .login-card { background: #fff; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); padding: 50px 40px; width: 400px; text-align: center; }
        .login-card img { width: 90px; margin-bottom: 20px; }
        .login-card h2 { color: #0A192F; margin-bottom: 8px; font-size: 1.6rem; }
        .login-card p { color: #777; margin-bottom: 30px; font-size: 0.9rem; }

        /* Layout */
        .admin-layout { display: flex; min-height: 100vh; }
        .sidebar { width: 260px; background: #0A192F; color: #fff; flex-shrink: 0; display: flex; flex-direction: column; }
        .sidebar-header { padding: 30px 25px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 15px; }
        .sidebar-header img { width: 50px; height: 50px; background: white; border-radius: 50%; padding: 3px; }
        .sidebar-header div h3 { font-size: 1rem; font-weight: 700; }
        .sidebar-header div span { font-size: 0.75rem; color: #8892B0; }
        
        .sidebar-nav { flex: 1; padding: 20px 0; }
        .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 13px 25px; color: #8892B0; font-size: 0.92rem; font-weight: 500; text-decoration: none; transition: all 0.2s; position: relative; }
        .sidebar-nav a:hover { background: rgba(255,255,255,0.07); color: #fff; }
        .sidebar-nav a.active { background: rgba(211,47,47,0.15); color: #fff; border-left: 3px solid #D32F2F; }
        .sidebar-nav a i { width: 20px; text-align: center; font-size: 1rem; }
        .badge { background: #D32F2F; color: #fff; font-size: 0.7rem; padding: 2px 7px; border-radius: 10px; margin-left: auto; }
        
        .sidebar-footer { padding: 20px 25px; border-top: 1px solid rgba(255,255,255,0.1); }
        .sidebar-footer a { color: #8892B0; font-size: 0.85rem; text-decoration: none; display: flex; align-items: center; gap: 8px; }
        .sidebar-footer a:hover { color: #D32F2F; }

        /* Main Content */
        .main-content { flex: 1; overflow-y: auto; }
        .topbar { background: #fff; padding: 18px 30px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e5e7eb; box-shadow: 0 1px 4px rgba(0,0,0,0.05); }
        .topbar h1 { font-size: 1.4rem; color: #0A192F; font-weight: 700; }
        .topbar-right { display: flex; gap: 10px; }
        
        .content-area { padding: 30px; }
        
        /* Cards */
        .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); padding: 28px; margin-bottom: 25px; }
        .card-title { font-size: 1.1rem; font-weight: 700; color: #0A192F; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .card-title i { color: #D32F2F; }
        
        /* Stats Row */
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: #fff; border-radius: 10px; padding: 20px 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border-left: 4px solid #D32F2F; }
        .stat-card h3 { font-size: 2rem; font-weight: 700; color: #0A192F; }
        .stat-card p { font-size: 0.85rem; color: #777; margin-top: 5px; }
        .stat-card.blue { border-color: #0A192F; }
        .stat-card.green { border-color: #2e7d32; }
        .stat-card.orange { border-color: #f39c12; }

        /* Forms */
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .form-group { margin-bottom: 0; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 0.85rem; color: #555; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 11px 14px; border: 1.5px solid #e0e0e0; border-radius: 7px; font-size: 0.9rem; font-family: 'Inter', sans-serif; transition: border-color 0.2s; background: #fafafa; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #D32F2F; background: #fff; }
        .form-group .hint { font-size: 0.78rem; color: #888; margin-top: 4px; }
        .id-badge { display: inline-block; background: #0A192F; color: #fff; font-size: 0.75rem; padding: 3px 10px; border-radius: 5px; font-weight: 600; margin-top: 6px; }

        /* Buttons */
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 22px; border-radius: 7px; border: none; cursor: pointer; font-size: 0.88rem; font-weight: 600; text-decoration: none; transition: all 0.2s; }
        .btn-red { background: #D32F2F; color: #fff; }
        .btn-red:hover { background: #B71C1C; }
        .btn-blue { background: #0A192F; color: #fff; }
        .btn-blue:hover { background: #071224; }
        .btn-yellow { background: #f39c12; color: #fff; }
        .btn-yellow:hover { background: #e67e22; }
        .btn-green { background: #2e7d32; color: #fff; }
        .btn-green:hover { background: #1b5e20; }
        .btn-gray { background: #eee; color: #555; }
        .btn-gray:hover { background: #ddd; }
        .btn-sm { padding: 6px 14px; font-size: 0.8rem; }
        .btn-outline { background: transparent; border: 1.5px solid #D32F2F; color: #D32F2F; }
        .btn-outline:hover { background: #D32F2F; color: #fff; }

        /* Table */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #f0f0f0; }
        th { background: #f8f9fa; font-weight: 600; color: #555; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.5px; }
        tr:hover td { background: #fafafa; }
        .actions { display: flex; gap: 6px; }

        /* Messages */
        .msg { padding: 12px 16px; border-radius: 7px; margin-bottom: 20px; font-weight: 500; font-size: 0.9rem; }
        .msg.error { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        .msg.success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }

        /* Photo preview */
        .photo-preview { display: flex; align-items: center; gap: 15px; margin-top: 8px; padding: 10px; background: #f9f9f9; border-radius: 7px; border: 1px dashed #ccc; }
        .photo-preview img { width: 55px; height: 55px; object-fit: cover; border-radius: 5px; border: 2px solid #D32F2F; }

        /* Gallery grid */
        .gallery-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; }
        .gallery-item { position: relative; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .gallery-item img { width: 100%; height: 150px; object-fit: cover; display: block; }
        .gallery-item .gallery-del { position: absolute; top: 8px; right: 8px; }

        /* Settings QR Preview */
        .qr-preview { text-align: center; padding: 20px; background: #f8f9fa; border-radius: 8px; border: 1px dashed #ccc; }
        .qr-preview img { max-width: 250px; max-height: 300px; object-fit: contain; }

        /* Message cards */
        .msg-card { border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; margin-bottom: 15px; }
        .msg-card.unread { border-left: 4px solid #D32F2F; background: #fff8f8; }
        .msg-card.read { border-left: 4px solid #e0e0e0; }
        .msg-meta { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px; }
        .msg-sender strong { font-size: 1rem; color: #0A192F; }
        .msg-sender span { font-size: 0.8rem; color: #888; display: block; }
        .msg-subject { font-weight: 600; color: #D32F2F; font-size: 0.9rem; margin-bottom: 6px; }
        .msg-body { color: #555; font-size: 0.875rem; line-height: 1.6; }
        .unread-dot { width: 10px; height: 10px; background: #D32F2F; border-radius: 50%; display: inline-block; margin-right: 6px; }

        /* Responsive sidebar */
        @media (max-width: 768px) {
            .admin-layout { flex-direction: column; }
            .sidebar { width: 100%; }
            .stats-row { grid-template-columns: 1fr 1fr; }
            .gallery-grid { grid-template-columns: 1fr 1fr; }
            .form-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php if (!$is_logged_in): ?>
<!-- ===== LOGIN PAGE ===== -->
<div class="login-wrapper">
    <div class="login-card">
        <img src="assets/logo.png" alt="TACC Logo" onerror="this.src='https://via.placeholder.com/90';">
        <h2>Admin Panel</h2>
        <p>Team Against Corruption and Crime</p>
        <?php if (isset($login_error)) echo "<div class='msg error'>$login_error</div>"; ?>
        <form method="POST">
            <div class="form-group" style="margin-bottom: 20px; text-align: left;">
                <label>Admin Password</label>
                <input type="password" name="password" placeholder="Enter password" required>
            </div>
            <button type="submit" name="login" class="btn btn-red" style="width: 100%; justify-content: center;">
                <i class="fas fa-sign-in-alt"></i> Login
            </button>
        </form>
        <p style="margin-top:20px; font-size:12px;"><a href="index.html" style="color:#777;">← Back to Website</a></p>
    </div>
</div>

<?php else: ?>
<!-- ===== ADMIN DASHBOARD ===== -->
<div class="admin-layout">

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <img src="assets/logo.png" alt="TACC">
            <div>
                <h3>TACC Admin</h3>
                <span>Manage your website</span>
            </div>
        </div>
        <nav class="sidebar-nav">
            <a href="?tab=members" class="<?= $active_tab === 'members' ? 'active' : '' ?>">
                <i class="fas fa-users"></i> Members
                <span class="badge"><?= count($members) ?></span>
            </a>
            <a href="?tab=gallery" class="<?= $active_tab === 'gallery' ? 'active' : '' ?>">
                <i class="fas fa-images"></i> Gallery
                <span class="badge"><?= count($gallery) ?></span>
            </a>
            <a href="?tab=certificates" class="<?= $active_tab === 'certificates' ? 'active' : '' ?>">
                <i class="fas fa-certificate"></i> Certificates
                <span class="badge"><?= count($certificates) ?></span>
            </a>
            <a href="?tab=messages" class="<?= $active_tab === 'messages' ? 'active' : '' ?>">
                <i class="fas fa-envelope"></i> Messages
                <?php if ($unread_count > 0): ?>
                    <span class="badge"><?= $unread_count ?></span>
                <?php endif; ?>
            </a>
            <a href="?tab=settings" class="<?= $active_tab === 'settings' ? 'active' : '' ?>">
                <i class="fas fa-cog"></i> Settings
            </a>
            <hr style="border-color: rgba(255,255,255,0.1); margin: 10px 25px;">
            <a href="index.html" target="_blank"><i class="fas fa-external-link-alt"></i> View Website</a>
        </nav>
        <div class="sidebar-footer">
            <a href="?logout=true"><i class="fas fa-sign-out-alt"></i> Logout</a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="topbar">
            <h1>
                <?php
                $titles = ['members' => 'Manage Members', 'gallery' => 'Manage Gallery', 'certificates' => 'Manage Certificates', 'messages' => 'Contact Messages', 'settings' => 'Website Settings'];
                echo $titles[$active_tab] ?? 'Dashboard';
                ?>
            </h1>
            <div class="topbar-right">
                <a href="index.html" target="_blank" class="btn btn-gray btn-sm"><i class="fas fa-eye"></i> View Site</a>
                <a href="?logout=true" class="btn btn-red btn-sm"><i class="fas fa-sign-out-alt"></i> Logout</a>
            </div>
        </div>

        <div class="content-area">
            <?= $message ?>

            <!-- ===== STATS ROW ===== -->
            <div class="stats-row">
                <div class="stat-card">
                    <h3><?= count($members) ?></h3>
                    <p>Total Members</p>
                </div>
                <div class="stat-card blue">
                    <h3><?= count($gallery) ?></h3>
                    <p>Gallery Images</p>
                </div>
                <div class="stat-card green">
                    <h3><?= count($certificates) ?></h3>
                    <p>Certificates</p>
                </div>
                <div class="stat-card orange">
                    <h3><?= $unread_count ?></h3>
                    <p>Unread Messages</p>
                </div>
            </div>

            <!-- ============================= -->
            <!-- TAB: MEMBERS                  -->
            <!-- ============================= -->
            <?php if ($active_tab === 'members'): ?>

            <div class="card">
                <div class="card-title">
                    <i class="fas fa-user-plus"></i>
                    <?= $edit_data ? 'Update Member Profile' : 'Add New Member' ?>
                </div>
                <form method="POST" action="admin.php?tab=members" enctype="multipart/form-data">
                    <?php if ($edit_data): ?>
                        <input type="hidden" name="original_id" value="<?= htmlspecialchars($edit_data['id']) ?>">
                        <input type="hidden" name="existing_photo" value="<?= htmlspecialchars($edit_data['photo']) ?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="form-group">
                            <label>Registration ID (TACC ID) *</label>
                            <input type="text" name="id" required value="<?= $edit_data ? htmlspecialchars($edit_data['id']) : htmlspecialchars($next_id) ?>">
                            <?php if (!$edit_data): ?>
                                <span class="id-badge">Next ID: <?= htmlspecialchars($next_id) ?></span>
                                <div class="hint">Format: Regd.no.Tacc/00/00001</div>
                            <?php endif; ?>
                        </div>
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="name" required value="<?= $edit_data ? htmlspecialchars($edit_data['name']) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>Designation *</label>
                            <input type="text" name="designation" required value="<?= $edit_data ? htmlspecialchars($edit_data['designation']) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>State *</label>
                            <input type="text" name="state" required placeholder="e.g. Punjab, Delhi" value="<?= $edit_data ? htmlspecialchars($edit_data['state']) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>Validity</label>
                            <input type="text" name="validity" placeholder="e.g. Lifetime, Dec 2028" value="<?= $edit_data ? htmlspecialchars($edit_data['validity']) : '' ?>">
                        </div>
                        <div class="form-group">
                            <label>Show on Website</label>
                            <div style="display: flex; align-items: center; gap: 8px; margin-top: 5px;">
                                <input type="checkbox" name="show_on_website" id="show_on_website" value="1" <?= ($edit_data && !empty($edit_data['show_on_website'])) ? 'checked' : '' ?> style="width: auto;">
                                <label for="show_on_website" style="margin: 0; font-weight: normal; cursor: pointer;">Tick to show on user side</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Upload Photo (Optional)</label>
                            <input type="file" name="photo" accept="image/*">
                            <?php if ($edit_data && !empty($edit_data['photo'])): ?>
                                <div class="photo-preview">
                                    <img src="<?= htmlspecialchars($edit_data['photo']) ?>" alt="Current Photo">
                                    <span style="font-size:0.8rem; color:#666;">Current photo — leave empty to keep.</span>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div style="margin-top: 20px; display: flex; gap: 10px; align-items: center;">
                        <button type="submit" name="save_member" class="btn btn-red">
                            <i class="fas fa-save"></i> <?= $edit_data ? 'Update Profile' : 'Save Member' ?>
                        </button>
                        <?php if ($edit_data): ?>
                            <a href="?tab=members" class="btn btn-gray">Cancel</a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-title"><i class="fas fa-list"></i> All Members (<?= count($members) ?>)</div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Photo</th>
                                <th>Reg. ID</th>
                                <th>Name</th>
                                <th>Designation</th>
                                <th>State</th>
                                <th>Validity</th>
                                <th>Show on Web</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($members)): ?>
                                <tr><td colspan="7" style="text-align:center; padding:30px; color:#888;">No members added yet.</td></tr>
                            <?php else: ?>
                                <?php foreach (array_reverse($members) as $m): ?>
                                <tr>
                                    <td><img src="<?= htmlspecialchars($m['photo']) ?>" style="width:42px; height:42px; border-radius:5px; object-fit:cover; border:2px solid #D32F2F;" alt=""></td>
                                    <td><strong style="font-size:0.8rem;"><?= htmlspecialchars($m['id']) ?></strong></td>
                                    <td><?= htmlspecialchars($m['name']) ?></td>
                                    <td><?= htmlspecialchars($m['designation']) ?></td>
                                    <td><?= htmlspecialchars($m['state'] ?? '') ?></td>
                                    <td><?= htmlspecialchars($m['validity'] ?? '') ?></td>
                                    <td><?= !empty($m['show_on_website']) ? '<span style="color: green;"><i class="fas fa-check-circle"></i> Yes</span>' : '<span style="color: red;"><i class="fas fa-times-circle"></i> No</span>' ?></td>
                                    <td class="actions">
                                        <a href="?tab=members&edit=<?= urlencode($m['id']) ?>" class="btn btn-yellow btn-sm"><i class="fas fa-edit"></i></a>
                                        <a href="?tab=members&delete_member=<?= urlencode($m['id']) ?>" class="btn btn-red btn-sm" onclick="return confirm('Delete this member?')"><i class="fas fa-trash"></i></a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- ============================= -->
            <!-- TAB: GALLERY                  -->
            <!-- ============================= -->
            <?php elseif ($active_tab === 'gallery'): ?>

            <div class="card">
                <div class="card-title"><i class="fas fa-upload"></i> Upload New Gallery Image</div>
                <form method="POST" action="admin.php?tab=gallery" enctype="multipart/form-data">
                    <div style="display: flex; gap: 15px; align-items: flex-end;">
                        <div class="form-group" style="flex: 1;">
                            <label>Select Image File</label>
                            <input type="file" name="gallery_image" accept="image/*" required>
                            <div class="hint">Accepted: JPG, PNG, WEBP, GIF</div>
                        </div>
                        <button type="submit" name="upload_gallery" class="btn btn-green"><i class="fas fa-upload"></i> Upload</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-title"><i class="fas fa-th"></i> Gallery Images (<?= count($gallery) ?>)</div>
                <?php if (empty($gallery)): ?>
                    <p style="color:#888; text-align:center; padding: 30px 0;">No gallery images uploaded yet. Upload some above!</p>
                <?php else: ?>
                    <div class="gallery-grid">
                        <?php foreach ($gallery as $idx => $img): ?>
                        <div class="gallery-item">
                            <img src="<?= htmlspecialchars($img) ?>" alt="Gallery Image <?= $idx+1 ?>">
                            <div class="gallery-del">
                                <a href="?tab=gallery&delete_gallery=<?= $idx ?>" class="btn btn-red btn-sm" onclick="return confirm('Delete this image?')" title="Delete"><i class="fas fa-times"></i></a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ============================= -->
            <!-- TAB: CERTIFICATES             -->
            <!-- ============================= -->
            <?php elseif ($active_tab === 'certificates'): ?>

            <div class="card">
                <div class="card-title"><i class="fas fa-upload"></i> Upload New Certificate</div>
                <form method="POST" action="admin.php?tab=certificates" enctype="multipart/form-data">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Certificate Title *</label>
                            <input type="text" name="cert_title" required placeholder="e.g. NGO Darpan Registration">
                        </div>
                        <div class="form-group">
                            <label>Certificate Caption</label>
                            <input type="text" name="cert_caption" placeholder="e.g. NGO Darpan Registration Certificate – NITI Aayog">
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label>Select Image File *</label>
                            <input type="file" name="cert_image" accept="image/*" required>
                            <div class="hint">Accepted: JPG, PNG, WEBP, GIF</div>
                        </div>
                    </div>
                    <div style="margin-top: 15px;">
                        <button type="submit" name="upload_certificate" class="btn btn-green"><i class="fas fa-upload"></i> Upload Certificate</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-title"><i class="fas fa-certificate"></i> All Certificates (<?= count($certificates) ?>)</div>
                <?php if (empty($certificates)): ?>
                    <p style="color:#888; text-align:center; padding: 30px 0;">No certificates uploaded yet.</p>
                <?php else: ?>
                    <div class="gallery-grid">
                        <?php foreach ($certificates as $idx => $cert): ?>
                        <div class="gallery-item">
                            <img src="<?= htmlspecialchars($cert['image']) ?>" alt="Certificate Image <?= $idx+1 ?>">
                            <div class="gallery-del">
                                <a href="?tab=certificates&delete_certificate=<?= $idx ?>" class="btn btn-red btn-sm" onclick="return confirm('Delete this certificate?')" title="Delete"><i class="fas fa-times"></i></a>
                            </div>
                            <div style="padding: 10px; background: #fff;">
                                <h4 style="font-size: 0.85rem; margin-bottom: 5px;"><?= htmlspecialchars($cert['title']) ?></h4>
                                <p style="font-size: 0.75rem; color: #777; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"><?= htmlspecialchars($cert['caption']) ?></p>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ============================= -->
            <!-- TAB: MESSAGES                 -->
            <!-- ============================= -->
            <?php elseif ($active_tab === 'messages'): ?>

            <div class="card">
                <div class="card-title">
                    <i class="fas fa-inbox"></i> Contact Form Messages
                    <?php if ($unread_count > 0): ?>
                        <span class="badge"><?= $unread_count ?> Unread</span>
                    <?php endif; ?>
                </div>
                <?php if (empty($msgs)): ?>
                    <p style="color:#888; text-align:center; padding: 40px 0;"><i class="fas fa-envelope-open" style="font-size:3rem; display:block; margin-bottom:15px; color:#ddd;"></i>No messages received yet.</p>
                <?php else: ?>
                    <?php foreach (array_reverse($msgs) as $msg): ?>
                    <div class="msg-card <?= ($msg['status'] ?? 'unread') ?>">
                        <div class="msg-meta">
                            <div class="msg-sender">
                                <?php if (($msg['status'] ?? 'unread') === 'unread'): ?>
                                    <span class="unread-dot"></span>
                                <?php endif; ?>
                                <strong><?= htmlspecialchars($msg['name']) ?></strong>
                                <span><?= htmlspecialchars($msg['email']) ?> &nbsp;|&nbsp; <?= htmlspecialchars($msg['phone'] ?? '') ?></span>
                                <span><?= htmlspecialchars($msg['date']) ?></span>
                            </div>
                            <div class="actions">
                                <?php if (($msg['status'] ?? 'unread') === 'unread'): ?>
                                    <a href="?tab=messages&mark_read=<?= urlencode($msg['id']) ?>" class="btn btn-green btn-sm"><i class="fas fa-check"></i> Mark Read</a>
                                <?php endif; ?>
                                <a href="?tab=messages&delete_message=<?= urlencode($msg['id']) ?>" class="btn btn-red btn-sm" onclick="return confirm('Delete this message?')"><i class="fas fa-trash"></i></a>
                            </div>
                        </div>
                        <div class="msg-subject">Subject: <?= htmlspecialchars($msg['subject'] ?? 'N/A') ?></div>
                        <div class="msg-body"><?= nl2br(htmlspecialchars($msg['message'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- ============================= -->
            <!-- TAB: SETTINGS                 -->
            <!-- ============================= -->
            <?php elseif ($active_tab === 'settings'): ?>

            <div class="card">
                <div class="card-title"><i class="fas fa-address-card"></i> Contact Details</div>
                <form method="POST" action="admin.php?tab=settings">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Primary Email</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($settings['email'] ?? '') ?>" placeholder="e.g. info@taccindia.org">
                        </div>
                        <div class="form-group">
                            <label>Phone Number 1</label>
                            <input type="text" name="phone1" value="<?= htmlspecialchars($settings['phone1'] ?? '') ?>" placeholder="e.g. +91 98147 66820">
                        </div>
                        <div class="form-group">
                            <label>Phone Number 2 (Optional)</label>
                            <input type="text" name="phone2" value="<?= htmlspecialchars($settings['phone2'] ?? '') ?>" placeholder="e.g. +91 98070-28000">
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label>Address</label>
                            <textarea name="address" rows="3" placeholder="Enter full address..."><?= htmlspecialchars($settings['address'] ?? '') ?></textarea>
                        </div>
                    </div>
                    
                    <h3 style="margin: 20px 0 15px; font-size: 1rem; color: #0A192F; border-bottom: 1px solid #eee; padding-bottom: 8px;">Bank Account Details</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Account Name</label>
                            <input type="text" name="bank_account_name" value="<?= htmlspecialchars($settings['bank_account_name'] ?? '') ?>" placeholder="e.g. TEAM AGAINST CORRUPTION AND CRIME">
                        </div>
                        <div class="form-group">
                            <label>Bank Name</label>
                            <input type="text" name="bank_name" value="<?= htmlspecialchars($settings['bank_name'] ?? '') ?>" placeholder="e.g. STATE BANK OF INDIA">
                        </div>
                        <div class="form-group">
                            <label>Branch Name</label>
                            <input type="text" name="bank_branch" value="<?= htmlspecialchars($settings['bank_branch'] ?? '') ?>" placeholder="e.g. NEW DELHI BRANCH">
                        </div>
                        <div class="form-group">
                            <label>Account Number</label>
                            <input type="text" name="bank_account_number" value="<?= htmlspecialchars($settings['bank_account_number'] ?? '') ?>" placeholder="e.g. 12345678901">
                        </div>
                        <div class="form-group">
                            <label>IFSC Code</label>
                            <input type="text" name="bank_ifsc" value="<?= htmlspecialchars($settings['bank_ifsc'] ?? '') ?>" placeholder="e.g. SBIN0001234">
                        </div>
                    </div>
                    <div style="margin-top: 15px;">
                        <button type="submit" name="save_settings" class="btn btn-blue"><i class="fas fa-save"></i> Save Details</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-title"><i class="fas fa-qrcode"></i> Donation QR Code</div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: start;">
                    <div>
                        <p style="margin-bottom: 15px; color: #555; font-size: 0.9rem;">The current QR code displayed on the Donate page. Upload a new image to replace it instantly across the website.</p>
                        <form method="POST" action="admin.php?tab=settings" enctype="multipart/form-data">
                            <div class="form-group" style="margin-bottom: 15px;">
                                <label>Upload New QR Code Image</label>
                                <input type="file" name="qr_image" accept="image/*" required>
                                <div class="hint">Accepted: JPG, PNG, WEBP</div>
                            </div>
                            <button type="submit" name="upload_qr" class="btn btn-blue"><i class="fas fa-save"></i> Update QR Code</button>
                        </form>
                    </div>
                    <div>
                        <p style="font-weight: 600; margin-bottom: 10px; color: #555; font-size: 0.85rem; text-transform: uppercase;">Current QR Code Preview</p>
                        <div class="qr-preview">
                            <img src="<?= htmlspecialchars($settings['qr_code_path']) ?>" alt="Current QR Code">
                        </div>
                        <p style="font-size:0.75rem; color:#888; margin-top:8px; text-align:center;"><?= htmlspecialchars($settings['qr_code_path']) ?></p>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title"><i class="fas fa-info-circle"></i> ID Generation Info</div>
                <p style="color:#555; font-size:0.9rem; margin-bottom: 15px;">New member IDs are auto-generated in the following format:</p>
                <div style="background:#f8f9fa; border-radius: 8px; padding: 20px; border-left: 4px solid #D32F2F;">
                    <div style="font-size: 1.3rem; font-weight: 700; color: #0A192F; font-family: monospace;">Regd.no.Tacc/00/00001</div>
                    <div style="font-size: 0.8rem; color: #777; margin-top: 8px;">The number increments automatically for every new member added.</div>
                </div>
                <div style="margin-top: 15px; padding: 12px 16px; background: #e8f5e9; border-radius: 7px; border: 1px solid #c8e6c9;">
                    <strong style="color:#2e7d32;">Next ID will be:</strong> <span style="font-family: monospace; font-weight: 600;"><?= htmlspecialchars($next_id) ?></span>
                </div>
            </div>

            <!-- Server Diagnostics Card -->
            <div class="card">
                <div class="card-title"><i class="fas fa-server"></i> Server Upload Diagnostics</div>
                <p style="color:#555;font-size:0.9rem;margin-bottom:16px;">Agar image upload nahi ho rahi, neeche check karein:</p>
                <?php
                    $img_dir_writable  = is_writable('assets/images/');
                    $photo_dir_writable = is_writable('assets/photos/');
                    $upload_max = ini_get('upload_max_filesize');
                    $post_max   = ini_get('post_max_size');
                    $tmp_ok     = is_writable(sys_get_temp_dir());

                    function diagRow($label, $ok, $val, $fix='') {
                        $icon  = $ok ? "<span style='color:#2e7d32'>✔</span>" : "<span style='color:#c62828'>✖</span>";
                        $color = $ok ? '#e8f5e9' : '#ffebee';
                        $border= $ok ? '#c8e6c9' : '#ffcdd2';
                        echo "<div style='display:flex;justify-content:space-between;align-items:center;padding:12px 16px;background:$color;border:1px solid $border;border-radius:7px;margin-bottom:10px;flex-wrap:wrap;gap:8px;'>
                            <div>$icon &nbsp;<strong>$label</strong>" . ($fix && !$ok ? "<br><small style='color:#c62828;margin-left:22px;'>$fix</small>" : "") . "</div>
                            <code style='font-size:0.82rem;'>$val</code>
                        </div>";
                    }
                ?>
                <?php diagRow('assets/images/ folder writable', $img_dir_writable,
                    $img_dir_writable ? 'Writable ✔' : 'NOT writable ✖',
                    'XAMPP mein assets/images folder par right-click > Properties > Security > Allow write'); ?>
                <?php diagRow('assets/photos/ folder writable', $photo_dir_writable,
                    $photo_dir_writable ? 'Writable ✔' : 'NOT writable ✖',
                    'assets/photos/ folder ko writable banayein'); ?>
                <?php diagRow('PHP Temp Dir writable', $tmp_ok,
                    sys_get_temp_dir(),
                    'PHP temp directory writable nahi hai - XAMPP restart karein'); ?>
                <?php diagRow('upload_max_filesize', true, $upload_max); ?>
                <?php diagRow('post_max_size', true, $post_max); ?>
                <?php diagRow('PHP Version', true, phpversion()); ?>

                <?php if (!$img_dir_writable || !$photo_dir_writable): ?>
                <div style="margin-top:14px;padding:14px 16px;background:#fff3e0;border:1px solid #ffe0b2;border-radius:7px;font-size:0.88rem;color:#e65100;">
                    <strong>⚠ Solution:</strong> XAMPP mein <code>c:\xampp\htdocs\tacacindia\assets\</code> folder open karein,
                    right-click karein, Properties > Security tab mein <strong>Everyone</strong> ko Full Control dein.
                    Ya phir XAMPP Apache ko Administrator mode mein run karein.
                </div>
                <?php endif; ?>
            </div>

            <?php endif; ?>

        </div><!-- end content-area -->
    </div><!-- end main-content -->
</div><!-- end admin-layout -->

<?php endif; ?>
</body>
</html>
