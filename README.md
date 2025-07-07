# نظام إدارة التراخيص PHP

نظام شامل لإدارة تراخيص البرمجيات مع واجهة ويب سهلة الاستخدام.

## المتطلبات

- PHP 7.4 أو أحدث
- SQLite3
- Node.js (للتهيئة الأولية فقط)

## التثبيت والتشغيل

### 1. تهيئة قاعدة البيانات
```bash
npm run initdb
```

### 2. إنشاء مدير افتراضي
```bash
php init_admin.php
```

### 3. تشغيل الخادم
```bash
npm start
```

أو مباشرة:
```bash
php -S localhost:3000
```

## بيانات الدخول الافتراضية

- **اسم المستخدم:** admin
- **كلمة المرور:** admin123

⚠️ **مهم:** يرجى تغيير كلمة المرور بعد أول تسجيل دخول.

## الميزات الرئيسية

### إدارة التراخيص
- إضافة وتعديل وحذف التراخيص
- دعم النطاقات الأساسية والثانوية
- أنواع التراخيص: تجريبي، شهري، سنوي، مدى الحياة
- حدود الموقع: موقع واحد أو مواقع غير محدودة
- إدارة تواريخ انتهاء الصلاحية

### API للتحقق من التراخيص
- **نقطة النهاية:** `POST /license-check.php`
- **المعاملات المطلوبة:**
  - `domain`: النطاق المراد التحقق منه
  - `key`: مفتاح الترخيص
  - `token`: رمز التحقق

#### مثال على الاستخدام:
```bash
curl -X POST http://localhost:3000/license-check.php \
  -d "domain=example.com" \
  -d "key=YOUR_LICENSE_KEY" \
  -d "token=YOUR_TOKEN"
```

#### الاستجابات المحتملة:
```json
{
  "status": "valid",
  "message": "License is valid and active",
  "license_type": "Yearly",
  "site_limit": "Single",
  "expires_at": "2025-12-31"
}
```

### API للتحديثات
- **نقطة النهاية:** `POST /update-check.php`
- دعم فحص التحديثات للإضافات
- تحميل ملفات التحديث

### لوحة التحكم
- إحصائيات شاملة للتراخيص
- بحث وتصفية متقدم
- سجل الأنشطة
- إدارة المديرين
- إعدادات النظام

### الأمان
- حماية CSRF
- تشفير كلمات المرور
- تحديد معدل الطلبات
- سجل الأنشطة

## هيكل الملفات

```
├── index.php              # صفحة تسجيل الدخول
├── dashboard.php          # لوحة التحكم الرئيسية
├── view-licenses.php      # عرض التراخيص
├── add-license.php        # إضافة/تعديل التراخيص
├── license-check.php      # API التحقق من التراخيص
├── update-check.php       # API التحديثات
├── upload-updates.php     # رفع ملفات التحديث
├── settings.php           # إعدادات النظام
├── auth.php              # نظام المصادقة
├── db.php                # اتصال قاعدة البيانات
├── utils.php             # وظائف مساعدة
├── style.css             # التصميم
├── script.js             # JavaScript
└── downloads/            # مجلد ملفات التحديث
```

## الاستخدام مع WordPress

يمكن استخدام هذا النظام مع إضافات WordPress للتحقق من التراخيص:

```php
// في إضافة WordPress
$response = wp_remote_post('http://your-license-server.com/license-check.php', [
    'body' => [
        'domain' => get_site_url(),
        'key' => get_option('your_license_key'),
        'token' => get_option('your_license_token')
    ]
]);

$license_data = json_decode(wp_remote_retrieve_body($response), true);

if ($license_data['status'] === 'valid') {
    // الترخيص صالح
} else {
    // الترخيص غير صالح
}
```

## الدعم والمساعدة

للحصول على المساعدة أو الإبلاغ عن مشاكل، يرجى مراجعة الوثائق أو الاتصال بفريق الدعم.