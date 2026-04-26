<?php
if (!defined('PDO::ATTR_ERRMODE')) exit; 

// ==========================================
// LOGIKA PEMROSESAN LOGIN (SELF-CONTAINED)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_submit'])) {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $username = sanitize_input($_POST['username']);
    $password = $_POST['password'];

    // 1. Cek di tabel users (Superadmin / Owner Tenant)
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        if ($user['status'] !== 'active') {
            set_flash("Akun Anda belum aktif atau sedang ditangguhkan. Hubungi Superadmin.", "error");
            header("Location: /#portal"); exit;
        }

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['username'] = $user['name'];
        
        if ($user['role'] === 'tenant') {
            $stmtT = $pdo->prepare("SELECT id, slug FROM tenants WHERE user_id = ?");
            $stmtT->execute([$user['id']]);
            $tenant = $stmtT->fetch();
            if($tenant) {
                $_SESSION['tenant_id'] = $tenant['id'];
                $_SESSION['tenant_slug'] = $tenant['slug'];
            }
            header("Location: /tenant/dashboard"); exit;
        } else {
            header("Location: /superadmin/dashboard"); exit;
        }
    }

    // 2. Cek di tabel cashiers (Pegawai Kasir)
    $stmtC = $pdo->prepare("SELECT * FROM cashiers WHERE username = ?");
    $stmtC->execute([$username]);
    $cashier = $stmtC->fetch();

    if ($cashier && password_verify($password, $cashier['password'])) {
        // Cek apakah tenant toko masih buka/aktif
        $stmtTC = $pdo->prepare("SELECT slug, is_open FROM tenants WHERE id = ?");
        $stmtTC->execute([$cashier['tenant_id']]);
        $tenantKasir = $stmtTC->fetch();

        $_SESSION['user_id'] = $cashier['id'];
        $_SESSION['role'] = 'cashier'; // Bedakan role kasir dengan owner
        $_SESSION['username'] = $cashier['name'];
        $_SESSION['tenant_id'] = $cashier['tenant_id'];
        $_SESSION['tenant_slug'] = $tenantKasir['slug'] ?? '';

        header("Location: /tenant/pos"); exit;
    }

    // JIKA KEDUANYA GAGAL (Salah Password/Username)
    set_flash("Username atau password yang Anda masukkan salah!", "error");
    header("Location: /#portal"); 
    exit;
}

// Persiapan URL Global
$current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Shop.Nvitens - Enterprise SaaS POS & Ordering</title>
    <meta name="description" content="Tingkatkan omset bisnis F&B Anda dengan Shop.Nvitens. Platform terpadu untuk pemesanan online, POS, Manajemen Stok Presisi, dan Laporan Keuangan.">
    <meta name="theme-color" content="#f59e0b">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        tailwind.config = {
            theme: { extend: { fontFamily: { sans: ['"Plus Jakarta Sans"', 'sans-serif'] }, colors: { brand: { 50: '#fef3c7', 100: '#fde68a', 500: '#f59e0b', 600: '#d97706', 900: '#78350f' } }, animation: { 'float': 'float 6s ease-in-out infinite' }, keyframes: { float: { '0%, 100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-20px)' } } } } }
        }
    </script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; -webkit-font-smoothing: antialiased; }
        .glass-nav { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); border-bottom: 1px solid rgba(226, 232, 240, 0.8); }
        .gradient-text { background-clip: text; -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
    </style>
</head>
<body class="bg-[#FAFAFA] text-slate-800">

<div class="w-full overflow-hidden relative">

    <nav class="glass-nav fixed top-0 w-full z-50 transition-all duration-300" id="navbar">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16 md:h-20">
                <div class="flex items-center gap-2 group cursor-pointer" onclick="window.scrollTo(0,0)">
                    <div class="w-8 h-8 md:w-10 md:h-10 bg-gradient-to-br from-brand-500 to-orange-500 rounded-lg md:rounded-xl text-white flex items-center justify-center shadow-lg"><i data-lucide="zap" class="w-5 h-5"></i></div>
                    <span class="font-extrabold text-xl md:text-2xl tracking-tight text-slate-900">ShopNvitens.</span>
                </div>
                <div class="hidden md:flex items-center space-x-8">
                    <a href="#fitur" class="text-sm font-semibold text-slate-600 hover:text-brand-500 transition">Fitur Pro</a>
                    <a href="#portal" class="text-sm font-bold text-slate-900 hover:text-brand-600 transition">Log in Dashboard</a>
                </div>
                <div>
                    <a href="#portal" class="bg-slate-900 hover:bg-slate-800 text-white text-xs md:text-sm px-5 py-2.5 rounded-full font-bold shadow-xl transition active:scale-95">Buka Toko Baru</a>
                </div>
            </div>
        </div>
    </nav>

    <!-- NOTIFIKASI ERROR DITAMPILKAN DI SINI SECARA MENGAMBANG -->
    <div class="fixed top-24 left-0 right-0 z-[100] px-4 max-w-xl mx-auto pointer-events-none" id="flash-msg">
        <div class="pointer-events-auto">
            <?php if(function_exists('display_flash')) display_flash(); ?>
        </div>
    </div>

    <main>
        <section class="relative pt-28 pb-16 md:pt-40 md:pb-24 overflow-hidden">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-10 lg:gap-8 items-center">
                    
                    <!-- Hero Text -->
                    <div class="max-w-2xl mx-auto text-center lg:text-left lg:mx-0">
                        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full bg-brand-50 border border-brand-100 text-brand-600 text-[10px] md:text-xs font-bold uppercase tracking-wide mb-4 md:mb-6">
                            <span class="relative flex h-2 w-2"><span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-brand-400 opacity-75"></span><span class="relative inline-flex rounded-full h-2 w-2 bg-brand-500"></span></span>
                            Enterprise SaaS V3 Ready
                        </div>
                        <h1 class="text-4xl sm:text-5xl lg:text-6xl font-extrabold text-slate-900 leading-[1.2] tracking-tight mb-4 md:mb-6">
                            Transformasi Bisnis F&B Anda <span class="bg-gradient-to-r from-brand-500 to-orange-500 gradient-text block sm:inline">Lebih Modern.</span>
                        </h1>
                        <p class="text-base md:text-lg text-slate-600 mb-8 leading-relaxed max-w-xl mx-auto lg:mx-0">
                            Kelola pesanan online, POS Thermal, Reservasi Meja, Diskon/Pajak, dan Laporan Laba Rugi Otomatis dalam satu sistem cerdas.
                        </p>
                        <div class="flex flex-col sm:flex-row justify-center lg:justify-start gap-3 md:gap-4">
                            <a href="#portal" class="w-full sm:w-auto flex items-center justify-center px-8 py-4 text-sm md:text-base font-bold rounded-full text-white bg-slate-900 hover:bg-slate-800 shadow-xl transition active:scale-95">Daftar Sekarang - Gratis</a>
                            <a href="#fitur" class="w-full sm:w-auto flex items-center justify-center px-8 py-4 text-sm md:text-base font-bold rounded-full text-slate-700 bg-white border border-slate-200 hover:bg-slate-50 shadow-sm transition active:scale-95 gap-2"><i data-lucide="play-circle" class="w-5 h-5 text-slate-400"></i> Pelajari Fitur</a>
                        </div>
                    </div>

                    <!-- Visual / Mockup -->
                    <div class="relative h-[400px] md:h-[600px] w-full flex items-center justify-center mt-6 lg:mt-0 px-4 sm:px-0">
                        <div class="absolute inset-0 bg-gradient-to-tr from-brand-500/20 to-blue-500/20 rounded-[2rem] md:rounded-[3rem] transform rotate-3 scale-105 -z-10"></div>
                        <div class="relative w-full max-w-[320px] md:max-w-md bg-white rounded-2xl md:rounded-3xl shadow-2xl border border-slate-100 p-2 animate-float">
                            <div class="flex items-center gap-2 px-3 py-2 border-b border-slate-50">
                                <div class="w-3 h-3 rounded-full bg-red-400"></div><div class="w-3 h-3 rounded-full bg-amber-400"></div><div class="w-3 h-3 rounded-full bg-green-400"></div>
                                <div class="ml-2 flex-1 h-6 bg-slate-50 rounded-full flex items-center px-3"><span class="text-[10px] text-slate-400">shop.nvitens.id/kedaikopi</span></div>
                            </div>
                            <div class="p-4 bg-slate-50/50 rounded-b-2xl h-64 md:h-80 flex flex-col gap-4 relative overflow-hidden">
                                <div class="flex justify-between items-end">
                                    <div><div class="w-20 h-4 bg-slate-200 rounded mb-2"></div><div class="w-32 h-8 bg-brand-500/20 rounded"></div></div>
                                    <div class="w-10 h-10 bg-slate-200 rounded-full"></div>
                                </div>
                                <div class="grid grid-cols-2 gap-3">
                                    <div class="h-24 bg-white rounded-xl border border-slate-100 p-3 shadow-sm"><div class="w-8 h-8 bg-blue-100 rounded-lg mb-2"></div><div class="w-full h-2 bg-slate-100 rounded mb-1"></div><div class="w-2/3 h-2 bg-slate-100 rounded"></div></div>
                                    <div class="h-24 bg-white rounded-xl border border-slate-100 p-3 shadow-sm"><div class="w-8 h-8 bg-green-100 rounded-lg mb-2"></div><div class="w-full h-2 bg-slate-100 rounded mb-1"></div><div class="w-2/3 h-2 bg-slate-100 rounded"></div></div>
                                </div>
                                <!-- Floating notif -->
                                <div class="absolute -right-4 md:-right-8 top-1/2 bg-white p-3 rounded-xl shadow-xl border border-slate-100 flex items-center gap-3">
                                    <div class="w-8 h-8 bg-green-500 rounded-full flex items-center justify-center text-white"><i data-lucide="check" class="w-4 h-4"></i></div>
                                    <div class="pr-2"><p class="text-xs font-bold text-slate-900">Pesanan Baru!</p><p class="text-[10px] text-slate-500">Meja 04 • Rp 45K</p></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>
        
        <!-- Portal Registration & Login -->
        <section id="portal" class="py-16 md:py-24 bg-slate-900 relative">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10">
                <div class="text-center mb-10 md:mb-16">
                    <h2 class="text-3xl md:text-4xl font-extrabold text-white mb-4">Portal Akses Ekosistem</h2>
                    <p class="text-slate-400">Masuk ke Dashboard atau daftarkan Toko Anda sekarang.</p>
                </div>

                <div class="flex flex-col lg:flex-row gap-6 md:gap-8 max-w-6xl mx-auto">
                    
                    <!-- Form Login -->
                    <div class="flex-1 bg-white/5 border border-slate-700/50 rounded-3xl p-6 md:p-10 shadow-2xl backdrop-blur">
                        <div class="mb-8">
                            <h3 class="text-2xl font-bold text-white mb-2">Login Dashboard</h3>
                            <p class="text-slate-400 text-sm">Akses Owner dan Kasir (Pegawai).</p>
                        </div>
                        
                        <!-- PERUBAHAN: Action dikosongkan agar memproses fungsi PHP di atas file ini -->
                        <form action="" method="POST" class="space-y-5">
                            <?= csrf_field() ?>
                            <!-- PERUBAHAN: Hidden input ini trigger logika login -->
                            <input type="hidden" name="login_submit" value="1">

                            <div>
                                <label class="block text-sm font-semibold text-slate-300 mb-2">Username</label>
                                <input type="text" name="username" required placeholder="Ketik username Anda" class="w-full px-5 py-3.5 rounded-xl bg-slate-800/50 border border-slate-700 text-white placeholder-slate-500 focus:border-brand-500 outline-none text-sm transition">
                            </div>
                            <div>
                                <label class="block text-sm font-semibold text-slate-300 mb-2">Password</label>
                                <input type="password" name="password" required placeholder="••••••••" class="w-full px-5 py-3.5 rounded-xl bg-slate-800/50 border border-slate-700 text-white placeholder-slate-500 focus:border-brand-500 outline-none text-sm transition">
                            </div>
                            <button type="submit" class="w-full bg-brand-500 hover:bg-brand-600 text-white font-extrabold py-4 rounded-xl mt-4 shadow-lg shadow-brand-500/30 transition">Masuk Sistem</button>
                        </form>
                    </div>

                    <!-- Form Registrasi Cepat -->
                    <div class="flex-[1.5] bg-gradient-to-br from-slate-50 to-white rounded-3xl p-6 md:p-10 shadow-2xl border border-slate-200">
                        <div class="mb-6">
                            <h3 class="text-2xl font-bold text-slate-900 mb-2">Ajukan Buka Toko Baru</h3>
                            <p class="text-slate-500 text-sm leading-relaxed">Isi data dasar toko. Akses Username & Password akan <span class="font-bold text-brand-600">diberikan oleh Admin</span> setelah konfirmasi WhatsApp.</p>
                        </div>

                        <form action="/auth/register" method="POST" class="space-y-4">
                            <?= csrf_field() ?>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Nama Toko/Usaha *</label>
                                    <input type="text" name="shop_name" required placeholder="Cth: Kedai Kopi Senja" class="w-full px-4 py-3 rounded-xl bg-white border border-slate-300 text-slate-900 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none text-sm transition shadow-sm">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Nama Pemilik *</label>
                                    <input type="text" name="owner_name" required placeholder="Nama Lengkap Anda" class="w-full px-4 py-3 rounded-xl bg-white border border-slate-300 text-slate-900 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none text-sm transition shadow-sm">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5">No WhatsApp Aktif *</label>
                                    <input type="text" name="whatsapp" required placeholder="08123456789" class="w-full px-4 py-3 rounded-xl bg-white border border-slate-300 text-slate-900 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none text-sm transition shadow-sm font-mono" onkeyup="this.value = this.value.replace(/[^0-9]/g, '')">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Email (Opsional)</label>
                                    <input type="email" name="email" placeholder="email@contoh.com" class="w-full px-4 py-3 rounded-xl bg-white border border-slate-300 text-slate-900 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none text-sm transition shadow-sm">
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-1.5">Alamat Lengkap Toko *</label>
                                <textarea name="address" required rows="2" placeholder="Jl. Jend. Sudirman No. 1..." class="w-full px-4 py-3 rounded-xl bg-white border border-slate-300 text-slate-900 focus:border-brand-500 focus:ring-2 focus:ring-brand-500/20 outline-none text-sm transition shadow-sm resize-none"></textarea>
                            </div>

                            <div>
                                <label class="block text-sm font-bold text-slate-700 mb-1.5">Klaim URL Web (Pilih Bebas) *</label>
                                <div class="flex rounded-xl border border-slate-300 bg-slate-50 w-full overflow-hidden focus-within:ring-2 focus-within:ring-brand-500/20 focus-within:border-brand-500 transition shadow-sm">
                                    <span class="inline-flex items-center px-4 bg-slate-100 text-slate-500 text-sm font-bold border-r border-slate-200">
                                        <?= $_SERVER['HTTP_HOST'] ?>/
                                    </span>
                                    <input type="text" name="slug" required placeholder="kedaikopi" class="flex-1 px-4 py-3 bg-white text-slate-900 outline-none font-mono text-sm w-full" onkeyup="this.value = this.value.replace(/[^a-z0-9]/g, '').toLowerCase()">
                                </div>
                                <p class="text-[10px] text-slate-500 mt-1 font-medium">Hanya huruf kecil & angka. Tanpa spasi. Ini akan jadi link toko Anda.</p>
                            </div>
                            
                            <button type="submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-extrabold py-4 rounded-xl shadow-xl mt-4 transition active:scale-95 flex justify-center items-center gap-2">
                                <i data-lucide="send" class="w-5 h-5"></i> Kirim Pendaftaran
                            </button>
                        </form>

                        <div class="mt-5 p-4 bg-blue-50/50 rounded-xl border border-blue-100 text-center">
                            <p class="text-xs text-blue-800 font-medium leading-relaxed flex items-center justify-center gap-1.5">
                                <i data-lucide="info" class="w-4 h-4"></i> Setelah klik kirim, hubungi <a href="https://wa.me/6282232067743" target="_blank" class="font-bold underline text-blue-900">WhatsApp Admin</a> untuk verifikasi.
                            </p>
                        </div>
                    </div>

                </div>
            </div>
        </section>
    </main>

    <footer class="bg-white border-t border-slate-100 pt-8 pb-8 text-center">
        <p class="text-slate-400 text-sm font-medium">&copy; <?= date('Y') ?> NVITENS SaaS. Hak cipta dilindungi.</p>
    </footer>

</div>

<script>
    lucide.createIcons();
    window.addEventListener('scroll', () => {
        const nav = document.getElementById('navbar');
        if (window.scrollY > 20) {
            nav.classList.add('shadow-sm'); nav.classList.replace('bg-white/85', 'bg-white/95');
        } else {
            nav.classList.remove('shadow-sm'); nav.classList.replace('bg-white/95', 'bg-white/85');
        }
    });
</script>
</body>
</html>