<?php
require_once 'config.php';

$action = $_GET['action'] ?? 'login';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'login') {
        $email = sanitize($_POST['email']);
        $password = $_POST['password'];

        if (empty($email) || empty($password)) {
            showAlert('Email dan password harus diisi', 'error');
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                showAlert('Login berhasil!', 'success');
                redirect('index.php');
            } else {
                showAlert('Email atau password salah', 'error');
            }
        }
    } elseif ($action === 'register') {
        $name = sanitize($_POST['name']);
        $email = sanitize($_POST['email']);
        $password = $_POST['password'];
        $phone = sanitize($_POST['phone']);
        $address = sanitize($_POST['address']);

        if (empty($name) || empty($email) || empty($password) || empty($address)) {
            showAlert('Semua field harus diisi', 'error');
        } else {
            // Check if email already exists
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);

            if ($stmt->fetch()) {
                showAlert('Email sudah terdaftar', 'error');
            } else {
                $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

                $stmt = $pdo->prepare("INSERT INTO users (name, email, password, phone, address) VALUES (?, ?, ?, ?, ?)");

                if ($stmt->execute([$name, $email, $hashedPassword, $phone, $address])) {
                    $_SESSION['user_id'] = $pdo->lastInsertId();
                    showAlert('Pendaftaran berhasil!', 'success');
                    redirect('index.php');
                } else {
                    showAlert('Terjadi kesalahan saat mendaftar', 'error');
                }
            }
        }
    }
}

// Handle logout
if ($action === 'logout') {
    session_destroy();
    redirect('index.php');
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $action === 'register' ? 'Daftar' : 'Login' ?> - <?= SITE_NAME ?></title>
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
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .auth-container {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(20px);
            border-radius: 20px;
            padding: 40px;
            border: 1px solid rgba(255, 255, 255, 0.2);
            width: 100%;
            max-width: 450px;
            color: white;
            box-shadow: 0 8px 32px rgba(31, 38, 135, 0.37);
        }

        .logo {
            text-align: center;
            font-size: 28px;
            font-weight: bold;
            margin-bottom: 10px;
        }

        .logo::before {
            content: "⚡";
            margin-right: 10px;
        }

        .subtitle {
            text-align: center;
            opacity: 0.8;
            margin-bottom: 30px;
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
        .form-group textarea {
            width: 100%;
            padding: 15px;
            border: 2px solid rgba(255, 255, 255, 0.3);
            border-radius: 10px;
            background: rgba(255, 255, 255, 0.1);
            color: white;
            font-size: 16px;
            transition: all 0.3s ease;
        }

        .form-group input::placeholder,
        .form-group textarea::placeholder {
            color: rgba(255, 255, 255, 0.6);
        }

        .form-group input:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: rgba(255, 255, 255, 0.6);
            background: rgba(255, 255, 255, 0.15);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }

        .btn {
            width: 100%;
            padding: 15px;
            border: none;
            border-radius: 25px;
            background: linear-gradient(45deg, #ff6b6b, #ee5a52);
            color: white;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 20px;
        }

        .btn:hover {
            background: linear-gradient(45deg, #ff5252, #e53935);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(255, 107, 107, 0.4);
        }

        .btn:active {
            transform: translateY(0);
        }

        .switch-form {
            text-align: center;
            opacity: 0.8;
        }

        .switch-form a {
            color: white;
            text-decoration: none;
            font-weight: bold;
            padding: 5px 10px;
            border-radius: 5px;
            transition: all 0.3s ease;
        }

        .switch-form a:hover {
            background: rgba(255, 255, 255, 0.1);
            text-decoration: underline;
        }

        .back-to-home {
            text-align: center;
            margin-top: 20px;
        }

        .back-to-home a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            font-size: 14px;
        }

        .back-to-home a:hover {
            color: white;
            text-decoration: underline;
        }

        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .alert.success {
            background: rgba(76, 175, 80, 0.2);
            border: 1px solid rgba(76, 175, 80, 0.5);
            color: #a5d6a7;
        }

        .alert.error {
            background: rgba(244, 67, 54, 0.2);
            border: 1px solid rgba(244, 67, 54, 0.5);
            color: #ef9a9a;
        }

        @media (max-width: 480px) {
            .auth-container {
                padding: 30px 20px;
                margin: 10px;
            }

            .logo {
                font-size: 24px;
            }
        }
    </style>
</head>

<body>
    <div class="auth-container">
        <div class="logo">ElektroShop Jakarta</div>
        <div class="subtitle">Part Elektronika Terlengkap</div>

        <?php if (isset($_SESSION['alert'])): ?>
            <div class="alert <?= $_SESSION['alert']['type'] ?>">
                <?= $_SESSION['alert']['message'] ?>
            </div>
            <?php unset($_SESSION['alert']); ?>
        <?php endif; ?>

        <?php if ($action === 'login'): ?>
            <form method="POST">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" placeholder="Masukkan email Anda" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Masukkan password Anda" required>
                </div>

                <button type="submit" class="btn">Masuk</button>
            </form>

            <div class="switch-form">
                Belum punya akun? <a href="?action=register">Daftar sekarang</a>
            </div>

        <?php else: ?>
            <form method="POST">
                <div class="form-group">
                    <label for="name">Nama Lengkap</label>
                    <input type="text" id="name" name="name" placeholder="Masukkan nama lengkap" required>
                </div>

                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" placeholder="Masukkan email Anda" required>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <input type="password" id="password" name="password" placeholder="Buat password" required>
                </div>

                <div class="form-group">
                    <label for="phone">Nomor Telepon</label>
                    <input type="tel" id="phone" name="phone" placeholder="Masukkan nomor telepon">
                </div>

                <div class="form-group">
                    <label for="address">Alamat</label>
                    <textarea id="address" name="address" placeholder="Masukkan alamat lengkap" required></textarea>
                </div>

                <button type="submit" class="btn">Daftar</button>
            </form>

            <div class="switch-form">
                Sudah punya akun? <a href="?action=login">Masuk di sini</a>
            </div>
        <?php endif; ?>

        <div class="back-to-home">
            <a href="index.php">← Kembali ke Beranda</a>
        </div>
    </div>
</body>

</html>