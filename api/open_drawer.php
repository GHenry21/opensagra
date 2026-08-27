<?php
require __DIR__ . '/../vendor/autoload.php';

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;
use Mike42\Escpos\PrintConnectors\NetworkPrintConnector;

try {
    $connector_usb = new WindowsPrintConnector("POS-80C"); // Sostituisci con il nome reale della stampante
    $printer = new Printer($connector_usb);

    // Per stampanti LAN,
    $ip = '192.168.88.10';
    $port = 9100;

    $connector_lan = new NetworkPrintConnector($ip, $port);
    $printer = new Printer($connector_lan);

    $printer->pulse(); // Comando per aprire il cassetto
    $printer->close();
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore apertura cassetto: ' . $e->getMessage()]);
}
?>
