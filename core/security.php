<?php
// Session Isolation & Setup (Aman dari multiple execution)
if (session_status() === PHP_SESSION_NONE) {
    @ini_set('session.cookie_httponly', 1); // Cegah XSS mencuri session
    @ini_set('session.use_only_cookies', 1); // Hanya gunakan cookie untuk session
    @session_start(); // Menggunakan @ agar strict warning tidak menyebabkan Error 500
}

// ==========================================
// 1. SISTEM CACHING (SaaS Optimization)
// ==========================================
// Mencegah Error 500 "Cannot redeclare class CacheManager"
if (!class_exists('CacheManager')) {
    class CacheManager {
        private static $cache_dir = __DIR__ . '/cache/';

        private static function init() {
            if (!is_dir(self::$cache_dir)) {
                @mkdir(self::$cache_dir, 0755, true);
            }
        }

        public static function get($key, $ttl = 3600) {
            self::init();
            $file = self::$cache_dir . md5($key) . '.cache';
            if (file_exists($file)) {
                if (filemtime($file) > (time() - $ttl)) {
                    return unserialize(file_get_contents($file));
                }
                @unlink($file);
            }
            return false;
        }

        public static function set($key, $data) {
            self::init();
            $file = self::$cache_dir . md5($key) . '.cache';
            @file_put_contents($file, serialize($data), LOCK_EX);
        }

        public static function invalidate($tag) {
            self::init();
            $files = glob(self::$cache_dir . '*');
            
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        @unlink($file);
                    }
                }
            }
        }
    }
}

// ==========================================
// 2. CSRF PROTECTION (Keamanan Form)
// ==========================================
if (!function_exists('generate_csrf_token')) {
    function generate_csrf_token() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}

if (!function_exists('verify_csrf_token')) {
    function verify_csrf_token($token) {
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
            set_flash("Sesi keamanan telah kedaluwarsa. Silakan muat ulang halaman dan coba lagi.", "error");
            $referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '/';
            header("Location: " . $referer);
            exit;
        }
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field() {
        $token = generate_csrf_token();
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
    }
}

// ==========================================
// 3. XSS MITIGATION & SANITIZATION
// ==========================================
if (!function_exists('sanitize_input')) {
    function sanitize_input($data) {
        return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
    }
}

// ==========================================
// 4. IDOR PREVENTION (Pencegahan Akses Lintas Tenant)
// ==========================================
if (!function_exists('verify_ownership')) {
    function verify_ownership($pdo, $table, $id, $tenant_id) {
        // FIX ERROR: Menambahkan tabel baru ke whitelist keamanan (reservations, categories, expenses)
        $allowed_tables = ['products', 'orders', 'cashiers', 'stock_logs', 'reservations', 'categories', 'expenses'];
        
        if (!in_array($table, $allowed_tables)) {
            die("Security Error: Akses tabel tidak sah.");
        }

        $stmt = $pdo->prepare("SELECT id FROM {$table} WHERE id = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$id, $tenant_id]);
        
        if (!$stmt->fetch()) {
            http_response_code(403);
            die("Security Error: Akses Ditolak (IDOR Violation). Anda tidak memiliki hak atas data ini.");
        }
        return true;
    }
}

// ==========================================
// 5. ANTI-MALWARE & RCE SECURE UPLOAD
// ==========================================
if (!function_exists('secure_upload')) {
    function secure_upload($file_array, $destination_dir) {
        if ($file_array['error'] !== UPLOAD_ERR_OK) {
            return ['status' => false, 'message' => 'Terjadi kesalahan saat mengunggah file.'];
        }

        $max_size = 5 * 1024 * 1024; // 5MB
        if ($file_array['size'] > $max_size) {
            return ['status' => false, 'message' => 'Ukuran file maksimal 5MB.'];
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file_array['tmp_name']);
        finfo_close($finfo);

        $mime_to_ext = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp'
        ];

        if (!array_key_exists($mime, $mime_to_ext)) {
            return ['status' => false, 'message' => 'Hanya file JPG, PNG, atau WEBP yang diperbolehkan.'];
        }

        $safe_extension = $mime_to_ext[$mime];
        $filename = uniqid('img_', true) . '.' . $safe_extension;
        
        if (!is_dir($destination_dir)) {
            @mkdir($destination_dir, 0755, true);
            @file_put_contents($destination_dir . '/.htaccess', "<FilesMatch \"\\.(php|phtml|php5|shtml)$\">\nOrder Allow,Deny\nDeny from all\n</FilesMatch>");
        }
        
        $filepath = rtrim($destination_dir, '/') . '/' . $filename;

        if (move_uploaded_file($file_array['tmp_name'], $filepath)) {
            return ['status' => true, 'filename' => $filename];
        }
        
        return ['status' => false, 'message' => 'Sistem gagal menyimpan file. Pastikan permission folder benar.'];
    }
}

// ==========================================
// 6. FLASH MESSAGES UTILITY
// ==========================================
if (!function_exists('set_flash')) {
    function set_flash($message, $type = 'success') {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    }
}

if (!function_exists('display_flash')) {
    function display_flash() {
        if (isset($_SESSION['flash'])) {
            
            if (is_array($_SESSION['flash'])) {
                $msg = $_SESSION['flash']['message'] ?? 'Pesan sistem';
                $type = $_SESSION['flash']['type'] ?? 'success';
            } else {
                $msg = $_SESSION['flash']; 
                $type = 'success';
            }

            $bg = ($type === 'success') ? 'bg-green-100 text-green-800 border-green-200' : 'bg-red-100 text-red-800 border-red-200';
            $icon = ($type === 'success') 
                ? '<svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>'
                : '<svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>';
            
            echo "<div id='flash-alert' class='flex items-center p-4 mb-4 text-sm rounded-lg border shadow-sm {$bg} transition-all duration-500' role='alert'>
                    {$icon} <span class='font-medium'>{$msg}</span>
                  </div>
                  <script>
                    setTimeout(() => {
                        let alert = document.getElementById('flash-alert');
                        if(alert) {
                            alert.style.opacity = '0';
                            setTimeout(() => alert.remove(), 500);
                        }
                    }, 4000);
                  </script>";
            unset($_SESSION['flash']);
        }
    }
}
?>