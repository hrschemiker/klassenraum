<?php
if (!defined('ABSPATH')) exit;

// Fix #13 note: the class name suffix "v211" is intentionally preserved to avoid breaking
// WordPress cron callbacks that have this class name serialised in wp_options. The actual
// version is tracked via the VERSION constant below.
final class GTBP_Learning_Dashboard_v211 {
    const VERSION = '2.20.2-tab-recovery';
    const CRON_AI_BACKFILL = 'gls_ai_backfill_1214';
    const OPT_AI_BACKFILL_VERSION = 'gls_ai_backfill_version';
    const AI_BACKFILL_VERSION = '12.14';
    const OPT_ACTIVATED = 'gls_activated_at';
    const OPT_SETTINGS  = 'gls_settings';
    const CRON_SYNC     = 'gls_sync_booking_sessions';
    const CRON_QUIZ_ANALYSIS = 'gls_process_quiz_report_async';

    private static $i = null;
    private $sessions;
    private $items;
    private $subs;
    private $logs;
    private $bookings;
    private $ai_profiles;
    private $ai_logs;
    private $quiz_reports;

    public static function instance(){ return self::$i ?: (self::$i = new self()); }

    private function __construct(){
        global $wpdb;
        $this->sessions = $wpdb->prefix.'gls_sessions';
        $this->items    = $wpdb->prefix.'gls_items';
        $this->subs     = $wpdb->prefix.'gls_submissions';
        $this->logs     = $wpdb->prefix.'gls_notifications';
        $this->bookings = $wpdb->prefix.'german_bookings';
        $this->ai_profiles = $wpdb->prefix.'gls_ai_student_profiles';
        $this->ai_logs = $wpdb->prefix.'gls_ai_logs';
        $this->quiz_reports = $wpdb->prefix.'gls_quiz_reports';

        if (!get_option(self::OPT_ACTIVATED)) update_option(self::OPT_ACTIVATED, current_time('mysql'));
        if (!get_option(self::OPT_SETTINGS)) update_option(self::OPT_SETTINGS, [
            'teacher_email'=>get_option('admin_email'),
            'teacher_name'=>'حمیدرضا سعادتی',
            'lesson_weight'=>40,
            'test_weight'=>40,
            'attendance_weight'=>20,
            'auto_publish_sessions'=>1,
            'ai_api_key'=>'',
            'ai_base_url'=>'https://api.gapgpt.app/v1',
            'ai_model_analysis'=>'gapgpt-qwen-3.5',
            'ai_model_reading'=>'gapgpt-qwen-3.5',
            'ai_model_correction'=>'gemini-2.5-flash',
            'ai_reading_title'=>'Lesetext zur Wiederholung',
            'ai_enabled'=>1,
        ]);

        add_action('init', [$this,'init']);
        add_action('admin_menu', [$this,'admin_menu']);
        add_action('admin_init', [$this,'admin_actions']);
        add_action('admin_notices', [$this,'admin_notices']);
        add_action('admin_enqueue_scripts', [$this,'admin_assets']);
        add_action('wp_enqueue_scripts', [$this,'front_assets']);
        add_action(self::CRON_SYNC, [$this,'sync_bookings']);
        add_action(self::CRON_AI_BACKFILL, [$this,'run_ai_backfill_1212']);
        add_action(self::CRON_QUIZ_ANALYSIS, [$this,'process_quiz_report_async'], 10, 1);

        add_action('wp_ajax_gls_save_lesson', [$this,'ajax_save_lesson']);
        add_action('wp_ajax_gls_add_item', [$this,'ajax_add_item']);
        add_action('wp_ajax_gls_delete_item', [$this,'ajax_delete_item']);
        add_action('wp_ajax_gls_reorder_items', [$this,'ajax_reorder_items']);
        add_action('wp_ajax_gls_submit_homework', [$this,'ajax_submit_homework']);
        add_action('wp_ajax_gls_mark_notifications_read', [$this,'ajax_mark_notifications_read']);
        add_action('wp_ajax_gls_save_profile', [$this,'ajax_save_profile']);
        add_action('wp_dashboard_setup', [$this,'register_dashboard_widgets']);
    }

    public function init(){
        add_shortcode('german_student_panel', [$this,'student_panel']);
        add_shortcode('german_session', [$this,'single_session']);
        $this->register_online_test_compat();
        $this->maybe_schedule_ai_backfill_1212();
        if (!is_admin() && !get_transient('gls_light_sync_lock')) {
            set_transient('gls_light_sync_lock', 1, 10 * MINUTE_IN_SECONDS);
            $this->sync_bookings(25);
        }
    }

    public function activate(){
        $this->tables();
        if (!get_option(self::OPT_ACTIVATED)) update_option(self::OPT_ACTIVATED, current_time('mysql'));
        if (!get_option(self::OPT_SETTINGS)) update_option(self::OPT_SETTINGS, [
            'teacher_email'=>get_option('admin_email'),
            'teacher_name'=>'حمیدرضا سعادتی',
            'lesson_weight'=>40,
            'test_weight'=>40,
            'attendance_weight'=>20,
            'auto_publish_sessions'=>1,
        ]);
        if (!wp_next_scheduled(self::CRON_SYNC)) wp_schedule_event(time()+60, 'hourly', self::CRON_SYNC);
        $this->sync_bookings(500);
        $this->maybe_schedule_ai_backfill_1212(true);
    }

    public function deactivate(){ wp_clear_scheduled_hook(self::CRON_SYNC); wp_clear_scheduled_hook(self::CRON_AI_BACKFILL); wp_clear_scheduled_hook(self::CRON_QUIZ_ANALYSIS); }

    private function maybe_schedule_ai_backfill_1212($force=false){
        $done=get_option(self::OPT_AI_BACKFILL_VERSION);
        if(!$force && $done==self::AI_BACKFILL_VERSION) return;
        if(!wp_next_scheduled(self::CRON_AI_BACKFILL)){
            wp_schedule_single_event(time()+35, self::CRON_AI_BACKFILL);
        }
        if($force) update_option(self::OPT_AI_BACKFILL_VERSION, 'scheduled-'.self::AI_BACKFILL_VERSION);
    }

    public function run_ai_backfill_1212(){
        global $wpdb;
        $this->tables();
        if(!$this->ai_enabled()){
            $this->ai_log('activation_backfill','system','error','', 'API Key یا دستیار آموزشی فعال نیست؛ پردازش یک‌باره 12.14 انجام نشد.',0,0);
            update_option(self::OPT_AI_BACKFILL_VERSION, 'skipped-no-key-'.self::AI_BACKFILL_VERSION);
            return;
        }
        $processed=0;
        $sessions=$wpdb->get_results("SELECT id FROM {$this->sessions} WHERE session_status='published' AND lesson_content IS NOT NULL AND lesson_content<>'' AND (ai_reading_content IS NULL OR ai_reading_content='') ORDER BY booking_date ASC,id ASC LIMIT 4");
        foreach((array)$sessions as $r){
            $res=$this->ai_generate_reading_for_session(intval($r->id));
            if(!is_wp_error($res)) $processed++;
        }
        $subs=$wpdb->get_results("SELECT id FROM {$this->subs} WHERE status='submitted' AND (feedback_text IS NULL OR feedback_text='') ORDER BY submitted_at ASC,id ASC LIMIT 4");
        foreach((array)$subs as $r){
            $res=$this->ai_correct_submission(intval($r->id));
            if(!is_wp_error($res)) $processed++;
        }
        $remain_sessions=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->sessions} WHERE session_status='published' AND lesson_content IS NOT NULL AND lesson_content<>'' AND (ai_reading_content IS NULL OR ai_reading_content='')");
        $remain_subs=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$this->subs} WHERE status='submitted' AND (feedback_text IS NULL OR feedback_text='')");
        if(($remain_sessions+$remain_subs)>0){
            wp_schedule_single_event(time()+120, self::CRON_AI_BACKFILL);
            update_option(self::OPT_AI_BACKFILL_VERSION, 'running-'.self::AI_BACKFILL_VERSION);
        }else{
            update_option(self::OPT_AI_BACKFILL_VERSION, self::AI_BACKFILL_VERSION);
        }
        $this->ai_log('activation_backfill','system','success','', 'پردازش یک‌باره 12.14 انجام شد. موارد پردازش‌شده: '.$processed,0,0);
    }

    private function tables(){
        global $wpdb;
        require_once ABSPATH.'wp-admin/includes/upgrade.php';
        $c=$wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$this->sessions} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            booking_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            student_first_name varchar(120) NOT NULL DEFAULT '',
            student_last_name varchar(120) NOT NULL DEFAULT '',
            student_name varchar(240) NOT NULL DEFAULT '',
            student_email varchar(190) NOT NULL DEFAULT '',
            booking_date date NOT NULL,
            booking_time varchar(80) NOT NULL DEFAULT '',
            jalali_date varchar(30) NOT NULL DEFAULT '',
            title varchar(255) NOT NULL DEFAULT '',
            class_name varchar(180) NOT NULL DEFAULT '',
            session_status varchar(30) NOT NULL DEFAULT 'published',
            attendance_status varchar(30) NOT NULL DEFAULT 'unset',
            lesson_content longtext NULL,
            video_url text NULL,
            video_url_source varchar(20) NOT NULL DEFAULT '',
            test_shortcode text NULL,
            teacher_level varchar(10) NOT NULL DEFAULT '',
            ai_detected_level varchar(10) NOT NULL DEFAULT '',
            ai_reading_title varchar(255) NOT NULL DEFAULT '',
            ai_reading_content longtext NULL,
            ai_analysis_json longtext NULL,
            ai_analyzed_at datetime NULL,
            last_notified_hash varchar(64) NOT NULL DEFAULT '',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            archived_at datetime NULL,
            PRIMARY KEY (id),
            UNIQUE KEY booking_id (booking_id),
            KEY user_id (user_id),
            KEY student_email (student_email),
            KEY booking_date (booking_date),
            KEY session_status (session_status)
        ) $c;");

        dbDelta("CREATE TABLE {$this->items} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            item_type varchar(30) NOT NULL DEFAULT 'resource',
            item_kind varchar(30) NOT NULL DEFAULT 'file',
            title varchar(255) NOT NULL DEFAULT '',
            url text NULL,
            attachment_id bigint(20) unsigned NOT NULL DEFAULT 0,
            content longtext NULL,
            sort_order int(11) NOT NULL DEFAULT 0,
            visible tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY item_type (item_type),
            KEY sort_order (sort_order)
        ) $c;");

        dbDelta("CREATE TABLE {$this->subs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            attempt_no int(11) NOT NULL DEFAULT 1,
            text_content longtext NULL,
            file_ids longtext NULL,
            status varchar(30) NOT NULL DEFAULT 'submitted',
            grade decimal(6,2) NULL,
            feedback_text longtext NULL,
            correction_file_ids longtext NULL,
            submitted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            corrected_at datetime NULL,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY submitted_at (submitted_at)
        ) $c;");

        dbDelta("CREATE TABLE {$this->logs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            session_id bigint(20) unsigned NOT NULL DEFAULT 0,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            email varchar(190) NOT NULL DEFAULT '',
            type varchar(60) NOT NULL DEFAULT '',
            subject varchar(255) NOT NULL DEFAULT '',
            message longtext NULL,
            sent_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY user_id (user_id),
            KEY type (type),
            KEY sent_at (sent_at)
        ) $c;");


        dbDelta("CREATE TABLE {$this->ai_profiles} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            student_email varchar(190) NOT NULL DEFAULT '',
            current_level varchar(10) NOT NULL DEFAULT '',
            profile_summary longtext NULL,
            taught_points longtext NULL,
            teacher_terms longtext NULL,
            careless_errors longtext NULL,
            performance_json longtext NULL,
            last_session_id bigint(20) unsigned NOT NULL DEFAULT 0,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY user_email (user_id,student_email),
            KEY student_email (student_email),
            KEY updated_at (updated_at)
        ) $c;");

        dbDelta("CREATE TABLE {$this->ai_logs} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            session_id bigint(20) unsigned NOT NULL DEFAULT 0,
            submission_id bigint(20) unsigned NOT NULL DEFAULT 0,
            request_type varchar(60) NOT NULL DEFAULT '',
            model varchar(120) NOT NULL DEFAULT '',
            status varchar(30) NOT NULL DEFAULT '',
            prompt_hash varchar(64) NOT NULL DEFAULT '',
            response_excerpt longtext NULL,
            error_message text NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY session_id (session_id),
            KEY submission_id (submission_id),
            KEY request_type (request_type),
            KEY created_at (created_at)
        ) $c;");


        dbDelta("CREATE TABLE {$this->quiz_reports} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            result_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            session_id bigint(20) unsigned NOT NULL DEFAULT 0,
            test_id bigint(20) unsigned NOT NULL DEFAULT 0,
            score decimal(8,2) NOT NULL DEFAULT 0,
            total decimal(8,2) NOT NULL DEFAULT 0,
            answers_json longtext NULL,
            analysis_html longtext NULL,
            analysis_status varchar(30) NOT NULL DEFAULT 'pending',
            emailed_at datetime NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY result_id (result_id),
            KEY user_id (user_id),
            KEY session_id (session_id),
            KEY test_id (test_id),
            KEY created_at (created_at)
        ) $c;");
    }

    private function settings(){
        return wp_parse_args((array)get_option(self::OPT_SETTINGS, []), [
            'teacher_email'=>get_option('admin_email'),
            'teacher_name'=>'حمیدرضا سعادتی',
            'lesson_weight'=>40,
            'test_weight'=>40,
            'attendance_weight'=>20,
            'auto_publish_sessions'=>1,
            'ai_api_key'=>'',
            'ai_base_url'=>'https://api.gapgpt.app/v1',
            'ai_model_analysis'=>'gapgpt-qwen-3.5',
            'ai_model_reading'=>'gapgpt-qwen-3.5',
            'ai_model_correction'=>'gemini-2.5-flash',
            'ai_reading_title'=>'Lesetext zur Wiederholung',
            'ai_enabled'=>0
        ]);
    }

    public function sync_bookings($limit=300){
        global $wpdb;
        $this->tables();

        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$this->bookings)) !== $this->bookings) return;

        $activated=get_option(self::OPT_ACTIVATED, current_time('mysql'));
        $set=$this->settings();

        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->bookings}
             WHERE status='confirmed' AND created_at >= %s
             ORDER BY created_at DESC
             LIMIT %d",
            $activated,
            intval($limit)
        ));

        foreach((array)$rows as $b) {
            $this->upsert_session($b, !empty($set['auto_publish_sessions'])?'published':'draft');
        }

        $sessions=$wpdb->get_results("SELECT id,booking_id FROM {$this->sessions} WHERE session_status!='archived' LIMIT 500");
        foreach((array)$sessions as $s){
            $st=$wpdb->get_var($wpdb->prepare("SELECT status FROM {$this->bookings} WHERE id=%d", intval($s->booking_id)));
            if ($st===null || $st!=='confirmed') {
                $wpdb->update(
                    $this->sessions,
                    ['session_status'=>'archived','archived_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],
                    ['id'=>intval($s->id)]
                );
            }
        }
    }

    private function upsert_session($b,$default='published'){
        global $wpdb;

        $bid=intval($b->id);
        if(!$bid) return;

        $email=sanitize_email($b->email ?? '');
        $user=$email ? get_user_by('email',$email) : false;

        $first=sanitize_text_field($b->first_name ?? '');
        $last=sanitize_text_field($b->last_name ?? '');

        $name=trim($first.' '.$last);
        if($name==='') $name=$email ?: 'زبان‌آموز';

        $jalali=$this->g2j_string($b->booking_date);
        $title=trim($name.' - '.$jalali);
        $video=!empty($b->roomeet_recording_link) ? esc_url_raw($b->roomeet_recording_link) : '';

        $old=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->sessions} WHERE booking_id=%d",$bid));

        $data=[
            'user_id'=>$user?intval($user->ID):0,
            'student_first_name'=>$first,
            'student_last_name'=>$last,
            'student_name'=>$name,
            'student_email'=>$email,
            'booking_date'=>sanitize_text_field($b->booking_date),
            'booking_time'=>sanitize_text_field($b->booking_time),
            'jalali_date'=>$jalali,
            'title'=>$title,
            'class_name'=>sanitize_text_field($b->class_name),
            'updated_at'=>current_time('mysql')
        ];

        if($old){
            if($old->session_status==='archived') $data['session_status']=$default;
            $source = isset($old->video_url_source) ? (string)$old->video_url_source : '';
            if($video !== '' && $source !== 'manual'){
                $data['video_url']=$video;
                $data['video_url_source']='auto';
            }
            $wpdb->update($this->sessions,$data,['booking_id'=>$bid]);
        } else {
            $data['booking_id']=$bid;
            $data['session_status']=$default;
            $data['attendance_status']='unset';
            $data['created_at']=current_time('mysql');
            if($video !== ''){
                $data['video_url']=$video;
                $data['video_url_source']='auto';
            }
            $wpdb->insert($this->sessions,$data);
        }
    }


    public function sync_recording_from_booking($booking_id, $recording_link, $notify=true){
        global $wpdb;
        $this->tables();
        $booking_id = intval($booking_id);
        $recording_link = esc_url_raw($recording_link);
        if($booking_id <= 0 || $recording_link === '') return false;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$this->bookings)) !== $this->bookings) return false;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->bookings} WHERE id=%d", $booking_id));
        if(!$booking) return false;
        if(!$this->has_session_for_booking($booking_id)) $this->upsert_session($booking, 'published');
        $session = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->sessions} WHERE booking_id=%d LIMIT 1", $booking_id));
        if(!$session) return false;
        $source = isset($session->video_url_source) ? (string)$session->video_url_source : '';
        if($source === 'manual' && trim((string)$session->video_url) !== '') return false;
        $old = $session;
        $changed = trim((string)$session->video_url) !== $recording_link;
        $wpdb->update($this->sessions, [
            'video_url'=>$recording_link,
            'video_url_source'=>'auto',
            'updated_at'=>current_time('mysql')
        ], ['id'=>intval($session->id)]);
        if($notify && $changed) $this->notify_if_needed($old, $this->get_session($session->id));
        return true;
    }

    public function session_has_manual_video_for_booking($booking_id){
        global $wpdb;
        $this->tables();
        $booking_id = intval($booking_id);
        if($booking_id <= 0) return false;
        $row = $wpdb->get_row($wpdb->prepare("SELECT video_url, video_url_source FROM {$this->sessions} WHERE booking_id=%d LIMIT 1", $booking_id));
        return $row && (string)$row->video_url_source === 'manual' && trim((string)$row->video_url) !== '';
    }

    public function get_session_video_for_booking($booking_id){
        global $wpdb;
        $this->tables();
        $booking_id = intval($booking_id);
        if($booking_id <= 0) return '';
        $url = $wpdb->get_var($wpdb->prepare("SELECT video_url FROM {$this->sessions} WHERE booking_id=%d AND video_url IS NOT NULL AND video_url<>'' LIMIT 1", $booking_id));
        return $url ? esc_url_raw($url) : '';
    }


    public function admin_notices(){
        if(!current_user_can('manage_options')) return;
        $err=get_transient('gls_admin_ai_error');
        if($err){ delete_transient('gls_admin_ai_error'); echo '<div class="notice notice-error"><p><strong>خطای دستیار آموزشی:</strong> '.esc_html($err).'</p></div>'; }
    }

    public function admin_menu(){
        add_submenu_page('gtbp_bookings','جلسات آموزشی','جلسات آموزشی','manage_options','gls_sessions',[$this,'admin_sessions']);
        add_submenu_page('gtbp_bookings','تکالیف آموزشی','تکالیف آموزشی','manage_options','gls_homework',[$this,'admin_homework']);
        add_submenu_page('gtbp_bookings','آرشیو جلسات','آرشیو جلسات','manage_options','gls_archive',[$this,'admin_archive']);
        add_submenu_page('gtbp_bookings','نتایج آزمونک‌ها','نتایج آزمونک‌ها','manage_options','gls_quiz_results',[$this,'admin_quiz_results']);
        add_submenu_page('gtbp_bookings','تنظیمات پنل آموزشی','پنل آموزشی','manage_options','gls_settings',[$this,'admin_settings']);
    }

    public function admin_assets($hook){
        if(strpos($hook,'gls_')===false && strpos($hook,'جلسات')===false) return;
        wp_enqueue_media();
        wp_enqueue_script('jquery-ui-sortable');
        $this->assets(true);
    }

    public function front_assets(){
        global $post;
        // صفحه‌سازها ممکن است شورت‌کد را در post_content نگه ندارند. در سایت مقصد این
        // باعث می‌شد HTML پنل نمایش داده شود اما JavaScript تب‌ها اصلاً بارگذاری نشود.
        // برای کاربران واردشده بارگذاری این بسته کوچک و idempotent امن‌تر و قطعی‌تر است.
        if(!is_user_logged_in()){
            if(!is_a($post,'WP_Post')) return;
            if(!has_shortcode($post->post_content,'german_student_panel') && !has_shortcode($post->post_content,'german_session')) return;
        }
        $this->assets(false);
    }

    private function assets($admin=false){
        $h=$admin?'gls-admin-inline':'gls-front-inline';

        wp_register_style($h,false,[],self::VERSION);
        wp_enqueue_style($h);
        wp_add_inline_style($h,$this->font_css().$this->css());

        // کتابخانه‌ها داخل خود افزونه نگه‌داری می‌شوند تا محدودیت CDN/اینترنت ایران
        // خروجی را به چاپ ناقص مرورگر (بدون رنگ و با فوتر هم‌پوشان) برنگرداند.
        $html2canvas_url=GTBP_PLUGIN_URL.'assets/vendor/html2canvas.min.js';
        $jspdf_url=GTBP_PLUGIN_URL.'assets/vendor/jspdf.umd.min.js';
        wp_enqueue_script('gtbp-html2canvas',$html2canvas_url,[],'1.4.1',true);
        wp_enqueue_script('gtbp-jspdf',$jspdf_url,['gtbp-html2canvas'],'2.5.1',true);

        wp_register_script($h,false,['jquery','gtbp-html2canvas','gtbp-jspdf'],self::VERSION,true);
        wp_enqueue_script($h);
        wp_localize_script($h,'GLS_DATA',[
            'ajax'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('gls_nonce'),
            'mediaTitle'=>'انتخاب فایل',
            'mediaButton'=>'استفاده از فایل',
            'fontWoff2'=>GTBP_PLUGIN_URL.'assets/fonts/IRANSansXFaNum-MediumD4.woff2',
            'fontWoff'=>GTBP_PLUGIN_URL.'assets/fonts/IRANSansXFaNum-MediumD4.woff',
            'fontTtf'=>GTBP_PLUGIN_URL.'assets/fonts/IRANSansXFaNum-MediumD4.ttf'
        ]);
        // مستقل از jQuery: حتی اگر بهینه‌ساز صفحه بستهٔ اصلی را به تأخیر بیندازد یا
        // خطایی آن را متوقف کند، دکمه‌های تب داشبورد همیشه کار می‌کنند.
        wp_add_inline_script($h,$this->nav_fallback_js(),'before');
        wp_add_inline_script($h,$this->js());
    }

    private function nav_fallback_js(){ return <<<'JS'
(function(){
  var MODES=['content','reserve','sessions','bookings','tasks','performance','notifications','profile'];
  var PANELS={reserve:'gls-mobile-book-class',bookings:'gls-mobile-bookings',tasks:'gls-mobile-tasks',performance:'gls-mobile-performance',notifications:'gls-mobile-notifications',profile:'gls-mobile-profile'};
  function activate(root,target){
    if(!root||MODES.indexOf(target)<0)return;
    MODES.forEach(function(mode){root.classList.remove('gls-mobile-mode-'+mode)});
    root.classList.add('gls-mobile-mode-'+target);
    var buttons=root.querySelectorAll('.gls-mobile-nav-btn,.gls-desktop-nav-btn');
    Array.prototype.forEach.call(buttons,function(btn){
      btn.classList.toggle('active',btn.getAttribute('data-target')===target);
    });
    Array.prototype.forEach.call(root.querySelectorAll('.gls-dashboard-card,.gls-booking-portal'),function(card){
      card.classList.remove('gls-mobile-active');
    });
    var panelId=PANELS[target];
    if(!panelId)return;
    var panel=root.querySelector('#'+panelId)||document.getElementById(panelId);
    if(!panel)return;
    panel.classList.add('gls-mobile-active');
    if(target==='reserve'){
      if(panel.tagName&&panel.tagName.toLowerCase()==='details')panel.open=true;
      setTimeout(function(){
        var cal=document.getElementById('gtbp-calendar')||document.getElementById('step-2-wrap')||panel;
        if(cal&&cal.scrollIntoView)cal.scrollIntoView({behavior:'smooth',block:'start'});
      },320);
    }
  }
  document.addEventListener('click',function(event){
    // اگر بسته اصلی فعال باشد، مالک وضعیت تب‌ها اوست و این نسخه پشتیبان کنار می‌کشد.
    if(window.glsNavReady)return;
    var btn=event.target&&event.target.closest?event.target.closest('.gls-mobile-nav-btn,.gls-desktop-nav-btn'):null;
    if(!btn)return;
    event.preventDefault();
    var root=btn.closest('.gls-student-full')||document.querySelector('.gls-student-full');
    activate(root,btn.getAttribute('data-target'));
  });
  function init(){
    if(window.glsNavReady)return;
    Array.prototype.forEach.call(document.querySelectorAll('.gls-student-full'),function(root){
      var has=MODES.some(function(mode){return root.classList.contains('gls-mobile-mode-'+mode)});
      if(has)return;
      var active=root.querySelector('.gls-mobile-nav-btn.active,.gls-desktop-nav-btn.active');
      activate(root,(active&&active.getAttribute('data-target'))||'reserve');
    });
  }
  // بسته اصلی چند لحظه فرصت دارد؛ اگر نیامد، نسخه پشتیبان تب‌ها را راه می‌اندازد.
  function initLater(){ setTimeout(init,600); }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',initLater);else initLater();
})();
JS;
    }

    private function font_css(){
        $candidates=[
            ['dir'=>GTBP_PLUGIN_DIR.'assets/fonts/','url'=>GTBP_PLUGIN_URL.'assets/fonts/','base'=>'IRANSansXFaNum-MediumD4'],
            ['dir'=>WP_CONTENT_DIR.'/plugins/german-teacher-booking/assets/fonts/','url'=>content_url('/plugins/german-teacher-booking/assets/fonts/'),'base'=>'IRANSansXFaNum-MediumD4'],
            ['dir'=>WP_CONTENT_DIR.'/plugins/wp-smart-dictionary/assets/fonts/','url'=>content_url('/plugins/wp-smart-dictionary/assets/fonts/'),'base'=>'IRANSansXFaNum'],
        ];
        $u='';$base='';
        foreach($candidates as $c){
            if(file_exists($c['dir'].$c['base'].'.woff2') || file_exists($c['dir'].$c['base'].'.woff') || file_exists($c['dir'].$c['base'].'.ttf')){ $u=trailingslashit($c['url']); $base=$c['base']; break; }
        }
        if(!$u) return '.gls-wrap,.gls-wrap *{font-family:Tahoma,Arial,sans-serif!important}';
        $src='src:url("'.esc_url($u.$base.'.woff2').'") format("woff2"),url("'.esc_url($u.$base.'.woff').'") format("woff"),url("'.esc_url($u.$base.'.ttf').'") format("truetype");font-style:normal;font-display:swap;';
        return '@font-face{font-family:"IRANSansXFaNum";'.$src.'font-weight:400;}@font-face{font-family:"IRANSansXFaNum";'.$src.'font-weight:500;}@font-face{font-family:"IRANSansXFaNum";'.$src.'font-weight:700;}@font-face{font-family:"IRANSansXFaNum";'.$src.'font-weight:900;}';
    }

    private function css(){ return <<<'CSS'
.gls-wrap,.gls-wrap *{box-sizing:border-box;font-family:"IRANSansXFaNum",Tahoma,sans-serif!important}.gls-wrap{direction:rtl;text-align:right;color:#171717;--black:#111;--red:#dd0000;--gold:#ffce00;--line:#eceef3;--muted:#667085;--shadow:0 12px 34px rgba(16,24,40,.08)}body.gls-no-scroll{overflow:hidden!important;height:100vh!important}.gls-admin{padding:20px;background:#f5f6fa}.gls-shell{max-width:1360px;margin:0 auto;background:linear-gradient(135deg,#111 0%,#111 32%,#dd0000 66%,#ffce00 110%);padding:1px;border-radius:28px;box-shadow:0 24px 70px rgba(0,0,0,.14)}.gls-inner{background:#fff;border-radius:27px;overflow:hidden}.gls-top{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:18px 22px;background:linear-gradient(135deg,#111 0%,#242424 72%,#3b2600 120%);color:#fff;border-bottom:4px solid var(--gold)}.gls-top h1,.gls-top h2{margin:0;font-size:20px;line-height:1.6;color:#fff;font-weight:900}.gls-top small{color:#ffe680;font-size:12px}.gls-btn{border:0;border-radius:13px;padding:9px 13px;background:#151515;color:#fff;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:7px;cursor:pointer;font-weight:850;line-height:1.45;transition:.18s}.gls-btn:hover{color:#fff;background:#dd0000;transform:translateY(-1px)}.gls-btn.gold{background:#ffce00;color:#111}.gls-btn.red{background:#dd0000;color:#fff}.gls-btn.light{background:#f4f5f7;color:#111}.gls-grid{display:grid;grid-template-columns:330px 1fr;gap:0;min-height:720px}.gls-sidebar{background:linear-gradient(180deg,#fbfbfc,#f5f6fa);border-left:1px solid var(--line);padding:16px;overflow:auto}.gls-main{padding:22px;background:#fff;min-width:0}.gls-session-card{display:block;text-decoration:none;color:#111;background:#fff;border:1px solid #e8eaf0;border-radius:20px;padding:14px;margin-bottom:11px;transition:.18s;box-shadow:0 6px 18px rgba(16,24,40,.045);position:relative;overflow:hidden}.gls-session-card:before{content:"";position:absolute;inset:0 0 auto 0;height:4px;background:linear-gradient(90deg,#111 0 33%,#dd0000 33% 66%,#ffce00 66% 100%)}.gls-session-card:hover,.gls-session-card.active{border-color:#dd0000;transform:translateY(-1px);box-shadow:0 14px 30px rgba(221,0,0,.12)}.gls-session-card h3{margin:6px 0 9px;font-size:15px;line-height:1.7;font-weight:900}.gls-session-time{display:inline-flex;align-items:center;gap:5px;border-radius:999px;background:#111;color:#fff;padding:4px 9px;font-size:11px;margin-top:7px}.gls-meta{display:flex;flex-wrap:wrap;gap:7px;color:var(--muted);font-size:12px}.gls-meta span{background:#f4f5f7;border:1px solid #edf0f4;border-radius:999px;padding:4px 8px}.gls-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}.gls-tab{padding:9px 13px;border-radius:14px;background:#f6f7f9;border:1px solid #e9ecf2;cursor:pointer;font-weight:850;color:#222}.gls-tab.active{background:#151515;color:#fff;border-color:#151515;box-shadow:inset 0 -3px 0 #ffce00}.gls-panel{display:none}.gls-panel.active{display:block}.gls-box{background:#fff;border:1px solid #e8eaf0;border-radius:20px;padding:17px;margin-bottom:15px;box-shadow:var(--shadow)}.gls-box h3{margin:0 0 12px;font-size:17px;line-height:1.7;font-weight:900;color:#111}.gls-empty{text-align:center;color:#777;background:#fafafa;border:1px dashed #d8dbe3;border-radius:18px;padding:28px}.gls-lesson-view{line-height:2.15;font-size:17px;background:#fff;border-radius:18px;max-width:100%;overflow-wrap:anywhere;word-break:normal;white-space:normal;overflow-x:hidden}.gls-lesson-view *{max-width:100%!important}.gls-lesson-view img,.gls-lesson-view video,.gls-lesson-view iframe{max-width:100%!important;height:auto!important}.gls-lesson-view img.emoji,.gls-lesson-view img.wp-smiley{width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;display:inline!important;vertical-align:-.12em!important;margin:0 .06em!important}.gls-lesson-view table{width:100%!important;table-layout:auto;border-collapse:collapse}.gls-lesson-actions{display:flex;gap:8px;justify-content:flex-end;align-items:center;margin-bottom:12px;flex-wrap:wrap}.gls-items{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:12px}.gls-item{border:1px solid #e8eaf0;border-radius:18px;padding:14px;background:#fff;box-shadow:0 8px 20px rgba(16,24,40,.045)}.gls-item h4{margin:0 0 8px;font-size:15px}.gls-status{display:inline-block;border-radius:999px;padding:5px 10px;font-size:12px;background:#f1f2f5;color:#2a2a2a}.gls-status.published,.gls-status.corrected{background:#e7f8ed;color:#067332}.gls-status.draft,.gls-status.submitted{background:#fff4cc;color:#7a5d00}.gls-status.archived,.gls-status.absent{background:#ffe5e5;color:#a00000}.gls-status.reviewing{background:#e8f0ff;color:#1849a9}.gls-status.resubmit{background:#ffe9df;color:#b54708}.gls-form label{display:block;font-weight:850;margin:12px 0 7px}.gls-form input[type=text],.gls-form input[type=url],.gls-form input[type=number],.gls-form select,.gls-form textarea{width:100%;border:1px solid #d8dbe3;border-radius:14px;padding:11px;background:#fff;min-height:43px}.gls-form textarea{min-height:110px}.gls-table{width:100%;border-collapse:separate;border-spacing:0 9px}.gls-table th{text-align:right;color:#555;font-size:13px;font-weight:900}.gls-table td{background:#fff;border-top:1px solid #e8eaf0;border-bottom:1px solid #e8eaf0;padding:12px}.gls-table td:first-child{border-right:1px solid #e8eaf0;border-radius:0 14px 14px 0}.gls-table td:last-child{border-left:1px solid #e8eaf0;border-radius:14px 0 0 14px}.gls-quiz-generator{border:1px solid #eadb9a;background:linear-gradient(135deg,#fffdf3,#fff8d8);border-radius:18px;padding:14px;margin:0 0 12px}.gls-quiz-generator-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:11px}.gls-quiz-generator-head strong{display:block;font-size:15px;font-weight:950}.gls-quiz-generator-head small{display:block;color:#6b5a20;margin-top:3px}.gls-quiz-generator-head>span{width:38px;height:38px;border-radius:13px;background:#111;color:#ffce00;display:flex;align-items:center;justify-content:center;font-size:20px}.gls-quiz-generator-grid{display:grid;grid-template-columns:minmax(240px,1fr) 110px 110px auto;gap:9px;align-items:end}.gls-quiz-generator-grid label{margin:0!important;font-size:12px}.gls-quiz-generator-grid input{margin-top:5px}.gls-quiz-generator-lock{margin:10px 0 0;color:#a00000;font-weight:800}.gls-quiz-generator button[disabled]{opacity:.5;cursor:not-allowed;transform:none!important}@media(max-width:760px){.gls-quiz-generator-grid{grid-template-columns:1fr 1fr}.gls-quiz-generator-grid label:first-child,.gls-quiz-generator-grid .gls-btn{grid-column:1/-1}}.gls-editor-wrap{position:relative}.gls-editor-wrap.gls-editor-fullscreen{position:fixed!important;inset:0!important;z-index:2147483647!important;background:#fff!important;padding:16px!important;margin:0!important;border-radius:0!important;display:flex!important;flex-direction:column!important;overflow:hidden!important}.gls-editor-fullscreen h3{display:none}.gls-editor-fullscreen .gls-toolbar{position:relative;top:auto;z-index:2;border-radius:18px;margin:0 0 10px}.gls-editor-fullscreen .gls-editor{flex:1!important;min-height:0!important;height:auto!important;overflow:auto!important;border-radius:18px!important}.gls-toolbar{position:sticky;top:32px;z-index:5;display:flex;align-items:center;flex-wrap:wrap;gap:7px;background:rgba(255,255,255,.96);backdrop-filter:saturate(180%) blur(10px);border:1px solid #e8eaf0;border-radius:18px;padding:9px;margin-bottom:10px;box-shadow:0 10px 28px rgba(16,24,40,.08)}.gls-toolbar-group{display:flex;align-items:center;gap:5px;border-left:1px solid #eceff5;padding-left:8px}.gls-toolbar-group:last-child{border-left:0}.gls-tool{width:38px;height:36px;border:0;background:#f4f5f7;border-radius:12px;cursor:pointer;font-weight:900;display:inline-flex;align-items:center;justify-content:center;color:#111}.gls-tool:hover{background:#ffce00}.gls-tool-select{height:36px;border:1px solid #d8dbe3;border-radius:12px;background:#fff;padding:0 9px;min-width:85px}.gls-color-pop{position:relative}.gls-color-palette{display:none;position:absolute;top:42px;right:0;width:226px;grid-template-columns:repeat(5,34px);gap:7px;background:#fff;border:1px solid #e8eaf0;border-radius:16px;padding:10px;box-shadow:0 18px 45px rgba(16,24,40,.18);z-index:20}.gls-color-pop.open .gls-color-palette{display:grid}.gls-color-chip{width:34px;height:30px;border-radius:9px;border:1px solid rgba(0,0,0,.12);cursor:pointer}.gls-custom-color{grid-column:1/-1;display:flex;gap:7px}.gls-custom-color input{flex:1;min-height:34px!important;border-radius:10px!important;padding:5px 8px!important}.gls-custom-color button{height:34px;padding:0 9px;border:0;border-radius:10px;background:#111;color:#fff}.gls-editor{min-height:470px;border:1px solid #d8dbe3;border-radius:18px;padding:22px;line-height:2.15;font-size:18px;outline:none;background:#fff;overflow-wrap:anywhere;word-break:normal;max-width:100%;overflow-x:hidden}.gls-editor:focus{border-color:#dd0000;box-shadow:0 0 0 4px rgba(221,0,0,.08)}.gls-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin-bottom:18px}.gls-kpi-card{border-radius:18px;padding:16px;background:#151515;color:#fff;min-height:98px}.gls-kpi-card:nth-child(2){background:#dd0000}.gls-kpi-card:nth-child(3){background:#ffce00;color:#111}.gls-score{font-size:34px;font-weight:950;line-height:1}.gls-student-full{width:100vw;margin-right:calc(50% - 50vw);margin-left:calc(50% - 50vw);background:#f5f6fa;min-height:80vh;padding:18px}.gls-admin-edit{max-width:1320px}.gls-notice{padding:12px 16px;border-radius:14px;background:#fff5cc;border:1px solid #ffe177;margin:12px 0}.gls-file-list{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.gls-file-chip{background:#f4f5f7;border:1px solid #e8eaf0;border-radius:999px;padding:6px 10px;font-size:12px;text-decoration:none;color:#111}.gls-admin-row{display:grid;grid-template-columns:1fr 1fr;gap:18px}.gls-hidden{display:none!important}.gls-homework-list{display:flex;flex-direction:column;gap:10px;margin-top:16px}.gls-homework-entry{border:1px solid #e8eaf0;border-radius:17px;overflow:hidden;background:#fff}.gls-homework-toggle{width:100%;border:0;background:#f8f9fb;padding:13px 14px;display:flex;align-items:center;justify-content:space-between;gap:10px;cursor:pointer;text-align:right}.gls-homework-body{display:none;padding:15px;border-top:1px solid #e8eaf0}.gls-homework-entry.open .gls-homework-body{display:block}.gls-mobile-session-picker{display:none}.gls-session-select{width:100%;border:1px solid #d8dbe3;border-radius:16px;padding:12px 14px;background:#fff;font-weight:850;color:#111;min-height:48px}.gls-session-jump{display:grid;grid-template-columns:1fr 48px;gap:8px;background:#fff;border-bottom:1px solid #e8eaf0;padding:12px}.gls-editor ul,.gls-editor ol,.gls-lesson-view ul,.gls-lesson-view ol,.gls-print-paper ul,.gls-print-paper ol{padding-inline-start:28px;padding-inline-end:28px;margin:10px 0;list-style-position:outside}.gls-editor ul,.gls-lesson-view ul,.gls-print-paper ul{list-style-type:disc}.gls-editor ol,.gls-lesson-view ol,.gls-print-paper ol{list-style-type:decimal}.gls-editor li,.gls-lesson-view li,.gls-print-paper li{display:list-item;margin:5px 0;overflow-wrap:anywhere}.gls-pdf-frame{position:fixed;left:-99999px;top:0;width:1px;height:1px;border:0;opacity:0}
/* 12.15: force generated Lesen direction in lesson view and print */
.gls-lesson-view .gls-ai-reading,.gls-lesson-view .gls-ai-reading *,.gls-print-paper .gls-ai-reading,.gls-print-paper .gls-ai-reading *{direction:ltr!important;text-align:left!important;unicode-bidi:isolate!important;}
.gls-lesson-view .gls-ai-reading{font-family:Tahoma,Arial,sans-serif!important;}
.gls-lesson-view .gls-ai-reading p,.gls-lesson-view .gls-ai-reading div,.gls-lesson-view .gls-ai-reading span,.gls-lesson-view .gls-ai-reading li{direction:ltr!important;text-align:left!important;unicode-bidi:plaintext!important;}
@media(max-width:800px){.gls-student-full{padding:0;background:#fff}.gls-shell{border-radius:0;padding:0;box-shadow:none}.gls-inner{border-radius:0}.gls-top{border-radius:0;padding:15px 16px}.gls-top h1,.gls-top h2{font-size:17px}.gls-grid{grid-template-columns:1fr;min-height:auto}.gls-sidebar{display:none}.gls-mobile-session-picker{display:block}.gls-main{padding:14px 14px 82px}.gls-tabs{position:sticky;top:0;background:#fff;z-index:3;padding:8px 0;display:grid;grid-template-columns:repeat(3,1fr);gap:7px;overflow:visible;flex-wrap:wrap}.gls-tab{white-space:normal;text-align:center;padding:10px 6px;font-size:12px;border-radius:12px}.gls-items{grid-template-columns:1fr}.gls-admin-row{grid-template-columns:1fr}.gls-table,.gls-table tbody,.gls-table tr,.gls-table td{display:block;width:100%}.gls-table thead{display:none}.gls-table td{border:0!important;border-bottom:1px solid #e8eaf0!important;border-radius:0!important}.gls-editor{min-height:55vh;font-size:17px;padding:16px}.gls-toolbar{top:0;overflow:auto;flex-wrap:nowrap;border-radius:14px}.gls-toolbar-group{flex:0 0 auto}.gls-color-palette{right:auto;left:0}.gls-tool{flex:0 0 38px}.gls-tool-select{flex:0 0 auto}.gls-box{padding:14px;border-radius:17px}.gls-lesson-view{font-size:16px;line-height:2}@media(max-width:420px){.gls-tabs{grid-template-columns:repeat(2,1fr)}}}@media print{@page{size:A4;margin:18mm 14mm 16mm 14mm}.gls-wrap>*:not(.gls-print-area),.gls-no-print{display:none!important}.gls-print-area{display:block!important;direction:rtl;text-align:right;color:#111;font-family:"IRANSansXFaNum",Tahoma,sans-serif!important}.gls-print-paper{display:block!important;font-size:12.5pt;line-height:2;overflow:visible!important}.gls-print-paper:after{content:"صفحه " counter(page);position:fixed;bottom:0;left:0;right:0;text-align:center;font-size:10pt;color:#777}.gls-print-paper *{max-width:100%!important;overflow-wrap:anywhere}.gls-print-paper img{max-width:100%!important;height:auto!important}.gls-print-paper img.emoji,.gls-print-paper img.wp-smiley{width:1em!important;height:1em!important;max-width:1em!important;max-height:1em!important;display:inline!important;vertical-align:-.12em!important;margin:0 .06em!important}}
/* ==== GLS 1.4.0 UX refinements ==== */
.gls-btn.pdf{background:linear-gradient(135deg,#ffce00,#ffdf63);color:#111;border:1px solid #e5b900;box-shadow:0 10px 24px rgba(255,206,0,.22)}
.gls-btn.pdf:hover{background:#111;color:#fff;box-shadow:0 10px 24px rgba(17,17,17,.18)}
.gls-session-card.is-empty{opacity:.55;filter:saturate(.65);background:linear-gradient(180deg,#fff,#fafafa)}
.gls-session-card.is-empty:after{content:"هنوز آماده نیست";position:absolute;left:12px;bottom:12px;background:#f1f2f5;color:#667085;border-radius:999px;padding:3px 8px;font-size:10px}
.gls-dashboard{display:grid;grid-template-columns:1.1fr .9fr;gap:14px;margin-bottom:16px}
.gls-dashboard-card{border:1px solid #e8eaf0;border-radius:22px;background:#fff;box-shadow:var(--shadow);padding:16px;overflow:hidden}
.gls-dashboard-card h3{margin:0 0 10px;font-size:16px;color:#111}
.gls-booking-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:10px}
.gls-booking-card{border:1px solid #e8eaf0;border-radius:18px;background:linear-gradient(180deg,#fff,#fbfbfc);padding:13px;position:relative;overflow:hidden}
.gls-booking-card:before{content:"";position:absolute;inset:0 0 auto 0;height:4px;background:linear-gradient(90deg,#111 0 33%,#dd0000 33% 66%,#ffce00 66% 100%)}
.gls-booking-card h4{margin:7px 0 8px;font-size:14px;line-height:1.7}
.gls-booking-date{display:flex;flex-wrap:wrap;gap:6px;color:#667085;font-size:12px;margin-bottom:10px}
.gls-link-locked{display:inline-flex;align-items:center;gap:6px;border-radius:999px;background:#f4f5f7;color:#667085;padding:7px 10px;font-size:12px;font-weight:850}
.gls-performance-card{display:grid;grid-template-columns:82px 1fr;gap:14px;align-items:center}
.gls-ring{width:82px;height:82px;border-radius:50%;display:grid;place-items:center;background:conic-gradient(#dd0000 calc(var(--score)*1%),#eef0f4 0);position:relative}
.gls-ring:after{content:"";position:absolute;width:62px;height:62px;border-radius:50%;background:#fff}
.gls-ring span{position:relative;z-index:1;font-size:18px;font-weight:950;color:#111}
.gls-perf-bars{display:flex;flex-direction:column;gap:8px}
.gls-perf-row{display:grid;grid-template-columns:70px 1fr 42px;gap:8px;align-items:center;font-size:12px;color:#475467}
.gls-bar{height:8px;background:#eef0f4;border-radius:999px;overflow:hidden}
.gls-bar i{display:block;height:100%;background:linear-gradient(90deg,#111,#dd0000,#ffce00);border-radius:999px}
.gls-resource-grid,.gls-exercise-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:12px}
.gls-material-card{border:1px solid #e8eaf0;border-radius:20px;background:#fff;box-shadow:0 8px 22px rgba(16,24,40,.055);padding:15px;position:relative;overflow:hidden}
.gls-material-card:before{content:"";position:absolute;right:0;top:0;bottom:0;width:5px;background:#111}
.gls-material-card.exercise:before{background:#dd0000}
.gls-material-card.resource:before{background:#ffce00}
.gls-material-icon{width:38px;height:38px;border-radius:14px;display:grid;place-items:center;background:#f4f5f7;margin-bottom:10px;font-size:20px}
.gls-material-card h4{margin:0 0 7px;font-size:15px;line-height:1.7}
.gls-material-card small{color:#667085}
.gls-homework-hero{border-radius:22px;background:linear-gradient(135deg,#111,#2a2a2a);color:#fff;padding:18px;margin-bottom:16px;position:relative;overflow:hidden}
.gls-homework-hero:after{content:"";position:absolute;width:150px;height:150px;border-radius:50%;background:rgba(255,206,0,.18);left:-40px;top:-50px}
.gls-homework-hero h3{color:#fff;margin:0 0 6px}
.gls-homework-hero p{margin:0;color:#ffe680}
.gls-homework-form{border:1px solid #e8eaf0;border-radius:20px;padding:15px;background:#fff;box-shadow:var(--shadow)}
.gls-upload-zone{border:1px dashed #cfd4dc;background:#fafbfc;border-radius:18px;padding:14px}
.gls-homework-entry{box-shadow:0 8px 22px rgba(16,24,40,.05)}
.gls-homework-toggle{background:linear-gradient(180deg,#fff,#f8f9fb)}
.gls-homework-toggle strong{color:#111}
.gls-hidden-section{display:none!important}
.gls-test-wrap{border:1px solid #e8eaf0;border-radius:20px;padding:14px;background:#fff;overflow:hidden}
@media(max-width:800px){.gls-dashboard{grid-template-columns:1fr;margin:12px}.gls-booking-list{grid-template-columns:1fr}.gls-dashboard-card{border-radius:18px}.gls-performance-card{grid-template-columns:70px 1fr}.gls-ring{width:70px;height:70px}.gls-ring:after{width:52px;height:52px}.gls-perf-row{grid-template-columns:62px 1fr 36px}.gls-resource-grid,.gls-exercise-grid{grid-template-columns:1fr}.gls-btn.pdf{width:100%;padding:12px}.gls-lesson-actions{justify-content:stretch}.gls-lesson-actions .gls-btn{flex:1}}
/* ==== GLS 1.4.2 mobile navigation + compact dashboard ==== */
.gls-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:12px}
.gls-card-head h3{margin:0 0 3px!important}
.gls-card-head small{color:#667085;font-size:12px}
.gls-count-badge{display:inline-flex;align-items:center;justify-content:center;min-width:30px;height:30px;border-radius:999px;background:#111;color:#ffce00;font-size:12px;font-weight:950}
.gls-more-booking{display:none}
.gls-bookings-expanded .gls-more-booking{display:flex}
.gls-toggle-bookings{width:100%;margin-top:10px}
.gls-booking-card{display:flex;align-items:center;justify-content:space-between;gap:12px}
.gls-booking-main{min-width:0}
.gls-booking-main h4{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.gls-homework-history{margin-top:18px}
.gls-homework-timeline{position:relative;display:flex;flex-direction:column;gap:12px}
.gls-homework-entry{border:1px solid #e8eaf0;border-radius:20px;background:#fff;box-shadow:0 12px 28px rgba(16,24,40,.06);overflow:hidden}
.gls-homework-toggle{display:grid;grid-template-columns:42px 1fr auto auto;align-items:center;gap:10px;padding:14px;background:linear-gradient(135deg,#fff,#fafafa);border:0;width:100%;cursor:pointer;text-align:right}
.gls-homework-num{width:38px;height:38px;border-radius:14px;display:grid;place-items:center;background:#111;color:#ffce00;font-weight:950}
.gls-homework-title{display:flex;flex-direction:column;gap:2px;min-width:0}
.gls-homework-title strong{font-size:14px;color:#111}
.gls-homework-title small{font-size:11px;color:#667085}
.gls-grade-chip{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;background:#ffce00;color:#111;padding:5px 9px;font-size:11px;font-weight:950;white-space:nowrap}
.gls-homework-body{padding:15px 16px;background:#fff;border-top:1px solid #edf0f4}
.gls-homework-answer{background:#fafbfc;border:1px solid #eef0f4;border-radius:16px;padding:12px;margin-bottom:12px}
.gls-correction-card{border:1px solid rgba(221,0,0,.14);background:linear-gradient(180deg,#fff,#fff7f7);border-radius:16px;padding:12px;margin-top:12px}
.gls-correction-head{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:8px;color:#111}
.gls-correction-head span{background:#111;color:#ffce00;border-radius:999px;padding:4px 9px;font-size:11px}
.gls-homework-hero{display:flex;align-items:center;justify-content:space-between;gap:14px}
.gls-homework-hero-icon{position:relative;z-index:1;width:54px;height:54px;border-radius:20px;display:grid;place-items:center;background:#ffce00;color:#111;font-size:24px;box-shadow:0 12px 26px rgba(255,206,0,.22)}
.gls-mobile-bottom-nav{display:none}
@media(min-width:801px){.gls-dashboard{align-items:start}.gls-bookings-panel .gls-booking-list{grid-template-columns:1fr}.gls-booking-card{min-height:78px}}
@media(max-width:800px){
  .gls-student-full{padding-bottom:88px}
  .gls-dashboard{display:block;margin:0 12px 8px}
  .gls-dashboard-card{display:none}
  .gls-dashboard-card.gls-mobile-active{display:block;animation:glsFadeUp .18s ease-out}.gls-panel-empty{padding:26px 20px;margin:14px 0;border:1px dashed #cbd5e1;border-radius:16px;background:#f8fafc;color:#475569;text-align:center;line-height:2;font-size:.98rem}.gls-tab-recovery{display:block}
  .gls-mobile-bottom-nav{position:fixed;right:10px;left:10px;bottom:10px;z-index:99990;display:grid;grid-template-columns:repeat(4,1fr);gap:6px;background:rgba(17,17,17,.94);border:1px solid rgba(255,206,0,.24);box-shadow:0 18px 40px rgba(0,0,0,.25);backdrop-filter:saturate(180%) blur(12px);border-radius:24px;padding:8px;direction:rtl}
  .gls-mobile-nav-btn{border:0;border-radius:18px;background:transparent;color:#fff;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;min-height:52px;font-family:inherit!important;font-weight:850;cursor:pointer}
  .gls-mobile-nav-btn span{font-size:18px;line-height:1}
  .gls-mobile-nav-btn b{font-size:10px;line-height:1.2}
  .gls-mobile-nav-btn.active{background:#ffce00;color:#111;box-shadow:0 8px 20px rgba(255,206,0,.25)}
  .gls-dashboard-card{box-shadow:0 14px 34px rgba(16,24,40,.12)}
  .gls-booking-card{display:block}
  .gls-booking-main h4{white-space:normal}
  .gls-booking-card .gls-btn,.gls-booking-card .gls-link-locked{margin-top:8px}
  .gls-homework-toggle{grid-template-columns:38px 1fr;grid-template-areas:"num title" "status grade";align-items:center}
  .gls-homework-num{grid-area:num;width:36px;height:36px}
  .gls-homework-title{grid-area:title}
  .gls-homework-toggle .gls-status{grid-area:status;justify-self:start}
  .gls-grade-chip{grid-area:grade;justify-self:end}
  .gls-homework-hero{align-items:flex-start}
  .gls-homework-hero-icon{width:46px;height:46px;border-radius:16px;font-size:20px;flex:0 0 auto}
}
@keyframes glsFadeUp{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

/* ==== GLS 1.4.3 visual polish: German flag UI, mobile nav, homework cards ==== */
.gls-btn.pdf{
  position:relative;
  overflow:hidden;
  min-height:42px;
  padding:11px 16px;
  border:1px solid rgba(17,17,17,.08)!important;
  background:
    linear-gradient(135deg,rgba(255,255,255,.16),rgba(255,255,255,0) 45%),
    linear-gradient(135deg,#111 0%,#2a2a2a 45%,#ffce00 46%,#ffdb4d 100%)!important;
  color:#fff!important;
  box-shadow:0 14px 30px rgba(17,17,17,.16),0 6px 14px rgba(255,206,0,.18)!important;
}
.gls-btn.pdf:before{
  content:"";
  width:8px;
  align-self:stretch;
  min-height:18px;
  border-radius:999px;
  background:#dd0000;
  box-shadow:0 0 0 3px rgba(221,0,0,.09);
}
.gls-btn.pdf:hover{
  background:
    linear-gradient(135deg,rgba(255,255,255,.18),rgba(255,255,255,0) 50%),
    linear-gradient(135deg,#dd0000 0%,#b80000 45%,#111 46%,#242424 100%)!important;
  color:#fff!important;
  transform:translateY(-2px);
}
.gls-toggle-bookings{
  min-height:44px;
  border:1px solid rgba(255,206,0,.55)!important;
  background:
    linear-gradient(90deg,#111 0 9px,#dd0000 9px 18px,#ffce00 18px 27px,transparent 27px),
    linear-gradient(180deg,#fff,#fff9de)!important;
  color:#111!important;
  box-shadow:0 10px 24px rgba(255,206,0,.18)!important;
}
.gls-toggle-bookings:hover{
  background:
    linear-gradient(90deg,#111 0 9px,#dd0000 9px 18px,#ffce00 18px 27px,transparent 27px),
    linear-gradient(180deg,#111,#242424)!important;
  color:#ffce00!important;
}
.gls-homework-form{
  background:
    linear-gradient(#fff,#fff) padding-box,
    linear-gradient(135deg,#111,#dd0000,#ffce00) border-box!important;
  border:1px solid transparent!important;
}
.gls-homework-form p:last-child{
  margin-top:22px!important;
  padding-top:16px;
  border-top:1px solid #edf0f4;
}
.gls-homework-form button[type=submit]{
  min-height:46px;
  padding:12px 20px;
  border-radius:16px;
  background:
    linear-gradient(135deg,#dd0000,#a90000 56%,#111)!important;
  box-shadow:0 12px 26px rgba(221,0,0,.18)!important;
}
.gls-upload-zone{
  margin-bottom:0;
  background:
    linear-gradient(180deg,#fff,#fafafa)!important;
  border-color:#d7dbe5!important;
  box-shadow:inset 0 0 0 1px rgba(255,206,0,.08);
}
.gls-homework-history{
  margin-top:22px;
  padding:16px;
  border-radius:24px;
  background:
    radial-gradient(circle at top left,rgba(255,206,0,.16),transparent 28%),
    linear-gradient(180deg,#fff,#fbfbfc);
  border:1px solid #e8eaf0;
  box-shadow:0 14px 34px rgba(16,24,40,.08);
}
.gls-homework-timeline{
  gap:14px;
}
.gls-homework-entry{
  position:relative;
  border:0!important;
  border-radius:22px!important;
  background:
    linear-gradient(#fff,#fff) padding-box,
    linear-gradient(135deg,rgba(17,17,17,.88),rgba(221,0,0,.72),rgba(255,206,0,.86)) border-box!important;
  border:1px solid transparent!important;
  box-shadow:0 14px 30px rgba(16,24,40,.075)!important;
}
.gls-homework-entry:before{
  content:"";
  position:absolute;
  inset:0 0 auto 0;
  height:4px;
  background:linear-gradient(90deg,#111 0 33%,#dd0000 33% 66%,#ffce00 66% 100%);
}
.gls-homework-entry.open{
  box-shadow:0 18px 40px rgba(16,24,40,.11)!important;
}
.gls-homework-toggle{
  min-height:72px;
  background:
    linear-gradient(135deg,#fff 0%,#fff 64%,#fff7d6 100%)!important;
}
.gls-homework-num{
  border-radius:16px!important;
  background:
    linear-gradient(135deg,#111 0 54%,#dd0000 55% 100%)!important;
  color:#ffce00!important;
  box-shadow:0 10px 20px rgba(17,17,17,.18);
}
.gls-homework-title strong{
  font-size:15px!important;
}
.gls-homework-title small{
  color:#667085!important;
}
.gls-grade-chip{
  border:1px solid rgba(17,17,17,.08);
  background:linear-gradient(135deg,#ffce00,#ffe680)!important;
  box-shadow:0 8px 18px rgba(255,206,0,.20);
}
.gls-homework-body{
  background:
    linear-gradient(180deg,#fff,#fbfbfc)!important;
  padding:16px!important;
}
.gls-homework-answer{
  background:#fff!important;
  border:1px solid #eceff5!important;
  border-right:4px solid #111!important;
  box-shadow:0 8px 20px rgba(16,24,40,.04);
}
.gls-correction-card{
  border:1px solid transparent!important;
  background:
    linear-gradient(#fff,#fff) padding-box,
    linear-gradient(135deg,#111,#dd0000,#ffce00) border-box!important;
  box-shadow:0 12px 28px rgba(221,0,0,.07);
}
.gls-correction-head span{
  background:#ffce00!important;
  color:#111!important;
}
.gls-mobile-bottom-nav{
  background:
    linear-gradient(#ffffff,#ffffff) padding-box,
    linear-gradient(90deg,#111 0 33%,#dd0000 33% 66%,#ffce00 66% 100%) border-box!important;
  border:1px solid transparent!important;
  box-shadow:0 18px 42px rgba(16,24,40,.23)!important;
}
.gls-mobile-nav-btn{
  color:#333!important;
}
.gls-mobile-nav-btn span{
  width:28px;
  height:28px;
  border-radius:12px;
  display:grid;
  place-items:center;
  background:#f4f5f7;
  transition:.18s ease;
}
.gls-mobile-nav-btn b{
  color:#475467;
  transition:.18s ease;
}
.gls-mobile-nav-btn.active{
  background:
    linear-gradient(135deg,#111 0%,#252525 72%,#3a2b00 100%)!important;
  color:#ffce00!important;
  box-shadow:0 12px 26px rgba(17,17,17,.22)!important;
}
.gls-mobile-nav-btn.active span{
  background:#ffce00!important;
  color:#111!important;
  transform:translateY(-2px);
}
.gls-mobile-nav-btn.active b{
  color:#fff!important;
}
@media(max-width:800px){
  .gls-mobile-bottom-nav{
    right:12px!important;
    left:12px!important;
    bottom:12px!important;
    padding:7px!important;
    border-radius:26px!important;
  }
  .gls-homework-form p:last-child{
    margin-top:24px!important;
  }
  .gls-homework-form button[type=submit]{
    width:100%;
  }
  .gls-homework-history{
    padding:12px;
    border-radius:22px;
  }
  .gls-homework-toggle{
    padding:14px 12px!important;
    row-gap:10px!important;
  }
  .gls-homework-entry:before{
    height:5px;
  }
}


/* ==== GLS 1.4.4 clean mobile app UI + minimal homework history ==== */
.gls-btn.pdf,
.gls-toggle-bookings{
  min-height:46px!important;
  padding:12px 18px!important;
  border:0!important;
  border-radius:16px!important;
  background:linear-gradient(135deg,#dd0000,#a90000 56%,#111)!important;
  color:#fff!important;
  box-shadow:0 12px 26px rgba(221,0,0,.18)!important;
  font-weight:900!important;
}
.gls-btn.pdf:before{display:none!important;content:none!important}
.gls-btn.pdf:hover,
.gls-toggle-bookings:hover{
  background:linear-gradient(135deg,#111,#2b2b2b)!important;
  color:#fff!important;
  transform:translateY(-1px)!important;
  box-shadow:0 14px 28px rgba(17,17,17,.18)!important;
}
.gls-toggle-bookings{
  width:100%;
  margin-top:12px!important;
}
.gls-homework-form p:last-child{
  margin-top:24px!important;
  padding-top:16px!important;
  border-top:1px solid #eef0f4!important;
}
.gls-homework-form button[type=submit]{
  min-height:46px!important;
  padding:12px 20px!important;
  border-radius:16px!important;
  background:linear-gradient(135deg,#dd0000,#a90000 56%,#111)!important;
  color:#fff!important;
  box-shadow:0 12px 26px rgba(221,0,0,.18)!important;
}
.gls-upload-zone{
  margin-bottom:0!important;
}
.gls-homework-history{
  margin-top:20px!important;
  padding:0!important;
  border:1px solid #e9edf3!important;
  border-radius:18px!important;
  background:#fff!important;
  box-shadow:0 8px 24px rgba(16,24,40,.05)!important;
  overflow:hidden!important;
}
.gls-homework-history .gls-card-head{
  margin:0!important;
  padding:12px 14px!important;
  background:#f8fafc!important;
  border-bottom:1px solid #edf1f5!important;
}
.gls-homework-history .gls-card-head h3{
  font-size:14px!important;
  margin:0!important;
}
.gls-homework-history .gls-card-head small{
  font-size:11px!important;
}
.gls-homework-history .gls-count-badge{
  min-width:24px!important;
  height:24px!important;
  background:#eef2ff!important;
  color:#3446eb!important;
  font-size:11px!important;
}
.gls-homework-timeline{
  gap:0!important;
  background:#fff!important;
}
.gls-homework-entry{
  border:0!important;
  border-radius:0!important;
  background:#fff!important;
  box-shadow:none!important;
  border-bottom:1px solid #edf1f5!important;
}
.gls-homework-entry:last-child{
  border-bottom:0!important;
}
.gls-homework-entry:before{
  display:none!important;
}
.gls-homework-entry.open{
  box-shadow:none!important;
}
.gls-homework-toggle{
  min-height:54px!important;
  grid-template-columns:34px minmax(0,1fr) auto auto!important;
  gap:8px!important;
  padding:10px 12px!important;
  background:#fff!important;
  transition:background .16s ease!important;
}
.gls-homework-toggle:hover{
  background:#f9fafb!important;
}
.gls-homework-num{
  width:26px!important;
  height:26px!important;
  border-radius:9px!important;
  background:#f1f5f9!important;
  color:#475569!important;
  box-shadow:none!important;
  font-size:11px!important;
}
.gls-homework-title strong{
  font-size:13px!important;
  font-weight:850!important;
  color:#111827!important;
}
.gls-homework-title small{
  font-size:10px!important;
  color:#8a94a6!important;
}
.gls-homework-toggle .gls-status{
  padding:4px 8px!important;
  font-size:10px!important;
}
.gls-grade-chip{
  padding:4px 8px!important;
  border:1px solid #e5e7eb!important;
  background:#f8fafc!important;
  color:#475569!important;
  box-shadow:none!important;
  font-size:10px!important;
}
.gls-homework-body{
  padding:12px 14px 14px!important;
  background:#fbfcfe!important;
  border-top:1px solid #edf1f5!important;
}
.gls-homework-answer{
  margin:0 0 10px!important;
  padding:10px 12px!important;
  border:1px solid #eef1f5!important;
  border-right:3px solid #cbd5e1!important;
  border-radius:12px!important;
  background:#fff!important;
  box-shadow:none!important;
}
.gls-correction-card{
  margin-top:10px!important;
  padding:10px 12px!important;
  border:1px solid #e8eef7!important;
  border-radius:12px!important;
  background:#fff!important;
  box-shadow:none!important;
}
.gls-correction-head{
  margin-bottom:6px!important;
}
.gls-correction-head span{
  background:#eef2ff!important;
  color:#3446eb!important;
  font-size:10px!important;
}
.gls-mobile-bottom-nav{
  display:none;
}
@media(max-width:800px){
  .gls-student-full{padding-bottom:78px!important}
  .gls-mobile-bottom-nav{
    position:fixed!important;
    right:14px!important;
    left:14px!important;
    bottom:12px!important;
    z-index:99990!important;
    display:grid!important;
    grid-template-columns:repeat(4,1fr)!important;
    gap:4px!important;
    direction:rtl!important;
    min-height:58px!important;
    padding:6px!important;
    border:1px solid rgba(148,163,184,.24)!important;
    border-radius:20px!important;
    background:rgba(255,255,255,.92)!important;
    box-shadow:0 14px 36px rgba(15,23,42,.18)!important;
    backdrop-filter:saturate(180%) blur(14px)!important;
    -webkit-backdrop-filter:saturate(180%) blur(14px)!important;
  }
  .gls-mobile-nav-btn{
    min-height:46px!important;
    border:0!important;
    border-radius:15px!important;
    background:transparent!important;
    color:#64748b!important;
    display:flex!important;
    flex-direction:column!important;
    align-items:center!important;
    justify-content:center!important;
    gap:2px!important;
    font-family:inherit!important;
    font-weight:850!important;
    cursor:pointer!important;
    transition:background .16s ease,color .16s ease,transform .16s ease!important;
  }
  .gls-mobile-nav-btn span{
    width:auto!important;
    height:auto!important;
    border-radius:0!important;
    display:block!important;
    background:transparent!important;
    font-size:16px!important;
    line-height:1!important;
    transform:none!important;
  }
  .gls-mobile-nav-btn b{
    color:inherit!important;
    font-size:9.5px!important;
    line-height:1.25!important;
    transition:none!important;
  }
  .gls-mobile-nav-btn.active{
    background:#eef2ff!important;
    color:#3446eb!important;
    box-shadow:none!important;
  }
  .gls-mobile-nav-btn.active span{
    background:transparent!important;
    color:inherit!important;
    transform:none!important;
  }
  .gls-mobile-nav-btn.active b{
    color:inherit!important;
  }
  .gls-mobile-nav-btn:active{
    transform:scale(.98)!important;
  }
  .gls-homework-history{
    border-radius:16px!important;
  }
  .gls-homework-toggle{
    grid-template-columns:30px minmax(0,1fr) auto!important;
    grid-template-areas:"num title grade" "status status status"!important;
    row-gap:7px!important;
    padding:10px!important;
  }
  .gls-homework-num{grid-area:num!important}
  .gls-homework-title{grid-area:title!important}
  .gls-homework-toggle .gls-status{
    grid-area:status!important;
    justify-self:start!important;
  }
  .gls-grade-chip{
    grid-area:grade!important;
    justify-self:end!important;
  }
  .gls-btn.pdf,
  .gls-toggle-bookings{
    width:100%!important;
  }
  .gls-homework-form button[type=submit]{
    width:100%!important;
  }
}


/* ==== GLS 1.5.0 integrated booking dashboard ==== */
.gls-booking-portal{
  margin:0 0 16px;
  border:1px solid #e6eaf0;
  border-radius:24px;
  background:linear-gradient(180deg,#fff,#fbfcff);
  box-shadow:0 14px 34px rgba(15,23,42,.08);
  overflow:hidden;
}
.gls-booking-portal[open]{box-shadow:0 20px 48px rgba(15,23,42,.12)}
.gls-booking-summary{
  list-style:none;
  cursor:pointer;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:14px;
  padding:16px 18px;
  user-select:none;
  background:
    radial-gradient(circle at left top,rgba(255,206,0,.12),transparent 26%),
    linear-gradient(180deg,#fff,#f8fafc);
}
.gls-booking-summary::-webkit-details-marker{display:none}
.gls-booking-summary-main{display:flex;align-items:center;gap:12px;min-width:0}
.gls-booking-icon{
  width:42px;height:42px;border-radius:16px;display:grid;place-items:center;
  background:linear-gradient(135deg,#f8fafc,#eef2f7);
  color:#1e293b;font-size:20px;box-shadow:inset 0 0 0 1px rgba(148,163,184,.20);
}
.gls-booking-summary h3{margin:0 0 3px!important;font-size:16px!important;line-height:1.5;color:#111827}
.gls-booking-summary small{display:block;color:#64748b;font-size:12px;line-height:1.7}
.gls-booking-cta{
  flex:0 0 auto;
  min-height:40px;
  padding:9px 14px;
  border-radius:14px;
  background:linear-gradient(135deg,#dd0000,#a90000 56%,#111);
  color:#fff;
  font-size:12px;
  font-weight:950;
  box-shadow:0 12px 24px rgba(221,0,0,.22);
}
.gls-booking-portal[open] .gls-booking-cta{background:linear-gradient(135deg,#111827,#334155)}
.gls-booking-embed{padding:0 16px 18px;background:#fff}
.gls-booking-unavailable{padding:18px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;border-radius:18px;margin:16px}
.gls-booking-portal .gtbp-wrapper{
  max-width:none!important;
  width:100%!important;
  margin:0!important;
  padding:18px!important;
  border:1px solid #edf1f7!important;
  border-radius:20px!important;
  box-shadow:none!important;
  background:#fff!important;
}
.gls-booking-portal .gtbp-step-title{color:#111827!important;border-bottom-color:#edf1f7!important;font-size:16px!important}
.gls-booking-portal .gtbp-date-box{border-radius:14px!important;border-color:#e6eaf0!important;background:#fff!important;box-shadow:0 5px 14px rgba(15,23,42,.04)}
.gls-booking-portal .gtbp-date-box:hover:not(.disabled){border-color:#dd0000!important;background:#fff6f6!important}
.gls-booking-portal .gtbp-date-box.selected{border-color:#dd0000!important;background:#fff5f5!important;color:#a90000!important}
.gls-booking-portal .gtbp-slot-btn,.gls-booking-portal button{
  border-radius:14px!important;
}
.gls-booking-portal .gtbp-slot-btn.selected,
.gls-booking-portal .gtbp-submit-btn,
.gls-booking-portal .gtbp-payment-btn,
.gls-booking-portal .gtbp-btn-primary{
  background:linear-gradient(135deg,#dd0000,#a90000 56%,#111)!important;
  color:#fff!important;
  border:0!important;
  box-shadow:0 10px 24px rgba(221,0,0,.20)!important;
}
.gls-booking-portal input,.gls-booking-portal select,.gls-booking-portal textarea{
  border-radius:14px!important;
  border-color:#dbe2ea!important;
}
.gls-dashboard{grid-template-columns:1fr 1fr!important}.gls-booking-portal{grid-column:1/-1}
.gls-mobile-nav-btn[data-target="reserve"] span{color:#dd0000}
@media(max-width:800px){
  .gls-booking-portal{margin:0 12px 12px;border-radius:22px;display:none}
  .gls-no-sessions .gls-booking-portal{display:block}
  .gls-booking-portal.gls-mobile-active{display:block;animation:glsFadeUp .18s ease-out}
  .gls-booking-summary{padding:14px}
  .gls-booking-icon{width:38px;height:38px;border-radius:14px;font-size:18px}
  .gls-booking-summary h3{font-size:14px!important}
  .gls-booking-summary small{font-size:11px}
  .gls-booking-cta{padding:8px 10px;font-size:11px;min-height:36px}
  .gls-booking-embed{padding:0 10px 12px}
  .gls-booking-portal .gtbp-wrapper{padding:12px!important;border-radius:18px!important}
  .gls-mobile-bottom-nav{grid-template-columns:repeat(5,1fr)!important}
  .gls-mobile-nav-btn{min-height:44px!important;border-radius:14px!important}
  .gls-mobile-nav-btn span{font-size:15px!important}
  .gls-mobile-nav-btn b{font-size:9px!important}
}


/* ==== GLS 1.5.1 full-screen dashboard, mobile single-view, compact sessions ==== */
.gls-student-full{
  width:100vw!important;
  min-height:100vh!important;
  margin-right:calc(50% - 50vw)!important;
  margin-left:calc(50% - 50vw)!important;
  padding:0!important;
  background:#f6f7fb!important;
}
.gls-student-full>.gls-shell{
  max-width:none!important;
  width:100%!important;
  margin:0!important;
  padding:0!important;
  border-radius:0!important;
  background:#f6f7fb!important;
  box-shadow:none!important;
}
.gls-student-full .gls-inner{
  min-height:100vh!important;
  border-radius:0!important;
  background:#f6f7fb!important;
  overflow:visible!important;
}
.gls-student-full .gls-top{
  border-radius:0!important;
}
.gls-student-full .gls-dashboard,
.gls-student-full .gls-booking-portal,
.gls-student-full .gls-grid,
.gls-student-full .gls-mobile-session-picker,
.gls-student-full>#gls-mobile-content{
  max-width:1360px;
  margin-left:auto;
  margin-right:auto;
}
.gls-student-full .gls-grid{
  background:#fff;
  border-top:1px solid #edf0f4;
}
.gls-sidebar{
  width:100%;
  padding:12px!important;
}
.gls-grid{
  grid-template-columns:280px 1fr!important;
}
.gls-session-card{
  border-radius:14px!important;
  padding:9px 10px 10px!important;
  margin-bottom:7px!important;
  box-shadow:0 4px 12px rgba(16,24,40,.035)!important;
}
.gls-session-card:before{
  height:3px!important;
}
.gls-session-card h3{
  margin:4px 0 5px!important;
  font-size:12.5px!important;
  line-height:1.55!important;
  font-weight:850!important;
}
.gls-session-card .gls-meta{
  gap:4px!important;
  font-size:10.5px!important;
}
.gls-session-card .gls-meta span{
  padding:2px 6px!important;
}
.gls-session-time{
  margin-top:5px!important;
  padding:3px 7px!important;
  font-size:10px!important;
}
.gls-bookings-expanded .gls-more-booking{
  display:flex!important;
}
.gls-more-booking{
  display:none!important;
}
@media(max-width:800px){
  .gls-student-full{
    min-height:100svh!important;
    padding:0 0 76px!important;
  }
  .gls-student-full .gls-inner{
    min-height:100svh!important;
  }
  .gls-student-full .gls-top{
    display:none!important;
  }
  .gls-student-full .gls-dashboard,
  .gls-student-full .gls-booking-portal,
  .gls-student-full .gls-grid,
  .gls-student-full .gls-mobile-session-picker,
  .gls-student-full>#gls-mobile-content{
    max-width:none!important;
    margin:0!important;
  }
  .gls-student-full.gls-mobile-mode-content .gls-dashboard,
  .gls-student-full.gls-mobile-mode-content .gls-booking-portal,
  .gls-student-full.gls-mobile-mode-content #gls-mobile-sessions,
  .gls-student-full.gls-mobile-mode-content > .gls-shell > .gls-inner > #gls-mobile-content{
    display:none!important;
  }
  .gls-student-full.gls-mobile-mode-content .gls-grid{
    display:grid!important;
  }

  .gls-student-full.gls-mobile-mode-reserve .gls-dashboard,
  .gls-student-full.gls-mobile-mode-reserve #gls-mobile-sessions,
  .gls-student-full.gls-mobile-mode-reserve .gls-grid,
  .gls-student-full.gls-mobile-mode-reserve > .gls-shell > .gls-inner > #gls-mobile-content{
    display:none!important;
  }
  .gls-student-full.gls-mobile-mode-reserve .gls-booking-portal{
    display:block!important;
  }

  .gls-student-full.gls-mobile-mode-sessions .gls-dashboard,
  .gls-student-full.gls-mobile-mode-sessions .gls-booking-portal,
  .gls-student-full.gls-mobile-mode-sessions .gls-grid,
  .gls-student-full.gls-mobile-mode-sessions > .gls-shell > .gls-inner > #gls-mobile-content{
    display:none!important;
  }
  .gls-student-full.gls-mobile-mode-sessions #gls-mobile-sessions{
    display:block!important;
  }

  .gls-student-full.gls-mobile-mode-bookings .gls-booking-portal,
  .gls-student-full.gls-mobile-mode-bookings #gls-mobile-sessions,
  .gls-student-full.gls-mobile-mode-bookings .gls-grid,
  .gls-student-full.gls-mobile-mode-bookings > .gls-shell > .gls-inner > #gls-mobile-content{
    display:none!important;
  }
  .gls-student-full.gls-mobile-mode-bookings .gls-dashboard{
    display:block!important;
  }
  .gls-student-full.gls-mobile-mode-bookings #gls-mobile-bookings{
    display:block!important;
  }

  .gls-student-full.gls-mobile-mode-performance .gls-booking-portal,
  .gls-student-full.gls-mobile-mode-performance #gls-mobile-sessions,
  .gls-student-full.gls-mobile-mode-performance .gls-grid,
  .gls-student-full.gls-mobile-mode-performance > .gls-shell > .gls-inner > #gls-mobile-content{
    display:none!important;
  }
  .gls-student-full.gls-mobile-mode-performance .gls-dashboard{
    display:block!important;
  }
  .gls-student-full.gls-mobile-mode-performance #gls-mobile-performance{
    display:block!important;
  }

  .gls-dashboard-card,
  .gls-booking-portal{
    border-radius:0!important;
    border-left:0!important;
    border-right:0!important;
    box-shadow:none!important;
  }
  .gls-main{
    padding:10px 10px 90px!important;
  }
  .gls-mobile-session-picker{
    padding:12px!important;
    background:#f6f7fb!important;
  }
  .gls-session-jump{
    border:1px solid #e5e7eb!important;
    border-radius:18px!important;
    background:#fff!important;
    padding:10px!important;
    box-shadow:0 10px 24px rgba(15,23,42,.06)!important;
  }
}


/* ==== GLS 1.5.2 advanced dashboard layout, full-width panels, cadence analytics ==== */
.gls-desktop-top-nav{display:flex;gap:8px;align-items:center;padding:12px 18px;background:#fff;border-bottom:1px solid #e8edf3;position:sticky;top:0;z-index:30;box-shadow:0 8px 22px rgba(15,23,42,.04)}
.gls-desktop-nav-btn{border:0;border-radius:14px;background:#f5f7fb;color:#475569;padding:10px 14px;font-weight:900;cursor:pointer;transition:.16s ease;min-height:42px}
.gls-desktop-nav-btn:hover{background:#fff0f0;color:#dd0000}
.gls-desktop-nav-btn.active{background:#111827;color:#fff;box-shadow:0 10px 24px rgba(15,23,42,.16)}
.gls-student-full .gls-dashboard,.gls-student-full .gls-booking-portal,.gls-student-full .gls-grid,.gls-student-full .gls-mobile-session-picker{max-width:none!important;width:100%!important;margin-left:0!important;margin-right:0!important}
.gls-student-full .gls-dashboard{padding:16px 18px;margin-bottom:0!important}
.gls-student-full .gls-booking-portal{border-radius:0!important;border-left:0!important;border-right:0!important;margin:0!important}
.gls-student-full .gls-grid{min-height:calc(100vh - 146px)!important;grid-template-columns:260px minmax(0,1fr)!important}
.gls-main{width:100%!important;max-width:none!important}.gls-main>.gls-box,.gls-main>.gls-panel,.gls-main section.gls-box{width:100%!important;max-width:none!important}.gls-lesson-view{width:100%!important}
.gls-sidebar{padding:10px!important}.gls-session-card{padding:8px 9px!important;margin-bottom:6px!important;border-radius:13px!important}.gls-session-card h3{font-size:12px!important;margin:3px 0 4px!important}.gls-session-card .gls-meta span{font-size:10px!important;padding:2px 5px!important}.gls-session-time{font-size:9.5px!important;padding:2px 6px!important}
.gls-mobile-session-picker{display:none;background:#fff;padding:18px}.gls-session-list-head{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;margin-bottom:12px}.gls-session-list-head h3{margin:0!important;font-size:16px!important}.gls-session-list-head small{color:#64748b;font-size:12px}.gls-mobile-session-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:10px}.gls-mobile-session-row{display:grid;grid-template-columns:10px minmax(0,1fr) auto;align-items:center;gap:10px;padding:11px 12px;border:1px solid #e8edf3;border-radius:16px;background:#fff;text-decoration:none;color:#111827;box-shadow:0 8px 20px rgba(15,23,42,.04);transition:.16s ease}.gls-mobile-session-row:hover{border-color:#c7d2fe;background:#f8fbff;transform:translateY(-1px)}.gls-mobile-session-row.active{border-color:#2563eb;background:#eff6ff}.gls-mobile-session-row.is-empty{opacity:.58}.gls-mobile-session-dot{width:9px;height:9px;border-radius:50%;background:#cbd5e1}.gls-mobile-session-row.has-lesson .gls-mobile-session-dot{background:#16a34a}.gls-mobile-session-info{min-width:0}.gls-mobile-session-info strong{display:block;font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.gls-mobile-session-info small{display:block;color:#64748b;font-size:10.5px;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.gls-mobile-session-state{font-size:10px;color:#64748b;background:#f1f5f9;border-radius:999px;padding:4px 7px;white-space:nowrap}.gls-mobile-session-row.active .gls-mobile-session-state{background:#dbeafe;color:#1d4ed8}
.gls-cadence-card{margin-top:14px;border:1px solid #e8edf3;border-radius:18px;background:linear-gradient(180deg,#fff,#f8fafc);padding:14px;display:grid;grid-template-columns:76px minmax(0,1fr);gap:14px;align-items:center}.gls-cadence-ring{width:76px;height:76px;border-radius:50%;display:grid;place-items:center;background:conic-gradient(#2563eb calc(var(--cadence)*1%),#e8edf3 0);position:relative}.gls-cadence-ring:after{content:"";position:absolute;width:56px;height:56px;border-radius:50%;background:#fff}.gls-cadence-ring span{position:relative;z-index:1;font-size:12px;font-weight:950;color:#111827;text-align:center}.gls-cadence-text strong{display:block;font-size:14px;color:#111827;margin-bottom:4px}.gls-cadence-text p{margin:0;color:#475569;font-size:12.5px;line-height:1.8}.gls-cadence-meta{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px}.gls-cadence-meta span{font-size:10.5px;color:#64748b;background:#eef2f7;border-radius:999px;padding:4px 7px}
@media(min-width:801px){
  .gls-student-full.gls-mobile-mode-content .gls-dashboard,.gls-student-full.gls-mobile-mode-content .gls-booking-portal,.gls-student-full.gls-mobile-mode-content #gls-mobile-sessions{display:none!important}.gls-student-full.gls-mobile-mode-content .gls-grid{display:grid!important}
  .gls-student-full.gls-mobile-mode-reserve .gls-dashboard,.gls-student-full.gls-mobile-mode-reserve #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-reserve .gls-grid{display:none!important}.gls-student-full.gls-mobile-mode-reserve .gls-booking-portal{display:block!important}
  .gls-student-full.gls-mobile-mode-sessions .gls-dashboard,.gls-student-full.gls-mobile-mode-sessions .gls-booking-portal,.gls-student-full.gls-mobile-mode-sessions .gls-grid{display:none!important}.gls-student-full.gls-mobile-mode-sessions #gls-mobile-sessions{display:block!important}
  .gls-student-full.gls-mobile-mode-bookings .gls-booking-portal,.gls-student-full.gls-mobile-mode-bookings #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-bookings .gls-grid{display:none!important}.gls-student-full.gls-mobile-mode-bookings .gls-dashboard{display:block!important}.gls-student-full.gls-mobile-mode-bookings #gls-mobile-bookings{display:block!important;max-width:760px;margin:auto}
  .gls-student-full.gls-mobile-mode-performance .gls-booking-portal,.gls-student-full.gls-mobile-mode-performance #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-performance .gls-grid{display:none!important}.gls-student-full.gls-mobile-mode-performance .gls-dashboard{display:block!important}.gls-student-full.gls-mobile-mode-performance #gls-mobile-performance{display:block!important;max-width:760px;margin:auto}
}
@media(max-width:800px){
  .gls-desktop-top-nav{display:none!important}.gls-student-full{background:#f6f7fb!important}.gls-student-full .gls-dashboard{padding:10px!important;margin:0!important}.gls-student-full .gls-grid{width:100%!important;min-height:auto!important;grid-template-columns:1fr!important;background:#fff!important}.gls-main{padding:10px 10px 86px!important;width:100%!important}.gls-main>.gls-box,.gls-main section.gls-box{border-radius:14px!important;padding:14px!important;width:100%!important}.gls-tabs{width:100%!important}.gls-panel.active{width:100%!important}.gls-booking-portal{width:100%!important;margin:0!important;border-radius:0!important}.gls-mobile-session-picker{padding:12px!important;width:100%!important}.gls-mobile-session-list{grid-template-columns:1fr!important;gap:8px}.gls-mobile-session-row{padding:10px!important;border-radius:14px}.gls-mobile-session-info strong{font-size:12px}.gls-session-list-head{display:block}.gls-session-list-head small{display:block;margin-top:4px}.gls-cadence-card{grid-template-columns:62px minmax(0,1fr);padding:12px;border-radius:16px}.gls-cadence-ring{width:62px;height:62px}.gls-cadence-ring:after{width:46px;height:46px}.gls-cadence-ring span{font-size:10px}.gls-cadence-text p{font-size:12px}
}

/* ==== GLS 1.6.1 stability + restored lesson tabs ==== */
html,body{max-width:100%!important;overflow-x:hidden!important}
.gls-wrap,.gls-student-full,.gls-shell,.gls-inner,.gls-dashboard,.gls-booking-portal,.gls-grid,.gls-main,.gls-sidebar,.gls-mobile-session-picker,.gls-mobile-bottom-nav{max-width:100%!important;box-sizing:border-box!important}
.gls-wrap{overflow-x:clip!important}
.gls-student-full{width:100%!important;max-width:100%!important;margin-left:0!important;margin-right:0!important;padding-left:0!important;padding-right:0!important;overflow-x:hidden!important}
.gls-shell{width:100%!important;max-width:none!important;margin:0!important;border-radius:0!important;padding-left:0!important;padding-right:0!important}
.gls-inner{width:100%!important;border-radius:0!important;overflow-x:hidden!important}
.gls-main,.gls-dashboard,.gls-booking-portal,#gls-mobile-sessions{min-width:0!important;overflow-x:hidden!important}
.gls-main *,.gls-dashboard *,.gls-booking-portal *{max-width:100%!important}
.gls-desktop-top-nav{justify-content:center!important;gap:6px!important;padding:10px 14px!important;background:rgba(255,255,255,.94)!important;backdrop-filter:saturate(180%) blur(10px)!important;border-bottom:1px solid #edf1f7!important;box-shadow:0 8px 24px rgba(15,23,42,.035)!important}
.gls-desktop-nav-btn{border-radius:999px!important;background:transparent!important;color:#475569!important;border:1px solid transparent!important;padding:8px 13px!important;min-height:36px!important;font-size:12px!important;font-weight:850!important;box-shadow:none!important}
.gls-desktop-nav-btn:hover{background:#f6f8fb!important;color:#111827!important;border-color:#e8edf3!important;transform:none!important}
.gls-desktop-nav-btn.active{background:#111827!important;color:#fff!important;border-color:#111827!important;box-shadow:0 8px 20px rgba(15,23,42,.11)!important}
.gls-tabs{width:100%!important;max-width:100%!important;overflow-x:auto!important;scrollbar-width:thin!important;flex-wrap:nowrap!important;padding-bottom:4px!important}
.gls-tab{flex:0 0 auto!important;white-space:nowrap!important}
.gls-ai-reading{direction:ltr!important;text-align:left!important;margin-top:22px;padding:18px;border:1px solid #e8edf3;border-radius:18px;background:#fffdf2;line-height:1.9;overflow-wrap:anywhere;word-break:normal}
.gls-ai-reading h3{direction:ltr!important;text-align:left!important;margin-top:0!important;color:#111827!important}
.gls-ai-reading-body{direction:ltr!important;text-align:left!important;unicode-bidi:plaintext!important}
.gls-ai-grammar{background:#fff3a3!important;border-radius:4px;padding:0 2px}
@media(max-width:800px){.gls-student-full{width:100%!important}.gls-top,.gls-dashboard,.gls-booking-portal,#gls-mobile-sessions,.gls-grid{width:100%!important;margin-left:0!important;margin-right:0!important}.gls-main{padding-left:10px!important;padding-right:10px!important}.gls-tabs{display:flex!important}.gls-tab{font-size:11.5px!important;padding:9px 10px!important}.gls-ai-reading{padding:14px;border-radius:14px;font-size:15px}.gls-mobile-bottom-nav{right:8px!important;left:8px!important;width:auto!important}}

/* ==== GLS 1.6.2 separated tasks/performance + clearer nav ==== */
.gls-desktop-top-nav{justify-content:center!important;background:#ffffff!important;border-bottom:1px solid #dbe3ef!important;box-shadow:0 10px 30px rgba(15,23,42,.08)!important;position:sticky!important;top:0!important;z-index:80!important;padding:12px 16px!important;gap:8px!important}
.gls-desktop-nav-btn{position:relative!important;background:#f7f9fc!important;color:#334155!important;border:1px solid #e5edf7!important;border-radius:999px!important;padding:9px 15px!important;min-height:38px!important;font-size:12.5px!important;font-weight:850!important;box-shadow:0 4px 12px rgba(15,23,42,.035)!important}
.gls-desktop-nav-btn.active{background:#111!important;color:#fff!important;border-color:#111!important;box-shadow:0 10px 24px rgba(15,23,42,.20)!important}
.gls-desktop-nav-btn.active:after{content:"";position:absolute;right:18px;left:18px;bottom:-12px;height:3px;border-radius:999px;background:#ffce00}
.gls-nav-badge,.gls-mobile-nav-btn i{display:inline-flex;align-items:center;justify-content:center;min-width:18px;height:18px;border-radius:999px;background:#ef4444;color:#fff;font-size:10px;font-style:normal;margin-right:6px;padding:0 5px}
.gls-taught-box{margin-top:16px;border:1px solid #e8edf3;border-radius:18px;background:#fff;padding:14px}
.gls-taught-list{list-style:none;margin:0;padding:0;display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px}
.gls-taught-list li{display:flex;align-items:center;gap:8px;border:1px solid #eef2f7;background:#f8fafc;border-radius:14px;padding:9px 10px;font-size:12.5px;color:#334155;min-width:0}
.gls-taught-list li span{width:20px;height:20px;border-radius:50%;display:grid;place-items:center;background:#dcfce7;color:#15803d;flex:0 0 auto}.gls-taught-list li b{font-weight:800;overflow-wrap:anywhere}
.gls-task-list{display:flex;flex-direction:column;gap:10px}.gls-task-row{border:1px solid #e8edf3;border-radius:16px;background:#fff;padding:12px;box-shadow:0 8px 20px rgba(15,23,42,.04)}.gls-task-row-head{display:flex;justify-content:space-between;gap:10px;align-items:center}.gls-task-row-head strong{font-size:13px}.gls-task-row-head small{color:#64748b;font-size:11px}.gls-task-chips{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px}.gls-task-chips span{background:#f1f5f9;border-radius:999px;padding:5px 8px;font-size:11px;color:#334155}.gls-task-sub-list{margin-top:10px;display:grid;gap:6px}.gls-task-sub-list div{display:flex;justify-content:space-between;gap:8px;background:#f8fafc;border-radius:12px;padding:7px 9px}.gls-task-sub-list b{font-size:11px}.gls-task-sub-list small{font-size:10.5px;color:#64748b}.gls-task-alert{margin-top:10px;background:#fff7ed;color:#9a3412;border-radius:12px;padding:8px 10px;font-size:11.5px}
@media(min-width:801px){
  .gls-student-full.gls-mobile-mode-tasks .gls-booking-portal,.gls-student-full.gls-mobile-mode-tasks #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-tasks .gls-grid{display:none!important}.gls-student-full.gls-mobile-mode-tasks .gls-dashboard{display:block!important}.gls-student-full.gls-mobile-mode-tasks #gls-mobile-tasks{display:block!important;max-width:900px;margin:auto}
  .gls-student-full.gls-mobile-mode-bookings #gls-mobile-performance,.gls-student-full.gls-mobile-mode-bookings #gls-mobile-tasks{display:none!important}
  .gls-student-full.gls-mobile-mode-performance #gls-mobile-bookings,.gls-student-full.gls-mobile-mode-performance #gls-mobile-tasks{display:none!important}
}
@media(max-width:800px){
  .gls-mobile-bottom-nav{grid-template-columns:repeat(6,1fr)!important;gap:3px!important;padding:6px!important;border-radius:20px!important}
  .gls-mobile-nav-btn{min-height:48px!important;border-radius:15px!important}.gls-mobile-nav-btn b{font-size:9.5px!important}.gls-mobile-nav-btn span{font-size:16px!important}.gls-mobile-nav-btn i{position:absolute;top:4px;left:6px;margin:0;min-width:16px;height:16px;font-size:9px}
  .gls-student-full.gls-mobile-mode-tasks .gls-booking-portal,.gls-student-full.gls-mobile-mode-tasks #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-tasks .gls-grid{display:none!important}.gls-student-full.gls-mobile-mode-tasks .gls-dashboard{display:block!important}.gls-student-full.gls-mobile-mode-tasks #gls-mobile-tasks{display:block!important}
  .gls-student-full.gls-mobile-mode-bookings #gls-mobile-performance,.gls-student-full.gls-mobile-mode-bookings #gls-mobile-tasks{display:none!important}
  .gls-student-full.gls-mobile-mode-performance #gls-mobile-bookings,.gls-student-full.gls-mobile-mode-performance #gls-mobile-tasks{display:none!important}
  .gls-taught-list{grid-template-columns:1fr}.gls-task-row{padding:10px;border-radius:14px}.gls-task-row-head{align-items:flex-start;flex-direction:column}.gls-task-sub-list div{display:block}
}


/* ==== GLS 1.6.3 nav badges, focused task tab, compact German-only learning list ==== */
.gls-desktop-nav-btn{display:inline-flex!important;align-items:center!important;gap:7px!important;line-height:1!important}
.gls-desktop-nav-btn b{font:inherit!important;font-weight:850!important;line-height:1!important}
.gls-desk-ico{display:inline-grid!important;place-items:center!important;width:22px!important;height:22px!important;border-radius:9px!important;background:#fff!important;border:1px solid #e8edf3!important;font-size:13px!important;line-height:1!important;box-shadow:0 3px 8px rgba(15,23,42,.04)!important}
.gls-desktop-nav-btn.active .gls-desk-ico{background:rgba(255,255,255,.16)!important;border-color:rgba(255,255,255,.28)!important;color:#fff!important;box-shadow:none!important}
.gls-mobile-nav-btn{position:relative!important}
.gls-mobile-nav-btn i{position:absolute!important;top:5px!important;right:50%!important;left:auto!important;transform:translateX(-12px)!important;margin:0!important;z-index:3!important;min-width:16px!important;height:16px!important;font-size:9px!important;padding:0 4px!important}
.gls-student-full.gls-mobile-mode-tasks .gls-dashboard-card{display:none!important}
.gls-student-full.gls-mobile-mode-tasks #gls-mobile-tasks{display:block!important}
.gls-student-full.gls-mobile-mode-bookings .gls-dashboard-card{display:none!important}
.gls-student-full.gls-mobile-mode-bookings #gls-mobile-bookings{display:block!important}
.gls-student-full.gls-mobile-mode-performance .gls-dashboard-card{display:none!important}
.gls-student-full.gls-mobile-mode-performance #gls-mobile-performance{display:block!important}
.gls-task-toggle{width:100%;display:flex;align-items:center;justify-content:space-between;gap:10px;border:0;background:transparent;text-align:right;cursor:pointer;padding:0;color:inherit;font-family:inherit!important}
.gls-task-row{transition:.16s ease}.gls-task-row.open{border-color:#cbd5e1;background:#fff}.gls-task-body{display:none;margin-top:12px;border-top:1px solid #eef2f7;padding-top:12px}.gls-task-row.open .gls-task-body{display:block}
.gls-task-mini-form{margin-top:12px;border:1px solid #eef2f7;background:#fbfcfe;border-radius:14px;padding:12px}.gls-task-mini-form textarea{min-height:84px!important}.gls-task-mini-form p:last-child{margin-top:16px!important;margin-bottom:0!important}.gls-task-mini-form button[type=submit]{background:linear-gradient(135deg,#dd0000,#a90000 56%,#111)!important;border-radius:12px!important;box-shadow:0 10px 22px rgba(221,0,0,.18)!important}
.gls-task-detail-box{background:#f8fafc;border:1px solid #eef2f7;border-radius:14px;padding:10px;margin-top:8px}.gls-task-detail-box h4{margin:0 0 6px!important;font-size:12.5px!important;color:#111827}.gls-task-detail-box p{margin-top:4px!important;margin-bottom:4px!important}.gls-task-sub-list button{border:0;background:transparent;padding:0;cursor:pointer;text-align:inherit;width:100%;display:flex;justify-content:space-between;gap:8px;color:inherit;font-family:inherit!important}
.gls-taught-list{grid-template-columns:repeat(auto-fit,minmax(160px,1fr))!important}.gls-taught-list li{padding:7px 9px!important;font-size:12px!important}.gls-taught-list li b{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:block}
@media(max-width:800px){.gls-desktop-nav-btn{gap:5px!important}.gls-task-toggle{align-items:flex-start}.gls-task-row-head{width:100%}.gls-task-sub-list button{display:block}.gls-mobile-nav-btn i{right:50%!important;transform:translateX(-10px)!important}}

/* ==== GLS 1.6.4 focused UI fixes ==== */
.gls-wrap,.gls-wrap *{max-width:100%;}
.gls-btn,.gls-btn:hover,.gls-btn:focus,.gls-btn:active{border-color:transparent!important;outline:none!important}
.gls-btn:not(.red):not(.gold),.gls-desktop-nav-btn,.gls-mobile-nav-btn{box-shadow:none!important}
.gls-btn:not(.red):not(.gold):hover{background:#f1f5f9!important;color:#0f172a!important;transform:none!important}
.gls-session-card:hover,.gls-session-card.active{border-color:#d9e2ee!important;box-shadow:0 8px 22px rgba(15,23,42,.075)!important;transform:none!important}
/* Fix #11: hide empty/disabled tabs completely */
.gls-tab.is-disabled{display:none!important}
.gls-tab-badge{display:inline-flex;align-items:center;justify-content:center;min-width:19px;height:19px;margin-right:6px;padding:0 6px;border-radius:999px;background:#e2e8f0;color:#334155;font-size:10px;font-weight:950}
.gls-tab.active .gls-tab-badge{background:#ffce00;color:#111827}
.gls-task-submit-card{border:1px solid #e8edf3;background:linear-gradient(180deg,#fff,#fbfdff);border-radius:18px;padding:14px;margin-bottom:14px;box-shadow:0 8px 20px rgba(15,23,42,.035)}
.gls-task-submit-card h4{margin:0 0 4px!important;font-size:14px!important;color:#111827}.gls-task-submit-card p{margin:0 0 10px!important;color:#64748b;font-size:12px;line-height:1.8}
.gls-task-main-form label{font-size:12px!important;margin:10px 0 6px!important}.gls-task-main-form textarea{min-height:92px!important}.gls-task-main-form p:last-child{margin-top:16px!important;margin-bottom:0!important}.gls-task-main-form button[type=submit]{background:linear-gradient(135deg,#dd0000,#a90000 56%,#111)!important;border-radius:12px!important;padding:10px 16px!important;box-shadow:0 10px 24px rgba(221,0,0,.16)!important}
.gls-task-sections{display:grid;grid-template-columns:1fr;gap:14px}.gls-task-section{border-top:1px solid #eef2f7;padding-top:12px}.gls-card-head.compact{margin-bottom:8px!important}.gls-card-head.compact h3{font-size:13.5px!important}.gls-card-head.compact small{font-size:11px!important;color:#64748b}
.gls-task-list{gap:7px!important}.gls-task-row{padding:0!important;border-radius:12px!important;box-shadow:none!important;border-color:#e9eef5!important}.gls-task-toggle{padding:9px 10px!important}.gls-task-row-head strong{font-size:12px!important}.gls-task-row-head small{font-size:10.5px!important}.gls-task-body{margin-top:0!important;padding:9px 10px!important}.gls-task-detail-box{padding:8px!important;border-radius:11px!important;margin-top:6px!important}.gls-task-detail-box h4{font-size:11.5px!important}.gls-task-sub-list{gap:5px!important}.gls-task-sub-list button{font-size:11px!important;background:#f8fafc!important;border-radius:10px!important;padding:6px 8px!important}.gls-empty.slim{padding:14px!important;border-radius:12px!important;font-size:12px!important}
.gls-student-full.gls-mobile-mode-tasks .gls-dashboard-card{display:none!important}.gls-student-full.gls-mobile-mode-tasks #gls-mobile-tasks{display:block!important}.gls-student-full.gls-mobile-mode-tasks #gls-mobile-bookings,.gls-student-full.gls-mobile-mode-tasks #gls-mobile-performance{display:none!important}
@media(max-width:800px){.gls-task-submit-card{padding:12px;border-radius:14px}.gls-task-sections{gap:12px}.gls-tabs{overflow-x:auto!important}.gls-tab{flex:0 0 auto!important}.gls-mobile-nav-btn i{position:absolute!important;top:4px!important;right:auto!important;left:50%!important;transform:translateX(-2px)!important;min-width:17px!important;height:17px!important;line-height:17px!important;font-size:9px!important}}


/* ==== GLS 1.6.5 task UX, print font, current session, compact lists ==== */
.gls-current-session-banner{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#ffffff;border:1px solid #e6eaf0;border-radius:18px;padding:11px 14px;margin:0 0 12px;box-shadow:0 8px 22px rgba(15,23,42,.045)}
.gls-current-session-banner .gls-current-session-mark{font-size:11px;background:#eef2ff;color:#3444d7;border-radius:999px;padding:4px 9px;font-weight:900}
.gls-current-session-banner strong{font-size:14px;color:#111827}.gls-current-session-banner small{color:#64748b;font-size:12px}
.gls-all-lessons-btn{margin-right:auto!important;background:linear-gradient(135deg,#dc0000,#a60000)!important;color:#fff!important;border:1px solid #930000!important;box-shadow:0 9px 22px rgba(190,0,0,.22)!important;white-space:nowrap}
.gls-all-lessons-btn:hover{background:#111!important;transform:translateY(-1px)}
.gls-all-lessons-source{position:fixed!important;left:-100000px!important;top:0!important;width:780px!important;background:#fff!important;pointer-events:none!important;z-index:-2147483647!important}
.gls-pdf-session{padding:0 0 28px;margin:0 0 28px;border-bottom:2px solid #e5e7eb}.gls-pdf-session:last-child{border-bottom:0;margin-bottom:0}
.gls-pdf-session-head{direction:rtl;text-align:right;background:linear-gradient(135deg,#fff1f1,#fff8d8);border-right:6px solid #c90000;border-radius:12px;padding:12px 14px;margin:0 0 18px;color:#111}.gls-pdf-session-head h2{margin:0 0 5px;font-size:19px;color:#a60000}.gls-pdf-session-head small{color:#555;font-size:12px}
.gls-correction-card,.gls-correction-card *,.gls-task-detail-box,.gls-task-detail-box *{columns:auto!important;column-count:auto!important;column-width:auto!important}
.gls-correction-card{display:block!important;width:100%;max-width:100%;line-height:1.9}
.gls-task-submit-card{padding:14px!important;border-radius:18px!important;background:#fff!important;border:1px solid #e7ebf2!important;box-shadow:0 8px 22px rgba(15,23,42,.045)!important}
.gls-task-submit-card h4{font-size:14px!important;margin:0 0 10px!important}.gls-task-submit-card p{display:none!important}
.gls-task-sections{gap:12px!important}.gls-task-section{border:1px solid #e7ebf2;border-radius:18px;background:#fff;padding:10px 12px;box-shadow:0 8px 22px rgba(15,23,42,.035)}
.gls-task-section .gls-card-head{margin-bottom:6px!important}.gls-task-section .gls-card-head h3{font-size:13px!important}.gls-task-section .gls-card-head small{display:none!important}
.gls-task-list{display:flex!important;flex-direction:column!important;gap:0!important;border-top:1px solid #eef1f5}
.gls-task-row{border:0!important;border-bottom:1px solid #eef1f5!important;border-radius:0!important;box-shadow:none!important;background:#fff!important;overflow:visible!important}
.gls-task-row:last-child{border-bottom:0!important}.gls-task-row.open{background:#fbfcff!important}
.gls-task-toggle{min-height:46px!important;padding:8px 4px!important;background:transparent!important;display:grid!important;grid-template-columns:1fr auto!important;gap:10px!important;align-items:center!important;border:0!important;width:100%!important;text-align:right!important;cursor:pointer!important}
.gls-task-row-head strong{font-size:12.5px!important;font-weight:900!important;color:#111827!important}.gls-task-row-head small{font-size:10.5px!important;color:#64748b!important}
.gls-task-toggle .gls-status{font-size:10px!important;padding:4px 8px!important}.gls-task-body{padding:8px 4px 12px!important;border-top:1px dashed #e6eaf0!important;background:#fbfcff!important}
.gls-task-sub-list{display:flex!important;flex-direction:column!important;gap:6px!important}.gls-task-sub-toggle{border:1px solid #e7ebf2!important;background:#fff!important;border-radius:12px!important;padding:8px 10px!important;display:flex!important;justify-content:space-between!important;gap:8px!important;cursor:pointer!important;text-align:right!important}.gls-task-sub-toggle b{font-size:12px!important}.gls-task-sub-toggle small{font-size:10.5px!important;color:#64748b!important}
.gls-task-detail-box{border:1px solid #e8edf3!important;background:#fff!important;border-radius:14px!important;padding:10px 12px!important;margin:7px 0!important;font-size:13px!important;line-height:1.85!important}.gls-task-detail-box h4{font-size:12.5px!important;margin:0 0 6px!important}
.gls-nav-badge,.gls-mobile-nav-btn i{transition:opacity .14s ease,transform .14s ease}.gls-nav-badge[style*="display: none"],.gls-mobile-nav-btn i[style*="display: none"]{transform:scale(.8)}
.gls-booking-portal{display:block!important}.gls-booking-static{cursor:default!important}.gls-booking-cta{display:none!important}.gls-booking-portal .gls-booking-embed{display:block!important}
@media(max-width:800px){.gls-current-session-banner{margin:0 0 10px;padding:10px 11px;border-radius:14px}.gls-current-session-banner strong{font-size:12.5px}.gls-current-session-banner small{width:100%;font-size:10.5px}.gls-task-section{border-radius:14px;padding:8px 10px}.gls-task-toggle{min-height:42px}.gls-task-row-head strong{font-size:12px!important}.gls-task-detail-box{font-size:12.5px!important}}


/* ==== GTBP/GLS 2.1 profile, notifications, compact cart ==== */
.gls-dashboard-card.gls-notification-panel,.gls-dashboard-card.gls-profile-panel{display:none}
.gls-notification-list{display:flex;flex-direction:column;gap:8px;max-height:360px;overflow:auto;padding-left:4px}
.gls-notification-row{display:grid;grid-template-columns:10px 1fr;gap:10px;align-items:start;border:1px solid #eef2f7;border-radius:14px;padding:10px 11px;background:#fff}
.gls-notification-dot{width:8px;height:8px;border-radius:999px;background:#2563eb;margin-top:8px}.gls-notification-row p{margin:2px 0;color:#475569;font-size:12px}.gls-notification-row small{color:#94a3b8;font-size:11px}.gls-mark-notifications-read{margin-top:10px;width:100%}
.gls-profile-head{display:flex;align-items:center;gap:12px;margin-bottom:14px}.gls-profile-head small{display:block;color:#64748b}.gls-profile-avatar{width:58px;height:58px;border-radius:20px;object-fit:cover;border:1px solid #e2e8f0}.gls-profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}.gls-profile-result{font-size:12px;color:#166534;margin-top:8px}.gls-nav-badge,.gls-mobile-nav-btn i,.gls-cart-badge{position:absolute;min-width:18px;height:18px;border-radius:999px;background:#ef4444;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:10px;font-style:normal;padding:0 5px;line-height:1}.gls-desktop-nav-btn{position:relative}.gls-desktop-nav-btn .gls-nav-badge{top:-7px;left:-6px}.gls-mobile-nav-btn{position:relative}.gls-mobile-nav-btn i{top:3px;left:50%;transform:translateX(-18px)}
@media(max-width:800px){.gls-mobile-bottom-nav{grid-template-columns:repeat(9,1fr)!important;overflow-x:auto;scrollbar-width:none}.gls-mobile-bottom-nav::-webkit-scrollbar{display:none}.gls-mobile-nav-btn{min-width:58px}.gls-profile-grid{grid-template-columns:1fr}.gls-student-full.gls-mobile-mode-notifications .gls-dashboard-card,.gls-student-full.gls-mobile-mode-profile .gls-dashboard-card{display:none!important}.gls-student-full.gls-mobile-mode-notifications #gls-mobile-notifications,.gls-student-full.gls-mobile-mode-profile #gls-mobile-profile{display:block!important;max-width:760px;margin:auto}.gls-student-full.gls-mobile-mode-notifications .gls-booking-portal,.gls-student-full.gls-mobile-mode-profile .gls-booking-portal,.gls-student-full.gls-mobile-mode-notifications #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-profile #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-notifications .gls-grid,.gls-student-full.gls-mobile-mode-profile .gls-grid{display:none!important}}
.gls-booking-embed .gtbp-payment-methods label,.gtbp-payment-methods label{display:flex!important;align-items:center!important;gap:10px!important;margin:10px 0!important;line-height:1.8!important}.gls-booking-embed input[name="gtbp_payment_method"],input[name="gtbp_payment_method"]{margin:0!important;flex:0 0 auto!important}.gls-booking-embed .gtbp-submit,.gls-booking-embed .gtbp-btn-submit{width:100%}.gls-dashboard-card.gls-mobile-active{display:block!important}



/* ==== GTBP/GLS 2.2 compact account/notification navigation fixes ==== */
.gls-dashboard-card.gls-notification-panel,.gls-dashboard-card.gls-profile-panel{display:none!important}
.gls-notification-panel,.gls-profile-panel{width:100%;max-width:760px;margin:0 auto 16px!important}
.gls-profile-panel .gls-form{max-width:none}.gls-profile-head{display:flex;align-items:center;gap:12px;padding:12px;border:1px solid #eef2f7;border-radius:18px;background:#f8fafc;margin-bottom:12px}.gls-profile-avatar{width:64px;height:64px;border-radius:22px;object-fit:cover;border:1px solid #e2e8f0}.gls-profile-head strong{display:block;font-size:15px;color:#0f172a}.gls-profile-head small{display:block;font-size:12px;color:#64748b;direction:ltr;text-align:right}.gls-profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.gls-profile-result{font-size:12px;color:#2563eb;margin-top:8px}
@media(min-width:801px){
  .gls-student-full.gls-mobile-mode-notifications .gls-booking-portal,.gls-student-full.gls-mobile-mode-notifications #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-notifications .gls-grid,
  .gls-student-full.gls-mobile-mode-profile .gls-booking-portal,.gls-student-full.gls-mobile-mode-profile #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-profile .gls-grid{display:none!important}
  .gls-student-full.gls-mobile-mode-notifications .gls-dashboard,.gls-student-full.gls-mobile-mode-profile .gls-dashboard{display:block!important;padding:22px!important}
  .gls-student-full.gls-mobile-mode-notifications .gls-dashboard-card,.gls-student-full.gls-mobile-mode-profile .gls-dashboard-card{display:none!important}
  .gls-student-full.gls-mobile-mode-notifications #gls-mobile-notifications{display:block!important}
  .gls-student-full.gls-mobile-mode-profile #gls-mobile-profile{display:block!important}
}
@media(max-width:800px){
  .gls-mobile-bottom-nav{grid-template-columns:repeat(8,minmax(0,1fr))!important;overflow:visible!important;right:8px!important;left:8px!important;gap:3px!important;padding:6px!important;border-radius:22px!important;max-width:calc(100vw - 16px)!important}
  .gls-mobile-bottom-nav::-webkit-scrollbar{display:none!important}
  .gls-mobile-nav-btn{min-width:0!important;width:auto!important;min-height:48px!important;padding:5px 2px!important;border-radius:15px!important;gap:2px!important}
  .gls-mobile-nav-btn span{font-size:16px!important;line-height:1!important}.gls-mobile-nav-btn b{font-size:8.8px!important;line-height:1.15!important;white-space:nowrap!important}.gls-mobile-nav-btn.gls-icon-only b{display:none!important}.gls-mobile-nav-btn.gls-icon-only span{font-size:18px!important}
  .gls-mobile-nav-btn i{top:3px!important;right:50%!important;transform:translateX(-13px)!important;min-width:15px!important;height:15px!important;font-size:8px!important;line-height:15px!important}
  .gls-student-full.gls-mobile-mode-reserve .gls-dashboard,.gls-student-full.gls-mobile-mode-reserve #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-reserve .gls-grid{display:none!important}.gls-student-full.gls-mobile-mode-reserve .gls-booking-portal{display:block!important}
  .gls-student-full.gls-mobile-mode-sessions .gls-dashboard,.gls-student-full.gls-mobile-mode-sessions .gls-booking-portal,.gls-student-full.gls-mobile-mode-sessions .gls-grid{display:none!important}.gls-student-full.gls-mobile-mode-sessions #gls-mobile-sessions{display:block!important}
  .gls-student-full.gls-mobile-mode-bookings .gls-booking-portal,.gls-student-full.gls-mobile-mode-bookings #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-bookings .gls-grid{display:none!important}.gls-student-full.gls-mobile-mode-bookings .gls-dashboard{display:block!important}.gls-student-full.gls-mobile-mode-bookings .gls-dashboard-card{display:none!important}.gls-student-full.gls-mobile-mode-bookings #gls-mobile-bookings{display:block!important}
  .gls-student-full.gls-mobile-mode-tasks .gls-booking-portal,.gls-student-full.gls-mobile-mode-tasks #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-tasks .gls-grid{display:none!important}.gls-student-full.gls-mobile-mode-tasks .gls-dashboard{display:block!important}.gls-student-full.gls-mobile-mode-tasks .gls-dashboard-card{display:none!important}.gls-student-full.gls-mobile-mode-tasks #gls-mobile-tasks{display:block!important}
  .gls-student-full.gls-mobile-mode-performance .gls-booking-portal,.gls-student-full.gls-mobile-mode-performance #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-performance .gls-grid{display:none!important}.gls-student-full.gls-mobile-mode-performance .gls-dashboard{display:block!important}.gls-student-full.gls-mobile-mode-performance .gls-dashboard-card{display:none!important}.gls-student-full.gls-mobile-mode-performance #gls-mobile-performance{display:block!important}
  .gls-student-full.gls-mobile-mode-notifications .gls-booking-portal,.gls-student-full.gls-mobile-mode-notifications #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-notifications .gls-grid{display:none!important}.gls-student-full.gls-mobile-mode-notifications .gls-dashboard{display:block!important}.gls-student-full.gls-mobile-mode-notifications .gls-dashboard-card{display:none!important}.gls-student-full.gls-mobile-mode-notifications #gls-mobile-notifications{display:block!important}
  .gls-student-full.gls-mobile-mode-profile .gls-booking-portal,.gls-student-full.gls-mobile-mode-profile #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-profile .gls-grid{display:none!important}.gls-student-full.gls-mobile-mode-profile .gls-dashboard{display:block!important}.gls-student-full.gls-mobile-mode-profile .gls-dashboard-card{display:none!important}.gls-student-full.gls-mobile-mode-profile #gls-mobile-profile{display:block!important}
  .gls-profile-grid{grid-template-columns:1fr}.gls-notification-panel,.gls-profile-panel{max-width:none!important;margin:0!important;border-radius:0!important;box-shadow:none!important}
}


/* ==== GTBP/GLS 2.3 stable exclusive menu states ==== */
.gls-notification-list{max-height:none!important;overflow:visible!important;padding-left:0!important}
.gls-student-full .gls-dashboard>.gls-dashboard-card{display:none!important}
.gls-student-full.gls-mobile-mode-content .gls-dashboard,.gls-student-full.gls-mobile-mode-content .gls-booking-portal,.gls-student-full.gls-mobile-mode-content #gls-mobile-sessions{display:none!important}
.gls-student-full.gls-mobile-mode-content .gls-grid{display:grid!important}
.gls-student-full.gls-mobile-mode-reserve .gls-dashboard,.gls-student-full.gls-mobile-mode-reserve #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-reserve .gls-grid{display:none!important}
.gls-student-full.gls-mobile-mode-reserve .gls-booking-portal{display:block!important}
.gls-student-full.gls-mobile-mode-sessions .gls-dashboard,.gls-student-full.gls-mobile-mode-sessions .gls-booking-portal,.gls-student-full.gls-mobile-mode-sessions .gls-grid{display:none!important}
.gls-student-full.gls-mobile-mode-sessions #gls-mobile-sessions{display:block!important}
.gls-student-full.gls-mobile-mode-bookings .gls-booking-portal,.gls-student-full.gls-mobile-mode-bookings #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-bookings .gls-grid,.gls-student-full.gls-mobile-mode-tasks .gls-booking-portal,.gls-student-full.gls-mobile-mode-tasks #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-tasks .gls-grid,.gls-student-full.gls-mobile-mode-performance .gls-booking-portal,.gls-student-full.gls-mobile-mode-performance #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-performance .gls-grid,.gls-student-full.gls-mobile-mode-notifications .gls-booking-portal,.gls-student-full.gls-mobile-mode-notifications #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-notifications .gls-grid,.gls-student-full.gls-mobile-mode-profile .gls-booking-portal,.gls-student-full.gls-mobile-mode-profile #gls-mobile-sessions,.gls-student-full.gls-mobile-mode-profile .gls-grid{display:none!important}
.gls-student-full.gls-mobile-mode-bookings .gls-dashboard,.gls-student-full.gls-mobile-mode-tasks .gls-dashboard,.gls-student-full.gls-mobile-mode-performance .gls-dashboard,.gls-student-full.gls-mobile-mode-notifications .gls-dashboard,.gls-student-full.gls-mobile-mode-profile .gls-dashboard{display:block!important;padding:18px!important}
.gls-student-full.gls-mobile-mode-bookings #gls-mobile-bookings,.gls-student-full.gls-mobile-mode-tasks #gls-mobile-tasks,.gls-student-full.gls-mobile-mode-performance #gls-mobile-performance,.gls-student-full.gls-mobile-mode-notifications #gls-mobile-notifications,.gls-student-full.gls-mobile-mode-profile #gls-mobile-profile{display:block!important;max-width:820px;margin:0 auto 16px!important}
@media(max-width:800px){
  .gls-mobile-bottom-nav{grid-template-columns:repeat(8,minmax(0,1fr))!important;overflow:visible!important;max-width:calc(100vw - 14px)!important;right:7px!important;left:7px!important}
  .gls-mobile-nav-btn{min-width:0!important;width:auto!important;padding:5px 1px!important}
  .gls-mobile-nav-btn b{font-size:8.5px!important}
  .gls-mobile-nav-btn.gls-icon-only b{display:none!important}
  .gls-student-full.gls-mobile-mode-bookings .gls-dashboard,.gls-student-full.gls-mobile-mode-tasks .gls-dashboard,.gls-student-full.gls-mobile-mode-performance .gls-dashboard,.gls-student-full.gls-mobile-mode-notifications .gls-dashboard,.gls-student-full.gls-mobile-mode-profile .gls-dashboard{padding:10px!important;margin:0!important}
  .gls-student-full.gls-mobile-mode-bookings #gls-mobile-bookings,.gls-student-full.gls-mobile-mode-tasks #gls-mobile-tasks,.gls-student-full.gls-mobile-mode-performance #gls-mobile-performance,.gls-student-full.gls-mobile-mode-notifications #gls-mobile-notifications,.gls-student-full.gls-mobile-mode-profile #gls-mobile-profile{max-width:none!important;margin:0!important;border-radius:0!important;box-shadow:none!important}
}


/* ==== GLS 12.7 login panel ==== */
.gls-login-wrap{min-height:72vh;display:grid;place-items:center;background:#f6f7fb;padding:24px;direction:rtl}.gls-login-wrap .gls-shell{width:min(520px,100%);border-radius:30px;padding:1px;background:linear-gradient(135deg,#111,#dd0000,#ffce00)}.gls-login-wrap .gls-inner{border-radius:29px;background:#fff;overflow:hidden}.gls-login-card{padding:28px}.gls-login-brand{text-align:center;margin-bottom:22px}.gls-login-icon{width:58px;height:58px;border-radius:22px;margin:0 auto 12px;display:grid;place-items:center;background:#111;color:#ffce00;font-size:26px;box-shadow:0 18px 38px rgba(15,23,42,.16)}.gls-login-brand h2{margin:0 0 8px;font-size:22px;color:#111827}.gls-login-brand p{margin:0;color:#64748b;line-height:1.9;font-size:13px}.gls-login-card form{display:grid;gap:12px}.gls-login-card label{font-weight:900;color:#111827;font-size:13px}.gls-login-card input[type=text],.gls-login-card input[type=password]{width:100%;min-height:48px;border:1px solid #dbe2ea;border-radius:16px;padding:10px 14px;background:#fff;font-family:inherit!important}.gls-login-card input[type=submit]{width:100%;min-height:48px;border:0;border-radius:16px;background:#111827;color:#fff;font-weight:950;cursor:pointer;font-family:inherit!important;box-shadow:0 14px 28px rgba(15,23,42,.16)}.gls-login-card input[type=submit]:hover{background:#0f172a}.gls-login-card .login-remember{display:flex;align-items:center;gap:8px;margin:0;color:#64748b}.gls-login-links{text-align:center;margin-top:16px}.gls-login-links a{color:#2563eb;text-decoration:none;font-weight:850}.gls-login-card .gls-notice{margin-bottom:14px;background:#fff7ed;border-color:#fed7aa;color:#9a3412}

/* ==== GLS 12.11 lesson editor tables + correction polish ==== */
.gls-editor{font-size:28px!important;line-height:2!important}
.gls-lesson-view{font-size:19px!important;line-height:2.1!important}
.gls-editor table,.gls-lesson-view table,.gls-print-paper table,.paper table{width:100%!important;max-width:100%!important;border-collapse:collapse!important;table-layout:fixed!important;margin:14px 0!important;direction:inherit!important}
.gls-editor table td,.gls-editor table th,.gls-lesson-view table td,.gls-lesson-view table th,.gls-print-paper table td,.gls-print-paper table th,.paper table td,.paper table th{border:1px solid #d9dee8!important;padding:10px 12px!important;vertical-align:top!important;min-width:48px!important;word-break:break-word!important;overflow-wrap:anywhere!important}
.gls-editor table th,.gls-lesson-view table th{background:#f8fafc;font-weight:900}.gls-editor hr,.gls-lesson-view hr,.gls-print-paper hr,.paper hr{border:0;border-top:2px solid #e5e7eb;margin:20px 0!important;width:100%}
.gls-table-tool-wrap{position:relative}.gls-table-picker{display:none;position:absolute;top:43px;right:0;width:246px;background:#fff;border:1px solid #e6ebf2;border-radius:18px;padding:12px;box-shadow:0 24px 60px rgba(15,23,42,.18);z-index:50}.gls-table-picker.open{display:block}.gls-table-picker-title{display:block;font-size:12px;font-weight:900;color:#475569;margin-bottom:8px}.gls-table-grid{display:grid;grid-template-columns:repeat(8,1fr);gap:5px;direction:ltr}.gls-table-grid button{width:22px;height:22px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;cursor:pointer}.gls-table-grid button.hot{background:#ffce00;border-color:#eab308}.gls-table-picker-size{display:block;text-align:center;color:#64748b;font-size:11px;margin-top:8px}.gls-table-context{display:none;position:absolute;width:205px;background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:10px;box-shadow:0 24px 60px rgba(15,23,42,.22);z-index:999999;direction:rtl}.gls-table-context.open{display:grid;gap:7px}.gls-table-context strong{font-size:12px;color:#111827}.gls-table-context button{border:0;border-radius:10px;background:#f8fafc;color:#111827;padding:8px 9px;text-align:right;cursor:pointer;font-family:inherit!important;font-size:12px}.gls-table-context button:hover{background:#ffce00}.gls-table-context-colors{display:grid;grid-template-columns:repeat(6,1fr);gap:5px}.gls-table-context-colors i{height:24px;border-radius:8px;border:1px solid rgba(15,23,42,.14);cursor:pointer}.gls-table-selected-cell{outline:3px solid rgba(255,206,0,.55)!important;outline-offset:-3px!important}.gls-correction-card{background:#fff!important;border:1px solid #e6ebf2!important;border-radius:18px!important;padding:14px!important;box-shadow:0 12px 30px rgba(15,23,42,.06)!important}.gls-correction-head{padding-bottom:10px;margin-bottom:12px!important;border-bottom:1px solid #edf2f7!important}.gls-feedback-view{display:block!important;width:100%!important;columns:auto!important;column-count:auto!important;column-width:auto!important;line-height:2.05!important;font-size:15px!important;color:#1f2937;background:#fbfcfe;border:1px solid #eef2f7;border-radius:16px;padding:14px!important;white-space:normal!important}.gls-feedback-view *{columns:auto!important;column-count:auto!important;max-width:100%!important}.gls-feedback-view del{background:#fee2e2;color:#991b1b;text-decoration:line-through;border-radius:6px;padding:0 4px}.gls-feedback-view strong{background:#dcfce7;color:#166534;border-radius:6px;padding:0 4px}.gls-feedback-view em{color:#7c3aed;font-style:normal;background:#f3e8ff;border-radius:6px;padding:0 4px}@media(max-width:800px){.gls-editor{font-size:24px!important}.gls-lesson-view{font-size:18px!important}.gls-table-picker{right:auto;left:0}.gls-table-context{width:196px}}
/* ==== GLS 12.12 print/background/test polish ==== */
.gls-editor table td[style*="background"],.gls-editor table th[style*="background"],.gls-lesson-view table td[style*="background"],.gls-lesson-view table th[style*="background"],.gls-print-paper table td[style*="background"],.gls-print-paper table th[style*="background"],.paper table td[style*="background"],.paper table th[style*="background"]{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
.gls-editor hr,.gls-lesson-view hr,.gls-print-paper hr,.paper hr{display:block!important;height:0!important;min-height:0!important;border:0!important;border-top:2px solid #cbd5e1!important;background:transparent!important;page-break-inside:avoid!important}
.gls-table-picker{background:#fff!important;isolation:isolate!important;z-index:999999!important}.gls-table-picker:before{content:"";position:absolute;inset:0;background:#fff;border-radius:18px;z-index:-1}.gls-table-grid,.gls-table-picker-title,.gls-table-picker-size{position:relative;z-index:2}.gls-table-grid button{background:#fff!important}.gls-table-grid button.hot{background:#ffce00!important}
.gls-test-fallback{direction:rtl;text-align:right;background:#fff;border:1px solid #e6ebf2;border-radius:18px;padding:16px;box-shadow:0 12px 30px rgba(15,23,42,.06)}.gls-test-fallback h3{margin-top:0}.gls-test-question{border:1px solid #edf2f7;border-radius:16px;padding:13px;margin:12px 0;background:#fbfcfe}.gls-test-question-text{direction:ltr;text-align:left;font-family:Tahoma,Arial,sans-serif;line-height:2}.gls-test-options{display:grid;gap:8px;margin-top:10px;direction:ltr;text-align:left}.gls-test-options label{display:flex;gap:8px;align-items:center;background:#fff;border:1px solid #e6ebf2;border-radius:12px;padding:8px 10px}.gls-test-feedback{margin-top:8px;border-radius:12px;padding:8px 10px;background:#f8fafc;border:1px solid #edf2f7}.gls-test-feedback.ok{background:#ecfdf3;border-color:#bbf7d0}.gls-test-feedback.bad{background:#fff1f2;border-color:#fecdd3}
@media print{.gls-editor table td,.gls-editor table th,.gls-lesson-view table td,.gls-lesson-view table th,.gls-print-paper table td,.gls-print-paper table th,.paper table td,.paper table th{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}.gls-editor hr,.gls-lesson-view hr,.gls-print-paper hr,.paper hr{border-top:2px solid #444!important}}


/* ==== 12.14 repair: fast font for headings and stable AI gate ==== */
.gls-wrap h1,.gls-wrap h2,.gls-wrap h3,.gls-wrap h4,.gls-wrap .gls-top h1,.gls-wrap .gls-top h2,.gtbp-wrapper h1,.gtbp-wrapper h2,.gtbp-wrapper h3,.gtbp-wrapper h4,.gtbp-admin-wrap h1,.gtbp-admin-wrap h2,.gtbp-admin-wrap h3,.gtbp-admin-wrap h4{font-family:"IRANSansXFaNum",Tahoma,sans-serif!important;font-synthesis-weight:none!important;text-rendering:optimizeLegibility!important}

/* ==== 12.13 hotfix: stable font loading, table colors, hr and tests ==== */
.gls-editor table,.gls-lesson-view table,.gls-print-paper table{width:100%!important;max-width:100%!important;border-collapse:collapse!important;table-layout:fixed!important;background:#fff}
.gls-editor table td,.gls-editor table th,.gls-lesson-view table td,.gls-lesson-view table th,.gls-print-paper table td,.gls-print-paper table th{border:1px solid #d9dee8!important;padding:8px 10px!important;vertical-align:top!important;word-break:break-word!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;color-adjust:exact!important;background-clip:padding-box!important}
.gls-editor table tr[style],.gls-editor table td[style],.gls-editor table th[style],.gls-lesson-view table tr[style],.gls-lesson-view table td[style],.gls-lesson-view table th[style],.gls-print-paper table tr[style],.gls-print-paper table td[style],.gls-print-paper table th[style]{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;color-adjust:exact!important}
.gls-editor hr,.gls-lesson-view hr,.gls-print-paper hr{display:block!important;height:0!important;border:0!important;border-top:2px solid #444!important;margin:22px 0!important;opacity:1!important;clear:both!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
.gls-table-picker{background:#fff!important;box-shadow:0 24px 60px rgba(15,23,42,.22)!important;isolation:isolate!important}
.gls-test-wrap .otg-test-container,.gls-test-wrap .gls-test-fallback{width:100%!important;max-width:100%!important;display:block!important;visibility:visible!important;opacity:1!important}
@media print{.gls-print-paper table td,.gls-print-paper table th{background-clip:padding-box!important;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;color-adjust:exact!important}.gls-print-paper hr{display:block!important;border-top:2px solid #444!important}}

/* ==== GLS 12.16 student class link buttons ==== */
/* 12.17: active only from 5 minutes before class until class time, based on Iran time. */
.gls-class-link-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:38px;border-radius:13px;padding:8px 12px;font-weight:900;font-size:12px;line-height:1.5;text-decoration:none;border:1px solid transparent;white-space:nowrap;transition:.18s}
.gls-class-link-active{background:linear-gradient(135deg,#dd0000,#a50000);color:#fff!important;box-shadow:0 10px 22px rgba(221,0,0,.18)}
.gls-class-link-active:hover{background:#111;color:#fff!important;transform:translateY(-1px)}
.gls-class-link-disabled{background:#f3f4f6;color:#667085!important;border-color:#e5e7eb;cursor:not-allowed}
 .gls-session-absent-card{background:linear-gradient(135deg,#fff1f2,#ffe4e6)!important;border-color:#fecdd3!important;color:#991b1b!important;box-shadow:0 10px 24px rgba(220,38,38,.10)!important;cursor:not-allowed!important;pointer-events:none!important;opacity:1!important}
.gls-session-absent-card:before{background:#dc2626!important}
.gls-session-absent-card h3,.gls-session-absent-card strong{color:#991b1b!important}
.gls-session-absent-card .gls-meta span,.gls-session-absent-card .gls-mobile-session-state{background:#fecdd3!important;color:#991b1b!important;border-color:#fda4af!important}
.gls-mobile-session-card-absent{background:linear-gradient(135deg,#fff1f2,#ffe4e6)!important;border-color:#fecdd3!important;box-shadow:0 10px 24px rgba(220,38,38,.10)!important}
.gls-mobile-session-card-absent .gls-mobile-session-row{background:transparent!important;color:#991b1b!important;pointer-events:none!important;cursor:not-allowed!important}
.gls-mobile-session-card-absent .gls-mobile-session-dot{background:#dc2626!important}
.gls-session-card-wrap{margin-bottom:12px;background:#fff;border:1px solid #e8eaf0;border-radius:20px;padding:0 0 10px;box-shadow:0 6px 18px rgba(16,24,40,.04);overflow:hidden}
.gls-session-card-wrap .gls-session-card{margin-bottom:8px;border:0;border-radius:20px 20px 14px 14px;box-shadow:none}
.gls-session-card-wrap>.gls-class-link-btn{margin:0 12px;width:calc(100% - 24px)}
.gls-mobile-session-card-with-link{background:#fff;border:1px solid #e8eaf0;border-radius:18px;padding:8px;margin-bottom:9px;box-shadow:0 8px 22px rgba(15,23,42,.055)}
.gls-mobile-session-card-with-link .gls-mobile-session-row{box-shadow:none;border:0;margin-bottom:7px;padding:8px}
.gls-mobile-session-card-with-link>.gls-class-link-btn{width:100%}
.gls-booking-card .gls-class-link-btn{flex:0 0 auto}
@media(max-width:800px){.gls-booking-card .gls-class-link-btn{width:100%;margin-top:8px}.gls-class-link-btn{white-space:normal;text-align:center}}

/* ==== GLS 12.23 session quiz direction only ==== */
/* German question text and choices must always stay LTR inside session quizzes. */
.gls-test-wrap .otg-question,
.gls-test-wrap .otg-question-text,
.gls-test-wrap .otg-options-vertical,
.gls-test-wrap .otg-option-item,
.gls-test-wrap .otg-option-letter,
.gls-test-wrap .otg-option-text,
.gls-test-wrap .otg-blank-input,
.gls-test-wrap .gls-test-question,
.gls-test-wrap .gls-test-question-text,
.gls-test-wrap .gls-test-options,
.gls-test-wrap .gls-test-options label,
.gls-test-wrap .gls-test-options span{
    direction:ltr!important;
    text-align:left!important;
    unicode-bidi:plaintext!important;
}
.gls-test-wrap .otg-option-item,
.gls-test-wrap .gls-test-options label{
    flex-direction:row!important;
    justify-content:flex-start!important;
}
/* Persian feedback/explanations shown after submission must stay RTL. */
.gls-test-wrap .otg-feedback,
.gls-test-wrap .otg-explanation,
.gls-test-wrap .gls-test-feedback,
.gls-test-wrap .gls-test-feedback *{
    direction:rtl!important;
    text-align:right!important;
    unicode-bidi:plaintext!important;
}

/* ==== GLS 12.22 admin session filters ==== */
.gls-admin-filters{display:grid;grid-template-columns:minmax(220px,1fr) minmax(180px,240px) auto auto auto;gap:10px;align-items:end;margin:16px 0}
.gls-admin-filters label{display:flex;flex-direction:column;gap:6px;font-weight:850;margin:0}
.gls-admin-filters input{width:100%;min-height:42px;border:1px solid #d8dbe3;border-radius:12px;padding:8px 10px}
@media(max-width:782px){
 .gls-admin-filters{grid-template-columns:1fr!important;align-items:stretch!important}
 .gls-admin-filters .gls-btn{width:100%!important}
 .gls-session-admin-table,.gls-session-admin-table tbody,.gls-session-admin-table tr,.gls-session-admin-table td{display:block;width:100%}
 .gls-session-admin-table thead{display:none}
 .gls-session-admin-table tr{background:#fff;border:1px solid #e8eaf0;border-radius:16px;padding:10px;margin-bottom:10px;box-shadow:0 6px 18px rgba(16,24,40,.05)}
 .gls-session-admin-table td{border:0!important;border-bottom:1px dashed #eceef3!important;border-radius:0!important;padding:9px 0!important;display:grid!important;grid-template-columns:90px 1fr;gap:8px;align-items:center}
 .gls-session-admin-table td:last-child{border-bottom:0!important}
 .gls-session-admin-table td:before{content:attr(data-label);font-size:11px;color:#667085;font-weight:900}
 .gls-session-admin-table td[colspan]{display:block!important;text-align:center!important}
 .gls-session-admin-table td[colspan]:before{display:none}
}


/* ==== GLS 12.24 session quiz results/history only ==== */
.gls-test-result-hero{direction:rtl;display:flex;align-items:center;justify-content:space-between;gap:18px;margin:0 0 18px;padding:18px 20px;border:1px solid #fde68a;border-radius:22px;background:linear-gradient(135deg,#fffdf2 0%,#fff8dc 100%);box-shadow:0 14px 34px rgba(146,64,14,.08)}
.gls-test-result-hero>div:first-child{display:flex;flex-direction:column;gap:4px}.gls-test-result-kicker{font-size:12px;font-weight:800;color:#92400e}.gls-test-result-hero strong{font-size:26px;color:#111827}.gls-test-result-hero small{color:#6b7280}.gls-test-result-ring{--test-score:0;width:76px;height:76px;border-radius:50%;display:grid;place-items:center;background:conic-gradient(#f59e0b calc(var(--test-score)*1%),#f3f4f6 0);position:relative;flex:0 0 auto}.gls-test-result-ring:before{content:"";position:absolute;inset:8px;border-radius:50%;background:#fff}.gls-test-result-ring span{position:relative;z-index:1;font-weight:900;color:#92400e}
.gls-test-history{direction:rtl;margin:0 0 18px;border:1px solid #e5e7eb;border-radius:16px;background:#fff;overflow:hidden}.gls-test-history summary{cursor:pointer;padding:12px 14px;font-weight:800;display:flex;justify-content:space-between;align-items:center}.gls-test-history summary span{background:#f3f4f6;border-radius:99px;padding:2px 8px}.gls-test-history-list{padding:0 12px 12px;display:grid;gap:8px}.gls-test-history-list>div{display:grid;grid-template-columns:1fr auto auto;gap:10px;align-items:center;padding:10px 12px;border-radius:12px;background:#f8fafc}.gls-test-history-list time{font-size:12px;color:#64748b}.gls-test-history-list b{color:#111827}.gls-test-history-list span{font-weight:800;color:#b45309}
.gls-test-wrap .otg-option-item,.gls-test-wrap .gls-test-options label{position:relative;border:1px solid #e5e7eb!important;border-radius:13px!important;padding:10px 12px!important}.gls-test-wrap .otg-option-item.otg-mc-correct,.gls-test-wrap .gls-test-options label.gls-answer-correct{background:#ecfdf3!important;border-color:#86efac!important;box-shadow:0 0 0 1px #bbf7d0 inset}.gls-test-wrap .otg-option-item.otg-mc-incorrect,.gls-test-wrap .gls-test-options label.gls-answer-wrong{background:#fff1f2!important;border-color:#fda4af!important}.gls-answer-mark{margin-inline-start:auto;width:24px;height:24px;border-radius:50%;display:grid;place-items:center;background:#16a34a;color:#fff}.gls-answer-wrong .gls-answer-mark{background:#dc2626}.gls-test-wrap .otg-correct-answer-info{display:none!important}.gls-test-wrap .otg-feedback,.gls-test-wrap .gls-test-feedback{border-radius:14px!important;padding:12px 14px!important}.gls-answer-explanation{margin-top:7px;padding-top:7px;border-top:1px dashed currentColor;opacity:.88}
@media(max-width:640px){.gls-test-result-hero{padding:14px}.gls-test-result-hero strong{font-size:21px}.gls-test-result-ring{width:64px;height:64px}.gls-test-history-list>div{grid-template-columns:1fr auto}.gls-test-history-list span{grid-column:2}}

/* ==== GLS 12.25 quiz admin, analysis and dashboard ==== */
.gls-quiz-analysis{background:#fff;border:1px solid #e8eaf0;border-right:5px solid #ffce00;border-radius:18px;padding:18px;margin:16px 0;line-height:2}.gls-quiz-analysis h3{margin:0 0 10px}.gls-quiz-admin-answers{display:grid;gap:12px}.gls-quiz-admin-answers article{border:1px solid #e8eaf0;border-radius:16px;padding:14px;background:#fff}.gls-quiz-admin-answers article.ok{border-right:5px solid #12a150;background:#f1fbf5}.gls-quiz-admin-answers article.bad{border-right:5px solid #dd0000;background:#fff4f4}.gls-quiz-admin-answers h4{margin:0 0 10px;text-align:left}.gls-quiz-admin-answers p{margin:6px 0;text-align:left}
/* ==== GLS 13.0 video player, table context, profile, editor improvements ==== */
.gls-video-wrap{display:flex;flex-direction:column;gap:12px}.gls-bbb-player-wrap{position:relative;width:100%;padding-bottom:56.25%;border-radius:16px;overflow:hidden;background:#111;box-shadow:0 12px 32px rgba(0,0,0,.18)}.gls-bbb-player{position:absolute;top:0;right:0;width:100%;height:100%;border:0}.gls-bbb-fullscreen-btn{position:absolute;bottom:110px;left:10px;z-index:10;border:0;border-radius:12px;background:rgba(255,255,255,.18);color:#fff;height:36px;padding:0 10px;font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:0;backdrop-filter:blur(12px) saturate(180%);-webkit-backdrop-filter:blur(12px) saturate(180%);border:1px solid rgba(255,255,255,.28);box-shadow:0 4px 16px rgba(0,0,0,.22);transition:.18s;line-height:1;white-space:nowrap;font-family:"IRANSansXFaNum",Tahoma,sans-serif}.gls-bbb-fullscreen-btn .gls-fs-icon{display:block;font-style:normal}.gls-bbb-fullscreen-btn .gls-fs-label{display:none}.gls-bbb-fullscreen-btn:hover{background:rgba(221,0,0,.72);border-color:rgba(221,0,0,.5)}@media(max-width:760px){.gls-bbb-player-wrap{border-radius:10px}.gls-bbb-fullscreen-btn{bottom:108px;left:8px;height:40px;padding:0 12px;font-size:15px;gap:7px;border-radius:13px}.gls-bbb-fullscreen-btn .gls-fs-label{display:block;font-size:13px;font-weight:850;font-family:"IRANSansXFaNum",Tahoma,sans-serif;letter-spacing:0}}
.gls-table-context{display:none;position:absolute;min-width:230px;background:#fff;border:1px solid #e2e8f0;border-radius:18px;padding:12px;box-shadow:0 24px 60px rgba(15,23,42,.22);z-index:999999;direction:rtl}.gls-table-context.open{display:flex;flex-direction:column;gap:8px}.gls-table-context strong{font-size:13px;color:#111827;font-weight:900;padding-bottom:6px;border-bottom:1px solid #f1f5f9}.gls-table-ctx-scope-btns{display:grid;grid-template-columns:1fr 1fr;gap:6px}.gls-table-context [data-scope]{border:0;border-radius:11px;background:#f8fafc;color:#111827;padding:9px 10px;text-align:center;cursor:pointer;font-family:inherit!important;font-size:13px;font-weight:800;transition:.14s;display:block;width:100%}.gls-table-context [data-scope]:hover{background:#ffce00}.gls-table-context [data-scope="clear"]{background:#fee2e2;color:#9b1c1c}.gls-table-context [data-scope="clear"]:hover{background:#fca5a5}.gls-table-context-colors{display:grid;grid-template-columns:repeat(6,1fr);gap:6px;padding-top:2px}.gls-table-context-colors i{height:26px;border-radius:8px;border:1.5px solid rgba(15,23,42,.12);cursor:pointer;transition:.14s;display:block}.gls-table-context-colors i:hover{transform:scale(1.15);border-color:#111}.gls-table-selected-cell{outline:3px solid rgba(255,206,0,.7)!important;outline-offset:-2px!important}
.gls-profile-panel{background:#fff;border:1px solid #e8eaf0;border-radius:22px;padding:22px;box-shadow:0 8px 28px rgba(16,24,40,.06)}.gls-profile-head{display:flex;align-items:center;gap:16px;padding:16px;border:1px solid #eef2f7;border-radius:18px;background:linear-gradient(135deg,#f8fafc,#f1f5f9);margin-bottom:18px}.gls-profile-avatar{width:68px;height:68px;border-radius:22px;object-fit:cover;border:2px solid #e2e8f0;box-shadow:0 6px 18px rgba(16,24,40,.1)}.gls-profile-head strong{display:block;font-size:16px;color:#0f172a;font-weight:900}.gls-profile-head small{display:block;font-size:12px;color:#64748b;direction:ltr;text-align:right;margin-top:3px}.gls-profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}@media(max-width:600px){.gls-profile-grid{grid-template-columns:1fr}}.gls-profile-panel .gls-form label{margin:14px 0 8px;font-size:13px;color:#374151}.gls-profile-section-title{font-size:13px;font-weight:900;color:#6b7280;text-transform:uppercase;letter-spacing:.04em;margin:18px 0 10px;padding-bottom:6px;border-bottom:1px solid #f1f5f9}.gls-profile-panel .gls-btn[type=submit]{margin-top:6px;min-width:140px}.gls-profile-result{font-size:13px;color:#166534;margin-top:10px;font-weight:800}
.gls-editor u,.gls-lesson-view u,.gls-print-paper u{text-decoration-line:underline;text-underline-offset:5px;text-decoration-thickness:1.5px}
.gls-editor mark,.gls-lesson-view mark,.gls-print-paper mark,.paper mark{display:inline;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;color-adjust:exact!important}
@media print{.gls-print-paper mark,.paper mark{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;color-adjust:exact!important}}
/* نسخه ۱۴.۶: هر عنصری با رنگ پس‌زمینه‌ی درون‌خطی (span/mark/...) در نمایش کاربر و پرینت رنگش حفظ شود */
.gls-lesson-view [style*="background"],.gls-print-paper [style*="background"],.paper [style*="background"],.gls-lesson-view font[color],.gls-print-paper font[color]{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;color-adjust:exact!important}
@media print{.gls-lesson-view [style*="background"],.gls-print-paper [style*="background"],.paper [style*="background"]{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important;color-adjust:exact!important}}
/* نسخه ۱۴.۶: دکمه‌ی محدوده‌ی فعال در منوی جدول */
.gls-table-context [data-scope].gls-scope-active{background:#ffce00!important;color:#111!important;font-weight:800!important;box-shadow:0 0 0 2px rgba(255,206,0,.5)!important}
.gls-table-ctx-hint{display:block;font-size:11px;color:#6b7280;margin:4px 0 2px;text-align:center}

/* ===== Fix #9: Next class hero card ===== */
.gls-next-class-hero{background:linear-gradient(135deg,#111827 0%,#1f2937 100%);border-radius:20px;padding:18px 20px;margin-bottom:16px;color:#fff;position:relative;overflow:hidden}
.gls-next-class-hero:before{content:"🎓";position:absolute;right:-12px;top:-8px;font-size:72px;opacity:.07;pointer-events:none}
.gls-next-class-label{font-size:11px;font-weight:900;color:#facc15;text-transform:uppercase;letter-spacing:.06em;margin-bottom:10px}
.gls-next-class-body{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
.gls-next-class-info strong{display:block;font-size:16px;font-weight:900;color:#fff;margin-bottom:4px}
.gls-next-class-info span{font-size:13px;color:#9ca3af}
.gls-next-class-actions{flex:0 0 auto}
.gls-next-class-hero .gls-class-link-btn{background:#facc15!important;color:#111!important;border-radius:12px!important;font-weight:900!important}
@media(max-width:600px){.gls-next-class-body{flex-direction:column;align-items:flex-start}}

/* ===== Fix #10: Mobile session chip row ===== */
.gls-session-chips{display:flex;gap:7px;overflow-x:auto;padding:10px 14px 8px;scrollbar-width:none;-ms-overflow-style:none;margin:0 0 2px}
.gls-session-chips::-webkit-scrollbar{display:none}
.gls-session-chip{flex:0 0 auto;padding:5px 12px;border-radius:999px;font-size:12px;font-weight:800;background:#f1f5f9;color:#374151;border:1px solid #e2e8f0;text-decoration:none;white-space:nowrap;transition:.18s}
.gls-session-chip:hover{background:#e2e8f0;color:#111}
.gls-session-chip.active{background:#111827;color:#fff;border-color:#111827}
.gls-session-chip.absent{background:#fee2e2;color:#991b1b;border-color:#fecaca}
@media(min-width:801px){.gls-session-chips{display:none}}

/* ===== Fix #12: Score ring wrap + tooltip ===== */
.gls-ring-wrap{position:relative;display:inline-flex;flex-direction:column;align-items:center;gap:5px}
.gls-ring-label{font-size:11px;font-weight:800;color:#667085;text-align:center;white-space:nowrap}

/* ===== Fix #14: AI reading card header ===== */
.gls-ai-reading-header{display:flex;align-items:flex-start;gap:12px;margin-bottom:14px;direction:ltr;text-align:left}
.gls-ai-reading-flag{font-size:2rem;line-height:1;flex:0 0 auto}
.gls-ai-reading-badge{display:inline-block;background:#dbeafe;color:#1d4ed8;border-radius:999px;padding:2px 10px;font-size:11px;font-weight:800;font-family:Tahoma,Arial,sans-serif;margin-top:4px}
.gls-ai-reading{border:2px solid #bfdbfe!important;background:linear-gradient(135deg,#eff6ff,#fff)!important}
.gls-ai-reading h3{font-family:Tahoma,Arial,sans-serif!important}

/* ===== Fix #15: Copy link + ICS buttons - match gls-class-link-btn pill style ===== */
.gls-copy-link-btn,.gls-ics-btn{
    display:inline-flex!important;align-items:center!important;justify-content:center!important;gap:4px!important;
    border:1px solid #e2e8f0!important;border-radius:10px!important;background:#f8fafc!important;color:#374151!important;
    font-size:13px!important;font-weight:700!important;padding:7px 12px!important;margin-left:5px!important;
    cursor:pointer!important;text-decoration:none!important;transition:.15s!important;
    font-family:"IRANSansXFaNum",Tahoma,sans-serif!important;line-height:1!important;white-space:nowrap!important;
    box-shadow:none!important;
}
.gls-copy-link-btn:hover,.gls-ics-btn:hover{background:#111!important;color:#fff!important;border-color:#111!important;}
.gls-next-class-hero .gls-copy-link-btn,.gls-next-class-hero .gls-ics-btn{background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.25)}
.gls-next-class-hero .gls-copy-link-btn:hover,.gls-next-class-hero .gls-ics-btn:hover{background:rgba(255,255,255,.3)}
CSS;
    }

    private function js(){ return <<<'JS'
// The dashboard bundle must survive script optimizers that defer or delay
// jQuery: wait for it instead of assuming it exists at parse time.
(function(){var glsBoot=function($){
  // یک مالک واحد برای وضعیت تب‌ها: تا وقتی این بسته فعال است، نسخه پشتیبان
  // (nav fallback) دخالتی نمی‌کند تا هرگز دو کلاس حالت هم‌زمان روی ریشه ننشیند.
  window.glsNavReady=true;
  var GLS_MODES=['content','reserve','sessions','bookings','tasks','performance','notifications','profile'];
  var GLS_PANELS={reserve:'gls-mobile-book-class',bookings:'gls-mobile-bookings',tasks:'gls-mobile-tasks',performance:'gls-mobile-performance',notifications:'gls-mobile-notifications',profile:'gls-mobile-profile'};
  function glsSetMode(root,target){
    // حذف همه حالت‌ها پیش از افزودن حالت جدید. اگر دو کلاس حالت هم‌زمان روی ریشه
    // بماند، قواعد CSS دو تب «رزرو کلاس» و «کلاس‌های آینده» یکدیگر را خنثی می‌کنند
    // و آن دو تب سفید باز می‌شوند.
    for(var i=0;i<GLS_MODES.length;i++) root.removeClass('gls-mobile-mode-'+GLS_MODES[i]);
    root.addClass('gls-mobile-mode-'+target);
  }
  function glsEnsureVisible(root,target){
    // شبکه ایمنی: اگر پنل مقصد وجود نداشته باشد یا پنهان بماند، به جای صفحه سفید
    // یک پیام قابل فهم نشان داده می‌شود.
    var id=GLS_PANELS[target];
    if(!id) return;
    var panel=document.getElementById(id);
    var visible=false;
    for(var node=panel;node&&node!==document.body;node=node.parentNode){
      if(node.nodeType!==1) break;
      if(window.getComputedStyle(node).display==='none'){visible=false;break;}
      visible=true;
    }
    if(panel&&visible) return;
    var host=root.find('.gls-dashboard').first();
    if(!host.length) host=root.find('.gls-inner').first();
    if(!host.length) return;
    var notice=root.find('.gls-tab-recovery');
    if(!notice.length){ notice=$('<div class="gls-tab-recovery gls-panel-empty"></div>'); host.append(notice); }
    notice.text('محتوای این بخش در حال حاضر در دسترس نیست. صفحه را یک بار تازه کنید؛ اگر باز هم تکرار شد، به پشتیبانی اطلاع دهید.').show();
    host.css('display','block');
  }
  function glsTaskBadgeKey(){return 'gls_task_badge_seen_'+($('.gls-student-full').data('user')||'user')}
  function glsCurrentTaskBadgeCount(){var n=0;$('.gls-nav-badge,.gls-mobile-nav-btn i').each(function(){n=Math.max(n,parseInt($(this).attr('data-count')||$(this).text()||'0',10)||0)});return n}
  function glsRefreshTaskBadges(){var current=glsCurrentTaskBadgeCount(),seen=0;try{seen=parseInt(localStorage.getItem(glsTaskBadgeKey())||'0',10)||0}catch(e){}if(current<=seen){$('.gls-nav-badge,.gls-mobile-nav-btn i').hide()}}
  function glsMarkTasksSeen(){var current=glsCurrentTaskBadgeCount();try{localStorage.setItem(glsTaskBadgeKey(),String(current))}catch(e){}$('.gls-nav-badge,.gls-mobile-nav-btn i').fadeOut(120)}
  glsRefreshTaskBadges();
  function exec(cmd,val){document.execCommand(cmd,false,val||null)}
  function focusEditor(){var e=document.getElementById('gls-editor'); if(e)e.focus()}
  var glsTableTarget=null,glsTableScope='cell',glsTableColor='#fff7cc';
  function glsBuildTablePicker(){
    var grid=$('.gls-table-grid');
    if(!grid.length||grid.children().length)return;
    for(var r=1;r<=8;r++){for(var c=1;c<=8;c++){grid.append('<button type="button" data-r="'+r+'" data-c="'+c+'"></button>')}}
  }
  glsBuildTablePicker();
  function glsInsertTable(rows,cols){
    focusEditor();
    rows=parseInt(rows,10)||2; cols=parseInt(cols,10)||2;
    var html='<table class="gls-lesson-table"><tbody>';
    for(var r=0;r<rows;r++){html+='<tr>';for(var c=0;c<cols;c++){html+='<td tabindex="0"><br></td>'}html+='</tr>'}
    html+='</tbody></table><p><br></p>';
    exec('insertHTML',html);
    $('.gls-table-picker').removeClass('open');
  }
  function glsCellIndex(td){return $(td).closest('tr').children('th,td').index(td)}
  function glsApplyTableColor(target,scope,color){
    if(!target)return;
    var td=$(target),table=td.closest('table');
    if(scope==='clear') color='';
    function setBg(el,c){
      if(c){$(el).css('background-color',c).attr('bgcolor',c).attr('data-gls-bg',c);}
      else{$(el).css('background-color','').removeAttr('bgcolor').removeAttr('data-gls-bg');}
    }
    if(scope==='row') td.closest('tr').children('th,td').each(function(){setBg(this,color)});
    else if(scope==='col'){
      var idx=glsCellIndex(td);
      table.find('tr').each(function(){var cell=$(this).children('th,td').eq(idx); if(cell.length)setBg(cell,color)});
    } else setBg(td,color);
  }
  $(document).on('mouseenter','.gls-table-grid button',function(){
    var r=$(this).data('r'),c=$(this).data('c');
    $('.gls-table-grid button').each(function(){$(this).toggleClass('hot',$(this).data('r')<=r&&$(this).data('c')<=c)});
    $('.gls-table-picker-size').text(r+' × '+c);
  });
  $(document).on('mouseleave','.gls-table-picker',function(){
    $('.gls-table-grid button').removeClass('hot');
    $('.gls-table-picker-size').text('0 × 0');
  });
  $(document).on('click','.gls-table-grid button',function(e){
    e.preventDefault(); e.stopPropagation();
    glsInsertTable($(this).data('r'),$(this).data('c'));
  });
  // نسخه ۱۴.۶: انتخاب و هایلایتِ سلول/سطر/ستون + بستنِ منو پس از انتخاب رنگ یا کلیک بیرون
  function glsCellsForScope(target,scope){
    var td=$(target); if(!td.length) return $();
    if(scope==='row') return td.closest('tr').children('th,td');
    if(scope==='col'){ var idx=td.closest('tr').children('th,td').index(td); return td.closest('table').find('tr').children('th,td').filter(function(){return $(this).closest('tr').children('th,td').index(this)===idx;}); }
    return td;
  }
  function glsHighlightScope(target,scope){
    $('#gls-editor table td,#gls-editor table th').removeClass('gls-table-selected-cell');
    glsCellsForScope(target,scope).addClass('gls-table-selected-cell');
  }
  function glsCloseTableCtx(){
    $('.gls-table-context').removeClass('open');
    $('#gls-editor table td,#gls-editor table th').removeClass('gls-table-selected-cell');
  }
  $(document).on('contextmenu','#gls-editor table td,#gls-editor table th',function(e){
    e.preventDefault();
    glsTableTarget=this;
    glsTableScope='cell';
    $('.gls-table-context [data-scope]').removeClass('gls-scope-active');
    $('.gls-table-context [data-scope="cell"]').addClass('gls-scope-active');
    glsHighlightScope(this,'cell');
    var mw=210, x=Math.max(8, e.pageX-mw), y=e.pageY+8;
    $('.gls-table-context').css({top:y,left:x}).addClass('open').attr('aria-hidden','false');
  });
  // انتخاب محدوده: سلول / سطر / ستون (فقط هایلایت می‌کند؛ رنگ با کلیک روی رنگ اعمال می‌شود)
  $(document).on('click','.gls-table-context [data-scope]',function(e){
    e.preventDefault(); e.stopPropagation();
    var scope=$(this).data('scope');
    if(scope==='clear'){
      glsApplyTableColor(glsTableTarget,glsTableScope,'');
      glsCloseTableCtx();
      return;
    }
    glsTableScope=scope;
    $('.gls-table-context [data-scope]').removeClass('gls-scope-active');
    $(this).addClass('gls-scope-active');
    glsHighlightScope(glsTableTarget,glsTableScope);
  });
  // اعمال رنگ روی محدوده‌ی انتخاب‌شده و بستن منو
  $(document).on('click','.gls-table-context-colors i',function(e){
    e.preventDefault(); e.stopPropagation();
    glsTableColor=$(this).data('color');
    glsApplyTableColor(glsTableTarget,glsTableScope,glsTableColor);
    glsCloseTableCtx();
  });
  // هر کلیک بیرون از منو (حتی داخل جدول) منو را می‌بندد
  $(document).on('click',function(e){
    if(!$(e.target).closest('.gls-table-context').length) glsCloseTableCtx();
    if(!$(e.target).closest('.gls-table-picker,.gls-table-trigger').length) $('.gls-table-picker').removeClass('open');
  });

  $(document).on('click','.gls-tab',function(){
    if($(this).is(':disabled') || $(this).hasClass('is-disabled')) return;
    var t=$(this).data('tab');
    $('.gls-tab').removeClass('active');
    $(this).addClass('active');
    $('.gls-panel').removeClass('active');
    $('#'+t).addClass('active');
  });

  $(document).on('change','.gls-session-select',function(){
    var url=$(this).val();
    if(url) window.location.href=url;
  });

  function applyBlockDirection(dir){
    focusEditor();
    var sel=window.getSelection();
    if(!sel||!sel.rangeCount)return;
    var node=sel.anchorNode;
    if(node&&node.nodeType===3)node=node.parentNode;
    var editor=document.getElementById('gls-editor');
    if(!node||!editor||!editor.contains(node))return;
    while(node&&node!==editor&&!/^(P|DIV|LI|H1|H2|H3|H4|BLOCKQUOTE)$/i.test(node.nodeName)){
      node=node.parentNode;
    }
    if(!node||node===editor){
      document.execCommand('formatBlock',false,'div');
      node=window.getSelection().anchorNode;
      if(node&&node.nodeType===3)node=node.parentNode;
    }
    if(node&&node!==editor){
      node.setAttribute('dir',dir);
      node.style.direction=dir;
      node.style.textAlign=dir==='ltr'?'left':'right';
      node.style.unicodeBidi='plaintext';
    } else {
      editor.setAttribute('dir',dir);
      editor.style.direction=dir;
      editor.style.textAlign=dir==='ltr'?'left':'right';
    }
  }

  $(document).on('click','.gls-tool',function(e){
    e.preventDefault();
    focusEditor();
    var cmd=$(this).data('cmd'),val=$(this).data('val');
    if(cmd==='createLink'){
      val=prompt('لینک را وارد کنید:','https://');
      if(!val)return;
    }
    if(cmd==='glsDir'){
      applyBlockDirection(val||'rtl');
      return;
    }
    if(cmd==='glsTable'){
      $('.gls-table-picker').toggleClass('open');
      return;
    }
    if(cmd) exec(cmd,val);
  });

  $(document).on('change','.gls-size-select',function(){
    focusEditor();
    var v=$(this).val();
    if(v==='custom'){
      v=prompt('سایز دلخواه را با واحد px وارد کنید. مثلا 28','28');
      if(!v){$(this).val('');return}
      v=parseInt(v,10);
      if(!v)return;
      exec('fontSize','7');
      $('#gls-editor font[size="7"]').removeAttr('size').css('font-size',v+'px');
    } else if(v){
      exec('fontSize','7');
      $('#gls-editor font[size="7"]').removeAttr('size').css('font-size',parseInt(v,10)+'px');
    }
    $(this).val('');
  });

  $(document).on('click','.gls-color-trigger',function(e){
    e.preventDefault();
    e.stopPropagation();
    $('.gls-color-pop').not($(this).closest('.gls-color-pop')).removeClass('open');
    $(this).closest('.gls-color-pop').toggleClass('open');
  });

  // Apply color: foreColor uses execCommand; hiliteColor wraps selection in <mark> with inline bg
  function glsApplyEditorColor(cmd,color){
    focusEditor();
    if(cmd==='hiliteColor'){
      // Reliable cross-browser highlight: wrap selected text in a span with inline background-color
      var sel=window.getSelection();
      if(!sel||sel.rangeCount===0||sel.isCollapsed){return;}
      var range=sel.getRangeAt(0);
      var mark=document.createElement('mark');
      mark.style.backgroundColor=color;
      mark.style.webkitPrintColorAdjust='exact';
      mark.style.printColorAdjust='exact';
      // If color is empty/white, remove highlight instead
      if(!color||color==='#ffffff'||color==='white'||color==='transparent'){
        // unwrap any existing marks in selection
        var marks=[];
        var editor=document.getElementById('gls-editor');
        if(editor) editor.querySelectorAll('mark[style]').forEach(function(m){marks.push(m);});
        marks.forEach(function(m){
          if(sel.containsNode(m,true)){
            var frag=document.createDocumentFragment();
            while(m.firstChild)frag.appendChild(m.firstChild);
            m.parentNode.replaceChild(frag,m);
          }
        });
        return;
      }
      try{range.surroundContents(mark);}
      catch(e){
        // Selection spans multiple elements: extract and wrap
        var frag=range.extractContents();
        mark.appendChild(frag);
        range.insertNode(mark);
      }
      sel.removeAllRanges();
      var newRange=document.createRange();
      newRange.selectNodeContents(mark);
      sel.addRange(newRange);
    } else {
      // foreColor: نسخه ۱۴.۶ - به‌جای execCommand که <font color> می‌سازد (و wp_kses_post آن را حذف می‌کند)،
      // انتخاب را در <span style="color:..."> می‌پیچیم تا رنگ متن در ذخیره، نمایش کاربر و پرینت باقی بماند.
      var sel2=window.getSelection();
      if(!sel2||sel2.rangeCount===0||sel2.isCollapsed){return;}
      if(!color){return;}
      var range2=sel2.getRangeAt(0);
      var span=document.createElement('span');
      span.style.color=color;
      try{range2.surroundContents(span);}
      catch(e){var frag2=range2.extractContents();span.appendChild(frag2);range2.insertNode(span);}
      sel2.removeAllRanges();
      var nr=document.createRange();nr.selectNodeContents(span);sel2.addRange(nr);
    }
  }

  $(document).on('click','.gls-color-chip',function(e){
    e.preventDefault();
    var p=$(this).closest('.gls-color-pop');
    glsApplyEditorColor(p.data('cmd'),$(this).data('color'));
    p.removeClass('open');
  });

  $(document).on('click','.gls-apply-custom-color',function(e){
    e.preventDefault();
    var p=$(this).closest('.gls-color-pop'),c=p.find('.gls-custom-color-input').val();
    if(c){
      glsApplyEditorColor(p.data('cmd'),c);
      p.removeClass('open');
    }
  });

  $(document).on('click',function(){
    $('.gls-color-pop').removeClass('open');
  });

  $(document).on('click','.gls-fullscreen-btn',function(e){
    e.preventDefault();
    var w=$('.gls-editor-wrap');
    w.toggleClass('gls-editor-fullscreen');
    $('body').toggleClass('gls-no-scroll',w.hasClass('gls-editor-fullscreen'));
  });

  $(document).on('keydown',function(e){
    if(e.key==='Escape'&&$('.gls-editor-wrap').hasClass('gls-editor-fullscreen')){
      $('.gls-editor-wrap').removeClass('gls-editor-fullscreen');
      $('body').removeClass('gls-no-scroll');
    }
  });

  // BBB fullscreen button
  $(document).on('click','.gls-bbb-fullscreen-btn',function(){
    var wrap=$(this).closest('.gls-bbb-player-wrap')[0];
    if(!wrap) return;
    if(document.fullscreenElement){document.exitFullscreen();}
    else if(wrap.requestFullscreen){wrap.requestFullscreen();}
    else if(wrap.webkitRequestFullscreen){wrap.webkitRequestFullscreen();}
  });

  // Table keyboard nav: Tab moves to next cell, ArrowDown goes to cell below
  $(document).on('keydown','#gls-editor table td,#gls-editor table th',function(e){
    var td=$(this),tr=td.closest('tr'),table=td.closest('table');
    var cells=tr.children('th,td'),idx=cells.index(td);
    if(e.key==='Tab'){
      e.preventDefault();
      var next=e.shiftKey?cells.eq(idx-1):cells.eq(idx+1);
      if(!next.length){
        var nextTr=e.shiftKey?tr.prev('tr'):tr.next('tr');
        if(nextTr.length){
          var nc=nextTr.children('th,td');
          next=e.shiftKey?nc.last():nc.first();
        }
      }
      if(next.length){next[0].focus();var r=document.createRange(),s=window.getSelection();r.selectNodeContents(next[0]);r.collapse(false);s.removeAllRanges();s.addRange(r);}
    } else if(e.key==='ArrowDown'||e.key==='ArrowUp'){
      var targetTr=e.key==='ArrowDown'?tr.next('tr'):tr.prev('tr');
      if(targetTr.length){
        var targetCell=targetTr.children('th,td').eq(idx);
        if(!targetCell.length)targetCell=targetTr.children('th,td').last();
        if(targetCell.length){e.preventDefault();targetCell[0].focus();var r2=document.createRange(),s2=window.getSelection();r2.selectNodeContents(targetCell[0]);r2.collapse(false);s2.removeAllRanges();s2.addRange(r2);}
      }
    }
  });

  $(document).on('click','.gls-save-lesson',function(e){
    e.preventDefault();
    var b=$(this),sid=b.data('session');
    b.text('در حال ذخیره...');
    $.post(GLS_DATA.ajax,{
      action:'gls_save_lesson',
      nonce:GLS_DATA.nonce,
      session_id:sid,
      content:$('#gls-editor').html()
    },function(r){
      b.text(r.success?'ذخیره شد':'خطا');
      setTimeout(function(){b.text('💾')},1300);
    });
  });

  var autosaveTimer=null;
  $(document).on('input','#gls-editor',function(){
    clearTimeout(autosaveTimer);
    autosaveTimer=setTimeout(function(){
      $('.gls-save-lesson').trigger('click');
    },12000);
  });

  $(document).on('click','.gls-insert-media',function(e){
    e.preventDefault();
    var frame=wp.media({title:GLS_DATA.mediaTitle,button:{text:'درج در جزوه'},multiple:false});
    frame.on('select',function(){
      var a=frame.state().get('selection').first().toJSON();
      focusEditor();
      if(a.type==='image') exec('insertHTML','<img src="'+a.url+'" alt="" />');
      else exec('insertHTML','<a href="'+a.url+'" target="_blank" rel="noopener">'+(a.filename||'فایل')+'</a>');
    });
    frame.open();
  });

  $(document).on('click','.gls-media-btn',function(e){
    e.preventDefault();
    var t=$(this).data('target');
    var frame=wp.media({title:GLS_DATA.mediaTitle,button:{text:GLS_DATA.mediaButton},multiple:false});
    frame.on('select',function(){
      var a=frame.state().get('selection').first().toJSON();
      $('#'+t).val(a.id);
      $('#'+t+'_url').val(a.url);
      $('#'+t+'_preview').html('<span class="gls-file-chip">'+a.filename+'</span>');
    });
    frame.open();
  });

  $(document).on('submit','.gls-ajax-item-form',function(e){
    e.preventDefault();
    var f=$(this);
    $.post(GLS_DATA.ajax,f.serialize()+'&action=gls_add_item&nonce='+GLS_DATA.nonce,function(r){
      if(r.success)location.reload();
      else alert(r.data||'خطا');
    });
  });

  $(document).on('click','.gls-delete-item',function(e){
    e.preventDefault();
    if(!confirm('حذف شود؟'))return;
    $.post(GLS_DATA.ajax,{
      action:'gls_delete_item',
      nonce:GLS_DATA.nonce,
      item_id:$(this).data('id')
    },function(r){
      if(r.success)location.reload();
    });
  });

  if($('.gls-sortable').length){
    $('.gls-sortable').sortable({
      update:function(){
        var ids=[];
        $('.gls-sortable .gls-item').each(function(){ids.push($(this).data('id'))});
        $.post(GLS_DATA.ajax,{action:'gls_reorder_items',nonce:GLS_DATA.nonce,ids:ids});
      }
    });
  }

  $(document).on('submit','.gls-homework-form',function(e){
    e.preventDefault();
    var form=this,fd=new FormData(form);
    fd.append('action','gls_submit_homework');
    fd.append('nonce',GLS_DATA.nonce);
    var btn=$(form).find('button[type=submit]');
    btn.prop('disabled',true).text('در حال ارسال...');
    $.ajax({
      url:GLS_DATA.ajax,
      type:'POST',
      data:fd,
      contentType:false,
      processData:false,
      success:function(r){
        btn.prop('disabled',false).text('ارسال جواب');
        if(r.success)location.reload();
        else alert(r.data||'خطا در ارسال');
      }
    });
  });

  $(document).on('click','.gls-homework-toggle',function(){
    $(this).closest('.gls-homework-entry').toggleClass('open');
  });


  $(document).on('click','.gls-task-toggle',function(e){
    e.preventDefault();
    glsMarkTasksSeen();
    $(this).closest('.gls-task-row').toggleClass('open');
  });

  $(document).on('click','.gls-task-sub-toggle',function(e){
    e.preventDefault();
    var box=$(this).closest('.gls-task-row').find('[data-sub-detail="'+$(this).data('sub')+'"]');
    box.toggleClass('gls-hidden');
  });

  $(document).on('click','.gls-pdf-lesson,.gls-all-lessons-btn',function(e){
    e.preventDefault();
    var btn=$(this);
    var origText=btn.text();
    var isAll=btn.hasClass('gls-all-lessons-btn');
    var title=isAll?'تمام جزوه‌های من':(btn.data('title')||'جزوه جلسه');
    var lessonSource=isAll?document.getElementById('gls-all-lessons-source'):document.querySelector('#gls-lesson .gls-lesson-view');
    if(!lessonSource||!lessonSource.innerHTML){alert('جزوه‌ای برای ساخت PDF وجود ندارد.');return;}

    // استایل‌های محاسبه‌شده‌ی نمای زنده را روی کپی جزوه inline می‌کنیم. این کار رنگ متن،
    // هایلایت و رنگ سلول‌های قدیمی را حتی اگر با <font>، کلاس CSS یا bgcolor ساخته شده باشند
    // قبل از ورود به html2canvas/پنجره چاپ به شکل پایدار حفظ می‌کند.
    var lessonClone=lessonSource.cloneNode(true);
    var sourceNodes=[lessonSource].concat(Array.prototype.slice.call(lessonSource.querySelectorAll('*')));
    var cloneNodes=[lessonClone].concat(Array.prototype.slice.call(lessonClone.querySelectorAll('*')));
    sourceNodes.forEach(function(src,i){
      var dst=cloneNodes[i];if(!dst)return;
      var cs=window.getComputedStyle(src);
      ['color','fontSize','fontWeight','fontStyle','textDecorationLine','textDecorationColor'].forEach(function(prop){
        if(cs[prop])dst.style[prop]=cs[prop];
      });
      // تحمیل direction روی span/markهای داخل متن ترکیبی فارسی و آلمانی ترتیب بصری
      // قطعه‌ها را خراب می‌کند. جهت فقط برای بلوک‌ها یا عناصر دارای dir صریح کپی شود.
      var blockLike=/^(block|flex|grid|table|list-item)$/.test(cs.display)||/^(P|DIV|LI|H1|H2|H3|H4|BLOCKQUOTE|TD|TH|ARTICLE|HEADER)$/i.test(dst.tagName);
      if(blockLike||src.hasAttribute('dir')||src.style.direction){
        if(cs.direction)dst.style.direction=cs.direction;
        if(cs.textAlign)dst.style.textAlign=cs.textAlign;
        if(cs.unicodeBidi)dst.style.unicodeBidi=cs.unicodeBidi;
      }
      var bg=cs.backgroundColor;
      if(bg&&bg!=='transparent'&&bg!=='rgba(0, 0, 0, 0)'){
        dst.style.backgroundColor=bg;
        dst.style.webkitPrintColorAdjust='exact';
        dst.style.printColorAdjust='exact';
        if(dst.tagName==='MARK'||dst.hasAttribute('data-gls-bg')){
          dst.style.boxShadow='inset 0 0 0 1000px '+bg;
          dst.style.webkitBoxDecorationBreak='clone';
          dst.style.boxDecorationBreak='clone';
        }
      }
      if(cs.backgroundImage&&cs.backgroundImage!=='none')dst.style.backgroundImage=cs.backgroundImage;
    });

    var $tmp=$(lessonClone);
    $tmp.find('img.emoji,img.wp-smiley').each(function(){
      $(this).attr({width:'16',height:'16'}).css({width:'1em',height:'1em',maxWidth:'1em',maxHeight:'1em',display:'inline',verticalAlign:'-0.12em',margin:'0 .06em'});
    });
    // Ensure mark background colors are preserved as inline style
    $tmp.find('mark').each(function(){
      var bg=$(this).css('background-color')||$(this).attr('style')||'';
      if(!$(this).style||!$(this).get(0).style.backgroundColor){
        var existBg=$(this).attr('style')||'';
        if(existBg.indexOf('background-color')===-1) $(this).css('background-color','#fef9c3');
      }
    });
    var lesson=$tmp.html();
    var pdfChunks=isAll?$tmp.children('.gls-pdf-session').map(function(){return this.outerHTML;}).get():[lesson];
    if(!pdfChunks.length)pdfChunks=[lesson];
    var safeTitle=String(title).replace(/[\\\/:*?"<>|]+/g,'-');
    var fontSrc=GLS_DATA.fontWoff2?'src:url("'+GLS_DATA.fontWoff2+'") format("woff2"),url("'+(GLS_DATA.fontWoff||'')+'") format("woff"),url("'+(GLS_DATA.fontTtf||'')+'") format("truetype");font-style:normal;font-display:swap;':'';var fontCss=fontSrc?'@font-face{font-family:IRANSansXFaNum;'+fontSrc+'font-weight:400;}@font-face{font-family:IRANSansXFaNum;'+fontSrc+'font-weight:500;}@font-face{font-family:IRANSansXFaNum;'+fontSrc+'font-weight:700;}@font-face{font-family:IRANSansXFaNum;'+fontSrc+'font-weight:900;}':'';
    // Try html2canvas + jsPDF for real PDF with background colors (mobile-friendly)
    var glsTryRealPdf=function(htmlChunks,fileName,fallback){
      if(typeof window.html2canvas==='undefined'||typeof window.jspdf==='undefined'){fallback();return;}
      btn.html('⏳ در حال ساخت...').prop('disabled',true);
      var jsPDF=window.jspdf.jsPDF;
      var pdf=new jsPDF({orientation:'portrait',unit:'mm',format:'a4'});
      var pageW=pdf.internal.pageSize.getWidth(),pageH=pdf.internal.pageSize.getHeight();
      var sideMargin=isAll?22:16,topMargin=isAll?18:16,bottomMargin=26;
      var contentW=pageW-sideMargin*2;
      var scale=2;
      var pageIdx=0;

      function renderChunk(index){
        if(index>=htmlChunks.length){pdf.save(fileName+'.pdf');btn.html(origText).prop('disabled',false);return Promise.resolve();}
        var tmp=document.createElement('div');
        tmp.style.cssText='position:fixed;left:0;top:0;z-index:-2147483647;width:780px;background:#fff;padding:'+(isAll?'18px 24px':'30px 42px')+';box-sizing:border-box;direction:rtl;text-align:right;font-family:IRANSansXFaNum,Tahoma,sans-serif;font-size:'+(isAll?'22px':'12.5pt')+';line-height:'+(isAll?'1.9':'2.1')+';color:#111;word-break:normal;overflow-wrap:anywhere;pointer-events:none;';
        tmp.innerHTML=htmlChunks[index];document.body.appendChild(tmp);
        tmp.querySelectorAll('*').forEach(function(el){
          if(isAll){el.style.fontSize='22px';el.style.lineHeight='1.9';}
          var bg=el.style.backgroundColor||el.getAttribute('bgcolor')||'';
          if(el.tagName==='MARK'&&!bg)bg='#ffff00';
          if(bg&&bg!=='transparent'&&bg!=='rgba(0, 0, 0, 0)'){el.style.backgroundColor=bg;el.style.webkitPrintColorAdjust='exact';el.style.printColorAdjust='exact';}
          if(!el.style.fontFamily)el.style.fontFamily='IRANSansXFaNum,Tahoma,Arial,sans-serif';
        });
        tmp.querySelectorAll('table').forEach(function(el){el.style.width='100%';el.style.borderCollapse='collapse';el.style.tableLayout='fixed';});
        tmp.querySelectorAll('table td,table th').forEach(function(el){el.style.border='1px solid #d9dee8';el.style.padding='8px 10px';el.style.verticalAlign='top';el.style.wordBreak='break-word';});
        var breaks=[];
        var fontReady=(document.fonts&&document.fonts.ready)?document.fonts.ready:Promise.resolve();
        var imageReady=Promise.all(Array.prototype.slice.call(tmp.querySelectorAll('img')).map(function(img){if(img.complete)return Promise.resolve();return new Promise(function(resolve){img.onload=resolve;img.onerror=resolve;});}));
        return Promise.all([fontReady,imageReady]).then(function(){
          var top=tmp.getBoundingClientRect().top,walker=document.createTreeWalker(tmp,NodeFilter.SHOW_TEXT,null),node;
          while((node=walker.nextNode())){if(!node.nodeValue||!node.nodeValue.trim())continue;var range=document.createRange();range.selectNodeContents(node);Array.prototype.forEach.call(range.getClientRects(),function(rect){if(rect.height>0)breaks.push(rect.bottom-top+4);});}
          tmp.querySelectorAll('p,div,li,tr,h1,h2,h3,h4,blockquote,img,table,article,header').forEach(function(el){var rect=el.getBoundingClientRect();if(rect.height>0)breaks.push(rect.bottom-top+5);});
          breaks.sort(function(a,b){return a-b;});
          return window.html2canvas(tmp,{scale:scale,useCORS:true,allowTaint:true,backgroundColor:'#ffffff',logging:false,windowWidth:884});
        }).then(function(canvas){
          var renderWidth=tmp.scrollWidth||780;if(tmp.parentNode)document.body.removeChild(tmp);
          var canvasPxPerMm=canvas.width/contentW,cssToCanvas=canvas.width/renderWidth;
          var pageHeightPx=Math.floor((pageH-topMargin-bottomMargin)*canvasPxPerMm);
          var candidates=breaks.map(function(v){return Math.round(v*cssToCanvas);});
          var y=0;
          while(y<canvas.height){
            if(pageIdx>0)pdf.addPage();
            var target=Math.min(y+pageHeightPx,canvas.height),cut=target;
            if(target<canvas.height){
              var minUseful=y+Math.floor(pageHeightPx*.58);
              for(var ci=0;ci<candidates.length;ci++){if(candidates[ci]>=minUseful&&candidates[ci]<=target-10)cut=candidates[ci];if(candidates[ci]>target)break;}
            }
            if(cut<=y+20)cut=target;
            var sliceH=cut-y,pageCanvas=document.createElement('canvas');pageCanvas.width=canvas.width;pageCanvas.height=sliceH;
            pageCanvas.getContext('2d').drawImage(canvas,0,y,canvas.width,sliceH,0,0,canvas.width,sliceH);
            pdf.addImage(pageCanvas.toDataURL('image/png'),'PNG',sideMargin,topMargin,contentW,sliceH/canvasPxPerMm);
            pdf.setFontSize(9);pdf.setTextColor(110,110,110);pdf.text(String(pageIdx+1),pageW/2,pageH-9,{align:'center'});
            y=cut;pageIdx++;
          }
          return renderChunk(index+1);
        });
      }
      renderChunk(0).catch(function(err){
        console.error('html2canvas error',err);
        fallback();
        btn.html(origText).prop('disabled',false);
      });
    };
    var fallbackMargins=isAll?'20mm 22mm 26mm 22mm':'18mm 16mm 24mm 16mm';
    var fallbackUniform=isAll?'.paper,.paper *{font-size:22px!important;line-height:1.9!important}':'';
    // Every '<' below is written as \x3C. Page optimizers and minifiers scan the
    // final page with regexes; a literal </body>, </html> or <style> inside this
    // string makes them inject markup mid-script, which closes the inline script
    // early and dumps the rest of this bundle as visible text in the footer.
    var html='\x3C!doctype html>\x3Chtml lang="fa" dir="rtl">\x3Chead>\x3Cmeta charset="utf-8">\x3Ctitle>'+safeTitle+'\x3C/title>\x3Cstyle>'+fontCss+'@page{size:A4;margin:'+fallbackMargins+'}html,body{margin:0;padding:0;background:#fff;color:#111;font-family:IRANSansXFaNum,Tahoma,sans-serif;direction:rtl;text-align:right;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}.paper{font-size:12.5pt;line-height:2;overflow:visible}'+fallbackUniform+'.paper *{max-width:100%!important;box-sizing:border-box;overflow-wrap:anywhere;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}.paper img{max-width:100%!important;height:auto!important}.paper table{width:100%!important;max-width:100%!important;border-collapse:collapse;table-layout:fixed}.paper table td,.paper table th{border:1px solid #d9dee8!important;padding:8px 10px!important;vertical-align:top!important;word-break:break-word}.paper hr{display:block!important;height:0!important;border:0!important;border-top:2px solid #444!important;margin:18px 0!important}.paper ul,.paper ol{padding-inline-start:28px;padding-inline-end:28px;list-style-position:outside}.paper li{display:list-item;margin:5px 0}.paper .gls-ai-reading,.paper .gls-ai-reading *{direction:ltr!important;text-align:left!important;unicode-bidi:isolate!important}\x3C/style>\x3C/head>\x3Cbody>\x3Cdiv class="paper">'+lesson+'\x3C/div>\x3C/body>\x3C/html>';
    // Fallback: open in hidden iframe and trigger print dialog
    var glsFallbackPrint=function(){
      var iframe=document.createElement('iframe');
      iframe.className='gls-pdf-frame';
      document.body.appendChild(iframe);
      iframe.contentWindow.document.open();
      iframe.contentWindow.document.write(html);
      iframe.contentWindow.document.close();
      var frameWindow=iframe.contentWindow;
      var fontsReady=frameWindow.document.fonts&&frameWindow.document.fonts.ready?frameWindow.document.fonts.ready:Promise.resolve();
      fontsReady.then(function(){setTimeout(function(){frameWindow.focus();frameWindow.print();},350)}).catch(function(){setTimeout(function(){frameWindow.focus();frameWindow.print();},500)});
      setTimeout(function(){try{document.body.removeChild(iframe)}catch(e){}},120000);
      btn.text('ذخیره PDF جزوه').prop('disabled',false);
    };
    glsTryRealPdf(pdfChunks,safeTitle,glsFallbackPrint);
  });

  $(document).on('click','.gls-toggle-bookings',function(e){
    e.preventDefault();
    var btn=$(this),panel=btn.closest('.gls-bookings-panel');
    panel.toggleClass('gls-bookings-expanded');
    var open=panel.hasClass('gls-bookings-expanded');
    btn.attr('data-more',open?'1':'0').text(open?'نمایش کمتر':'نمایش همه رزروها');
  });

  $(document).on('click','.gls-mobile-nav-btn,.gls-desktop-nav-btn',function(e){
    e.preventDefault();
    var btn=$(this),target=btn.data('target'),root=btn.closest('.gls-student-full');
    if(!root.length) root=$('.gls-student-full').first();

    glsSetMode(root,target);
    root.find('.gls-tab-recovery').hide();

    root.find('.gls-mobile-nav-btn,.gls-desktop-nav-btn').removeClass('active');
    root.find('.gls-mobile-nav-btn[data-target="'+target+'"],.gls-desktop-nav-btn[data-target="'+target+'"]').addClass('active');
    root.find('.gls-dashboard-card,.gls-booking-portal').removeClass('gls-mobile-active');

    if(target==='content'){
      return;
    }
    if(target==='reserve'){
      var reserve=root.find('#gls-mobile-book-class');
      reserve.addClass('gls-mobile-active');
      if(reserve.length && reserve.prop('tagName').toLowerCase()==='details') reserve.prop('open', true);
      // 4-3: scroll to calendar inside booking portal
      setTimeout(function(){
        var cal=document.getElementById('gtbp-calendar')||document.getElementById('step-2-wrap')||reserve[0];
        if(cal) cal.scrollIntoView({behavior:'smooth',block:'start'});
      }, 320);
      glsEnsureVisible(root,'reserve');
      return;
    }
    if(target==='bookings'){
      root.find('#gls-mobile-bookings').addClass('gls-mobile-active');
      glsEnsureVisible(root,'bookings');
      return;
    }
    if(target==='tasks'){
      glsMarkTasksSeen();
      root.find('#gls-mobile-tasks').addClass('gls-mobile-active');
      glsEnsureVisible(root,'tasks');
      return;
    }
    if(target==='performance'){
      root.find('#gls-mobile-performance').addClass('gls-mobile-active');
      glsEnsureVisible(root,'performance');
      return;
    }
    if(target==='notifications'){
      root.find('#gls-mobile-notifications').addClass('gls-mobile-active');
      glsEnsureVisible(root,'notifications');
      return;
    }
    if(target==='profile'){
      root.find('#gls-mobile-profile').addClass('gls-mobile-active');
      glsEnsureVisible(root,'profile');
      return;
    }
  });

  $('.gls-student-full').each(function(){
    var root=$(this);
    var active=root.find('.gls-mobile-nav-btn.active,.gls-desktop-nav-btn.active').first();
    var target=active.length ? active.data('target') : 'reserve';
    if(GLS_MODES.indexOf(target)<0) target='reserve';
    glsSetMode(root,target);
    root.find('.gls-mobile-nav-btn[data-target="'+target+'"],.gls-desktop-nav-btn[data-target="'+target+'"]').addClass('active');
    if(target==='reserve') root.find('#gls-mobile-book-class').addClass('gls-mobile-active');
    if(target==='bookings') root.find('#gls-mobile-bookings').addClass('gls-mobile-active');
    if(target==='tasks') root.find('#gls-mobile-tasks').addClass('gls-mobile-active');
    if(target==='performance') root.find('#gls-mobile-performance').addClass('gls-mobile-active');
    if(target==='notifications') root.find('#gls-mobile-notifications').addClass('gls-mobile-active');
    if(target==='profile') root.find('#gls-mobile-profile').addClass('gls-mobile-active');
    glsEnsureVisible(root,target);
  });


  function glsUpdateCartBadge(){
    var count=0;
    try{count=parseInt(localStorage.getItem('gtbp_cart_count')||'0',10)||0;}catch(e){}
    $('.gls-cart-badge').each(function(){ if(count>0){$(this).text(count).show();} else {$(this).hide();} });
  }
  glsUpdateCartBadge();
  window.addEventListener('storage', glsUpdateCartBadge);
  document.addEventListener('gtbpCartUpdated', glsUpdateCartBadge);

  $(document).on('click','.gls-mobile-nav-btn[data-target="notifications"],.gls-desktop-nav-btn[data-target="notifications"]',function(){
    $.post(GLS_DATA.ajax,{action:'gls_mark_notifications_read',nonce:GLS_DATA.nonce},function(r){
      if(r.success){$('.gls-mobile-nav-btn[data-target="notifications"] i,.gls-desktop-nav-btn[data-target="notifications"] .gls-nav-badge,.gls-notification-panel .gls-count-badge').fadeOut(120);}
    });
  });

  $(document).on('click','.gls-mark-notifications-read',function(e){
    e.preventDefault();
    $.post(GLS_DATA.ajax,{action:'gls_mark_notifications_read',nonce:GLS_DATA.nonce},function(r){
      if(r.success){$('.gls-nav-badge:not(.gls-cart-badge),.gls-mobile-nav-btn[data-target="notifications"] i,.gls-notification-panel .gls-count-badge').fadeOut(120);}
    });
  });

  $(document).on('submit','.gls-profile-form',function(e){
    e.preventDefault();
    var form=this,fd=new FormData(form);
    fd.append('action','gls_save_profile');fd.append('nonce',GLS_DATA.nonce);
    var out=$(form).find('.gls-profile-result');out.text('در حال ذخیره...');
    $.ajax({url:GLS_DATA.ajax,type:'POST',data:fd,contentType:false,processData:false,success:function(r){out.text(r.success?(r.data||'ذخیره شد'):(r.data||'خطا'));}});
  });

};var glsTries=0;(function glsWait(){if(window.jQuery){window.jQuery(function($){try{glsBoot($);}catch(e){if(window.console)console.error('GLS dashboard init failed',e);}});}else if(glsTries++<400){setTimeout(glsWait,25);}else if(window.console){console.error('GLS dashboard: jQuery never became available');}})();})();
JS;
    }

    public function admin_actions(){
        if(!current_user_can('manage_options')) return;

        if(isset($_POST['gls_ai_generate_quiz'])){
            $id=intval($_POST['session_id']??0);
            if(!isset($_POST['gls_quiz_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['gls_quiz_nonce'])),'gls_generate_quiz_'.$id)){ wp_die('Security check failed'); }
            if($id){
                global $wpdb;
                $snapshot=isset($_POST['lesson_content_snapshot']) ? wp_kses_post(wp_unslash($_POST['lesson_content_snapshot'])) : '';
                if(trim(wp_strip_all_tags($snapshot))!==''){
                    $wpdb->update($this->sessions,['lesson_content'=>$snapshot,'updated_at'=>current_time('mysql')],['id'=>$id]);
                }
                $topic=sanitize_text_field(wp_unslash($_POST['quiz_topic']??''));
                $mc=max(0,intval($_POST['quiz_mc_count']??20));
                $fib=max(0,intval($_POST['quiz_fib_count']??5));
                $res=$this->ai_generate_quiz_for_session($id,$topic,$mc,$fib);
                if(is_wp_error($res)){
                    set_transient('gls_admin_ai_error',$res->get_error_message(),90);
                    wp_redirect(admin_url('admin.php?page=gls_sessions&edit='.$id.'&quiz_error=1'));
                    exit;
                }
                wp_redirect(admin_url('admin.php?page=gls_sessions&edit='.$id.'&quiz_created=1&test_id='.intval($res)));
                exit;
            }
        }

        if(isset($_POST['gls_ai_generate_reading'])){
            check_admin_referer('gls_save_session');
            $id=intval($_POST['session_id']??0);
            if($id){ $this->save_session($_POST); $res=$this->ai_generate_reading_for_session($id); if(is_wp_error($res)){ set_transient('gls_admin_ai_error', $res->get_error_message(), 60); wp_redirect(admin_url('admin.php?page=gls_sessions&edit='.$id.'&ai_error=1')); exit; } }
            wp_redirect(admin_url('admin.php?page=gls_sessions&edit='.$id.'&ai_done=1'));
            exit;
        }

        if(isset($_POST['gls_ai_correct_submission'])){
            check_admin_referer('gls_save_correction');
            $sid=intval($_POST['submission_id']??0);
            if($sid){ $res=$this->ai_correct_submission($sid); if(is_wp_error($res)){ set_transient('gls_admin_ai_error', $res->get_error_message(), 60); wp_redirect(admin_url('admin.php?page=gls_homework&submission='.$sid.'&ai_error=1')); exit; } }
            wp_redirect(admin_url('admin.php?page=gls_homework&submission='.$sid.'&ai_corrected=1'));
            exit;
        }

        if(isset($_POST['gls_save_settings'])){
            check_admin_referer('gls_save_settings');
            $gls_new_settings=[
                'teacher_email'=>sanitize_email($_POST['teacher_email']??get_option('admin_email')),
                'teacher_name'=>sanitize_text_field($_POST['teacher_name']??''),
                'lesson_weight'=>intval($_POST['lesson_weight']??40),
                'test_weight'=>intval($_POST['test_weight']??40),
                'attendance_weight'=>intval($_POST['attendance_weight']??20),
                'auto_publish_sessions'=>!empty($_POST['auto_publish_sessions'])?1:0,
                'ai_enabled'=>1,
                'ai_base_url'=>esc_url_raw($_POST['ai_base_url']??'https://api.gapgpt.app/v1'),
                'ai_api_key'=>$this->sanitize_ai_key_input($_POST['ai_api_key']??''),
                'ai_model_analysis'=>sanitize_text_field($_POST['ai_model_analysis']??'gapgpt-qwen-3.5'),
                'ai_model_reading'=>sanitize_text_field($_POST['ai_model_reading']??'gapgpt-qwen-3.5'),
                'ai_model_correction'=>sanitize_text_field($_POST['ai_model_correction']??'gemini-2.5-flash'),
                'ai_reading_title'=>sanitize_text_field($_POST['ai_reading_title']??'Lesetext zur Wiederholung')
            ];
            update_option(self::OPT_SETTINGS,$gls_new_settings);
            if(!empty($gls_new_settings['ai_api_key'])) $this->maybe_schedule_ai_backfill_1212(true);
            wp_redirect(admin_url('admin.php?page=gls_settings&updated=1'));
            exit;
        }

        if(isset($_POST['gls_save_session'])){
            check_admin_referer('gls_save_session');
            $this->save_session($_POST);
            wp_redirect(admin_url('admin.php?page=gls_sessions&edit='.intval($_POST['session_id']).'&updated=1'));
            exit;
        }

        if(isset($_POST['gls_save_correction'])){
            check_admin_referer('gls_save_correction');
            $this->save_correction($_POST);
            wp_redirect(admin_url('admin.php?page=gls_homework&corrected=1'));
            exit;
        }

        if(isset($_GET['gls_sync']) && $_GET['gls_sync']==='1'){
            check_admin_referer('gls_sync');
            $this->sync_bookings(1000);
            wp_redirect(admin_url('admin.php?page=gls_sessions&synced=1'));
            exit;
        }

        if(isset($_GET['gls_make_session']) && isset($_GET['booking_id'])){
            $booking_id = intval($_GET['booking_id']);
            check_admin_referer('gls_make_session_' . $booking_id);
            $made = $this->create_session_from_booking_id($booking_id);
            $target = isset($_GET['redirect_to']) ? esc_url_raw(wp_unslash($_GET['redirect_to'])) : admin_url('admin.php?page=gtbp_bookings&tab=list');
            wp_redirect(add_query_arg('msg', $made ? 'gls_session_created' : 'gls_session_exists_or_failed', $target));
            exit;
        }
    }

    public function has_session_for_booking($booking_id){
        global $wpdb;
        $booking_id = intval($booking_id);
        if($booking_id <= 0) return false;
        return (bool)$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->sessions} WHERE booking_id=%d LIMIT 1", $booking_id));
    }

    public function create_session_from_booking_id($booking_id){
        global $wpdb;
        $booking_id = intval($booking_id);
        if($booking_id <= 0) return false;
        if($this->has_session_for_booking($booking_id)) return false;
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$this->bookings)) !== $this->bookings) return false;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->bookings} WHERE id=%d", $booking_id));
        if(!$booking) return false;
        $this->upsert_session($booking, 'published');
        return $this->has_session_for_booking($booking_id);
    }

    private function save_session($p){
        global $wpdb;
        $this->tables();
        $id=intval($p['session_id']??0);
        if(!$id) return;

        $old=$this->get_session($id);

        $new_video = esc_url_raw($p['video_url']??'');
        $old_video = $old ? esc_url_raw($old->video_url ?? '') : '';
        $video_source = ($new_video !== '' && $new_video !== $old_video) ? 'manual' : ($new_video === '' ? '' : sanitize_key($old->video_url_source ?? ''));

        // لینک ویدئوی Google Meet / Drive - کاملاً افزودنی
        $new_meet = esc_url_raw($p['meet_video_url'] ?? '');
        $old_meet = $old ? esc_url_raw($old->meet_video_url ?? '') : '';
        $meet_source = ($new_meet !== '' && $new_meet !== $old_meet) ? 'manual' : ($new_meet === '' ? '' : sanitize_key($old->meet_video_url_source ?? ''));

        // لینک ویدئوی روومیت - کاملاً افزودنی
        $new_rmt = esc_url_raw($p['rmt_video_url'] ?? '');
        $old_rmt = $old ? esc_url_raw($old->rmt_video_url ?? '') : '';
        $rmt_source = ($new_rmt !== '' && $new_rmt !== $old_rmt) ? 'manual' : ($new_rmt === '' ? '' : sanitize_key($old->rmt_video_url_source ?? ''));

        $data = [
            'session_status'=>sanitize_key($p['session_status']??'published'),
            'attendance_status'=>sanitize_key($p['attendance_status']??'unset'),
            'test_shortcode'=>wp_kses_post($p['test_shortcode']??''),
            'teacher_level'=>sanitize_text_field($p['teacher_level']??''),
            'ai_reading_title'=>sanitize_text_field($p['ai_reading_title']??''),
            'ai_reading_content'=>wp_kses_post(wp_unslash($p['ai_reading_content']??'')),
            'video_url'=>$new_video,
            'video_url_source'=>$video_source,
            'updated_at'=>current_time('mysql')
        ];
        // اگر ستون‌های Meet/روومیت در دیتابیس موجود است، آن‌ها را هم آپدیت کن
        $meet_cols = $wpdb->get_col("SHOW COLUMNS FROM {$this->sessions}");
        if (in_array('meet_video_url', $meet_cols, true))        $data['meet_video_url']        = $new_meet;
        if (in_array('meet_video_url_source', $meet_cols, true)) $data['meet_video_url_source'] = $meet_source;
        if (in_array('rmt_video_url', $meet_cols, true))         $data['rmt_video_url']         = $new_rmt;
        if (in_array('rmt_video_url_source', $meet_cols, true))  $data['rmt_video_url_source']  = $rmt_source;

        $wpdb->update($this->sessions, $data, ['id'=>$id]);

        $this->notify_if_needed($old,$this->get_session($id));
    }

    private function save_correction($p){
        global $wpdb;
        $sid=intval($p['submission_id']??0);
        $sub=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->subs} WHERE id=%d",$sid));
        if(!$sub)return;

        $session=$this->get_session($sub->session_id);
        $files=array_filter(array_map('intval',explode(',',sanitize_text_field($p['correction_file_ids']??''))));

        $wpdb->update($this->subs,[
            'status'=>sanitize_key($p['status']??'corrected'),
            'grade'=>min(100,max(0,floatval($p['grade']??0))),
            'feedback_text'=>wp_kses_post($p['feedback_text']??''),
            'correction_file_ids'=>wp_json_encode($files),
            'corrected_at'=>current_time('mysql'),
            'updated_at'=>current_time('mysql')
        ],['id'=>$sid]);

        if($session && $session->student_email) {
            $this->mail_log(
                $session,
                'correction_ready',
                $session->student_email,
                'تصحیح تکلیف شما آماده شد',
                "سلام {$session->student_name} عزیز،\n\nتکلیف جلسه {$session->jalali_date} تصحیح شد. لطفاً وارد پنل آموزشی شوید و نتیجه را ببینید.\n\nبا احترام"
            );
        }
    }

    private function mail_log($session,$type,$to,$subject,$message){
        global $wpdb;
        wp_mail($to,$subject,$message);

        $wpdb->insert($this->logs,[
            'session_id'=>$session?intval($session->id):0,
            'user_id'=>$session?intval($session->user_id):0,
            'email'=>sanitize_email($to),
            'type'=>sanitize_key($type),
            'subject'=>sanitize_text_field($subject),
            'message'=>$message,
            'sent_at'=>current_time('mysql')
        ]);

        do_action('gtbp_learning_notification_created', $session, $type, $to, $subject, $message);
    }

    private function notify_if_needed($old,$new){
        if(!$new || !$new->student_email || $new->session_status!=='published')return;

        $sig=[];
        $oc=$old?$this->counts($old->id):['resources'=>0,'exercises'=>0];
        $nc=$this->counts($new->id);

        if(!$old || $old->session_status!=='published')$sig[]='جلسه آموزشی منتشر شد';
        if($nc['resources']>$oc['resources'])$sig[]='منبع جدید اضافه شد';
        if($nc['exercises']>$oc['exercises'])$sig[]='تمرین/تکلیف جدید اضافه شد';
        if(($old?$old->test_shortcode:'')!==$new->test_shortcode && trim($new->test_shortcode)!=='')$sig[]='آزمونک جلسه اضافه شد';
        if(($old?$old->video_url:'')!==$new->video_url && trim($new->video_url)!=='')$sig[]='ویدئوی جلسه آماده شد';

        if(!$sig)return;

        $hash=md5(implode('|',$sig).'|'.$new->updated_at);
        if($hash===$new->last_notified_hash)return;

        $this->mail_log(
            $new,
            'session_update',
            $new->student_email,
            'به‌روزرسانی جلسه آموزشی شما',
            "سلام {$new->student_name} عزیز،\n\nبرای جلسه {$new->jalali_date} این موارد اضافه/به‌روزرسانی شد:\n- ".implode("\n- ",$sig)."\n\nلطفاً وارد پنل آموزشی شوید.\n\nبا احترام"
        );

        global $wpdb;
        $wpdb->update($this->sessions,['last_notified_hash'=>$hash],['id'=>$new->id]);
    }

    private function get_session($id){
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->sessions} WHERE id=%d",intval($id)));
    }

    private function get_items($sid,$type=null){
        global $wpdb;
        return $type
            ? $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->items} WHERE session_id=%d AND item_type=%s ORDER BY sort_order ASC,id ASC",intval($sid),$type))
            : $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->items} WHERE session_id=%d ORDER BY item_type ASC,sort_order ASC,id ASC",intval($sid)));
    }

    private function counts($sid){
        global $wpdb;
        return [
            'resources'=>intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->items} WHERE session_id=%d AND item_type='resource'",intval($sid)))),
            'exercises'=>intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->items} WHERE session_id=%d AND item_type='exercise'",intval($sid))))
        ];
    }

    public function admin_sessions(){ $this->admin_list('active'); }
    public function admin_archive(){ $this->admin_list('archived'); }

    private function admin_list($mode='active'){
        global $wpdb;
        $this->sync_bookings(300);

        if(isset($_GET['edit'])){
            $this->admin_edit(intval($_GET['edit']));
            return;
        }

        $base_where=$mode==='archived' ? "session_status='archived'" : "session_status!='archived'";
        $filter_name=isset($_GET['gls_name']) ? sanitize_text_field(wp_unslash($_GET['gls_name'])) : '';
        $filter_date=isset($_GET['gls_date']) ? sanitize_text_field(wp_unslash($_GET['gls_date'])) : '';
        $where=$base_where;
        $params=[];
        if($filter_name!==''){
            $like='%'.$wpdb->esc_like($filter_name).'%';
            $where.=" AND (student_name LIKE %s OR student_email LIKE %s OR title LIKE %s OR class_name LIKE %s)";
            array_push($params,$like,$like,$like,$like);
        }
        if($filter_date!=='' && preg_match('/^\d{4}-\d{2}-\d{2}$/',$filter_date)){
            $where.=" AND booking_date=%s";
            $params[]=$filter_date;
        }
        $sql="SELECT * FROM {$this->sessions} WHERE $where ORDER BY booking_date DESC,booking_time DESC,id DESC LIMIT 300";
        if($params) $sql=$wpdb->prepare($sql,$params);
        $rows=$wpdb->get_results($sql);
        $page_slug=$mode==='archived'?'gls_archive':'gls_sessions';
        $sync=wp_nonce_url(admin_url('admin.php?page=gls_sessions&gls_sync=1'),'gls_sync');
        $today_url=add_query_arg(['page'=>$page_slug,'gls_date'=>current_time('Y-m-d')],admin_url('admin.php'));

        echo '<div class="wrap gls-wrap gls-admin"><div class="gls-top"><div><h1>جلسات آموزشی</h1><small>همگام با رزروهای تأییدشده</small></div><a class="gls-btn gold" href="'.esc_url($sync).'">همگام‌سازی رزروها</a></div>';
        echo '<form method="get" class="gls-box gls-admin-filters"><input type="hidden" name="page" value="'.esc_attr($page_slug).'"><label>نام زبان‌آموز یا جلسه<input type="search" name="gls_name" value="'.esc_attr($filter_name).'" placeholder="نام، ایمیل یا عنوان جلسه"></label><label>تاریخ میلادی<input type="date" name="gls_date" value="'.esc_attr($filter_date).'"></label><button class="gls-btn" type="submit">فیلتر</button><a class="gls-btn gold" href="'.esc_url($today_url).'">جلسات امروز</a>'.(($filter_name||$filter_date)?'<a class="gls-btn light" href="'.esc_url(admin_url('admin.php?page='.$page_slug)).'">حذف فیلتر</a>':'').'</form>';
        echo '<table class="gls-table gls-session-admin-table"><thead><tr><th>جلسه</th><th>تاریخ و ساعت</th><th>وضعیت</th><th>حضور</th><th>عملیات</th></tr></thead><tbody>';

        foreach($rows as $r){
            echo '<tr><td data-label="جلسه"><strong>'.esc_html($r->title).'</strong><br><small>'.esc_html($r->student_name).' | '.esc_html($r->class_name).' | '.esc_html($r->student_email).'</small></td><td data-label="تاریخ و ساعت">'.esc_html($r->jalali_date).'<br><small>'.esc_html($r->booking_date).' | '.esc_html($r->booking_time).'</small></td><td data-label="وضعیت"><span class="gls-status '.esc_attr($r->session_status).'">'.esc_html($this->status_label($r->session_status)).'</span></td><td data-label="حضور"><span class="gls-status '.esc_attr($r->attendance_status).'">'.esc_html($this->attendance_label($r->attendance_status)).'</span></td><td data-label="عملیات"><a class="gls-btn" href="'.esc_url(admin_url('admin.php?page=gls_sessions&edit='.intval($r->id))).'">مدیریت جلسه</a></td></tr>';
        }
        if(!$rows) echo '<tr><td colspan="5" class="gls-empty">جلسه‌ای مطابق فیلتر پیدا نشد.</td></tr>';

        echo '</tbody></table></div>';
    }

    private function admin_edit($id){
        $s=$this->get_session($id);
        if(!$s){
            echo '<div class="wrap"><p>جلسه پیدا نشد.</p></div>';
            return;
        }

        $resources=$this->get_items($id,'resource');
        $exercises=$this->get_items($id,'exercise');

        echo '<div class="wrap gls-wrap gls-admin gls-admin-edit"><div class="gls-top"><div><h1>'.esc_html($s->title).'</h1><small>'.esc_html($s->jalali_date).' | '.esc_html($s->booking_date).' | '.esc_html($s->booking_time).'</small></div><a class="gls-btn light" href="'.esc_url(admin_url('admin.php?page=gls_sessions')).'">بازگشت</a></div>';

        if(isset($_GET['quiz_created'])){
            echo '<div class="notice notice-success inline"><p><strong>آزمونک ساخته و به جلسه متصل شد.</strong> شناسه آزمون: '.intval($_GET['test_id']??0).'</p></div>';
        }

        echo '<form method="post" class="gls-form gls-box">';
        wp_nonce_field('gls_save_session');

        echo '<input type="hidden" name="session_id" value="'.intval($s->id).'">';
        echo '<div class="gls-admin-row"><div><label>وضعیت انتشار</label><select name="session_status"><option value="draft" '.selected($s->session_status,'draft',false).'>پیش‌نویس</option><option value="published" '.selected($s->session_status,'published',false).'>منتشرشده</option><option value="archived" '.selected($s->session_status,'archived',false).'>آرشیو</option></select></div><div><label>وضعیت حضور</label><select name="attendance_status"><option value="unset" '.selected($s->attendance_status,'unset',false).'>ثبت نشده</option><option value="present" '.selected($s->attendance_status,'present',false).'>حاضر</option><option value="absent" '.selected($s->attendance_status,'absent',false).'>غایب</option></select></div></div>';
        $has_test=trim((string)$s->test_shortcode)!=='';
        echo '<section class="gls-quiz-generator gls-no-print">';
        echo '<input type="hidden" name="gls_quiz_nonce" value="'.esc_attr(wp_create_nonce('gls_generate_quiz_'.intval($s->id))).'"><input type="hidden" name="lesson_content_snapshot" value="">';
        echo '<div class="gls-quiz-generator-head"><div><strong>ساخت آزمونک از محتوای تدریس‌شده</strong><small>جزوه فعلی با دقت کامل بررسی می‌شود و فقط جزوه جلسات قبلیِ حاضرشده برای مرور در نظر گرفته می‌شود.</small></div><span>✦</span></div>';
        echo '<div class="gls-quiz-generator-grid"><label>موضوع آزمونک<input type="text" name="quiz_topic" placeholder="مثلاً سوپرمارکت، بیمه یا همسایه‌ها" '.($has_test?'disabled':'').'></label><label>چهارگزینه‌ای<input type="number" name="quiz_mc_count" min="0" step="1" value="20" '.($has_test?'disabled':'').'></label><label>جای‌خالی<input type="number" name="quiz_fib_count" min="0" step="1" value="5" '.($has_test?'disabled':'').'></label><button class="gls-btn gold" type="submit" name="gls_ai_generate_quiz" value="1" onclick="var e=document.getElementById(\'gls-editor\');if(e){this.form.querySelector(\'[name=lesson_content_snapshot]\').value=e.innerHTML;}" '.($has_test?'disabled':'').'>ساخت آزمونک</button></div>';
        if($has_test) echo '<p class="gls-quiz-generator-lock">برای این جلسه آزمونک ثبت شده است؛ ساخت خودکار غیرفعال است.</p>';
        echo '</section>';
        echo '<label>لینک ویدئوی جلسه BBB</label><input type="url" name="video_url" value="'.esc_attr($s->video_url).'">';
        $meet_video = isset($s->meet_video_url) ? (string)$s->meet_video_url : '';
        echo '<label>لینک ویدئوی جلسه Google Meet / Drive</label><input type="url" name="meet_video_url" value="'.esc_attr($meet_video).'" placeholder="https://drive.google.com/...">';
        $rmt_video = isset($s->rmt_video_url) ? (string)$s->rmt_video_url : '';
        echo '<label>لینک ویدئوی جلسه روومیت</label><input type="url" name="rmt_video_url" value="'.esc_attr($rmt_video).'" placeholder="https://lbvip.roomeet.ir/playback/... یا لینک دانلود مستقیم">';
        echo '<label>آزمونک جلسه؛ ID، شورت‌کد یا کد کامل</label><textarea name="test_shortcode">'.esc_textarea($s->test_shortcode).'</textarea>';
        echo '<label>سطح زبان‌آموز برای تحلیل آموزشی</label><select name="teacher_level"><option value="" '.selected($s->teacher_level,'',false).'>تشخیص خودکار</option><option value="A1" '.selected($s->teacher_level,'A1',false).'>A1</option><option value="A2" '.selected($s->teacher_level,'A2',false).'>A2</option><option value="B1" '.selected($s->teacher_level,'B1',false).'>B1</option><option value="B2" '.selected($s->teacher_level,'B2',false).'>B2</option><option value="C1" '.selected($s->teacher_level,'C1',false).'>C1</option></select>';
        echo '<label>عنوان متن Lesen تکمیلی</label><input type="text" name="ai_reading_title" value="'.esc_attr($s->ai_reading_title ?: $this->settings()['ai_reading_title']).'">';
        echo '<label>متن Lesen تکمیلی تولیدشده/قابل ویرایش</label><textarea name="ai_reading_content" style="min-height:180px">'.esc_textarea($s->ai_reading_content).'</textarea>';
        echo '<p style="display:flex;gap:8px;flex-wrap:wrap"><button class="gls-btn red" name="gls_save_session" value="1">ذخیره تنظیمات جلسه</button><button class="gls-btn gold" name="gls_ai_generate_reading" value="1">تحلیل جزوه و ساخت متن Lesen</button></p></form>';

        $this->lesson_editor($s);

        echo '<div class="gls-admin-row"><div class="gls-box"><h3>منابع جلسه</h3>';
        $this->admin_items($resources);
        $this->item_form($s->id,'resource');
        echo '</div><div class="gls-box"><h3>تمرینات جلسه</h3>';
        $this->admin_items($exercises);
        $this->item_form($s->id,'exercise');
        echo '</div></div></div>';
    }

    private function lesson_editor($s){
        $colors=[
            '#000000','#dd0000','#ffce00','#ffffff','#0057b8',
            '#00a651','#ff6b00','#7b2cbf','#c1121f','#008080',
            '#1d3557','#e63946','#2a9d8f','#f4a261','#264653',
            '#8338ec','#3a86ff','#ff006e','#06d6a0','#ffd166',
            '#f8f9fa','#dee2e6','#adb5bd','#6c757d','#343a40'
        ];

        $bg_colors=[
            '#fef9c3','#dcfce7','#dbeafe','#fce7f3','#ede9fe',
            '#ffedd5','#cffafe','#f0fdf4','#fdf4ff','#e0f2fe',
            '#fef3c7','#e0f2fe','#f1f5f9','#fff7ed','#ecfdf5',
            '#fef2f2','#f5f3ff','#fffbeb','#f0f9ff','#fafafa',
        ];
        $palette=function($cmd) use($colors,$bg_colors){
            $palette_colors=$cmd==='hiliteColor'?$bg_colors:$colors;
            $out='<span class="gls-color-pop" data-cmd="'.esc_attr($cmd).'"><button type="button" class="gls-tool gls-color-trigger" title="'.($cmd==='foreColor'?'رنگ متن':'رنگ پس‌زمینه').'">'.($cmd==='foreColor'?'🎨':'🖍️').'</button><span class="gls-color-palette">';
            foreach($palette_colors as $c){
                $out.='<button type="button" class="gls-color-chip" data-color="'.esc_attr($c).'" style="background:'.esc_attr($c).'"></button>';
            }
            $out.='<span class="gls-custom-color"><input class="gls-custom-color-input" type="text" placeholder="#123456"><button type="button" class="gls-apply-custom-color">اعمال</button></span></span></span>';
            return $out;
        };

        echo '<div class="gls-box gls-editor-wrap"><h3>جزوه جلسه</h3>';
        echo '<div class="gls-toolbar gls-no-print">';
        echo '<span class="gls-toolbar-group"><button type="button" class="gls-tool" data-cmd="bold" title="Bold"><strong>B</strong></button><button type="button" class="gls-tool" data-cmd="italic" title="Italic"><em>I</em></button><button type="button" class="gls-tool gls-tool-underline" data-cmd="underline" title="زیرخط"><u style="text-decoration-line:underline;text-underline-offset:5px">U</u></button><button type="button" class="gls-tool" data-cmd="strikeThrough" title="خط روی نوشته">S̶</button></span>';
        echo '<span class="gls-toolbar-group"><button type="button" class="gls-tool" data-cmd="justifyRight" title="راست‌چین">↦</button><button type="button" class="gls-tool" data-cmd="justifyCenter" title="وسط‌چین">↔</button><button type="button" class="gls-tool" data-cmd="justifyLeft" title="چپ‌چین">↤</button><button type="button" class="gls-tool" data-cmd="insertOrderedList" title="لیست شماره‌دار">1.</button><button type="button" class="gls-tool" data-cmd="insertUnorderedList" title="لیست">•</button><button type="button" class="gls-tool" data-cmd="insertHorizontalRule" title="خط افقی">─</button></span>';
        echo '<span class="gls-toolbar-group"><button type="button" class="gls-tool" data-cmd="glsDir" data-val="rtl" title="جهت راست به چپ">RTL</button><button type="button" class="gls-tool" data-cmd="glsDir" data-val="ltr" title="جهت چپ به راست">LTR</button></span>';
        echo '<span class="gls-toolbar-group"><select class="gls-tool-select gls-size-select" title="سایز متن"><option value="">Aa</option><option value="5">5</option><option value="7">7</option><option value="10">10</option><option value="13">13</option><option value="16">16</option><option value="19">19</option><option value="22">22</option><option value="25">25</option><option value="custom">دستی</option></select>'.$palette('foreColor').$palette('hiliteColor').'</span>';
        echo '<span class="gls-toolbar-group gls-table-tool-wrap"><button type="button" class="gls-tool gls-table-trigger" data-cmd="glsTable" title="درج جدول">▦</button><span class="gls-table-picker" aria-hidden="true"><span class="gls-table-picker-title">اندازه جدول</span><span class="gls-table-grid" data-max="8"></span><small class="gls-table-picker-size">0 × 0</small></span></span>';
        echo '<span class="gls-toolbar-group"><button type="button" class="gls-tool" data-cmd="createLink" title="لینک">🔗</button><button type="button" class="gls-tool gls-insert-media" title="رسانه">🖼️</button><button type="button" class="gls-tool gls-fullscreen-btn" title="تمام‌صفحه">⛶</button><button type="button" class="gls-tool gls-save-lesson" data-session="'.intval($s->id).'" title="ذخیره">💾</button></span>';
        echo '</div>';
        echo '<div id="gls-editor" class="gls-editor" contenteditable="true">'.wp_kses_post($s->lesson_content).'</div><div class="gls-table-context gls-no-print" aria-hidden="true"><strong>تنظیمات جدول</strong><small class="gls-table-ctx-hint">۱) محدوده را انتخاب کنید ۲) رنگ را بزنید</small><div class="gls-table-ctx-scope-btns"><button type="button" data-scope="cell">سلول</button><button type="button" data-scope="row">کل سطر</button><button type="button" data-scope="col">کل ستون</button><button type="button" data-scope="clear">پاک کردن رنگ</button></div><div class="gls-table-context-colors"><i style="background:#fef9c3" data-color="#fef9c3" title="زرد روشن"></i><i style="background:#dcfce7" data-color="#dcfce7" title="سبز روشن"></i><i style="background:#dbeafe" data-color="#dbeafe" title="آبی روشن"></i><i style="background:#fce7f3" data-color="#fce7f3" title="صورتی روشن"></i><i style="background:#ede9fe" data-color="#ede9fe" title="بنفش روشن"></i><i style="background:#ffedd5" data-color="#ffedd5" title="نارنجی روشن"></i><i style="background:#f1f5f9" data-color="#f1f5f9" title="خاکستری روشن"></i><i style="background:#fff" data-color="#fff" title="سفید" style="border-color:#d1d5db"></i><i style="background:#fef3c7" data-color="#fef3c7" title="کرم"></i><i style="background:#e0f2fe" data-color="#e0f2fe" title="آسمانی روشن"></i><i style="background:#f0fdf4" data-color="#f0fdf4" title="سبز خیلی روشن"></i><i style="background:#fdf4ff" data-color="#fdf4ff" title="لیلاکی روشن"></i></div></div></div>';
    }

    private function admin_items($items){
        if(!$items){
            echo '<div class="gls-empty">هنوز چیزی ثبت نشده است.</div>';
            return;
        }

        echo '<div class="gls-sortable">';
        foreach($items as $it){
            echo '<div class="gls-item" data-id="'.intval($it->id).'">';
            echo '<h4>'.esc_html($it->title).'</h4><small>'.esc_html($this->kind_label($it->item_kind)).'</small>';
            echo '<p>'.wp_kses_post(wp_trim_words($it->content,18)).'</p>';
            if($it->url) echo '<a target="_blank" href="'.esc_url($it->url).'">مشاهده</a> ';
            echo '<button type="button" class="gls-btn light gls-delete-item" data-id="'.intval($it->id).'">حذف</button>';
            echo '</div>';
        }
        echo '</div>';
    }

    private function item_form($sid,$type){
        $id='gls_attach_'.$type;

        echo '<form class="gls-form gls-ajax-item-form">';
        echo '<input type="hidden" name="session_id" value="'.intval($sid).'">';
        echo '<input type="hidden" name="item_type" value="'.esc_attr($type).'">';
        echo '<label>عنوان</label><input type="text" name="title" required>';
        echo '<label>نوع</label><select name="item_kind"><option value="file">فایل/PDF</option><option value="image">عکس</option><option value="link">لینک</option><option value="book">کتاب</option><option value="text">متن</option></select>';
        echo '<label>لینک خارجی</label><input type="url" name="url">';
        echo '<label>فایل از رسانه وردپرس</label>';
        echo '<input type="hidden" id="'.$id.'" name="attachment_id">';
        echo '<input type="hidden" id="'.$id.'_url" name="attachment_url">';
        echo '<button type="button" class="gls-btn light gls-media-btn" data-target="'.$id.'">انتخاب فایل</button>';
        echo '<div id="'.$id.'_preview"></div>';
        echo '<label>متن/توضیح</label><textarea name="content"></textarea>';
        echo '<p><button class="gls-btn red">افزودن</button></p></form>';
    }

    public function admin_homework(){
        global $wpdb;

        if(isset($_GET['submission'])){
            $this->correction_page(intval($_GET['submission']));
            return;
        }

        $rows=$wpdb->get_results("SELECT sub.*,s.title,s.student_name,s.jalali_date
                                  FROM {$this->subs} sub
                                  LEFT JOIN {$this->sessions} s ON s.id=sub.session_id
                                  ORDER BY sub.submitted_at DESC
                                  LIMIT 300");

        echo '<div class="wrap gls-wrap gls-admin"><div class="gls-top"><h1>تکالیف آموزشی</h1><small>جدیدترین ارسال‌ها در بالا؛ وضعیت‌ها برای بررسی سریع‌تر واضح‌تر شده‌اند.</small></div>';
        echo '<style>.gls-admin-homework-source{display:inline-flex;border-radius:999px;padding:3px 8px;background:#eef2ff;color:#3730a3;font-size:11px;margin-top:6px}.gls-admin-homework-status{display:flex;gap:6px;align-items:center;flex-wrap:wrap}</style>';
        echo '<table class="gls-table"><thead><tr><th>جلسه و زبان‌آموز</th><th>ارسال</th><th>وضعیت بررسی</th><th>نمره</th><th>عملیات</th></tr></thead><tbody>';

        foreach($rows as $r){
            $source = (strpos((string)$r->text_content, '[ارسال از تلگرام]') !== false) ? 'ارسال از تلگرام' : 'ارسال از سایت';
            $priority = ($r->status==='submitted') ? 'جدید / در انتظار تصحیح' : (($r->status==='reviewing') ? 'در حال بررسی' : $this->submission_label($r->status));
            echo '<tr><td><strong>'.esc_html($r->title).'</strong><br><small>'.esc_html($r->student_name).' | '.esc_html($r->jalali_date).'</small><br><span class="gls-admin-homework-source">'.esc_html($source).'</span></td><td>'.esc_html($r->submitted_at).'<br><small>تلاش '.intval($r->attempt_no).'</small></td><td><span class="gls-status '.esc_attr($r->status).'">'.esc_html($priority).'</span></td><td>'.esc_html($r->grade!==null?$r->grade.'/100':'-').'</td><td><a class="gls-btn" href="'.esc_url(admin_url('admin.php?page=gls_homework&submission='.intval($r->id))).'">بررسی / تصحیح</a></td></tr>';
        }

        echo '</tbody></table></div>';
    }

    private function correction_page($id){
        global $wpdb;

        $sub=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->subs} WHERE id=%d",$id));
        if(!$sub){
            echo '<div class="wrap">پیدا نشد.</div>';
            return;
        }

        $s=$this->get_session($sub->session_id);

        echo '<div class="wrap gls-wrap gls-admin"><div class="gls-top"><h1>تصحیح تکلیف</h1><a class="gls-btn light" href="'.esc_url(admin_url('admin.php?page=gls_homework')).'">بازگشت</a></div>';
        echo '<div class="gls-box"><h3>'.esc_html($s?$s->title:'').'</h3><div>'.wpautop(wp_kses_post($sub->text_content)).'</div>';
        $this->file_links($sub->file_ids);
        echo '</div>';

        echo '<form method="post" class="gls-form gls-box">';
        wp_nonce_field('gls_save_correction');
        echo '<input type="hidden" name="submission_id" value="'.intval($sub->id).'">';
        echo '<label>وضعیت</label><select name="status"><option value="reviewing" '.selected($sub->status,'reviewing',false).'>در حال بررسی</option><option value="corrected" '.selected($sub->status,'corrected',false).'>تصحیح شد</option><option value="resubmit" '.selected($sub->status,'resubmit',false).'>نیاز به ارسال مجدد</option></select>';
        echo '<label>نمره از ۱۰۰</label><input type="number" name="grade" min="0" max="100" value="'.esc_attr($sub->grade).'">';
        echo '<label>بازخورد استاد</label><textarea name="feedback_text">'.esc_textarea($sub->feedback_text).'</textarea>';
        echo '<label>ID فایل‌های تصحیح، با کاما جدا کن</label><input type="text" name="correction_file_ids" value="'.esc_attr(implode(',',(array)json_decode($sub->correction_file_ids,true))).'">';
        echo '<p style="display:flex;gap:8px;flex-wrap:wrap"><button name="gls_ai_correct_submission" value="1" class="gls-btn gold">ساخت پیش‌نویس تصحیح</button><button name="gls_save_correction" value="1" class="gls-btn red">ثبت تصحیح</button></p></form></div>';
    }

    public function admin_settings(){
        $s=$this->settings();

        echo '<div class="wrap gls-wrap gls-admin"><div class="gls-top"><h1>تنظیمات جلسات آموزشی</h1></div>';
        echo '<form method="post" class="gls-form gls-box">';
        wp_nonce_field('gls_save_settings');

        echo '<label>ایمیل استاد</label><input type="text" name="teacher_email" value="'.esc_attr($s['teacher_email']).'">';
        echo '<label>نام استاد</label><input type="text" name="teacher_name" value="'.esc_attr($s['teacher_name']).'">';
        echo '<div class="gls-admin-row"><div><label>وزن تکلیف</label><input type="number" name="lesson_weight" value="'.intval($s['lesson_weight']).'"></div><div><label>وزن آزمونک</label><input type="number" name="test_weight" value="'.intval($s['test_weight']).'"></div></div>';
        echo '<label>وزن حضور</label><input type="number" name="attendance_weight" value="'.intval($s['attendance_weight']).'">';
        echo '<label><input type="checkbox" name="auto_publish_sessions" value="1" '.checked($s['auto_publish_sessions'],1,false).'> انتشار خودکار جلسه بعد از تایید رزرو</label>';
        echo '<hr><h3>تنظیمات دستیار آموزشی</h3>';
        echo '<label><input type="checkbox" name="ai_enabled" value="1" checked disabled> دستیار آموزشی با وجود API Key فعال است</label>';
        echo '<label>Base URL</label><input type="text" name="ai_base_url" value="'.esc_attr($s['ai_base_url']).'">';
        echo '<label>API Key</label><input type="password" name="ai_api_key" value="" placeholder="برای حفظ کلید فعلی خالی بگذارید؛ کلید فعلی: '.$this->mask_ai_key($s['ai_api_key']).'">';
        echo '<div class="gls-admin-row"><div><label>مدل تحلیل جزوه</label><select name="ai_model_analysis">'.$this->ai_model_options($s['ai_model_analysis']).'</select></div><div><label>مدل تولید Lesen</label><select name="ai_model_reading">'.$this->ai_model_options($s['ai_model_reading']).'</select></div></div>';
        echo '<label>مدل تصحیح تکلیف و تصویر</label><select name="ai_model_correction">'.$this->ai_model_options($s['ai_model_correction']).'</select>';
        echo '<label>عنوان پیش‌فرض متن Lesen</label><input type="text" name="ai_reading_title" value="'.esc_attr($s['ai_reading_title']).'">';
        echo '<p><button name="gls_save_settings" value="1" class="gls-btn red">ذخیره</button></p></form></div>';
    }

    private function session_has_content($s){
        if(!$s) return false;
        if(trim(wp_strip_all_tags((string)$s->lesson_content)) !== '') return true;
        if(trim(wp_strip_all_tags((string)($s->ai_reading_content ?? ''))) !== '') return true;
        if(trim((string)$s->video_url) !== '') return true;
        if(isset($s->meet_video_url) && trim((string)$s->meet_video_url) !== '') return true;
        if(isset($s->rmt_video_url) && trim((string)$s->rmt_video_url) !== '') return true;
        if(trim((string)$s->test_shortcode) !== '') return true;
        $c=$this->counts(intval($s->id));
        return ($c['resources'] + $c['exercises']) > 0;
    }

    private function user_bookings($uid=0,$limit=12){
        if(!$uid) $uid=get_current_user_id();
        $user=get_userdata($uid);
        if(!$user) return [];
        global $wpdb;
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$this->bookings)) !== $this->bookings) return [];
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->bookings}
             WHERE email=%s AND status IN ('confirmed','pending_card')
             ORDER BY booking_date DESC, booking_time DESC
             LIMIT %d",
            $user->user_email,
            intval($limit)
        ));
    }

    private function upcoming_bookings($uid=0,$limit=10){
        $all=$this->user_bookings($uid,500);
        $now=$this->iran_now_ts();
        $out=[];
        foreach((array)$all as $b){
            $start=$this->booking_start_ts($b);
            if($start && $start >= $now) $out[]=$b;
        }
        usort($out, function($a, $b){
            $ak=$this->booking_start_ts($a);
            $bk=$this->booking_start_ts($b);
            if($ak===$bk) return intval($a->id ?? 0) <=> intval($b->id ?? 0);
            return $ak <=> $bk;
        });
        $limit=intval($limit);
        return $limit>0 ? array_slice($out,0,$limit) : $out;
    }

    private function iran_now_ts(){
        try {
            $tz = new DateTimeZone('Asia/Tehran');
            return (new DateTime('now', $tz))->getTimestamp();
        } catch (Exception $e) {
            return time();
        }
    }

    private function booking_start_ts($b){
        if(!$b || empty($b->booking_date)) return 0;
        $time='00:00';
        if(!empty($b->booking_time) && preg_match('/(\d{1,2}):(\d{2})/', $b->booking_time, $m)){
            $time=sprintf('%02d:%02d', intval($m[1]), intval($m[2]));
        }
        try {
            $tz = new DateTimeZone('Asia/Tehran');
            return (new DateTime($b->booking_date.' '.$time, $tz))->getTimestamp();
        } catch (Exception $e) {
            return strtotime($b->booking_date.' '.$time);
        }
    }

    // نسخه ۱۴.۵: زمان پایان کلاس (بخش دوم booking_time مثل «13:00-14:00») به وقت رسمی ایران
    private function booking_end_ts($b){
        if(!$b || empty($b->booking_date) || empty($b->booking_time)) return 0;
        $parts = explode('-', (string)$b->booking_time);
        if(count($parts) < 2) return 0;
        if(!preg_match('/(\d{1,2}):(\d{2})/', trim($parts[1]), $m)) return 0;
        $time = sprintf('%02d:%02d', intval($m[1]), intval($m[2]));
        try {
            $tz = new DateTimeZone('Asia/Tehran');
            return (new DateTime($b->booking_date.' '.$time, $tz))->getTimestamp();
        } catch (Exception $e) {
            return strtotime($b->booking_date.' '.$time);
        }
    }

    private function can_show_join_link($b){
        if(!$b || empty($b->roomeet_join_link) || ($b->status ?? '') !== 'confirmed') return false;

        $start = $this->booking_start_ts($b);
        if(!$start) return false;

        // نسخه ۱۴.۵: پنجره‌ی نمایش لینک = از ۳ دقیقه قبل از شروع تا پایان کلاس (به وقت رسمی ایران).
        // مقایسه با timestamp مطلق است، پس برای کاربر خارج از ایران هم درست کار می‌کند.
        $end = $this->booking_end_ts($b);
        if($end <= $start) $end = $start + HOUR_IN_SECONDS; // اگر زمان پایان قابل استخراج نبود، پیش‌فرض ۱ ساعت
        $now = $this->iran_now_ts();

        return ($now >= ($start - 3 * MINUTE_IN_SECONDS) && $now <= $end);
    }
private function booking_has_started($b){
        if(!$b) return false;
        $start=$this->booking_start_ts($b);
        if(!$start) return false;
        return $this->iran_now_ts() > $start;
    }

    private function can_show_student_day_link($b){
        return $this->can_show_join_link($b);
    }

    // Fix #15: helper to generate ICS data URI for calendar download
    private function make_ics_data_uri($booking){
        if(!$booking || empty($booking->booking_date)) return '';
        $time_raw=preg_replace('/[^0-9:]/', '', $booking->booking_time ?? '00:00');
        if(!preg_match('/^\d{1,2}:\d{2}$/', $time_raw)) $time_raw='00:00';
        list($h,$m)=explode(':',$time_raw);
        try {
            $tz=new DateTimeZone('Asia/Tehran');
            $dt_start=new DateTime($booking->booking_date.' '.sprintf('%02d',$h).':'.$m.':00', $tz);
            $dt_end=clone $dt_start;
            $dt_end->modify('+1 hour');
            $dt_start->setTimezone(new DateTimeZone('UTC'));
            $dt_end->setTimezone(new DateTimeZone('UTC'));
            $dts=$dt_start->format('Ymd\THis\Z');
            $dte=$dt_end->format('Ymd\THis\Z');
        } catch(Exception $e){ return ''; }
        $summary='German Class: '.($booking->class_name ?? '');
        $uid='gtbp-'.intval($booking->id).'@'.parse_url(get_home_url(), PHP_URL_HOST);
        $ics="BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//GermanTeacherBooking//EN\r\nBEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:{$dts}\r\nDTSTART:{$dts}\r\nDTEND:{$dte}\r\nSUMMARY:{$summary}\r\nEND:VEVENT\r\nEND:VCALENDAR";
        return 'data:text/calendar;charset=utf-8,'.rawurlencode($ics);
    }

    private function student_class_link_button($booking, $context='booking'){
        if($context==='session' && $this->booking_has_started($booking)) return '';
        $label='🎓 ورود به جلسه';
        $extra='';
        // Fix #15: Copy link button
        if($booking && !empty($booking->roomeet_join_link)){
            $extra.='<button type="button" class="gls-copy-link-btn" onclick="(function(btn,url){if(!navigator.clipboard)return;navigator.clipboard.writeText(url).then(function(){btn.innerHTML=\'✅ کپی شد\';setTimeout(function(){btn.innerHTML=\'🔗 کپی لینک\';},1500)});})(this,\''.esc_js($booking->roomeet_join_link).'\')">🔗 کپی لینک</button>';
        }
        // Fix #15: ICS calendar download
        $ics_uri=$this->make_ics_data_uri($booking);
        if($ics_uri){
            $extra.='<a class="gls-ics-btn" href="'.esc_attr($ics_uri).'" download="class.ics" title="افزودن به تقویم">📆 تقویم</a>';
        }
        if($booking && $this->can_show_join_link($booking)){
            return $extra.'<a class="gls-class-link-btn gls-class-link-active" target="_blank" rel="noopener" href="'.esc_url($booking->roomeet_join_link).'">'.$label.'</a>';
        }
        return $extra.'<span class="gls-class-link-btn gls-class-link-disabled" aria-disabled="true">'.$label.'</span>';
    }

    private function booking_for_session_row($session){
        if(!$session || empty($session->booking_id)) return null;
        global $wpdb;
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$this->bookings)) !== $this->bookings) return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->bookings} WHERE id=%d LIMIT 1", intval($session->booking_id)));
    }

    private function has_real_performance($uid){
        global $wpdb;
        $user=get_userdata($uid);
        if(!$user) return false;
        $graded=intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->subs} WHERE user_id=%d AND grade IS NOT NULL",
            $uid
        )));
        if($graded>0) return true;
        $marked=intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->sessions} WHERE (user_id=%d OR student_email=%s) AND attendance_status IN ('present','absent')",
            $uid,
            $user->user_email
        )));
        if($marked>0) return true;
        $table=$wpdb->prefix.'otg_results';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table){
            $tests=intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $table WHERE user_id=%d",$uid)));
            if($tests>0) return true;
        }
        return false;
    }

    private function booking_cadence_data($uid){
        $bookings=$this->user_bookings($uid,500);
        $confirmed=[];
        foreach((array)$bookings as $b){
            if(($b->status ?? '')==='confirmed' && !empty($b->booking_date)) $confirmed[]=$b;
        }
        if(count($confirmed)<2){
            return [
                'count'=>count($confirmed),
                'days'=>0,
                'avg'=>0,
                'first'=>'',
                'last'=>'',
                'label'=>'بعد از ثبت چند رزرو، ریتم کلاس‌ها اینجا نمایش داده می‌شود.',
                'percent'=>0
            ];
        }
        usort($confirmed,function($a,$b){
            return strcmp((string)$a->booking_date.' '.(string)$a->booking_time,(string)$b->booking_date.' '.(string)$b->booking_time);
        });
        $first=reset($confirmed);
        $last=end($confirmed);
        $first_ts=strtotime($first->booking_date.' 00:00:00');
        $last_ts=strtotime($last->booking_date.' 00:00:00');
        $days=max(0, intval(floor(($last_ts-$first_ts)/DAY_IN_SECONDS)));
        $avg=count($confirmed)>1 ? round($days/(count($confirmed)-1),1) : 0;
        if($avg<=0){
            $label='چند رزرو شما در یک بازه خیلی نزدیک ثبت شده‌اند.';
        } elseif($avg<7){
            $label='خیلی منظم پیش می‌روی؛ تقریباً هر '.$this->fa_num($avg).' روز یک کلاس رزرو کرده‌ای.';
        } elseif($avg<15){
            $label='ریتم خوبی داری؛ به طور میانگین هر '.$this->fa_num($avg).' روز یک رزرو انجام داده‌ای.';
        } else {
            $label='با فاصله‌های آرام‌تر جلو می‌روی؛ میانگین رزروها هر '.$this->fa_num($avg).' روز است.';
        }
        return [
            'count'=>count($confirmed),
            'days'=>$days,
            'avg'=>$avg,
            'first'=>$this->g2j_string($first->booking_date),
            'last'=>$this->g2j_string($last->booking_date),
            'label'=>$label,
            'percent'=>min(100,max(8,round(100/(max(1,$avg)/7))))
        ];
    }

    private function booking_cadence_box($uid){
        $d=$this->booking_cadence_data($uid);
        $avg=$d['avg'] ? $this->fa_num($d['avg']).' روز' : '-';
        $html='<div class="gls-cadence-card"><div class="gls-cadence-ring" style="--cadence:'.esc_attr($d['percent']).'"><span>'.$avg.'</span></div>';
        $html.='<div class="gls-cadence-text"><strong>ریتم رزرو کلاس</strong><p>'.esc_html($d['label']).'</p>';
        if($d['count']>=2){
            $html.='<div class="gls-cadence-meta"><span>از '.esc_html($d['first']).'</span><span>تا '.esc_html($d['last']).'</span><span>'.esc_html($this->fa_num($d['count'])).' رزرو قطعی</span></div>';
        }
        $html.='</div></div>';
        return $html;
    }


    private function ai_taught_points_for_user($uid){
        global $wpdb;
        $user=get_userdata($uid);
        if(!$user) return [];
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$this->ai_profiles)) !== $this->ai_profiles) return [];
        $profile=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->ai_profiles} WHERE user_id=%d OR student_email=%s ORDER BY updated_at DESC LIMIT 1",intval($uid),$user->user_email));
        if(!$profile) return [];
        $points=json_decode($profile->taught_points,true);
        if(!is_array($points)) return [];
        $clean=[];
        foreach($points as $p){
            $p=trim(wp_strip_all_tags((string)$p));
            if($p==='') continue;
            if(preg_match('/[\x{0600}-\x{06FF}]/u',$p)) continue;
            $p=preg_replace('/\s+/u',' ',$p);
            $words=preg_split('/\s+/u',$p,-1,PREG_SPLIT_NO_EMPTY);
            if(count($words)>7) $p=implode(' ',array_slice($words,0,7)).'…';
            if($p!=='') $clean[]=$p;
        }
        return array_values(array_unique($clean));
    }

    private function taught_points_box($uid){
        $points=$this->ai_taught_points_for_user($uid);
        $html='<div class="gls-taught-box"><div class="gls-card-head"><div><h3>چیزهایی که تا الان یاد گرفته‌ای</h3><small>بر اساس تحلیل جزوه‌های ثبت‌شده توسط استاد</small></div><span class="gls-count-badge">'.count($points).'</span></div>';
        if(!$points){
            $html.='<div class="gls-empty">بعد از اینکه استاد جزوه‌ها را تحلیل کند، خلاصه مطالبی که یاد گرفته‌ای اینجا مرحله‌به‌مرحله نمایش داده می‌شود.</div></div>';
            return $html;
        }
        $html.='<ul class="gls-taught-list">';
        foreach(array_slice($points,-40) as $p){
            $html.='<li><span>✓</span><b>'.esc_html($p).'</b></li>';
        }
        $html.='</ul></div>';
        return $html;
    }

    private function homework_badge_count($uid){
        global $wpdb;
        $sessions=$this->user_sessions($uid);
        if(!$sessions) return 0;
        $count=0;
        foreach($sessions as $s){
            $count += intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$this->subs} WHERE session_id=%d AND user_id=%d AND status IN ('corrected','reviewing','resubmit')",intval($s->id),intval($uid))));
        }
        return $count;
    }

    private function homework_overview_panel($uid){
        global $wpdb;
        $sessions=$this->user_sessions($uid);
        $badge=$this->homework_badge_count($uid);
        $html='<div id="gls-mobile-tasks" class="gls-dashboard-card gls-tasks-panel"><div class="gls-card-head"><div><h3>ارسال تکلیف</h3><small>ارسال‌ها، وضعیت بررسی و تصحیح‌های شما</small></div>'.($badge?'<span class="gls-count-badge">'.intval($badge).'</span>':'').'</div>';

        if(!$sessions){
            $html.='<div class="gls-empty">هنوز جلسه‌ای برای ارسال تکلیف وجود ندارد. بعد از رزرو و تشکیل جلسه، این بخش فعال می‌شود.</div></div>';
            return $html;
        }

        $default_session=intval($sessions[0]->id);
        $html.='<div class="gls-task-submit-card"><h4>ارسال تکلیف جدید</h4><form class="gls-form gls-homework-form gls-task-main-form" enctype="multipart/form-data"><label>جلسه مرتبط</label><select name="session_id">';
        foreach($sessions as $s){
            $html.='<option value="'.intval($s->id).'" '.selected(intval($s->id),$default_session,false).'>'.esc_html($s->jalali_date.' | '.$s->booking_time.' | '.$s->class_name).'</option>';
        }
        $html.='</select><label>متن جواب</label><textarea name="text_content" placeholder="متن تکلیف یا توضیح خود را اینجا بنویسید..."></textarea><div class="gls-upload-zone"><input type="file" name="homework_files[]" multiple accept="image/*,.pdf,.doc,.docx"><small>می‌توانید عکس، PDF یا فایل ورد اضافه کنید.</small></div><p><button type="submit" class="gls-btn red">ارسال تکلیف</button></p></form></div>';

        $html.='<div class="gls-task-sections">';
        $sent_any=false;
        $sent_html='';
        foreach($sessions as $s){
            $subs=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->subs} WHERE session_id=%d AND user_id=%d ORDER BY submitted_at DESC,id DESC",intval($s->id),intval($uid)));
            if(!$subs) continue;
            $sent_any=true;
            $sent_html.='<article class="gls-task-row"><button type="button" class="gls-task-toggle"><span class="gls-task-row-head"><strong>'.esc_html($s->jalali_date.' - '.$s->class_name).'</strong><small>'.esc_html($s->booking_time.' | '.$s->title).'</small></span><span class="gls-status submitted">'.count($subs).' ارسال</span></button><div class="gls-task-body"><div class="gls-task-sub-list">';
            foreach($subs as $sub){
                $grade=($sub->grade!==null && $sub->grade!=='') ? $sub->grade.'/100' : 'بدون نمره';
                $sent_html.='<button type="button" class="gls-task-sub-toggle" data-sub="'.intval($sub->id).'"><b>'.esc_html($this->submission_label($sub->status)).'</b><small>'.esc_html($sub->submitted_at).' | '.esc_html($grade).'</small></button>';
                $sent_html.='<div class="gls-task-detail-box gls-hidden" data-sub-detail="'.intval($sub->id).'"><h4>متن ارسال‌شده</h4><div>'.wpautop(wp_kses_post($sub->text_content)).'</div>';
                ob_start(); $this->file_links($sub->file_ids); $files_html=ob_get_clean();
                $sent_html.=$files_html;
                if($sub->feedback_text || $sub->grade!==null || $sub->correction_file_ids){
                    $sent_html.='<div class="gls-correction-card"><div class="gls-correction-head"><strong>تصحیح استاد</strong><span>'.esc_html($grade).'</span></div><div class="gls-feedback-view">'.wpautop(wp_kses_post($sub->feedback_text)).'</div>';
                    ob_start(); $this->file_links($sub->correction_file_ids); $corr_files=ob_get_clean();
                    $sent_html.=$corr_files.'</div>';
                }
                $sent_html.='</div>';
            }
            $sent_html.='</div></div></article>';
        }
        $html.='<section class="gls-task-section"><div class="gls-card-head compact"><div><h3>ارسال‌ها و تصحیح‌ها</h3><small>ارسال‌ها و تصحیح‌های ثبت‌شده</small></div></div>';
        $html.= $sent_any ? '<div class="gls-task-list">'.$sent_html.'</div>' : '<div class="gls-empty slim">هنوز تکلیفی ارسال نکرده‌اید.</div>';
        $html.='</section>';

        $exercise_any=false;
        $exercise_html='';
        foreach($sessions as $s){
            $ex=$this->get_items($s->id,'exercise');
            if(!$ex) continue;
            $exercise_any=true;
            $exercise_html.='<article class="gls-task-row gls-task-exercise-row"><button type="button" class="gls-task-toggle"><span class="gls-task-row-head"><strong>'.esc_html($s->jalali_date.' - '.$s->class_name).'</strong><small>'.count($ex).' تمرین ثبت‌شده</small></span><span class="gls-status draft">تمرین‌ها</span></button><div class="gls-task-body">';
            foreach($ex as $item){
                $url=$item->url ?: ($item->attachment_id?wp_get_attachment_url($item->attachment_id):'');
                $exercise_html.='<div class="gls-task-detail-box"><h4>'.esc_html($item->title).'</h4>';
                if(trim((string)$item->content)!=='') $exercise_html.='<div>'.wpautop(wp_kses_post($item->content)).'</div>';
                if($url) $exercise_html.='<a class="gls-file-chip" target="_blank" rel="noopener" href="'.esc_url($url).'">باز کردن تمرین</a>';
                $exercise_html.='</div>';
            }
            $exercise_html.='</div></article>';
        }
        $html.='<section class="gls-task-section"><div class="gls-card-head compact"><div><h3>تمرین‌های داده‌شده</h3><small>لیست تمرین‌های ثبت‌شده برای جلسه‌ها</small></div></div>';
        $html.= $exercise_any ? '<div class="gls-task-list">'.$exercise_html.'</div>' : '<div class="gls-empty slim">تمرینی برای جلسات شما ثبت نشده است.</div>';
        $html.='</section></div></div>';
        return $html;
    }

    private function delete_submission_files_after_ai($sub){
        if(!$sub || empty($sub->file_ids)) return;
        $ids=json_decode($sub->file_ids,true);
        if(!is_array($ids) || !$ids) return;
        foreach($ids as $id){
            $id=intval($id);
            if($id>0) wp_delete_attachment($id,true);
        }
    }

    private function performance_box($uid){
        $cadence=$this->booking_cadence_box($uid);
        if(!$this->has_real_performance($uid)) {
            return '<div id="gls-mobile-performance" class="gls-dashboard-card gls-performance-panel"><div class="gls-card-head"><div><h3>عملکرد آموزشی</h3><small>بعد از شروع فعالیت آموزشی فعال می‌شود.</small></div></div>'.$cadence.'<div class="gls-empty">بعد از برگزاری جلسه، ارسال تکلیف یا انجام آزمونک، جزئیات عملکرد آموزشی اینجا نمایش داده می‌شود.</div>'.$this->taught_points_box($uid).'</div>';
        }
        $rank=$this->rank($uid);
        $score=max(0,min(100,intval($rank['score'])));
        $homework=$this->homework_average($uid);
        $attendance=$this->attendance_average($uid);
        $user_sessions=$this->user_sessions($uid);
        $has_assigned_tests=$this->has_assigned_tests($user_sessions);
        $tests=$this->test_score($uid,$user_sessions);
        $settings=$this->settings();
        $lw=intval($settings['lesson_weight']??40); $tw=intval($settings['test_weight']??40); $aw=intval($settings['attendance_weight']??20);
        $html='<div id="gls-mobile-performance" class="gls-dashboard-card gls-performance-panel"><div class="gls-card-head"><div><h3>عملکرد آموزشی</h3><small>خلاصه وضعیت یادگیری و ریتم رزرو</small></div></div><div class="gls-performance-card">';
        // Fix #12: score ring with breakdown tooltip
        $tooltip_text="تکلیف: {$lw}٪ | آزمونک: {$tw}٪ | حضور: {$aw}٪";
        $html.='<div class="gls-ring-wrap"><div class="gls-ring" style="--score:'.$score.'" title="'.esc_attr($tooltip_text).'"><span>'.$score.'%</span></div><div class="gls-ring-label">'.esc_html($rank['label']).'</div></div>';
        $html.='<div><div class="gls-perf-bars">';
        $html.=$this->perf_row('تکلیف',$homework);
        if($has_assigned_tests) $html.=$this->perf_row('آزمونک',$tests);
        $html.=$this->perf_row('حضور',$attendance);
        $html.='</div></div></div>'.$cadence.$this->taught_points_box($uid).'</div>';
        return $html;
    }

    private function ai_latest_profile_for_user($uid){
        global $wpdb;
        $user=get_userdata($uid);
        if(!$user || empty($this->ai_profiles)) return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->ai_profiles} WHERE user_id=%d OR student_email=%s ORDER BY updated_at DESC LIMIT 1",intval($uid),$user->user_email));
    }

    private function perf_row($label,$val){
        $v=max(0,min(100,round(floatval($val))));
        return '<div class="gls-perf-row"><span>'.esc_html($label).'</span><span class="gls-bar"><i style="width:'.$v.'%"></i></span><b>'.$v.'%</b></div>';
    }

    private function user_sessions($uid){
        global $wpdb;
        $user=get_userdata($uid);
        if(!$user)return [];
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->sessions} WHERE (user_id=%d OR student_email=%s) AND session_status='published'",
            $uid,
            $user->user_email
        ));
    }

    private function homework_average($uid){
        global $wpdb;
        $avg=$wpdb->get_var($wpdb->prepare("SELECT AVG(grade) FROM {$this->subs} WHERE user_id=%d AND grade IS NOT NULL",$uid));
        return $avg===null?0:floatval($avg);
    }

    private function attendance_average($uid){
        $sessions=$this->user_sessions($uid);
        $present=0;$marked=0;
        foreach($sessions as $s){
            if($s->attendance_status==='present'){ $present++; $marked++; }
            elseif($s->attendance_status==='absent'){ $marked++; }
        }
        return $marked?($present/$marked*100):0;
    }

    private function booking_board($uid){
        $bookings=$this->upcoming_bookings($uid,0);
        // تب «کلاس‌های آینده» باید همیشه یک پنل قابل نمایش داشته باشد. اگر این متد
        // خالی برمی‌گشت، کل تب سفید و ظاهراً خراب دیده می‌شد.
        if(!$bookings){
            return '<div id="gls-mobile-bookings" class="gls-dashboard-card gls-bookings-panel"><div class="gls-card-head"><div><h3>رزروهای آینده</h3><small>لینک ورود طبق زمان‌بندی کلاس فعال می‌شود.</small></div></div><div class="gls-bookings-empty gls-panel-empty">در حال حاضر کلاس رزروشده‌ای در پیش رو ندارید. از تب «رزرو کلاس» می‌توانید کلاس جدید ثبت کنید.</div></div>';
        }

        // Fix #9: "Next class" hero card for first upcoming booking
        $next=$bookings[0];
        $next_jalali=$this->g2j_string($next->booking_date);
        $next_status=($next->status ?? '')==='confirmed';
        $hero='<div class="gls-next-class-hero">';
        $hero.='<div class="gls-next-class-label">🎯 کلاس بعدی شما</div>';
        $hero.='<div class="gls-next-class-body"><div class="gls-next-class-info"><strong>'.esc_html($next->class_name).'</strong><span>'.esc_html($next_jalali).' | ⏰ '.esc_html($next->booking_time).'</span></div>';
        $hero.='<div class="gls-next-class-actions">';
        if($next_status){
            $hero.=$this->student_class_link_button($next,'booking');
        } else {
            $hero.='<span class="gls-link-locked">⏳ در انتظار تایید پرداخت</span>';
        }
        $hero.='</div></div></div>';

        $html=$hero.'<div id="gls-mobile-bookings" class="gls-dashboard-card gls-bookings-panel"><div class="gls-card-head"><div><h3>رزروهای آینده</h3><small>لینک ورود طبق زمان‌بندی کلاس فعال می‌شود.</small></div><span class="gls-count-badge">'.count($bookings).'</span></div><div class="gls-booking-list">';
        foreach($bookings as $i=>$b){
            $jalali=$this->g2j_string($b->booking_date);
            $html.='<div class="gls-booking-card"><div class="gls-booking-main"><h4>'.esc_html($b->class_name).'</h4>';
            $html.='<div class="gls-booking-date"><span>'.esc_html($jalali).'</span><span>'.esc_html($b->booking_date).'</span><span>⏰ '.esc_html($b->booking_time).'</span></div></div>';
            if(($b->status ?? '')==='confirmed') {
                $html.=$this->student_class_link_button($b,'booking');
            } else {
                $html.='<span class="gls-link-locked">⏳ در انتظار تایید پرداخت</span>';
            }
            $html.='</div>';
        }
        $html.='</div></div>';
        return $html;
    }

    private function booking_portal(){
        if(!is_user_logged_in()) return '';

        if(!shortcode_exists('german_teacher_booking')){
            return '<section id="gls-mobile-book-class" class="gls-booking-portal gls-booking-portal-open gls-no-print"><div class="gls-booking-summary gls-booking-static"><span class="gls-booking-summary-main"><span class="gls-booking-icon">🗓️</span><span><h3>رزرو کلاس جدید</h3><small>افزونه رزرو کلاس فعال یا در دسترس نیست.</small></span></span></div><div class="gls-booking-unavailable">برای نمایش فرم رزرو، افزونه رزرو کلاس باید فعال باشد و شورت‌کد <code>german_teacher_booking</code> را ثبت کرده باشد.</div></section>';
        }

        $booking_html=do_shortcode('[german_teacher_booking]');
        if(trim(wp_strip_all_tags($booking_html))===''){
            $booking_html='<div class="gls-booking-unavailable">فرم رزرو در حال حاضر خروجی قابل نمایش ندارد. تنظیمات افزونه رزرو را بررسی کنید.</div>';
        }

        return '<section id="gls-mobile-book-class" class="gls-booking-portal gls-booking-portal-open gls-no-print"><div class="gls-booking-summary gls-booking-static"><span class="gls-booking-summary-main"><span class="gls-booking-icon">🗓️</span><span><h3>رزرو کلاس جدید</h3><small>انتخاب نوع کلاس، تاریخ، ساعت، پکیج و پرداخت از همین پنل</small></span></span></div><div class="gls-booking-embed">'.$booking_html.'</div></section>';
    }

    private function notification_count($uid){
        global $wpdb;
        $user=get_userdata($uid);
        if(!$user) return 0;
        $read_at=get_user_meta($uid,'gls_notifications_read_at',true);
        $bot_read_at=get_user_meta($uid,'gtbp_bot_notifications_read_at',true);
        if($bot_read_at && (!$read_at || strtotime($bot_read_at) > strtotime($read_at))) $read_at=$bot_read_at;
        if(!$read_at) $read_at='1970-01-01 00:00:00';
        return intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->logs} WHERE (user_id=%d OR email=%s) AND sent_at>%s",
            intval($uid), $user->user_email, $read_at
        )));
    }

    private function notification_panel($uid){
        global $wpdb;
        $user=get_userdata($uid);
        if(!$user) return '';
        $read_at=get_user_meta($uid,'gls_notifications_read_at',true);
        $bot_read_at=get_user_meta($uid,'gtbp_bot_notifications_read_at',true);
        if($bot_read_at && (!$read_at || strtotime($bot_read_at) > strtotime($read_at))) $read_at=$bot_read_at;
        if(!$read_at) $read_at='1970-01-01 00:00:00';
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->logs} WHERE (user_id=%d OR email=%s) AND sent_at>%s ORDER BY sent_at DESC LIMIT 40",
            intval($uid), $user->user_email, $read_at
        ));
        $count=count((array)$rows);
        $html='<div id="gls-mobile-notifications" class="gls-dashboard-card gls-notification-panel"><div class="gls-card-head"><div><h3>اعلان‌ها</h3><small>جزوه، تمرین، ویدئو و تصحیح‌های جدید</small></div>'.($count?'<span class="gls-count-badge">'.intval($count).'</span>':'').'</div>';
        if(!$rows){
            $html.='<div class="gls-empty slim">اعلان تازه‌ای برای نمایش وجود ندارد.</div></div>';
            return $html;
        }
        $html.='<div class="gls-notification-list">';
        foreach($rows as $r){
            $type=$this->notification_type_label($r->type);
            $html.='<article class="gls-notification-row"><span class="gls-notification-dot"></span><div><strong>'.esc_html($type).'</strong><p>'.esc_html($r->subject).'</p><small>'.esc_html($r->sent_at).'</small></div></article>';
        }
        $html.='</div></div>';
        return $html;
    }

    private function notification_type_label($type){
        $map=[
            'session_update'=>'به‌روزرسانی جلسه',
            'correction_ready'=>'تصحیح تکلیف',
            'homework_submitted'=>'ارسال تکلیف',
            'recording_ready'=>'ویدئوی ضبط‌شده',
        ];
        return $map[$type] ?? 'اعلان آموزشی';
    }

    private function profile_panel($uid){
        $u=get_userdata($uid);
        if(!$u) return '';
        $card=get_user_meta($uid,'gtbp_refund_card',true);
        $avatar_id=intval(get_user_meta($uid,'gls_profile_avatar_id',true));
        $avatar=$avatar_id ? wp_get_attachment_image_url($avatar_id,'thumbnail') : get_avatar_url($uid, ['size'=>96]);
        $html='<div id="gls-mobile-profile" class="gls-dashboard-card gls-profile-panel"><div class="gls-card-head"><div><h3>اطلاعات کاربری</h3><small>مدیریت اطلاعات حساب و کارت برگشت وجه</small></div></div>';
        $html.='<form class="gls-form gls-profile-form" enctype="multipart/form-data">';
        $html.='<div class="gls-profile-head"><img src="'.esc_url($avatar).'" alt="" class="gls-profile-avatar"><div><strong>'.esc_html($u->display_name).'</strong><small>'.esc_html($u->user_email).'</small></div></div>';
        $html.='<div class="gls-profile-section-title">اطلاعات شخصی</div>';
        $html.='<div class="gls-profile-grid"><label>نام<input type="text" name="first_name" value="'.esc_attr($u->first_name).'" autocomplete="given-name"></label><label>نام خانوادگی<input type="text" name="last_name" value="'.esc_attr($u->last_name).'" autocomplete="family-name"></label></div>';
        $html.='<label>شماره کارت برای برگشت وجه<input type="text" name="refund_card" value="'.esc_attr($card).'" inputmode="numeric" placeholder="مثلاً 6037-..." dir="ltr"></label>';
        $html.='<div class="gls-profile-section-title">عکس پروفایل</div>';
        $html.='<label>عکس پروفایل<input type="file" name="profile_avatar" accept="image/*"></label>';
        $html.='<div class="gls-profile-section-title">تغییر رمز عبور</div>';
        $html.='<div class="gls-profile-grid"><label>رمز جدید<input type="password" name="new_pass" autocomplete="new-password"></label><label>تکرار رمز جدید<input type="password" name="new_pass2" autocomplete="new-password"></label></div>';
        $html.='<p style="margin-top:18px"><button class="gls-btn red" type="submit">ذخیره اطلاعات</button></p><div class="gls-profile-result"></div></form></div>';
        return $html;
    }

    public function ajax_mark_notifications_read(){
        check_ajax_referer('gls_nonce','nonce');
        if(!is_user_logged_in()) wp_send_json_error('ابتدا وارد شوید.');
        $now=current_time('mysql');
        update_user_meta(get_current_user_id(),'gls_notifications_read_at',$now);
        update_user_meta(get_current_user_id(),'gtbp_bot_notifications_read_at',$now);
        wp_send_json_success(['count'=>0]);
    }

    public function ajax_save_profile(){
        check_ajax_referer('gls_nonce','nonce');
        if(!is_user_logged_in()) wp_send_json_error('ابتدا وارد شوید.');
        $uid=get_current_user_id();
        $first=sanitize_text_field($_POST['first_name']??'');
        $last=sanitize_text_field($_POST['last_name']??'');
        wp_update_user(['ID'=>$uid,'first_name'=>$first,'last_name'=>$last,'display_name'=>trim($first.' '.$last) ?: wp_get_current_user()->display_name]);
        update_user_meta($uid,'gtbp_refund_card',sanitize_text_field($_POST['refund_card']??''));
        $p1=(string)($_POST['new_pass']??'');
        $p2=(string)($_POST['new_pass2']??'');
        if($p1!=='' || $p2!==''){
            if(strlen($p1)<8) wp_send_json_error('رمز عبور باید حداقل ۸ کاراکتر باشد.');
            if($p1!==$p2) wp_send_json_error('تکرار رمز عبور درست نیست.');
            wp_set_password($p1,$uid);
            wp_set_auth_cookie($uid,true);
        }
        if(!empty($_FILES['profile_avatar']['name'])){
            require_once ABSPATH.'wp-admin/includes/file.php';
            require_once ABSPATH.'wp-admin/includes/media.php';
            require_once ABSPATH.'wp-admin/includes/image.php';
            $old=intval(get_user_meta($uid,'gls_profile_avatar_id',true));
            $att=media_handle_upload('profile_avatar',0);
            if(!is_wp_error($att)){
                if($old) wp_delete_attachment($old,true);
                update_user_meta($uid,'gls_profile_avatar_id',intval($att));
            }
        }
        wp_send_json_success('اطلاعات حساب ذخیره شد.');
    }

    private function login_panel_html(){
        $redirect = (is_ssl() ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
        $redirect = esc_url_raw(remove_query_arg(['login','loggedout','wp_lang'], $redirect));
        ob_start();
        echo '<div class="gls-wrap gls-login-wrap"><div class="gls-shell"><div class="gls-inner"><section class="gls-login-card">';
        echo '<div class="gls-login-brand"><span class="gls-login-icon">🔐</span><h2>ورود به پنل آموزشی</h2><p>برای مشاهده رزروها، جزوه‌ها، تکالیف و اعلان‌های خود وارد حساب کاربری شوید.</p></div>';
        if (isset($_GET['login']) && $_GET['login'] === 'failed') echo '<div class="gls-notice">نام کاربری یا رمز عبور درست نیست.</div>';
        wp_login_form([
            'echo' => true,
            'redirect' => $redirect,
            'form_id' => 'gls-login-form',
            'label_username' => 'ایمیل یا نام کاربری',
            'label_password' => 'رمز عبور',
            'label_remember' => 'مرا به خاطر بسپار',
            'label_log_in' => 'ورود به پنل',
            'remember' => true,
            'value_remember' => true,
        ]);
        echo '<div class="gls-login-links"><a href="'.esc_url(wp_lostpassword_url($redirect)).'">رمز عبور را فراموش کرده‌ام</a></div>';
        echo '</section></div></div></div>';
        return ob_get_clean();
    }

    public function student_panel(){
        if(!is_user_logged_in()) {
            return $this->login_panel_html();
        }

        $this->sync_bookings(30);

        global $wpdb;
        $u=wp_get_current_user();

        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->sessions}
             WHERE session_status='published' AND (user_id=%d OR student_email=%s)
             ORDER BY booking_date DESC,id DESC",
            get_current_user_id(),
            $u->user_email
        ));

        $default_sel = 0;
        foreach ((array)$rows as $r) {
            if (($r->attendance_status ?? '') === 'absent') continue;
            if (!$default_sel) $default_sel = intval($r->id);
            if (trim(wp_strip_all_tags((string)$r->lesson_content)) !== '') {
                $default_sel = intval($r->id);
                break;
            }
        }
        if(!$default_sel && $rows) $default_sel=intval($rows[0]->id);
        $sel = isset($_GET['gls_session']) ? intval($_GET['gls_session']) : $default_sel;
        foreach ((array)$rows as $r) {
            if (intval($r->id)===$sel && ($r->attendance_status ?? '')==='absent' && $default_sel) { $sel=$default_sel; break; }
        }
        $initial_target = isset($_GET['gls_session']) ? 'content' : 'reserve';

        ob_start();

        echo '<div class="gls-wrap gls-student-full'.($rows?'':' gls-no-sessions').'" data-user="'.intval(get_current_user_id()).'"><div class="gls-shell"><div class="gls-inner"><div class="gls-top"><div><h2>پنل آموزشی من</h2><small>رزرو کلاس، جلسات، جزوه، تکالیف و آزمونک‌ها</small></div></div>';
        $desktop_default='reserve';
        $task_badge=$this->homework_badge_count(get_current_user_id());
        $notification_badge=$this->notification_count(get_current_user_id());
        echo '<nav class="gls-desktop-top-nav gls-no-print" aria-label="منوی پنل آموزشی"><button type="button" class="gls-desktop-nav-btn '.($initial_target==='content'?'active':'').'" data-target="content"><span class="gls-desk-ico">📖</span><b>جزوه‌ها</b></button><button type="button" class="gls-desktop-nav-btn '.($initial_target==='reserve'?'active':'').'" data-target="reserve"><span class="gls-desk-ico">🗓️</span><b>رزرو کلاس</b></button><button type="button" class="gls-desktop-nav-btn" data-target="sessions"><span class="gls-desk-ico">📚</span><b>لیست جلسات</b></button><button type="button" class="gls-desktop-nav-btn" data-target="bookings"><span class="gls-desk-ico">🎓</span><b>کلاس‌های آینده</b></button><button type="button" class="gls-desktop-nav-btn" data-target="tasks"><span class="gls-desk-ico">✍️</span><b>تکالیف</b>'.($task_badge?'<span class="gls-nav-badge" data-count="'.intval($task_badge).'">'.intval($task_badge).'</span>':'').'</button><button type="button" class="gls-desktop-nav-btn" data-target="performance"><span class="gls-desk-ico">📊</span><b>عملکرد آموزشی</b></button><button type="button" class="gls-desktop-nav-btn" data-target="notifications"><span class="gls-desk-ico">🔔</span><b>اعلان‌ها</b>'.($notification_badge?'<span class="gls-nav-badge">'.intval($notification_badge).'</span>':'').'</button><button type="button" class="gls-desktop-nav-btn" data-target="profile"><span class="gls-desk-ico">👤</span><b>حساب</b></button><button type="button" class="gls-desktop-nav-btn gls-cart-nav-btn" data-target="reserve"><span class="gls-desk-ico">🛒</span><b>سبد</b><span class="gls-nav-badge gls-cart-badge" style="display:none">0</span></button></nav>';

        echo '<div class="gls-dashboard gls-no-print">';
        echo $this->booking_board(get_current_user_id());
        echo $this->performance_box(get_current_user_id());
        echo $this->homework_overview_panel(get_current_user_id());
        echo $this->notification_panel(get_current_user_id());
        echo $this->profile_panel(get_current_user_id());
        echo '</div>';
        echo $this->booking_portal();

        if(!$rows){
            echo '<div id="gls-mobile-content" class="gls-empty">هنوز جلسه آموزشی آماده‌ای برای شما ثبت نشده است. از بخش رزرو می‌توانید کلاس جدید ثبت کنید.</div>';
            echo '<nav class="gls-mobile-bottom-nav gls-no-print" aria-label="منوی پنل آموزشی"><button type="button" class="gls-mobile-nav-btn active" data-target="reserve"><span>🗓️</span><b>رزرو</b></button><button type="button" class="gls-mobile-nav-btn" data-target="sessions"><span>📅</span><b>جلسات</b></button><button type="button" class="gls-mobile-nav-btn" data-target="bookings"><span>🎓</span><b>کلاس‌ها</b></button><button type="button" class="gls-mobile-nav-btn" data-target="tasks"><span>✍️</span><b>تکالیف</b>'.($task_badge?'<i data-count="'.intval($task_badge).'">'.intval($task_badge).'</i>':'').'</button><button type="button" class="gls-mobile-nav-btn" data-target="performance"><span>📊</span><b>عملکرد</b></button><button type="button" class="gls-mobile-nav-btn gls-icon-only" data-target="notifications" aria-label="اعلان‌ها"><span>🔔</span>'.($notification_badge?'<i>'.intval($notification_badge).'</i>':'').'</button><button type="button" class="gls-mobile-nav-btn gls-icon-only" data-target="profile" aria-label="حساب کاربری"><span>👤</span></button><button type="button" class="gls-mobile-nav-btn gls-cart-nav-btn gls-icon-only" data-target="reserve" aria-label="سبد رزرو"><span>🛒</span><i class="gls-cart-badge" style="display:none">0</i></button></nav>';
            echo '</div></div></div>';
            return ob_get_clean();
        }

        // Fix #10: horizontal chip row for quick mobile session navigation
        echo '<div class="gls-session-chips gls-no-print" aria-label="انتخاب سریع جلسه">';
        foreach($rows as $r){
            $absent=(($r->attendance_status ?? '')==='absent');
            $chip_active=($r->id==$sel)?'active':'';
            $chip_class='gls-session-chip '.($absent?'absent ':'').($chip_active?'active ':'');
            if($absent){
                echo '<span class="'.esc_attr($chip_class).'">'.esc_html($r->jalali_date).'</span>';
            } else {
                echo '<a class="'.esc_attr($chip_class).'" href="'.esc_url(add_query_arg('gls_session',intval($r->id))).'">'.esc_html($r->jalali_date).'</a>';
            }
        }
        echo '</div>';
        echo '<section id="gls-mobile-sessions" class="gls-mobile-session-picker gls-no-print"><div class="gls-session-list-head"><h3>جلسات آموزشی</h3><small>از جدیدترین به قدیمی‌ترین؛ روی هر جلسه بزنید تا محتوایش باز شود.</small></div><div class="gls-mobile-session-list">';
        foreach($rows as $r){
            $empty=!$this->session_has_content($r);
            $has_lesson=trim(wp_strip_all_tags((string)$r->lesson_content)) !== '';
            $absent=(($r->attendance_status ?? '')==='absent');
            $booking_for_link=$this->booking_for_session_row($r);
            $row_class='gls-mobile-session-row '.($r->id==$sel?'active ':'').($empty?'is-empty ':'').($has_lesson?'has-lesson ':'').($absent?'gls-session-absent-card ':'');
            $state=$absent?'غایب':($has_lesson?'جزوه دارد':($empty?'آماده نیست':'باز شود'));
            if($absent){
                echo '<div class="gls-mobile-session-card-with-link gls-mobile-session-card-absent"><div class="'.esc_attr($row_class).'"><span class="gls-mobile-session-dot"></span><span class="gls-mobile-session-info"><strong>'.esc_html($r->title).'</strong><small>'.esc_html($r->jalali_date).' | '.esc_html($r->booking_time).' | '.esc_html($r->class_name).'</small></span><span class="gls-mobile-session-state">'.esc_html($state).'</span></div></div>';
            } else {
                echo '<div class="gls-mobile-session-card-with-link"><a class="'.esc_attr($row_class).'" href="'.esc_url(add_query_arg('gls_session',intval($r->id))).'"><span class="gls-mobile-session-dot"></span><span class="gls-mobile-session-info"><strong>'.esc_html($r->title).'</strong><small>'.esc_html($r->jalali_date).' | '.esc_html($r->booking_time).' | '.esc_html($r->class_name).'</small></span><span class="gls-mobile-session-state">'.esc_html($state).'</span></a>'.$this->student_class_link_button($booking_for_link,'session').'</div>';
            }
        }
        echo '</div></section>';

        echo '<div class="gls-grid"><aside class="gls-sidebar">';
        foreach($rows as $r){
            $empty=!$this->session_has_content($r);
            $absent=(($r->attendance_status ?? '')==='absent');
            $card_class='gls-session-card '.($r->id==$sel?'active ':'').($empty?'is-empty ':'').($absent?'gls-session-absent-card':'');
            $inner='<h3>'.esc_html($r->title).'</h3><div class="gls-meta"><span>'.esc_html($r->jalali_date).'</span><span>'.esc_html($r->booking_date).'</span>'.($absent?'<span>غایب</span>':'').'</div><span class="gls-session-time">⏰ '.esc_html($r->booking_time).'</span>';
            if($absent){
                echo '<div class="'.esc_attr($card_class).'">'.$inner.'</div>';
            } else {
                echo '<a class="'.esc_attr($card_class).'" href="'.esc_url(add_query_arg('gls_session',intval($r->id))).'">'.$inner.'</a>';
            }
        }
        echo '</aside><main id="gls-mobile-content" class="gls-main">';
        $this->student_session($sel);
        echo '</main></div>';
        echo '<nav class="gls-mobile-bottom-nav gls-no-print" aria-label="منوی پنل آموزشی"><button type="button" class="gls-mobile-nav-btn '.($initial_target==='reserve'?'active':'').'" data-target="reserve"><span>🗓️</span><b>رزرو</b></button><button type="button" class="gls-mobile-nav-btn" data-target="sessions"><span>📅</span><b>جلسات</b></button><button type="button" class="gls-mobile-nav-btn" data-target="bookings"><span>🎓</span><b>کلاس‌ها</b></button><button type="button" class="gls-mobile-nav-btn" data-target="tasks"><span>✍️</span><b>تکالیف</b>'.($task_badge?'<i data-count="'.intval($task_badge).'">'.intval($task_badge).'</i>':'').'</button><button type="button" class="gls-mobile-nav-btn" data-target="performance"><span>📊</span><b>عملکرد</b></button><button type="button" class="gls-mobile-nav-btn gls-icon-only" data-target="notifications" aria-label="اعلان‌ها"><span>🔔</span>'.($notification_badge?'<i>'.intval($notification_badge).'</i>':'').'</button><button type="button" class="gls-mobile-nav-btn gls-icon-only" data-target="profile" aria-label="حساب کاربری"><span>👤</span></button><button type="button" class="gls-mobile-nav-btn gls-cart-nav-btn gls-icon-only" data-target="reserve" aria-label="سبد رزرو"><span>🛒</span><i class="gls-cart-badge" style="display:none">0</i></button></nav>';
        echo '</div></div></div>';

        return ob_get_clean();
    }

    public function single_session($atts){
        $id=intval(shortcode_atts(['id'=>0],$atts)['id']);
        if(!$id)return '';

        ob_start();
        echo '<div class="gls-wrap gls-student-full"><div class="gls-shell"><div class="gls-inner"><main class="gls-main">';
        $this->student_session($id);
        echo '</main></div></div></div>';
        return ob_get_clean();
    }

    private function can_view($s){
        if(!$s||!is_user_logged_in())return false;
        if(current_user_can('manage_options'))return true;
        $u=wp_get_current_user();
        return intval($s->user_id)===get_current_user_id() || strtolower($s->student_email)===strtolower($u->user_email);
    }

    private function student_session($id){
        $s=$this->get_session($id);
        if(!$this->can_view($s)){
            echo '<div class="gls-empty">دسترسی به این جلسه مجاز نیست.</div>';
            return;
        }

        $res=$this->get_items($id,'resource');
        $ex=$this->get_items($id,'exercise');
        $subs=$this->get_user_submissions($id);

        $has_lesson=trim(wp_strip_all_tags((string)$s->lesson_content)) !== '' || trim(wp_strip_all_tags((string)($s->ai_reading_content ?? ''))) !== '';
        $has_resources=!empty($res);
        $has_exercises=!empty($ex);
        // خروجی‌های BBB شخصی در جدول مستقل Bridge نگهداری می‌شوند تا هسته افزونه
        // و چرخه ثبت رزرو از انتقال فایل‌های ضبط‌شده مستقل بمانند.
        $bbb_presentation = '';
        $bbb_download = '';
        $bbb_filename = '';
        global $wpdb;
        $transport_table = $wpdb->prefix . 'gtbp_recording_transport';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $transport_table)) === $transport_table) {
            $transport = $wpdb->get_row($wpdb->prepare(
                "SELECT presentation_url,video_url,filename FROM {$transport_table} WHERE status='ready' AND (session_id=%d OR (booking_id=%d AND booking_id>0)) ORDER BY updated_at DESC,id DESC LIMIT 1",
                intval($s->id), intval($s->booking_id ?? 0)
            ));
            if ($transport) {
                $bbb_presentation = trim((string)$transport->presentation_url);
                $bbb_download = trim((string)$transport->video_url);
                $bbb_filename = sanitize_file_name((string)$transport->filename);
            }
        }
        // نسخه ۱۴.۱۲.۲: اولویت پخش BBB شخصی، سپس لینک‌های قدیمی جلسه.
        $effective_video = $bbb_presentation !== '' ? $bbb_presentation : trim((string)$s->video_url);
        if ($effective_video === '' && $bbb_download !== '') $effective_video = $bbb_download;
        if ($effective_video === '' && isset($s->meet_video_url)) $effective_video = trim((string)$s->meet_video_url);
        if ($effective_video === '' && isset($s->rmt_video_url))  $effective_video = trim((string)$s->rmt_video_url);
        // لینک دانلود مستقیم روومیت (در صورت وجود روی رکورد رزرو)
        $rmt_download = '';
        if ($effective_video !== '' && isset($s->rmt_video_url) && $effective_video === trim((string)$s->rmt_video_url) && !empty($s->booking_id)) {
            global $wpdb;
            $dl = $wpdb->get_var($wpdb->prepare("SELECT rmt_recording_download FROM {$wpdb->prefix}german_bookings WHERE id=%d", intval($s->booking_id)));
            if ($dl) $rmt_download = (string)$dl;
        }
        $has_video = $effective_video !== '';
        $has_test=trim((string)$s->test_shortcode) !== '';
        $has_homework=true;

        if(!$has_lesson && !$has_resources && !$has_exercises && !$has_video && !$has_test && empty($subs)){
            echo '<div class="gls-box"><div class="gls-empty">این جلسه هنوز آماده نشده است. وقتی جزوه، تمرین، منبع، ویدئو یا آزمونک اضافه شود، اینجا نمایش داده می‌شود.</div></div>';
            return;
        }

        $active='gls-lesson';
        if(!$has_lesson && $has_resources) $active='gls-resources';
        elseif(!$has_lesson && !$has_resources && $has_exercises) $active='gls-exercises';
        elseif(!$has_lesson && !$has_resources && !$has_exercises && $has_video) $active='gls-video';
        elseif(!$has_lesson && !$has_resources && !$has_exercises && !$has_video && $has_test) $active='gls-test';
        elseif(!$has_lesson && !$has_resources && !$has_exercises && !$has_video && !$has_test && !empty($subs)) $active='gls-submit';


        $tabs=[
            ['gls-lesson','جزوه',$has_lesson,0],
            ['gls-resources','منابع',$has_resources,count($res)],
            ['gls-exercises','تمرینات',$has_exercises,count($ex)],
            ['gls-video','ویدئو',$has_video,0],
            ['gls-submit','ارسال تکلیف',true,0],
            ['gls-test','آزمونک',$has_test,0],
        ];

        $requested_tab=isset($_GET['gls_tab'])?sanitize_key(wp_unslash($_GET['gls_tab'])):'';
        $allowed_tabs=['gls-lesson','gls-resources','gls-exercises','gls-video','gls-submit','gls-test'];
        if(in_array($requested_tab,$allowed_tabs,true)){
            if($requested_tab==='gls-test' && $has_test) $active='gls-test';
            elseif($requested_tab==='gls-lesson' && $has_lesson) $active='gls-lesson';
            elseif($requested_tab==='gls-resources' && $has_resources) $active='gls-resources';
            elseif($requested_tab==='gls-exercises' && $has_exercises) $active='gls-exercises';
            elseif($requested_tab==='gls-video' && $has_video) $active='gls-video';
            elseif($requested_tab==='gls-submit') $active='gls-submit';
        }
        global $wpdb;
        $all_lessons=$wpdb->get_results($wpdb->prepare(
            "SELECT id,title,jalali_date,booking_date,booking_time,class_name,lesson_content,ai_reading_title,ai_reading_content
             FROM {$this->sessions}
             WHERE session_status='published'
             AND (user_id=%d OR student_email=%s)
             AND ((lesson_content IS NOT NULL AND lesson_content<>'') OR (ai_reading_content IS NOT NULL AND ai_reading_content<>''))
             ORDER BY booking_date DESC,id DESC",
            intval($s->user_id),
            (string)$s->student_email
        ));
        echo '<div class="gls-current-session-banner gls-no-print"><span class="gls-current-session-mark">جلسه فعال</span><strong>'.esc_html($s->title).'</strong><small>'.esc_html($s->jalali_date.' | '.$s->booking_time.' | '.$s->class_name).'</small>';
        if($all_lessons) echo '<button type="button" class="gls-btn gls-all-lessons-btn">⬇ دانلود همه جزوه‌ها</button>';
        echo '</div>';
        if($all_lessons){
            echo '<div id="gls-all-lessons-source" class="gls-all-lessons-source" aria-hidden="true">';
            foreach($all_lessons as $lesson_row){
                echo '<article class="gls-pdf-session"><header class="gls-pdf-session-head"><h2>'.esc_html($lesson_row->title).'</h2><small>'.esc_html($lesson_row->jalali_date.' | '.$lesson_row->booking_time.' | '.$lesson_row->class_name).'</small></header><div class="gls-lesson-view">';
                if(trim(wp_strip_all_tags((string)$lesson_row->lesson_content))!=='') echo wp_kses_post($lesson_row->lesson_content);
                if(trim(wp_strip_all_tags((string)$lesson_row->ai_reading_content))!==''){
                    $all_ai_title=esc_html($lesson_row->ai_reading_title ?: $this->settings()['ai_reading_title']);
                    echo '<div class="gls-ai-reading" dir="ltr" lang="de"><div class="gls-ai-reading-header"><span class="gls-ai-reading-flag">🇩🇪</span><div><h3 style="margin:0 0 2px;">'.$all_ai_title.'</h3><small class="gls-ai-reading-badge">Lesetext · German Reading</small></div></div><div class="gls-ai-reading-body">'.wp_kses_post($lesson_row->ai_reading_content).'</div></div>';
                }
                echo '</div></article>';
            }
            echo '</div>';
        }

        echo '<div class="gls-tabs gls-no-print">';
        foreach($tabs as $t){
            $disabled = !$t[2];
            $badge = $t[3] ? '<span class="gls-tab-badge">'.intval($t[3]).'</span>' : '';
            echo '<button type="button" class="gls-tab '.($t[0]===$active?'active ':'').($disabled?'is-disabled':'').'" data-tab="'.esc_attr($t[0]).'" '.($disabled?'disabled aria-disabled="true"':'').'>'.esc_html($t[1]).$badge.'</button>';
        }
        echo '</div>';

        echo '<section id="gls-lesson" class="gls-panel '.($active==='gls-lesson'?'active':'').' gls-box">';
        if($has_lesson){
            echo '<div class="gls-lesson-actions gls-no-print"><button type="button" class="gls-btn pdf gls-pdf-lesson" data-title="'.esc_attr($s->title).'">ذخیره PDF جزوه</button></div>';
            echo '<h3>جزوه جلسه</h3>';
            echo '<div class="gls-lesson-view">';
            if(trim(wp_strip_all_tags((string)$s->lesson_content)) !== '') echo wp_kses_post($s->lesson_content);
            if(trim(wp_strip_all_tags((string)($s->ai_reading_content ?? ''))) !== ''){
                // Fix #14: visually distinct AI reading card with flag + LTR label
                $ai_title=esc_html($s->ai_reading_title ?: $this->settings()['ai_reading_title']);
                echo '<div class="gls-ai-reading" dir="ltr" lang="de"><div class="gls-ai-reading-header"><span class="gls-ai-reading-flag">🇩🇪</span><div><h3 style="margin:0 0 2px;">'.$ai_title.'</h3><small class="gls-ai-reading-badge">Lesetext · German Reading</small></div></div><div class="gls-ai-reading-body">'.wp_kses_post($s->ai_reading_content).'</div></div>';
            }
            echo '</div>';
        } else {
            echo '<div class="gls-empty">جزوه هنوز برای این جلسه ذخیره نشده است.</div>';
        }
        echo '</section>';

        echo '<section id="gls-resources" class="gls-panel '.($active==='gls-resources'?'active':'').' gls-box"><h3>منابع جلسه</h3>';
        if($has_resources) $this->front_items($res,'resource'); else echo '<div class="gls-empty">منبعی برای این جلسه ثبت نشده است.</div>';
        echo '</section>';

        echo '<section id="gls-exercises" class="gls-panel '.($active==='gls-exercises'?'active':'').' gls-box"><h3>تمرینات جلسه</h3>';
        if($has_exercises) $this->front_items($ex,'exercise'); else echo '<div class="gls-empty">تمرینی برای این جلسه ثبت نشده است.</div>';
        echo '</section>';

        echo '<section id="gls-video" class="gls-panel '.($active==='gls-video'?'active':'').' gls-box"><h3>ویدئوی جلسه</h3>';
        if($has_video){
            $vid_url = esc_url($effective_video);
            // تشخیص نوع منبع ویدئو
            $drive_id = '';
            if (preg_match('#drive\.google\.com/(?:file/d/|open\?id=|uc\?id=)([A-Za-z0-9_\-]{20,})#', $effective_video, $m)) {
                $drive_id = $m[1];
            } elseif (preg_match('#drive\.usercontent\.google\.com/download\?id=([A-Za-z0-9_\-]{20,})#', $effective_video, $m)) {
                $drive_id = $m[1];
            } elseif (preg_match('#[?&]id=([A-Za-z0-9_\-]{20,})#', $effective_video, $m)) {
                $drive_id = $m[1];
            }
            // منابع مستقیم ویدئویی (روومیت m4v/mp4) در تگ <video> پخش می‌شوند
            $is_direct_media = (bool) preg_match('#\.(m4v|mp4|webm|ogg)(\?|$)#i', $effective_video);
            $is_roomeet      = (strpos($effective_video, 'roomeet.ir') !== false);

            if ($drive_id) {
                $embed_url    = 'https://drive.google.com/file/d/' . $drive_id . '/preview';
                $download_url = $bbb_download !== '' ? $bbb_download : (!empty($rmt_download) ? $rmt_download : ('https://drive.usercontent.google.com/download?id=' . $drive_id . '&export=download'));
                $open_url     = 'https://drive.google.com/file/d/' . $drive_id . '/view';
                $open_label   = '🔗 باز کردن در Google Drive';
            } else {
                $embed_url    = $effective_video;
                $download_url = $bbb_download !== '' ? $bbb_download : (!empty($rmt_download) ? $rmt_download : ($is_direct_media ? $effective_video : ''));
                $open_url     = $effective_video;
                $open_label   = $is_roomeet ? '🔗 باز کردن در روومیت' : '🔗 باز کردن در پنجره جدید';
            }

            echo '<div class="gls-video-wrap">';

            // دکمه‌های اکشن بالای پخش‌کننده
            echo '<div class="gls-video-actions" style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;justify-content:center;">';
            if ($download_url) {
                echo '<a href="'.esc_url($download_url).'" download="'.esc_attr($bbb_filename).'" target="_blank" rel="noopener" class="gls-btn gls-video-dl" style="background:#0f766e;color:#fff;padding:9px 18px;border-radius:10px;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:6px;box-shadow:0 6px 16px rgba(15,118,110,.28);">⬇️ دانلود ویدئوی جلسه</a>';
            }
            echo '<a href="'.esc_url($open_url).'" target="_blank" rel="noopener" class="gls-btn" style="background:#111;color:#fff;padding:9px 18px;border-radius:10px;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:6px;">'.esc_html($open_label).'</a>';
            echo '</div>';

            echo '<div class="gls-bbb-player-wrap">';
            if ($is_direct_media && !$drive_id) {
                // پخش‌کننده بومی HTML5 برای فایل مستقیم روومیت
                echo '<video class="gls-bbb-player" src="'.esc_url($effective_video).'" controls playsinline preload="metadata" style="background:#000;"></video>';
            } else {
                echo '<iframe class="gls-bbb-player" src="'.esc_url($embed_url).'" allowfullscreen allow="autoplay; fullscreen; picture-in-picture; web-share"></iframe>';
            }
            echo '<button type="button" class="gls-bbb-fullscreen-btn" title="تمام‌صفحه"><i class="gls-fs-icon">⛶</i><span class="gls-fs-label">نمایش تمام صفحه</span></button>';
            echo '</div>';

            echo '</div>';
        } else echo '<div class="gls-empty">ویدئوی این جلسه هنوز آماده نیست.</div>';
        echo '</section>';

        echo '<section id="gls-submit" class="gls-panel '.($active==='gls-submit'?'active':'').' gls-box">';
        $this->homework_box($s);
        echo '</section>';

        echo '<section id="gls-test" class="gls-panel '.($active==='gls-test'?'active':'').' gls-box"><h3>آزمونک جلسه</h3>';
        if($has_test) echo '<div class="gls-test-wrap">'.$this->render_test($s->test_shortcode,intval($s->id)).'</div>'; else echo '<div class="gls-empty">آزمونکی برای این جلسه ثبت نشده است.</div>';
        echo '</section>';
    }

    private function get_user_submissions($session_id){
        global $wpdb;
        if(!is_user_logged_in()) return [];
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->subs}
             WHERE session_id=%d AND user_id=%d
             ORDER BY submitted_at DESC,id DESC",
            intval($session_id),
            get_current_user_id()
        ));
    }

    private function front_items($items,$type='resource'){
        if(!$items){
            echo '<div class="gls-empty">موردی ثبت نشده است.</div>';
            return;
        }

        $wrap=$type==='exercise'?'gls-exercise-grid':'gls-resource-grid';
        echo '<div class="'.esc_attr($wrap).'">';
        foreach($items as $it){
            $url=$it->url ?: ($it->attachment_id?wp_get_attachment_url($it->attachment_id):'');
            $icon=$type==='exercise'?'📝':($it->item_kind==='book'?'📚':($it->item_kind==='link'?'🔗':($it->item_kind==='image'?'🖼️':'📄')));
            echo '<div class="gls-material-card '.esc_attr($type).'"><div class="gls-material-icon">'.$icon.'</div><h4>'.esc_html($it->title).'</h4><small>'.esc_html($this->kind_label($it->item_kind)).'</small><div>'.wpautop(wp_kses_post($it->content)).'</div>';
            if($url) echo '<a class="gls-btn light" target="_blank" href="'.esc_url($url).'">'.($type==='exercise'?'باز کردن تمرین':'باز کردن منبع').'</a>';
            echo '</div>';
        }
        echo '</div>';
    }

    private function register_online_test_compat(){
        if(!post_type_exists('online_test')){
            register_post_type('online_test',[
                'labels'=>['name'=>'Tests','singular_name'=>'Test'],
                'public'=>false,
                'show_ui'=>true,
                'show_in_menu'=>false,
                'capability_type'=>'post',
                'supports'=>['title'],
                'show_in_rest'=>false,
            ]);
        }
        if(!shortcode_exists('online_test')){
            add_shortcode('online_test',[$this,'online_test_compat_shortcode']);
        }
    }

    public function online_test_compat_shortcode($atts=[]){
        $atts=shortcode_atts(['id'=>0],(array)$atts,'online_test');
        return $this->render_online_test_fallback(intval($atts['id']));
    }

    private function render_test($x,$session_id=0){
        $x=trim((string)$x);
        if($x==='') return '';

        $x=html_entity_decode($x, ENT_QUOTES, get_bloginfo('charset'));
        $x=str_replace(['“','”','‘','’','&#8220;','&#8221;','&quot;'], ['"','"',"'","'",'"','"','"'], $x);
        $test_id=0;
        $is_placement=false;

        if(preg_match('/\[online_test[^\]]*id=["\']?(\d+)["\']?/i',$x,$m)){
            $test_id=intval($m[1]);
            if(shortcode_exists('online_test')){
                $out=do_shortcode('[online_test id="'.$test_id.'"]');
                if(trim($out)!=='' && trim($out)!=='[online_test id="'.$test_id.'"]' && strpos($out,'[online_test')===false) return $this->decorate_session_test_output($out,$test_id,$session_id);
            }
            return $this->render_online_test_fallback($test_id,$session_id);
        }

        if(preg_match('/\[placement_test[^\]]*id=["\']?(\d+)["\']?/i',$x,$m)){
            $is_placement=true;
            if(shortcode_exists('placement_test')) return do_shortcode('[placement_test id="'.intval($m[1]).'"]');
            return '<div class="gls-empty">برای نمایش آزمون تعیین سطح، افزونه آزمون‌ساز باید فعال باشد.</div>';
        }

        if(is_numeric($x)){
            $test_id=intval($x);
            if(shortcode_exists('online_test')){
                $out=do_shortcode('[online_test id="'.$test_id.'"]');
                if(trim($out)!=='' && trim($out)!=='[online_test id="'.$test_id.'"]' && strpos($out,'[online_test')===false) return $this->decorate_session_test_output($out,$test_id,$session_id);
            }
            return $this->render_online_test_fallback($test_id,$session_id);
        }

        if(preg_match('/online_test\s+id\s*=?\s*["\']?(\d+)["\']?/i',$x,$m)){
            $test_id=intval($m[1]);
            if(shortcode_exists('online_test')){
                $out=do_shortcode('[online_test id="'.$test_id.'"]');
                if(trim($out)!=='' && trim($out)!=='[online_test id="'.$test_id.'"]' && strpos($out,'[online_test')===false) return $this->decorate_session_test_output($out,$test_id,$session_id);
            }
            return $this->render_online_test_fallback($test_id,$session_id);
        }

        if(strpos($x,'[')!==false && strpos($x,']')!==false){
            $out=do_shortcode($x);
            if(trim($out)!=='' && $out!==$x) return $out;
        }

        return wp_kses_post($x);
    }

    private function render_online_test_fallback($test_id,$session_id=0){
        $test_id=intval($test_id);
        if($test_id<=0) return '<div class="gls-empty">آزمون معتبر نیست.</div>';
        $json=get_post_meta($test_id,'_otg_questions_json',true);
        $data=json_decode($json,true);
        $questions=(is_array($data)&&!empty($data['questions'])&&is_array($data['questions']))?$data['questions']:[];
        if(!$questions) return '<div class="gls-empty">این آزمون هنوز سؤال ندارد یا افزونه آزمون‌ساز فعال نیست.</div>';
        $duration=intval(get_post_meta($test_id,'_otg_duration',true));
        $submitted=($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['gls_otg_submit'],$_POST['gls_otg_test_id']) && intval($_POST['gls_otg_test_id'])===$test_id && isset($_POST['gls_otg_nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['gls_otg_nonce'])),'gls_otg_'.$test_id));
        $results=[];$correct=0;
        if($submitted){
            foreach($questions as $i=>$q){
                $ans=isset($_POST['gls_otg_answer_'.$test_id.'_'.$i])?sanitize_text_field(wp_unslash($_POST['gls_otg_answer_'.$test_id.'_'.$i])):'';
                $ok=false;
                if(($q['type']??'')==='multiple_choice'){
                    $ok=((string)$ans===(string)intval($q['correct']??-1));
                }else{
                    $clean=mb_strtolower(trim($ans));
                    foreach((array)($q['acceptable_answers']??[]) as $acc){
                        $a=is_array($acc)?($acc['answer']??''):$acc;
                        if(mb_strtolower(trim((string)$a))===$clean){$ok=true;break;}
                    }
                }
                if($ok)$correct++;
                $results[$i]=['ok'=>$ok,'answer'=>$ans];
            }
            if(is_user_logged_in()){
                global $wpdb;
                $table=$wpdb->prefix.'otg_results';
                if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))===$table){
                    $wpdb->insert($table,['user_id'=>get_current_user_id(),'test_id'=>$test_id,'score'=>$correct,'total'=>count($questions),'date_created'=>current_time('mysql')]);
                }
                $this->capture_quiz_submission($test_id,$session_id,get_current_user_id());
            }
        }
        ob_start();
        echo '<div class="gls-test-fallback"><h3>'.esc_html(get_the_title($test_id) ?: 'آزمون جلسه').'</h3>';
        if($duration>0) echo '<small>زمان پیشنهادی: '.esc_html($duration).' دقیقه</small>';
        echo $this->session_test_result_summary($test_id,get_current_user_id(),$submitted?$correct:null,count($questions));
        $return_url=$this->session_test_return_url($session_id);
        echo '<form method="post" action="'.esc_url($return_url).'">'.wp_nonce_field('gls_otg_'.$test_id,'gls_otg_nonce',true,false).'<input type="hidden" name="gls_otg_test_id" value="'.intval($test_id).'"><input type="hidden" name="gls_otg_submit" value="1"><input type="hidden" name="gls_session_return" value="'.intval($session_id).'">';
        foreach($questions as $i=>$q){
            $type=$q['type']??'multiple_choice';
            echo '<div class="gls-test-question"><div class="gls-test-question-text"><strong>'.intval($i+1).'. </strong>';
            if($type==='fill_in_blank'){
                $name='gls_otg_answer_'.$test_id.'_'.$i;
                $val=esc_attr($results[$i]['answer']??'');
                echo wp_kses_post(str_replace('__________','<input type="text" name="'.esc_attr($name).'" value="'.$val.'" class="gls-form-input" style="min-width:120px;border:0;border-bottom:2px solid #111;background:transparent;text-align:center;">',(string)($q['text']??'')));
            }else{
                echo esc_html($q['text']??'');
            }
            echo '</div>';
            if($type==='multiple_choice'){
                echo '<div class="gls-test-options">';
                foreach((array)($q['options']??[]) as $idx=>$op){
                    $checked=isset($results[$i]['answer']) && (string)$results[$i]['answer']===(string)$idx;
                    $is_correct=$submitted && isset($q['correct']) && intval($q['correct'])===intval($idx);
                    $is_wrong_selected=$submitted && $checked && !$is_correct;
                    $classes=[];
                    if($is_correct)$classes[]='gls-answer-correct';
                    if($is_wrong_selected)$classes[]='gls-answer-wrong';
                    echo '<label class="'.esc_attr(implode(' ',$classes)).'"><input type="radio" name="gls_otg_answer_'.intval($test_id).'_'.intval($i).'" value="'.intval($idx).'" '.checked($checked,true,false).'> <span>'.esc_html($op).'</span>'.($is_correct?'<b class="gls-answer-mark">✓</b>':($is_wrong_selected?'<b class="gls-answer-mark">×</b>':'')).'</label>';
                }
                echo '</div>';
            }
            if($submitted){
                $ok=!empty($results[$i]['ok']);
                echo '<div class="gls-test-feedback '.($ok?'ok':'bad').'">'.($ok?'✓ پاسخ شما درست است':'✕ پاسخ شما نیاز به بررسی دارد');
                if(!empty($q['explanation'])) echo '<div class="gls-answer-explanation">'.esc_html($q['explanation']).'</div>';
                echo '</div>';
            }
            echo '</div>';
        }
        if(!$submitted) echo '<button type="submit" class="gls-btn red">ارسال آزمون</button>';
        else echo '<div class="gls-score">نمره شما: '.esc_html($correct).' از '.esc_html(count($questions)).'</div>';
        echo '</form></div>';
        return ob_get_clean();
    }

    private function homework_box($s){
        $subs=$this->get_user_submissions(intval($s->id));

        echo '<div class="gls-homework-hero"><div><h3>ارسال تکلیف</h3><p>متن جواب را بنویسید و در صورت نیاز عکس، PDF یا فایل ورد اضافه کنید.</p></div><span class="gls-homework-hero-icon">✍️</span></div>';
        // Fix #13: file-type and size validation added via data-attrs + inline JS
        echo '<form class="gls-form gls-homework-form" enctype="multipart/form-data" onsubmit="return glsValidateHomework(this)"><input type="hidden" name="session_id" value="'.intval($s->id).'"><label>متن جواب</label><textarea name="text_content" placeholder="جواب تکلیف را اینجا بنویسید..."></textarea><label>فایل/عکس/PDF</label><div class="gls-upload-zone"><input type="file" name="homework_files[]" multiple accept="image/*,.pdf,.doc,.docx" data-maxsize="10485760" onchange="glsPreviewFiles(this)"><div class="gls-file-preview" style="margin-top:6px;font-size:0.8rem;color:#555;"></div><small>می‌توانید چند فایل را همزمان انتخاب کنید. حداکثر ۱۰ مگابایت، فرمت‌های مجاز: تصویر، PDF، Word.</small></div><div class="gls-file-error" style="display:none;color:#dc3545;font-size:0.85rem;margin-top:6px;font-weight:600;"></div><p><button type="submit" class="gls-btn red">ارسال جواب</button></p></form>';
        echo '<script>
function glsValidateHomework(form){
    var inp=form.querySelector("input[type=file]");
    var errEl=form.querySelector(".gls-file-error");
    if(!inp||!inp.files.length){if(errEl)errEl.style.display="none";return true;}
    var allowed=["image/jpeg","image/png","image/gif","image/webp","application/pdf","application/msword","application/vnd.openxmlformats-officedocument.wordprocessingml.document"];
    var maxSize=10485760;
    for(var i=0;i<inp.files.length;i++){
        var f=inp.files[i];
        if(!allowed.includes(f.type)&&!f.name.match(/\.(jpg|jpeg|png|gif|webp|pdf|doc|docx)$/i)){
            if(errEl){errEl.textContent="فایل «"+f.name+"» مجاز نیست. فقط تصویر، PDF یا Word مجاز است.";errEl.style.display="block";}
            return false;
        }
        if(f.size>maxSize){
            if(errEl){errEl.textContent="حجم فایل «"+f.name+"» بیشتر از ۱۰ مگابایت است.";errEl.style.display="block";}
            return false;
        }
    }
    if(errEl)errEl.style.display="none";
    return true;
}
function glsPreviewFiles(inp){
    var prev=inp.parentNode.querySelector(".gls-file-preview");
    var errEl=inp.closest("form").querySelector(".gls-file-error");
    if(!prev)return;
    if(!inp.files.length){prev.textContent="";return;}
    var names=[];
    for(var i=0;i<inp.files.length;i++) names.push(inp.files[i].name+" ("+Math.round(inp.files[i].size/1024)+" KB)");
    prev.textContent="📎 "+names.join(" | ");
    if(errEl)errEl.style.display="none";
}
</script>';

        if($subs){
            echo '<div class="gls-homework-history"><div class="gls-card-head"><div><h3>ارسال‌های قبلی</h3><small>از جدیدترین به قدیمی‌ترین</small></div><span class="gls-count-badge">'.count($subs).'</span></div><div class="gls-homework-timeline">';
            foreach($subs as $i=>$sub){
                $grade=($sub->grade!==null && $sub->grade!=='') ? esc_html($sub->grade).'/100' : 'بدون نمره';
                $has_feedback=($sub->feedback_text || $sub->grade!==null || $sub->correction_file_ids);
                echo '<article class="gls-homework-entry'.($i===0?' open':'').'">';
                echo '<button type="button" class="gls-homework-toggle"><span class="gls-homework-num">'.intval($sub->attempt_no).'</span><span class="gls-homework-title"><strong>ارسال شماره '.intval($sub->attempt_no).'</strong><small>'.esc_html($sub->submitted_at).'</small></span><span class="gls-status '.esc_attr($sub->status).'">'.esc_html($this->submission_label($sub->status)).'</span><span class="gls-grade-chip">'.esc_html($grade).'</span></button>';
                echo '<div class="gls-homework-body"><div class="gls-homework-answer">'.wpautop(wp_kses_post($sub->text_content)).'</div>';
                $this->file_links($sub->file_ids);

                if($has_feedback){
                    echo '<div class="gls-correction-card"><div class="gls-correction-head"><strong>تصحیح استاد</strong><span>'.esc_html($grade).'</span></div><div class="gls-feedback-view">'.wpautop(wp_kses_post($sub->feedback_text)).'</div>';
                    $this->file_links($sub->correction_file_ids);
                    echo '</div>';
                }

                echo '</div></article>';
            }
            echo '</div></div>';
        }
    }

    public function ajax_save_lesson(){
        check_ajax_referer('gls_nonce','nonce');
        if(!current_user_can('manage_options'))wp_send_json_error('دسترسی غیرمجاز');

        global $wpdb;
        $wpdb->update($this->sessions,[
            'lesson_content'=>wp_kses_post(wp_unslash($_POST['content']??'')),
            'updated_at'=>current_time('mysql')
        ],['id'=>intval($_POST['session_id']??0)]);

        // ذخیره جزوه نباید ایمیل یا اعلان جدیدی برای زبان‌آموز ارسال کند.
        wp_send_json_success();
    }

    public function ajax_add_item(){
        check_ajax_referer('gls_nonce','nonce');
        if(!current_user_can('manage_options'))wp_send_json_error('دسترسی غیرمجاز');

        global $wpdb;
        $sid=intval($_POST['session_id']??0);
        $type=sanitize_key($_POST['item_type']??'resource');
        $old=$this->get_session($sid);

        $max=intval($wpdb->get_var($wpdb->prepare(
            "SELECT MAX(sort_order) FROM {$this->items} WHERE session_id=%d AND item_type=%s",
            $sid,
            $type
        )));

        $wpdb->insert($this->items,[
            'session_id'=>$sid,
            'item_type'=>$type,
            'item_kind'=>sanitize_key($_POST['item_kind']??'file'),
            'title'=>sanitize_text_field($_POST['title']??''),
            'url'=>esc_url_raw($_POST['url']??($_POST['attachment_url']??'')),
            'attachment_id'=>intval($_POST['attachment_id']??0),
            'content'=>wp_kses_post($_POST['content']??''),
            'sort_order'=>$max+1,
            'created_at'=>current_time('mysql'),
            'updated_at'=>current_time('mysql')
        ]);

        $this->notify_if_needed($old,$this->get_session($sid));
        wp_send_json_success();
    }

    public function ajax_delete_item(){
        check_ajax_referer('gls_nonce','nonce');
        if(!current_user_can('manage_options'))wp_send_json_error('دسترسی غیرمجاز');

        global $wpdb;
        $wpdb->delete($this->items,['id'=>intval($_POST['item_id']??0)]);
        wp_send_json_success();
    }

    public function ajax_reorder_items(){
        check_ajax_referer('gls_nonce','nonce');
        if(!current_user_can('manage_options'))wp_send_json_error('دسترسی غیرمجاز');

        global $wpdb;
        $ids=array_map('intval',(array)($_POST['ids']??[]));
        $i=1;
        foreach($ids as $id)$wpdb->update($this->items,['sort_order'=>$i++],['id'=>$id]);
        wp_send_json_success();
    }

    public function ajax_submit_homework(){
        check_ajax_referer('gls_nonce','nonce');
        if(!is_user_logged_in())wp_send_json_error('ابتدا وارد شوید.');

        $sid=intval($_POST['session_id']??0);
        $s=$this->get_session($sid);
        if(!$this->can_view($s))wp_send_json_error('دسترسی غیرمجاز');

        global $wpdb;
        $attempt=intval($wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->subs} WHERE session_id=%d AND user_id=%d",
            $sid,
            get_current_user_id()
        )))+1;

        $files=$this->upload_files('homework_files');

        $wpdb->insert($this->subs,[
            'session_id'=>$sid,
            'user_id'=>get_current_user_id(),
            'attempt_no'=>$attempt,
            'text_content'=>wp_kses_post($_POST['text_content']??''),
            'file_ids'=>wp_json_encode($files),
            'status'=>'submitted',
            'submitted_at'=>current_time('mysql'),
            'updated_at'=>current_time('mysql')
        ]);

        $set=$this->settings();
        $this->mail_log(
            $s,
            'homework_submitted',
            $set['teacher_email'],
            'تکلیف جدید دریافت شد',
            "یک تکلیف جدید برای جلسه {$s->title} دریافت شد.\nزبان‌آموز: {$s->student_name}\nتاریخ: {$s->jalali_date}"
        );

        wp_send_json_success();
    }

    private function upload_files($field){
        if(empty($_FILES[$field]))return [];

        require_once ABSPATH.'wp-admin/includes/file.php';
        require_once ABSPATH.'wp-admin/includes/media.php';
        require_once ABSPATH.'wp-admin/includes/image.php';

        $ids=[];
        $f=$_FILES[$field];
        $n=is_array($f['name'])?count($f['name']):0;

        for($i=0;$i<$n;$i++){
            if(empty($f['name'][$i]))continue;

            $_FILES['gls_single_file']=[
                'name'=>$f['name'][$i],
                'type'=>$f['type'][$i],
                'tmp_name'=>$f['tmp_name'][$i],
                'error'=>$f['error'][$i],
                'size'=>$f['size'][$i]
            ];

            $id=media_handle_upload('gls_single_file',0);
            if(!is_wp_error($id))$ids[]=intval($id);
        }

        unset($_FILES['gls_single_file']);
        return $ids;
    }

    private function file_links($json){
        $ids=json_decode($json,true);
        if(!is_array($ids)||!$ids)return;

        echo '<div class="gls-file-list">';
        foreach($ids as $id){
            $url=wp_get_attachment_url(intval($id));
            if($url)echo '<a class="gls-file-chip" target="_blank" href="'.esc_url($url).'">'.esc_html(get_the_title($id)?:'فایل').'</a>';
        }
        echo '</div>';
    }

    private function rank($uid){
        global $wpdb;

        $u=get_userdata($uid);
        $email=$u?$u->user_email:'';

        $sessions=$wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->sessions}
             WHERE (user_id=%d OR student_email=%s) AND session_status='published'",
            intval($uid),
            $email
        ));

        if(!$sessions)return ['score'=>0,'label'=>'شروع مسیر'];

        $ids=implode(',',array_map('intval',wp_list_pluck($sessions,'id')));
        $avg=$ids?$wpdb->get_var("SELECT AVG(grade) FROM {$this->subs} WHERE session_id IN ($ids) AND grade IS NOT NULL"):null;
        $hw=$avg===null?0:floatval($avg);

        $present=0;
        $marked=0;
        foreach($sessions as $s){
            if($s->attendance_status==='present'){
                $present++;
                $marked++;
            } elseif($s->attendance_status==='absent'){
                $marked++;
            }
        }

        $att=$marked?($present/$marked*100):80;
        $has_tests=$this->has_assigned_tests($sessions);
        $test=$this->test_score($uid,$sessions);
        $set=$this->settings();
        $lesson_weight=intval($set['lesson_weight']);
        $test_weight=$has_tests?intval($set['test_weight']):0;
        $attendance_weight=intval($set['attendance_weight']);
        $sum=max(1,$lesson_weight+$test_weight+$attendance_weight);

        $score=round(($hw*$lesson_weight+$test*$test_weight+$att*$attendance_weight)/$sum);

        return [
            'score'=>$score,
            'label'=>$score>=90?'عالی':($score>=75?'خیلی خوب':($score>=60?'خوب':'نیاز به تلاش بیشتر'))
        ];
    }

    private function test_score($uid,$sessions){
        global $wpdb;

        $table=$wpdb->prefix.'otg_results';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table)return 0;

        $ids=[];
        foreach($sessions as $s){
            if(preg_match('/id=["\']?(\d+)/',(string)$s->test_shortcode,$m))$ids[]=intval($m[1]);
            elseif(is_numeric(trim((string)$s->test_shortcode)))$ids[]=intval($s->test_shortcode);
        }

        if(!$ids)return 0;

        $sql=implode(',',array_unique(array_filter($ids)));
        $rows=$wpdb->get_results($wpdb->prepare(
            "SELECT score,total FROM $table WHERE user_id=%d AND test_id IN ($sql)",
            intval($uid)
        ));

        if(!$rows)return 0;

        $sum=0;
        $n=0;
        foreach($rows as $r){
            if(floatval($r->total)>0){
                $sum+=(floatval($r->score)/floatval($r->total))*100;
                $n++;
            }
        }

        return $n?($sum/$n):0;
    }

    private function has_assigned_tests($sessions){
        foreach((array)$sessions as $s){
            if(trim((string)($s->test_shortcode??''))!=='') return true;
        }
        return false;
    }

    private function session_test_return_url($session_id){
        $url=remove_query_arg(['gls_tab','login','loggedout','wp_lang']);
        if(intval($session_id)>0) $url=add_query_arg(['gls_session'=>intval($session_id),'gls_tab'=>'gls-test'],$url);
        return $url.'#gls-test';
    }

    private function decorate_session_test_output($html,$test_id,$session_id){
        $html=(string)$html;
        $return_url=$this->session_test_return_url($session_id);
        $html=preg_replace('/<form\b([^>]*\bid=["\']otg-test-form["\'][^>]*)>/i','<form$1 action="'.esc_attr($return_url).'">',$html,1);
        if(strpos($html,'id="otg-test-form"')!==false && strpos($html,'gls_session_return')===false){
            $hidden='<input type="hidden" name="gls_session_return" value="'.intval($session_id).'">';
            $html=preg_replace('/(<form\b[^>]*\bid=["\']otg-test-form["\'][^>]*>)/i','$1'.$hidden,$html,1);
        }
        $submitted=($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['otg_submit'],$_POST['otg_test_id']) && intval($_POST['otg_test_id'])===intval($test_id));
        $summary=$this->session_test_result_summary($test_id,get_current_user_id(),null,null);
        if($summary!==''){
            $html=preg_replace('/(<div\b[^>]*class=["\'][^"\']*otg-test-container[^"\']*["\'][^>]*>)/i','$1'.$summary,$html,1);
        }
        if($submitted){
            $this->capture_quiz_submission($test_id,$session_id,get_current_user_id());
            $html.='<script>document.addEventListener(\'DOMContentLoaded\',function(){  document.querySelectorAll(\'.gls-test-wrap .otg-question\').forEach(function(q){    var info=q.querySelector(\'.otg-correct-answer-info\');    if(!info)return;    var txt=(info.textContent||\'\').replace(/^\s*(Correct answer:|پاسخ درست:)\s*/i,\'\').trim();    q.querySelectorAll(\'.otg-option-item\').forEach(function(opt){      var t=opt.querySelector(\'.otg-option-text\');      if(t && t.textContent.trim()===txt){opt.classList.add(\'otg-mc-correct\');if(!opt.querySelector(\'.gls-answer-mark\')){var m=document.createElement(\'b\');m.className=\'gls-answer-mark\';m.textContent=\'✓\';opt.appendChild(m);}}    });  });});</script><script>(function(){var el=document.getElementById("gls-test");if(el){setTimeout(function(){el.scrollIntoView({behavior:"smooth",block:"start"});},120);}})();</script>';
        }
        return $html;
    }

    private function session_test_result_summary($test_id,$uid,$current_score=null,$current_total=null){
        if(!$uid) return '';
        global $wpdb;
        $table=$wpdb->prefix.'otg_results';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$table))!==$table) return '';
        $rows=$wpdb->get_results($wpdb->prepare("SELECT score,total,date_created FROM $table WHERE user_id=%d AND test_id=%d ORDER BY date_created DESC,id DESC LIMIT 10",intval($uid),intval($test_id)));
        if(!$rows && $current_score===null) return '';
        $latest=$rows?$rows[0]:null;
        $score=$current_score!==null?intval($current_score):intval($latest->score??0);
        $total=$current_total!==null?intval($current_total):intval($latest->total??0);
        $pct=$total>0?round(($score/$total)*100):0;
        $html='<div class="gls-test-result-hero"><div><span class="gls-test-result-kicker">نتیجه آزمونک</span><strong>'.$this->fa_num($score).' از '.$this->fa_num($total).'</strong><small>'.$this->fa_num($pct).'% پاسخ درست</small></div><div class="gls-test-result-ring" style="--test-score:'.intval($pct).'"><span>'.$this->fa_num($pct).'%</span></div></div>';
        if($rows){
            $html.='<details class="gls-test-history"'.(count($rows)>1?'':' open').'><summary>سابقه آزمونک‌ها <span>'.count($rows).'</span></summary><div class="gls-test-history-list">';
            foreach($rows as $r){
                $rpct=floatval($r->total)>0?round((floatval($r->score)/floatval($r->total))*100):0;
                $date=date_i18n('Y/m/d H:i',strtotime($r->date_created));
                $html.='<div><time>'.$this->fa_num($date).'</time><b>'.$this->fa_num($r->score).' / '.$this->fa_num($r->total).'</b><span>'.$this->fa_num($rpct).'%</span></div>';
            }
            $html.='</div></details>';
        }
        return $html;
    }

    private function fa_num($value){
        $value=(string)$value;
        $value=str_replace('.0','',$value);
        return strtr($value,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹','.'=>'٫']);
    }



    private function quiz_questions_payload($test_id){
        $json=get_post_meta(intval($test_id),'_otg_questions_json',true);
        $data=json_decode((string)$json,true);
        return (!empty($data['questions']) && is_array($data['questions'])) ? $data['questions'] : [];
    }

    private function capture_quiz_submission($test_id,$session_id,$uid){
        global $wpdb;
        $uid=intval($uid); $test_id=intval($test_id); $session_id=intval($session_id);
        if(!$uid || !$test_id) return;
        $this->tables();
        $results=$wpdb->prefix.'otg_results';
        if($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$results))!==$results) return;
        $result=$wpdb->get_row($wpdb->prepare("SELECT * FROM $results WHERE user_id=%d AND test_id=%d ORDER BY id DESC LIMIT 1",$uid,$test_id));
        if(!$result) return;
        $exists=$wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->quiz_reports} WHERE result_id=%d",intval($result->id)));
        if($exists) return;
        $questions=$this->quiz_questions_payload($test_id);
        $answers=[];
        foreach($questions as $i=>$q){
            $type=(string)($q['type']??'multiple_choice');
            $raw='';
            if(isset($_POST['answer_'.$i])) $raw=sanitize_text_field(wp_unslash($_POST['answer_'.$i]));
            elseif(isset($_POST['gls_otg_answer_'.$test_id.'_'.$i])) $raw=sanitize_text_field(wp_unslash($_POST['gls_otg_answer_'.$test_id.'_'.$i]));
            $correct_answer=''; $user_answer=$raw; $ok=false;
            if($type==='multiple_choice'){
                $opts=(array)($q['options']??[]); $ci=intval($q['correct']??-1);
                $correct_answer=isset($opts[$ci])?(string)$opts[$ci]:'';
                $user_answer=(isset($opts[intval($raw)])?(string)$opts[intval($raw)]:$raw);
                $ok=((string)$raw===(string)$ci);
            }else{
                $correct_answer=(string)($q['correct_answer']??'');
                $clean=mb_strtolower(trim((string)$raw));
                foreach((array)($q['acceptable_answers']??[]) as $a){
                    $v=is_array($a)?($a['answer']??''):$a;
                    if(mb_strtolower(trim((string)$v))===$clean){$ok=true;break;}
                }
            }
            $answers[]=[
                'question'=>wp_strip_all_tags((string)($q['text']??'')),
                'type'=>$type,
                'user_answer'=>$user_answer,
                'correct_answer'=>$correct_answer,
                'correct'=>$ok,
                'explanation'=>wp_strip_all_tags((string)($q['explanation']??''))
            ];
        }
        $wpdb->insert($this->quiz_reports,[
            'result_id'=>intval($result->id),'user_id'=>$uid,'session_id'=>$session_id,'test_id'=>$test_id,
            'score'=>floatval($result->score),'total'=>floatval($result->total),
            'answers_json'=>wp_json_encode($answers,JSON_UNESCAPED_UNICODE),
            'analysis_status'=>'pending','created_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')
        ]);
        $report_id=intval($wpdb->insert_id);
        if(!$report_id) return;

        // تحلیل و ایمیل در پس‌زمینه اجرا می‌شوند تا زبان‌آموز منتظر پاسخ GapGPT نماند.
        $args=[$report_id];
        if(!wp_next_scheduled(self::CRON_QUIZ_ANALYSIS,$args)){
            $scheduled=wp_schedule_single_event(time()+8,self::CRON_QUIZ_ANALYSIS,$args);
            if($scheduled && function_exists('spawn_cron')) spawn_cron(time());
        }
    }

    public function process_quiz_report_async($report_id){
        global $wpdb;
        $report_id=intval($report_id);
        if(!$report_id) return;
        $report=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->quiz_reports} WHERE id=%d",$report_id));
        if(!$report || $report->analysis_status==='done' || $report->emailed_at) return;
        $answers=json_decode((string)$report->answers_json,true)?:[];
        $analysis=$this->generate_quiz_analysis($report_id,$answers);
        $this->send_quiz_result_email($report_id,$analysis);
    }

    private function generate_quiz_analysis($report_id,$answers){
        global $wpdb;
        $report=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->quiz_reports} WHERE id=%d",intval($report_id)));
        if(!$report) return '';
        $session=$this->get_session($report->session_id);
        $user=get_userdata($report->user_id);
        $lessons=$wpdb->get_results($wpdb->prepare("SELECT lesson_content,ai_reading_content,title,booking_date FROM {$this->sessions} WHERE (user_id=%d OR student_email=%s) AND session_status='published' ORDER BY booking_date ASC,id ASC",intval($report->user_id),$user?$user->user_email:''));
        $context='';
        foreach((array)$lessons as $l){
            $chunk=trim(wp_strip_all_tags((string)$l->lesson_content.' '.(string)$l->ai_reading_content));
            if($chunk!=='') $context.="\nجلسه {$l->title}: ".mb_substr($chunk,0,2200);
            if(mb_strlen($context)>12000) break;
        }
        $wrong=[];
        foreach($answers as $a) if(empty($a['correct'])) $wrong[]=$a;
        if(!$wrong){
            $html='<div class="gls-quiz-analysis"><h3>تحلیل آموزشی</h3><p>عملکرد این آزمون بسیار خوب بوده و پاسخ نادرست معناداری برای تحلیل ضعف ثبت نشده است.</p></div>';
            $wpdb->update($this->quiz_reports,['analysis_html'=>$html,'analysis_status'=>'done','updated_at'=>current_time('mysql')],['id'=>intval($report_id)]);
            return $html;
        }
        if(!$this->ai_enabled()){
            $html='<div class="gls-quiz-analysis"><h3>تحلیل آموزشی</h3><p>تحلیل خودکار در دسترس نبود. پاسخ‌های نادرست در گزارش زیر ثبت شده‌اند.</p></div>';
            $wpdb->update($this->quiz_reports,['analysis_html'=>$html,'analysis_status'=>'unavailable','updated_at'=>current_time('mysql')],['id'=>intval($report_id)]);
            return $html;
        }
        $payload=wp_json_encode($wrong,JSON_UNESCAPED_UNICODE);
        $prompt="تو یک مدرس حرفه‌ای زبان آلمانی هستی. براساس پاسخ‌های نادرست این آزمونک و تمام جزوه‌های قبلی همین زبان‌آموز، یک تحلیل عمیق اما کاربردی به فارسی بنویس. ضعف‌های احتمالی، الگوهای خطا، تفاوت بی‌دقتی با ضعف مفهومی، مباحثی که نیاز به مرور دارند و پیشنهاد تمرین بعدی را مشخص کن. ادعاهایی نکن که از داده‌ها قابل استنباط نیست. خروجی فقط HTML تمیز با تیترهای کوتاه و لیست باشد، بدون Markdown و بدون اشاره به هوش مصنوعی. پاسخ‌های نادرست: {$payload}\nمحتوای آموزشی قبلی: ".mb_substr($context,0,12000);
        $settings=$this->settings();
        $raw=$this->ai_call_chat([
            ['role'=>'system','content'=>'You are a careful German teacher. Return Persian HTML only.'],
            ['role'=>'user','content'=>$prompt]
        ],$settings['ai_model_analysis'],'quiz_analysis',intval($report->session_id),0,0.2);
        if(is_wp_error($raw)){
            $html='<div class="gls-quiz-analysis"><h3>تحلیل آموزشی</h3><p>تحلیل خودکار در این لحظه کامل نشد؛ پاسخ‌های آزمون در گزارش ذخیره شده‌اند.</p></div>';
            $status='error';
        }else{
            $html='<div class="gls-quiz-analysis"><h3>تحلیل آموزشی</h3>'.wp_kses_post((string)$raw).'</div>';
            $status='done';
        }
        $wpdb->update($this->quiz_reports,['analysis_html'=>$html,'analysis_status'=>$status,'updated_at'=>current_time('mysql')],['id'=>intval($report_id)]);
        return $html;
    }

    private function send_quiz_result_email($report_id,$analysis=''){
        global $wpdb;
        $r=$wpdb->get_row($wpdb->prepare("SELECT qr.*,s.title session_title,s.student_name,s.student_email FROM {$this->quiz_reports} qr LEFT JOIN {$this->sessions} s ON s.id=qr.session_id WHERE qr.id=%d",intval($report_id)));
        if(!$r || $r->emailed_at) return;
        $settings=$this->settings(); $to=sanitize_email($settings['teacher_email']?:get_option('admin_email'));
        if(!$to) return;
        $answers=json_decode((string)$r->answers_json,true)?:[];
        $pct=floatval($r->total)>0?round(floatval($r->score)/floatval($r->total)*100):0;
        $body='<div dir="rtl" style="font-family:Tahoma,Arial,sans-serif;line-height:1.9"><h2>نتیجه آزمونک زبان‌آموز</h2><p><strong>زبان‌آموز:</strong> '.esc_html($r->student_name ?: $r->student_email).'<br><strong>جلسه:</strong> '.esc_html($r->session_title).'<br><strong>نمره:</strong> '.esc_html($r->score).' از '.esc_html($r->total).' ('.esc_html($pct).'٪)</p>';
        $body.='<table style="width:100%;border-collapse:collapse"><thead><tr><th style="border:1px solid #ddd;padding:8px">سؤال</th><th style="border:1px solid #ddd;padding:8px">پاسخ زبان‌آموز</th><th style="border:1px solid #ddd;padding:8px">پاسخ درست</th><th style="border:1px solid #ddd;padding:8px">وضعیت</th></tr></thead><tbody>';
        foreach($answers as $a){
            $body.='<tr><td dir="ltr" style="border:1px solid #ddd;padding:8px;text-align:left">'.esc_html($a['question']??'').'</td><td dir="ltr" style="border:1px solid #ddd;padding:8px;text-align:left">'.esc_html($a['user_answer']??'').'</td><td dir="ltr" style="border:1px solid #ddd;padding:8px;text-align:left">'.esc_html($a['correct_answer']??'').'</td><td style="border:1px solid #ddd;padding:8px">'.(!empty($a['correct'])?'درست':'نادرست').'</td></tr>';
        }
        $body.='</tbody></table>'.wp_kses_post($analysis).'</div>';
        $mail_type=function(){return 'text/html';};
        add_filter('wp_mail_content_type',$mail_type);
        $sent=wp_mail($to,'نتیجه آزمونک - '.($r->student_name?:$r->student_email),$body);
        remove_filter('wp_mail_content_type',$mail_type);
        if($sent) $wpdb->update($this->quiz_reports,['emailed_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')],['id'=>intval($report_id)]);
    }

    public function admin_quiz_results(){
        if(!current_user_can('manage_options')) return;
        global $wpdb; $this->tables();
        $view=isset($_GET['view'])?intval($_GET['view']):0;
        echo '<div class="wrap gls-wrap gls-admin"><div class="gls-top"><div><h1>نتایج آزمونک‌ها</h1><small>نتیجه، پاسخ‌ها و تحلیل آموزشی</small></div></div><div class="gls-main">';
        if($view){
            $r=$wpdb->get_row($wpdb->prepare("SELECT qr.*,s.title session_title,s.student_name,s.student_email FROM {$this->quiz_reports} qr LEFT JOIN {$this->sessions} s ON s.id=qr.session_id WHERE qr.id=%d",$view));
            if(!$r){echo '<div class="gls-empty">نتیجه پیدا نشد.</div></div></div>';return;}
            $answers=json_decode((string)$r->answers_json,true)?:[]; $pct=$r->total>0?round($r->score/$r->total*100):0;
            echo '<a class="gls-btn light" href="'.esc_url(admin_url('admin.php?page=gls_quiz_results')).'">بازگشت</a><div class="gls-test-result-hero"><div><span class="gls-test-result-kicker">'.esc_html($r->student_name?:$r->student_email).'</span><strong>'.$this->fa_num($r->score).' از '.$this->fa_num($r->total).'</strong><small>'.$this->fa_num($pct).'% پاسخ درست</small></div></div>';
            if(trim((string)$r->analysis_html)!=='') echo wp_kses_post($r->analysis_html);
            else echo '<div class="gls-box gls-quiz-analysis"><h3>تحلیل آموزشی</h3><p>تحلیل در پس‌زمینه در حال آماده‌سازی است. این صفحه را کمی بعد تازه‌سازی کنید.</p></div>';
            echo '<div class="gls-box"><h3>'.esc_html($r->session_title).'</h3><div class="gls-quiz-admin-answers">';
            foreach($answers as $i=>$a){echo '<article class="'.(!empty($a['correct'])?'ok':'bad').'"><h4 dir="ltr">'.intval($i+1).'. '.esc_html($a['question']??'').'</h4><p dir="ltr"><b>پاسخ زبان‌آموز:</b> '.esc_html($a['user_answer']??'').'</p><p dir="ltr"><b>پاسخ درست:</b> '.esc_html($a['correct_answer']??'').'</p>'.(!empty($a['explanation'])?'<small>'.esc_html($a['explanation']).'</small>':'').'</article>';}
            echo '</div></div></div></div>'; return;
        }
        $rows=$wpdb->get_results("SELECT qr.*,s.title session_title,s.student_name,s.student_email FROM {$this->quiz_reports} qr LEFT JOIN {$this->sessions} s ON s.id=qr.session_id ORDER BY qr.created_at DESC LIMIT 300");
        if(!$rows){echo '<div class="gls-empty">هنوز نتیجه آزمونکی ثبت نشده است.</div></div></div>';return;}
        echo '<table class="gls-table"><thead><tr><th>زبان‌آموز</th><th>جلسه</th><th>نمره</th><th>تاریخ</th><th></th></tr></thead><tbody>';
        foreach($rows as $r){$pct=$r->total>0?round($r->score/$r->total*100):0;echo '<tr><td>'.esc_html($r->student_name?:$r->student_email).'</td><td>'.esc_html($r->session_title).'</td><td>'.$this->fa_num($r->score).' / '.$this->fa_num($r->total).' ('.$this->fa_num($pct).'٪)</td><td>'.$this->fa_num(date_i18n('Y/m/d H:i',strtotime($r->created_at))).'</td><td><a class="gls-btn" href="'.esc_url(admin_url('admin.php?page=gls_quiz_results&view='.intval($r->id))).'">مشاهده</a></td></tr>';}
        echo '</tbody></table></div></div>';
    }

    public function register_dashboard_widgets(){
        if(!current_user_can('manage_options')) return;
        wp_add_dashboard_widget('gls_dash_upcoming','رزروهای امروز و فردا',[$this,'dashboard_upcoming_widget']);
        wp_add_dashboard_widget('gls_dash_quizzes','پنج نتیجه آخر آزمونک‌ها',[$this,'dashboard_quiz_widget']);
        wp_add_dashboard_widget('gls_dash_teacher','عملکرد ماه جاری',[$this,'dashboard_teacher_widget']);
    }

    private function dashboard_widget_css(){
        static $printed=false;
        if($printed) return;
        $printed=true;
        echo '<style>'.$this->font_css().'.gls-dw-list,.gls-dw-list *,.gls-dw-stats,.gls-dw-stats *{font-family:"IRANSansXFaNum",Tahoma,Arial,sans-serif!important}.gls-dw-list{display:grid;gap:9px}.gls-dw-item{display:flex;justify-content:space-between;gap:10px;padding:10px 12px;border:1px solid #e7e9ef;border-radius:12px;text-decoration:none;color:#111;background:#fff}.gls-dw-item:hover{border-color:#dd0000}.gls-dw-item small{color:#667085}.gls-dw-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.gls-dw-stat{padding:14px 8px;text-align:center;border-radius:14px;background:#f6f7f9}.gls-dw-stat b{display:block;font-size:22px;color:#111}.gls-dw-stat:nth-child(2){background:#fff1f1}.gls-dw-stat:nth-child(3){background:#fff8d9}</style>';
    }

    public function dashboard_upcoming_widget(){
        global $wpdb; $this->dashboard_widget_css(); $today=current_time('Y-m-d'); $tomorrow=date('Y-m-d',strtotime($today.' +1 day'));
        $rows=$wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->bookings} WHERE booking_date IN (%s,%s) AND status='confirmed' ORDER BY booking_date ASC,booking_time ASC",$today,$tomorrow));
        if(!$rows){echo '<p>رزروی برای امروز یا فردا وجود ندارد.</p>';return;}
        echo '<div class="gls-dw-list">'; foreach($rows as $r){$jdate=$this->g2j_string($r->booking_date);echo '<div class="gls-dw-item"><div><b>'.esc_html(trim(($r->first_name??'').' '.($r->last_name??''))).'</b><br><small>'.esc_html($r->class_name??'').'</small></div><div><b>'.esc_html($this->fa_num($jdate)).'</b><br><small>'.esc_html($r->booking_time).'</small></div></div>';} echo '</div>';
    }

    public function dashboard_quiz_widget(){
        global $wpdb; $this->dashboard_widget_css(); $this->tables();
        $rows=$wpdb->get_results("SELECT qr.*,s.student_name,s.student_email,s.title session_title FROM {$this->quiz_reports} qr LEFT JOIN {$this->sessions} s ON s.id=qr.session_id ORDER BY qr.created_at DESC LIMIT 5");
        if(!$rows){echo '<p>هنوز نتیجه‌ای ثبت نشده است.</p>';return;} echo '<div class="gls-dw-list">';
        foreach($rows as $r){$pct=$r->total>0?round($r->score/$r->total*100):0;echo '<a class="gls-dw-item" href="'.esc_url(admin_url('admin.php?page=gls_quiz_results&view='.intval($r->id))).'"><div><b>'.esc_html($r->student_name?:$r->student_email).'</b><br><small>'.esc_html($r->session_title).'</small></div><div><b>'.$this->fa_num($pct).'٪</b><br><small>'.$this->fa_num(date_i18n('m/d H:i',strtotime($r->created_at))).'</small></div></a>';} echo '</div>';
    }

    public function dashboard_teacher_widget(){
        global $wpdb; $this->dashboard_widget_css(); $today=current_time('Y-m-d'); [$jy,$jm,$jd]=$this->g2j(intval(substr($today,0,4)),intval(substr($today,5,2)),intval(substr($today,8,2)));
        $rows=$wpdb->get_results("SELECT booking_date,status FROM {$this->bookings} WHERE booking_date >= DATE_SUB(CURDATE(),INTERVAL 45 DAY) AND booking_date <= DATE_ADD(CURDATE(),INTERVAL 45 DAY)");
        $month=0;$booked=0;$held=0;
        foreach((array)$rows as $r){[$ry,$rm,$rd]=$this->g2j(intval(substr($r->booking_date,0,4)),intval(substr($r->booking_date,5,2)),intval(substr($r->booking_date,8,2))); if($ry!=$jy||$rm!=$jm)continue; $month++; if($r->status==='confirmed')$booked++; if($r->status==='confirmed' && $r->booking_date<=$today)$held++;}
        echo '<div class="gls-dw-stats"><div class="gls-dw-stat"><b>'.$this->fa_num($month).'</b><span>کل کلاس‌های ماه</span></div><div class="gls-dw-stat"><b>'.$this->fa_num($booked).'</b><span>رزرو قطعی</span></div><div class="gls-dw-stat"><b>'.$this->fa_num($held).'</b><span>برگزارشده</span></div></div>';
    }

    private function ai_model_options($selected=''){
        $models=[
            'gapgpt-qwen-3.5'=>'GapGPT Qwen 3.5 - اقتصادی',
            'gapgpt-qwen-3.5-thinking'=>'GapGPT Qwen 3.5 Thinking',
            'gapgpt-qwen-3.6'=>'GapGPT Qwen 3.6',
            'gemini-2.5-flash-lite'=>'Gemini 2.5 Flash Lite - سریع',
            'gemini-2.5-flash'=>'Gemini 2.5 Flash - متن و تصویر',
            'gemini-2.5-pro'=>'Gemini 2.5 Pro - دقیق‌تر',
            'gemini-3-flash-preview'=>'Gemini 3 Flash Preview',
            'gemini-3-pro-preview'=>'Gemini 3 Pro Preview'
        ];
        $out='';
        foreach($models as $id=>$label){
            $out.='<option value="'.esc_attr($id).'" '.selected($selected,$id,false).'>'.esc_html($label).'</option>';
        }
        return $out;
    }

    private function mask_ai_key($key){
        $key=(string)$key;
        if($key==='') return 'ثبت نشده';
        return str_repeat('•',8).substr($key,-4);
    }

    private function sanitize_ai_key_input($input){
        $input=trim((string)$input);
        $old=$this->settings();
        return $input==='' ? ($old['ai_api_key'] ?? '') : sanitize_text_field($input);
    }

    private function ai_enabled(){
        $s=$this->settings();
        $key=trim((string)($s['ai_api_key'] ?? ''));
        // از نسخه 12.14 وجود API Key معتبر برای فعال بودن دستیار کافی است.
        // در نسخه‌های قبلی اگر ai_enabled با مقدار 0 ذخیره شده بود، کل مسیرها خاموش می‌شدند.
        return $key !== '';
    }

    private function ai_call_chat($messages,$model,$type='general',$session_id=0,$submission_id=0,$temperature=0.25){
        $settings=$this->settings();
        if(!$this->ai_enabled()) return new WP_Error('gls_ai_disabled','دستیار آموزشی فعال نیست یا API Key وارد نشده است.');
        $base=rtrim($settings['ai_base_url'] ?: 'https://api.gapgpt.app/v1','/');
        $payload=[
            'model'=>$model,
            'messages'=>$messages,
            'temperature'=>$temperature
        ];
        $res=wp_remote_post($base.'/chat/completions',[
            'timeout'=>90,
            'headers'=>[
                'Content-Type'=>'application/json',
                'Authorization'=>'Bearer '.$settings['ai_api_key']
            ],
            'body'=>wp_json_encode($payload)
        ]);
        if(is_wp_error($res)){
            $this->ai_log($type,$model,'error','',$res->get_error_message(),$session_id,$submission_id);
            return $res;
        }
        $code=wp_remote_retrieve_response_code($res);
        $body=wp_remote_retrieve_body($res);
        if($code<200 || $code>=300){
            $this->ai_log($type,$model,'error','',$body,$session_id,$submission_id);
            return new WP_Error('gls_ai_http','خطای ارتباط با API: '.$code.' - '.wp_strip_all_tags($body));
        }
        $json=json_decode($body,true);
        $content=$json['choices'][0]['message']['content'] ?? '';
        if(is_array($content)){
            $buf='';
            foreach($content as $part){
                if(is_array($part) && isset($part['text'])) $buf.=$part['text'];
                elseif(is_string($part)) $buf.=$part;
            }
            $content=$buf;
        }
        if($content==='' && !empty($json['choices'][0]['message']['reasoning_content'])) $content=(string)$json['choices'][0]['message']['reasoning_content'];
        if($content===''){
            $this->ai_log($type,$model,'error','',$body,$session_id,$submission_id);
            return new WP_Error('gls_ai_empty','پاسخ معتبر از مدل دریافت نشد.');
        }
        $this->ai_log($type,$model,'success',wp_json_encode($messages),wp_trim_words(wp_strip_all_tags((string)$content),80),$session_id,$submission_id);
        return (string)$content;
    }

    private function ai_log($type,$model,$status,$prompt,$response,$session_id=0,$submission_id=0){
        global $wpdb;
        if(empty($this->ai_logs)) return;
        $wpdb->insert($this->ai_logs,[
            'user_id'=>get_current_user_id(),
            'session_id'=>intval($session_id),
            'submission_id'=>intval($submission_id),
            'request_type'=>sanitize_key($type),
            'model'=>sanitize_text_field($model),
            'status'=>sanitize_key($status),
            'prompt_hash'=>$prompt?md5($prompt):'',
            'response_excerpt'=>is_string($response)?wp_strip_all_tags($response):'',
            'error_message'=>$status==='error'?(is_string($response)?wp_strip_all_tags($response):''):'',
            'created_at'=>current_time('mysql')
        ]);
    }

    private function ai_extract_json($text){
        $text=trim((string)$text);
        $text=preg_replace('/^```(?:json)?\s*/u','',$text);
        $text=preg_replace('/\s*```$/u','',$text);
        $data=json_decode($text,true);
        if(is_array($data)) return $data;
        if(preg_match('/\{.*\}/su',$text,$m)){
            $data=json_decode($m[0],true);
            if(is_array($data)) return $data;
        }
        return [];
    }

    private function ai_generate_quiz_for_session($session_id,$topic,$mc_count=20,$fib_count=5){
        global $wpdb;
        $session=$this->get_session($session_id);
        if(!$session) return new WP_Error('quiz_session_missing','جلسه پیدا نشد.');
        if(trim((string)$session->test_shortcode)!=='') return new WP_Error('quiz_exists','برای این جلسه قبلاً آزمونک ثبت شده است.');
        $topic=trim((string)$topic);
        if($topic==='') return new WP_Error('quiz_topic_missing','موضوع آزمونک را وارد کنید.');
        $mc_count=max(0,intval($mc_count));
        $fib_count=max(0,intval($fib_count));
        $total=$mc_count+$fib_count;
        if($total<1) return new WP_Error('quiz_count_invalid','مجموع سؤال‌ها باید حداقل ۱ باشد.');
        if(!$this->ai_enabled()) return new WP_Error('quiz_ai_disabled','API Key دستیار آموزشی ثبت نشده است.');

        $current=trim(wp_strip_all_tags((string)$session->lesson_content));
        if($current==='') return new WP_Error('quiz_lesson_empty','جزوه فعلی خالی است. ابتدا متن جزوه را بنویسید.');

        $user=get_userdata(intval($session->user_id));
        $email=$session->student_email ?: ($user?$user->user_email:'');
        $previous=$wpdb->get_results($wpdb->prepare(
            "SELECT id,title,jalali_date,booking_date,teacher_level,lesson_content FROM {$this->sessions} WHERE id<>%d AND (user_id=%d OR student_email=%s) AND attendance_status='present' AND session_status='published' AND booking_date<=%s AND lesson_content IS NOT NULL AND lesson_content<>'' ORDER BY booking_date ASC,booking_time ASC,id ASC",
            intval($session_id),intval($session->user_id),$email,$session->booking_date
        ));

        $previous_context='';
        $budget=42000;
        foreach((array)$previous as $row){
            $txt=trim(wp_strip_all_tags((string)$row->lesson_content));
            if($txt==='') continue;
            $piece="\n--- جلسه قبلی: {$row->title} | تاریخ {$row->jalali_date} | سطح ".($row->teacher_level?:'ثبت‌نشده')." ---\n".mb_substr($txt,0,6000);
            if(mb_strlen($previous_context.$piece)>$budget) break;
            $previous_context.=$piece;
        }
        $current_context=mb_substr($current,0,18000);
        $level=$session->teacher_level ?: 'از متن جزوه فعلی تشخیص بده، بدون عبور از آن';
        // نسخه ۱۴.۶: قبلاً این عبارت داخل heredoc نوشته شده بود و اجرا نمی‌شد (کد PHP به‌صورت متن وارد پرامپت می‌شد).
        $previous_block = ($previous_context !== '') ? $previous_context : 'هیچ جلسه قبلیِ حاضرشده‌ای با جزوه ثبت نشده است.';

        $system=<<<'SYSTEM'
تو یک طراح آزمون تخصصی زبان آلمانی برای کلاس خصوصی هستی. هدف: ساخت آزمونکی که دقیقاً آنچه در این جلسه و جلسات قبلیِ مجاز تدریس شده را ارزیابی کند - نه بیشتر، نه کمتر.

## قانون اساسی: شاهد جزوه
پیش از ساخت هر سؤال، یک شاهد صریح (قاعده، ساختار یا منطق آموزشی) از جزوه‌ها پیدا کن.
- شاهد پیدا شد → سؤال مجاز است
- شاهد پیدا نشد → سؤال را حذف کن و جایگزین بساز

شاهد لازم نیست عین جمله یا واژه باشد؛ کافی است قاعده‌ای که پشت سؤال است در جزوه آموزش داده شده باشد.

## منبع گرامری (سخت‌بسته)
تمام گرامر، ساختارها، استثناها و قواعد دستوری فقط از جزوه‌های ارائه‌شده. حتی اگر جزوه ناقص یا ساده‌شده باشد، از دانش عمومی‌ات وارد نکن. اصطلاحات و نام‌گذاری‌های استاد اولویت مطلق دارند.

## واژگان و موقعیت (آزاد)
جمله‌سازی، واژگان و موقعیت‌ها آزادند - مشروط به سه شرط:
1. مناسب موضوع درخواستی
2. در سطح این جلسه
3. ساختار دستوری فقط از گرامرهای تدریس‌شده

## روش کار (ترتیب اجباری)
**گام ۱ - استخراج:** جزوه فعلی را خط‌به‌خط بخوان؛ گرامرها و ساختارهای تدریس‌شده را مشخص کن.
**گام ۲ - پیش‌نیاز:** جزوه‌های قبلی مجاز را برای پیش‌نیازهای تأییدشده بررسی کن.
**گام ۳ - ساخت:** برای هر سؤال ابتدا شاهد را تأیید کن، سپس بساز.
**گام ۴ - کنترل کیفیت:** هر سؤال را از نظر طبیعی بودن، یکتایی پاسخ، سطح دشواری مناسب و وابستگی به شاهد بررسی کن.
SYSTEM;
        $prompt=<<<PROMPT
<task>
ساخت آزمونک زبان آلمانی به فرمت JSON - خروجی فقط JSON معتبر، بدون Markdown، بدون هیچ متن اضافه.
</task>

<parameters>
موضوع واژگانی: {$topic}
سطح: {$level}
تعداد چهارگزینه‌ای: {$mc_count}
تعداد جای‌خالی: {$fib_count}
مجموع: {$total}
</parameters>

<rules>
### منبع گرامری (سخت‌بسته)
- جزوه فعلی: منبع اصلی؛ ۷۰–۸۰٪ سؤال‌ها مستقیم از آن
- جلسات قبلی: فقط برای پیش‌نیازهای جلسه‌هایی که زبان‌آموز حاضر بوده
- جلسه با غیبت: کاملاً ممنوع
- شرط ساخت هر سؤال: شاهد صریح در جزوه (قاعده، ساختار یا منطق آموزشی)
- ممنوع مطلق: وارد کردن قاعده/استثنا/تحلیل گرامری از دانش عمومی

### گزینه‌های غلط در چهارگزینه‌ای
هر گزینه غلط باید یکی از این‌ها باشد:
- خطای رایج زبان‌آموزان همین سطح
- اشتباه ناشی از تداخل با زبان مادری (فارسی)
- خطای منطقی مرتبط با همان ساختار گرامری
گزینه‌های خیلی دور یا ساده‌لوحانه ممنوع است.

### جای‌خالی (FIB)
- دقیقاً یک جای خالی با نشانه: __________
- باید فرم گرامری مشخص و محدود بسنجد (نه واژه آزاد)
- acceptable_answers شامل تمام فرم‌های معتبر (مثلاً هر دو فرم رسمی و محاوره‌ای)

### کیفیت
- متن سؤال: آلمانی استاندارد، کوتاه، طبیعی، بدون ابهام
- توضیح پاسخ: فارسی روان، آموزشی، با اصطلاحات استاد، نه صرف اعلام جواب
- ممنوع: سؤال تکراری، پاسخ چندپهلو، جمله غیرطبیعی

### ترتیب دشواری (بدون برچسب)
آسان ← متوسط ← سخت ← بسیار سخت
دشواری فقط از طریق پیچیدگی جمله و شباهت گزینه‌ها تغییر کند - نه از طریق خروج از سطح.
</rules>

<output_schema>
{
  "questions": [
    {
      "type": "mc",
      "text": "متن سؤال آلمانی",
      "options": ["گزینه۱","گزینه۲","گزینه۳","گزینه۴"],
      "correct": 0,
      "explanation": "توضیح فارسی آموزشی"
    },
    {
      "type": "fib",
      "text": "جمله با __________ جای خالی",
      "acceptable_answers": ["پاسخ۱","پاسخ۲"],
      "correct_answer": "پاسخ اصلی",
      "explanation": "توضیح فارسی آموزشی"
    }
  ]
}
- correct: اندیس صفرمبنا (0 تا 3)
- acceptable_answers: ۱ تا ۷ پاسخ معتبر
- جای خالی فقط با این نشانه: __________
- جای‌خالی باید فرم گرامری محدود و قابل تصحیح بسنجد
</output_schema>

<current_lesson>
--- {$session->title} | {$session->jalali_date} | سطح {$level} ---
{$current_context}
</current_lesson>

<previous_lessons>
{$previous_block}
</previous_lessons>
PROMPT;

        $settings=$this->settings();
        $messages=[['role'=>'system','content'=>$system],['role'=>'user','content'=>$prompt]];
        $raw=$this->ai_call_chat($messages,$settings['ai_model_analysis'] ?: $settings['ai_model_reading'],'quiz_generation',$session_id,0,0.05);
        if(is_wp_error($raw)) return $raw;
        $data=$this->ai_extract_json($raw);
        $validated=$this->validate_generated_quiz($data,$mc_count,$fib_count);
        if(is_wp_error($validated)){
            $repair="خروجی قبلی نامعتبر بود: ".$validated->get_error_message().". همان آزمون را دوباره بساز. خروجی باید دقیقاً یک JSON معتبر با کلید questions و تعداد دقیق ".$mc_count." چهارگزینه‌ای (type:mc) و ".$fib_count." جای‌خالی (type:fib) باشد. بدون Markdown، بدون توضیح، فقط JSON.";
            $messages[]=['role'=>'assistant','content'=>(string)$raw];
            $messages[]=['role'=>'user','content'=>$repair];
            $raw=$this->ai_call_chat($messages,$settings['ai_model_analysis'] ?: $settings['ai_model_reading'],'quiz_generation_retry',$session_id,0,0.0);
            if(is_wp_error($raw)) return $raw;
            $validated=$this->validate_generated_quiz($this->ai_extract_json($raw),$mc_count,$fib_count);
            if(is_wp_error($validated)) return $validated;
        }

        $title='آزمونک '.$topic.' - '.($session->student_name?:$session->student_email).' - '.$session->jalali_date;
        $post_id=wp_insert_post([
            'post_type'=>'online_test','post_status'=>'publish','post_title'=>sanitize_text_field($title),'post_author'=>get_current_user_id()
        ],true);
        if(is_wp_error($post_id)) return $post_id;
        update_post_meta($post_id,'_otg_level',sanitize_text_field($session->teacher_level?:'B1'));
        update_post_meta($post_id,'_otg_duration',0);
        update_post_meta($post_id,'_otg_order','sequential');
        update_post_meta($post_id,'_otg_questions_json',wp_json_encode(['questions'=>$validated],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        update_post_meta($post_id,'_gls_generated_for_session',intval($session_id));
        update_post_meta($post_id,'_gls_generated_topic',sanitize_text_field($topic));
        update_post_meta($post_id,'_gls_generation_scope','current_and_attended_previous_lessons_only');
        $wpdb->update($this->sessions,['test_shortcode'=>'[online_test id="'.intval($post_id).'"]','updated_at'=>current_time('mysql')],['id'=>intval($session_id)]);
        return intval($post_id);
    }

    private function validate_generated_quiz($data,$mc_count,$fib_count){
        if(!is_array($data) || empty($data['questions']) || !is_array($data['questions'])) return new WP_Error('quiz_json_invalid','ساختار JSON یا آرایه questions معتبر نیست.');
        $clean=[];$mc=0;$fib=0;
        foreach($data['questions'] as $q){
            if(!is_array($q)) continue;
            $type=sanitize_key($q['type']??'');
            // نسخه ۱۴.۶: رفع باگ اصلی - مدل طبق اسکیما «mc»/«fib» می‌فرستد اما اعتبارسنجی «multiple_choice»/«fill_in_blank» می‌خواست
            // که باعث می‌شد همه‌ی سؤال‌ها رد شوند و آزمونک همیشه با خطا شکست بخورد. حالا هر دو نام پذیرفته می‌شوند.
            if(in_array($type,['mc','multiple_choice','multiplechoice','choice','mcq'],true)) $type='multiple_choice';
            elseif(in_array($type,['fib','fill_in_blank','fillintheblank','fill_in_the_blank','blank','cloze'],true)) $type='fill_in_blank';
            $text=trim(wp_strip_all_tags((string)($q['text']??'')));
            $explanation=trim(wp_strip_all_tags((string)($q['explanation']??'')));
            if($text==='' || $explanation==='') continue;
            if($type==='multiple_choice'){
                $opts=array_values(array_map('sanitize_text_field',(array)($q['options']??[])));
                $correct=intval($q['correct']??-1);
                if(count($opts)!==4 || $correct<0 || $correct>3 || count(array_unique($opts))!==4) continue;
                $clean[]=['type'=>'multiple_choice','text'=>sanitize_text_field($text),'options'=>$opts,'correct'=>$correct,'explanation'=>sanitize_textarea_field($explanation)];
                $mc++;
            }elseif($type==='fill_in_blank'){
                if(substr_count($text,'__________')!==1) continue;
                $answers=[];
                foreach((array)($q['acceptable_answers']??[]) as $a){
                    $v=is_array($a)?($a['answer']??''):$a;
                    $v=sanitize_text_field($v);
                    if($v!=='') $answers[]=$v;
                }
                $answers=array_slice(array_values(array_unique($answers)),0,7);
                $correct=sanitize_text_field($q['correct_answer']??'');
                if(!$answers || $correct==='') continue;
                if(!in_array($correct,$answers,true)) array_unshift($answers,$correct);
                $clean[]=['type'=>'fill_in_blank','text'=>sanitize_text_field($text),'acceptable_answers'=>array_slice(array_values(array_unique($answers)),0,7),'correct_answer'=>$correct,'explanation'=>sanitize_textarea_field($explanation)];
                $fib++;
            }
        }
        if($mc!==intval($mc_count) || $fib!==intval($fib_count)) return new WP_Error('quiz_count_mismatch','تعداد سؤال‌های معتبر با تعداد درخواستی یکسان نیست (چهارگزینه‌ای '.$mc.' از '.$mc_count.'، جای‌خالی '.$fib.' از '.$fib_count.').');
        return $clean;
    }

    private function ai_student_profile($session){
        global $wpdb;
        if(!$session) return null;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->ai_profiles} WHERE user_id=%d AND student_email=%s",intval($session->user_id),$session->student_email));
    }

    private function ai_update_student_profile($session,$analysis){
        global $wpdb;
        if(!$session || !$session->student_email) return;
        $old=$this->ai_student_profile($session);
        $points=[];
        foreach((array)($analysis['taught_points']??[]) as $p){ $points[]=sanitize_text_field($p); }
        $terms=[];
        foreach((array)($analysis['teacher_terms']??[]) as $t){ $terms[]=sanitize_text_field($t); }
        $summary=trim(($old->profile_summary ?? '')."\n".'جلسه '.$session->jalali_date.': سطح '.($analysis['detected_level']??'').'؛ '.implode('، ',array_slice($points,0,10)));
        $summary=mb_substr($summary,-6000);
        $data=[
            'user_id'=>intval($session->user_id),
            'student_email'=>sanitize_email($session->student_email),
            'current_level'=>sanitize_text_field($analysis['detected_level'] ?? $session->teacher_level),
            'profile_summary'=>$summary,
            'taught_points'=>wp_json_encode(array_values(array_unique(array_merge((array)json_decode($old->taught_points ?? '[]',true),$points))),JSON_UNESCAPED_UNICODE),
            'teacher_terms'=>wp_json_encode(array_values(array_unique(array_merge((array)json_decode($old->teacher_terms ?? '[]',true),$terms))),JSON_UNESCAPED_UNICODE),
            'last_session_id'=>intval($session->id),
            'updated_at'=>current_time('mysql')
        ];
        if($old) $wpdb->update($this->ai_profiles,$data,['id'=>intval($old->id)]);
        else $wpdb->insert($this->ai_profiles,$data);
    }

    private function ai_generate_reading_for_session($session_id){
        global $wpdb;
        $session=$this->get_session($session_id);
        if(!$session) return new WP_Error('not_found','جلسه پیدا نشد.');
        $lesson=trim(wp_strip_all_tags((string)$session->lesson_content));
        if($lesson==='') return new WP_Error('empty_lesson','جزوه این جلسه هنوز متنی ندارد.');
        $settings=$this->settings();
        $profile=$this->ai_student_profile($session);
        $profile_text=$profile?wp_strip_all_tags($profile->profile_summary):'سابقه‌ای هنوز ثبت نشده است.';
        $manual_level=$session->teacher_level ?: 'تشخیص خودکار بر اساس جزوه';
        $lesson_short=mb_substr($lesson,0,10000);
        $reading_title=$settings['ai_reading_title'];

        // نسخه ۱۴.۶: علاوه بر خلاصه‌ی پروفایل، محتوای واقعیِ جلسات قبلیِ حاضرشده را هم می‌آوریم تا
        // «متنِ خواندن» بر پایه‌ی چیزهایی که شاگرد قبلاً یاد گرفته ساخته شود (ورودیِ قابل‌فهم i+1).
        $email=$session->student_email ?: '';
        $previous=$wpdb->get_results($wpdb->prepare(
            "SELECT title,jalali_date,teacher_level,lesson_content FROM {$this->sessions} WHERE id<>%d AND (user_id=%d OR student_email=%s) AND attendance_status='present' AND session_status='published' AND booking_date<=%s AND lesson_content IS NOT NULL AND lesson_content<>'' ORDER BY booking_date ASC,booking_time ASC,id ASC",
            intval($session_id),intval($session->user_id),$email,$session->booking_date
        ));
        $previous_context=''; $budget=24000;
        foreach((array)$previous as $row){
            $txt=trim(wp_strip_all_tags((string)$row->lesson_content));
            if($txt==='') continue;
            $piece="\n--- جلسه قبلی: {$row->title} | {$row->jalali_date} | سطح ".($row->teacher_level?:'ثبت‌نشده')." ---\n".mb_substr($txt,0,4000);
            if(mb_strlen($previous_context.$piece)>$budget) break;
            $previous_context.=$piece;
        }
        $previous_block = ($previous_context!=='') ? $previous_context : 'هنوز جلسه‌ی قبلیِ حاضرشده‌ای با جزوه ثبت نشده است؛ فقط بر جزوه‌ی همین جلسه تکیه کن.';

        $system='You are an expert German (Deutsch als Fremdsprache) teacher creating a "Lesen" (reading) passage for a private student. Output ONLY one valid JSON object. Do not mention being an AI.';

        $prompt=<<<PROMPT
<task>
یک متنِ «Lesen» (متن خواندنِ آلمانی) بساز که در انتهای جزوه‌ی همین جلسه قرار می‌گیرد و به زبان‌آموز کمک می‌کند تا مطالبِ همین جلسه را در یک متنِ طبیعی تمرین کند.
خروجی: فقط یک JSON معتبر، بدون Markdown، بدون هیچ متن اضافه.
</task>

<focus_rules>
1. تمرکز اصلی (۷۰–۸۰٪): گرامر/ساختار/واژگانی که در «جزوه‌ی همین جلسه» تدریس شده. متن باید این موارد را به‌طور طبیعی و پرتکرار به‌کار ببرد (نه اینکه درباره‌ی آن‌ها توضیح بدهد).
2. پایه و زیربنا: فقط از واژگان و گرامری استفاده کن که زبان‌آموز از قبل بلد است - یعنی مطالبِ «جلسات قبلیِ حاضرشده» + همین جلسه. چیزی خارج از این‌ها وارد نکن (ورودیِ قابل‌فهم، i+1).
3. سطح: متن دقیقاً در سطح زبان‌آموز باشد؛ نه ساده‌تر، نه سخت‌تر.
</focus_rules>

<style_rules>
- متن باید کاملاً تازه و با موضوعی نو باشد (داستان کوتاه، ایمیل، گفت‌وگوی روزمره، گزارش ساده، یا موقعیت واقعی).
- ممنوع: ترجمه، خلاصه، توضیح گرامر، فهرست نکات، یا بازنویسی جمله‌های جزوه.
- متن باید ۱۰۰٪ آلمانیِ طبیعی و روان باشد؛ هیچ کلمه یا توضیح فارسی/انگلیسی داخل متن نباشد.
- حداقل ۲۰۰ کلمه‌ی آلمانی.
- نمونه‌هایی از گرامرِ همین جلسه را داخل متن با <mark class="gls-ai-grammar">...</mark> هایلایت کن (فقط نمونه‌های واقعیِ همان گرامر، نه هر کلمه).
- اصطلاحات و نام‌گذاری‌های استاد (اگر در جزوه هست) در اولویت‌اند.
</style_rules>

<output_schema>
{
  "detected_level": "A1|A2|B1|B2|C1",
  "taught_points": ["نکته‌ی گرامری/ساختاری این جلسه به‌آلمانی و کوتاه", "..."],
  "teacher_terms": ["اصطلاح یا نام‌گذاری خاص استاد (اگر بود)"],
  "reading_title": "عنوان کوتاه و مرتبط به‌آلمانی",
  "reading_html": "متن Lesen به‌صورت HTML ساده (<p>، <mark class=\"gls-ai-grammar\">) - فقط آلمانی، حداقل ۲۰۰ کلمه"
}
- taught_points فقط آلمانی و عنوان‌گونه (فارسی ننویس).
- reading_html هیچ ترجمه یا توضیح فارسی نداشته باشد.
</output_schema>

<student_level>سطح انتخابی استاد: {$manual_level}</student_level>
<default_title>{$reading_title}</default_title>
<student_history_summary>{$profile_text}</student_history_summary>

<previous_lessons_content>
{$previous_block}
</previous_lessons_content>

<current_lesson_content>
--- {$session->title} | {$session->jalali_date} ---
{$lesson_short}
</current_lesson_content>
PROMPT;

        $messages=[
            ['role'=>'system','content'=>$system],
            ['role'=>'user','content'=>$prompt]
        ];
        $raw=$this->ai_call_chat($messages,$settings['ai_model_reading'],'lesson_reading',$session_id,0,0.2);
        if(is_wp_error($raw)) return $raw;
        $data=$this->ai_extract_json($raw);
        if(!$data) return new WP_Error('bad_json','پاسخ مدل JSON معتبر نبود.');
        $title=sanitize_text_field($data['reading_title'] ?? $settings['ai_reading_title']);
        $html=wp_kses_post($data['reading_html'] ?? '');
        $detected=sanitize_text_field($data['detected_level'] ?? '');
        $wpdb->update($this->sessions,[
            'ai_detected_level'=>$detected,
            'ai_reading_title'=>$title,
            'ai_reading_content'=>$html,
            'ai_analysis_json'=>wp_json_encode($data,JSON_UNESCAPED_UNICODE),
            'ai_analyzed_at'=>current_time('mysql'),
            'updated_at'=>current_time('mysql')
        ],['id'=>intval($session_id)]);
        $session=$this->get_session($session_id);
        $this->ai_update_student_profile($session,$data);
        return true;
    }

    private function ai_file_content_parts($json){
        $ids=json_decode($json,true);
        $parts=[];
        if(!is_array($ids)) return $parts;
        $count=0;
        foreach($ids as $id){
            if($count>=3) break;
            $id=intval($id);
            $path=get_attached_file($id);
            $mime=get_post_mime_type($id);
            if($path && file_exists($path) && strpos((string)$mime,'image/')===0 && filesize($path)<4*1024*1024){
                $data=base64_encode(file_get_contents($path));
                $parts[]=['type'=>'image_url','image_url'=>['url'=>'data:'.$mime.';base64,'.$data]];
                $count++;
            }
        }
        return $parts;
    }

    private function ai_correct_submission($submission_id){
        global $wpdb;
        $sub=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->subs} WHERE id=%d",intval($submission_id)));
        if(!$sub) return new WP_Error('not_found','تکلیف پیدا نشد.');
        $session=$this->get_session($sub->session_id);
        if(!$session) return new WP_Error('no_session','جلسه پیدا نشد.');
        $settings=$this->settings();
        $profile=$this->ai_student_profile($session);
        $lesson_context=mb_substr(wp_strip_all_tags((string)$session->lesson_content.' '.(string)$session->ai_reading_content),0,7000);
        $profile_text=$profile?wp_strip_all_tags($profile->profile_summary):'';
        $task_text=trim(wp_strip_all_tags((string)$sub->text_content));
        $instruction="تو نقش استاد آلمانی را داری و باید تکلیف زبان‌آموز را مثل تصحیح انسانی بررسی کنی. فقط بر اساس سطح و مطالبی که در جزوه‌ها و سابقه شاگرد آمده تصحیح کن. اصطلاحات استاد را از سابقه و جزوه بفهم و همان منطق را رعایت کن. خروجی را HTML بده، نه Markdown. اشتباهات را با <del>...</del> خط بزن، شکل درست را با <strong>...</strong> بیاور و توضیح‌های مهم را با <em>...</em> بنویس. اگر تصویر ارسال شده، ابتدا متن داخل تصویر را بخوان و سپس همان متن را تصحیح کن. خروجی JSON معتبر با کلیدهای feedback_html، grade_suggestion، careless_errors_count، extracted_text باشد. سابقه شاگرد: {$profile_text}. جزوه/مطالب تدریس‌شده: {$lesson_context}. متن تایپ‌شده شاگرد: {$task_text}";
        $content=[['type'=>'text','text'=>$instruction]];
        foreach($this->ai_file_content_parts($sub->file_ids) as $part) $content[]=$part;
        $messages=[
            ['role'=>'system','content'=>'Return only valid JSON. You are a precise German teacher.'],
            ['role'=>'user','content'=>$content]
        ];
        $raw=$this->ai_call_chat($messages,$settings['ai_model_correction'],'homework_correction',intval($session->id),intval($submission_id),0.15);
        if(is_wp_error($raw)) return $raw;
        $data=$this->ai_extract_json($raw);
        $feedback=wp_kses_post($data['feedback_html'] ?? $raw);
        $grade=isset($data['grade_suggestion']) ? min(100,max(0,floatval($data['grade_suggestion']))) : null;
        $update=[
            'status'=>'reviewing',
            'feedback_text'=>$feedback,
            'updated_at'=>current_time('mysql')
        ];
        if($grade!==null) $update['grade']=$grade;
        $extracted=trim(wp_strip_all_tags((string)($data['extracted_text'] ?? '')));
        if($extracted!=='' && !empty($sub->file_ids)){
            $base_text=trim((string)$sub->text_content);
            $update['text_content']=trim($base_text."

متن استخراج‌شده از فایل/تصویر:
".$extracted);
            $update['file_ids']=wp_json_encode([]);
        }
        $wpdb->update($this->subs,$update,['id'=>intval($submission_id)]);
        if($extracted!=='' && !empty($sub->file_ids)){
            $this->delete_submission_files_after_ai($sub);
        }
        $set=$this->settings();
        $this->mail_log($session,'ai_correction_draft',$set['teacher_email'],'پیش‌نویس تصحیح آماده شد',"پیش‌نویس تصحیح تکلیف {$session->title} آماده شد.\n\n".wp_strip_all_tags($feedback));
        if($profile){
            $careless=intval($data['careless_errors_count'] ?? 0);
            $wpdb->update($this->ai_profiles,[
                'careless_errors'=>wp_json_encode(['last_submission_id'=>intval($submission_id),'count'=>$careless,'updated_at'=>current_time('mysql')],JSON_UNESCAPED_UNICODE),
                'updated_at'=>current_time('mysql')
            ],['id'=>intval($profile->id)]);
        }
        return true;
    }

    private function status_label($s){ return ['draft'=>'پیش‌نویس','published'=>'منتشرشده','archived'=>'آرشیو'][$s]??$s; }
    private function attendance_label($s){ return ['unset'=>'ثبت نشده','present'=>'حاضر','absent'=>'غایب'][$s]??$s; }
    private function submission_label($s){ return ['submitted'=>'ارسال شده','reviewing'=>'در حال بررسی','corrected'=>'تصحیح شد','resubmit'=>'نیاز به ارسال مجدد'][$s]??$s; }
    private function kind_label($s){ return ['file'=>'فایل/PDF','image'=>'عکس','link'=>'لینک','book'=>'کتاب','text'=>'متن'][$s]??$s; }

    private function g2j_string($date){
        if(!$date||strpos($date,'-')===false)return '';
        [$gy,$gm,$gd]=array_map('intval',explode('-',$date));
        [$jy,$jm,$jd]=$this->g2j($gy,$gm,$gd);
        return $jy.'/'.sprintf('%02d',$jm).'/'.sprintf('%02d',$jd);
    }

    private function g2j($gy,$gm,$gd){
        $g=[0,31,59,90,120,151,181,212,243,273,304,334];

        if($gy>1600){
            $jy=979;
            $gy-=1600;
        } else {
            $jy=0;
            $gy-=621;
        }

        $gy2=$gm>2?$gy+1:$gy;
        $days=365*$gy+intval(($gy2+3)/4)-intval(($gy2+99)/100)+intval(($gy2+399)/400)-80+$gd+$g[$gm-1];

        $jy+=33*intval($days/12053);
        $days%=12053;
        $jy+=4*intval($days/1461);
        $days%=1461;

        if($days>365){
            $jy+=intval(($days-1)/365);
            $days=($days-1)%365;
        }

        $jm=$days<186?1+intval($days/31):7+intval(($days-186)/30);
        $jd=1+($days<186?$days%31:($days-186)%30);

        return [$jy,$jm,$jd];
    }
}
