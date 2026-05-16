<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'phpmailer/Exception.php';
require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';

$host = 'localhost';
$db   = 'spam_system';
$user = 'root';
$pass = '12345'; 

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (\PDOException $e) {
    die("資料庫連線失敗: " . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_email') {
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    if ($email) {
        $stmt = $pdo->prepare("INSERT INTO emails (gmail) VALUES (?)");
        $stmt->execute([$email]);
        echo "<script>alert('Email 已成功加入！'); window.location.href='system.php';</script>";
    } else {
        echo "<script>alert('格式錯誤！'); window.location.href='system.php';</script>";
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_mail') {
    $mode = $_POST['mode'] ?? 'all';
    $random_limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 5;
    $interval = isset($_POST['interval']) ? (int)$_POST['interval'] : 1;
    
    $custom_subject = $_POST['mail_subject'] ?? '預設主旨';
    $custom_content = $_POST['mail_content'] ?? '預設內容';

    if ($mode === 'random') {
        $stmt = $pdo->prepare("SELECT gmail FROM emails ORDER BY RAND() LIMIT :limit");
        $stmt->bindValue(':limit', $random_limit, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $stmt = $pdo->query("SELECT gmail FROM emails");
    }
    
    $targets = $stmt->fetchAll();
    $total_targets = count($targets);

    if ($total_targets > 0) {
        if (ob_get_level() == 0) ob_start();
        echo "<h3>開始透過 Gmail SMTP 寄送郵件...</h3>";
        
        foreach ($targets as $index => $target) {
            $current_count = $index + 1;
            $to = $target['gmail'];
            
            $mail = new PHPMailer(true);
            try {
                $mail->isSMTP();
                $mail->Host       = 'smtp.gmail.com';
                $mail->SMTPAuth   = true;
                $mail->Username   = 'a0989354196@gmail.com'; 
                $mail->Password   = 'ctlb htxh ivae vfmo';    
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                $mail->Port       = 587;
                $mail->CharSet    = 'UTF-8';

                $mail->setFrom('a0989354196@gmail.com', '垃圾郵件寄送系統');
                $mail->addAddress($to);

                // ③ 套用基本郵件介面自訂的內容
                $mail->isHTML(true);
                $mail->Subject = $custom_subject; 
                $mail->Body    = nl2br(htmlspecialchars($custom_content));

                $mail->send();
                $status = "<span style='color:green;'>成功寄出！</span>";
            } catch (Exception $e) {
                $status = "<span style='color:red;'>失敗: {$mail->ErrorInfo}</span>";
            }
            
            echo "<div>進度: <strong>{$current_count} / {$total_targets}</strong> - 正在寄送到: {$to} ... {$status}</div>";
            
            ob_flush();
            flush();

            if ($current_count < $total_targets) {
                sleep($interval); 
            }
        }
        echo "<h3>運作結束！</h3><hr><a href='system.php'>返回主介面</a>";
        exit;
    } else {
        echo "<script>alert('資料庫沒資料！'); window.location.href='system.php';</script>";
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>垃圾郵件寄送系統</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 30px; line-height: 1.6; background-color: #f9f9f9; }
        h1 { color: #333; }
        .section { margin-bottom: 30px; padding: 20px; border: 1px solid #ccc; border-radius: 5px; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        label { display: inline-block; width: 120px; font-weight: bold; margin-bottom: 8px; }
        input[type="text"], input[type="email"], input[type="number"], textarea { padding: 6px; width: 300px; border: 1px solid #ccc; border-radius: 4px; }
        textarea { width: 450px; height: 100px; resize: vertical; }
        .form-group { margin-bottom: 12px; }
        button { padding: 8px 20px; background: #007BFF; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; }
        button:hover { background: #0056b3; }
    </style>
</head>
<body>
    <h1>垃圾郵件寄送系統</h1>
    
    <div class="section">
        <h2>A. 建構資料庫 (新增 Email)</h2>
        <form action="system.php" method="POST">
            <input type="hidden" name="action" value="add_email">
            <div class="form-group">
                <label>Gmail 位址:</label>
                <input type="email" name="email" required placeholder="example@gmail.com">
                <button type="submit">加入資料庫</button>
            </div>
        </form>
    </div>

    <div class="section">
        <h2>B. ③ 基本郵件介面 & 寄發設定</h2>
        <form action="system.php" method="POST">
            <input type="hidden" name="action" value="send_mail">
            
            <fieldset style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 4px;">
                <legend style="padding: 0 5px; font-weight: bold; color: #555;">③ 郵件內容介面</legend>
                <div class="form-group">
                    <label for="mail_subject">郵件主旨:</label>
                    <input type="text" id="mail_subject" name="mail_subject" required placeholder="請輸入電子郵件標題" value="測試信件">
                </div>
                <div class="form-group" style="display: flex; align-items: flex-start;">
                    <label for="mail_content">郵件內容:</label>
                    <textarea id="mail_content" name="mail_content" required placeholder="請輸入電子郵件內文...">當你看到這封信，代表系統運作正常！</textarea>
                </div>
            </fieldset>

            <div class="form-group">
                <label>① 寄送模式:</label>
                <input type="radio" id="mode_all" name="mode" value="all" checked onclick="document.getElementById('limit_field').style.display='none'">
                <label for="mode_all" style="width:auto; font-weight: normal; margin-right: 15px;">全部寄送</label>
                
                <input type="radio" id="mode_rand" name="mode" value="random" onclick="document.getElementById('limit_field').style.display='block'">
                <label for="mode_rand" style="width:auto; font-weight: normal;">隨機隨選幾筆</label>
            </div>
            
            <div class="form-group" id="limit_field" style="display:none;">
                <label>隨機寄送筆數:</label>
                <input type="number" name="limit" value="5" min="1">
            </div>
            
            <div class="form-group">
                <label>② 時間間隔(秒):</label>
                <input type="number" name="interval" value="2" min="0">
            </div>
            
            <button type="submit">開始發送（顯示進度）</button>
        </form>
    </div>
</body>
</html>
