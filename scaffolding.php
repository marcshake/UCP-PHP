<?php
/**
 * UCP (Universal Commerce Protocol) Reference Implementation in PHP
 * Portierung des Node.js Samples
 * NOT FOR PRODUCTION USE!!!!
 * Usage: Starten Sie den Server mit "php -S localhost:3000 index.php"
 */
exit('NOT FOR PRODUCTION USE! JUST A PORT OF NODEJS, UNTESTED');
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// CORS Handle
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

// ------------------------------------------------------------------
// 1. DATABASE SETUP (SQLite)
// ------------------------------------------------------------------
class Database {
    private $pdo;

    public function __construct() {
        // Erstellt die DBs im selben Ordner, wenn sie nicht existieren
        $this->pdo = new PDO('sqlite:commerce.db');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->initialize();
    }

    private function initialize() {
        // Produkte Tabelle (Beispiel: Blumenladen)
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS products (
            id TEXT PRIMARY KEY,
            name TEXT NOT NULL,
            description TEXT,
            price_amount INTEGER NOT NULL, -- in Cents
            price_currency TEXT NOT NULL,
            image_url TEXT
        )");

        // Transaktionen/Checkout Tabelle
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS checkouts (
            id TEXT PRIMARY KEY,
            status TEXT NOT NULL,
            items TEXT, -- JSON String
            total_amount INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Seed Data wenn leer
        $stmt = $this->pdo->query("SELECT count(*) FROM products");
        if ($stmt->fetchColumn() == 0) {
            $this->pdo->exec("INSERT INTO products (id, name, description, price_amount, price_currency) VALUES 
                ('prod_001', 'Rote Rosen', 'Ein Strauß frischer roter Rosen', 2999, 'EUR'),
                ('prod_002', 'Sonnenblumen', 'Strahlende Sonnenblumen', 1550, 'EUR'),
                ('prod_003', 'Tulpen Mix', 'Bunter Tulpenstrauß', 1299, 'EUR')
            ");
        }
    }

    public function getPdo() {
        return $this->pdo;
    }
}

// ------------------------------------------------------------------
// 2. HELPERS & ROUTER
// ------------------------------------------------------------------
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function getJsonInput() {
    $input = json_decode(file_get_contents('php://input'), true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        jsonResponse(['error' => 'Invalid JSON'], 400);
    }
    return $input;
}

// Einfacher Router
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$db = (new Database())->getPdo();

// ------------------------------------------------------------------
// 3. API ENDPOINTS (Implementation der UCP Logic)
// ------------------------------------------------------------------

// Route: Discovery (Was kann dieser Server?)
if ($method === 'GET' && $path === '/ucp/capabilities') {
    jsonResponse([
        'capabilities' => [
            'checkout' => [
                'version' => 'v1',
                'features' => ['guest_checkout', 'address_collection']
            ],
            'product_discovery' => [
                'version' => 'v1'
            ]
        ]
    ]);
}

// Route: Get Products
if ($method === 'GET' && $path === '/products') {
    $stmt = $db->query("SELECT * FROM products");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    jsonResponse(['products' => $products]);
}

// Route: Create Checkout Session
if ($method === 'POST' && $path === '/checkout/sessions') {
    $input = getJsonInput();
    $checkoutId = uniqid('sess_');
    
    // Initialer Status
    $session = [
        'id' => $checkoutId,
        'status' => 'active',
        'items' => json_encode($input['items'] ?? []),
        'total_amount' => 0 // Würde normalerweise berechnet werden
    ];

    // Preisberechnung (vereinfacht)
    $total = 0;
    $itemsInput = $input['items'] ?? [];
    $processedItems = [];
    
    foreach ($itemsInput as $item) {
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$item['product_id']]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            $lineTotal = $product['price_amount'] * ($item['quantity'] ?? 1);
            $total += $lineTotal;
            $processedItems[] = [
                'product_id' => $product['id'],
                'name' => $product['name'],
                'quantity' => $item['quantity'],
                'line_total' => $lineTotal,
                'currency' => $product['price_currency']
            ];
        }
    }

    $session['total_amount'] = $total;
    $session['items'] = json_encode($processedItems);

    $stmt = $db->prepare("INSERT INTO checkouts (id, status, items, total_amount) VALUES (:id, :status, :items, :total_amount)");
    $stmt->execute($session);

    $session['items'] = $processedItems; // Für Output dekodieren
    jsonResponse($session, 201);
}

// Route: Get Checkout Session
if ($method === 'GET' && preg_match('#^/checkout/sessions/([^/]+)$#', $path, $matches)) {
    $checkoutId = $matches[1];
    $stmt = $db->prepare("SELECT * FROM checkouts WHERE id = ?");
    $stmt->execute([$checkoutId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        jsonResponse(['error' => 'Session not found'], 404);
    }

    $session['items'] = json_decode($session['items'], true);
    jsonResponse($session);
}

// Route: Complete Checkout (Bestellung abschließen)
if ($method === 'POST' && preg_match('#^/checkout/sessions/([^/]+)/complete$#', $path, $matches)) {
    $checkoutId = $matches[1];
    
    // Prüfen ob Session existiert
    $stmt = $db->prepare("SELECT * FROM checkouts WHERE id = ?");
    $stmt->execute([$checkoutId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$session) {
        jsonResponse(['error' => 'Session not found'], 404);
    }

    // Status aktualisieren
    $updateStmt = $db->prepare("UPDATE checkouts SET status = 'completed' WHERE id = ?");
    $updateStmt->execute([$checkoutId]);

    jsonResponse([
        'status' => 'completed',
        'order_id' => 'ord_' . bin2hex(random_bytes(4)),
        'message' => 'Vielen Dank für Ihren Einkauf!'
    ]);
}

// 404 Fallback
jsonResponse(['error' => 'Not Found', 'path' => $path], 404);

?>
