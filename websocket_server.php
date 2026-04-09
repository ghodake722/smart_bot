<?php
date_default_timezone_set("Asia/Kolkata");
require_once __DIR__ . '/vendor/autoload.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use React\EventLoop\Loop;

class TimeServer implements MessageComponentInterface {
    protected $clients;

    public function __construct() {
        $this->clients = new \SplObjectStorage;
        echo "WebSocket Server Initialized...\n";
    }

    public function onOpen(ConnectionInterface $conn) {
        $this->clients->attach($conn);
        echo "New client connected! ({$conn->resourceId})\n";
        
        // Output immediate timestamp upon connection
        $conn->send(json_encode([
            'type' => 'time_update',
            'timestamp' => date('Y-m-d H:i:s')
        ]));
    }

    public function onMessage(ConnectionInterface $from, $msg) {
        // Echo back or handle messages if needed
        echo sprintf("Connection %d sending message \"%s\"\n", $from->resourceId, $msg);
    }

    public function onClose(ConnectionInterface $conn) {
        $this->clients->detach($conn);
        echo "Client {$conn->resourceId} has disconnected\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e) {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }
    
    public function broadcastTime() {
        if (count($this->clients) > 0) {
            $payload = json_encode([
                'type' => 'time_update',
                'timestamp' => date('Y-m-d H:i:s')
            ]);
            foreach ($this->clients as $client) {
                $client->send($payload);
            }
        }
    }
}

// 1. Initialize Event Loop
$loop = Loop::get();
$timeServer = new TimeServer();

// 2. Add Periodic Timer (Executes every 1.0 seconds)
$loop->addPeriodicTimer(1.0, function () use ($timeServer) {
    $timeServer->broadcastTime();
});

// 3. Construct WebSocket Server binding to 0.0.0.0 (All interfaces) on port 8080
$socket = new React\Socket\SocketServer('0.0.0.0:8080', [
    'tls' => false
]);

$server = new Ratchet\Server\IoServer(
    new Ratchet\Http\HttpServer(
        new Ratchet\WebSocket\WsServer(
            $timeServer
        )
    ),
    $socket,
    $loop
);

echo "ReactPHP Ratchet WebSocket server running actively on port 8080...\n";
$server->run();
