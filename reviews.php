<?php
require_once 'includes/config.php';

// Check if user is logged in
if (!isLoggedIn()) {
    showAlert('Anda harus login terlebih dahulu.', 'error');
    redirect('auth.php');
}

$currentUser = getCurrentUser();
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'add') {
        $product_id = intval($_POST['product_id']);
        $rating = intval($_POST['rating']);
        $comment = sanitize($_POST['review_text']);

        // Validasi input
        if ($rating < 1 || $rating > 5) {
            showAlert('Rating harus antara 1-5 bintang', 'error');
            redirect('reviews.php?action=add&product_id=' . $product_id);
        }

        // Cek apakah user sudah pernah membeli produk ini
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM orders o 
                              INNER JOIN order_items oi ON o.id = oi.order_id 
                              WHERE o.user_id = ? AND oi.product_id = ? AND o.status = 'delivered'");
        $stmt->execute([$currentUser['id'], $product_id]);
        $hasPurchased = $stmt->fetchColumn() > 0;

        if (!$hasPurchased) {
            showAlert('Anda hanya dapat memberikan review untuk produk yang sudah dibeli', 'error');
            redirect('reviews.php');
        }

        // Cek apakah user sudah memberikan review untuk produk ini
        $stmt = $pdo->prepare("SELECT id FROM reviews WHERE user_id = ? AND product_id = ?");
        $stmt->execute([$currentUser['id'], $product_id]);
        $existingReview = $stmt->fetch();

        if ($existingReview) {
            showAlert('Anda sudah memberikan review untuk produk ini', 'error');
            redirect('reviews.php');
        }

// Insert review
$stmt = $pdo->prepare("SELECT o.id FROM orders o 
               INNER JOIN order_items oi ON o.id = oi.order_id 
               WHERE o.user_id = ? AND oi.product_id = ? AND o.status = 'delivered' 
               LIMIT 1");
$stmt->execute([$currentUser['id'], $product_id]);
$order_id = $stmt->fetchColumn();
        try {
            $stmt = $pdo->prepare("INSERT INTO reviews (product_id, user_id, order_id, rating, comment) VALUES (?, ?, ?, ?, ?)");
            if ($stmt->execute([$product_id, $currentUser['id'], $order_id, $rating, $comment])) {
                showAlert('Review berhasil ditambahkan! Menunggu persetujuan admin.', 'success');
            } else {
                showAlert('Gagal menambahkan review', 'error');
            }
        } catch (PDOException $e) {
            error_log("Review insert error: " . $e->getMessage());
            showAlert('Error database: ' . $e->getMessage(), 'error');
        }

        redirect('reviews.php');
    } elseif ($action === 'edit') {
        $review_id = intval($_POST['review_id']);
        $rating = intval($_POST['rating']);
        $comment = sanitize($_POST['review_text']);

        // Validasi input
        if ($rating < 1 || $rating > 5) {
            showAlert('Rating harus antara 1-5 bintang', 'error');
            redirect('reviews.php?action=edit&id=' . $review_id);
        }

        // Update review (hanya milik user yang login)
        try {
            $stmt = $pdo->prepare("UPDATE reviews SET rating = ?, comment = ?, status = 'pending' WHERE id = ? AND user_id = ?");
            if ($stmt->execute([$rating, $comment, $review_id, $currentUser['id']])) {
                showAlert('Review berhasil diperbarui! Menunggu persetujuan admin.', 'success');
            } else {
                showAlert('Gagal memperbarui review', 'error');
            }
        } catch (PDOException $e) {
            error_log("Review update error: " . $e->getMessage());
            showAlert('Error database: ' . $e->getMessage(), 'error');
        }

        redirect('reviews.php');
    } elseif ($action === 'delete') {
        $review_id = intval($_POST['review_id']);

        try {
            $stmt = $pdo->prepare("DELETE FROM reviews WHERE id = ? AND user_id = ?");
            if ($stmt->execute([$review_id, $currentUser['id']])) {
                showAlert('Review berhasil dihapus', 'success');
            } else {
                showAlert('Gagal menghapus review', 'error');
            }
        } catch (PDOException $e) {
            error_log("Review delete error: " . $e->getMessage());
            showAlert('Error database: ' . $e->getMessage(), 'error');
        }

        redirect('reviews.php');
    }
}

// Get user's reviews
$stmt = $pdo->prepare("SELECT r.*, p.name as product_name, p.image as product_image 
                       FROM reviews r 
                       INNER JOIN products p ON r.product_id = p.id 
                       WHERE r.user_id = ? 
                       ORDER BY r.created_at DESC");
$stmt->execute([$currentUser['id']]);
$userReviews = $stmt->fetchAll();

// Get products that user has bought but not reviewed
$stmt = $pdo->prepare("SELECT DISTINCT p.id, p.name, p.image 
                       FROM products p
                       INNER JOIN order_items oi ON p.id = oi.product_id
                       INNER JOIN orders o ON oi.order_id = o.id
                       WHERE o.user_id = ? AND o.status = 'delivered'
                       AND p.id NOT IN (
                           SELECT product_id FROM reviews WHERE user_id = ?
                       )
                       ORDER BY p.name");
$stmt->execute([$currentUser['id'], $currentUser['id']]);
$reviewableProducts = $stmt->fetchAll();

// Get specific product for review form
$reviewProduct = null;
if ($action === 'add' && isset($_GET['product_id'])) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([$_GET['product_id']]);
    $reviewProduct = $stmt->fetch();
}

// Get specific review for editing
$editReview = null;
if ($action === 'edit' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT r.*, p.name as product_name, p.image as product_image 
                           FROM reviews r 
                           INNER JOIN products p ON r.product_id = p.id 
                           WHERE r.id = ? AND r.user_id = ?");
    $stmt->execute([$_GET['id'], $currentUser['id']]);
    $editReview = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Review Produk - <?= SITE_NAME ?></title>
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

        .btn-success {
            background: linear-gradient(45deg, #4caf50, #45a049);
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

        .card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
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
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 15px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: rgba(255, 255, 255, 0.6);
            background: rgba(255, 255, 255, 0.15);
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .star-rating {
            display: flex;
            gap: 5px;
            margin-bottom: 15px;
        }

        .star {
            font-size: 24px;
            color: rgba(255, 255, 255, 0.3);
            cursor: pointer;
            transition: color 0.3s ease;
        }

        .star:hover,
        .star.active {
            color: #ffd700;
        }

        .review-item {
            background: rgba(255, 255, 255, 0.05);
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 15px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .review-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }

        .product-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }

        .product-image {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            object-fit: cover;
        }

        .product-image-placeholder {
            width: 60px;
            height: 60px;
            background: linear-gradient(45deg, #4facfe, #00f2fe);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }

        .rating-display {
            display: flex;
            gap: 2px;
            margin-bottom: 10px;
        }

        .rating-display .star {
            font-size: 18px;
            cursor: default;
        }

        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }

        .status-pending {
            background: rgba(255, 193, 7, 0.3);
            color: #ffc107;
        }

        .status-approved {
            background: rgba(76, 175, 80, 0.3);
            color: #4caf50;
        }

        .status-rejected {
            background: rgba(244, 67, 54, 0.3);
            color: #f44336;
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

        .tabs {
            display: flex;
            margin-bottom: 20px;
        }

        .tab {
            padding: 15px 25px;
            background: rgba(255, 255, 255, 0.1);
            border: none;
            color: white;
            cursor: pointer;
            border-radius: 10px 10px 0 0;
            margin-right: 5px;
            transition: all 0.3s ease;
        }

        .tab.active {
            background: rgba(255, 255, 255, 0.2);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .empty-state {
            text-align: center;
            padding: 50px;
            color: rgba(255, 255, 255, 0.7);
        }

        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 15px;
            }

            .review-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }

            .product-info {
                flex-direction: column;
                text-align: center;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <?php displayAlert(); ?>

        <div class="header">
            <h1>Review Produk</h1>
            <div>
                <a href="index.php" class="btn btn-secondary">Kembali ke Toko</a>
            </div>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="showTab('my-reviews')">Review Saya</button>
            <button class="tab" onclick="showTab('add-review')">Tulis Review</button>
        </div>

        <div id="my-reviews" class="tab-content active">
            <div class="card">
                <h2>Review Saya</h2>
                <?php if (empty($userReviews)): ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">⭐</div>
                        <div>Anda belum memberikan review untuk produk apapun</div>
                    </div>
                <?php else: ?>
                    <?php foreach ($userReviews as $review): ?>
                        <div class="review-item">
                            <div class="review-header">
                                <div class="product-info">
                                    <?php if ($review['product_image'] && file_exists($review['product_image'])): ?>
                                        <img src="<?= htmlspecialchars($review['product_image']) ?>"
                                            alt="<?= htmlspecialchars($review['product_name']) ?>"
                                            class="product-image">
                                    <?php else: ?>
                                        <div class="product-image-placeholder">📦</div>
                                    <?php endif; ?>
                                    <div>
                                        <div style="font-weight: 600; margin-bottom: 5px;">
                                            <?= htmlspecialchars($review['product_name']) ?>
                                        </div>
                                        <div class="rating-display">
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <span class="star <?= $i <= $review['rating'] ? 'active' : '' ?>">★</span>
                                            <?php endfor; ?>
                                        </div>
                                    </div>
                                </div>
                                <div style="text-align: right;">
                                    <div class="status-badge status-<?= $review['status'] ?>">
                                        <?php
                                        $statusLabels = [
                                            'pending' => 'Menunggu Persetujuan',
                                            'approved' => 'Disetujui',
                                            'rejected' => 'Ditolak'
                                        ];
                                        echo $statusLabels[$review['status']];
                                        ?>
                                    </div>
                                    <div style="font-size: 12px; margin-top: 5px; opacity: 0.7;">
                                        <?= date('d/m/Y H:i', strtotime($review['created_at'])) ?>
                                    </div>
                                </div>
                            </div>

                            <?php if ($review['comment']): ?>
                                <div style="margin-bottom: 15px;">
                                    <?= nl2br(htmlspecialchars($review['comment'])) ?>
                                </div>
                            <?php endif; ?>

                            <div style="display: flex; gap: 10px;">
                                <a href="?action=edit&id=<?= $review['id'] ?>" class="btn btn-primary">Edit</a>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menghapus review ini?')">
                                    <input type="hidden" name="review_id" value="<?= $review['id'] ?>">
                                    <button type="submit" name="action" value="delete" class="btn btn-danger">Hapus</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div id="add-review" class="tab-content">
            <div class="card">
                <h2><?= $editReview ? 'Edit Review' : 'Tulis Review Baru' ?></h2>

                <?php if ($editReview): ?>
                    <form method="POST" action="?action=edit">
                        <input type="hidden" name="review_id" value="<?= $editReview['id'] ?>">

                        <div class="product-info" style="margin-bottom: 20px;">
                            <?php if ($editReview['product_image'] && file_exists($editReview['product_image'])): ?>
                                <img src="<?= htmlspecialchars($editReview['product_image']) ?>"
                                    alt="<?= htmlspecialchars($editReview['product_name']) ?>"
                                    class="product-image">
                            <?php else: ?>
                                <div class="product-image-placeholder">📦</div>
                            <?php endif; ?>
                            <div>
                                <div style="font-weight: 600;">
                                    <?= htmlspecialchars($editReview['product_name']) ?>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Rating *</label>
                            <div class="star-rating" id="rating-stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <span class="star <?= $i <= $editReview['rating'] ? 'active' : '' ?>"
                                        data-rating="<?= $i ?>">★</span>
                                <?php endfor; ?>
                            </div>
                            <input type="hidden" name="rating" id="rating-input" value="<?= $editReview['rating'] ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="review_text">Review</label>
                            <textarea id="review_text" name="review_text" rows="4"
                                placeholder="Tulis review Anda tentang produk ini..."><?= htmlspecialchars($editReview['comment']) ?></textarea>
                        </div>

                        <button type="submit" class="btn btn-success" style="width: 100%;">
                            Update Review
                        </button>
                    </form>
                <?php elseif ($reviewProduct): ?>
                    <form method="POST" action="?action=add">
                        <input type="hidden" name="product_id" value="<?= $reviewProduct['id'] ?>">

                        <div class="product-info" style="margin-bottom: 20px;">
                            <?php if ($reviewProduct['image'] && file_exists($reviewProduct['image'])): ?>
                                <img src="<?= htmlspecialchars($reviewProduct['image']) ?>"
                                    alt="<?= htmlspecialchars($reviewProduct['name']) ?>"
                                    class="product-image">
                            <?php else: ?>
                                <div class="product-image-placeholder">📦</div>
                            <?php endif; ?>
                            <div>
                                <div style="font-weight: 600;">
                                    <?= htmlspecialchars($reviewProduct['name']) ?>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Rating *</label>
                            <div class="star-rating" id="rating-stars">
                                <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <span class="star" data-rating="<?= $i ?>">★</span>
                                <?php endfor; ?>
                            </div>
                            <input type="hidden" name="rating" id="rating-input" required>
                        </div>

                        <div class="form-group">
                            <label for="review_text">Review</label>
                            <textarea id="review_text" name="review_text" rows="4"
                                placeholder="Tulis review Anda tentang produk ini..."></textarea>
                        </div>

                        <button type="submit" class="btn btn-success" style="width: 100%;">
                            Kirim Review
                        </button>
                    </form>
                <?php else: ?>
                    <div>
                        <h3>Pilih produk untuk di-review:</h3>
                        <?php if (empty($reviewableProducts)): ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">📦</div>
                                <div>Tidak ada produk yang dapat di-review</div>
                                <div style="font-size: 14px; margin-top: 10px; opacity: 0.7;">
                                    Anda hanya dapat me-review produk yang sudah dibeli
                                </div>
                            </div>
                        <?php else: ?>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; margin-top: 20px;">
                                <?php foreach ($reviewableProducts as $product): ?>
                                    <div style="background: rgba(255, 255, 255, 0.05); padding: 15px; border-radius: 10px; text-align: center;">
                                        <?php if ($product['image'] && file_exists($product['image'])): ?>
                                            <img src="<?= htmlspecialchars($product['image']) ?>"
                                                alt="<?= htmlspecialchars($product['name']) ?>"
                                                style="width: 80px; height: 80px; object-fit: cover; border-radius: 10px; margin-bottom: 10px;">
                                        <?php else: ?>
                                            <div style="width: 80px; height: 80px; background: linear-gradient(45deg, #4facfe, #00f2fe); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 32px; margin: 0 auto 10px;">📦</div>
                                        <?php endif; ?>
                                        <div style="font-weight: 500; margin-bottom: 10px;">
                                            <?= htmlspecialchars($product['name']) ?>
                                        </div>
                                        <a href="?action=add&product_id=<?= $product['id'] ?>" class="btn btn-primary">
                                            Tulis Review
                                        </a>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function showTab(tabName) {
            // Hide all tab contents
            const tabContents = document.querySelectorAll('.tab-content');
            tabContents.forEach(content => {
                content.classList.remove('active');
            });

            // Remove active class from all tabs
            const tabs = document.querySelectorAll('.tab');
            tabs.forEach(tab => {
                tab.classList.remove('active');
            });

            // Show selected tab content
            document.getElementById(tabName).classList.add('active');

            // Add active class to clicked tab
            event.target.classList.add('active');
        }

        // Star rating functionality
        const stars = document.querySelectorAll('#rating-stars .star');
        const ratingInput = document.getElementById('rating-input');

        stars.forEach(star => {
            star.addEventListener('click', function() {
                const rating = parseInt(this.dataset.rating);
                ratingInput.value = rating;

                // Update star appearance
                stars.forEach((s, index) => {
                    if (index < rating) {
                        s.classList.add('active');
                    } else {
                        s.classList.remove('active');
                    }
                });
            });

            star.addEventListener('mouseover', function() {
                const rating = parseInt(this.dataset.rating);
                stars.forEach((s, index) => {
                    if (index < rating) {
                        s.style.color = '#ffd700';
                    } else {
                        s.style.color = 'rgba(255, 255, 255, 0.3)';
                    }
                });
            });
        });

        // Reset star colors on mouse leave
        const ratingContainer = document.getElementById('rating-stars');
        if (ratingContainer) {
            ratingContainer.addEventListener('mouseleave', function() {
                const currentRating = parseInt(ratingInput.value) || 0;
                stars.forEach((s, index) => {
                    if (index < currentRating) {
                        s.style.color = '#ffd700';
                    } else {
                        s.style.color = 'rgba(255, 255, 255, 0.3)';
                    }
                });
            });
        }

        // Auto-switch to add-review tab if editing or adding
        <?php if ($action === 'edit' || $action === 'add'): ?>
            showTab('add-review');
        <?php endif; ?>
    </script>
</body>

</html>