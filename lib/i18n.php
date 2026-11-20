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

/** Inline SVG icon (same set as the dashboard, subset). */
function icon(string $name, string $cls = ''): string
{
    static $p = [
        'bot' => '<rect x="4" y="8" width="16" height="12" rx="3"/><path d="M12 8V4"/><circle cx="12" cy="3.5" r="1"/><path d="M9 13v2M15 13v2M2 13v3M22 13v3"/>',
        'youtube' => '<rect x="2" y="5" width="20" height="14" rx="4"/><path d="m10 9 5 3-5 3z"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'upload' => '<path d="M12 15V3"/><path d="m7 8 5-5 5 5"/><path d="M4 15v4a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-4"/>',
        'alert' => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
        'sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
        'moon' => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        'languages' => '<path d="m5 8 6 6M4 14l6-6 2-3M2 5h12M7 2h1M22 22l-5-10-5 10M14 18h6"/>',
        'hourglass' => '<path d="M5 22h14M5 2h14M17 22v-4.2a2 2 0 0 0-.6-1.4L12 12l-4.4 4.4a2 2 0 0 0-.6 1.4V22M7 2v4.2a2 2 0 0 0 .6 1.4L12 12l4.4-4.4a2 2 0 0 0 .6-1.4V2"/>',
        'lock' => '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
    ];
    return '<svg class="i ' . $cls . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($p[$name] ?? '') . '</svg>';
}

/** Shared <head> + wrapper for the standalone pages. */
function page_head(string $title): void
{
    $l = ui_lang();
    $dir = $l === 'ar' ? 'rtl' : 'ltr';
    echo '<!doctype html><html lang="' . $l . '" dir="' . $dir . '"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">'
        . '<title>' . h($title) . ' · ' . APP_NAME . '</title>'
        . '<link rel="icon" href="assets/img/logo.svg">'
        . '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
        . '<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Space+Grotesk:wght@500;600;700&display=swap" rel="stylesheet">'
        . '<link rel="stylesheet" href="assets/css/app.css?v=' . APP_VERSION . '.' . @filemtime(ROOT_DIR . '/assets/css/app.css') . '">'
        . '<script>(function(){var p="auto";try{p=localStorage.getItem("ytc_theme")||"auto"}catch(e){}'
        . 'var d=p==="dark"||(p==="auto"&&window.matchMedia&&matchMedia("(prefers-color-scheme: dark)").matches);'
        . 'document.documentElement.dataset.theme=d?"dark":"light"})();'
        . 'function ytcTheme(){var d=document.documentElement.dataset.theme!=="dark";document.documentElement.dataset.theme=d?"dark":"light";try{localStorage.setItem("ytc_theme",d?"dark":"light")}catch(e){}}</script>'
        . '</head><body class="auth-body"><div class="auth-bg"></div>';
}

function lang_switch(): string
{
    $other = ui_lang() === 'ar' ? 'en' : 'ar';
    $label = $other === 'ar' ? 'العربية' : 'English';
    $q = $_GET;
    $q['lang'] = $other;
    return '<div class="auth-tools"><button type="button" class="lang-pill theme-pill" onclick="ytcTheme()" title="Theme">' . icon('sun') . '</button>'
        . '<a class="lang-pill" href="?' . h(http_build_query($q)) . '">' . icon('languages', 'sm') . $label . '</a></div>';
}
