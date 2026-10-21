<?php
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    http_response_code(201);
    echo json_encode(['id' => 901, 'guid' => ['rendered' => 'https://shop.invalid/image.png'], 'bytes' => strlen(file_get_contents('php://input'))]);
} else {
    echo json_encode(['path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), 'search' => $_GET['search'] ?? '']);
}
