<?php
// 強制開啟錯誤回報，方便排查
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'phpmailer/Exception.php';
require 'phpmailer/PHPMailer.php';
require 'phpmailer/SMTP.php';

// ====== 資料庫連線設定 ======
$host = 'localhost';
$db   = 'spam_system';
$user = 'root';
$pass = '12345'; // 若有密碼請填入

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (\PDOException $e) {
    die("資料庫連線失敗，請檢查資料庫名稱或密碼: " . $e->getMessage());
}

// 處理 A. 新增 Email 表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_email') {
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    if ($email) {
        try {
            // 注意：這裡配合你原本的欄位名稱「gmail」
            $stmt = $pdo->prepare("INSERT INTO emails (gmail) VALUES (?)");
            $stmt->execute([$email]);
            echo "<script>alert('Email 已成功加入資料庫！'); window.location.href='system.php';</script>";
        } catch (\Exception $e) {
            echo "<script>alert('加入失敗（可能 Email 已重複）：" . addslashes($e->getMessage()) . "'); window.location.href='system.php';</script>";
        }
    } else {
        echo "<script>alert('Email 格式錯誤！'); window.location.href='system.php';</script>";
    }
    exit;
}

// API 1：獲取發信目標名單（給 JavaScript 呼叫）
if (isset($_GET['action']) && $_GET['action'] === 'get_targets') {
    header('Content-Type: application/json');
    $mode = $_GET['mode'] ?? 'all';
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 5;

    if ($mode === 'random') {
        $stmt = $pdo->prepare("SELECT gmail FROM emails ORDER BY RAND() LIMIT :limit");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
    } else {
        $stmt = $pdo->query("SELECT gmail FROM emails");
    }
    $targets = $stmt->fetchAll(PDO::FETCH_COLUMN, 0); 
    echo json_encode(['targets' => $targets]);
    exit;
}

// API 2：負責單筆發信（給 JavaScript 呼叫）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'send_single') {
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $to = $input['to'] ?? '';
    $custom_subject = $input['subject'] ?? '預設主旨';
    $custom_content = $input['content'] ?? '預設內容';
    $interval = isset($input['interval']) ? (int)$input['interval'] : 0;

    if (empty($to)) {
        echo json_encode(['success' => false, 'msg' => '收件者為空']);
        exit;
    }

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

        $mail->isHTML(true);
        $mail->Subject = $custom_subject; 
        $mail->Body    = nl2br(htmlspecialchars($custom_content)); 

        $mail->send();
        
        if ($interval > 0) {
            sleep($interval);
        }

        echo json_encode(['success' => true, 'msg' => "成功寄出"]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'msg' => "失敗: {$mail->ErrorInfo}"]);
    }
    exit;
}

// 撈取目前資料庫所有名單（用來在網頁上顯示表格）
// 假設你的主鍵欄位叫 no，如果叫 id 請自行修改下方 SQL
try {
    $stmt_list = $pdo->query("SELECT * FROM emails ORDER BY no ASC");
    $all_emails = $stmt_list->fetchAll();
    $total_emails = count($all_emails);
} catch (Exception $e) {
    // 預防萬一：如果你的主鍵叫 id，自動切換成 id 查詢
    $stmt_list = $pdo->query("SELECT id, gmail FROM emails ORDER BY id ASC");
    $all_emails = $stmt_list->fetchAll();
    $total_emails = count($all_emails);
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
        
        /* 進度條樣式 */
        #progress-section { display: none; margin-top: 20px; padding: 15px; background: #eee; border-radius: 5px; }
        .progress-container { background: #ccc; width: 100%; height: 20px; border-radius: 10px; overflow: hidden; margin: 10px 0; }
        .progress-bar { background: #28a745; width: 0%; height: 100%; transition: width 0.2s; }
        #log-box { max-height: 150px; overflow-y: auto; background: #222; color: #fff; padding: 10px; font-family: monospace; border-radius: 4px; margin-top: 10px; font-size: 13px; }
        
        /* 資料庫表格樣式 */
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table, th, td { border: 1px solid #ddd; }
        th, td { padding: 10px; text-align: left; }
        th { background-color: #f2f2f2; }
        tr:hover { background-color: #f5f5f5; }
    </style>
</head>
<body>
    <h1>垃圾郵件寄送系統</h1>
    
    <div class="section">
        <h2>A. 建構資料庫 (目前名單總數: <?php echo $total_emails; ?> 筆)</h2>
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
        <h2>B. 基本郵件介面 & 寄發設定</h2>
        <form id="mailForm" onsubmit="startSending(event)">
            <fieldset style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 4px;">
                <legend style="padding: 0 5px; font-weight: bold; color: #555;">③ 郵件內容介面</legend>
                <div class="form-group">
                    <label for="mail_subject">郵件主旨:</label>
                    <input type="text" id="mail_subject" required placeholder="請輸入電子郵件標題" value="測試信件">
                </div>
                <div class="form-group" style="display: flex; align-items: flex-start;">
                    <label for="mail_content">郵件內容:</label>
                    <textarea id="mail_content" required placeholder="請輸入電子郵件內文...">當你看到這封信，代表系統運作正常！</textarea>
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
                <input type="number" id="limit" value="5" min="1" max="<?php echo $total_emails; ?>">
            </div>
            
            <div class="form-group">
                <label>② 時間間隔(秒):</label>
                <input type="number" id="interval" value="2" min="0">
            </div>
            
            <button type="submit" id="submitBtn">開始異步群發郵件</button>
        </form>

        <div id="progress-section">
            <h3>3. 郵件寄送進度</h3>
            <div id="progress-text">準備發送... (0/0)</div>
            <div class="progress-container">
                <div class="progress-bar" id="p-bar"></div>
            </div>
            <div id="log-box"></div>
        </div>
    </div>

    <div class="section">
        <h2>C. 資料庫名單列表 (即時查看)</h2>
        <?php if ($total_emails > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>No (流水號)</th>
                        <th>Email (電子郵件)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_emails as $row): ?>
                        <tr>
                            <td><?php echo isset($row['no']) ? $row['no'] : $row['id']; ?></td>
                            <td><?php echo htmlspecialchars($row['gmail']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color: #999;">目前資料庫中沒有任何名單，請使用上方表單新增。</p>
        <?php endif; ?>
    </div>

<script>
let sendQueue = [];
let currentIndex = 0;
let totalTasks = 0;

function startSending(e) {
    e.preventDefault(); 

    const mode = document.querySelector('input[name="mode"]:checked').value;
    const limit = document.getElementById('limit').value;
    const subject = document.getElementById('mail_subject').value;
    const content = document.getElementById('mail_content').value;
    const interval = document.getElementById('interval').value;

    document.getElementById('submitBtn').disabled = true;
    document.getElementById('progress-section').style.display = 'block';
    document.getElementById('log-box').innerHTML = '正在向資料庫撈取發信清單...<br>';

    fetch(`system.php?action=get_targets&mode=${mode}&limit=${limit}`)
        .then(res => res.json())
        .then(data => {
            sendQueue = data.targets;
            totalTasks = sendQueue.length;
            currentIndex = 0;

            if (totalTasks === 0) {
                document.getElementById('log-box').innerHTML += '<span style="color:red;">錯誤: 資料庫內沒有可發送的 Email 名單！</span><br>';
                document.getElementById('submitBtn').disabled = false;
                return;
            }

            document.getElementById('log-box').innerHTML += `成功撈取名單，共 ${totalTasks} 筆。開始非同步發送...<br><hr>`;
            sendNext(subject, content, interval);
        })
        .catch(err => {
            alert('撈取名單失敗！請確認 MySQL 已啟動、且資料庫與資料表皆建立正確。');
            document.getElementById('submitBtn').disabled = false;
        });
}

function sendNext(subject, content, interval) {
    if (currentIndex >= totalTasks) {
        document.getElementById('progress-text').innerText = `發送完成! 共成功處理 ${totalTasks} / ${totalTasks}`;
        document.getElementById('log-box').innerHTML += `<br><span style="color:#00ff00;">★ 任務結束！</span>`;
        document.getElementById('submitBtn').disabled = false;
        return;
    }

    const currentEmail = sendQueue[currentIndex];
    const displayCount = currentIndex + 1;

    document.getElementById('progress-text').innerText = `正在發送: ${displayCount} / ${totalTasks} (目標: ${currentEmail})`;
    const percent = (displayCount / totalTasks) * 100;
    document.getElementById('p-bar').style.width = percent + '%';

    fetch('system.php?action=send_single', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }, 
        body: JSON.stringify({
            to: currentEmail,
            subject: subject,
            content: content,
            interval: interval
        })
    })
    .then(res => res.json())
    .then(result => {
        const logBox = document.getElementById('log-box');
        if (result.success) {
            logBox.innerHTML += `[${displayCount}/${totalTasks}] <span style="color:#00ff00;">成功</span> - ${currentEmail}<br>`;
        } else {
            logBox.innerHTML += `[${displayCount}/${totalTasks}] <span style="color:#ff0000;">失敗</span> - ${currentEmail} (${result.msg})<br>`;
        }
        logBox.scrollTop = logBox.scrollHeight; 

        currentIndex++;
        sendNext(subject, content, interval);
    })
    .catch(err => {
        const logBox = document.getElementById('log-box');
        logBox.innerHTML += `[${displayCount}/${totalTasks}] <span style="color:#ff0000;">伺服器回應異常</span> - ${currentEmail}<br>`;
        currentIndex++;
        sendNext(subject, content, interval);
    });
}
</script>
</body>
</html>