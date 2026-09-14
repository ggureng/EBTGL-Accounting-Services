<?php


ini_set('display_errors', 1);


ini_set('display_startup_errors', 1);


error_reporting(E_ALL);


require 'db_connection.php';


session_start();








$user_id = $_SESSION['user_id'] ?? null; // Get user_id from session





$accountsTable = $_GET['acct'] ?? null;


$transactionsTable = $_GET['txn'] ?? null;





if (!$accountsTable || !$transactionsTable) {


    die("Missing table names.");


}








if ($_SERVER['REQUEST_METHOD'] === 'POST') {


    $date = $_POST['date'];


    $description = $_POST['description'];


    $entries = $_POST['entries'];


    $documents = $_FILES['document'] ?? null;





    try {


        $pdo->beginTransaction();


        $resolvedEntries = [];





        foreach ($entries as $index => $entry) {


            $account_id = $entry['account_id'];


            $type = $entry['type'];


            $amount = $entry['amount'];


            $documentPath = null;





            // Handle file upload (must match the index in the loop)


            if ($documents && isset($documents['name'][$index]) && $documents['error'][$index] === UPLOAD_ERR_OK) {


                $uploadDir = 'uploads/';


                if (!is_dir($uploadDir)) {


                    mkdir($uploadDir, 0777, true);


                }





                $originalName = basename($documents['name'][$index]);


                $safeName = $user_id . '_' . uniqid() . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $originalName);


                $targetPath = $uploadDir . $safeName;





                if (move_uploaded_file($documents['tmp_name'][$index], $targetPath)) {


                    $documentPath = $targetPath;


                }


            }





            // Resolve account name/type


            if ($account_id === 'other') {


                $account_name = trim($entry['other_account']);


                $account_type = $entry['other_type'] ?? '';





                if ($account_name === '' || $account_type === '') {


                    throw new Exception("Missing 'Other' account info.");


                }





                $check = $pdo->prepare("SELECT account_number FROM `$accountsTable` WHERE name = ?");


                $check->execute([$account_name]);


                $existing = $check->fetch();





                if ($existing) {


                    $account_id = $existing['account_number'];


                } else {


                    $insert = $pdo->prepare("INSERT INTO `$accountsTable` (name, type) VALUES (?, ?)");


                    $insert->execute([$account_name, $account_type]);


                    $account_id = $pdo->lastInsertId();


                }





                $resolvedEntries[] = [


                    'account_name' => $account_name,


                    'account_type' => $account_type,


                    'entry_type' => $type,


                    'amount' => $amount,


                    'document_path' => $documentPath


                ];


            } else {


                $stmt = $pdo->prepare("SELECT name, type FROM `$accountsTable` WHERE account_number = ?");


                $stmt->execute([$account_id]);


                $account = $stmt->fetch();





                if (!$account) {


                    throw new Exception("Invalid account ID.");


                }





                $resolvedEntries[] = [


                    'account_name' => $account['name'],


                    'account_type' => $account['type'],


                    'entry_type' => $type,


                    'amount' => $amount,


                    'document_path' => $documentPath


                ];


            }


        }





        // Insert into transactions table


        foreach ($resolvedEntries as $row) {


            $stmt = $pdo->prepare("INSERT INTO `$transactionsTable`


                (date, description, account_name, account_type, entry_type, amount, document_path)


                VALUES (?, ?, ?, ?, ?, ?, ?)");


            $stmt->execute([


                $date,


                $description,


                $row['account_name'],


                $row['account_type'],


                $row['entry_type'],


                $row['amount'],


                $row['document_path']


            ]);


        }





        $pdo->commit();


        header("Location: asset.php?success=1");


        exit;





    } catch (Exception $e) {


        $pdo->rollBack();


        echo "Error: " . $e->getMessage();


    }


}


