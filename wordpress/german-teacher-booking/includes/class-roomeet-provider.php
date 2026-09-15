<?php
/**
 * Roomeet Provider - افزونه رزرو کلاس
 *
 * ادغام با API روومیت (https://node.roomeet.ir/api/v2) برای:
 *   - ساخت/به‌روزرسانی/حذف اتاق کلاس
 *   - لینک ورود مدرس (moderator) و شاگرد (guest) بدون نیاز به وارد کردن نام
 *   - گرفتن ضبط کلاس + لینک دانلود مستقیم
 *
 * کاملاً افزودنی؛ داده‌های موجود دست‌نخورده باقی می‌مانند.
 * ستون‌های جدید روی جدول رزروها (اگر نبودند):
 *   - class_provider          varchar(20)  - 'bbb' | 'meet' | 'roomeet'
 *   - rmt_meeting_id          varchar(191) - theIdOfMeeting در روومیت
 *   - rmt_recording_link      text         - لینک پخش ضبط روومیت
 *   - rmt_recording_download  text         - لینک دانلود مستقیم ضبط
 *   - rmt_recording_checked   datetime
 * ستون‌های جدید روی جدول جلسات آموزشی:
 *   - rmt_video_url           text
 *   - rmt_video_url_source    varchar(20)
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('GTBP_Roomeet_Provider')):

class GTBP_Roomeet_Provider {

    const OPT_ENABLED     = 'gtbp_roomeet_enabled';       // نگه‌داشته برای سازگاری؛ منبع اصلی «سرویس فعال» است
    const OPT_API_KEY     = 'gtbp_roomeet_api_key';
    const OPT_PHONE       = 'gtbp_roomeet_phone';
    const OPT_PASSWORD    = 'gtbp_roomeet_password';
    const OPT_SALE_ID     = 'gtbp_roomeet_sale_id';
    const OPT_TOKEN       = 'gtbp_roomeet_token';
    const OPT_TOKEN_TIME  = 'gtbp_roomeet_token_time';
    const OPT_TEACHER     = 'gtbp_roomeet_teacher_name';
    const OPT_LAST_ERROR  = 'gtbp_roomeet_last_error';
    const OPT_DB_VERSION  = 'gtbp_roomeet_db_version';
    // نسخه ۱۴.۵: حساب مهمانِ مشترک (برای ورود شاگرد با نام خودش به‌جای نام مدیر)
    const OPT_GUEST_MEMBER_ID = 'gtbp_roomeet_guest_member_id';
    const OPT_GUEST_PHONE     = 'gtbp_roomeet_guest_phone';
    const OPT_GUEST_PASSWORD  = 'gtbp_roomeet_guest_password';

    const API_BASE  = 'https://node.roomeet.ir/api/v2';
    const DB_VERSION = '2';
    const TOKEN_TTL  = 18000; // ۵ ساعت

    private static $instance = null;
    private $bookings_table;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        // جدول اصلی رزروها در افزونه مادر. نام قبلی
        // german_teacher_bookings اشتباه بود و باعث می‌شد کران ضبط هیچ رزروی را نبیند.
        $this->bookings_table = $wpdb->prefix . 'german_bookings';

        add_action('plugins_loaded', array($this, 'maybe_upgrade_schema'), 30);

        // کران چک ضبط‌های روومیت (هر ۳۰ دقیقه)
        add_action('gtbp_check_roomeet_recordings', array($this, 'check_roomeet_recordings'));
        if (!wp_next_scheduled('gtbp_check_roomeet_recordings')) {
            wp_schedule_event(time() + 700, 'gtbp_thirty_minutes', 'gtbp_check_roomeet_recordings');
        }
    }

    /* ==========================================================================
       SCHEMA
       ========================================================================== */
    public function maybe_upgrade_schema() {
        if (get_option(self::OPT_DB_VERSION) === self::DB_VERSION) return;
        $this->ensure_columns();
        update_option(self::OPT_DB_VERSION, self::DB_VERSION);
    }

    public function ensure_columns() {
        global $wpdb;
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->bookings_table)) === $this->bookings_table) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$this->bookings_table}");
            $add = [
                'class_provider'         => "ALTER TABLE {$this->bookings_table} ADD class_provider varchar(20) DEFAULT NULL",
                'rmt_meeting_id'         => "ALTER TABLE {$this->bookings_table} ADD rmt_meeting_id varchar(191) DEFAULT NULL",
                'rmt_recording_link'     => "ALTER TABLE {$this->bookings_table} ADD rmt_recording_link text DEFAULT NULL",
                'rmt_recording_download' => "ALTER TABLE {$this->bookings_table} ADD rmt_recording_download text DEFAULT NULL",
                'rmt_recording_checked'  => "ALTER TABLE {$this->bookings_table} ADD rmt_recording_checked datetime DEFAULT NULL",
            ];
            foreach ($add as $c => $sql) if (!in_array($c, $cols, true)) $wpdb->query($sql);
        }

        // مهاجرت کاملاً غیرمخرب از جدول اشتباه نسخه‌های قبلی، اگر آن جدول واقعاً
        // ساخته شده و داده‌ای گرفته باشد. هیچ رکورد/ستونی حذف نمی‌شود و فقط فیلدهای
        // خالی جدول اصلی با مقدار موجود در جدول قدیمی پر می‌شوند.
        $legacy = $wpdb->prefix . 'german_teacher_bookings';
        if ($legacy !== $this->bookings_table
            && $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $legacy)) === $legacy
            && $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->bookings_table)) === $this->bookings_table) {
            $main_cols   = $wpdb->get_col("SHOW COLUMNS FROM {$this->bookings_table}");
            $legacy_cols = $wpdb->get_col("SHOW COLUMNS FROM {$legacy}");
            $copy_cols   = ['class_provider', 'rmt_meeting_id', 'rmt_recording_link', 'rmt_recording_download', 'rmt_recording_checked'];
            $sets = [];
            foreach ($copy_cols as $col) {
                if (!in_array($col, $main_cols, true) || !in_array($col, $legacy_cols, true)) continue;
                if ($col === 'rmt_recording_checked') {
                    $sets[] = "b.{$col}=COALESCE(b.{$col},l.{$col})";
                } else {
                    $sets[] = "b.{$col}=CASE WHEN b.{$col} IS NULL OR b.{$col}='' THEN l.{$col} ELSE b.{$col} END";
                }
            }
            if ($sets) {
                $join = 'l.id=b.id';
                if (in_array('email', $main_cols, true) && in_array('email', $legacy_cols, true)) $join .= ' AND l.email=b.email';
                if (in_array('booking_date', $main_cols, true) && in_array('booking_date', $legacy_cols, true)) $join .= ' AND l.booking_date=b.booking_date';
                $wpdb->query("UPDATE {$this->bookings_table} b INNER JOIN {$legacy} l ON {$join} SET " . implode(',', $sets));
            }
        }
        $sessions = $wpdb->prefix . 'gls_sessions';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $sessions)) === $sessions) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$sessions}");
            $add = [
                'rmt_video_url'        => "ALTER TABLE {$sessions} ADD rmt_video_url text DEFAULT NULL",
                'rmt_video_url_source' => "ALTER TABLE {$sessions} ADD rmt_video_url_source varchar(20) NOT NULL DEFAULT ''",
            ];
            foreach ($add as $c => $sql) if (!in_array($c, $cols, true)) $wpdb->query($sql);
        }
    }

    /* ==========================================================================
       STATUS
       ========================================================================== */
    public function is_configured() {
        return get_option(self::OPT_API_KEY) && get_option(self::OPT_PHONE) && get_option(self::OPT_PASSWORD) && get_option(self::OPT_SALE_ID);
    }

    public function teacher_name() {
        $n = trim((string)get_option(self::OPT_TEACHER, ''));
        return $n !== '' ? $n : get_option('gtbp_bbb_teacher_name', 'مدرس');
    }

    /* ==========================================================================
       AUTH
       ========================================================================== */
    private function get_token($force = false) {
        $token = get_option(self::OPT_TOKEN, '');
        $time  = intval(get_option(self::OPT_TOKEN_TIME, 0));
        if (!$force && $token && (time() - $time) < self::TOKEN_TTL) return $token;

        $resp = wp_remote_post(self::API_BASE . '/auth/login', [
            'timeout' => 25,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode([
                'phone'    => (string)get_option(self::OPT_PHONE),
                'password' => (string)get_option(self::OPT_PASSWORD),
            ]),
        ]);
        if (is_wp_error($resp)) { $this->save_error('login WP_Error: ' . $resp->get_error_message()); return ''; }
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        if ($code !== 200) { $this->save_error("login HTTP {$code}: " . mb_substr($body, 0, 400)); return ''; }
        $data = json_decode($body, true);
        if (empty($data['token'])) { $this->save_error('login: no token in response'); return ''; }
        update_option(self::OPT_TOKEN, $data['token'], false);
        update_option(self::OPT_TOKEN_TIME, time(), false);
        return $data['token'];
    }

    /**
     * ورود با یک حساب دلخواه (مثلاً حساب مهمان مشترک) و برگرداندن توکن JWT آن.
     * نسخه ۱۴.۵.
     */
    public function login($phone, $password) {
        $resp = wp_remote_post(self::API_BASE . '/auth/login', [
            'timeout' => 25,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['phone' => (string)$phone, 'password' => (string)$password]),
        ]);
        if (is_wp_error($resp)) { $this->save_error('guest login WP_Error: ' . $resp->get_error_message()); return ''; }
        if (wp_remote_retrieve_response_code($resp) !== 200) { $this->save_error('guest login HTTP ' . wp_remote_retrieve_response_code($resp) . ': ' . mb_substr(wp_remote_retrieve_body($resp), 0, 300)); return ''; }
        $data = json_decode(wp_remote_retrieve_body($resp), true);
        return !empty($data['token']) ? $data['token'] : '';
    }

    /**
     * درخواست عمومی به API روومیت با تلاش مجدد در صورت انقضای توکن (401/403).
     * نسخه ۱۴.۵: پارامتر $token اختیاری برای درخواست با حسابی غیر از مدیر (مثل حساب مهمان).
     */
    private function request($method, $path, $query = [], $body = null, $retry = true, $token = null) {
        $is_manager_token = ($token === null); // توکن مهمان از بیرون پاس داده می‌شود
        if ($token === null) $token = $this->get_token();
        if (!$token) return null;

        $query['api_key'] = get_option(self::OPT_API_KEY);
        $url = self::API_BASE . $path . '?' . http_build_query($query);

        $args = [
            'method'  => $method,
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
        ];
        if ($body !== null) $args['body'] = wp_json_encode($body);

        $resp = wp_remote_request($url, $args);
        if (is_wp_error($resp)) { $this->save_error("{$method} {$path} WP_Error: " . $resp->get_error_message()); return null; }
        $code = wp_remote_retrieve_response_code($resp);
        $raw  = wp_remote_retrieve_body($resp);

        // فقط برای توکن مدیر تلاش مجدد کن (توکن مهمان قابل تازه‌سازی خودکار نیست)
        if (($code === 401 || $code === 403) && $retry && $is_manager_token) {
            $this->get_token(true);
            return $this->request($method, $path, $query, $body, false);
        }
        if ($code < 200 || $code >= 300) {
            $this->save_error("{$method} {$path} HTTP {$code}: " . mb_substr($raw, 0, 500));
            return null;
        }
        return json_decode($raw, true);
    }

    /* ==========================================================================
       DIAGNOSTIC / SETTINGS HELPERS
       ========================================================================== */
    public function test_connection() {
        $data = $this->request('GET', '/school/info');
        return is_array($data) && !empty($data['name']) ? $data : null;
    }

    public function fetch_services() {
        $data = $this->request('GET', '/school/services');
        return (is_array($data) && !empty($data['packages'])) ? $data['packages'] : [];
    }

    /* ==========================================================================
       ROOM LIFECYCLE
       ========================================================================== */

    /**
     * ساخت اتاق روومیت. نام باید در مدرسه یکتا باشد.
     * @return string|false theIdOfMeeting
     */
    public function create_room($name) {
        if (!$this->is_configured()) return false;
        $sale_id = get_option(self::OPT_SALE_ID);
        $body = [
            'name'                    => $name,
            'guest'                   => true,   // شاگرد به‌صورت مهمان وارد می‌شود
            'record'                  => true,
            'autoStartRecording'      => true,   // ضبط از ابتدای جلسه
            'allowDownloadRecordings' => true,   // دانلود ضبط مجاز
            'muteOnStart'             => true,
            'language'                => 'de',
            'logoutURL'               => home_url(),
            'welcomeMessage'          => 'به کلاس آنلاین خوش آمدید.',
        ];
        $res = $this->request('POST', '/school/room/create', ['saleId' => $sale_id], $body);
        if (is_array($res) && !empty($res['theIdOfMeeting'])) return $res['theIdOfMeeting'];

        // نسخه ۱۴.۴: مقاوم در برابر تایم‌اوت/تکرار.
        // اگر پاسخ null بود (تایم‌اوت/خطای شبکه) شاید اتاق روی سرور ساخته شده باشد، یا نام تکراری باشد؛
        // ابتدا با نام جست‌وجو می‌کنیم و در صورت وجود، همان اتاق را استفاده می‌کنیم (به‌جای ساخت اتاق تکراری).
        $existing = $this->find_room_id_by_name($name);
        if ($existing) return $existing;

        // اگر واقعاً ساخته نشده، با نام یکتا یک بار دیگر تلاش کن
        $body['name'] = $name . ' #' . substr(md5($name . microtime(true)), 0, 5);
        $res = $this->request('POST', '/school/room/create', ['saleId' => $sale_id], $body);
        if (is_array($res) && !empty($res['theIdOfMeeting'])) return $res['theIdOfMeeting'];

        // آخرین تلاش: شاید همین اتاقِ یکتا هم به‌خاطر تایم‌اوت ساخته شده باشد
        $existing2 = $this->find_room_id_by_name($body['name']);
        if ($existing2) return $existing2;

        return false;
    }

    /**
     * جست‌وجوی اتاق موجود بر اساس نام (برای جلوگیری از اتاق تکراری و بازیابی اتاقِ تایم‌اوت‌شده).
     * @return string theIdOfMeeting یا ''
     */
    public function find_room_id_by_name($name) {
        $name = trim((string)$name);
        if ($name === '') return '';
        $data = $this->request('GET', '/school/room/fetch-all', ['page' => 1, 'countPerPage' => 50, 'keyword' => $name]);
        if (!is_array($data) || empty($data['meetings'])) return '';
        // اولویت با تطبیق دقیقِ نام
        foreach ($data['meetings'] as $m) {
            if (!empty($m['theIdOfMeeting']) && isset($m['name']) && (string)$m['name'] === $name) return (string)$m['theIdOfMeeting'];
        }
        // سپس تطبیق شامل‌بودن نام
        foreach ($data['meetings'] as $m) {
            if (!empty($m['theIdOfMeeting']) && isset($m['name']) && mb_strpos((string)$m['name'], $name) !== false) return (string)$m['theIdOfMeeting'];
        }
        return '';
    }

    public function remove_room($the_id) {
        if (!$the_id) return false;
        $res = $this->request('POST', '/school/room/remove', ['theIdOfMeeting' => $the_id], new stdClass());
        return is_array($res);
    }

    public function start_room($the_id) {
        if (!$the_id) return null;
        // شروع اتاق؛ خطای «قبلاً در حال اجرا» مشکلی نیست
        return $this->request('POST', '/school/room/start', ['theIdOfMeeting' => $the_id], new stdClass());
    }

    /**
     * لینک ورود مدیر/مدرس. مدیر مدرسه به هر اتاق با دسترسی moderator وارد می‌شود.
     * چون شاگرد هم به‌صورت مهمان از همین اتاق استفاده می‌کند، همین لینک برای هر دو نقش کار می‌کند.
     * @return string|'' لینک مستقیم ورود
     */
    public function get_join_link($the_id) {
        if (!$the_id) return '';
        $res = $this->request('GET', '/user/join-room', ['theIdOfMeeting' => $the_id]);
        return (is_array($res) && !empty($res['link'])) ? $res['link'] : '';
    }

    /**
     * اطمینان از شروع اتاق و برگرداندن لینک ورود آماده (برای gateway) - با دسترسی مدیر/مدرس (moderator).
     */
    public function ensure_and_join($the_id) {
        if (!$the_id) return '';
        $this->start_room($the_id);
        return $this->get_join_link($the_id);
    }

    /* ==========================================================================
       نسخه ۱۴.۵: حذف گروهی اتاق‌ها از روومیت (فقط روومیت؛ رزروهای سایت دست‌نخورده)
       ========================================================================== */

    /** یک صفحه از اتاق‌های مدرسه را می‌آورد. */
    public function fetch_rooms_page($page = 1, $count = 50) {
        $data = $this->fetch_rooms_result($page, $count);
        return (is_array($data) && !empty($data['meetings'])) ? $data['meetings'] : [];
    }

    /** پاسخ کامل صفحهٔ اتاق‌ها، شامل اطلاعات صفحه‌بندی API رومیت. */
    public function fetch_rooms_result($page = 1, $count = 50) {
        return $this->request('GET', '/school/room/fetch-all', [
            'page' => max(1, intval($page)),
            'countPerPage' => max(1, min(50, intval($count))),
        ]);
    }

    /**
     * حذف همه‌ی اتاق‌های ساخته‌شده در روومیت. رزروهای سایت را دست نمی‌زند.
     * @return array ['deleted'=>int, 'failed'=>int]
     */
    public function delete_all_rooms() {
        if (!$this->is_configured()) return ['deleted' => 0, 'failed' => 0, 'error' => 'not_configured'];
        $deleted = 0; $failed = 0;
        // همیشه صفحه‌ی اول را می‌گیریم چون با حذف، بقیه بالا می‌آیند (تا سقف امن).
        for ($guard = 0; $guard < 200; $guard++) {
            $rooms = $this->fetch_rooms_page(1, 50);
            if (empty($rooms)) break;
            $progress = false;
            foreach ($rooms as $m) {
                if (empty($m['theIdOfMeeting'])) continue;
                if ($this->remove_room($m['theIdOfMeeting'])) { $deleted++; $progress = true; }
                else { $failed++; }
            }
            // اگر هیچ اتاقی حذف نشد (همه fail)، برای جلوگیری از حلقه بی‌پایان خارج شو
            if (!$progress) break;
        }
        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /* ==========================================================================
       نسخه ۱۴.۵: حساب مهمانِ مشترک - ورود شاگرد با نام خودش (نه نام مدیر)
       ========================================================================== */

    /**
     * اطمینان از وجود یک حساب دانش‌آموزِ مشترک در روومیت که سرور با آن، شاگرد را وارد می‌کند.
     * شاگرد هیچ‌گاه با روومیت درگیر نمی‌شود؛ این حساب فقط سمت سرور استفاده می‌شود و
     * درست قبل از هر ورود، نامش به نام همان شاگرد تغییر می‌کند.
     * @return string memberId یا ''
     */
    public function ensure_guest_account() {
        $mid = get_option(self::OPT_GUEST_MEMBER_ID, '');
        if ($mid) return $mid;
        if (!$this->is_configured()) return '';

        $phone = get_option(self::OPT_GUEST_PHONE, '');
        if (!$phone) {
            // یک شماره‌ی داخلی یکتا (فقط نام‌کاربری روومیت؛ پیامکی ارسال نمی‌شود)
            $phone = '09' . str_pad((string)wp_rand(100000000, 999999999), 9, '0', STR_PAD_LEFT);
            update_option(self::OPT_GUEST_PHONE, $phone, false);
        }
        $password = get_option(self::OPT_GUEST_PASSWORD, '');
        if (!$password) {
            $password = 'Gg1@' . wp_generate_password(14, false);
            update_option(self::OPT_GUEST_PASSWORD, $password, false);
        }

        $res = $this->request('POST', '/school/add-user', [], [
            'role'  => 'student',
            'users' => [['name' => 'زبان‌آموز', 'password' => $password, 'phone' => $phone]],
        ]);
        if (is_array($res) && !empty($res['users'])) {
            foreach ($res['users'] as $u) {
                // فقط عضوی که واقعاً ساخته شده (error !== true) را بپذیر تا memberId معتبر ذخیره شود
                if (empty($u['error']) && !empty($u['memberId'])) {
                    update_option(self::OPT_GUEST_MEMBER_ID, (string)$u['memberId'], false);
                    return (string)$u['memberId'];
                }
            }
            // اگر «قبلاً وجود دارد» بود، همان memberId قابل استفاده است
            foreach ($res['users'] as $u) {
                if (!empty($u['memberId'])) {
                    update_option(self::OPT_GUEST_MEMBER_ID, (string)$u['memberId'], false);
                    return (string)$u['memberId'];
                }
            }
        }
        $this->save_error('guest account creation failed (add-user returned no usable memberId)');
        return '';
    }

    /** تغییر نام یک عضو (برای هم‌نام کردن حساب مهمان با شاگردِ فعلی). */
    public function set_member_name($member_id, $name) {
        if (!$member_id) return false;
        $name = trim((string)$name);
        if ($name === '') $name = 'زبان‌آموز';
        $res = $this->request('POST', '/school/update-user', [], ['memberId' => (string)$member_id, 'name' => $name]);
        return is_array($res);
    }

    /** افزودن یک عضو به اتاق (idempotent - اگر عضو باشد مشکلی نیست). */
    public function add_member_to_room($the_id, $member_id, $role = 'student') {
        if (!$the_id || !$member_id) return false;
        $res = $this->request('POST', '/school/room/add-members', ['theIdOfMeeting' => $the_id], [
            'members' => [['memberId' => (string)$member_id, 'role' => $role]],
        ]);
        return is_array($res);
    }

    /**
     * لینک ورود شاگرد به‌صورت «مهمانِ نام‌دار»: حساب مهمانِ مشترک را به نام شاگرد تغییر می‌دهد،
     * به اتاق اضافه می‌کند، با آن حساب لاگین می‌کند و لینک join را با نقش شرکت‌کننده و نام شاگرد می‌گیرد.
     * اگر هر مرحله شکست خورد، '' برمی‌گرداند تا caller به لینک مدیر برگردد.
     * @return string لینک یا ''
     */
    public function guest_join_link($the_id, $student_name) {
        if (!$the_id) return '';
        $member_id = $this->ensure_guest_account();
        if (!$member_id) return '';

        // نام حساب مهمان را به نام شاگرد تغییر بده تا در کلاس با نام خودش دیده شود
        $this->set_member_name($member_id, $student_name);
        // حساب مهمان را عضو این اتاق کن (اگر باشد، بی‌اثر است)
        $this->add_member_to_room($the_id, $member_id, 'student');

        $token = $this->login(get_option(self::OPT_GUEST_PHONE, ''), get_option(self::OPT_GUEST_PASSWORD, ''));
        if (!$token) return '';

        // join-room با توکنِ حساب مهمان → لینک با نقش شرکت‌کننده و نام شاگرد
        $res = $this->request('GET', '/user/join-room', ['theIdOfMeeting' => $the_id], null, false, $token);
        return (is_array($res) && !empty($res['link'])) ? $res['link'] : '';
    }

    /* ==========================================================================
       RECORDINGS
       ========================================================================== */

    /**
     * گرفتن ضبط‌های یک اتاق و انتخاب بهترین ضبط منتشرشده.
     * @return array|null ['link'=>playback, 'download'=>directDownload, 'recordId'=>..]
     */
    public function get_recording($the_id) {
        if (!$the_id) return null;
        $res = $this->request('GET', '/school/room/recordings', ['theIdOfMeeting' => $the_id]);
        if (!is_array($res) || empty($res['recordings'])) return null;

        $recs = $res['recordings'];
        // جدیدترین ضبط را انتخاب کن (آخرین عنصر معمولاً جدیدترین است؛ برای اطمینان بر اساس startTime مرتب می‌کنیم)
        usort($recs, function($a, $b) {
            return strcmp((string)($b['startTime'] ?? ''), (string)($a['startTime'] ?? ''));
        });
        foreach ($recs as $r) {
            $link     = (string)($r['link'] ?? '');
            $download = (string)($r['downloadLink'] ?? '');
            if ($link === '' && $download === '') continue;

            // اگر منتشر نشده، منتشرش کن تا برای کاربر قابل مشاهده باشد
            if (isset($r['isPublished']) && $r['isPublished'] === false && !empty($r['recordId'])) {
                $this->publish_recording($r['recordId'], true);
            }
            return [
                'link'     => $link,
                'download' => $download,
                'recordId' => (string)($r['recordId'] ?? ''),
            ];
        }
        return null;
    }

    public function publish_recording($record_id, $publish = true) {
        if (!$record_id) return false;
        $res = $this->request('POST', '/school/publish-recording', [], ['recordId' => $record_id, 'publish' => (bool)$publish]);
        return is_array($res);
    }

    /**
     * کران: برای رزروهای روومیت که هنوز ضبط ندارند، ضبط را پیدا و ذخیره کن.
     */
    public function check_roomeet_recordings() {
        if (!$this->is_configured()) return;
        global $wpdb;
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$this->bookings_table}");
        if (!in_array('rmt_meeting_id', $cols, true)) return;

        $retry_after = date('Y-m-d H:i:s', strtotime('-1 hours'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->bookings_table}
             WHERE class_provider = 'roomeet'
             AND rmt_meeting_id IS NOT NULL AND rmt_meeting_id <> ''
             AND (rmt_recording_link IS NULL OR rmt_recording_link = '')
             AND (rmt_recording_checked IS NULL OR rmt_recording_checked < %s)
             AND booking_date <= CURDATE()
             ORDER BY booking_date ASC LIMIT 40",
            $retry_after
        ));
        foreach ($rows as $b) {
            if ($this->session_has_manual_roomeet_video($b->id)) {
                $wpdb->update($this->bookings_table, ['rmt_recording_checked' => current_time('mysql')], ['id' => intval($b->id)]);
                continue;
            }
            $rec = $this->get_recording($b->rmt_meeting_id);
            // پاسخ خالی/خطای موقت API نباید لینک ضبطی را که قبلاً ذخیره شده پاک کند.
            $wpdb->update($this->bookings_table, [
                'rmt_recording_checked' => current_time('mysql'),
            ], ['id' => intval($b->id)]);
            if ($rec && ($rec['link'] || $rec['download'])) {
                $this->save_recording_for_booking($b->id, $rec, $b->rmt_meeting_id, false);
            }
        }
    }

    /**
     * ذخیره امن نتیجه ضبط روی رزرو اصلی و همگام‌سازی با پنل آموزشی.
     * لینک‌های موجود و لینک‌های دستی هرگز بازنویسی نمی‌شوند مگر $overwrite صریحاً true باشد.
     */
    public function save_recording_for_booking($booking_id, $rec, $the_id = '', $overwrite = false) {
        if (!$booking_id || !is_array($rec)) return false;
        $link = esc_url_raw((string)($rec['link'] ?? ''));
        $download = esc_url_raw((string)($rec['download'] ?? ''));
        if ($link === '' && $download === '') return false;

        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->bookings_table} WHERE id=%d", intval($booking_id)));
        if (!$booking) return false;

        $data = [
            'class_provider'       => 'roomeet',
            'rmt_recording_checked'=> current_time('mysql'),
        ];
        if ($the_id !== '' && ($overwrite || empty($booking->rmt_meeting_id))) $data['rmt_meeting_id'] = sanitize_text_field($the_id);
        if ($link !== '' && ($overwrite || empty($booking->rmt_recording_link))) $data['rmt_recording_link'] = $link;
        if ($download !== '' && ($overwrite || empty($booking->rmt_recording_download))) $data['rmt_recording_download'] = $download;

        $updated = $wpdb->update($this->bookings_table, $data, ['id' => intval($booking_id)]);
        if ($updated === false) {
            $this->save_error('save recording DB error: ' . $wpdb->last_error);
            return false;
        }

        $effective = !empty($booking->rmt_recording_link) && !$overwrite ? (string)$booking->rmt_recording_link : ($link ?: $download);
        if ($effective !== '') $this->sync_learning_roomeet_video($booking_id, $effective);
        return true;
    }

    /**
     * یک دسته از رزروهای قدیمی رومیت را بررسی می‌کند. برای اجرای AJAX زنجیره‌ای طراحی شده
     * تا تعداد زیاد کلاس باعث timeout نشود.
     */
    public function backfill_recordings_batch($after_id = 0, $limit = 5) {
        if (!$this->is_configured()) return ['done'=>true, 'processed'=>0, 'found'=>0, 'synced'=>0, 'failed'=>0, 'next_cursor'=>intval($after_id), 'error'=>'not_configured'];
        global $wpdb;
        $limit = max(1, min(10, intval($limit)));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->bookings_table}
             WHERE id > %d
             AND (
                 (class_provider='roomeet' AND rmt_meeting_id IS NOT NULL AND rmt_meeting_id<>'')
                 OR roomeet_room_id LIKE 'rmt:%%'
             )
             AND booking_date <= CURDATE()
             AND status IN ('confirmed','completed')
             ORDER BY id ASC LIMIT %d",
            intval($after_id), $limit
        ));

        $processed = 0; $found = 0; $synced = 0; $failed = 0; $cursor = intval($after_id);
        foreach ((array)$rows as $booking) {
            $processed++;
            $cursor = intval($booking->id);

            // داده دستی جلسه اولویت قطعی دارد و دست‌نخورده باقی می‌ماند.
            if ($this->session_has_manual_roomeet_video($booking->id)) continue;

            // پشتیبانی از رزروهای خیلی قدیمی که هنوز شناسه رومیت را در مارکر rmt: نگه داشته‌اند.
            $the_id = trim((string)($booking->rmt_meeting_id ?? ''));
            if ($the_id === '' && strpos((string)($booking->roomeet_room_id ?? ''), 'rmt:') === 0) {
                $marker = trim(substr((string)$booking->roomeet_room_id, 4));
                if ($marker !== '' && $marker !== 'pending') {
                    $the_id = $marker;
                    // مارکر قدیمی عمداً حذف نمی‌شود؛ فقط ستون‌های جدید به شکل افزایشی پر می‌شوند.
                    $wpdb->update($this->bookings_table, [
                        'class_provider' => 'roomeet',
                        'rmt_meeting_id' => $the_id,
                    ], ['id' => intval($booking->id)]);
                }
            }
            if ($the_id === '' || $the_id === 'pending') continue;

            if (!empty($booking->rmt_recording_link) || !empty($booking->rmt_recording_download)) {
                $existing = !empty($booking->rmt_recording_link) ? $booking->rmt_recording_link : $booking->rmt_recording_download;
                $this->sync_learning_roomeet_video($booking->id, $existing);
                $synced++;
                continue;
            }

            $rec = $this->get_recording($the_id);
            $wpdb->update($this->bookings_table, ['rmt_recording_checked'=>current_time('mysql')], ['id'=>intval($booking->id)]);
            if (!$rec || (empty($rec['link']) && empty($rec['download']))) continue;
            $found++;
            if ($this->save_recording_for_booking($booking->id, $rec, $the_id, false)) $synced++;
            else $failed++;
        }

        return [
            'done'        => count($rows) < $limit,
            'processed'   => $processed,
            'found'       => $found,
            'synced'      => $synced,
            'failed'      => $failed,
            'next_cursor' => $cursor,
            'error'       => '',
        ];
    }

    /**
     * بازیابی API-first: مستقیماً همه اتاق‌های واقعی پنل رومیت را صفحه‌به‌صفحه
     * می‌خواند؛ بنابراین به برچسب‌گذاری صحیح رزروهای قدیمی وابسته نیست.
     */
    public function backfill_roomeet_rooms_batch($page = 1, $limit = 5) {
        if (!$this->is_configured()) return ['done'=>true, 'processed'=>0, 'found'=>0, 'synced'=>0, 'unmatched'=>0, 'without_recording'=>0, 'failed'=>0, 'next_cursor'=>intval($page), 'error'=>'not_configured'];
        $page = max(1, intval($page));
        $limit = max(1, min(10, intval($limit)));
        $result = $this->fetch_rooms_result($page, $limit);
        if (!is_array($result) || !array_key_exists('meetings', $result)) {
            return ['done'=>true, 'processed'=>0, 'found'=>0, 'synced'=>0, 'unmatched'=>0, 'without_recording'=>0, 'failed'=>1, 'next_cursor'=>$page, 'error'=>'room_list_failed'];
        }

        $rooms = is_array($result['meetings']) ? $result['meetings'] : [];
        $processed = 0; $found = 0; $synced = 0; $unmatched = 0; $without = 0; $failed = 0; $unmatched_rooms = [];
        foreach ($rooms as $room) {
            $the_id = trim((string)($room['theIdOfMeeting'] ?? ''));
            if ($the_id === '') { $failed++; continue; }
            $processed++;
            $rec = $this->get_recording($the_id);
            if (!$rec || (empty($rec['link']) && empty($rec['download']))) { $without++; continue; }
            $found++;

            $booking = $this->find_booking_for_roomeet_room($room);
            if (!$booking) {
                $unmatched++;
                if (count($unmatched_rooms) < 10) {
                    $unmatched_rooms[] = [
                        'the_id' => $the_id,
                        'name' => sanitize_text_field((string)($room['name'] ?? '')),
                        'link' => esc_url_raw((string)($rec['link'] ?? $rec['download'] ?? '')),
                    ];
                }
                continue;
            }

            if ($this->session_has_manual_roomeet_video($booking->id)) { $synced++; continue; }
            if ($this->save_recording_for_booking($booking->id, $rec, $the_id, false)) $synced++;
            else $failed++;
        }

        $total_pages = max(0, intval($result['paginationLength'] ?? 0));
        $total_count = max(0, intval($result['totalCount'] ?? 0));
        $done = $total_pages > 0 ? $page >= $total_pages : count($rooms) < $limit;
        if ($total_count > 0 && ($page * $limit) >= $total_count) $done = true;
        return [
            'done' => $done,
            'processed' => $processed,
            'found' => $found,
            'synced' => $synced,
            'unmatched' => $unmatched,
            'without_recording' => $without,
            'failed' => $failed,
            'next_cursor' => $page + 1,
            'unmatched_rooms' => $unmatched_rooms,
            'error' => '',
        ];
    }

    /** اتصال یک اتاق واقعی رومیت به رزرو سایت با شناسه ذخیره‌شده یا #شماره‌رزرو نام اتاق. */
    private function find_booking_for_roomeet_room($room) {
        global $wpdb;
        $the_id = trim((string)($room['theIdOfMeeting'] ?? ''));
        $meeting_id = trim((string)($room['meetingId'] ?? ''));
        if ($the_id === '') return null;

        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->bookings_table}
             WHERE rmt_meeting_id=%s OR roomeet_room_id=%s
             ORDER BY id DESC LIMIT 1",
            $the_id,
            'rmt:' . $the_id
        ));
        if ($booking) return $booking;

        // بعضی نسخه‌های بسیار قدیمی شناسه داخلی BBB را در ستون عمومی اتاق نگه می‌داشتند.
        if ($meeting_id !== '') {
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$this->bookings_table} WHERE roomeet_room_id=%s ORDER BY id DESC LIMIT 1",
                $meeting_id
            ));
            if ($booking) return $booking;
        }

        // اتاق‌های ساخته‌شده توسط افزونه شماره رزرو را به‌صورت #333 در نام دارند.
        $name = (string)($room['name'] ?? '');
        if (preg_match('/#\s*(\d+)(?=\D|$)/u', $name, $m)) {
            $candidate = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->bookings_table} WHERE id=%d", intval($m[1])));
            if ($candidate) return $candidate;
        }
        return null;
    }

    private function session_has_manual_roomeet_video($booking_id) {
        global $wpdb;
        $sessions = $wpdb->prefix . 'gls_sessions';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $sessions)) !== $sessions) return false;
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$sessions}");
        if (!in_array('rmt_video_url', $cols, true)) return false;
        $row = $wpdb->get_row($wpdb->prepare("SELECT rmt_video_url, rmt_video_url_source FROM {$sessions} WHERE booking_id=%d LIMIT 1", $booking_id));
        return $row && (string)$row->rmt_video_url_source === 'manual' && trim((string)$row->rmt_video_url) !== '';
    }

    public function sync_learning_roomeet_video($booking_id, $link) {
        global $wpdb;
        $sessions = $wpdb->prefix . 'gls_sessions';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $sessions)) !== $sessions) return;
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$sessions}");
        if (!in_array('rmt_video_url', $cols, true)) return;
        $row = $wpdb->get_row($wpdb->prepare("SELECT id,rmt_video_url,rmt_video_url_source FROM {$sessions} WHERE booking_id=%d LIMIT 1", $booking_id));
        if (!$row) return;
        if ((string)$row->rmt_video_url_source === 'manual' && trim((string)$row->rmt_video_url) !== '') return;
        $wpdb->update($sessions, [
            'rmt_video_url'        => esc_url_raw($link),
            'rmt_video_url_source' => 'auto',
        ], ['id' => intval($row->id)]);
    }

    /**
     * جستجوی دستی ضبط با theIdOfMeeting که ادمین وارد می‌کند.
     */
    public function manual_fetch_recording($booking, $the_id) {
        $the_id = trim(sanitize_text_field($the_id));
        if ($the_id === '') return false;
        $rec = $this->get_recording($the_id);
        if (!$rec || (!$rec['link'] && !$rec['download'])) return false;
        return $this->save_recording_for_booking($booking->id, $rec, $the_id, true);
    }

    /* ==========================================================================
       LOG
       ========================================================================== */
    public function save_error($msg) {
        update_option(self::OPT_LAST_ERROR, '[' . current_time('mysql') . '] ' . $msg, false);
        if (function_exists('error_log')) error_log('[GTBP_Roomeet] ' . $msg);
    }
    public function get_last_error() { return get_option(self::OPT_LAST_ERROR, ''); }
    public function clear_error() { delete_option(self::OPT_LAST_ERROR); }
}

endif;
