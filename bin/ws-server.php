<?php
require __DIR__ . '/../vendor/autoload.php';

use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use SecureChat\ChatServer;

$server = IoServer::factory(new HttpServer(new WsServer(new ChatServer())), 8081, '0.0.0.0');
fwrite(STDOUT, "WebSocket server listening on 0.0.0.0:8081\n");
$server->run();
