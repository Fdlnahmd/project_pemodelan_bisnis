<?php
require_once 'config.php';

// Check if user is admin (simple check - in production, use proper role-based auth)
if (!isLoggedIn()) {
    redirect('auth.php');
}

$currentUser = getCurrentUser();
if ($currentUser['email'] !== 'admin@elektroshop.com') {
    showAlert('Akses ditolak. Hanya admin yang dapat mengakses halaman ini.', 'error');
    redirect('index.php');
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // DEBUG: Tambahkan debug info
    error_log("POST request received");
    error_log("Action: " . $action);
    error_log("POST data: " . print_r($_POST, true));

    if ($action === 'add' || $action === 'edit') {
        // Validasi input
        if (empty($_POST['name'])) {
            showAlert('Nama produk tidak boleh kosong', 'error');
            redirect('admin.php?action=add');
        }

        if (empty($_POST['price']) || $_POST['price'] <= 0) {
            showAlert('Harga produk tidak valid', 'error');
            redirect('admin.php?action=add');
        }

        if (empty($_POST['stock']) || $_POST['stock'] < 0) {
            showAlert('Stok produk tidak valid', 'error');
            redirect('admin.php?action=add');
        }

        if (empty($_POST['category_id'])) {
            showAlert('Kategori produk harus dipilih', 'error');
            redirect('admin.php?action=add');
        }

        $name = sanitize($_POST['name']);
        $description = sanitize($_POST['description']);
        $price = floatval($_POST['price']);
        $stock = intval($_POST['stock']);
        $category_id = intval($_POST['category_id']);

        $image_path = '';

        // Handle image upload
        if (isset($_FILES['image']) && $_FILES['image']['error'] === 0) {
            $allowed_types = ['jpg', 'jpeg', 'png', 'gif'];
            $file_info = pathinfo($_FILES['image']['name']);
            $file_ext = strtolower($file_info['extension']);

            if (in_array($file_ext, $allowed_types)) {
                $upload_dir = 'uploads/products/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }

                $file_name = uniqid() . '.' . $file_ext;
                $upload_path = $upload_dir . $file_name;

                if (move_uploaded_file($_FILES['image']['tmp_name'], $upload_path)) {
                    $image_path = $upload_path;
                } else {
                    error_log("Failed to upload image");
                }
            } else {
                showAlert('Format file tidak didukung. Gunakan JPG, PNG, atau GIF', 'error');
            }
        }

        if ($action === 'add') {
            try {
                // DEBUG: Log query
                error_log("Executing INSERT query");
                error_log("Values: name=$name, description=$description, price=$price, stock=$stock, category_id=$category_id, image=$image_path");

                $stmt = $pdo->prepare("INSERT INTO products (name, description, price, stock, category_id, image) VALUES (?, ?, ?, ?, ?, ?)");
                $result = $stmt->execute([$name, $description, $price, $stock, $category_id, $image_path]);

                if ($result) {
                    $lastId = $pdo->lastInsertId();
                    error_log("Product inserted successfully with ID: " . $lastId);
                    showAlert('Produk berhasil ditambahkan!', 'success');
                } else {
                    $errorInfo = $stmt->errorInfo();
                    error_log("Database error: " . print_r($errorInfo, true));
                    showAlert('Gagal menambahkan produk: ' . $errorInfo[2], 'error');
                }
            } catch (PDOException $e) {
                error_log("PDO Exception: " . $e->getMessage());
                showAlert('Error database: ' . $e->getMessage(), 'error');
            }
        } else {
            $id = intval($_POST['id']);

            try {
                // Update query - only update image if new one is uploaded
                if ($image_path) {
                    // Delete old image if exists
                    $stmt = $pdo->prepare("SELECT image FROM products WHERE id = ?");
                    $stmt->execute([$id]);
                    $old_product = $stmt->fetch();
                    if ($old_product && $old_product['image'] && file_exists($old_product['image'])) {
                        unlink($old_product['image']);
                    }

                    $stmt = $pdo->prepare("UPDATE products SET name=?, description=?, price=?, stock=?, category_id=?, image=? WHERE id=?");
                    $result = $stmt->execute([$name, $description, $price, $stock, $category_id, $image_path, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE products SET name=?, description=?, price=?, stock=?, category_id=? WHERE id=?");
                    $result = $stmt->execute([$name, $description, $price, $stock, $category_id, $id]);
                }

                if ($result) {
                    showAlert('Produk berhasil diperbarui!', 'success');
                } else {
                    $errorInfo = $stmt->errorInfo();
                    error_log("Update error: " . print_r($errorInfo, true));
                    showAlert('Gagal memperbarui produk: ' . $errorInfo[2], 'error');
                }
            } catch (PDOException $e) {
                error_log("PDO Exception on update: " . $e->getMessage());
                showAlert('Error database: ' . $e->getMessage(), 'error');
            }
        }

        redirect('admin.php');
    } elseif ($action === 'delete') {
        $id = intval($_POST['id']);

        try {
            // Delete image file if exists
            $stmt = $pdo->prepare("SELECT image FROM products WHERE id = ?");
            $stmt->execute([$id]);
            $product = $stmt->fetch();
            if ($product && $product['image'] && file_exists($product['image'])) {
                unlink($product['image']);
            }

            $stmt = $pdo->prepare("DELETE FROM products WHERE id = ?");
            if ($stmt->execute([$id])) {
                showAlert('Produk berhasil dihapus!', 'success');
            } else {
                showAlert('Gagal menghapus produk', 'error');
            }
        } catch (PDOException $e) {
            error_log("Delete error: " . $e->getMessage());
            showAlert('Error menghapus produk: ' . $e->getMessage(), 'error');
        }

        redirect('admin.php');
    }
}

// Get categories for select dropdown
try {
    $stmt = $pdo->query("SELECT * FROM categories ORDER BY name");
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching categories: " . $e->getMessage());
    $categories = [];
}

// Get products for listing
try {
    $stmt = $pdo->query("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id ORDER BY p.created_at DESC");
    $products = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching products: " . $e->getMessage());
    $products = [];
}

// Get specific product for editing
$edit_product = null;
if ($action === 'edit' && isset($_GET['id'])) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$_GET['id']]);
        $edit_product = $stmt->fetch();
    } catch (PDOException $e) {
        error_log("Error fetching product for edit: " . $e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - <?= SITE_NAME ?></title>
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

        .form-group select option {
            background: #333;
            color: white;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }

        .table th,
        .table td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid rgba(255, 255, 255, 0.2);
        }

        .table th {
            background: rgba(255, 255, 255, 0.1);
            font-weight: 600;
        }

        .product-image {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            object-fit: cover;
            background: linear-gradient(45deg, #4facfe, #00f2fe);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
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

        .file-upload {
            position: relative;
            display: inline-block;
            cursor: pointer;
            width: 100%;
        }

        .file-upload input[type="file"] {
            position: absolute;
            left: -9999px;
        }

        .file-upload-label {
            display: block;
            padding: 15px;
            border: 2px dashed rgba(255, 255, 255, 0.3);
            border-radius: 10px;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .file-upload-label:hover {
            border-color: rgba(255, 255, 255, 0.6);
            background: rgba(255, 255, 255, 0.05);
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

        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                gap: 15px;
            }

            .table {
                font-size: 14px;
            }

            .table th,
            .table td {
                padding: 10px;
            }
        }
    </style>
</head>

<body>
    <div class="container">
        <?php displayAlert(); ?>

        <div class="header">
            <h1>Admin Panel - <?= SITE_NAME ?></h1>
            <div>
                <a href="index.php" class="btn btn-secondary">Kembali ke Toko</a>
                <a href="admin_reviews.php" class="btn btn-secondary">Reviews</a>
                <a href="auth.php?action=logout" class="btn btn-danger">Logout</a>
            </div>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="showTab('products')">Kelola Produk</button>
            <button class="tab" onclick="showTab('add-product')">Tambah Produk</button>
        </div>

        <div id="products" class="tab-content active">
            <div class="card">
                <h2>Daftar Produk</h2>
                <div style="overflow-x: auto;">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Gambar</th>
                                <th>Nama</th>
                                <th>Kategori</th>
                                <th>Harga</th>
                                <th>Stok</th>
                                <th>Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($products)): ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; padding: 30px;">
                                        Belum ada produk yang ditambahkan
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($products as $product): ?>
                                    <tr>
                                        <td>
                                            <?php if ($product['image'] && file_exists($product['image'])): ?>
                                                <img src="<?= htmlspecialchars($product['image']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" class="product-image">
                                            <?php else: ?>
                                                <div class="product-image">📦</div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($product['name']) ?></td>
                                        <td><?= htmlspecialchars($product['category_name']) ?></td>
                                        <td><?= formatPrice($product['price']) ?></td>
                                        <td><?= $product['stock'] ?></td>
                                        <td>
                                            <a href="?action=edit&id=<?= $product['id'] ?>" class="btn btn-primary">Edit</a>
                                            <form method="POST" style="display: inline;" onsubmit="return confirm('Yakin ingin menghapus produk ini?')">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="id" value="<?= $product['id'] ?>">
                                                <button type="submit" value="delete" class="btn btn-danger">Hapus</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="add-product" class="tab-content">
            <div class="card">
                <h2><?= $edit_product ? 'Edit Produk' : 'Tambah Produk Baru' ?></h2>
               <form method="POST" enctype="multipart/form-data" 
      action="?action=<?= $edit_product ? 'edit' : 'add' ?>">
                    <?php if ($edit_product): ?>
                        <input type="hidden" name="id" value="<?= $edit_product['id'] ?>">
                    <?php endif; ?>

                    <div class="form-group">
                        <label for="name">Nama Produk *</label>
                        <input type="text" id="name" name="name" required
                            value="<?= $edit_product ? htmlspecialchars($edit_product['name']) : '' ?>"
                            placeholder="Masukkan nama produk">
                    </div>

                    <div class="form-group">
                        <label for="description">Deskripsi</label>
                        <textarea id="description" name="description" rows="4"
                            placeholder="Deskripsi produk"><?= $edit_product ? htmlspecialchars($edit_product['description']) : '' ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="price">Harga (Rp) *</label>
                        <input type="number" id="price" name="price" step="0.01" required min="0"
                            value="<?= $edit_product ? $edit_product['price'] : '' ?>"
                            placeholder="0">
                    </div>

                    <div class="form-group">
                        <label for="stock">Stok *</label>
                        <input type="number" id="stock" name="stock" required min="0"
                            value="<?= $edit_product ? $edit_product['stock'] : '' ?>"
                            placeholder="0">
                    </div>

                    <div class="form-group">
                        <label for="category_id">Kategori *</label>
                        <select id="category_id" name="category_id" required>
                            <option value="">Pilih Kategori</option>
                            <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['id'] ?>"
                                    <?= $edit_product && $edit_product['category_id'] == $category['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($category['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label>Gambar Produk</label>
                        <?php if ($edit_product && $edit_product['image']): ?>
                            <div style="margin-bottom: 15px;">
                                <img src="<?= htmlspecialchars($edit_product['image']) ?>"
                                    alt="Current image"
                                    style="max-width: 200px; border-radius: 10px;">
                            </div>
                        <?php endif; ?>
                        <div class="file-upload">
                            <input type="file" id="image" name="image" accept="image/*">
                            <label for="image" class="file-upload-label">
                                📸 Pilih Gambar (JPG, PNG, GIF)
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-success" style="width: 100%;">
                        <?= $edit_product ? 'Update Produk' : 'Tambah Produk' ?>
                    </button>
                </form>
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

        // File upload preview
        document.getElementById('image').addEventListener('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                const label = document.querySelector('.file-upload-label');
                label.textContent = `📸 ${file.name}`;
            }
        });

        // Auto-switch to add-product tab if editing
        <?php if ($action === 'edit'): ?>
            showTab('add-product');
        <?php endif; ?>
    </script>
</body>

</html>