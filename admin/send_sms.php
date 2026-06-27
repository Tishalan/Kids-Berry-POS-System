[file name]: send_sms.php
[file content begin]
<?php
// Dialog Ideamart SMS API Integration
function sendDialogSMS($phone, $message) {
    // Dialog Ideamart Credentials - ඔබගේ credentials එහි දමන්න
    $applicationId = "APP_XXXXXX"; // ඔබගේ Dialog Ideamart App ID
    $password = "your_password"; // ඔබගේ Dialog Ideamart Password
    
    // Phone number format කරන්න
    $phone = preg_replace('/[^0-9]/', '', $phone);
    
    // Sri Lankan phone numbers convert කරන්න
    if (substr($phone, 0, 2) == '07') {
        $phone = '94' . substr($phone, 1);
    } elseif (substr($phone, 0, 1) == '7' && strlen($phone) == 9) {
        $phone = '94' . $phone;
    } elseif (substr($phone, 0, 3) == '011' || substr($phone, 0, 3) == '012' || 
              substr($phone, 0, 3) == '013' || substr($phone, 0, 3) == '014' || 
              substr($phone, 0, 3) == '015' || substr($phone, 0, 3) == '016' || 
              substr($phone, 0, 3) == '017' || substr($phone, 0, 3) == '018' || 
              substr($phone, 0, 3) == '019') {
        $phone = '94' . substr($phone, 1);
    }
    
    // Already 94 format නම්
    if (substr($phone, 0, 2) != '94') {
        $phone = '94' . $phone;
    }
    
    // Dialog Ideamart API URL
    $url = "https://api.dialog.lk/sms/send";
    
    // Prepare data
    $data = array(
        'applicationId' => $applicationId,
        'password' => $password,
        'message' => $message,
        'destinationAddresses' => array('tel:' . $phone),
        'encoding' => "0" // 0 for GSM7 (English/Sinhala), 1 for Unicode
    );
    
    // Send request
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json'));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // SSL verify කරන්න එපා test වලදී
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    // Debugging සඳහා log කරන්න
    file_put_contents('sms_log.txt', 
        date('Y-m-d H:i:s') . " | Phone: $phone | Status: $httpCode | Response: $response\n", 
        FILE_APPEND);
    
    if ($httpCode == 200) {
        $result = json_decode($response, true);
        if (isset($result['statusCode']) && $result['statusCode'] == 'S1000') {
            return array('success' => true, 'message' => 'SMS sent successfully');
        } else {
            return array('success' => false, 'error' => $result['statusDetail'] ?? 'Unknown error');
        }
    } else {
        return array('success' => false, 'error' => "HTTP Error: $httpCode - $error");
    }
}

// Bulk SMS Function for All Customers
function sendBulkSMS($message) {
    global $conn; // Database connection
    
    if (empty($message)) {
        return "Message cannot be empty";
    }
    
    // Get all customers with valid phone numbers
    $sql = "SELECT phone_no, name FROM customers WHERE phone_no IS NOT NULL AND phone_no != ''";
    $result = $conn->query($sql);
    
    $total = $result->num_rows;
    $success = 0;
    $failed = 0;
    $failed_numbers = array();
    
    if ($total > 0) {
        // Daily limit check (Dialog Ideamart gives 15 free SMS per day)
        $sms_sent_today = $conn->query("SELECT COUNT(*) as count FROM sms_logs WHERE DATE(sent_at) = CURDATE() AND status='success'")->fetch_assoc()['count'];
        $remaining_sms = max(0, 15 - $sms_sent_today);
        
        if ($remaining_sms <= 0) {
            return "Daily SMS limit reached! You have already sent $sms_sent_today SMS today. Dialog Ideamart allows only 15 free SMS per day.";
        }
        
        if ($total > $remaining_sms) {
            return "You have $remaining_sms SMS remaining today, but you have $total customers. Please send to fewer customers or wait until tomorrow.";
        }
        
        while ($row = $result->fetch_assoc()) {
            $phone = trim($row['phone_no']);
            $name = $row['name'];
            
            // Personalize message - [Customer Name] replace කරන්න
            $personalized_message = str_replace('[Customer Name]', $name, $message);
            
            // Send SMS
            $sms_result = sendDialogSMS($phone, $personalized_message);
            
            if ($sms_result['success']) {
                $success++;
                
                // Log successful SMS
                $log_sql = "INSERT INTO sms_logs (customer_phone, customer_name, message, status, sent_at) 
                           VALUES (?, ?, ?, 'success', NOW())";
                $stmt = $conn->prepare($log_sql);
                $stmt->bind_param("sss", $phone, $name, $personalized_message);
                $stmt->execute();
                $stmt->close();
            } else {
                $failed++;
                $failed_numbers[] = array(
                    'phone' => $phone,
                    'name' => $name,
                    'error' => $sms_result['error']
                );
                
                // Log failed SMS
                $log_sql = "INSERT INTO sms_logs (customer_phone, customer_name, message, status, error, sent_at) 
                           VALUES (?, ?, ?, 'failed', ?, NOW())";
                $stmt = $conn->prepare($log_sql);
                $error_msg = substr($sms_result['error'], 0, 255);
                $stmt->bind_param("ssss", $phone, $name, $personalized_message, $error_msg);
                $stmt->execute();
                $stmt->close();
            }
            
            // Add delay to avoid rate limiting (0.5 seconds between messages)
            usleep(500000); // 0.5 seconds
        }
        
        // Prepare result message
        $result_msg = "SMS sending completed!\n\n";
        $result_msg .= "✅ Successfully sent: $success\n";
        $result_msg .= "❌ Failed: $failed\n";
        $result_msg .= "📱 Total attempted: $total\n";
        $result_msg .= "📅 SMS sent today: " . ($sms_sent_today + $success) . "/15\n\n";
        
        if ($failed > 0) {
            $result_msg .= "Failed numbers (first 5):\n";
            $count = 0;
            foreach ($failed_numbers as $failed_item) {
                if ($count >= 5) break;
                $result_msg .= "- {$failed_item['name']} ({$failed_item['phone']})\n";
                $count++;
            }
            if ($failed > 5) {
                $result_msg .= "... and " . ($failed - 5) . " more\n";
            }
        }
        
        return $result_msg;
    } else {
        return "No customers found with phone numbers";
    }
}

// Single SMS Function
function sendSingleSMS($phone, $message, $customer_name = '') {
    global $conn;
    
    if (empty($phone) || empty($message)) {
        return array('success' => false, 'error' => 'Phone and message are required');
    }
    
    // Daily limit check
    $sms_sent_today = $conn->query("SELECT COUNT(*) as count FROM sms_logs WHERE DATE(sent_at) = CURDATE() AND status='success'")->fetch_assoc()['count'];
    if ($sms_sent_today >= 15) {
        return array('success' => false, 'error' => "Daily SMS limit reached! You have already sent $sms_sent_today SMS today.");
    }
    
    // Personalize message
    if (!empty($customer_name)) {
        $message = str_replace('[Customer Name]', $customer_name, $message);
    }
    
    $result = sendDialogSMS($phone, $message);
    
    // Log the SMS
    if ($result['success']) {
        $log_sql = "INSERT INTO sms_logs (customer_phone, customer_name, message, status, sent_at) 
                   VALUES (?, ?, ?, 'success', NOW())";
        $stmt = $conn->prepare($log_sql);
        $stmt->bind_param("sss", $phone, $customer_name, $message);
        $stmt->execute();
        $stmt->close();
    } else {
        $log_sql = "INSERT INTO sms_logs (customer_phone, customer_name, message, status, error, sent_at) 
                   VALUES (?, ?, ?, 'failed', ?, NOW())";
        $stmt = $conn->prepare($log_sql);
        $error_msg = substr($result['error'], 0, 255);
        $stmt->bind_param("ssss", $phone, $customer_name, $message, $error_msg);
        $stmt->execute();
        $stmt->close();
    }
    
    return $result;
}
?>
[file content end]