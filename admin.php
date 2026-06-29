<?php
session_start();

$admin_password = 'mpwaindia@india';
$data_file = 'data.json';
$requests_file = 'requests.json';
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
function getRequests() {
    global $requests_file;
    return file_exists($requests_file) ? json_decode(file_get_contents($requests_file), true) : [];
}
function saveRequests($data) {
    global $requests_file;
    return file_put_contents($requests_file, json_encode(array_values($data), JSON_PRETTY_PRINT));
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

// Generate next official MPWA Registration ID (e.g. MPWA-26-5001)
function getNextMpwaId() {
    $members = getMembers();
    $max = 5000;
    foreach ($members as $m) {
        if (preg_match('/MPWA-\d+-(\d+)/i', $m['id'], $match)) {
            $num = intval($match[1]);
            if ($num > $max) $max = $num;
        }
    }
    $next = $max + 1;
    return 'MPWA-' . (date('y')) . '-' . $next;
}

// Helper: Compress and resize uploaded images
function compressImage($source, $destination, $max_width = 1200, $quality = 75) {
    if (!extension_loaded('gd') || !function_exists('imagecreatefromstring')) {
        return move_uploaded_file($source, $destination);
    }
    $info = getimagesize($source);
    if ($info === false) return move_uploaded_file($source, $destination);
    
    $mime = $info['mime'];
    switch ($mime) {
        case 'image/jpeg':
        case 'image/jpg': $image = @imagecreatefromjpeg($source); break;
        case 'image/png': $image = @imagecreatefrompng($source); break;
        case 'image/gif': $image = @imagecreatefromgif($source); break;
        case 'image/webp': $image = @imagecreatefromwebp($source); break;
        default: $image = false;
    }
    if (!$image) return move_uploaded_file($source, $destination);
    
    $orig_width = imagesx($image);
    $orig_height = imagesy($image);
    
    if ($orig_width > $max_width) {
        $new_width = $max_width;
        $new_height = floor($orig_height * ($max_width / $orig_width));
        $new_image = imagecreatetruecolor($new_width, $new_height);
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
    
    $success = false;
    $ext = strtolower(pathinfo($destination, PATHINFO_EXTENSION));
    switch ($ext) {
        case 'png': $success = @imagepng($image, $destination, 9 - round(($quality / 100) * 9)); break;
        case 'gif': $success = @imagegif($image, $destination); break;
        case 'webp': $success = @imagewebp($image, $destination, $quality); break;
        default: $success = @imagejpeg($image, $destination, $quality); break;
    }
    imagedestroy($image);
    return $success;
}

// ---- HANDLE: APPROVE REQUEST ----
if ($is_logged_in && isset($_POST['approve_request'])) {
    $req_id = $_POST['request_id'];
    $assigned_id = trim($_POST['assigned_id']);
    $validity = trim($_POST['validity']);
    
    $requests = getRequests();
    $target_req = null;
    $remaining_reqs = [];
    foreach ($requests as $r) {
        if ($r['request_id'] === $req_id) {
            $target_req = $r;
        } else {
            $remaining_reqs[] = $r;
        }
    }
    
    if ($target_req) {
        // Check if assigned ID already exists in members
        $members = getMembers();
        $exists = false;
        foreach ($members as $m) {
            if (strtoupper($m['id']) === strtoupper($assigned_id)) {
                $exists = true;
                break;
            }
        }
        
        if ($exists) {
            $_SESSION['message'] = "<div class='msg error'>Error: Registration Number <strong>$assigned_id</strong> already exists!</div>";
        } else {
            // Add to members
            $new_member = [
                'id' => $assigned_id,
                'name' => $target_req['name'],
                'fatherName' => $target_req['fatherName'] ?? '',
                'motherName' => $target_req['motherName'] ?? '',
                'aadharNo' => $target_req['aadharNo'] ?? '',
                'dob' => $target_req['dob'] ?? '',
                'mobileNo' => $target_req['mobileNo'] ?? '',
                'designation' => $target_req['regType'] ?? 'RMP Doctor',
                'state' => $target_req['state'] ?? 'Punjab',
                'address' => $target_req['address'] ?? '',
                'awardingBody' => $target_req['awardingBody'] ?? '',
                'validity' => $validity,
                'photo' => $target_req['photo'],
                'show_on_website' => true
            ];
            $members[] = $new_member;
            
            if (saveMembers($members) && saveRequests($remaining_reqs)) {
                $_SESSION['message'] = "<div class='msg success'>Application approved! Member successfully registered with ID: <strong>$assigned_id</strong></div>";
            } else {
                $_SESSION['message'] = "<div class='msg error'>Error processing approval files.</div>";
            }
        }
    } else {
        $_SESSION['message'] = "<div class='msg error'>Request application not found.</div>";
    }
    header("Location: admin.php?tab=requests");
    exit;
}

// ---- HANDLE: DELETE REQUEST ----
if ($is_logged_in && isset($_GET['delete_request'])) {
    $del_id = $_GET['delete_request'];
    $requests = getRequests();
    $new_reqs = array_filter($requests, fn($r) => $r['request_id'] !== $del_id);
    
    // Delete photo if it's stored on server
    foreach ($requests as $r) {
        if ($r['request_id'] === $del_id && !empty($r['photo']) && strpos($r['photo'], 'https://ui-avatars') === false) {
            @unlink($r['photo']);
        }
    }
    
    if (saveRequests($new_reqs)) {
        $_SESSION['message'] = "<div class='msg success'>Registration request deleted/rejected successfully.</div>";
    } else {
        $_SESSION['message'] = "<div class='msg error'>Error deleting request.</div>";
    }
    header("Location: admin.php?tab=requests");
    exit;
}

// ---- HANDLE: DELETE APPROVED MEMBER ----
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

// ---- HANDLE: UPDATE MEMBER ----
$edit_data = null;
if ($is_logged_in && isset($_GET['edit'])) {
    foreach (getMembers() as $m) {
        if (strtoupper($m['id']) === strtoupper($_GET['edit'])) {
            $edit_data = $m; break;
        }
    }
}
if ($is_logged_in && isset($_POST['update_member'])) {
    $current_data = getMembers();
    $original_id = $_POST['original_id'];
    $member_id = trim($_POST['id']);
    $member_name = trim($_POST['name']);
    $father_name = trim($_POST['fatherName'] ?? '');
    $mother_name = trim($_POST['motherName'] ?? '');
    $aadhar_no = trim($_POST['aadharNo'] ?? '');
    $dob = trim($_POST['dob'] ?? '');
    $mobile_no = trim($_POST['mobileNo'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $awarding_body = trim($_POST['awardingBody'] ?? '');
    $photo_path = $_POST['existing_photo'] ?? '';

    $upload_error = "";
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $safe_id = preg_replace('/[^A-Za-z0-9_\-]/', '_', $member_id);
            $fname = $photos_dir . $safe_id . '_' . time() . '.' . $ext;
            if (compressImage($_FILES['photo']['tmp_name'], $fname, 800, 75)) {
                $photo_path = $fname;
            } else {
                $upload_error = "<div class='msg error'>Photo upload failed. Check permissions.</div>";
            }
        }
    }
    
    $new_member = [
        "id" => $member_id,
        "name" => $member_name,
        "fatherName" => $father_name,
        "motherName" => $mother_name,
        "aadharNo" => $aadhar_no,
        "dob" => $dob,
        "mobileNo" => $mobile_no,
        "designation" => trim($_POST['designation']),
        "state" => trim($_POST['state']),
        "address" => $address,
        "awardingBody" => $awarding_body,
        "validity" => trim($_POST['validity']),
        "photo" => $photo_path,
        "show_on_website" => isset($_POST['show_on_website']) ? true : false
    ];

    $exists = false;
    foreach ($current_data as $m) {
        if (strtoupper($m['id']) === strtoupper($member_id) && strtoupper($original_id) !== strtoupper($member_id)) {
            $exists = true; break;
        }
    }

    if ($exists) {
        $message = "<div class='msg error'>Error: Registration ID already exists!</div>";
    } else {
        foreach ($current_data as $k => $m) {
            if (strtoupper($m['id']) === strtoupper($original_id)) {
                $current_data[$k] = $new_member; break;
            }
        }
        saveMembers($current_data);
        $_SESSION['message'] = $upload_error . "<div class='msg success'>Member updated successfully!</div>";
        header("Location: admin.php?tab=members");
        exit;
    }
}

// ---- HANDLE: ADD MEMBER ----
if ($is_logged_in && isset($_POST['add_member'])) {
    $member_id = trim($_POST['id']);
    $member_name = trim($_POST['name']);
    $father_name = trim($_POST['fatherName'] ?? '');
    $mother_name = trim($_POST['motherName'] ?? '');
    $aadhar_no = trim($_POST['aadharNo'] ?? '');
    $dob = trim($_POST['dob'] ?? '');
    $mobile_no = trim($_POST['mobileNo'] ?? '');
    $designation = trim($_POST['designation']);
    $state = trim($_POST['state']);
    $address = trim($_POST['address'] ?? '');
    $awarding_body = trim($_POST['awardingBody'] ?? '');
    $validity = trim($_POST['validity']);
    $show_on_website = isset($_POST['show_on_website']) ? true : false;
    $photo_path = "";

    $upload_error = "";
    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $safe_id = preg_replace('/[^A-Za-z0-9_\-]/', '_', $member_id);
            $fname = $photos_dir . $safe_id . '_' . time() . '.' . $ext;
            if (compressImage($_FILES['photo']['tmp_name'], $fname, 800, 75)) {
                $photo_path = $fname;
            } else {
                $upload_error = "<div class='msg error'>Photo upload failed. Check permissions.</div>";
            }
        }
    }
    
    if (empty($photo_path)) {
        $photo_path = "https://ui-avatars.com/api/?name=" . urlencode($member_name) . "&background=112240&color=fff&size=150";
    }

    $current_data = getMembers();
    $exists = false;
    foreach ($current_data as $m) {
        if (strtoupper($m['id']) === strtoupper($member_id)) {
            $exists = true; break;
        }
    }

    if ($exists) {
        $_SESSION['message'] = "<div class='msg error'>Error: Registration ID <strong>$member_id</strong> already exists!</div>";
    } else {
        $new_member = [
            "id" => $member_id,
            "name" => $member_name,
            "fatherName" => $father_name,
            "motherName" => $mother_name,
            "aadharNo" => $aadhar_no,
            "dob" => $dob,
            "mobileNo" => $mobile_no,
            "designation" => $designation,
            "state" => $state,
            "address" => $address,
            "awardingBody" => $awarding_body,
            "validity" => $validity,
            "photo" => $photo_path,
            "show_on_website" => $show_on_website
        ];
        $current_data[] = $new_member;
        if (saveMembers($current_data)) {
            $_SESSION['message'] = $upload_error . "<div class='msg success'>Member successfully registered with ID: <strong>$member_id</strong></div>";
        } else {
            $_SESSION['message'] = "<div class='msg error'>Error processing files.</div>";
        }
    }
    header("Location: admin.php?tab=members");
    exit;
}

// ---- OTHER HANDLERS ----
// Upload Gallery
if ($is_logged_in && isset($_POST['upload_gallery'])) {
    if (isset($_FILES['gallery_image']) && $_FILES['gallery_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['gallery_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $fname = $upload_dir . 'gallery_' . time() . rand(100,999) . '.' . $ext;
            if (compressImage($_FILES['gallery_image']['tmp_name'], $fname, 1200, 75)) {
                $gallery = getGallery();
                $gallery[] = $fname;
                saveGallery($gallery);
                $_SESSION['message'] = "<div class='msg success'>Gallery image uploaded!</div>";
            }
        }
    }
    header("Location: admin.php?tab=gallery");
    exit;
}
// Delete Gallery
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
// Upload Certificate
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
                $_SESSION['message'] = "<div class='msg success'>Certificate uploaded!</div>";
            }
        }
    }
    header("Location: admin.php?tab=certificates");
    exit;
}
// Delete Certificate
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
// Save Settings
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
    $_SESSION['message'] = "<div class='msg success'>Settings saved!</div>";
    header("Location: admin.php?tab=settings");
    exit;
}
// Upload QR
if ($is_logged_in && isset($_POST['upload_qr'])) {
    if (isset($_FILES['qr_image']) && $_FILES['qr_image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['qr_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            $fname = $upload_dir . 'donate_qr_' . time() . rand(100,999) . '.' . $ext;
            if (compressImage($_FILES['qr_image']['tmp_name'], $fname, 600, 80)) {
                $settings = getSettings();
                $settings['qr_code_path'] = $fname;
                saveSettings($settings);
                $_SESSION['message'] = "<div class='msg success'>QR Code updated!</div>";
            }
        }
    }
    header("Location: admin.php?tab=settings");
    exit;
}
// Mark read / Delete message
if ($is_logged_in && isset($_GET['mark_read'])) {
    $msgs = getMessages();
    foreach ($msgs as &$msg) {
        if ($msg['id'] === $_GET['mark_read']) { $msg['status'] = 'read'; break; }
    }
    file_put_contents($messages_file, json_encode($msgs, JSON_PRETTY_PRINT));
    header("Location: admin.php?tab=messages");
    exit;
}
if ($is_logged_in && isset($_GET['delete_message'])) {
    $msgs = getMessages();
    $msgs = array_values(array_filter($msgs, fn($m) => $m['id'] !== $_GET['delete_message']));
    file_put_contents($messages_file, json_encode($msgs, JSON_PRETTY_PRINT));
    header("Location: admin.php?tab=messages");
    exit;
}

// ---- READ DATA ----
$members  = getMembers();
$requests = getRequests();
$gallery  = getGallery();
$certificates = getCertificates();
$msgs     = getMessages();
$settings = getSettings();

$active_tab = $_GET['tab'] ?? 'requests';
$unread_count = count(array_filter($msgs, fn($m) => ($m['status'] ?? 'unread') === 'unread'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MPWA Admin Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Inter', sans-serif; background: #f0f4f8; color: #333; }
        .login-wrapper { display: flex; min-height: 100vh; align-items: center; justify-content: center; background: linear-gradient(135deg, #0A192F 60%, #D32F2F 100%); }
        .login-card { background: #fff; border-radius: 12px; box-shadow: 0 20px 60px rgba(0,0,0,0.3); padding: 50px 40px; width: 400px; text-align: center; }
        .login-card img { width: 90px; margin-bottom: 20px; }
        .login-card h2 { color: #0A192F; margin-bottom: 8px; font-size: 1.6rem; }
        .login-card p { color: #777; margin-bottom: 30px; font-size: 0.9rem; }
        .admin-layout { display: flex; min-height: 100vh; }
        .sidebar { width: 260px; background: #0A192F; color: #fff; flex-shrink: 0; display: flex; flex-direction: column; }
        .sidebar-header { padding: 30px 25px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 15px; }
        .sidebar-header img { width: 50px; height: 50px; background: white; border-radius: 50%; padding: 3px; }
        .sidebar-header div h3 { font-size: 0.95rem; font-weight: 700; }
        .sidebar-header div span { font-size: 0.72rem; color: #8892B0; }
        .sidebar-nav { flex: 1; padding: 20px 0; }
        .sidebar-nav a { display: flex; align-items: center; gap: 12px; padding: 13px 25px; color: #8892B0; font-size: 0.92rem; font-weight: 500; text-decoration: none; transition: all 0.2s; position: relative; }
        .sidebar-nav a:hover { background: rgba(255,255,255,0.07); color: #fff; }
        .sidebar-nav a.active { background: rgba(211,47,47,0.15); color: #fff; border-left: 3px solid #D32F2F; }
        .sidebar-nav a i { width: 20px; text-align: center; font-size: 1rem; }
        .badge { background: #D32F2F; color: #fff; font-size: 0.7rem; padding: 2px 7px; border-radius: 10px; margin-left: auto; }
        .sidebar-footer { padding: 20px 25px; border-top: 1px solid rgba(255,255,255,0.1); }
        .sidebar-footer a { color: #8892B0; font-size: 0.85rem; text-decoration: none; display: flex; align-items: center; gap: 8px; }
        .sidebar-footer a:hover { color: #D32F2F; }
        .main-content { flex: 1; overflow-y: auto; }
        .topbar { background: #fff; padding: 18px 30px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e5e7eb; }
        .topbar h1 { font-size: 1.4rem; color: #0A192F; font-weight: 700; }
        .content-area { padding: 30px; }
        .card { background: #fff; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); padding: 28px; margin-bottom: 25px; }
        .card-title { font-size: 1.1rem; font-weight: 700; color: #0A192F; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
        .card-title i { color: #D32F2F; }
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 25px; }
        .stat-card { background: #fff; border-radius: 10px; padding: 20px 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); border-left: 4px solid #D32F2F; }
        .stat-card h3 { font-size: 2rem; font-weight: 700; color: #0A192F; }
        .stat-card p { font-size: 0.85rem; color: #777; margin-top: 5px; }
        .stat-card.blue { border-color: #0A192F; }
        .stat-card.green { border-color: #2e7d32; }
        .stat-card.orange { border-color: #f39c12; }
        .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 18px; }
        .form-group { margin-bottom: 0; }
        .form-group label { display: block; margin-bottom: 6px; font-weight: 600; font-size: 0.85rem; color: #555; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 11px 14px; border: 1.5px solid #e0e0e0; border-radius: 7px; font-size: 0.9rem; font-family: 'Inter', sans-serif; transition: border-color 0.2s; background: #fafafa; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: #D32F2F; background: #fff; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 22px; border-radius: 7px; border: none; cursor: pointer; font-size: 0.88rem; font-weight: 600; text-decoration: none; transition: all 0.2s; }
        .btn-red { background: #D32F2F; color: #fff; }
        .btn-red:hover { background: #B71C1C; }
        .btn-blue { background: #0A192F; color: #fff; }
        .btn-blue:hover { background: #071224; }
        .btn-green { background: #2e7d32; color: #fff; }
        .btn-green:hover { background: #1b5e20; }
        .btn-gray { background: #eee; color: #555; }
        .btn-gray:hover { background: #ddd; }
        .btn-sm { padding: 6px 12px; font-size: 0.8rem; }
        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        th, td { padding: 12px 16px; text-align: left; border-bottom: 1px solid #f0f0f0; }
        th { background: #f8f9fa; font-weight: 600; color: #555; font-size: 0.8rem; text-transform: uppercase; }
        .msg { padding: 12px 16px; border-radius: 7px; margin-bottom: 20px; font-weight: 500; font-size: 0.9rem; }
        .msg.error { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        .msg.success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .request-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
        .request-grid { display: grid; grid-template-columns: 100px 1fr; gap: 20px; }
        .request-photo { width: 100px; height: 120px; object-fit: cover; border-radius: 6px; border: 2px solid #0A192F; }
        .request-details-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; font-size: 0.85rem; }
        .request-details-grid div strong { color: #0A192F; }
        .approval-form-box { background: #f8fafc; border: 1.5px dashed #cbd5e1; border-radius: 8px; padding: 15px; margin-top: 15px; display: none; }
        .gallery-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; }
        .gallery-item { position: relative; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .gallery-item img { width: 100%; height: 150px; object-fit: cover; display: block; }
        .gallery-item .gallery-del { position: absolute; top: 8px; right: 8px; }
        .qr-preview img { max-width: 200px; }
    </style>
    <script>
        function toggleApprovalForm(id) {
            const form = document.getElementById('approval-form-' + id);
            if(form.style.display === 'none' || form.style.display === '') {
                form.style.display = 'block';
            } else {
                form.style.display = 'none';
            }
        }
        function toggleAddMemberForm() {
            const form = document.getElementById('add-member-form');
            if(form.style.display === 'none' || form.style.display === '') {
                form.style.display = 'block';
            } else {
                form.style.display = 'none';
            }
        }
    </script>
</head>
<body>

<?php if (!$is_logged_in): ?>
<div class="login-wrapper">
    <div class="login-card">
        <img src="assets/logo.png" alt="MPWA Logo" onerror="this.src='https://via.placeholder.com/90';">
        <h2>Admin Panel</h2>
        <p>Medical Practitioners Welfare Association</p>
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
<div class="admin-layout">

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="sidebar-header">
            <img src="assets/logo.png" alt="MPWA">
            <div>
                <h3>MPWA Admin</h3>
                <span>Registration & Welfare</span>
            </div>
        </div>
        <nav class="sidebar-nav">
            <a href="?tab=requests" class="<?= $active_tab === 'requests' ? 'active' : '' ?>">
                <i class="fas fa-user-clock"></i> Pending Requests
                <span class="badge" style="background:#f39c12;"><?= count($requests) ?></span>
            </a>
            <a href="?tab=members" class="<?= $active_tab === 'members' ? 'active' : '' ?>">
                <i class="fas fa-users"></i> Approved Members
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
                <?php if ($unread_count > 0): ?><span class="badge"><?= $unread_count ?></span><?php endif; ?>
            </a>
            <a href="?tab=settings" class="<?= $active_tab === 'settings' ? 'active' : '' ?>">
                <i class="fas fa-cog"></i> Settings
            </a>
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
                $titles = [
                    'requests' => 'Pending Registration Applications',
                    'members' => 'Approved Active Members',
                    'gallery' => 'Manage Gallery',
                    'certificates' => 'Manage Certificates',
                    'messages' => 'Messages Box',
                    'settings' => 'Website Settings'
                ];
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
                <div class="stat-card orange">
                    <h3><?= count($requests) ?></h3>
                    <p>Pending Requests</p>
                </div>
                <div class="stat-card">
                    <h3><?= count($members) ?></h3>
                    <p>Approved Members</p>
                </div>
                <div class="stat-card blue">
                    <h3><?= count($gallery) ?></h3>
                    <p>Gallery Images</p>
                </div>
                <div class="stat-card green">
                    <h3><?= count($certificates) ?></h3>
                    <p>Certificates</p>
                </div>
            </div>

            <!-- ============================= -->
            <!-- TAB: REQUESTS (PENDING LIST)  -->
            <!-- ============================= -->
            <?php if ($active_tab === 'requests'): ?>
            <div class="card">
                <div class="card-title"><i class="fas fa-user-clock"></i> New Applications (<?= count($requests) ?>)</div>
                <?php if (empty($requests)): ?>
                    <p style="color:#777; text-align:center; padding: 40px 0;"><i class="fas fa-check-circle" style="font-size:3rem; display:block; margin-bottom:15px; color:#2e7d32;"></i>All caught up! No pending registrations.</p>
                <?php else: ?>
                    <?php foreach (array_reverse($requests) as $req): ?>
                    <div class="request-card">
                        <div class="request-grid">
                            <div>
                                <img src="<?= htmlspecialchars($req['photo']) ?>" alt="Photo" class="request-photo">
                            </div>
                            <div>
                                <h3 style="color:#0A192F; font-size:1.15rem; margin-bottom:8px;"><?= htmlspecialchars($req['name']) ?></h3>
                                <div class="request-details-grid">
                                    <div><strong>Request Ref ID:</strong> <?= htmlspecialchars($req['request_id']) ?></div>
                                    <div><strong>Registration Type:</strong> <?= htmlspecialchars($req['regType']) ?></div>
                                    <div><strong>Mobile No:</strong> <?= htmlspecialchars($req['mobileNo']) ?></div>
                                    <div><strong>Father's Name:</strong> <?= htmlspecialchars($req['fatherName']) ?></div>
                                    <div><strong>Mother's Name:</strong> <?= htmlspecialchars($req['motherName']) ?></div>
                                    <div><strong>Aadhar Number:</strong> <?= htmlspecialchars($req['aadharNo']) ?></div>
                                    <div><strong>Date of Birth:</strong> <?= htmlspecialchars($req['dob']) ?></div>
                                    <div><strong>Awarding Board/Uni:</strong> <?= htmlspecialchars($req['awardingBody']) ?></div>
                                    <div><strong>State:</strong> <?= htmlspecialchars($req['state']) ?></div>
                                    <div style="grid-column: span 3;"><strong>Address:</strong> <?= htmlspecialchars($req['address']) ?></div>
                                </div>
                                
                                <div style="margin-top: 15px; display:flex; gap: 10px;">
                                    <button type="button" class="btn btn-green btn-sm" onclick="toggleApprovalForm('<?= $req['request_id'] ?>')">
                                        <i class="fas fa-check"></i> Approve Application
                                    </button>
                                    <a href="?tab=requests&delete_request=<?= urlencode($req['request_id']) ?>" class="btn btn-red btn-sm" onclick="return confirm('Reject and delete this application request?')">
                                        <i class="fas fa-trash"></i> Reject / Delete
                                    </a>
                                </div>

                                <!-- Approval form slide box -->
                                <div class="approval-form-box" id="approval-form-<?= $req['request_id'] ?>">
                                    <h4 style="color:#0A192F; font-size:0.9rem; margin-bottom:10px; font-weight:700;">Set Registration Details:</h4>
                                    <form method="POST" action="admin.php?tab=requests">
                                        <input type="hidden" name="request_id" value="<?= htmlspecialchars($req['request_id']) ?>">
                                        <div class="form-grid">
                                            <div class="form-group">
                                                <label>Registration Number (ID) *</label>
                                                <input type="text" name="assigned_id" required value="<?= htmlspecialchars(getNextMpwaId()) ?>">
                                            </div>
                                            <div class="form-group">
                                                <label>Validity Date (e.g., 5 Years or Lifetime) *</label>
                                                <?php
                                                    $fiveYearsLater = date('d-M-Y', strtotime('+5 years'));
                                                ?>
                                                <input type="text" name="validity" required value="<?= $fiveYearsLater ?>">
                                            </div>
                                        </div>
                                        <div style="margin-top:12px;">
                                            <button type="submit" name="approve_request" class="btn btn-blue btn-sm"><i class="fas fa-save"></i> Confirm and Approve</button>
                                            <button type="button" class="btn btn-gray btn-sm" onclick="toggleApprovalForm('<?= $req['request_id'] ?>')">Cancel</button>
                                        </div>
                                    </form>
                                </div>

                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- ============================= -->
            <!-- TAB: MEMBERS (APPROVED LIST)  -->
            <!-- ============================= -->
            <?php elseif ($active_tab === 'members'): ?>
            
            <?php if ($edit_data): ?>
            <!-- Edit Member Form -->
            <div class="card">
                <div class="card-title"><i class="fas fa-edit"></i> Edit Approved Member Profile</div>
                <form method="POST" action="admin.php?tab=members" enctype="multipart/form-data">
                    <input type="hidden" name="original_id" value="<?= htmlspecialchars($edit_data['id']) ?>">
                    <input type="hidden" name="existing_photo" value="<?= htmlspecialchars($edit_data['photo']) ?>">
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Name of the university/Board/Council awarding Certificate *</label>
                            <input type="text" name="awardingBody" required value="<?= htmlspecialchars($edit_data['awardingBody'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Registration ID *</label>
                            <input type="text" name="id" required value="<?= htmlspecialchars($edit_data['id']) ?>">
                        </div>
                        <div class="form-group">
                            <label>Full Name *</label>
                            <input type="text" name="name" required value="<?= htmlspecialchars($edit_data['name']) ?>">
                        </div>
                        <div class="form-group">
                            <label>Father Name *</label>
                            <input type="text" name="fatherName" required value="<?= htmlspecialchars($edit_data['fatherName'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Mother Name *</label>
                            <input type="text" name="motherName" required value="<?= htmlspecialchars($edit_data['motherName'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Aadhar number *</label>
                            <input type="text" name="aadharNo" required value="<?= htmlspecialchars($edit_data['aadharNo'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Date of Birth *</label>
                            <input type="date" name="dob" required value="<?= htmlspecialchars($edit_data['dob'] ?? '') ?>" style="width: 100%; padding: 11px 14px; border: 1.5px solid #e0e0e0; border-radius: 7px; font-size: 0.9rem; font-family: 'Inter', sans-serif; background: #fafafa;">
                        </div>
                        <div class="form-group">
                            <label>Mobile number *</label>
                            <input type="text" name="mobileNo" required value="<?= htmlspecialchars($edit_data['mobileNo'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Designation *</label>
                            <input type="text" name="designation" required value="<?= htmlspecialchars($edit_data['designation']) ?>">
                        </div>
                        <div class="form-group">
                            <label>State *</label>
                            <input type="text" name="state" required value="<?= htmlspecialchars($edit_data['state'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Validity *</label>
                            <input type="text" name="validity" required value="<?= htmlspecialchars($edit_data['validity'] ?? '') ?>">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Residential Address *</label>
                            <textarea name="address" rows="3" required style="width: 100%; padding: 11px 14px; border: 1.5px solid #e0e0e0; border-radius: 7px; font-size: 0.9rem; font-family: 'Inter', sans-serif; background: #fafafa;"><?= htmlspecialchars($edit_data['address'] ?? '') ?></textarea>
                        </div>
                        <div class="form-group">
                            <label>Show on Website</label>
                            <div style="display: flex; align-items: center; gap: 8px; margin-top: 5px;">
                                <input type="checkbox" name="show_on_website" id="show_on_website" value="1" <?= (!empty($edit_data['show_on_website'])) ? 'checked' : '' ?> style="width: auto;">
                                <label for="show_on_website" style="margin: 0; font-weight: normal; cursor: pointer;">Show on homepage leaders grid</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Upload Photo (Optional)</label>
                            <input type="file" name="photo" accept="image/*">
                        </div>
                    </div>
                    <div style="margin-top: 20px; display: flex; gap: 10px;">
                        <button type="submit" name="update_member" class="btn btn-red"><i class="fas fa-save"></i> Save Changes</button>
                        <a href="?tab=members" class="btn btn-gray">Cancel</a>
                    </div>
                </form>
            </div>
            <?php endif; ?>

            <!-- Add Member Trigger Button -->
            <div style="margin-bottom: 20px; display: flex; justify-content: flex-end;">
                <button type="button" class="btn btn-green" onclick="toggleAddMemberForm()">
                    <i class="fas fa-user-plus"></i> Add New Member
                </button>
            </div>

            <!-- Add Member Form -->
            <div class="card" id="add-member-form" style="display: none;">
                <div class="card-title"><i class="fas fa-user-plus"></i> Add New Member Profile</div>
                <form method="POST" action="admin.php?tab=members" enctype="multipart/form-data">
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Name of the university/Board/Council awarding Certificate *</label>
                            <input type="text" name="awardingBody" required placeholder="Enter awarding university/board/council">
                        </div>
                        <div class="form-group">
                            <label>Registration ID *</label>
                            <input type="text" name="id" required value="<?= htmlspecialchars(getNextMpwaId()) ?>">
                        </div>
                        <div class="form-group">
                            <label>Full Name of candidate *</label>
                            <input type="text" name="name" required placeholder="Enter full name of candidate">
                        </div>
                        <div class="form-group">
                            <label>Father Name *</label>
                            <input type="text" name="fatherName" required placeholder="Enter Father Name">
                        </div>
                        <div class="form-group">
                            <label>Mother Name *</label>
                            <input type="text" name="motherName" required placeholder="Enter Mother Name">
                        </div>
                        <div class="form-group">
                            <label>Aadhar number *</label>
                            <input type="text" name="aadharNo" required placeholder="Enter Aadhar number">
                        </div>
                        <div class="form-group">
                            <label>Date of Birth *</label>
                            <input type="date" name="dob" required style="width: 100%; padding: 11px 14px; border: 1.5px solid #e0e0e0; border-radius: 7px; font-size: 0.9rem; font-family: 'Inter', sans-serif; background: #fafafa;">
                        </div>
                        <div class="form-group">
                            <label>Mobile number *</label>
                            <input type="text" name="mobileNo" required placeholder="Enter Mobile number">
                        </div>
                        <div class="form-group">
                            <label>Registration Type (Designation) *</label>
                            <select name="designation" required style="width: 100%; padding: 11px 14px; border: 1.5px solid #e0e0e0; border-radius: 7px; font-size: 0.9rem; font-family: 'Inter', sans-serif; background: #fafafa;">
                                <option value="">------Select Registration Type------</option>
                                <option value="RMP Doctor">RMP Doctor</option>
                                <option value="BAMS">BAMS</option>
                                <option value="BHMS">BHMS</option>
                                <option value="BUMS">BUMS</option>
                                <option value="BNYS">BNYS</option>
                                <option value="BPT (Physiotherapist)">BPT (Physiotherapist)</option>
                                <option value="DMLT Professional">DMLT Professional</option>
                                <option value="Lab Technician">Lab Technician</option>
                                <option value="Pharmacist">Pharmacist</option>
                                <option value="Staff Nurse">Staff Nurse</option>
                                <option value="ANM">ANM</option>
                                <option value="GNM">GNM</option>
                                <option value="Community Health Officer">Community Health Officer</option>
                                <option value="Clinic Owner">Clinic Owner</option>
                                <option value="Other healthcare Professional">Other healthcare Professional</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>State *</label>
                            <select name="state" required style="width: 100%; padding: 11px 14px; border: 1.5px solid #e0e0e0; border-radius: 7px; font-size: 0.9rem; font-family: 'Inter', sans-serif; background: #fafafa;">
                                <option value="">------Select State------</option>
                                <option value="Andaman and Nicobar Islands">Andaman and Nicobar Islands</option>
                                <option value="Andhra Pradesh">Andhra Pradesh</option>
                                <option value="Arunachal Pradesh">Arunachal Pradesh</option>
                                <option value="Assam">Assam</option>
                                <option value="Bihar">Bihar</option>
                                <option value="Chandigarh">Chandigarh</option>
                                <option value="Chhattisgarh">Chhattisgarh</option>
                                <option value="Dadra and Nagar Haveli">Dadra and Nagar Haveli</option>
                                <option value="Daman and Diu">Daman and Diu</option>
                                <option value="Delhi">Delhi</option>
                                <option value="Goa">Goa</option>
                                <option value="Gujarat">Gujarat</option>
                                <option value="Haryana">Haryana</option>
                                <option value="Himachal Pradesh">Himachal Pradesh</option>
                                <option value="Jammu and Kashmir">Jammu and Kashmir</option>
                                <option value="Jharkhand">Jharkhand</option>
                                <option value="Karnataka">Karnataka</option>
                                <option value="Kerala">Kerala</option>
                                <option value="Ladakh">Ladakh</option>
                                <option value="Lakshadweep">Lakshadweep</option>
                                <option value="Madhya Pradesh">Madhya Pradesh</option>
                                <option value="Maharashtra">Maharashtra</option>
                                <option value="Manipur">Manipur</option>
                                <option value="Meghalaya">Meghalaya</option>
                                <option value="Mizoram">Mizoram</option>
                                <option value="Nagaland">Nagaland</option>
                                <option value="Odisha">Odisha</option>
                                <option value="Puducherry">Puducherry</option>
                                <option value="Punjab" selected>Punjab</option>
                                <option value="Rajasthan">Rajasthan</option>
                                <option value="Sikkim">Sikkim</option>
                                <option value="Tamil Nadu">Tamil Nadu</option>
                                <option value="Telangana">Telangana</option>
                                <option value="Tripura">Tripura</option>
                                <option value="Uttar Pradesh">Uttar Pradesh</option>
                                <option value="Uttarakhand">Uttarakhand</option>
                                <option value="West Bengal">West Bengal</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Validity Date (e.g. 5 Years or Lifetime) *</label>
                            <input type="text" name="validity" required value="<?= date('d-M-Y', strtotime('+5 years')) ?>">
                        </div>
                        <div class="form-group" style="grid-column: span 2;">
                            <label>Residential Address *</label>
                            <textarea name="address" rows="3" required placeholder="Enter Residential Address" style="width: 100%; padding: 11px 14px; border: 1.5px solid #e0e0e0; border-radius: 7px; font-size: 0.9rem; font-family: 'Inter', sans-serif; background: #fafafa;"></textarea>
                        </div>
                        <div class="form-group">
                            <label>Show on Website</label>
                            <div style="display: flex; align-items: center; gap: 8px; margin-top: 5px;">
                                <input type="checkbox" name="show_on_website" id="add_show_on_website" value="1" checked style="width: auto;">
                                <label for="add_show_on_website" style="margin: 0; font-weight: normal; cursor: pointer;">Show on homepage leaders grid</label>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Upload Photo (Optional)</label>
                            <input type="file" name="photo" accept="image/*">
                        </div>
                    </div>
                    <div style="margin-top: 20px; display: flex; gap: 10px;">
                        <button type="submit" name="add_member" class="btn btn-green"><i class="fas fa-plus"></i> Add Member</button>
                        <button type="button" class="btn btn-gray" onclick="toggleAddMemberForm()">Cancel</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-title"><i class="fas fa-list"></i> Approved Members Base (<?= count($members) ?>)</div>
                <div style="overflow-x:auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Photo</th>
                                <th>Reg. ID</th>
                                <th>Name</th>
                                <th>Designation</th>
                                <th>State</th>
                                <th>Validity</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($members)): ?>
                                <tr><td colspan="7" style="text-align:center; padding:30px; color:#888;">No members approved yet.</td></tr>
                            <?php else: ?>
                                <?php foreach (array_reverse($members) as $m): ?>
                                <tr>
                                    <td><img src="<?= htmlspecialchars($m['photo']) ?>" style="width:42px; height:42px; border-radius:5px; object-fit:cover; border:2px solid #D32F2F;" alt=""></td>
                                    <td><strong style="font-size:0.82rem; color:#0A192F;"><?= htmlspecialchars($m['id']) ?></strong></td>
                                    <td><?= htmlspecialchars($m['name']) ?></td>
                                    <td><?= htmlspecialchars($m['designation']) ?></td>
                                    <td><?= htmlspecialchars($m['state'] ?? 'N/A') ?></td>
                                    <td><?= htmlspecialchars($m['validity'] ?? 'N/A') ?></td>
                                    <td class="actions">
                                        <a href="?tab=members&edit=<?= urlencode($m['id']) ?>" class="btn btn-yellow btn-sm"><i class="fas fa-edit"></i> Edit</a>
                                        <a href="?tab=members&delete_member=<?= urlencode($m['id']) ?>" class="btn btn-red btn-sm" onclick="return confirm('Delete this member completely from database?')" title="Delete"><i class="fas fa-trash"></i> Delete</a>
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
                        </div>
                        <button type="submit" name="upload_gallery" class="btn btn-green"><i class="fas fa-upload"></i> Upload</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-title"><i class="fas fa-th"></i> Gallery Images (<?= count($gallery) ?>)</div>
                <?php if (empty($gallery)): ?>
                    <p style="color:#888; text-align:center; padding:30px;">No gallery images uploaded yet.</p>
                <?php else: ?>
                    <div class="gallery-grid">
                        <?php foreach ($gallery as $idx => $img): ?>
                        <div class="gallery-item">
                            <img src="<?= htmlspecialchars($img) ?>" alt="">
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
                            <input type="text" name="cert_caption" placeholder="e.g. NITI Aayog Govt. of India">
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label>Select Image File *</label>
                            <input type="file" name="cert_image" accept="image/*" required>
                        </div>
                    </div>
                    <div style="margin-top: 15px;">
                        <button type="submit" name="upload_certificate" class="btn btn-green"><i class="fas fa-upload"></i> Upload Certificate</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-title"><i class="fas fa-certificate"></i> Certificates (<?= count($certificates) ?>)</div>
                <?php if (empty($certificates)): ?>
                    <p style="color:#888; text-align:center; padding:30px;">No certificates uploaded yet.</p>
                <?php else: ?>
                    <div class="gallery-grid">
                        <?php foreach ($certificates as $idx => $cert): ?>
                        <div class="gallery-item">
                            <img src="<?= htmlspecialchars($cert['image']) ?>" alt="">
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
                <div class="card-title"><i class="fas fa-inbox"></i> Inbox Messages</div>
                <?php if (empty($msgs)): ?>
                    <p style="color:#888; text-align:center; padding:40px;"><i class="fas fa-envelope-open" style="font-size:3rem; display:block; margin-bottom:15px; color:#ddd;"></i>No messages received yet.</p>
                <?php else: ?>
                    <?php foreach (array_reverse($msgs) as $msg): ?>
                    <div class="msg-card <?= ($msg['status'] ?? 'unread') === 'unread' ? 'unread' : 'read' ?>">
                        <div class="msg-meta">
                            <div class="msg-sender">
                                <strong><?= htmlspecialchars($msg['name']) ?></strong>
                                <span><?= htmlspecialchars($msg['email']) ?> | <?= htmlspecialchars($msg['phone'] ?? '') ?></span>
                                <span><?= htmlspecialchars($msg['date']) ?></span>
                            </div>
                            <div class="actions">
                                <?php if (($msg['status'] ?? 'unread') === 'unread'): ?>
                                    <a href="?tab=messages&mark_read=<?= urlencode($msg['id']) ?>" class="btn btn-green btn-sm"><i class="fas fa-check"></i> Mark Read</a>
                                <?php endif; ?>
                                <a href="?tab=messages&delete_message=<?= urlencode($msg['id']) ?>" class="btn btn-red btn-sm" onclick="return confirm('Delete message?')" title="Delete"><i class="fas fa-trash"></i></a>
                            </div>
                        </div>
                        <div class="msg-subject">Subject: <?= htmlspecialchars($msg['subject'] ?? 'General Inquiry') ?></div>
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
                <div class="card-title"><i class="fas fa-address-card"></i> Contact Information</div>
                <form method="POST" action="admin.php?tab=settings">
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Primary Email</label>
                            <input type="email" name="email" value="<?= htmlspecialchars($settings['email'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Phone Number 1</label>
                            <input type="text" name="phone1" value="<?= htmlspecialchars($settings['phone1'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Phone Number 2</label>
                            <input type="text" name="phone2" value="<?= htmlspecialchars($settings['phone2'] ?? '') ?>">
                        </div>
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label>Address</label>
                            <textarea name="address" rows="3"><?= htmlspecialchars($settings['address'] ?? '') ?></textarea>
                        </div>
                    </div>
                    
                    <h3 style="margin: 25px 0 15px; font-size: 1rem; color: #0A192F; border-bottom: 1px solid #eee; padding-bottom: 8px;">Bank Account Details</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Account Name</label>
                            <input type="text" name="bank_account_name" value="<?= htmlspecialchars($settings['bank_account_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Bank Name</label>
                            <input type="text" name="bank_name" value="<?= htmlspecialchars($settings['bank_name'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Branch Name</label>
                            <input type="text" name="bank_branch" value="<?= htmlspecialchars($settings['bank_branch'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>Account Number</label>
                            <input type="text" name="bank_account_number" value="<?= htmlspecialchars($settings['bank_account_number'] ?? '') ?>">
                        </div>
                        <div class="form-group">
                            <label>IFSC Code</label>
                            <input type="text" name="bank_ifsc" value="<?= htmlspecialchars($settings['bank_ifsc'] ?? '') ?>">
                        </div>
                    </div>
                    <div style="margin-top: 20px;">
                        <button type="submit" name="save_settings" class="btn btn-blue"><i class="fas fa-save"></i> Save Details</button>
                    </div>
                </form>
            </div>

            <div class="card">
                <div class="card-title"><i class="fas fa-qrcode"></i> Donation QR Code</div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; align-items: start;">
                    <div>
                        <p style="margin-bottom: 15px; color: #555; font-size: 0.9rem;">Upload a new image to replace the Donate QR Code instantly.</p>
                        <form method="POST" action="admin.php?tab=settings" enctype="multipart/form-data">
                            <div class="form-group" style="margin-bottom: 15px;">
                                <label>Upload New QR Code Image</label>
                                <input type="file" name="qr_image" accept="image/*" required>
                            </div>
                            <button type="submit" name="upload_qr" class="btn btn-blue"><i class="fas fa-save"></i> Update QR Code</button>
                        </form>
                    </div>
                    <div>
                        <p style="font-weight: 600; margin-bottom: 10px; color: #555; font-size: 0.85rem; text-transform: uppercase;">Current QR Code Preview</p>
                        <div class="qr-preview" style="text-align:center; padding:15px; background:#f8f9fa; border: 1px dashed #ccc; border-radius:6px;">
                            <img src="<?= htmlspecialchars($settings['qr_code_path']) ?>" alt="QR Code">
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>
<?php endif; ?>
</body>
</html>
