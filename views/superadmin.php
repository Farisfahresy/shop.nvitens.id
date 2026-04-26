<?php
// Mencegah akses langsung tanpa melalui router
if (!defined('PDO::ATTR_ERRMODE')) exit;

$action = isset($route_parts[1]) ? $route_parts[1] : 'dashboard';

// ==========================================
// HANDLE POST ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    // Fitur Mengubah Status & Mengatur Username/Password Tenant dari PENDING ke ACTIVE
    if ($action === 'status') {
        $user_id = (int)$_POST['user_id'];
        $new_status = $_POST['status'];
        
        if ($new_status === 'active') {
            // Karena di Form Pendaftaran Awal tidak ada password, 
            // Superadmin WAJIB membuatkan username dan password saat menyetujui (Approve)
            $username = sanitize_input($_POST['new_username']);
            $password_input = $_POST['new_password'];
            
            if (empty($username) || empty($password_input)) {
                set_flash("Aktivasi Gagal: Username dan Password wajib diisi untuk mengaktifkan toko.", "error");
                header("Location: /superadmin/dashboard"); exit;
            }

            $password = password_hash($password_input, PASSWORD_DEFAULT);
            
            // Cek Ketersediaan Username secara Global
            $stmtCek = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmtCek->execute([$username, $user_id]);
            $stmtCekCashier = $pdo->prepare("SELECT id FROM cashiers WHERE username = ?");
            $stmtCekCashier->execute([$username]);

            if($stmtCek->fetch() || $stmtCekCashier->fetch()){
                set_flash("Gagal: Username '$username' sudah dipakai oleh Tenant/Kasir lain!", "error");
                header("Location: /superadmin/dashboard"); exit;
            }

            // Update user menjadi active dan simpan kredensial
            $stmt = $pdo->prepare("UPDATE users SET status = 'active', username = ?, password = ? WHERE id = ? AND role = 'tenant'");
            $stmt->execute([$username, $password, $user_id]);
            
            set_flash("Toko berhasil diaktifkan! Jangan lupa klik tombol WA untuk mengirim akun ke pemilik toko.");

        } elseif ($new_status === 'suspended') {
            $stmt = $pdo->prepare("UPDATE users SET status = 'suspended' WHERE id = ? AND role = 'tenant'");
            $stmt->execute([$user_id]);
            set_flash("Toko berhasil ditangguhkan/dibekukan sementara.");

        } elseif ($new_status === 'delete') {
            // Hard Delete (ON DELETE CASCADE akan membersihkan semua tabel relasi: products, orders, dll)
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ? AND role = 'tenant'");
            $stmt->execute([$user_id]);
            set_flash("Toko beserta seluruh database historisnya berhasil dihapus permanen.");
        }
        
        header("Location: /superadmin/dashboard"); exit;
    }
}

// ==========================================
// PENGAMBILAN DATA 
// ==========================================
// Ambil seluruh data tenant
$stmt = $pdo->query("
    SELECT u.id as user_id, u.name as owner_name, u.email, u.status, u.username,
           t.id as tenant_id, t.shop_name, t.slug, t.whatsapp, t.address, t.created_at
    FROM users u
    JOIN tenants t ON u.id = t.user_id
    WHERE u.role = 'tenant'
    ORDER BY u.status ASC, t.created_at DESC
");
$tenants = $stmt->fetchAll();

$pending_count = 0; $active_count = 0;
foreach($tenants as $t) {
    if($t['status'] === 'pending') $pending_count++;
    if($t['status'] === 'active') $active_count++;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Superadmin V3 - SaaS Control Panel</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>tailwind.config={theme:{extend:{fontFamily:{sans:['"Plus Jakarta Sans"','sans-serif']},colors:{brand:{500:'#f59e0b',600:'#d97706'}}}}}</script>
    <style>body{font-family:'Plus Jakarta Sans',sans-serif;background-color:#f8fafc;}</style>
</head>
<body class="text-slate-800 pb-20">

    <!-- Topbar Superadmin -->
    <nav class="bg-slate-900 text-white shadow-lg sticky top-0 z-40 border-b border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 bg-brand-500 rounded-lg flex items-center justify-center font-bold text-white shadow-inner"><i data-lucide="shield-check" class="w-4 h-4"></i></div>
                    <span class="font-extrabold text-xl tracking-tight">Superadmin</span>
                </div>
                <div class="flex items-center gap-4 border-l border-slate-700 pl-4">
                    <span class="text-sm font-medium text-slate-300 hidden sm:block">Halo, Faris</span>
                    <a href="/auth/logout" class="bg-red-500/10 text-red-500 hover:bg-red-500 hover:text-white px-3 py-1.5 rounded-lg text-sm font-bold transition flex items-center gap-2"><i data-lucide="log-out" class="w-4 h-4"></i> Keluar</a>
                </div>
            </div>
        </div>
    </nav>

    <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-8">
        
        <div class="mb-6 relative z-50">
            <?php if(function_exists('display_flash')) display_flash(); ?>
        </div>

        <!-- Global Stats -->
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-200 relative overflow-hidden">
                <p class="text-slate-500 font-extrabold text-xs uppercase tracking-wider mb-1 relative z-10">Total Terdaftar</p>
                <h3 class="text-4xl font-extrabold text-slate-900 relative z-10"><?= count($tenants) ?></h3>
                <i data-lucide="building-2" class="absolute right-[-10px] bottom-[-10px] w-24 h-24 text-slate-50 opacity-50 z-0"></i>
            </div>
            <div class="bg-amber-50 p-6 rounded-2xl shadow-sm border border-amber-200 relative overflow-hidden">
                <p class="text-amber-700 font-extrabold text-xs uppercase tracking-wider mb-1 relative z-10">Pending Approval</p>
                <h3 class="text-4xl font-extrabold text-amber-600 relative z-10"><?= $pending_count ?></h3>
                <i data-lucide="clock" class="absolute right-[-10px] bottom-[-10px] w-24 h-24 text-amber-100 opacity-50 z-0"></i>
            </div>
            <div class="bg-green-50 p-6 rounded-2xl shadow-sm border border-green-200 relative overflow-hidden">
                <p class="text-green-800 font-extrabold text-xs uppercase tracking-wider mb-1 relative z-10">Tenant Aktif</p>
                <h3 class="text-4xl font-extrabold text-green-600 relative z-10"><?= $active_count ?></h3>
                <i data-lucide="check-circle" class="absolute right-[-10px] bottom-[-10px] w-24 h-24 text-green-100 opacity-50 z-0"></i>
            </div>
        </div>

        <!-- Tabel Manajemen Tenant -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
            <div class="p-6 border-b border-slate-200 bg-slate-50 flex justify-between items-center">
                <h2 class="font-bold text-lg text-slate-800">Manajemen Database Toko (Tenant)</h2>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs text-slate-500 uppercase bg-white border-b border-slate-200">
                        <tr><th class="px-6 py-4">Toko & URL</th><th class="px-6 py-4">Pemilik & Kontak</th><th class="px-6 py-4">Status Akun</th><th class="px-6 py-4">Akses Login</th><th class="px-6 py-4 text-center">Aksi</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach($tenants as $t): ?>
                        <tr class="hover:bg-slate-50 transition <?= $t['status'] === 'pending' ? 'bg-amber-50/20' : '' ?>">
                            <td class="px-6 py-4">
                                <p class="font-extrabold text-slate-900 text-base mb-1"><?= htmlspecialchars($t['shop_name']) ?></p>
                                <a href="/<?= htmlspecialchars($t['slug']) ?>" target="_blank" class="inline-flex items-center gap-1 font-mono text-[10px] font-bold text-brand-600 bg-brand-50 px-2 py-0.5 rounded border border-brand-200 hover:bg-brand-100 transition">/<?= htmlspecialchars($t['slug']) ?> <i data-lucide="external-link" class="w-3 h-3"></i></a>
                                <p class="text-[10px] text-slate-400 mt-2 font-medium">Daftar: <?= date('d M Y', strtotime($t['created_at'])) ?></p>
                            </td>
                            <td class="px-6 py-4">
                                <p class="font-bold text-slate-800 mb-1"><?= htmlspecialchars($t['owner_name']) ?></p>
                                <p class="text-xs font-mono text-slate-600 flex items-center gap-1 mb-1"><i data-lucide="phone" class="w-3 h-3"></i> <?= htmlspecialchars($t['whatsapp']) ?></p>
                                <?php if($t['email']): ?><p class="text-[10px] text-slate-500 flex items-center gap-1"><i data-lucide="mail" class="w-3 h-3"></i> <?= htmlspecialchars($t['email']) ?></p><?php endif; ?>
                            </td>
                            <td class="px-6 py-4">
                                <?php 
                                    $bg='bg-amber-100 text-amber-700 border-amber-200'; $icon='clock';
                                    if($t['status']=='active'){ $bg='bg-green-100 text-green-700 border-green-200'; $icon='check-circle'; }
                                    elseif($t['status']=='suspended'){ $bg='bg-red-100 text-red-700 border-red-200'; $icon='ban'; }
                                ?>
                                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 text-[10px] font-extrabold uppercase rounded-lg border <?= $bg ?> shadow-sm"><i data-lucide="<?= $icon ?>" class="w-3 h-3"></i> <?= $t['status'] ?></span>
                            </td>
                            <td class="px-6 py-4">
                                <?php if($t['status'] === 'active' && !empty($t['username'])): ?>
                                    <div class="bg-slate-100 border border-slate-200 rounded-lg p-2 w-max">
                                        <p class="text-[10px] font-bold text-slate-500 uppercase mb-0.5">Username (Owner)</p>
                                        <p class="text-xs font-mono font-bold text-slate-900"><?= htmlspecialchars($t['username']) ?></p>
                                    </div>
                                <?php else: ?>
                                    <span class="text-xs text-slate-400 italic">Belum diset</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <button onclick='openStatusModal(<?= json_encode($t) ?>)' class="bg-slate-900 hover:bg-slate-800 text-white px-5 py-2.5 rounded-xl text-xs font-bold transition shadow-lg shadow-slate-900/20 active:scale-95 flex items-center gap-2 mx-auto"><i data-lucide="settings-2" class="w-4 h-4"></i> Kelola</button>
                            </td>
                        </tr>
                        <?php endforeach; if(empty($tenants)): ?><tr><td colspan="5" class="text-center py-12 text-slate-500 font-medium">Belum ada tenant yang mendaftar.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    <!-- Modal Kelola & Approval Tenant -->
    <div id="status-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-3xl shadow-2xl w-full max-w-md overflow-hidden transform scale-95 transition-transform" id="status-modal-content">
            <div class="px-6 py-4 border-b border-slate-100 flex justify-between items-center bg-slate-50">
                <h3 class="font-extrabold text-lg text-slate-900">Approval <span id="modal-shop-name" class="text-brand-600"></span></h3>
                <button type="button" onclick="closeStatusModal()" class="text-slate-400 bg-white border border-slate-200 hover:bg-slate-100 w-8 h-8 rounded-full flex items-center justify-center transition"><i data-lucide="x" class="w-4 h-4"></i></button>
            </div>
            
            <form action="/superadmin/status" method="POST" class="p-6" onsubmit="return confirmAction()">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" id="modal-user-id">
                
                <div class="space-y-5">
                    <div>
                        <label class="block text-sm font-bold text-slate-700 mb-2">Pilih Status Akun & Hak Akses</label>
                        <select name="status" id="modal-status-select" class="w-full px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl focus:ring-2 focus:ring-brand-500 outline-none font-bold text-sm transition" onchange="toggleAuthInputs()">
                            <option value="pending">Pending (Menunggu Review)</option>
                            <option value="active">Active (Setujui & Beri Akses)</option>
                            <option value="suspended">Suspend (Bekukan Sementara)</option>
                            <option value="delete">Hapus Permanen (Data Toko Dihapus)</option>
                        </select>
                    </div>

                    <!-- Input Username & Password (Hanya muncul jika status ACTIVE) -->
                    <div id="auth-inputs" class="hidden bg-blue-50 p-5 border border-blue-200 rounded-2xl space-y-4 shadow-inner">
                        <p class="text-[11px] font-extrabold text-blue-800 uppercase tracking-wider flex items-center gap-1.5"><i data-lucide="key" class="w-4 h-4"></i> Buat Kredensial Login Owner</p>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5">Buat Username Baru</label>
                            <input type="text" name="new_username" id="new_username" class="w-full px-4 py-2.5 border border-blue-200 rounded-xl outline-none focus:ring-2 focus:ring-blue-500 font-mono text-sm bg-white shadow-sm" placeholder="cth: kedaikopi_owner" onkeyup="this.value=this.value.toLowerCase().replace(/[^a-z0-9_]/g,''); updateWALink();">
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-slate-600 mb-1.5">Buat Password Baru <span id="pass-hint" class="text-amber-500 hidden font-normal">(Isi jika ingin mengubah sandi lama)</span></label>
                            <input type="text" name="new_password" id="new_password" class="w-full px-4 py-2.5 border border-blue-200 rounded-xl outline-none focus:ring-2 focus:ring-blue-500 font-mono text-sm bg-white shadow-sm" placeholder="Minimal 6 karakter" onkeyup="updateWALink()">
                        </div>
                        
                        <div class="mt-4 pt-4 border-t border-blue-200/50">
                            <a href="#" id="wa-link" target="_blank" class="w-full flex items-center justify-center gap-2 bg-[#25D366] hover:bg-[#20bd5a] text-white py-3 rounded-xl text-sm font-bold transition shadow-lg shadow-green-500/20 active:scale-95">
                                <i data-lucide="message-circle" class="w-5 h-5"></i> Kirim Akses Login ke WA Pelanggan
                            </a>
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full bg-slate-900 text-white font-bold py-4 rounded-xl shadow-xl shadow-slate-900/20 mt-8 hover:bg-slate-800 transition active:scale-95 flex justify-center items-center gap-2">
                    <i data-lucide="save" class="w-5 h-5"></i> Simpan Perubahan Status
                </button>
            </form>
        </div>
    </div>

    <script>
        lucide.createIcons();
        
        let currentWa = '';
        let currentShop = '';
        let isExistingUser = false;

        function openStatusModal(tenant) {
            const modal = document.getElementById('status-modal');
            const content = document.getElementById('status-modal-content');
            
            document.getElementById('modal-shop-name').innerText = tenant.shop_name;
            document.getElementById('modal-user-id').value = tenant.user_id;
            
            const sel = document.getElementById('modal-status-select');
            sel.value = tenant.status;
            
            currentWa = tenant.whatsapp;
            currentShop = tenant.shop_name;
            
            // Cek apakah sudah pernah di set username-nya (sudah active sebelumnya)
            if (tenant.username) {
                isExistingUser = true;
                document.getElementById('new_username').value = tenant.username;
                document.getElementById('pass-hint').classList.remove('hidden');
            } else {
                isExistingUser = false;
                document.getElementById('new_username').value = '';
                document.getElementById('pass-hint').classList.add('hidden');
            }
            document.getElementById('new_password').value = '';
            
            toggleAuthInputs();
            updateWALink();

            modal.classList.remove('hidden');
            setTimeout(() => { content.classList.remove('scale-95'); }, 10);
        }

        function closeStatusModal() {
            const content = document.getElementById('status-modal-content');
            content.classList.add('scale-95');
            setTimeout(() => { document.getElementById('status-modal').classList.add('hidden'); }, 150);
        }

        function toggleAuthInputs() {
            const status = document.getElementById('modal-status-select').value;
            const authDiv = document.getElementById('auth-inputs');
            const userInp = document.getElementById('new_username');
            const passInp = document.getElementById('new_password');
            
            if (status === 'active') {
                authDiv.classList.remove('hidden');
                userInp.setAttribute('required', 'true');
                if(!isExistingUser) passInp.setAttribute('required', 'true'); // Wajib jika user baru
                else passInp.removeAttribute('required'); // Opsional jika edit password lama
            } else {
                authDiv.classList.add('hidden');
                userInp.removeAttribute('required');
                passInp.removeAttribute('required');
            }
        }

        function updateWALink() {
            const user = document.getElementById('new_username').value;
            const pass = document.getElementById('new_password').value;
            const domain = window.location.host;
            
            let text = `Halo Kak dari *${currentShop}*! Pendaftaran toko online Anda di platform Nvitens sudah kami setujui (Active). 🎉%0A%0ABerikut adalah akses login untuk Dashboard Owner Anda:%0AURL Login: https://${domain}%0AUsername: *${user}*%0APassword: *${pass ? pass : '(Password lama Anda)'}*%0A%0AHarap simpan informasi ini baik-baik. Jika ada kendala, silakan balas pesan ini!`;
            
            let waNumber = currentWa;
            if(waNumber.startsWith('0')) waNumber = '62' + waNumber.substring(1);
            
            document.getElementById('wa-link').href = `https://wa.me/${waNumber}?text=${text}`;
        }

        function confirmAction() {
            const status = document.getElementById('modal-status-select').value;
            if(status === 'delete') {
                return confirm("PERINGATAN KRITIS!\n\nYakin ingin menghapus toko beserta SELURUH DATANYA (Produk, Pesanan, Laporan) secara permanen? Aksi ini tidak dapat dibatalkan.");
            }
            return true;
        }
    </script>
</body>
</html>