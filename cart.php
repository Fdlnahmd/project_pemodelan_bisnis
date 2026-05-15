<?php
require_once 'includes/config.php';

if (!isLoggedIn()) {
    redirect('auth.php');
}

$currentUser = getCurrentUser();

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity'] ?? 1);

        // Check if product exists and has stock
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ? AND status = 'active'");
        $stmt->execute([$product_id]);
        $product = $stmt->fetch();

        if ($product && $product['stock'] >= $quantity) {
            // Check if item already in cart
            $stmt = $pdo->prepare("SELECT quantity FROM cart WHERE user_id = ? AND product_id = ?");
            $stmt->execute([$currentUser['id'], $product_id]);
            $existingItem = $stmt->fetch();

            if ($existingItem) {
                // Update quantity
                $newQuantity = $existingItem['quantity'] + $quantity;
                if ($newQuantity <= $product['stock']) {
                    $stmt = $pdo->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
                    $stmt->execute([$newQuantity, $currentUser['id'], $product_id]);
                    showAlert('Quantity updated in cart!', 'success');
                } else {
                    showAlert('Not enough stock available', 'error');
                }
            } else {
                // Add new item
                $stmt = $pdo->prepare("INSERT INTO cart (user_id, product_id, quantity) VALUES (?, ?, ?)");
                $stmt->execute([$currentUser['id'], $product_id, $quantity]);
                showAlert('Product added to cart!', 'success');
            }
        } else {
            showAlert('Product not available or insufficient stock', 'error');
        }

        // Redirect back to referring page or index
        $referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';
        redirect($referer);
    }

    if ($action === 'update') {
        $product_id = intval($_POST['product_id']);
        $quantity = intval($_POST['quantity']);

        if ($quantity <= 0) {
            $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
            $stmt->execute([$currentUser['id'], $product_id]);
        } else {
            $stmt = $pdo->prepare("UPDATE cart SET quantity = ? WHERE user_id = ? AND product_id = ?");
            $stmt->execute([$quantity, $currentUser['id'], $product_id]);
        }

        showAlert('Cart updated!', 'success');
        redirect('cart.php');
    }

    if ($action === 'remove') {
        $product_id = intval($_POST['product_id']);

        $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ? AND product_id = ?");
        $stmt->execute([$currentUser['id'], $product_id]);

        showAlert('Product removed from cart!', 'success');
        redirect('cart.php');
    }

    if ($action === 'checkout') {
        $delivery_method = $_POST['delivery_method'] ?? 'pickup';
        $payment_method = $_POST['payment_method'] ?? 'cod';
        $notes = sanitize($_POST['notes'] ?? '');

        // Get cart items
        $stmt = $pdo->prepare("SELECT c.*, p.name, p.price, p.stock FROM cart c 
                              JOIN products p ON c.product_id = p.id 
                              WHERE c.user_id = ?");
        $stmt->execute([$currentUser['id']]);
        $cartItems = $stmt->fetchAll();

        if (empty($cartItems)) {
            showAlert('Your cart is empty!', 'error');
            redirect('cart.php');
        }

        // Calculate total
        $subtotal = 0;
        foreach ($cartItems as $item) {
            $subtotal += $item['price'] * $item['quantity'];
        }

        $deliveryFee = $deliveryFees[$delivery_method] ?? 0;
        $total = $subtotal + $deliveryFee;

        // Create order
        $orderNumber = generateOrderNumber();

        $stmt = $pdo->prepare("INSERT INTO orders (user_id, order_number, total_amount, delivery_method, delivery_fee, payment_method, notes) 
                              VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$currentUser['id'], $orderNumber, $total, $delivery_method, $deliveryFee, $payment_method, $notes]);

        $orderId = $pdo->lastInsertId();

        // Add order items
        foreach ($cartItems as $item) {
            $stmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
            $stmt->execute([$orderId, $item['product_id'], $item['quantity'], $item['price']]);

            // Update product stock
            $stmt = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ?");
            $stmt->execute([$item['quantity'], $item['product_id']]);
        }

        // Clear cart
        $stmt = $pdo->prepare("DELETE FROM cart WHERE user_id = ?");
        $stmt->execute([$currentUser['id']]);

        showAlert('Order placed successfully! Order number: ' . $orderNumber, 'success');
        redirect('orders.php');
    }
}

// Get cart items
$stmt = $pdo->prepare("SELECT c.*, p.name, p.price, p.image, p.stock FROM cart c 
                      JOIN products p ON c.product_id = p.id 
                      WHERE c.user_id = ?");
$stmt->execute([$currentUser['id']]);
$cartItems = $stmt->fetchAll();

// Calculate totals
$subtotal = 0;
foreach ($cartItems as $item) {
    $subtotal += $item['price'] * $item['quantity'];
}
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Keranjang Belanja - <?= SITE_NAME ?></title>
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

        .btn-danger {
            background: linear-gradient(45deg, #f44336, #da190b);
            color: white;
        }

        .btn-secondary {
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: 1px solid rgba(255, 255, 255, 0.3);
        }

        .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.3);
        }

        .cart-item {
            display: flex;
            align-items: center;
            padding: 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
            gap: 20px;
        }

        .cart-item:last-child {
            border-bottom: none;
        }

        .product-image {
            width: 80px;
            height: 80px;
            background: linear-gradient(45deg, #4facfe, #00f2fe);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            flex-shrink: 0;
        }

        .product-info {
            flex: 1;
        }

        .product-name {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .product-price {
            font-size: 16px;
            color: #ffd700;
            margin-bottom: 10px;
        }

        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .quantity-btn {
            width: 30px;
            height: 30px;
            border: none;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .quantity-input {
            width: 60px;
            padding: 5px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 5px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .checkout-section {
            background: rgba(255, 255, 255, 0.15);
            padding: 20px;
            border-radius: 10px;
            margin-top: 20px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            border-radius: 5px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .form-group select option {
            background: #333;
            color: white;
        }

        .total-section {
            text-align: right;
            padding: 20px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 10px;
            margin-top: 20px;
        }

        .total-line {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 16px;
        }

        .total-final {
            font-size: 20px;
            font-weight: bold;
            color: #ffd700;
            border-top: 1px solid rgba(255, 255, 255, 0.3);
            padding-top: 10px;
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

        .empty-cart {
            text-align: center;
            padding: 50px;
            color: white;
        }

        .empty-cart-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .cart-item {
                flex-direction: column;
                align-items: flex-start;
            }

            .header {
                flex-direction: column;
                gap: 15px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <?php displayAlert(); ?>

        <div class="header">
            <h1>Keranjang Belanja</h1>
            <div>
                <a href="index.php" class="btn btn-secondary">Lanjut Belanja</a>
                <a href="orders.php" class="btn btn-secondary">Pesanan Saya</a>
            </div>
        </div>

        <?php if (empty($cartItems)): ?>
            <div class="card">
                <div class="empty-cart">
                    <div class="empty-cart-icon">🛒</div>
                    <h2>Keranjang Anda Kosong</h2>
                    <p>Belum ada produk di keranjang belanja Anda</p>
                    <a href="index.php" class="btn btn-primary" style="margin-top: 20px;">Mulai Belanja</a>
                </div>
            </div>
        <?php else: ?>
            <div class="card">
                <h2>Item di Keranjang (<?= count($cartItems) ?>)</h2>

                <?php foreach ($cartItems as $item): ?>
                    <div class="cart-item">
                        <div class="product-image">
                            <?php if ($item['image'] && file_exists($item['image'])): ?>
                                <img src="<?= htmlspecialchars($item['image']) ?>" alt="<?= htmlspecialchars($item['name']) ?>" style="width: 100%; height: 100%; object-fit: cover; border-radius: 10px;">
                            <?php else: ?>
                                📦
                            <?php endif; ?>
                        </div>

                        <div class="product-info">
                            <div class="product-name"><?= htmlspecialchars($item['name']) ?></div>
                            <div class="product-price"><?= formatPrice($item['price']) ?></div>
                            <div>Stok tersedia: <?= $item['stock'] ?></div>
                        </div>

                        <div class="quantity-controls">
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                                <input type="hidden" name="quantity" value="<?= max(1, $item['quantity'] - 1) ?>">
                                <button type="submit" class="quantity-btn">-</button>
                            </form>

                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                                <input type="number" name="quantity" value="<?= $item['quantity'] ?>"
                                    min="1" max="<?= $item['stock'] ?>" class="quantity-input"
                                    onchange="this.form.submit()">
                            </form>

                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                                <input type="hidden" name="quantity" value="<?= min($item['stock'], $item['quantity'] + 1) ?>">
                                <button type="submit" class="quantity-btn">+</button>
                            </form>
                        </div>

                        <div style="font-weight: bold; font-size: 18px; color: #ffd700;">
                            <?= formatPrice($item['price'] * $item['quantity']) ?>
                        </div>

                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                            <button type="submit" class="btn btn-danger" onclick="return confirm('Hapus produk ini dari keranjang?')">
                                Hapus
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>

                <div class="total-section">
                    <div class="total-line">
                        <span>Subtotal:</span>
                        <span><?= formatPrice($subtotal) ?></span>
                    </div>
                </div>

                <div class="checkout-section">
                    <h3>Checkout</h3>
                    <form method="POST">
                        <input type="hidden" name="action" value="checkout">

                        <div class="form-group">
                            <label for="delivery_method">Metode Pengiriman:</label>
                            <select name="delivery_method" id="delivery_method" required onchange="updateDeliveryFee()">
                                <option value="pickup">Ambil di Toko (Gratis)</option>
                                <option value="gojek">GoJek (Rp 15.000)</option>
                                <option value="grab">Grab (Rp 18.000)</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="payment_method">Metode Pembayaran:</label>
                            <select name="payment_method" id="payment_method" required>
                                <option value="cod">Cash on Delivery</option>
                                <option value="transfer">Transfer Bank</option>
                                <option value="ewallet">E-Wallet</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="notes">Catatan (Opsional):</label>
                            <textarea name="notes" id="notes" rows="3" placeholder="Tambahkan catatan untuk pesanan Anda"></textarea>
                        </div>

                        <div class="total-section">
                            <div class="total-line">
                                <span>Subtotal:</span>
                                <span><?= formatPrice($subtotal) ?></span>
                            </div>
                            <div class="total-line">
                                <span>Ongkir:</span>
                                <span id="delivery-fee">Rp 0</span>
                            </div>
                            <div class="total-line total-final">
                                <span>Total:</span>
                                <span id="total-amount"><?= formatPrice($subtotal) ?></span>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 15px; font-size: 16px;">
                            Pesan Sekarang
                        </button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        function updateDeliveryFee() {
            const deliveryMethod = document.getElementById('delivery_method').value;
            const fees = {
                'pickup': 0,
                'gojek': 15000,
                'grab': 18000
            };

            const fee = fees[deliveryMethod] || 0;
            const subtotal = <?= $subtotal ?>;
            const total = subtotal + fee;

            document.getElementById('delivery-fee').textContent = formatPrice(fee);
            document.getElementById('total-amount').textContent = formatPrice(total);
        }

        function formatPrice(amount) {
            return 'Rp ' + amount.toLocaleString('id-ID');
        }
    </script>
</body>

</html>