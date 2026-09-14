<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

/**
 * Get all accounts from the specified accounts table

 */
function getAccounts($pdo, $accountsTable) {
    $stmt = $pdo->query("SELECT * FROM `$accountsTable`");
    return $stmt->fetchAll();
}
/**
 * Add a transaction into the specified transactions table
 * @param array $entries must include resolved account_name and account_type
 */
function addTransaction($pdo, $transactionsTable, $date, $description, $entries) {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        INSERT INTO `$transactionsTable` (date, description, account_name, account_type, entry_type, amount) 
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    foreach ($entries as $entry) {
        $stmt->execute([
            $date,
            $description,
            $entry['resolved_account_name'],
            $entry['resolved_account_type'],
            $entry['type'],
            $entry['amount']
        ]);
    }

    $pdo->commit();
}
?>
