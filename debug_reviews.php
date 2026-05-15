<?php
// Debug script untuk mengecek mengapa produk tidak tampil
require_once 'includes/config.php';

if (!isLoggedIn()) {
    die('Please login first');
}

$currentUser = getCurrentUser();
echo "<h2>Debug Info for User: " . htmlspecialchars($currentUser['username']) . " (ID: " . $currentUser['id'] . ")</h2>";

// 1. Cek semua pesanan user
echo "<h3>1. Semua Pesanan User:</h3>";
$stmt = $pdo->prepare("SELECT id, status, created_at FROM orders WHERE user_id = ? ORDER BY created_at DESC");
$stmt->execute([$currentUser['id']]);
$orders = $stmt->fetchAll();

if (empty($orders)) {
    echo "<p style='color: red;'>TIDAK ADA PESANAN DITEMUKAN!</p>";
} else {
    echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>";
    echo "<tr><th>Order ID</th><th>Status</th><th>Tanggal</th></tr>";
    foreach ($orders as $order) {
        echo "<tr>";
        echo "<td>" . $order['id'] . "</td>";
        echo "<td>" . $order['status'] . "</td>";
        echo "<td>" . $order['created_at'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 2. Cek pesanan yang statusnya 'completed'
echo "<h3>2. Pesanan dengan Status 'completed':</h3>";
$stmt = $pdo->prepare("SELECT id, status, created_at FROM orders WHERE user_id = ? AND status = 'completed' ORDER BY created_at DESC");
$stmt->execute([$currentUser['id']]);
$completedOrders = $stmt->fetchAll();

if (empty($completedOrders)) {
    echo "<p style='color: red;'>TIDAK ADA PESANAN COMPLETED!</p>";

    // Cek status apa saja yang ada
    echo "<h4>Status yang tersedia:</h4>";
    $stmt = $pdo->prepare("SELECT DISTINCT status FROM orders WHERE user_id = ?");
    $stmt->execute([$currentUser['id']]);
    $statuses = $stmt->fetchAll();

    if (!empty($statuses)) {
        echo "<ul>";
        foreach ($statuses as $status) {
            echo "<li>" . htmlspecialchars($status['status']) . "</li>";
        }
        echo "</ul>";
    }
} else {
    echo "<p style='color: green;'>Ditemukan " . count($completedOrders) . " pesanan completed</p>";
}

// 3. Cek item dari pesanan completed
echo "<h3>3. Item dari Pesanan Completed:</h3>";
$stmt = $pdo->prepare("SELECT o.id as order_id, oi.product_id, p.name as product_name 
                       FROM orders o 
                       INNER JOIN order_items oi ON o.id = oi.order_id 
                       INNER JOIN products p ON oi.product_id = p.id
                       WHERE o.user_id = ? AND o.status = 'completed'");
$stmt->execute([$currentUser['id']]);
$orderItems = $stmt->fetchAll();

if (empty($orderItems)) {
    echo "<p style='color: red;'>TIDAK ADA ITEM DITEMUKAN!</p>";
} else {
    echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>";
    echo "<tr><th>Order ID</th><th>Product ID</th><th>Product Name</th></tr>";
    foreach ($orderItems as $item) {
        echo "<tr>";
        echo "<td>" . $item['order_id'] . "</td>";
        echo "<td>" . $item['product_id'] . "</td>";
        echo "<td>" . htmlspecialchars($item['product_name']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 4. Cek review yang sudah ada
echo "<h3>4. Review yang Sudah Ada:</h3>";
$stmt = $pdo->prepare("SELECT product_id, rating, status FROM reviews WHERE user_id = ?");
$stmt->execute([$currentUser['id']]);
$existingReviews = $stmt->fetchAll();

if (empty($existingReviews)) {
    echo "<p>Belum ada review</p>";
} else {
    echo "<table border='1' style='border-collapse: collapse; margin-bottom: 20px;'>";
    echo "<tr><th>Product ID</th><th>Rating</th><th>Status</th></tr>";
    foreach ($existingReviews as $review) {
        echo "<tr>";
        echo "<td>" . $review['product_id'] . "</td>";
        echo "<td>" . $review['rating'] . "</td>";
        echo "<td>" . $review['status'] . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}

// 5. Query final untuk produk yang bisa di-review
echo "<h3>5. Produk yang Bisa di-Review (Query Final):</h3>";
$stmt = $pdo->prepare("SELECT DISTINCT p.id, p.name, p.image 
                       FROM products p
                       INNER JOIN order_items oi ON p.id = oi.product_id
                       INNER JOIN orders o ON oi.order_id = o.id
                       WHERE o.user_id = ? AND o.status = 'completed'
                       AND p.id NOT IN (
                           SELECT product_id FROM reviews WHERE user_id = ?
                       )
                       ORDER BY p.name");
$stmt->execute([$currentUser['id'], $currentUser['id']]);
$reviewableProducts = $stmt->fetchAll();

if (empty($reviewableProducts)) {
    echo "<p style='color: red;'>TIDAK ADA PRODUK YANG BISA DI-REVIEW!</p>";
} else {
    echo "<p style='color: green;'>Ditemukan " . count($reviewableProducts) . " produk yang bisa di-review:</p>";
    echo "<ul>";
    foreach ($reviewableProducts as $product) {
        echo "<li>ID: " . $product['id'] . " - " . htmlspecialchars($product['name']) . "</li>";
    }
    echo "</ul>";
}

// 6. Cek apakah ada masalah dengan JOIN
echo "<h3>6. Test Query Langkah demi Langkah:</h3>";

// Step 1: Cek tabel orders
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM orders WHERE user_id = ?");
$stmt->execute([$currentUser['id']]);
$orderCount = $stmt->fetchColumn();
echo "<p>Total orders untuk user: " . $orderCount . "</p>";

// Step 2: Cek tabel order_items
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM order_items oi 
                       INNER JOIN orders o ON oi.order_id = o.id 
                       WHERE o.user_id = ?");
$stmt->execute([$currentUser['id']]);
$itemCount = $stmt->fetchColumn();
echo "<p>Total order items untuk user: " . $itemCount . "</p>";

// Step 3: Cek dengan JOIN ke products
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM products p
                       INNER JOIN order_items oi ON p.id = oi.product_id
                       INNER JOIN orders o ON oi.order_id = o.id
                       WHERE o.user_id = ?");
$stmt->execute([$currentUser['id']]);
$productCount = $stmt->fetchColumn();
echo "<p>Total products dari orders user: " . $productCount . "</p>";

// Step 4: Dengan filter completed
$stmt = $pdo->prepare("SELECT COUNT(*) as count FROM products p
                       INNER JOIN order_items oi ON p.id = oi.product_id
                       INNER JOIN orders o ON oi.order_id = o.id
                       WHERE o.user_id = ? AND o.status = 'completed'");
$stmt->execute([$currentUser['id']]);
$completedProductCount = $stmt->fetchColumn();
echo "<p>Total products dari completed orders: " . $completedProductCount . "</p>";

echo "<hr>";
echo "<h3>Kesimpulan:</h3>";
if ($completedProductCount == 0) {
    echo "<p style='color: red; font-weight: bold;'>MASALAH: Tidak ada produk dari pesanan yang berstatus 'completed'</p>";
    echo "<p>Kemungkinan penyebab:</p>";
    echo "<ul>";
    echo "<li>Status pesanan bukan 'completed' (cek bagian 1 di atas)</li>";
    echo "<li>Tidak ada relasi yang benar antara orders dan order_items</li>";
    echo "<li>Tidak ada relasi yang benar antara order_items dan products</li>";
    echo "</ul>";
} else {
    echo "<p style='color: orange;'>Ada " . $completedProductCount . " produk dari pesanan completed, tapi tidak tampil karena:</p>";
    echo "<ul>";
    echo "<li>Semua produk sudah di-review</li>";
    echo "<li>Ada masalah dengan query NOT IN untuk filter review</li>";
    echo "</ul>";
}
