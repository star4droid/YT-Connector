<?php
/** Minimal translations for the server-rendered pages (install, login, consent, drop). */
declare(strict_types=1);

function ui_lang(): string
{
    if (isset($_GET['lang']) && in_array($_GET['lang'], ['ar', 'en'], true)) {
        setcookie('ytc_lang', $_GET['lang'], ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
        return $_GET['lang'];
    }
    $c = $_COOKIE['ytc_lang'] ?? '';
    if (in_array($c, ['ar', 'en'], true)) return $c;
    return stripos($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '', 'ar') === 0 ? 'ar' : 'en';
}

function t(string $key): string
{
    static $d = null;
    if ($d === null) {
        $d = [
            'en' => [
                'login_title' => 'Welcome back',
                'login_sub' => 'Sign in to manage your YouTube channels',
                'password' => 'Password',
                'sign_in' => 'Sign in',
                'wrong_password' => 'Wrong password.',
                'too_many' => 'Too many attempts. Try again in 15 minutes.',
                'install_title' => 'Set up YT Connector',
                'install_sub' => 'A few seconds and your YouTube control center is ready.',
                'requirements' => 'Server requirements',
                'admin_password' => 'Admin password',
                'confirm_password' => 'Confirm password',
                'site_url' => 'Site URL',
                'site_url_hint' => 'Detected automatically. Must be HTTPS for Claude connectors.',
                'database' => 'Database',
                'db_sqlite' => 'SQLite (recommended, zero setup)',
                'db_mysql' => 'MySQL / MariaDB',
                'db_host' => 'Host', 'db_name' => 'Database name', 'db_user' => 'User', 'db_pass' => 'Password',
                'install_btn' => 'Install now',
                'pw_short' => 'Password must be at least 8 characters.',
                'pw_mismatch' => 'Passwords do not match.',
                'not_writable' => 'The data/ folder is not writable. Set its permission to 755 or 775.',
                'consent_title' => 'Authorize access',
                'consent_sub' => 'wants to control your YouTube channels through this site.',
                'consent_list' => 'It will be able to:',
                'consent_1' => 'List channels and read videos, comments and analytics',
                'consent_2' => 'Upload, edit, delete videos and change thumbnails (on channels with full access)',
                'consent_3' => 'Write to the AI Notebook',
                'redirect_to' => 'You will be redirected to',
                'allow' => 'Allow access',
                'deny' => 'Deny',
                'drop_title' => 'Upload a video',
                'drop_sub' => 'Drop your video file here. It will be published to the channel once the AI (or you) adds the details.',
                'drop_choose' => 'Choose a file or drag it here',
                'drop_expired' => 'This upload link is invalid or has expired.',
                'drop_done' => 'File received! You can return to the chat.',
                'channel' => 'Channel',
            ],
            'ar' => [
                'login_title' => 'أهلاً بعودتك',
                'login_sub' => 'سجّل الدخول لإدارة قنوات يوتيوب الخاصة بك',
                'password' => 'كلمة المرور',
                'sign_in' => 'تسجيل الدخول',
                'wrong_password' => 'كلمة المرور غير صحيحة.',
                'too_many' => 'محاولات كثيرة. حاول بعد 15 دقيقة.',
                'install_title' => 'تثبيت YT Connector',
                'install_sub' => 'ثوانٍ قليلة ويصبح مركز التحكم بيوتيوب جاهزاً.',
                'requirements' => 'متطلبات الخادم',
                'admin_password' => 'كلمة مرور المدير',
                'confirm_password' => 'تأكيد كلمة المرور',
                'site_url' => 'رابط الموقع',
                'site_url_hint' => 'يُكتشف تلقائياً. يجب أن يكون HTTPS ليعمل كـ connector في Claude.',
                'database' => 'قاعدة البيانات',
                'db_sqlite' => 'SQLite (موصى به، بدون إعداد)',
                'db_mysql' => 'MySQL / MariaDB',
                'db_host' => 'الخادم', 'db_name' => 'اسم القاعدة', 'db_user' => 'المستخدم', 'db_pass' => 'كلمة المرور',
                'install_btn' => 'ثبّت الآن',
                'pw_short' => 'كلمة المرور يجب أن تكون 8 أحرف على الأقل.',
                'pw_mismatch' => 'كلمتا المرور غير متطابقتين.',
                'not_writable' => 'مجلد data/ غير قابل للكتابة. اجعل صلاحياته 755 أو 775.',
                'consent_title' => 'منح صلاحية الوصول',
                'consent_sub' => 'يريد التحكم بقنوات يوتيوب الخاصة بك عبر هذا الموقع.',
                'consent_list' => 'سيتمكن من:',
                'consent_1' => 'عرض القنوات وقراءة الفيديوهات والتعليقات والإحصائيات',
                'consent_2' => 'رفع وتعديل وحذف الفيديوهات وتغيير الصور المصغّرة (للقنوات ذات التحكم الكامل)',
                'consent_3' => 'الكتابة في دفتر الذكاء الاصطناعي',
                'redirect_to' => 'سيتم تحويلك إلى',
                'allow' => 'السماح بالوصول',
                'deny' => 'رفض',
                'drop_title' => 'رفع فيديو',
                'drop_sub' => 'أسقط ملف الفيديو هنا. سيُنشر على القناة بعد أن يضيف الذكاء الاصطناعي (أو أنت) التفاصيل.',
                'drop_choose' => 'اختر ملفاً أو اسحبه هنا',
                'drop_expired' => 'رابط الرفع غير صالح أو منتهي الصلاحية.',
                'drop_done' => 'تم استلام الملف! يمكنك العودة إلى المحادثة.',
                'channel' => 'القناة',
            ],
        ];
    }
    $l = ui_lang();
    return $d[$l][$key] ?? $d['en'][$key] ?? $key;
}

/** Shared <head> + wrapper for the standalone pages. */
function page_head(string $title): void
{
    $l = ui_lang();
    $dir = $l === 'ar' ? 'rtl' : 'ltr';
    echo '<!doctype html><html lang="' . $l . '" dir="' . $dir . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>' . h($title) . ' · ' . APP_NAME . '</title>'
        . '<link rel="icon" href="assets/img/logo.svg">'
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">'
        . '<link rel="stylesheet" href="assets/css/app.css?v=' . APP_VERSION . '"></head><body class="auth-body">'
        . '<div class="auth-bg"><span></span><span></span><span></span></div>';
}

function lang_switch(): string
{
    $other = ui_lang() === 'ar' ? 'en' : 'ar';
    $label = $other === 'ar' ? 'العربية' : 'English';
    $q = $_GET;
    $q['lang'] = $other;
    return '<a class="lang-pill" href="?' . h(http_build_query($q)) . '">' . $label . '</a>';
}
