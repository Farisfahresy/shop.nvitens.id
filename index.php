<?php
// Mencegah Session Fixation & Mengamankan Cookie
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    ini_set('session.cookie_httponly', 1); 
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Lax'); 
    session_start();
}

// Include Konfigurasi & Keamanan Core
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/core/security.php';

// ==========================================
// 1. ROUTING PINTAR (FRONT CONTROLLER)
// ==========================================
$route = isset($_GET['route']) ? rtrim($_GET['route'], '/') : '';
$route_parts = explode('/', $route);
$base_route = $route_parts[0];

// ==========================================
// 2. API ENDPOINTS (AJAX REAL-TIME POLLING)
// ==========================================
if ($base_route === 'api') {
    $api_action = isset($route_parts[1]) ? $route_parts[1] : '';
    
    if ($api_action === 'check_new_orders') {
        header('Content-Type: application/json');
        if (!isset($_SESSION['tenant_id'])) { 
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']); exit; 
        }
        
        $tenant_id = (int)$_SESSION['tenant_id'];
        $last_check = isset($_GET['last_check']) ? sanitize_input($_GET['last_check']) : date('Y-m-d H:i:s', strtotime('-1 minute'));
        
        try {
            // Cek Pesanan Baru
            $stmtOrder = $pdo->prepare("SELECT COUNT(id) FROM orders WHERE tenant_id = ? AND created_at > ? AND status = 'waiting'");
            $stmtOrder->execute([$tenant_id, $last_check]);
            $new_orders = $stmtOrder->fetchColumn();
            
            // Cek Reservasi Baru
            $stmtRes = $pdo->prepare("SELECT COUNT(id) FROM reservations WHERE tenant_id = ? AND created_at > ? AND status = 'waiting'");
            $stmtRes->execute([$tenant_id, $last_check]);
            $new_reservations = $stmtRes->fetchColumn();

            echo json_encode([
                'status' => 'success', 
                'new_orders' => (int)$new_orders, 
                'new_reservations' => (int)$new_reservations,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error']);
        }
        exit;
    }
    exit;
}

// ==========================================
// 3. ROUTING LANDING PAGE (ROOT)
// ==========================================
if ($base_route === '') {
    require_once __DIR__ . '/views/landing.php';
    exit;
}

// ==========================================
// 4. ROUTING AUTENTIKASI (LOGIN & REGISTER)
// ==========================================
if ($base_route === 'auth') {
    $auth_action = isset($route_parts[1]) ? $route_parts[1] : '';
    
    if ($auth_action === 'logout') {
        session_unset();
        session_destroy();
        header("Location: /"); exit;
    }
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf_token($_POST['csrf_token'] ?? '');
        
        // --- PROSES REGISTRASI TENANT BARU ---
        if ($auth_action === 'register') {
            $shop_name = sanitize_input($_POST['shop_name']);
            $owner_name = sanitize_input($_POST['owner_name']);
            $whatsapp = sanitize_input($_POST['whatsapp']);
            $email = sanitize_input($_POST['email'] ?? '');
            $address = sanitize_input($_POST['address']);
            $slug = preg_replace('/[^a-z0-9]/', '', strtolower($_POST['slug'])); 
            
            if(empty($slug)) {
                set_flash("Format URL tidak valid. Gunakan huruf dan angka tanpa spasi.", "error");
                header("Location: /#portal"); exit;
            }

            // Validasi URL Unik
            $stmtCek = $pdo->prepare("SELECT id FROM tenants WHERE slug = ?");
            $stmtCek->execute([$slug]);
            if ($stmtCek->fetch()) {
                set_flash("URL toko '{$slug}' sudah dipakai. Silakan pilih URL lain.", "error");
                header("Location: /#portal"); exit;
            }
            
            try {
                $pdo->beginTransaction();
                // Simpan user tanpa password (status pending)
                $stmtUser = $pdo->prepare("INSERT INTO users (name, email, role, status) VALUES (?, ?, 'tenant', 'pending')");
                $stmtUser->execute([$owner_name, $email]);
                $user_id = $pdo->lastInsertId();
                
                // Simpan data toko (tenant)
                $stmtTenant = $pdo->prepare("INSERT INTO tenants (user_id, shop_name, slug, whatsapp, address) VALUES (?, ?, ?, ?, ?)");
                $stmtTenant->execute([$user_id, $shop_name, $slug, $whatsapp, $address]);
                
                $pdo->commit();
                // Menampilkan instruksi sukses sesuai alur bisnis baru
                set_flash("Pendaftaran Berhasil! Status Anda PENDING. Segera hubungi Superadmin via WA: 082232067743 atau IG: @nvitens.id untuk aktivasi & mendapatkan akses login.", "success");
                header("Location: /#portal"); exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                set_flash("Terjadi kesalahan sistem saat mendaftar.", "error");
                header("Location: /#portal"); exit;
            }
        }
        
        // --- PROSES LOGIN (SUPERADMIN, OWNER, KASIR) ---
        if ($auth_action === 'login') {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            
            // Rate Limiting Check
            if (function_exists('checkRateLimit') && !checkRateLimit($ip)) {
                set_flash("Akses diblokir sementara karena terlalu banyak percobaan gagal (Tunggu 5 Menit).", "error");
                header("Location: /#portal"); exit;
            }
            
            $username = sanitize_input($_POST['username']);
            $password = $_POST['password'];
            
            // 1. Cek di tabel Users (Superadmin / Owner)
            $stmtUser = $pdo->prepare("SELECT id, username, password, role, status FROM users WHERE username = ?");
            $stmtUser->execute([$username]);
            $user = $stmtUser->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] !== 'active') {
                    set_flash("Akun Anda berstatus '{$user['status']}'. Silakan hubungi admin.", "error");
                    header("Location: /#portal"); exit;
                }
                
                if (function_exists('clearLoginAttempts')) clearLoginAttempts($ip);
                session_regenerate_id(true); 
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                
                if ($user['role'] === 'superadmin') {
                    header("Location: /superadmin/dashboard"); exit;
                } elseif ($user['role'] === 'tenant') {
                    $stmtT = $pdo->prepare("SELECT id FROM tenants WHERE user_id = ?");
                    $stmtT->execute([$user['id']]);
                    $_SESSION['tenant_id'] = $stmtT->fetchColumn();
                    header("Location: /tenant/dashboard"); exit;
                }
            } else {
                // 2. Cek di tabel Cashiers (Kasir Pegawai)
                $stmtCashier = $pdo->prepare("SELECT id, tenant_id, name, password FROM cashiers WHERE username = ? AND is_active = 1");
                $stmtCashier->execute([$username]);
                $cashier = $stmtCashier->fetch();
                
                if ($cashier && password_verify($password, $cashier['password'])) {
                    if (function_exists('clearLoginAttempts')) clearLoginAttempts($ip);
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $cashier['id'];
                    $_SESSION['username'] = $cashier['name']; 
                    $_SESSION['role'] = 'cashier';
                    $_SESSION['tenant_id'] = $cashier['tenant_id'];
                    header("Location: /tenant/pos"); exit;
                }
            }
            
            // Gagal Login
            if (function_exists('recordFailedLogin')) recordFailedLogin($ip);
            set_flash("Username atau Password salah!", "error");
            header("Location: /#portal"); exit;
        }
    }
    header("Location: /"); exit;
}

// ==========================================
// 5. ROUTING SUPERADMIN (PROTECTED)
// ==========================================
if ($base_route === 'superadmin') {
    if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
        set_flash("Akses ditolak. Silakan login sebagai Superadmin.", "error");
        header("Location: /#portal"); exit;
    }
    require_once __DIR__ . '/views/superadmin.php';
    exit;
}

// ==========================================
// 6. ROUTING TENANT ADMIN & POS (PROTECTED)
// ==========================================
if ($base_route === 'tenant') {
    if (!isset($_SESSION['role']) || ($_SESSION['role'] !== 'tenant' && $_SESSION['role'] !== 'cashier')) {
        set_flash("Sesi berakhir. Silakan login kembali.", "error");
        header("Location: /#portal"); exit;
    }
    
    // RBAC: Batasi akses Kasir
    $sub = isset($route_parts[1]) ? $route_parts[1] : 'dashboard';
    $kasir_allowed = ['dashboard', 'pos', 'pos_checkout', 'orders', 'order_status', 'reservations', 'reservation_status'];
    
    if ($_SESSION['role'] === 'cashier' && !in_array($sub, $kasir_allowed)) {
        set_flash("Akses ditolak. Anda login sebagai Kasir.", "error");
        header("Location: /tenant/pos"); exit;
    }
    
    // Ambil Data Konfigurasi Tenant Global
    $stmtShop = $pdo->prepare("SELECT * FROM tenants WHERE id = ?");
    $stmtShop->execute([$_SESSION['tenant_id']]);
    $tenant_data = $stmtShop->fetch();
    
    require_once __DIR__ . '/views/tenant_admin.php';
    exit;
}

// ==========================================
// 7. ROUTING STOREFRONT (ETALASE PUBLIK CUSTOMER)
// ==========================================
if (!empty($base_route)) {
    // Cari Toko Berdasarkan Slug URL
    $stmtSlug = $pdo->prepare("SELECT * FROM tenants WHERE slug = ?");
    $stmtSlug->execute([$base_route]);
    $tenant_data = $stmtSlug->fetch();
    
    if ($tenant_data) {
        // Blokir akses pembeli jika toggle "Toko Buka" dimatikan oleh owner
        if ($tenant_data['is_open'] == 0) {
            http_response_code(503);
            die("
            <!DOCTYPE html><html lang='id'>
            <head>
                <meta charset='UTF-8'><meta name='viewport' content='width=device-width, initial-scale=1.0'>
                <title>Toko Sedang Tutup</title>
                <style>body{font-family:sans-serif;display:flex;flex-direction:column;align-items:center;justify-content:center;height:100vh;background:#f8fafc;margin:0;text-align:center;}h1{color:#0f172a;font-size:2rem;margin-bottom:10px;}p{color:#64748b;max-width:400px;}</style>
            </head>
            <body>
                <svg width='64' height='64' viewBox='0 0 24 24' fill='none' stroke='#cbd5e1' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'><rect width='18' height='18' x='3' y='3' rx='2'/><path d='m9 9 6 6'/><path d='m15 9-6 6'/></svg>
                <h1>Toko Sedang Tutup</h1>
                <p>Maaf, <b>" . htmlspecialchars($tenant_data['shop_name']) . "</b> saat ini sedang tutup dan tidak menerima pesanan online. Silakan kembali lagi nanti.</p>
            </body>
            </html>
            ");
        }
        
        // Panggil View Toko Customer
        require_once __DIR__ . '/views/tenant_shop.php';
        exit;
    } else {
        // Jika Slug tidak ditemukan
        http_response_code(404);
        die("<h1>404 Not Found</h1><p>Toko tidak ditemukan. Periksa kembali ejaan URL Anda.</p><a href='/'>Kembali ke Nvitens</a>");
    }
}
?>