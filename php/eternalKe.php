<?php
session_start();
require_once "db.php";

if (!isset($_SESSION["user_id"])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $user_id = $_SESSION["user_id"];
    $phone_number = $_POST["phone_number"];
    $amount = $_POST["amount"];

    if ($amount < 400) {
        die("Minimum deposit is Ksh 400.");
    }

    $cleaned_phone = '254' . substr($phone_number, 1);
    
    $stk_data = [
        'phoneNumber' => $cleaned_phone,
        'amount' => $amount
    ];
    
    $ch = curl_init('https://mpesa-stk.giftedtech.co.ke/api/payTheLegend.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($stk_data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    $result = json_decode($response, true);
    
    if ($result['success']) {
        $checkoutRequestId = $result['CheckoutRequestID'];
        $merchantRequestId = $result['MerchantRequestID'];
        // insert txn to db
        $stmt = $pdo->prepare("INSERT INTO deposits (user_id, phone_number, amount, mpesa_code, checkout_request_id, merchant_request_id, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$user_id, $phone_number, $amount, "PENDING", $checkoutRequestId, $merchantRequestId, "pending"]);
        $transaction_id = $pdo->lastInsertId();
        
        // polling txn status
        $max_attempts = 60; // 1 min max check
        $attempt = 0;
        $transaction_status = "pending";
        $mpesa_code = "PENDING";
        
        while ($attempt < $max_attempts && ($transaction_status === "pending" || $transaction_status === "processing")) {
            sleep(1); // check txn status every second
            
            $verify_data = [
                'checkoutRequestId' => $checkoutRequestId
            ];
            
            $ch = curl_init('https://mpesa-stk.giftedtech.co.ke/api/verify-transaction.php');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($verify_data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json'
            ]);
            
            $verify_response = curl_exec($ch);
            curl_close($ch);
            
            $verify_result = json_decode($verify_response, true);
            
            if ($verify_result['success']) {
                $transaction_status = $verify_result['status'];
                
                if ($transaction_status === "completed") {
                    $mpesa_code = isset($verify_result['data']['MpesaReceiptNumber']) 
                        ? $verify_result['data']['MpesaReceiptNumber'] 
                        : "COMPLETED";
                    break;
                } elseif ($transaction_status === "failed") {
                    $mpesa_code = "FAILED";
                    break;
                }
            }
            
            $attempt++;
        }
        // final txn update in db
        $update_stmt = $pdo->prepare("UPDATE deposits SET mpesa_code = ?, status = ? WHERE id = ?");
        $update_stmt->execute([$mpesa_code, $transaction_status, $transaction_id]);
        
        if ($transaction_status === "completed") {
            echo "Payment successful! M-Pesa Code: $mpesa_code";
        } elseif ($transaction_status === "failed") {
            echo "Payment failed. Please try again.";
        } else {
            echo "Payment is taking longer than expected. We'll notify you once processed.";
        }
    } else {
        echo "Failed to send STK push: " . $result['message'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Safaricom Payment</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
       body { Background: linear-gradient(to bottom, #001f54, #003366); color: white; font-family: sans-serif; padding: 20px; }
        .container {
            max-width: 500px;
            margin-top: 50px;
        }
          .bottom-nav {
  position: fixed;
  bottom: 0;
  left: 0;
  right: 0;
  background: #000;
  display: flex;
  justify-content: space-around;
  padding: 10px 0;
}
        .amount {
            color: blue;
            font-weight: bold;
            font-size: 24px;
        }
        .deposit-btn{
            background-color: #c2ff3d; 
            color: white;
        }
        b{
        color:orange;
        font-size: 22px;
        }
        .instruction-box {
  background-color: #f9f9f9;
  border: 1px solid #ddd;
  border-radius: 6px;
  padding: 15px 20px;
  max-width: 600px;
  margin: 20px auto;
  font-family: Arial, sans-serif;
  color: #333;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
}

.instruction-box h3 {
  font-size: 16px;
  margin-bottom: 10px;
}

.instruction-box ol {
  padding-left: 18px;
  font-size: 14px;
  line-height: 1.6;
}
    </style>
</head>
<body>
    <div class="container">
        <hr>
        <h2>💰 Deposit</h2>
     
        <form method="POST">
            <div class="mb-3">
                <label for="phone" class="form-label">Phone Number</label>
                <input type="text" class="form-control" name="phone_number" id="phone" placeholder="0XXXXXXXXX" required>
            </div>
            <div class="mb-3">
                <label for="amount" class="form-label">Amount (KES)</label>
                <input type="number" class="form-control" name="amount" id="amount" placeholder="e.g. 1000">
            </div>
            
            <button type="submit" class="btn deposit-btn w-100"><i class="fas fa-paper-plane"></i> Deposit</button>
        </form>
    </div>

    <h3>Recharge Instructions</h3>
    <div class="instruction-box">
        <ol>
            <li>The minimum recharge amount is 400 KSH. If the amount is less than the minimum, it will not be deposited.</li>
            <li>Please verify the account information carefully when transferring funds to avoid payment errors.</li>
            <li>Please comply with the recharge rules. If you do not recharge according to the platform rules.</li>
            <li>After a successful transfer, please wait for 20 to 30 minutes. If the payment does not arrive for a long time, please contact online customer service.</li>
        </ol>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
