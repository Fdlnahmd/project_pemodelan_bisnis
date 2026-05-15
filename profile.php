<?php
require_once 'includes/config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    header('Location: auth.php');
    exit();
}

$currentUser = getCurrentUser();
$errors = [];
$success = false;

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    // Validate input
    if (empty($name)) {
        $errors[] = 'Nama harus diisi';
    }

    if (empty($email)) {
        $errors[] = 'Email harus diisi';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Format email tidak valid';
    }

    // Check if email is already used by another user
    if (!empty($email) && $email !== $currentUser['email']) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $currentUser['id']]);
        if ($stmt->fetch()) {
            $errors[] = 'Email sudah digunakan oleh pengguna lain';
        }
    }

    // Validate phone number
    if (!empty($phone) && !preg_match('/^[0-9+\-\s()]+$/', $phone)) {
        $errors[] = 'Nomor telepon hanya boleh mengandung angka, +, -, spasi, dan tanda kurung';
    }

    // Validate password change
    if (!empty($new_password) || !empty($confirm_password) || !empty($current_password)) {
        if (empty($current_password)) {
            $errors[] = 'Password saat ini harus diisi untuk mengubah password';
        } elseif (!password_verify($current_password, $currentUser['password'])) {
            $errors[] = 'Password saat ini salah';
        } elseif (empty($new_password)) {
            $errors[] = 'Password baru harus diisi';
        } elseif (strlen($new_password) < 6) {
            $errors[] = 'Password baru minimal 6 karakter';
        } elseif ($new_password !== $confirm_password) {
            $errors[] = 'Konfirmasi password tidak cocok';
        }
    }

    // Update profile if no errors
    if (empty($errors)) {
        try {
            if (!empty($new_password)) {
                // Update with new password
                $hashedPassword = password_hash($new_password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, address = ?, password = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$name, $email, $phone, $address, $hashedPassword, $currentUser['id']]);
            } else {
                // Update without password change
                $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, address = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                $stmt->execute([$name, $email, $phone, $address, $currentUser['id']]);
            }

            $success = true;
            $_SESSION['alert'] = [
                'message' => 'Profile berhasil diperbarui!',
                'type' => 'success'
            ];

            // Refresh user data
            $currentUser = getCurrentUser();
        } catch (Exception $e) {
            $errors[] = 'Terjadi kesalahan saat memperbarui profile';
        }
    }
}

// Get user statistics
$stmt = $pdo->prepare("SELECT 
    COUNT(*) as total_orders,
    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as completed_orders,
    SUM(CASE WHEN status IN ('pending', 'confirmed', 'preparing', 'shipped') THEN 1 ELSE 0 END) as active_orders,
    COALESCE(SUM(CASE WHEN status = 'delivered' THEN total_amount ELSE 0 END), 0) as total_spent
    FROM orders WHERE user_id = ?");
$stmt->execute([$currentUser['id']]);
$stats = $stmt->fetch();

// Get recent orders
$stmt = $pdo->prepare("SELECT o.*, 
    GROUP_CONCAT(CONCAT(p.name, ' (', oi.quantity, 'x)') SEPARATOR ', ') as items
    FROM orders o
    LEFT JOIN order_items oi ON o.id = oi.order_id
    LEFT JOIN products p ON oi.product_id = p.id
    WHERE o.user_id = ?
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT 5");
$stmt->execute([$currentUser['id']]);
$recentOrders = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Profile - <?= SITE_NAME ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
        }

        .header {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .logo {
            color: white;
            font-size: 24px;
            font-weight: bold;
            text-decoration: none;
        }

        .nav-links {
            display: flex;
            gap: 15px;
            align-items: center;
        }

        .nav-links a {
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 20px;
            transition: background 0.3s ease;
        }

        .nav-links a:hover {
            background: rgba(255, 255, 255, 0.2);
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 25px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            font-size: 14px;
        }

        .btn-primary {
            background: linear-gradient(45deg, #ff6b6b, #ee5a52);
            color: white;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 107, 107, 0.4);
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .main-content {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }

        .profile-card,
        .stats-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 30px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
        }

        .card-title {
            font-size: 24px;
            margin-bottom: 20px;
            text-align: center;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.9);
            font-size: 16px;
            transition: box-shadow 0.3s ease;
        }

        .form-control:focus {
            outline: none;
            box-shadow: 0 0 20px rgba(255, 255, 255, 0.5);
        }

        .form-control[readonly] {
            background: rgba(255, 255, 255, 0.6);
            cursor: not-allowed;
        }

        .password-section {
            border-top: 1px solid rgba(255, 255, 255, 0.2);
            padding-top: 20px;
            margin-top: 20px;
        }

        .password-section h3 {
            margin-bottom: 15px;
            color: #ffd700;
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        .stat-item {
            text-align: center;
            padding: 20px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .stat-number {
            font-size: 24px;
            font-weight: bold;
            color: #ffd700;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 14px;
            opacity: 0.8;
        }

        .recent-orders {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 30px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
        }

        .order-item {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 15px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .order-number {
            font-weight: bold;
            color: #ffd700;
        }

        .order-status {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-pending {
            background: #ff9800;
            color: white;
        }

        .status-confirmed {
            background: #2196f3;
            color: white;
        }

        .status-preparing {
            background: #9c27b0;
            color: white;
        }

        .status-shipped {
            background: #607d8b;
            color: white;
        }

        .status-delivered {
            background: #4caf50;
            color: white;
        }

        .status-cancelled {
            background: #f44336;
            color: white;
        }

        .order-items {
            font-size: 14px;
            opacity: 0.8;
            margin-bottom: 10px;
        }

        .order-total {
            font-weight: bold;
            text-align: right;
        }

        .alert {
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert-success {
            background: rgba(76, 175, 80, 0.9);
            color: white;
        }

        .alert-error {
            background: rgba(244, 67, 54, 0.9);
            color: white;
        }

        .user-info {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 20px;
        }

        .user-avatar {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(45deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            font-weight: bold;
        }

        .user-details h2 {
            margin-bottom: 5px;
        }

        .user-details p {
            opacity: 0.8;
            font-size: 14px;
        }

        @media (max-width: 768px) {
            .main-content {
                grid-template-columns: 1fr;
                gap: 20px;
            }

            .stats-grid {
                grid-template-columns: 1fr;
            }

            .header {
                flex-direction: column;
                gap: 15px;
            }

            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
            }

            .order-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <?php displayAlert(); ?>

        <header class="header">
            <a href="index.php" class="logo">⚡ <?= SITE_NAME ?></a>
            <div class="nav-links">
                <a href="index.php">🏠 Beranda</a>
                <a href="orders.php">📦 Pesanan</a>
                <a href="cart.php">🛒 Keranjang</a>
                <a href="auth.php?action=logout" class="btn btn-secondary">Logout</a>
            </div>
        </header>

        <div class="main-content">
            <div class="profile-card">
                <h2 class="card-title">👤 Profile Saya</h2>

                <div class="user-info">
                    <div class="user-avatar">
                        <?= strtoupper(substr($currentUser['name'], 0, 1)) ?>
                    </div>
                    <div class="user-details">
                        <h2><?= htmlspecialchars($currentUser['name']) ?></h2>
                        <p>Member sejak <?= date('d F Y', strtotime($currentUser['created_at'])) ?></p>
                    </div>
                </div>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error">
                        <ul style="margin: 0; padding-left: 20px;">
                            <?php foreach ($errors as $error): ?>
                                <li><?= htmlspecialchars($error) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>

                <form method="POST">
                    <div class="form-group">
                        <label for="name">Nama Lengkap</label>
                        <input type="text" id="name" name="name" class="form-control"
                            value="<?= htmlspecialchars($currentUser['name']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" class="form-control"
                            value="<?= htmlspecialchars($currentUser['email']) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="phone">Nomor Telepon</label>
                        <input type="text" id="phone" name="phone" class="form-control"
                            value="<?= htmlspecialchars($currentUser['phone'] ?? '') ?>"
                            placeholder="Contoh: 081234567890">
                    </div>

                    <div class="form-group">
                        <label for="address">Alamat</label>
                        <textarea id="address" name="address" class="form-control" rows="3"
                            placeholder="Alamat lengkap untuk pengiriman"><?= htmlspecialchars($currentUser['address'] ?? '') ?></textarea>
                    </div>

                    <div class="password-section">
                        <h3>🔒 Ubah Password</h3>
                        <p style="opacity: 0.8; margin-bottom: 15px; font-size: 14px;">
                            Kosongkan jika tidak ingin mengubah password
                        </p>

                        <div class="form-group">
                            <label for="current_password">Password Saat Ini</label>
                            <input type="password" id="current_password" name="current_password" class="form-control">
                        </div>

                        <div class="form-group">
                            <label for="new_password">Password Baru</label>
                            <input type="password" id="new_password" name="new_password" class="form-control">
                        </div>

                        <div class="form-group">
                            <label for="confirm_password">Konfirmasi Password Baru</label>
                            <input type="password" id="confirm_password" name="confirm_password" class="form-control">
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        💾 Simpan Perubahan
                    </button>
                </form>
            </div>

            <div class="stats-card">
                <h2 class="card-title">📊 Statistik Belanja</h2>

                <div class="stats-grid">
                    <div class="stat-item">
                        <div class="stat-number"><?= $stats['total_orders'] ?></div>
                        <div class="stat-label">Total Pesanan</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?= $stats['completed_orders'] ?></div>
                        <div class="stat-label">Pesanan Selesai</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?= $stats['active_orders'] ?></div>
                        <div class="stat-label">Pesanan Aktif</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-number"><?= formatPrice($stats['total_spent']) ?></div>
                        <div class="stat-label">Total Belanja</div>
                    </div>
                </div>

                <div style="text-align: center; margin-top: 20px;">
                    <a href="orders.php" class="btn btn-secondary">📦 Lihat Semua Pesanan</a>
                </div>
            </div>
        </div>

        <div class="recent-orders">
            <h2 class="card-title">📦 Pesanan Terbaru</h2>

            <?php if (empty($recentOrders)): ?>
                <div style="text-align: center; padding: 40px; opacity: 0.8;">
                    <div style="font-size: 48px; margin-bottom: 20px;">📦</div>
                    <div>Belum ada pesanan</div>
                    <a href="index.php" class="btn btn-primary" style="margin-top: 20px;">
                        🛒 Mulai Belanja
                    </a>
                </div>
            <?php else: ?>
                <?php foreach ($recentOrders as $order): ?>
                    <div class="order-item">
                        <div class="order-header">
                            <span class="order-number">#<?= $order['order_number'] ?></span>
                            <span class="order-status status-<?= $order['status'] ?>">
                                <?= ucfirst($order['status']) ?>
                            </span>
                        </div>
                        <div class="order-items">
                            <?= htmlspecialchars($order['items']) ?>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: center;">
                            <div style="font-size: 14px; opacity: 0.8;">
                                <?= date('d/m/Y H:i', strtotime($order['created_at'])) ?>
                            </div>
                            <div class="order-total">
                                <?= formatPrice($order['total_amount']) ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div style="text-align: center; margin-top: 20px;">
                    <a href="orders.php" class="btn btn-secondary">
                        Lihat Semua Pesanan
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>