<?php
/**
 * API Printers Configuration
 * Handles reading and writing printer configurations to printers.json
 */

header('Content-Type: application/json');

$configFile = __DIR__ . '/printers.json';

$action = $_GET['action'] ?? 'load';

try {
    if ($action === 'load') {
        if (file_exists($configFile)) {
            $json = file_get_contents($configFile);
            $config = json_decode($json, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Invalid JSON in printers.json');
            }
            
            echo json_encode([
                'success' => true,
                'printers' => $config['printers'] ?? []
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'printers' => []
            ]);
        }
    } 
    elseif ($action === 'save') {
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!isset($input['printers'])) {
            throw new Exception('Missing printers data');
        }
        
        $printers = $input['printers'];
        
        foreach ($printers as $printer) {
            if (empty($printer['cassa_id'])) {
                throw new Exception('Cassa ID cannot be empty');
            }
            if (empty($printer['ip'])) {
                throw new Exception('IP address cannot be empty');
            }
            if (!isset($printer['type']) || !in_array($printer['type'], ['usb', 'network'])) {
                throw new Exception('Invalid printer type');
            }
            if (!isset($printer['port']) || $printer['port'] < 1 || $printer['port'] > 65535) {
                throw new Exception('Invalid port number');
            }
            if ($printer['type'] === 'usb' && empty(trim($printer['printer_name'] ?? ''))) {
                throw new Exception('Printer name is required for USB Printrz entries');
            }
        }
        
        $config = [
            'printer' => [
                'type' => 'printbridge',
                'printbridge' => [
                    'mode' => 'client_mapping',
                    'clients' => []
                ]
            ]
        ];
        
        foreach ($printers as $printer) {
            $cassa_id = $printer['cassa_id'];
            
            if ($printer['type'] === 'usb') {
                $config['printer']['printbridge']['clients'][$cassa_id] = [
                    'host' => $printer['ip'],
                    'port' => intval($printer['port']),
                    'type' => 'usb',
                    'printer_name' => $printer['printer_name'] ?? '',
                    'description' => $cassa_id
                ];
            } else {
                $config['printer']['printbridge']['clients'][$cassa_id] = [
                    'host' => $printer['ip'],
                    'port' => intval($printer['port']),
                    'type' => 'network',
                    'description' => $cassa_id
                ];
            }
        }
        
        $config['printers'] = $printers;
        
        $json = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        
        if (file_put_contents($configFile, $json) === false) {
            throw new Exception('Failed to write printers.json');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'Configuration saved successfully'
        ]);
    } 
    else {
        throw new Exception('Unknown action: ' . $action);
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>