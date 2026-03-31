<?php
// ============================================================
// CONFIG.PHP - Cấu hình toàn hệ thống
// ============================================================

// Database
define('DB_HOST', 'localhost');
define('DB_NAME', 'smm_panel');
define('DB_USER', 'root');
define('DB_PASS', '');

// Provider API (mua key tại justanotherpanel.com, peakerr.com...)
define('PROVIDER_API_URL', 'https://justanotherpanel.com/api/v2');
define('PROVIDER_API_KEY', '[
    {
        "service": 1,
        "name": "Followers",
        "type": "Default",
        "category": "First Category",
        "rate": "0.90",
        "min": "50",
        "max": "10000",
        "refill": true,
        "cancel": true
    },
    {
        "service": 2,
        "name": "Comments",
        "type": "Custom Comments",
        "category": "Second Category",
        "rate": "8",
        "min": "10",
        "max": "1500",
        "refill": false,
        "cancel": true
    }
]');  // Dán key của bạn vào đây

// Tỉ lệ lợi nhuận: Provider bán 10đ → bạn bán 20đ (x2)
define('MARKUP_RATE', 2.0);


// ============================================================
// DATABASE.SQL - Chạy file này trong phpMyAdmin
// ============================================================
/*
CREATE TABLE services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    provider_service_id INT NOT NULL,   -- ID dịch vụ bên provider
    platform VARCHAR(50),               -- facebook, tiktok, youtube...
    name VARCHAR(255),
    description TEXT,
    price_per_1000 DECIMAL(10,2),       -- Giá provider (đồng/1000)
    sell_price_per_1000 DECIMAL(10,2),  -- Giá bán ra (đồng/1000)
    min_order INT DEFAULT 10,
    max_order INT DEFAULT 1000000,
    is_active TINYINT DEFAULT 1
);

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    service_id INT NOT NULL,
    provider_order_id INT,              -- ID đơn bên provider
    link VARCHAR(500) NOT NULL,
    quantity INT NOT NULL,
    start_count INT DEFAULT 0,          -- Số lượng ban đầu
    remains INT DEFAULT 0,              -- Còn lại chưa chạy
    total_price DECIMAL(15,0) NOT NULL,
    status ENUM('pending','processing','inprogress','completed','partial','canceled','error') DEFAULT 'pending',
    note TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (service_id) REFERENCES services(id)
);

CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_provider ON orders(provider_order_id);
*/


// ============================================================
// DB CLASS
// ============================================================
class DB {
    private static $pdo = null;

    public static function get(): \PDO {
        if (!self::$pdo) {
            self::$pdo = new PDO(
                "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
                DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                 PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
            );
        }
        return self::$pdo;
    }

    public static function query(string $sql, array $p = []) {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($p);
        return $stmt;
    }

    public static function fetch(string $sql, array $p = []): ?array {
        return self::query($sql, $p)->fetch() ?: null;
    }

    public static function fetchAll(string $sql, array $p = []): array {
        return self::query($sql, $p)->fetchAll();
    }

    public static function lastId(): string { return self::get()->lastInsertId(); }

    public static function begin()    { self::get()->beginTransaction(); }
    public static function commit()   { self::get()->commit(); }
    public static function rollback() { self::get()->rollBack(); }
}


// ============================================================
// PROVIDER API CLASS
// Giao tiếp với nhà cung cấp buff (justanotherpanel, peakerr...)
// Tất cả panel SMM đều dùng chuẩn API giống nhau
// ============================================================
class ProviderAPI {

    /**
     * Gọi API provider
     */
    private static function call(array $params): array {
        $params['key'] = PROVIDER_API_KEY;

        $ch = curl_init(PROVIDER_API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode !== 200) {
            throw new Exception("Lỗi kết nối API provider: HTTP $httpCode");
        }

        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Response API không hợp lệ: $response");
        }

        if (isset($data['error'])) {
            throw new Exception("Provider lỗi: " . $data['error']);
        }

        return $data;
    }

    /**
     * Lấy danh sách dịch vụ từ provider
     * Dùng để đồng bộ services vào database
     */
    public static function getServices(): array {
        return self::call(['action' => 'services']);
    }

    /**
     * ĐẶT ĐƠN HÀNG lên provider
     * @return int Provider Order ID
     */
    public static function addOrder(int $serviceId, string $link, int $quantity): int {
        $result = self::call([
            'action'   => 'add',
            'service'  => $serviceId,
            'link'     => $link,
            'quantity' => $quantity,
        ]);

        if (!isset($result['order'])) {
            throw new Exception("Provider không trả về order ID");
        }

        return (int)$result['order'];
    }

    /**
     * KIỂM TRA TRẠNG THÁI một đơn
     */
    public static function getOrderStatus(int $providerOrderId): array {
        $result = self::call([
            'action' => 'status',
            'order'  => $providerOrderId,
        ]);
        // Trả về: charge, start_count, status, remains, currency
        return $result;
    }

    /**
     * KIỂM TRA NHIỀU ĐƠN cùng lúc (tối đa 100)
     */
    public static function getMultipleStatus(array $providerOrderIds): array {
        $result = self::call([
            'action' => 'status',
            'orders' => implode(',', $providerOrderIds),
        ]);
        return $result; // array[orderId] => {charge, start_count, status, remains}
    }

    /**
     * YÊU CẦU REFILL (bù lại nếu tụt)
     */
    public static function refill(int $providerOrderId): string {
        $result = self::call([
            'action' => 'refill',
            'order'  => $providerOrderId,
        ]);
        return $result['refill'] ?? '';
    }

    /**
     * Lấy số dư API của bạn bên provider
     */
    public static function getBalance(): float {
        $result = self::call(['action' => 'balance']);
        return (float)($result['balance'] ?? 0);
    }
}


// ============================================================
// ORDER SERVICE - Xử lý đặt hàng
// ============================================================
class OrderService {

    /**
     * BƯỚC 1: Khách đặt đơn
     * - Kiểm tra số dư
     * - Trừ tiền
     * - Lưu vào DB với status = pending
     * - Gọi API provider
     * - Cập nhật provider_order_id
     */
    public static function placeOrder(int $userId, int $serviceId, string $link, int $quantity): array {
        // Lấy thông tin dịch vụ
        $service = DB::fetch("SELECT * FROM services WHERE id = ? AND is_active = 1", [$serviceId]);
        if (!$service) throw new Exception("Dịch vụ không tồn tại hoặc đã tắt");

        // Kiểm tra min/max
        if ($quantity < $service['min_order']) throw new Exception("Số lượng tối thiểu: " . number_format($service['min_order']));
        if ($quantity > $service['max_order']) throw new Exception("Số lượng tối đa: " . number_format($service['max_order']));

        // Tính tiền
        $totalPrice = (int)ceil($quantity / 1000 * $service['sell_price_per_1000']);
        if ($totalPrice < 1) $totalPrice = 1;

        // Kiểm tra & trừ số dư (dùng transaction để tránh race condition)
        DB::begin();
        try {
            $user = DB::fetch("SELECT balance FROM users WHERE id = ? FOR UPDATE", [$userId]);
            if ((int)$user['balance'] < $totalPrice) {
                throw new Exception("Số dư không đủ. Cần: " . number_format($totalPrice) . "đ, Hiện có: " . number_format($user['balance']) . "đ");
            }

            // Trừ tiền
            DB::query("UPDATE users SET balance = balance - ? WHERE id = ?", [$totalPrice, $userId]);

            // Tạo đơn hàng trong DB
            DB::query(
                "INSERT INTO orders (user_id, service_id, link, quantity, total_price, status)
                 VALUES (?, ?, ?, ?, ?, 'pending')",
                [$userId, $serviceId, $link, $quantity, $totalPrice]
            );
            $orderId = (int)DB::lastId();

            DB::commit();

            // Ghi balance log
            DB::query(
                "INSERT INTO balance_logs (user_id, type, amount, ref_id, note)
                 VALUES (?, 'order', ?, ?, ?)",
                [$userId, -$totalPrice, $orderId, "Đặt đơn #$orderId - {$service['name']}"]
            );

        } catch (Exception $e) {
            DB::rollback();
            throw $e;
        }

        // BƯỚC 2: Gửi lên provider (ngoài transaction để tránh lock lâu)
        try {
            $providerOrderId = ProviderAPI::addOrder(
                $service['provider_service_id'],
                $link,
                $quantity
            );

            // Cập nhật provider_order_id và chuyển status → processing
            DB::query(
                "UPDATE orders SET provider_order_id = ?, status = 'processing' WHERE id = ?",
                [$providerOrderId, $orderId]
            );

        } catch (Exception $e) {
            // Provider lỗi → hoàn tiền, đánh dấu lỗi
            DB::query("UPDATE orders SET status = 'error', note = ? WHERE id = ?", [$e->getMessage(), $orderId]);
            DB::query("UPDATE users SET balance = balance + ? WHERE id = ?", [$totalPrice, $userId]);
            DB::query(
                "INSERT INTO balance_logs (user_id, type, amount, ref_id, note)
                 VALUES (?, 'refund', ?, ?, ?)",
                [$userId, $totalPrice, $orderId, "Hoàn tiền đơn #$orderId - Provider lỗi: " . $e->getMessage()]
            );
            throw new Exception("Đặt hàng thất bại: " . $e->getMessage());
        }

        return [
            'order_id'         => $orderId,
            'provider_order_id'=> $providerOrderId,
            'total_price'      => $totalPrice,
            'status'           => 'processing',
        ];
    }


    /**
     * Lấy lịch sử đơn hàng của user
     */
    public static function getHistory(int $userId, int $page = 1, int $limit = 20): array {
        $offset = ($page - 1) * $limit;
        $orders = DB::fetchAll(
            "SELECT o.*, s.name AS service_name, s.platform
             FROM orders o
             JOIN services s ON s.id = o.service_id
             WHERE o.user_id = ?
             ORDER BY o.created_at DESC
             LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );
        $total = DB::fetch("SELECT COUNT(*) AS cnt FROM orders WHERE user_id = ?", [$userId]);
        return ['orders' => $orders, 'total' => (int)$total['cnt'], 'page' => $page];
    }
}


// ============================================================
// CRON JOB - cron.php
// Chạy mỗi 1 phút bằng lệnh:
// * * * * * php /var/www/html/cron.php >> /var/log/smm_cron.log 2>&1
// ============================================================
class CronJob {

    public static function run(): void {
        echo "[" . date('Y-m-d H:i:s') . "] Cron bắt đầu chạy...\n";

        self::syncOrderStatuses();
        self::syncServices();

        echo "[" . date('Y-m-d H:i:s') . "] Cron hoàn thành.\n";
    }

    /**
     * Đồng bộ trạng thái đơn hàng từ provider về DB
     */
    private static function syncOrderStatuses(): void {
        // Lấy tất cả đơn đang chạy (chưa xong)
        $pendingOrders = DB::fetchAll(
            "SELECT id, user_id, provider_order_id, quantity, total_price, status
             FROM orders
             WHERE status IN ('pending','processing','inprogress')
               AND provider_order_id IS NOT NULL
             LIMIT 100"  // Mỗi lần xử lý 100 đơn
        );

        if (empty($pendingOrders)) {
            echo "  → Không có đơn nào cần cập nhật.\n";
            return;
        }

        // Lấy danh sách provider_order_id
        $providerIds = array_column($pendingOrders, 'provider_order_id');
        echo "  → Đang kiểm tra " . count($providerIds) . " đơn hàng...\n";

        try {
            // Gọi 1 request duy nhất cho nhiều đơn (tiết kiệm API calls)
            $statuses = ProviderAPI::getMultipleStatus($providerIds);
        } catch (Exception $e) {
            echo "  ✗ Lỗi lấy trạng thái: " . $e->getMessage() . "\n";
            return;
        }

        // Xây dựng map: providerOrderId → order info
        $orderMap = [];
        foreach ($pendingOrders as $o) {
            $orderMap[$o['provider_order_id']] = $o;
        }

        foreach ($statuses as $providerOrderId => $statusData) {
            if (!isset($orderMap[$providerOrderId])) continue;

            $order     = $orderMap[$providerOrderId];
            $newStatus = self::mapProviderStatus($statusData['status'] ?? '');
            $remains   = (int)($statusData['remains'] ?? 0);
            $startCount= (int)($statusData['start_count'] ?? 0);

            // Cập nhật vào DB
            DB::query(
                "UPDATE orders SET status = ?, remains = ?, start_count = ?, updated_at = NOW()
                 WHERE id = ?",
                [$newStatus, $remains, $startCount, $order['id']]
            );

            echo "  → Đơn #{$order['id']} (Provider: $providerOrderId): {$order['status']} → $newStatus\n";

            // Nếu đơn bị hủy từ phía provider → hoàn tiền
            if ($newStatus === 'canceled' && $order['status'] !== 'canceled') {
                self::refundOrder($order);
            }

            // Nếu partial (chạy thiếu) → hoàn tiền phần còn lại
            if ($newStatus === 'partial' && $order['status'] !== 'partial') {
                self::refundPartial($order, $remains);
            }
        }
    }

    /**
     * Map trạng thái provider → trạng thái hệ thống
     */
    private static function mapProviderStatus(string $providerStatus): string {
        return match(strtolower($providerStatus)) {
            'pending'    => 'pending',
            'processing' => 'processing',
            'inprogress', 'in progress' => 'inprogress',
            'completed'  => 'completed',
            'partial'    => 'partial',
            'canceled', 'cancelled' => 'canceled',
            default      => 'processing',
        };
    }

    /**
     * Hoàn tiền toàn bộ đơn bị hủy
     */
    private static function refundOrder(array $order): void {
        DB::query("UPDATE users SET balance = balance + ? WHERE id = ?", [$order['total_price'], $order['user_id']]);
        DB::query(
            "INSERT INTO balance_logs (user_id, type, amount, ref_id, note) VALUES (?, 'refund', ?, ?, ?)",
            [$order['user_id'], $order['total_price'], $order['id'], "Hoàn tiền đơn #{$order['id']} bị hủy"]
        );
        echo "  💰 Hoàn tiền {$order['total_price']}đ cho user #{$order['user_id']}\n";
    }

    /**
     * Hoàn tiền phần chạy thiếu (partial)
     */
    private static function refundPartial(array $order, int $remains): void {
        if ($remains <= 0 || $order['quantity'] <= 0) return;

        // Tính tiền hoàn theo tỉ lệ số lượng còn lại
        $refundAmount = (int)ceil($order['total_price'] * $remains / $order['quantity']);
        if ($refundAmount <= 0) return;

        DB::query("UPDATE users SET balance = balance + ? WHERE id = ?", [$refundAmount, $order['user_id']]);
        DB::query(
            "INSERT INTO balance_logs (user_id, type, amount, ref_id, note) VALUES (?, 'refund', ?, ?, ?)",
            [$order['user_id'], $refundAmount, $order['id'], "Hoàn tiền partial đơn #{$order['id']} - Còn $remains"]
        );
        echo "  💰 Hoàn partial {$refundAmount}đ (còn {$remains}) cho user #{$order['user_id']}\n";
    }

    /**
     * Đồng bộ danh sách dịch vụ từ provider (chạy 1 lần/ngày)
     */
    private static function syncServices(): void {
        // Chỉ chạy lúc 3:00 sáng
        if (date('H') !== '03') return;

        echo "  → Đồng bộ danh sách dịch vụ từ provider...\n";
        try {
            $services = ProviderAPI::getServices();
            foreach ($services as $svc) {
                $sellPrice = round($svc['rate'] * MARKUP_RATE * 1000, 0); // Nhân tỉ lệ lợi nhuận
                DB::query(
                    "INSERT INTO services (provider_service_id, name, description, price_per_1000, sell_price_per_1000, min_order, max_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE
                       name = VALUES(name),
                       price_per_1000 = VALUES(price_per_1000),
                       min_order = VALUES(min_order),
                       max_order = VALUES(max_order)",
                    [$svc['service'], $svc['name'], $svc['category'] ?? '', $svc['rate'] * 1000, $sellPrice, $svc['min'], $svc['max']]
                );
            }
            echo "  ✓ Đã đồng bộ " . count($services) . " dịch vụ.\n";
        } catch (Exception $e) {
            echo "  ✗ Lỗi đồng bộ: " . $e->getMessage() . "\n";
        }
    }
}


// ============================================================
// API ENDPOINT - order_api.php
// Đặt tại: /api/order.php
// ============================================================

header('Content-Type: application/json; charset=utf-8');
session_start();

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Chưa đăng nhập']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {

        // Lấy danh sách dịch vụ theo platform
        case 'get_services':
            $platform = $_GET['platform'] ?? 'tiktok';
            $services = DB::fetchAll(
                "SELECT id, name, sell_price_per_1000, min_order, max_order, description
                 FROM services WHERE platform = ? AND is_active = 1 ORDER BY name",
                [$platform]
            );
            echo json_encode(['success' => true, 'data' => $services]);
            break;

        // Tính giá trước khi đặt
        case 'calc_price':
            $serviceId = (int)($_POST['service_id'] ?? 0);
            $quantity  = (int)($_POST['quantity'] ?? 0);
            $svc = DB::fetch("SELECT sell_price_per_1000 FROM services WHERE id = ?", [$serviceId]);
            if (!$svc) throw new Exception("Dịch vụ không tồn tại");
            $price = (int)ceil($quantity / 1000 * $svc['sell_price_per_1000']);
            echo json_encode(['success' => true, 'price' => $price, 'formatted' => number_format($price) . 'đ']);
            break;

        // ĐẶT ĐƠN HÀNG
        case 'place_order':
            $serviceId = (int)($_POST['service_id'] ?? 0);
            $link      = trim($_POST['link'] ?? '');
            $quantity  = (int)($_POST['quantity'] ?? 0);

            // Validate link
            if (empty($link) || !filter_var($link, FILTER_VALIDATE_URL)) {
                throw new Exception("Link không hợp lệ, vui lòng nhập đúng URL");
            }

            $result = OrderService::placeOrder($_SESSION['user_id'], $serviceId, $link, $quantity);

            // Trả về số dư mới
            $user = DB::fetch("SELECT balance FROM users WHERE id = ?", [$_SESSION['user_id']]);
            $result['new_balance'] = $user['balance'];

            echo json_encode(['success' => true, 'data' => $result, 'message' => 'Đặt hàng thành công! Đơn đang được xử lý.']);
            break;

        // Lịch sử đơn hàng
        case 'history':
            $page = max(1, (int)($_GET['page'] ?? 1));
            $data = OrderService::getHistory($_SESSION['user_id'], $page);
            echo json_encode(['success' => true, 'data' => $data]);
            break;

        default:
            throw new Exception("Action không hợp lệ");
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}


// ============================================================
// ENTRY POINT CHO CRON JOB
// File: cron.php — chạy độc lập từ command line
// ============================================================
/*
<?php
// Chỉ cho phép chạy từ CLI
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/config.php';
CronJob::run();
*/
