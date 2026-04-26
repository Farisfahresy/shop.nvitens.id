<?php
if (!defined('PDO::ATTR_ERRMODE')) exit;

// PAKSA ZONA WAKTU INDONESIA SECARA EKSPLISIT
date_default_timezone_set('Asia/Jakarta');

$tenant_id = $tenant_data['id'];
$is_checkout = isset($_GET['checkout']) && $_GET['checkout'] == 'true';
$is_reserve = isset($_GET['reserve']) && $_GET['reserve'] == 'true';

// ==========================================
// HANDLE RESERVASI MEJA (POST)
// ==========================================
if ($is_reserve && $_SERVER['REQUEST_METHOD'] === 'POST' && $tenant_data['enable_reservation']) {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    $customer_name = sanitize_input($_POST['res_name']);
    $customer_phone = preg_replace('/[^0-9]/', '', $_POST['res_phone']);
    $res_date = sanitize_input($_POST['res_date']);
    $res_time = sanitize_input($_POST['res_time']);
    $pax = (int)$_POST['res_pax'];
    $notes = sanitize_input($_POST['res_notes']);
    
    if($pax < 1) { set_flash("Jumlah orang minimal 1.", "error"); header("Location: /" . $tenant_data['slug']); exit; }

    try {
        $stmt = $pdo->prepare("INSERT INTO reservations (tenant_id, customer_name, customer_phone, res_date, res_time, pax, notes, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'waiting')");
        $stmt->execute([$tenant_id, $customer_name, $customer_phone, $res_date, $res_time, $pax, $notes]);
        
        set_flash("Permintaan reservasi berhasil dikirim! Silakan tunggu konfirmasi admin.", "success");
        header("Location: /" . $tenant_data['slug']); 
        exit;
    } catch (Exception $e) {
        set_flash("Gagal mengirim reservasi.", "error");
        header("Location: /" . $tenant_data['slug']); exit;
    }
}

// ==========================================
// HANDLE CHECKOUT (POST)
// ==========================================
if ($is_checkout && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token']);
    
    $cart = json_decode($_POST['cart_data'], true);
    if (empty($cart)) { 
        set_flash("Gagal: Keranjang belanja tidak terbaca oleh sistem.", "error"); 
        header("Location: /" . $tenant_data['slug']); 
        exit; 
    }
    
    $customer_name = sanitize_input($_POST['customer_name']);
    $customer_phone = preg_replace('/[^0-9]/', '', $_POST['customer_phone']);
    $order_type = sanitize_input($_POST['order_type']); 
    $payment_method = sanitize_input($_POST['payment_method']);
    
    // TANGKAP CATATAN PESANAN
    $customer_notes = sanitize_input($_POST['customer_notes'] ?? '');
    
    $address_table_no = '';
    if ($order_type === 'delivery') $address_table_no = sanitize_input($_POST['address']);
    if ($order_type === 'dinein') $address_table_no = 'Meja: ' . sanitize_input($_POST['table_no']);
    if ($order_type === 'pickup') $address_table_no = 'Diambil sendiri (Pick-up)';
    
    // Handle Upload Bukti Bayar
    $proof_image = null;
    if ($payment_method === 'transfer' && isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
        $upload_dir = __DIR__ . '/../uploads/proofs';
        $upload_result = secure_upload($_FILES['payment_proof'], $upload_dir);
        if (!$upload_result['status']) {
            set_flash($upload_result['message'], "error");
            header("Location: /" . $tenant_data['slug']);
            exit;
        }
        $proof_image = $upload_result['filename'];
    }

    $subtotal = 0;
    try {
        $pdo->beginTransaction();
        
        // Cek Stok dan Kalkulasi Total dengan Database Concurrency Locking (FOR UPDATE)
        foreach ($cart as $key => $item) {
            $stmt = $pdo->prepare("SELECT price, discount_price, stock FROM products WHERE id = ? AND tenant_id = ? AND stock > 0 AND is_active = 1 FOR UPDATE");
            $stmt->execute([$item['id'], $tenant_id]);
            $product_db = $stmt->fetch();
            
            if (!$product_db) {
                throw new Exception("Produk " . htmlspecialchars($item['name']) . " tidak tersedia atau telah dihapus.");
            }
            if ($product_db['stock'] < $item['qty']) {
                throw new Exception("Stok " . htmlspecialchars($item['name']) . " tidak mencukupi.");
            }
            
            // Prioritaskan harga diskon jika ada
            $actual_price = ($product_db['discount_price'] > 0) ? $product_db['discount_price'] : $product_db['price'];
            $cart[$key]['price'] = $actual_price;
            $subtotal += ($actual_price * $item['qty']);
        }

        // Kalkulasi Global Diskon & Pajak sesuai setting Tenant
        $discount_amount = 0;
        if ($tenant_data['enable_discount']) {
            if ($tenant_data['discount_type'] === 'percent') {
                $discount_amount = $subtotal * ($tenant_data['discount_value'] / 100);
            } else {
                $discount_amount = $tenant_data['discount_value'];
            }
        }
        $subtotal_after_discount = max(0, $subtotal - $discount_amount);
        
        $tax_amount = 0;
        if ($tenant_data['enable_tax']) {
            $tax_amount = $subtotal_after_discount * ($tenant_data['tax_percentage'] / 100);
        }
        
        $shipping_fee = 0; // Dinamis untuk update selanjutnya
        $total_amount = $subtotal_after_discount + $tax_amount + $shipping_fee;

        $now = date('Y-m-d H:i:s'); // Injeksi Timezone Waktu Jakarta

        // Insert Master Order (Dengan customer_notes)
        $stmtOrder = $pdo->prepare("INSERT INTO orders (tenant_id, customer_name, customer_phone, order_type, address_table_no, customer_notes, subtotal, tax_amount, discount_amount, shipping_fee, total_amount, payment_method, payment_proof, status, source, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'waiting', 'online', ?)");
        $stmtOrder->execute([
            $tenant_id, $customer_name, $customer_phone, $order_type, $address_table_no, $customer_notes, $subtotal, $tax_amount, $discount_amount, $shipping_fee, $total_amount, $payment_method, $proof_image, $now
        ]);
        $order_id = $pdo->lastInsertId();

        // Insert Order Items & Potong Stok & Catat Log
        $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
        $stmtUpdateStock = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND tenant_id = ?");
        $stmtLog = $pdo->prepare("INSERT INTO stock_logs (tenant_id, product_id, type, qty_change, final_stock, note, created_at) VALUES (?, ?, 'sale_online', ?, (SELECT stock FROM products WHERE id = ?), ?, ?)");

        foreach ($cart as $item) {
            $stmtItem->execute([$order_id, $item['id'], $item['qty'], $item['price']]);
            $stmtUpdateStock->execute([$item['qty'], $item['id'], $tenant_id]);
            $stmtLog->execute([$tenant_id, $item['id'], -$item['qty'], $item['id'], "Pesanan Online #ORD-" . $order_id, $now]);
        }
        
        $pdo->commit();
        CacheManager::invalidate('tenant_products_' . $tenant_id);

        set_flash("Pesanan sukses! ID Transaksi: #ORD-{$order_id}. Silakan tunggu pesanan disiapkan.", "success");
        header("Location: /" . $tenant_data['slug']); 
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        set_flash("Gagal memproses pesanan: " . $e->getMessage(), "error");
        header("Location: /" . $tenant_data['slug']); 
        exit;
    }
}

// ==========================================
// FETCH PRODUCTS DENGAN CACHING V3
// ==========================================
$cache_key = 'tenant_products_' . $tenant_id;
$products = CacheManager::get($cache_key);

if ($products === false) {
    // Ambil data produk berserta nama kategori (V3 Query)
    $stmt = $pdo->prepare("
        SELECT p.id, p.name, p.description, p.price, p.discount_price, p.stock, p.image, c.name as category 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.id 
        WHERE p.tenant_id = ? AND p.is_active = 1 AND p.stock > 0 
        ORDER BY c.name ASC, p.name ASC
    ");
    $stmt->execute([$tenant_id]);
    $products = $stmt->fetchAll();
    CacheManager::set($cache_key, $products, 300);
}

// Ekstrak list kategori unik dari data produk untuk tombol filter
$categories = [];
if (!empty($products)) {
    foreach ($products as $p) {
        $catName = $p['category'] ?? 'Umum';
        if (!in_array($catName, $categories)) {
            $categories[] = $catName;
        }
    }
}

// Persiapan Variabel SEO
$shop_name = htmlspecialchars($tenant_data['shop_name'], ENT_QUOTES, 'UTF-8');
$shop_slug = htmlspecialchars($tenant_data['slug'], ENT_QUOTES, 'UTF-8');
$current_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]/$shop_slug";
$shop_logo = !empty($tenant_data['logo']) ? "/uploads/logos/" . htmlspecialchars($tenant_data['logo'], ENT_QUOTES, 'UTF-8') : "/assets/default-shop.png";
$base_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
$full_logo_url = rtrim($base_url, '/') . $shop_logo;
?>
<!DOCTYPE html>
<html lang="id" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?= $shop_name ?> - Pesan Online Sekarang</title>
    <meta name="description" content="Pesan menu favorit dari <?= $shop_name ?> secara online. Cepat dan mudah">
    <link rel="canonical" href="<?= $current_url ?>">
    <meta name="theme-color" content="#f59e0b">
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>
        tailwind.config = {
            theme: { extend: { fontFamily: { sans: ['"Plus Jakarta Sans"', 'sans-serif'] }, colors: { brand: { 50: '#fef3c7', 100: '#fde68a', 500: '#f59e0b', 600: '#d97706', 900: '#78350f' } } } }
        }
    </script>
    <style>
        body { font-family: 'Plus Jakarta Sans', sans-serif; -webkit-font-smoothing: antialiased; }
        .hide-scrollbar::-webkit-scrollbar { display: none; }
        .hide-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        input[type="radio"]:checked ~ div.checked-indicator { display: block; }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 pb-32">

    <div class="fixed top-4 left-0 right-0 z-[100] px-4 max-w-lg mx-auto pointer-events-none" id="flash-container">
        <div class="pointer-events-auto">
            <?php if(function_exists('display_flash')) display_flash(); ?>
        </div>
    </div>

    <!-- Header / Info Toko -->
    <header class="bg-white shadow-sm sticky top-0 z-40 border-b border-slate-100">
        <div class="max-w-2xl mx-auto px-4 py-3 flex items-center justify-between">
            <div class="flex items-center gap-3 min-w-0 flex-1">
                <div class="w-12 h-12 bg-slate-100 rounded-full border border-slate-200 overflow-hidden shrink-0 shadow-sm">
                    <?php if (!empty($tenant_data['logo'])): ?>
                        <img src="/uploads/logos/<?= htmlspecialchars($tenant_data['logo']) ?>" alt="<?= $shop_name ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                        <div class="w-full h-full flex items-center justify-center text-slate-400 bg-slate-100"><i data-lucide="store" class="w-6 h-6"></i></div>
                    <?php endif; ?>
                </div>
                <div class="flex-1 min-w-0 pr-4">
                    <h1 class="font-extrabold text-lg text-slate-900 truncate leading-tight"><?= $shop_name ?></h1>
                    <p class="text-xs text-slate-500 truncate mt-0.5"><i data-lucide="map-pin" class="w-3 h-3 inline pb-0.5"></i> <?= htmlspecialchars($tenant_data['address']) ?></p>
                </div>
            </div>
            
            <div class="flex gap-2">
                <?php if($tenant_data['enable_reservation']): ?>
                <button onclick="document.getElementById('modal-reservasi').classList.remove('hidden')" class="w-10 h-10 bg-slate-100 text-slate-700 rounded-full flex items-center justify-center shrink-0 hover:bg-slate-200 transition" title="Reservasi Meja"><i data-lucide="calendar" class="w-5 h-5"></i></button>
                <?php endif; ?>
                <a href="https://wa.me/<?= preg_replace('/^0/', '62', htmlspecialchars($tenant_data['whatsapp'])) ?>" target="_blank" class="w-10 h-10 bg-[#25D366]/10 text-[#25D366] rounded-full flex items-center justify-center shrink-0 hover:bg-[#25D366] hover:text-white transition shadow-sm">
                    <i data-lucide="phone" class="w-5 h-5"></i>
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-6">
        
        <!-- SECTION PENCARIAN & KATEGORI -->
        <div class="pb-3 space-y-3 mb-2">
            <div class="relative">
                <i data-lucide="search" class="absolute left-3 top-2.5 text-slate-400 w-5 h-5"></i>
                <input type="text" id="search-input" placeholder="Cari..." class="w-full pl-10 pr-4 py-2.5 bg-white border border-slate-200 shadow-sm rounded-xl text-sm outline-none focus:ring-2 focus:ring-brand-500 transition" onkeyup="filterMenu()">
            </div>
            
            <div class="flex gap-2 overflow-x-auto hide-scrollbar pb-1">
                <button onclick="filterKategori('all', this)" class="cat-btn bg-slate-900 text-white px-4 py-1.5 rounded-full text-xs font-bold whitespace-nowrap shadow-sm transition">Semua</button>
                <?php foreach($categories as $cat): ?>
                <button onclick="filterKategori('<?= htmlspecialchars($cat) ?>', this)" class="cat-btn bg-white border border-slate-200 text-slate-600 px-4 py-1.5 rounded-full text-xs font-bold whitespace-nowrap shadow-sm transition"><?= htmlspecialchars($cat) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- END SECTION PENCARIAN & KATEGORI -->

        <h2 class="text-sm font-bold uppercase tracking-wider text-slate-400 mb-4 mt-2">Katalog Tersedia</h2>
        
        <?php if(empty($products)): ?>
            <div class="text-center py-16 bg-white rounded-2xl border border-slate-100 shadow-sm">
                <div class="w-16 h-16 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4 text-slate-300"><i data-lucide="frown" class="w-8 h-8"></i></div>
                <h3 class="font-bold text-slate-700 text-lg mb-1">Tidak ada produk</h3>
                <p class="text-sm text-slate-500 max-w-[250px] mx-auto">Toko ini belum menambahkan produk atau stok sedang kosong.</p>
            </div>
        <?php else: ?>
            <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 sm:gap-4" id="product-grid">
                <?php foreach($products as $p): 
                    $actual_price = ($p['discount_price'] > 0) ? $p['discount_price'] : $p['price'];
                    $cat_name = htmlspecialchars($p['category'] ?? 'Umum');
                ?>
                <div class="product-card bg-white rounded-xl sm:rounded-2xl shadow-sm border border-slate-100 overflow-hidden flex flex-col hover:border-brand-500 transition-colors cursor-pointer group" 
                     data-name="<?= strtolower(htmlspecialchars($p['name'])) ?>"
                     data-category="<?= strtolower($cat_name) ?>"
                     onclick="addToCart(<?= $p['id'] ?>, '<?= addslashes(htmlspecialchars($p['name'])) ?>', <?= $actual_price ?>, <?= $p['stock'] ?>)">
                    <div class="relative pt-[100%] bg-slate-100 overflow-hidden">
                        <?php if($p['image']): ?>
                            <img src="/uploads/products/<?= htmlspecialchars($p['image']) ?>" alt="<?= htmlspecialchars($p['name']) ?>" class="absolute inset-0 w-full h-full object-cover group-hover:scale-110 transition duration-500" loading="lazy">
                        <?php else: ?>
                            <div class="absolute inset-0 flex items-center justify-center text-slate-300 bg-slate-50"><i data-lucide="image" class="w-8 h-8"></i></div>
                        <?php endif; ?>
                        
                        <div class="absolute top-2 left-2 bg-slate-900/80 backdrop-blur-sm text-white text-[10px] px-2 py-0.5 rounded uppercase font-bold tracking-wide shadow-sm"><?= $cat_name ?></div>
                        
                        <?php if($p['discount_price'] > 0): ?>
                            <div class="absolute top-2 right-2 bg-red-600 text-white text-[10px] px-2 py-0.5 rounded font-bold animate-pulse">PROMO</div>
                        <?php endif; ?>
                    </div>
                    <div class="p-3 flex-1 flex flex-col justify-between">
                        <div>
                            <h3 class="font-bold text-sm text-slate-900 leading-snug mb-1 line-clamp-2"><?= htmlspecialchars($p['name']) ?></h3>
                            <?php if(!empty($p['description'])): ?><p class="text-[10px] text-slate-500 line-clamp-2 leading-tight"><?= htmlspecialchars($p['description']) ?></p><?php endif; ?>
                        </div>
                        <div class="mt-3 flex items-center justify-between">
                            <div>
                                <?php if($p['discount_price'] > 0): ?>
                                    <p class="text-[10px] text-slate-400 line-through">Rp<?= number_format($p['price'], 0, ',', '.') ?></p>
                                <?php endif; ?>
                                <span class="text-brand-600 font-extrabold text-sm">Rp<?= number_format($actual_price, 0, ',', '.') ?></span>
                            </div>
                            <button class="w-7 h-7 bg-brand-50 hover:bg-brand-500 text-brand-600 hover:text-white rounded-full flex items-center justify-center transition shadow-sm"><i data-lucide="plus" class="w-4 h-4"></i></button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pesan jika tidak ada hasil pencarian -->
            <div id="no-result-msg" class="hidden text-center py-12">
                <i data-lucide="search-x" class="w-12 h-12 text-slate-300 mx-auto mb-3"></i>
                <p class="text-sm font-bold text-slate-500"> tidak ditemukan</p>
            </div>
        <?php endif; ?>
    </main>

    <!-- Modal Reservasi -->
    <?php if($tenant_data['enable_reservation']): ?>
    <div id="modal-reservasi" class="fixed inset-0 z-50 hidden flex flex-col justify-end md:justify-center items-center">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm" onclick="document.getElementById('modal-reservasi').classList.add('hidden')"></div>
        <div class="relative w-full max-w-md bg-white rounded-t-3xl md:rounded-3xl shadow-2xl p-6 transition-transform">
            <div class="flex justify-between items-center mb-5 border-b border-slate-100 pb-3">
                <h3 class="font-extrabold text-lg text-slate-900">Booking Meja</h3>
                <button onclick="document.getElementById('modal-reservasi').classList.add('hidden')" class="text-slate-400 bg-slate-100 w-8 h-8 rounded-full flex justify-center items-center"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            <form action="/<?= $tenant_data['slug'] ?>?reserve=true" method="POST" class="space-y-4">
                <?= csrf_field() ?>
                <div><label class="block text-xs font-bold text-slate-700 mb-1">Nama Pemesan</label><input type="text" name="res_name" required class="w-full px-4 py-2 border rounded-xl outline-none focus:border-brand-500"></div>
                <div><label class="block text-xs font-bold text-slate-700 mb-1">No WhatsApp</label><input type="tel" name="res_phone" required class="w-full px-4 py-2 border rounded-xl outline-none focus:border-brand-500"></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-xs font-bold text-slate-700 mb-1">Tanggal</label><input type="date" name="res_date" required min="<?= date('Y-m-d') ?>" class="w-full px-4 py-2 border rounded-xl outline-none"></div>
                    <div><label class="block text-xs font-bold text-slate-700 mb-1">Jam Kedatangan</label><input type="time" name="res_time" required class="w-full px-4 py-2 border rounded-xl outline-none"></div>
                </div>
                <div><label class="block text-xs font-bold text-slate-700 mb-1">Jumlah Orang (Pax)</label><input type="number" name="res_pax" required min="1" class="w-full px-4 py-2 border rounded-xl outline-none"></div>
                <div><label class="block text-xs font-bold text-slate-700 mb-1">Catatan (Opsional)</label><textarea name="res_notes" rows="2" class="w-full px-4 py-2 border rounded-xl outline-none resize-none"></textarea></div>
                <button type="submit" class="w-full bg-slate-900 text-white font-bold py-3.5 rounded-xl mt-2">Kirim Reservasi</button>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Floating Cart Button -->
    <div class="fixed bottom-6 left-0 right-0 px-4 max-w-2xl mx-auto z-40 transition-transform duration-300 translate-y-32" id="floating-cart">
        <button onclick="toggleCheckoutForm()" class="w-full bg-slate-900 text-white rounded-2xl p-4 shadow-2xl flex items-center justify-between hover:bg-slate-800 active:scale-[0.98] transition border border-slate-700">
            <div class="flex items-center gap-3">
                <div class="bg-white/20 w-10 h-10 rounded-xl flex items-center justify-center relative backdrop-blur-sm">
                    <i data-lucide="shopping-bag" class="w-5 h-5"></i>
                    <span id="cart-badge" class="absolute -top-2 -right-2 bg-red-500 text-white text-[10px] font-bold w-5 h-5 flex items-center justify-center rounded-full border-2 border-slate-900 shadow-sm">0</span>
                </div>
                <div class="text-left">
                    <p class="text-xs text-slate-400 font-medium">Total Pesanan</p>
                    <p class="font-bold text-lg leading-none mt-0.5" id="cart-total-float">Rp 0</p>
                </div>
            </div>
            <div class="font-bold text-sm bg-white/10 px-4 py-2.5 rounded-xl flex items-center gap-1 backdrop-blur-sm">Lanjut Bayar <i data-lucide="chevron-right" class="w-4 h-4"></i></div>
        </button>
    </div>

    <!-- Checkout Modal / Drawer -->
    <div id="checkout-form-modal" class="fixed inset-0 z-50 hidden">
        <div class="absolute inset-0 bg-slate-900/60 backdrop-blur-sm transition-opacity" onclick="toggleCheckoutForm()"></div>
        <div class="absolute bottom-0 left-0 right-0 bg-white rounded-t-3xl shadow-2xl max-w-2xl mx-auto h-[90vh] flex flex-col transition-transform transform translate-y-0">
            <div class="p-4 md:p-5 border-b border-slate-100 flex justify-between items-center bg-white rounded-t-3xl shrink-0">
                <h3 class="font-extrabold text-lg text-slate-900">Selesaikan Pesanan</h3>
                <button type="button" onclick="toggleCheckoutForm()" class="w-8 h-8 bg-slate-100 hover:bg-slate-200 rounded-full flex items-center justify-center text-slate-600 transition"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            
            <form action="/<?= $tenant_data['slug'] ?>?checkout=true" method="POST" enctype="multipart/form-data" id="main-checkout-form" onsubmit="return submitCheckout()" class="flex-1 flex flex-col overflow-hidden">
                <?= csrf_field() ?>
                <input type="hidden" name="cart_data" id="checkout-cart-input">
                
                <div class="flex-1 overflow-y-auto p-4 md:p-6 hide-scrollbar">
                    
                    <!-- Rincian Pesanan (Cart Items) -->
                    <div class="bg-slate-50 rounded-2xl p-4 border border-slate-100 mb-6 shadow-sm">
                        <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 mb-3 border-b border-slate-200 pb-2">Rincian Pesanan</h4>
                        <div id="cart-items-list" class="space-y-3 mb-4"></div>
                        
                        <!-- CATATAN PESANAN DITAMBAHKAN DI SINI -->
                        <div class="pt-2 mb-4">
                            <label class="block text-xs font-bold text-slate-700 mb-1.5">Catatan Pesanan (Opsional)</label>
                            <textarea name="customer_notes" rows="2" class="w-full px-4 py-2.5 bg-white border border-slate-200 rounded-xl focus:ring-2 focus:ring-brand-500 outline-none transition text-sm resize-none" placeholder="Cth: Pedas, tanpa sayur, es dipisah..."></textarea>
                        </div>

                        <div class="space-y-1.5 border-t border-slate-200 pt-3">
                            <div class="flex justify-between text-sm"><span class="text-slate-600">Subtotal</span><span class="font-bold" id="c-subtotal">Rp 0</span></div>
                            <?php if($tenant_data['enable_discount']): ?>
                                <div class="flex justify-between text-sm text-green-600"><span class="font-bold">Diskon Toko</span><span class="font-bold" id="c-discount">- Rp 0</span></div>
                            <?php endif; ?>
                            <?php if($tenant_data['enable_tax']): ?>
                                <div class="flex justify-between text-sm"><span class="text-slate-600">Pajak (<?= $tenant_data['tax_percentage'] ?>%)</span><span class="font-bold" id="c-tax">Rp 0</span></div>
                            <?php endif; ?>
                            <div class="flex justify-between items-center pt-2 mt-2 border-t border-slate-200"><span class="font-bold text-slate-800">Total Akhir</span><span class="font-extrabold text-2xl text-brand-600" id="c-total">Rp 0</span></div>
                        </div>
                    </div>
                        
                    <div class="space-y-6">
                        <!-- Tipe Pesanan -->
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Pilih Tipe Pesanan <span class="text-red-500">*</span></label>
                            <div class="grid grid-cols-2 gap-3">
                                <?php if($tenant_data['allow_delivery']): ?>
                                <label class="border border-slate-200 rounded-xl p-3 flex items-center cursor-pointer hover:bg-slate-50 transition has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 has-[:checked]:ring-1 has-[:checked]:ring-brand-500">
                                    <input type="radio" name="order_type" value="delivery" class="sr-only" onchange="handleOrderType()" required>
                                    <div class="w-4 h-4 rounded-full border border-slate-300 mr-3 flex-shrink-0 relative flex items-center justify-center bg-white"><div class="w-2 h-2 rounded-full bg-brand-500 hidden checked-indicator"></div></div>
                                    <span class="font-semibold text-sm text-slate-800">Delivery / Antar</span>
                                </label>
                                <?php endif; ?>
                                
                                <?php if($tenant_data['allow_dinein']): ?>
                                <label class="border border-slate-200 rounded-xl p-3 flex items-center cursor-pointer hover:bg-slate-50 transition has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 has-[:checked]:ring-1 has-[:checked]:ring-brand-500">
                                    <input type="radio" name="order_type" value="dinein" class="sr-only" onchange="handleOrderType()" required>
                                    <div class="w-4 h-4 rounded-full border border-slate-300 mr-3 flex-shrink-0 relative flex items-center justify-center bg-white"><div class="w-2 h-2 rounded-full bg-brand-500 hidden checked-indicator"></div></div>
                                    <span class="font-semibold text-sm text-slate-800">Dine-in (Meja)</span>
                                </label>
                                <?php endif; ?>

                                <?php if($tenant_data['allow_pickup']): ?>
                                <label class="border border-slate-200 rounded-xl p-3 flex items-center cursor-pointer hover:bg-slate-50 transition has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 has-[:checked]:ring-1 has-[:checked]:ring-brand-500 md:col-span-2">
                                    <input type="radio" name="order_type" value="pickup" class="sr-only" onchange="handleOrderType()" required>
                                    <div class="w-4 h-4 rounded-full border border-slate-300 mr-3 flex-shrink-0 relative flex items-center justify-center bg-white"><div class="w-2 h-2 rounded-full bg-brand-500 hidden checked-indicator"></div></div>
                                    <span class="font-semibold text-sm text-slate-800">Ambil Sendiri (Pick-up)</span>
                                </label>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Data Diri -->
                        <div class="bg-white p-4 rounded-2xl border border-slate-100 shadow-sm space-y-4">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-slate-500 border-b border-slate-100 pb-2">Informasi Pemesan</h4>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5">Nama Lengkap <span class="text-red-500">*</span></label>
                                    <input type="text" name="customer_name" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-brand-500 focus:bg-white outline-none transition text-sm">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-slate-700 mb-1.5">No WhatsApp <span class="text-red-500">*</span></label>
                                    <input type="tel" name="customer_phone" required class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-brand-500 focus:bg-white outline-none transition text-sm font-mono" placeholder="08..." onkeyup="this.value = this.value.replace(/[^0-9]/g, '')">
                                </div>
                            </div>

                            <div id="input-address" class="hidden pt-2">
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">Alamat Pengiriman Lengkap <span class="text-red-500">*</span></label>
                                <textarea name="address" rows="2" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-brand-500 focus:bg-white outline-none transition text-sm resize-none" placeholder="Detail alamat..."></textarea>
                            </div>
                            <div id="input-table" class="hidden pt-2">
                                <label class="block text-xs font-bold text-slate-700 mb-1.5">Nomor Meja Anda <span class="text-red-500">*</span></label>
                                <input type="text" name="table_no" class="w-full px-4 py-2.5 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-brand-500 focus:bg-white outline-none transition text-sm font-bold" placeholder="Cth: 04">
                            </div>
                        </div>

                        <!-- Metode Pembayaran -->
                        <div>
                            <label class="block text-sm font-bold text-slate-700 mb-2">Metode Pembayaran <span class="text-red-500">*</span></label>
                            <div class="space-y-3">
                                <label class="border border-slate-200 rounded-xl p-3 flex items-center cursor-pointer hover:bg-slate-50 transition has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 has-[:checked]:ring-1 has-[:checked]:ring-brand-500">
                                    <input type="radio" name="payment_method" value="tunai" class="sr-only" onchange="handlePayment()" required>
                                    <div class="w-10 h-10 bg-green-100 rounded-lg flex items-center justify-center text-green-600 mr-3 shrink-0"><i data-lucide="banknote" class="w-5 h-5"></i></div>
                                    <div class="flex-1">
                                        <p class="font-bold text-sm text-slate-900">Bayar di Tempat (Tunai)</p>
                                        <p class="text-[10px] text-slate-500 mt-0.5">Bayar tunai ke kurir/kasir</p>
                                    </div>
                                    <div class="w-4 h-4 rounded-full border border-slate-300 relative flex items-center justify-center bg-white"><div class="w-2 h-2 rounded-full bg-brand-500 hidden checked-indicator"></div></div>
                                </label>
                                <label class="border border-slate-200 rounded-xl p-3 flex items-center cursor-pointer hover:bg-slate-50 transition has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50 has-[:checked]:ring-1 has-[:checked]:ring-brand-500">
                                    <input type="radio" name="payment_method" value="transfer" class="sr-only" onchange="handlePayment()" required>
                                    <div class="w-10 h-10 bg-blue-100 rounded-lg flex items-center justify-center text-blue-600 mr-3 shrink-0"><i data-lucide="credit-card" class="w-5 h-5"></i></div>
                                    <div class="flex-1">
                                        <p class="font-bold text-sm text-slate-900">Transfer / QRIS</p>
                                        <p class="text-[10px] text-slate-500 mt-0.5">Wajib upload bukti bayar</p>
                                    </div>
                                    <div class="w-4 h-4 rounded-full border border-slate-300 relative flex items-center justify-center bg-white"><div class="w-2 h-2 rounded-full bg-brand-500 hidden checked-indicator"></div></div>
                                </label>
                            </div>
                        </div>

                        <!-- Bukti Transfer Conditional -->
                        <div id="transfer-details" class="hidden bg-blue-50/50 p-5 border border-blue-100 rounded-2xl">
                            <h4 class="font-bold text-sm text-slate-800 mb-3 flex items-center gap-2"><i data-lucide="info" class="w-4 h-4 text-blue-600"></i> Instruksi Pembayaran</h4>
                            
                            <div class="grid grid-cols-1 sm:grid-cols-1 gap-4 mb-4">
                                <?php if(!empty($tenant_data['qris_image'])): ?>
                                    <div class="bg-white p-3 rounded-xl shadow-sm border border-slate-100 flex flex-col items-center justify-center text-center">
                                        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-2">Scan QRIS Ini</p>
                                        <img src="/uploads/qris/<?= htmlspecialchars($tenant_data['qris_image']) ?>" alt="QRIS" class="w-64 h-80 object-cover rounded-lg border border-slate-200 ">
                                    </div>
                                <?php endif; ?>
                                
                                <?php if(!empty($tenant_data['bank_account'])): ?>
                                    <div class="bg-white p-4 rounded-xl shadow-sm border border-slate-100 flex flex-col justify-center">
                                        <p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider mb-1">Transfer ke Rekening</p>
                                        <p class="font-mono font-bold text-slate-900 text-sm whitespace-pre-line"><?= htmlspecialchars($tenant_data['bank_account']) ?></p>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="bg-white p-4 rounded-xl border border-blue-100 shadow-sm">
                                <label class="block text-xs font-bold text-slate-700 mb-2">Upload Bukti Bayar <span class="text-red-500">*</span></label>
                                <input type="file" name="payment_proof" id="input-proof" accept="image/jpeg,image/png,image/webp" class="block w-full text-sm text-slate-500 file:mr-4 file:py-2 file:px-4 file:rounded-full file:border-0 file:text-xs file:font-bold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100 outline-none cursor-pointer">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="p-4 border-t border-slate-100 bg-white shrink-0 rounded-b-3xl">
                    <button type="submit" id="btn-submit" class="w-full bg-slate-900 hover:bg-slate-800 text-white font-bold py-4 rounded-xl shadow-xl shadow-slate-900/20 flex items-center justify-center gap-2 transition active:scale-[0.98]">
                        <i data-lucide="check-circle" class="w-5 h-5"></i> Kirim Pesanan Sekarang
                    </button>
                </div>
            </form>

        </div>
    </div>

    <script>
        lucide.createIcons();
        
        const CFG = {
            tax_en: <?= $tenant_data['enable_tax'] ? 'true' : 'false' ?>,
            tax_pct: <?= (float)$tenant_data['tax_percentage'] ?>,
            disc_en: <?= $tenant_data['enable_discount'] ? 'true' : 'false' ?>,
            disc_type: '<?= $tenant_data['discount_type'] ?>',
            disc_val: <?= (float)$tenant_data['discount_value'] ?>
        };

        let cart = JSON.parse(localStorage.getItem('nvitens_cart_<?= $tenant_id ?>')) || [];

        // ==========================================
        // FITUR PENCARIAN & FILTER KATEGORI (NEW)
        // ==========================================
        let currentCategory = 'all';

        function filterMenu() {
            const searchInput = document.getElementById('search-input').value.toLowerCase();
            const cards = document.querySelectorAll('.product-card');
            let visibleCount = 0;

            cards.forEach(card => {
                const name = card.getAttribute('data-name');
                const category = card.getAttribute('data-category');
                
                // Cek apakah memenuhi keyword dan kategori
                const matchesSearch = name.includes(searchInput);
                const matchesCategory = currentCategory === 'all' || category === currentCategory.toLowerCase();

                if (matchesSearch && matchesCategory) {
                    card.style.display = 'flex';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            // Tampilkan pesan jika tidak ada yang ditemukan
            const noResultMsg = document.getElementById('no-result-msg');
            if(noResultMsg) {
                noResultMsg.style.display = visibleCount === 0 ? 'block' : 'none';
            }
        }

        function filterKategori(cat, btnElement) {
            currentCategory = cat;

            // Update styling untuk semua tombol kategori
            const allBtns = document.querySelectorAll('.cat-btn');
            allBtns.forEach(btn => {
                btn.classList.remove('bg-slate-900', 'text-white');
                btn.classList.add('bg-white', 'text-slate-600', 'border', 'border-slate-200');
            });

            // Set styling aktif pada tombol yang diklik
            btnElement.classList.remove('bg-white', 'text-slate-600', 'border', 'border-slate-200');
            btnElement.classList.add('bg-slate-900', 'text-white');

            // Terapkan filter kembali
            filterMenu();
        }
        // ==========================================

        function formatRupiah(number) {
            return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(number);
        }

        function addToCart(id, name, price, maxStock) {
            const existing = cart.find(item => item.id === id);
            if (existing) {
                if (existing.qty < maxStock) {
                    existing.qty += 1;
                } else {
                    alert('Maaf, stok maksimal untuk menu ini tercapai.');
                    return;
                }
            } else {
                if(maxStock < 1) { alert('Stok habis'); return; }
                cart.push({ id, name, price, qty: 1, maxStock });
            }
            updateUI();
            
            const floatBtn = document.getElementById('floating-cart').querySelector('button');
            floatBtn.classList.add('scale-95');
            setTimeout(() => floatBtn.classList.remove('scale-95'), 150);
        }

        function updateQty(id, delta) {
            const index = cart.findIndex(item => item.id === id);
            if (index !== -1) {
                const item = cart[index];
                const newQty = item.qty + delta;
                
                if (newQty <= 0) {
                    cart.splice(index, 1);
                } else if (newQty > item.maxStock) {
                    alert('Maksimal stok tercapai.');
                } else {
                    item.qty = newQty;
                }
                updateUI();
                
                if(cart.length === 0) {
                    document.getElementById('checkout-form-modal').classList.add('hidden');
                }
            }
        }

        function updateUI() {
            localStorage.setItem('nvitens_cart_<?= $tenant_id ?>', JSON.stringify(cart));
            
            const floatCart = document.getElementById('floating-cart');
            if (cart.length > 0) {
                floatCart.classList.remove('translate-y-32'); 
                floatCart.classList.remove('translate-y-24'); 
            } else {
                floatCart.classList.add('translate-y-32'); 
            }

            let totalQty = 0;
            let subtotal = 0;
            let htmlList = '';

            cart.forEach(item => {
                totalQty += item.qty;
                subtotal += (item.qty * item.price);
                htmlList += `
                    <div class="flex items-center justify-between bg-white p-3 rounded-xl border border-slate-100 shadow-sm">
                        <div class="flex-1 min-w-0 pr-3">
                            <h5 class="text-sm font-bold text-slate-800 truncate">${item.name}</h5>
                            <p class="text-brand-600 font-bold text-xs mt-0.5">${formatRupiah(item.price)}</p>
                        </div>
                        <div class="flex items-center gap-3 bg-slate-50 border border-slate-200 p-1 rounded-lg shrink-0">
                            <button type="button" onclick="updateQty(${item.id}, -1)" class="w-7 h-7 bg-white border border-slate-200 rounded text-slate-600 flex items-center justify-center hover:bg-red-50 hover:text-red-500 hover:border-red-200 transition"><i data-lucide="minus" class="w-3 h-3"></i></button>
                            <span class="font-bold text-sm w-4 text-center">${item.qty}</span>
                            <button type="button" onclick="updateQty(${item.id}, 1)" class="w-7 h-7 bg-white border border-slate-200 rounded text-slate-600 flex items-center justify-center hover:bg-brand-50 hover:text-brand-600 hover:border-brand-200 transition"><i data-lucide="plus" class="w-3 h-3"></i></button>
                        </div>
                    </div>
                `;
            });

            let disc = 0;
            if(CFG.disc_en) disc = (CFG.disc_type === 'percent') ? subtotal * (CFG.disc_val/100) : CFG.disc_val;
            let subAfterDisc = Math.max(0, subtotal - disc);
            let tax = 0;
            if(CFG.tax_en) tax = subAfterDisc * (CFG.tax_pct/100);
            let grandTotal = subAfterDisc + tax;

            document.getElementById('cart-badge').innerText = totalQty;
            document.getElementById('cart-items-list').innerHTML = htmlList;
            
            document.getElementById('cart-total-float').innerText = formatRupiah(grandTotal);
            document.getElementById('c-subtotal').innerText = formatRupiah(subtotal);
            if(CFG.disc_en) document.getElementById('c-discount').innerText = '- ' + formatRupiah(disc);
            if(CFG.tax_en) document.getElementById('c-tax').innerText = formatRupiah(tax);
            document.getElementById('c-total').innerText = formatRupiah(grandTotal);
            
            document.getElementById('checkout-cart-input').value = JSON.stringify(cart);
            
            lucide.createIcons();
        }

        function toggleCheckoutForm() { 
            if(cart.length === 0) return;
            document.getElementById('checkout-form-modal').classList.toggle('hidden'); 
        }

        function handleOrderType() {
            const t = document.querySelector('input[name="order_type"]:checked')?.value;
            const a = document.getElementById('input-address');
            const tb = document.getElementById('input-table');
            
            a.classList.add('hidden'); 
            a.querySelector('textarea')?.removeAttribute('required'); 
            
            tb.classList.add('hidden'); 
            tb.querySelector('input')?.removeAttribute('required');
            
            if(t === 'delivery') { 
                a.classList.remove('hidden'); 
                a.querySelector('textarea').setAttribute('required', 'true'); 
            }
            if(t === 'dinein') { 
                tb.classList.remove('hidden'); 
                tb.querySelector('input').setAttribute('required', 'true'); 
            }
        }

        function handlePayment() {
            const m = document.querySelector('input[name="payment_method"]:checked')?.value;
            const d = document.getElementById('transfer-details');
            const p = document.getElementById('input-proof');
            
            if(m === 'transfer') { 
                d.classList.remove('hidden'); 
                p.setAttribute('required', 'true'); 
            } else { 
                d.classList.add('hidden'); 
                p.removeAttribute('required'); 
            }
        }

        function submitCheckout() { 
            if(cart.length === 0) {
                alert('Keranjang belanja kosong!');
                return false;
            }
            const btn = document.getElementById('btn-submit');
            btn.innerHTML = '<svg class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Memproses...'; 
            btn.classList.add('opacity-75', 'pointer-events-none'); 
            
            localStorage.removeItem('nvitens_cart_<?= $tenant_id ?>');
            return true; 
        }

        updateUI(); 
    </script>
</body>
</html>