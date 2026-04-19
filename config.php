<?php
// config.php - Database Configuration
session_start();

define('DB_HOST', 'mysql');      // nama service di docker-compose
define('DB_USER', 'root');
define('DB_PASS', 'secret');     // sesuai MYSQL_ROOT_PASSWORD
define('DB_NAME', 'elektronik_shop');

// Site configuration
define('SITE_NAME', 'ElektroShop Jakarta');
define('SITE_URL', 'http://localhost/elektronik-shop');

// Admin configuration
define('ADMIN_EMAILS', ['admin@elektroshop.com', 'admin@gmail.com']);

// Create database connection
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Helper functions
function sanitize($data)
{
    return htmlspecialchars(strip_tags(trim($data)));
}

function isLoggedIn()
{
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function getCurrentUser()
{
    global $pdo;
    if (!isLoggedIn()) return null;

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

function isAdmin($user = null)
{
    if (!$user) {
        $user = getCurrentUser();
    }

    if (!$user) return false;

    return in_array($user['email'], ADMIN_EMAILS);
}

function requireAdmin()
{
    if (!isLoggedIn()) {
        showAlert('Silakan login terlebih dahulu.', 'error');
        redirect('auth.php');
    }

    $currentUser = getCurrentUser();
    if (!isAdmin($currentUser)) {
        showAlert('Akses ditolak. Hanya admin yang dapat mengakses halaman ini.', 'error');
        redirect('index.php');
    }
}

function formatPrice($price)
{
    return 'Rp ' . number_format($price, 0, ',', '.');
}

function generateOrderNumber()
{
    return 'ORD' . date('Ymd') . rand(1000, 9999);
}

function redirect($url)
{
    if (headers_sent()) {
        echo "<script>window.location.href = '$url';</script>";
    } else {
        header("Location: $url");
    }
    exit();
}

function showAlert($message, $type = 'success')
{
    $_SESSION['alert'] = ['message' => $message, 'type' => $type];
}

function displayAlert()
{
    if (isset($_SESSION['alert'])) {
        $alert = $_SESSION['alert'];
        echo "<div class='alert alert-{$alert['type']}'>{$alert['message']}</div>";
        unset($_SESSION['alert']);
    }
}

function uploadProductImage($file)
{
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return false;
    }

    $allowed_types = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $max_size = 5 * 1024 * 1024; // 5MB

    $file_info = pathinfo($file['name']);
    $file_ext = strtolower($file_info['extension']);

    // Check file extension
    if (!in_array($file_ext, $allowed_types)) {
        return false;
    }

    // Check file size
    if ($file['size'] > $max_size) {
        return false;
    }

    // Create upload directory if it doesn't exist
    $upload_dir = 'uploads/products/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Generate unique filename
    $file_name = uniqid() . '_' . time() . '.' . $file_ext;
    $upload_path = $upload_dir . $file_name;

    // Move uploaded file
    if (move_uploaded_file($file['tmp_name'], $upload_path)) {
        return $upload_path;
    }

    return false;
}

function deleteProductImage($image_path)
{
    if (!empty($image_path) && file_exists($image_path)) {
        unlink($image_path);
    }
}

function resizeImage($source, $destination, $width, $height)
{
    $info = getimagesize($source);
    $mime_type = $info['mime'];

    switch ($mime_type) {
        case 'image/jpeg':
            $image = imagecreatefromjpeg($source);
            break;
        case 'image/png':
            $image = imagecreatefrompng($source);
            break;
        case 'image/gif':
            $image = imagecreatefromgif($source);
            break;
        default:
            return false;
    }

    $original_width = imagesx($image);
    $original_height = imagesy($image);

    // Calculate new dimensions while maintaining aspect ratio
    $ratio = min($width / $original_width, $height / $original_height);
    $new_width = (int)($original_width * $ratio);
    $new_height = (int)($original_height * $ratio);

    // Create new image
    $new_image = imagecreatetruecolor($new_width, $new_height);

    // Handle transparency for PNG and GIF
    if ($mime_type == 'image/png' || $mime_type == 'image/gif') {
        imagealphablending($new_image, false);
        imagesavealpha($new_image, true);
        $transparent = imagecolorallocatealpha($new_image, 255, 255, 255, 127);
        imagefilledrectangle($new_image, 0, 0, $new_width, $new_height, $transparent);
    }

    // Resize image
    imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $original_width, $original_height);

    // Save resized image
    switch ($mime_type) {
        case 'image/jpeg':
            imagejpeg($new_image, $destination, 90);
            break;
        case 'image/png':
            imagepng($new_image, $destination);
            break;
        case 'image/gif':
            imagegif($new_image, $destination);
            break;
    }

    // Free memory
    imagedestroy($image);
    imagedestroy($new_image);

    return true;
}

// Delivery fees
$deliveryFees = [
    'pickup' => 0,
    'gojek' => 15000,
    'grab' => 18000
];

// Order status labels
$statusLabels = [
    'pending' => 'Menunggu Konfirmasi',
    'confirmed' => 'Dikonfirmasi',
    'preparing' => 'Sedang Disiapkan',
    'shipped' => 'Dalam Perjalanan',
    'delivered' => 'Diterima',
    'cancelled' => 'Dibatalkan'
];

// Auto-create admin user if not exists
try {
    $admin_email = ADMIN_EMAILS[0];
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$admin_email]);

    if (!$stmt->fetch()) {
        $admin_password = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (name, email, password, phone, address) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute(['Admin', $admin_email, $admin_password, '081234567890', 'Jakarta Pusat']);
    }
} catch (PDOException $e) {
    // Database might not be ready yet, ignore this error
}
