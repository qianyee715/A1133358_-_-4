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

// 處理 C. 刪除名單資料
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && trim($_POST['action']) === 'delete_email') {
    $delete_id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if ($delete_id !== false && $delete_id !== null) {
        try {
            try {
                $stmt = $pdo->prepare("DELETE FROM emails WHERE no = ?");
                $stmt->execute([$delete_id]);
            } catch (\Exception $ex) {
                $stmt = $pdo->prepare("DELETE FROM emails WHERE id = ?");
                $stmt->execute([$delete_id]);
            }
            echo "<script>alert('該名單已成功從資料庫刪除！'); window.location.href='system.php';</script>";
        } catch (\Exception $e) {
            echo "<script>alert('刪除失敗：" . addslashes($e->getMessage()) . "'); window.location.href='system.php';</script>";
        }
    } else {
        echo "<script>alert('無效的識別碼！'); window.location.href='system.php';</script>";
    }
    exit;
}

// 處理 A. 新增 Email 表單提交
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && trim($_POST['action']) === 'add_email') {
    $email = filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL);
    if ($email) {
        try {
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

// API 1：獲取發信目標名單 (GET 方式)
if (isset($_GET['action']) && trim($_GET['action']) === 'get_targets') {
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

// API 2：負責單筆發信 (改為完全從 POST 讀取 JSON)
$raw_input = file_get_contents('php://input');
if (!empty($raw_input)) {
    $clean_input = str_replace("\xc2\xa0", " ", $raw_input); 
    $input = json_decode($clean_input, true);
    
    if (isset($input['action']) && $input['action'] === 'send_single') {
        header('Content-Type: application/json');
        
        $to = isset($input['to']) ? trim($input['to']) : '';
        $custom_subject = $input['subject'] ?? '預設主旨';
        $custom_content = $input['content'] ?? '預設內容';
        $interval = isset($input['interval']) ? (int)$input['interval'] : 0;

        if (empty($to)) {
            echo json_encode(['success' => false, 'msg' => '電子郵件為空值']);
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

            $mail->setFrom('a0989354196@gmail.com', '垃圾郵件群發系統');
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
}

// 撈取目前資料庫所有名單
try {
    $stmt_list = $pdo->query("SELECT * FROM emails ORDER BY no ASC");
    $all_emails = $stmt_list->fetchAll();
    $total_emails = count($all_emails);
} catch (Exception $e) {
    try {
        $stmt_list = $pdo->query("SELECT id, gmail FROM emails ORDER BY id ASC");
        $all_emails = $stmt_list->fetchAll();
        $total_emails = count($all_emails);
    } catch (Exception $ex) {
        $all_emails = [];
        $total_emails = 0;
    }
}
?>

<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <title>垃圾郵件無限循環群發系統</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 30px; line-height: 1.6; background-color: #f9f9f9; }
        h1 { color: #333; }
        .section { margin-bottom: 30px; padding: 20px; border: 1px solid #ccc; border-radius: 5px; background: #fff; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        label { display: inline-block; width: 120px; font-weight: bold; margin-bottom: 8px; }
        input[type="text"], input[type="email"], input[type="number"], textarea { padding: 6px; width: 300px; border: 1px solid #ccc; border-radius: 4px; }
        textarea { width: 450px; height: 100px; resize: vertical; }
        .form-group { margin-bottom: 12px; }
        button { padding: 8px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; margin-right: 10px; }
        .btn-start { background: #28a745; color: white; }
        .btn-start:hover { background: #218838; }
        .btn-stop { background: #dc3545; color: white; display: none; }
        .btn-stop:hover { background: #c82333; }
        .btn-add { background: #007BFF; color: white; padding: 6px 15px; }
        .btn-delete { background: #dc3545; color: white; padding: 4px 10px; font-size: 12px; border-radius: 3px; }
        .btn-delete:hover { background: #bd2130; }
        
        #progress-section { display: none; margin-top: 20px; padding: 15px; background: #eee; border-radius: 5px; }
        .progress-container { background: #ccc; width: 100%; height: 20px; border-radius: 10px; overflow: hidden; margin: 10px 0; }
        .progress-bar { background: #ffc107; width: 100%; height: 100%; animation: progress-blink 1s infinite; }
        @keyframes progress-blink { 0% { opacity: 0.6; } 50% { opacity: 1; } 100% { opacity: 0.6; } }
        #log-box { max-height: 250px; overflow-y: auto; background: #222; color: #fff; padding: 10px; font-family: monospace; border-radius: 4px; margin-top: 10px; font-size: 13px; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        table, th, td { border: 1px solid #ddd; }
        th, td { padding: 10px; text-align: left; }
        th { background-color: #f2f2f2; }
    </style>
</head>
<body>
    <h1>垃圾郵件無限循環系統 (完整管理版)</h1>
    
    <div class="section">
        <h2>A. 建構資料庫 (目前名單總數: <?php echo $total_emails; ?> 筆)</h2>
        <form action="system.php" method="POST">
            <input type="hidden" name="action" value="add_email">
            <div class="form-group">
                <label>Gmail 位址:</label>
                <input type="email" name="email" required placeholder="example@gmail.com">
                <button type="submit" class="btn-add">加入資料庫</button>
            </div>
        </form>
    </div>

    <div class="section">
        <h2>B. 郵件內容與無限循環設定</h2>
        <form id="mailForm" onsubmit="startSending(event)" novalidate>
            <fieldset style="border: 1px solid #ddd; padding: 15px; margin-bottom: 15px; border-radius: 4px;">
                <legend style="padding: 0 5px; font-weight: bold; color: #555;">郵件內容介面</legend>
                <div class="form-group">
                    <label for="mail_subject">郵件主旨:</label>
                    <input type="text" id="mail_subject" required value="無限轟炸測試信">
                </div>
                <div class="form-group" style="display: flex; align-items: flex-start;">
                    <label for="mail_content">郵件內容:</label>
                    <textarea id="mail_content" required>此系統正在執行無限重複發送測試...</textarea>
                </div>
            </fieldset>

            <div class="form-group">
                <label>① 篩選模式:</label>
                <input type="radio" id="mode_all" name="mode" value="all" checked onclick="document.getElementById('limit_field').style.display='none'">
                <label for="mode_all" style="width:auto; font-weight: normal; margin-right: 15px;">跑完一輪全部，再重複下一輪</label>
                
                <input type="radio" id="mode_rand" name="mode" value="random" onclick="document.getElementById('limit_field').style.display='block'">
                <label for="mode_rand" style="width:auto; font-weight: normal;">每輪隨機抽幾筆，無限抽發</label>
            </div>
            
            <div class="form-group" id="limit_field" style="display:none;">
                <label>隨機抽取筆數:</label>
                <input type="number" id="limit" value="3" min="1" max="<?php echo $total_emails; ?>">
            </div>
            
            <div class="form-group">
                <label>② 發信間隔(秒):</label>
                <input type="number" id="interval" value="1" min="0">
            </div>
            
            <button type="submit" id="submitBtn" class="btn-start">開始無限循環發送</button>
            <button type="button" id="stopBtn" class="btn-stop" onclick="stopSending()">停止發送</button>
        </form>

        <div id="progress-section">
            <h3 style="color: #ffc107;">● 無限轟炸模式運行中...</h3>
            <div id="progress-text">初始化中...</div>
            <div class="progress-container">
                <div class="progress-bar" id="p-bar"></div>
            </div>
            <div id="log-box"></div>
        </div>
    </div>

    <div class="section">
        <h2>C. 資料庫名單列表</h2>
        <?php if ($total_emails > 0): ?>
            <table>
                <thead>
                    <tr>
                        <th>No (流水號)</th>
                        <th>Email (電子郵件)</th>
                        <th>操作</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($all_emails as $row): ?>
                        <?php $row_id = isset($row['no']) ? $row['no'] : (isset($row['id']) ? $row['id'] : 0); ?>
                        <tr>
                            <td><?php echo $row_id; ?></td>
                            <td><?php echo htmlspecialchars($row['gmail']); ?></td>
                            <td>
                                <form action="system.php" method="POST" style="margin:0; padding:0;" onsubmit="return confirm('確定要刪除此筆 Email 名單嗎？');">
                                    <input type="hidden" name="action" value="delete_email">
                                    <input type="hidden" name="id" value="<?php echo $row_id; ?>">
                                    <button type="submit" class="btn-delete">刪除</button>
                                </form>
                            </td>
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
let roundCount = 1; 
let isRunning = false; 

function startSending(e) {
    e.preventDefault(); 
    if (isRunning) return;

    isRunning = true;
    roundCount = 1;
    currentIndex = 0;

    document.getElementById('submitBtn').style.display = 'none';
    document.getElementById('stopBtn').style.display = 'inline-block';
    document.getElementById('progress-section').style.display = 'block';
    document.getElementById('log-box').innerHTML = '<span style="color:#ffc107;">[系統預熱] 正在啟動無限發送引擎...</span><br>';

    fetchListAndSend();
}

function fetchListAndSend() {
    if (!isRunning) return;

    const mode = document.querySelector('input[name="mode"]:checked').value;
    const limit = document.getElementById('limit').value;

    document.getElementById('log-box').innerHTML += `<br><span style="color:#00ffff;">【第 ${roundCount} 輪開始】正在從資料庫同步最新名單...</span><br>`;
    
    fetch(`system.php?action=get_targets&mode=${mode}&limit=${limit}`)
        .then(res => {
            if (!res.ok) throw new Error('網路回應不成功');
            return res.json();
        })
        .then(data => {
            sendQueue = data.targets;
            currentIndex = 0;

            if (!sendQueue || sendQueue.length === 0) {
                document.getElementById('log-box').innerHTML += '<span style="color:red;">錯誤: 資料庫是空的，請先加入 Email！</span><br>';
                stopSending();
                return;
            }

            sendNextSingle();
        })
        .catch(err => {
            document.getElementById('log-box').innerHTML += '<span style="color:red;">撈取資料庫名單失敗，3秒後嘗試重新撈取...</span><br>';
            setTimeout(fetchListAndSend, 3000);
        });
}

function sendNextSingle() {
    if (!isRunning) return;

    if (currentIndex >= sendQueue.length) {
        roundCount++;
        fetchListAndSend(); 
        return;
    }

    const currentEmail = sendQueue[currentIndex].trim();
    const subject = document.getElementById('mail_subject').value;
    const content = document.getElementById('mail_content').value;
    const interval = document.getElementById('interval').value;

    document.getElementById('progress-text').innerText = `第 ${roundCount} 輪 - 正在發送第 ${currentIndex + 1}/${sendQueue.length} 筆：${currentEmail}`;

    fetch('system.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' }, 
        body: JSON.stringify({
            action: 'send_single',
            to: currentEmail,
            subject: subject,
            content: content,
            interval: interval
        })
    })
    .then(res => {
        if (!res.ok) throw new Error('網路回應不成功');
        return res.json();
    })
    .then(result => {
        const logBox = document.getElementById('log-box');
        if (result && result.success) {
            logBox.innerHTML += `[輪次:${roundCount}] 成功寄給 -> <span style="color:#00ff00;">${currentEmail}</span><br>`;
        } else {
            const errorMsg = result ? result.msg : '不明錯誤';
            logBox.innerHTML += `[輪次:${roundCount}] <span style="color:#ff0000;">${errorMsg}</span> -> ${currentEmail}<br>`;
        }
        logBox.scrollTop = logBox.scrollHeight; 

        if (isRunning) {
            currentIndex++;
            sendNextSingle(); 
        }
    })
    .catch(err => {
        const logBox = document.getElementById('log-box');
        logBox.innerHTML += `[輪次:${roundCount}] <span style="color:#ff0000;">網路連線異常或後端報錯，跳過該筆</span> -> ${currentEmail}<br>`;
        
        if (isRunning) {
            currentIndex++;
            sendNextSingle();
        }
    });
}

function stopSending() {
    isRunning = false;
    document.getElementById('submitBtn').style.display = 'inline-block';
    document.getElementById('stopBtn').style.display = 'none';
    document.getElementById('log-box').innerHTML += `<br><span style="color:#ff0000; font-weight:bold;">★ 系統已手動中斷發送。共執行了 ${roundCount} 輪。</span><br>`;
}
</script>
</body>
</html>