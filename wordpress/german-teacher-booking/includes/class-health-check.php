<?php
if (!defined('ABSPATH')) exit;

/**
 * بررسی زندهٔ سلامت اتصال‌ها: گوگل (کلندر/درایو)، تلگرام، کرون‌ها، جدول‌ها و پنل.
 * هیچ داده‌ای را تغییر نمی‌دهد؛ فقط وضعیت واقعی را گزارش می‌کند.
 */
final class GTBP_Health_Check {
    const PAGE = 'gtbp_health_check';
    private static $instance;

    public static function instance() { return self::$instance ?: (self::$instance = new self()); }

    private function __construct() {
        add_action('admin_menu', array($this, 'menu'), 40);
    }

    public function menu() {
        add_submenu_page('gtbp_bookings', 'بررسی سلامت اتصال‌ها', 'سلامت اتصال‌ها', 'manage_options', self::PAGE, array($this, 'page'));
    }

    private function row($title, $state, $detail = '') {
        $colors = ['ok' => '#0f766e', 'warn' => '#b45309', 'fail' => '#b91c1c', 'skip' => '#64748b'];
        $labels = ['ok' => 'سالم', 'warn' => 'هشدار', 'fail' => 'خطا', 'skip' => 'غیرفعال'];
        $color = $colors[$state] ?? '#64748b';
        echo '<tr><td style="font-weight:700">' . esc_html($title) . '</td>';
        echo '<td><span style="background:' . $color . ';color:#fff;padding:3px 10px;border-radius:12px;font-size:.8rem">' . esc_html($labels[$state] ?? $state) . '</span></td>';
        echo '<td style="direction:ltr;text-align:left;font-family:monospace;font-size:.82rem;line-height:1.7">' . esc_html($detail) . '</td></tr>';
    }

    public function page() {
        if (!current_user_can('manage_options')) return;
        $run = isset($_POST['gtbp_run_health']) && check_admin_referer('gtbp_run_health');
        echo '<div class="wrap" dir="rtl"><h1>بررسی سلامت اتصال‌ها</h1>';
        echo '<p style="max-width:900px;line-height:2">این صفحه اتصال‌های واقعی را همین حالا آزمایش می‌کند: گوگل کلندر و درایو، عمومی‌شدن ویدئوها، ربات تلگرام، زمان‌بندهای خودکار و جدول‌های پایگاه داده. هیچ داده‌ای تغییر نمی‌کند.</p>';
        echo '<form method="post"><input type="hidden" name="gtbp_run_health" value="1">';
        wp_nonce_field('gtbp_run_health');
        echo '<p><button class="button button-primary button-hero">اجرای بررسی کامل</button></p></form>';
        if (!$run) { echo '</div>'; return; }

        echo '<table class="widefat striped" style="max-width:1100px;margin-top:18px"><thead><tr><th style="width:230px">مورد</th><th style="width:90px">وضعیت</th><th>جزئیات</th></tr></thead><tbody>';
        $this->check_google();
        $this->check_telegram();
        $this->check_cron();
        $this->check_database();
        $this->check_panel();
        echo '</tbody></table></div>';
    }

    /* ---------------- Google Meet / Drive ---------------- */
    private function check_google() {
        if (!class_exists('GTBP_Google_Meet_Provider')) { $this->row('گوگل میت', 'skip', 'provider class not loaded'); return; }
        $meet = GTBP_Google_Meet_Provider::instance();
        if (!$meet->is_enabled()) { $this->row('گوگل میت', 'skip', 'integration disabled in settings'); return; }
        if (!$meet->is_configured()) { $this->row('گوگل میت', 'fail', 'client id/secret or refresh token missing - reconnect the Google account'); return; }

        $token = $meet->health_access_token();
        if (!$token) { $this->row('توکن گوگل', 'fail', 'refresh token rejected - reconnect the Google account'); return; }
        $this->row('توکن گوگل', 'ok', 'access token refreshed successfully');

        $calendar_id = get_option('gtbp_meet_calendar_id', 'primary');
        $cal = $meet->health_api_get('https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendar_id) . '/events?maxResults=1');
        $this->row('گوگل کلندر', is_array($cal) ? 'ok' : 'fail', is_array($cal) ? ('calendar "' . $calendar_id . '" readable') : 'cannot read calendar - check calendar id and scopes');

        $folder = trim((string) get_option('gtbp_meet_recordings_folder', ''));
        $query  = "mimeType contains 'video/' and trashed=false";
        if ($folder !== '') $query .= " and '" . str_replace("'", "\\'", $folder) . "' in parents";
        $files = $meet->health_api_get('https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q' => $query, 'fields' => 'files(id,name,createdTime)', 'pageSize' => 5,
            'orderBy' => 'createdTime desc', 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
        ]));
        if (!is_array($files)) {
            $this->row('گوگل درایو', 'fail', 'drive search failed - check the drive scope');
        } elseif (empty($files['files'])) {
            $this->row('گوگل درایو', 'warn', 'connection works, but no video file found' . ($folder !== '' ? ' in the configured folder' : ' in the account'));
        } else {
            $newest = $files['files'][0];
            $this->row('گوگل درایو', 'ok', count($files['files']) . ' video(s) visible; newest: ' . $newest['name']);
            $public = $meet->drive_file_is_public($newest['id']);
            $this->row('عمومی بودن ویدئو', $public ? 'ok' : 'warn',
                $public ? ('newest recording is shared with "anyone with the link"')
                        : ('newest recording is NOT public yet - it becomes public when the plugin links it, or Workspace policy blocks public sharing'));
            $download = GTBP_Google_Meet_Provider::drive_url_to_download('https://drive.google.com/file/d/' . $newest['id'] . '/view');
            $this->row('لینک دانلود', $download ? 'ok' : 'fail', $download ?: 'could not build a direct download url');
        }

        // چند کلاس Meet ویدئو دارند و چند تا منتظر تطبیق دستی هستند
        global $wpdb;
        $bookings = $wpdb->prefix . 'german_bookings';
        $meet_where = "((meet_join_link IS NOT NULL AND meet_join_link<>'')
                       OR (meet_calendar_event_id IS NOT NULL AND meet_calendar_event_id<>'')
                       OR class_provider='meet'
                       OR roomeet_join_link LIKE '%meet.google.com%')";
        $past    = (int) $wpdb->get_var("SELECT COUNT(*) FROM `" . esc_sql($bookings) . "` WHERE {$meet_where} AND booking_date <= CURDATE()");
        $linked  = (int) $wpdb->get_var("SELECT COUNT(*) FROM `" . esc_sql($bookings) . "` WHERE {$meet_where} AND booking_date <= CURDATE() AND meet_recording_link IS NOT NULL AND meet_recording_link<>''");
        if ($past === 0) {
            $this->row('ویدئوی کلاس‌های Meet', 'ok', 'no past Google Meet class yet');
        } else {
            $this->row('ویدئوی کلاس‌های Meet', $linked === $past ? 'ok' : 'warn',
                $linked . ' of ' . $past . ' past Meet class(es) have a recording linked'
                . ($linked < $past ? ' - use the Google Meet box on the video search page' : ''));
        }

        $error = get_option('gtbp_meet_last_error', '');
        if ($error) $this->row('آخرین خطای گوگل', 'warn', mb_substr((string) $error, 0, 300));
    }

    /* ---------------- Telegram ---------------- */
    private function check_telegram() {
        $token = trim((string) get_option('gtbp_telegram_token', ''));
        if ($token === '') { $this->row('ربات تلگرام', 'skip', 'no bot token configured'); return; }
        $resp = wp_remote_get('https://api.telegram.org/bot' . $token . '/getMe', ['timeout' => 20]);
        if (is_wp_error($resp)) { $this->row('ربات تلگرام', 'fail', 'connection error: ' . $resp->get_error_message()); return; }
        $code = wp_remote_retrieve_response_code($resp);
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if ($code !== 200 || empty($body['ok'])) {
            $this->row('ربات تلگرام', 'fail', 'getMe HTTP ' . $code . ' - token is invalid or outbound access to api.telegram.org is blocked');
            return;
        }
        $this->row('ربات تلگرام', 'ok', 'bot @' . ($body['result']['username'] ?? '?') . ' reachable');

        $hook = wp_remote_get('https://api.telegram.org/bot' . $token . '/getWebhookInfo', ['timeout' => 20]);
        if (!is_wp_error($hook)) {
            $info = json_decode(wp_remote_retrieve_body($hook), true);
            $url  = (string) ($info['result']['url'] ?? '');
            $pending = intval($info['result']['pending_update_count'] ?? 0);
            $last_error = (string) ($info['result']['last_error_message'] ?? '');
            if ($url === '') {
                $this->row('وبهوک تلگرام', 'warn', 'no webhook registered - press the webhook button on the bot settings page');
            } else {
                $state = $last_error !== '' ? 'warn' : 'ok';
                $this->row('وبهوک تلگرام', $state, 'url set, pending=' . $pending . ($last_error !== '' ? ' , last error: ' . $last_error : ''));
            }
        }
    }

    /* ---------------- Cron ---------------- */
    private function check_cron() {
        $schedules = wp_get_schedules();
        $this->row('زمان‌بند ۳۰ دقیقه‌ای', isset($schedules['gtbp_thirty_minutes']) ? 'ok' : 'fail',
            isset($schedules['gtbp_thirty_minutes']) ? 'gtbp_thirty_minutes registered' : 'custom schedule missing');
        foreach ([
            'gtbp_check_meet_recordings' => 'کرون ضبط گوگل میت',
            'gtbp_check_bbb_recordings'  => 'کرون ضبط BigBlueButton',
            'gtbp_check_roomeet_recordings' => 'کرون ضبط روومیت',
        ] as $hook => $title) {
            $next = wp_next_scheduled($hook);
            if (!$next) { $this->row($title, 'warn', 'not scheduled'); continue; }
            $this->row($title, 'ok', 'next run: ' . get_date_from_gmt(gmdate('Y-m-d H:i:s', $next), 'Y-m-d H:i'));
        }
        if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
            $this->row('اجرای WP-Cron', 'warn', 'DISABLE_WP_CRON is true - make sure a real server cron calls wp-cron.php');
        }
    }

    /* ---------------- Database ---------------- */
    private function check_database() {
        global $wpdb;
        $bookings = $wpdb->prefix . 'german_bookings';
        $sessions = $wpdb->prefix . 'gls_sessions';
        $needed_bookings = ['roomeet_join_link', 'meet_join_link', 'meet_recording_link', 'meet_calendar_event_id', 'class_provider', 'status'];
        $cols = (array) $wpdb->get_col("SHOW COLUMNS FROM `" . esc_sql($bookings) . "`");
        $missing = array_values(array_diff($needed_bookings, $cols));
        $this->row('جدول رزروها', $missing ? 'fail' : 'ok', $missing ? ('missing columns: ' . implode(', ', $missing)) : 'all required columns present');

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $sessions)) === $sessions) {
            $scols = (array) $wpdb->get_col("SHOW COLUMNS FROM `" . esc_sql($sessions) . "`");
            $need = ['meet_video_url', 'meet_video_url_source', 'video_url', 'booking_id'];
            $miss = array_values(array_diff($need, $scols));
            $this->row('جدول جلسات', $miss ? 'warn' : 'ok', $miss ? ('missing columns: ' . implode(', ', $miss)) : 'all required columns present');
        } else {
            $this->row('جدول جلسات', 'warn', 'sessions table not created yet');
        }
    }

    /* ---------------- Panel / booking flow ---------------- */
    private function check_panel() {
        global $wpdb;
        $this->row('شورت‌کد فرم رزرو', shortcode_exists('german_teacher_booking') ? 'ok' : 'fail', 'german_teacher_booking');
        $this->row('شورت‌کد پنل دانش‌آموز', shortcode_exists('german_student_panel') ? 'ok' : 'fail', 'german_student_panel');
        $this->row('شورت‌کد ضبط‌ها', shortcode_exists('german_teacher_all_recordings') ? 'ok' : 'fail', 'german_teacher_all_recordings');
        $this->row('پردازش سبد و اسلات‌ها', has_action('wp_ajax_gtbp_get_slots') ? 'ok' : 'fail', 'wp_ajax_gtbp_get_slots');

        $classes = get_option('gtbp_classes', []);
        $this->row('کلاس‌های تعریف‌شده', is_array($classes) && $classes ? 'ok' : 'warn',
            is_array($classes) ? (count($classes) . ' class(es) defined') : 'option is not an array');

        $bookings = $wpdb->prefix . 'german_bookings';
        $upcoming = (int) $wpdb->get_var("SELECT COUNT(*) FROM `" . esc_sql($bookings) . "` WHERE booking_date >= CURDATE()");
        $linked   = (int) $wpdb->get_var("SELECT COUNT(*) FROM `" . esc_sql($bookings) . "` WHERE booking_date >= CURDATE() AND roomeet_join_link IS NOT NULL AND roomeet_join_link<>''");
        $state = ($upcoming === 0) ? 'ok' : ($linked === $upcoming ? 'ok' : 'warn');
        $this->row('لینک کلاس‌های آینده', $state, $linked . ' of ' . $upcoming . ' upcoming booking(s) have a join link');
    }
}
