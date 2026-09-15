<?php
/*
Plugin Name: GTBP Recording Transport Bridge
Description: Signed recording callbacks and Telegram object reference delivery.
Version: 1.2.2
Author: Hamidreza Saadati
*/
if (!defined('ABSPATH')) exit;

final class GTBP_Recording_Transport_Bridge {
    const DB_VERSION = '2';
    const OPTION_SECRET = 'gtbp_bridge_shared_secret';
    const OPTION_GATEWAY = 'gtbp_bridge_gateway_url';
    private static $instance;

    public static function instance() { return self::$instance ?: (self::$instance = new self()); }
    private function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        add_action('init', [$this, 'maybe_upgrade']);
        add_action('rest_api_init', [$this, 'routes']);
        add_action('admin_menu', [$this, 'menu']);
        add_filter('gtbp_bot_videos_handled', [$this, 'bot_videos'], 10, 6);
    }
    private function table() { global $wpdb; return $wpdb->prefix . 'gtbp_recording_transport'; }
    public function activate() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $table = $this->table();
        dbDelta("CREATE TABLE {$table} (
          id bigint unsigned NOT NULL AUTO_INCREMENT,
          booking_id bigint unsigned DEFAULT NULL,
          session_id bigint unsigned DEFAULT NULL,
          record_id varchar(191) NOT NULL,
          presentation_url text DEFAULT NULL,
          video_url text DEFAULT NULL,
          filename varchar(191) DEFAULT NULL,
          telegram_file_id text DEFAULT NULL,
          telegram_file_unique_id varchar(191) DEFAULT NULL,
          telegram_chat_id varchar(64) DEFAULT NULL,
          telegram_message_id bigint DEFAULT NULL,
          file_size bigint unsigned DEFAULT NULL,
          sha256 char(64) DEFAULT NULL,
          duration decimal(12,3) DEFAULT NULL,
          status varchar(32) NOT NULL DEFAULT 'ready',
          created_at datetime NOT NULL,
          updated_at datetime NOT NULL,
          PRIMARY KEY (id), UNIQUE KEY record_id (record_id), KEY booking_id (booking_id), KEY session_id (session_id)
        ) $charset;");
        update_option('gtbp_bridge_db_version', self::DB_VERSION, false);
    }
    public function maybe_upgrade() {
        if ((string)get_option('gtbp_bridge_db_version', '') !== self::DB_VERSION) $this->activate();
    }
    public function routes() {
        register_rest_route('gtbp-bridge/v1', '/recording-ready', ['methods'=>'POST','callback'=>[$this,'receive'],'permission_callback'=>'__return_true']);
        register_rest_route('gtbp-bridge/v1', '/health', ['methods'=>'GET','callback'=>[$this,'health'],'permission_callback'=>'__return_true']);
    }
    public function health(WP_REST_Request $request) {
        $secret=(string)get_option(self::OPTION_SECRET,'');
        $gateway=(string)get_option(self::OPTION_GATEWAY,'');
        $ts=(string)$request->get_header('x-bcp-timestamp');
        $sig=(string)$request->get_header('x-bcp-signature');
        $authenticated=strlen($secret)>=32 && ctype_digit($ts) && abs(time()-intval($ts))<=300 && hash_equals(hash_hmac('sha256',$ts.'.bridge-ready',$secret),$sig);
        return ['ok'=>true,'version'=>'1.2.2','gateway_configured'=>(bool)preg_match('#^https://[^/]+/telegram-api/?$#',$gateway),'authenticated'=>$authenticated];
    }
    private function authorized(WP_REST_Request $request) {
        $secret = (string)get_option(self::OPTION_SECRET, '');
        $ts = (string)$request->get_header('x-bcp-timestamp');
        $sig = (string)$request->get_header('x-bcp-signature');
        if (strlen($secret) < 32 || !ctype_digit($ts) || abs(time() - intval($ts)) > 300 || !preg_match('/^[a-f0-9]{64}$/', $sig)) return false;
        return hash_equals(hash_hmac('sha256', $ts . '.' . $request->get_body(), $secret), $sig);
    }
    public function receive(WP_REST_Request $request) {
        if (!$this->authorized($request)) return new WP_Error('forbidden','Invalid signature',['status'=>403]);
        $p = $request->get_json_params();
        $record_id = sanitize_text_field($p['record_id'] ?? '');
        $file_id = sanitize_text_field($p['file_id'] ?? '');
        if ($record_id === '' || $file_id === '') return new WP_Error('invalid','Missing immutable identifiers',['status'=>422]);
        global $wpdb;
        $bookings = $wpdb->prefix . 'german_bookings'; $sessions = $wpdb->prefix . 'gls_sessions';
        $meeting_id = sanitize_text_field($p['meeting_id'] ?? '');
        $booking_id = $meeting_id ? intval($wpdb->get_var($wpdb->prepare("SELECT id FROM {$bookings} WHERE roomeet_room_id=%s ORDER BY id DESC LIMIT 1", $meeting_id))) : 0;
        if (!$booking_id) $booking_id = intval($wpdb->get_var($wpdb->prepare("SELECT id FROM {$bookings} WHERE roomeet_room_id=%s ORDER BY id DESC LIMIT 1", $record_id)));
        $session_id = $booking_id ? intval($wpdb->get_var($wpdb->prepare("SELECT id FROM {$sessions} WHERE booking_id=%d LIMIT 1", $booking_id))) : 0;
        $now = current_time('mysql');
        $data = ['booking_id'=>$booking_id ?: null,'session_id'=>$session_id ?: null,'record_id'=>$record_id,'presentation_url'=>esc_url_raw($p['presentation_url'] ?? ''),'video_url'=>esc_url_raw($p['video_url'] ?? ''),'filename'=>sanitize_file_name($p['filename'] ?? ''),'telegram_file_id'=>$file_id,'telegram_file_unique_id'=>sanitize_text_field($p['file_unique_id'] ?? ''),'telegram_chat_id'=>sanitize_text_field($p['chat_id'] ?? ''),'telegram_message_id'=>intval($p['message_id'] ?? 0),'file_size'=>intval($p['file_size'] ?? 0),'sha256'=>sanitize_text_field($p['sha256'] ?? ''),'duration'=>floatval($p['duration'] ?? 0),'status'=>'ready','updated_at'=>$now];
        $table = $this->table();
        $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE record_id=%s", $record_id));
        if ($existing) $wpdb->update($table, $data, ['id'=>intval($existing)]); else { $data['created_at']=$now; $wpdb->insert($table, $data); }
        $preferred_url = $data['presentation_url'] ?: $data['video_url'];
        if ($booking_id && $preferred_url !== '') {
            $booking_columns=(array)$wpdb->get_col("SHOW COLUMNS FROM {$bookings}");
            $booking_data=['roomeet_recording_checked'=>$now];
            $old = $wpdb->get_var($wpdb->prepare("SELECT roomeet_recording_link FROM {$bookings} WHERE id=%d",$booking_id));
            if (!$old) $booking_data['roomeet_recording_link']=$preferred_url;
            if (in_array('bbb_presentation_link',$booking_columns,true)) $booking_data['bbb_presentation_link']=$data['presentation_url'];
            if (in_array('bbb_video_link',$booking_columns,true)) $booking_data['bbb_video_link']=$data['video_url'];
            $wpdb->update($bookings,$booking_data,['id'=>$booking_id]);
        }
        if ($session_id && $preferred_url !== '') {
            $row=$wpdb->get_row($wpdb->prepare("SELECT video_url,video_url_source FROM {$sessions} WHERE id=%d",$session_id));
            if ($row && !((string)$row->video_url_source==='manual' && trim((string)$row->video_url)!=='')) $wpdb->update($sessions,['video_url'=>$preferred_url,'video_url_source'=>'auto'],['id'=>$session_id]);
        }
        return ['ok'=>true,'booking_id'=>$booking_id,'session_id'=>$session_id];
    }
    public function bot_videos($handled, $platform, $token, $chat_id, $state, $sender) {
        if ($platform !== 'telegram') return $handled;
        $user_id = intval($state['data']['user_id'] ?? 0); if (!$user_id) return false;
        global $wpdb; $sessions=$wpdb->prefix.'gls_sessions';
        $table=$this->table();
        $rows=$wpdb->get_results($wpdb->prepare("SELECT t.telegram_file_id,s.jalali_date,s.class_name FROM {$table} t INNER JOIN {$sessions} s ON s.id=t.session_id WHERE t.status='ready' AND s.user_id=%d ORDER BY s.booking_date DESC LIMIT 20",$user_id));
        if (!$rows) return false;
        foreach ($rows as $r) wp_remote_post('https://api.telegram.org/bot'.$token.'/sendVideo',['timeout'=>60,'body'=>['chat_id'=>$chat_id,'video'=>$r->telegram_file_id,'caption'=>$r->jalali_date.' | '.$r->class_name,'protect_content'=>'true']]);
        return true;
    }
    public function menu(){ add_management_page('Recording Transport','Recording Transport','manage_options','gtbp-recording-transport',[$this,'page']); }
    public function page(){
        if (!current_user_can('manage_options')) return;
        if (isset($_POST['save']) && check_admin_referer('gtbp_bridge_save')) {
            $secret=sanitize_text_field(wp_unslash($_POST['secret']??''));
            if ($secret!=='') update_option(self::OPTION_SECRET,$secret,false);
            update_option(self::OPTION_GATEWAY,esc_url_raw(rtrim(wp_unslash($_POST['gateway']??''),'/')),false);
        }
        global $wpdb; $table=$this->table(); $rows=$wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC LIMIT 100");
        echo '<div class="wrap"><h1>Recording Transport</h1><form method="post">'; wp_nonce_field('gtbp_bridge_save');
        echo '<p><label>Gateway URL <input type="url" name="gateway" value="'.esc_attr(get_option(self::OPTION_GATEWAY,'')).'" size="70" placeholder="https://media.example.com/telegram-api"></label></p>';
        echo '<p><label>Shared secret <input type="password" name="secret" value="" size="70" autocomplete="new-password"></label> <button class="button button-primary" name="save">Save</button></p></form>';
        echo '<table class="widefat striped"><thead><tr><th>Record</th><th>Booking</th><th>Size</th><th>Status</th><th>Updated</th></tr></thead><tbody>';
        foreach($rows as $r) echo '<tr><td>'.esc_html($r->record_id).'</td><td>'.intval($r->booking_id).'</td><td>'.size_format($r->file_size).'</td><td>'.esc_html($r->status).'</td><td>'.esc_html($r->updated_at).'</td></tr>';
        echo '</tbody></table></div>';
    }
}
GTBP_Recording_Transport_Bridge::instance();
