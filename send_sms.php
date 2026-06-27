<?php
function sendBulkSMS($message) {
    $authkey = "479520A1EIvmUCl692525eeP1";
    $sender  = "KidsBerry";

   $servername = "localhost";
$username = "root";
$password = "";
$dbname = "kidsberry";

$conn = new mysqli($servername, $username, $password, $dbname);
    if ($conn->connect_error) return "DB Connection Failed!";

    $sql = "SELECT phone_no FROM customers";
    $result = $conn->query($sql);

    $mobiles = [];
    while ($row = $result->fetch_assoc()) {
        $mobile = preg_replace('/\D/', '', $row['phone_no']);

        if (substr($mobile, 0, 2) == "94") $mobile = substr($mobile, 2);
        if (substr($mobile, 0, 3) == "+94") $mobile = substr($mobile, 3);
        if (substr($mobile, 0, 1) == "0")  $mobile = substr($mobile, 1);

        if (strlen($mobile) == 9) {
            $mobiles[] = "94" . $mobile;
        }
    }
    $conn->close();

    if (empty($mobiles)) {
        return "No valid Sri Lankan numbers found!";
    }

    $postData = [
        'sender'  => $sender,
        'route'   => '4',
        'country' => '94',
        'sms' => [['message' => $message, 'to' => $mobiles]]
    ];

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL            => "https://api.msg91.com/api/v5/sms",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => json_encode($postData),
        CURLOPT_HTTPHEADER     => ["authkey: $authkey", "content-type: application/json"],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => false,   // இதுதான் SSL error fix
        CURLOPT_SSL_VERIFYHOST => 0,       // இதுவும் safe ஆ இருக்கும்
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    curl_close($curl);

    if ($err) {
        return "cURL Error: $err";
    }

    return "SMS sent to " . count($mobiles) . " customers successfully! Sri Lanka";
}
?>