<?php
if (!defined('PDO::ATTR_ERRMODE')) exit;

date_default_timezone_set('Asia/Jakarta');
$pdo->exec("SET time_zone = '+07:00';");

$tenant_id = $_SESSION['tenant_id'] ?? 0;
$action = isset($route_parts[1]) ? $route_parts[1] : 'dashboard';
$is_owner = (isset($_SESSION['role']) && $_SESSION['role'] === 'tenant');
$user_id = $_SESSION['user_id'] ?? 0; 
$user_name = $_SESSION['username'] ?? 'Kasir';

// ==========================================
// HANDLE POST ACTIONS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf_token($_POST['csrf_token'] ?? '');
    
    // --- 1. MANAJEMEN KATEGORI ---
    if ($action === 'products' && isset($_POST['sub_action']) && $_POST['sub_action'] === 'cat_action' && $is_owner) {
        $cat_mode = $_POST['cat_mode'] ?? '';
        $name = sanitize_input($_POST['name'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        
        if ($cat_mode === 'add') {
            $stmt = $pdo->prepare("INSERT INTO categories (tenant_id, name) VALUES (?, ?)");
            $stmt->execute([$tenant_id, $name]);
            set_flash("Kategori baru ditambahkan.");
        } elseif ($cat_mode === 'delete') {
            verify_ownership($pdo, 'categories', $id, $tenant_id);
            $stmt = $pdo->prepare("UPDATE categories SET is_active = 0 WHERE id = ?");
            $stmt->execute([$id]);
            set_flash("Kategori dihapus.");
        }
        header("Location: /tenant/products"); exit;
    }

    // --- 2. MANAJEMEN PRODUK & STOK ---
    if ($action === 'products' && $is_owner && (!isset($_POST['sub_action']) || $_POST['sub_action'] !== 'cat_action')) {
        $sub_action = $_POST['sub_action'] ?? '';
        if ($sub_action === 'add' || $sub_action === 'edit') {
            $name = sanitize_input($_POST['name']);
            $cat_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
            $desc = sanitize_input($_POST['description'] ?? '');
            $price = (float)str_replace(['.', ','], '', $_POST['price']);
            $disc_price = (float)str_replace(['.', ','], '', $_POST['discount_price'] ?? 0);
            $stock = (int)$_POST['stock'];
            $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            
            try {
                $pdo->beginTransaction();
                $image_col_insert = ""; $image_val_insert = ""; $image_query_update = ""; 
                $params = [$cat_id, $name, $desc, $price, $disc_price, $stock];
                
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $upload = secure_upload($_FILES['image'], __DIR__ . '/../uploads/products');
                    if (!$upload['status']) throw new Exception($upload['message']);
                    $image_col_insert = ", image"; $image_val_insert = ", ?"; $image_query_update = ", image = ?";
                    $params[] = $upload['filename'];
                }

                if ($sub_action === 'add') {
                    $params[] = $tenant_id;
                    $stmt = $pdo->prepare("INSERT INTO products (category_id, name, description, price, discount_price, stock {$image_col_insert}, tenant_id) VALUES (?, ?, ?, ?, ?, ? {$image_val_insert}, ?)");
                    $stmt->execute($params);
                    $new_id = $pdo->lastInsertId();
                    $stmtLog = $pdo->prepare("INSERT INTO stock_logs (tenant_id, product_id, type, qty_change, final_stock, note) VALUES (?, ?, 'new', ?, ?, 'Input awal')");
                    $stmtLog->execute([$tenant_id, $new_id, $stock, $stock]);
                } else {
                    verify_ownership($pdo, 'products', $id, $tenant_id);
                    $stmtOld = $pdo->prepare("SELECT stock FROM products WHERE id = ?");
                    $stmtOld->execute([$id]); $oldStock = $stmtOld->fetchColumn();
                    $params[] = $id; $params[] = $tenant_id;
                    $stmt = $pdo->prepare("UPDATE products SET category_id=?, name=?, description=?, price=?, discount_price=?, stock=? {$image_query_update} WHERE id=? AND tenant_id=?");
                    $stmt->execute($params);

                    if ($oldStock !== false && $oldStock != $stock) {
                        $diff = $stock - $oldStock;
                        $note_edit = sanitize_input($_POST['edit_note'] ?? 'Penyesuaian stok');
                        $stmtLog = $pdo->prepare("INSERT INTO stock_logs (tenant_id, product_id, type, qty_change, final_stock, note) VALUES (?, ?, 'manual_edit', ?, ?, ?)");
                        $stmtLog->execute([$tenant_id, $id, $diff, $stock, $note_edit]);
                    }
                }
                $pdo->commit(); CacheManager::invalidate('tenant_products_' . $tenant_id); set_flash("Data produk disimpan.");
            } catch (Exception $e) { $pdo->rollBack(); set_flash("Gagal: " . $e->getMessage(), 'error'); }
        } elseif ($sub_action === 'delete') {
            $id = (int)$_POST['id']; verify_ownership($pdo, 'products', $id, $tenant_id);
            $stmt = $pdo->prepare("UPDATE products SET is_active = 0 WHERE id = ? AND tenant_id = ?"); $stmt->execute([$id, $tenant_id]);
            CacheManager::invalidate('tenant_products_' . $tenant_id); set_flash("Produk diarsipkan.");
        } elseif ($sub_action === 'restore') {
            $id = (int)$_POST['id']; verify_ownership($pdo, 'products', $id, $tenant_id);
            $stmt = $pdo->prepare("UPDATE products SET is_active = 1 WHERE id = ? AND tenant_id = ?"); $stmt->execute([$id, $tenant_id]);
            CacheManager::invalidate('tenant_products_' . $tenant_id); set_flash("Produk dipulihkan.");
        }
        header("Location: /tenant/products"); exit;
    }
    
    // --- 3. KASIR POS (CHECKOUT) ---
    if ($action === 'pos_checkout') {
        $cart = json_decode($_POST['cart_data'], true);
        $payment_method = sanitize_input($_POST['payment_method']);
        $cash_amount = (float)str_replace(['.', ','], '', $_POST['cash_amount'] ?? 0);
        $customer_name = sanitize_input($_POST['customer_name'] ?: 'Pelanggan POS');
        $customer_notes = sanitize_input($_POST['customer_notes'] ?? '');
        
        if (empty($cart)) { set_flash("Keranjang kosong!", "error"); header("Location: /tenant/pos"); exit; }
        $subtotal = 0;
        try {
            $pdo->beginTransaction();
            foreach ($cart as $key => $item) {
                $stmt = $pdo->prepare("SELECT price, discount_price, stock FROM products WHERE id = ? AND tenant_id = ? AND is_active = 1 FOR UPDATE");
                $stmt->execute([$item['id'], $tenant_id]); $product_db = $stmt->fetch();
                if (!$product_db) throw new Exception("Produk {$item['name']} tidak tersedia.");
                if ($product_db['stock'] < $item['qty']) throw new Exception("Stok {$item['name']} kurang.");
                
                $actual_price = ($product_db['discount_price'] > 0) ? $product_db['discount_price'] : $product_db['price'];
                $cart[$key]['price'] = $actual_price; $subtotal += ($actual_price * $item['qty']);
            }
            $discount_amount = 0;
            if ($tenant_data['enable_discount']) $discount_amount = ($tenant_data['discount_type'] === 'percent') ? ($subtotal * ($tenant_data['discount_value']/100)) : $tenant_data['discount_value'];
            $subtotal_after_discount = max(0, $subtotal - $discount_amount);
            $tax_amount = 0;
            if ($tenant_data['enable_tax']) $tax_amount = $subtotal_after_discount * ($tenant_data['tax_percentage'] / 100);
            
            $total_amount = $subtotal_after_discount + $tax_amount;
            if ($payment_method === 'tunai' && $cash_amount < $total_amount) throw new Exception("Uang tunai kurang!");

            $change = ($payment_method === 'tunai') ? ($cash_amount - $total_amount) : 0;
            $cashier_info = "Kasir: " . $user_name;

            $stmtOrder = $pdo->prepare("INSERT INTO orders (tenant_id, customer_name, order_type, address_table_no, customer_notes, subtotal, tax_amount, discount_amount, total_amount, payment_method, status, source) VALUES (?, ?, 'dinein', ?, ?, ?, ?, ?, ?, ?, 'completed', 'pos')");
            $stmtOrder->execute([$tenant_id, $customer_name, $cashier_info, $customer_notes, $subtotal, $tax_amount, $discount_amount, $total_amount, $payment_method]);
            $order_id = $pdo->lastInsertId();

            $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, product_id, quantity, price) VALUES (?, ?, ?, ?)");
            $stmtUpdateStock = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND tenant_id = ?");
            $stmtLog = $pdo->prepare("INSERT INTO stock_logs (tenant_id, product_id, type, qty_change, final_stock, note) VALUES (?, ?, 'sale_pos', ?, (SELECT stock FROM products WHERE id = ?), ?)");

            foreach ($cart as $item) {
                $stmtItem->execute([$order_id, $item['id'], $item['qty'], $item['price']]);
                $stmtUpdateStock->execute([$item['qty'], $item['id'], $tenant_id]);
                $stmtLog->execute([$tenant_id, $item['id'], -$item['qty'], $item['id'], "POS #ORD-" . $order_id]);
            }
            $pdo->commit(); CacheManager::invalidate('tenant_products_' . $tenant_id);
            
            $_SESSION['print_order_id'] = $order_id;
            $_SESSION['print_data'] = [
                'order' => ['id' => $order_id, 'date' => date('Y-m-d H:i:s'), 'customer' => $customer_name, 'notes' => $customer_notes, 'subtotal' => $subtotal, 'tax' => $tax_amount, 'discount' => $discount_amount, 'total' => $total_amount, 'payment_method' => $payment_method, 'cash' => $cash_amount, 'change' => $change, 'cashier' => $user_name],
                'items' => $cart, 'tenant' => ['name' => $tenant_data['shop_name'], 'address' => $tenant_data['address'], 'phone' => $tenant_data['whatsapp'], 'wifi' => $tenant_data['wifi_password'], 'footer' => $tenant_data['receipt_footer']]
            ];
            header("Location: /tenant/pos"); exit;
        } catch (Exception $e) { $pdo->rollBack(); set_flash($e->getMessage(), 'error'); header("Location: /tenant/pos"); exit; }
    }
    
    // --- 4. UBAH STATUS PESANAN ---
    if ($action === 'order_status') {
        $order_id = (int)$_POST['order_id']; $status = $_POST['status']; verify_ownership($pdo, 'orders', $order_id, $tenant_id);
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("UPDATE orders SET status = ? WHERE id = ? AND tenant_id = ?"); $stmt->execute([$status, $order_id, $tenant_id]);
            if ($status === 'cancelled') {
                $stmtItems = $pdo->prepare("SELECT product_id, quantity FROM order_items WHERE order_id = ?"); $stmtItems->execute([$order_id]); $items = $stmtItems->fetchAll();
                $stmtRetStock = $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ? AND tenant_id = ?");
                $stmtLog = $pdo->prepare("INSERT INTO stock_logs (tenant_id, product_id, type, qty_change, final_stock, note) VALUES (?, ?, 'return', ?, (SELECT stock FROM products WHERE id = ?), ?)");
                foreach ($items as $item) {
                    $stmtRetStock->execute([$item['quantity'], $item['product_id'], $tenant_id]);
                    $stmtLog->execute([$tenant_id, $item['product_id'], $item['quantity'], $item['product_id'], "Batal #ORD-" . $order_id]);
                }
                CacheManager::invalidate('tenant_products_' . $tenant_id);
            }
            $pdo->commit(); set_flash("Status pesanan diperbarui.");
        } catch (Exception $e) { $pdo->rollBack(); set_flash("Gagal mengubah status.", 'error'); }
        $referer = $_SERVER['HTTP_REFERER'] ?? ''; header("Location: /tenant/orders" . ($referer ? "?" . parse_url($referer, PHP_URL_QUERY) : "")); exit;
    }

    // --- 4.5 CETAK ULANG STRUK ---
    if ($action === 'orders' && isset($_POST['reprint_order_id'])) {
        $order_id = (int)$_POST['reprint_order_id'];
        verify_ownership($pdo, 'orders', $order_id, $tenant_id);

        $stmtOrder = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmtOrder->execute([$order_id]);
        $order = $stmtOrder->fetch();

        $stmtItems = $pdo->prepare("SELECT oi.quantity as qty, oi.price, p.name FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
        $stmtItems->execute([$order_id]);
        $items = $stmtItems->fetchAll();

        $_SESSION['print_order_id'] = $order_id;
        $_SESSION['print_data'] = [
            'order' => [
                'id' => $order_id, 
                'date' => $order['created_at'], 
                'customer' => $order['customer_name'] ?: 'Pelanggan', 
                'notes' => $order['customer_notes'], 
                'subtotal' => $order['subtotal'], 
                'tax' => $order['tax_amount'], 
                'discount' => $order['discount_amount'], 
                'total' => $order['total_amount'], 
                'payment_method' => $order['payment_method'], 
                'cash' => $order['total_amount'], // Diasumsikan tunai pas jika dicetak ulang
                'change' => 0, 
                'cashier' => $user_name
            ],
            'items' => $items, 
            'tenant' => [
                'name' => $tenant_data['shop_name'], 
                'address' => $tenant_data['address'], 
                'phone' => $tenant_data['whatsapp'], 
                'wifi' => $tenant_data['wifi_password'] ?? '', 
                'footer' => $tenant_data['receipt_footer'] ?? ''
            ]
        ];
        $_SESSION['force_print'] = true;

        $referer = $_SERVER['HTTP_REFERER'] ?? ''; header("Location: /tenant/orders" . ($referer ? "?" . parse_url($referer, PHP_URL_QUERY) : "")); exit;
    }

    // --- 5. STATUS RESERVASI ---
    if ($action === 'reservation_status') {
        $res_id = (int)$_POST['res_id']; $status = $_POST['status']; verify_ownership($pdo, 'reservations', $res_id, $tenant_id);
        $stmt = $pdo->prepare("UPDATE reservations SET status = ? WHERE id = ? AND tenant_id = ?"); $stmt->execute([$status, $res_id, $tenant_id]);
        set_flash("Status reservasi diperbarui."); header("Location: /tenant/reservations"); exit;
    }

    // --- 6. PENGELUARAN ---
    if ($action === 'expenses' && $is_owner) {
        $sub_action = $_POST['sub_action'] ?? '';
        if($sub_action === 'add') {
            $desc = sanitize_input($_POST['description']); $amount = (float)str_replace(['.', ','], '', $_POST['amount']); $date = sanitize_input($_POST['expense_date']);
            $stmt = $pdo->prepare("INSERT INTO expenses (tenant_id, description, amount, expense_date) VALUES (?, ?, ?, ?)"); $stmt->execute([$tenant_id, $desc, $amount, $date]); set_flash("Pengeluaran dicatat.");
        } elseif ($sub_action === 'delete') {
            $id = (int)$_POST['id']; verify_ownership($pdo, 'expenses', $id, $tenant_id);
            $stmt = $pdo->prepare("DELETE FROM expenses WHERE id = ? AND tenant_id = ?"); $stmt->execute([$id, $tenant_id]); set_flash("Pengeluaran dihapus.");
        }
        header("Location: /tenant/expenses"); exit;
    }

    // --- 7. KASIR ---
    if ($action === 'cashiers' && $is_owner) {
        $sub_action = $_POST['sub_action'] ?? '';
        if ($sub_action === 'add' || $sub_action === 'edit') {
            $name = sanitize_input($_POST['name']); $username = $tenant_data['slug'] . '_' . preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['username'])); $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
            $stmtCek = $pdo->prepare("SELECT id FROM cashiers WHERE username = ? AND id != ?"); $stmtCek->execute([$username, $id]);
            if ($stmtCek->fetch()) { set_flash("Username '$username' telah digunakan!", "error"); } else {
                if ($sub_action === 'add') {
                    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("INSERT INTO cashiers (tenant_id, username, password, name) VALUES (?, ?, ?, ?)"); $stmt->execute([$tenant_id, $username, $password, $name]);
                } else {
                    verify_ownership($pdo, 'cashiers', $id, $tenant_id);
                    $passQuery = ""; $params = [$name, $username];
                    if (!empty($_POST['password'])) { $passQuery = ", password = ?"; $params[] = password_hash($_POST['password'], PASSWORD_DEFAULT); }
                    $params[] = $id; $params[] = $tenant_id;
                    $stmt = $pdo->prepare("UPDATE cashiers SET name = ?, username = ? {$passQuery} WHERE id = ? AND tenant_id = ?"); $stmt->execute($params);
                }
                set_flash("Akun kasir disimpan.");
            }
        } elseif ($sub_action === 'delete') {
            $id = (int)$_POST['id']; verify_ownership($pdo, 'cashiers', $id, $tenant_id);
            $stmt = $pdo->prepare("DELETE FROM cashiers WHERE id = ? AND tenant_id = ?"); $stmt->execute([$id, $tenant_id]); set_flash("Kasir dihapus permanen.");
        }
        header("Location: /tenant/cashiers"); exit;
    }

    // --- 8. PENGATURAN TOKO ---
    if ($action === 'settings' && $is_owner) {
        $shop_name = sanitize_input($_POST['shop_name']); $address = sanitize_input($_POST['address']); $whatsapp = sanitize_input($_POST['whatsapp']);
        $ig = sanitize_input($_POST['ig_handle'] ?? ''); $wifi = sanitize_input($_POST['wifi_password'] ?? ''); $bank = sanitize_input($_POST['bank_account'] ?? ''); $footer = sanitize_input($_POST['receipt_footer'] ?? '');
        $is_open = isset($_POST['is_open']) ? 1 : 0; $allow_delivery = isset($_POST['allow_delivery']) ? 1 : 0; $allow_pickup = isset($_POST['allow_pickup']) ? 1 : 0; $allow_dinein = isset($_POST['allow_dinein']) ? 1 : 0;
        $enable_tax = isset($_POST['enable_tax']) ? 1 : 0; $tax_percentage = (float)($_POST['tax_percentage'] ?? 0);
        $enable_discount = isset($_POST['enable_discount']) ? 1 : 0; $discount_type = in_array($_POST['discount_type'], ['percent','nominal']) ? $_POST['discount_type'] : 'nominal'; $discount_value = (float)str_replace(['.', ','], '', $_POST['discount_value'] ?? 0);
        $enable_reservation = isset($_POST['enable_reservation']) ? 1 : 0; $auto_print = isset($_POST['auto_print']) ? 1 : 0;
        
        $logo_q = ""; $qris_q = ""; $params = [$shop_name, $address, $whatsapp, $ig, $wifi, $bank, $footer, $is_open, $allow_delivery, $allow_pickup, $allow_dinein, $enable_tax, $tax_percentage, $enable_discount, $discount_type, $discount_value, $enable_reservation, $auto_print];
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) { $up = secure_upload($_FILES['logo'], __DIR__ . '/../uploads/logos'); if ($up['status']) { $logo_q = ", logo = ?"; $params[] = $up['filename']; } }
        if (isset($_FILES['qris_image']) && $_FILES['qris_image']['error'] === UPLOAD_ERR_OK) { $up = secure_upload($_FILES['qris_image'], __DIR__ . '/../uploads/qris'); if ($up['status']) { $qris_q = ", qris_image = ?"; $params[] = $up['filename']; } }
        $params[] = $tenant_id;
        $stmt = $pdo->prepare("UPDATE tenants SET shop_name=?, address=?, whatsapp=?, ig_handle=?, wifi_password=?, bank_account=?, receipt_footer=?, is_open=?, allow_delivery=?, allow_pickup=?, allow_dinein=?, enable_tax=?, tax_percentage=?, enable_discount=?, discount_type=?, discount_value=?, enable_reservation=?, auto_print=? {$logo_q} {$qris_q} WHERE id=?");
        $stmt->execute($params); set_flash("Pengaturan Toko diperbarui."); header("Location: /tenant/settings"); exit;
    }
}

// ==========================================
// PENGAMBILAN DATA SESUAI VIEW
// ==========================================
if ($action === 'dashboard') {
    $start_date = isset($_GET['start_date']) ? sanitize_input($_GET['start_date']) : date('Y-m-d');
    $end_date = isset($_GET['end_date']) ? sanitize_input($_GET['end_date']) : date('Y-m-d');
    $stmtR = $pdo->prepare("SELECT COALESCE(SUM(subtotal), 0) as kotor, COALESCE(SUM(tax_amount), 0) as pajak, COALESCE(SUM(discount_amount), 0) as diskon, COUNT(id) as total_trx FROM orders WHERE tenant_id = ? AND status = 'completed' AND DATE(created_at) BETWEEN ? AND ?");
    $stmtR->execute([$tenant_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']); $lap = $stmtR->fetch();
    $stmtE = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM expenses WHERE tenant_id = ? AND expense_date BETWEEN ? AND ?");
    $stmtE->execute([$tenant_id, $start_date, $end_date]); $total_expenses = $stmtE->fetchColumn();
    $laba_bersih = ($lap['kotor'] - $lap['diskon'] + $lap['pajak']) - $total_expenses;
    $stmtRecent = $pdo->prepare("SELECT id, customer_name, total_amount, status, created_at, source, payment_method FROM orders WHERE tenant_id = ? ORDER BY created_at DESC LIMIT 5");
    $stmtRecent->execute([$tenant_id]); $recent_orders = $stmtRecent->fetchAll();
}
if ($action === 'orders') {
    $start_date = isset($_GET['start_date']) ? sanitize_input($_GET['start_date']) : date('Y-m-d'); 
    $end_date = isset($_GET['end_date']) ? sanitize_input($_GET['end_date']) : date('Y-m-d');   
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1; $limit = 50; $offset = ($page - 1) * $limit;
    $stmtCount = $pdo->prepare("SELECT COUNT(id) FROM orders WHERE tenant_id = ? AND DATE(created_at) BETWEEN ? AND ?");
    $stmtCount->execute([$tenant_id, $start_date . ' 00:00:00', $end_date . ' 23:59:59']); $total_orders = $stmtCount->fetchColumn(); $total_pages = ceil($total_orders / $limit);
    $stmtOrders = $pdo->prepare("SELECT * FROM orders WHERE tenant_id = :tid AND DATE(created_at) BETWEEN :sdate AND :edate ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
    $stmtOrders->bindValue(':tid', $tenant_id, \PDO::PARAM_INT); $stmtOrders->bindValue(':sdate', $start_date . ' 00:00:00', \PDO::PARAM_STR); $stmtOrders->bindValue(':edate', $end_date . ' 23:59:59', \PDO::PARAM_STR); $stmtOrders->bindValue(':limit', (int)$limit, \PDO::PARAM_INT); $stmtOrders->bindValue(':offset', (int)$offset, \PDO::PARAM_INT); $stmtOrders->execute(); 
    $all_orders = $stmtOrders->fetchAll();
    $stmtOrderItems = $pdo->prepare("SELECT oi.quantity, p.name FROM order_items oi JOIN products p ON oi.product_id = p.id WHERE oi.order_id = ?");
    foreach ($all_orders as $key => $order) { $stmtOrderItems->execute([$order['id']]); $all_orders[$key]['items'] = $stmtOrderItems->fetchAll(); }
}
if ($action === 'reservations') {
    $stmtRes = $pdo->prepare("SELECT * FROM reservations WHERE tenant_id = ? ORDER BY res_date DESC, res_time DESC LIMIT 100");
    $stmtRes->execute([$tenant_id]); $reservations = $stmtRes->fetchAll();
}
if ($action === 'products' || $action === 'pos') {
    $stmtC = $pdo->prepare("SELECT * FROM categories WHERE tenant_id = ? AND is_active = 1 ORDER BY name"); $stmtC->execute([$tenant_id]); $categories = $stmtC->fetchAll();
    $stmtP = $pdo->prepare("SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id WHERE p.tenant_id = ? ORDER BY p.is_active DESC, c.name ASC, p.name ASC"); $stmtP->execute([$tenant_id]); $products = $stmtP->fetchAll();
}
if ($action === 'stock_logs') {
    $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1; $limit = 50; $offset = ($page - 1) * $limit;
    $stmtCount = $pdo->prepare("SELECT COUNT(id) FROM stock_logs WHERE tenant_id = ?"); $stmtCount->execute([$tenant_id]); $total_logs = $stmtCount->fetchColumn(); $total_log_pages = ceil($total_logs / $limit);
    $stmtL = $pdo->prepare("SELECT l.*, p.name FROM stock_logs l JOIN products p ON l.product_id = p.id WHERE l.tenant_id = :tid ORDER BY l.created_at DESC LIMIT :limit OFFSET :offset");
    $stmtL->bindValue(':tid', $tenant_id, \PDO::PARAM_INT); $stmtL->bindValue(':limit', (int)$limit, \PDO::PARAM_INT); $stmtL->bindValue(':offset', (int)$offset, \PDO::PARAM_INT); $stmtL->execute(); $logs = $stmtL->fetchAll();
}
if ($action === 'expenses') {
    $stmtE = $pdo->prepare("SELECT * FROM expenses WHERE tenant_id = ? ORDER BY expense_date DESC LIMIT 50"); $stmtE->execute([$tenant_id]); $expenses = $stmtE->fetchAll();
}
if ($action === 'cashiers') {
    $stmtC = $pdo->prepare("SELECT * FROM cashiers WHERE tenant_id = ?"); $stmtC->execute([$tenant_id]); $cashiers = $stmtC->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Dashboard <?= htmlspecialchars($tenant_data['shop_name'] ?? 'Toko') ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <script>tailwind.config={theme:{extend:{fontFamily:{sans:['"Plus Jakarta Sans"','sans-serif']},colors:{brand:{50:'#fef3c7',500:'#f59e0b',600:'#d97706',900:'#78350f'}}}}}</script>
    <style>
        body{font-family:'Plus Jakarta Sans',sans-serif;background-color:#F8FAFC;}
        .hide-scrollbar::-webkit-scrollbar{display:none;}
        .hide-scrollbar{-ms-overflow-style:none;scrollbar-width:none;}
        /* CSS Printer Thermal Lebar Adaptif Maks 80mm */
        @media print{
            @page { margin: 0; }
            body{background:white;margin:0;padding:0;font-family:'Courier New',monospace;width:100%;max-width:80mm;color:black;} 
            .no-print{display:none !important;} 
            .print-only{display:block !important;padding:2mm; width:100%; box-sizing: border-box;}
        }
        .print-only{display:none;}
        input:checked + div{background-color:#f59e0b;border-color:#f59e0b;}
        input:checked + div > svg{display:block;}
    </style>
</head>
<body class="text-slate-800 flex h-[100dvh] overflow-hidden bg-slate-50">

    <!-- CETAK STRUK THERMAL (Hidden by Default) -->
    <?php if(isset($_SESSION['print_data'])): $printData = $_SESSION['print_data']; ?>
    <div class="print-only">
        <div style="text-align: center; margin-bottom: 10px;">
            <h2 style="margin: 0; font-size: 14px; font-weight: bold; text-transform: uppercase;"><?= htmlspecialchars($printData['tenant']['name']) ?></h2>
            <p style="margin: 2px 0; font-size: 9px; line-height: 1.2;"><?= htmlspecialchars($printData['tenant']['address']) ?></p>
            <?php if(!empty($printData['tenant']['phone'])): ?><p style="margin: 2px 0; font-size: 9px;">WA: <?= htmlspecialchars($printData['tenant']['phone']) ?></p><?php endif; ?>
        </div>
        <div style="border-bottom: 1px dashed #000; margin-bottom: 5px;"></div>
        <table style="width: 100%; font-size: 9px; margin-bottom: 5px; line-height: 1.2;">
            <tr><td style="text-align: left;">Tgl</td><td style="text-align: right;"><?= date('d/m/y H:i', strtotime($printData['order']['date'])) ?></td></tr>
            <tr><td style="text-align: left;">Kasir</td><td style="text-align: right;"><?= htmlspecialchars($printData['order']['cashier']) ?></td></tr>
            <tr><td style="text-align: left;">Cust</td><td style="text-align: right; font-weight: bold;"><?= htmlspecialchars($printData['order']['customer']) ?></td></tr>
        </table>
        <div style="border-bottom: 1px dashed #000; margin-bottom: 5px;"></div>
        <table style="width: 100%; font-size: 9px; margin-bottom: 5px; line-height: 1.2;">
            <?php foreach($printData['items'] as $item): ?>
            <tr><td colspan="2" style="text-align: left; font-weight: bold;"><?= htmlspecialchars($item['name']) ?></td></tr>
            <tr><td style="text-align: left; padding-bottom: 3px;"><?= $item['qty'] ?> x <?= number_format($item['price'], 0, ',', '.') ?></td><td style="text-align: right; padding-bottom: 3px;"><?= number_format($item['qty'] * $item['price'], 0, ',', '.') ?></td></tr>
            <?php endforeach; ?>
        </table>
        <div style="border-bottom: 1px dashed #000; margin-bottom: 5px;"></div>
        <table style="width: 100%; font-size: 10px; font-weight: bold; line-height: 1.3;">
            <?php if($printData['order']['discount'] > 0): ?><tr><td style="text-align: left; font-weight: normal;">Subtotal</td><td style="text-align: right; font-weight: normal;">Rp <?= number_format($printData['order']['subtotal'], 0, ',', '.') ?></td></tr><tr><td style="text-align: left; font-weight: normal;">Diskon</td><td style="text-align: right; font-weight: normal;">-Rp <?= number_format($printData['order']['discount'], 0, ',', '.') ?></td></tr><?php endif; ?>
            <?php if($printData['order']['tax'] > 0): ?><tr><td style="text-align: left; font-weight: normal;">Pajak</td><td style="text-align: right; font-weight: normal;">Rp <?= number_format($printData['order']['tax'], 0, ',', '.') ?></td></tr><?php endif; ?>
            <tr><td style="text-align: left; font-size: 11px;">TOTAL</td><td style="text-align: right; font-size: 11px;">Rp <?= number_format($printData['order']['total'], 0, ',', '.') ?></td></tr>
            <?php if($printData['order']['payment_method'] === 'tunai'): ?>
            <tr><td style="text-align: left; font-weight: normal;">TUNAI</td><td style="text-align: right; font-weight: normal;">Rp <?= number_format($printData['order']['cash'], 0, ',', '.') ?></td></tr>
            <tr><td style="text-align: left; font-weight: normal;">KEMBALI</td><td style="text-align: right; font-weight: normal;">Rp <?= number_format($printData['order']['change'], 0, ',', '.') ?></td></tr>
            <?php else: ?>
            <tr><td style="text-align: left; font-weight: normal;">BAYAR</td><td style="text-align: right; font-weight: normal; text-transform: uppercase;"><?= $printData['order']['payment_method'] ?></td></tr>
            <?php endif; ?>
        </table>
        <?php if(!empty($printData['order']['notes'])): ?><div style="border-bottom: 1px dashed #000; margin-bottom: 5px;"></div><p style="margin: 2px 0; font-size: 9px; font-weight: bold;">Catatan: <?= htmlspecialchars($printData['order']['notes']) ?></p><?php endif; ?>
        <div style="border-bottom: 1px dashed #000; margin: 8px 0 5px 0;"></div>
        <div style="text-align: center; font-size: 9px; margin-top: 8px;">
            <?php if(!empty($printData['tenant']['wifi'])): ?><p style="margin:2px 0; border:1px solid #000; padding:2px; display:inline-block;">WiFi: <?= htmlspecialchars($printData['tenant']['wifi']) ?></p><?php endif; ?>
            <?php if(!empty($printData['tenant']['footer'])): ?><p style="margin:4px 0 0 0; white-space: pre-line;"><?= htmlspecialchars($printData['tenant']['footer']) ?></p><?php endif; ?>
            <p style="margin:4px 0 0 0; font-weight:bold;">*** TERIMA KASIH ***</p>
        </div>
    </div>
    <script>window.onload=function(){<?php if($tenant_data['auto_print'] || isset($_SESSION['force_print'])): ?> window.print(); <?php endif; ?>}</script>
    <?php unset($_SESSION['print_data']); unset($_SESSION['print_order_id']); unset($_SESSION['force_print']); endif; ?>

    <!-- OVERLAY SIDEBAR MOBILE -->
    <div id="sidebar-overlay" class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm z-40 hidden lg:hidden" onclick="toggleSidebar()"></div>

    <!-- SIDEBAR NAVIGATION -->
    <aside id="sidebar" class="fixed inset-y-0 left-0 z-50 w-64 bg-slate-900 text-white flex flex-col transform -translate-x-full lg:relative lg:translate-x-0 transition-transform duration-300 ease-in-out no-print shadow-2xl lg:shadow-none h-[100dvh]">
        <div class="h-16 flex items-center justify-between px-6 border-b border-slate-800 bg-slate-900/50 shrink-0">
            <div class="flex items-center">
                <div class="w-8 h-8 bg-brand-500 rounded-lg flex items-center justify-center shadow-lg"><i data-lucide="zap" class="w-4 h-4 text-white"></i></div>
                <span class="ml-3 font-extrabold text-lg tracking-tight text-white line-clamp-1"><?= htmlspecialchars($tenant_data['shop_name'] ?? 'Toko') ?></span>
            </div>
            <button class="lg:hidden text-slate-400 hover:text-white" onclick="toggleSidebar()"><i data-lucide="x" class="w-6 h-6"></i></button>
        </div>
        
        <div class="px-6 py-3 bg-slate-800/50 border-b border-slate-800/50 lg:hidden flex items-center gap-3 shrink-0">
            <div class="w-10 h-10 bg-brand-500 text-white rounded-full flex items-center justify-center font-bold text-lg"><?= strtoupper(substr($user_name,0,1)) ?></div>
            <div><p class="font-bold text-sm leading-tight"><?= htmlspecialchars($user_name) ?></p><p class="text-[10px] text-slate-400 uppercase tracking-widest"><?= $is_owner ? 'Owner' : 'Kasir' ?></p></div>
        </div>

        <nav class="flex-1 py-4 overflow-y-auto hide-scrollbar space-y-1 px-3">
            <a href="/tenant/dashboard" class="flex items-center px-4 py-3 text-slate-400 hover:text-white hover:bg-slate-800 rounded-xl transition <?= $action==='dashboard'?'bg-brand-500 text-white hover:bg-brand-600':'' ?>"><i data-lucide="layout-dashboard" class="w-5 h-5 mr-3 shrink-0"></i><span class="font-medium text-sm">Dashboard</span></a>
            <a href="/tenant/pos" class="flex items-center px-4 py-3 text-slate-400 hover:text-white hover:bg-slate-800 rounded-xl transition <?= $action==='pos'?'bg-brand-500 text-white hover:bg-brand-600':'' ?>"><i data-lucide="calculator" class="w-5 h-5 mr-3 shrink-0"></i><span class="font-medium text-sm">Kasir POS</span></a>
            
            <div class="pt-4 pb-2 px-4"><p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Transaksi</p></div>
            <a href="/tenant/orders" class="flex items-center px-4 py-3 text-slate-400 hover:text-white hover:bg-slate-800 rounded-xl transition relative <?= $action==='orders'?'bg-brand-500 text-white hover:bg-brand-600':'' ?>"><i data-lucide="clipboard-list" class="w-5 h-5 mr-3 shrink-0"></i><span class="font-medium text-sm">Pesanan Masuk</span> <span id="nav-badge-order" class="absolute right-4 top-1/2 -translate-y-1/2 w-2 h-2 bg-red-500 rounded-full hidden"></span></a>
            <?php if($tenant_data['enable_reservation']): ?>
            <a href="/tenant/reservations" class="flex items-center px-4 py-3 text-slate-400 hover:text-white hover:bg-slate-800 rounded-xl transition relative <?= $action==='reservations'?'bg-brand-500 text-white hover:bg-brand-600':'' ?>"><i data-lucide="calendar" class="w-5 h-5 mr-3 shrink-0"></i><span class="font-medium text-sm">Reservasi Meja</span> <span id="nav-badge-res" class="absolute right-4 top-1/2 -translate-y-1/2 w-2 h-2 bg-red-500 rounded-full hidden"></span></a>
            <?php endif; ?>
            
            <?php if($is_owner): ?>
            <div class="pt-4 pb-2 px-4"><p class="text-[10px] font-bold text-slate-500 uppercase tracking-wider">Manajemen</p></div>
            <a href="/tenant/products" class="flex items-center px-4 py-3 text-slate-400 hover:text-white hover:bg-slate-800 rounded-xl transition <?= $action==='products'?'bg-brand-500 text-white hover:bg-brand-600':'' ?>"><i data-lucide="package" class="w-5 h-5 mr-3 shrink-0"></i><span class="font-medium text-sm">Menu & Kategori</span></a>
            <a href="/tenant/expenses" class="flex items-center px-4 py-3 text-slate-400 hover:text-white hover:bg-slate-800 rounded-xl transition <?= $action==='expenses'?'bg-brand-500 text-white hover:bg-brand-600':'' ?>"><i data-lucide="receipt" class="w-5 h-5 mr-3 shrink-0"></i><span class="font-medium text-sm">Pengeluaran</span></a>
            <a href="/tenant/cashiers" class="flex items-center px-4 py-3 text-slate-400 hover:text-white hover:bg-slate-800 rounded-xl transition <?= $action==='cashiers'?'bg-brand-500 text-white hover:bg-brand-600':'' ?>"><i data-lucide="users" class="w-5 h-5 mr-3 shrink-0"></i><span class="font-medium text-sm">Pegawai Kasir</span></a>
            <a href="/tenant/stock_logs" class="flex items-center px-4 py-3 text-slate-400 hover:text-white hover:bg-slate-800 rounded-xl transition <?= $action==='stock_logs'?'bg-brand-500 text-white hover:bg-brand-600':'' ?>"><i data-lucide="history" class="w-5 h-5 mr-3 shrink-0"></i><span class="font-medium text-sm">Riwayat Stok</span></a>
            <a href="/tenant/settings" class="flex items-center px-4 py-3 text-slate-400 hover:text-white hover:bg-slate-800 rounded-xl transition <?= $action==='settings'?'bg-brand-500 text-white hover:bg-brand-600':'' ?>"><i data-lucide="settings" class="w-5 h-5 mr-3 shrink-0"></i><span class="font-medium text-sm">Pengaturan Toko</span></a>
            <?php endif; ?>
        </nav>
        
        <div class="p-4 border-t border-slate-800 bg-slate-900/50 shrink-0">
            <a href="/auth/logout" class="flex items-center justify-center px-4 py-2.5 bg-red-500/10 text-red-500 hover:bg-red-500 hover:text-white rounded-xl transition"><i data-lucide="log-out" class="w-5 h-5 mr-2"></i><span class="font-bold text-sm">Keluar</span></a>
        </div>
    </aside>

    <!-- MAIN WRAPPER -->
    <div class="flex-1 flex flex-col min-w-0 overflow-hidden no-print relative">
        
        <!-- TOPBAR -->
        <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 lg:px-6 z-10 shrink-0">
            <div class="flex items-center gap-3">
                <button class="lg:hidden p-2 text-slate-600 hover:bg-slate-100 rounded-lg mr-1" onclick="toggleSidebar()"><i data-lucide="menu" class="w-6 h-6"></i></button>
                <h2 class="text-lg lg:text-xl font-bold text-slate-800 capitalize leading-none truncate max-w-[120px] sm:max-w-xs"><?= str_replace('_', ' ', $action) ?></h2>
                <?php if($tenant_data['is_open'] == 0): ?><span class="bg-red-100 text-red-600 text-[10px] font-bold px-2 py-0.5 rounded uppercase border border-red-200 hidden sm:block">Toko Tutup</span><?php endif; ?>
            </div>
            <div class="flex items-center gap-2 lg:gap-4">
                <button id="btn-audio-init" class="w-8 h-8 lg:w-10 lg:h-10 rounded-full bg-slate-100 text-slate-400 hover:text-brand-500 flex items-center justify-center transition" title="Aktifkan Suara Notifikasi"><i data-lucide="bell" class="w-4 h-4 lg:w-5 lg:h-5"></i></button>
                <a href="/<?= $tenant_data['slug'] ?? '' ?>" target="_blank" class="hidden sm:flex items-center gap-2 text-sm font-bold bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-1.5 rounded-lg border border-slate-200 transition"><i data-lucide="external-link" class="w-4 h-4"></i> Web Toko</a>
                <div class="hidden lg:flex items-center gap-3 pl-4 border-l border-slate-200">
                    <div class="text-right"><p class="text-sm font-bold text-slate-900 leading-tight"><?= htmlspecialchars($user_name) ?></p><p class="text-[10px] font-bold text-slate-500 uppercase tracking-widest"><?= $is_owner ? 'Owner' : 'Kasir' ?></p></div>
                    <div class="w-9 h-9 bg-brand-100 text-brand-600 rounded-full flex items-center justify-center font-bold border border-brand-200 shadow-sm"><?= strtoupper(substr($user_name,0,1)) ?></div>
                </div>
            </div>
        </header>

        <div class="absolute top-20 right-4 lg:right-6 z-[70] pointer-events-none"><?php if(function_exists('display_flash')) display_flash(); ?></div>

        <!-- MAIN SCROLLABLE AREA -->
        <main class="flex-1 overflow-y-auto p-4 lg:p-6 pb-36 lg:pb-10 relative bg-slate-50 <?= $action === 'pos' ? 'flex flex-col' : '' ?>">
            
            <?php if ($action === 'dashboard'): ?>
            <!-- ================= DASHBOARD ================= -->
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-end mb-6 gap-4">
                <div><h1 class="text-xl lg:text-2xl font-bold text-slate-900">Laporan Keuangan</h1><p class="text-xs lg:text-sm text-slate-500 mt-1">Laba = Kotor - Diskon + Pajak - Pengeluaran</p></div>
                <form method="GET" action="/tenant/dashboard" class="flex flex-wrap items-center bg-white border border-slate-200 p-1.5 rounded-xl shadow-sm gap-1 w-full sm:w-auto">
                    <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" required class="flex-1 sm:w-auto px-2 py-1.5 text-xs lg:text-sm bg-transparent outline-none font-medium text-slate-700">
                    <span class="text-slate-400 mx-1 hidden sm:inline">-</span>
                    <input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" required class="flex-1 sm:w-auto px-2 py-1.5 text-xs lg:text-sm bg-transparent outline-none font-medium text-slate-700">
                    <button type="submit" class="w-full sm:w-auto mt-2 sm:mt-0 bg-slate-900 hover:bg-slate-800 text-white px-4 py-2 rounded-lg text-sm font-bold transition">Filter</button>
                </form>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4 mb-6">
                <div class="bg-white rounded-2xl p-4 lg:p-5 border border-slate-200 shadow-sm"><p class="text-[10px] lg:text-xs font-bold text-slate-500 uppercase mb-1">Pendapatan Kotor</p><h3 class="text-lg lg:text-xl font-extrabold text-slate-900 truncate">Rp <?= number_format($lap['kotor'] ?? 0,0,',','.') ?></h3></div>
                <div class="bg-white rounded-2xl p-4 lg:p-5 border border-slate-200 shadow-sm"><div class="flex justify-between items-center mb-1"><p class="text-[10px] lg:text-xs font-bold text-red-500 uppercase">Diskon</p><p class="text-[10px] lg:text-xs font-bold text-blue-500 uppercase">Pajak</p></div><div class="flex justify-between items-center"><h3 class="text-base lg:text-lg font-bold text-red-600 truncate mr-2">-Rp <?= number_format($lap['diskon'] ?? 0,0,',','.') ?></h3><h3 class="text-base lg:text-lg font-bold text-blue-600 truncate">+Rp <?= number_format($lap['pajak'] ?? 0,0,',','.') ?></h3></div></div>
                <div class="bg-white rounded-2xl p-4 lg:p-5 border border-slate-200 shadow-sm"><p class="text-[10px] lg:text-xs font-bold text-amber-600 uppercase mb-1">Total Pengeluaran</p><h3 class="text-lg lg:text-xl font-extrabold text-amber-600 truncate">-Rp <?= number_format($total_expenses ?? 0,0,',','.') ?></h3></div>
                <div class="bg-slate-900 rounded-2xl p-4 lg:p-5 border border-slate-800 shadow-lg text-white"><p class="text-[10px] lg:text-xs font-bold text-slate-300 uppercase mb-1">Laba Bersih</p><h3 class="text-xl lg:text-2xl font-extrabold text-brand-400 truncate">Rp <?= number_format($laba_bersih,0,',','.') ?></h3></div>
            </div>
            
            <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
                <div class="xl:col-span-2 bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                    <div class="p-4 lg:p-5 border-b border-slate-100 flex justify-between items-center"><h3 class="font-bold text-slate-800 text-sm lg:text-base">Transaksi Terakhir</h3><a href="/tenant/orders" class="text-xs font-bold text-brand-600 hover:underline">Lihat Semua</a></div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm text-left whitespace-nowrap">
                            <thead class="text-xs text-slate-500 uppercase bg-slate-50"><tr><th class="px-4 lg:px-5 py-3">Waktu</th><th class="px-4 lg:px-5 py-3">Pelanggan</th><th class="px-4 lg:px-5 py-3">Total</th><th class="px-4 lg:px-5 py-3">Tipe</th></tr></thead>
                            <tbody class="divide-y divide-slate-100">
                                <?php foreach($recent_orders as $ro): if($ro['status'] !== 'completed') continue; ?>
                                <tr class="hover:bg-slate-50"><td class="px-4 lg:px-5 py-3"><p class="font-bold text-slate-900"><?= date('H:i', strtotime($ro['created_at'])) ?></p><p class="text-[10px] text-slate-500"><?= date('d/m', strtotime($ro['created_at'])) ?></p></td><td class="px-4 lg:px-5 py-3 font-bold text-slate-900"><?= htmlspecialchars($ro['customer_name']) ?></td><td class="px-4 lg:px-5 py-3 text-brand-600 font-bold">Rp <?= number_format($ro['total_amount'],0,',','.') ?></td><td class="px-4 lg:px-5 py-3"><span class="px-2 py-1 text-[10px] font-bold uppercase rounded border bg-slate-100 text-slate-600"><?= $ro['source'] ?></span></td></tr>
                                <?php endforeach; if(empty($recent_orders)): ?><tr><td colspan="4" class="text-center py-6 text-slate-400 italic">Belum ada transaksi.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="bg-white rounded-2xl border border-slate-200 shadow-sm p-6 flex flex-col justify-center items-center text-center h-48 xl:h-auto">
                    <div class="w-16 h-16 lg:w-20 lg:h-20 bg-brand-50 rounded-full flex items-center justify-center text-brand-500 mb-4"><i data-lucide="shopping-bag" class="w-8 h-8 lg:w-10 lg:h-10"></i></div>
                    <h4 class="text-3xl lg:text-4xl font-extrabold text-slate-900 mb-1"><?= number_format($lap['total_trx'] ?? 0) ?></h4>
                    <p class="text-xs lg:text-sm font-bold text-slate-500 uppercase tracking-wider">Pesanan Sukses</p>
                </div>
            </div>
            
            <?php elseif ($action === 'products' && $is_owner): ?>
            <!-- ================= MANAJEMEN PRODUK ================= -->
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-6 gap-4">
                <h1 class="text-xl lg:text-2xl font-bold text-slate-900">Katalog Menu & Kategori</h1>
                <div class="flex flex-wrap gap-2 w-full sm:w-auto">
                    <button onclick="document.getElementById('modal-category-box').classList.remove('hidden')" class="flex-1 sm:flex-none justify-center bg-white border border-slate-300 text-slate-700 px-4 py-2.5 rounded-xl font-bold flex items-center gap-2 shadow-sm text-sm"><i data-lucide="list" class="w-4 h-4"></i> Kategori</button>
                    <button onclick="openModalAddProduct()" class="flex-1 sm:flex-none justify-center bg-slate-900 text-white px-4 py-2.5 rounded-xl font-bold flex items-center gap-2 shadow-sm text-sm"><i data-lucide="plus" class="w-4 h-4"></i> Tambah Menu</button>
                </div>
            </div>
            
            <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-4 gap-3 lg:gap-6">
                <?php foreach ($products as $p): $is_del = ($p['is_active'] == 0); ?>
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200 flex flex-col relative group <?= $is_del ? 'opacity-60 grayscale' : '' ?>">
                    <?php if($is_del): ?><div class="absolute top-2 left-2 bg-red-600 text-white text-[9px] px-2 py-0.5 rounded backdrop-blur z-10 font-bold">DIARSIPKAN</div>
                    <?php else: ?>
                        <div class="absolute top-2 left-2 bg-slate-900/80 text-white text-[9px] px-2 py-0.5 rounded backdrop-blur z-10 uppercase font-bold truncate max-w-[80px]"><?= htmlspecialchars($p['category_name'] ?? 'Umum') ?></div>
                        <div class="absolute top-2 right-2 bg-white/95 px-1.5 py-0.5 rounded text-[9px] font-bold z-10 border border-slate-200">Stok: <span class="<?= $p['stock'] < 5 ? 'text-red-600' : 'text-green-600' ?>"><?= $p['stock'] ?></span></div>
                        <?php if($p['discount_price'] > 0): ?><div class="absolute bottom-2 right-2 bg-red-500 text-white text-[9px] px-1.5 rounded font-bold z-10">PROMO</div><?php endif; ?>
                    <?php endif; ?>
                    <div class="h-28 lg:h-40 bg-slate-100 flex items-center justify-center relative overflow-hidden rounded-t-2xl">
                        <?php if($p['image']): ?><img src="/uploads/products/<?= htmlspecialchars($p['image']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-500"><?php else: ?><i data-lucide="image" class="w-8 h-8 text-slate-300"></i><?php endif; ?>
                    </div>
                    <div class="p-3 lg:p-4 flex-1 flex flex-col">
                        <h3 class="font-bold text-xs lg:text-sm text-slate-900 leading-tight mb-1 line-clamp-2 <?= $is_del ? 'line-through text-slate-500' : '' ?>"><?= htmlspecialchars($p['name']) ?></h3>
                        <div class="mb-3 mt-auto">
                            <?php if($p['discount_price'] > 0 && !$is_del): ?>
                                <p class="text-[9px] lg:text-[10px] text-slate-400 line-through">Rp <?= number_format($p['price'], 0, ',', '.') ?></p>
                                <p class="text-brand-600 font-extrabold text-xs lg:text-sm">Rp <?= number_format($p['discount_price'], 0, ',', '.') ?></p>
                            <?php else: ?><p class="text-brand-600 font-extrabold text-xs lg:text-sm">Rp <?= number_format($p['price'], 0, ',', '.') ?></p><?php endif; ?>
                        </div>
                        <div class="flex gap-1.5">
                            <?php if($is_del): ?>
                            <form action="/tenant/products" method="POST" class="w-full"><?= csrf_field() ?> <input type="hidden" name="sub_action" value="restore"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button type="submit" class="w-full py-1.5 bg-green-50 border border-green-200 text-green-700 rounded-lg text-[10px] lg:text-xs font-bold flex justify-center items-center gap-1"><i data-lucide="refresh-cw" class="w-3 h-3"></i> Pulihkan</button></form>
                            <?php else: ?>
                            <button type="button" onclick='openModalEditProduct(<?= json_encode($p) ?>)' class="flex-1 py-1.5 bg-slate-50 border border-slate-200 text-slate-700 rounded-lg text-[10px] lg:text-xs font-bold flex justify-center items-center gap-1"><i data-lucide="edit-3" class="w-3 h-3"></i> Edit</button>
                            <form action="/tenant/products" method="POST" onsubmit="return confirm('Arsipkan produk ini?');" class="w-8 lg:w-10 shrink-0"><?= csrf_field() ?> <input type="hidden" name="sub_action" value="delete"><input type="hidden" name="id" value="<?= $p['id'] ?>"><button type="submit" class="w-full h-full bg-red-50 border border-red-100 text-red-600 rounded-lg flex justify-center items-center"><i data-lucide="archive" class="w-3 h-3"></i></button></form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; if(empty($products)): ?><div class="col-span-full text-center py-12 bg-white rounded-2xl border shadow-sm"><p class="font-bold text-slate-500">Belum ada menu.</p></div><?php endif; ?>
            </div>

            <?php elseif ($action === 'pos'): ?>
            <!-- ================= KASIR POS ================= -->
            <div class="flex flex-col lg:flex-row gap-4 lg:gap-6 flex-1 min-h-0 relative">
                 <!-- Kiri: Grid Produk -->
                <div class="flex-1 flex flex-col min-w-0">
                    <div class="flex gap-2 mb-3 overflow-x-auto hide-scrollbar pb-1">
                        <button class="cat-btn px-4 py-1.5 bg-slate-900 text-white rounded-full text-xs lg:text-sm font-bold shrink-0 transition" onclick="filterCat('all', this)">Semua</button>
                        <?php foreach($categories as $c): ?><button class="cat-btn px-4 py-1.5 bg-white border border-slate-200 text-slate-600 rounded-full text-xs lg:text-sm font-bold shrink-0 transition" onclick="filterCat(<?= $c['id'] ?>, this)"><?= htmlspecialchars($c['name']) ?></button><?php endforeach; ?>
                    </div>
                    <div class="relative mb-4"><i data-lucide="search" class="absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 w-4 h-4 lg:w-5 lg:h-5"></i><input type="text" id="search-pos" placeholder="Cari menu (Ketik/Barcode)..." class="w-full pl-10 lg:pl-12 pr-4 py-2.5 lg:py-3 bg-white border border-slate-200 rounded-xl outline-none text-sm lg:text-base shadow-sm focus:ring-2 focus:ring-brand-500 transition" onkeyup="filterMenu()"></div>
                    
                    
                    <div class="flex-1 overflow-y-auto pr-1 hide-scrollbar pb-2">
                        <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-4 gap-2 lg:gap-3" id="pos-grid">
                            <?php foreach($products as $p): if($p['is_active'] == 0) continue; $actual_price = ($p['discount_price'] > 0) ? $p['discount_price'] : $p['price']; ?>
                            <div class="pos-item bg-white border border-slate-100 rounded-xl lg:rounded-2xl p-2 cursor-pointer hover:border-brand-500 hover:shadow-md flex flex-col <?= $p['stock'] < 1 ? 'opacity-50 grayscale pointer-events-none' : '' ?>" onclick="addToCart(<?= $p['id'] ?>, '<?= addslashes($p['name']) ?>', <?= $actual_price ?>, <?= $p['stock'] ?>)" data-name="<?= strtolower($p['name']) ?>" data-cat="<?= $p['category_id'] ?>">
                                <div class="h-20 lg:h-24 bg-slate-100 rounded-lg lg:rounded-xl mb-2 relative overflow-hidden">
                                    <?php if($p['image']): ?><img src="/uploads/products/<?= htmlspecialchars($p['image']) ?>" class="w-full h-full object-cover"><?php endif; ?>
                                    <?php if($p['discount_price'] > 0): ?><div class="absolute top-1 right-1 bg-red-600 text-white text-[8px] lg:text-[9px] px-1 rounded font-bold">PROMO</div><?php endif; ?>
                                </div>
                                <div class="px-1 flex-1 flex flex-col"><h4 class="font-bold text-[10px] lg:text-xs text-slate-900 leading-tight mb-1 line-clamp-2"><?= htmlspecialchars($p['name']) ?></h4><div class="mt-auto flex justify-between items-center"><p class="text-brand-600 font-extrabold text-xs">Rp<?= number_format($actual_price,0,',','.') ?></p><span class="text-[8px] lg:text-[9px] font-bold text-slate-500 bg-slate-100 px-1 rounded">Stok: <?= $p['stock'] ?></span></div></div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <!-- Pesan jika pencarian kosong -->
                        <div id="no-result-msg" class="hidden absolute inset-0 flex flex-col items-center justify-center text-slate-400 mt-10">
                            <i data-lucide="search-x" class="w-10 h-10 mb-2"></i>
                            <p class="font-bold text-sm">Menu tidak ditemukan</p>
                        </div>
                    </div>
                </div>

                <!-- Floating Cart Button (Mobile Only) -->
                <button id="mobile-cart-btn" class="lg:hidden fixed bottom-6 left-4 right-4 bg-slate-900 text-white p-4 rounded-2xl shadow-2xl z-40 flex justify-between items-center transition-transform active:scale-95 border border-slate-700 translate-y-32" onclick="toggleMobileCart()">
                    <div class="flex items-center gap-3">
                        <div class="bg-white/20 w-10 h-10 rounded-xl flex items-center justify-center relative"><i data-lucide="shopping-bag" class="w-5 h-5"></i><span id="mobile-cart-badge" class="absolute -top-2 -right-2 bg-red-500 text-white text-[10px] font-bold w-5 h-5 flex items-center justify-center rounded-full border-2 border-slate-900">0</span></div>
                        <div class="text-left"><p class="text-xs text-slate-400 font-medium">Total Pesanan</p><p class="font-bold text-lg leading-none mt-0.5" id="mobile-cart-total">Rp 0</p></div>
                    </div>
                    <div class="font-bold text-sm bg-white/10 px-4 py-2 rounded-xl flex items-center gap-1">Lihat <i data-lucide="chevron-up" class="w-4 h-4"></i></div>
                </button>

                <!-- Kanan: Panel Cart (Drawer di Mobile, Sidebar di Desktop) -->
                <div id="pos-cart-panel" class="fixed inset-x-0 bottom-0 top-16 z-[60] bg-white transform translate-y-full lg:translate-y-0 lg:static lg:w-96 flex flex-col shadow-2xl lg:shadow-sm border-t lg:border border-slate-200 lg:rounded-3xl transition-transform duration-300">
                    <div class="p-4 border-b border-slate-100 bg-slate-50 flex justify-between items-center lg:rounded-t-3xl">
                        <h3 class="font-bold text-base lg:text-lg flex items-center gap-2"><i data-lucide="shopping-cart" class="w-5 h-5"></i> Kasir POS</h3>
                        <div class="flex gap-3 items-center">
                            <button onclick="clearCart()" class="text-xs font-bold text-red-500 hover:bg-red-50 px-2 py-1 rounded">Kosongkan</button>
                            <button class="lg:hidden w-8 h-8 bg-white border border-slate-200 rounded-full flex justify-center items-center text-slate-500" onclick="toggleMobileCart()"><i data-lucide="chevron-down" class="w-4 h-4"></i></button>
                        </div>
                    </div>
                    <div class="flex-1 overflow-y-auto p-3 lg:p-4 space-y-2 lg:space-y-3 bg-white" id="cart-items">
                        <div class="text-center py-10 text-slate-400 font-medium text-sm"><i data-lucide="shopping-bag" class="w-10 h-10 mx-auto mb-2 opacity-30"></i> Belum ada pesanan</div>
                    </div>
                    <div class="p-4 lg:p-5 bg-slate-50 border-t border-slate-200 lg:rounded-b-3xl">
                        <div class="space-y-1 mb-3 lg:mb-4 border-b border-slate-200 pb-2 lg:pb-3">
                            <div class="flex justify-between text-[10px] lg:text-xs font-bold text-slate-500"><span>Subtotal</span><span id="pos-subtotal">Rp 0</span></div>
                            <?php if($tenant_data['enable_discount']): ?><div class="flex justify-between text-[10px] lg:text-xs font-bold text-red-500"><span>Diskon</span><span id="pos-discount">- Rp 0</span></div><?php endif; ?>
                            <?php if($tenant_data['enable_tax']): ?><div class="flex justify-between text-[10px] lg:text-xs font-bold text-blue-500"><span>Pajak (<?= $tenant_data['tax_percentage'] ?>%)</span><span id="pos-tax">+ Rp 0</span></div><?php endif; ?>
                        </div>
                        <div class="flex justify-between items-center mb-3 lg:mb-4"><span class="text-slate-800 font-bold text-sm lg:text-base">TOTAL</span><span class="text-xl lg:text-2xl font-extrabold text-brand-600" id="cart-total-text">Rp 0</span></div>
                        <button onclick="openPayment()" id="btn-pay" disabled class="w-full bg-slate-900 disabled:bg-slate-200 disabled:text-slate-400 text-white font-bold py-3.5 lg:py-4 rounded-xl shadow-lg flex justify-center items-center gap-2 text-sm lg:text-base transition"><i data-lucide="credit-card" class="w-4 h-4 lg:w-5 lg:h-5"></i> PROSES BAYAR</button>
                    </div>
                </div>
            </div>

            <?php elseif ($action === 'orders'): ?>
            <!-- ================= RIWAYAT PESANAN ================= -->
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-6 gap-4">
                <div><h1 class="text-xl lg:text-2xl font-bold text-slate-900">Pesanan Masuk</h1><p class="text-slate-500 text-xs lg:text-sm mt-1">Total: <b><?= $total_orders ?></b> pesanan hari ini.</p></div>
                <form method="GET" action="/tenant/orders" class="flex flex-wrap items-center bg-white border border-slate-200 p-1.5 rounded-xl shadow-sm gap-1 w-full sm:w-auto">
                    <input type="date" name="start_date" value="<?= htmlspecialchars($start_date) ?>" required class="flex-1 px-2 py-1.5 text-xs bg-transparent outline-none font-medium"><span class="text-slate-400 hidden sm:inline">-</span><input type="date" name="end_date" value="<?= htmlspecialchars($end_date) ?>" required class="flex-1 px-2 py-1.5 text-xs bg-transparent outline-none font-medium">
                    <button type="submit" class="bg-brand-500 text-white px-3 py-1.5 rounded-lg text-xs font-bold w-full sm:w-auto mt-2 sm:mt-0">Filter</button>
                </form>
            </div>

            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden w-full">
                <div class="overflow-x-auto w-full hide-scrollbar">
                    <table class="w-full text-sm text-left whitespace-nowrap">
                        <thead class="text-[10px] lg:text-xs text-slate-500 uppercase bg-slate-50 border-b"><tr><th class="px-4 py-3">ID & Waktu</th><th class="px-4 py-3">Detail Pemesan</th><th class="px-4 py-3">Total & Bayar</th><th class="px-4 py-3">Bukti</th><th class="px-4 py-3">Aksi</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach($all_orders as $o): ?>
                            <tr class="hover:bg-slate-50 transition <?= $o['status']=='waiting'?'bg-amber-50/30':'' ?>">
                                <td class="px-4 py-3 align-top"><p class="font-bold text-slate-900 font-mono text-xs">#ORD-<?= $o['id'] ?></p><p class="text-[9px] text-slate-500 mt-0.5"><?= date('d/m y H:i', strtotime($o['created_at'])) ?></p></td>
                                <td class="px-4 py-3 align-top">
                                    <p class="font-bold text-slate-800 text-xs mb-0.5 flex items-center gap-1"><?= htmlspecialchars($o['customer_name']) ?> <span class="<?= $o['source']=='online'?'bg-blue-100 text-blue-600':'bg-slate-200 text-slate-600' ?> text-[8px] px-1 rounded uppercase"><?= $o['source'] ?></span></p>
                                    <p class="text-[10px] text-slate-500"><i data-lucide="map-pin" class="w-2.5 h-2.5 inline"></i> <?= htmlspecialchars($o['address_table_no']) ?: '-' ?></p>
                                    <div class="mt-1.5 bg-slate-100 border border-slate-200 rounded p-1.5 min-w-[150px] max-w-[250px] whitespace-normal">
                                        <p class="text-[8px] font-bold text-slate-500 uppercase mb-0.5">Pesanan:</p>
                                        <ul class="text-[10px] font-bold text-slate-700 leading-tight">
                                            <?php if(!empty($o['items'])): foreach($o['items'] as $item): ?><li><span class="text-brand-600"><?= $item['quantity'] ?>x</span> <?= htmlspecialchars($item['name']) ?></li><?php endforeach; endif; ?>
                                        </ul>
                                    </div>
                                    <?php if(!empty($o['customer_notes'])): ?><div class="mt-1 p-1.5 bg-amber-50 border border-amber-200 rounded max-w-[250px] whitespace-normal"><p class="text-[8px] font-bold text-amber-800 uppercase mb-0.5"><i data-lucide="message-square" class="w-2.5 h-2.5 inline"></i> Catatan:</p><p class="text-[10px] text-amber-900 italic leading-tight">"<?= htmlspecialchars($o['customer_notes']) ?>"</p></div><?php endif; ?>
                                </td>
                                <td class="px-4 py-3 align-top"><p class="font-bold text-brand-600 text-sm">Rp <?= number_format($o['total_amount'],0,',','.') ?></p><p class="text-[8px] uppercase font-bold text-slate-500 mt-1 border px-1.5 py-0.5 rounded w-max"><?= $o['payment_method'] ?></p></td>
                                <td class="px-4 py-3 align-top"><?php if($o['payment_proof']): ?><a href="/uploads/proofs/<?= htmlspecialchars($o['payment_proof']) ?>" target="_blank" class="text-[10px] bg-blue-50 text-blue-600 px-2 py-1 rounded font-bold border border-blue-200">Cek</a><?php else: ?><span class="text-[10px] text-slate-400 italic">Kosong</span><?php endif; ?></td>
                                <td class="px-4 py-3 align-top">
                                    <form action="/tenant/order_status" method="POST" class="min-w-[120px] mb-2">
                                        <?= csrf_field() ?><input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                        <?php if($o['status'] === 'cancelled'): ?><span class="px-2 py-1 bg-red-100 text-red-700 font-bold text-[10px] rounded border border-red-200 block text-center">DIBATALKAN</span><?php elseif($o['status'] === 'completed'): ?><span class="px-2 py-1 bg-green-100 text-green-700 font-bold text-[10px] rounded border border-green-200 block text-center">SELESAI</span>
                                        <?php else: ?><select name="status" class="border border-brand-300 rounded text-[10px] font-bold px-1.5 py-1 outline-none bg-brand-50 text-brand-800 w-full" onchange="this.form.submit()"><option value="waiting" <?= $o['status']=='waiting'?'selected':'' ?>>⏳ Menunggu</option><option value="processed" <?= $o['status']=='processed'?'selected':'' ?>>🍳 Diproses</option><option value="ready" <?= $o['status']=='ready'?'selected':'' ?>>🛵 Siap/Kirim</option><option value="completed">✅ Selesai</option><option value="cancelled">❌ Batal</option></select><?php endif; ?>
                                    </form>
                                    <form action="/tenant/orders" method="POST">
                                        <?= csrf_field() ?><input type="hidden" name="reprint_order_id" value="<?= $o['id'] ?>">
                                        <button type="submit" class="w-full bg-white hover:bg-slate-100 text-slate-700 border border-slate-300 rounded text-[10px] font-bold px-2 py-1.5 flex items-center justify-center gap-1 transition shadow-sm">
                                            <i data-lucide="printer" class="w-3 h-3"></i> Cetak Struk
                                        </button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; if(empty($all_orders)): ?><tr><td colspan="5" class="text-center py-10 text-slate-400 text-xs">Tidak ada pesanan.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
                <?php if($total_pages > 1): ?><div class="p-3 border-t border-slate-100 bg-slate-50 flex justify-center"><div class="flex items-center gap-1 bg-white border border-slate-200 p-1 rounded-xl shadow-sm">
                        <?php $q = $_GET; if($page > 1) { $q['page'] = $page - 1; echo "<a href='/tenant/orders?".http_build_query($q)."' class='w-7 h-7 flex items-center justify-center rounded text-slate-500 hover:bg-slate-100'><i data-lucide='chevron-left' class='w-3 h-3'></i></a>"; }
                        for($i=1; $i<=$total_pages; $i++) { if($i==1 || $i==$total_pages || ($i>=$page-1 && $i<=$page+1)) { $q['page']=$i; $act = ($i==$page)?"bg-slate-900 text-white":"text-slate-600"; echo "<a href='/tenant/orders?".http_build_query($q)."' class='w-7 h-7 flex items-center justify-center rounded text-xs font-bold {$act}'>{$i}</a>"; } elseif($i==$page-2 || $i==$page+2) echo "<span class='px-1 text-slate-300 text-xs'>..</span>"; }
                        if($page < $total_pages) { $q['page'] = $page + 1; echo "<a href='/tenant/orders?".http_build_query($q)."' class='w-7 h-7 flex items-center justify-center rounded text-slate-500 hover:bg-slate-100'><i data-lucide='chevron-right' class='w-3 h-3'></i></a>"; } ?>
                </div></div><?php endif; ?>
            </div>

            <?php elseif ($action === 'reservations'): ?>
            <!-- ================= RESERVASI ================= -->
            <div class="mb-6"><h1 class="text-xl lg:text-2xl font-bold text-slate-900">Reservasi Meja</h1></div>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden w-full">
                <div class="overflow-x-auto w-full hide-scrollbar">
                    <table class="w-full text-sm text-left whitespace-nowrap">
                        <thead class="text-[10px] lg:text-xs text-slate-500 uppercase bg-slate-50 border-b"><tr><th class="px-4 py-3">Jadwal</th><th class="px-4 py-3">Pemesan</th><th class="px-4 py-3">Info</th><th class="px-4 py-3">Status</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach($reservations as $r): ?>
                            <tr class="hover:bg-slate-50 <?= $r['status']=='waiting'?'bg-amber-50/30':'' ?>">
                                <td class="px-4 py-3"><p class="font-bold text-brand-600 text-sm"><?= date('d/m/y', strtotime($r['res_date'])) ?></p><p class="text-[10px] font-bold text-slate-600"><i data-lucide="clock" class="w-2.5 h-2.5 inline"></i> <?= date('H:i', strtotime($r['res_time'])) ?></p></td>
                                <td class="px-4 py-3"><p class="font-bold text-slate-900 text-xs"><?= htmlspecialchars($r['customer_name']) ?></p><a href="https://wa.me/<?= preg_replace('/^0/','62',$r['customer_phone']) ?>" target="_blank" class="text-[9px] text-green-600 font-bold border border-green-200 bg-green-50 px-1.5 py-0.5 rounded mt-1 inline-block">WA: <?= htmlspecialchars($r['customer_phone']) ?></a></td>
                                <td class="px-4 py-3 whitespace-normal min-w-[120px]"><span class="bg-slate-100 border text-[10px] px-2 py-0.5 rounded font-bold text-slate-700"><?= $r['pax'] ?> Orang</span><p class="text-[9px] text-slate-500 mt-1 line-clamp-2"><?= htmlspecialchars($r['notes']) ?: '-' ?></p></td>
                                <td class="px-4 py-3">
                                    <form action="/tenant/reservation_status" method="POST" class="min-w-[110px]"><?= csrf_field() ?><input type="hidden" name="res_id" value="<?= $r['id'] ?>">
                                        <?php if($r['status'] === 'cancelled'): ?><span class="px-2 py-1 bg-red-100 text-red-700 font-bold text-[10px] rounded border">DITOLAK</span><?php elseif($r['status'] === 'completed'): ?><span class="px-2 py-1 bg-slate-100 text-slate-600 font-bold text-[10px] rounded border">SELESAI</span><?php else: ?><select name="status" class="border border-blue-300 rounded text-[10px] font-bold px-1.5 py-1 outline-none bg-blue-50 text-blue-800 w-full" onchange="this.form.submit()"><option value="waiting" <?= $r['status']=='waiting'?'selected':'' ?>>Menunggu</option><option value="approved" <?= $r['status']=='approved'?'selected':'' ?>>Disetujui</option><option value="completed">Selesai (Hadir)</option><option value="cancelled">Tolak/Batal</option></select><?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; if(empty($reservations)): ?><tr><td colspan="4" class="text-center py-10 text-slate-400 text-xs">Belum ada data reservasi.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php elseif ($action === 'expenses' && $is_owner): ?>
            <!-- ================= PENGELUARAN ================= -->
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-6 gap-4">
                <h1 class="text-xl lg:text-2xl font-bold text-slate-900">Catatan Pengeluaran</h1>
                <button onclick="document.getElementById('modal-expense').classList.remove('hidden')" class="bg-amber-500 text-white px-4 py-2 rounded-xl font-bold flex items-center justify-center gap-2 text-sm"><i data-lucide="plus" class="w-4 h-4"></i> Catat Pengeluaran</button>
            </div>
            <div class="bg-white rounded-2xl border border-slate-200 shadow-sm overflow-hidden">
                <div class="overflow-x-auto hide-scrollbar">
                    <table class="w-full text-sm text-left whitespace-nowrap">
                        <thead class="text-[10px] lg:text-xs text-slate-500 uppercase bg-slate-50 border-b"><tr><th class="px-4 py-3">Tanggal</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3">Nominal (Rp)</th><th class="px-4 py-3 text-right">Hapus</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach($expenses as $e): ?>
                            <tr class="hover:bg-slate-50"><td class="px-4 py-3 font-bold text-xs"><?= date('d/m/y', strtotime($e['expense_date'])) ?></td><td class="px-4 py-3 text-xs whitespace-normal min-w-[150px]"><?= htmlspecialchars($e['description']) ?></td><td class="px-4 py-3 font-bold text-red-600 text-sm">-Rp <?= number_format($e['amount'],0,',','.') ?></td><td class="px-4 py-3 text-right"><form action="/tenant/expenses" method="POST" onsubmit="return confirm('Hapus pengeluaran?');"><?= csrf_field() ?><input type="hidden" name="sub_action" value="delete"><input type="hidden" name="id" value="<?= $e['id'] ?>"><button type="submit" class="p-1.5 bg-red-50 text-red-500 rounded hover:bg-red-100"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button></form></td></tr>
                            <?php endforeach; if(empty($expenses)): ?><tr><td colspan="4" class="text-center py-10 text-slate-400 text-xs">Kosong.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <?php elseif ($action === 'cashiers' && $is_owner): ?>
            <!-- ================= KASIR ================= -->
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center mb-6 gap-4"><h1 class="text-xl lg:text-2xl font-bold text-slate-900">Manajemen Kasir</h1><button onclick="openModalKasir()" class="bg-slate-900 text-white px-4 py-2 rounded-xl font-bold flex items-center justify-center gap-2 text-sm"><i data-lucide="plus" class="w-4 h-4"></i> Tambah Kasir</button></div>
            <div class="bg-white rounded-2xl border shadow-sm overflow-hidden"><div class="overflow-x-auto hide-scrollbar"><table class="w-full text-sm text-left whitespace-nowrap"><thead class="text-[10px] lg:text-xs text-slate-500 uppercase bg-slate-50 border-b"><tr><th class="px-4 py-3">Nama Lengkap</th><th class="px-4 py-3">Username Login</th><th class="px-4 py-3">Aksi</th></tr></thead><tbody class="divide-y divide-slate-100">
                <?php foreach($cashiers as $c): ?><tr><td class="px-4 py-3 font-bold text-xs flex items-center gap-2"><div class="w-6 h-6 bg-brand-100 text-brand-600 rounded-full flex justify-center items-center text-[10px]"><?= substr($c['name'],0,1) ?></div><?= htmlspecialchars($c['name']) ?></td><td class="px-4 py-3"><span class="bg-slate-100 border px-1.5 py-0.5 rounded font-mono text-[10px] font-bold"><?= htmlspecialchars($c['username']) ?></span></td><td class="px-4 py-3 flex justify-end gap-1.5"><button type="button" onclick='openModalEditKasir(<?= json_encode($c) ?>)' class="p-1.5 bg-slate-50 text-slate-600 rounded border hover:bg-slate-100"><i data-lucide="edit-3" class="w-3.5 h-3.5"></i></button><form action="/tenant/cashiers" method="POST" onsubmit="return confirm('Hapus permanen kasir ini?');"><?= csrf_field() ?><input type="hidden" name="sub_action" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button type="submit" class="p-1.5 bg-red-50 text-red-500 rounded border border-red-100 hover:bg-red-100"><i data-lucide="trash-2" class="w-3.5 h-3.5"></i></button></form></td></tr><?php endforeach; if(empty($cashiers)): ?><tr><td colspan="3" class="text-center py-10 text-slate-400 text-xs">Kosong.</td></tr><?php endif; ?>
            </tbody></table></div></div>

            <?php elseif ($action === 'stock_logs' && $is_owner): ?>
            <!-- ================= LOG STOK ================= -->
            <div class="mb-6"><h1 class="text-xl lg:text-2xl font-bold text-slate-900">Audit Stok</h1></div>
            <div class="bg-white rounded-2xl border shadow-sm overflow-hidden flex flex-col"><div class="overflow-x-auto hide-scrollbar"><table class="w-full text-sm text-left whitespace-nowrap"><thead class="text-[10px] lg:text-xs text-slate-500 uppercase bg-slate-50 border-b"><tr><th class="px-4 py-3">Waktu</th><th class="px-4 py-3">Produk</th><th class="px-4 py-3">Ubah</th><th class="px-4 py-3">Sisa</th><th class="px-4 py-3">Tipe/Catatan</th></tr></thead><tbody class="divide-y divide-slate-100">
                <?php foreach($logs as $l): ?><tr><td class="px-4 py-3 text-[10px] text-slate-500"><?= date('d/m/y H:i', strtotime($l['created_at'])) ?></td><td class="px-4 py-3 font-bold text-xs truncate max-w-[120px]"><?= htmlspecialchars($l['name']) ?></td><td class="px-4 py-3 font-extrabold text-sm <?= $l['qty_change']>0?'text-green-600':'text-red-600' ?>"><?= $l['qty_change']>0?'+'.$l['qty_change']:$l['qty_change'] ?></td><td class="px-4 py-3 font-bold"><?= $l['final_stock'] ?></td><td class="px-4 py-3"><p class="text-[8px] font-bold uppercase bg-slate-100 px-1 rounded inline-block mb-0.5"><?= str_replace('_',' ',$l['type']) ?></p><p class="text-[9px] text-slate-500 whitespace-normal min-w-[100px]"><?= htmlspecialchars($l['note']) ?></p></td></tr><?php endforeach; if(empty($logs)): ?><tr><td colspan="5" class="text-center py-10 text-slate-400 text-xs">Kosong.</td></tr><?php endif; ?>
            </tbody></table></div></div>

            <?php elseif ($action === 'settings' && $is_owner): ?>
            <!-- ================= PENGATURAN TOKO ================= -->
            <div class="mb-6"><h1 class="text-xl lg:text-2xl font-bold text-slate-900">Pengaturan Toko</h1></div>
            <form action="/tenant/settings" method="POST" enctype="multipart/form-data" class="space-y-6 max-w-3xl pb-10">
                <?= csrf_field() ?>
                
                <div class="bg-white rounded-2xl lg:rounded-3xl border shadow-sm p-4 lg:p-6">
                    <h3 class="font-extrabold text-slate-800 mb-4 border-b pb-2 flex items-center gap-2 text-sm lg:text-base"><i data-lucide="store" class="w-4 h-4 text-brand-500"></i> Info Toko</h3>
                    <div class="space-y-4">
                        <div class="flex flex-col sm:flex-row gap-4 items-start sm:items-center">
                            <div class="w-20 h-20 bg-slate-100 rounded-xl border-2 border-dashed border-slate-300 flex justify-center items-center overflow-hidden relative shrink-0 cursor-pointer" onclick="document.getElementById('input-logo').click()"><?php if(!empty($tenant_data['logo'])): ?><img src="/uploads/logos/<?= htmlspecialchars($tenant_data['logo']) ?>" class="w-full h-full object-cover"><?php else: ?><span class="text-[10px] text-slate-400">Logo</span><?php endif; ?></div>
                            <div class="flex-1 w-full"><label class="block text-xs font-bold text-slate-500 mb-1">Ganti Logo</label><input type="file" name="logo" id="input-logo" accept="image/*" class="text-xs w-full file:bg-brand-50 file:text-brand-700 file:border-0 file:px-3 file:py-1.5 file:rounded-lg"></div>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div><label class="block text-xs font-bold mb-1">Nama Toko *</label><input type="text" name="shop_name" required value="<?= htmlspecialchars($tenant_data['shop_name']) ?>" class="w-full px-3 py-2 border rounded-lg outline-none text-sm"></div>
                            <div><label class="block text-xs font-bold mb-1">WhatsApp CS *</label><input type="text" name="whatsapp" required value="<?= htmlspecialchars($tenant_data['whatsapp']) ?>" class="w-full px-3 py-2 border rounded-lg outline-none text-sm font-mono"></div>
                        </div>
                        <div><label class="block text-xs font-bold mb-1">Alamat *</label><textarea name="address" required rows="2" class="w-full px-3 py-2 border rounded-lg outline-none text-sm"><?= htmlspecialchars($tenant_data['address']) ?></textarea></div>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                            <div><label class="block text-xs font-bold mb-1">Instagram (@)</label><input type="text" name="ig_handle" value="<?= htmlspecialchars($tenant_data['ig_handle'] ?? '') ?>" class="w-full px-3 py-2 border rounded-lg outline-none text-sm"></div>
                            <div><label class="block text-xs font-bold mb-1">WiFi Pass</label><input type="text" name="wifi_password" value="<?= htmlspecialchars($tenant_data['wifi_password'] ?? '') ?>" class="w-full px-3 py-2 border rounded-lg outline-none text-sm"></div>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-2xl lg:rounded-3xl border shadow-sm p-4 lg:p-6">
                    <h3 class="font-extrabold text-slate-800 mb-4 border-b pb-2 flex items-center gap-2 text-sm"><i data-lucide="toggle-right" class="w-4 h-4 text-brand-500"></i> Operasional</h3>
                    <div class="space-y-3">
                        <label class="flex justify-between items-center p-3 bg-slate-50 border rounded-lg"><span class="text-xs font-bold">Toko Buka</span><input type="checkbox" name="is_open" class="w-4 h-4 accent-brand-500" <?= $tenant_data['is_open'] ? 'checked' : '' ?>></label>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="flex justify-between items-center p-3 bg-slate-50 border rounded-lg"><span class="text-xs font-bold">Delivery</span><input type="checkbox" name="allow_delivery" class="w-4 h-4 accent-brand-500" <?= $tenant_data['allow_delivery'] ? 'checked' : '' ?>></label>
                            <label class="flex justify-between items-center p-3 bg-slate-50 border rounded-lg"><span class="text-xs font-bold">Pick-up</span><input type="checkbox" name="allow_pickup" class="w-4 h-4 accent-brand-500" <?= $tenant_data['allow_pickup'] ? 'checked' : '' ?>></label>
                            <label class="flex justify-between items-center p-3 bg-slate-50 border rounded-lg"><span class="text-xs font-bold">Dine-in</span><input type="checkbox" name="allow_dinein" class="w-4 h-4 accent-brand-500" <?= $tenant_data['allow_dinein'] ? 'checked' : '' ?>></label>
                            <label class="flex justify-between items-center p-3 bg-slate-50 border rounded-lg"><span class="text-xs font-bold text-slate-600 leading-tight">Modul<br>Reservasi</span><input type="checkbox" name="enable_reservation" class="w-4 h-4 accent-brand-500" <?= $tenant_data['enable_reservation'] ? 'checked' : '' ?>></label>
                        </div>
                        <label class="flex justify-between items-center p-3 bg-blue-50 border border-blue-200 rounded-lg"><span class="text-xs font-bold text-blue-800">Auto-Print Kasir</span><input type="checkbox" name="auto_print" class="w-4 h-4 accent-blue-600" <?= $tenant_data['auto_print'] ? 'checked' : '' ?>></label>
                    </div>
                </div>

                <div class="bg-white rounded-2xl lg:rounded-3xl border shadow-sm p-4 lg:p-6">
                    <h3 class="font-extrabold text-slate-800 mb-4 border-b pb-2 flex items-center gap-2 text-sm"><i data-lucide="calculator" class="w-4 h-4 text-brand-500"></i> Keuangan & Bayar</h3>
                    <div class="space-y-4">
                        <div class="border p-3 rounded-lg bg-slate-50"><label class="flex justify-between items-center mb-2"><span class="text-xs font-bold">Aktifkan Pajak (%)</span><input type="checkbox" name="enable_tax" class="w-4 h-4 accent-brand-500" <?= $tenant_data['enable_tax'] ? 'checked' : '' ?>></label><input type="number" step="0.01" name="tax_percentage" value="<?= $tenant_data['tax_percentage'] ?>" class="w-full px-3 py-2 border rounded-lg text-sm" placeholder="Contoh: 11"></div>
                        <div class="border p-3 rounded-lg bg-slate-50"><label class="flex justify-between items-center mb-2"><span class="text-xs font-bold">Diskon Global</span><input type="checkbox" name="enable_discount" class="w-4 h-4 accent-brand-500" <?= $tenant_data['enable_discount'] ? 'checked' : '' ?>></label><div class="flex gap-2"><select name="discount_type" class="w-1/2 px-2 py-2 border rounded-lg text-xs bg-white"><option value="nominal" <?= $tenant_data['discount_type']=='nominal'?'selected':'' ?>>Rp</option><option value="percent" <?= $tenant_data['discount_type']=='percent'?'selected':'' ?>>%</option></select><input type="text" name="discount_value" value="<?= (float)$tenant_data['discount_value'] ?>" class="w-1/2 px-3 py-2 border rounded-lg text-sm"></div></div>
                        <div><label class="block text-xs font-bold mb-1">Info Rekening Transfer</label><textarea name="bank_account" rows="2" class="w-full px-3 py-2 border rounded-lg outline-none text-sm font-mono"><?= htmlspecialchars($tenant_data['bank_account'] ?? '') ?></textarea></div>
                        <div><label class="block text-xs font-bold mb-1">Upload QRIS</label><input type="file" name="qris_image" accept="image/*" class="text-xs w-full file:bg-blue-50 file:text-blue-700 file:border-0 file:px-3 file:py-1.5 file:rounded-lg"></div>
                    </div>
                </div>

                <div class="sticky bottom-4 z-40 bg-white/90 backdrop-blur border p-3 rounded-2xl shadow-xl flex justify-center"><button type="submit" class="w-full bg-brand-500 text-white font-bold py-3 rounded-xl shadow-lg text-sm flex justify-center items-center gap-2"><i data-lucide="save" class="w-4 h-4"></i> Simpan Semua</button></div>
            </form>
            <?php endif; ?>
        </main>
    </div>

    <!-- KUMPULAN SEMUA MODAL -->
    
    <div id="modal-product" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-sm flex flex-col max-h-[90vh]">
            <div class="p-4 border-b flex justify-between bg-slate-50 rounded-t-2xl"><h3 class="font-bold text-sm" id="modal-product-title">Tambah Menu</h3><button type="button" onclick="closeModal('modal-product')"><i data-lucide="x" class="w-4 h-4"></i></button></div>
            <form action="/tenant/products" method="POST" enctype="multipart/form-data" class="p-4 overflow-y-auto space-y-3">
                <?= csrf_field() ?><input type="hidden" name="sub_action" id="modal-sub-action" value="add"><input type="hidden" name="id" id="modal-id" value="">
                <div><label class="text-xs font-bold">Nama *</label><input type="text" name="name" id="modal-name" required class="w-full border p-2 rounded-lg text-sm mt-1"></div>
                <div><label class="text-xs font-bold">Kategori</label><select name="category_id" id="modal-category" class="w-full border p-2 rounded-lg text-sm mt-1 bg-white"><option value="">-- Pilih --</option><?php if(!empty($categories)): foreach($categories as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; endif; ?></select></div>
                <div><label class="text-xs font-bold">Deskripsi</label><textarea name="description" id="modal-desc" rows="2" class="w-full border p-2 rounded-lg text-sm mt-1"></textarea></div>
                <div class="flex gap-2"><div class="w-1/2"><label class="text-xs font-bold">Harga *</label><input type="text" name="price" id="modal-price" required class="w-full border p-2 rounded-lg text-sm mt-1" onkeyup="this.value=this.value.replace(/[^0-9]/g,'')"></div><div class="w-1/2"><label class="text-xs font-bold">Stok *</label><input type="number" name="stock" id="modal-stock" required class="w-full border p-2 rounded-lg text-sm mt-1"></div></div>
                <div><label class="text-xs font-bold text-red-500">Harga Diskon</label><input type="text" name="discount_price" id="modal-disc-price" class="w-full border p-2 rounded-lg text-sm mt-1" onkeyup="this.value=this.value.replace(/[^0-9]/g,'')"></div>
                <div id="edit-note-container" class="hidden"><label class="text-xs font-bold text-amber-600">Alasan Edit Stok</label><input type="text" name="edit_note" id="modal-edit-note" class="w-full border p-2 rounded-lg text-sm mt-1 bg-amber-50"></div>
                <div><label class="text-xs font-bold">Foto</label><input type="file" name="image" accept="image/*" class="text-xs w-full mt-1"></div>
                <button type="submit" class="w-full bg-slate-900 text-white p-3 rounded-xl font-bold mt-2 text-sm">Simpan</button>
            </form>
        </div>
    </div>

    <div id="modal-category-box" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-sm"><div class="p-4 border-b flex justify-between bg-slate-50 rounded-t-2xl"><h3 class="font-bold text-sm">Kategori</h3><button type="button" onclick="closeModal('modal-category-box')"><i data-lucide="x" class="w-4 h-4"></i></button></div>
            <div class="p-4"><form action="/tenant/products" method="POST" class="flex gap-2 mb-4"><?= csrf_field() ?><input type="hidden" name="sub_action" value="cat_action"><input type="hidden" name="cat_mode" value="add"><input type="text" name="name" required class="flex-1 border p-2 rounded-lg text-sm" placeholder="Baru.."><button type="submit" class="bg-slate-900 text-white px-3 rounded-lg text-xs font-bold">Tambah</button></form><div class="max-h-48 overflow-y-auto space-y-1">
            <?php if(!empty($categories)): foreach($categories as $c): ?><div class="flex justify-between items-center p-2 bg-slate-50 border rounded-lg"><span class="text-xs font-bold"><?= htmlspecialchars($c['name']) ?></span><form action="/tenant/products" method="POST" onsubmit="return confirm('Hapus kategori?');"><?= csrf_field() ?><input type="hidden" name="sub_action" value="cat_action"><input type="hidden" name="cat_mode" value="delete"><input type="hidden" name="id" value="<?= $c['id'] ?>"><button type="submit" class="text-red-500"><i data-lucide="trash" class="w-3 h-3"></i></button></form></div><?php endforeach; endif; ?></div></div>
        </div>
    </div>

    <div id="modal-expense" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-sm"><div class="p-4 border-b flex justify-between bg-slate-50 rounded-t-2xl"><h3 class="font-bold text-sm">Pengeluaran</h3><button type="button" onclick="closeModal('modal-expense')"><i data-lucide="x" class="w-4 h-4"></i></button></div>
            <form action="/tenant/expenses" method="POST" class="p-4 space-y-3"><?= csrf_field() ?><input type="hidden" name="sub_action" value="add">
                <div><label class="text-xs font-bold">Tanggal</label><input type="date" name="expense_date" required value="<?= date('Y-m-d') ?>" class="w-full border p-2 rounded-lg text-sm mt-1"></div>
                <div><label class="text-xs font-bold">Ket / Beli Apa</label><input type="text" name="description" required class="w-full border p-2 rounded-lg text-sm mt-1"></div>
                <div><label class="text-xs font-bold">Nominal (Rp)</label><input type="text" name="amount" required class="w-full border p-2 rounded-lg text-sm mt-1" onkeyup="this.value=new Intl.NumberFormat('id-ID').format(this.value.replace(/[^0-9]/g,''))"></div>
                <button type="submit" class="w-full bg-slate-900 text-white p-3 rounded-xl font-bold text-sm mt-2">Simpan</button>
            </form>
        </div>
    </div>

    <div id="modal-kasir" class="fixed inset-0 bg-slate-900/60 backdrop-blur-sm z-[100] hidden flex items-center justify-center p-4">
        <div class="bg-white rounded-2xl w-full max-w-sm"><div class="p-4 border-b flex justify-between bg-slate-50 rounded-t-2xl"><h3 class="font-bold text-sm" id="modal-kasir-title">Tambah Kasir</h3><button type="button" onclick="closeModal('modal-kasir')"><i data-lucide="x" class="w-4 h-4"></i></button></div>
            <form action="/tenant/cashiers" method="POST" class="p-4 space-y-3"><?= csrf_field() ?><input type="hidden" name="sub_action" id="modal-k-action" value="add"><input type="hidden" name="id" id="modal-k-id">
                <div><label class="text-xs font-bold">Nama</label><input type="text" name="name" id="modal-k-name" required class="w-full border p-2 rounded-lg text-sm mt-1"></div>
                <div><label class="text-xs font-bold">Username</label><div class="flex border rounded-lg mt-1 overflow-hidden"><span class="bg-slate-100 p-2 text-xs font-mono border-r"><?= $tenant_data['slug'] ?>_</span><input type="text" name="username" id="modal-k-user" required class="w-full p-2 outline-none text-sm lowercase font-mono"></div></div>
                <div><label class="text-xs font-bold">Password <span id="k-pass-note" class="hidden text-amber-500 font-normal">(Kosongkan jika tidak diubah)</span></label><input type="password" name="password" id="modal-k-pass" required class="w-full border p-2 rounded-lg text-sm mt-1"></div>
                <button type="submit" class="w-full bg-slate-900 text-white p-3 rounded-xl font-bold text-sm mt-2">Simpan</button>
            </form>
        </div>
    </div>

    <!-- Modal Bayar POS -->
    <div id="modal-payment" class="fixed inset-0 bg-slate-900/80 backdrop-blur-sm z-[70] hidden flex items-end lg:items-center justify-center lg:p-4 transition-all">
        <div class="bg-white rounded-t-3xl lg:rounded-2xl w-full max-w-sm flex flex-col h-[85vh] lg:h-auto transform transition-transform" id="modal-payment-content">
            <div class="p-4 border-b flex justify-between items-center bg-slate-50 lg:rounded-t-2xl"><h3 class="font-bold text-sm">Pembayaran</h3><button type="button" onclick="closePayment()" class="bg-white border w-8 h-8 rounded-full flex justify-center items-center"><i data-lucide="x" class="w-4 h-4"></i></button></div>
            <form action="/tenant/pos_checkout" method="POST" class="p-4 flex-1 overflow-y-auto space-y-4">
                <?= csrf_field() ?><input type="hidden" name="cart_data" id="payment-cart-data">
                <div class="bg-brand-50 rounded-xl p-3 text-center border border-brand-100"><p class="text-[10px] text-brand-600 font-bold uppercase">TOTAL</p><p class="text-2xl font-extrabold text-slate-900" id="payment-total-text">Rp 0</p></div>
                <div><label class="text-xs font-bold text-slate-500">Pelanggan</label><input type="text" name="customer_name" class="w-full border bg-slate-50 p-2.5 rounded-lg text-sm mt-1"></div>
                <div><label class="text-xs font-bold text-slate-500">Catatan Pesanan</label><input type="text" name="customer_notes" class="w-full border bg-slate-50 p-2.5 rounded-lg text-sm mt-1"></div>
                <div class="grid grid-cols-2 gap-2"><label class="border p-2 rounded-lg text-center cursor-pointer has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50"><input type="radio" name="payment_method" value="tunai" class="sr-only" checked onchange="toggleCash()"><i data-lucide="banknote" class="mx-auto mb-1 w-4 h-4 text-slate-500"></i><span class="text-xs font-bold">Tunai</span></label><label class="border p-2 rounded-lg text-center cursor-pointer has-[:checked]:border-brand-500 has-[:checked]:bg-brand-50"><input type="radio" name="payment_method" value="transfer" class="sr-only" onchange="toggleCash()"><i data-lucide="qr-code" class="mx-auto mb-1 w-4 h-4 text-slate-500"></i><span class="text-xs font-bold">Transfer</span></label></div>
                <div id="cash-group">
                    <label class="text-xs font-bold text-slate-500">Uang Diterima</label>
                    <div class="flex items-center gap-2 mt-1"><span class="bg-slate-100 border p-2.5 rounded-lg text-sm font-bold text-slate-500">Rp</span><input type="text" name="cash_amount" id="cash_amount" class="w-full border p-2.5 rounded-lg font-mono text-lg font-extrabold" onkeyup="calcChange()"></div>
                    <div class="flex gap-1 mt-2"><button type="button" onclick="setUang(finalTotal)" class="px-2 py-1 bg-slate-100 text-xs rounded border flex-1">Pas</button><button type="button" onclick="setUang(50000)" class="px-2 py-1 bg-slate-100 text-xs rounded border flex-1">50k</button><button type="button" onclick="setUang(100000)" class="px-2 py-1 bg-slate-100 text-xs rounded border flex-1">100k</button></div>
                </div>
                <div id="change-box" class="bg-green-50 p-3 rounded-lg border border-green-200 flex justify-between items-center"><span class="text-xs font-bold text-green-800">KEMBALI</span><span class="font-bold text-green-700 text-lg" id="change-text">Rp 0</span></div>
                <button type="submit" id="btn-process" class="w-full bg-slate-900 text-white font-bold py-3.5 rounded-xl mt-2 flex justify-center items-center gap-2 text-sm disabled:opacity-50 transition"><i data-lucide="printer" class="w-4 h-4"></i> Bayar & Cetak</button>
            </form>
        </div>
    </div>

    <!-- JS GLOBAL & RESPONSIVE LOGIC -->
    <script>
        document.addEventListener("DOMContentLoaded", () => lucide.createIcons());

        function toggleSidebar() {
            document.getElementById('sidebar').classList.toggle('-translate-x-full');
            document.getElementById('sidebar-overlay').classList.toggle('hidden');
        }

        function closeModal(id) { document.getElementById(id).classList.add('hidden'); }

        function openModalAddProduct() { 
            document.getElementById('modal-sub-action').value = 'add'; 
            document.getElementById('modal-id').value = ''; 
            document.getElementById('modal-name').value = ''; 
            document.getElementById('modal-category').value = ''; 
            document.getElementById('modal-desc').value = ''; 
            document.getElementById('modal-price').value = ''; 
            document.getElementById('modal-disc-price').value = ''; 
            document.getElementById('modal-stock').value = ''; 
            document.getElementById('edit-note-container').classList.add('hidden'); 
            document.getElementById('modal-product-title').innerText = 'Tambah Menu'; 
            document.getElementById('modal-product').classList.remove('hidden'); 
        }

        function openModalEditProduct(p) { 
            document.getElementById('modal-sub-action').value = 'edit'; 
            document.getElementById('modal-id').value = p.id; 
            document.getElementById('modal-name').value = p.name; 
            if(p.category_id) { document.getElementById('modal-category').value = p.category_id; } 
            else { document.getElementById('modal-category').value = ''; }
            document.getElementById('modal-desc').value = p.description || ''; 
            document.getElementById('modal-price').value = p.price; 
            document.getElementById('modal-disc-price').value = p.discount_price > 0 ? p.discount_price : ''; 
            document.getElementById('modal-stock').value = p.stock; 
            document.getElementById('edit-note-container').classList.remove('hidden'); 
            document.getElementById('modal-product-title').innerText = 'Edit Menu'; 
            document.getElementById('modal-product').classList.remove('hidden'); 
        }

        function openModalKasir() { 
            document.getElementById('modal-kasir').classList.remove('hidden'); 
            document.getElementById('modal-k-action').value = 'add'; 
            document.getElementById('modal-k-id').value = ''; 
            document.getElementById('modal-k-name').value = ''; 
            document.getElementById('modal-k-user').value = ''; 
            document.getElementById('modal-k-pass').setAttribute('required', 'true'); 
            document.getElementById('k-pass-note').classList.add('hidden');
            document.getElementById('modal-kasir-title').innerText = 'Tambah Kasir'; 
        }

        function openModalEditKasir(c) { 
            document.getElementById('modal-kasir').classList.remove('hidden'); 
            document.getElementById('modal-k-action').value = 'edit'; 
            document.getElementById('modal-k-id').value = c.id; 
            document.getElementById('modal-k-name').value = c.name; 
            document.getElementById('modal-k-user').value = c.username.replace('<?= $tenant_data['slug'] ?? '' ?>_', ''); 
            document.getElementById('modal-k-pass').removeAttribute('required'); 
            document.getElementById('k-pass-note').classList.remove('hidden');
            document.getElementById('modal-kasir-title').innerText = 'Edit Kasir'; 
        }

 <?php if ($action === 'pos'): ?>
        const CFG = { tax: <?= $tenant_data['enable_tax']?'true':'false' ?>, pct: <?= (float)$tenant_data['tax_percentage'] ?>, disc: <?= $tenant_data['enable_discount']?'true':'false' ?>, dtype: '<?= $tenant_data['discount_type'] ?>', dval: <?= (float)$tenant_data['discount_value'] ?> };
        let cart = []; let finalTotal = 0;
        let currentCategory = 'all';
        const fRp = (n) => new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',minimumFractionDigits:0}).format(n);
        
        function filterMenu() { 
            const q = document.getElementById('search-pos').value.toLowerCase(); 
            let visibleCount = 0;
            document.querySelectorAll('.pos-item').forEach(e => {
                const matchesSearch = e.dataset.name.includes(q);
                const matchesCategory = currentCategory === 'all' || e.dataset.cat == currentCategory;
                if (matchesSearch && matchesCategory) {
                    e.style.display = 'flex';
                    visibleCount++;
                } else {
                    e.style.display = 'none';
                }
            }); 
            
            const noResultMsg = document.getElementById('no-result-msg');
            if(noResultMsg) {
                noResultMsg.style.display = visibleCount === 0 ? 'flex' : 'none';
            }
        }

        function filterCat(c, btnElement) { 
            currentCategory = c;
            const allBtns = document.querySelectorAll('.cat-btn');
            allBtns.forEach(btn => {
                btn.classList.remove('bg-slate-900', 'text-white');
                btn.classList.add('bg-white', 'text-slate-600', 'border', 'border-slate-200');
            });
            btnElement.classList.remove('bg-white', 'text-slate-600', 'border', 'border-slate-200');
            btnElement.classList.add('bg-slate-900', 'text-white');
            
            filterMenu();
            //document.getElementById('search-pos').focus();
        }
        
        function addToCart(id, n, p, ms) {
            const ex = cart.find(i=>i.id===id); if(ex){if(ex.qty<ms)ex.qty++;else{alert('Stok max');return;}}else{if(ms<1)return;cart.push({id:id,name:n,price:p,qty:1,ms:ms});}
            updateCart();
            const btn = document.getElementById('mobile-cart-btn');
            if(btn) { btn.classList.add('scale-90'); setTimeout(()=>btn.classList.remove('scale-90'), 150); }
        }
        function upQty(id, d) { const i=cart.findIndex(x=>x.id===id); if(i>-1){cart[i].qty+=d; if(cart[i].qty<=0)cart.splice(i,1); else if(cart[i].qty>cart[i].ms){cart[i].qty--;alert('Stok limit');} updateCart();} }
        function clearCart() { cart=[]; updateCart(); }

        function updateCart() {
            const c = document.getElementById('cart-items');
            if(cart.length === 0) { 
                c.innerHTML = '<div class="text-center py-10 text-slate-400 font-medium text-xs"><i data-lucide="shopping-bag" class="w-8 h-8 mx-auto mb-2 opacity-30"></i> Kosong</div>'; 
                finalTotal = 0; document.getElementById('btn-pay').disabled = true; document.getElementById('pos-subtotal').innerText='Rp 0';
                if(CFG.disc) document.getElementById('pos-discount').innerText='- Rp 0'; if(CFG.tax) document.getElementById('pos-tax').innerText='+ Rp 0';
                document.getElementById('mobile-cart-btn').classList.add('translate-y-32');
            } else {
                let h='', st=0, tq=0;
                cart.forEach(i => { st+=(i.qty*i.price); tq+=i.qty;
                    h += `<div class="bg-white p-2 lg:p-3 rounded-lg lg:rounded-xl border flex justify-between items-center shadow-sm"><div class="flex-1 pr-2"><h5 class="font-bold text-[10px] lg:text-xs line-clamp-1">${i.name}</h5><p class="text-brand-600 font-bold text-[10px] mt-0.5">Rp${i.price.toLocaleString('id-ID')}</p></div><div class="flex items-center gap-1 bg-slate-50 border p-1 rounded-lg"><button type="button" onclick="upQty(${i.id}, -1)" class="w-5 h-5 lg:w-6 lg:h-6 bg-white border rounded text-slate-600"><i data-lucide="minus" class="w-3 h-3 mx-auto"></i></button><span class="text-[10px] lg:text-xs font-bold w-4 text-center">${i.qty}</span><button type="button" onclick="upQty(${i.id}, 1)" class="w-5 h-5 lg:w-6 lg:h-6 bg-white border rounded text-slate-600"><i data-lucide="plus" class="w-3 h-3 mx-auto"></i></button></div></div>`;
                });
                let d=0; if(CFG.disc) d=(CFG.dtype==='percent')?st*(CFG.dval/100):CFG.dval; let sad=Math.max(0,st-d);
                let tx=0; if(CFG.tax) tx=sad*(CFG.pct/100); finalTotal = sad+tx;
                c.innerHTML=h; document.getElementById('btn-pay').disabled=false;
                document.getElementById('pos-subtotal').innerText=fRp(st);
                if(CFG.disc) document.getElementById('pos-discount').innerText='- '+fRp(d);
                if(CFG.tax) document.getElementById('pos-tax').innerText='+ '+fRp(tx);
                
                document.getElementById('mobile-cart-btn').classList.remove('translate-y-32');
                document.getElementById('mobile-cart-badge').innerText = tq;
                document.getElementById('mobile-cart-total').innerText = fRp(finalTotal);
            }
            document.getElementById('cart-total-text').innerText = fRp(finalTotal);
            lucide.createIcons();
        }
        
        function toggleMobileCart() { document.getElementById('pos-cart-panel').classList.toggle('translate-y-full'); }
        
        function openPayment() {
            document.getElementById('modal-payment').classList.remove('hidden');
            setTimeout(()=>document.getElementById('modal-payment-content').classList.remove('translate-y-full','scale-95'), 10);
            document.getElementById('payment-cart-data').value=JSON.stringify(cart);
            document.getElementById('payment-total-text').innerText=fRp(finalTotal);
            document.getElementById('cash_amount').value=''; calcChange(); toggleCash();
        }
        function closePayment() { document.getElementById('modal-payment-content').classList.add('translate-y-full','scale-95'); setTimeout(()=>document.getElementById('modal-payment').classList.add('hidden'),300); }
        function toggleCash() { const m=document.querySelector('input[name="payment_method"]:checked').value; const c=document.getElementById('cash-group'); const ch=document.getElementById('change-box'); const b=document.getElementById('btn-process'); if(m==='tunai'){c.classList.remove('hidden');ch.classList.remove('hidden');calcChange();}else{c.classList.add('hidden');ch.classList.add('hidden');b.disabled=false;b.innerHTML="<i data-lucide='printer' class='w-4 h-4'></i> Bayar & Cetak";lucide.createIcons();} }
        function setUang(v) { document.getElementById('cash_amount').value=new Intl.NumberFormat('id-ID').format(v); calcChange(); }
        function calcChange() {
            const inp=document.getElementById('cash_amount'); let v=inp.value.replace(/[^0-9]/g,''); if(v)inp.value=new Intl.NumberFormat('id-ID').format(v);
            const cs=parseInt(v)||0; const cg=cs-finalTotal; const b=document.getElementById('btn-process'); const bx=document.getElementById('change-box'); const tx=document.getElementById('change-text');
            if(cs<finalTotal) { tx.innerText="Kurang "+fRp(Math.abs(cg)); bx.classList.replace('bg-green-50','bg-red-50'); tx.classList.replace('text-green-700','text-red-600'); b.disabled=true; b.innerHTML="<i data-lucide='alert-circle' class='w-4 h-4'></i> Uang Kurang"; } 
            else { tx.innerText=fRp(cg); bx.classList.replace('bg-red-50','bg-green-50'); tx.classList.replace('text-red-600','text-green-700'); b.disabled=false; b.innerHTML="<i data-lucide='printer' class='w-4 h-4'></i> Bayar & Cetak"; }
            lucide.createIcons();
        }
        <?php endif; ?>

        // POLLING AJAX (Persistent Audio Storage)
        let lastCheck = "<?= date('Y-m-d H:i:s') ?>"; 
        let audioCtx = null; 
        let snd = null;
        let audioEnabled = localStorage.getItem('nvitens_audio') === '1';

        function initAudio() {
            if(audioCtx) return;
            try {
                audioCtx = new (window.AudioContext || window.webkitAudioContext)();
                snd = () => {
                    if(!audioCtx || audioCtx.state === 'suspended') return;
                    const o = audioCtx.createOscillator(); const g = audioCtx.createGain();
                    o.type = 'sine'; o.frequency.setValueAtTime(880, audioCtx.currentTime); o.frequency.exponentialRampToValueAtTime(1760, audioCtx.currentTime + 0.1); 
                    g.gain.setValueAtTime(0, audioCtx.currentTime); g.gain.linearRampToValueAtTime(1, audioCtx.currentTime + 0.05); g.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.5);
                    o.connect(g); g.connect(audioCtx.destination); o.start(); o.stop(audioCtx.currentTime + 0.5);
                };
            } catch(e) {}
        }

        const btnAudio = document.getElementById('btn-audio-init');
        
        // Auto-activate visual & bind if previously enabled
        if(audioEnabled) {
            btnAudio.classList.replace('text-slate-400', 'text-brand-500'); 
            btnAudio.classList.replace('bg-slate-100', 'bg-brand-50');
            document.body.addEventListener('click', initAudio, { once: true });
            document.body.addEventListener('touchstart', initAudio, { once: true });
        }

        // Toggle Audio Button
        btnAudio.addEventListener('click', function(e) {
            e.stopPropagation();
            audioEnabled = !audioEnabled;
            if(audioEnabled) {
                localStorage.setItem('nvitens_audio', '1');
                this.classList.replace('text-slate-400', 'text-brand-500'); 
                this.classList.replace('bg-slate-100', 'bg-brand-50');
                initAudio(); 
                alert('Notifikasi Suara Aktif!');
            } else {
                localStorage.setItem('nvitens_audio', '0');
                this.classList.replace('text-brand-500', 'text-slate-400'); 
                this.classList.replace('bg-brand-50', 'bg-slate-100');
                audioCtx = null; 
                snd = null;
            }
        });

        // Loop check API
        setInterval(() => {
            fetch(`/api/check_new_orders?last_check=${encodeURIComponent(lastCheck)}`).then(r=>r.json()).then(d => {
                if(d.status === 'success') {
                    let h = false;
                    const bo = document.getElementById('nav-badge-order'); 
                    if(d.new_orders > 0 && bo){ bo.classList.remove('hidden'); bo.classList.add('animate-pulse'); h = true; }
                    const br = document.getElementById('nav-badge-res'); 
                    if(br && d.new_reservations > 0){ br.classList.remove('hidden'); br.classList.add('animate-pulse'); h = true; }
                    
                    if(h && snd) snd(); 
                    if(!h) lastCheck = d.timestamp;
                }
            }).catch(e=>e);
        }, 15000);
    </script>
</body>
</html>