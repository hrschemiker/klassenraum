<?php
/**
 * Google Meet Provider - افزونه رزرو کلاس (نسخه بازنویسی‌شده و اصلاح‌شده)
 *
 * قابلیت‌ها:
 *  - OAuth 2.0 با Google
 *  - ساخت خودکار لینک Meet با Google Calendar API
 *  - یافتن خودکار ضبط جلسات در Google Drive
 *  - جست‌وجوی دستی ضبط با Meet ID
 *  - public کردن خودکار فایل Drive
 *  - عدم تداخل با BigBlueButton
 *
 * ستون‌های افزوده به جدول رزروها (اگر نبودند):
 *   - meet_join_link              text
 *   - meet_recording_link         text
 *   - meet_recording_checked      datetime
 *   - meet_calendar_event_id      varchar(191)
 *
 * ستون‌های افزوده به جدول جلسات آموزشی:
 *   - meet_video_url              text
 *   - meet_video_url_source       varchar(20)
 */

if (!defined('ABSPATH')) exit;

if (!class_exists('GTBP_Google_Meet_Provider')):

class GTBP_Google_Meet_Provider {

    const OPT_ENABLED           = 'gtbp_meet_enabled';
    const OPT_CLIENT_ID         = 'gtbp_meet_client_id';
    const OPT_CLIENT_SECRET     = 'gtbp_meet_client_secret';
    const OPT_REFRESH_TOKEN     = 'gtbp_meet_refresh_token';
    const OPT_ACCESS_TOKEN      = 'gtbp_meet_access_token';
    const OPT_ACCESS_EXPIRES    = 'gtbp_meet_access_expires';
    const OPT_ACCOUNT_EMAIL     = 'gtbp_meet_account_email';
    const OPT_CALENDAR_ID       = 'gtbp_meet_calendar_id';
    const OPT_TIMEZONE          = 'gtbp_meet_timezone';
    const OPT_RECORDINGS_FOLDER = 'gtbp_meet_recordings_folder';
    const OPT_AUTO_CREATE       = 'gtbp_meet_auto_create';
    const OPT_DB_VERSION        = 'gtbp_meet_db_version';
    const OPT_LAST_ERROR        = 'gtbp_meet_last_error';

    const DB_VERSION = '4';

    // scope شامل calendar (نه فقط calendar.events) تا endpoint اطلاعات کلندر هم کار کند
    const SCOPES = 'https://www.googleapis.com/auth/calendar https://www.googleapis.com/auth/drive openid email';

    private static $instance = null;
    private $bookings_table;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        global $wpdb;
        $this->bookings_table = $wpdb->prefix . 'german_bookings';

        // مقادیر پیش‌فرض
        if (get_option(self::OPT_ENABLED) === false)     add_option(self::OPT_ENABLED, '0');
        if (get_option(self::OPT_CALENDAR_ID) === false) add_option(self::OPT_CALENDAR_ID, 'primary');
        if (get_option(self::OPT_TIMEZONE) === false)    add_option(self::OPT_TIMEZONE, 'Asia/Tehran');
        if (get_option(self::OPT_AUTO_CREATE) === false) add_option(self::OPT_AUTO_CREATE, '1');

        // ستون‌ها باید یک‌بار پس از فعال‌سازی/آپدیت ساخته شوند - نه هر درخواست
        add_action('plugins_loaded', array($this, 'maybe_upgrade_schema'), 25);

        add_action('admin_menu', array($this, 'register_admin_menu'), 25);
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_action('admin_init', array($this, 'handle_oauth_callback'));

        // کران چک ضبط‌های گوگل درایو (هر ۳۰ دقیقه)
        add_action('gtbp_check_meet_recordings', array($this, 'check_meet_recordings'));
        if (!wp_next_scheduled('gtbp_check_meet_recordings')) {
            wp_schedule_event(time() + 600, 'gtbp_thirty_minutes', 'gtbp_check_meet_recordings');
        }

        add_shortcode('german_teacher_all_recordings', array($this, 'render_all_recordings'));
    }

    /* ==========================================================================
       DATABASE SCHEMA - فقط یک‌بار پس از آپدیت اجرا می‌شود
       ========================================================================== */

    public function maybe_upgrade_schema() {
        if (get_option(self::OPT_DB_VERSION) === self::DB_VERSION) return;
        $this->ensure_columns();
        update_option(self::OPT_DB_VERSION, self::DB_VERSION);
    }

    public function ensure_columns() {
        global $wpdb;

        // جدول رزروها
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->bookings_table)) === $this->bookings_table) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$this->bookings_table}");
            $add  = [
                'meet_join_link'         => "ALTER TABLE {$this->bookings_table} ADD meet_join_link text DEFAULT NULL",
                'meet_recording_link'    => "ALTER TABLE {$this->bookings_table} ADD meet_recording_link text DEFAULT NULL",
                'meet_recording_checked' => "ALTER TABLE {$this->bookings_table} ADD meet_recording_checked datetime DEFAULT NULL",
                'meet_calendar_event_id' => "ALTER TABLE {$this->bookings_table} ADD meet_calendar_event_id varchar(191) DEFAULT NULL",
            ];
            foreach ($add as $c => $sql) if (!in_array($c, $cols, true)) $wpdb->query($sql);
        }

        // حفظ داده نسخه‌های قبلی که به‌علت نام اشتباه جدول در german_teacher_bookings
        // نوشته شده‌اند. فقط خانه‌های خالی جدول اصلی پر می‌شوند و جدول قدیمی حذف نمی‌شود.
        $legacy = $wpdb->prefix . 'german_teacher_bookings';
        if ($legacy !== $this->bookings_table
            && $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $legacy)) === $legacy
            && $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->bookings_table)) === $this->bookings_table) {
            $main_cols = $wpdb->get_col("SHOW COLUMNS FROM {$this->bookings_table}");
            $legacy_cols = $wpdb->get_col("SHOW COLUMNS FROM {$legacy}");
            $sets = [];
            foreach (['meet_join_link','meet_recording_link','meet_recording_checked','meet_calendar_event_id'] as $col) {
                if (!in_array($col, $main_cols, true) || !in_array($col, $legacy_cols, true)) continue;
                if ($col === 'meet_recording_checked') $sets[] = "b.{$col}=COALESCE(b.{$col},l.{$col})";
                else $sets[] = "b.{$col}=CASE WHEN b.{$col} IS NULL OR b.{$col}='' THEN l.{$col} ELSE b.{$col} END";
            }
            if ($sets) {
                $join = 'l.id=b.id';
                if (in_array('email',$main_cols,true) && in_array('email',$legacy_cols,true)) $join .= ' AND l.email=b.email';
                if (in_array('booking_date',$main_cols,true) && in_array('booking_date',$legacy_cols,true)) $join .= ' AND l.booking_date=b.booking_date';
                $wpdb->query("UPDATE {$this->bookings_table} b INNER JOIN {$legacy} l ON {$join} SET " . implode(',', $sets));
            }
        }

        // جدول جلسات آموزشی
        $sessions = $wpdb->prefix . 'gls_sessions';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $sessions)) === $sessions) {
            $cols = $wpdb->get_col("SHOW COLUMNS FROM {$sessions}");
            $add  = [
                'meet_video_url'        => "ALTER TABLE {$sessions} ADD meet_video_url text DEFAULT NULL",
                'meet_video_url_source' => "ALTER TABLE {$sessions} ADD meet_video_url_source varchar(20) NOT NULL DEFAULT ''",
            ];
            foreach ($add as $c => $sql) if (!in_array($c, $cols, true)) $wpdb->query($sql);
        }
    }

    public function is_enabled()      { return get_option(self::OPT_ENABLED, '0') === '1'; }
    public function auto_create_on()  { return get_option(self::OPT_AUTO_CREATE, '1') === '1'; }
    public function has_refresh_token() { return (bool) get_option(self::OPT_REFRESH_TOKEN, ''); }

    public function is_configured() {
        return $this->is_enabled()
            && get_option(self::OPT_CLIENT_ID)
            && get_option(self::OPT_CLIENT_SECRET)
            && get_option(self::OPT_REFRESH_TOKEN);
    }

    /* ==========================================================================
       ADMIN MENU + SETTINGS PAGE
       ========================================================================== */

    public function register_admin_menu() {
        // This page is intentionally registered as a hidden admin page.  It is
        // still used as the OAuth redirect target by existing Google Cloud
        // credentials, so it must remain a valid WordPress admin route even
        // though the visible settings UI now lives in the unified service tab.
        add_submenu_page(
            '',
            'تنظیمات Google Meet',
            'تنظیمات Google Meet',
            'manage_options',
            'gtbp_meet',
            array($this, 'render_settings_page')
        );
    }

    private function settings_url($args = array()) {
        $url = admin_url('admin.php?page=gtbp_online_class&tab=meet');
        return $args ? add_query_arg($args, $url) : $url;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) return;

        $enabled     = get_option(self::OPT_ENABLED, '0');
        $client_id   = get_option(self::OPT_CLIENT_ID, '');
        $client_sec  = get_option(self::OPT_CLIENT_SECRET, '');
        $account     = get_option(self::OPT_ACCOUNT_EMAIL, '');
        $calendar_id = get_option(self::OPT_CALENDAR_ID, 'primary');
        $timezone    = get_option(self::OPT_TIMEZONE, 'Asia/Tehran');
        $folder_id   = get_option(self::OPT_RECORDINGS_FOLDER, '');
        $auto_create = get_option(self::OPT_AUTO_CREATE, '1');
        $has_token   = $this->has_refresh_token();
        $last_error  = get_option(self::OPT_LAST_ERROR, '');
        $current_scopes = 'calendar + drive (کامل)';

        $redirect_uri = admin_url('admin.php?page=gtbp_meet&gtbp_meet_oauth=callback');

        if (isset($_GET['msg'])) {
            $msg = sanitize_key($_GET['msg']);
            $texts = [
                'meet_saved'    => ['success', '✅ تنظیمات Google Meet ذخیره شد.'],
                'meet_auth_ok'  => ['success', '✅ حساب Google با موفقیت متصل شد.'],
                'meet_auth_err' => ['error',   '❌ خطا در اتصال حساب Google. جزئیات در «آخرین خطا» پایین صفحه.'],
                'meet_dc'       => ['success', 'حساب Google قطع شد و توکن‌ها پاک شدند.'],
                'meet_test_ok'  => ['success', '✅ اتصال به Calendar API موفق بود.'],
                'meet_test_err' => ['error',   '❌ اتصال به Calendar API ناموفق بود؛ به «آخرین خطا» پایین صفحه نگاه کنید.'],
                'meet_reauth'   => ['warning', 'حساب گوگل نیاز به اتصال مجدد دارد چون scope تغییر کرده است.'],
            ];
            if (isset($texts[$msg])) {
                echo '<div class="notice notice-' . esc_attr($texts[$msg][0]) . ' is-dismissible"><p>' . esc_html($texts[$msg][1]) . '</p></div>';
            }
        }
        ?>
        <div class="wrap gtbp-admin-wrap">
            <h1>تنظیمات Google Meet</h1>

            <div style="max-width:900px;background:#fff;padding:15px 20px;border-right:4px solid #34a853;margin-bottom:16px;line-height:2;">
                <strong>راهنمای اتصال:</strong>
                <ol style="margin:8px 20px 0;">
                    <li>در <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a> یک پروژه بسازید.</li>
                    <li>در <b>APIs &amp; Services → Library</b>، هر دو API را فعال کنید: <code>Google Calendar API</code> و <code>Google Drive API</code>.</li>
                    <li>در <b>OAuth consent screen</b> نوع را <b>External</b> بگذارید و ایمیل خود را به Test users اضافه کنید.</li>
                    <li>در <b>Credentials → Create Credentials → OAuth client ID</b> نوع <b>Web application</b> بسازید و این آدرس را در <b>Authorized redirect URIs</b> اضافه کنید:<br>
                        <code style="direction:ltr;display:inline-block;background:#f6f7f7;padding:4px 8px;margin-top:4px;user-select:all;"><?php echo esc_html($redirect_uri); ?></code>
                    </li>
                    <li>Client ID و Client Secret را پایین وارد کنید و «ذخیره» را بزنید.</li>
                    <li>سپس روی «اتصال به حساب Google» کلیک کنید. <strong>مهم:</strong> اگر قبلاً وصل شده‌اید، ابتدا «قطع اتصال» را بزنید تا scope جدید اعمال شود.</li>
                </ol>
                <p style="margin:12px 0 0;color:#b45309;background:#fef3c7;padding:8px 12px;border-radius:6px;">
                    <b>توجه:</b> scope در این نسخه به <code dir="ltr"><?php echo esc_html($current_scopes); ?></code> ارتقا یافته. اگر قبلاً وصل بودید، حتماً یک‌بار قطع و دوباره وصل کنید.
                </p>
            </div>

            <form method="post" style="max-width:900px;background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;">
                <?php wp_nonce_field('gtbp_meet_save', 'gtbp_meet_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">فعال‌سازی Google Meet</th>
                        <td>
                            <label><input type="checkbox" name="meet_enabled" value="1" <?php checked($enabled, '1'); ?>> فعال باشد</label>
                            <p class="description">اگر همزمان BigBlueButton هم فعال باشد، برای رزروها فقط BBB ساخته می‌شود. Meet برای فراخوانی دستی/ویدئو در دسترس است.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">ساخت خودکار لینک Meet</th>
                        <td>
                            <label><input type="checkbox" name="meet_auto_create" value="1" <?php checked($auto_create, '1'); ?>> در صورت فعال بودن Meet و غیرفعال بودن BBB، برای رزروهای جدید لینک Meet خودکار ساخته شود.</label>
                        </td>
                    </tr>
                    <tr><th scope="row">Client ID</th>
                        <td><input type="text" name="meet_client_id" value="<?php echo esc_attr($client_id); ?>" dir="ltr" style="width:100%;padding:8px;" placeholder="xxxxxxxxxxxxxxxx.apps.googleusercontent.com"></td>
                    </tr>
                    <tr><th scope="row">Client Secret</th>
                        <td><input type="password" name="meet_client_secret" value="<?php echo esc_attr($client_sec); ?>" dir="ltr" style="width:100%;padding:8px;" placeholder="GOCSPX-..."></td>
                    </tr>
                    <tr><th scope="row">Redirect URI</th>
                        <td>
                            <code style="direction:ltr;display:inline-block;background:#f6f7f7;padding:6px 10px;user-select:all;"><?php echo esc_html($redirect_uri); ?></code>
                            <p class="description">این دقیقاً همان مقدار Authorized redirect URI است که در Google Cloud وارد کرده‌اید.</p>
                        </td>
                    </tr>
                    <tr><th scope="row">Calendar ID</th>
                        <td>
                            <input type="text" name="meet_calendar_id" value="<?php echo esc_attr($calendar_id); ?>" dir="ltr" style="width:100%;padding:8px;" placeholder="primary">
                            <p class="description">پیش‌فرض <code>primary</code> یعنی تقویم اصلی همان حساب.</p>
                        </td>
                    </tr>
                    <tr><th scope="row">منطقه زمانی</th>
                        <td><input type="text" name="meet_timezone" value="<?php echo esc_attr($timezone); ?>" dir="ltr" style="width:280px;padding:8px;" placeholder="Asia/Tehran"></td>
                    </tr>
                    <tr><th scope="row">پوشه ضبط‌ها در Google Drive</th>
                        <td>
                            <input type="text" name="meet_recordings_folder" value="<?php echo esc_attr($folder_id); ?>" dir="ltr" style="width:100%;padding:8px;" placeholder="اختیاری - ID پوشه Meet Recordings">
                            <p class="description">اگر خالی باشد، افزونه در کل Drive دنبال ضبط‌ها می‌گردد. برای سرعت بیشتر ID پوشه <b>Meet Recordings</b> را وارد کنید.</p>
                        </td>
                    </tr>
                </table>

                <p>
                    <button type="submit" name="gtbp_meet_save" class="button button-primary button-large">ذخیره تنظیمات</button>
                    <button type="submit" name="gtbp_meet_test" class="button button-secondary button-large">تست اتصال Calendar</button>
                    <button type="submit" name="gtbp_meet_test_create" class="button button-secondary button-large" style="background:#34a853;color:#fff;border-color:#188038;">🎥 ساخت جلسه تستی</button>
                </p>
                <p class="description">دکمهٔ «ساخت جلسه تستی» یک ایونت آزمایشی برای فردا ۱۰ تا ۱۱ می‌سازد، لینک Meet را نشان می‌دهد و در پایین صفحه Response کامل Google را چاپ می‌کند.</p>
            </form>

            <div style="max-width:900px;margin-top:20px;background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;">
                <h2 style="margin-top:0;">وضعیت حساب Google</h2>
                <?php if ($has_token): ?>
                    <p>✅ متصل به: <strong dir="ltr"><?php echo esc_html($account ?: 'نامشخص'); ?></strong></p>
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field('gtbp_meet_disconnect', 'gtbp_meet_dc_nonce'); ?>
                        <button type="submit" name="gtbp_meet_disconnect" class="button button-secondary" onclick="return confirm('اطمینان دارید؟ لینک‌های ساخته‌شده تحت تأثیر قرار نمی‌گیرند.');">قطع اتصال</button>
                    </form>
                    <?php if ($client_id && $client_sec): ?>
                        <a href="<?php echo esc_url($this->build_auth_url()); ?>" class="button button-secondary" style="margin-inline-start:8px;">🔄 اتصال مجدد (برای اعمال scope جدید)</a>
                    <?php endif; ?>
                <?php else: ?>
                    <p>⚠️ هنوز به هیچ حساب Google متصل نشده‌اید.</p>
                    <?php if ($client_id && $client_sec): ?>
                        <a href="<?php echo esc_url($this->build_auth_url()); ?>" class="button button-primary button-large">🔗 اتصال به حساب Google</a>
                    <?php else: ?>
                        <p><em>ابتدا Client ID و Client Secret را ذخیره کنید.</em></p>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($last_error): ?>
                    <div style="margin-top:16px;padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;">
                        <strong>آخرین خطا:</strong>
                        <pre style="direction:ltr;text-align:left;white-space:pre-wrap;word-break:break-all;margin:6px 0 0;font-size:12px;color:#7f1d1d;background:#fff;padding:8px;border-radius:4px;"><?php echo esc_html($last_error); ?></pre>
                        <form method="post" style="margin-top:8px;">
                            <?php wp_nonce_field('gtbp_meet_clear_error', 'gtbp_meet_ce_nonce'); ?>
                            <button type="submit" name="gtbp_meet_clear_error" class="button button-small">پاک کردن</button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    public function handle_admin_actions() {
        if (!current_user_can('manage_options')) return;

        // ذخیره تنظیمات
        if (isset($_POST['gtbp_meet_save']) && check_admin_referer('gtbp_meet_save', 'gtbp_meet_nonce')) {
            $this->save_settings_from_post();
            wp_safe_redirect($this->settings_url(array('msg' => 'meet_saved')));
            exit;
        }

        // قطع اتصال
        if (isset($_POST['gtbp_meet_disconnect']) && check_admin_referer('gtbp_meet_disconnect', 'gtbp_meet_dc_nonce')) {
            delete_option(self::OPT_REFRESH_TOKEN);
            delete_option(self::OPT_ACCESS_TOKEN);
            delete_option(self::OPT_ACCESS_EXPIRES);
            delete_option(self::OPT_ACCOUNT_EMAIL);
            delete_option(self::OPT_LAST_ERROR);
            wp_safe_redirect($this->settings_url(array('msg' => 'meet_dc')));
            exit;
        }

        // پاک کردن خطا
        if (isset($_POST['gtbp_meet_clear_error']) && check_admin_referer('gtbp_meet_clear_error', 'gtbp_meet_ce_nonce')) {
            delete_option(self::OPT_LAST_ERROR);
            wp_safe_redirect($this->settings_url());
            exit;
        }

        // تست - ابتدا فیلدهای فعلی را ذخیره می‌کنیم بعد تست
        if (isset($_POST['gtbp_meet_test']) && check_admin_referer('gtbp_meet_save', 'gtbp_meet_nonce')) {
            $this->save_settings_from_post();
            delete_option(self::OPT_LAST_ERROR);
            $calendar_id = get_option(self::OPT_CALENDAR_ID, 'primary');
            $url = 'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendar_id) . '/events?maxResults=1';
            $result = $this->api_get($url);
            $ok = ($result !== null);
            wp_safe_redirect($this->settings_url(array('msg' => $ok ? 'meet_test_ok' : 'meet_test_err')));
            exit;
        }

        // ساخت جلسه تستی - برای تشخیص مشکل ساخت لینک Meet
        if (isset($_POST['gtbp_meet_test_create']) && check_admin_referer('gtbp_meet_save', 'gtbp_meet_nonce')) {
            $this->save_settings_from_post();
            delete_option(self::OPT_LAST_ERROR);
            $this->run_diagnostic_create();
            exit;
        }
    }

    /**
     * ساخت ایونت تستی + چاپ Response کامل برای تشخیص مشکل.
     */
    private function run_diagnostic_create() {
        if (!$this->is_configured()) {
            wp_die('<h2>❌ تشخیص</h2><p>Provider پیکربندی نشده. Client ID / Secret / Refresh token را بررسی کنید.</p><p><a href="' . esc_url($this->settings_url()) . '">بازگشت</a></p>');
        }

        $tz = get_option(self::OPT_TIMEZONE, 'Asia/Tehran');
        $tomorrow = date('Y-m-d', strtotime('+1 day'));
        $body = [
            'summary'     => 'GTBP Test Meeting - ' . date('Y-m-d H:i'),
            'description' => 'ایونت تست ساخته‌شده توسط افزونه برای تشخیص Meet integration.',
            'start'       => ['dateTime' => "{$tomorrow}T10:00:00", 'timeZone' => $tz],
            'end'         => ['dateTime' => "{$tomorrow}T11:00:00", 'timeZone' => $tz],
            'conferenceData' => [
                'createRequest' => [
                    'requestId'             => 'gtbp-test-' . wp_generate_password(12, false),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ],
        ];
        $calendar_id = get_option(self::OPT_CALENDAR_ID, 'primary');
        $url = 'https://www.googleapis.com/calendar/v3/calendars/'
             . rawurlencode($calendar_id) . '/events?conferenceDataVersion=1&sendUpdates=none';

        // درخواست خام برای دیدن راهنمای کامل
        $token = $this->get_access_token();
        $token_msg = $token ? '✅ Access token دریافت شد (طول ' . strlen($token) . ' کاراکتر)' : '❌ Access token دریافت نشد - رفرش توکن مشکل دارد. آخرین خطا را ببینید.';

        $resp = wp_remote_post($url, [
            'timeout' => 30,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
        ]);
        $code = is_wp_error($resp) ? 0 : wp_remote_retrieve_response_code($resp);
        $body_raw = is_wp_error($resp) ? $resp->get_error_message() : wp_remote_retrieve_body($resp);
        $data = json_decode($body_raw, true);

        $meet_link = '';
        if (is_array($data)) {
            if (!empty($data['hangoutLink'])) $meet_link = $data['hangoutLink'];
            elseif (!empty($data['conferenceData']['entryPoints'])) {
                foreach ($data['conferenceData']['entryPoints'] as $ep) {
                    if (($ep['entryPointType'] ?? '') === 'video' && !empty($ep['uri'])) { $meet_link = $ep['uri']; break; }
                }
            }
        }

        $ok = ($code === 200 || $code === 201) && $meet_link !== '';
        $back = esc_url($this->settings_url());

        echo '<!DOCTYPE html><html dir="rtl"><head><meta charset="utf-8"><title>تشخیص Meet</title>';
        echo '<style>body{font-family:Tahoma,sans-serif;background:#f0f0f1;padding:24px;max-width:900px;margin:0 auto;}';
        echo 'h1{color:' . ($ok ? '#166534' : '#991b1b') . ';}';
        echo 'pre{background:#111;color:#0f0;padding:14px;border-radius:6px;direction:ltr;text-align:left;white-space:pre-wrap;word-break:break-all;font-size:12px;line-height:1.6;max-height:400px;overflow:auto;}';
        echo '.card{background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px 20px;margin-bottom:14px;}';
        echo '.k{color:#555;font-size:12px;}.v{font-weight:bold;direction:ltr;text-align:right;}';
        echo 'a.btn{background:#2271b1;color:#fff;padding:8px 14px;border-radius:4px;text-decoration:none;}</style></head><body>';
        echo '<h1>' . ($ok ? '✅ ساخت لینک Meet موفق بود!' : '❌ ساخت لینک Meet ناموفق بود') . '</h1>';

        echo '<div class="card"><h3>مرحله ۱ - Access Token</h3><p>' . esc_html($token_msg) . '</p></div>';

        echo '<div class="card"><h3>مرحله ۲ - درخواست ارسالی</h3>';
        echo '<p><span class="k">Endpoint:</span> <code>' . esc_html($url) . '</code></p>';
        echo '<pre>' . esc_html(wp_json_encode($body, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre></div>';

        echo '<div class="card"><h3>مرحله ۳ - پاسخ Google</h3>';
        echo '<p><span class="k">HTTP Status:</span> <span class="v">' . esc_html($code) . '</span></p>';
        echo '<pre>' . esc_html(mb_substr($body_raw, 0, 4000)) . '</pre></div>';

        if ($meet_link) {
            echo '<div class="card" style="border-color:#166534;background:#dcfce7;"><h3>🎥 لینک Meet ساخته‌شده</h3>';
            echo '<p><a href="' . esc_url($meet_link) . '" target="_blank" style="font-size:18px;direction:ltr;display:inline-block;">' . esc_html($meet_link) . '</a></p>';
            echo '<p style="color:#166534;">ایونت با ID <code>' . esc_html($data['id'] ?? '') . '</code> در تقویم گوگل شما ساخته شد. می‌توانید در Google Calendar بررسی و حذفش کنید.</p></div>';
        } else {
            echo '<div class="card" style="border-color:#991b1b;background:#fee2e2;"><h3>⚠️ چرا لینک Meet نیامد؟</h3>';
            echo '<ul style="line-height:2;">';
            if ($code === 401) echo '<li>HTTP 401 → توکن نامعتبر است. یک بار «قطع اتصال» بزنید و دوباره وصل شوید.</li>';
            if ($code === 403) echo '<li>HTTP 403 → scope کافی نیست یا Calendar API فعال نشده. در Google Cloud Console چک کنید.</li>';
            if ($code === 400) echo '<li>HTTP 400 → درخواست مشکل دارد. متن پاسخ بالا را بخوانید.</li>';
            if ($code === 200 || $code === 201) echo '<li>ایونت ساخته شد ولی <code>hangoutLink</code> در پاسخ نبود. برای حساب‌های شخصی Gmail این گاهی رخ می‌دهد. راه‌حل: از یک حساب Google Workspace استفاده کنید یا اجازه ساخت جلسات Meet را در Google Calendar دستی چک کنید.</li>';
            echo '</ul></div>';
        }

        echo '<p><a href="' . $back . '" class="btn">بازگشت به تنظیمات</a></p>';
        echo '</body></html>';
    }

    private function save_settings_from_post() {
        update_option(self::OPT_ENABLED,           isset($_POST['meet_enabled']) ? '1' : '0');
        update_option(self::OPT_AUTO_CREATE,       isset($_POST['meet_auto_create']) ? '1' : '0');
        if (isset($_POST['meet_client_id']))         update_option(self::OPT_CLIENT_ID,         sanitize_text_field(wp_unslash($_POST['meet_client_id'])));
        if (isset($_POST['meet_client_secret']))     update_option(self::OPT_CLIENT_SECRET,     sanitize_text_field(wp_unslash($_POST['meet_client_secret'])));
        if (isset($_POST['meet_calendar_id']))       update_option(self::OPT_CALENDAR_ID,       sanitize_text_field(wp_unslash($_POST['meet_calendar_id'])) ?: 'primary');
        if (isset($_POST['meet_timezone']))          update_option(self::OPT_TIMEZONE,          sanitize_text_field(wp_unslash($_POST['meet_timezone'])) ?: 'Asia/Tehran');
        if (isset($_POST['meet_recordings_folder'])) update_option(self::OPT_RECORDINGS_FOLDER, sanitize_text_field(wp_unslash($_POST['meet_recordings_folder'])));
    }

    /* ==========================================================================
       OAUTH 2.0 FLOW
       ========================================================================== */

    private function redirect_uri() {
        return admin_url('admin.php?page=gtbp_meet&gtbp_meet_oauth=callback');
    }

    private function build_auth_url() {
        $params = [
            'client_id'              => get_option(self::OPT_CLIENT_ID),
            'redirect_uri'           => $this->redirect_uri(),
            'response_type'          => 'code',
            'scope'                  => self::SCOPES,
            'access_type'            => 'offline',
            // Always show the account chooser so a previously authorised
            // Google account cannot be selected silently.
            'prompt'                 => 'select_account consent',
            'include_granted_scopes' => 'true',
            'state'                  => wp_create_nonce('gtbp_meet_oauth_state'),
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    public function handle_oauth_callback() {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        $tab  = isset($_GET['tab']) ? sanitize_key(wp_unslash($_GET['tab'])) : '';
        if ($page !== 'gtbp_meet' && !($page === 'gtbp_online_class' && $tab === 'meet')) return;
        if (empty($_GET['gtbp_meet_oauth']) || $_GET['gtbp_meet_oauth'] !== 'callback') return;
        if (!current_user_can('manage_options')) return;
        if (empty($_GET['code'])) return;
        if (empty($_GET['state']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['state'])), 'gtbp_meet_oauth_state')) {
            $this->save_error('OAuth state validation failed. Please try connecting again.');
            wp_safe_redirect($this->settings_url(array('msg' => 'meet_auth_err')));
            exit;
        }
        $code = sanitize_text_field(wp_unslash($_GET['code']));
        $resp = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 25,
            'body'    => [
                'code'          => $code,
                'client_id'     => get_option(self::OPT_CLIENT_ID),
                'client_secret' => get_option(self::OPT_CLIENT_SECRET),
                'redirect_uri'  => $this->redirect_uri(),
                'grant_type'    => 'authorization_code',
            ],
        ]);
        if (is_wp_error($resp)) {
            $this->save_error('Token exchange WP_Error: ' . $resp->get_error_message());
            wp_safe_redirect($this->settings_url(array('msg' => 'meet_auth_err')));
            exit;
        }
        $code_resp = wp_remote_retrieve_response_code($resp);
        $body_raw  = wp_remote_retrieve_body($resp);
        if ($code_resp !== 200) {
            $this->save_error("Token exchange HTTP {$code_resp}: {$body_raw}");
            wp_safe_redirect($this->settings_url(array('msg' => 'meet_auth_err')));
            exit;
        }
        $data = json_decode($body_raw, true);
        if (empty($data['refresh_token'])) {
            $this->save_error('No refresh_token in Google response. Try disconnecting first, then reconnect. Response: ' . $body_raw);
            wp_safe_redirect($this->settings_url(array('msg' => 'meet_auth_err')));
            exit;
        }
        update_option(self::OPT_REFRESH_TOKEN, $data['refresh_token'], false);
        if (!empty($data['access_token'])) {
            update_option(self::OPT_ACCESS_TOKEN, $data['access_token'], false);
            update_option(self::OPT_ACCESS_EXPIRES, time() + intval($data['expires_in'] ?? 3500), false);

            // بخوان ایمیل حساب
            $userinfo = wp_remote_get('https://www.googleapis.com/oauth2/v2/userinfo', [
                'timeout' => 15,
                'headers' => ['Authorization' => 'Bearer ' . $data['access_token']],
            ]);
            if (!is_wp_error($userinfo) && wp_remote_retrieve_response_code($userinfo) === 200) {
                $ui = json_decode(wp_remote_retrieve_body($userinfo), true);
                if (!empty($ui['email'])) update_option(self::OPT_ACCOUNT_EMAIL, sanitize_email($ui['email']));
            }
        }
        delete_option(self::OPT_LAST_ERROR);
        wp_safe_redirect($this->settings_url(array('msg' => 'meet_auth_ok')));
        exit;
    }

    private function get_access_token() {
        $token   = get_option(self::OPT_ACCESS_TOKEN, '');
        $expires = intval(get_option(self::OPT_ACCESS_EXPIRES, 0));
        if ($token && $expires > (time() + 30)) return $token;

        $refresh = get_option(self::OPT_REFRESH_TOKEN, '');
        if (!$refresh) return '';

        $resp = wp_remote_post('https://oauth2.googleapis.com/token', [
            'timeout' => 25,
            'body'    => [
                'client_id'     => get_option(self::OPT_CLIENT_ID),
                'client_secret' => get_option(self::OPT_CLIENT_SECRET),
                'refresh_token' => $refresh,
                'grant_type'    => 'refresh_token',
            ],
        ]);
        if (is_wp_error($resp)) {
            $this->save_error('Refresh token WP_Error: ' . $resp->get_error_message());
            return '';
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body_raw = wp_remote_retrieve_body($resp);
        if ($code !== 200) {
            $this->save_error("Refresh token HTTP {$code}: {$body_raw}");
            return '';
        }
        $data = json_decode($body_raw, true);
        if (empty($data['access_token'])) {
            $this->save_error('No access_token in refresh response: ' . $body_raw);
            return '';
        }
        update_option(self::OPT_ACCESS_TOKEN, $data['access_token'], false);
        update_option(self::OPT_ACCESS_EXPIRES, time() + intval($data['expires_in'] ?? 3500), false);
        return $data['access_token'];
    }

    private function api_get($url) {
        $token = $this->get_access_token();
        if (!$token) return null;
        $resp = wp_remote_get($url, [
            'timeout' => 25,
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        if (is_wp_error($resp)) {
            $this->save_error("GET {$url} :: WP_Error :: " . $resp->get_error_message());
            return null;
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        if ($code !== 200) {
            $this->save_error("GET {$url} :: HTTP {$code} :: " . mb_substr($body, 0, 500));
            return null;
        }
        return json_decode($body, true);
    }

    private function api_post($url, $body) {
        $token = $this->get_access_token();
        if (!$token) return null;
        $resp = wp_remote_post($url, [
            'timeout' => 25,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode($body),
        ]);
        if (is_wp_error($resp)) {
            $this->save_error("POST {$url} :: WP_Error :: " . $resp->get_error_message());
            return null;
        }
        $code = wp_remote_retrieve_response_code($resp);
        $body_raw = wp_remote_retrieve_body($resp);
        if ($code !== 200 && $code !== 201) {
            $this->save_error("POST {$url} :: HTTP {$code} :: " . mb_substr($body_raw, 0, 500));
            return null;
        }
        return json_decode($body_raw, true);
    }

    private function api_delete($url) {
        $token = $this->get_access_token();
        if (!$token) return false;
        $resp = wp_remote_request($url, [
            'method'  => 'DELETE',
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $token],
        ]);
        if (is_wp_error($resp)) return false;
        $code = wp_remote_retrieve_response_code($resp);
        return ($code === 200 || $code === 204 || $code === 410);
    }

    /* ==========================================================================
       CREATE MEETING (Calendar Event with Meet)
       ========================================================================== */

    public function create_meeting_for_booking($args) {
        if (!$this->is_configured()) return false;

        $date = isset($args['date']) ? $args['date'] : '';
        $time = isset($args['time']) ? $args['time'] : '';
        $parts = explode('-', $time);
        $start = trim($parts[0] ?? '');
        $end   = trim($parts[1] ?? '');
        if (!$date || !$start || !$end) return false;

        // بازه‌هایی مثل 23:00-00:00 از نیمه‌شب عبور می‌کنند. قبلاً تاریخ
        // پایان همان تاریخ شروع ارسال می‌شد و Google Calendar با خطای
        // timeRangeEmpty درخواست را رد می‌کرد.
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
            || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $start)
            || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $end)) {
            $this->save_error('فرمت تاریخ/زمان رزرو نامعتبر است: date=' . $date . ', time=' . $time);
            return false;
        }
        $end_date = $date;
        if ($end <= $start) {
            $next_day = strtotime($date . ' +1 day');
            if ($next_day === false) {
                $this->save_error('محاسبه تاریخ پایان جلسه ناموفق بود: date=' . $date . ', time=' . $time);
                return false;
            }
            $end_date = date('Y-m-d', $next_day);
        }

        // اگر ایونت قبلی هست، حذفش کن تا انباشت نشود
        if (!empty($args['booking_id'])) {
            global $wpdb;
            $old_event_id = $wpdb->get_var($wpdb->prepare(
                "SELECT meet_calendar_event_id FROM {$this->bookings_table} WHERE id=%d",
                intval($args['booking_id'])
            ));
            if ($old_event_id) {
                $cal_id = get_option(self::OPT_CALENDAR_ID, 'primary');
                $this->api_delete('https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal_id) . '/events/' . rawurlencode($old_event_id));
            }
        }

        $tz = get_option(self::OPT_TIMEZONE, 'Asia/Tehran');
        $title = isset($args['title']) ? $args['title'] : 'کلاس آنلاین';

        $body = [
            'summary'     => $title,
            'description' => 'کلاس ساخته‌شده توسط افزونه رزرو کلاس',
            'start'       => ['dateTime' => "{$date}T{$start}:00", 'timeZone' => $tz],
            'end'         => ['dateTime' => "{$end_date}T{$end}:00", 'timeZone' => $tz],
            'conferenceData' => [
                'createRequest' => [
                    'requestId'             => 'gtbp-' . wp_generate_password(12, false),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ],
        ];
        if (!empty($args['attendee_email'])) {
            $body['attendees'] = [['email' => sanitize_email($args['attendee_email'])]];
        }

        $calendar_id = get_option(self::OPT_CALENDAR_ID, 'primary');
        $url = 'https://www.googleapis.com/calendar/v3/calendars/'
             . rawurlencode($calendar_id)
             . '/events?conferenceDataVersion=1&sendUpdates=none';

        $event = $this->api_post($url, $body);
        if (!$event || empty($event['id'])) return false;

        $meet = '';
        if (!empty($event['hangoutLink'])) {
            $meet = $event['hangoutLink'];
        } elseif (!empty($event['conferenceData']['entryPoints'])) {
            foreach ($event['conferenceData']['entryPoints'] as $ep) {
                if (!empty($ep['uri']) && ($ep['entryPointType'] ?? '') === 'video') { $meet = $ep['uri']; break; }
            }
        }
        if (!$meet) return false;

        if (!empty($args['booking_id'])) {
            global $wpdb;
            $wpdb->update($this->bookings_table, [
                'meet_join_link'         => esc_url_raw($meet),
                'meet_calendar_event_id' => sanitize_text_field($event['id']),
                // ریست کردن ضبط برای جست‌وجوی مجدد
                'meet_recording_link'    => null,
                'meet_recording_checked' => null,
            ], ['id' => intval($args['booking_id'])]);
        }

        return ['join_link' => $meet, 'event_id' => $event['id']];
    }

    /* ==========================================================================
       DRIVE - یافتن ضبط‌های Meet
       ========================================================================== */

    // escape single quotes for Drive query language
    private function drive_escape($s) {
        return str_replace("'", "\\'", (string)$s);
    }

    /**
     * جست‌وجوی خودکار ضبط برای یک رزرو.
     * منطق: بازه تاریخ + تطبیق نام دانش‌آموز/عنوان کلاس.
     */
    /* ==========================================================================
       موتور تطبیق ضبط‌های Meet با جلسه (نسخه ۱۴.۱۶)
       ترتیب اعتماد:
         ۱) فایلی که خود گوگل به ایونت کلندر همان جلسه پیوست کرده  (قطعی)
         ۲) کد جلسه Meet داخل نام فایل                              (بسیار قوی)
         ۳) نام دانش‌آموز/عنوان ایونت داخل نام فایل + نزدیکی زمانی   (قوی)
         ۴) فقط نزدیکی زمانی به شروع کلاس                            (ضعیف)
       ========================================================================== */

    /** کد جلسه از لینک Meet، مثل abc-defg-hij */
    public function meet_code_from_link($link) {
        if (!$link) return '';
        if (preg_match('#meet\.google\.com/([a-z]{3}-[a-z]{4}-[a-z]{3})#i', (string) $link, $m)) return strtolower($m[1]);
        if (preg_match('#meet\.google\.com/([a-z0-9\-_]{8,})#i', (string) $link, $m)) return strtolower($m[1]);
        return '';
    }

    /** بازه واقعی کلاس بر اساس تاریخ/ساعت رزرو و منطقه زمانی تنظیم‌شده */
    public function booking_window($booking) {
        $date = (string) ($booking->booking_date ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return [0, 0];
        $time  = (string) ($booking->booking_time ?? '');
        $parts = array_map('trim', explode('-', $time));
        $start_hm = preg_match('/^\d{1,2}:\d{2}$/', $parts[0] ?? '') ? $parts[0] : '00:00';
        $end_hm   = preg_match('/^\d{1,2}:\d{2}$/', $parts[1] ?? '') ? $parts[1] : '';
        $tz_name  = get_option(self::OPT_TIMEZONE, 'Asia/Tehran');
        try { $tz = new DateTimeZone($tz_name); } catch (Exception $e) { $tz = new DateTimeZone('UTC'); }
        try {
            $start = new DateTime($date . ' ' . $start_hm . ':00', $tz);
        } catch (Exception $e) { return [0, 0]; }
        $start_ts = $start->getTimestamp();
        if ($end_hm === '') return [$start_ts, $start_ts + 3600];
        try {
            $end = new DateTime($date . ' ' . $end_hm . ':00', $tz);
        } catch (Exception $e) { return [$start_ts, $start_ts + 3600]; }
        $end_ts = $end->getTimestamp();
        if ($end_ts <= $start_ts) $end_ts += 86400; // کلاسی که از نیمه‌شب رد می‌شود
        return [$start_ts, $end_ts];
    }

    /** ایونت کلندر جلسه همراه با پیوست‌هایی که گوگل بعد از ضبط اضافه می‌کند */
    public function calendar_event_for_booking($booking) {
        $event_id = (string) ($booking->meet_calendar_event_id ?? '');
        if ($event_id === '') return null;
        $cal_id = get_option(self::OPT_CALENDAR_ID, 'primary');
        return $this->api_get('https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($cal_id)
            . '/events/' . rawurlencode($event_id) . '?fields=id,summary,description,start,end,hangoutLink,attachments');
    }

    /**
     * فایل‌های ویدئویی Drive در یک بازه زمانی.
     * بر اساس createdTime جست‌وجو می‌شود چون زمان ساخته‌شدن ضبط ثابت است،
     * ولی modifiedTime با هر تغییر دسترسی جابه‌جا می‌شود.
     */
    public function list_drive_recordings($after_ts, $before_ts, $limit = 100) {
        $q  = "(mimeType contains 'video/' or mimeType = 'application/vnd.google-apps.video') and trashed = false";
        if ($after_ts)  $q .= " and createdTime > '" . gmdate('Y-m-d\TH:i:s\Z', (int) $after_ts) . "'";
        if ($before_ts) $q .= " and createdTime < '" . gmdate('Y-m-d\TH:i:s\Z', (int) $before_ts) . "'";
        $folder = trim((string) get_option(self::OPT_RECORDINGS_FOLDER, ''));
        if ($folder !== '') $q .= " and '" . $this->drive_escape($folder) . "' in parents";

        $files = [];
        $page  = '';
        do {
            $args = [
                'q'                         => $q,
                'fields'                    => 'nextPageToken,files(id,name,webViewLink,createdTime,modifiedTime,size,videoMediaMetadata(durationMillis),parents,owners(emailAddress))',
                'pageSize'                  => 100,
                'orderBy'                   => 'createdTime desc',
                'supportsAllDrives'         => 'true',
                'includeItemsFromAllDrives' => 'true',
            ];
            if ($page !== '') $args['pageToken'] = $page;
            $data = $this->api_get('https://www.googleapis.com/drive/v3/files?' . http_build_query($args));
            if (!is_array($data)) break;
            foreach ((array) ($data['files'] ?? []) as $file) $files[] = $file;
            $page = (string) ($data['nextPageToken'] ?? '');
        } while ($page !== '' && count($files) < $limit);

        return array_slice($files, 0, $limit);
    }

    /** نرمال‌سازی متن برای مقایسه نام‌ها (حذف نویسه‌های عربی/فارسی متفاوت و فاصله‌ها) */
    private function normalize_text($text) {
        $text = (string) $text;
        $text = str_replace(['ي', 'ك', 'ۀ', 'ة', 'أ', 'إ', 'آ', 'ـ', '‌'], ['ی', 'ک', 'ه', 'ه', 'ا', 'ا', 'ا', '', ' '], $text);
        $text = preg_replace('/[\p{P}\p{S}]+/u', ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text);
        return trim(function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text));
    }

    /**
     * امتیازدهی یک فایل Drive برای یک جلسه مشخص.
     * خروجی: ['score' => int, 'reasons' => string[]]
     */
    public function score_recording_candidate($file, $booking, $context = []) {
        $score   = 0;
        $reasons = [];

        $name  = $this->normalize_text($file['name'] ?? '');
        $raw   = (string) ($file['name'] ?? '');
        $created = !empty($file['createdTime']) ? strtotime($file['createdTime']) : 0;

        list($start_ts, $end_ts) = isset($context['window']) ? $context['window'] : $this->booking_window($booking);

        // ۱) کد جلسه Meet داخل نام فایل
        $code = (string) ($context['meet_code'] ?? '');
        if ($code !== '' && stripos($raw, $code) !== false) {
            $score += 60;
            $reasons[] = 'کد جلسه Meet در نام فایل';
        }

        // ۲) تطبیق نام‌ها
        $needles = array_filter([
            'عنوان ایونت'      => $this->normalize_text($context['event_summary'] ?? ''),
            'نام و نام خانوادگی' => $this->normalize_text(trim(($booking->first_name ?? '') . ' ' . ($booking->last_name ?? ''))),
            'نام خانوادگی'     => $this->normalize_text($booking->last_name ?? ''),
            'نام کلاس'         => $this->normalize_text($booking->class_name ?? ''),
        ], 'strlen');
        $weights = ['عنوان ایونت' => 45, 'نام و نام خانوادگی' => 40, 'نام خانوادگی' => 18, 'نام کلاس' => 12];
        $name_hit = false;
        foreach ($needles as $label => $needle) {
            if ($needle !== '' && mb_strlen($needle) >= 2 && mb_strpos($name, $needle) !== false) {
                $score += $weights[$label] ?? 10;
                $reasons[] = $label . ' در نام فایل';
                $name_hit = true;
            }
        }

        // ۳) نزدیکی زمانی: ضبط بین شروع کلاس و چند ساعت بعد از پایان ساخته می‌شود
        if ($created && $start_ts) {
            $delta_start = $created - $start_ts;
            if ($delta_start >= -1800 && $created <= $end_ts + 6 * 3600) {
                $score += 35;
                $reasons[] = 'زمان ساخت فایل داخل بازه همان کلاس';
            } elseif ($created >= $start_ts - 3600 && $created <= $end_ts + 24 * 3600) {
                $score += 18;
                $reasons[] = 'زمان ساخت فایل نزدیک همان کلاس';
            } elseif (abs($delta_start) <= 3 * 86400) {
                $score += 4;
                $reasons[] = 'فایل در همان چند روز ساخته شده';
            } else {
                $score -= 25;
                $reasons[] = 'فاصله زمانی زیاد با کلاس';
            }
        }

        // ۴) طول ویدئو نزدیک به طول کلاس
        $duration_ms = isset($file['videoMediaMetadata']['durationMillis']) ? (int) $file['videoMediaMetadata']['durationMillis'] : 0;
        if ($duration_ms > 0 && $start_ts && $end_ts > $start_ts) {
            $class_seconds = $end_ts - $start_ts;
            $ratio = ($duration_ms / 1000) / max(1, $class_seconds);
            if ($ratio >= 0.4 && $ratio <= 1.4) {
                $score += 12;
                $reasons[] = 'طول ویدئو با طول کلاس هم‌خوان است';
            }
        }

        // تاریخ کلاس به شکل متنی در نام فایل (الگوی خود گوگل میت)
        if (!empty($booking->booking_date) && strpos($raw, (string) $booking->booking_date) !== false) {
            $score += 15;
            $reasons[] = 'تاریخ کلاس در نام فایل';
            $name_hit = true;
        }

        return ['score' => $score, 'reasons' => $reasons, 'name_hit' => $name_hit];
    }

    /**
     * پیدا کردن بهترین گزینه‌ها برای یک جلسه.
     * خروجی: ['attachment' => ?file, 'candidates' => [['file'=>..,'score'=>..,'reasons'=>..], ...]]
     */
    public function match_recordings_for_booking($booking, $limit = 8) {
        $result = ['attachment' => null, 'candidates' => [], 'event_summary' => '', 'meet_code' => ''];
        if (!$this->is_configured() || empty($booking->booking_date)) return $result;

        $window    = $this->booking_window($booking);
        $meet_code = $this->meet_code_from_link($booking->meet_join_link ?? '');
        if ($meet_code === '') $meet_code = $this->meet_code_from_link($booking->roomeet_join_link ?? '');

        // سیگنال قطعی: پیوست ضبط روی ایونت کلندر همان جلسه
        $event = $this->calendar_event_for_booking($booking);
        $event_summary = '';
        if (is_array($event)) {
            $event_summary = trim((string) ($event['summary'] ?? ''));
            if ($meet_code === '') $meet_code = $this->meet_code_from_link($event['hangoutLink'] ?? '');
            foreach ((array) ($event['attachments'] ?? []) as $attachment) {
                $file_id = (string) ($attachment['fileId'] ?? '');
                $mime    = (string) ($attachment['mimeType'] ?? '');
                if ($file_id === '') continue;
                if ($mime !== '' && stripos($mime, 'video') === false) continue;
                $result['attachment'] = [
                    'id'          => $file_id,
                    'name'        => (string) ($attachment['title'] ?? 'Google Meet recording'),
                    'webViewLink' => (string) ($attachment['fileUrl'] ?? ('https://drive.google.com/file/d/' . $file_id . '/view')),
                    'createdTime' => '',
                ];
                break;
            }
        }
        $result['event_summary'] = $event_summary;
        $result['meet_code']     = $meet_code;
        if ($result['attachment']) return $result;

        list($start_ts, $end_ts) = $window;
        $base = $start_ts ?: strtotime($booking->booking_date . ' 00:00:00');
        $files = $this->list_drive_recordings($base - 2 * 86400, ($end_ts ?: $base) + 5 * 86400, 120);
        if (!$files) return $result;

        $context  = ['window' => $window, 'meet_code' => $meet_code, 'event_summary' => $event_summary];
        $scored = [];
        foreach ($files as $file) {
            $s = $this->score_recording_candidate($file, $booking, $context);
            $scored[] = ['file' => $file, 'score' => $s['score'], 'reasons' => $s['reasons'], 'name_hit' => $s['name_hit']];
        }
        usort($scored, function ($a, $b) { return $b['score'] <=> $a['score']; });
        $result['candidates'] = array_slice($scored, 0, $limit);
        return $result;
    }

    /** حداقل امتیاز برای اختصاص خودکار بدون تایید انسان */
    const AUTO_ASSIGN_MIN_SCORE = 70;

    /**
     * ذخیره یک فایل Drive روی رزرو + عمومی کردن + همگام‌سازی با پنل زبان‌آموز.
     * $overwrite=false یعنی لینک دستی قبلی بازنویسی نمی‌شود.
     */
    public function assign_recording_to_booking($booking_id, $file_id, $web_link = '', $overwrite = true) {
        global $wpdb;
        $booking_id = intval($booking_id);
        $file_id    = sanitize_text_field($file_id);
        if (!$booking_id || $file_id === '') return false;

        if ($web_link === '') $web_link = 'https://drive.google.com/file/d/' . $file_id . '/view';
        $existing = $wpdb->get_var($wpdb->prepare("SELECT meet_recording_link FROM {$this->bookings_table} WHERE id=%d", $booking_id));
        if (!$overwrite && $existing) return true;

        $this->make_drive_file_public($file_id);
        $wpdb->update($this->bookings_table, [
            'meet_recording_link'    => esc_url_raw($web_link),
            'meet_recording_checked' => current_time('mysql'),
        ], ['id' => $booking_id]);
        $this->sync_learning_meet_video($booking_id, $web_link);
        return true;
    }

    public function find_recording_for_booking($booking) {
        if (!$this->is_configured()) return '';
        if (empty($booking->booking_date)) return '';

        $match = $this->match_recordings_for_booking($booking, 5);

        // ۱) اگر خود گوگل ضبط را به ایونت همان جلسه پیوست کرده، قطعی است.
        if (!empty($match['attachment'])) {
            $file = $match['attachment'];
            $this->make_drive_file_public($file['id']);
            return $file['webViewLink'];
        }

        if (empty($match['candidates'])) return '';
        $best = $match['candidates'][0];

        // ۲) فقط وقتی به‌صورت خودکار ثبت می‌کنیم که امتیاز کافی باشد و گزینه دوم
        //    به‌روشنی ضعیف‌تر باشد؛ در غیر این صورت تصمیم با مدیر است.
        $second = isset($match['candidates'][1]) ? (int) $match['candidates'][1]['score'] : -999;
        if ($best['score'] < self::AUTO_ASSIGN_MIN_SCORE || ($best['score'] - $second) < 15) {
            $this->save_error(sprintf(
                'ضبط Meet برای رزرو %d به‌صورت خودکار ثبت نشد (بهترین امتیاز %d، گزینه بعدی %d). از صفحه «جستجوی ویدئوها» بخش گوگل میت، گزینه درست را دستی انتخاب کنید.',
                intval($booking->id ?? 0), (int) $best['score'], $second
            ));
            return '';
        }

        $this->make_drive_file_public($best['file']['id']);
        return (string) $best['file']['webViewLink'];
    }

    /**
     * جستجوی دستی با Meet ID (مثل api-fiqf-vdh):
     *  1) Calendar را با q=meet_url جست‌وجو می‌کنیم تا ایونت متناظر پیدا شود
     *  2) از عنوان و زمان ایونت برای پیدا کردن فایل Drive استفاده می‌کنیم
     *  3) اگر ایونت پیدا نشد، فایل‌های ۳ روز اطراف تاریخ رزرو را می‌گردیم
     */
    public function find_recording_by_meet_id($booking, $meet_id) {
        if (!$this->is_configured()) return '';
        $meet_id = trim(sanitize_text_field($meet_id));
        if ($meet_id === '') return '';

        $event_summary = '';
        $event_start   = 0;

        // مرحله ۱: پیدا کردن ایونت با Meet ID
        $calendar_id = get_option(self::OPT_CALENDAR_ID, 'primary');
        $search_q    = 'meet.google.com/' . $meet_id;
        $ev_list = $this->api_get('https://www.googleapis.com/calendar/v3/calendars/'
            . rawurlencode($calendar_id)
            . '/events?' . http_build_query([
                'q'          => $search_q,
                'maxResults' => 5,
                'orderBy'    => 'startTime',
                'singleEvents' => 'true',
                'timeMin'    => gmdate('Y-m-d\TH:i:s\Z', strtotime('-90 days')),
            ]));
        if (!empty($ev_list['items'][0])) {
            $ev = $ev_list['items'][0];
            $event_summary = trim((string)($ev['summary'] ?? ''));
            if (!empty($ev['start']['dateTime'])) $event_start = strtotime($ev['start']['dateTime']);
        }

        // مرحله ۲: جست‌وجوی Drive
        $folder = get_option(self::OPT_RECORDINGS_FOLDER, '');
        $file = null;

        if ($event_summary !== '') {
            // با نام ایونت جست‌وجو کن
            $q = "mimeType contains 'video/' and trashed=false and name contains '" . $this->drive_escape($event_summary) . "'";
            if ($folder) $q .= " and '" . $this->drive_escape($folder) . "' in parents";
            $data = $this->api_get('https://www.googleapis.com/drive/v3/files?' . http_build_query([
                'q'                        => $q,
                'fields'                   => 'files(id,name,webViewLink,createdTime)',
                'pageSize'                 => 10,
                'orderBy'                  => 'createdTime desc',
                'supportsAllDrives'        => 'true',
                'includeItemsFromAllDrives'=> 'true',
            ]));
            $file = (!empty($data['files'])) ? $data['files'][0] : null;
        }

        // مرحله ۳: fallback با تاریخ رزرو
        if (!$file && !empty($booking->booking_date)) {
            $ref_ts = $event_start ? $event_start : strtotime($booking->booking_date . ' 00:00:00');
            $after_iso  = gmdate('Y-m-d\TH:i:s\Z', $ref_ts);
            $before_iso = gmdate('Y-m-d\TH:i:s\Z', $ref_ts + 3 * 86400);
            $q2 = "mimeType contains 'video/' and trashed=false and modifiedTime > '{$after_iso}' and modifiedTime < '{$before_iso}'";
            if ($folder) $q2 .= " and '" . $this->drive_escape($folder) . "' in parents";
            $data2 = $this->api_get('https://www.googleapis.com/drive/v3/files?' . http_build_query([
                'q'                        => $q2,
                'fields'                   => 'files(id,name,webViewLink,createdTime)',
                'pageSize'                 => 25,
                'orderBy'                  => 'createdTime',
                'supportsAllDrives'        => 'true',
                'includeItemsFromAllDrives'=> 'true',
            ]));
            $file = (!empty($data2['files'])) ? $data2['files'][0] : null;
        }

        if (!$file) return '';

        $this->make_drive_file_public($file['id']);
        $link = $file['webViewLink'];

        global $wpdb;
        $wpdb->update($this->bookings_table, [
            'meet_recording_link'    => esc_url_raw($link),
            'meet_recording_checked' => current_time('mysql'),
        ], ['id' => intval($booking->id)]);
        $this->sync_learning_meet_video($booking->id, $link);
        return $link;
    }

    /**
     * public کردن فایل Drive برای دسترسی هرکس با لینک.
     */
    /** دسترسی فقط-خواندنی برای صفحه بررسی سلامت (بدون تغییر داده). */
    public function health_access_token() { return $this->get_access_token(); }
    public function health_api_get($url)  { return $this->api_get($url); }

    public function make_drive_file_public($file_id) {
        $token = $this->get_access_token();
        if (!$token || !$file_id) return false;
        $url = 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id)
             . '/permissions?supportsAllDrives=true&sendNotificationEmail=false';
        $resp = wp_remote_post($url, [
            'timeout' => 20,
            'headers' => ['Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json'],
            'body'    => wp_json_encode(['role' => 'reader', 'type' => 'anyone']),
        ]);
        if (is_wp_error($resp)) {
            $this->save_error('Drive public WP_Error :: ' . $resp->get_error_message());
            return false;
        }
        $code = wp_remote_retrieve_response_code($resp);
        // 200/201 موفق، 204 بدون بدنه. کد 409 یعنی دسترسی از قبل وجود دارد.
        if (!in_array($code, [200, 201, 204, 409], true)) {
            $this->save_error('Drive public HTTP ' . $code . ' :: ' . mb_substr(wp_remote_retrieve_body($resp), 0, 500));
            // ادامه می‌دهیم تا با بررسی واقعی مشخص شود فایل عمومی هست یا نه.
        }
        return $this->drive_file_is_public($file_id);
    }

    /**
     * بررسی واقعی اینکه فایل با «هرکس با لینک» قابل مشاهده است.
     * اگر سیاست Google Workspace اشتراک عمومی را ببندد، همین‌جا مشخص می‌شود و
     * مدیر به‌جای «دسترسی رد شد» برای دانش‌آموز، پیام روشن می‌بیند.
     */
    public function drive_file_is_public($file_id) {
        if (!$file_id) return false;
        $data = $this->api_get('https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id)
              . '?fields=id,name,permissions(id,type,role)&supportsAllDrives=true');
        if (empty($data['permissions']) || !is_array($data['permissions'])) return false;
        foreach ($data['permissions'] as $permission) {
            if (($permission['type'] ?? '') === 'anyone') return true;
        }
        $this->save_error('فایل Drive ' . $file_id . ' عمومی نشد. احتمالاً سیاست Google Workspace اشتراک «هرکس با لینک» را محدود کرده یا مالک فایل حساب دیگری است.');
        return false;
    }

    public function check_meet_recordings() {
        if (!$this->is_configured()) return;
        global $wpdb;
        $retry_after = date('Y-m-d H:i:s', strtotime('-2 hours'));
        // نسخه ۱۴.۱۶: قبلاً فقط رزروهایی دیده می‌شدند که ستون meet_join_link آن‌ها
        // پر بود. کلاس‌هایی که لینک Meet فقط در ستون مشترک roomeet_join_link ذخیره
        // شده بود (یا قبل از افزوده‌شدن ستون ساخته شده بودند) هرگز بررسی نمی‌شدند و
        // ضبط‌شان به هیچ جلسه‌ای اختصاص نمی‌یافت.
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->bookings_table}
             WHERE (
                   (meet_join_link IS NOT NULL AND meet_join_link<>'')
                OR (meet_calendar_event_id IS NOT NULL AND meet_calendar_event_id<>'')
                OR (class_provider = 'meet')
                OR (roomeet_join_link LIKE '%%meet.google.com%%')
             )
             AND (meet_recording_link IS NULL OR meet_recording_link='')
             AND (meet_recording_checked IS NULL OR meet_recording_checked < %s)
             AND booking_date <= CURDATE()
             ORDER BY booking_date DESC LIMIT 40",
            $retry_after
        ));
        foreach ($rows as $b) {
            $link = $this->find_recording_for_booking($b);
            $wpdb->update($this->bookings_table, [
                'meet_recording_checked' => current_time('mysql'),
                'meet_recording_link'    => $link ? esc_url_raw($link) : null,
            ], ['id' => intval($b->id)]);
            if ($link) $this->sync_learning_meet_video($b->id, $link);
        }
    }

    private function sync_learning_meet_video($booking_id, $link) {
        global $wpdb;
        $sessions = $wpdb->prefix . 'gls_sessions';
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $sessions)) !== $sessions) return;
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$sessions}");
        if (!in_array('meet_video_url', $cols, true)) return;

        $row = $wpdb->get_row($wpdb->prepare("SELECT id,meet_video_url,meet_video_url_source FROM {$sessions} WHERE booking_id=%d LIMIT 1", $booking_id));

        // اگر جلسه‌ای با شناسه رزرو پیدا نشد، با ایمیل و تاریخ همان رزرو تطبیق می‌دهیم.
        // بدون این مسیر، لینک روی رزرو ذخیره می‌شد ولی در پنل زبان‌آموز دیده نمی‌شد.
        if (!$row) {
            $booking = $wpdb->get_row($wpdb->prepare(
                "SELECT email, booking_date, booking_time FROM {$this->bookings_table} WHERE id=%d", intval($booking_id)));
            if ($booking && !empty($booking->email) && !empty($booking->booking_date)
                && in_array('student_email', $cols, true) && in_array('booking_date', $cols, true)) {
                $row = $wpdb->get_row($wpdb->prepare(
                    "SELECT id,meet_video_url,meet_video_url_source FROM {$sessions}
                     WHERE student_email=%s AND booking_date=%s ORDER BY id DESC LIMIT 1",
                    $booking->email, $booking->booking_date));
                // اگر جلسه پیدا شد ولی هنوز به رزرو وصل نیست، پیوندش را کامل می‌کنیم.
                if ($row && in_array('booking_id', $cols, true)) {
                    $wpdb->update($sessions, ['booking_id' => intval($booking_id)], ['id' => intval($row->id)]);
                }
            }
        }
        if (!$row) {
            $this->save_error('لینک ضبط روی رزرو ' . intval($booking_id) . ' ذخیره شد ولی جلسه آموزشی متناظری برای نمایش در پنل زبان‌آموز پیدا نشد. از بخش «جلسات» یک جلسه برای این رزرو بسازید.');
            return;
        }
        if ((string)$row->meet_video_url_source === 'manual' && trim((string)$row->meet_video_url) !== '') return;
        $wpdb->update($sessions, [
            'meet_video_url'        => esc_url_raw($link),
            'meet_video_url_source' => 'auto',
        ], ['id' => intval($row->id)]);
    }

    /* ==========================================================================
       PUBLIC: تبدیل لینک Drive به لینک دانلود مستقیم
       ========================================================================== */
    public static function drive_url_to_download($url) {
        $id = self::extract_drive_id($url);
        // confirm=t صفحه هشدار اسکن ویروس برای فایل‌های بزرگ را رد می‌کند.
        return $id ? ('https://drive.usercontent.google.com/download?id=' . $id . '&export=download&confirm=t') : '';
    }

    public static function drive_url_to_preview($url) {
        $id = self::extract_drive_id($url);
        return $id ? ('https://drive.google.com/file/d/' . $id . '/preview') : $url;
    }

    public static function extract_drive_id($url) {
        if (!$url) return '';
        if (preg_match('#drive\.google\.com/(?:file/d/|open\?id=|uc\?id=)([A-Za-z0-9_\-]{20,})#', $url, $m)) return $m[1];
        if (preg_match('#drive\.usercontent\.google\.com/download\?id=([A-Za-z0-9_\-]{20,})#', $url, $m)) return $m[1];
        if (preg_match('#[?&]id=([A-Za-z0-9_\-]{20,})#', $url, $m)) return $m[1];
        return '';
    }

    /* ==========================================================================
       SHORTCODE: نمایش یکپارچه همه ضبط‌ها
       ========================================================================== */

    public function render_all_recordings() {
        if (!is_user_logged_in()) {
            return '<p style="font-family:IRANSansXFaNum,Tahoma;color:#c00;padding:20px;">برای مشاهده ضبط کلاس‌ها لطفاً وارد سایت شوید.</p>';
        }
        $this->maybe_upgrade_schema();

        $user = wp_get_current_user();
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'gls_sessions';

        // اطمینان از وجود ستون‌ها قبل از SELECT
        $b_cols = $wpdb->get_col("SHOW COLUMNS FROM {$this->bookings_table}");
        $has_meet_col = in_array('meet_recording_link', $b_cols, true);
        $has_rmt_col  = in_array('rmt_recording_link', $b_cols, true);
        $has_rmt_dl   = in_array('rmt_recording_download', $b_cols, true);

        $select_meet = $has_meet_col ? 'b.meet_recording_link AS meet_link_b,' : "'' AS meet_link_b,";
        $select_rmt  = $has_rmt_col  ? 'b.rmt_recording_link AS rmt_link_b,'    : "'' AS rmt_link_b,";
        $select_rmtd = $has_rmt_dl   ? 'b.rmt_recording_download AS rmt_dl_b,'  : "'' AS rmt_dl_b,";

        $sessions_exists = ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $sessions_table)) === $sessions_table);
        if ($sessions_exists) {
            $s_cols = $wpdb->get_col("SHOW COLUMNS FROM {$sessions_table}");
            $sel_meet_video   = in_array('meet_video_url', $s_cols, true)        ? 's.meet_video_url AS meet_session_video,'          : "'' AS meet_session_video,";
            $sel_meet_source  = in_array('meet_video_url_source', $s_cols, true) ? 's.meet_video_url_source AS meet_session_source,'  : "'' AS meet_session_source,";
            $sel_rmt_video    = in_array('rmt_video_url', $s_cols, true)         ? 's.rmt_video_url AS rmt_session_video,'            : "'' AS rmt_session_video,";
            $sel_rmt_source   = in_array('rmt_video_url_source', $s_cols, true)  ? 's.rmt_video_url_source AS rmt_session_source,'    : "'' AS rmt_session_source,";
        } else {
            $s_cols = [];
            $sel_meet_video  = "'' AS meet_session_video,";
            $sel_meet_source = "'' AS meet_session_source,";
            $sel_rmt_video   = "'' AS rmt_session_video,";
            $sel_rmt_source  = "'' AS rmt_session_source,";
        }

        $join = $sessions_exists ? "LEFT JOIN {$sessions_table} s ON s.booking_id = b.id" : '';
        $bbb_video  = $sessions_exists ? "s.video_url AS bbb_session_video," : "'' AS bbb_session_video,";
        $bbb_source = $sessions_exists ? "s.video_url_source AS bbb_session_source" : "'' AS bbb_session_source";

        $user_clause = ($sessions_exists && in_array('user_id', (array) $s_cols, true)) ? 'OR s.user_id = %d' : '';
        $sql = "SELECT b.class_name, b.booking_date, b.booking_time,
                       b.roomeet_recording_link AS bbb_link,
                       {$select_meet}
                       {$select_rmt}
                       {$select_rmtd}
                       {$bbb_video}
                       {$sel_meet_video}
                       {$sel_meet_source}
                       {$sel_rmt_video}
                       {$sel_rmt_source}
                       {$bbb_source}
                FROM {$this->bookings_table} b
                {$join}
                WHERE b.email = %s {$user_clause}
                ORDER BY b.booking_date DESC, b.booking_time DESC";

        // اگر دانش‌آموز ایمیلش را عوض کرده باشد، ضبط‌ها از طریق جلسات ثبت‌شده
        // با شناسه کاربری هم پیدا می‌شوند و گم نمی‌شوند.
        $rows = ($sessions_exists && in_array('user_id', (array) $s_cols, true))
            ? $wpdb->get_results($wpdb->prepare($sql, $user->user_email, intval($user->ID)))
            : $wpdb->get_results($wpdb->prepare($sql, $user->user_email));

        $items = [];
        foreach ($rows as $r) {
            $bbb = ((isset($r->bbb_session_source) && $r->bbb_session_source === 'manual') && !empty($r->bbb_session_video))
                ? $r->bbb_session_video : ($r->bbb_link ?: ($r->bbb_session_video ?? ''));
            if ($bbb) $items[] = ['label' => 'BigBlueButton', 'url' => $bbb, 'download' => '', 'class' => $r->class_name, 'date' => $r->booking_date, 'time' => $r->booking_time];

            $meet = ((isset($r->meet_session_source) && $r->meet_session_source === 'manual') && !empty($r->meet_session_video))
                ? $r->meet_session_video : ($r->meet_link_b ?: ($r->meet_session_video ?? ''));
            if ($meet) $items[] = ['label' => 'Google Meet', 'url' => $meet, 'download' => '', 'class' => $r->class_name, 'date' => $r->booking_date, 'time' => $r->booking_time];

            $rmt = ((isset($r->rmt_session_source) && $r->rmt_session_source === 'manual') && !empty($r->rmt_session_video))
                ? $r->rmt_session_video : (($r->rmt_link_b ?? '') ?: ($r->rmt_session_video ?? ''));
            if ($rmt) $items[] = ['label' => 'روومیت', 'url' => $rmt, 'download' => ($r->rmt_dl_b ?? ''), 'class' => $r->class_name, 'date' => $r->booking_date, 'time' => $r->booking_time];
        }

        if (!$items) {
            return '<div style="font-family:IRANSansXFaNum,Tahoma;background:#f5f5f5;padding:20px;border-radius:8px;text-align:center;">📹 هنوز ضبطی برای کلاس‌های شما ثبت نشده است.</div>';
        }

        $out  = '<div style="font-family:IRANSansXFaNum,Tahoma;direction:rtl;max-width:820px;margin:0 auto;">';
        $out .= '<h3 style="color:#8B0000;margin-bottom:16px;">ضبط‌های کلاس‌های شما</h3>';
        foreach ($items as $it) {
            $badge_color = $it['label'] === 'Google Meet' ? '#34a853' : ($it['label'] === 'روومیت' ? '#1d4ed8' : '#8B0000');
            // اولویت دانلود: لینک دانلود مستقیم روومیت > تبدیل لینک درایو
            $download_url = !empty($it['download']) ? $it['download'] : self::drive_url_to_download($it['url']);
            $out .= '<div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:#fff;border:1px solid #eee;border-radius:8px;margin-bottom:8px;gap:12px;flex-wrap:wrap;">';
            $out .= '<div><strong>' . esc_html($it['class']) . '</strong><br><small>📅 ' . esc_html($it['date']) . ' | ⏰ ' . esc_html($it['time']) . '</small></div>';
            $out .= '<div style="display:flex;align-items:center;gap:8px;">';
            $out .= '<span style="background:' . $badge_color . ';color:#fff;padding:3px 8px;border-radius:12px;font-size:0.75rem;">' . esc_html($it['label']) . '</span>';
            if ($download_url) {
                $out .= '<a href="' . esc_url($download_url) . '" target="_blank" rel="noopener" style="background:#0f766e;color:#fff;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:0.85rem;">⬇️ دانلود</a>';
            }
            $out .= '<a href="' . esc_url($it['url']) . '" target="_blank" rel="noopener" style="background:#111;color:#fff;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:0.85rem;">▶ مشاهده</a>';
            $out .= '</div></div>';
        }
        $out .= '</div>';
        return $out;
    }

    /* ==========================================================================
       LOGGING
       ========================================================================== */
    private function save_error($msg) {
        update_option(self::OPT_LAST_ERROR, '[' . current_time('mysql') . '] ' . $msg, false);
        if (function_exists('error_log')) error_log('[GTBP_Meet] ' . $msg);
    }
}

endif; // class_exists guard
