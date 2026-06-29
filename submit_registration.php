<?php
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data || empty($data['name']) || empty($data['mobileNo'])) {
    echo json_encode(['success' => false, 'message' => 'Required fields are missing.']);
    exit;
}

$requests_file = 'requests.json';
$requests = [];
if (file_exists($requests_file)) {
    $requests = json_decode(file_get_contents($requests_file), true) ?: [];
}

// Generate unique Request Reference ID: MPWA-REQ-XXXX
$req_number = count($requests) + 1;
$request_id = 'MPWA-REQ-' . str_pad($req_number, 4, '0', STR_PAD_LEFT);

// If photo is base64, we can save it as an image file on the server to keep requests.json lightweight
$photo_path = "";
if (!empty($data['photo']) && strpos($data['photo'], 'data:image') === 0) {
    $photos_dir = 'assets/photos/';
    if (!is_dir($photos_dir)) {
        mkdir($photos_dir, 0755, true);
    }
    
    // Extract base64 content
    $parts = explode(',', $data['photo']);
    $image_type_parts = explode(';', $parts[0]);
    $image_type = str_replace('data:image/', '', $image_type_parts[0]);
    $image_base64 = base64_decode($parts[1]);
    
    $file_name = 'pending_' . time() . '_' . rand(1000, 9999) . '.' . $image_type;
    $full_path = $photos_dir . $file_name;
    
    if (file_put_contents($full_path, $image_base64)) {
        $photo_path = $full_path;
    }
}

// Fallback to avatar if no photo was saved
if (empty($photo_path)) {
    $photo_path = "https://ui-avatars.com/api/?name=" . urlencode($data['name']) . "&background=112240&color=fff&size=150";
}

$new_request = [
    'request_id' => $request_id,
    'name' => trim($data['name']),
    'fatherName' => trim($data['fatherName'] ?? ''),
    'motherName' => trim($data['motherName'] ?? ''),
    'aadharNo' => trim($data['aadharNo'] ?? ''),
    'dob' => trim($data['dob'] ?? ''),
    'mobileNo' => trim($data['mobileNo'] ?? ''),
    'regType' => trim($data['regType'] ?? 'RMP Doctor'),
    'state' => trim($data['state'] ?? 'Punjab'),
    'address' => trim($data['address'] ?? ''),
    'awardingBody' => trim($data['awardingBody'] ?? ''),
    'photo' => $photo_path,
    'date' => date('d-M-Y H:i:s')
];

$requests[] = $new_request;
file_put_contents($requests_file, json_encode($requests, JSON_PRETTY_PRINT));

echo json_encode([
    'success' => true,
    'message' => 'Request submitted successfully.',
    'request_id' => $request_id
]);
