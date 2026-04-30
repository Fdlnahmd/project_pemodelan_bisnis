<?php
// reviews.php - Admin Reviews Management
require_once 'config.php';

if (!isLoggedIn()) {
    redirect('auth.php');
}

$currentUser = getCurrentUser();

// Only admin can access this page
if (!isAdmin($currentUser)) {
    redirect('index.php');
}

// Handle review actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'approve') {
        $review_id = intval($_POST['review_id']);
        $stmt = $pdo->prepare("UPDATE reviews SET status = 'approved' WHERE id = ?");
        $stmt->execute([$review_id]);
        showAlert('Review berhasil disetujui!', 'success');
    }

    if ($_POST['action'] === 'reject') {
        $review_id = intval($_POST['review_id']);
        $stmt = $pdo->prepare("UPDATE reviews SET status = 'rejected' WHERE id = ?");
        $stmt->execute([$review_id]);
        showAlert('Review berhasil ditolak!', 'success');
    }

    if ($_POST['action'] === 'delete') {
        $review_id = intval($_POST['review_id']);
        $stmt = $pdo->prepare("DELETE FROM reviews WHERE id = ?");
        $stmt->execute([$review_id]);
        showAlert('Review berhasil dihapus!', 'success');
    }

    redirect('admin_reviews.php');
}

// Get filter parameters
$status_filter = $_GET['status'] ?? 'all';
$rating_filter = $_GET['rating'] ?? 'all';
$sort_by = $_GET['sort'] ?? 'newest';

// Build query
$whereConditions = [];
$params = [];

if ($status_filter !== 'all') {
    $whereConditions[] = "r.status = ?";
    $params[] = $status_filter;
}

if ($rating_filter !== 'all') {
    $whereConditions[] = "r.rating = ?";
    $params[] = intval($rating_filter);
}

$whereClause = !empty($whereConditions) ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// Sort options
$sortOptions = [
    'newest' => 'r.created_at DESC',
    'oldest' => 'r.created_at ASC',
    'rating_high' => 'r.rating DESC',
    'rating_low' => 'r.rating ASC'
];

$orderBy = $sortOptions[$sort_by] ?? 'r.created_at DESC';

// Get reviews with product and user info
$stmt = $pdo->prepare("
    SELECT r.*, p.name as product_name, p.image as product_image, 
           u.name as user_name, u.email as user_email
    FROM reviews r 
    JOIN products p ON r.product_id = p.id 
    JOIN users u ON r.user_id = u.id 
    $whereClause 
    ORDER BY $orderBy
");
$stmt->execute($params);
$reviews = $stmt->fetchAll();

// Get statistics
$stats = [
    'total' => 0,
    'approved' => 0,
    'pending' => 0,
    'rejected' => 0,
    'avg_rating' => 0
];

$stmt = $pdo->query("SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected,
    AVG(CASE WHEN status = 'approved' THEN rating ELSE NULL END) as avg_rating
    FROM reviews");
$stats = $stmt->fetch();
$stats['avg_rating'] = round((float)($stats['avg_rating'] ?? 0), 1);
?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kelola Reviews - <?= SITE_NAME ?></title>
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

        .btn-danger {
            background: linear-gradient(45deg, #f44336, #da190b);
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

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
            color: white;
        }

        .stat-number {
            font-size: 36px;
            font-weight: bold;
            color: #ffd700;
            margin-bottom: 10px;
        }

        .stat-label {
            font-size: 14px;
            opacity: 0.8;
        }

        .filters {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .filter-group {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }

        .filter-label {
            color: white;
            font-weight: 500;
            margin-right: 10px;
        }

        .filter-select {
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            background: rgba(255, 255, 255, 0.1);
            color: white;
        }

        .filter-select option {
            background: #333;
            color: white;
        }

        .review-card {
            background: rgba(255, 255, 255, 0.05);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            border: 1px solid rgba(255, 255, 255, 0.1);
        }

        .review-header {
            display: flex;
            justify-content: between;
            align-items: flex-start;
            margin-bottom: 15px;
            gap: 15px;
        }

        .review-info {
            flex: 1;
        }

        .review-status {
            padding: 5px 12px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .status-approved {
            background: rgba(76, 175, 80, 0.2);
            color: #4caf50;
            border: 1px solid #4caf50;
        }

        .status-pending {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
            border: 1px solid #ffc107;
        }

        .status-rejected {
            background: rgba(244, 67, 54, 0.2);
            color: #f44336;
            border: 1px solid #f44336;
        }

        .product-info {
            display: flex;
            align-items: center;
            gap: 15px;
            margin-bottom: 15px;
        }

        .product-image {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            background: linear-gradient(45deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: white;
            flex-shrink: 0;
        }

        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            border-radius: 8px;
        }

        .product-details h4 {
            margin-bottom: 5px;
        }

        .product-details p {
            font-size: 14px;
            opacity: 0.8;
        }

        .rating {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 10px;
        }

        .stars {
            color: #ffd700;
            font-size: 18px;
        }

        .rating-number {
            font-weight: bold;
            color: #ffd700;
        }

        .review-content {
            background: rgba(255, 255, 255, 0.05);
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 15px;
            line-height: 1.6;
        }

        .review-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 14px;
            opacity: 0.8;
            margin-bottom: 15px;
        }

        .review-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
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

        .empty-state {
            text-align: center;
            padding: 50px;
            color: white;
        }

        .empty-icon {
            font-size: 64px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 15px;
            }

            .filter-group {
                flex-direction: column;
                align-items: flex-start;
            }

            .review-header {
                flex-direction: column;
                align-items: flex-start;
            }

            .product-info {
                flex-direction: column;
                align-items: flex-start;
            }

            .review-actions {
                flex-direction: column;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <?php displayAlert(); ?>

        <div class="header">
            <h1>📝 Kelola Reviews</h1>
            <div>
                <a href="admin.php" class="btn btn-secondary">Panel Admin</a>
                <a href="index.php" class="btn btn-secondary">Beranda</a>
            </div>
        </div>

        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $stats['total'] ?></div>
                <div class="stat-label">Total Reviews</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['approved'] ?></div>
                <div class="stat-label">Disetujui</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['pending'] ?></div>
                <div class="stat-label">Menunggu</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['rejected'] ?></div>
                <div class="stat-label">Ditolak</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $stats['avg_rating'] ?: '0' ?></div>
                <div class="stat-label">Rating Rata-rata</div>
            </div>
        </div>

        <!-- Filters -->
        <div class="filters">
            <form method="GET" class="filter-group">
                <div class="filter-group">
                    <label class="filter-label">Status:</label>
                    <select name="status" class="filter-select">
                        <option value="all" <?= $status_filter === 'all' ? 'selected' : '' ?>>Semua</option>
                        <option value="pending" <?= $status_filter === 'pending' ? 'selected' : '' ?>>Menunggu</option>
                        <option value="approved" <?= $status_filter === 'approved' ? 'selected' : '' ?>>Disetujui</option>
                        <option value="rejected" <?= $status_filter === 'rejected' ? 'selected' : '' ?>>Ditolak</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">Rating:</label>
                    <select name="rating" class="filter-select">
                        <option value="all" <?= $rating_filter === 'all' ? 'selected' : '' ?>>Semua</option>
                        <option value="5" <?= $rating_filter === '5' ? 'selected' : '' ?>>5 Bintang</option>
                        <option value="4" <?= $rating_filter === '4' ? 'selected' : '' ?>>4 Bintang</option>
                        <option value="3" <?= $rating_filter === '3' ? 'selected' : '' ?>>3 Bintang</option>
                        <option value="2" <?= $rating_filter === '2' ? 'selected' : '' ?>>2 Bintang</option>
                        <option value="1" <?= $rating_filter === '1' ? 'selected' : '' ?>>1 Bintang</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label class="filter-label">Urutkan:</label>
                    <select name="sort" class="filter-select">
                        <option value="newest" <?= $sort_by === 'newest' ? 'selected' : '' ?>>Terbaru</option>
                        <option value="oldest" <?= $sort_by === 'oldest' ? 'selected' : '' ?>>Terlama</option>
                        <option value="rating_high" <?= $sort_by === 'rating_high' ? 'selected' : '' ?>>Rating Tertinggi</option>
                        <option value="rating_low" <?= $sort_by === 'rating_low' ? 'selected' : '' ?>>Rating Terendah</option>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary">Filter</button>
            </form>
        </div>

        <!-- Reviews -->
        <div class="card">
            <?php if (empty($reviews)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📝</div>
                    <h2>Belum Ada Reviews</h2>
                    <p>Belum ada review yang masuk dari customer.</p>
                </div>
            <?php else: ?>
                <h2>Daftar Reviews (<?= count($reviews) ?>)</h2>

                <?php foreach ($reviews as $review): ?>
                    <div class="review-card">
                        <div class="review-header">
                            <div class="review-info">
                                <div class="product-info">
                                    <div class="product-image">
                                        <?php if ($review['product_image'] && file_exists($review['product_image'])): ?>
                                            <img src="<?= htmlspecialchars($review['product_image']) ?>"
                                                alt="<?= htmlspecialchars($review['product_name']) ?>">
                                        <?php else: ?>
                                            📦
                                        <?php endif; ?>
                                    </div>
                                    <div class="product-details">
                                        <h4><?= htmlspecialchars($review['product_name']) ?></h4>
                                        <p>Review oleh: <?= htmlspecialchars($review['user_name']) ?></p>
                                    </div>
                                </div>

                                <div class="rating">
                                    <div class="stars">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <?= $i <= $review['rating'] ? '★' : '☆' ?>
                                        <?php endfor; ?>
                                    </div>
                                    <span class="rating-number"><?= $review['rating'] ?>/5</span>
                                </div>
                            </div>

                            <div class="review-status status-<?= $review['status'] ?>">
                                <?php
                                $statusLabels = [
                                    'pending' => 'Menunggu',
                                    'approved' => 'Disetujui',
                                    'rejected' => 'Ditolak'
                                ];
                                echo $statusLabels[$review['status']] ?? $review['status'];
                                ?>
                            </div>
                        </div>

                        <?php if (!empty($review['comment'])): ?>
                            <div class="review-content">
                                <?= nl2br(htmlspecialchars($review['comment'])) ?>
                            </div>
                        <?php endif; ?>

                        <div class="review-meta">
                            <span>📅 <?= date('d M Y, H:i', strtotime($review['created_at'])) ?></span>
                            <span>👤 <?= htmlspecialchars($review['user_email']) ?></span>
                        </div>

                        <div class="review-actions">
                            <?php if ($review['status'] === 'pending'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="review_id" value="<?= $review['id'] ?>">
                                    <button type="submit" class="btn btn-success btn-small">
                                        ✓ Setujui
                                    </button>
                                </form>

                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="review_id" value="<?= $review['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-small">
                                        ✗ Tolak
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ($review['status'] === 'approved'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="review_id" value="<?= $review['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-small">
                                        ✗ Tolak
                                    </button>
                                </form>
                            <?php endif; ?>

                            <?php if ($review['status'] === 'rejected'): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="review_id" value="<?= $review['id'] ?>">
                                    <button type="submit" class="btn btn-success btn-small">
                                        ✓ Setujui
                                    </button>
                                </form>
                            <?php endif; ?>

                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="review_id" value="<?= $review['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-small"
                                    onclick="return confirm('Yakin ingin menghapus review ini?')">
                                    🗑️ Hapus
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>

</html>