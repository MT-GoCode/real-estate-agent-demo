<?php
// Deletes demo users and their chats, for example before recording the demo again.
// Usage: frankenphp php-cli demo/reset-users.php 'linht'      or      'check-%'  (% matches anything)
require __DIR__ . '/../db.php';

$chats = db()->prepare('DELETE FROM chats.chats WHERE user_id IN (SELECT id FROM users.users WHERE username LIKE ?)');
$chats->execute([$argv[1]]);
$people = db()->prepare('DELETE FROM users.users WHERE username LIKE ?');
$people->execute([$argv[1]]);
echo "Deleted {$people->rowCount()} users and {$chats->rowCount()} chats.\n";
