<?php
session_start();
require 'db_connection.php';

$client_id = $_GET['client_id'] ?? null;
if (!$client_id) exit;

$stmt = $pdo->prepare("
  SELECT * FROM messages
  WHERE sender_id = :id OR recipient_id = :id
  ORDER BY sent_at ASC
");
$stmt->execute(['id' => $client_id]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($messages as $m): ?>
  <div class="msg <?= $m['is_admin_reply'] ? 'admin-msg' : 'client-msg' ?>">
    <?= nl2br(htmlspecialchars($m['message'])) ?>
    <div class="msg-time"><?= $m['sent_at'] ?></div>
  </div>
<?php endforeach; ?>
