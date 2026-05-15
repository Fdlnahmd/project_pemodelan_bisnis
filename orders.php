<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('auth.php');
}

$currentUser = getCurrentUser();

// Handle order status updates (for admin)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'update_status' && isAdmin($currentUser)) {
        $order_id = intval($_POST['order_id']);
        $new_status = $_POST['status'];

        $allowed_statuses = ['pending', 'confirmed', 'preparing', 'shipped', 'delivered', 'cancelled'];
        if (in_array($new_status, $allowed_statuses)) {
            $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ?");
            $stmt->execute([$new_status, $order_id]);
            showAlert('Status pesanan berhasil diperbarui!', 'success');
        }
        redirect('orders.php');
    }

    if ($_POST['action'] === 'cancel_order') {
        $order_id = intval($_POST['order_id']);

        // Check if order belongs to current user and can be cancelled
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ? AND status IN ('pending', 'confirmed')");
        $stmt->execute([$order_id, $currentUser['id']]);
        $order = $stmt->fetch();

        if ($order) {
            // Update order status
            $stmt = $pdo->prepare("UPDATE orders SET status = 'cancelled' WHERE id = ?");
            $stmt->execute([$order_id]);

            // Restore product stock
            $stmt = $pdo->prepare("SELECT oi.product_id, oi.quantity FROM order_items oi WHERE oi.order_id = ?");
            $stmt->execute([$order_id]);
            $orderItems = $stmt->fetchAll();

            foreach ($orderItems as $item) {
                $stmt = $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?");
                $stmt->execute([$item['quantity'], $item['product_id']]);
            }

            showAlert('Pesanan berhasil dibatalkan!', 'success');
        } else {
            showAlert('Pesanan tidak dapat dibatalkan!', 'error');
        }
        redirect('orders.php');
    }
}

// Get orders
$whereClause = '';
$params = [];

if (!isAdmin($currentUser)) {
    $whereClause = 'WHERE o.user_id = ?';
    $params[] = $currentUser['id'];
}

$stmt = $pdo->prepare("SELECT o.*, u.name as customer_name, u.email as customer_email 
                       FROM orders o 
                       JOIN users u ON o.user_id = u.id 
                       $whereClause 
                       ORDER BY o.created_at DESC");
$stmt->execute($params);
$orders = $stmt->fetchAll();

// Get order items for each order
$orderDetails = [];
$orderReviews = [];
foreach ($orders as $order) {
    $stmt = $pdo->prepare("SELECT oi.*, p.name as product_name, p.image as product_image 
                           FROM order_items oi 
                           JOIN products p ON oi.product_id = p.id 
                           WHERE oi.order_id = ?");
    $stmt->execute([$order['id']]);
    $orderDetails[$order['id']] = $stmt->fetchAll();

    // Check if order has been reviewed
    if (!isAdmin($currentUser)) {
        $stmt = $pdo->prepare("SELECT COUNT(*) as review_count FROM reviews WHERE order_id = ? AND user_id = ?");
        $stmt->execute([$order['id'], $currentUser['id']]);
        $reviewResult = $stmt->fetch();
        $orderReviews[$order['id']] = $reviewResult['review_count'] > 0;
    }
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isAdmin($currentUser) ? 'Kelola Pesanan' : 'Riwayat Pesanan' ?> - <?= SITE_NAME ?></title>
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
            padding: 20px;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
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
            color: white;
        }

        .card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
        }

        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 20px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            font-size: 14px;
            margin: 2px;
        }

        .btn-primary {
            background: linear-gradient(45deg, #ff6b6b, #ee5a52);
            color: white;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .btn-success {
            background: linear-gradient(45deg, #4caf50, #45a049);
            color: white;
        }

        .btn-warning {
            background: linear-gradient(45deg, #ff9800, #f57c00);
            color: white;
        }

        .btn-danger {
            background: linear-gradient(45deg, #f44336, #da190b);
            color: white;
        }

        .btn-review {
            background: linear-gradient(45deg, #9c27b0, #7b1fa2);
            color: white;
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }

        .btn-small {
            padding: 5px 10px;
            font-size: 12px;
        }

        .order-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 15px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .order-number {
            font-size: 18px;
            font-weight: bold;
            color: #ffd700;
        }

        .order-date {
            font-size: 14px;
            opacity: 0.8;
        }

        .order-status {
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-pending {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
            border: 1px solid #ffc107;
        }

        .status-confirmed {
            background: rgba(33, 150, 243, 0.2);
            color: #2196f3;
            border: 1px solid #2196f3;
        }

        .status-preparing {
            background: rgba(255, 152, 0, 0.2);
            color: #ff9800;
            border: 1px solid #ff9800;
        }

        .status-shipped {
            background: rgba(156, 39, 176, 0.2);
            color: #9c27b0;
            border: 1px solid #9c27b0;
        }

        .status-delivered {
            background: rgba(76, 175, 80, 0.2);
            color: #4caf50;
            border: 1px solid #4caf50;
        }

        .status-cancelled {
            background: rgba(244, 67, 54, 0.2);
            color: #f44336;
            border: 1px solid #f44336;
        }

        .order-items {
            margin-bottom: 15px;
        }

        .order-item {
            display: flex;
            align-items: center;
            padding: 10px;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 5px;
            margin-bottom: 8px;
            gap: 15px;
        }

        .item-image {
            width: 50px;
            height: 50px;
            border-radius: 5px;
            background: linear-gradient(45deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            color: white;
            flex-shrink: 0;
        }

        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 5px;
        }

        .item-info {
            flex: 1;
        }

        .item-name {
            font-weight: 500;
            margin-bottom: 5px;
        }

        .item-details {
            font-size: 14px;
            opacity: 0.8;
        }

        .order-summary {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }

        .summary-item {
            background: rgba(255, 255, 255, 0.05);
            padding: 15px;
            border-radius: 8px;
            text-align: center;
        }

        .summary-label {
            font-size: 14px;
            opacity: 0.8;
            margin-bottom: 5px;
        }

        .summary-value {
            font-size: 16px;
            font-weight: bold;
        }

        .order-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 15px;
        }

        .admin-controls {
            background: rgba(255, 255, 255, 0.1);
            padding: 15px;
            border-radius: 8px;
            margin-top: 15px;
        }

        .status-select {
            padding: 8px 12px;
            border-radius: 5px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.1);
            color: white;
            margin-right: 10px;
        }

        .status-select option {
            background: #333;
            color: white;
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

        .empty-orders {
            text-align: center;
            padding: 50px;
            color: white;
        }

        .empty-orders-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        .customer-info {
            background: rgba(255, 255, 255, 0.05);
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 14px;
        }

        .order-notes {
            background: rgba(255, 255, 255, 0.05);
            padding: 10px;
            border-radius: 5px;
            margin-bottom: 15px;
            font-size: 14px;
            font-style: italic;
        }

        .review-indicator {
            background: rgba(156, 39, 176, 0.2);
            color: #9c27b0;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
            margin-left: 10px;
            border: 1px solid #9c27b0;
        }

        .review-modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(5px);
        }

        .review-modal-content {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            margin: 5% auto;
            padding: 30px;
            border-radius: 15px;
            width: 90%;
            max-width: 600px;
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .close {
            color: white;
            float: right;
            font-size: 28px;
            font-weight: bold;
            cursor: pointer;
            line-height: 1;
        }

        .close:hover {
            opacity: 0.7;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 8px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 14px;
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #ff6b6b;
            box-shadow: 0 0 10px rgba(255, 107, 107, 0.3);
        }

        .rating-input {
            display: flex;
            gap: 10px;
            margin-bottom: 10px;
        }

        .rating-input input[type="radio"] {
            display: none;
        }

        .rating-input label {
            font-size: 24px;
            color: rgba(255, 255, 255, 0.3);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .rating-input input[type="radio"]:checked~label,
        .rating-input label:hover {
            color: #ffd700;
        }

        .rating-input label:hover~label {
            color: rgba(255, 255, 255, 0.3);
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 15px;
            }

            .order-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .order-summary {
                grid-template-columns: 1fr;
            }

            .order-actions {
                flex-direction: column;
            }

            .admin-controls {
                text-align: center;
            }

            .review-modal-content {
                width: 95%;
                margin: 10% auto;
                padding: 20px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <?php displayAlert(); ?>

        <div class="header">
            <h1><?= isAdmin($currentUser) ? 'Kelola Pesanan' : 'Riwayat Pesanan' ?></h1>
            <div>
                <a href="index.php" class="btn btn-secondary">Beranda</a>
                <a href="reviews.php" class="btn btn-secondary">Reviews</a>
                <a href="cart.php" class="btn btn-secondary">Keranjang</a>
                <?php if (isAdmin($currentUser)): ?>
                    <a href="admin/admin.php" class="btn btn-primary">Panel Admin</a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (empty($orders)): ?>
            <div class="card">
                <div class="empty-orders">
                    <div class="empty-orders-icon">📋</div>
                    <h2><?= isAdmin($currentUser) ? 'Belum Ada Pesanan' : 'Belum Ada Pesanan' ?></h2>
                    <p><?= isAdmin($currentUser) ? 'Belum ada pesanan yang masuk' : 'Anda belum memiliki riwayat pesanan' ?></p>
                    <?php if (!isAdmin($currentUser)): ?>
                        <a href="index.php" class="btn btn-primary" style="margin-top: 20px;">Mulai Belanja</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <h2>Total Pesanan: <?= count($orders) ?></h2>

                <?php foreach ($orders as $order): ?>
                    <div class="order-card">
                        <div class="order-header">
                            <div>
                                <div class="order-number"><?= htmlspecialchars($order['order_number']) ?></div>
                                <div class="order-date"><?= date('d F Y, H:i', strtotime($order['created_at'])) ?></div>
                            </div>
                            <div style="display: flex; align-items: center;">
                                <div class="order-status status-<?= $order['status'] ?>">
                                    <?= $statusLabels[$order['status']] ?? $order['status'] ?>
                                </div>
                                <?php if (!isAdmin($currentUser) && $order['status'] === 'delivered' && isset($orderReviews[$order['id']]) && $orderReviews[$order['id']]): ?>
                                    <span class="review-indicator">✓ Sudah Direview</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <?php if (isAdmin($currentUser)): ?>
                            <div class="customer-info">
                                <strong>Customer:</strong> <?= htmlspecialchars($order['customer_name']) ?>
                                (<?= htmlspecialchars($order['customer_email']) ?>)
                            </div>
                        <?php endif; ?>

                        <div class="order-items">
                            <h4>Produk yang Dipesan:</h4>
                            <?php foreach ($orderDetails[$order['id']] as $item): ?>
                                <div class="order-item">
                                    <div class="item-image">
                                        <?php if ($item['product_image'] && file_exists($item['product_image'])): ?>
                                            <img src="<?= htmlspecialchars($item['product_image']) ?>"
                                                alt="<?= htmlspecialchars($item['product_name']) ?>">
                                        <?php else: ?>
                                            📦
                                        <?php endif; ?>
                                    </div>
                                    <div class="item-info">
                                        <div class="item-name"><?= htmlspecialchars($item['product_name']) ?></div>
                                        <div class="item-details">
                                            <?= $item['quantity'] ?> x <?= formatPrice($item['price']) ?> =
                                            <strong><?= formatPrice($item['price'] * $item['quantity']) ?></strong>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="order-summary">
                            <div class="summary-item">
                                <div class="summary-label">Metode Pengiriman</div>
                                <div class="summary-value">
                                    <?php
                                    $deliveryMethods = [
                                        'pickup' => 'Ambil di Toko',
                                        'gojek' => 'GoJek',
                                        'grab' => 'Grab'
                                    ];
                                    echo $deliveryMethods[$order['delivery_method']] ?? $order['delivery_method'];
                                    ?>
                                </div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Metode Pembayaran</div>
                                <div class="summary-value">
                                    <?php
                                    $paymentMethods = [
                                        'cod' => 'Cash on Delivery',
                                        'transfer' => 'Transfer Bank',
                                        'ewallet' => 'E-Wallet'
                                    ];
                                    echo $paymentMethods[$order['payment_method']] ?? $order['payment_method'];
                                    ?>
                                </div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Ongkir</div>
                                <div class="summary-value"><?= formatPrice($order['delivery_fee']) ?></div>
                            </div>
                            <div class="summary-item">
                                <div class="summary-label">Total</div>
                                <div class="summary-value" style="color: #ffd700;"><?= formatPrice($order['total_amount']) ?></div>
                            </div>
                        </div>

                        <?php if (!empty($order['notes'])): ?>
                            <div class="order-notes">
                                <strong>Catatan:</strong> <?= htmlspecialchars($order['notes']) ?>
                            </div>
                        <?php endif; ?>

                        <div class="order-actions">
                            <?php if (!isAdmin($currentUser)): ?>
                                <?php if (in_array($order['status'], ['pending', 'confirmed'])): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="cancel_order">
                                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                        <button type="submit" class="btn btn-danger"
                                            onclick="return confirm('Yakin ingin membatalkan pesanan ini?')">
                                            Batalkan Pesanan
                                        </button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($order['status'] === 'delivered'): ?>
                                    <?php if (!$orderReviews[$order['id']]): ?>
                                       <a href="reviews.php">

                                           <button class="btn btn-review" onclick="openReviewModal(<?= $order['id'] ?>, '<?= htmlspecialchars($order['order_number']) ?>')">
                                               ⭐ Beri Review
                                            </button>
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-success" disabled>✓ Sudah Direview</button>
                                        <a href="reviews.php?order_id=<?= $order['id'] ?>" class="btn btn-secondary">Lihat Review</a>
                                    <?php endif; ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="admin-controls">
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                        <select name="status" class="status-select">
                                            <?php foreach ($statusLabels as $status => $label): ?>
                                                <option value="<?= $status ?>"
                                                    <?= $order['status'] === $status ? 'selected' : '' ?>>
                                                    <?= $label ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button type="submit" class="btn btn-primary btn-small">
                                            Update Status
                                        </button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Review Modal -->
    <div id="reviewModal" class="review-modal">
        <div class="review-modal-content">
            <span class="close" onclick="closeReviewModal()">&times;</span>
            <h2>Beri Review Pesanan</h2>
            <p>Pesanan: <span id="reviewOrderNumber"></span></p>
            <form id="reviewForm" method="POST" action="reviews.php">
                <input type="hidden" name="action" value="add_review">
                <input type="hidden" name="order_id" id="reviewOrderId">

                <div class="form-group">
                    <label for="rating">Rating:</label>
                    <div class="rating-input">
                        <input type="radio" id="star5" name="rating" value="5" required>
                        <label for="star5">⭐</label>
                        <input type="radio" id="star4" name="rating" value="4">
                        <label for="star4">⭐</label>
                        <input type="radio" id="star3" name="rating" value="3">
                        <label for="star3">⭐</label>
                        <input type="radio" id="star2" name="rating" value="2">
                        <label for="star2">⭐</label>
                        <input type="radio" id="star1" name="rating" value="1">
                        <label for="star1">⭐</label>
                    </div>
                </div>

                <div class="form-group">
                    <label for="review_text">Review:</label>
                    <textarea name="review_text" id="review_text"
                        placeholder="Tulis review Anda tentang produk dan layanan..."
                        required></textarea>
                </div>

                <div class="form-group">
                    <button type="submit" class="btn btn-primary">Kirim Review</button>
                    <button type="button" class="btn btn-secondary" onclick="closeReviewModal()">Batal</button>
                </div>
            </form>
        </div>
    </div>