<?php
require_once '../includes/config.php';

if (!isLoggedIn()) {
    redirect('../auth/auth.php');
}

$currentUser = getCurrentUser();
if (!isAdmin($currentUser)) {
    showAlert('Akses ditolak. Hanya admin yang dapat mengakses halaman ini.', 'error');
    redirect('../index.php');
}

// ── Filter periode ────────────────────────────────────────────────
$period    = $_GET['period']    ?? 'this_month';
$dateStart = $_GET['date_start'] ?? '';
$dateEnd   = $_GET['date_end']   ?? '';

switch ($period) {
    case 'today':
        $dateStart = date('Y-m-d');
        $dateEnd   = date('Y-m-d');
        break;
    case 'this_week':
        $dateStart = date('Y-m-d', strtotime('monday this week'));
        $dateEnd   = date('Y-m-d');
        break;
    case 'this_month':
        $dateStart = date('Y-m-01');
        $dateEnd   = date('Y-m-d');
        break;
    case 'last_month':
        $dateStart = date('Y-m-01', strtotime('first day of last month'));
        $dateEnd   = date('Y-m-t', strtotime('last day of last month'));
        break;
    case 'this_year':
        $dateStart = date('Y-01-01');
        $dateEnd   = date('Y-m-d');
        break;
    case 'custom':
        // pakai input user, sudah di-set di atas
        break;
    default:
        $dateStart = date('Y-m-01');
        $dateEnd   = date('Y-m-d');
}

$dateStartFull = $dateStart . ' 00:00:00';
$dateEndFull   = $dateEnd   . ' 23:59:59';

// ── Handle export CSV ─────────────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $pdo->prepare("
        SELECT o.order_number, u.name AS customer_name, u.email AS customer_email,
               o.total_amount, o.delivery_method, o.delivery_fee, o.payment_method,
               o.status, o.created_at
        FROM orders o
        JOIN users u ON o.user_id = u.id
        WHERE o.created_at BETWEEN ? AND ?
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$dateStartFull, $dateEndFull]);
    $exportOrders = $stmt->fetchAll();

    $deliveryMethods = ['pickup' => 'Ambil di Toko', 'gojek' => 'GoJek', 'grab' => 'Grab'];
    $paymentMethods  = ['cod' => 'Cash on Delivery', 'transfer' => 'Transfer Bank', 'ewallet' => 'E-Wallet'];
    $statusLabelsMap = [
        'pending'   => 'Menunggu Konfirmasi',
        'confirmed' => 'Dikonfirmasi',
        'preparing' => 'Sedang Disiapkan',
        'shipped'   => 'Dalam Perjalanan',
        'delivered' => 'Diterima',
        'cancelled' => 'Dibatalkan',
    ];

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="laporan_penjualan_' . $dateStart . '_' . $dateEnd . '.csv"');

    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM UTF-8

    fputcsv($out, ['No. Order', 'Customer', 'Email', 'Total', 'Pengiriman', 'Ongkir', 'Pembayaran', 'Status', 'Tanggal']);
    foreach ($exportOrders as $row) {
        fputcsv($out, [
            $row['order_number'],
            $row['customer_name'],
            $row['customer_email'],
            $row['total_amount'],
            $deliveryMethods[$row['delivery_method']] ?? $row['delivery_method'],
            $row['delivery_fee'],
            $paymentMethods[$row['payment_method']]  ?? $row['payment_method'],
            $statusLabelsMap[$row['status']]          ?? $row['status'],
            date('d/m/Y H:i', strtotime($row['created_at'])),
        ]);
    }
    fclose($out);
    exit;
}

// ── Statistik ringkasan ───────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) AS total_orders,
        COALESCE(SUM(CASE WHEN status != 'cancelled' THEN total_amount END), 0) AS total_revenue,
        COALESCE(SUM(CASE WHEN status = 'delivered'  THEN total_amount END), 0) AS delivered_revenue,
        SUM(CASE WHEN status = 'pending'   THEN 1 ELSE 0 END) AS pending_count,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered_count,
        SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_count,
        SUM(CASE WHEN status = 'preparing' THEN 1 ELSE 0 END) AS preparing_count
    FROM orders
    WHERE created_at BETWEEN ? AND ?
");
$stmt->execute([$dateStartFull, $dateEndFull]);
$summary = $stmt->fetch();

// ── Penjualan harian (untuk chart) ───────────────────────────────
$stmt = $pdo->prepare("
    SELECT DATE(created_at) AS tgl, COUNT(*) AS jumlah_order,
           COALESCE(SUM(CASE WHEN status != 'cancelled' THEN total_amount END), 0) AS pendapatan
    FROM orders
    WHERE created_at BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY tgl ASC
");
$stmt->execute([$dateStartFull, $dateEndFull]);
$dailySales = $stmt->fetchAll();

$chartLabels   = array_column($dailySales, 'tgl');
$chartRevenue  = array_column($dailySales, 'pendapatan');
$chartOrders   = array_column($dailySales, 'jumlah_order');

// ── Produk terlaris ───────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT p.name, SUM(oi.quantity) AS total_qty,
           SUM(oi.quantity * oi.price) AS total_revenue
    FROM order_items oi
    JOIN products p ON oi.product_id = p.id
    JOIN orders o ON oi.order_id = o.id
    WHERE o.created_at BETWEEN ? AND ? AND o.status != 'cancelled'
    GROUP BY p.id, p.name
    ORDER BY total_qty DESC
    LIMIT 10
");
$stmt->execute([$dateStartFull, $dateEndFull]);
$topProducts = $stmt->fetchAll();

// ── Distribusi status ─────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT status, COUNT(*) AS jumlah
    FROM orders
    WHERE created_at BETWEEN ? AND ?
    GROUP BY status
");
$stmt->execute([$dateStartFull, $dateEndFull]);
$statusDist = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

// ── Metode pembayaran ─────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT payment_method, COUNT(*) AS jumlah,
           SUM(CASE WHEN status != 'cancelled' THEN total_amount ELSE 0 END) AS total
    FROM orders
    WHERE created_at BETWEEN ? AND ?
    GROUP BY payment_method
");
$stmt->execute([$dateStartFull, $dateEndFull]);
$paymentDist = $stmt->fetchAll();

// ── Daftar order detail ───────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT o.*, u.name AS customer_name, u.email AS customer_email
    FROM orders o
    JOIN users u ON o.user_id = u.id
    WHERE o.created_at BETWEEN ? AND ?
    ORDER BY o.created_at DESC
");
$stmt->execute([$dateStartFull, $dateEndFull]);
$orders = $stmt->fetchAll();

$statusLabels = [
    'pending'   => 'Menunggu Konfirmasi',
    'confirmed' => 'Dikonfirmasi',
    'preparing' => 'Sedang Disiapkan',
    'shipped'   => 'Dalam Perjalanan',
    'delivered' => 'Diterima',
    'cancelled' => 'Dibatalkan',
];
$deliveryMethods = ['pickup' => 'Ambil di Toko', 'gojek' => 'GoJek', 'grab' => 'Grab'];
$paymentMethods  = ['cod' => 'Cash on Delivery', 'transfer' => 'Transfer Bank', 'ewallet' => 'E-Wallet'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laporan Penjualan - <?= SITE_NAME ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .container { max-width: 1200px; margin: 0 auto; }

        /* ── Header ── */
        .header {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            padding: 20px;
            border-radius: 15px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
        }

        /* ── Buttons ── */
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
        .btn-primary  { background: linear-gradient(45deg,#ff6b6b,#ee5a52); color: white; }
        .btn-success  { background: linear-gradient(45deg,#4caf50,#45a049); color: white; }
        .btn-secondary{ background: rgba(255,255,255,0.2); color: white; border: 1px solid rgba(255,255,255,0.3); }
        .btn-export   { background: linear-gradient(45deg,#2196f3,#1565c0); color: white; }
        .btn:hover    { transform: translateY(-2px); box-shadow: 0 5px 15px rgba(0,0,0,0.3); }
        .btn-sm       { padding: 7px 14px; font-size: 13px; }

        /* ── Card ── */
        .card {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
        }
        .card h2 { margin-bottom: 20px; font-size: 18px; }

        /* ── Filter bar ── */
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .filter-btn {
            padding: 8px 18px;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 20px;
            background: rgba(255,255,255,0.1);
            color: white;
            cursor: pointer;
            font-size: 13px;
            text-decoration: none;
            transition: all 0.3s;
        }
        .filter-btn:hover, .filter-btn.active {
            background: rgba(255,255,255,0.3);
            border-color: rgba(255,255,255,0.6);
        }
        .custom-date-inputs {
            display: flex;
            gap: 8px;
            align-items: center;
            flex-wrap: wrap;
        }
        .custom-date-inputs input[type="date"] {
            padding: 7px 12px;
            border: 1px solid rgba(255,255,255,0.3);
            border-radius: 10px;
            background: rgba(255,255,255,0.1);
            color: white;
            font-size: 13px;
        }
        .custom-date-inputs input[type="date"]::-webkit-calendar-picker-indicator {
            filter: invert(1);
        }

        /* ── Stat cards ── */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 20px;
            border: 1px solid rgba(255,255,255,0.2);
            color: white;
            text-align: center;
        }
        .stat-icon  { font-size: 32px; margin-bottom: 8px; }
        .stat-value { font-size: 22px; font-weight: bold; color: #ffd700; }
        .stat-label { font-size: 13px; opacity: 0.8; margin-top: 4px; }

        /* ── Charts ── */
        .charts-grid {
            display: grid;
            grid-template-columns: 2fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        .chart-wrapper {
            position: relative;
            width: 100%;
        }
        canvas { max-width: 100%; }

        /* ── Table ── */
        .table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 14px; }
        .table th, .table td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid rgba(255,255,255,0.15);
        }
        .table th { background: rgba(255,255,255,0.1); font-weight: 600; }
        .table tbody tr:hover { background: rgba(255,255,255,0.05); }

        /* ── Status badge ── */
        .badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: bold;
            text-transform: uppercase;
        }
        .badge-pending   { background: rgba(255,193,7,0.2);  color:#ffc107; border:1px solid #ffc107; }
        .badge-confirmed { background: rgba(33,150,243,0.2); color:#2196f3; border:1px solid #2196f3; }
        .badge-preparing { background: rgba(255,152,0,0.2);  color:#ff9800; border:1px solid #ff9800; }
        .badge-shipped   { background: rgba(156,39,176,0.2); color:#9c27b0; border:1px solid #9c27b0; }
        .badge-delivered { background: rgba(76,175,80,0.2);  color:#4caf50; border:1px solid #4caf50; }
        .badge-cancelled { background: rgba(244,67,54,0.2);  color:#f44336; border:1px solid #f44336; }

        /* ── Top products ── */
        .product-rank {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        .rank-num {
            width: 28px; height: 28px;
            border-radius: 50%;
            background: rgba(255,255,255,0.15);
            display: flex; align-items: center; justify-content: center;
            font-weight: bold; font-size: 13px; flex-shrink: 0;
        }
        .rank-num.top1 { background: #ffd700; color: #333; }
        .rank-num.top2 { background: #c0c0c0; color: #333; }
        .rank-num.top3 { background: #cd7f32; color: #fff; }
        .rank-info { flex: 1; }
        .rank-name { font-size: 14px; font-weight: 500; }
        .rank-meta { font-size: 12px; opacity: 0.7; margin-top: 2px; }
        .rank-revenue { font-weight: bold; color: #ffd700; font-size: 14px; white-space: nowrap; }

        /* ── Period label ── */
        .period-label {
            font-size: 13px;
            opacity: 0.7;
            margin-top: 5px;
        }

        /* ── Tabs (sama kayak admin.php) ── */
        .tabs { display: flex; margin-bottom: 20px; }
        .tab {
            padding: 15px 25px;
            background: rgba(255,255,255,0.1);
            border: none;
            color: white;
            cursor: pointer;
            border-radius: 10px 10px 0 0;
            margin-right: 5px;
            transition: all 0.3s ease;
        }
        .tab.active { background: rgba(255,255,255,0.2); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* ── Alert ── */
        .alert { padding: 15px 20px; border-radius: 10px; margin-bottom: 20px; font-weight: 500; }
        .alert-success { background: rgba(76,175,80,0.9); color: white; }
        .alert-error   { background: rgba(244,67,54,0.9); color: white; }

        /* ── Overflow ── */
        .table-scroll { overflow-x: auto; }

        @media (max-width: 900px) {
            .charts-grid { grid-template-columns: 1fr; }
            .header { flex-direction: column; gap: 15px; }
            .filter-bar { gap: 6px; }
        }
        @media (max-width: 600px) {
            .stats-grid { grid-template-columns: 1fr 1fr; }
        }
    </style>
</head>
<body>
<div class="container">
    <?php displayAlert(); ?>

    <!-- Header -->
    <div class="header">
        <div>
            <h1>📊 Laporan Penjualan</h1>
            <div class="period-label">
                <?= date('d M Y', strtotime($dateStart)) ?> – <?= date('d M Y', strtotime($dateEnd)) ?>
            </div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <a href="index.php"       class="btn btn-secondary">Kembali ke Toko</a>
            <a href="admin_reviews.php" class="btn btn-secondary">Reviews</a>
            <a href="admin.php"       class="btn btn-secondary">Panel Admin</a>
        </div>
    </div>

    <!-- Filter Periode -->
    <div class="card" style="padding:18px 25px;">
        <div class="filter-bar">
            <?php
            $periods = [
                'today'      => 'Hari Ini',
                'this_week'  => 'Minggu Ini',
                'this_month' => 'Bulan Ini',
                'last_month' => 'Bulan Lalu',
                'this_year'  => 'Tahun Ini',
                'custom'     => 'Kustom',
            ];
            foreach ($periods as $key => $label):
                $isActive = ($period === $key) ? 'active' : '';
                $href = "?period=$key";
                if ($key === 'custom') {
                    // handled by form below
                    continue;
                }
            ?>
                <a href="<?= $href ?>" class="filter-btn <?= $isActive ?>"><?= $label ?></a>
            <?php endforeach; ?>

            <!-- Custom date form -->
            <form method="GET" style="display:inline-flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <input type="hidden" name="period" value="custom">
                <div class="custom-date-inputs">
                    <p>Custom Date :</p>
                    <input type="date" name="date_start"
                           value="<?= $period === 'custom' ? $dateStart : date('Y-m-01') ?>"
                           max="<?= date('Y-m-d') ?>">
                    <span style="color:white;opacity:0.7;">s/d</span>
                    <input type="date" name="date_end"
                           value="<?= $period === 'custom' ? $dateEnd : date('Y-m-d') ?>"
                           max="<?= date('Y-m-d') ?>">
                    <button type="submit" class="btn btn-secondary btn-sm <?= $period === 'custom' ? 'active' : '' ?>">
                        Terapkan
                    </button>
                </div>
            </form>

            <!-- Export CSV -->
            <a href="?period=<?= $period ?>&date_start=<?= $dateStart ?>&date_end=<?= $dateEnd ?>&export=csv"
               class="btn btn-export btn-sm" style="margin-left:auto;">
                ⬇ Export CSV
            </a>
        </div>
    </div>

    <!-- Statistik Ringkasan -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">🛒</div>
            <div class="stat-value"><?= number_format($summary['total_orders']) ?></div>
            <div class="stat-label">Total Pesanan</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">💰</div>
            <div class="stat-value"><?= formatPrice($summary['total_revenue']) ?></div>
            <div class="stat-label">Total Pendapatan</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">✅</div>
            <div class="stat-value"><?= number_format((int)($summary['delivered_count'] ?? 0)) ?></div>
            <div class="stat-label">Pesanan Selesai</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">⏳</div>
            <div class="stat-value"><?= number_format((int)($summary['pending_count'] ?? 0)) ?></div>
            <div class="stat-label">Menunggu Konfirmasi</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">🔧</div>
            <div class="stat-value"><?= number_format((int)($summary['preparing_count'] ?? 0)) ?></div>
            <div class="stat-label">Sedang Disiapkan</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">❌</div>
            <div class="stat-value"><?= number_format((int)($summary['cancelled_count'] ?? 0)) ?></div>
            <div class="stat-label">Dibatalkan</div>
        </div>
        <div class="stat-card">
            <div class="stat-icon">📦</div>
            <div class="stat-value">
                <?php
                $convRate = $summary['total_orders'] > 0
                    ? round($summary['delivered_count'] / $summary['total_orders'] * 100, 1)
                    : 0;
                echo $convRate . '%';
                ?>
            </div>
            <div class="stat-label">Completion Rate</div>
        </div>
    </div>

    <!-- Charts -->
    <div class="charts-grid">
        <!-- Chart: Pendapatan Harian -->
        <div class="card">
            <h2>📈 Tren Pendapatan & Pesanan</h2>
            <div class="chart-wrapper" style="height:280px;">
                <canvas id="salesChart"></canvas>
            </div>
        </div>

        <!-- Chart: Distribusi Status -->
        <div class="card">
            <h2>🔘 Status Pesanan</h2>
            <div class="chart-wrapper" style="height:280px;display:flex;align-items:center;justify-content:center;">
                <canvas id="statusChart"></canvas>
            </div>
        </div>
    </div>

    <!-- Tabs: Produk Terlaris | Metode Pembayaran | Daftar Order -->
    <div class="tabs">
        <button class="tab active" onclick="showTab('tab-products', this)">🏆 Produk Terlaris</button>
        <button class="tab" onclick="showTab('tab-payment', this)">💳 Pembayaran</button>
        <button class="tab" onclick="showTab('tab-orders', this)">📋 Daftar Order</button>
    </div>

    <!-- Tab: Produk Terlaris -->
    <div id="tab-products" class="tab-content active">
        <div class="card">
            <h2>🏆 Produk Terlaris (Top 10)</h2>
            <?php if (empty($topProducts)): ?>
                <p style="text-align:center;opacity:0.7;padding:30px;">Belum ada data penjualan produk.</p>
            <?php else: ?>
                <?php foreach ($topProducts as $i => $prod): ?>
                    <div class="product-rank">
                        <div class="rank-num <?= $i === 0 ? 'top1' : ($i === 1 ? 'top2' : ($i === 2 ? 'top3' : '')) ?>">
                            <?= $i + 1 ?>
                        </div>
                        <div class="rank-info">
                            <div class="rank-name"><?= htmlspecialchars($prod['name']) ?></div>
                            <div class="rank-meta"><?= number_format($prod['total_qty']) ?> unit terjual</div>
                        </div>
                        <div class="rank-revenue"><?= formatPrice($prod['total_revenue']) ?></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tab: Metode Pembayaran -->
    <div id="tab-payment" class="tab-content">
        <div class="card">
            <h2>💳 Distribusi Metode Pembayaran</h2>
            <?php if (empty($paymentDist)): ?>
                <p style="text-align:center;opacity:0.7;padding:30px;">Belum ada data.</p>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Metode Pembayaran</th>
                                <th>Jumlah Order</th>
                                <th>Total Pendapatan</th>
                                <th>Proporsi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $totalOrders = array_sum(array_column($paymentDist, 'jumlah'));
                            foreach ($paymentDist as $row):
                                $pct = $totalOrders > 0 ? round($row['jumlah'] / $totalOrders * 100, 1) : 0;
                            ?>
                            <tr>
                                <td><?= $paymentMethods[$row['payment_method']] ?? $row['payment_method'] ?></td>
                                <td><?= number_format($row['jumlah']) ?></td>
                                <td><?= formatPrice($row['total']) ?></td>
                                <td>
                                    <div style="display:flex;align-items:center;gap:8px;">
                                        <div style="background:rgba(255,255,255,0.1);border-radius:4px;height:8px;width:100px;overflow:hidden;">
                                            <div style="background:#ffd700;height:8px;width:<?= $pct ?>%;border-radius:4px;"></div>
                                        </div>
                                        <span><?= $pct ?>%</span>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div style="height:220px;max-width:320px;margin:25px auto 0;">
                    <canvas id="paymentChart"></canvas>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tab: Daftar Order -->
    <div id="tab-orders" class="tab-content">
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;flex-wrap:wrap;gap:10px;">
                <h2>📋 Daftar Order (<?= count($orders) ?>)</h2>
                <a href="?period=<?= $period ?>&date_start=<?= $dateStart ?>&date_end=<?= $dateEnd ?>&export=csv"
                   class="btn btn-export btn-sm">⬇ Export CSV</a>
            </div>
            <?php if (empty($orders)): ?>
                <p style="text-align:center;opacity:0.7;padding:30px;">Tidak ada order pada periode ini.</p>
            <?php else: ?>
                <div class="table-scroll">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>No. Order</th>
                                <th>Customer</th>
                                <th>Pengiriman</th>
                                <th>Pembayaran</th>
                                <th>Total</th>
                                <th>Status</th>
                                <th>Tanggal</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($orders as $order): ?>
                            <tr>
                                <td style="color:#ffd700;font-weight:bold;"><?= htmlspecialchars($order['order_number']) ?></td>
                                <td>
                                    <div><?= htmlspecialchars($order['customer_name']) ?></div>
                                    <div style="font-size:12px;opacity:0.7;"><?= htmlspecialchars($order['customer_email']) ?></div>
                                </td>
                                <td><?= $deliveryMethods[$order['delivery_method']] ?? $order['delivery_method'] ?></td>
                                <td><?= $paymentMethods[$order['payment_method']]  ?? $order['payment_method'] ?></td>
                                <td style="font-weight:bold;"><?= formatPrice($order['total_amount']) ?></td>
                                <td>
                                    <span class="badge badge-<?= $order['status'] ?>">
                                        <?= $statusLabels[$order['status']] ?? $order['status'] ?>
                                    </span>
                                </td>
                                <td style="font-size:13px;"><?= date('d M Y H:i', strtotime($order['created_at'])) ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

</div><!-- /container -->

<script>
// ── Tab switcher ────────────────────────────────────────────────
function showTab(tabId, btn) {
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(el => el.classList.remove('active'));
    document.getElementById(tabId).classList.add('active');
    btn.classList.add('active');
}

// ── Data dari PHP ───────────────────────────────────────────────
const chartLabels  = <?= json_encode(array_map(fn($d) => date('d M', strtotime($d)), $chartLabels)) ?>;
const chartRevenue = <?= json_encode(array_map('floatval', $chartRevenue)) ?>;
const chartOrders  = <?= json_encode(array_map('intval',   $chartOrders)) ?>;

const statusData = <?= json_encode([
    'Menunggu'      => intval($statusDist['pending']   ?? 0),
    'Dikonfirmasi'  => intval($statusDist['confirmed'] ?? 0),
    'Disiapkan'     => intval($statusDist['preparing'] ?? 0),
    'Dikirim'       => intval($statusDist['shipped']   ?? 0),
    'Selesai'       => intval($statusDist['delivered'] ?? 0),
    'Dibatalkan'    => intval($statusDist['cancelled'] ?? 0),
]) ?>;

const paymentData = <?= json_encode(array_combine(
    array_map(fn($r) => $paymentMethods[$r['payment_method']] ?? $r['payment_method'], $paymentDist),
    array_column($paymentDist, 'jumlah')
)) ?>;

// ── Chart defaults ──────────────────────────────────────────────
Chart.defaults.color = 'rgba(255,255,255,0.75)';
Chart.defaults.font.family = "'Segoe UI', sans-serif";

// ── Sales & Revenue chart ───────────────────────────────────────
new Chart(document.getElementById('salesChart'), {
    type: 'bar',
    data: {
        labels: chartLabels,
        datasets: [
            {
                label: 'Pendapatan (Rp)',
                data: chartRevenue,
                backgroundColor: 'rgba(255, 215, 0, 0.45)',
                borderColor: '#ffd700',
                borderWidth: 2,
                borderRadius: 6,
                yAxisID: 'y',
            },
            {
                label: 'Jumlah Order',
                data: chartOrders,
                type: 'line',
                borderColor: '#4facfe',
                backgroundColor: 'rgba(79,172,254,0.15)',
                borderWidth: 2,
                pointBackgroundColor: '#4facfe',
                pointRadius: 4,
                fill: true,
                tension: 0.4,
                yAxisID: 'y1',
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { labels: { color: 'rgba(255,255,255,0.8)', font: { size: 12 } } },
            tooltip: {
                callbacks: {
                    label: ctx => {
                        if (ctx.dataset.yAxisID === 'y') {
                            return ' Rp ' + ctx.parsed.y.toLocaleString('id-ID');
                        }
                        return ' ' + ctx.parsed.y + ' order';
                    }
                }
            }
        },
        scales: {
            x: {
                grid: { color: 'rgba(255,255,255,0.08)' },
                ticks: { color: 'rgba(255,255,255,0.7)', font: { size: 11 } }
            },
            y: {
                position: 'left',
                grid: { color: 'rgba(255,255,255,0.08)' },
                ticks: {
                    color: 'rgba(255,255,255,0.7)',
                    font: { size: 11 },
                    callback: v => 'Rp ' + (v >= 1000000 ? (v/1000000).toFixed(1)+'jt' : (v/1000).toFixed(0)+'rb')
                }
            },
            y1: {
                position: 'right',
                grid: { drawOnChartArea: false },
                ticks: { color: '#4facfe', font: { size: 11 } }
            }
        }
    }
});

// ── Status doughnut ─────────────────────────────────────────────
new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: Object.keys(statusData),
        datasets: [{
            data: Object.values(statusData),
            backgroundColor: [
                'rgba(255,193,7,0.75)',
                'rgba(33,150,243,0.75)',
                'rgba(255,152,0,0.75)',
                'rgba(156,39,176,0.75)',
                'rgba(76,175,80,0.75)',
                'rgba(244,67,54,0.75)',
            ],
            borderColor: 'rgba(255,255,255,0.15)',
            borderWidth: 2,
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom',
                labels: { color: 'rgba(255,255,255,0.8)', font: { size: 11 }, padding: 12 }
            }
        }
    }
});

// ── Payment chart ───────────────────────────────────────────────
const paymentCtx = document.getElementById('paymentChart');
if (paymentCtx) {
    new Chart(paymentCtx, {
        type: 'pie',
        data: {
            labels: Object.keys(paymentData),
            datasets: [{
                data: Object.values(paymentData),
                backgroundColor: [
                    'rgba(79,172,254,0.75)',
                    'rgba(255,215,0,0.75)',
                    'rgba(76,175,80,0.75)',
                ],
                borderColor: 'rgba(255,255,255,0.2)',
                borderWidth: 2,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: { color: 'rgba(255,255,255,0.8)', font: { size: 12 }, padding: 14 }
                }
            }
        }
    });
}
</script>
</body>
</html>