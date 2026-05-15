<?php
require_once 'includes/config.php';

// Get categories for filter
$stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
$categories = $stmt->fetchAll();

// Get products with search and filter
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';

$sql = "SELECT p.*, c.name as category_name FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        WHERE p.status = 'active'";
$params = [];

if ($search) {
    $sql .= " AND p.name LIKE ?";
    $params[] = "%$search%";
}

if ($category) {
    $sql .= " AND c.slug = ?";
    $params[] = $category;
}

$sql .= " ORDER BY p.created_at DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// Get cart count for logged in user
$cartCount = 0;
if (isLoggedIn()) {
    $stmt = $pdo->prepare("SELECT SUM(quantity) as total FROM cart WHERE user_id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $result = $stmt->fetch();
    $cartCount = $result['total'] ?? 0;
}

$currentUser = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= SITE_NAME ?> - Part Elektronik Online Jakarta</title>
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

        .user-info {
            color: white;
            display: flex;
            align-items: center;
            gap: 15px;
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


        .btn-admin:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(76, 175, 80, 0.4);
        }

        .search-section {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .search-form {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
        }

        .search-input {
            flex: 1;
            padding: 15px;
            border: none;
            border-radius: 25px;
            background: rgba(255, 255, 255, 0.9);
            font-size: 16px;
        }

        .search-input:focus {
            outline: none;
            box-shadow: 0 0 20px rgba(255, 255, 255, 0.5);
        }

        .category-filters {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .filter-btn {
            padding: 8px 16px;
            background: rgba(255, 255, 255, 0.2);
            color: white;
            border: none;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
        }

        .filter-btn.active {
            background: #ff6b6b;
        }

        .filter-btn:hover {
            background: rgba(255, 255, 255, 0.3);
        }

        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 30px;
        }

        .product-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.3s ease;
            color: white;
        }

        .product-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
        }

        .product-image {
            width: 100%;
            height: 200px;
            border-radius: 10px;
            margin-bottom: 15px;
            overflow: hidden;
            position: relative;
        }

        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .product-image-placeholder {
            width: 100%;
            height: 100%;
            background: linear-gradient(45deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 48px;
            color: white;
        }

        .product-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 8px;
        }

        .product-price {
            font-size: 20px;
            font-weight: bold;
            color: #ffd700;
            margin-bottom: 10px;
        }

        .product-stock {
            font-size: 14px;
            color: #90ee90;
            margin-bottom: 15px;
        }

        .product-stock.low {
            color: #ffeb3b;
        }

        .product-stock.out {
            color: #ff6b6b;
        }

        .cart {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            padding: 15px;
            border-radius: 50px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
            cursor: pointer;
            font-size: 18px;
            z-index: 1000;
            text-decoration: none;
        }

        .cart:hover {
            transform: scale(1.1);
        }

        .cart-count {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #ff6b6b;
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
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

        .user-role {
            background: rgba(255, 255, 255, 0.2);
            padding: 4px 8px;
            border-radius: 10px;
            font-size: 12px;
            margin-left: 10px;
        }

        .user-role.admin {
            background: rgba(76, 175, 80, 0.3);
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 15px;
            }

            .search-form {
                flex-direction: column;
            }

            .products-grid {
                grid-template-columns: 1fr;
            }

            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
            }

            .cart {
                bottom: 20px;
                right: 20px;
                top: auto;
            }
        }
    </style>
    <!-- Cloudflare Web Analytics --><script defer src='https://static.cloudflareinsights.com/beacon.min.js' data-cf-beacon='{"token": "64cbce0f6a4f4f179f95a7a9917112bb"}'></script><!-- End Cloudflare Web Analytics -->
</head>

<body>
    <div class="container">
        <?php displayAlert(); ?>

        <header class="header">
            <a href="index.php" class="logo">⚡ <?= SITE_NAME ?></a>
            <div class="user-info">
                <?php if ($currentUser): ?>
                    <div class="nav-links">
                        <span>
                            Halo, <?= htmlspecialchars($currentUser['name']) ?>
                            <?php if (isAdmin($currentUser)): ?>
                                <span class="user-role admin">Admin</span>
                            <?php endif; ?>
                        </span>
                        <a href="orders.php">Pesanan</a>
                        <a href="profile.php">Profile</a>
                        <?php if (isAdmin($currentUser)): ?>
                            <a href="admin/admin.php" class="btn btn-admin">Panel Admin</a>
                        <?php endif; ?>
                        <a href="auth/auth.php?action=logout" class="btn btn-secondary">Logout</a>
                    </div>
                <?php else: ?>
                    <div class="nav-links">
                        <a href="auth/auth.php" class="btn btn-secondary">Login</a>
                        <a href="auth/auth.php?action=register" class="btn btn-primary">Daftar</a>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <div class="search-section">
            <form class="search-form" method="GET">
                <input type="text" name="search" class="search-input"
                    placeholder="Cari part elektronik..."
                    value="<?= htmlspecialchars($search) ?>">
                <button type="submit" class="btn btn-primary">🔍 Cari</button>
            </form>
            <div class="category-filters">
                <a href="index.php" class="filter-btn <?= !$category ? 'active' : '' ?>">Semua</a>
                <?php foreach ($categories as $cat): ?>
                    <a href="?category=<?= $cat['slug'] ?><?= $search ? '&search=' . urlencode($search) : '' ?>"
                        class="filter-btn <?= $category === $cat['slug'] ? 'active' : '' ?>">
                        <?= htmlspecialchars($cat['name']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="products-grid">
            <?php if (empty($products)): ?>
                <div style="grid-column: 1/-1; text-align: center; color: white; font-size: 18px; padding: 50px;">
                    <div style="font-size: 48px; margin-bottom: 20px;">📦</div>
                    <div>Tidak ada produk ditemukan</div>
                    <?php if ($search || $category): ?>
                        <a href="index.php" class="btn btn-primary" style="margin-top: 20px;">Lihat Semua Produk</a>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <?php foreach ($products as $product): ?>
                    <div class="product-card">
                        <div class="product-image">
                            <?php if (!empty($product['image']) && file_exists($product['image'])): ?>
                                <img src="<?= htmlspecialchars($product['image']) ?>"
                                    alt="<?= htmlspecialchars($product['name']) ?>"
                                    onerror="this.parentElement.innerHTML='<div class=\'product-image-placeholder\'>📦</div>'">
                            <?php else: ?>
                                <div class="product-image-placeholder">
                                    <?php
                                    // Icon berdasarkan kategori
                                    $icons = [
                                        'resistor' => '⚡',
                                        'capacitor' => '🔋',
                                        'ic' => '💾',
                                        'sensor' => '🌡️',
                                        'arduino' => '🤖',
                                        'led' => '💡'
                                    ];
                                    $categorySlug = '';
                                    foreach ($categories as $cat) {
                                        if ($cat['id'] == $product['category_id']) {
                                            $categorySlug = $cat['slug'];
                                            break;
                                        }
                                    }
                                    echo $icons[$categorySlug] ?? '📦';
                                    ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="product-title"><?= htmlspecialchars($product['name']) ?></div>
                        <div class="product-price"><?= formatPrice($product['price']) ?></div>
                        <div class="product-stock <?= $product['stock'] <= 0 ? 'out' : ($product['stock'] <= 5 ? 'low' : '') ?>">
                            <?php if ($product['stock'] <= 0): ?>
                                Stok Habis
                            <?php elseif ($product['stock'] <= 5): ?>
                                Stok Terbatas: <?= $product['stock'] ?>
                            <?php else: ?>
                                Stok: <?= $product['stock'] ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($product['description']): ?>
                            <div style="font-size: 14px; margin-bottom: 15px; opacity: 0.8;">
                                <?= htmlspecialchars(substr($product['description'], 0, 100)) ?>
                                <?= strlen($product['description']) > 100 ? '...' : '' ?>
                            </div>
                        <?php endif; ?>
                        <div style="margin-bottom: 10px; font-size: 14px; opacity: 0.8;">
                            <strong>Kategori:</strong> <?= htmlspecialchars($product['category_name']) ?>
                        </div>

                        <?php if ($currentUser): ?>
                            <?php if ($product['stock'] > 0): ?>
                                <form method="POST" action="cart.php">
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                                    <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                                        <input type="number" name="quantity" value="1" min="1" max="<?= $product['stock'] ?>"
                                            style="width: 70px; padding: 5px; border: none; border-radius: 5px; text-align: center;">
                                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                                            🛒 Tambah ke Keranjang
                                        </button>
                                    </div>
                                </form>
                            <?php else: ?>
                                <button class="btn btn-secondary" style="width: 100%;" disabled>
                                    Stok Habis
                                </button>
                            <?php endif; ?>
                        <?php else: ?>
                            <a href="auth/auth.php" class="btn btn-secondary" style="width: 100%; text-align: center;">
                                Login untuk Membeli
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($currentUser): ?>
        <a href="cart.php" class="cart">
            🛒
            <?php if ($cartCount > 0): ?>
                <span class="cart-count"><?= $cartCount ?></span>
            <?php endif; ?>
        </a>
    <?php endif; ?>
</body>

</html>