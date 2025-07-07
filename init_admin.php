<?php
require_once 'db.php';
require_once 'auth.php';

// إنشاء مدير افتراضي إذا لم يكن موجوداً
try {
    $stmt = $db->query("SELECT COUNT(*) as count FROM admins");
    $result = $stmt->fetchArray(SQLITE3_ASSOC);
    
    if ($result['count'] == 0) {
        $username = 'admin';
        $password = 'admin123';
        $password_hash = password_hash($password, PASSWORD_DEFAULT);
        
        $stmt = $db->prepare("INSERT INTO admins (username, password_hash) VALUES (:username, :password_hash)");
        $stmt->bindValue(':username', $username, SQLITE3_TEXT);
        $stmt->bindValue(':password_hash', $password_hash, SQLITE3_TEXT);
        $stmt->execute();
        
        echo "تم إنشاء مدير افتراضي:\n";
        echo "اسم المستخدم: admin\n";
        echo "كلمة المرور: admin123\n";
        echo "يرجى تغيير كلمة المرور بعد تسجيل الدخول.\n";
    } else {
        echo "يوجد مديرين في النظام بالفعل.\n";
    }
} catch (Exception $e) {
    echo "خطأ في إنشاء المدير: " . $e->getMessage() . "\n";
}
?>