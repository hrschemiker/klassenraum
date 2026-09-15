<?php
/*
Plugin Name: افزونه رزرو کلاس و پنل آموزشی
Description: سیستم یکپارچه رزرو کلاس، پنل آموزشی، جزوه، تکلیف و عملکرد با چهار ارائه دهنده کلاس آنلاین
Author: حمیدرضا سعادتی
Version: 14.16.0-meet-recordings
*/

if (!defined('ABSPATH')) exit;

/* --- Google Meet Provider (افزودنی؛ داده‌های موجود دست‌نخورده باقی می‌مانند) --- */
if (file_exists(__DIR__ . '/includes/class-google-meet-provider.php')) {
    require_once __DIR__ . '/includes/class-google-meet-provider.php';
    add_action('plugins_loaded', function () {
        if (class_exists('GTBP_Google_Meet_Provider')) GTBP_Google_Meet_Provider::instance();
    }, 20);
}

/* --- Roomeet Provider (افزودنی) --- */
if (file_exists(__DIR__ . '/includes/class-roomeet-provider.php')) {
    require_once __DIR__ . '/includes/class-roomeet-provider.php';
    add_action('plugins_loaded', function () {
        if (class_exists('GTBP_Roomeet_Provider')) GTBP_Roomeet_Provider::instance();
    }, 20);
}

// Fix #12 note: class name suffix "_v10" is kept intentionally. Renaming it would break
// any serialised action-hook references stored in wp_options / wp_cron.
class GermanTeacherBookingPlugin_v10 {
    private $table_name;
    
    // ثابت‌های BigBlueButton (قابل تغییر در تنظیمات افزونه)
    const BBB_DEFAULT_URL = 'https://bbb.example.com/bigbluebutton/';
    const BBB_DEFAULT_SECRET = '';
    const BBB_DEFAULT_TEACHER_NAME = 'حمیدرضا سعادتی';

    // کش گزینه‌ها
    private $cached_classes = null;
    private $cached_bank_info = null;
    private $cached_holidays = null;
    private $cached_hours = null;
    private $cached_user_hours = null;
    private $cached_packages = null;

    /* ==========================================================================
       ویژگی‌های جدید نسخه ۸.۰ - ماژول بدهی/طلب کاربران (حفظ شده)
       ========================================================================== */
    
    private function get_user_adjustment($user_id) {
        $adjustment = get_user_meta($user_id, '_gtbp_user_adjustment', true);
        return is_numeric($adjustment) ? intval($adjustment) : 0;
    }
    
    private function set_user_adjustment($user_id, $amount) {
        update_user_meta($user_id, '_gtbp_user_adjustment', intval($amount));
    }

    /* ==========================================================================
       ویژگی‌های قبلی - کارت به کارت یکپارچه و غیره (حفظ شده)
       ========================================================================== */
    
    public function check_pending_card_payments() {
        global $wpdb;
        
        $deadline = date('Y-m-d H:i:s', strtotime('-24 hours'));
        $pending_bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE status = 'pending_card' 
             AND created_at <= %s",
            $deadline
        ));
        
        foreach ($pending_bookings as $booking) {
            if ($booking->outside_iran == 1) {
                $wpdb->update(
                    $this->table_name,
                    ['status' => 'cancelled'],
                    ['id' => $booking->id]
                );
                
                $subject = "⚠️ لغو رزرو به دلیل عدم پرداخت";
                $message = "سلام {$booking->first_name} عزیز،\n\n";
                $message .= "رزرو شما به دلیل عدم واریز وجه در مهلت ۲۴ ساعته لغو شد.\n";
                $message .= "در صورت تمایل به رزرو مجدد، لطفاً اقدام فرمایید.\n\nبا تشکر";
                
                wp_mail($booking->email, $subject, $message);
                wp_mail($this->admin_notification_email(), "لغو خودکار رزرو - {$booking->first_name} {$booking->last_name}", $message);
            }
        }
        
        // نسخه ۱۳.۸: به جای حذف رزروهای موقت پس از ۱۰ دقیقه، آن‌ها را به حالت «در انتظار پرداخت» تبدیل می‌کنیم
        // تا اگر کاربر با تأخیر روی «پرداخت انجام شد» کلیک کرد، رزرو از دست نرود.
        $ten_minutes_ago = date('Y-m-d H:i:s', strtotime('-10 minutes'));
        $wpdb->query($wpdb->prepare(
            "UPDATE {$this->table_name} SET status = 'pending_card' WHERE status = 'temp_card' AND created_at <= %s",
            $ten_minutes_ago
        ));
    }

    public function send_payment_reminders() {
        global $wpdb;
        
        // Fix #9: renamed variables to clarify the time-window logic (bookings 18–24 h old).
        $older_than_18h = date('Y-m-d H:i:s', strtotime('-18 hours'));
        $newer_than_24h = date('Y-m-d H:i:s', strtotime('-24 hours'));

        $reminder_bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE status = 'pending_card'
             AND outside_iran = 1
             AND created_at <= %s
             AND created_at > %s",
            $older_than_18h, $newer_than_24h
        ));
        
        foreach ($reminder_bookings as $booking) {
            $subject = "⏳ یادآوری پرداخت - کلاس آلمانی";
            $confirm_link = add_query_arg([
                'gtbp_reminder_confirm' => '1',
                'booking_id' => $booking->id,
                'nonce' => wp_create_nonce('gtbp_reminder_' . $booking->id)
            ], home_url());
            
            $message = "سلام {$booking->first_name} عزیز،\n\n";
            $message .= "ما ۱۸ ساعت پیش رزرو شما را با گزینه مهلت ۲۴ ساعته پرداخت ثبت کردیم.\n";
            $message .= "اگر هنوز واریز را انجام نداده‌اید، لطفاً هر چه سریعتر اقدام فرمایید.\n";
            $message .= "پس از واریز، روی لینک زیر کلیک کنید تا رزرو شما نهایی شود:\n";
            $message .= $confirm_link . "\n\n";
            $message .= "در غیر این صورت تا ۶ ساعت دیگر رزرو شما لغو خواهد شد.\n\nبا تشکر";
            
            wp_mail($booking->email, $subject, $message);
        }
    }
    
    public function handle_reminder_confirmation() {
        if (!isset($_GET['gtbp_reminder_confirm']) || !isset($_GET['booking_id']) || !isset($_GET['nonce'])) {
            return;
        }
        
        $booking_id = intval($_GET['booking_id']);
        $nonce = $_GET['nonce'];
        
        if (!wp_verify_nonce($nonce, 'gtbp_reminder_' . $booking_id)) {
            wp_die('لینک نامعتبر است.');
        }
        
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d AND status = 'pending_card' AND outside_iran = 1",
            $booking_id
        ));
        
        if (!$booking) {
            wp_die('رزرو مورد نظر یافت نشد یا قبلاً نهایی شده است.');
        }
        
        $wpdb->update(
            $this->table_name,
            ['status' => 'confirmed'],
            ['id' => $booking_id]
        );
        
        $subject = "✅ تایید نهایی رزرو - کلاس آلمانی";
        $message = "سلام {$booking->first_name} عزیز،\n\n";
        $message .= "پرداخت شما تایید شد و رزرو شما قطعی گردید.\n";
        $message .= "لینک ورود به کلاس: {$booking->roomeet_join_link}\n\nبا تشکر";
        
        wp_mail($booking->email, $subject, $message);
        wp_mail($this->admin_notification_email(), "تایید پرداخت (از طریق لینک یادآوری) - {$booking->first_name} {$booking->last_name}", $message);
        
        wp_redirect(add_query_arg('gtbp_payment_confirmed', '1', home_url()));
        exit;
    }

    public function ajax_confirm_card_payment() {
        // Fix #1: CSRF protection.
        check_ajax_referer('gtbp_ajax_nonce', 'nonce');
        // نسخه ۱۴.۳: کارت‌به‌کارت برای همه‌ی کاربرانِ واردشده بدون محدودیت نقش. (قبلاً current_user_can('read') بعضی نشست‌ها را رد می‌کرد)
        if (!is_user_logged_in()) {
            wp_send_json_error('نشست شما منقضی شده است. لطفاً دوباره وارد سایت شوید و صفحه را تازه‌سازی کنید.');
        }
        if (!isset($_POST['booking_id']) && !isset($_POST['booking_ids'])) {
            wp_send_json_error('اطلاعات رزرو ارسال نشد. لطفاً صفحه را تازه‌سازی کنید و دوباره تلاش کنید.');
        }
        
        $ids = [];
        if (isset($_POST['booking_ids'])) {
            $raw_ids = wp_unslash($_POST['booking_ids']);
            $decoded = json_decode($raw_ids, true);
            if (is_array($decoded)) {
                $ids = array_map('intval', $decoded);
            } else {
                $ids = array_map('intval', preg_split('/\s*,\s*/', sanitize_text_field($raw_ids), -1, PREG_SPLIT_NO_EMPTY));
            }
        } elseif (isset($_POST['booking_id'])) {
            $ids = [intval($_POST['booking_id'])];
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) wp_send_json_error('رزرو معتبری برای تایید پیدا نشد.');
        
        $user = wp_get_current_user();
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $query_args = array_merge($ids, [$user->user_email]);
        // نسخه ۱۳.۸: پذیرش هر دو حالت «موقت» و «در انتظار پرداخت» برای همه کاربران.
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id IN ($placeholders) AND email = %s AND status IN ('temp_card','pending_card')",
            $query_args
        ));

        if (empty($bookings)) {
            wp_send_json_error('رزرو مورد نظر یافت نشد یا منقضی شده است.');
        }
        if (count($bookings) !== count($ids)) {
            wp_send_json_error('بخشی از رزروهای این سبد قبلاً تایید/منقضی شده‌اند. لطفاً صفحه را تازه‌سازی کنید.');
        }

        $cart_for_email = [];
        $jitsi_rooms = [];
        foreach ($bookings as $booking) {
            $wpdb->update($this->table_name, ['status' => 'confirmed'], ['id' => intval($booking->id)]);
            $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", intval($booking->id)));
            if ($fresh) {
                $this->update_user_jitsi_links($user->ID, $fresh);
                $cart_for_email[] = [
                    'date' => $fresh->booking_date,
                    'time' => $fresh->booking_time,
                    'jDate' => $this->gregorian_to_jalali_string($fresh->booking_date),
                    'class' => ['name' => $fresh->class_name, 'price' => 0],
                ];
                $jitsi_rooms[] = [
                    'date' => $fresh->booking_date,
                    'time' => $fresh->booking_time,
                    'class_name' => $fresh->class_name,
                    'student_link' => $fresh->roomeet_join_link,
                    'teacher_link' => $fresh->bbb_moderator_link,
                ];
            }
        }
        
        // نسخه ۱۴.۳: تسویه‌ی بدهی/طلبِ کاربر پس از پرداخت کارت‌به‌کارت.
        // مبلغ تعدیل (بدهی مثبت/طلب منفی) کاملاً در مبلغ قابل پرداخت اعمال شده بود؛ پس از پرداخت باید از موجودی کسر شود.
        $applied_adj = get_transient('gtbp_card_adj_' . intval($user->ID));
        if ($applied_adj !== false && intval($applied_adj) != 0) {
            $current_adj = $this->get_user_adjustment($user->ID);
            $new_adj = $current_adj - intval($applied_adj); // بدهی: D-D=0 ، طلب: (-C)-(-C)=0
            $this->set_user_adjustment($user->ID, $new_adj);
            delete_transient('gtbp_card_adj_' . intval($user->ID));
        }

        $first = $bookings[0];
        $this->send_booking_emails($first->first_name, $first->last_name, $first->email, $first->phone, $cart_for_email, 0, false, true, $jitsi_rooms);

        wp_send_json_success(['message' => 'پرداخت با موفقیت تایید شد.', 'confirmed_count' => count($bookings)]);
    }

    public function ajax_outside_iran() {
        // Fix #1: CSRF protection.
        check_ajax_referer('gtbp_ajax_nonce', 'nonce');
        if ((!isset($_POST['booking_id']) && !isset($_POST['booking_ids'])) || !is_user_logged_in()) {
            wp_send_json_error('دسترسی غیرمجاز');
        }
        
        $ids = [];
        if (isset($_POST['booking_ids'])) {
            $raw_ids = wp_unslash($_POST['booking_ids']);
            $decoded = json_decode($raw_ids, true);
            if (is_array($decoded)) {
                $ids = array_map('intval', $decoded);
            } else {
                $ids = array_map('intval', preg_split('/\s*,\s*/', sanitize_text_field($raw_ids), -1, PREG_SPLIT_NO_EMPTY));
            }
        } elseif (isset($_POST['booking_id'])) {
            $ids = [intval($_POST['booking_id'])];
        }
        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) wp_send_json_error('رزرو معتبری برای ثبت مهلت ۲۴ ساعته پیدا نشد.');
        
        $user = wp_get_current_user();
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $query_args = array_merge($ids, [$user->user_email]);
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id IN ($placeholders) AND email = %s AND status = 'temp_card'",
            $query_args
        ));
        
        if (empty($bookings)) {
            wp_send_json_error('رزرو مورد نظر یافت نشد یا منقضی شده است.');
        }
        if (count($bookings) !== count($ids)) {
            wp_send_json_error('بخشی از رزروهای این سبد قبلاً تایید/منقضی شده‌اند. لطفاً صفحه را تازه‌سازی کنید.');
        }
        
        foreach ($bookings as $booking) {
            $wpdb->update(
                $this->table_name,
                ['status' => 'pending_card', 'outside_iran' => 1],
                ['id' => intval($booking->id)]
            );
        }
        
        $bank = $this->get_bank_info();
        $first = $bookings[0];
        $subject = "⏳ رزرو موقت - ۲۴ ساعت فرصت پرداخت";
        $message = "سلام {$first->first_name} عزیز،\n\n";
        $message .= "گزینه مهلت ۲۴ ساعته پرداخت برای کل سبد رزرو شما ثبت شد.\n";
        $message .= "تا ۲۴ ساعت آینده فرصت دارید مبلغ را واریز کنید.\n\n";
        $message .= "اطلاعات پرداخت:\n";
        $message .= "کارت: {$bank['card']}\n";
        $message .= "به نام: {$bank['owner']}\n\n";
        $message .= "پس از واریز، وارد سایت شوید و در صفحه رزرو، روی دکمه 'پرداخت انجام شد' کلیک کنید.\n\n";
        $message .= "در غیر این صورت رزرو شما لغو خواهد شد.\n\nبا تشکر";
        
        wp_mail($first->email, $subject, $message);
        wp_mail($this->admin_notification_email(), "ثبت مهلت ۲۴ ساعته پرداخت - {$first->first_name} {$first->last_name}", $message);
        
        wp_send_json_success(['message' => 'مهلت ۲۴ ساعته پرداخت برای کل سبد با موفقیت ثبت شد.', 'pending_count' => count($bookings)]);
    }

    public function ajax_confirm_pending_payment() {
        // Fix #1: CSRF protection.
        check_ajax_referer('gtbp_ajax_nonce', 'nonce');
        if (!isset($_POST['booking_id']) || !is_user_logged_in()) {
            wp_send_json_error('دسترسی غیرمجاز');
        }
        
        $booking_id = intval($_POST['booking_id']);
        $user = wp_get_current_user();
        
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d AND email = %s AND status = 'pending_card'",
            $booking_id, $user->user_email
        ));
        
        if (!$booking) {
            wp_send_json_error('رزرو مورد نظر یافت نشد.');
        }
        
        $wpdb->update(
            $this->table_name,
            ['status' => 'confirmed'],
            ['id' => $booking_id]
        );
        
        $subject = "✅ تایید نهایی رزرو - کلاس آلمانی";
        $message = "سلام {$booking->first_name} عزیز،\n\n";
        $message .= "پرداخت شما تایید شد و رزرو شما قطعی گردید.\n";
        $message .= "لینک ورود به کلاس: {$booking->roomeet_join_link}\n\nبا تشکر";
        
        wp_mail($booking->email, $subject, $message);
        wp_mail($this->admin_notification_email(), "تایید پرداخت (از طریق اعلان) - {$booking->first_name} {$booking->last_name}", $message);
        
        wp_send_json_success(['message' => 'پرداخت با موفقیت تایید شد.']);
    }

    public function show_pending_bookings_notice() {
        if (!is_user_logged_in()) {
            return '';
        }
        
        $user = wp_get_current_user();
        global $wpdb;
        
        $pending_bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE email = %s AND status = 'pending_card' AND outside_iran = 1
             ORDER BY created_at DESC",
            $user->user_email
        ));
        
        if (empty($pending_bookings)) {
            return '';
        }
        
        $output = '<div style="background: #fff3cd; border: 2px solid #ffc107; border-radius: 10px; padding: 20px; margin-bottom: 30px; font-family: IRANSansXFaNum, Tahoma, sans-serif;">';
        $output .= '<h4 style="color: #856404; margin-top: 0;">⏳ رزروهای در انتظار پرداخت</h4>';
        $output .= '<p style="color: #856404;">شما کلاس‌های زیر را رزرو کرده‌اید اما هنوز پرداخت نکرده‌اید. لطفاً پس از واریز، روی دکمه "پرداخت انجام شد" کلیک کنید.</p>';
        
        foreach ($pending_bookings as $booking) {
            $j_date = $this->gregorian_to_jalali_string($booking->booking_date);
            
            $output .= '<div style="background: white; border-radius: 8px; padding: 15px; margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">';
            $output .= '<div>';
            $output .= '<strong style="color: #8B0000;">' . esc_html($booking->class_name) . '</strong><br>';
            $output .= '📅 ' . esc_html($j_date) . ' | ⏰ ' . esc_html($booking->booking_time);
            $output .= '</div>';
            $output .= '<button class="confirm-pending-btn" data-id="' . $booking->id . '" style="background: #28a745; color: white; border: none; padding: 10px 20px; border-radius: 5px; cursor: pointer; font-weight: bold;">💰 پرداخت انجام شد</button>';
            $output .= '</div>';
        }
        
        $output .= '</div>';
        
        return $output;
    }

    private function update_user_jitsi_links($user_id, $new_booking) {
        return $this->upsert_user_jitsi_link($user_id, $new_booking);
    }

    public function cleanup_old_jitsi_links() {
        global $wpdb;
        
        $users_with_links = get_users([
            'meta_key' => 'classlogin',
            'meta_compare' => 'EXISTS'
        ]);
        
        $today = current_time('Y-m-d');
        
        foreach ($users_with_links as $user) {
            $links = get_user_meta($user->ID, 'classlogin', true);
            if (!is_array($links) || empty($links)) {
                continue;
            }
            
            $active_links = [];
            foreach ($links as $link) {
                if (isset($link['booking_id'])) {
                    $booking = $wpdb->get_row($wpdb->prepare(
                        "SELECT booking_date FROM {$this->table_name} WHERE id = %d",
                        $link['booking_id']
                    ));
                    if ($booking && $booking->booking_date >= $today) {
                        $active_links[] = $link;
                    }
                }
            }
            
            if (empty($active_links)) {
                delete_user_meta($user->ID, 'classlogin');
            } else {
                update_user_meta($user->ID, 'classlogin', $active_links);
            }
        }
    }

    /* ==========================================================================
       WOOCOMMERCE INTEGRATION METHODS (با تغییر به BigBlueButton)
       ========================================================================== */
    public function sync_all_classes_to_wc($old_value, $new_value, $option_name) {
        if (!class_exists('WooCommerce')) return;
        $active_ids = [];
        foreach ($new_value as $class) {
            $wc_id = $this->sync_class_to_wc($class['id'], $class['name'], $class['price']);
            if($wc_id) $active_ids[] = $class['id'];
        }

        $args = array(
            'post_type' => 'product',
            'meta_key' => '_gtbp_class_id',
            'posts_per_page' => -1
        );
        $posts = get_posts($args);
        foreach ($posts as $p) {
            $cid = get_post_meta($p->ID, '_gtbp_class_id', true);
            if (!in_array($cid, $active_ids)) {
                wp_trash_post($p->ID);
            }
        }
    }

    private function sync_class_to_wc($class_id, $class_name, $price) {
        if (!class_exists('WooCommerce')) return false;

        $args = array(
            'post_type' => 'product',
            'meta_query' => array(
                array('key' => '_gtbp_class_id', 'value' => $class_id)
            ),
            'post_status' => array('publish', 'pending', 'draft', 'trash')
        );
        $posts = get_posts($args);

        if ($posts) {
            $product = wc_get_product($posts[0]->ID);
            if($product->get_status() === 'trash') {
                $product->set_status('publish');
            }
            $product->set_name('رزرو کلاس: ' . $class_name);
            $product->set_regular_price($price);
            $product->save();
            return $posts[0]->ID;
        } else {
            $product = new WC_Product_Simple();
            $product->set_name('رزرو کلاس: ' . $class_name);
            $product->set_regular_price($price);
            $product->set_virtual(true);
            $product->set_catalog_visibility('hidden');
            $product->add_meta_data('_gtbp_class_id', $class_id, true);
            $product_id = $product->save();
            return $product_id;
        }
    }

    public function wc_payment_complete($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;

        $is_processed = get_post_meta($order_id, '_gtbp_processed', true);
        if ($is_processed === 'yes') return;
        
        update_post_meta($order_id, '_gtbp_processed', 'yes');

        $cart = $order->get_meta('_gtbp_pending_cart');
        if (!empty($cart) && is_array($cart)) {
            global $wpdb;
            $user_id = $order->get_customer_id();
            $user = get_userdata($user_id);
            $f = $user->first_name ? $user->first_name : $user->display_name;
            $l = $user->last_name;
            $e = $user->user_email;
            $p = get_user_meta($user_id, 'billing_phone', true);
            if(empty($p)) $p = 'ثبت نشده';

            $student_name = trim($f . ' ' . $l);
            $teacher_name = get_option('gtbp_bbb_teacher_name', self::BBB_DEFAULT_TEACHER_NAME);
            $jitsi_rooms = [];
            // Fix #8: collect temp IDs but delete them only AFTER all inserts succeed,
            // so a mid-loop failure does not orphan the user without any booking record.
            $temp_booking_ids = $order->get_meta('_gtbp_temp_booking_ids');

            foreach ($cart as $item) {
                $date = sanitize_text_field($item['date']);
                $time = sanitize_text_field($item['time']);
                $class_name = sanitize_text_field($item['class']['name']);
                if (!empty($item['package']['name'])) {
                    $class_name .= ' - ' . sanitize_text_field($item['package']['name']);
                }

                $jDate = isset($item['jDate']) ? $item['jDate'] : $this->gregorian_to_jalali_string($date);
                $room_name = $jDate . ' - ' . $student_name;
                $room = $this->create_jitsi_room($room_name, $student_name, $teacher_name);
                
                $meeting_id = $room ? $room['meeting_id'] : null;
                $student_link = $room ? $room['student_link'] : null;
                $teacher_link = $room ? $room['teacher_link'] : null;

                if ($room) {
                    $jitsi_rooms[] = [
                        'date' => $date,
                        'time' => $time,
                        'class_name' => $class_name,
                        'student_link' => $student_link,
                        'teacher_link' => $teacher_link
                    ];
                } else {
                    error_log("BigBlueButton: Failed to create room for {$room_name}");
                }

                $wpdb->insert(
                    $this->table_name,
                    [
                        'first_name' => $f,
                        'last_name' => $l,
                        'email' => $e,
                        'phone' => $p,
                        'booking_date' => $date,
                        'booking_time' => $time,
                        'class_name' => $class_name,
                        'status' => 'confirmed',
                        'roomeet_room_id' => $meeting_id,
                        'roomeet_join_link' => $student_link,
                        'bbb_moderator_link' => $teacher_link,
                        'telegram_chat_id' => (string)$order->get_meta('_gtbp_bot_chat_id'),
                        'telegram_platform' => (string)$order->get_meta('_gtbp_bot_platform')
                    ]
                );
                
                if ($wpdb->last_error) {
                    error_log("GTBP: Insert error for booking: " . $wpdb->last_error);
                }
                
                $booking_id = $wpdb->insert_id;
                if ($booking_id && class_exists('GTBP_Learning_Dashboard_v211')) { GTBP_Learning_Dashboard_v211::instance()->create_session_from_booking_id($booking_id); }
                
                if ($room && $booking_id) {
                    $booking = $wpdb->get_row("SELECT * FROM {$this->table_name} WHERE id = {$booking_id}");
                    $this->update_user_jitsi_links($user_id, $booking);
                }
            }

            // Fix #8 (continued): now that all confirmed bookings are inserted, safe to remove temp ones.
            if (!empty($temp_booking_ids) && is_array($temp_booking_ids)) {
                foreach ($temp_booking_ids as $temp_id) {
                    $wpdb->delete($this->table_name, ['id' => intval($temp_id)]);
                }
            }

            $total_price = $order->get_total();
            $this->send_booking_emails($f, $l, $e, $p, $cart, $total_price, false, true, $jitsi_rooms);

            $discount_code = $order->get_meta('_gtbp_discount_code');
            if (!empty($discount_code)) {
                $usage = (int) get_user_meta($user_id, '_gtbp_coupon_usage_' . $discount_code, true);
                update_user_meta($user_id, '_gtbp_coupon_usage_' . $discount_code, $usage + 1);
            }

            // نسخه ۱۴.۳: تسویه‌ی بدهی/طلبِ کاربر پس از پرداخت آنلاین موفق (قبلاً به‌عنوان کارمزد در سفارش اعمال شده بود).
            $applied_adj = $order->get_meta('_gtbp_user_adjustment_applied');
            $already_settled = $order->get_meta('_gtbp_adjustment_settled');
            if ($applied_adj !== '' && intval($applied_adj) != 0 && $already_settled !== 'yes') {
                $current_adj = $this->get_user_adjustment($user_id);
                $this->set_user_adjustment($user_id, $current_adj - intval($applied_adj));
                $order->update_meta_data('_gtbp_adjustment_settled', 'yes');
                $order->save();
            }
        }
    }

    public function custom_wc_return_url($return_url, $order) {
        $source_url = $order->get_meta('_gtbp_source_url');
        if (!empty($source_url)) {
            return add_query_arg(['gtbp_success' => '1', 'order_id' => $order->get_id()], $source_url) . '#gls-mobile-book-class';
        }
        return $return_url;
    }

    public function wc_payment_failed_redirect($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        $source_url = $order->get_meta('_gtbp_source_url');
        if (!empty($source_url)) {
            $redirect = add_query_arg(['gtbp_payment_failed' => '1', 'order_id' => $order_id], $source_url) . '#gls-mobile-book-class';
            if (!headers_sent()) {
                wp_safe_redirect($redirect);
                exit;
            }
        }
    }

    public function custom_wc_cancel_url($cancel_url, $order) {
        if (!is_a($order, 'WC_Order')) return $cancel_url;
        $source_url = $order->get_meta('_gtbp_source_url');
        if (!empty($source_url)) {
            return add_query_arg(['gtbp_payment_failed' => '1', 'order_id' => $order->get_id()], $source_url) . '#gls-mobile-book-class';
        }
        return $cancel_url;
    }

    public function disable_wc_emails_for_bookings($recipient, $order) {
        if ( ! is_a( $order, 'WC_Order' ) ) return $recipient;
        $cart = $order->get_meta('_gtbp_pending_cart');
        if (!empty($cart)) {
            return ''; 
        }
        return $recipient;
    }

    /* ==========================================================================
       سازنده و هوک‌ها
       ========================================================================== */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'german_bookings';
        
        if (get_option('gtbp_payment_online_enabled') === false) {
            update_option('gtbp_payment_online_enabled', '1');
        }
        if (get_option('gtbp_payment_card_enabled') === false) {
            update_option('gtbp_payment_card_enabled', '1');
        }
        
        if (get_option('gtbp_bbb_url') === false) update_option('gtbp_bbb_url', self::BBB_DEFAULT_URL);
        if (get_option('gtbp_bbb_secret') === false) update_option('gtbp_bbb_secret', self::BBB_DEFAULT_SECRET);
        if (get_option('gtbp_bbb_teacher_name') === false) update_option('gtbp_bbb_teacher_name', self::BBB_DEFAULT_TEACHER_NAME);
        // Fix #5: default passwords 'ap'/'mp' were trivially guessable; generate random values instead.
        if (get_option('gtbp_bbb_attendee_password') === false) update_option('gtbp_bbb_attendee_password', wp_generate_password(16, false));
        if (get_option('gtbp_bbb_moderator_password') === false) update_option('gtbp_bbb_moderator_password', wp_generate_password(20, false));
        if (get_option('gtbp_bbb_auto_create_enabled') === false) update_option('gtbp_bbb_auto_create_enabled', '1');
        if (get_option('gtbp_personal_bbb_url') === false) update_option('gtbp_personal_bbb_url', '');
        if (get_option('gtbp_personal_bbb_secret') === false) update_option('gtbp_personal_bbb_secret', '');
        if (get_option('gtbp_personal_bbb_teacher_name') === false) update_option('gtbp_personal_bbb_teacher_name', get_option('gtbp_bbb_teacher_name', self::BBB_DEFAULT_TEACHER_NAME));
        if (get_option('gtbp_personal_bbb_attendee_password') === false) update_option('gtbp_personal_bbb_attendee_password', wp_generate_password(16, false));
        if (get_option('gtbp_personal_bbb_moderator_password') === false) update_option('gtbp_personal_bbb_moderator_password', wp_generate_password(20, false));
        if (get_option('gtbp_personal_bbb_auto_create_enabled') === false) update_option('gtbp_personal_bbb_auto_create_enabled', '1');
        if (get_option('gtbp_booking_packages') === false) {
            update_option('gtbp_booking_packages', []);
        }

        if (get_option('gtbp_performance_setup_v9') !== 'done') {
            $this->ensure_table_columns();
            
            $this->schedule_payment_checks();
            $this->schedule_reminder_cron();
            $this->schedule_bbb_cron();
            $this->schedule_link_cleanup();
            $this->schedule_bot_payment_reminders();
            $this->schedule_upcoming_class_links();
            $this->schedule_class_start_reminders();
            
            update_option('gtbp_performance_setup_v9', 'done');
        }
        // Fix #6: cron scheduling was duplicated outside this block, causing 5 extra DB queries
        // on every page load. All scheduling now lives inside activate_plugin() and the one-time
        // setup block above; the duplicate calls below have been removed.

        add_action('phpmailer_init', array($this, 'setup_smtp'));
        register_activation_hook(__FILE__, array($this, 'activate_plugin'));
        
        add_action('init', array($this, 'init_plugin_hooks'));
        add_action('init', array($this, 'handle_reminder_confirmation'));
        add_action('init', array($this, 'handle_bbb_join_gateway'));
        add_action('init', array($this, 'handle_roomeet_join_gateway'));
        
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_menu', array($this, 'hide_legacy_provider_submenus'), 999);
        add_action('admin_init', array($this, 'handle_admin_actions'));
        add_action('admin_head', array($this, 'admin_styles'));
        add_action('admin_enqueue_scripts', array($this, 'admin_enqueue_media_for_bbb'));
        add_filter('cron_schedules', array($this, 'add_custom_cron_schedules'));
        
        add_action('admin_init', array($this, 'check_table_structure'));
        add_action('admin_init', array($this, 'maybe_backfill_roomeet_markers'));

        add_action('wp_ajax_gtbp_get_slots', array($this, 'ajax_get_slots'));
        add_action('wp_ajax_nopriv_gtbp_get_slots', array($this, 'ajax_get_slots'));
        add_action('wp_ajax_gtbp_submit_cart', array($this, 'ajax_submit_cart'));
        add_action('wp_ajax_nopriv_gtbp_submit_cart', array($this, 'ajax_submit_cart'));
        add_action('wp_ajax_gtbp_validate_coupon', array($this, 'validate_coupon'));
        add_action('wp_ajax_nopriv_gtbp_validate_coupon', array($this, 'validate_coupon'));
        add_action('wp_ajax_gtbp_get_calendar_status', array($this, 'ajax_get_calendar_status'));
        add_action('wp_ajax_nopriv_gtbp_get_calendar_status', array($this, 'ajax_get_calendar_status'));
        
        add_action('wp_ajax_gtbp_confirm_card_payment', array($this, 'ajax_confirm_card_payment'));
        add_action('wp_ajax_gtbp_outside_iran', array($this, 'ajax_outside_iran'));
        add_action('wp_ajax_gtbp_confirm_pending_payment', array($this, 'ajax_confirm_pending_payment'));
        add_action('wp_ajax_gtbp_roomeet_backfill_recordings', array($this, 'ajax_roomeet_backfill_recordings'));
        add_action('wp_ajax_gtbp_meet_backfill_recordings', array($this, 'ajax_meet_backfill_recordings'));
        
        add_action('gtbp_check_pending_payments', array($this, 'check_pending_card_payments'));
        add_action('gtbp_send_payment_reminders', array($this, 'send_payment_reminders'));
        add_action('gtbp_check_bbb_recordings', array($this, 'check_bbb_recordings'));
        add_action('gtbp_cleanup_class_links', array($this, 'cleanup_old_jitsi_links'));
        add_action('gtbp_send_bot_payment_reminders', array($this, 'send_bot_payment_reminders'));
        add_action('gtbp_send_upcoming_class_links', array($this, 'send_upcoming_class_links'));
        add_action('gtbp_send_class_start_reminders', array($this, 'send_class_start_reminders'));
        
        add_action('update_option_gtbp_classes', array($this, 'sync_all_classes_to_wc'), 10, 3);
        add_action('woocommerce_payment_complete', array($this, 'wc_payment_complete'));
        add_filter('woocommerce_get_return_url', array($this, 'custom_wc_return_url'), 10, 2);
        add_action('woocommerce_order_status_failed', array($this, 'wc_payment_failed_redirect'));
        add_action('woocommerce_order_status_cancelled', array($this, 'wc_payment_failed_redirect'));
        add_filter('woocommerce_get_cancel_order_url_raw', array($this, 'custom_wc_cancel_url'), 10, 2);
        add_filter('woocommerce_email_recipient_new_order', array($this, 'disable_wc_emails_for_bookings'), 10, 2);
        add_filter('woocommerce_email_recipient_customer_processing_order', array($this, 'disable_wc_emails_for_bookings'), 10, 2);
        add_filter('woocommerce_email_recipient_customer_completed_order', array($this, 'disable_wc_emails_for_bookings'), 10, 2);
        
        add_filter('manage_gtbp_bookings_page_gtbp_bookings_columns', array($this, 'add_sortable_columns'));

        add_action('wp_head', array($this, 'inject_custom_fonts'));
        add_action('admin_head', array($this, 'inject_custom_fonts'));
        
        // افزودن هوک‌های جدید بات
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('admin_menu', array($this, 'add_bot_admin_menus'), 20);
        add_action('admin_init', array($this, 'handle_bot_admin_actions'));
        add_action('gtbp_learning_notification_created', array($this, 'send_learning_notification_to_telegram'), 10, 5);
        add_action('gtbp_internal_admin_event', array($this, 'handle_internal_admin_event'), 10, 3);
    }

    public function inject_custom_fonts() {
        if ( ! is_admin() ) {
            global $post;
            if ( ! is_a( $post, 'WP_Post' ) || ! has_shortcode( $post->post_content, 'german_teacher_booking' ) ) {
                return;
            }
        }

        $font_candidates = [
            ['dir'=>plugin_dir_path(__FILE__).'assets/fonts/','url'=>plugin_dir_url(__FILE__).'assets/fonts/','base'=>'IRANSansXFaNum-MediumD4'],
            ['dir'=>WP_CONTENT_DIR.'/plugins/wp-smart-dictionary/assets/fonts/','url'=>content_url('/plugins/wp-smart-dictionary/assets/fonts/'),'base'=>'IRANSansXFaNum'],
        ];
        $font_url = '';
        $font_base = '';
        foreach ($font_candidates as $fc) {
            if (file_exists($fc['dir'].$fc['base'].'.woff2') || file_exists($fc['dir'].$fc['base'].'.woff') || file_exists($fc['dir'].$fc['base'].'.ttf')) {
                $font_url = trailingslashit($fc['url']);
                $font_base = $fc['base'];
                break;
            }
        }
        $font_face = '';
        if ($font_url && $font_base) {
            $src = 'src: url("' . esc_url($font_url . $font_base . '.woff2') . '") format("woff2"), url("' . esc_url($font_url . $font_base . '.woff') . '") format("woff"), url("' . esc_url($font_url . $font_base . '.ttf') . '") format("truetype"); font-style: normal; font-display: swap;';
            $font_face = '@font-face{font-family:"IRANSansXFaNum";'.$src.'font-weight:400;}@font-face{font-family:"IRANSansXFaNum";'.$src.'font-weight:500;}@font-face{font-family:"IRANSansXFaNum";'.$src.'font-weight:700;}@font-face{font-family:"IRANSansXFaNum";'.$src.'font-weight:900;}';
        }
        $css = $font_face . '
            .gtbp-wrapper, .gtbp-wrapper *,
            .gtbp-admin-wrap, .gtbp-admin-wrap * {
                font-family: "IRANSansXFaNum", Tahoma, Arial, sans-serif !important;
            }
        ';
        wp_register_style( 'gtbp-fonts', false );
        wp_enqueue_style( 'gtbp-fonts' );
        wp_add_inline_style( 'gtbp-fonts', $css );
    }

    public function ensure_table_columns() {
        global $wpdb;
        $table = $this->table_name;
        
        // Fix #10: use prepared statement for SHOW TABLES LIKE.
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
            return;
        }

        $required_columns = [
            'outside_iran' => "ALTER TABLE $table ADD outside_iran tinyint(1) NOT NULL DEFAULT 0 AFTER status",
            'roomeet_room_id' => "ALTER TABLE $table ADD roomeet_room_id varchar(100) DEFAULT NULL AFTER outside_iran",
            'roomeet_join_link' => "ALTER TABLE $table ADD roomeet_join_link text DEFAULT NULL AFTER roomeet_room_id",
            'roomeet_recording_link' => "ALTER TABLE $table ADD roomeet_recording_link text DEFAULT NULL AFTER roomeet_join_link",
            'roomeet_recording_checked' => "ALTER TABLE $table ADD roomeet_recording_checked datetime DEFAULT NULL AFTER roomeet_recording_link",
            'bbb_moderator_link' => "ALTER TABLE $table ADD bbb_moderator_link text DEFAULT NULL AFTER roomeet_recording_link",
            'telegram_chat_id' => "ALTER TABLE $table ADD telegram_chat_id varchar(50) DEFAULT NULL AFTER bbb_moderator_link",
            'telegram_platform' => "ALTER TABLE $table ADD telegram_platform varchar(20) DEFAULT NULL AFTER telegram_chat_id",
            'telegram_payment_reminder_sent' => "ALTER TABLE $table ADD telegram_payment_reminder_sent tinyint(1) NOT NULL DEFAULT 0 AFTER telegram_platform",
            'telegram_class_link_sent' => "ALTER TABLE $table ADD telegram_class_link_sent tinyint(1) NOT NULL DEFAULT 0 AFTER telegram_payment_reminder_sent",
            'telegram_class_reminder_sent' => "ALTER TABLE $table ADD telegram_class_reminder_sent tinyint(1) NOT NULL DEFAULT 0 AFTER telegram_class_link_sent",
            'class_provider' => "ALTER TABLE $table ADD class_provider varchar(20) DEFAULT NULL",
            'rmt_meeting_id' => "ALTER TABLE $table ADD rmt_meeting_id varchar(191) DEFAULT NULL",
            'rmt_recording_link' => "ALTER TABLE $table ADD rmt_recording_link text DEFAULT NULL",
            'rmt_recording_download' => "ALTER TABLE $table ADD rmt_recording_download text DEFAULT NULL",
            'rmt_recording_checked' => "ALTER TABLE $table ADD rmt_recording_checked datetime DEFAULT NULL",
            'bbb_presentation_link' => "ALTER TABLE $table ADD bbb_presentation_link text DEFAULT NULL",
            'bbb_video_link' => "ALTER TABLE $table ADD bbb_video_link text DEFAULT NULL",
        ];

        $existing_columns = $wpdb->get_col("SHOW COLUMNS FROM $table");

        foreach ($required_columns as $column => $sql) {
            if (!in_array($column, $existing_columns)) {
                $wpdb->query($sql);
                if ($wpdb->last_error) {
                    error_log("GTBP: Error adding column $column: " . $wpdb->last_error);
                }
            }
        }
    }

    public function check_table_structure() {
        $this->ensure_table_columns();
    }

    public function schedule_payment_checks() {
        if (!wp_next_scheduled('gtbp_check_pending_payments')) {
            wp_schedule_event(time(), 'hourly', 'gtbp_check_pending_payments');
        }
    }
    
    public function schedule_reminder_cron() {
        if (!wp_next_scheduled('gtbp_send_payment_reminders')) {
            wp_schedule_event(time(), 'hourly', 'gtbp_send_payment_reminders');
        }
    }
    public function schedule_bbb_cron() {
        if (!wp_next_scheduled('gtbp_check_bbb_recordings')) {
            wp_schedule_event(time() + 300, 'gtbp_thirty_minutes', 'gtbp_check_bbb_recordings');
        }
    }

    public function add_custom_cron_schedules($schedules) {
        if (!isset($schedules['gtbp_five_minutes'])) {
            $schedules['gtbp_five_minutes'] = ['interval' => 300, 'display' => 'Every Five Minutes'];
        }
        if (!isset($schedules['gtbp_thirty_minutes'])) {
            $schedules['gtbp_thirty_minutes'] = ['interval' => 1800, 'display' => 'Every Thirty Minutes'];
        }
        return $schedules;
    }

    public function schedule_bot_payment_reminders() {
        if (!wp_next_scheduled('gtbp_send_bot_payment_reminders')) {
            wp_schedule_event(time(), 'hourly', 'gtbp_send_bot_payment_reminders');
        }
    }

    public function schedule_upcoming_class_links() {
        if (!wp_next_scheduled('gtbp_send_upcoming_class_links')) {
            wp_schedule_event(time(), 'gtbp_five_minutes', 'gtbp_send_upcoming_class_links');
        }
    }


    public function schedule_class_start_reminders() {
        if (!wp_next_scheduled('gtbp_send_class_start_reminders')) {
            wp_schedule_event(time(), 'gtbp_five_minutes', 'gtbp_send_class_start_reminders');
        }
    }
    
    public function schedule_link_cleanup() {
        if (!wp_next_scheduled('gtbp_cleanup_class_links')) {
            wp_schedule_event(time(), 'daily', 'gtbp_cleanup_class_links');
        }
    }

    public function init_plugin_hooks() {
        add_shortcode('german_teacher_booking', array($this, 'render_booking_frontend'));
        add_shortcode('german_teacher_user_bookings', array($this, 'render_user_bookings'));
        add_shortcode('german_teacher_recordings', array($this, 'render_user_recordings'));
    }

    public function activate_plugin() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $this->table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            first_name varchar(50) NOT NULL,
            last_name varchar(50) NOT NULL,
            email varchar(100) NOT NULL,
            phone varchar(20) NOT NULL,
            booking_date date NOT NULL,
            booking_time varchar(50) NOT NULL,
            class_name varchar(100) NOT NULL,
            status varchar(20) DEFAULT 'confirmed' NOT NULL,
            outside_iran tinyint(1) DEFAULT 0 NOT NULL,
            roomeet_room_id varchar(100) DEFAULT NULL,
            roomeet_join_link text DEFAULT NULL,
            roomeet_recording_link text DEFAULT NULL,
            roomeet_recording_checked datetime DEFAULT NULL,
            class_provider varchar(20) DEFAULT NULL,
            rmt_meeting_id varchar(191) DEFAULT NULL,
            rmt_recording_link text DEFAULT NULL,
            rmt_recording_download text DEFAULT NULL,
            rmt_recording_checked datetime DEFAULT NULL,
            bbb_presentation_link text DEFAULT NULL,
            bbb_video_link text DEFAULT NULL,
            bbb_moderator_link text DEFAULT NULL,
            telegram_chat_id varchar(50) DEFAULT NULL,
            telegram_platform varchar(20) DEFAULT NULL,
            telegram_payment_reminder_sent tinyint(1) DEFAULT 0 NOT NULL,
            telegram_class_link_sent tinyint(1) DEFAULT 0 NOT NULL,
            telegram_class_reminder_sent tinyint(1) DEFAULT 0 NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
            PRIMARY KEY  (id),
            KEY idx_booking_date (booking_date),
            KEY idx_status (status),
            KEY idx_recording (roomeet_recording_link)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);

        $default_slots = "09:00-10:00, 10:30-11:30, 12:00-13:00, 13:30-14:30, 15:00-16:00";
        if (!get_option('gtbp_working_hours')) {
            update_option('gtbp_working_hours', [
                ['day' => 6, 'slots' => $default_slots, 'priority_slots' => '', 'active' => true],
                ['day' => 0, 'slots' => $default_slots, 'priority_slots' => '', 'active' => true],
                ['day' => 1, 'slots' => $default_slots, 'priority_slots' => '', 'active' => true],
                ['day' => 2, 'slots' => $default_slots, 'priority_slots' => '', 'active' => true],
                ['day' => 3, 'slots' => $default_slots, 'priority_slots' => '', 'active' => true],
                ['day' => 4, 'slots' => '', 'priority_slots' => '', 'active' => false],
                ['day' => 5, 'slots' => '', 'priority_slots' => '', 'active' => false]
            ]);
        }

        if (!get_option('gtbp_holidays')) update_option('gtbp_holidays', []);
        if (get_option('gtbp_user_working_hours') === false) update_option('gtbp_user_working_hours', []);
        if (!get_option('gtbp_bank_info')) update_option('gtbp_bank_info', ['card' => '1234-5678-9012-3456', 'owner' => 'نام شما']);
        if (!get_option('gtbp_classes')) {
            update_option('gtbp_classes', [
                ['id' => uniqid(), 'name' => 'خصوصی', 'price' => 500000],
                ['id' => uniqid(), 'name' => 'دو نفره', 'price' => 700000]
            ]);
        }
        
        if (!get_option('gtbp_bbb_url')) update_option('gtbp_bbb_url', self::BBB_DEFAULT_URL);
        if (get_option('gtbp_bbb_secret') === false) update_option('gtbp_bbb_secret', self::BBB_DEFAULT_SECRET);
        if (!get_option('gtbp_bbb_teacher_name')) update_option('gtbp_bbb_teacher_name', self::BBB_DEFAULT_TEACHER_NAME);
        if (get_option('gtbp_bbb_attendee_password') === false) update_option('gtbp_bbb_attendee_password', wp_generate_password(16, false));
        if (get_option('gtbp_bbb_moderator_password') === false) update_option('gtbp_bbb_moderator_password', wp_generate_password(20, false));
        if (get_option('gtbp_bbb_auto_create_enabled') === false) update_option('gtbp_bbb_auto_create_enabled', '1');
        if (get_option('gtbp_booking_packages') === false) update_option('gtbp_booking_packages', []);

        if (get_option('gtbp_payment_online_enabled') === false) update_option('gtbp_payment_online_enabled', '1');
        if (get_option('gtbp_payment_card_enabled') === false) update_option('gtbp_payment_card_enabled', '1');
        
        // مقداردهی اولیه گزینه‌های ربات
        if (get_option('gtbp_telegram_token') === false) update_option('gtbp_telegram_token', '');
        if (get_option('gtbp_telegram_enabled') === false) update_option('gtbp_telegram_enabled', 0);
        if (get_option('gtbp_telegram_secret') === false) update_option('gtbp_telegram_secret', '');
        if (get_option('gtbp_bot_welcome_message') === false) update_option('gtbp_bot_welcome_message', 'سلام! برای رزرو کلاس، لطفاً ایمیل خود را وارد کنید:');
    }

    /* ==========================================================================
       BIGBLUEBUTTON API METHODS (با قابلیت تنظیمات پیشرفته و نام واقعی)
       ========================================================================== */
    /* ==========================================================================
       JITSI MEET INTEGRATION METHODS
       ========================================================================== */

    private function normalize_bbb_base_url($url = '') {
        $url = trim($url);
        if (empty($url)) $url = self::BBB_DEFAULT_URL;
        if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
        $url = rtrim($url, "/ \t\n\r\0\x0B") . '/';
        if (substr($url, -15) !== '/bigbluebutton/') $url = rtrim($url, '/') . '/bigbluebutton/';
        return $url;
    }

    private function bbb_option($name, $provider = 'bbb', $default = '') {
        $prefix = ($provider === 'bbb_personal') ? 'gtbp_personal_bbb_' : 'gtbp_bbb_';
        return get_option($prefix . $name, $default);
    }

    private function bbb_checksum($call_name, $query_string = '', $provider = 'bbb') {
        $secret = trim((string)$this->bbb_option('secret', $provider, self::BBB_DEFAULT_SECRET));
        return sha1($call_name . $query_string . $secret);
    }

    private function bbb_api_url($call_name, $params = [], $provider = 'bbb') {
        $base = $this->normalize_bbb_base_url($this->bbb_option('url', $provider, self::BBB_DEFAULT_URL));
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return $base . 'api/' . $call_name . '?' . $query . '&checksum=' . $this->bbb_checksum($call_name, $query, $provider);
    }

    private function bbb_health_diagnostic($url = null, $secret = null, $provider = 'bbb') {
        $prefix = ($provider === 'bbb_personal') ? 'gtbp_personal_bbb_' : 'gtbp_bbb_';
        if ($url !== null) update_option($prefix . 'url', $this->normalize_bbb_base_url($url));
        if ($secret !== null) update_option($prefix . 'secret', trim($secret));
        $endpoint = $this->bbb_api_url('getMeetings', [], $provider);
        $safe_endpoint = preg_replace('/([?&]checksum=)[^&]+/i', '$1[hidden]', $endpoint);
        $response = wp_remote_get($endpoint, ['timeout' => 20, 'sslverify' => true]);
        if (is_wp_error($response)) return ['ok'=>false,'kind'=>'wordpress_http','message'=>$response->get_error_message(),'endpoint'=>$safe_endpoint,'http_code'=>0];
        $http_code = intval(wp_remote_retrieve_response_code($response));
        $body = wp_remote_retrieve_body($response);
        $ok = $http_code > 0 && $http_code < 400 && stripos($body, '<returncode>SUCCESS</returncode>') !== false;
        $message = '';
        if (!$ok) {
            if (preg_match('#<message>(.*?)</message>#is', $body, $match)) $message = wp_strip_all_tags($match[1]);
            if ($message === '' && preg_match('#<messageKey>(.*?)</messageKey>#is', $body, $match)) $message = wp_strip_all_tags($match[1]);
            if ($message === '') $message = 'پاسخ معتبر SUCCESS از BigBlueButton دریافت نشد.';
        }
        return ['ok'=>$ok,'kind'=>$ok?'success':'bbb_response','message'=>$message,'endpoint'=>$safe_endpoint,'http_code'=>$http_code];
    }

    private function bbb_health_check($url = null, $secret = null, $provider = 'bbb') {
        $diagnostic = $this->bbb_health_diagnostic($url, $secret, $provider);
        return !empty($diagnostic['ok']);
    }

    private function create_jitsi_room($room_name, $student_name = null, $teacher_name = null, $force_service = null) {
        // نام متد برای سازگاری داخلی حفظ شده.
        // نسخه ۱۴.۰: بر اساس «سرویس فعال» دیسپچ می‌شود (یا سرویس تحمیل‌شده برای جلسه فوری). پیش‌فرض BBB.
        $svc = ($force_service && in_array($force_service, ['bbb', 'bbb_personal', 'meet', 'roomeet'], true)) ? $force_service : $this->get_active_service();

        // --- Roomeet ---
        // نسخه ۱۴.۵: اتاق را اینجا (قبل از وجود booking_id) نمی‌سازیم. مارکر pending برمی‌گردانیم و
        // ساختِ واقعیِ اتاق را به roomeet_provision_room می‌سپاریم که نام اتاق را «نامِ زبان‌آموز - تاریخ #شماره‌رزرو»
        // می‌گذارد (رفع مشکل نام اتاق + جلوگیری از اتاق تکراری، چون فقط یک مسیرِ ساخت وجود دارد).
        if ($svc === 'roomeet') {
            return ['meeting_id' => 'rmt:pending', 'student_link' => '', 'teacher_link' => ''];
        }

        // --- Google Meet: تاریخ/زمان در create_jitsi_room نیست؛ ساخت واقعی بعد از درج (ensure) انجام می‌شود. ---
        if ($svc === 'meet') {
            return ['meeting_id' => 'meet:pending', 'student_link' => '', 'teacher_link' => ''];
        }

        // سرور شخصی هنگام اولین ورود ساخته می‌شود تا شناسه رزرو، نام زبان آموز و تاریخ
        // پیش از ایجاد جلسه در metadata ضبط ثبت شده باشند.
        if ($svc === 'bbb_personal') {
            return ['meeting_id' => 'gtbp-personal-' . substr(hash('sha256', $room_name . microtime(true) . wp_rand()), 0, 18), 'student_link' => '', 'teacher_link' => ''];
        }

        // --- BBB (پیش‌فرض) ---
        // سازگاری قبلی: اگر BBB خاموش بود ولی Meet فعال (بدون انتخاب سرویس صریح).
        $bbb_on = ($this->bbb_option('auto_create_enabled', $svc, '1') === '1');
        if (in_array($svc, ['bbb', 'bbb_personal'], true) && !$bbb_on && !$force_service) {
            if ($this->meet_available() && GTBP_Google_Meet_Provider::instance()->auto_create_on()) {
                return ['meeting_id' => 'meet:pending', 'student_link' => '', 'teacher_link' => ''];
            }
            return false;
        }
        $meeting_id = 'gtbp-' . substr(hash('sha256', $room_name . microtime(true) . wp_rand()), 0, 18);
        $attendee_pw = $this->bbb_option('attendee_password', $svc, 'ap');
        $moderator_pw = $this->bbb_option('moderator_password', $svc, 'mp');
        $teacher_final_name = !empty($teacher_name) ? $teacher_name : $this->bbb_option('teacher_name', $svc, self::BBB_DEFAULT_TEACHER_NAME);
        $student_final_name = !empty($student_name) ? $student_name : 'دانش‌آموز';
        $params = [
            'name' => $room_name,
            'meetingID' => $meeting_id,
            'attendeePW' => $attendee_pw,
            'moderatorPW' => $moderator_pw,
            'record' => 'true',
            'autoStartRecording' => 'false',
            'allowStartStopRecording' => 'true',
            'logoutURL' => home_url(),
            'guestPolicy' => 'ALWAYS_ACCEPT',
            'muteOnStart' => 'true',
            'allowModsToUnmuteUsers' => 'true',
        ];
        $response = wp_remote_get($this->bbb_api_url('create', $params, $svc), ['timeout' => 20, 'sslverify' => true]);
        if (is_wp_error($response)) { $this->log_event('bbb_error','create_room',0,$response->get_error_message()); error_log('BBB create room error: ' . $response->get_error_message()); return false; }
        $body = wp_remote_retrieve_body($response);
        if (stripos($body, '<returncode>SUCCESS</returncode>') === false && stripos($body, '<messageKey>idNotUnique</messageKey>') === false) {
            $this->log_event('bbb_error','create_room',0,$body); error_log('BBB create room failed: ' . $body); return false;
        }
        return ['meeting_id' => $meeting_id, 'student_link' => $this->get_bbb_join_url($meeting_id, $student_final_name, $attendee_pw, $svc), 'teacher_link' => $this->get_bbb_join_url($meeting_id, $teacher_final_name, $moderator_pw, $svc)];
    }

    private function get_bbb_join_url($meeting_id, $name, $password, $provider = 'bbb') {
        return $this->bbb_api_url('join', [
            'fullName' => $name,
            'meetingID' => $meeting_id,
            'password' => $password,
            'redirect' => 'true',
            'joinViaHtml5' => 'true',
            'userdata-bbb_skip_video_preview' => 'true'
        ], $provider);
    }

    private function bbb_gateway_token($booking_id, $role) {
        return hash_hmac('sha256', intval($booking_id) . '|' . sanitize_key($role), wp_salt('auth'));
    }

    private function bbb_booking_gateway_url($booking_id, $role = 'student') {
        $role = ($role === 'teacher') ? 'teacher' : 'student';
        return add_query_arg([
            'gtbp_bbb_join' => intval($booking_id),
            'role' => $role,
            'token' => $this->bbb_gateway_token($booking_id, $role),
        ], home_url('/'));
    }

    private function create_fixed_bbb_meeting($meeting_id, $room_name, $student_name = 'دانش‌آموز', $provider = 'bbb', $booking = null) {
        $meeting_id = trim((string)$meeting_id);
        if ($meeting_id === '') return false;
        if ($this->bbb_option('auto_create_enabled', $provider, '1') !== '1') return false;
        $attendee_pw = $this->bbb_option('attendee_password', $provider, 'ap');
        $moderator_pw = $this->bbb_option('moderator_password', $provider, 'mp');
        $params = [
            'name' => $room_name ?: ('کلاس آلمانی - ' . $meeting_id),
            'meetingID' => $meeting_id,
            'attendeePW' => $attendee_pw,
            'moderatorPW' => $moderator_pw,
            'record' => 'true',
            'autoStartRecording' => 'false',
            'allowStartStopRecording' => 'true',
            'logoutURL' => home_url(),
            'guestPolicy' => 'ALWAYS_ACCEPT',
            'muteOnStart' => 'true',
            'allowModsToUnmuteUsers' => 'true',
        ];
        if ($booking) {
            $params['meta_gtbp_booking_id'] = intval($booking->id);
            $params['meta_gtbp_student_name'] = trim(($booking->first_name ?? '') . ' ' . ($booking->last_name ?? ''));
            $params['meta_gtbp_session_date'] = !empty($booking->booking_date) ? $this->gregorian_to_jalali_string($booking->booking_date) : '';
        }
        $response = wp_remote_get($this->bbb_api_url('create', $params, $provider), ['timeout' => 20, 'sslverify' => true]);
        if (is_wp_error($response)) { $this->log_event('bbb_error','fixed_create',0,$response->get_error_message()); error_log('BBB fixed create error: ' . $response->get_error_message()); return false; }
        $body = wp_remote_retrieve_body($response);
        if (stripos($body, '<returncode>SUCCESS</returncode>') === false && stripos($body, '<messageKey>idNotUnique</messageKey>') === false) {
            $this->log_event('bbb_error','fixed_create',0,$body); error_log('BBB fixed create failed: ' . $body);
            return false;
        }
        return true;
    }

    private function create_standalone_bbb_room($room_name, $student_name, $teacher_name, $provider) {
        if (!in_array($provider, ['bbb', 'bbb_personal'], true)) return false;
        $meeting_id = ($provider === 'bbb_personal' ? 'gtbp-personal-instant-' : 'gtbp-instant-') . substr(hash('sha256', $room_name . microtime(true) . wp_rand()), 0, 18);
        if (!$this->create_fixed_bbb_meeting($meeting_id, $room_name, $student_name, $provider, null)) return false;
        $attendee_pw = $this->bbb_option('attendee_password', $provider, 'ap');
        $moderator_pw = $this->bbb_option('moderator_password', $provider, 'mp');
        return [
            'meeting_id' => $meeting_id,
            'student_link' => $this->get_bbb_join_url($meeting_id, $student_name, $attendee_pw, $provider),
            'teacher_link' => $this->get_bbb_join_url($meeting_id, $teacher_name, $moderator_pw, $provider),
        ];
    }

    private function ensure_booking_gateway_links($booking_or_id) {
        global $wpdb;
        $booking = is_object($booking_or_id) ? $booking_or_id : $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id=%d", intval($booking_or_id)));
        if (!$booking) return false;

        $raw_id = trim((string)($booking->roomeet_room_id ?? ''));

        // --- نسخه ۱۴.۲: مارکر Roomeet (مقاوم؛ حتی pending هم لینک gateway می‌گیرد) ---
        if (strpos($raw_id, 'rmt:') === 0 || (isset($booking->class_provider) && $booking->class_provider === 'roomeet')) {
            $marker = strpos($raw_id, 'rmt:') === 0 ? substr($raw_id, 4) : '';
            $the_id = ($marker !== '' && $marker !== 'pending') ? $marker : trim((string)($booking->rmt_meeting_id ?? ''));
            // اگر theId هنوز نداریم (pending)، gateway ساخته می‌شود و اتاق هنگام اولین کلیک ساخته خواهد شد
            $wpdb->update($this->table_name, [
                'class_provider'    => 'roomeet',
                'rmt_meeting_id'    => ($the_id !== '' ? $the_id : null),
                'roomeet_room_id'   => null,
                'roomeet_join_link' => $this->roomeet_booking_gateway_url($booking->id, 'student'),
                'bbb_moderator_link'=> $this->roomeet_booking_gateway_url($booking->id, 'teacher'),
            ], ['id' => intval($booking->id)]);
            return true;
        }

        // --- نسخه ۱۴.۰: مارکر Meet در انتظار (تاریخ/زمان از رکورد رزرو) ---
        if ($raw_id === 'meet:pending' || (isset($booking->class_provider) && $booking->class_provider === 'meet' && empty($booking->roomeet_join_link))) {
            if ($this->meet_available() && !empty($booking->booking_time) && strpos($booking->booking_time, '-') !== false) {
                $title = ($booking->class_name ?: 'کلاس') . ' - ' . trim($booking->first_name . ' ' . $booking->last_name);
                $res = GTBP_Google_Meet_Provider::instance()->create_meeting_for_booking([
                    'booking_id'     => intval($booking->id),
                    'date'           => $booking->booking_date,
                    'time'           => $booking->booking_time,
                    'title'          => $title,
                    'attendee_email' => $booking->email,
                ]);
                if ($res && !empty($res['join_link'])) {
                    $wpdb->update($this->table_name, [
                        'class_provider'    => 'meet',
                        'roomeet_room_id'   => null,
                        'roomeet_join_link' => esc_url_raw($res['join_link']),
                        'bbb_moderator_link'=> esc_url_raw($res['join_link']),
                    ], ['id' => intval($booking->id)]);
                    return true;
                }
            }
            return false;
        }

        // --- BBB (رفتار قبلی) ---
        $meeting_id = $raw_id;
        if ($meeting_id === '') {
            $meeting_id = $this->extract_jitsi_room_id_from_url($booking->roomeet_join_link ?? '');
            if ($meeting_id === '') $meeting_id = $this->extract_jitsi_room_id_from_url($booking->bbb_moderator_link ?? '');
        }
        if ($meeting_id === '') return false;
        $student_gateway = $this->bbb_booking_gateway_url($booking->id, 'student');
        $teacher_gateway = $this->bbb_booking_gateway_url($booking->id, 'teacher');
        $needs_update = ($booking->roomeet_room_id !== $meeting_id) || ($booking->roomeet_join_link !== $student_gateway) || ($booking->bbb_moderator_link !== $teacher_gateway);
        if ($needs_update) {
            $wpdb->update($this->table_name, [
                'roomeet_room_id' => $meeting_id,
                'roomeet_join_link' => $student_gateway,
                'bbb_moderator_link' => $teacher_gateway,
            ], ['id' => intval($booking->id)]);
        }
        return true;
    }

    public function handle_bbb_join_gateway() {
        if (empty($_GET['gtbp_bbb_join'])) return;
        $booking_id = intval($_GET['gtbp_bbb_join']);
        $role = isset($_GET['role']) && $_GET['role'] === 'teacher' ? 'teacher' : 'student';
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        if (!$booking_id || !hash_equals($this->bbb_gateway_token($booking_id, $role), $token)) {
            wp_die('لینک کلاس نامعتبر است.');
        }
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id=%d", $booking_id));
        if (!$booking) wp_die('رزرو پیدا نشد.');
        $meeting_id = trim((string)$booking->roomeet_room_id);
        if ($meeting_id === '') {
            $provider = (($booking->class_provider ?? '') === 'bbb_personal') ? 'bbb_personal' : 'bbb';
            $meeting_id = ($provider === 'bbb_personal' ? 'gtbp-personal-booking-' : 'gtbp-booking-') . intval($booking_id);
            $wpdb->update($this->table_name, ['roomeet_room_id'=>$meeting_id], ['id'=>$booking_id]);
            $booking->roomeet_room_id = $meeting_id;
            $this->ensure_booking_gateway_links($booking);
        }
        $student_name = trim(($booking->first_name ?? '') . ' ' . ($booking->last_name ?? '')) ?: 'دانش‌آموز';
        $room_name = $this->gregorian_to_jalali_string($booking->booking_date) . ' - ' . $student_name;
        $provider = (($booking->class_provider ?? '') === 'bbb_personal') ? 'bbb_personal' : 'bbb';
        if (!$this->create_fixed_bbb_meeting($meeting_id, $room_name, $student_name, $provider, $booking)) {
            wp_die('در حال حاضر امکان ساخت/ورود به کلاس وجود ندارد. لطفاً تنظیمات BigBlueButton را بررسی کنید.');
        }
        $name = ($role === 'teacher') ? $this->bbb_option('teacher_name', $provider, self::BBB_DEFAULT_TEACHER_NAME) : $student_name;
        $password = ($role === 'teacher') ? $this->bbb_option('moderator_password', $provider, 'mp') : $this->bbb_option('attendee_password', $provider, 'ap');
        wp_redirect($this->get_bbb_join_url($meeting_id, $name, $password, $provider));
        exit;
    }

    /* ==========================================================================
       سرویس فعال کلاس آنلاین (bbb | meet | roomeet) - نسخه ۱۴.۰
       پیش‌فرض 'bbb' تا رفتار فعلی سایت بدون تغییر بماند.
       ========================================================================== */
    public function get_active_service() {
        $svc = get_option('gtbp_active_class_service', 'bbb');
        return in_array($svc, ['bbb', 'bbb_personal', 'meet', 'roomeet'], true) ? $svc : 'bbb';
    }

    private function roomeet_available() {
        return class_exists('GTBP_Roomeet_Provider') && GTBP_Roomeet_Provider::instance()->is_configured();
    }
    private function meet_available() {
        return class_exists('GTBP_Google_Meet_Provider') && GTBP_Google_Meet_Provider::instance()->is_configured();
    }

    /* ---------- Roomeet gateway (مانند BBB) ---------- */
    private function roomeet_gateway_token($booking_id, $role) {
        return substr(hash_hmac('sha256', 'roomeet|' . intval($booking_id) . '|' . $role, wp_salt('auth')), 0, 24);
    }
    private function roomeet_booking_gateway_url($booking_id, $role = 'student') {
        return add_query_arg([
            'gtbp_roomeet_join' => intval($booking_id),
            'role'  => $role,
            'token' => $this->roomeet_gateway_token($booking_id, $role),
        ], home_url('/'));
    }

    public function handle_roomeet_join_gateway() {
        if (empty($_GET['gtbp_roomeet_join'])) return;
        $booking_id = intval($_GET['gtbp_roomeet_join']);
        $role = (isset($_GET['role']) && $_GET['role'] === 'teacher') ? 'teacher' : 'student';
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        if (!$booking_id || !hash_equals($this->roomeet_gateway_token($booking_id, $role), $token)) {
            wp_die('لینک کلاس نامعتبر است.');
        }
        if (!$this->roomeet_available()) wp_die('سرویس روومیت پیکربندی نشده است.');
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id=%d", $booking_id));
        if (!$booking) wp_die('رزرو پیدا نشد.');

        $roomeet = GTBP_Roomeet_Provider::instance();
        $the_id = trim((string)($booking->rmt_meeting_id ?? ''));
        // اگر مارکر rmt: هنوز تبدیل نشده، از roomeet_room_id استخراج کن (به‌جز pending)
        if ($the_id === '' && strpos((string)($booking->roomeet_room_id ?? ''), 'rmt:') === 0) {
            $marker = substr($booking->roomeet_room_id, 4);
            if ($marker !== '' && $marker !== 'pending') {
                $the_id = $marker;
                $wpdb->update($this->table_name, ['class_provider' => 'roomeet', 'rmt_meeting_id' => $the_id, 'roomeet_room_id' => null], ['id' => intval($booking->id)]);
            }
        }
        // نسخه ۱۴.۲: اگر اتاقی نداریم (خالی یا pending)، همین‌جا (۳ دقیقه قبل کلاس، هنگام کلیک) بساز
        if ($the_id === '' || $the_id === 'pending') {
            $the_id = $this->roomeet_provision_room($booking);
            if (!$the_id || $the_id === 'pending') {
                wp_die('اتاق روومیت ساخته نشد. لطفاً تنظیمات روومیت را بررسی کنید (بخش «آخرین خطا» در تب روومیت).');
            }
        }
        // نسخه ۱۴.۵: نقش‌ها جدا شدند.
        // - مدرس/ادمین: با دسترسی مدیر (moderator) و نام خودش.
        // - شاگرد: به‌صورت «مهمانِ نام‌دار» - با نام خودِ زبان‌آموز، نه نام ادمین.
        if ($role === 'teacher') {
            $link = $roomeet->ensure_and_join($the_id);
        } else {
            // اطمینان از شروع اتاق (فقط مدیر می‌تواند شروع کند)، سپس ورود به‌عنوان شاگردِ نام‌دار
            $roomeet->start_room($the_id);
            $student_name = trim(($booking->first_name ?? '') . ' ' . ($booking->last_name ?? ''));
            if ($student_name === '') $student_name = 'زبان‌آموز';
            $link = $roomeet->guest_join_link($the_id, $student_name);
            // اگر ساخت مهمان نام‌دار شکست خورد، به لینک استاندارد برگرد تا کلاس از دست نرود
            if (!$link) $link = $roomeet->get_join_link($the_id);
        }
        if (!$link) wp_die('امکان ورود به کلاس روومیت فراهم نشد. لطفاً چند لحظه بعد دوباره تلاش کنید یا تنظیمات روومیت را بررسی کنید.');
        wp_redirect($link);
        exit;
    }

    /**
     * ساخت اتاق روومیت برای یک رزرو و ذخیره theIdOfMeeting + لینک‌های gateway.
     * @return string|false theIdOfMeeting
     */
    private function roomeet_provision_room($booking, $force_new = false) {
        global $wpdb;
        // نسخه ۱۴.۳: حتی اگر روومیت هنوز کامل پیکربندی نشده باشد، لینک‌های gateway ساخته می‌شوند
        // (اتاق هنگام اولین کلیک، به‌صورت خودکار ساخته می‌شود).
        $the_id = '';
        if ($this->roomeet_available()) {
            $roomeet = GTBP_Roomeet_Provider::instance();
            // حذف اتاق قبلی در صورت تولید مجدد
            if ($force_new && !empty($booking->rmt_meeting_id)) {
                $roomeet->remove_room($booking->rmt_meeting_id);
            }
            $the_id = (!$force_new && !empty($booking->rmt_meeting_id)) ? $booking->rmt_meeting_id : '';
            if ($the_id === '') {
                // نسخه ۱۴.۵: نام اتاق با نامِ زبان‌آموز شروع می‌شود + تاریخ + شماره‌رزرو (برای شناسایی و یکتایی).
                $student_name = trim(($booking->first_name ?? '') . ' ' . ($booking->last_name ?? '')) ?: 'زبان‌آموز';
                $room_name = $student_name . ' - ' . $this->gregorian_to_jalali_string($booking->booking_date) . ' #' . intval($booking->id);
                $the_id = $roomeet->create_room($room_name);
            }
        }
        // لینک‌های gateway همیشه ساخته می‌شوند (از booking_id قابل تولیدند و مستقل از وجود اتاق کار می‌کنند)
        $student_gateway = $this->roomeet_booking_gateway_url($booking->id, 'student');
        $teacher_gateway = $this->roomeet_booking_gateway_url($booking->id, 'teacher');
        $wpdb->update($this->table_name, [
            'class_provider'    => 'roomeet',
            'rmt_meeting_id'    => ($the_id ?: null),
            'roomeet_room_id'   => null,   // پاک تا با کران BBB تداخل نکند
            'roomeet_join_link' => $student_gateway,
            'bbb_moderator_link'=> $teacher_gateway,
        ], ['id' => intval($booking->id)]);
        return $the_id ?: 'pending';
    }

    /**
     * نسخه ۱۴.۴: مهاجرت یک‌باره - تبدیل همه‌ی رزروهایی که مارکر خام 'rmt:...' دارند
     * به ستون‌های درست روومیت (class_provider/rmt_meeting_id + لینک‌های gateway).
     * لینک gateway از booking_id تولید می‌شود؛ نیازی به تماس با API نیست.
     * این کارت ادمین، صفحه‌ی دانش‌آموز و ربات را هم‌زمان درست می‌کند.
     */
    public function maybe_backfill_roomeet_markers() {
        if (get_option('gtbp_rmt_backfill_v1') === 'done') return;
        global $wpdb;
        if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $this->table_name)) !== $this->table_name) return;
        $cols = $wpdb->get_col("SHOW COLUMNS FROM {$this->table_name}");
        if (!in_array('rmt_meeting_id', $cols, true) || !in_array('class_provider', $cols, true)) return; // ستون‌ها هنوز ساخته نشده‌اند

        $rows = $wpdb->get_results("SELECT id, roomeet_room_id FROM {$this->table_name} WHERE roomeet_room_id LIKE 'rmt:%' LIMIT 5000");
        foreach ((array)$rows as $r) {
            $marker = substr((string)$r->roomeet_room_id, 4);
            $the_id = ($marker !== '' && $marker !== 'pending') ? $marker : null;
            $wpdb->update($this->table_name, [
                'class_provider'    => 'roomeet',
                'rmt_meeting_id'    => $the_id,
                'roomeet_room_id'   => null,
                'roomeet_join_link' => $this->roomeet_booking_gateway_url($r->id, 'student'),
                'bbb_moderator_link'=> $this->roomeet_booking_gateway_url($r->id, 'teacher'),
            ], ['id' => intval($r->id)]);
        }
        update_option('gtbp_rmt_backfill_v1', 'done');
    }

    private function end_bbb_meeting($meeting_id, $provider = 'bbb') {
        $meeting_id = trim((string)$meeting_id);
        if ($meeting_id === '') return false;
        $moderator_pw = $this->bbb_option('moderator_password', $provider, 'mp');
        $response = wp_remote_get($this->bbb_api_url('end', ['meetingID' => $meeting_id, 'password' => $moderator_pw], $provider), ['timeout' => 20, 'sslverify' => true]);
        if (is_wp_error($response)) {
            error_log('BBB end meeting error: ' . $response->get_error_message());
            return false;
        }
        return intval(wp_remote_retrieve_response_code($response)) < 400;
    }

    private function regenerate_booking_bbb_links($booking_id, $provider = 'bbb') {
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", intval($booking_id)));
        if (!$booking) { $this->log_event('booking_error','regenerate_links',intval($booking_id),'رزرو پیدا نشد.'); return false; }
        if (!in_array($provider, ['bbb', 'bbb_personal'], true)) $provider = 'bbb';
        if (!empty($booking->roomeet_room_id) && in_array((string)($booking->class_provider ?? ''), ['bbb', 'bbb_personal'], true)) {
            $this->end_bbb_meeting($booking->roomeet_room_id, (string)$booking->class_provider);
        }
        $student_name = trim($booking->first_name . ' ' . $booking->last_name);
        if ($student_name === '') $student_name = 'دانش‌آموز';
        $teacher_name = $this->bbb_option('teacher_name', $provider, self::BBB_DEFAULT_TEACHER_NAME);
        $room_name = $this->gregorian_to_jalali_string($booking->booking_date) . ' - ' . $student_name;
        if ($provider === 'bbb_personal') {
            $personal_url = trim((string)$this->bbb_option('url', 'bbb_personal', ''));
            $personal_secret = trim((string)$this->bbb_option('secret', 'bbb_personal', ''));
            if ($personal_url === '' || $personal_secret === '' || $this->bbb_option('auto_create_enabled', 'bbb_personal', '1') !== '1') {
                $this->log_event('bbb_error','regenerate_links',intval($booking_id),'تنظیمات BigBlueButton شخصی کامل یا فعال نیست.');
                return false;
            }
            $room = ['meeting_id' => 'gtbp-personal-booking-' . intval($booking_id)];
        } else {
            $room = $this->create_jitsi_room($room_name, $student_name, $teacher_name, $provider);
        }
        if (!$room) { $this->log_event('bbb_error','regenerate_links',intval($booking_id),'ساخت لینک/جلسه BBB ناموفق بود.'); return false; }
        $student_gateway = $this->bbb_booking_gateway_url($booking_id, 'student');
        $teacher_gateway = $this->bbb_booking_gateway_url($booking_id, 'teacher');
        $updated = $wpdb->update($this->table_name, [
            'class_provider' => $provider,
            'roomeet_room_id' => $room['meeting_id'],
            'roomeet_join_link' => $student_gateway,
            'bbb_moderator_link' => $teacher_gateway,
            'telegram_class_link_sent' => 0
        ], ['id' => intval($booking_id)]);
        $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", intval($booking_id)));
        if ($fresh) {
            $user = get_user_by('email', $fresh->email);
            if ($user) $this->upsert_user_jitsi_link($user->ID, $fresh);
        }
        return $updated !== false;
    }

    /**
     * تولید/به‌روزرسانی لینک کلاس بر اساس «سرویس فعال». نسخه ۱۴.۰
     * با تعویض سرویس و زدن این دکمه، کلاس بر اساس سرویس جدید ساخته/آپدیت می‌شود.
     * هیچ ایمیلی ارسال نمی‌شود.
     * @return array ['ok'=>bool, 'provider'=>str, 'error'=>str]
     */
    private function regenerate_booking_class_links($booking_id, $force_service = null) {
        global $wpdb;
        $svc = in_array($force_service, ['bbb', 'bbb_personal', 'meet', 'roomeet'], true) ? $force_service : $this->get_active_service();
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", intval($booking_id)));
        if (!$booking) return ['ok' => false, 'provider' => $svc, 'error' => 'رزرو پیدا نشد'];

        if ($svc === 'roomeet') {
            // اگر قبلاً BBB داشت، اتاق BBB را ببند
            if (!empty($booking->roomeet_room_id) && strpos((string)$booking->roomeet_room_id, 'rmt:') !== 0) $this->end_bbb_meeting($booking->roomeet_room_id);
            // نسخه ۱۴.۳: لینک‌های gateway همیشه ساخته می‌شوند (حتی اگر روومیت هنوز کامل پیکربندی نشده باشد).
            $the_id = $this->roomeet_provision_room($booking, true);
            // لینک‌های ضبط قبلی متعلق به تاریخچه آموزشی‌اند و با تولید مجدد لینک کلاس
            // نباید حذف شوند. فقط وضعیت ارسال لینک ورود تازه بازنشانی می‌شود.
            $wpdb->update($this->table_name, [
                'telegram_class_link_sent' => 0,
            ], ['id' => intval($booking_id)]);
            $this->refresh_user_classlogin($booking_id);
            $warn = $this->roomeet_available() ? '' : 'روومیت هنوز کامل پیکربندی نشده؛ لینک ساخته شد اما تا تکمیل تنظیمات و تست اتصال، ورود به کلاس ممکن نیست.';
            return ['ok' => true, 'provider' => 'roomeet', 'error' => $warn];
        }

        if ($svc === 'meet') {
            if (!$this->meet_available()) return ['ok' => false, 'provider' => 'meet', 'error' => 'گوگل میت پیکربندی نشده'];
            if (empty($booking->booking_time) || strpos($booking->booking_time, '-') === false) {
                return ['ok' => false, 'provider' => 'meet', 'error' => 'فرمت زمان نامعتبر'];
            }
            // اتاق BBB قبلی را ببند
            if (!empty($booking->roomeet_room_id)) $this->end_bbb_meeting($booking->roomeet_room_id);
            $title = ($booking->class_name ?: 'کلاس') . ' - ' . trim($booking->first_name . ' ' . $booking->last_name);
            $res = GTBP_Google_Meet_Provider::instance()->create_meeting_for_booking([
                'booking_id'     => intval($booking->id),
                'date'           => $booking->booking_date,
                'time'           => $booking->booking_time,
                'title'          => $title,
                'attendee_email' => $booking->email,
            ]);
            if (!$res || empty($res['join_link'])) return ['ok' => false, 'provider' => 'meet', 'error' => 'ساخت لینک Meet ناموفق بود'];
            // لینک Meet مشترک بین مدرس و شاگرد؛ در ستون‌های عمومی هم بازتاب بده
            $wpdb->update($this->table_name, [
                'class_provider'    => 'meet',
                'roomeet_room_id'   => null,
                'roomeet_join_link' => esc_url_raw($res['join_link']),
                'bbb_moderator_link'=> esc_url_raw($res['join_link']),
                'telegram_class_link_sent' => 0,
            ], ['id' => intval($booking_id)]);
            $this->refresh_user_classlogin($booking_id);
            return ['ok' => true, 'provider' => 'meet', 'error' => ''];
        }

        // پیش‌فرض: BBB (رفتار قبلی)
        $bbb_provider = ($svc === 'bbb_personal') ? 'bbb_personal' : 'bbb';
        $ok = $this->regenerate_booking_bbb_links($booking_id, $bbb_provider);
        if ($ok) $wpdb->update($this->table_name, ['class_provider' => $bbb_provider], ['id' => intval($booking_id)]);
        return ['ok' => (bool)$ok, 'provider' => $bbb_provider, 'error' => $ok ? '' : 'ساخت لینک BBB ناموفق بود'];
    }

    private function refresh_user_classlogin($booking_id) {
        global $wpdb;
        $fresh = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", intval($booking_id)));
        if (!$fresh) return;
        $user = get_user_by('email', $fresh->email);
        if (!$user) return;
        // برای roomeet/meet مستقیماً classlogin را با لینک‌های موجود به‌روزرسانی کن (بدون منطق gateway مخصوص BBB)
        $current = get_user_meta($user->ID, 'classlogin', true);
        if (!is_array($current)) $current = [];
        $entry = [
            'booking_id'  => $fresh->id,
            'class_name'  => $fresh->class_name,
            'date'        => $this->gregorian_to_jalali_string($fresh->booking_date),
            'time'        => $fresh->booking_time,
            'link'        => $fresh->roomeet_join_link,
            'teacher_link'=> $fresh->bbb_moderator_link,
            'created_at'  => current_time('mysql'),
        ];
        $found = false;
        foreach ($current as $i => $l) {
            if (isset($l['booking_id']) && intval($l['booking_id']) === intval($fresh->id)) { $current[$i] = array_merge($l, $entry); $found = true; break; }
        }
        if (!$found) $current[] = $entry;
        update_user_meta($user->ID, 'classlogin', $current);
    }

    private function delete_booking_bbb_links($booking_id) {
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", intval($booking_id)));
        if (!$booking) return false;
        if (!empty($booking->roomeet_room_id)) {
            $this->end_bbb_meeting($booking->roomeet_room_id);
        }
        $updated = $wpdb->update($this->table_name, [
            'roomeet_room_id' => null,
            'roomeet_join_link' => null,
            'bbb_moderator_link' => null,
            'telegram_class_link_sent' => 0
        ], ['id' => intval($booking_id)]);
        $user = get_user_by('email', $booking->email);
        if ($user) $this->remove_user_jitsi_link($user->ID, $booking->id);
        return $updated !== false;
    }

    private function extract_jitsi_room_id_from_url($url) {
        // Fix #7: always return '' (not null) so callers comparing with === '' work correctly.
        $parts = wp_parse_url(trim((string)$url));
        if (!$parts || empty($parts['query'])) return '';
        parse_str($parts['query'], $q);
        if (!empty($q['meetingID'])) return sanitize_text_field($q['meetingID']);
        if (!empty($q['gtbp_bbb_join'])) {
            global $wpdb;
            $bid = intval($q['gtbp_bbb_join']);
            if ($bid) {
                $mid = $wpdb->get_var($wpdb->prepare("SELECT roomeet_room_id FROM {$this->table_name} WHERE id=%d", $bid));
                if (!empty($mid)) return sanitize_text_field($mid);
            }
        }
        return '';
    }

    private function learning_session_has_manual_recording($booking_id) {
        return class_exists('GTBP_Learning_Dashboard_v211') && GTBP_Learning_Dashboard_v211::instance()->session_has_manual_video_for_booking($booking_id);
    }

    private function get_learning_session_recording_link($booking_id) {
        if (!class_exists('GTBP_Learning_Dashboard_v211')) return '';
        return GTBP_Learning_Dashboard_v211::instance()->get_session_video_for_booking($booking_id);
    }

    private function sync_learning_recording_link($booking_id, $recording_link, $notify=true) {
        if (!class_exists('GTBP_Learning_Dashboard_v211') || empty($recording_link)) return false;
        return GTBP_Learning_Dashboard_v211::instance()->sync_recording_from_booking($booking_id, $recording_link, $notify);
    }

    private function upsert_user_jitsi_link($user_id, $booking) {
        if (!$booking) return false;
        // نسخه ۱۴.۴: تبدیل مارکر سرویس (rmt:/meet:) مستقل از وجود کاربر انجام می‌شود.
        // (باگ ریشه‌ای: قبلاً این تبدیل پشت گیت user_id بود و برای رزروهای بدون کاربر/مهمان اجرا نمی‌شد.)
        $this->ensure_booking_gateway_links($booking);
        if (!$user_id) return false; // برای نوشتن classlogin به کاربر نیاز است، اما تبدیل بالا انجام شد
        global $wpdb;
        $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id=%d", intval($booking->id)));
        if (!$booking || empty($booking->roomeet_join_link)) return false;
        $current_links = get_user_meta($user_id, 'classlogin', true);
        if (!is_array($current_links)) $current_links = [];
        $entry = ['booking_id' => $booking->id, 'class_name' => $booking->class_name, 'date' => $this->gregorian_to_jalali_string($booking->booking_date), 'time' => $booking->booking_time, 'link' => $booking->roomeet_join_link, 'teacher_link' => $booking->bbb_moderator_link, 'created_at' => current_time('mysql')];
        $updated = false;
        foreach ($current_links as $idx => $link) if (isset($link['booking_id']) && intval($link['booking_id']) === intval($booking->id)) { $current_links[$idx] = array_merge($link, $entry); $updated = true; break; }
        if (!$updated) $current_links[] = $entry;
        usort($current_links, function($a, $b) { return strtotime($b['created_at']) - strtotime($a['created_at']); });
        update_user_meta($user_id, 'classlogin', $current_links);
        return true;
    }

    private function remove_user_jitsi_link($user_id, $booking_id) {
        if (!$user_id || !$booking_id) return false;
        $current_links = get_user_meta($user_id, 'classlogin', true);
        if (!is_array($current_links) || empty($current_links)) return false;
        $filtered = [];
        foreach ($current_links as $link) if (!isset($link['booking_id']) || intval($link['booking_id']) !== intval($booking_id)) $filtered[] = $link;
        if (empty($filtered)) delete_user_meta($user_id, 'classlogin'); else update_user_meta($user_id, 'classlogin', $filtered);
        return true;
    }

    private function get_bbb_recordings($meeting_id, $provider = 'bbb') {
        $response = wp_remote_get($this->bbb_api_url('getRecordings', ['meetingID' => $meeting_id], $provider), ['timeout' => 20, 'sslverify' => true]);
        if (is_wp_error($response)) return false;
        $body = wp_remote_retrieve_body($response);
        if (stripos($body, '<returncode>SUCCESS</returncode>') === false) return false;
        $xml = @simplexml_load_string($body);
        if (!$xml || empty($xml->recordings->recording)) return false;
        $recordings = [];
        foreach ($xml->recordings->recording as $rec) {
            $item = ['link' => '', 'presentation' => '', 'video' => ''];
            foreach ($rec->playback->format as $format) {
                $type = strtolower(trim((string)$format->type));
                if ($type === '') $type = strtolower(trim((string)$format['type']));
                $url = trim((string)$format->url);
                if ($url === '') continue;
                if ($type === 'presentation') $item['presentation'] = $url;
                if ($type === 'video') $item['video'] = $url;
            }
            $item['link'] = $item['presentation'] ?: $item['video'];
            if ($item['link']) $recordings[] = $item;
        }
        return $recordings;
    }

    private function gtbp_extract_time_parts($time_text) {
        $time_text = trim((string)$time_text);
        if ($time_text === '') return [null, null];
        preg_match_all('/(?:[01]?\d|2[0-3])[:.][0-5]\d/u', $time_text, $m);
        if (empty($m[0])) return [null, null];
        $times = array_map(function($t){ return str_replace('.', ':', $t); }, $m[0]);
        $start = $times[0] ?? null;
        $end = count($times) > 1 ? end($times) : null;
        return [$start, $end];
    }

    private function booking_recording_lookup_ready($booking) {
        if (!$booking || empty($booking->booking_date)) return false;
        list($start, $end) = $this->gtbp_extract_time_parts($booking->booking_time ?? '');
        if (!$end && $start) {
            $end_ts_guess = strtotime($booking->booking_date . ' ' . $start . ':00 +' . 90 . ' minutes');
            if ($end_ts_guess) $end = date('H:i', $end_ts_guess);
        }
        if (!$end) $end = '23:59';
        $end_ts = strtotime($booking->booking_date . ' ' . $end . ':00');
        if (!$end_ts) return false;
        $ready_ts = $end_ts + (15 * MINUTE_IN_SECONDS);
        return current_time('timestamp') >= $ready_ts;
    }

    private function save_found_bbb_recording_for_booking($booking, $recording, $notify=true) {
        if (!$booking || empty($recording)) return false;
        if ($this->learning_session_has_manual_recording($booking->id)) return false;
        global $wpdb;
        if (!is_array($recording)) $recording = ['link'=>$recording, 'presentation'=>$recording, 'video'=>''];
        $presentation = esc_url_raw($recording['presentation'] ?? '');
        $video = esc_url_raw($recording['video'] ?? '');
        $recording_link = esc_url_raw($recording['link'] ?? ($presentation ?: $video));
        if ($recording_link === '') return false;
        $data = [
            'roomeet_recording_link' => $recording_link,
            'roomeet_recording_checked' => current_time('mysql')
        ];
        $columns = (array)$wpdb->get_col("SHOW COLUMNS FROM {$this->table_name}");
        if (in_array('bbb_presentation_link', $columns, true)) $data['bbb_presentation_link'] = $presentation;
        if (in_array('bbb_video_link', $columns, true)) $data['bbb_video_link'] = $video;
        $wpdb->update($this->table_name, $data, ['id' => intval($booking->id)]);
        $this->sync_learning_recording_link($booking->id, $recording_link, false);
        if ($notify) $this->send_recording_notification($booking, $recording_link);
        return true;
    }

    public function check_bbb_recordings() {
        $this->ensure_table_columns();
        global $wpdb;
        $retry_after = date('Y-m-d H:i:s', strtotime('-30 minutes'));
        // نسخه ۱۴.۰: فقط رزروهای BBB (نه Roomeet/Meet). مارکرهای rmt:/meet: نادیده گرفته می‌شوند.
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE roomeet_room_id IS NOT NULL AND roomeet_room_id <> ''
             AND roomeet_room_id NOT LIKE 'rmt:%' AND roomeet_room_id NOT LIKE 'meet:%'
             AND (class_provider IS NULL OR class_provider = '' OR class_provider IN ('bbb','bbb_personal'))
             AND status IN ('confirmed','completed')
             AND ((roomeet_recording_link IS NULL OR roomeet_recording_link = '')
                  OR (bbb_presentation_link IS NULL OR bbb_presentation_link = '')
                  OR (bbb_video_link IS NULL OR bbb_video_link = ''))
             AND (roomeet_recording_checked IS NULL OR roomeet_recording_checked < %s)
             AND booking_date <= CURDATE()
             ORDER BY booking_date ASC, booking_time ASC, id ASC
             LIMIT 120",
             $retry_after
        ));
        foreach ($bookings as $booking) {
            if ($this->learning_session_has_manual_recording($booking->id)) {
                $wpdb->update($this->table_name, ['roomeet_recording_checked' => current_time('mysql')], ['id' => intval($booking->id)]);
                continue;
            }
            if (!$this->booking_recording_lookup_ready($booking)) {
                continue;
            }
            $wpdb->update($this->table_name, ['roomeet_recording_checked' => current_time('mysql')], ['id' => intval($booking->id)]);
            $recordings = $this->get_bbb_recordings($booking->roomeet_room_id, (($booking->class_provider ?? '') === 'bbb_personal') ? 'bbb_personal' : 'bbb');
            if (!empty($recordings)) {
                foreach ($recordings as $recording) {
                    if (!empty($recording['link'])) {
                        $this->save_found_bbb_recording_for_booking($booking, $recording, true);
                        break;
                    }
                }
            }
        }

        // If a recording link already exists on a booking but the educational session is still missing it,
        // sync it forward unless the teacher has manually set a different session video.
        $existing = $wpdb->get_results("SELECT * FROM {$this->table_name} WHERE roomeet_recording_link IS NOT NULL AND roomeet_recording_link <> '' ORDER BY id DESC LIMIT 120");
        foreach ($existing as $booking) {
            if (!$this->learning_session_has_manual_recording($booking->id)) {
                $this->sync_learning_recording_link($booking->id, $booking->roomeet_recording_link, false);
            }
        }
    }
    
    private function send_recording_notification($booking, $recording_link) {
        $this->sync_learning_recording_link($booking->id, $recording_link, false);
        $subject = "🎥 ضبط کلاس شما آماده شد - {$booking->class_name}";
        $message = "سلام {$booking->first_name} عزیز،\n\n";
        $message .= "کلاس {$booking->class_name} شما در تاریخ {$booking->booking_date} ضبط شده است.\n\n";
        $message .= "برای مشاهده ضبط کلاس روی لینک زیر کلیک کنید:\n";
        $message .= $recording_link . "\n\nبا تشکر از انتخاب شما";
        
        wp_mail($booking->email, $subject, $message);
        wp_mail($this->admin_notification_email(), "ضبط جدید برای {$booking->first_name} {$booking->last_name}", $message);
        global $wpdb;
        $logs_table = $wpdb->prefix . 'gls_notifications';
        $sessions_table = $wpdb->prefix . 'gls_sessions';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $logs_table)) === $logs_table) {
            $user = get_user_by('email', $booking->email);
            $session_id = 0;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $sessions_table)) === $sessions_table) {
                $session_id = intval($wpdb->get_var($wpdb->prepare("SELECT id FROM $sessions_table WHERE booking_id=%d LIMIT 1", intval($booking->id))));
            }
            $wpdb->insert($logs_table, [
                'session_id' => $session_id,
                'user_id' => $user ? intval($user->ID) : 0,
                'email' => sanitize_email($booking->email),
                'type' => 'recording_ready',
                'subject' => $subject,
                'message' => $message,
                'sent_at' => current_time('mysql')
            ]);
            $session_obj = null;
            if ($session_id && $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $sessions_table)) === $sessions_table) {
                $session_obj = $wpdb->get_row($wpdb->prepare("SELECT * FROM $sessions_table WHERE id=%d", $session_id));
            }
            do_action('gtbp_learning_notification_created', $session_obj, 'recording_ready', $booking->email, $subject, $message);
        }
    }

    /* ==========================================================================
       ADMIN PANEL SECTION
       ========================================================================== */
    public function admin_styles() {
        echo '<style>
            .gtbp-admin-wrap { font-family: IRANSansXFaNum, Tahoma, sans-serif; direction: rtl; }
            .gtbp-admin-wrap h1 { color: #8B0000; margin-bottom: 20px; font-weight:bold; }
            .gtbp-table { width: 100%; border-collapse: collapse; background: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.1); margin-top:15px; }
            .gtbp-table th, .gtbp-table td { padding: 12px; border: 1px solid #ddd; text-align: right; }
            .gtbp-table th { background: #8B0000; color: #fff; }
            .gtbp-table th a { color: #fff; text-decoration: none; display: inline-block; width: 100%; }
            .gtbp-table th a:hover { text-decoration: underline; }
            .gtbp-btn { background: #8B0000; color: #fff; padding: 6px 15px; text-decoration: none; border-radius: 4px; border: none; cursor:pointer; display: inline-block; }
            .gtbp-btn-success { background: #28a745; }
            .gtbp-btn-warning { background: #ffc107; color: #333; }
            .gtbp-btn-danger { background: #dc3545; }
            .gtbp-search-bar { background: #fff; padding: 15px; margin-bottom: 20px; border: 1px solid #ddd; display: flex; gap: 15px; align-items: center; border-radius:5px;}
            .gtbp-search-bar input { padding: 6px; border: 1px solid #ccc; border-radius: 3px; }
            .gtbp-input-wide { width: 100%; padding: 8px; box-sizing: border-box; }
            .sorted-asc, .sorted-desc { font-weight: bold; }
            .sorted-asc:after { content: " ▲"; }
            .sorted-desc:after { content: " ▼"; }
            .gtbp-bulk-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; background:#fff; border:1px solid #ddd; border-radius:8px; padding:12px; margin-bottom:15px; }
            .gtbp-action-stack { display:flex; flex-direction:column; gap:8px; min-width:170px; }
            .gtbp-action-group { border:1px solid #e2e2e2; background:#fafafa; border-radius:8px; padding:8px; display:flex; flex-direction:column; gap:6px; }
            .gtbp-action-group-title { font-size:11px; color:#555; font-weight:bold; margin-bottom:2px; }
            .gtbp-link-tools { display:flex; flex-wrap:wrap; gap:5px; align-items:center; justify-content:flex-start; }
            .gtbp-icon-btn { min-width:34px; min-height:30px; padding:5px 8px; border-radius:6px; border:1px solid #d7d7d7; background:#fff; color:#333; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; gap:4px; font-size:12px; line-height:1.4; }
            .gtbp-icon-btn:hover { background:#f0f0f0; color:#111; }
            .gtbp-icon-btn-enter { background:#e9f7ef; border-color:#28a745; color:#146c2e; }
            .gtbp-icon-btn-show { background:#eef4ff; border-color:#6ea8fe; color:#084298; }
            .gtbp-icon-btn-copy { background:#fff8e1; border-color:#ffc107; color:#664d03; }
            .gtbp-mini-link-panel { display:none; margin-top:7px; padding:7px; background:#f8f9fa; border:1px dashed #bbb; border-radius:6px; direction:ltr; text-align:left; max-width:260px; overflow-wrap:anywhere; font-size:11px; }
            .gtbp-btn-small { padding:5px 9px; font-size:12px; border-radius:5px; }

            .gtbp-payment-methods label{display:flex!important;align-items:center!important;gap:10px!important;line-height:1.8!important;text-align:right!important}.gtbp-payment-methods input[type="radio"]{margin:0!important;flex:0 0 auto!important}.gtbp-wrapper{max-width:100%!important}.gtbp-booking-embed .gtbp-wrapper{box-shadow:none!important;border:0!important;padding:18px 0!important}.gtbp-step-title{scroll-margin-top:18px}.gtbp-slots-grid{scroll-margin-top:18px}

            /* 12.22: admin menu prominence + mobile booking admin */
            #adminmenu #toplevel_page_gtbp_bookings > a.menu-top{background:#fde68a!important;color:#1f2937!important;border-right:4px solid #dc2626!important;font-weight:800!important}
            #adminmenu #toplevel_page_gtbp_bookings > a.menu-top .wp-menu-image:before{color:#1f2937!important}
            #adminmenu #toplevel_page_gtbp_bookings:hover > a.menu-top,#adminmenu #toplevel_page_gtbp_bookings.wp-has-current-submenu > a.menu-top{background:#facc15!important;color:#111827!important}
            @media(max-width:782px){
                .gtbp-search-bar{display:grid!important;grid-template-columns:1fr!important;gap:8px!important;padding:12px!important}
                .gtbp-search-bar label{margin:0!important;font-weight:800!important}
                .gtbp-search-bar input,.gtbp-search-bar select,.gtbp-search-bar .gtbp-btn{width:100%!important;box-sizing:border-box!important;min-height:42px!important}
                .gtbp-bulk-actions{display:grid!important;grid-template-columns:1fr!important;align-items:stretch!important}
                .gtbp-bulk-actions .gtbp-btn{width:100%!important;box-sizing:border-box!important;text-align:center!important}
                .gtbp-booking-cards-wrap{display:grid!important;grid-template-columns:minmax(0,1fr)!important;gap:12px!important}
                .gtbp-booking-card{width:100%!important;min-width:0!important;padding:12px!important;box-sizing:border-box!important;overflow:visible!important}
                .gtbp-card-top{display:grid!important;grid-template-columns:1fr!important;gap:10px!important;padding-left:0!important}
                .gtbp-card-top>div:last-child{align-items:flex-start!important}
                .gtbp-card-identity{align-items:flex-start!important}
                .gtbp-card-meta{grid-template-columns:1fr 1fr!important;gap:7px!important}
                .gtbp-card-bottom-row{grid-template-columns:1fr!important;justify-items:stretch!important;gap:8px!important}
                .gtbp-link-role-student,.gtbp-link-role-teacher,.gtbp-card-bottom-row .gtbp-admin-menu,.gtbp-card-empty{grid-column:1!important;grid-row:auto!important;justify-self:stretch!important;width:100%!important}
                .gtbp-link-role{min-width:0!important;width:100%!important;justify-content:center!important}
                .gtbp-admin-menu{display:block!important;width:100%!important}
                .gtbp-admin-menu>summary{width:100%!important;border-radius:10px!important}
                .gtbp-admin-menu-panel{position:fixed!important;left:12px!important;right:12px!important;bottom:18px!important;top:auto!important;width:auto!important;max-height:72vh!important;overflow:auto!important;border-radius:14px!important}
            }
            @media(max-width:480px){.gtbp-card-meta{grid-template-columns:1fr!important}.gtbp-card-meta-item{min-width:0!important}}
        </style>';
    }

    public function admin_enqueue_media_for_bbb($hook) {
        // فعلاً تنظیمات BBB نیازی به Media Uploader ندارد.
    }

    public function add_admin_menu() {
        add_menu_page('رزرو کلاس آلمانی', 'رزرو کلاس', 'manage_options', 'gtbp_bookings', array($this, 'admin_page'), 'dashicons-welcome-learn-more', 3);
        add_submenu_page('gtbp_bookings', 'کدهای تخفیف', 'کدهای تخفیف', 'manage_options', 'gtbp_coupons', array($this, 'admin_coupons_page'));
        // نسخه ۱۴.۰: صفحه یکپارچه تنظیمات سرویس (تب‌بندی‌شده)
        add_submenu_page('gtbp_bookings', 'تنظیمات سرویس', 'تنظیمات سرویس', 'manage_options', 'gtbp_online_class', array($this, 'admin_online_class_page'));
        // صفحه BBB همچنان ثبت می‌شود تا از داخل تب کار کند، اما از منو پنهان می‌شود.
        add_submenu_page('gtbp_bookings', 'تنظیمات BigBlueButton', 'تنظیمات BBB', 'manage_options', 'gtbp_bbb', array($this, 'admin_bbb_page'));
        add_submenu_page('gtbp_bookings', 'جستجوی ویدئوهای کلاس', 'جستجوی ویدئوها', 'manage_options', 'gtbp_bbb_recording_search', array($this, 'admin_bbb_recording_search_page'));
        add_submenu_page('gtbp_bookings', 'گزارش خطاها', 'گزارش خطاها', 'manage_options', 'gtbp_event_logs', array($this, 'admin_event_logs_page'));
        // اضافه کردن منوی فرعی جدید: ساخت جلسه فوری
        add_submenu_page('gtbp_bookings', 'ساخت جلسه فوری', 'ساخت جلسه فوری', 'manage_options', 'gtbp_instant_session', array($this, 'admin_instant_session_page'));
    }

    // نسخه ۱۴.۰: پنهان کردن صفحات جداگانه از منو (URL و ذخیره‌شان همچنان کار می‌کند؛ داخل تب‌ها نمایش داده می‌شوند)
    public function hide_legacy_provider_submenus() {
        remove_submenu_page('gtbp_bookings', 'gtbp_bbb');
        remove_submenu_page('gtbp_bookings', 'gtbp_meet');
        remove_submenu_page('gtbp_bookings', 'gtbp_coupons'); // نسخه ۱۴.۱: کدهای تخفیف حالا یک تب است
    }

    /* ==========================================================================
       صفحه یکپارچه تنظیمات کلاس آنلاین - نسخه ۱۴.۰
       ========================================================================== */
    public function admin_online_class_page() {
        if (!current_user_can('manage_options')) return;
        $tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'active';
        $active_svc = $this->get_active_service();

        if (isset($_GET['msg']) && $_GET['msg'] === 'active_saved') {
            echo '<div class="notice notice-success is-dismissible"><p>✅ سرویس فعال کلاس آنلاین ذخیره شد.</p></div>';
        }

        echo '<div class="wrap gtbp-admin-wrap"><h1>تنظیمات سرویس</h1>';
        $base = admin_url('admin.php?page=gtbp_online_class');
        $tabs = [
            'active'  => '⚙️ سرویس فعال',
            'roomeet' => 'روومیت',
            'bbb'     => 'BigBlueButton',
            'bbb_personal' => 'BigBlueButton شخصی',
            'meet'    => 'Google Meet',
        ];
        echo '<h2 class="nav-tab-wrapper" style="margin-bottom:16px;">';
        foreach ($tabs as $k => $label) {
            $cls = ($tab === $k) ? ' nav-tab-active' : '';
            echo '<a href="' . esc_url(add_query_arg('tab', $k, $base)) . '" class="nav-tab' . $cls . '">' . esc_html($label) . '</a>';
        }
        echo '</h2>';

        if ($tab === 'active') {
            $svc_defs = [
                'bbb'     => ['BigBlueButton', 'اتصال قبلی BigBlueButton برای سازگاری با کلاس‌های موجود.'],
                'bbb_personal' => ['BigBlueButton شخصی', 'ساخت کلاس و دریافت ضبط از سرور اختصاصی شما.'],
                'meet'    => ['Google Meet', 'ساخت لینک Meet با حساب گوگل شما و گرفتن ضبط از Google Drive.'],
                'roomeet' => ['روومیت (Roomeet)', 'ساخت کلاس، لینک مدرس/شاگرد و گرفتن ضبط از سرویس روومیت.'],
            ];
            echo '<form method="post" style="max-width:760px;background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;">';
            wp_nonce_field('gtbp_save_active_service', 'gtbp_active_nonce');
            echo '<p style="line-height:2;">فقط یک سرویس در هر زمان فعال است. با انتخاب سرویس، از آن پس <strong>کلاس‌ها و رزروهای جدید</strong> بر اساس همان سرویس ساخته می‌شوند. برای رزروهای قبلی، از دکمهٔ «تولید/به‌روزرسانی لینک کلاس» در لیست رزروها استفاده کنید.</p>';
            foreach ($svc_defs as $k => $d) {
                $checked = checked($active_svc, $k, false);
                $ready = ($k === 'bbb') ? (get_option('gtbp_bbb_url') && get_option('gtbp_bbb_secret'))
                       : (($k === 'bbb_personal') ? (get_option('gtbp_personal_bbb_url') && get_option('gtbp_personal_bbb_secret'))
                       : (($k === 'meet') ? $this->meet_available() : $this->roomeet_available()));
                $status = $ready ? '<span style="color:#166534;">✅ آماده</span>' : '<span style="color:#b45309;">⚠️ تنظیمات ناقص</span>';
                echo '<label style="display:block;padding:12px 14px;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:10px;cursor:pointer;">';
                echo '<input type="radio" name="active_class_service" value="' . esc_attr($k) . '" ' . $checked . '> ';
                echo '<strong>' . esc_html($d[0]) . '</strong> - ' . $status . '<br>';
                echo '<span style="color:#555;font-size:13px;padding-inline-start:22px;">' . esc_html($d[1]) . '</span>';
                echo '</label>';
            }
            echo '<p><button type="submit" name="gtbp_save_active_service" class="button button-primary button-large">ذخیره سرویس فعال</button></p>';
            echo '</form>';
        } elseif ($tab === 'bbb') {
            $this->admin_bbb_page();
        } elseif ($tab === 'bbb_personal') {
            $this->admin_personal_bbb_page();
        } elseif ($tab === 'meet') {
            if (class_exists('GTBP_Google_Meet_Provider')) {
                GTBP_Google_Meet_Provider::instance()->render_settings_page();
            } else {
                echo '<p>ماژول Google Meet در دسترس نیست.</p>';
            }
        } elseif ($tab === 'roomeet') {
            $this->render_roomeet_settings_tab();
        }

        echo '</div>';
    }

    private function render_roomeet_settings_tab() {
        if (!class_exists('GTBP_Roomeet_Provider')) { echo '<p>ماژول روومیت در دسترس نیست.</p>'; return; }
        $R = 'GTBP_Roomeet_Provider';
        $api_key  = get_option($R::OPT_API_KEY, '');
        $phone    = get_option($R::OPT_PHONE, '');
        $password = get_option($R::OPT_PASSWORD, '');
        $sale_id  = get_option($R::OPT_SALE_ID, '');
        $teacher  = get_option($R::OPT_TEACHER, '');
        $last_err = GTBP_Roomeet_Provider::instance()->get_last_error();

        if (isset($_GET['rmt_msg'])) {
            $m = sanitize_key($_GET['rmt_msg']);
            $texts = [
                'saved'    => ['success', '✅ تنظیمات روومیت ذخیره شد.'],
                'test_ok'  => ['success', '✅ اتصال به روومیت موفق بود.'],
                'test_err' => ['error',   '❌ اتصال به روومیت ناموفق بود؛ به «آخرین خطا» نگاه کنید.'],
                'svc_ok'   => ['success', '✅ سرویس‌ها دریافت شدند؛ در فهرست پایین انتخاب کنید.'],
                'svc_err'  => ['error',   '❌ دریافت سرویس‌ها ناموفق بود.'],
            ];
            if (isset($texts[$m])) echo '<div class="notice notice-' . esc_attr($texts[$m][0]) . ' is-dismissible"><p>' . esc_html($texts[$m][1]) . '</p></div>';
            if ($m === 'rooms_deleted') {
                $dn = isset($_GET['deleted']) ? intval($_GET['deleted']) : 0;
                $fl = isset($_GET['failed']) ? intval($_GET['failed']) : 0;
                echo '<div class="notice notice-success is-dismissible"><p>✅ ' . esc_html($dn) . ' کلاس از روومیت پاک شد' . ($fl ? ' - ' . esc_html($fl) . ' مورد پاک نشد' : '') . '. (رزروهای سایت دست‌نخورده ماندند.) حالا می‌توانید در لیست رزروها «تولید/به‌روزرسانی گروهی لینک کلاس» را بزنید.</p></div>';
            }
        }

        $services = get_transient('gtbp_roomeet_services_cache');
        ?>
        <div style="max-width:900px;background:#fff;padding:15px 20px;border-right:4px solid #1d4ed8;margin-bottom:16px;line-height:2;">
            <strong>راهنما:</strong> کلید API را از <a href="https://panel.roomeet.ir/admin/api-key/" target="_blank" rel="noopener">پنل مدیر روومیت</a> بگیرید. شماره موبایل و رمز عبورِ حساب <b>مدیر آموزشگاه</b> را وارد کنید. سپس «دریافت سرویس‌ها» را بزنید و سرویس (بستهٔ خریداری‌شده) را انتخاب کنید.
        </div>
        <form method="post" style="max-width:900px;background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;">
            <?php wp_nonce_field('gtbp_roomeet_save', 'gtbp_roomeet_nonce'); ?>
            <table class="form-table">
                <tr><th scope="row">کلید API</th><td><input type="text" name="rmt_api_key" value="<?php echo esc_attr($api_key); ?>" dir="ltr" style="width:100%;padding:8px;" placeholder="8SKpmnuKEGk9WF/..."></td></tr>
                <tr><th scope="row">موبایل مدیر</th><td><input type="text" name="rmt_phone" value="<?php echo esc_attr($phone); ?>" dir="ltr" style="width:280px;padding:8px;" placeholder="09123456789"></td></tr>
                <tr><th scope="row">رمز عبور مدیر</th><td><input type="password" name="rmt_password" value="<?php echo esc_attr($password); ?>" dir="ltr" style="width:280px;padding:8px;"></td></tr>
                <tr><th scope="row">نام مدرس</th><td><input type="text" name="rmt_teacher" value="<?php echo esc_attr($teacher); ?>" style="width:280px;padding:8px;" placeholder="حمیدرضا سعادتی"></td></tr>
                <tr><th scope="row">سرویس (saleId)</th>
                    <td>
                        <input type="text" name="rmt_sale_id" value="<?php echo esc_attr($sale_id); ?>" dir="ltr" style="width:100%;padding:8px;" placeholder="شناسه سرویس خریداری‌شده">
                        <?php if (!empty($services) && is_array($services)): ?>
                            <p class="description">سرویس‌های موجود (کلیک برای انتخاب):</p>
                            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;">
                            <?php foreach ($services as $svc): ?>
                                <button type="button" class="button" onclick="this.closest('form').querySelector('[name=rmt_sale_id]').value='<?php echo esc_js($svc['saleId']); ?>';"><?php echo esc_html(($svc['packageName'] ?? '') . ' (' . ($svc['maxParticipants'] ?? '') . ')'); ?></button>
                            <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
            <p>
                <button type="submit" name="gtbp_roomeet_save" class="button button-primary button-large">ذخیره تنظیمات</button>
                <button type="submit" name="gtbp_roomeet_test" class="button button-secondary button-large">تست اتصال</button>
                <button type="submit" name="gtbp_roomeet_fetch_services" class="button button-secondary button-large">دریافت سرویس‌ها</button>
            </p>
        </form>

        <?php // نسخه ۱۴.۵: پاک‌سازی همه‌ی کلاس‌های ساخته‌شده در روومیت (رزروهای سایت دست‌نخورده) ?>
        <div style="max-width:900px;margin-top:20px;background:#fff;padding:20px;border:1px solid #f0c0c0;border-radius:8px;border-right:4px solid #dc2626;">
            <h2 style="margin-top:0;color:#b91c1c;">پاک‌سازی کلاس‌های روومیت</h2>
            <p style="line-height:2;">
                این دکمه فقط <strong>اتاق‌های ساخته‌شده در محیط روومیت</strong> را پاک می‌کند تا فضای کاربری روومیت شلوغ نشود.
                <strong style="color:#b91c1c;">هیچ رزروی از سایت شما پاک نمی‌شود</strong> - فقط اتاق‌های روومیت.
                پس از پاک‌سازی، با زدن «تولید/به‌روزرسانی گروهی لینک کلاس» در لیست رزروها، برای هر رزرو دقیقاً یک اتاق تازه ساخته می‌شود.
            </p>
            <form method="post" onsubmit="return confirm('همه‌ی کلاس‌های ساخته‌شده در روومیت پاک می‌شوند (رزروهای سایت دست‌نخورده می‌مانند). ادامه می‌دهید؟');">
                <?php wp_nonce_field('gtbp_roomeet_delete_all_rooms', 'gtbp_roomeet_del_nonce'); ?>
                <button type="submit" name="gtbp_roomeet_delete_all_rooms" class="button button-large" style="background:#dc2626;color:#fff;border-color:#b91c1c;">🗑️ حذف همه‌ی کلاس‌های روومیت</button>
            </form>
        </div>

        <?php if ($last_err): ?>
            <div style="max-width:900px;margin-top:16px;padding:12px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;">
                <strong>آخرین خطا:</strong>
                <pre style="direction:ltr;text-align:left;white-space:pre-wrap;word-break:break-all;margin:6px 0 0;font-size:12px;color:#7f1d1d;background:#fff;padding:8px;border-radius:4px;"><?php echo esc_html($last_err); ?></pre>
            </div>
        <?php endif;
    }

    public function add_sortable_columns($columns) {
        $columns['id'] = 'id';
        $columns['first_name'] = 'first_name';
        $columns['class_name'] = 'class_name';
        $columns['booking_date'] = 'booking_date';
        $columns['status'] = 'status';
        return $columns;
    }

    public function handle_admin_actions() {
        if (!current_user_can('manage_options')) return;

        if (isset($_GET['action']) && $_GET['action'] == 'delete_booking' && isset($_GET['id'])) {
            global $wpdb;
            $booking_id = intval($_GET['id']);
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'gtbp_delete_booking_' . $booking_id)) {
                wp_die('درخواست حذف نامعتبر است.');
            }
            $wpdb->delete($this->table_name, ['id' => $booking_id]);
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=list&msg=booking_deleted'));
            exit;
        }

        if (isset($_GET['action']) && $_GET['action'] == 'gtbp_regenerate_bbb_links' && isset($_GET['id'])) {
            $booking_id = intval($_GET['id']);
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'gtbp_regenerate_bbb_links_' . $booking_id)) {
                wp_die('درخواست نامعتبر است.');
            }
            $result = $this->regenerate_booking_bbb_links($booking_id);
            $msg = $result ? 'bbb_regenerated' : 'bbb_regenerate_failed';
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=list&msg=' . $msg));
            exit;
        }

        // نسخه ۱۴.۰: تولید/به‌روزرسانی لینک کلاس بر اساس «سرویس فعال» (تک رزرو)
        if (isset($_GET['action']) && $_GET['action'] == 'gtbp_regenerate_class_links' && isset($_GET['id'])) {
            $booking_id = intval($_GET['id']);
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'gtbp_regenerate_class_links_' . $booking_id)) {
                wp_die('درخواست نامعتبر است.');
            }
            $r = $this->regenerate_booking_class_links($booking_id);
            $msg = $r['ok'] ? 'class_regenerated' : 'class_regenerate_failed';
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=list&msg=' . $msg . '&provider=' . rawurlencode($r['provider'])));
            exit;
        }

        // نسخه ۱۴.۰: جستجوی دستی ضبط روومیت با theIdOfMeeting
        if (isset($_POST['gtbp_roomeet_lookup_by_id'])) {
            $booking_id = intval($_POST['gtbp_roomeet_lookup_by_id']);
            $nonce_key  = 'gtbp_roomeet_lookup_nonce_' . $booking_id;
            $id_key     = 'roomeet_id_' . $booking_id;
            if (!$booking_id || empty($_POST[$nonce_key]) || !wp_verify_nonce($_POST[$nonce_key], 'gtbp_roomeet_lookup_' . $booking_id)) {
                wp_die('درخواست نامعتبر است.');
            }
            $the_id = isset($_POST[$id_key]) ? sanitize_text_field(wp_unslash($_POST[$id_key])) : '';
            $found = false;
            if ($the_id !== '' && $this->roomeet_available()) {
                global $wpdb;
                $b = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id=%d", $booking_id));
                if ($b) $found = GTBP_Roomeet_Provider::instance()->manual_fetch_recording($b, $the_id);
            }
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=list&msg=' . ($found ? 'roomeet_lookup_ok' : 'roomeet_lookup_fail')));
            exit;
        }

        if (isset($_GET['action']) && $_GET['action'] == 'gtbp_delete_bbb_links' && isset($_GET['id'])) {
            $booking_id = intval($_GET['id']);
            if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'gtbp_delete_bbb_links_' . $booking_id)) {
                wp_die('درخواست نامعتبر است.');
            }
            $result = $this->delete_booking_bbb_links($booking_id);
            $msg = $result ? 'bbb_links_deleted' : 'bbb_links_delete_failed';
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=list&msg=' . $msg));
            exit;
        }
        
        if (isset($_POST['gtbp_save_manual_links'])) {
            global $wpdb;
            $booking_id = intval($_POST['gtbp_save_manual_links']);
            $student_links = isset($_POST['manual_student_link']) && is_array($_POST['manual_student_link']) ? $_POST['manual_student_link'] : [];
            $teacher_links = isset($_POST['manual_teacher_link']) && is_array($_POST['manual_teacher_link']) ? $_POST['manual_teacher_link'] : [];

            $student_link = isset($student_links[$booking_id]) ? esc_url_raw(trim($student_links[$booking_id])) : '';
            $teacher_link = isset($teacher_links[$booking_id]) ? esc_url_raw(trim($teacher_links[$booking_id])) : '';
            $room_id = $this->extract_jitsi_room_id_from_url($student_link);
            if (empty($room_id)) {
                $room_id = $this->extract_jitsi_room_id_from_url($teacher_link);
            }

            if (!empty($room_id)) {
                $student_link = $this->bbb_booking_gateway_url($booking_id, 'student');
                $teacher_link = $this->bbb_booking_gateway_url($booking_id, 'teacher');
            }
            $wpdb->update(
                $this->table_name,
                [
                    'roomeet_room_id' => $room_id,
                    'roomeet_join_link' => $student_link,
                    'bbb_moderator_link' => $teacher_link
                ],
                ['id' => $booking_id]
            );

            // نسخه ۱۳.۱۰: ذخیرهٔ دستی لینک Google Meet (کاملاً افزودنی)
            $meet_links = isset($_POST['manual_meet_link']) && is_array($_POST['manual_meet_link']) ? $_POST['manual_meet_link'] : [];
            if (isset($meet_links[$booking_id])) {
                $meet_link_raw = esc_url_raw(trim(wp_unslash($meet_links[$booking_id])));
                $cols = $wpdb->get_col("SHOW COLUMNS FROM {$this->table_name}");
                if (in_array('meet_join_link', $cols, true)) {
                    $wpdb->update($this->table_name, ['meet_join_link' => $meet_link_raw !== '' ? $meet_link_raw : null], ['id' => $booking_id]);
                }
            }

            $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $booking_id));
            if ($booking) {
                $user = get_user_by('email', $booking->email);
                if ($user) {
                    if (!empty($booking->roomeet_join_link)) {
                        $this->upsert_user_jitsi_link($user->ID, $booking);
                    } else {
                        $this->remove_user_jitsi_link($user->ID, $booking->id);
                    }
                }
            }

            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=list&msg=links_saved'));
            exit;
        }

        if (isset($_POST['gtbp_bulk_delete']) && isset($_POST['bulk_ids']) && is_array($_POST['bulk_ids'])) {
            global $wpdb;
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'gtbp_bulk_booking_actions')) {
                wp_die('درخواست گروهی نامعتبر است.');
            }
            $ids = array_map('intval', $_POST['bulk_ids']);
            if (!empty($ids)) {
                $ids_list = implode(',', $ids);
                $wpdb->query("DELETE FROM {$this->table_name} WHERE id IN ($ids_list)");
            }
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=list&msg=bulk_deleted'));
            exit;
        }

        if (isset($_POST['gtbp_bulk_delete_links']) && isset($_POST['bulk_ids']) && is_array($_POST['bulk_ids'])) {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'gtbp_bulk_booking_actions')) {
                wp_die('درخواست گروهی نامعتبر است.');
            }
            $ids = array_map('intval', $_POST['bulk_ids']);
            $done = 0;
            foreach ($ids as $id) {
                if ($this->delete_booking_bbb_links($id)) $done++;
            }
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=list&msg=bulk_links_deleted&count=' . intval($done)));
            exit;
        }

        if (isset($_POST['gtbp_bulk_regenerate_links']) && isset($_POST['bulk_ids']) && is_array($_POST['bulk_ids'])) {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'gtbp_bulk_booking_actions')) {
                wp_die('درخواست گروهی نامعتبر است.');
            }
            $ids = array_map('intval', $_POST['bulk_ids']);
            $done = 0;
            foreach ($ids as $id) {
                if ($this->regenerate_booking_bbb_links($id)) $done++;
            }
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=list&msg=bulk_links_regenerated&count=' . intval($done)));
            exit;
        }

        // نسخه ۱۴.۰: تولید/به‌روزرسانی گروهی لینک کلاس بر اساس «سرویس فعال»
        if (isset($_POST['gtbp_bulk_regenerate_class_links']) && isset($_POST['bulk_ids']) && is_array($_POST['bulk_ids'])) {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'gtbp_bulk_booking_actions')) {
                wp_die('درخواست گروهی نامعتبر است.');
            }
            $ids = array_map('intval', $_POST['bulk_ids']);
            $bulk_provider = isset($_POST['gtbp_bulk_provider']) ? sanitize_key(wp_unslash($_POST['gtbp_bulk_provider'])) : $this->get_active_service();
            if (!in_array($bulk_provider, ['bbb', 'bbb_personal', 'meet', 'roomeet'], true)) $bulk_provider = $this->get_active_service();
            $done = 0; $fail = 0; $reasons = [];
            foreach ($ids as $id) {
                $r = $this->regenerate_booking_class_links($id, $bulk_provider);
                if ($r['ok']) { $done++; } else { $fail++; if ($r['error']) $reasons[] = "#{$id}: {$r['error']}"; }
            }
            if ($reasons) update_option('gtbp_last_class_regen_error', "Success: {$done}, Fail: {$fail}\n" . implode("\n", array_slice($reasons, 0, 12)), false);
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=list&msg=bulk_class_regenerated&count=' . intval($done) . '&fail=' . intval($fail) . '&provider=' . rawurlencode($bulk_provider)));
            exit;
        }

        // نسخه ۱۳.۸: بازتولید گروهی لینک‌های Google Meet
        if (isset($_POST['gtbp_bulk_regenerate_meet_links']) && isset($_POST['bulk_ids']) && is_array($_POST['bulk_ids'])) {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'gtbp_bulk_booking_actions')) {
                wp_die('درخواست گروهی نامعتبر است.');
            }
            $ids = array_map('intval', $_POST['bulk_ids']);
            $done = 0; $fail = 0; $fail_reasons = [];
            if (!class_exists('GTBP_Google_Meet_Provider')) {
                update_option('gtbp_meet_last_error', 'کلاس GTBP_Google_Meet_Provider بارگذاری نشده است.');
                $fail = count($ids);
            } else {
                global $wpdb;
                $meet = GTBP_Google_Meet_Provider::instance();
                if (!$meet->is_enabled()) {
                    update_option('gtbp_meet_last_error', 'Google Meet در تنظیمات فعال نیست.');
                    $fail = count($ids);
                } elseif (!$meet->is_configured()) {
                    update_option('gtbp_meet_last_error', 'Meet پیکربندی کامل نشده (Client/Secret/RefreshToken). صفحه تنظیمات Google Meet را بررسی کنید.');
                    $fail = count($ids);
                } else {
                    foreach ($ids as $id) {
                        $b = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $id));
                        if (!$b) { $fail++; $fail_reasons[] = "#{$id}: رزرو پیدا نشد"; continue; }
                        // تأیید booking_time در فرمت HH:MM-HH:MM
                        if (empty($b->booking_time) || strpos($b->booking_time, '-') === false) {
                            $fail++; $fail_reasons[] = "#{$id}: فرمت زمان نامعتبر ('{$b->booking_time}')"; continue;
                        }
                        $title = ($b->class_name ?: 'کلاس') . ' - ' . trim($b->first_name . ' ' . $b->last_name);
                        $result = $meet->create_meeting_for_booking([
                            'booking_id'     => intval($b->id),
                            'date'           => $b->booking_date,
                            'time'           => $b->booking_time,
                            'title'          => $title,
                            'attendee_email' => $b->email,
                        ]);
                        if ($result && !empty($result['join_link'])) {
                            $done++;
                        } else {
                            $fail++;
                            $fail_reasons[] = "#{$id}: پاسخ Google خالی/شکست خورد (به آخرین خطا در تنظیمات Meet نگاه کنید)";
                        }
                    }
                    if ($fail_reasons) {
                        update_option('gtbp_meet_last_error', "Bulk regen - Success: {$done}, Fail: {$fail}\n" . implode("\n", array_slice($fail_reasons, 0, 10)));
                    }
                }
            }
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=list&msg=bulk_meet_regenerated&count=' . intval($done) . '&fail=' . intval($fail)));
            exit;
        }

        // نسخه ۱۳.۹: جستجوی دستی ویدئوی Meet بر اساس Meet ID
        // نانس با نام اختصاصی (نه _wpnonce) تا با نانس فرم گروهی بیرونی تداخل نکند.
        if (isset($_POST['gtbp_meet_lookup_by_id'])) {
            $booking_id = intval($_POST['gtbp_meet_lookup_by_id']);
            $nonce_key  = 'gtbp_meet_lookup_nonce_' . $booking_id;
            $meet_key   = 'meet_id_' . $booking_id;
            if (!$booking_id || empty($_POST[$nonce_key]) || !wp_verify_nonce($_POST[$nonce_key], 'gtbp_meet_lookup_' . $booking_id)) {
                wp_die('درخواست نامعتبر است.');
            }
            $meet_id = isset($_POST[$meet_key]) ? sanitize_text_field(wp_unslash($_POST[$meet_key])) : '';
            if ($meet_id === '') {
                wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=list&msg=meet_lookup_fail'));
                exit;
            }
            $found = false;
            if (class_exists('GTBP_Google_Meet_Provider')) {
                global $wpdb;
                $b = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id=%d", $booking_id));
                if ($b) {
                    $link = GTBP_Google_Meet_Provider::instance()->find_recording_by_meet_id($b, $meet_id);
                    if ($link) $found = true;
                }
            }
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=list&msg=' . ($found ? 'meet_lookup_ok' : 'meet_lookup_fail')));
            exit;
        }


        if (isset($_POST['gtbp_bulk_create_learning_sessions']) && isset($_POST['bulk_ids']) && is_array($_POST['bulk_ids'])) {
            if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'gtbp_bulk_booking_actions')) {
                wp_die('درخواست گروهی نامعتبر است.');
            }
            $ids = array_values(array_unique(array_filter(array_map('intval', $_POST['bulk_ids']))));
            $done = 0;
            $skipped = 0;
            if (!empty($ids) && class_exists('GTBP_Learning_Dashboard_v211')) {
                $learning = GTBP_Learning_Dashboard_v211::instance();
                foreach ($ids as $id) {
                    if ($learning->has_session_for_booking($id)) { $skipped++; continue; }
                    if ($learning->create_session_from_booking_id($id)) $done++; else $skipped++;
                }
            }
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=list&msg=bulk_sessions_created&count=' . intval($done) . '&skipped=' . intval($skipped)));
            exit;
        }
        
        if (isset($_POST['gtbp_confirm_pending'])) {
            global $wpdb;
            $ids = array_map('intval', $_POST['pending_ids']);
            if (!empty($ids)) {
                foreach ($ids as $id) {
                    $wpdb->update(
                        $this->table_name,
                        ['status' => 'confirmed'],
                        ['id' => $id]
                    );
                }
                wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=pending&msg=confirmed'));
                exit;
            }
        }


        if (isset($_POST['gtbp_manual_package_book'])) {
            global $wpdb;
            $selected_user_id = intval($_POST['mp_user_id']);
            $selected_user = get_userdata($selected_user_id);
            if (!$selected_user) wp_die('کاربر انتخاب‌شده معتبر نیست.');
            $package_id = isset($_POST['mp_package_id']) ? sanitize_text_field($_POST['mp_package_id']) : '';
            $package = $this->find_package_by_id($package_id);
            if (!$package) wp_die('طرح چندجلسه‌ای انتخاب‌شده معتبر نیست.');
            $class = $this->find_class_by_id($package['class_id']);
            if (!$class) wp_die('نوع کلاس مربوط به این طرح یافت نشد.');
            $lines = isset($_POST['mp_sessions']) ? preg_split('/\r\n|\r|\n/', trim(stripslashes($_POST['mp_sessions']))) : [];
            $cart = [];
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') continue;
                $parts = preg_split('/\s+/', $line, 2);
                if (count($parts) < 2) wp_die('فرمت جلسات طرح درست نیست. هر خط باید مثل 2026-06-01 09:00-10:00 باشد.');
                $date_input = sanitize_text_field($parts[0]);
                $time = sanitize_text_field($parts[1]);
                $date = '';
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_input)) {
                    $date = $date_input;
                } else {
                    $date = $this->jalali_string_to_gregorian_date($date_input);
                }
                if (empty($date)) wp_die('تاریخ یکی از جلسات معتبر نیست: ' . esc_html($date_input) . ' | فرمت مجاز: 2026-06-01 یا 1405/03/11');
                $cart[] = [
                    'date' => $date,
                    'time' => $time,
                    'jDate' => $this->gregorian_to_jalali_string($date),
                    'class' => $class,
                    'package' => $package,
                ];
            }
            $validation = $this->validate_package_cart($cart, $package);
            if (empty($validation['valid'])) wp_die(esc_html($validation['message']));
            foreach ($cart as $item) {
                // نسخه ۱۴.۱: تشخیص هم‌پوشانی زمانی (نه فقط تطابق دقیق)
                if ($this->slot_conflicts_with_bookings($item['date'], $item['time'])) {
                    wp_die('زمان ' . esc_html($item['time']) . ' در تاریخ ' . esc_html($item['date']) . ' با یک رزرو دیگر تداخل دارد.');
                }
            }
            $f = sanitize_text_field($selected_user->first_name ? $selected_user->first_name : $selected_user->display_name);
            $l = sanitize_text_field($selected_user->last_name);
            $e = sanitize_email($selected_user->user_email);
            $p = get_user_meta($selected_user_id, 'billing_phone', true);
            if (empty($p)) $p = 'ثبت نشده';
            $student_name = trim($f . ' ' . $l);
            $teacher_name = get_option('gtbp_bbb_teacher_name', self::BBB_DEFAULT_TEACHER_NAME);
            $jitsi_rooms = [];
            foreach ($cart as $item) {
                $room_name = $item['jDate'] . ' - ' . $student_name;
                $room = $this->create_jitsi_room($room_name, $student_name, $teacher_name);
                $meeting_id = $room ? $room['meeting_id'] : null;
                $student_link = $room ? $room['student_link'] : null;
                $teacher_link = $room ? $room['teacher_link'] : null;
                if ($room) $jitsi_rooms[] = ['date' => $item['date'], 'time' => $item['time'], 'class_name' => $item['class']['name'], 'student_link' => $student_link, 'teacher_link' => $teacher_link];
                $wpdb->insert($this->table_name, [
                    'first_name' => $f,
                    'last_name' => $l,
                    'email' => $e,
                    'phone' => $p,
                    'booking_date' => $item['date'],
                    'booking_time' => $item['time'],
                    'class_name' => $item['class']['name'] . ' - ' . $package['name'],
                    'status' => 'confirmed',
                    'roomeet_room_id' => $meeting_id,
                    'roomeet_join_link' => $student_link,
                    'bbb_moderator_link' => $teacher_link
                ]);
                $booking_id = $wpdb->insert_id;
                if ($booking_id && class_exists('GTBP_Learning_Dashboard_v211')) { GTBP_Learning_Dashboard_v211::instance()->create_session_from_booking_id($booking_id); }
                if ($booking_id) {
                    $booking = $wpdb->get_row("SELECT * FROM {$this->table_name} WHERE id = {$booking_id}");
                    $this->update_user_jitsi_links($selected_user_id, $booking);
                }
            }
            $totals = $this->calculate_cart_totals($cart, '', $selected_user_id);
            $this->send_booking_emails($f, $l, $e, $p, $cart, $totals['payable_total'], true, false, $jitsi_rooms);
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=manual&msg=manual_success'));
            exit;
        }

        if (isset($_POST['gtbp_manual_book'])) {
            global $wpdb;
            $selected_user_id = intval($_POST['m_user_id']);
            $selected_user = get_userdata($selected_user_id);

            $f = sanitize_text_field($selected_user->first_name ? $selected_user->first_name : $selected_user->display_name);
            $l = sanitize_text_field($selected_user->last_name);
            $e = sanitize_email($selected_user->user_email);
            $p = get_user_meta($selected_user_id, 'billing_phone', true);
            if(empty($p)) $p = 'ثبت نشده';

            $date = sanitize_text_field($_POST['m_date']);
            $time_custom = isset($_POST['m_time_custom']) ? sanitize_text_field($_POST['m_time_custom']) : '';
            $time_selected = isset($_POST['m_time']) ? sanitize_text_field($_POST['m_time']) : '';
            $time = !empty($time_custom) ? $time_custom : $time_selected;
            if (empty($time)) {
                wp_die('لطفاً زمان کلاس را وارد یا انتخاب کنید.');
            }
            $cname = sanitize_text_field($_POST['m_class']);
        
            list($gy, $gm, $gd) = explode('-', $date);
            $jalali = $this->internal_gregorian_to_jalali($gy, $gm, $gd);
            $jDate = $jalali[0] . '/' . sprintf('%02d', $jalali[1]) . '/' . sprintf('%02d', $jalali[2]);

            $student_name = trim($f . ' ' . $l);
            $teacher_name = get_option('gtbp_bbb_teacher_name', self::BBB_DEFAULT_TEACHER_NAME); // Fix #11
            $room_name = $jDate . ' - ' . $student_name;
            $room = $this->create_jitsi_room($room_name, $student_name, $teacher_name);
            
            $meeting_id = $room ? $room['meeting_id'] : null;
            $student_link = $room ? $room['student_link'] : null;
            $teacher_link = $room ? $room['teacher_link'] : null;

            $jitsi_rooms = [];
            if ($room) {
                $jitsi_rooms[] = [
                    'date' => $date,
                    'time' => $time,
                    'class_name' => $cname,
                    'student_link' => $student_link,
                    'teacher_link' => $teacher_link
                ];
            }

            $wpdb->insert(
                $this->table_name,
                [
                    'first_name' => $f,
                    'last_name' => $l,
                    'email' => $e,
                    'phone' => $p,
                    'booking_date' => $date,
                    'booking_time' => $time,
                    'class_name' => $cname,
                    'status' => 'confirmed',
                    'roomeet_room_id' => $meeting_id,
                    'roomeet_join_link' => $student_link,
                    'bbb_moderator_link' => $teacher_link
                ]
            );
            
            $booking_id = $wpdb->insert_id;
                if ($booking_id && class_exists('GTBP_Learning_Dashboard_v211')) { GTBP_Learning_Dashboard_v211::instance()->create_session_from_booking_id($booking_id); }
            
            if ($room && $booking_id) {
                $booking = $wpdb->get_row("SELECT * FROM {$this->table_name} WHERE id = {$booking_id}");
                $this->update_user_jitsi_links($selected_user_id, $booking);
            }

            $cart_mock = [
                ['date' => $date, 'time' => $time, 'jDate' => $jDate, 'class' => ['name' => $cname]]
            ];
            
            $this->send_booking_emails($f, $l, $e, $p, $cart_mock, 0, true, false, $jitsi_rooms);

            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=manual&msg=manual_success'));
            exit;
        }

        if (isset($_POST['gtbp_save_hours'])) {
            $hours = [];
            $days_order = [6, 0, 1, 2, 3, 4, 5];
            foreach ($days_order as $day_idx) {
                $hours[] = [
                    'day' => $day_idx,
                    'slots' => sanitize_text_field(stripslashes($_POST['slots'][$day_idx])),
                    'priority_slots' => isset($_POST['priority_slots'][$day_idx]) ? sanitize_text_field(stripslashes($_POST['priority_slots'][$day_idx])) : '',
                    'active' => isset($_POST['active'][$day_idx]) ? true : false
                ];
            }
            update_option('gtbp_working_hours', $hours);
            $this->cached_hours = null;
        }


        if (isset($_POST['gtbp_save_user_hours'])) {
            $user_id = isset($_POST['gtbp_private_user_id']) ? absint($_POST['gtbp_private_user_id']) : 0;
            if ($user_id > 0) {
                $all_user_hours = $this->get_user_working_hours_all();
                $days_order = [6, 0, 1, 2, 3, 4, 5];
                $user_rows = [];
                foreach ($days_order as $day_idx) {
                    $raw_slots = isset($_POST['private_slots'][$day_idx]) ? sanitize_text_field(stripslashes($_POST['private_slots'][$day_idx])) : '';
                    $slots = implode(', ', $this->normalize_slots_list($raw_slots));
                    if ($slots !== '') {
                        $user_rows[] = [
                            'day' => $day_idx,
                            'slots' => $slots,
                            'active' => true
                        ];
                    }
                }
                if (!empty($user_rows)) {
                    $all_user_hours[$user_id] = $user_rows;
                } else {
                    unset($all_user_hours[$user_id]);
                }
                update_option('gtbp_user_working_hours', $all_user_hours);
                $this->cached_user_hours = null;
                wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=hours&msg=user_hours_saved'));
                exit;
            }
        }

        if (isset($_GET['action']) && $_GET['action'] === 'delete_user_hours' && isset($_GET['user_id']) && current_user_can('manage_options')) {
            $user_id = absint($_GET['user_id']);
            $all_user_hours = $this->get_user_working_hours_all();
            if ($user_id && isset($all_user_hours[$user_id])) {
                unset($all_user_hours[$user_id]);
                update_option('gtbp_user_working_hours', $all_user_hours);
                $this->cached_user_hours = null;
            }
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=hours&msg=user_hours_deleted'));
            exit;
        }

        if (isset($_POST['gtbp_save_bank'])) {
            update_option('gtbp_bank_info', [
                'card' => sanitize_text_field($_POST['bank_card']),
                'owner' => sanitize_text_field($_POST['bank_owner'])
            ]);
            $this->cached_bank_info = null;
        }
        
        if (isset($_POST['gtbp_save_payment_settings'])) {
            $online = isset($_POST['gtbp_payment_online']) ? '1' : '0';
            $card   = isset($_POST['gtbp_payment_card']) ? '1' : '0';
            update_option('gtbp_payment_online_enabled', $online);
            update_option('gtbp_payment_card_enabled', $card);
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=settings&msg=payment_saved'));
            exit;
        }

        if (isset($_POST['gtbp_add_holiday'])) {
            $date = isset($_POST['holiday_date']) ? sanitize_text_field($_POST['holiday_date']) : '';
            $jalali_date = isset($_POST['holiday_jalali']) ? sanitize_text_field($_POST['holiday_jalali']) : '';
            if (empty($date) && !empty($jalali_date)) {
                $date = $this->jalali_string_to_gregorian_date($jalali_date);
            }
            if ($date) {
                $holidays = get_option('gtbp_holidays', []);
                if (!in_array($date, $holidays)) $holidays[] = $date;
                sort($holidays);
                update_option('gtbp_holidays', $holidays);
                $this->cached_holidays = null;
            }
        }
        if (isset($_GET['action']) && $_GET['action'] == 'delete_holiday' && isset($_GET['date'])) {
            $holidays = get_option('gtbp_holidays', []);
            $holidays = array_diff($holidays, [sanitize_text_field($_GET['date'])]);
            update_option('gtbp_holidays', $holidays);
            $this->cached_holidays = null;
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=holidays'));
            exit;
        }

        if (isset($_POST['gtbp_add_class'])) {
            $classes = get_option('gtbp_classes', []);
            $next_sort = 0;
            foreach ($classes as $existing) $next_sort = max($next_sort, isset($existing['sort']) ? intval($existing['sort']) : 0);
            $classes[] = [
                'id' => uniqid(),
                'name' => sanitize_text_field($_POST['class_name']),
                'price' => intval($_POST['class_price']),
                'sort' => $next_sort + 10,
                'bg' => $this->sanitize_card_color($_POST['class_bg'] ?? ''),
                'color' => $this->sanitize_card_color($_POST['class_color'] ?? ''),
                'accent' => $this->sanitize_card_color($_POST['class_accent'] ?? ''),
            ];
            update_option('gtbp_classes', $classes);
            $this->cached_classes = null;
        }
        if (isset($_POST['gtbp_save_card_styles'])) {
            check_admin_referer('gtbp_save_card_styles');
            $posted = isset($_POST['gtbp_card']) && is_array($_POST['gtbp_card']) ? wp_unslash($_POST['gtbp_card']) : [];
            $classes = get_option('gtbp_classes', []);
            foreach ($classes as $index => $class) {
                $id = (string) ($class['id'] ?? '');
                if ($id === '' || empty($posted['class'][$id])) continue;
                $fields = $posted['class'][$id];
                $classes[$index]['sort'] = intval($fields['sort'] ?? 0);
                $reset = !empty($fields['reset']);
                $classes[$index]['bg'] = $reset ? '' : $this->sanitize_card_color($fields['bg'] ?? '');
                $classes[$index]['color'] = $reset ? '' : $this->sanitize_card_color($fields['color'] ?? '');
                $classes[$index]['accent'] = $reset ? '' : $this->sanitize_card_color($fields['accent'] ?? '');
            }
            update_option('gtbp_classes', array_values($classes));
            $packages = get_option('gtbp_booking_packages', []);
            if (is_array($packages)) {
                foreach ($packages as $index => $package) {
                    $id = (string) ($package['id'] ?? '');
                    if ($id === '' || empty($posted['package'][$id])) continue;
                    $fields = $posted['package'][$id];
                    $packages[$index]['sort'] = intval($fields['sort'] ?? 0);
                    $reset = !empty($fields['reset']);
                    $packages[$index]['bg'] = $reset ? '' : $this->sanitize_card_color($fields['bg'] ?? '');
                    $packages[$index]['color'] = $reset ? '' : $this->sanitize_card_color($fields['color'] ?? '');
                    $packages[$index]['accent'] = $reset ? '' : $this->sanitize_card_color($fields['accent'] ?? '');
                }
                update_option('gtbp_booking_packages', array_values($packages));
            }
            update_option('gtbp_cards_packages_first', empty($_POST['gtbp_cards_packages_first']) ? '0' : '1', false);
            $this->cached_classes = null;
            delete_transient('gtbp_cached_classes');
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=classes&msg=cards_saved'));
            exit;
        }

        if (isset($_GET['action']) && $_GET['action'] == 'delete_class' && isset($_GET['class_id'])) {
            $classes = get_option('gtbp_classes', []);
            foreach ($classes as $k => $c) {
                if ($c['id'] == $_GET['class_id']) unset($classes[$k]);
            }
            update_option('gtbp_classes', array_values($classes));
            $this->cached_classes = null;
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=classes'));
            exit;
        }
        

        if (isset($_POST['gtbp_add_package'])) {
            $packages = get_option('gtbp_booking_packages', []);
            if (!is_array($packages)) $packages = [];
            $class_id = isset($_POST['package_class_id']) ? sanitize_text_field($_POST['package_class_id']) : '';
            $class = $this->find_class_by_id($class_id);
            if (!$class) {
                wp_die('نوع کلاس انتخاب شده برای طرح معتبر نیست.');
            }
            $session_count = isset($_POST['package_session_count']) ? intval($_POST['package_session_count']) : 0;
            $window_days = isset($_POST['package_window_days']) ? intval($_POST['package_window_days']) : 0;
            $discount_percent = isset($_POST['package_discount_percent']) ? floatval($_POST['package_discount_percent']) : 0;
            if ($session_count < 2 || $window_days < 1 || $discount_percent < 0 || $discount_percent > 100) {
                wp_die('اطلاعات طرح چندجلسه‌ای معتبر نیست.');
            }
            $packages[] = [
                'id' => uniqid('pkg_', true),
                'name' => sanitize_text_field($_POST['package_name']),
                'class_id' => $class_id,
                'session_count' => $session_count,
                'window_days' => $window_days,
                'discount_percent' => $discount_percent,
                'active' => isset($_POST['package_active']) ? true : false,
                'bg' => $this->sanitize_card_color($_POST['package_bg'] ?? ''),
                'color' => $this->sanitize_card_color($_POST['package_color'] ?? ''),
                'accent' => $this->sanitize_card_color($_POST['package_accent'] ?? ''),
            ];
            update_option('gtbp_booking_packages', array_values($packages));
            $this->cached_packages = null;
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=classes&msg=package_saved'));
            exit;
        }
        if (isset($_GET['action']) && $_GET['action'] == 'delete_package' && isset($_GET['package_id'])) {
            $packages = get_option('gtbp_booking_packages', []);
            if (!is_array($packages)) $packages = [];
            $package_id = sanitize_text_field($_GET['package_id']);
            $packages = array_values(array_filter($packages, function($pkg) use ($package_id) {
                return !isset($pkg['id']) || $pkg['id'] !== $package_id;
            }));
            update_option('gtbp_booking_packages', $packages);
            $this->cached_packages = null;
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=classes&msg=package_deleted'));
            exit;
        }

        if (isset($_POST['gtbp_save_user_adjustment']) && isset($_POST['user_id']) && isset($_POST['adjustment_amount'])) {
            $user_id = intval($_POST['user_id']);
            $amount = intval($_POST['adjustment_amount']);
            $this->set_user_adjustment($user_id, $amount);
            wp_redirect(admin_url('admin.php?page=gtbp_bookings&tab=debt_credit&msg=adjustment_saved'));
            exit;
        }
        
        if (isset($_POST['gtbp_save_bbb'])) {
            update_option('gtbp_bbb_url', $this->normalize_bbb_base_url(isset($_POST['bbb_url']) ? esc_url_raw($_POST['bbb_url']) : ''));
            update_option('gtbp_bbb_secret', isset($_POST['bbb_secret']) ? sanitize_text_field($_POST['bbb_secret']) : '');
            update_option('gtbp_bbb_teacher_name', isset($_POST['bbb_teacher_name']) ? sanitize_text_field($_POST['bbb_teacher_name']) : self::BBB_DEFAULT_TEACHER_NAME);
            // Fix #5: if the admin clears the password fields, keep the existing random value rather than resetting to trivial defaults.
            if (!empty($_POST['bbb_attendee_password'])) update_option('gtbp_bbb_attendee_password', sanitize_text_field($_POST['bbb_attendee_password']));
            if (!empty($_POST['bbb_moderator_password'])) update_option('gtbp_bbb_moderator_password', sanitize_text_field($_POST['bbb_moderator_password']));
            update_option('gtbp_bbb_auto_create_enabled', isset($_POST['gtbp_bbb_auto_create_enabled']) ? '1' : '0');
            // نسخه ۱۴.۰: برگشت به تب BBB در صفحه یکپارچه
            wp_redirect(admin_url('admin.php?page=gtbp_online_class&tab=bbb&msg=bbb_saved'));
            exit;
        }

        if (isset($_POST['gtbp_test_bbb'])) {
            $result = $this->bbb_health_check(isset($_POST['bbb_url']) ? esc_url_raw($_POST['bbb_url']) : null, isset($_POST['bbb_secret']) ? sanitize_text_field($_POST['bbb_secret']) : null);
            echo $result ? '<div class="notice notice-success"><p>✅ اتصال به BigBlueButton با موفقیت برقرار شد.</p></div>' : '<div class="notice notice-error"><p>❌ خطا در اتصال به BigBlueButton. آدرس API و Secret را بررسی کنید.</p></div>';
        }

        if (isset($_POST['gtbp_save_personal_bbb']) && check_admin_referer('gtbp_save_personal_bbb', 'gtbp_personal_bbb_nonce')) {
            update_option('gtbp_personal_bbb_url', $this->normalize_bbb_base_url(esc_url_raw(wp_unslash($_POST['personal_bbb_url'] ?? ''))));
            update_option('gtbp_personal_bbb_secret', sanitize_text_field(wp_unslash($_POST['personal_bbb_secret'] ?? '')));
            update_option('gtbp_personal_bbb_teacher_name', sanitize_text_field(wp_unslash($_POST['personal_bbb_teacher_name'] ?? self::BBB_DEFAULT_TEACHER_NAME)));
            if (!empty($_POST['personal_bbb_attendee_password'])) update_option('gtbp_personal_bbb_attendee_password', sanitize_text_field(wp_unslash($_POST['personal_bbb_attendee_password'])));
            if (!empty($_POST['personal_bbb_moderator_password'])) update_option('gtbp_personal_bbb_moderator_password', sanitize_text_field(wp_unslash($_POST['personal_bbb_moderator_password'])));
            update_option('gtbp_personal_bbb_auto_create_enabled', isset($_POST['personal_bbb_auto_create_enabled']) ? '1' : '0');
            if (!empty($_POST['recording_bridge_secret'])) update_option('gtbp_bridge_shared_secret', sanitize_text_field(wp_unslash($_POST['recording_bridge_secret'])));
            update_option('gtbp_bridge_gateway_url', esc_url_raw(rtrim(wp_unslash($_POST['recording_bridge_gateway'] ?? ''), '/')));
            wp_safe_redirect(admin_url('admin.php?page=gtbp_online_class&tab=bbb_personal&msg=personal_saved'));
            exit;
        }

        if (isset($_POST['gtbp_test_personal_bbb']) && check_admin_referer('gtbp_save_personal_bbb', 'gtbp_personal_bbb_nonce')) {
            $result = $this->bbb_health_diagnostic(esc_url_raw(wp_unslash($_POST['personal_bbb_url'] ?? '')), sanitize_text_field(wp_unslash($_POST['personal_bbb_secret'] ?? '')), 'bbb_personal');
            if (!empty($result['ok'])) {
                echo '<div class="notice notice-success"><p>اتصال به BigBlueButton شخصی برقرار شد.</p><p dir="ltr"><code>'.esc_html($result['endpoint']).'</code></p></div>';
            } else {
                echo '<div class="notice notice-error"><p><strong>اتصال برقرار نشد.</strong></p><p>'.esc_html($result['message']).'</p><p>HTTP: '.intval($result['http_code']).' | نوع خطا: <code>'.esc_html($result['kind']).'</code></p><p dir="ltr"><code>'.esc_html($result['endpoint']).'</code></p></div>';
            }
        }

        // نسخه ۱۴.۰: ذخیره «سرویس فعال»
        if (isset($_POST['gtbp_save_active_service']) && check_admin_referer('gtbp_save_active_service', 'gtbp_active_nonce')) {
            $svc = isset($_POST['active_class_service']) ? sanitize_key($_POST['active_class_service']) : 'bbb';
            if (!in_array($svc, ['bbb', 'bbb_personal', 'meet', 'roomeet'], true)) $svc = 'bbb';
            update_option('gtbp_active_class_service', $svc);
            // همگام‌سازی سازگاری: اگر BBB انتخاب شد، auto_create روشن؛ در غیر این صورت خاموش تا مسیر قدیمی BBB تداخل نکند.
            update_option('gtbp_bbb_auto_create_enabled', $svc === 'bbb' ? '1' : '0');
            update_option('gtbp_personal_bbb_auto_create_enabled', $svc === 'bbb_personal' ? '1' : get_option('gtbp_personal_bbb_auto_create_enabled', '1'));
            wp_redirect(admin_url('admin.php?page=gtbp_online_class&tab=active&msg=active_saved'));
            exit;
        }

        // نسخه ۱۴.۵: حذف همه‌ی کلاس‌های روومیت (فقط روومیت؛ رزروهای سایت دست‌نخورده)
        if (isset($_POST['gtbp_roomeet_delete_all_rooms']) && check_admin_referer('gtbp_roomeet_delete_all_rooms', 'gtbp_roomeet_del_nonce')) {
            $deleted = 0; $failed = 0;
            if (class_exists('GTBP_Roomeet_Provider')) {
                $res = GTBP_Roomeet_Provider::instance()->delete_all_rooms();
                $deleted = intval($res['deleted'] ?? 0);
                $failed  = intval($res['failed'] ?? 0);
                // اشاره‌گرهای اتاق (rmt_meeting_id) روی رزروها اکنون باطل‌اند؛ فقط همین اشاره‌گر را پاک می‌کنیم
                // تا با کلیک بعدی یا «تولید مجدد گروهی» اتاق تازه ساخته شود. رزرو/لینک/ضبط‌های ذخیره‌شده دست‌نخورده می‌مانند.
                global $wpdb;
                $cols = $wpdb->get_col("SHOW COLUMNS FROM {$this->table_name}");
                if (in_array('rmt_meeting_id', $cols, true)) {
                    $wpdb->query("UPDATE {$this->table_name} SET rmt_meeting_id = NULL, rmt_recording_checked = NULL WHERE class_provider = 'roomeet'");
                }
            }
            wp_redirect(admin_url('admin.php?page=gtbp_online_class&tab=roomeet&rmt_msg=rooms_deleted&deleted=' . $deleted . '&failed=' . $failed));
            exit;
        }

        // نسخه ۱۴.۰: تنظیمات روومیت
        if (isset($_POST['gtbp_roomeet_save']) && check_admin_referer('gtbp_roomeet_save', 'gtbp_roomeet_nonce')) {
            $R = 'GTBP_Roomeet_Provider';
            update_option($R::OPT_API_KEY,  sanitize_text_field(wp_unslash($_POST['rmt_api_key'] ?? '')));
            update_option($R::OPT_PHONE,    sanitize_text_field(wp_unslash($_POST['rmt_phone'] ?? '')));
            update_option($R::OPT_PASSWORD, (string) wp_unslash($_POST['rmt_password'] ?? ''));
            update_option($R::OPT_SALE_ID,  sanitize_text_field(wp_unslash($_POST['rmt_sale_id'] ?? '')));
            update_option($R::OPT_TEACHER,  sanitize_text_field(wp_unslash($_POST['rmt_teacher'] ?? '')));
            // توکن قبلی را باطل کن تا با اعتبار جدید لاگین شود
            delete_option($R::OPT_TOKEN); delete_option($R::OPT_TOKEN_TIME);
            wp_redirect(admin_url('admin.php?page=gtbp_online_class&tab=roomeet&rmt_msg=saved'));
            exit;
        }
        if (isset($_POST['gtbp_roomeet_test']) && check_admin_referer('gtbp_roomeet_save', 'gtbp_roomeet_nonce')) {
            // ابتدا ذخیره کن، بعد تست
            $R = 'GTBP_Roomeet_Provider';
            update_option($R::OPT_API_KEY,  sanitize_text_field(wp_unslash($_POST['rmt_api_key'] ?? '')));
            update_option($R::OPT_PHONE,    sanitize_text_field(wp_unslash($_POST['rmt_phone'] ?? '')));
            update_option($R::OPT_PASSWORD, (string) wp_unslash($_POST['rmt_password'] ?? ''));
            update_option($R::OPT_SALE_ID,  sanitize_text_field(wp_unslash($_POST['rmt_sale_id'] ?? '')));
            update_option($R::OPT_TEACHER,  sanitize_text_field(wp_unslash($_POST['rmt_teacher'] ?? '')));
            delete_option($R::OPT_TOKEN); delete_option($R::OPT_TOKEN_TIME);
            GTBP_Roomeet_Provider::instance()->clear_error();
            $ok = GTBP_Roomeet_Provider::instance()->test_connection();
            wp_redirect(admin_url('admin.php?page=gtbp_online_class&tab=roomeet&rmt_msg=' . ($ok ? 'test_ok' : 'test_err')));
            exit;
        }
        if (isset($_POST['gtbp_roomeet_fetch_services']) && check_admin_referer('gtbp_roomeet_save', 'gtbp_roomeet_nonce')) {
            $R = 'GTBP_Roomeet_Provider';
            update_option($R::OPT_API_KEY,  sanitize_text_field(wp_unslash($_POST['rmt_api_key'] ?? '')));
            update_option($R::OPT_PHONE,    sanitize_text_field(wp_unslash($_POST['rmt_phone'] ?? '')));
            update_option($R::OPT_PASSWORD, (string) wp_unslash($_POST['rmt_password'] ?? ''));
            update_option($R::OPT_TEACHER,  sanitize_text_field(wp_unslash($_POST['rmt_teacher'] ?? '')));
            delete_option($R::OPT_TOKEN); delete_option($R::OPT_TOKEN_TIME);
            $svcs = GTBP_Roomeet_Provider::instance()->fetch_services();
            if (!empty($svcs)) set_transient('gtbp_roomeet_services_cache', $svcs, HOUR_IN_SECONDS);
            wp_redirect(admin_url('admin.php?page=gtbp_online_class&tab=roomeet&rmt_msg=' . (!empty($svcs) ? 'svc_ok' : 'svc_err')));
            exit;
        }

    }

    public function admin_page() {
        global $wpdb;
        
        $tab = isset($_GET['tab']) ? $_GET['tab'] : 'list';
        
        if (isset($_GET['msg']) && $_GET['msg'] == 'bulk_deleted') {
            echo '<div class="notice notice-success is-dismissible"><p>رزروهای انتخاب شده با موفقیت حذف شدند.</p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'payment_saved') {
            echo '<div class="notice notice-success is-dismissible"><p>تنظیمات روش‌های پرداخت ذخیره شد.</p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'gls_session_created') {
            echo '<div class="notice notice-success is-dismissible"><p>جلسه آموزشی برای رزرو انتخاب‌شده ساخته شد.</p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'gls_session_exists_or_failed') {
            echo '<div class="notice notice-warning is-dismissible"><p>برای این رزرو قبلاً جلسه ساخته شده یا امکان ساخت جلسه وجود نداشت.</p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'adjustment_saved') {
            echo '<div class="notice notice-success is-dismissible"><p>مقدار بدهی/طلب کاربر با موفقیت ذخیره شد.</p></div>';
        }
        
        if (isset($_GET['msg']) && $_GET['msg'] == 'links_saved') {
            echo '<div class="notice notice-success is-dismissible"><p>لینک‌های کلاس با موفقیت ذخیره شدند.</p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'booking_deleted') {
            echo '<div class="notice notice-success is-dismissible"><p>رزرو انتخاب‌شده حذف شد.</p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'bulk_links_deleted') {
            $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>لینک‌های ' . esc_html($count) . ' رزرو انتخاب‌شده حذف شدند.</p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'bulk_links_regenerated') {
            $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>لینک‌های ' . esc_html($count) . ' رزرو انتخاب‌شده دوباره ساخته شدند.</p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'bulk_meet_regenerated') {
            $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
            $fail  = isset($_GET['fail']) ? intval($_GET['fail']) : 0;
            $type  = ($count > 0) ? 'success' : ($fail > 0 ? 'error' : 'warning');
            $meet_settings_url = esc_url(admin_url('admin.php?page=gtbp_meet'));
            $extra = $fail ? ' | <a href="' . $meet_settings_url . '">مشاهده جزئیات خطا در تنظیمات Meet</a>' : '';
            echo '<div class="notice notice-' . $type . ' is-dismissible"><p>لینک Google Meet برای ' . esc_html($count) . ' رزرو ساخته شد.' . ($fail ? ' ' . esc_html($fail) . ' مورد ناموفق بود.' . $extra : '') . '</p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'meet_lookup_ok') {
            echo '<div class="notice notice-success is-dismissible"><p>✅ ویدئوی Google Meet پیدا و به رزرو متصل شد.</p></div>';
        }
        // نسخه ۱۴.۰: پیام‌های سرویس یکپارچه
        $svc_names = ['bbb' => 'BigBlueButton', 'meet' => 'Google Meet', 'roomeet' => 'روومیت'];
        if (isset($_GET['msg']) && $_GET['msg'] == 'class_regenerated') {
            $pv = isset($_GET['provider']) ? sanitize_key($_GET['provider']) : '';
            echo '<div class="notice notice-success is-dismissible"><p>✅ لینک کلاس با موفقیت ساخته/به‌روزرسانی شد' . (isset($svc_names[$pv]) ? ' (' . esc_html($svc_names[$pv]) . ')' : '') . '.</p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'class_regenerate_failed') {
            $pv = isset($_GET['provider']) ? sanitize_key($_GET['provider']) : '';
            $err = get_option('gtbp_last_class_regen_error', '');
            $settings_hint = $pv === 'roomeet' ? admin_url('admin.php?page=gtbp_online_class&tab=roomeet') : ($pv === 'meet' ? admin_url('admin.php?page=gtbp_meet') : admin_url('admin.php?page=gtbp_online_class&tab=bbb'));
            echo '<div class="notice notice-error is-dismissible"><p>❌ ساخت لینک کلاس ناموفق بود' . (isset($svc_names[$pv]) ? ' (' . esc_html($svc_names[$pv]) . ')' : '') . '. <a href="' . esc_url($settings_hint) . '">بررسی تنظیمات سرویس</a></p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'bulk_class_regenerated') {
            $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
            $fail  = isset($_GET['fail']) ? intval($_GET['fail']) : 0;
            $pv    = isset($_GET['provider']) ? sanitize_key($_GET['provider']) : '';
            $type  = ($count > 0) ? 'success' : ($fail > 0 ? 'error' : 'warning');
            $extra = $fail ? ' | جزئیات خطا: ' . esc_html(get_option('gtbp_last_class_regen_error', '')) : '';
            echo '<div class="notice notice-' . $type . ' is-dismissible"><p>لینک کلاس (' . esc_html($svc_names[$pv] ?? '') . ') برای ' . esc_html($count) . ' رزرو ساخته/به‌روزرسانی شد.' . ($fail ? ' ' . esc_html($fail) . ' مورد ناموفق.' . $extra : '') . '</p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'roomeet_lookup_ok') {
            echo '<div class="notice notice-success is-dismissible"><p>✅ ضبط روومیت پیدا، منتشر و به رزرو متصل شد.</p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'roomeet_lookup_fail') {
            echo '<div class="notice notice-error is-dismissible"><p>❌ ضبطی برای این شناسه جلسه روومیت پیدا نشد. مطمئن شوید جلسه ضبط شده و شناسه درست است.</p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'meet_lookup_fail') {
            echo '<div class="notice notice-error is-dismissible"><p>❌ ویدئویی برای این Meet ID در Drive پیدا نشد. مطمئن شوید ضبط ذخیره شده و پوشه ضبط‌ها در تنظیمات Meet درست است.</p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'bulk_sessions_created') {
            $count = isset($_GET['count']) ? intval($_GET['count']) : 0;
            $skipped = isset($_GET['skipped']) ? intval($_GET['skipped']) : 0;
            echo '<div class="notice notice-success is-dismissible"><p>برای ' . esc_html($count) . ' رزرو انتخاب‌شده جلسه آموزشی ساخته شد. ' . ($skipped ? esc_html($skipped) . ' مورد قبلاً جلسه داشتند یا قابل ساخت نبودند.' : '') . '</p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'bbb_regenerated') {
            echo '<div class="notice notice-success is-dismissible"><p>لینک جدید BigBlueButton با موفقیت ساخته و جایگزین شد.</p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'bbb_links_deleted') {
            echo '<div class="notice notice-success is-dismissible"><p>لینک‌های کلاس حذف شدند و کلاس قبلی در BigBlueButton بسته شد.</p></div>';
        }
        if (isset($_GET['msg']) && ($_GET['msg'] == 'bbb_regenerate_failed' || $_GET['msg'] == 'bbb_links_delete_failed')) {
            echo '<div class="notice notice-error is-dismissible"><p>عملیات لینک BBB انجام نشد. تنظیمات BBB یا رکورد رزرو را بررسی کنید.</p></div>';
        }
        
        echo '<div class="wrap gtbp-admin-wrap">';
        echo '<h1>مدیریت رزرو کلاس آلمانی</h1>';
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="?page=gtbp_bookings&tab=list" class="nav-tab '.($tab=='list'?'nav-tab-active':'').'">لیست رزروها</a>';
        echo '<a href="?page=gtbp_bookings&tab=pending" class="nav-tab '.($tab=='pending'?'nav-tab-active':'').'">در انتظار پرداخت</a>';
        echo '<a href="?page=gtbp_bookings&tab=manual" class="nav-tab '.($tab=='manual'?'nav-tab-active':'').'">ثبت رزرو دستی</a>';
        echo '<a href="?page=gtbp_bookings&tab=hours" class="nav-tab '.($tab=='hours'?'nav-tab-active':'').'">ساعات کاری</a>';
        echo '<a href="?page=gtbp_bookings&tab=classes" class="nav-tab '.($tab=='classes'?'nav-tab-active':'').'">تعریف کلاس‌ها</a>';
        echo '<a href="?page=gtbp_bookings&tab=holidays" class="nav-tab '.($tab=='holidays'?'nav-tab-active':'').'">تعطیلات</a>';
        echo '<a href="?page=gtbp_bookings&tab=settings" class="nav-tab '.($tab=='settings'?'nav-tab-active':'').'">تنظیمات پایه</a>';
        echo '<a href="?page=gtbp_bookings&tab=debt_credit" class="nav-tab '.($tab=='debt_credit'?'nav-tab-active':'').'">مدیریت بدهی/طلب</a>';
        echo '<a href="?page=gtbp_bookings&tab=coupons" class="nav-tab '.($tab=='coupons'?'nav-tab-active':'').'">کدهای تخفیف</a>';
        echo '</h2>';

        if ($tab == 'list') {
            $this->admin_tab_list();
        } elseif ($tab == 'pending') {
            $this->admin_tab_pending();
        } elseif ($tab == 'manual') {
            $this->admin_tab_manual();
        } elseif ($tab == 'holidays') {
            $this->admin_tab_holidays();
        } elseif ($tab == 'classes') {
            $this->admin_tab_classes();
        } elseif ($tab == 'hours') {
            $this->admin_tab_hours();
        } elseif ($tab == 'settings') {
            $this->admin_tab_settings();
        } elseif ($tab == 'debt_credit') {
            $this->admin_tab_debt_credit();
        } elseif ($tab == 'coupons') {
            // نسخه ۱۴.۱: کدهای تخفیف به‌صورت تب داخل همین صفحه
            $this->admin_coupons_page();
        }

        echo '</div>';
    }

    private function admin_tab_list() {
        global $wpdb;
        $search_date = isset($_GET['s_date']) ? sanitize_text_field($_GET['s_date']) : '';
        $search_name = isset($_GET['s_name']) ? sanitize_text_field($_GET['s_name']) : '';

        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $offset = ($current_page - 1) * $per_page;

        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'booking_date';
        $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'DESC';
        $allowed_orderby = array('id', 'first_name', 'class_name', 'booking_date', 'status');
        if (!in_array($orderby, $allowed_orderby)) $orderby = 'booking_date';
        $order = ($order === 'ASC') ? 'ASC' : 'DESC';

        echo '<style>
            .gtbp-booking-cards-wrap { margin-top:16px; display:grid; grid-template-columns: repeat(auto-fit, minmax(430px, 1fr)); gap:10px; align-items:start; }
            .gtbp-booking-card { position:relative; background:#fff; border:1px solid #e5e7eb; border-radius:15px; padding:10px 12px; box-shadow:0 5px 16px rgba(0,0,0,0.042); transition:transform .15s ease, box-shadow .15s ease; overflow:visible; z-index:1; }
            .gtbp-booking-card:has(.gtbp-admin-menu[open]), .gtbp-booking-card.gtbp-card-menu-open { z-index:999; }
            .gtbp-booking-card:hover { transform:translateY(-1px); box-shadow:0 9px 24px rgba(0,0,0,0.075); }
            .gtbp-booking-card-future { background:linear-gradient(135deg, #f0f9ff 0%, #ffffff 76%); border-color:#bae6fd; }
            .gtbp-booking-card-future:before { content:"آینده"; position:absolute; top:9px; left:9px; background:#e0f2fe; color:#0369a1; border:1px solid #7dd3fc; border-radius:999px; padding:1px 7px; font-size:9.5px; font-weight:bold; }
            .gtbp-card-top { display:flex; justify-content:space-between; gap:10px; align-items:flex-start; padding-left:56px; }
            .gtbp-card-id { direction:ltr; color:#8B0000; font-weight:800; font-size:13px; }
            .gtbp-card-identity { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-top:4px; }
            .gtbp-class-badge { display:inline-flex; align-items:center; background:#f3f4f6; border:1px solid #e5e7eb; color:#4b5563; border-radius:999px; padding:3px 9px; font-size:11px; font-weight:700; line-height:1.5; }
            .gtbp-card-student { color:#111827; font-weight:900; font-size:13.5px; }
            .gtbp-card-status { display:inline-flex; align-items:center; border-radius:999px; padding:4px 9px; background:#f8fafc; border:1px solid #e5e7eb; font-size:11px; font-weight:bold; white-space:nowrap; }
            .gtbp-card-check { display:flex; align-items:center; gap:5px; color:#555; font-size:12px; }
            .gtbp-card-check input { transform:scale(1.1); }
            .gtbp-card-meta { display:grid; grid-template-columns:repeat(4, minmax(0,1fr)); gap:7px; margin:9px 0; }
            .gtbp-card-meta-item { background:rgba(255,255,255,.78); border:1px solid #edf0f3; border-radius:11px; padding:7px 8px; min-height:38px; box-sizing:border-box; }
            .gtbp-card-meta-item-contact { display:flex; flex-direction:column; justify-content:center; }
            .gtbp-card-contact-tools { display:flex; align-items:center; justify-content:flex-start; gap:6px; width:100%; direction:ltr; min-height:28px; box-sizing:border-box; }
            .gtbp-card-meta-label { display:block; font-size:10.5px; color:#6b7280; margin-bottom:3px; }
            .gtbp-card-meta-value { display:block; color:#111827; font-weight:700; font-size:12px; overflow-wrap:anywhere; line-height:1.55; }
            .gtbp-card-bottom-row { display:grid; grid-template-columns:minmax(126px,1fr) minmax(126px,1fr) minmax(126px,1fr); align-items:center; justify-items:center; gap:12px; margin-top:8px; border-top:1px dashed #e5e7eb; padding-top:8px; overflow:visible; position:relative; }
            .gtbp-link-tools { display:contents; }
            .gtbp-link-role { display:inline-flex; gap:4px; align-items:center; justify-content:center; border:1px solid #e5e7eb; background:#fff; border-radius:10px; padding:4px 5px; white-space:nowrap; min-width:126px; box-sizing:border-box; }
            .gtbp-link-role-student { grid-column:3; grid-row:1; justify-self:center; }
            .gtbp-link-role-teacher { grid-column:2; grid-row:1; justify-self:center; }
            .gtbp-card-bottom-row .gtbp-admin-menu { grid-column:1; grid-row:1; justify-self:center; }
            .gtbp-link-role-label { display:none; }
            .gtbp-icon-btn { width:30px; height:28px; padding:0; border-radius:8px; border:1px solid #d7d7d7; background:#fff; color:#333; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; justify-content:center; font-size:14px; line-height:1; flex:0 0 auto; box-sizing:border-box; }
            .gtbp-icon-btn:hover { background:#f0f0f0; color:#111; }
            .gtbp-icon-btn-enter { background:#e9f7ef; border-color:#28a745; color:#146c2e; }
            .gtbp-icon-btn-show { background:#eef4ff; border-color:#6ea8fe; color:#084298; }
            .gtbp-icon-btn-copy { background:#fff8e1; border-color:#ffc107; color:#664d03; }
            .gtbp-icon-btn-edit { background:#f5f3ff; border-color:#a78bfa; color:#5b21b6; }
            .gtbp-mini-link-panel { display:none; margin-top:7px; padding:7px; background:#f8f9fa; border:1px dashed #bbb; border-radius:6px; direction:ltr; text-align:left; max-width:100%; overflow-wrap:anywhere; font-size:11px; }
            .gtbp-card-empty { color:#9ca3af; font-size:12px; grid-column:2 / span 2; grid-row:1; justify-self:center; }
            .gtbp-admin-menu { position:relative; display:inline-flex; z-index:1000; }
            .gtbp-admin-menu > summary { list-style:none; cursor:pointer; width:30px; height:28px; display:inline-flex; align-items:center; justify-content:center; background:#8B0000; color:#fff; border-radius:8px; padding:0; font-weight:bold; font-size:14px; }
            .gtbp-admin-menu > summary::-webkit-details-marker { display:none; }
            .gtbp-admin-menu[open] > summary { border-radius:8px 8px 0 0; }
            .gtbp-admin-menu-panel { position:absolute; left:0; top:calc(100% + 6px); z-index:9999; min-width:270px; background:#fff; border:1px solid #ddd; border-radius:12px 0 12px 12px; box-shadow:0 18px 40px rgba(0,0,0,.22); padding:10px; display:flex; flex-direction:column; gap:8px; }
            .gtbp-admin-menu-group { border:1px solid #eee; border-radius:10px; padding:8px; background:#fafafa; display:flex; flex-direction:column; gap:7px; }
            .gtbp-admin-menu-group-title { font-size:11px; font-weight:bold; color:#555; }
            .gtbp-btn-small { padding:6px 9px; font-size:12px; border-radius:6px; }
            .gtbp-edit-links-box { display:none; border:1px dashed #c4b5fd; background:#faf5ff; border-radius:10px; padding:8px; margin-top:7px; }
            .gtbp-edit-links-box input { width:100%; margin:4px 0; font-size:11px; direction:ltr; }
            @media (max-width: 980px) { .gtbp-card-meta { grid-template-columns:1fr 1fr; } }
            @media (max-width: 782px) { .gtbp-booking-cards-wrap { grid-template-columns:1fr; } .gtbp-card-top { padding-left:0; } .gtbp-card-bottom-row { grid-template-columns:1fr; gap:8px; } .gtbp-link-role-student, .gtbp-link-role-teacher, .gtbp-card-bottom-row .gtbp-admin-menu, .gtbp-card-empty { grid-column:1; justify-self:center; } .gtbp-admin-menu-panel { position:fixed; left:16px; right:16px; top:auto; bottom:24px; min-width:0; width:auto; border-radius:12px; margin-top:0; z-index:10000; } .gtbp-booking-card-future:before { position:static; display:inline-block; margin-bottom:8px; } }
        </style>';

        echo '<form method="GET" class="gtbp-search-bar">';
        echo '<input type="hidden" name="page" value="gtbp_bookings">';
        echo '<input type="hidden" name="tab" value="list">';
        echo '<label>تاریخ (میلادی):</label> <input type="date" name="s_date" value="'.esc_attr($search_date).'">';
        echo '<label>نام کاربر:</label> <input type="text" name="s_name" value="'.esc_attr($search_name).'" placeholder="جستجو...">';
        echo '<label>مرتب‌سازی:</label> <select name="orderby" style="padding:6px;border:1px solid #ccc;border-radius:3px;">';
        $sort_options = array('booking_date' => 'تاریخ کلاس', 'id' => 'شناسه رزرو', 'first_name' => 'نام زبان‌آموز', 'class_name' => 'نوع کلاس', 'status' => 'وضعیت');
        foreach ($sort_options as $key => $label) echo '<option value="' . esc_attr($key) . '" ' . selected($orderby, $key, false) . '>' . esc_html($label) . '</option>';
        echo '</select>';
        echo '<select name="order" style="padding:6px;border:1px solid #ccc;border-radius:3px;">';
        echo '<option value="DESC" ' . selected($order, 'DESC', false) . '>نزولی؛ جدیدتر به قدیمی‌تر</option>';
        echo '<option value="ASC" ' . selected($order, 'ASC', false) . '>صعودی؛ قدیمی‌تر به جدیدتر</option>';
        echo '</select>';
        echo '<button type="submit" class="gtbp-btn">فیلتر</button>';
        $today_filter_url = add_query_arg(['page' => 'gtbp_bookings', 'tab' => 'list', 's_date' => current_time('Y-m-d'), 'orderby' => $orderby, 'order' => $order], admin_url('admin.php'));
        echo '<a href="' . esc_url($today_filter_url) . '" class="gtbp-btn gtbp-btn-success" style="text-decoration:none;">نمایش کلاس‌های امروز</a>';
        $id_sort_url = add_query_arg(['page'=>'gtbp_bookings','tab'=>'list','orderby'=>'id','order'=>'DESC'], admin_url('admin.php'));
        echo '<a href="' . esc_url($id_sort_url) . '" class="gtbp-btn" style="text-decoration:none;background:#374151;color:#fff;">مرتب‌سازی براساس شناسه رزرو</a>';
        if($search_date || $search_name || isset($_GET['orderby']) || isset($_GET['order'])) echo '<a href="?page=gtbp_bookings&tab=list" style="color:#dc3545;margin-right:10px;">حذف فیلتر</a>';
        echo '</form>';

        $where = "WHERE 1=1";
        if ($search_date) $where .= $wpdb->prepare(" AND booking_date = %s", $search_date);
        if ($search_name) $where .= $wpdb->prepare(" AND (first_name LIKE %s OR last_name LIKE %s)", '%'.$search_name.'%', '%'.$search_name.'%');
        $total_items = $wpdb->get_var("SELECT COUNT(*) FROM {$this->table_name} $where");
        $total_pages = ceil($total_items / $per_page);
        $sql_orderby = ($orderby === 'booking_date') ? "booking_date $order, booking_time $order, id $order" : "$orderby $order, booking_date DESC, booking_time DESC";
        $bookings = $wpdb->get_results("SELECT * FROM {$this->table_name} $where ORDER BY $sql_orderby LIMIT " . intval($per_page) . " OFFSET " . intval($offset));

        echo '<form method="POST" action="'.admin_url('admin.php?page=gtbp_bookings&tab=list').'" id="gtbp-bookings-bulk-form">';
        wp_nonce_field('gtbp_bulk_booking_actions');
        echo '<div class="gtbp-bulk-actions">';
        echo '<label class="gtbp-card-check"><input type="checkbox" id="gtbp-select-all"> انتخاب همه کارت‌های این صفحه</label>';
        echo '<strong>عملیات گروهی:</strong>';
        $active_svc_lbl = ['bbb' => 'BigBlueButton', 'bbb_personal' => 'BigBlueButton شخصی', 'meet' => 'Google Meet', 'roomeet' => 'روومیت'][$this->get_active_service()] ?? 'BBB';
        echo '<label style="display:inline-flex;align-items:center;gap:7px;font-weight:700">سرویس لینک‌های جدید <select name="gtbp_bulk_provider">';
        foreach (['bbb_personal'=>'BigBlueButton شخصی','bbb'=>'BigBlueButton','meet'=>'Google Meet','roomeet'=>'روومیت'] as $bulk_key=>$bulk_label) {
            echo '<option value="'.esc_attr($bulk_key).'" '.selected($this->get_active_service(),$bulk_key,false).'>'.esc_html($bulk_label).'</option>';
        }
        echo '</select></label>';
        echo '<button type="submit" name="gtbp_bulk_delete" class="gtbp-btn gtbp-btn-danger" onclick="return gtbpConfirmBulk(this, \'delete-bookings\')">🗑️ حذف گروهی رزروها</button>';
        echo '<button type="submit" name="gtbp_bulk_delete_links" class="gtbp-btn gtbp-btn-warning" onclick="return gtbpConfirmBulk(this, \'delete-links\')">🔗 حذف گروهی لینک‌ها</button>';
        echo '<button type="submit" name="gtbp_bulk_regenerate_class_links" class="gtbp-btn gtbp-btn-success" style="background:#1d4ed8;" onclick="return gtbpConfirmBulk(this, \'regen-class-links\')">🔗 تولید/به‌روزرسانی گروهی لینک کلاس (' . esc_html($active_svc_lbl) . ')</button>';
        echo '<button type="submit" name="gtbp_bulk_create_learning_sessions" class="gtbp-btn" onclick="return gtbpConfirmBulk(this, \'create-sessions\')">📚 ساخت گروهی جلسه آموزشی</button>';
        echo '</div>';
        echo '<div class="gtbp-booking-cards-wrap">';

        if ($bookings) {
            $today = current_time('Y-m-d');
            $panel_page_id = intval($wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_status='publish' AND post_type IN ('page','post') AND post_content LIKE %s ORDER BY ID ASC LIMIT 1",
                '%' . $wpdb->esc_like('[german_student_panel') . '%'
            )));
            $panel_base_url = $panel_page_id ? get_permalink($panel_page_id) : '';
            foreach ($bookings as $b) {
                try {
                    $booking_id = intval($b->id);
                    $j_date = $this->gregorian_to_jalali_string($b->booking_date);
                    $is_future = ($b->booking_date >= $today);
                    $card_class = $is_future ? 'gtbp-booking-card gtbp-booking-card-future' : 'gtbp-booking-card';
                    // از نسخه 12.5 به بعد لینک‌های رزرو نباید الزاماً لینک مستقیم BBB باشند.
                    // لینک درست برای رزروهای آینده، لینک واسط داخلی وردپرس است که هنگام کلیک جلسه BBB را با همان meetingID آماده می‌کند.
                    $has_student_link = !empty($b->roomeet_join_link);
                    $has_teacher_link = !empty($b->bbb_moderator_link);
                    // نسخه ۱۳.۱۰: لینک Google Meet - مشترک بین معلم و شاگرد
                    $meet_link_val    = isset($b->meet_join_link) ? (string)$b->meet_join_link : '';
                    $has_meet_link    = $meet_link_val !== '';
                    $meet_url_safe    = $has_meet_link ? esc_url($meet_link_val) : '';
                    $meet_attr_safe   = esc_attr($meet_link_val);
                    $has_any_link = !empty($b->roomeet_room_id) || $has_student_link || $has_teacher_link || $has_meet_link;
                    $link_action_label = ($has_student_link || $has_teacher_link || !empty($b->roomeet_room_id)) ? 'تولید مجدد لینک BBB' : 'تولید لینک BBB';
                    $student_url = $has_student_link ? esc_url($b->roomeet_join_link) : '';
                    $teacher_url = $has_teacher_link ? esc_url($b->bbb_moderator_link) : '';
                    $student_safe = esc_attr($b->roomeet_join_link);
                    $teacher_safe = esc_attr($b->bbb_moderator_link);
                    $email_safe = esc_attr($b->email);
                    $phone_safe = esc_attr($b->phone);
                    // نسخه ۱۴.۴: تشخیص سرویس این رزرو (اولویت با class_provider، سپس مارکر خام rmt:/meet:)
                    $raw_room_id = (string)($b->roomeet_room_id ?? '');
                    $provider = isset($b->class_provider) ? (string)$b->class_provider : '';
                    if ($provider === '') {
                        if (strpos($raw_room_id, 'rmt:') === 0) $provider = 'roomeet';            // مارکر تبدیل‌نشده‌ی روومیت
                        elseif (isset($b->rmt_meeting_id) && (string)$b->rmt_meeting_id !== '') $provider = 'roomeet';
                        elseif ($raw_room_id === 'meet:pending') $provider = 'meet';
                        elseif ($has_meet_link && !$has_student_link && !$has_teacher_link && empty($b->roomeet_room_id)) $provider = 'meet';
                        else $provider = 'bbb';
                    }
                    $provider_labels = ['bbb' => 'BigBlueButton', 'meet' => 'Google Meet', 'roomeet' => 'روومیت'];
                    $provider_colors = ['bbb' => '#8B0000', 'meet' => '#34a853', 'roomeet' => '#1d4ed8'];
                    $provider_label  = $provider_labels[$provider] ?? 'BigBlueButton';
                    $provider_color  = $provider_colors[$provider] ?? '#8B0000';

                    // نسخه ۱۴.۱: لینک نهایی معلم/شاگرد برای هر سه سرویس (رفع نمایش نشدن لینک روومیت/میت)
                    if ($provider === 'meet') {
                        // در Meet لینک مدرس و شاگرد یکی است؛ از هر ستونی که پر باشد استفاده کن
                        $meet_final = $b->roomeet_join_link ?: ($b->bbb_moderator_link ?: $meet_link_val);
                        $student_link_final = $meet_final;
                        $teacher_link_final = $meet_final;
                    } else { // bbb و roomeet: student=roomeet_join_link ، teacher=bbb_moderator_link
                        $student_link_final = (string)$b->roomeet_join_link;
                        $teacher_link_final = (string)$b->bbb_moderator_link;
                    }
                    $has_student_link = trim((string)$student_link_final) !== '';
                    $has_teacher_link = trim((string)$teacher_link_final) !== '';
                    $student_url  = $has_student_link ? esc_url($student_link_final) : '';
                    $teacher_url  = $has_teacher_link ? esc_url($teacher_link_final) : '';
                    $student_safe = esc_attr($student_link_final);
                    $teacher_safe = esc_attr($teacher_link_final);
                    // نسخه ۱۴.۴: اگر هیچ لینکی ذخیره نشده اما سرویس این رزرو روومیت است (مارکر خام rmt: یا
                    // class_provider یا rmt_meeting_id) یا سرویس فعال روومیت است، لینک gateway را همان‌جا بساز و ذخیره کن.
                    // نکته: به roomeet_available وابسته نیست - لینک gateway مستقل از اتاق کار می‌کند و هنگام کلیک اعتبارسنجی می‌شود.
                    if (!$has_student_link && !$has_teacher_link) {
                        $is_roomeet_context = ($provider === 'roomeet')
                            || (strpos($raw_room_id, 'rmt:') === 0)
                            || (isset($b->rmt_meeting_id) && (string)$b->rmt_meeting_id !== '')
                            || (empty($b->roomeet_room_id) && $this->get_active_service() === 'roomeet');
                        if ($is_roomeet_context) {
                            $provider = 'roomeet';
                            $provider_label = 'روومیت';
                            $provider_color = '#1d4ed8';
                            $student_link_final = $this->roomeet_booking_gateway_url($booking_id, 'student');
                            $teacher_link_final = $this->roomeet_booking_gateway_url($booking_id, 'teacher');
                            $has_student_link = true; $has_teacher_link = true;
                            $has_any_link = true;
                            $student_url  = esc_url($student_link_final);
                            $teacher_url  = esc_url($teacher_link_final);
                            $student_safe = esc_attr($student_link_final);
                            $teacher_safe = esc_attr($teacher_link_final);
                            // ذخیره‌ی پایدار + تبدیل کامل مارکر خام rmt: (بدون فراخوانی API؛ اتاق هنگام کلیک ساخته می‌شود)
                            // تا سمت دانش‌آموز و ربات هم لینک را ببینند. یک‌بار انجام می‌شود (دفعه بعد از DB خوانده می‌شود).
                            $heal_data = [
                                'class_provider'     => 'roomeet',
                                'roomeet_join_link'  => $student_link_final,
                                'bbb_moderator_link' => $teacher_link_final,
                            ];
                            if (strpos($raw_room_id, 'rmt:') === 0) {
                                $rmt_marker = substr($raw_room_id, 4);
                                $heal_data['rmt_meeting_id'] = ($rmt_marker !== '' && $rmt_marker !== 'pending') ? $rmt_marker : null;
                                $heal_data['roomeet_room_id'] = null; // پاک تا با کران BBB تداخل نکند و دوباره bbb تشخیص داده نشود
                            }
                            $wpdb->update($this->table_name, $heal_data, ['id' => intval($booking_id)]);
                        }
                    }
                    // برچسب نقش‌ها بر اساس سرویس
                    $student_role_title = $provider === 'meet' ? 'ورود زبان‌آموز (Meet)' : ($provider === 'roomeet' ? 'ورود زبان‌آموز (روومیت)' : 'ورود زبان‌آموز');
                    $teacher_role_title = $provider === 'meet' ? 'ورود مدرس (Meet)' : ($provider === 'roomeet' ? 'ورود مدرس (روومیت)' : 'ورود معلم');

                    // ضبط مؤثر بر اساس سرویس (ایزوله): roomeet→rmt، meet→meet، bbb→legacy/جلسه
                    $rmt_recording   = isset($b->rmt_recording_link) ? (string)$b->rmt_recording_link : '';
                    $rmt_download    = isset($b->rmt_recording_download) ? (string)$b->rmt_recording_download : '';
                    $meet_recording  = isset($b->meet_recording_link) ? (string)$b->meet_recording_link : '';
                    if ($provider === 'roomeet') {
                        $effective_recording_link = $rmt_recording !== '' ? $rmt_recording : $this->get_learning_session_recording_link($booking_id);
                    } elseif ($provider === 'meet') {
                        $effective_recording_link = $meet_recording !== '' ? $meet_recording : $this->get_learning_session_recording_link($booking_id);
                    } else {
                        $effective_recording_link = !empty($b->roomeet_recording_link) ? $b->roomeet_recording_link : $this->get_learning_session_recording_link($booking_id);
                    }
                    $recording_status = !empty($effective_recording_link) ? '<a href="' . esc_url($effective_recording_link) . '" target="_blank" class="gtbp-btn gtbp-btn-small">مشاهده ضبط</a>' : '<span class="gtbp-card-empty">در انتظار ضبط</span>';
                    if ($provider === 'roomeet' && $rmt_download !== '') {
                        $recording_status .= ' <a href="' . esc_url($rmt_download) . '" target="_blank" class="gtbp-btn gtbp-btn-small" style="background:#0f766e;color:#fff;">⬇️ دانلود</a>';
                    }
                    // دکمه یکپارچه: تولید/به‌روزرسانی بر اساس سرویس فعال
                    $active_svc = $this->get_active_service();
                    $active_svc_label = $provider_labels[$active_svc] ?? 'BBB';
                    $link_action_label = ($has_student_link || $has_teacher_link || !empty($b->roomeet_room_id) || $provider === 'roomeet' || $has_meet_link) ? ('به‌روزرسانی لینک کلاس (' . $active_svc_label . ')') : ('ساخت لینک کلاس (' . $active_svc_label . ')');
                    $regenerate_url = wp_nonce_url(admin_url('admin.php?page=gtbp_bookings&tab=list&action=gtbp_regenerate_class_links&id=' . $booking_id), 'gtbp_regenerate_class_links_' . $booking_id);
                    $delete_links_url = wp_nonce_url(admin_url('admin.php?page=gtbp_bookings&tab=list&action=gtbp_delete_bbb_links&id=' . $booking_id), 'gtbp_delete_bbb_links_' . $booking_id);
                    $delete_booking_url = wp_nonce_url(admin_url('admin.php?page=gtbp_bookings&tab=list&action=delete_booking&id=' . $booking_id), 'gtbp_delete_booking_' . $booking_id);
                    $gls_session_id = 0;
                    $gls_sessions_table = $wpdb->prefix . 'gls_sessions';
                    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $gls_sessions_table)) === $gls_sessions_table) {
                        $gls_session_id = intval($wpdb->get_var($wpdb->prepare("SELECT id FROM $gls_sessions_table WHERE booking_id=%d LIMIT 1", $booking_id)));
                    }
                    $gls_session_exists = $gls_session_id > 0;
                    $gls_lesson_url = $gls_session_id ? admin_url('admin.php?page=gls_sessions&edit=' . intval($gls_session_id)) : '';
                    $make_session_url = wp_nonce_url(admin_url('admin.php?page=gtbp_bookings&tab=list&gls_make_session=1&booking_id=' . $booking_id . '&redirect_to=' . rawurlencode(admin_url('admin.php?page=gtbp_bookings&tab=list'))), 'gls_make_session_' . $booking_id);

                    $status_text = esc_html($b->status); $status_color = '#374151'; $status_bg = '#f8fafc'; $status_border = '#e5e7eb';
                    if ($b->status == 'confirmed') { $status_text = '✅ تایید شده'; $status_color = '#166534'; $status_bg = '#dcfce7'; $status_border = '#86efac'; }
                    elseif ($b->status == 'pending_card') { $status_text = '⏳ در انتظار پرداخت'; $status_color = '#854d0e'; $status_bg = '#fef3c7'; $status_border = '#fcd34d'; }
                    elseif ($b->status == 'temp_card') { $status_text = '🕐 موقت'; $status_color = '#9a3412'; $status_bg = '#ffedd5'; $status_border = '#fdba74'; }
                    elseif ($b->status == 'cancelled') { $status_text = '❌ لغو شده'; $status_color = '#991b1b'; $status_bg = '#fee2e2'; $status_border = '#fca5a5'; }

                    echo '<div class="' . esc_attr($card_class) . '">';
                    echo '<div class="gtbp-card-top"><div>';
                    echo '<div class="gtbp-card-id">#' . esc_html($booking_id) . '</div>';
                    echo '<div class="gtbp-card-identity"><span class="gtbp-class-badge">' . esc_html($b->class_name) . '</span><span class="gtbp-card-student">👤 ' . esc_html(trim($b->first_name . ' ' . $b->last_name)) . '</span>';
                    echo $gls_session_exists ? '<span class="gtbp-class-badge" style="background:#ecfdf5;color:#047857;border-color:#a7f3d0;">جلسه آموزشی دارد</span>' : '<span class="gtbp-class-badge" style="background:#fff7ed;color:#c2410c;border-color:#fed7aa;">بدون جلسه</span>';
                    echo $has_any_link ? '<span class="gtbp-class-badge" style="background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe;">لینک کلاس دارد</span>' : '<span class="gtbp-class-badge" style="background:#f8fafc;color:#64748b;border-color:#e2e8f0;">بدون لینک</span>';
                    // نسخه ۱۴.۰: بج سرویس این رزرو
                    if ($has_any_link) echo '<span class="gtbp-class-badge" style="background:' . esc_attr($provider_color) . ';color:#fff;border-color:' . esc_attr($provider_color) . ';">' . esc_html($provider_label) . '</span>';
                    if (!empty($effective_recording_link)) echo '<span class="gtbp-class-badge" style="background:#f5f3ff;color:#6d28d9;border-color:#ddd6fe;">ضبط دارد</span>';
                    echo '</div>';
                    echo '</div><div style="display:flex;flex-direction:column;align-items:flex-end;gap:7px;">';
                    echo '<label class="gtbp-card-check"><input type="checkbox" name="bulk_ids[]" value="' . esc_attr($booking_id) . '"> انتخاب</label>';
                    echo '<span class="gtbp-card-status" style="color:' . esc_attr($status_color) . ';background:' . esc_attr($status_bg) . ';border-color:' . esc_attr($status_border) . ';">' . $status_text . '</span>';
                    echo '</div></div>';

                    echo '<div class="gtbp-card-meta">';
                    echo '<div class="gtbp-card-meta-item"><span class="gtbp-card-meta-label">تاریخ میلادی</span><span class="gtbp-card-meta-value" dir="ltr">' . esc_html($b->booking_date) . '</span></div>';
                    echo '<div class="gtbp-card-meta-item"><span class="gtbp-card-meta-label">تاریخ شمسی</span><span class="gtbp-card-meta-value">' . esc_html($j_date) . '</span></div>';
                    echo '<div class="gtbp-card-meta-item"><span class="gtbp-card-meta-label">ساعت کلاس</span><span class="gtbp-card-meta-value" dir="ltr">' . esc_html($b->booking_time) . '</span></div>';
                    echo '<div class="gtbp-card-meta-item gtbp-card-meta-item-contact"><span class="gtbp-card-meta-label">تماس</span><span class="gtbp-card-contact-tools"><button type="button" class="gtbp-icon-btn gtbp-icon-btn-copy" data-link="' . $email_safe . '" title="کپی ایمیل">✉️</button><button type="button" class="gtbp-icon-btn gtbp-icon-btn-copy" data-link="' . $phone_safe . '" title="کپی شماره تماس">📞</button></span></div>';
                    echo '</div>';

                    echo '<div class="gtbp-card-bottom-row">';
                    echo '<div class="gtbp-link-tools" title="لینک‌های کلاس">';
                    $enter_icon = $provider === 'meet' ? '🎥' : ($provider === 'roomeet' ? '🟦' : '');
                    if ($has_student_link) {
                        echo '<span class="gtbp-link-role gtbp-link-role-student"><a href="' . $student_url . '" target="_blank" class="gtbp-icon-btn gtbp-icon-btn-enter" title="' . esc_attr($student_role_title) . '">🎓↗</a><button type="button" class="gtbp-icon-btn gtbp-icon-btn-show" data-target="gtbp-student-link-' . $booking_id . '" title="نمایش لینک زبان‌آموز">👁</button><button type="button" class="gtbp-icon-btn gtbp-icon-btn-copy" data-link="' . $student_safe . '" title="کپی لینک زبان‌آموز">📋</button><button type="button" class="gtbp-icon-btn gtbp-icon-btn-edit" data-target="gtbp-edit-links-' . $booking_id . '" title="ویرایش لینک‌ها">✏️</button></span>';
                    }
                    if ($has_teacher_link) {
                        echo '<span class="gtbp-link-role gtbp-link-role-teacher"><a href="' . $teacher_url . '" target="_blank" class="gtbp-icon-btn gtbp-icon-btn-enter" title="' . esc_attr($teacher_role_title) . '">👨‍🏫↗</a><button type="button" class="gtbp-icon-btn gtbp-icon-btn-show" data-target="gtbp-teacher-link-' . $booking_id . '" title="نمایش لینک معلم">👁</button><button type="button" class="gtbp-icon-btn gtbp-icon-btn-copy" data-link="' . $teacher_safe . '" title="کپی لینک معلم">📋</button><button type="button" class="gtbp-icon-btn gtbp-icon-btn-edit" data-target="gtbp-edit-links-' . $booking_id . '" title="ویرایش لینک‌ها">✏️</button>' . ($gls_lesson_url ? '<a href="' . esc_url($gls_lesson_url) . '" target="_blank" rel="noopener" class="gtbp-icon-btn" title="ویرایش جلسه و جزوه">📘</a>' : '') . '</span>';
                    } elseif ($gls_lesson_url) {
                        echo '<span class="gtbp-link-role gtbp-link-role-teacher"><a href="' . esc_url($gls_lesson_url) . '" target="_blank" rel="noopener" class="gtbp-icon-btn" title="ویرایش جلسه و جزوه">📘</a></span>';
                    }
                    if (!$has_student_link && !$has_teacher_link) echo '<span class="gtbp-card-empty">لینک کلاس ساخته نشده</span>';
                    echo '</div>';
                    echo '<details class="gtbp-admin-menu"><summary title="تنظیمات و عملیات">⚙️</summary><div class="gtbp-admin-menu-panel">';
                    echo '<div class="gtbp-admin-menu-group"><div class="gtbp-admin-menu-group-title">مدیریت لینک کلاس</div>';
                    echo '<a href="' . esc_url($regenerate_url) . '" class="gtbp-btn gtbp-btn-warning gtbp-btn-small" onclick="return confirm(\'برای رزرو #' . $booking_id . ' لینک کلاس بر اساس سرویس فعال (' . esc_js($active_svc_label) . ') ساخته/به‌روزرسانی شود؟\')">♻️ ' . esc_html($link_action_label) . '</a>';
                    if ($has_any_link) echo '<a href="' . esc_url($delete_links_url) . '" class="gtbp-btn gtbp-btn-danger gtbp-btn-small" onclick="return confirm(\'فقط لینک‌های رزرو #' . $booking_id . ' حذف شود؟\')">🔗 حذف لینک کلاس</a>';
                    echo '<div class="gtbp-edit-links-box" id="gtbp-edit-links-' . $booking_id . '"><input type="url" name="manual_student_link[' . $booking_id . ']" value="' . esc_attr($b->roomeet_join_link) . '" placeholder="لینک زبان‌آموز"><input type="url" name="manual_teacher_link[' . $booking_id . ']" value="' . esc_attr($b->bbb_moderator_link) . '" placeholder="لینک معلم"><button type="submit" name="gtbp_save_manual_links" value="' . $booking_id . '" class="gtbp-btn gtbp-btn-success gtbp-btn-small">ذخیره لینک‌ها</button></div>';
                    // نسخه ۱۴.۱: ویرایش دستی لینک Google Meet (با دکمه toggle مستقل چون چیپ جدا حذف شد)
                    echo '<button type="button" class="gtbp-btn gtbp-btn-small gtbp-icon-btn-edit" data-target="gtbp-edit-meet-link-' . $booking_id . '" onclick="var el=document.getElementById(this.getAttribute(\'data-target\'));if(el)el.style.display=(el.style.display===\'block\'?\'none\':\'block\');return false;" style="text-align:right;">✏️ ویرایش دستی لینک Google Meet</button>';
                    echo '<div class="gtbp-edit-links-box" id="gtbp-edit-meet-link-' . $booking_id . '"><input type="url" name="manual_meet_link[' . $booking_id . ']" value="' . $meet_attr_safe . '" placeholder="لینک Google Meet (مثلاً https://meet.google.com/xxx-yyyy-zzz)"><button type="submit" name="gtbp_save_manual_links" value="' . $booking_id . '" class="gtbp-btn gtbp-btn-success gtbp-btn-small">ذخیره لینک Meet</button></div>';
                    echo '</div>';
                    echo '<div class="gtbp-admin-menu-group"><div class="gtbp-admin-menu-group-title">ضبط کلاس</div>' . $recording_status . '</div>';
                    // نسخه ۱۳.۹: جستجوی دستی ویدئوی Google Meet با Meet ID
                    // نکته امنیتی: چون این کنترل‌ها داخل فرم بزرگ‌تر «عملیات گروهی» رندر می‌شوند و
                    // HTML اجازه‌ی فرم تودرتو نمی‌دهد، از نانس با نام اختصاصی (نه _wpnonce) استفاده می‌کنیم
                    // تا نانس فرم بیرونی خراب نشود.
                    if (class_exists('GTBP_Google_Meet_Provider') && GTBP_Google_Meet_Provider::instance()->is_configured()) {
                        $meet_lookup_nonce = wp_create_nonce('gtbp_meet_lookup_' . $booking_id);
                        echo '<div class="gtbp-admin-menu-group"><div class="gtbp-admin-menu-group-title">جستجوی ویدئوی Meet با ID</div>';
                        echo '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">';
                        echo '<input type="text" name="meet_id_' . intval($booking_id) . '" placeholder="api-fiqf-vdh" dir="ltr" style="flex:1;min-width:110px;padding:4px 6px;font-size:12px;">';
                        echo '<button type="submit" name="gtbp_meet_lookup_by_id" value="' . intval($booking_id) . '" formnovalidate class="gtbp-btn gtbp-btn-small" style="background:#34a853;color:#fff;">🔍 جستجو</button>';
                        echo '<input type="hidden" name="gtbp_meet_lookup_nonce_' . intval($booking_id) . '" value="' . esc_attr($meet_lookup_nonce) . '">';
                        echo '</div>';
                        echo '<small style="color:#666;font-size:11px;">این جستجو دستی است و اجرای خودکار افزونه را متوقف نمی‌کند.</small>';
                        echo '</div>';
                    }
                    // نسخه ۱۴.۰: جستجوی دستی ضبط روومیت با theIdOfMeeting
                    if ($this->roomeet_available()) {
                        $rmt_lookup_nonce = wp_create_nonce('gtbp_roomeet_lookup_' . $booking_id);
                        $rmt_prefill = isset($b->rmt_meeting_id) ? esc_attr($b->rmt_meeting_id) : '';
                        echo '<div class="gtbp-admin-menu-group"><div class="gtbp-admin-menu-group-title">جستجوی ضبط روومیت با ID جلسه</div>';
                        echo '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">';
                        echo '<input type="text" name="roomeet_id_' . intval($booking_id) . '" value="' . $rmt_prefill . '" placeholder="theIdOfMeeting" dir="ltr" style="flex:1;min-width:110px;padding:4px 6px;font-size:12px;">';
                        echo '<button type="submit" name="gtbp_roomeet_lookup_by_id" value="' . intval($booking_id) . '" formnovalidate class="gtbp-btn gtbp-btn-small" style="background:#1d4ed8;color:#fff;">🔍 جستجو</button>';
                        echo '<input type="hidden" name="gtbp_roomeet_lookup_nonce_' . intval($booking_id) . '" value="' . esc_attr($rmt_lookup_nonce) . '">';
                        echo '</div>';
                        echo '<small style="color:#666;font-size:11px;">شناسه جلسه (theIdOfMeeting) روومیت را وارد کنید تا ضبط پیدا و منتشر شود. جستجوی خودکار متوقف نمی‌شود.</small>';
                        echo '</div>';
                    }
                    echo '<div class="gtbp-admin-menu-group"><div class="gtbp-admin-menu-group-title">جلسه آموزشی</div>';
                    if ($gls_session_exists) echo '<span class="gtbp-card-empty">جلسه آموزشی ساخته شده است</span>';
                    else echo '<a href="' . esc_url($make_session_url) . '" class="gtbp-btn gtbp-btn-success gtbp-btn-small" onclick="return confirm(\'برای رزرو #' . $booking_id . ' جلسه آموزشی ساخته شود؟\')">➕ ساخت جلسه</a>';
                    echo '</div>';
                    echo '<div class="gtbp-admin-menu-group"><div class="gtbp-admin-menu-group-title">مدیریت رزرو</div><a href="' . esc_url($delete_booking_url) . '" class="gtbp-btn gtbp-btn-danger gtbp-btn-small" onclick="return confirm(\'خود رزرو #' . $booking_id . ' کامل حذف شود؟\')">🗑️ حذف رزرو</a></div>';
                    echo '</div></details>';
                    echo '</div>';
                    if ($has_student_link) echo '<div id="gtbp-student-link-' . $booking_id . '" class="gtbp-mini-link-panel">' . esc_html($student_link_final) . '</div>';
                    if ($has_teacher_link) echo '<div id="gtbp-teacher-link-' . $booking_id . '" class="gtbp-mini-link-panel">' . esc_html($teacher_link_final) . '</div>';
                    echo '</div>';
                } catch (\Throwable $e) {
                    echo '<div class="gtbp-booking-card" style="border-color:#fca5a5;background:#fef2f2;color:#991b1b;">⚠️ خطا در رندر این کارت: ' . esc_html($e->getMessage()) . '</div>';
                }
            }
        } else {
            echo '<div class="gtbp-booking-card" style="grid-column:1/-1;text-align:center;color:#777;">موردی یافت نشد.</div>';
        }
        echo '</div></form>';

        if ($total_pages > 1) {
            echo '<div style="margin-top: 20px; display: flex; gap: 5px; justify-content: center; flex-wrap:wrap;">';
            for ($i = 1; $i <= $total_pages; $i++) {
                $active_style = ($i == $current_page) ? 'background: #8B0000; color: #fff;' : 'background: #fff; color: #8B0000; border: 1px solid #8B0000;';
                $page_url = add_query_arg(['paged' => $i, 's_date' => $search_date, 's_name' => $search_name, 'orderby' => $orderby, 'order' => $order], admin_url('admin.php?page=gtbp_bookings&tab=list'));
                echo "<a href='" . esc_url($page_url) . "' class='gtbp-btn' style='" . esc_attr($active_style) . " text-decoration:none;'>{$i}</a>";
            }
            echo '</div>';
        }

        echo '<script>
        (function(){
            var selectAll = document.getElementById("gtbp-select-all");
            if (selectAll) selectAll.addEventListener("change", function(){ document.querySelectorAll("input[name=\'bulk_ids[]\']").forEach(function(cb){ cb.checked = selectAll.checked; }); });
            window.gtbpConfirmBulk = function(btn, action) {
                var checked = document.querySelectorAll("input[name=\'bulk_ids[]\']:checked").length;
                if (!checked) { alert("هیچ رزروی انتخاب نشده است."); return false; }
                if (action === "delete-bookings") return confirm("آیا از حذف کامل " + checked + " رزرو انتخاب‌شده مطمئن هستید؟");
                if (action === "delete-links") return confirm("آیا لینک‌های " + checked + " رزرو انتخاب‌شده حذف شوند؟");
                if (action === "regen-links") return confirm("آیا برای " + checked + " رزرو انتخاب‌شده لینک‌های BBB جدید ساخته شود؟");
                if (action === "regen-meet-links") return confirm("آیا برای " + checked + " رزرو انتخاب‌شده لینک‌های Google Meet جدید ساخته شود؟");
                if (action === "regen-class-links") return confirm("آیا برای " + checked + " رزرو انتخاب‌شده لینک کلاس بر اساس سرویس فعال ساخته/به‌روزرسانی شود؟");
                if (action === "create-sessions") return confirm("برای رزروهای انتخاب‌شده‌ای که هنوز جلسه آموزشی ندارند، جلسه ساخته شود؟");
                return true;
            };
            document.addEventListener("toggle", function(e){
                if (e.target && e.target.classList && e.target.classList.contains("gtbp-admin-menu")) {
                    var card = e.target.closest(".gtbp-booking-card");
                    if (card) card.classList.toggle("gtbp-card-menu-open", e.target.open);
                    if (e.target.open) {
                        document.querySelectorAll(".gtbp-admin-menu[open]").forEach(function(menu){
                            if (menu !== e.target) {
                                menu.open = false;
                                var otherCard = menu.closest(".gtbp-booking-card");
                                if (otherCard) otherCard.classList.remove("gtbp-card-menu-open");
                            }
                        });
                    }
                }
            }, true);
            document.addEventListener("click", function(e){
                var showBtn = e.target.closest(".gtbp-icon-btn-show");
                if (showBtn) { var panel = document.getElementById(showBtn.getAttribute("data-target")); if (panel) panel.style.display = panel.style.display === "block" ? "none" : "block"; }
                var editBtn = e.target.closest(".gtbp-icon-btn-edit");
                if (editBtn) { var box = document.getElementById(editBtn.getAttribute("data-target")); if (box) box.style.display = box.style.display === "block" ? "none" : "block"; }
                var copyBtn = e.target.closest(".gtbp-icon-btn-copy");
                if (copyBtn) { var link = copyBtn.getAttribute("data-link") || ""; if (!link) return; if (navigator.clipboard) { navigator.clipboard.writeText(link).then(function(){ copyBtn.textContent="✅"; setTimeout(function(){copyBtn.textContent="📋";},1200); }); } else { prompt("لینک را کپی کنید:", link); } }
            });
        })();
        </script>';
    }

    private function admin_tab_pending() {
        global $wpdb;
        
        $pending = $wpdb->get_results(
            "SELECT * FROM {$this->table_name} 
             WHERE status IN ('pending_card', 'temp_card') 
             ORDER BY created_at DESC"
        );
        
        if (isset($_GET['msg']) && $_GET['msg'] == 'confirmed') {
            echo '<div class="notice notice-success"><p>رزروهای انتخاب شده با موفقیت تایید شدند.</p></div>';
        }
        
        echo '<form method="POST" action="">';
        echo '<div style="margin-bottom: 15px;">';
        echo '<button type="submit" name="gtbp_confirm_pending" class="gtbp-btn gtbp-btn-success" onclick="return confirm(\'آیا از تایید رزروهای انتخاب شده مطمئن هستید؟\')">تایید پرداخت انتخاب‌شده‌ها</button>';
        echo '</div>';
        
        echo '<table class="gtbp-table">
            <thead>
                <tr>
                    <th style="width: 30px;"><input type="checkbox" id="gtbp-select-all"></th>
                    <th>شناسه</th>
                    <th>نام</th>
                    <th>کلاس</th>
                    <th>تاریخ (شمسی)</th>
                    <th>ساعت</th>
                    <th>وضعیت</th>
                    <th>زمان ایجاد</th>
                    <th>خارج از ایران</th>
                </tr>
            </thead><tbody>';
            
        if ($pending) {
            foreach ($pending as $p) {
                $j_date = $this->gregorian_to_jalali_string($p->booking_date);
                $status_text = $p->status == 'pending_card' ? '⏳ در انتظار (۲۴ ساعت)' : '🕐 موقت (۱۰ دقیقه)';
                $outside = $p->outside_iran ? '✅ بله' : '❌ خیر';
                
                echo "<tr>
                    <td><input type='checkbox' name='pending_ids[]' value='{$p->id}'></td>
                    <td>#{$p->id}</td>
                    <td>{$p->first_name} {$p->last_name}</td>
                    <td>{$p->class_name}</td>
                    <td>{$j_date}</td>
                    <td>{$p->booking_time}</td>
                    <td>{$status_text}</td>
                    <td>{$p->created_at}</td>
                    <td>{$outside}</td>
                   </tr>";
            }
        } else {
            echo "<tr><td colspan='9' style='text-align:center;'>موردی یافت نشد.}</td></tr>";
        }
        
        echo '</tbody></table>';
        echo '</form>';
        
        echo '<script>
        document.getElementById("gtbp-select-all").addEventListener("change", function() {
            var checkboxes = document.querySelectorAll("input[name=\'pending_ids[]\']");
            for (var checkbox of checkboxes) {
                checkbox.checked = this.checked;
            }
        });
        </script>';
    }

    private function admin_tab_manual() {
        if (isset($_GET['msg']) && $_GET['msg'] == 'manual_success') {
            echo '<div class="notice notice-success is-dismissible"><p>رزرو دستی با موفقیت ثبت شد و ایمیل‌ها ارسال گردید.</p></div>';
        }
        $classes = $this->get_classes();
        $packages = $this->get_active_booking_packages();
        ?>
        <div style="background:#fff; padding:20px; border:1px solid #ddd; border-radius:5px; max-width:500px; margin-top:20px;">
            <h3>ثبت رزرو دستی (بدون پرداخت)</h3>
            <p style="color:#666; font-size:12px;">با ثبت این فرم، زمان انتخاب شده اشغال شده و ایمیل تاییدیه برای کاربر ارسال می‌شود، اما لینک ورود کلاس فقط برای ادمین ایمیل می‌شود و برای زبان‌آموز ۳ دقیقه قبل از کلاس فعال/ارسال خواهد شد.</p>
            <form method="post">
                <p><label>انتخاب کاربر سایت:</label><br>
                <select name="m_user_id" required class="gtbp-input-wide" style="margin-bottom: 15px;">
                    <option value="">-- یک کاربر را از لیست انتخاب کنید --</option>
                    <?php
                    $users = get_users();
                    foreach ($users as $user) {
                        $full_name = trim($user->first_name . ' ' . $user->last_name);
                        if (empty($full_name)) $full_name = $user->display_name;
                        echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($full_name . ' (' . $user->user_email . ')') . '</option>';
                    }
                    ?>
                </select>

                <p><label>نوع کلاس:</label><br>
                    <select name="m_class" required class="gtbp-input-wide">
                        <option value="">-- انتخاب کنید --</option>
                        <?php foreach($classes as $c) { echo '<option value="'.esc_attr($c['name']).'">'.esc_html($c['name']).'</option>'; } ?>
                    </select>
                </p>
                <p><label>تاریخ میلادی کلاس:</label><br><input type="date" name="m_date" id="m_date_picker" required class="gtbp-input-wide"></p>
                
                <p>
                    <button type="button" id="fetch_slots_btn" class="gtbp-btn" style="background:#0073aa;">جستجوی زمان‌های آزاد در این تاریخ</button>
                    <span id="slot_loading" style="display:none; color:#666; font-size:12px;">در حال جستجو...</span>
                </p>

                <p id="slots_container" style="display:none;">
                    <label>انتخاب از زمان‌های آزاد موجود:</label><br>
                    <select name="m_time" id="m_time_select" class="gtbp-input-wide"></select>
                    <small>اختیاری است؛ ادمین می‌تواند پایین‌تر زمان دلخواه وارد کند.</small>
                </p>

                <p>
                    <label>یا زمان دلخواه ادمین:</label><br>
                    <input type="text" name="m_time_custom" class="gtbp-input-wide" dir="ltr" placeholder="مثلاً 18:15-19:15"><br>
                    <small>برای رزرو دستی، تاریخ‌های گذشته هم مجاز هستند و این زمان حتی اگر در لیست ساعات آزاد نباشد پذیرفته می‌شود. اگر این فیلد پر شود، اولویت با همین مقدار است.</small>
                </p>

                <p><button type="submit" name="gtbp_manual_book" class="gtbp-btn gtbp-btn-wide" style="font-size:16px; padding:10px 0;">ثبت نهایی رزرو</button></p>
            </form>
        </div>

        <?php if (!empty($packages)): ?>
        <div style="background:#fffdf7; padding:20px; border:1px dashed #ffc107; border-radius:5px; max-width:700px; margin-top:20px;">
            <h3>ثبت رزرو دستی طرح چندجلسه‌ای</h3>
            <p style="color:#666; font-size:12px; line-height:1.8;">برای طرح چندجلسه‌ای، قانون تعداد دقیق جلسات و بازه مجاز از اولین رزرو کنترل می‌شود. هر جلسه را در یک خط وارد کنید. تاریخ می‌تواند میلادی یا شمسی باشد: <code dir="ltr">2026-06-01 09:00-10:00</code> یا <code dir="ltr">1405/03/11 09:00-10:00</code>.</p>
            <form method="post">
                <p><label>انتخاب کاربر سایت:</label><br>
                <select name="mp_user_id" required class="gtbp-input-wide">
                    <option value="">-- یک کاربر را از لیست انتخاب کنید --</option>
                    <?php
                    foreach ($users as $user) {
                        $full_name = trim($user->first_name . ' ' . $user->last_name);
                        if (empty($full_name)) $full_name = $user->display_name;
                        echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($full_name . ' (' . $user->user_email . ')') . '</option>';
                    }
                    ?>
                </select></p>
                <p><label>طرح چندجلسه‌ای:</label><br>
                <select name="mp_package_id" required class="gtbp-input-wide">
                    <option value="">-- انتخاب کنید --</option>
                    <?php foreach($packages as $pkg) {
                        $class = $this->find_class_by_id($pkg['class_id']);
                        echo '<option value="'.esc_attr($pkg['id']).'">'.esc_html($pkg['name'] . ' | ' . ($class ? $class['name'] : '') . ' | ' . intval($pkg['session_count']) . ' جلسه | ' . intval($pkg['window_days']) . ' روز | ' . $pkg['discount_percent'] . '٪ تخفیف').'</option>';
                    } ?>
                </select></p>
                <p><label>جلسات طرح:</label><br>
                <textarea name="mp_sessions" required class="gtbp-input-wide" rows="8" dir="ltr" placeholder="2026-06-01 09:00-10:00&#10;1405/03/11 10:30-11:30"></textarea></p>
                <p><button type="submit" name="gtbp_manual_package_book" class="gtbp-btn" style="font-size:16px; padding:10px 20px;">ثبت نهایی طرح چندجلسه‌ای</button></p>
            </form>
        </div>
        <?php endif; ?>

        <script>
        document.getElementById('fetch_slots_btn').addEventListener('click', function() {
            var date = document.getElementById('m_date_picker').value;
            if(!date) { alert('لطفا ابتدا یک تاریخ انتخاب کنید.'); return; }
            
            document.getElementById('slot_loading').style.display = 'inline';
            var data = new FormData();
            data.append('action', 'gtbp_get_slots');
            data.append('date', date);
            data.append('admin_manual', '1');

            fetch("<?php echo admin_url('admin-ajax.php'); ?>", {
                method: 'POST', body: data
            })
            .then(response => response.json())
            .then(data => {
                document.getElementById('slot_loading').style.display = 'none';
                var select = document.getElementById('m_time_select');
                select.innerHTML = '';
                
                if(data.success && data.data && data.data.length > 0) {
                    data.data.forEach(function(slot) {
                        if(!slot.booked) {
                            var opt = document.createElement('option');
                            opt.value = slot.time;
                            opt.innerHTML = slot.time;
                            select.appendChild(opt);
                        }
                    });
                    if(select.options.length === 0) {
                        alert('در این تاریخ تمام ظرفیت‌ها پر است.');
                        document.getElementById('slots_container').style.display = 'none';
                    } else {
                        document.getElementById('slots_container').style.display = 'block';
                    }
                } else {
                    alert('در این تاریخ ساعت کاری تعریف نشده یا ظرفیت پر است.');
                    document.getElementById('slots_container').style.display = 'none';
                }
            });
        });
        </script>
        <?php
    }

    private function admin_tab_holidays() {
        $holidays = $this->get_holidays();
        echo '<div style="background:#fff; padding:20px; border:1px solid #ddd; margin-top:20px; max-width:520px;">
            <h3>ثبت روز تعطیل</h3>
            <form method="POST">
                <p><label>تاریخ میلادی:</label><br><input type="date" name="holiday_date" style="padding:5px; width:100%;"></p>
                <p><label>یا تاریخ شمسی:</label><br><input type="text" name="holiday_jalali" placeholder="مثلاً 1404/03/15" dir="ltr" style="padding:5px; width:100%;"></p>
                <p style="color:#666; font-size:12px;">یکی از دو فیلد کافی است. اگر هر دو را پر کنید، تاریخ میلادی اولویت دارد.</p>
                <button type="submit" name="gtbp_add_holiday" class="gtbp-btn">افزودن</button>
            </form>
        </div>';

        echo '<table class="gtbp-table" style="max-width:520px;">
            <thead><tr><th>تاریخ میلادی</th><th>معادل شمسی</th><th>حذف</th></tr></thead><tbody>';
        foreach ($holidays as $date) {
            $j_date = $this->gregorian_to_jalali_string($date);
            echo "<tr><td dir='ltr'>{$date}</td><td>{$j_date}</td>
            <td><a href='?page=gtbp_bookings&tab=holidays&action=delete_holiday&date={$date}' class='gtbp-btn gtbp-btn-danger'>X</a></td></tr>";
        }
        echo '</tbody></table>';
    }

    private function admin_tab_classes() {
        $classes = $this->get_classes();
        $packages = $this->get_booking_packages();
        if (isset($_GET['msg']) && $_GET['msg'] == 'package_saved') {
            echo '<div class="notice notice-success is-dismissible"><p>طرح چندجلسه‌ای با موفقیت ذخیره شد.</p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'package_deleted') {
            echo '<div class="notice notice-success is-dismissible"><p>طرح چندجلسه‌ای حذف شد.</p></div>';
        }
        if (isset($_GET['msg']) && $_GET['msg'] == 'cards_saved') {
            echo '<div class="notice notice-success is-dismissible"><p>ترتیب نمایش و رنگ کارت‌ها ذخیره شد.</p></div>';
        }
        echo '<div style="display:flex; gap:20px; flex-wrap:wrap; align-items:flex-start;">';
        echo '<div style="background:#fff; padding:20px; border:1px solid #ddd; max-width:500px; margin-top:20px; flex:1; min-width:320px;">
            <h3>تعریف کلاس جدید</h3>
            <form method="POST">
                <p>نام کلاس: <input type="text" name="class_name" required class="gtbp-input-wide"></p>
                <p>هزینه هر جلسه (تومان): <input type="number" name="class_price" required class="gtbp-input-wide"></p>
                <p style="display:flex;gap:14px;flex-wrap:wrap;align-items:center">
                    <label>رنگ پس‌زمینه <input type="color" name="class_bg" value="#ffffff"></label>
                    <label>رنگ متن <input type="color" name="class_color" value="#111111"></label>
                    <label>رنگ انتخاب و حاشیه <input type="color" name="class_accent" value="#2563eb"></label>
                </p>
                <button type="submit" name="gtbp_add_class" class="gtbp-btn">ذخیره کلاس</button>
            </form>
        </div>';

        echo '<div style="background:#fff; padding:20px; border:1px solid #ddd; max-width:560px; margin-top:20px; flex:1; min-width:340px;">
            <h3>تعریف طرح چندجلسه‌ای</h3>
            <p style="color:#666; font-size:12px; line-height:1.8;">در این مدل، کاربر باید دقیقاً همان تعداد جلسه‌ای را که تعیین می‌کنید انتخاب کند و همه جلسات باید در بازه مشخص‌شده از اولین رزرو باشند. تخفیف این طرح به‌صورت خودکار، جدا از کد تخفیف، روی سبد اعمال می‌شود.</p>
            <form method="POST">
                <p>نام طرح: <input type="text" name="package_name" required class="gtbp-input-wide" placeholder="مثلاً پکیج ۹ جلسه‌ای خصوصی"></p>
                <p>نوع کلاس مجاز:<br><select name="package_class_id" required class="gtbp-input-wide"><option value="">-- انتخاب کنید --</option>';
        foreach ($classes as $c) {
            echo '<option value="' . esc_attr($c['id']) . '">' . esc_html($c['name'] . ' - ' . number_format($c['price']) . ' تومان') . '</option>';
        }
        echo '</select></p>
                <p>تعداد جلسات الزامی: <input type="number" name="package_session_count" min="2" required class="gtbp-input-wide" placeholder="مثلاً 9"></p>
                <p>حداکثر بازه از اولین رزرو (روز): <input type="number" name="package_window_days" min="1" required class="gtbp-input-wide" placeholder="مثلاً 21 برای سه هفته"></p>
                <p>درصد تخفیف خودکار: <input type="number" step="0.01" name="package_discount_percent" min="0" max="100" required class="gtbp-input-wide" placeholder="مثلاً 15"></p>
                <p style="display:flex;gap:14px;flex-wrap:wrap;align-items:center">
                    <label>رنگ پس‌زمینه <input type="color" name="package_bg" value="#ffffff"></label>
                    <label>رنگ متن <input type="color" name="package_color" value="#111111"></label>
                    <label>رنگ انتخاب و حاشیه <input type="color" name="package_accent" value="#2563eb"></label>
                </p>
                <p><label><input type="checkbox" name="package_active" value="1" checked> طرح فعال باشد</label></p>
                <button type="submit" name="gtbp_add_package" class="gtbp-btn">ذخیره طرح</button>
            </form>
        </div>';
        echo '</div>';

        echo '<h3 style="margin-top:30px;">ترتیب نمایش و ظاهر کارت‌ها</h3>';
        echo '<p style="color:#666;font-size:12px;line-height:1.9;max-width:900px">عدد «ترتیب» کوچک‌تر یعنی نمایش زودتر در پنل رزرو دانشجو (مثلاً ۱۰، ۲۰، ۳۰). رنگ‌ها روی کارت انتخاب کلاس در مرحله اول رزرو اعمال می‌شوند. برای بازگشت به رنگ پیش‌فرض، تیک «رنگ پیش‌فرض» را بزنید.</p>';
        echo '<form method="POST" style="background:#fff;padding:18px;border:1px solid #ddd;max-width:1000px">';
        wp_nonce_field('gtbp_save_card_styles');
        $packages_first = get_option('gtbp_cards_packages_first', '0') === '1';
        echo '<p><label><input type="checkbox" name="gtbp_cards_packages_first" value="1" ' . checked($packages_first, true, false) . '> ابتدا طرح‌های چندجلسه‌ای نمایش داده شوند، سپس کلاس‌های تکی</label></p>';
        $style_rows = function ($group, $items, $title) {
            echo '<h4 style="margin:18px 0 8px">' . esc_html($title) . '</h4>';
            if (empty($items)) { echo '<p style="color:#888">موردی تعریف نشده است.</p>'; return; }
            echo '<table class="gtbp-table" style="width:100%"><thead><tr><th>نام</th><th>ترتیب</th><th>پس‌زمینه</th><th>متن</th><th>حاشیه</th><th>رنگ پیش‌فرض</th></tr></thead><tbody>';
            foreach ($items as $item) {
                $id = esc_attr($item['id']);
                $base = 'gtbp_card[' . $group . '][' . $id . ']';
                echo '<tr><td>' . esc_html($item['name']) . '</td>';
                echo '<td><input type="number" style="width:90px" name="' . $base . '[sort]" value="' . esc_attr(isset($item['sort']) ? intval($item['sort']) : 0) . '"></td>';
                foreach (['bg' => '#ffffff', 'color' => '#111111', 'accent' => '#2563eb'] as $field => $default) {
                    $value = isset($item[$field]) && $item[$field] !== '' ? $item[$field] : $default;
                    echo '<td><input type="color" name="' . $base . '[' . $field . ']" value="' . esc_attr($value) . '"></td>';
                }
                $is_default = empty($item['bg']) && empty($item['color']) && empty($item['accent']);
                echo '<td><label><input type="checkbox" name="' . $base . '[reset]" value="1" ' . checked($is_default, true, false) . '> پیش‌فرض</label></td></tr>';
            }
            echo '</tbody></table>';
        };
        $style_rows('class', $classes, 'کلاس‌های تکی');
        $style_rows('package', $packages, 'طرح‌های چندجلسه‌ای');
        echo '<p style="margin-top:16px"><button type="submit" name="gtbp_save_card_styles" class="gtbp-btn">ذخیره ترتیب و رنگ‌ها</button></p>';
        echo '</form>';

        echo '<h3 style="margin-top:30px;">کلاس‌های تعریف‌شده</h3>';
        echo '<table class="gtbp-table" style="max-width:700px;">
        <thead><tr><th>نوع کلاس</th><th>مبلغ هر جلسه (تومان)</th><th>عملیات</th></tr></thead><tbody>';
        foreach ($classes as $c) {
            echo '<tr><td>' . esc_html($c['name']) . '</td><td>' . number_format($c['price']) . '</td>
            <td><a href="?page=gtbp_bookings&tab=classes&action=delete_class&class_id=' . esc_attr($c['id']) . '" class="gtbp-btn gtbp-btn-danger" onclick="return confirm(\'حذف شود؟\')">حذف</a></td></tr>';
        }
        if (empty($classes)) echo '<tr><td colspan="3" style="text-align:center;">هنوز کلاسی تعریف نشده است.</td></tr>';
        echo '</tbody></table>';

        echo '<h3 style="margin-top:30px;">طرح‌های چندجلسه‌ای</h3>';
        echo '<table class="gtbp-table" style="max-width:1000px;">
        <thead><tr><th>نام طرح</th><th>نوع کلاس</th><th>تعداد جلسات</th><th>بازه مجاز</th><th>تخفیف</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
        foreach ($packages as $pkg) {
            $class = $this->find_class_by_id($pkg['class_id']);
            echo '<tr><td>' . esc_html($pkg['name']) . '</td><td>' . esc_html($class ? $class['name'] : 'کلاس حذف‌شده') . '</td><td>' . intval($pkg['session_count']) . '</td><td>' . intval($pkg['window_days']) . ' روز</td><td>' . esc_html($pkg['discount_percent']) . '٪</td><td>' . (!empty($pkg['active']) ? 'فعال' : 'غیرفعال') . '</td>
            <td><a href="?page=gtbp_bookings&tab=classes&action=delete_package&package_id=' . esc_attr($pkg['id']) . '" class="gtbp-btn gtbp-btn-danger" onclick="return confirm(\'این طرح حذف شود؟\')">حذف</a></td></tr>';
        }
        if (empty($packages)) echo '<tr><td colspan="7" style="text-align:center;">هنوز طرح چندجلسه‌ای تعریف نشده است.</td></tr>';
        echo '</tbody></table>';
    }

    private function admin_tab_hours() {
        $hours = $this->get_working_hours();
        $days_fa = [6=>'شنبه', 0=>'یکشنبه', 1=>'دوشنبه', 2=>'سه‌شنبه', 3=>'چهارشنبه', 4=>'پنج‌شنبه', 5=>'جمعه'];
        $sorted_hours = [];
        $order = [6, 0, 1, 2, 3, 4, 5];
        foreach ($order as $d) foreach($hours as $h) { if($h['day'] == $d) { $sorted_hours[] = $h; break; } }
        echo '<div style="background:#eef5fa; padding:15px; border-right:4px solid #8B0000; margin-top:15px; max-width:900px;"><strong>راهنما:</strong> همه بازه‌ها با کاما جدا شوند. ستون اولویت اختیاری است؛ اگر خالی باشد همه ساعت‌های آزاد همان روز نمایش داده می‌شوند. اگر پر شود، فقط همان زمان‌های اولویت‌دار ابتدا به کاربر نمایش داده می‌شوند.</div>';
        echo '<form method="POST"><table class="gtbp-table" style="max-width:1000px;"><thead><tr><th width="100">روز هفته</th><th width="80">فعال است؟</th><th>همه بازه‌های زمانی</th><th>زمان‌های اولویت‌دار</th></tr></thead><tbody>';
        foreach ($sorted_hours as $h) {
            $day_idx = $h['day'];
            $checked = !empty($h['active']) ? 'checked' : '';
            $slots = isset($h['slots']) ? $h['slots'] : '';
            $priority = isset($h['priority_slots']) ? $h['priority_slots'] : '';
            echo "<tr><td><strong>{$days_fa[$day_idx]}</strong></td><td><input type='checkbox' name='active[{$day_idx}]' value='1' {$checked}></td><td><input type='text' name='slots[{$day_idx}]' value='".esc_attr($slots)."' class='gtbp-input-wide' dir='ltr'></td><td><input type='text' name='priority_slots[{$day_idx}]' value='".esc_attr($priority)."' class='gtbp-input-wide' dir='ltr' placeholder='مثلاً 13:30-14:30, 15:00-16:00'><br><small>خالی = نمایش همه ساعت‌ها</small></td></tr>";
        }
        echo '</tbody></table><br><button type="submit" name="gtbp_save_hours" class="gtbp-btn" style="font-size:16px; padding:10px 20px;">ذخیره تغییرات ساعات</button></form>';

        $all_user_hours = $this->get_user_working_hours_all();
        $edit_user_id = isset($_GET['edit_user_hours']) ? absint($_GET['edit_user_hours']) : 0;
        $edit_rows = ($edit_user_id && isset($all_user_hours[$edit_user_id]) && is_array($all_user_hours[$edit_user_id])) ? $all_user_hours[$edit_user_id] : [];
        $edit_by_day = [];
        foreach ($edit_rows as $row) { if (isset($row['day'])) $edit_by_day[intval($row['day'])] = $row; }
        $users = get_users(['number' => 300, 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => ['ID','display_name','user_email']]);
        echo '<hr style="margin:35px 0;">';
        echo '<div style="background:#fff8e1; padding:15px; border-right:4px solid #f0b400; margin:15px 0; max-width:1000px; line-height:1.9;"><strong>ساعات اختصاصی شاگرد:</strong> این ساعت‌ها فقط برای کاربر انتخاب‌شده در فرم رزرو نمایش داده می‌شوند. اگر همان ساعت قبلاً رزرو شده باشد، مثل روال عادی بسته/اشغال نمایش داده می‌شود.</div>';
        echo '<form method="POST"><h3>تعریف ساعات اختصاصی برای یک شاگرد</h3>';
        echo '<p><label><strong>انتخاب کاربر:</strong></label><br><select name="gtbp_private_user_id" class="gtbp-input-wide" style="max-width:480px;">';
        echo '<option value="0">انتخاب کنید...</option>';
        foreach ($users as $u) {
            $label = trim($u->display_name) !== '' ? $u->display_name : $u->user_email;
            echo '<option value="' . esc_attr($u->ID) . '" ' . selected($edit_user_id, $u->ID, false) . '>' . esc_html($label . ' - ' . $u->user_email) . '</option>';
        }
        echo '</select></p>';
        echo '<table class="gtbp-table" style="max-width:1000px;"><thead><tr><th width="120">روز هفته</th><th>ساعت‌های اختصاصی این کاربر</th></tr></thead><tbody>';
        foreach ($order as $day_idx) {
            $value = isset($edit_by_day[$day_idx]['slots']) ? $edit_by_day[$day_idx]['slots'] : '';
            echo '<tr><td><strong>' . esc_html($days_fa[$day_idx]) . '</strong></td><td><input type="text" name="private_slots[' . esc_attr($day_idx) . ']" value="' . esc_attr($value) . '" class="gtbp-input-wide" dir="ltr" placeholder="مثلاً 18:00-19:00, 21:00-22:00"><br><small>خالی = برای این روز ساعت اختصاصی ندارد.</small></td></tr>';
        }
        echo '</tbody></table><br><button type="submit" name="gtbp_save_user_hours" class="gtbp-btn" style="font-size:16px; padding:10px 20px;">ذخیره ساعات اختصاصی شاگرد</button></form>';

        echo '<h3 style="margin-top:30px;">شاگردهای دارای ساعات اختصاصی</h3>';
        echo '<table class="gtbp-table" style="max-width:1000px;"><thead><tr><th>کاربر</th><th>ساعات اختصاصی</th><th>عملیات</th></tr></thead><tbody>';
        if (!empty($all_user_hours)) {
            foreach ($all_user_hours as $uid => $rows) {
                $user = get_user_by('id', absint($uid));
                if (!$user) continue;
                $parts = [];
                foreach ((array)$rows as $row) {
                    $d = isset($row['day']) ? intval($row['day']) : -1;
                    $slots = isset($row['slots']) ? $row['slots'] : '';
                    if ($slots !== '' && isset($days_fa[$d])) $parts[] = $days_fa[$d] . ': ' . $slots;
                }
                echo '<tr><td>' . esc_html(($user->display_name ?: $user->user_email) . ' - ' . $user->user_email) . '</td><td dir="ltr" style="text-align:left;line-height:1.8;">' . esc_html(implode(' | ', $parts)) . '</td><td><a class="gtbp-btn" href="' . esc_url(admin_url('admin.php?page=gtbp_bookings&tab=hours&edit_user_hours=' . absint($uid))) . '">ویرایش</a> <a class="gtbp-btn gtbp-btn-danger" onclick="return confirm(&quot;ساعات اختصاصی این کاربر حذف شود؟&quot;)" href="' . esc_url(admin_url('admin.php?page=gtbp_bookings&tab=hours&action=delete_user_hours&user_id=' . absint($uid))) . '">حذف</a></td></tr>';
            }
        } else {
            echo '<tr><td colspan="3" style="text-align:center;">هنوز ساعت اختصاصی برای شاگردی تعریف نشده است.</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function admin_tab_settings() {
        $bank = $this->get_bank_info();
        $online_enabled = get_option('gtbp_payment_online_enabled', '1');
        $card_enabled   = get_option('gtbp_payment_card_enabled', '1');
        ?>
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
            <div style="background:#fff; padding:20px; border:1px solid #ddd; max-width:500px;">
                <h3>اطلاعات پرداخت</h3>
                <form method="POST">
                    <p><label>شماره کارت بانکی:</label><br>
                    <input type="text" name="bank_card" value="<?php echo esc_attr($bank['card']); ?>" class="gtbp-input-wide" dir="ltr" placeholder="1234-5678-9012-3456"></p>
                    
                    <p><label>به نام (نام صاحب حساب):</label><br>
                    <input type="text" name="bank_owner" value="<?php echo esc_attr($bank['owner']); ?>" class="gtbp-input-wide"></p>
                    
                    <button type="submit" name="gtbp_save_bank" class="gtbp-btn">ذخیره اطلاعات بانکی</button>
                </form>
            </div>

            <div style="background:#fff; padding:20px; border:1px solid #ddd; max-width:500px;">
                <h3>تنظیمات روش‌های پرداخت</h3>
                <form method="POST">
                    <p>
                        <label>
                            <input type="checkbox" name="gtbp_payment_online" value="1" <?php checked($online_enabled, '1'); ?>>
                            فعال‌سازی پرداخت آنلاین (ووکامرس)
                        </label>
                    </p>
                    <p>
                        <label>
                            <input type="checkbox" name="gtbp_payment_card" value="1" <?php checked($card_enabled, '1'); ?>>
                            فعال‌سازی پرداخت کارت به کارت
                        </label>
                    </p>
                    <p style="color:#666; font-size:12px;">توجه: حداقل یکی از روش‌ها باید فعال باشد.</p>
                    <button type="submit" name="gtbp_save_payment_settings" class="gtbp-btn">ذخیره تنظیمات پرداخت</button>
                </form>
            </div>
        </div>
        <?php
    }

    private function admin_tab_debt_credit() {
        ?>
        <div style="background:#fff; padding:20px; border:1px solid #ddd; max-width:800px; margin-top:20px;">
            <h3>تنظیم بدهی یا طلب کاربر</h3>
            <p style="color:#666; font-size:12px;">عدد مثبت = بدهی (مبلغ به کل سبد اضافه می‌شود) | عدد منفی = طلب (مبلغ از کل سبد کم می‌شود).</p>
            <form method="POST">
                <p><label>انتخاب کاربر:</label><br>
                <select name="user_id" required style="width:100%; padding:8px;">
                    <option value="">-- انتخاب کنید --</option>
                    <?php
                    $users = get_users(['fields' => ['ID', 'user_login', 'user_email', 'display_name', 'first_name', 'last_name']]);
                    foreach ($users as $user) {
                        $full_name = trim($user->first_name . ' ' . $user->last_name);
                        if (empty($full_name)) $full_name = $user->display_name;
                        $adjustment = $this->get_user_adjustment($user->ID);
                        $adjustment_text = ($adjustment > 0) ? "بدهی: " . number_format($adjustment) . " تومان" : (($adjustment < 0) ? "طلب: " . number_format(abs($adjustment)) . " تومان" : "بدون بدهی/طلب");
                        echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($full_name . ' (' . $user->user_email . ') - ' . $adjustment_text) . '</option>';
                    }
                    ?>
                </select></p>
                <p><label>مقدار (تومان):</label><br>
                <input type="number" name="adjustment_amount" value="0" step="1000" style="width:100%; padding:8px;" required>
                <small style="display:block; color:#777;">مثال: 50000 (بدهی) یا -30000 (طلب)</small>
                </p>
                <button type="submit" name="gtbp_save_user_adjustment" class="gtbp-btn">ذخیره تغییرات</button>
            </form>
        </div>

        <div style="margin-top: 30px;">
            <h3>لیست کاربران و وضعیت بدهی/طلب فعلی</h3>
            <table class="gtbp-table" style="max-width:800px;">
                <thead>
                    <tr><th>نام کاربر</th><th>ایمیل</th><th>بدهی/طلب (تومان)</th><th>توضیح</th></tr>
                </thead>
                <tbody>
                    <?php
                    $all_users = get_users(['fields' => ['ID', 'user_login', 'user_email', 'display_name', 'first_name', 'last_name']]);
                    foreach ($all_users as $user) {
                        $full_name = trim($user->first_name . ' ' . $user->last_name);
                        if (empty($full_name)) $full_name = $user->display_name;
                        $adjustment = $this->get_user_adjustment($user->ID);
                        if ($adjustment > 0) {
                            $text = "بدهی: " . number_format($adjustment) . " تومان";
                            $color = "#dc3545";
                        } elseif ($adjustment < 0) {
                            $text = "طلب: " . number_format(abs($adjustment)) . " تومان";
                            $color = "#28a745";
                        } else {
                            $text = "بدون بدهی/طلب";
                            $color = "#666";
                        }
                        echo "<tr><td>{$full_name}</td><td>{$user->user_email}</td><td style='color:{$color}; font-weight:bold;'>{$text}</td><td>-</td></tr>";
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function gtbp_event_logs_table() {
        global $wpdb;
        return $wpdb->prefix . 'gtbp_event_logs';
    }

    private function ensure_event_logs_table() {
        global $wpdb;
        $table = $this->gtbp_event_logs_table();
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table) return;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset_collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE $table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(80) NOT NULL DEFAULT '',
            context varchar(120) NOT NULL DEFAULT '',
            object_id bigint(20) unsigned NOT NULL DEFAULT 0,
            message text NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY object_id (object_id),
            KEY created_at (created_at)
        ) $charset_collate;");
    }

    private function log_event($type, $context, $object_id, $message) {
        global $wpdb;
        $this->ensure_event_logs_table();
        $wpdb->insert($this->gtbp_event_logs_table(), [
            'event_type' => sanitize_text_field($type),
            'context' => sanitize_text_field($context),
            'object_id' => intval($object_id),
            'message' => wp_strip_all_tags((string)$message),
            'created_at' => current_time('mysql')
        ]);
    }

    public function handle_internal_admin_event($type, $object_id, $message) {
        $this->log_event($type, 'admin_notice', intval($object_id), $message);
    }

    public function admin_event_logs_page() {
        if (!current_user_can('manage_options')) wp_die('شما دسترسی لازم را ندارید.');
        global $wpdb;
        $this->ensure_event_logs_table();
        $rows = $wpdb->get_results("SELECT * FROM " . $this->gtbp_event_logs_table() . " ORDER BY id DESC LIMIT 200");
        echo '<div class="wrap gtbp-admin-wrap"><h1>گزارش خطاها و رویدادهای فنی</h1>';
        echo '<p style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px;max-width:900px;line-height:1.9">اینجا خطاهای مهم مربوط به ساخت لینک کلاس، BBB، تلگرام، ساخت جلسه آموزشی و اعلان‌ها ثبت می‌شود تا عیب‌یابی سریع‌تر باشد.</p>';
        echo '<table class="widefat striped"><thead><tr><th>زمان</th><th>نوع</th><th>بخش</th><th>شناسه</th><th>پیام</th></tr></thead><tbody>';
        if ($rows) foreach($rows as $r) {
            echo '<tr><td dir="ltr">'.esc_html($r->created_at).'</td><td>'.esc_html($r->event_type).'</td><td>'.esc_html($r->context).'</td><td>'.intval($r->object_id).'</td><td>'.esc_html($r->message).'</td></tr>';
        } else echo '<tr><td colspan="5">هنوز گزارشی ثبت نشده است.</td></tr>';
        echo '</tbody></table></div>';
    }

    public function send_class_start_reminders() {
        global $wpdb;
        $this->ensure_table_columns();
        $now = current_time('timestamp');
        $today = current_time('Y-m-d');
        $tomorrow = date('Y-m-d', $now + DAY_IN_SECONDS);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE status='confirmed' AND booking_date BETWEEN %s AND %s AND (telegram_class_reminder_sent IS NULL OR telegram_class_reminder_sent=0) ORDER BY booking_date ASC, booking_time ASC LIMIT 100", $today, $tomorrow));
        foreach ((array)$rows as $b) {
            $parts = explode('-', (string)$b->booking_time);
            $start = trim($parts[0] ?? '');
            if (!preg_match('/^\d{1,2}:\d{2}$/', $start)) continue;
            $class_ts = strtotime($b->booking_date . ' ' . $start . ':00');
            if (!$class_ts) continue;
            $diff = $class_ts - $now;
            if ($diff > 30 * MINUTE_IN_SECONDS || $diff < 0) continue;
            $jdate = $this->gregorian_to_jalali_string($b->booking_date);
            $subject = 'یادآوری کلاس آلمانی';
            $msg = "سلام {$b->first_name} عزیز،\n\nکلاس شما حدود ۳۰ دقیقه دیگر شروع می‌شود.\n{$b->class_name}\n{$jdate} | {$b->booking_time}\n\nلینک کلاس نزدیک زمان شروع از پنل کاربری و ربات قابل دسترسی است.";
            if (!empty($b->email)) wp_mail($b->email, $subject, $msg);
            if (get_option('gtbp_telegram_enabled', 0) && !empty($b->telegram_chat_id)) {
                $token = get_option('gtbp_telegram_token', '');
                if ($token) $this->send_bot_message('telegram', $token, $b->telegram_chat_id, "⏰ یادآوری کلاس\n{$b->class_name}\n{$jdate} | {$b->booking_time}\nحدود ۳۰ دقیقه دیگر شروع می‌شود.");
            } else {
                $u = get_user_by('email', $b->email);
                $chat = $u ? get_user_meta($u->ID, '_gtbp_telegram_chat_id', true) : '';
                if ($chat && get_option('gtbp_telegram_enabled', 0)) {
                    $token = get_option('gtbp_telegram_token', '');
                    if ($token) $this->send_bot_message('telegram', $token, $chat, "⏰ یادآوری کلاس\n{$b->class_name}\n{$jdate} | {$b->booking_time}\nحدود ۳۰ دقیقه دیگر شروع می‌شود.");
                }
            }
            $wpdb->update($this->table_name, ['telegram_class_reminder_sent'=>1], ['id'=>intval($b->id)]);
        }
    }

    public function admin_bbb_page() {
        $bbb_url = get_option('gtbp_bbb_url', self::BBB_DEFAULT_URL);
        $bbb_secret = get_option('gtbp_bbb_secret', self::BBB_DEFAULT_SECRET);
        $teacher_name = get_option('gtbp_bbb_teacher_name', self::BBB_DEFAULT_TEACHER_NAME);
        $attendee_pw = get_option('gtbp_bbb_attendee_password', 'ap');
        $moderator_pw = get_option('gtbp_bbb_moderator_password', 'mp');
        $auto_create_enabled = get_option('gtbp_bbb_auto_create_enabled', '1');
        if (isset($_GET['msg']) && $_GET['msg'] == 'bbb_saved') {
            echo '<div class="notice notice-success is-dismissible"><p>تنظیمات BigBlueButton با موفقیت ذخیره شد.</p></div>';
        }
        ?>
        <div class="wrap gtbp-admin-wrap">
            <h1>تنظیمات BigBlueButton</h1>
            <p style="max-width:850px; line-height:1.9; background:#fff; padding:15px; border-right:4px solid #2271b1;">
                از این نسخه، ساخت خودکار کلاس‌ها دوباره با BigBlueButton انجام می‌شود. لینک ورود زبان‌آموز در ایمیل ارسال نمی‌شود و فقط پنج دقیقه قبل از کلاس از طریق سایت یا ربات در اختیار او قرار می‌گیرد؛ لینک مدیر/مدرس در ایمیل ادمین ارسال می‌شود.
            </p>
            <form method="post" style="max-width:850px; background:#fff; padding:20px; border:1px solid #ddd; border-radius:8px;">
                <table class="form-table">
                    <tr>
                        <th scope="row">ساخت خودکار کلاس BBB</th>
                        <td>
                            <label>
                                <input type="checkbox" name="gtbp_bbb_auto_create_enabled" value="1" <?php checked($auto_create_enabled, '1'); ?>>
                                فعال باشد
                            </label>
                            <p class="description">اگر غیرفعال باشد، ادمین باید لینک‌ها را دستی در لیست رزروها وارد کند.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">آدرس سرور BBB</th>
                        <td>
                            <input type="url" name="bbb_url" value="<?php echo esc_attr($bbb_url); ?>" dir="ltr" style="width:100%; padding:8px;" placeholder="https://bbb.example.com/bigbluebutton/"><br>
                            <small>آدرس API یا دامنه BBB. اگر فقط دامنه وارد شود، مسیر <code>/bigbluebutton/</code> خودکار اضافه می‌شود.</small>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Secret / Salt</th>
                        <td>
                            <input type="text" name="bbb_secret" value="<?php echo esc_attr($bbb_secret); ?>" dir="ltr" style="width:100%; padding:8px;" placeholder="BigBlueButton shared secret">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">نام مدرس</th>
                        <td><input type="text" name="bbb_teacher_name" value="<?php echo esc_attr($teacher_name); ?>" style="width:100%; padding:8px;"></td>
                    </tr>
                    <tr>
                        <th scope="row">رمز ورود زبان‌آموز</th>
                        <td><input type="text" name="bbb_attendee_password" value="<?php echo esc_attr($attendee_pw); ?>" dir="ltr" style="width:180px; padding:8px;"></td>
                    </tr>
                    <tr>
                        <th scope="row">رمز ورود مدرس/مدیر</th>
                        <td><input type="text" name="bbb_moderator_password" value="<?php echo esc_attr($moderator_pw); ?>" dir="ltr" style="width:180px; padding:8px;"></td>
                    </tr>
                </table>
                <p>
                    <button type="submit" name="gtbp_save_bbb" class="button button-primary button-large">ذخیره تنظیمات</button>
                    <button type="submit" name="gtbp_test_bbb" class="button button-secondary button-large">تست اتصال</button>
                </p>
            </form>
        </div>
        <?php
    }

    public function admin_personal_bbb_page() {
        $url = get_option('gtbp_personal_bbb_url', '');
        $secret = get_option('gtbp_personal_bbb_secret', '');
        $teacher = get_option('gtbp_personal_bbb_teacher_name', self::BBB_DEFAULT_TEACHER_NAME);
        $attendee = get_option('gtbp_personal_bbb_attendee_password', '');
        $moderator = get_option('gtbp_personal_bbb_moderator_password', '');
        $enabled = get_option('gtbp_personal_bbb_auto_create_enabled', '1');
        $gateway = get_option('gtbp_bridge_gateway_url', '');
        if (isset($_GET['msg']) && $_GET['msg'] === 'personal_saved') echo '<div class="notice notice-success is-dismissible"><p>تنظیمات سرور شخصی ذخیره شد.</p></div>';
        echo '<div class="wrap gtbp-admin-wrap"><h1>BigBlueButton شخصی</h1><form method="post" style="max-width:850px;background:#fff;padding:20px;border:1px solid #ddd;border-radius:8px;">';
        wp_nonce_field('gtbp_save_personal_bbb', 'gtbp_personal_bbb_nonce');
        echo '<table class="form-table">';
        echo '<tr><th>ساخت خودکار کلاس</th><td><label><input type="checkbox" name="personal_bbb_auto_create_enabled" value="1" '.checked($enabled, '1', false).'> فعال</label></td></tr>';
        echo '<tr><th>آدرس سرور</th><td><input type="url" name="personal_bbb_url" value="'.esc_attr($url).'" dir="ltr" class="large-text" placeholder="https://media.example.com/bigbluebutton/"></td></tr>';
        echo '<tr><th>Secret</th><td><input type="password" name="personal_bbb_secret" value="'.esc_attr($secret).'" dir="ltr" class="large-text" autocomplete="new-password"></td></tr>';
        echo '<tr><th>نام مدرس</th><td><input type="text" name="personal_bbb_teacher_name" value="'.esc_attr($teacher).'" class="large-text"></td></tr>';
        echo '<tr><th>رمز زبان آموز</th><td><input type="text" name="personal_bbb_attendee_password" value="'.esc_attr($attendee).'" dir="ltr"></td></tr>';
        echo '<tr><th>رمز مدرس</th><td><input type="text" name="personal_bbb_moderator_password" value="'.esc_attr($moderator).'" dir="ltr"></td></tr>';
        echo '<tr><th>درگاه انتقال ضبط</th><td><input type="url" name="recording_bridge_gateway" value="'.esc_attr($gateway).'" dir="ltr" class="large-text" placeholder="https://media.example.com/telegram-api"><p class="description">این مقدار را نرم افزار کنترل سرور تحویل می‌دهد.</p></td></tr>';
        echo '<tr><th>کلید مشترک ضبط</th><td><input type="password" name="recording_bridge_secret" value="" dir="ltr" class="large-text" autocomplete="new-password"><p class="description">خالی بماند تا مقدار فعلی تغییر نکند.</p></td></tr>';
        echo '</table><p><button class="button button-primary button-large" name="gtbp_save_personal_bbb">ذخیره</button> <button class="button button-secondary button-large" name="gtbp_test_personal_bbb">تست اتصال</button></p></form></div>';
    }



    public function admin_bbb_recording_search_page() {
        if (!current_user_can('manage_options')) wp_die('شما دسترسی لازم را ندارید.');
        $this->ensure_table_columns();
        global $wpdb;

        $bbb_input = isset($_POST['gtbp_recording_link']) ? sanitize_text_field(trim(wp_unslash($_POST['gtbp_recording_link']))) : '';
        $bbb_meeting_id = '';
        $bbb_recordings = [];
        $bbb_bookings = [];
        $rmt_input = isset($_POST['gtbp_roomeet_meeting_id']) ? sanitize_text_field(trim(wp_unslash($_POST['gtbp_roomeet_meeting_id']))) : '';
        $rmt_the_id = '';
        $rmt_recording = null;
        $rmt_bookings = [];
        $message = '';

        if (isset($_POST['gtbp_save_found_recording']) && check_admin_referer('gtbp_bbb_recording_search')) {
            $booking_id = intval($_POST['booking_id'] ?? 0);
            $recording_link = esc_url_raw(trim(wp_unslash($_POST['recording_link'] ?? '')));
            if ($booking_id && $recording_link) {
                $wpdb->update($this->table_name, [
                    'roomeet_recording_link' => $recording_link,
                    'roomeet_recording_checked' => current_time('mysql'),
                ], ['id'=>$booking_id]);
                $this->sync_learning_recording_link($booking_id, $recording_link, false);
                $message = 'لینک BBB برای رزرو انتخاب‌شده و جلسه آموزشی مرتبط ذخیره شد.';
            }
        }

        if (isset($_POST['gtbp_save_found_roomeet_recording']) && check_admin_referer('gtbp_roomeet_recording_search')) {
            $booking_id = intval($_POST['booking_id'] ?? 0);
            $the_id = sanitize_text_field(wp_unslash($_POST['the_id'] ?? ''));
            $rec = [
                'link' => esc_url_raw(wp_unslash($_POST['recording_link'] ?? '')),
                'download' => esc_url_raw(wp_unslash($_POST['download_link'] ?? '')),
            ];
            $saved = $booking_id && class_exists('GTBP_Roomeet_Provider')
                ? GTBP_Roomeet_Provider::instance()->save_recording_for_booking($booking_id, $rec, $the_id, false)
                : false;
            $message = $saved ? 'لینک ضبط رومیت بدون بازنویسی داده‌های موجود ذخیره و با پنل زبان‌آموز همگام شد.' : 'ذخیره ضبط رومیت انجام نشد؛ اطلاعات رزرو یا لینک ضبط را بررسی کنید.';
        }

        $meet_query   = isset($_POST['gtbp_meet_query']) ? sanitize_text_field(trim(wp_unslash($_POST['gtbp_meet_query']))) : '';
        $meet_results = [];

        if (isset($_POST['gtbp_assign_meet_recording']) && check_admin_referer('gtbp_meet_recording_search')) {
            $booking_id = intval($_POST['booking_id'] ?? 0);
            $file_id    = sanitize_text_field(wp_unslash($_POST['meet_file_id'] ?? ''));
            $file_link  = esc_url_raw(wp_unslash($_POST['meet_file_link'] ?? ''));
            $done = ($booking_id && $file_id && $this->meet_available())
                ? GTBP_Google_Meet_Provider::instance()->assign_recording_to_booking($booking_id, $file_id, $file_link, true)
                : false;
            $message = $done
                ? 'ویدئوی گوگل میت به این جلسه اختصاص یافت، فایل در درایو عمومی شد و در پنل زبان‌آموز قابل مشاهده و دانلود است.'
                : 'اختصاص ویدئوی گوگل میت انجام نشد. اتصال گوگل و شناسه فایل را بررسی کنید.';
        }

        if (isset($_POST['gtbp_search_meet_recordings']) && check_admin_referer('gtbp_meet_recording_search')) {
            if (!$this->meet_available()) {
                $message = 'ابتدا اتصال گوگل میت را کامل کنید.';
            } elseif ($meet_query === '') {
                $message = 'شماره رزرو، ایمیل یا نام زبان‌آموز را وارد کنید.';
            } else {
                $like = '%' . $wpdb->esc_like($meet_query) . '%';
                if (ctype_digit($meet_query)) {
                    $bookings = $wpdb->get_results($wpdb->prepare(
                        "SELECT * FROM {$this->table_name} WHERE id=%d LIMIT 5", intval($meet_query)));
                } else {
                    $bookings = $wpdb->get_results($wpdb->prepare(
                        "SELECT * FROM {$this->table_name}
                         WHERE (email LIKE %s OR first_name LIKE %s OR last_name LIKE %s
                                OR CONCAT(first_name,' ',last_name) LIKE %s)
                           AND booking_date <= CURDATE()
                         ORDER BY booking_date DESC LIMIT 12",
                        $like, $like, $like, $like));
                }
                if (!$bookings) {
                    $message = 'رزروی با این مشخصات پیدا نشد.';
                } else {
                    $provider = GTBP_Google_Meet_Provider::instance();
                    foreach ($bookings as $b) {
                        $match = $provider->match_recordings_for_booking($b, 6);
                        $meet_results[] = [
                            'booking'     => $b,
                            'attachment'  => $match['attachment'],
                            'candidates'  => $match['candidates'],
                        ];
                    }
                }
            }
        }

        if (isset($_POST['gtbp_search_recordings']) && check_admin_referer('gtbp_bbb_recording_search')) {
            $bbb_meeting_id = $this->extract_jitsi_room_id_from_url($bbb_input);
            if (!$bbb_meeting_id && preg_match('/^[A-Za-z0-9_\-\.]+$/', $bbb_input)) $bbb_meeting_id = sanitize_text_field($bbb_input);
            if ($bbb_meeting_id) {
                $bbb_recordings = $this->get_bbb_recordings($bbb_meeting_id);
                $bbb_bookings = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE roomeet_room_id=%s ORDER BY id DESC LIMIT 20", $bbb_meeting_id));
                if (empty($bbb_recordings)) $message = 'برای این meetingID هنوز ضبط BBB پیدا نشد.';
            } else {
                $message = 'از ورودی BBB شناسه جلسه قابل تشخیص نبود.';
            }
        }

        if (isset($_POST['gtbp_search_roomeet_recordings']) && check_admin_referer('gtbp_roomeet_recording_search')) {
            if ($rmt_input !== '' && class_exists('GTBP_Roomeet_Provider')) {
                // ورودی می‌تواند theIdOfMeeting، شماره رزرو یا لینک gateway معلم/زبان‌آموز باشد.
                $candidate_booking_id = 0;
                $query_string = wp_parse_url($rmt_input, PHP_URL_QUERY);
                if ($query_string) {
                    $query_args = [];
                    parse_str($query_string, $query_args);
                    if (!empty($query_args['gtbp_roomeet_join'])) $candidate_booking_id = intval($query_args['gtbp_roomeet_join']);
                } elseif (ctype_digit($rmt_input)) {
                    $candidate_booking_id = intval($rmt_input);
                }
                if ($candidate_booking_id) {
                    $rmt_the_id = (string)$wpdb->get_var($wpdb->prepare("SELECT rmt_meeting_id FROM {$this->table_name} WHERE id=%d", $candidate_booking_id));
                }
                if ($rmt_the_id === '') $rmt_the_id = $rmt_input;
                $rmt_the_id = sanitize_text_field($rmt_the_id);
                $rmt_recording = GTBP_Roomeet_Provider::instance()->get_recording($rmt_the_id);
                $rmt_bookings = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE rmt_meeting_id=%s ORDER BY id DESC LIMIT 20", $rmt_the_id));
                if (!$rmt_recording) $message = 'برای این theIdOfMeeting هنوز ضبطی از API رومیت پیدا نشد.';
            } else {
                $message = 'شناسه theIdOfMeeting رومیت را وارد کنید یا ابتدا تنظیمات رومیت را کامل کنید.';
            }
        }

        echo '<div class="wrap gtbp-admin-wrap"><h1>جستجو و بازیابی ویدئوهای کلاس</h1>';
        if ($message) echo '<div class="notice notice-info is-dismissible"><p>' . esc_html($message) . '</p></div>';

        echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:16px;max-width:1100px">';
        echo '<div class="card" style="padding:18px"><h2>BigBlueButton</h2><p>لینک ورود یا meetingID را وارد کنید تا API سرور BBB بررسی شود.</p><form method="post">';
        wp_nonce_field('gtbp_bbb_recording_search');
        echo '<input type="text" name="gtbp_recording_link" value="' . esc_attr($bbb_input) . '" placeholder="لینک جلسه یا meetingID" dir="ltr" style="width:100%;padding:10px;margin:8px 0 12px"> ';
        echo '<button type="submit" name="gtbp_search_recordings" class="button button-primary">جستجوی BBB</button></form>';
        if ($bbb_meeting_id) echo '<p><strong>meetingID:</strong> <code dir="ltr">' . esc_html($bbb_meeting_id) . '</code></p>';
        echo '</div>';

        echo '<div class="card" style="padding:18px;border-top:4px solid #1d4ed8"><h2>رومیت</h2><p>theIdOfMeeting، شماره رزرو یا لینک ورود معلم/زبان‌آموز را وارد کنید.</p><form method="post">';
        wp_nonce_field('gtbp_roomeet_recording_search');
        echo '<input type="text" name="gtbp_roomeet_meeting_id" value="' . esc_attr($rmt_input) . '" placeholder="theIdOfMeeting" dir="ltr" style="width:100%;padding:10px;margin:8px 0 12px"> ';
        echo '<button type="submit" name="gtbp_search_roomeet_recordings" class="button button-primary">جستجوی رومیت</button></form>';
        if ($rmt_the_id !== '') echo '<p><strong>theIdOfMeeting:</strong> <code dir="ltr">' . esc_html($rmt_the_id) . '</code></p>';
        echo '</div>';
        echo '</div>';

        if (!empty($bbb_recordings)) {
            echo '<h2>نتیجه BBB</h2><table class="widefat striped"><thead><tr><th>لینک ویدئو</th><th>ذخیره برای رزرو</th></tr></thead><tbody>';
            foreach ($bbb_recordings as $rec) {
                $link = esc_url($rec['link'] ?? '');
                echo '<tr><td dir="ltr"><a href="' . $link . '" target="_blank">' . esc_html($link) . '</a></td><td>';
                if ($bbb_bookings) {
                    echo '<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
                    wp_nonce_field('gtbp_bbb_recording_search');
                    echo '<input type="hidden" name="recording_link" value="' . esc_attr($rec['link']) . '"><select name="booking_id">';
                    foreach ($bbb_bookings as $b) echo '<option value="' . intval($b->id) . '">#' . intval($b->id) . ' - ' . esc_html($b->first_name . ' ' . $b->last_name . ' | ' . $this->gregorian_to_jalali_string($b->booking_date)) . '</option>';
                    echo '</select><button class="button" name="gtbp_save_found_recording" type="submit">ذخیره روی رزرو</button></form>';
                } else echo '<span style="color:#777">رزرو متناظر پیدا نشد.</span>';
                echo '</td></tr>';
            }
            echo '</tbody></table>';
        }

        if ($rmt_recording) {
            $rmt_link = esc_url($rmt_recording['link'] ?? '');
            $rmt_download = esc_url($rmt_recording['download'] ?? '');
            echo '<h2>نتیجه رومیت</h2><table class="widefat striped"><thead><tr><th>پخش/دانلود</th><th>ذخیره برای رزرو</th></tr></thead><tbody><tr><td dir="ltr">';
            if ($rmt_link) echo '<a href="' . $rmt_link . '" target="_blank">' . esc_html($rmt_link) . '</a>';
            if ($rmt_download) echo '<br><a href="' . $rmt_download . '" target="_blank">' . esc_html($rmt_download) . '</a>';
            echo '</td><td>';
            if ($rmt_bookings) {
                echo '<form method="post" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
                wp_nonce_field('gtbp_roomeet_recording_search');
                echo '<input type="hidden" name="the_id" value="' . esc_attr($rmt_the_id) . '"><input type="hidden" name="recording_link" value="' . esc_attr($rmt_recording['link'] ?? '') . '"><input type="hidden" name="download_link" value="' . esc_attr($rmt_recording['download'] ?? '') . '"><select name="booking_id">';
                foreach ($rmt_bookings as $b) echo '<option value="' . intval($b->id) . '">#' . intval($b->id) . ' - ' . esc_html($b->first_name . ' ' . $b->last_name . ' | ' . $this->gregorian_to_jalali_string($b->booking_date)) . '</option>';
                echo '</select><button class="button button-primary" name="gtbp_save_found_roomeet_recording" type="submit">ذخیره و نمایش در پنل</button></form>';
            } else echo '<span style="color:#777">رزروی با این theIdOfMeeting پیدا نشد.</span>';
            echo '</td></tr></tbody></table>';
        }

        /* ---------- گوگل میت: جستجو و اختصاص دقیق ضبط به جلسه ---------- */
        echo '<div class="card" style="max-width:1100px;padding:20px;margin-top:22px;border-right:5px solid #34a853">';
        echo '<h2>گوگل میت — اختصاص ویدئوی ضبط‌شده به جلسه</h2>';
        if (!$this->meet_available()) {
            echo '<p style="color:#b45309">اتصال گوگل میت هنوز کامل نیست. ابتدا از تب سرویس کلاس آنلاین، حساب گوگل را متصل کنید.</p>';
        } else {
            echo '<p style="line-height:2;color:#555">شماره رزرو، ایمیل یا نام زبان‌آموز را وارد کنید. افزونه ویدئوهای گوگل درایو را بررسی می‌کند و برای هر جلسه، نزدیک‌ترین گزینه‌ها را با امتیاز و دلیل تطبیق نشان می‌دهد تا با اطمینان ویدئوی درست را به همان جلسه وصل کنید.</p>';
            echo '<form method="post" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px">';
            wp_nonce_field('gtbp_meet_recording_search');
            echo '<input type="text" name="gtbp_meet_query" value="' . esc_attr($meet_query) . '" placeholder="شماره رزرو، ایمیل یا نام زبان‌آموز" style="min-width:320px;padding:8px">';
            echo '<button type="submit" name="gtbp_search_meet_recordings" class="button button-primary">جستجوی ویدئوهای گوگل میت</button>';
            echo '</form>';

            if (!empty($meet_results)) {
                foreach ($meet_results as $entry) {
                    $b = $entry['booking'];
                    echo '<div style="border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin-bottom:14px;background:#fff">';
                    echo '<h3 style="margin:0 0 8px">#' . intval($b->id) . ' — ' . esc_html(trim($b->first_name . ' ' . $b->last_name)) . ' | ' . esc_html($b->class_name) . '</h3>';
                    echo '<p style="margin:0 0 10px;color:#555">📅 ' . esc_html($b->booking_date) . ' | ⏰ ' . esc_html($b->booking_time);
                    if (!empty($b->meet_recording_link)) echo ' | <span style="color:#0f766e">هم‌اکنون یک ویدئو ثبت شده است</span>';
                    echo '</p>';

                    if (!empty($entry['attachment'])) {
                        $att = $entry['attachment'];
                        echo '<div style="background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:12px;margin-bottom:10px">';
                        echo '<strong>✅ ضبط رسمی این جلسه (پیوست‌شده توسط خود گوگل به همان ایونت):</strong><br>';
                        echo '<span dir="ltr">' . esc_html($att['name']) . '</span> ';
                        echo '<form method="post" style="display:inline-flex;gap:8px;margin-top:8px">';
                        wp_nonce_field('gtbp_meet_recording_search');
                        echo '<input type="hidden" name="meet_file_id" value="' . esc_attr($att['id']) . '">';
                        echo '<input type="hidden" name="meet_file_link" value="' . esc_attr($att['webViewLink']) . '">';
                        echo '<input type="hidden" name="booking_id" value="' . intval($b->id) . '">';
                        echo '<button class="button button-primary" name="gtbp_assign_meet_recording" type="submit">ذخیره و نمایش در پنل زبان‌آموز</button>';
                        echo '</form></div>';
                    }

                    if (empty($entry['candidates']) && empty($entry['attachment'])) {
                        echo '<p style="color:#b45309">هیچ ویدئویی در گوگل درایو برای بازه این کلاس پیدا نشد. اگر ضبط را در پوشه دیگری نگه می‌دارید، شناسه پوشه را در تنظیمات گوگل میت خالی بگذارید تا کل درایو جست‌وجو شود.</p>';
                    }

                    if (!empty($entry['candidates'])) {
                        echo '<table class="widefat striped"><thead><tr><th>نام فایل</th><th style="width:110px">ساخته‌شده</th><th style="width:90px">امتیاز</th><th>دلیل تطبیق</th><th style="width:230px">عملیات</th></tr></thead><tbody>';
                        foreach ($entry['candidates'] as $cand) {
                            $file = $cand['file'];
                            $created = !empty($file['createdTime']) ? gmdate('Y-m-d H:i', strtotime($file['createdTime'])) : '-';
                            $score = intval($cand['score']);
                            $color = $score >= 70 ? '#0f766e' : ($score >= 40 ? '#b45309' : '#94a3b8');
                            echo '<tr>';
                            echo '<td dir="ltr" style="text-align:left"><a href="' . esc_url($file['webViewLink']) . '" target="_blank" rel="noopener">' . esc_html($file['name']) . '</a></td>';
                            echo '<td dir="ltr">' . esc_html($created) . '</td>';
                            echo '<td><span style="background:' . $color . ';color:#fff;padding:2px 10px;border-radius:12px">' . $score . '</span></td>';
                            echo '<td style="font-size:.86rem;color:#555">' . esc_html(implode(' • ', $cand['reasons'])) . '</td>';
                            echo '<td><form method="post" style="display:flex;gap:6px">';
                            wp_nonce_field('gtbp_meet_recording_search');
                            echo '<input type="hidden" name="meet_file_id" value="' . esc_attr($file['id']) . '">';
                            echo '<input type="hidden" name="meet_file_link" value="' . esc_attr($file['webViewLink']) . '">';
                            echo '<input type="hidden" name="booking_id" value="' . intval($b->id) . '">';
                            echo '<button class="button" name="gtbp_assign_meet_recording" type="submit">اختصاص به این جلسه</button>';
                            echo '</form></td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                    }
                    echo '</div>';
                }
            }

            $meet_bulk_nonce = wp_create_nonce('gtbp_meet_backfill_recordings');
            echo '<div style="margin-top:18px;padding-top:16px;border-top:1px dashed #cbd5e1">';
            echo '<h3>بازیابی خودکار همه ضبط‌های قبلی گوگل میت</h3>';
            echo '<p style="color:#555;line-height:2">همه کلاس‌های گذشته Meet که هنوز ویدئویی ندارند بررسی می‌شوند. فقط تطبیق‌های مطمئن (امتیاز بالا یا پیوست رسمی گوگل) ثبت می‌شوند و هیچ لینک موجودی بازنویسی نمی‌شود.</p>';
            echo '<button class="button button-primary" id="gtbp-meet-backfill">شروع بازیابی ضبط‌های گوگل میت</button> <span id="gtbp-meet-backfill-status" style="margin-right:10px;color:#555"></span>';
            echo '<script>(function(){var btn=document.getElementById("gtbp-meet-backfill"),out=document.getElementById("gtbp-meet-backfill-status");if(!btn)return;btn.addEventListener("click",function(){btn.disabled=true;var cursor=0,total=0,assigned=0,skipped=0,failed=0;function run(){var d=new FormData();d.append("action","gtbp_meet_backfill_recordings");d.append("nonce","' . esc_js($meet_bulk_nonce) . '");d.append("cursor",cursor);fetch(ajaxurl,{method:"POST",credentials:"same-origin",body:d}).then(function(r){return r.json()}).then(function(r){if(!r.success)throw new Error(typeof r.data==="string"?r.data:"خطای گوگل درایو");var x=r.data;cursor=x.next_cursor;total+=x.processed;assigned+=x.assigned;skipped+=x.skipped;failed+=x.failed;out.textContent="بررسی‌شده: "+total+" | ثبت‌شده: "+assigned+" | نامطمئن (نیاز به انتخاب دستی): "+skipped+" | خطا: "+failed;if(x.done){btn.disabled=false;out.textContent+=" — بررسی کامل شد.";}else run();}).catch(function(e){btn.disabled=false;out.textContent="متوقف شد: "+e.message;});}run();});})();</script>';
            echo '</div>';
        }
        echo '</div>';

        $bulk_nonce = wp_create_nonce('gtbp_roomeet_backfill_recordings');
        echo '<div class="card" style="max-width:1100px;padding:20px;margin-top:22px;border-right:5px solid #0f766e"><h2>بازیابی همه ضبط‌های قبلی رومیت</h2><p>فهرست واقعی همه اتاق‌ها مستقیماً و صفحه‌به‌صفحه از پنل رومیت خوانده می‌شود؛ سپس ضبط هر اتاق به رزرو متناظر وصل می‌شود. داده و لینک دستی موجود بازنویسی یا حذف نمی‌شود.</p><button type="button" id="gtbp-rmt-backfill" class="button button-primary button-large">پیدا کردن و ثبت همه ضبط‌های قبلی رومیت</button><p id="gtbp-rmt-backfill-status" style="font-weight:700"></p></div>';
        echo '<script>(function(){var btn=document.getElementById("gtbp-rmt-backfill"),out=document.getElementById("gtbp-rmt-backfill-status");if(!btn)return;btn.addEventListener("click",function(){if(!confirm("همه اتاق‌های موجود در پنل رومیت و ضبط‌هایشان بررسی شوند؟ هیچ داده موجودی پاک یا بازنویسی نمی‌شود."))return;btn.disabled=true;var cursor=1,total=0,found=0,synced=0,unmatched=0,without=0,failed=0;function run(){var data=new FormData();data.append("action","gtbp_roomeet_backfill_recordings");data.append("nonce","' . esc_js($bulk_nonce) . '");data.append("cursor",cursor);fetch(ajaxurl,{method:"POST",credentials:"same-origin",body:data}).then(function(r){return r.json()}).then(function(r){if(!r.success)throw new Error(typeof r.data==="string"?r.data:"خطای API رومیت");var d=r.data;cursor=d.next_cursor;total+=d.processed;found+=d.found;synced+=d.synced;unmatched+=d.unmatched||0;without+=d.without_recording||0;failed+=d.failed;out.textContent="اتاق بررسی‌شده: "+total+" | دارای ضبط: "+found+" | ثبت‌شده در پنل: "+synced+" | بدون ضبط: "+without+" | بدون رزرو متناظر: "+unmatched+" | خطا: "+failed;if(d.done){btn.disabled=false;out.textContent+=" - بررسی همه صفحات رومیت کامل شد.";}else run();}).catch(function(e){btn.disabled=false;out.textContent="عملیات متوقف شد: "+e.message;});}run();});})();</script>';
        echo '</div>';
    }

    /** بازیابی دسته‌ای ضبط‌های قدیمی گوگل میت؛ فقط تطبیق‌های مطمئن ثبت می‌شوند. */
    public function ajax_meet_backfill_recordings() {
        check_ajax_referer('gtbp_meet_backfill_recordings', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی غیرمجاز');
        if (!$this->meet_available()) wp_send_json_error('اتصال گوگل میت کامل نیست.');

        global $wpdb;
        $cursor    = isset($_POST['cursor']) ? intval($_POST['cursor']) : 0;
        $batch     = 5;
        $provider  = GTBP_Google_Meet_Provider::instance();
        $processed = 0; $assigned = 0; $skipped = 0; $failed = 0;

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name}
             WHERE (
                   (meet_join_link IS NOT NULL AND meet_join_link<>'')
                OR (meet_calendar_event_id IS NOT NULL AND meet_calendar_event_id<>'')
                OR (class_provider = 'meet')
                OR (roomeet_join_link LIKE '%%meet.google.com%%')
             )
             AND (meet_recording_link IS NULL OR meet_recording_link='')
             AND booking_date <= CURDATE()
             ORDER BY booking_date DESC
             LIMIT %d OFFSET %d",
            $batch, $cursor
        ));

        foreach ((array) $rows as $b) {
            $processed++;
            try {
                $match = $provider->match_recordings_for_booking($b, 3);
                if (!empty($match['attachment'])) {
                    $file = $match['attachment'];
                    $provider->assign_recording_to_booking($b->id, $file['id'], $file['webViewLink'], false)
                        ? $assigned++ : $failed++;
                    continue;
                }
                if (empty($match['candidates'])) { $skipped++; continue; }
                $best   = $match['candidates'][0];
                $second = isset($match['candidates'][1]) ? intval($match['candidates'][1]['score']) : -999;
                if (intval($best['score']) >= GTBP_Google_Meet_Provider::AUTO_ASSIGN_MIN_SCORE
                    && (intval($best['score']) - $second) >= 15) {
                    $provider->assign_recording_to_booking($b->id, $best['file']['id'], $best['file']['webViewLink'], false)
                        ? $assigned++ : $failed++;
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $failed++;
            }
        }

        wp_send_json_success([
            'processed'   => $processed,
            'assigned'    => $assigned,
            'skipped'     => $skipped,
            'failed'      => $failed,
            'next_cursor' => $cursor + $processed,
            'done'        => ($processed < $batch),
        ]);
    }

    /** اجرای یک دسته از بازیابی ضبط‌های قدیمی رومیت از صفحه ادمین. */
    public function ajax_roomeet_backfill_recordings() {
        check_ajax_referer('gtbp_roomeet_backfill_recordings', 'nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('دسترسی غیرمجاز');
        if (!class_exists('GTBP_Roomeet_Provider') || !$this->roomeet_available()) {
            wp_send_json_error('تنظیمات رومیت کامل نیست یا اتصال در دسترس نیست.');
        }
        $cursor = max(1, intval($_POST['cursor'] ?? 1));
        $result = GTBP_Roomeet_Provider::instance()->backfill_roomeet_rooms_batch($cursor, 5);
        if (!empty($result['error'])) {
            $errors = [
                'not_configured' => 'تنظیمات اتصال رومیت کامل نیست.',
                'room_list_failed' => 'API رومیت فهرست اتاق‌ها را برنگرداند. گزارش خطای رومیت را بررسی کنید.',
            ];
            wp_send_json_error($errors[$result['error']] ?? ('بازیابی رومیت انجام نشد: ' . sanitize_text_field($result['error'])));
        }
        wp_send_json_success($result);
    }

    /* ========================================================================== 
       صفحه جدید: ساخت جلسه فوری (Instant Session)
       ========================================================================== */
    public function admin_instant_session_page() {
        if (!current_user_can('manage_options')) {
            wp_die('شما دسترسی لازم را ندارید.');
        }

        // پردازش فرم
        $message = '';
        $message_type = '';
        $generated_links = null; // برای حالت بدون شاگرد

        // نسخه ۱۴.۱: انتخاب سرویس برای جلسه فوری
        $svc_labels = ['bbb' => 'BigBlueButton', 'bbb_personal' => 'BigBlueButton شخصی', 'meet' => 'Google Meet', 'roomeet' => 'روومیت'];
        $instant_service = $this->get_active_service();

        if (isset($_POST['gtbp_create_instant_session']) && wp_verify_nonce($_POST['_wpnonce'], 'gtbp_instant_session')) {
            $no_student = isset($_POST['no_student']) ? true : false;
            $class_name = sanitize_text_field($_POST['class_name']);
            $date = !empty($_POST['date']) ? sanitize_text_field($_POST['date']) : current_time('Y-m-d');
            $instant_start = current_time('H:i');
            $instant_end = date('H:i', strtotime(current_time('Y-m-d H:i') . ' +60 minutes'));
            $time = !empty($_POST['time']) ? sanitize_text_field($_POST['time']) : ($instant_start . '-' . $instant_end);
            $instant_service = isset($_POST['instant_service']) ? sanitize_key($_POST['instant_service']) : $this->get_active_service();
            if (!in_array($instant_service, ['bbb', 'bbb_personal', 'meet', 'roomeet'], true)) $instant_service = 'bbb';
            $svc_label = $svc_labels[$instant_service];

            $errors = [];
            if ($instant_service === 'roomeet' && !$this->roomeet_available()) $errors[] = 'سرویس روومیت پیکربندی نشده است.';
            if ($instant_service === 'meet' && !$this->meet_available()) $errors[] = 'سرویس Google Meet پیکربندی نشده است.';


            if (!$no_student) {
                $user_id = intval($_POST['student_user_id']);
                if (empty($user_id)) {
                    $errors[] = 'لطفاً یک شاگرد انتخاب کنید یا گزینه "ساخت اتاق بدون شاگرد" را فعال کنید.';
                }
            }

            if (empty($class_name)) {
                $errors[] = 'لطفاً نوع کلاس را انتخاب کنید.';
            }

            if (empty($errors)) {
                global $wpdb;

                if (!$no_student) {
                    // حالت با شاگرد: رزرو کامل + BigBlueButton + ایمیل
                    $user = get_userdata($user_id);
                    $first_name = $user->first_name ? $user->first_name : $user->display_name;
                    $last_name = $user->last_name;
                    $email = $user->user_email;
                    $phone = get_user_meta($user_id, 'billing_phone', true);
                    if (empty($phone)) $phone = 'ثبت نشده';

                    // تبدیل تاریخ میلادی به شمسی برای نام اتاق
                    list($gy, $gm, $gd) = explode('-', $date);
                    $jalali = $this->internal_gregorian_to_jalali($gy, $gm, $gd);
                    $jDate = $jalali[0] . '/' . sprintf('%02d', $jalali[1]) . '/' . sprintf('%02d', $jalali[2]);

                    $student_name = trim($first_name . ' ' . $last_name);
                    $teacher_name = 'حمیدرضا سعادتی';
                    $room_name = $jDate . ' - ' . $student_name;

                    $room = $this->create_jitsi_room($room_name, $student_name, $teacher_name, $instant_service);
                    if (!$room) {
                        $message = "خطا در ایجاد اتاق {$svc_label}. لطفاً تنظیمات {$svc_label} را بررسی کنید.";
                        $message_type = 'error';
                    } else {
                        $meeting_id = $room['meeting_id'];
                        $student_link = $room['student_link'];
                        $teacher_link = $room['teacher_link'];

                        $wpdb->insert(
                            $this->table_name,
                            [
                                'first_name' => $first_name,
                                'last_name' => $last_name,
                                'email' => $email,
                                'phone' => $phone,
                                'booking_date' => $date,
                                'booking_time' => $time,
                                'class_name' => $class_name,
                                'status' => 'confirmed',
                                'class_provider' => $instant_service,
                                'roomeet_room_id' => $meeting_id,
                                'roomeet_join_link' => $student_link,
                                'bbb_moderator_link' => $teacher_link
                            ]
                        );
                        $booking_id = $wpdb->insert_id;
                if ($booking_id && class_exists('GTBP_Learning_Dashboard_v211')) { GTBP_Learning_Dashboard_v211::instance()->create_session_from_booking_id($booking_id); }
                        if ($booking_id && $room) {
                            $booking = $wpdb->get_row("SELECT * FROM {$this->table_name} WHERE id = {$booking_id}");
                            // برای roomeet/meet لینک‌های واقعی در ensure ساخته می‌شوند
                            $this->update_user_jitsi_links($user_id, $booking);
                            $fresh = $wpdb->get_row("SELECT * FROM {$this->table_name} WHERE id = {$booking_id}");
                            if ($fresh) { $student_link = $fresh->roomeet_join_link; $teacher_link = $fresh->bbb_moderator_link; }
                        }

                        // ارسال ایمیل (مشابه رزرو دستی) با لینک‌های نهایی
                        $cart_mock = [
                            [
                                'date' => $date,
                                'time' => $time,
                                'jDate' => $jDate,
                                'class' => ['name' => $class_name]
                            ]
                        ];
                        $jitsi_rooms = [[
                            'date' => $date,
                            'time' => $time,
                            'class_name' => $class_name,
                            'student_link' => $student_link,
                            'teacher_link' => $teacher_link
                        ]];
                        $this->send_booking_emails($first_name, $last_name, $email, $phone, $cart_mock, 0, true, false, $jitsi_rooms);

                        $message = "جلسه فوری با {$svc_label} با موفقیت ایجاد شد و ایمیل‌ها ارسال گردید.";
                        $message_type = 'success';
                    }
                } else {
                    // حالت بدون شاگرد: ساخت اتاق با سرویس انتخابی و نمایش لینک‌ها
                    $jalali_date = $this->gregorian_to_jalali_string($date);
                    $room_name = "جلسه فوری - بدون شاگرد - {$jalali_date} {$time}";
                    $ns_links = null;
                    if ($instant_service === 'roomeet') {
                        $the_id = GTBP_Roomeet_Provider::instance()->create_room($room_name);
                        if ($the_id) {
                            $link = GTBP_Roomeet_Provider::instance()->ensure_and_join($the_id);
                            if ($link) $ns_links = ['student_link' => $link, 'teacher_link' => $link];
                        }
                    } elseif ($instant_service === 'meet') {
                        $res = GTBP_Google_Meet_Provider::instance()->create_meeting_for_booking([
                            'date' => $date, 'time' => $time, 'title' => $room_name,
                        ]);
                        if ($res && !empty($res['join_link'])) $ns_links = ['student_link' => $res['join_link'], 'teacher_link' => $res['join_link']];
                    } else {
                        $room = $this->create_standalone_bbb_room($room_name, 'بدون شاگرد', 'حمیدرضا سعادتی', $instant_service);
                        if ($room) $ns_links = ['student_link' => $room['student_link'], 'teacher_link' => $room['teacher_link']];
                    }
                    if (!$ns_links) {
                        $message = "خطا در ایجاد اتاق {$svc_label}. لطفاً تنظیمات {$svc_label} را بررسی کنید.";
                        $message_type = 'error';
                    } else {
                        $generated_links = $ns_links;
                        $message = "اتاق {$svc_label} با موفقیت ساخته شد. لینک‌های زیر برای استفاده در دسترس هستند.";
                        $message_type = 'success';
                    }
                }
            } else {
                $message = implode('<br>', $errors);
                $message_type = 'error';
            }
        }

        // نمایش صفحه
        $classes = $this->get_classes();
        ?>
        <div class="wrap gtbp-admin-wrap">
            <h1>ساخت جلسه فوری</h1>
            <?php if ($message): ?>
                <div class="notice notice-<?php echo esc_attr($message_type === 'success' ? 'success' : 'error'); ?> is-dismissible">
                    <p><?php echo wp_kses_post($message); ?></p>
                </div>
            <?php endif; ?>

            <?php if ($generated_links): ?>
                <div style="background: #e9f7ef; border: 1px solid #28a745; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
                    <h3 style="color: #155724; margin-top: 0;">لینک‌های اتاق ساخته شده:</h3>
                    <p><strong>لینک دانش‌آموز (شرکت‌کننده):</strong><br>
                    <a href="<?php echo esc_url($generated_links['student_link']); ?>" target="_blank" style="word-break: break-all;"><?php echo esc_url($generated_links['student_link']); ?></a></p>
                    <p><strong>لینک معلم (مدیر جلسه):</strong><br>
                    <a href="<?php echo esc_url($generated_links['teacher_link']); ?>" target="_blank" style="word-break: break-all;"><?php echo esc_url($generated_links['teacher_link']); ?></a></p>
                    <p><small>با کلیک روی لینک‌ها می‌توانید آن‌ها را کپی کنید یا در اختیار دیگران قرار دهید.</small></p>
                </div>
            <?php endif; ?>

            <div style="background:#fff; padding:20px; border:1px solid #ddd; border-radius:5px; max-width:600px; margin-top:20px;">
                <form method="post">
                    <?php wp_nonce_field('gtbp_instant_session'); ?>
                    <p>
                        <label>سرویس ساخت کلاس:</label><br>
                        <select name="instant_service" class="gtbp-input-wide" style="max-width:100%;">
                            <?php foreach ($svc_labels as $sk => $sl):
                                $ready = ($sk === 'bbb') ? true : ($sk === 'meet' ? $this->meet_available() : $this->roomeet_available());
                                $sel = selected($instant_service, $sk, false);
                                echo '<option value="' . esc_attr($sk) . '" ' . $sel . '>' . esc_html($sl) . ($ready ? '' : ' (تنظیمات ناقص)') . '</option>';
                            endforeach; ?>
                        </select>
                        <small style="color:#666;display:block;margin-top:4px;">پیش‌فرض روی سرویس فعال است. می‌توانید برای این جلسه سرویس دیگری انتخاب کنید.</small>
                    </p>
                    <p>
                        <label><input type="checkbox" name="no_student" value="1" id="no_student_checkbox"> ساخت اتاق بدون شاگرد (هیچ رزروی در دیتابیس ثبت نمی‌شود و ایمیلی ارسال نمی‌گردد)</label>
                    </p>
                    <div id="student_select_wrapper" style="margin-bottom:15px;">
                        <label>انتخاب شاگرد (دانش‌آموز):</label><br>
                        <select name="student_user_id" class="gtbp-input-wide" style="max-width:100%;">
                            <option value="">-- انتخاب کنید --</option>
                            <?php
                            $users = get_users();
                            foreach ($users as $user) {
                                $full_name = trim($user->first_name . ' ' . $user->last_name);
                                if (empty($full_name)) $full_name = $user->display_name;
                                echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($full_name . ' (' . $user->user_email . ')') . '</option>';
                            }
                            ?>
                        </select>
                    </div>
                    <p>
                        <label>نوع کلاس:</label><br>
                        <select name="class_name" required class="gtbp-input-wide">
                            <option value="">-- انتخاب کنید --</option>
                            <?php foreach($classes as $c): ?>
                                <option value="<?php echo esc_attr($c['name']); ?>"><?php echo esc_html($c['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </p>
                    <div style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:10px; padding:12px; margin:15px 0; color:#374151; line-height:1.8;">
                        برای جلسه فوری، تاریخ و ساعت به‌صورت خودکار روی زمان فعلی ثبت می‌شود و نیازی به وارد کردن آن‌ها نیست.
                    </div>
                    <input type="hidden" name="date" value="">
                    <input type="hidden" name="time" value="">
                    <p>
                        <button type="submit" name="gtbp_create_instant_session" class="gtbp-btn" style="font-size:16px; padding:10px 20px;">ساخت جلسه فوری</button>
                    </p>
                </form>
            </div>
        </div>
        <script>
            // غیرفعال/فعال کردن dropdown شاگرد بر اساس چک‌باکس
            document.getElementById('no_student_checkbox').addEventListener('change', function() {
                var wrapper = document.getElementById('student_select_wrapper');
                var select = wrapper.querySelector('select');
                if (this.checked) {
                    select.disabled = true;
                    wrapper.style.opacity = '0.6';
                } else {
                    select.disabled = false;
                    wrapper.style.opacity = '1';
                }
            });
        </script>
        <?php
    }

    /* ==========================================================================
       FRONTEND UI (SHORTCODE) - با پشتیبانی از بدهی/طلب و رفع مشکلات ظاهری
       ========================================================================== */
    public function render_booking_frontend() {
        if (!is_user_logged_in()) {
            return '<div style="background:#f8d7da; color:#721c24; padding:20px; border-radius:8px; text-align:center; font-family:IRANSansXFaNum, Tahoma, sans-serif; direction:rtl; border:1px solid #f5c6cb;">برای رزرو کلاس، لطفاً ابتدا وارد حساب کاربری خود شوید.</div>';
        }

        wp_enqueue_script('jquery');
        
        $classes = $this->get_classes();
        $packages = $this->get_active_booking_packages();
        $bank_info = $this->get_bank_info();
        $online_enabled = get_option('gtbp_payment_online_enabled', '1') === '1';
        $card_enabled   = get_option('gtbp_payment_card_enabled', '1') === '1';
        
        if (!$online_enabled && !$card_enabled) {
            return '<div style="background:#f8d7da; color:#721c24; padding:20px; border-radius:8px; text-align:center; font-family:IRANSansXFaNum, Tahoma, sans-serif; direction:rtl; border:1px solid #f5c6cb;">در حال حاضر هیچ روش پرداختی فعال نیست. لطفاً با مدیریت تماس بگیرید.</div>';
        }
        
        $current_user = wp_get_current_user();
        $current_phone = get_user_meta($current_user->ID, 'billing_phone', true);
        $show_outside_iran_option = true; // نسخه ۱۰.۶: مهلت ۲۴ ساعته کارت‌به‌کارت برای همه کاربران فعال است.
        $user_adjustment = $this->get_user_adjustment($current_user->ID);
        
        ob_start();
        
        if (isset($_GET['gtbp_failed']) && $_GET['gtbp_failed'] == '1') {
            echo '<div style="background:#fee2e2; color:#991b1b; padding:20px; border-radius:8px; text-align:center; font-family:IRANSansXFaNum, Tahoma, sans-serif; direction:rtl; border: 1px solid #fecaca; margin-bottom: 20px; font-weight:bold;">❌ پرداخت ناموفق بود یا توسط شما لغو شد. رزرو قطعی ثبت نشد. می‌توانید دوباره اقدام کنید.</div>';
        }

        if (isset($_GET['gtbp_success']) && $_GET['gtbp_success'] == '1') {
            echo '<div style="background:#d4edda; color:#155724; padding:20px; border-radius:8px; text-align:center; font-family:IRANSansXFaNum, Tahoma, sans-serif; direction:rtl; border: 1px solid #c3e6cb; margin-bottom: 20px; font-weight:bold;">
            ✅ پرداخت شما با موفقیت انجام شد و کلاس‌های شما به صورت قطعی رزرو گردید. لینک ورود به کلاس ۳ دقیقه قبل از شروع در حساب کاربری و در صورت اتصال، در ربات تلگرام فعال/ارسال می‌شود.
            </div>';
        }
        
        if (isset($_GET['gtbp_outside_iran']) && $_GET['gtbp_outside_iran'] == '1') {
            echo '<div style="background:#fff3cd; color:#856404; padding:20px; border-radius:8px; text-align:center; font-family:IRANSansXFaNum, Tahoma, sans-serif; direction:rtl; border: 1px solid #ffeeba; margin-bottom: 20px; font-weight:bold;">
            ⏳ مهلت ۲۴ ساعته پرداخت با موفقیت ثبت شد. شما تا ۲۴ ساعت آینده فرصت دارید وجه را واریز کنید. ایمیل حاوی اطلاعات پرداخت برای شما ارسال شد.
            </div>';
        }
        
        if (isset($_GET['gtbp_payment_success']) && $_GET['gtbp_payment_success'] == '1') {
            echo '<div style="background:#d4edda; color:#155724; padding:20px; border-radius:8px; text-align:center; font-family:IRANSansXFaNum, Tahoma, sans-serif; direction:rtl; border: 1px solid #c3e6cb; margin-bottom: 20px; font-weight:bold;">
            💰 پرداخت شما تایید شد و رزرو شما قطعی گردید. لینک ورود به کلاس ۳ دقیقه قبل از شروع فعال/ارسال می‌شود.
            </div>';
        }
        
        if (isset($_GET['gtbp_payment_confirmed']) && $_GET['gtbp_payment_confirmed'] == '1') {
            echo '<div style="background:#d4edda; color:#155724; padding:20px; border-radius:8px; text-align:center; font-family:IRANSansXFaNum, Tahoma, sans-serif; direction:rtl; border: 1px solid #c3e6cb; margin-bottom: 20px; font-weight:bold;">
            ✅ پرداخت شما تایید شد و رزرو شما قطعی گردید.
            </div>';
        }
        
        echo $this->show_pending_bookings_notice();

        ?>
        <style>
            .gtbp-wrapper,.gtbp-wrapper *{font-family:'IRANSansXFaNum',Tahoma,sans-serif;}
            .gtbp-wrapper{direction:rtl;max-width:800px;margin:0 auto;background:#fff;padding:30px;border-radius:20px;box-shadow:0 16px 48px rgba(15,23,42,.09);border:1px solid #eaedf5;color:#111;position:relative;}
            .gtbp-step-title{font-size:1.15rem;font-weight:900;color:#111;margin-bottom:15px;display:flex;align-items:center;justify-content:space-between;gap:8px;border-bottom:1.5px solid #edf0f6;padding-bottom:10px;}
            .gtbp-month-header{text-align:center;font-size:1.05rem;font-weight:900;color:#111;margin-bottom:15px;background:#f8fafc;padding:10px 14px;border-radius:14px;border:1px solid #edf0f6;}
            .gtbp-calendar-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:8px;margin-bottom:30px;}
            .gtbp-day-name{text-align:center;font-size:.85rem;color:#667085;font-weight:600;padding-bottom:5px;}
            .gtbp-date-box{position:relative;background:#fdfdfd;border:1px solid #edf0f6;border-radius:14px;padding:12px 5px;text-align:center;cursor:pointer;transition:.2s;display:flex;flex-direction:column;min-height:60px;}
            .gtbp-date-box:hover:not(.disabled){border-color:#dd0000;background:#fff6f6;}
            .gtbp-date-box.active{background:#dd0000;color:#fff;border-color:#dd0000;}
            .gtbp-date-box.disabled{opacity:.35;cursor:not-allowed;background:#f5f5f5;filter:grayscale(100%);}
            .gtbp-j-num{font-size:1.3rem;font-weight:bold;line-height:1;margin-bottom:4px;margin-top:5px;}
            .gtbp-g-num{font-size:.75rem;opacity:.8;font-family:Tahoma,sans-serif;direction:ltr;}
            .gtbp-flags-wrap{position:absolute;top:4px;right:4px;display:flex;gap:4px;}
            .gtbp-flag{font-size:14px;line-height:1;cursor:pointer;transition:transform .2s;}
            .gtbp-flag:hover{transform:scale(1.3);}
            .gtbp-slots-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px;margin-bottom:30px;}
            .gtbp-slot-btn{position:relative;background:#fff;border:1px solid #dde2ea;border-radius:14px;padding:12px 5px;text-align:center;cursor:pointer;font-family:'IRANSansXFaNum',Tahoma,sans-serif;font-size:.95rem;font-weight:bold;transition:.2s;direction:ltr;}
            .gtbp-slot-btn:hover:not(.booked){border-color:#dd0000;color:#dd0000;}
            .gtbp-slot-btn.slot-morning{background:#fffdf5;color:#5f4300;border-color:#f7d46b;box-shadow:0 4px 12px rgba(245,158,11,.08);}
            .gtbp-slot-btn.slot-morning:hover:not(.booked){border-color:#f59e0b;color:#8a5a00;background:#fff8dc;}
            .gtbp-slot-btn.slot-afternoon{background:#f3f6f8;color:#374151;border-color:#cfd8e3;box-shadow:0 4px 12px rgba(100,116,139,.08);}
            .gtbp-slot-btn.slot-afternoon:hover:not(.booked){border-color:#94a3b8;color:#1f2937;background:#edf2f7;}
            .gtbp-slot-btn.slot-night{background:#172554;color:#fff;border-color:#1e3a8a;box-shadow:0 4px 14px rgba(23,37,84,.22);}
            .gtbp-slot-btn.slot-night:hover:not(.booked){border-color:#60a5fa;color:#fff;background:#1e3a8a;}
            .gtbp-slot-icon{position:absolute;top:-8px;right:-8px;width:26px;height:26px;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;box-shadow:0 2px 8px rgba(0,0,0,.14);font-size:15px;border:1px solid rgba(255,255,255,.85);}
            .gtbp-slot-btn.slot-morning .gtbp-slot-icon{background:#fff7cc;}
            .gtbp-slot-btn.slot-afternoon .gtbp-slot-icon{background:#e8eef5;}
            .gtbp-slot-btn.slot-night .gtbp-slot-icon{background:#243b73;color:#fff;}
            .gtbp-slot-btn.active{background:#dd0000!important;color:#fff!important;border-color:#dd0000!important;}
            .gtbp-slot-btn.booked{background:#f0f0f0!important;color:#aaa!important;cursor:not-allowed;text-decoration:line-through;border-color:#e0e0e0!important;box-shadow:none!important;}
            .gtbp-wrapper .gtbp-slot-btn.slot-night:not(.booked):not(.active),.gtbp-booking-embed .gtbp-wrapper .gtbp-slot-btn.slot-night:not(.booked):not(.active){background:#172554!important;color:#fff!important;border-color:#1e3a8a!important;box-shadow:0 4px 14px rgba(23,37,84,.22)!important;}
            .gtbp-wrapper .gtbp-slot-btn.slot-night:not(.booked):not(.active):hover,.gtbp-booking-embed .gtbp-wrapper .gtbp-slot-btn.slot-night:not(.booked):not(.active):hover{background:#1e3a8a!important;color:#fff!important;border-color:#60a5fa!important;}
            .gtbp-wrapper .gtbp-slot-btn.slot-night .gtbp-slot-icon,.gtbp-booking-embed .gtbp-wrapper .gtbp-slot-btn.slot-night .gtbp-slot-icon{background:#243b73!important;color:#fff!important;border-color:rgba(255,255,255,.35)!important;}
            .gtbp-slot-too-soon{font-size:.65rem;color:#dd0000;display:block;margin-top:3px;font-weight:normal;text-decoration:none;}
            .gtbp-slot-private{display:block;margin-top:4px;font-size:.65rem;font-weight:700;color:#0f766e;direction:rtl;text-decoration:none;}
            .gtbp-classes-grid{display:flex;flex-direction:column;gap:24px;margin-bottom:30px;}
            .gtbp-class-group{border-bottom:1px solid #e7ebf2;padding-bottom:24px;}
            .gtbp-class-group:last-child{border-bottom:0;padding-bottom:0;}
            .gtbp-class-group-title{display:flex;align-items:center;gap:10px;font-size:1.05rem;font-weight:900;color:#111;margin:0 0 14px;}
            .gtbp-class-group-icon{width:34px;height:34px;display:inline-flex;align-items:center;justify-content:center;border-radius:50%;background:#f2f5fa;color:#475569;flex:0 0 34px;}
            .gtbp-class-group-icon svg{width:20px;height:20px;stroke:currentColor;fill:none;stroke-width:1.8;stroke-linecap:round;stroke-linejoin:round;}
            .gtbp-class-cards-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;}
            .gtbp-class-card{--gtbp-card-bg:#fff;--gtbp-card-color:#111;--gtbp-card-accent:#2563eb;position:relative;min-width:0;min-height:128px;border:1.5px solid #d8dee8;border-radius:14px;padding:20px 48px 17px 18px;text-align:right;cursor:pointer;transition:border-color .2s,background .2s,box-shadow .2s,transform .2s;background:var(--gtbp-card-bg);color:var(--gtbp-card-color);box-shadow:0 3px 12px rgba(15,23,42,.035);display:flex;flex-direction:column;justify-content:center;}
            .gtbp-class-card:hover{border-color:var(--gtbp-card-accent);transform:translateY(-1px);box-shadow:0 8px 22px rgba(15,23,42,.07);}
            .gtbp-class-card.active{border-color:var(--gtbp-card-accent);background:#eff6ff;background:color-mix(in srgb,var(--gtbp-card-bg) 88%,var(--gtbp-card-accent) 12%);box-shadow:0 0 0 2px color-mix(in srgb,var(--gtbp-card-accent) 18%,transparent);}
            .gtbp-card-radio{position:absolute;right:17px;top:21px;width:22px;height:22px;border:1.8px solid #9aa7b8;border-radius:50%;background:#fff;display:flex;align-items:center;justify-content:center;box-sizing:border-box;}
            .gtbp-class-card.active .gtbp-card-radio{border-color:var(--gtbp-card-accent);}
            .gtbp-class-card.active .gtbp-card-radio:after{content:'';width:11px;height:11px;border-radius:50%;background:var(--gtbp-card-accent);}
            .gtbp-class-card.gtbp-package-card{min-height:142px;}
            .gtbp-package-badge{align-self:flex-start;display:inline-block;background:#fff2cf;color:#9a6200;border-radius:999px;padding:3px 10px;font-size:.7rem;font-weight:800;margin:2px 0 5px;}
            .gtbp-package-hint{background:#fff3cd;color:#856404;border:1px solid #ffeeba;border-radius:14px;padding:10px;margin-bottom:12px;font-size:.9rem;line-height:1.8;}
            .gtbp-class-name{font-weight:900;font-size:1rem;margin-bottom:5px;color:var(--gtbp-card-color);line-height:1.7;}
            .gtbp-class-price{font-size:.78rem;color:var(--gtbp-card-color);opacity:.72;margin-top:3px;line-height:1.8;}
            .gtbp-card-selected{font-size:.76rem;color:var(--gtbp-card-accent);font-weight:800;margin-top:8px;min-height:18px;}
            @media(max-width:760px){.gtbp-class-cards-grid{grid-template-columns:repeat(2,minmax(0,1fr));}}
            @media(max-width:520px){.gtbp-class-cards-grid{grid-template-columns:1fr;}.gtbp-class-card{min-height:112px;}}
            .gtbp-cart-item{display:flex;justify-content:space-between;align-items:center;background:#fff;border:1px solid #edf0f6;padding:12px 15px;border-radius:14px;margin-bottom:10px;font-size:.95rem;box-shadow:0 4px 12px rgba(15,23,42,.04);}
            .gtbp-cart-item-del{color:#dd0000;cursor:pointer;font-weight:bold;padding:5px;}
            .gtbp-total-row{font-size:1.05rem;font-weight:700;border-top:1.5px solid #edf0f6;padding-top:12px;margin-top:12px;color:#374151;display:flex;justify-content:space-between;align-items:center;}
            .gtbp-total-row span:first-child{font-weight:600;color:#64748b;}
            .gtbp-adjustment-row{font-size:.95rem;border-top:1px dashed #dde2ea;margin-top:8px;padding-top:8px;color:#374151;display:flex;justify-content:space-between;align-items:center;}
            .gtbp-adjustment-row span:first-child{color:#64748b;}
            .gtbp-adjustment-btn,.gtbp-total-btn{display:inline-flex;align-items:center;gap:4px;background:#f8fafc;border:1px solid #dde2ea;color:#374151;padding:5px 12px;border-radius:10px;font-weight:700;font-family:'IRANSansXFaNum',Tahoma,sans-serif;cursor:default;}
            .gtbp-adjustment-credit-btn,.gtbp-total-credit-btn{background:#ecfdf5!important;color:#16a34a!important;border-color:#a7f3d0!important;}
            .gtbp-adjustment-debt-btn,.gtbp-total-debt-btn{background:#fff1f2!important;color:#dd0000!important;border-color:#fecdd3!important;}
            @media(max-width:600px){.gtbp-total-row{font-size:.9rem;}.gtbp-adjustment-row{font-size:.8rem;}.gtbp-adjustment-btn,.gtbp-total-btn{font-size:.8rem;padding:4px 8px;}}
            .gtbp-bank-box{background:#fffafb;border:1px solid #ffd0d4;padding:16px;border-radius:16px;margin-top:20px;text-align:center;}
            .gtbp-bank-card{font-size:1.2rem;font-weight:bold;letter-spacing:2px;font-family:Tahoma,sans-serif;direction:ltr;display:inline-block;color:#dd0000;margin:10px 0;}
            .gtbp-btn-submit{background:linear-gradient(135deg,#dd0000,#a90000 56%,#111);color:#fff;width:100%;border:none;padding:16px;border-radius:16px;font-size:1.15rem;font-weight:900;cursor:pointer;font-family:'IRANSansXFaNum',Tahoma,sans-serif;transition:.2s;margin-top:20px;box-shadow:0 12px 28px rgba(221,0,0,.22);}
            .gtbp-btn-submit:hover:not(:disabled){box-shadow:0 16px 36px rgba(221,0,0,.30);transform:translateY(-1px);}
            .gtbp-btn-submit:disabled{background:#ccc;cursor:not-allowed;box-shadow:none;transform:none;}
            .gtbp-msg{padding:14px;border-radius:14px;margin-bottom:20px;display:none;text-align:center;font-weight:bold;}
            .gtbp-msg-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb;}
            .gtbp-msg-error{background:#fff1f2;color:#9b1c1c;border:1px solid #fecdd3;}
            #gtbp-loader{display:none;color:#dd0000;font-size:.9rem;margin-bottom:10px;}
            .gtbp-modal-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.6);z-index:99999;align-items:center;justify-content:center;backdrop-filter:blur(3px);}
            .gtbp-modal-box{background:#fff;padding:28px;border-radius:20px;width:90%;max-width:400px;text-align:center;box-shadow:0 24px 60px rgba(15,23,42,.18);}
            .gtbp-modal-box h4{margin-top:0;color:#dd0000;font-size:1.25rem;margin-bottom:10px;}
            .gtbp-modal-box p{color:#555;margin-bottom:20px;line-height:1.7;}
            .gtbp-modal-btn{padding:11px 24px;border:none;border-radius:14px;font-family:'IRANSansXFaNum',Tahoma,sans-serif;font-size:1rem;cursor:pointer;margin:5px;font-weight:900;}
            .gtbp-btn-yes{background:#f1f5f9;color:#334155;}
            .gtbp-btn-no{background:linear-gradient(135deg,#dd0000,#a90000);color:#fff;box-shadow:0 8px 20px rgba(221,0,0,.18);}
            #gtbp-apply-coupon{background:#dd0000;color:#fff;border:1px solid #dd0000;}
            #gtbp-apply-coupon:hover{background:#a90000;color:#fff;}
            .gtbp-recording-item{background:#f9f9f9;border:1px solid #e0e0e0;border-radius:14px;padding:15px;margin-bottom:10px;display:flex;justify-content:space-between;align-items:center;}
            .gtbp-recording-item:hover{background:#f0f0f0;}
            .gtbp-recording-link{background:#ffce00;color:#111;padding:8px 16px;border-radius:10px;text-decoration:none;font-size:.9rem;font-weight:900;}
            .gtbp-recording-link:hover{background:#e6b800;}
            .gtbp-card-payment-box{background:#f8f9fa;border:1.5px solid #dd0000;border-radius:20px;padding:25px;margin-top:30px;text-align:center;display:none;}
            .gtbp-card-timer{font-size:2.5rem;font-weight:bold;color:#dd0000;margin:15px 0;font-family:monospace;direction:ltr;}
            .gtbp-card-bank-info{background:#fff;border-radius:16px;padding:20px;margin:20px 0;}

            /* ===== Fix #1: Step progress bar ===== */
            .gtbp-stepper{display:flex;align-items:center;justify-content:center;margin:0 0 24px;direction:rtl;}
            .gtbp-step{display:flex;flex-direction:column;align-items:center;gap:5px;flex:0 0 auto;}
            .gtbp-step-num{width:34px;height:34px;border-radius:50%;background:#e5e7eb;color:#9ca3af;font-size:.85rem;font-weight:900;display:flex;align-items:center;justify-content:center;transition:.25s;}
            .gtbp-step.active .gtbp-step-num{background:#dd0000;color:#fff;box-shadow:0 0 0 5px rgba(221,0,0,.14);}
            .gtbp-step.done .gtbp-step-num{background:#16a34a;color:#fff;}
            .gtbp-step-lbl{font-size:.68rem;color:#9ca3af;font-weight:700;white-space:nowrap;}
            .gtbp-step.active .gtbp-step-lbl{color:#dd0000;}
            .gtbp-step.done .gtbp-step-lbl{color:#16a34a;}
            .gtbp-step-line{flex:1 1 30px;height:2px;background:#e5e7eb;min-width:16px;max-width:70px;transition:.25s;}
            .gtbp-step-line.done{background:#16a34a;}

            /* ===== Fix #3: Available-slot count badge ===== */
            .gtbp-slot-badge{position:absolute;top:3px;left:3px;background:#dd0000;color:#fff;font-size:.55rem;font-weight:900;border-radius:999px;padding:1px 5px;line-height:1.6;min-width:16px;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.18);}
            .gtbp-date-box{position:relative;}

            /* ===== Fix #4: +1 floating toast ===== */
            @keyframes gtbp-float-up{0%{opacity:1;transform:translateY(0) scale(1);}100%{opacity:0;transform:translateY(-48px) scale(1.2);}}
            .gtbp-add-toast{position:fixed;pointer-events:none;z-index:99999;font-size:1rem;font-weight:900;color:#dd0000;background:#fff;border:2px solid #dd0000;border-radius:999px;padding:4px 12px;animation:gtbp-float-up .8s ease-out forwards;}

            /* ===== Fix #5: Per-item price in cart ===== */
            .gtbp-cart-item-price{font-size:.75rem;color:#dd0000;font-weight:700;white-space:nowrap;}
            .gtbp-cart-item-price s{color:#aaa;font-weight:400;margin-left:4px;}

            /* ===== Fix #6: Countdown urgency ===== */
            @keyframes gtbp-pulse-red{0%,100%{box-shadow:0 0 0 0 rgba(221,0,0,.5);}50%{box-shadow:0 0 0 8px rgba(221,0,0,0);}}
            .gtbp-card-timer.gtbp-timer-urgent{color:#dd0000!important;animation:gtbp-pulse-red 1s infinite;}
            .gtbp-card-payment-expired{background:#fee2e2;border:1px solid #fecaca;border-radius:14px;padding:16px;color:#991b1b;font-weight:bold;text-align:center;margin-top:10px;}

            /* ===== Fix #7: Payment choice cards ===== */
            .gtbp-pay-choice-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:14px;}
            .gtbp-pay-choice{border:1.5px solid #edf0f6;border-radius:16px;padding:16px 10px;text-align:center;cursor:pointer;transition:.2s;background:#fff;box-shadow:0 4px 12px rgba(15,23,42,.04);}
            .gtbp-pay-choice:hover{border-color:#dd0000;background:#fff6f6;}
            .gtbp-pay-choice.selected{border-color:#dd0000;background:#fff5f5;box-shadow:0 0 0 3px rgba(221,0,0,.09);}
            .gtbp-pay-choice-icon{font-size:1.8rem;margin-bottom:6px;}
            .gtbp-pay-choice-label{font-weight:900;font-size:.9rem;color:#111;}
            .gtbp-pay-choice-sub{font-size:.72rem;color:#667085;margin-top:3px;}
            @media(max-width:500px){.gtbp-pay-choice-grid{grid-template-columns:1fr;}}

            /* ===== Fix #8: Zero-payable / direct booking success screen ===== */
            .gtbp-success-screen{display:none;text-align:center;padding:30px 20px;}
            .gtbp-success-icon{font-size:3.5rem;margin-bottom:12px;}
            .gtbp-success-screen h3{color:#16a34a;margin:0 0 10px;}
            .gtbp-success-screen p{color:#555;font-size:.95rem;line-height:1.7;}

            /* ===== Policy notice ===== */
            .gtbp-policy-notice{display:flex;gap:12px;align-items:flex-start;background:#fafbfc;border:1px solid #e8ecf3;border-right:3px solid #dd0000;border-radius:14px;padding:14px 16px;margin:18px 0;direction:rtl;}
            .gtbp-policy-icon{flex:0 0 auto;width:28px;height:28px;border-radius:50%;background:#dd0000;color:#fff;display:flex;align-items:center;justify-content:center;font-size:14px;font-style:normal;}
            .gtbp-policy-notice p{margin:0;font-size:.85rem;color:#374151;line-height:2;}
            .gtbp-policy-notice p strong{color:#dd0000;font-weight:700;}

            /* ===== Payment section cleanup ===== */
            .gtbp-coupon-box{margin:18px 0;border:1px solid #e8ecf3;padding:16px;border-radius:14px;background:#fafbfc;}
            .gtbp-coupon-box h4{margin:0 0 10px;color:#111;font-size:.95rem;font-weight:700;}
            .gtbp-coupon-box p{margin:0;color:#667085;font-size:.875rem;}
            .gtbp-coupon-input-row{display:flex;gap:10px;}
            .gtbp-coupon-input-row input{flex:1;padding:9px 12px;border:1px solid #dde2ea;border-radius:12px;font-family:'IRANSansXFaNum',Tahoma,sans-serif;}
            .gtbp-method-box{margin:18px 0;border:1px solid #e8ecf3;padding:16px;border-radius:14px;background:#fafbfc;}
            .gtbp-method-box h4{margin:0 0 12px;color:#111;font-size:.95rem;font-weight:700;border-bottom:1px solid #edf0f6;padding-bottom:10px;}
        </style>

        <div class="gtbp-wrapper">
            <div id="gtbp-msg" class="gtbp-msg"></div>

            <!-- Fix #1: Step progress bar -->
            <div class="gtbp-stepper" id="gtbp-stepper">
                <div class="gtbp-step active" id="gstep-1"><div class="gtbp-step-num">۱</div><div class="gtbp-step-lbl">نوع کلاس</div></div>
                <div class="gtbp-step-line" id="gstep-line-1"></div>
                <div class="gtbp-step" id="gstep-2"><div class="gtbp-step-num">۲</div><div class="gtbp-step-lbl">تاریخ</div></div>
                <div class="gtbp-step-line" id="gstep-line-2"></div>
                <div class="gtbp-step" id="gstep-3"><div class="gtbp-step-num">۳</div><div class="gtbp-step-lbl">ساعت و پرداخت</div></div>
            </div>

            <!-- Fix #8: Zero-payable direct booking success screen -->
            <div class="gtbp-success-screen" id="gtbp-success-screen">
                <div class="gtbp-success-icon">✅</div>
                <h3>رزرو شما با موفقیت ثبت شد!</h3>
                <p>جلسه‌های شما به صورت قطعی رزرو گردید. لینک ورود به کلاس ۳ دقیقه قبل از شروع در حساب کاربری فعال می‌شود.</p>
                <button class="gtbp-btn" style="background:#111;color:#fff;margin-top:16px;padding:10px 28px;border-radius:14px;font-weight:900;" onclick="location.reload()">بازگشت به صفحه رزرو</button>
            </div>

            <div id="booking-section">
                <div class="gtbp-step-title">۱. نوع کلاس را انتخاب کنید</div>
                <div class="gtbp-classes-grid" id="gtbp-classes"></div>

                <div id="step-2-wrap" style="display:none;">
                    <div class="gtbp-step-title" id="title-step-2">۲. تاریخ جلسه را انتخاب کنید</div>
                    <div class="gtbp-month-header" style="display:flex; justify-content:space-between; align-items:center;">
                        <button class="gtbp-btn" onclick="shiftMonth(-1)" id="btn-prev-month" style="padding:4px 12px; font-size:0.9rem;">ماه قبل</button>
                        <span id="gtbp-month-name"></span>
                        <button class="gtbp-btn" onclick="shiftMonth(1)" id="btn-next-month" style="padding:4px 12px; font-size:0.9rem;">ماه بعد</button>
                    </div>
                    <div class="gtbp-calendar-grid" id="gtbp-calendar"></div>
                </div>

                <div class="gtbp-step-title" style="display:none;" id="title-step-3">۳. ساعت را انتخاب کنید <span style="font-size:0.8rem; font-weight:normal; color:#666;">(فقط ساعات با حداقل ۲۴ ساعت فاصله)</span></div>
                <div id="gtbp-loader">در حال بررسی ظرفیت کلاس‌ها...</div>
                <div class="gtbp-slots-grid" id="gtbp-slots"></div>
            </div>

            <div id="summary-section" style="display:none;">
                <div class="gtbp-step-title">سبد رزرو شما <button onclick="backToCalendar()" style="background:none;border:1.5px solid #dd0000;color:#dd0000;padding:6px 12px;border-radius:10px;cursor:pointer;font-family:'IRANSansXFaNum',Tahoma,sans-serif;font-size:.8rem;font-weight:900;">+ افزودن کلاس دیگر</button></div>
                <div id="gtbp-package-status" class="gtbp-package-hint" style="display:none;"></div>
                <div id="cart-items-list"></div>
                
                <div class="gtbp-total-row"><span>مبلغ کل کلاس‌ها:</span>
                <span id="subtotal_price">0 تومان</span></div>

                <div class="gtbp-total-row" id="gtbp-package-discount-row" style="display:none; color:#155724; font-size:1rem;">
                    <span>تخفیف طرح چندجلسه‌ای:</span>
                    <span id="gtbp-package-discount-amount">0 تومان</span>
                </div>

                <div class="gtbp-total-row" id="gtbp-discount-row" style="display:none; color:#155724; font-size:1rem;">
                    <span>تخفیف کد تخفیف:</span>
                    <span id="gtbp-discount-amount">0 تومان</span>
                </div>

                <div class="gtbp-adjustment-row" id="gtbp-adjustment-row" style="display:none;">
                    <span id="gtbp-adjustment-label">بدهی/طلب:</span>
                    <span id="gtbp-adjustment-value" class="gtbp-adjustment-btn">0 تومان</span>
                </div>

                <div class="gtbp-total-row"><span>مبلغ کل قابل پرداخت:</span>
                <span id="sum_price_total" class="gtbp-total-btn">0 تومان</span></div>

                <!-- Policy notice -->
                <div class="gtbp-policy-notice">
                    <i class="gtbp-policy-icon">!</i>
                    <p>لطفاً پیش از تأیید نهایی توجه فرمایید: پس از ثبت رزرو، <strong>امکان لغو یا تغییر زمان جلسه وجود ندارد.</strong> در صورت عدم حضور در جلسه، آن جلسه به‌طور کامل از اعتبار کاربری کسر خواهد شد.</p>
                </div>

                <?php if ($user_adjustment == 0): ?>
                <div class="gtbp-coupon-box">
                    <h4>آیا کد تخفیف دارید؟</h4>
                    <div class="gtbp-coupon-input-row">
                        <input type="text" id="gtbp-discount-code" placeholder="کد تخفیف را وارد کنید">
                        <button type="button" id="gtbp-apply-coupon" class="gtbp-btn">اعمال کد</button>
                    </div>
                    <div id="gtbp-coupon-msg" style="margin-top:10px;font-size:.875rem;"></div>
                </div>
                <?php else: ?>
                <div class="gtbp-coupon-box">
                    <h4>کد تخفیف (غیرفعال)</h4>
                    <p>به دلیل وجود بدهی یا طلب، امکان استفاده از کد تخفیف وجود ندارد.</p>
                </div>
                <?php endif; ?>

                <?php if ($online_enabled || $card_enabled): ?>
                <div class="gtbp-method-box" id="gtbp-payment-methods-container">
                    <h4>روش پرداخت را انتخاب کنید</h4>
                    <div class="gtbp-pay-choice-grid">
                    <?php if ($online_enabled): ?>
                    <label class="gtbp-pay-choice <?php echo $online_enabled ? 'selected' : ''; ?>">
                        <input type="radio" name="gtbp_payment_method" value="online" <?php echo $online_enabled ? 'checked' : ''; ?> style="display:none;">
                        <div class="gtbp-pay-choice-icon">💳</div>
                        <div class="gtbp-pay-choice-label">آنلاین</div>
                        <div class="gtbp-pay-choice-sub">درگاه امن بانکی</div>
                    </label>
                    <?php endif; ?>
                    <?php if ($card_enabled): ?>
                    <label class="gtbp-pay-choice <?php echo !$online_enabled ? 'selected' : ''; ?>">
                        <input type="radio" name="gtbp_payment_method" value="bank" <?php echo !$online_enabled ? 'checked' : ''; ?> style="display:none;">
                        <div class="gtbp-pay-choice-icon">🏦</div>
                        <div class="gtbp-pay-choice-label">کارت به کارت</div>
                        <div class="gtbp-pay-choice-sub">پرداخت آفلاین</div>
                    </label>
                    <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($card_enabled): ?>
                <div id="gtbp-card-payment-section" class="gtbp-card-payment-box" style="display: none;">
                    <h3 style="color:#dd0000;margin-top:0;">💳 پرداخت کارت به کارت</h3>
                    <div id="gtbp-card-timer" class="gtbp-card-timer">10:00</div>
                    <!-- Fix #6: Expired state shown inline instead of alert -->
                    <div id="gtbp-card-expired" class="gtbp-card-payment-expired" style="display:none;">⌛ مهلت ۱۰ دقیقه‌ای پایان یافت. لطفاً دوباره اقدام به رزرو کنید.</div>
                    <div class="gtbp-card-bank-info">
                        <p style="font-size:1.1rem;">لطفاً مبلغ را به کارت زیر واریز کنید:</p>
                        <div class="gtbp-bank-card"><?php echo esc_html($bank_info['card']); ?></div>
                        <p style="font-size:1.2rem;">به نام: <strong><?php echo esc_html($bank_info['owner']); ?></strong></p>
                    </div>
                    <!-- Fix #7: Side-by-side payment action choice cards -->
                    <p style="color: #666; margin: 20px 0 10px;">پس از واریز، یکی از گزینه‌های زیر را انتخاب کنید:</p>
                    <div style="display:grid;grid-template-columns:1fr<?php echo $show_outside_iran_option ? ' 1fr' : ''; ?>;gap:10px;">
                        <button class="gtbp-btn gtbp-btn-success" id="gtbp-confirm-payment" style="background:linear-gradient(135deg,#dd0000,#a90000 56%,#111);color:#fff;border-radius:12px;padding:14px 10px;display:flex;flex-direction:column;align-items:center;gap:4px;font-size:.9rem;box-shadow:0 8px 20px rgba(221,0,0,.18);">
                            <span style="font-size:1.6rem;">✅</span><strong>پرداخت انجام شد</strong><small style="opacity:.8;font-size:0.72rem;">رزرو من همین الان تایید شود</small>
                        </button>
                        <?php if ($show_outside_iran_option): ?>
                        <button class="gtbp-btn gtbp-btn-warning" id="gtbp-outside-iran" style="background:#f59e0b;color:#fff;border-radius:12px;padding:14px 10px;display:flex;flex-direction:column;align-items:center;gap:4px;font-size:0.9rem;">
                            <span style="font-size:1.6rem;">⏳</span><strong>مهلت ۲۴ ساعته</strong><small style="opacity:.9;font-size:0.72rem;">بعداً پرداخت می‌کنم</small>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php if ($show_outside_iran_option): ?>
                    <div class="warning" style="margin-top: 14px; background: #fff3cd; color: #856404; padding: 12px; border-radius: 10px; font-size:0.85rem;">
                        <strong>توجه:</strong> با گزینه مهلت ۲۴ ساعته، رزرو در انتظار پرداخت می‌ماند. پس از واریز، دکمه «پرداخت انجام شد» را بزنید.
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <button class="gtbp-btn-submit" id="gtbp-submit"><?php echo $online_enabled ? 'انتقال به درگاه پرداخت' : 'ادامه و دریافت اطلاعات کارت به کارت'; ?></button>
            </div>
        </div>

        <div class="gtbp-modal-overlay" id="gtbp-modal">
            <div class="gtbp-modal-box">
                <h4>به سبد افزوده شد</h4>
                <p>ساعت انتخابی شما با موفقیت در سبد رزرو قرار گرفت. آیا مایل هستید کلاس دیگری هم برای روز یا ساعت دیگر انتخاب کنید؟</p>
                <button class="gtbp-modal-btn gtbp-btn-yes" onclick="handleModal(true)">بله، کلاس بعدی</button>
                <button class="gtbp-modal-btn gtbp-btn-no" onclick="handleModal(false)">خیر، مشاهده سبد نهایی</button>
            </div>
        </div>

        <div class="gtbp-modal-overlay" id="gtbp-holiday-modal">
            <div class="gtbp-modal-box">
                <h4 style="margin-bottom:15px;color:#333;">وضعیت تعطیلات</h4>
                <p id="gtbp-holiday-text" style="font-size:1rem;"></p>
                <button class="gtbp-modal-btn gtbp-btn-no" onclick="document.getElementById('gtbp-holiday-modal').style.display='none'">متوجه شدم</button>
            </div>
        </div>

        <script>
        // متغیرهای عمومی
        var appliedCouponCode = '';
        var cart = [];
        try {
            const storedCart = JSON.parse(localStorage.getItem('gtbp_booking_cart') || '[]');
            const todayISO = new Date().toISOString().slice(0,10);
            if (Array.isArray(storedCart)) cart = storedCart.filter(function(item){ return item && item.date && item.date >= todayISO; });
            localStorage.setItem('gtbp_booking_cart', JSON.stringify(cart));
            localStorage.setItem('gtbp_cart_count', String(cart.length));
        } catch(e) { cart = []; }
        var sDate = null;
        var sDateJalali = null;
        var sClass = null;
        var viewJYear = null;
        var viewJMonth = null;
        var calendarLoaded = false;
        var gtbpDiscount = { code: '', percent: 0, amount: 0, payable: 0 };
        var currentCardBookingId = 0;
        var currentCardBookingIds = [];
        var cardTimerInterval = null;
        var userAdjustment = <?php echo intval($user_adjustment); ?>;

        var classesData = <?php echo json_encode($classes); ?>;
        var packagesData = <?php echo json_encode($packages); ?>;
        var gtbpPackagesFirst = <?php echo get_option('gtbp_cards_packages_first', '0') === '1' ? 'true' : 'false'; ?>;
        var ajaxurl = "<?php echo admin_url('admin-ajax.php'); ?>";
        // Fix #1: nonce for all AJAX requests - prevents CSRF attacks.
        var gtbpNonce = "<?php echo wp_create_nonce('gtbp_ajax_nonce'); ?>";
        var faDigits = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹'];
        var toFa = str => str.toString().replace(/\d/g, x => faDigits[x]);
        function persistCart(){
            try {
                const todayISO = new Date().toISOString().slice(0,10);
                cart = cart.filter(function(item){ return item && item.date && item.date >= todayISO; });
                localStorage.setItem('gtbp_booking_cart', JSON.stringify(cart));
                localStorage.setItem('gtbp_cart_count', String(cart.length));
                document.dispatchEvent(new CustomEvent('gtbpCartUpdated', {detail:{count:cart.length}}));
            } catch(e) {}
        }
        function gtbpAutoScrollTo(el){
            if(!el) return;
            setTimeout(function(){ try{ el.scrollIntoView({behavior:'smooth', block:'start'}); }catch(e){ scrollToElement(el); } }, 160);
        }

        // Fix #1: Step progress bar update helper
        function gtbpSetStep(n){
            [1,2,3].forEach(function(i){
                var s=document.getElementById('gstep-'+i);
                var l=document.getElementById('gstep-line-'+i);
                if(!s) return;
                s.classList.toggle('active', i===n);
                s.classList.toggle('done', i<n);
                if(l) l.classList.toggle('done', i<n);
            });
        }

        // Fix #4: Floating +1 toast when slot added
        function gtbpAddToast(x, y){
            var t=document.createElement('div');
            t.className='gtbp-add-toast';
            t.textContent='+۱';
            t.style.left=x+'px'; t.style.top=y+'px';
            document.body.appendChild(t);
            setTimeout(function(){ t.remove(); }, 850);
        }

        // Fix #2: Inline alert helper - uses #gtbp-msg
        function gtbpAlert(msg, type){
            var box=document.getElementById('gtbp-msg');
            if(box){ showMsg(msg, type||'success'); } else { alert(msg); }
        }
        var jMonths = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
        var gMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

        function gToJ(gy, gm, gd) {
            var g_d_m = [0,31,59,90,120,151,181,212,243,273,304,334];
            var jy = (gy <= 1600) ? 0 : 979;
            gy -= (gy <= 1600) ? 621 : 1600;
            var gy2 = (gm > 2) ? (gy + 1) : gy;
            var days = (365 * gy) + parseInt((gy2 + 3) / 4) - parseInt((gy2 + 99) / 100) + parseInt((gy2 + 399) / 400) - 80 + gd + g_d_m[gm - 1];
            jy += 33 * parseInt(days / 12053); days %= 12053;
            jy += 4 * parseInt(days / 1461); days %= 1461;
            if (days > 365) { jy += parseInt((days - 1) / 365); days = (days - 1) % 365; }
            var jm = (days < 186) ? 1 + parseInt(days / 31) : 7 + parseInt((days - 186) / 30);
            var jd = 1 + ((days < 186) ? (days % 31) : ((days - 186) % 30));
            return [jy, jm, jd];
        }

        function jToG(jy, jm, jd) {
            var gy, gm, gd, days;
            jy += 1595;
            days = -355668 + (365 * jy) + (Math.floor(jy / 33) * 8) + Math.floor(((jy % 33) + 3) / 4) + jd;
            if (jm < 7) days += (jm - 1) * 31; else days += ((jm - 7) * 30) + 186;
            gy = 400 * Math.floor(days / 146097); days %= 146097;
            if (days > 36524) { gy += 100 * Math.floor(--days / 36524); days %= 36524; if (days >= 365) days++; }
            gy += 4 * Math.floor(days / 1461); days %= 1461;
            if (days > 365) { gy += Math.floor((days - 1) / 365); days = (days - 1) % 365; }
            gd = days + 1;
            var sal_a = [0,31,((gy%4===0&&gy%100!==0)||(gy%400===0))?29:28,31,30,31,30,31,31,30,31,30,31];
            for (gm = 0; gm < 13 && gd > sal_a[gm]; gm++) gd -= sal_a[gm];
            return [gy, gm, gd];
        }

        function scrollToElement(element) {
            if (element) setTimeout(() => element.scrollIntoView({ behavior: 'smooth', block: 'center' }), 100);
        }

        function startSelection(selectedClass) {
            // Fix #2: replace confirm() with a quick inline check
            var doStart = function(){
                sClass = selectedClass;
                buildClasses();
                document.getElementById('step-2-wrap').style.display = 'block';
                gtbpAutoScrollTo(document.getElementById('title-step-2'));
                scrollToElement(document.getElementById('title-step-2'));
                // Fix #1: advance stepper to step 2
                gtbpSetStep(2);
                if(!calendarLoaded) {
                    let today = new Date();
                    let todayJ = gToJ(today.getFullYear(), today.getMonth()+1, today.getDate());
                    viewJYear = todayJ[0]; viewJMonth = todayJ[1];
                    buildCalendar();
                    calendarLoaded = true;
                }
            };
            if (cart.length > 0) {
                // show inline warning in #gtbp-msg, then proceed after short delay
                showMsg('سبد پاک شد. لطفاً تاریخ و ساعت جدید انتخاب کنید.', 'error');
                cart = []; persistCart();
                doStart();
            } else {
                doStart();
            }
        }

        // رنگ‌های دلخواه هر کارت از تنظیمات مدیریت خوانده می‌شوند.
        function gtbpPaintCard(el, source) {
            if (!source) return;
            if (source.bg) el.style.setProperty('--gtbp-card-bg', source.bg);
            if (source.color) el.style.setProperty('--gtbp-card-color', source.color);
            if (source.accent) el.style.setProperty('--gtbp-card-accent', source.accent);
        }

        function buildClasses() {
            const box = document.getElementById('gtbp-classes');
            const personIcon = `<span class="gtbp-class-group-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><circle cx="12" cy="7" r="4"></circle><path d="M4.5 21v-2a7.5 7.5 0 0 1 15 0v2"></path></svg></span>`;
            const packageIcon = `<span class="gtbp-class-group-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="3" y="8" width="18" height="13" rx="2"></rect><path d="M12 8v13M3 12h18M7.5 8C5 8 4 6.8 4 5.5S5 3 6.5 3C9 3 12 8 12 8M16.5 8C19 8 20 6.8 20 5.5S19 3 17.5 3C15 3 12 8 12 8"></path></svg></span>`;
            box.innerHTML = `<section class="gtbp-class-group" id="gtbp-single-group"><h3 class="gtbp-class-group-title">${personIcon}<span>جلسه‌های تک‌جلسه‌ای</span></h3><div class="gtbp-class-cards-grid" id="gtbp-single-classes"></div></section><section class="gtbp-class-group" id="gtbp-package-group"><h3 class="gtbp-class-group-title">${packageIcon}<span>پکیج‌های چندجلسه‌ای</span></h3><div class="gtbp-class-cards-grid" id="gtbp-package-classes"></div></section>`;
            const singleBox = document.getElementById('gtbp-single-classes');
            const packageBox = document.getElementById('gtbp-package-classes');
            var renderSingle = function () { classesData.forEach(c => {
                let d = document.createElement('div');
                const isActive = !!(sClass && sClass.id === c.id && !sClass.package);
                d.className = `gtbp-class-card ${isActive ? 'active' : ''}`;
                d.setAttribute('role','radio'); d.setAttribute('aria-checked',isActive ? 'true' : 'false'); d.tabIndex = 0;
                d.innerHTML = `<span class="gtbp-card-radio" aria-hidden="true"></span><div class="gtbp-class-name">${c.name}</div><div class="gtbp-class-price">${toFa(parseInt(c.price).toLocaleString())} تومان / هر جلسه</div><div class="gtbp-card-selected">${isActive ? '✓ انتخاب‌شده' : ''}</div>`;
                gtbpPaintCard(d, c);
                d.onclick = () => startSelection({...c});
                d.onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); d.click(); } };
                singleBox.appendChild(d);
            }); };
            var renderPackages = function () { packagesData.forEach(pkg => {
                const baseClass = classesData.find(c => c.id === pkg.class_id);
                if (!baseClass) return;
                let d = document.createElement('div');
                const isActive = !!(sClass && sClass.package && sClass.package.id === pkg.id);
                d.className = `gtbp-class-card gtbp-package-card ${isActive ? 'active' : ''}`;
                d.setAttribute('role','radio'); d.setAttribute('aria-checked',isActive ? 'true' : 'false'); d.tabIndex = 0;
                d.innerHTML = `<span class="gtbp-card-radio" aria-hidden="true"></span><div class="gtbp-class-name">${pkg.name}</div><span class="gtbp-package-badge">${toFa(parseInt(pkg.discount_percent).toLocaleString())}٪ تخفیف</span><div class="gtbp-class-price">${baseClass.name} · ${toFa(parseInt(pkg.session_count).toLocaleString())} جلسه</div><div class="gtbp-class-price">بازه مجاز: ${toFa(parseInt(pkg.window_days).toLocaleString())} روز از اولین رزرو</div><div class="gtbp-card-selected">${isActive ? '✓ انتخاب‌شده' : ''}</div>`;
                gtbpPaintCard(d, pkg);
                d.onclick = () => startSelection({...baseClass, package: pkg});
                d.onkeydown = e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); d.click(); } };
                packageBox.appendChild(d);
            }); };
            renderSingle(); renderPackages();
            document.getElementById('gtbp-single-group').style.display = classesData.length ? '' : 'none';
            document.getElementById('gtbp-package-group').style.display = packageBox.children.length ? '' : 'none';
            if (gtbpPackagesFirst && packageBox.children.length) box.insertBefore(document.getElementById('gtbp-package-group'), document.getElementById('gtbp-single-group'));
        }

        window.shiftMonth = function(dir) {
            viewJMonth += dir;
            if(viewJMonth > 12) { viewJMonth = 1; viewJYear++; }
            if(viewJMonth < 1) { viewJMonth = 12; viewJYear--; }
            document.getElementById('title-step-3').style.display = 'none';
            document.getElementById('gtbp-slots').innerHTML = '';
            buildCalendar();
        };

        function buildCalendar() {
            const box = document.getElementById('gtbp-calendar');
            box.innerHTML = '<div style="grid-column:1/-1;text-align:center;padding:20px;color:#667085;">در حال بارگذاری تقویم...</div>';
            let firstDayG = jToG(viewJYear, viewJMonth, 1);
            let startDay = new Date(firstDayG[0], firstDayG[1]-1, firstDayG[2]);
            let nextMonth = viewJMonth === 12 ? 1 : viewJMonth + 1;
            let nextYear = viewJMonth === 12 ? viewJYear + 1 : viewJYear;
            let nextMonthFirstG = jToG(nextYear, nextMonth, 1);
            let nextMonthStartDay = new Date(nextMonthFirstG[0], nextMonthFirstG[1]-1, nextMonthFirstG[2]);
            let daysInMonth = Math.round((nextMonthStartDay - startDay) / (1000 * 60 * 60 * 24));
            let endDay = new Date(startDay); endDay.setDate(startDay.getDate() + daysInMonth - 1); 
            let gStrStart = startDay.getFullYear() + '-' + String(startDay.getMonth()+1).padStart(2,'0') + '-' + String(startDay.getDate()).padStart(2,'0');
            let gStrEnd = endDay.getFullYear() + '-' + String(endDay.getMonth()+1).padStart(2,'0') + '-' + String(endDay.getDate()).padStart(2,'0');
            const fd = new URLSearchParams(); fd.append('action', 'gtbp_get_calendar_status'); fd.append('start', gStrStart); fd.append('end', gStrEnd);
            fetch(ajaxurl, { method: 'POST', body: fd }).then(res => res.json()).then(data => {
                if(data.success) renderCalendarGrid(startDay, daysInMonth, data.data);
                else box.innerHTML = '<div style="grid-column:1/-1;color:red;text-align:center;">خطا در دریافت اطلاعات تقویم.</div>';
            });
        }

        function renderCalendarGrid(startDay, daysInMonth, statusData) {
            const box = document.getElementById('gtbp-calendar'); box.innerHTML = '';
            const daysFa = ['ش','ی','د','س','چ','پ','ج'];
            for(let i=0;i<7;i++) { let d = document.createElement('div'); d.className='gtbp-day-name'; d.innerText=daysFa[i]; box.appendChild(d); }
            let mName = jMonths[viewJMonth-1];
            document.getElementById('gtbp-month-name').innerText = mName + ' ' + toFa(viewJYear);
            let todayJ = gToJ(new Date().getFullYear(), new Date().getMonth()+1, new Date().getDate());
            let isCurrentMonth = (viewJYear < todayJ[0] || (viewJYear === todayJ[0] && viewJMonth <= todayJ[1]));
            const btnPrev = document.getElementById('btn-prev-month');
            btnPrev.style.opacity = isCurrentMonth ? '0.4' : '1';
            btnPrev.style.cursor = isCurrentMonth ? 'not-allowed' : 'pointer';
            btnPrev.disabled = isCurrentMonth;
            let dow = startDay.getDay(); 
            let offset = dow === 6 ? 0 : dow + 1; 
            for(let i=0;i<offset;i++) { let emptyDiv = document.createElement('div'); emptyDiv.style.visibility='hidden'; box.appendChild(emptyDiv); }
            for(let i=0;i<daysInMonth;i++) {
                let cDate = new Date(startDay); cDate.setDate(startDay.getDate() + i);
                let gStr = cDate.getFullYear() + '-' + String(cDate.getMonth()+1).padStart(2,'0') + '-' + String(cDate.getDate()).padStart(2,'0');
                let jDate = gToJ(cDate.getFullYear(), cDate.getMonth()+1, cDate.getDate());
                let gLabel = cDate.getDate() + ' ' + gMonths[cDate.getMonth()];
                let status = statusData[gStr] || {past:true, active:false, holiday:false, full:false};
                let isDisabled = status.past || !status.active || status.holiday || status.full;
                let div = document.createElement('div');
                div.className = `gtbp-date-box ${isDisabled ? 'disabled' : ''}`;
                if(status.full && !status.past && status.active && !status.holiday) div.title = 'تمامی ساعات در این روز پر شده است';
                else if (!status.active && !status.past) div.title = 'ساعت کاری برای این روز تعریف نشده است';
                let flagsHtml = '';
                if (status.ir_holiday || status.de_holiday) {
                    flagsHtml = '<div class="gtbp-flags-wrap">';
                    let isClosedByAdmin = status.holiday || !status.active;
                    if (status.ir_holiday) {
                        let msg = isClosedByAdmin ? `این روز تعطیل رسمی (${status.ir_holiday}) است.` : `این روز تعطیل رسمی (${status.ir_holiday}) است، اما کلاس‌های شما طبق برنامه برقرار و قابل رزرو است.`;
                        flagsHtml += `<span class="gtbp-flag" title="تعطیل رسمی ایران" onclick="showHolidayMsg(event, '${msg}')">🇮🇷</span>`;
                    }
                    if (status.de_holiday) {
                        let msg = isClosedByAdmin ? `این روز تعطیل رسمی (${status.de_holiday}) است.` : `این روز تعطیل رسمی (${status.de_holiday}) است، اما کلاس‌های شما طبق برنامه برقرار و قابل رزرو است.`;
                        flagsHtml += `<span class="gtbp-flag" title="تعطیل رسمی آلمان" onclick="showHolidayMsg(event, '${msg}')">🇩🇪</span>`;
                    }
                    flagsHtml += '</div>';
                }
                // Fix #3: slot count badge
                var badgeHtml = '';
                if(!isDisabled && status.available_count > 0){
                    badgeHtml = `<span class="gtbp-slot-badge">${toFa(status.available_count)}</span>`;
                }
                div.innerHTML = `${flagsHtml}<div class="gtbp-j-num">${toFa(jDate[2])}</div><div class="gtbp-g-num">${gLabel}</div>${badgeHtml}`;
                if(!isDisabled) {
                    div.onclick = function() {
                        document.querySelectorAll('.gtbp-date-box').forEach(el => el.classList.remove('active'));
                        div.classList.add('active');
                        sDate = gStr; sDateJalali = `${jDate[0]}/${jDate[1]}/${jDate[2]}`;
                        document.getElementById('title-step-3').style.display = 'block';
                        gtbpAutoScrollTo(document.getElementById('title-step-3'));
                        gtbpSetStep(3);
                        loadSlots(gStr);
                        // 4-3: scroll to slots after load
                        setTimeout(function(){
                            var slotsEl=document.getElementById('gtbp-slots');
                            if(slotsEl) slotsEl.scrollIntoView({behavior:'smooth',block:'start'});
                        }, 700);
                    };
                }
                box.appendChild(div);
            }
            // 4-5: if no bookable day in this month, auto-advance to next month
            var freeDays = box.querySelectorAll('.gtbp-date-box:not(.disabled)').length;
            if(freeDays === 0){
                var msg = document.createElement('div');
                msg.style.cssText = 'grid-column:1/-1;text-align:center;padding:14px;color:#667085;font-size:.85rem;';
                msg.textContent = 'این ماه ظرفیتی ندارد؛ به ماه بعد می‌رویم…';
                box.appendChild(msg);
                setTimeout(function(){ shiftMonth(1); }, 1100);
            }
        }

        function loadSlots(dateStr) {
            const box = document.getElementById('gtbp-slots');
            const loader = document.getElementById('gtbp-loader');
            box.innerHTML = ''; loader.style.display = 'block';
            const fd = new URLSearchParams(); fd.append('action', 'gtbp_get_slots'); fd.append('date', dateStr);
            fetch(ajaxurl, { method: 'POST', body: fd }).then(res => res.json()).then(data => {
                loader.style.display = 'none';
                if(!data.success) { box.innerHTML = `<div style="grid-column:1/-1;color:#dd0000;">${data.data}</div>`; return; }
                if(data.data.length === 0) { box.innerHTML = `<div style="grid-column:1/-1;color:#667085;">ساعتی برای این روز تعریف نشده است.</div>`; return; }
                let slots = data.data;
                // 4-2: sort from earliest to latest
                slots.sort(function(a,b){ return a.time.localeCompare(b.time); });
                // 4-4: hide slots booked by others (show slots in own cart as taken)
                let filteredSlots = slots.filter(function(slot){
                    let inCart = cart.some(c => c.date === dateStr && c.time === slot.time);
                    return !slot.booked || inCart;
                });
                if(filteredSlots.length === 0) { box.innerHTML = `<div style="grid-column:1/-1;color:#667085;">تمامی ساعات این روز پر شده‌اند یا در محدوده ۲۴ ساعت آینده هستند.</div>`; return; }
                filteredSlots.forEach(slot => {
                    let inCart = cart.some(c => c.date === dateStr && c.time === slot.time);
                    let isBooked = slot.booked || inCart;
                    let isTooSoon = slot.too_soon && !isBooked; 
                    let div = document.createElement('div');
                    div.className = `gtbp-slot-btn ${slot.period_class || ''} ${(isBooked || isTooSoon) ? 'booked' : ''}`;
                    div.innerHTML = `<span class="gtbp-slot-icon">${slot.icon || '⏰'}</span>${toFa(slot.time)}`;
                    if(slot.private_slot) { div.innerHTML += '<span class="gtbp-slot-private">اختصاصی شما</span>'; }
                    if(isTooSoon) { div.innerHTML += '<span class="gtbp-slot-too-soon">کمتر از ۲۴ ساعت</span>'; div.title = 'زمان این کلاس تا ۲۴ ساعت آینده فرا می‌رسد و قابل رزرو نیست.'; }
                    if(!isBooked && !isTooSoon) {
                        div.onclick = function(e) {
                            cart.push({ date: sDate, jDate: sDateJalali, time: slot.time, class: {id: sClass.id, name: sClass.name, price: sClass.price}, package: sClass.package ? sClass.package : null });
                            persistCart();
                            div.classList.add('booked');
                            // Fix #4: floating +1 toast
                            gtbpAddToast(e.clientX - 20, e.clientY - 40);
                            document.getElementById('gtbp-modal').style.display = 'flex';
                        }
                    }
                    box.appendChild(div);
                });
            });
        }

        function removeFromCart(index) { cart.splice(index, 1); persistCart(); renderCart(); if (cart.length === 0) { const s=document.getElementById('summary-section'); const b=document.getElementById('booking-section'); if(s)s.style.display='none'; if(b)b.style.display='block'; } }
        function backToCalendar() { document.getElementById('summary-section').style.display = 'none'; document.getElementById('booking-section').style.display = 'block'; scrollToElement(document.getElementById('title-step-2')); }
        // نسخه ۱۳.۸: اگر سبد از قبل اقلامی دارد یا کاربر با ?view=cart وارد شده، مستقیم سبد را نمایش بده
        (function autoShowCartIfNeeded(){
            var qs = new URLSearchParams(window.location.search);
            var wantsCart = qs.get('view') === 'cart';
            if ((cart && cart.length > 0) || wantsCart) {
                var b = document.getElementById('booking-section');
                var s = document.getElementById('summary-section');
                if (b && s) { b.style.display='none'; s.style.display='block'; setTimeout(function(){ try{ renderCart(); }catch(e){} scrollToElement(s); }, 60); }
            }
        })();
        function handleModal(wantsMore) {
            document.getElementById('gtbp-modal').style.display = 'none';
            if(wantsMore) { document.querySelectorAll('.gtbp-slot-btn').forEach(el => el.classList.remove('active')); scrollToElement(document.getElementById('title-step-2')); }
            else { document.getElementById('booking-section').style.display = 'none'; document.getElementById('summary-section').style.display = 'block'; scrollToElement(document.getElementById('summary-section')); renderCart(); }
        }
        window.showHolidayMsg = function(e, msg) { e.stopPropagation(); document.getElementById('gtbp-holiday-text').innerText = msg; document.getElementById('gtbp-holiday-modal').style.display = 'flex'; };

        function getCartPackage() {
            if (!cart.length) return null;
            const pkg = cart.find(i => i.package)?.package || null;
            return pkg || null;
        }

        function validatePackageCart() {
            const pkg = getCartPackage();
            if (!pkg) return {valid: true, message: ''};
            if (cart.length !== parseInt(pkg.session_count, 10)) {
                return {valid: false, message: `برای طرح «${pkg.name}» باید دقیقاً ${toFa(pkg.session_count)} جلسه انتخاب کنید. جلسات انتخاب‌شده: ${toFa(cart.length)}`};
            }
            const dates = cart.map(i => i.date).sort();
            const first = new Date(dates[0] + 'T00:00:00');
            const last = new Date(dates[dates.length - 1] + 'T00:00:00');
            const diffDays = Math.floor((last - first) / (1000 * 60 * 60 * 24));
            if (diffDays > parseInt(pkg.window_days, 10)) {
                return {valid: false, message: `همه جلسات این طرح باید حداکثر تا ${toFa(pkg.window_days)} روز پس از اولین رزرو انتخاب شوند.`};
            }
            return {valid: true, message: `طرح «${pkg.name}» کامل است و تخفیف خودکار اعمال می‌شود.`};
        }

        function renderCart() {
            const list = document.getElementById('cart-items-list');
            if (!list) return;
            list.innerHTML = '';
            let subtotal = 0;
            cart.forEach(item => subtotal += parseInt(item.class.price, 10));

            const pkg = getCartPackage();
            const packageDiscountAmount = (pkg && subtotal > 0) ? Math.floor((subtotal * parseFloat(pkg.discount_percent)) / 100) : 0;
            const couponDiscountAmount = (gtbpDiscount.percent > 0 && subtotal > 0) ? Math.floor((subtotal * gtbpDiscount.percent) / 100) : 0;
            const totalDiscountAmount = Math.min(subtotal, packageDiscountAmount + couponDiscountAmount);
            let afterDiscount = subtotal - totalDiscountAmount;
            const adjustmentAmount = userAdjustment;
            let payableTotal = afterDiscount + adjustmentAmount;
            if (payableTotal < 0) payableTotal = 0;
            gtbpDiscount.amount = couponDiscountAmount;
            gtbpDiscount.payable = payableTotal;

            const packageStatus = document.getElementById('gtbp-package-status');
            const packageValidation = validatePackageCart();
            if (packageStatus) {
                if (pkg) {
                    packageStatus.style.display = 'block';
                    packageStatus.style.background = packageValidation.valid ? '#d4edda' : '#fff3cd';
                    packageStatus.style.color = packageValidation.valid ? '#155724' : '#856404';
                    packageStatus.innerHTML = packageValidation.message + `<br>تعداد الزامی: ${toFa(pkg.session_count)} جلسه | بازه مجاز: ${toFa(pkg.window_days)} روز | تخفیف طرح: ${toFa(pkg.discount_percent)}٪`;
                } else {
                    packageStatus.style.display = 'none';
                }
            }

            // Fix #5: per-item price in cart
            const hasAnyDiscount = totalDiscountAmount > 0;
            cart.forEach((item, index) => {
                const itemDiv = document.createElement('div'); itemDiv.className = 'gtbp-cart-item';
                const displayDate = item.jDate || item.date;
                const timeSlot = item.time; const className = item.class.name;
                const itemPrice = parseInt(item.class.price, 10);
                const packageLabel = item.package ? `<span style="background:#b87900;color:#fff;border-radius:999px;padding:2px 7px;font-size:.7rem;">${item.package.name}</span>` : '';
                const priceHtml = hasAnyDiscount
                    ? `<span class="gtbp-cart-item-price"><s>${toFa(itemPrice.toLocaleString())}</s>${toFa(Math.round(itemPrice*(1-(totalDiscountAmount/subtotal))).toLocaleString())} تومان</span>`
                    : `<span class="gtbp-cart-item-price">${toFa(itemPrice.toLocaleString())} تومان</span>`;
                itemDiv.innerHTML = `
                    <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap; flex:1;">
                        <span style="font-weight:700;color:#111;min-width:80px;">${className}</span>
                        ${packageLabel}
                        <span>📅 ${displayDate}</span>
                        <span>⏰ ${toFa(timeSlot)}</span>
                        ${priceHtml}
                    </div>
                    <span class="gtbp-cart-item-del" onclick="removeFromCart(${index})">✕</span>
                `;
                list.appendChild(itemDiv);
            });
            const subtotalEl = document.getElementById('subtotal_price');
            if (subtotalEl) subtotalEl.innerText = toFa(subtotal.toLocaleString()) + ' تومان';

            const packageDiscountRow = document.getElementById('gtbp-package-discount-row');
            const packageDiscountAmountEl = document.getElementById('gtbp-package-discount-amount');
            if (packageDiscountRow && packageDiscountAmountEl) {
                if (packageDiscountAmount > 0) { packageDiscountRow.style.display = 'flex'; packageDiscountAmountEl.innerText = `${toFa(packageDiscountAmount.toLocaleString())} تومان`; }
                else packageDiscountRow.style.display = 'none';
            }

            const discountRow = document.getElementById('gtbp-discount-row');
            const discountAmountEl = document.getElementById('gtbp-discount-amount');
            if (discountRow && discountAmountEl) {
                if (couponDiscountAmount > 0) { discountRow.style.display = 'flex'; discountAmountEl.innerText = `${toFa(couponDiscountAmount.toLocaleString())} تومان`; }
                else discountRow.style.display = 'none';
            }
            const adjustmentRow = document.getElementById('gtbp-adjustment-row');
            const adjustmentValueSpan = document.getElementById('gtbp-adjustment-value');
            const adjustmentLabel = document.getElementById('gtbp-adjustment-label');
            if (adjustmentRow && adjustmentValueSpan && adjustmentLabel) {
                if (adjustmentAmount !== 0) {
                    adjustmentRow.style.display = 'flex';
                    if (adjustmentAmount > 0) {
                        adjustmentLabel.innerText = 'بدهی (اضافی):';
                        adjustmentValueSpan.className = 'gtbp-adjustment-btn gtbp-adjustment-debt-btn';
                        adjustmentValueSpan.innerHTML = `+ ${toFa(adjustmentAmount.toLocaleString())} تومان`;
                    } else {
                        adjustmentLabel.innerText = 'طلب (تخفیف ویژه):';
                        adjustmentValueSpan.className = 'gtbp-adjustment-btn gtbp-adjustment-credit-btn';
                        adjustmentValueSpan.innerHTML = `- ${toFa(Math.abs(adjustmentAmount).toLocaleString())} تومان`;
                    }
                } else adjustmentRow.style.display = 'none';
            }
            const totalEl = document.getElementById('sum_price_total');
            if (totalEl) {
                let additionalText = '';
                if (adjustmentAmount !== 0) additionalText = adjustmentAmount > 0 ? ' (با اعمال بدهی)' : ' (با اعمال طلب)';
                else if (totalDiscountAmount > 0) additionalText = ' (با اعمال تخفیف)';
                if (adjustmentAmount !== 0) {
                    totalEl.className = `gtbp-total-btn ${adjustmentAmount > 0 ? 'gtbp-total-debt-btn' : 'gtbp-total-credit-btn'}`;
                    totalEl.innerHTML = `<span style="text-decoration:line-through; color:#e1e1e1; font-size:0.9rem;">${toFa(subtotal.toLocaleString())} تومان</span><span>${toFa(payableTotal.toLocaleString())} تومان${additionalText}</span>`;
                } else if (totalDiscountAmount > 0) {
                    totalEl.className = 'gtbp-total-btn';
                    totalEl.innerHTML = `<span style="text-decoration:line-through; color:#e1e1e1; font-size:0.9rem;">${toFa(subtotal.toLocaleString())} تومان</span><span>${toFa(payableTotal.toLocaleString())} تومان${additionalText}</span>`;
                } else {
                    totalEl.className = 'gtbp-total-btn';
                    totalEl.innerHTML = `<span>${toFa(payableTotal.toLocaleString())} تومان</span>`;
                }
            }
            const submitEl = document.getElementById('gtbp-submit');
            if (submitEl) submitEl.disabled = cart.length === 0 || !packageValidation.valid;
            const paymentRadiosAll = document.querySelectorAll('input[name="gtbp_payment_method"]');
            if (paymentRadiosAll.length) {
                paymentRadiosAll.forEach(r => { r.disabled = false; });
                if (!document.querySelector('input[name="gtbp_payment_method"]:checked')) {
                    paymentRadiosAll[0].checked = true;
                }
            }
            const paymentMethodsDiv = document.getElementById('gtbp-payment-methods-container');
            const submitBtn = document.getElementById('gtbp-submit');
            if (payableTotal <= 0) {
                if (paymentMethodsDiv) paymentMethodsDiv.style.display = 'none';
                if (submitBtn) { submitBtn.innerText = 'ثبت رزرو'; submitBtn.style.background = '#28a745'; }
            } else {
                if (paymentMethodsDiv) paymentMethodsDiv.style.display = 'block';
                if (submitBtn) {
                    const onlineEnabled = <?php echo $online_enabled ? 'true' : 'false'; ?>;
                    const selectedRadio = document.querySelector('input[name="gtbp_payment_method"]:checked');
                    const paymentMethod = selectedRadio ? selectedRadio.value : 'online';
                    submitBtn.innerText = paymentMethod === 'online' && onlineEnabled ? 'انتقال به درگاه پرداخت' : 'ادامه و دریافت اطلاعات کارت به کارت';
                    submitBtn.style.background = packageValidation.valid ? '#8B0000' : '#ccc';
                }
            }
        }

        function startCardTimer(expiresAt) {
            if (cardTimerInterval) clearInterval(cardTimerInterval);
            const timerEl = document.getElementById('gtbp-card-timer');
            const expiredEl = document.getElementById('gtbp-card-expired');
            const expiryTimestamp = Number(expiresAt);
            if (isNaN(expiryTimestamp)) {
                timerEl.textContent = '00:00';
                console.error('Invalid expiry timestamp:', expiresAt);
                return;
            }
            const update = () => {
                const now = Math.floor(Date.now() / 1000);
                const remaining = Math.max(0, expiryTimestamp - now);
                const minutes = Math.floor(remaining / 60);
                const seconds = remaining % 60;
                timerEl.textContent = String(minutes).padStart(2, '0') + ':' + String(seconds).padStart(2, '0');
                // Fix #6: urgency pulse when < 2 minutes
                timerEl.classList.toggle('gtbp-timer-urgent', remaining > 0 && remaining < 120);
                if (remaining <= 0) {
                    clearInterval(cardTimerInterval);
                    timerEl.style.display = 'none';
                    // Fix #6: inline expired message instead of alert
                    if (expiredEl) expiredEl.style.display = 'block';
                }
            };
            update();
            cardTimerInterval = setInterval(update, 1000);
        }

        function showMsg(message, type = 'success') {
            const box = document.getElementById('gtbp-msg');
            if (!box) { alert(message); return; }
            box.className = 'gtbp-msg ' + (type === 'error' ? 'gtbp-msg-error' : 'gtbp-msg-success');
            box.textContent = message;
            box.style.display = 'block';
            scrollToElement(box);
        }

        document.addEventListener('DOMContentLoaded', function() {
            buildClasses();
            // Fix #1: init stepper
            gtbpSetStep(1);

            // Fix #7: Payment choice card selection highlight
            document.querySelectorAll('.gtbp-pay-choice').forEach(function(card){
                card.addEventListener('click', function(){
                    document.querySelectorAll('.gtbp-pay-choice').forEach(function(c){ c.classList.remove('selected'); });
                    this.classList.add('selected');
                    var radio=this.querySelector('input[type="radio"]');
                    if(radio){ radio.checked=true; radio.dispatchEvent(new Event('change',{bubbles:true})); }
                });
            });

            const paymentRadios = document.querySelectorAll('input[name="gtbp_payment_method"]');
            if (paymentRadios.length) {
                paymentRadios.forEach(radio => {
                    radio.addEventListener('change', function() {
                        const submitBtn = document.getElementById('gtbp-submit');
                        const payableTotal = parseInt(document.getElementById('sum_price_total')?.innerText?.replace(/[^0-9]/g, '') || '0');
                        if (payableTotal <= 0) return;
                        if(this.value === 'bank') {
                            submitBtn.innerText = 'ادامه و دریافت اطلاعات کارت به کارت';
                        } else {
                            submitBtn.innerText = 'انتقال به درگاه پرداخت';
                        }
                    });
                });
            }

            document.getElementById('gtbp-submit').addEventListener('click', function() {
                if(cart.length === 0) return;
                const pkgValidation = validatePackageCart();
                if (!pkgValidation.valid) { showMsg(pkgValidation.message, 'error'); renderCart(); return; }
                const btn = this; btn.disabled = true; btn.innerText = 'در حال پردازش...';
                const fd = new URLSearchParams();
                fd.append('action', 'gtbp_submit_cart');
                fd.append('cart', JSON.stringify(cart));
                let appliedCode = '';
                const discountInput = document.getElementById('gtbp-discount-code');
                if (discountInput) {
                    if (discountInput.dataset.appliedCode) appliedCode = discountInput.dataset.appliedCode.trim();
                    else if (gtbpDiscount.code) appliedCode = gtbpDiscount.code.trim();
                }
                if (appliedCode) fd.append('discount_code', appliedCode);
                let paymentMethod = 'online';
                const selectedRadio = document.querySelector('input[name="gtbp_payment_method"]:checked');
                if (selectedRadio) paymentMethod = selectedRadio.value;
                fd.append('payment_method', paymentMethod);
                fd.append('user_adjustment', userAdjustment);
                fd.append('nonce', gtbpNonce); // Fix #1
                fetch(ajaxurl, { method: 'POST', body: fd })
                .then(res => res.json())
                .then(data => {
                    if(data.success) {
                        if (data.data && data.data.type === 'online') {
                            persistCart(); localStorage.removeItem('gtbp_booking_cart'); localStorage.setItem('gtbp_cart_count','0'); document.dispatchEvent(new CustomEvent('gtbpCartUpdated',{detail:{count:0}})); window.location.href = data.data.redirect_url;
                        } else if (data.data && data.data.type === 'bank') {
                            currentCardBookingId = data.data.booking_id;
                            currentCardBookingIds = Array.isArray(data.data.booking_ids) ? data.data.booking_ids : (currentCardBookingId ? [currentCardBookingId] : []);
                            const expiresAt = data.data.expires;
                            btn.style.display = 'none';
                            const cardSection = document.getElementById('gtbp-card-payment-section');
                            if (cardSection) cardSection.style.display = 'block';
                            startCardTimer(expiresAt);
                        } else if (data.data && data.data.type === 'direct') {
                            // Fix #8: Zero-payable success screen instead of alert + reload
                            localStorage.removeItem('gtbp_booking_cart'); localStorage.setItem('gtbp_cart_count','0'); document.dispatchEvent(new CustomEvent('gtbpCartUpdated',{detail:{count:0}}));
                            var ws = document.getElementById('gtbp-wrapper') || document.querySelector('.gtbp-wrapper');
                            var ss = document.getElementById('gtbp-success-screen');
                            if (ss) {
                                document.getElementById('booking-section') && (document.getElementById('booking-section').style.display = 'none');
                                document.getElementById('summary-section') && (document.getElementById('summary-section').style.display = 'none');
                                document.getElementById('gtbp-stepper') && (document.getElementById('gtbp-stepper').style.display = 'none');
                                ss.style.display = 'block';
                                gtbpAutoScrollTo(ss);
                            } else { location.reload(); }
                        } else {
                            // Fix #8: same success screen for unknown direct type
                            localStorage.removeItem('gtbp_booking_cart'); localStorage.setItem('gtbp_cart_count','0'); document.dispatchEvent(new CustomEvent('gtbpCartUpdated',{detail:{count:0}}));
                            var ss2 = document.getElementById('gtbp-success-screen');
                            if (ss2) {
                                document.getElementById('booking-section') && (document.getElementById('booking-section').style.display = 'none');
                                document.getElementById('summary-section') && (document.getElementById('summary-section').style.display = 'none');
                                document.getElementById('gtbp-stepper') && (document.getElementById('gtbp-stepper').style.display = 'none');
                                ss2.style.display = 'block';
                                gtbpAutoScrollTo(ss2);
                            } else { location.reload(); }
                        }
                    } else {
                        // Fix #2: inline error instead of alert
                        gtbpAlert(data.data, 'error');
                        btn.disabled = false;
                        const selectedRadio = document.querySelector('input[name="gtbp_payment_method"]:checked');
                        const paymentMethod = selectedRadio ? selectedRadio.value : 'online';
                        const onlineEnabled = <?php echo $online_enabled ? 'true' : 'false'; ?>;
                        btn.innerText = (paymentMethod === 'online' && onlineEnabled) ? 'انتقال به درگاه پرداخت' : 'ادامه و دریافت اطلاعات کارت به کارت';
                    }
                })
                .catch(error => {
                    console.error(error);
                    btn.disabled = false;
                    const selectedRadio = document.querySelector('input[name="gtbp_payment_method"]:checked');
                    const paymentMethod = selectedRadio ? selectedRadio.value : 'online';
                    const onlineEnabled = <?php echo $online_enabled ? 'true' : 'false'; ?>;
                    btn.innerText = (paymentMethod === 'online' && onlineEnabled) ? 'انتقال به درگاه پرداخت' : 'ادامه و دریافت اطلاعات کارت به کارت';
                });
            });

            const confirmBtn = document.getElementById('gtbp-confirm-payment');
            if (confirmBtn) {
                confirmBtn.addEventListener('click', function() {
                    if (!currentCardBookingId && (!currentCardBookingIds || !currentCardBookingIds.length)) return;
                    const confirmPayload = new URLSearchParams({ action: 'gtbp_confirm_card_payment', nonce: gtbpNonce }); // Fix #1
                    confirmPayload.append('booking_ids', JSON.stringify(currentCardBookingIds && currentCardBookingIds.length ? currentCardBookingIds : [currentCardBookingId]));
                    fetch(ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: confirmPayload
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            // Fix #2: success screen instead of alert + reload
                            localStorage.removeItem('gtbp_booking_cart'); localStorage.setItem('gtbp_cart_count','0'); document.dispatchEvent(new CustomEvent('gtbpCartUpdated',{detail:{count:0}}));
                            var ss=document.getElementById('gtbp-success-screen');
                            if(ss){
                                document.getElementById('gtbp-card-payment-section') && (document.getElementById('gtbp-card-payment-section').style.display='none');
                                document.getElementById('summary-section') && (document.getElementById('summary-section').style.display='none');
                                document.getElementById('gtbp-stepper') && (document.getElementById('gtbp-stepper').style.display='none');
                                ss.querySelector('h3').textContent='پرداخت تایید شد!';
                                ss.querySelector('p').textContent='رزرو شما قطعی شد. ایمیل تایید برای شما ارسال گردید.';
                                ss.style.display='block'; gtbpAutoScrollTo(ss);
                            } else { location.reload(); }
                        } else {
                            gtbpAlert(data.data || 'خطا در تایید پرداخت', 'error');
                        }
                    });
                });
            }

            const outsideBtn = document.getElementById('gtbp-outside-iran');
            if (outsideBtn) {
                outsideBtn.addEventListener('click', function() {
                    if (!currentCardBookingId && (!currentCardBookingIds || !currentCardBookingIds.length)) return;
                    const outsidePayload = new URLSearchParams({ action: 'gtbp_outside_iran', nonce: gtbpNonce }); // Fix #1
                    outsidePayload.append('booking_ids', JSON.stringify(currentCardBookingIds && currentCardBookingIds.length ? currentCardBookingIds : [currentCardBookingId]));
                    fetch(ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: outsidePayload
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            // Fix #2: inline success instead of alert + reload
                            var ss=document.getElementById('gtbp-success-screen');
                            if(ss){
                                document.getElementById('gtbp-card-payment-section') && (document.getElementById('gtbp-card-payment-section').style.display='none');
                                document.getElementById('summary-section') && (document.getElementById('summary-section').style.display='none');
                                document.getElementById('gtbp-stepper') && (document.getElementById('gtbp-stepper').style.display='none');
                                ss.querySelector('h3').textContent='مهلت ۲۴ ساعته ثبت شد';
                                ss.querySelector('p').textContent='رزرو شما ۲۴ ساعت در انتظار پرداخت می‌ماند. پس از واریز، اطلاع دهید تا رزرو تایید شود.';
                                ss.style.display='block'; gtbpAutoScrollTo(ss);
                            } else { location.reload(); }
                        } else {
                            gtbpAlert(data.data || 'خطا در ثبت', 'error');
                        }
                    });
                });
            }

            document.querySelectorAll('.confirm-pending-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const bookingId = this.dataset.id;
                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: new URLSearchParams({ action: 'gtbp_confirm_pending_payment', booking_id: bookingId, nonce: gtbpNonce }) // Fix #1
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            // Fix #2: inline success instead of alert
                            gtbpAlert('پرداخت شما تایید شد.', 'success');
                            setTimeout(function(){ location.reload(); }, 1800);
                        } else {
                            gtbpAlert(data.data || 'خطا در تایید', 'error');
                        }
                    });
                });
            });
        });

        jQuery(document).on('click', '#gtbp-apply-coupon', function() {
            let code = jQuery('#gtbp-discount-code').val().trim();
            if(!code) return;
            jQuery('#gtbp-coupon-msg').text('در حال بررسی...').css('color', '#666');
            jQuery.post(ajaxurl, { action: 'gtbp_validate_coupon', coupon_code: code }, function(res) {
                if(res.success) {
                    appliedCouponCode = code;
                    jQuery('#gtbp-coupon-msg').text(res.data.message).css('color', 'green');
                    gtbpDiscount.code = code;
                    gtbpDiscount.percent = parseFloat(res.data.percent) || 0;
                    const discountInput = document.getElementById('gtbp-discount-code');
                    discountInput.dataset.appliedCode = code;
                    discountInput.dataset.discountPercent = gtbpDiscount.percent;
                    renderCart();
                } else {
                    appliedCouponCode = '';
                    jQuery('#gtbp-coupon-msg').text(res.data.message).css('color', 'red');
                    gtbpDiscount.code = ''; gtbpDiscount.percent = 0;
                    const discountInput = document.getElementById('gtbp-discount-code');
                    delete discountInput.dataset.appliedCode; delete discountInput.dataset.discountPercent;
                    renderCart();
                }
            });
        });

        var discountField = document.getElementById('gtbp-discount-code');
        if (discountField) {
            discountField.addEventListener('input', function () {
                if (this.dataset.appliedCode && this.value.trim() !== this.dataset.appliedCode) {
                    gtbpDiscount.code = ''; gtbpDiscount.percent = 0;
                    delete this.dataset.appliedCode; delete this.dataset.discountPercent;
                    document.getElementById('gtbp-coupon-msg').innerText = '';
                    renderCart();
                }
            });
        }
        </script>
        <?php
        return ob_get_clean();
    }

    /* ==========================================================================
       USER RECORDINGS SHORTCODE
       ========================================================================== */
    public function render_user_recordings() {
        $this->ensure_table_columns();
        
        if (!is_user_logged_in()) {
            return '<p style="font-family: IRANSansXFaNum, Tahoma; color:red; padding:20px;">برای مشاهده ضبط کلاس‌ها لطفا وارد سایت شوید.</p>';
        }
        $user = wp_get_current_user(); $email = $user->user_email;
        global $wpdb;
        // نسخه ۱۳.۸: نمایش هم ضبط BBB و هم ضبط Google Meet (خودکار یا دستی)
        $booking_cols = (array) $wpdb->get_col("SHOW COLUMNS FROM {$this->table_name}");
        $has_meet_col = in_array('meet_recording_link', $booking_cols, true);
        $has_rmt_col  = in_array('rmt_recording_link', $booking_cols, true);
        $has_bbb_presentation = in_array('bbb_presentation_link', $booking_cols, true);
        $has_bbb_video = in_array('bbb_video_link', $booking_cols, true);
        $sess = $wpdb->prefix . 'gls_sessions';
        $has_sess = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $sess)) === $sess;
        $sess_cols = $has_sess ? (array)$wpdb->get_col("SHOW COLUMNS FROM {$sess}") : [];
        $has_rmt_session = in_array('rmt_video_url', $sess_cols, true) && in_array('rmt_video_url_source', $sess_cols, true);

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE email = %s ORDER BY booking_date DESC, booking_time DESC",
            $email
        ));

        $items = [];
        foreach ($rows as $row) {
            $provider = isset($row->class_provider) ? (string)$row->class_provider : '';
            // رومیت: ستون اختصاصی خودش، بدون مخلوط‌شدن با ستون legacy مربوط به BBB.
            if ($has_rmt_col && !empty($row->rmt_recording_link)) {
                $items[] = ['label' => 'رومیت', 'color' => '#1d4ed8', 'url' => $row->rmt_recording_link, 'row' => $row];
            }
            // BBB
            if ($provider !== 'roomeet') {
                $presentation = $has_bbb_presentation ? trim((string)($row->bbb_presentation_link ?? '')) : '';
                $video = $has_bbb_video ? trim((string)($row->bbb_video_link ?? '')) : '';
                if ($presentation !== '') $items[] = ['label' => 'BBB Presentation', 'action'=>'مشاهده', 'color' => '#8B0000', 'url' => $presentation, 'row' => $row];
                if ($video !== '' && $video !== $presentation) $items[] = ['label' => 'BBB Video', 'action'=>'دانلود / پخش', 'color' => '#0f766e', 'url' => $video, 'row' => $row];
                if ($presentation === '' && $video === '' && !empty($row->roomeet_recording_link)) {
                    $items[] = ['label' => 'BigBlueButton', 'action'=>'مشاهده', 'color' => '#8B0000', 'url' => $row->roomeet_recording_link, 'row' => $row];
                }
            }
            // Meet (روی رزرو)
            if ($has_meet_col && !empty($row->meet_recording_link)) {
                $items[] = ['label' => 'Google Meet', 'color' => '#34a853', 'url' => $row->meet_recording_link, 'row' => $row];
            }
            // Meet (دستی روی جلسه آموزشی)
            if ($has_sess) {
                $rmt_select = $has_rmt_session ? ', rmt_video_url, rmt_video_url_source' : '';
                $s = $wpdb->get_row($wpdb->prepare("SELECT meet_video_url, meet_video_url_source, video_url, video_url_source {$rmt_select} FROM {$sess} WHERE booking_id = %d", intval($row->id)));
                if ($s && !empty($s->meet_video_url) && (string)$s->meet_video_url_source === 'manual') {
                    $items[] = ['label' => 'Google Meet (دستی)', 'color' => '#0f766e', 'url' => $s->meet_video_url, 'row' => $row];
                }
                if ($has_rmt_session && $s && !empty($s->rmt_video_url) && (string)$s->rmt_video_url_source === 'manual' && $s->rmt_video_url !== ($row->rmt_recording_link ?? '')) {
                    $items[] = ['label' => 'رومیت (دستی)', 'color' => '#1e40af', 'url' => $s->rmt_video_url, 'row' => $row];
                }
                if ($s && !empty($s->video_url) && (string)$s->video_url_source === 'manual' && $s->video_url !== ($row->roomeet_recording_link ?? '')) {
                    $items[] = ['label' => 'BBB (دستی)', 'color' => '#7c2d12', 'url' => $s->video_url, 'row' => $row];
                }
            }
        }

        if (empty($items)) {
            return '<div style="font-family: IRANSansXFaNum, Tahoma; background:#f5f5f5; padding:20px; border-radius:8px; text-align:center;">
                <p>📹 هنوز ضبطی برای کلاس‌های شما آماده نشده است.</p>
                <p style="font-size:0.9rem; color:#666;">ضبط‌ها معمولاً چند ساعت پس از کلاس آماده می‌شوند.</p>
            </div>';
        }

        $output = '<div style="font-family: IRANSansXFaNum, Tahoma; direction: rtl; max-width: 820px; margin: 0 auto;">';
        $output .= '<h3 style="color: #8B0000; margin-bottom: 20px;">ضبط‌های کلاس‌های شما</h3>';
        foreach ($items as $it) {
            $row = $it['row'];
            list($gy,$gm,$gd) = explode('-', $row->booking_date);
            $jalali = $this->internal_gregorian_to_jalali($gy,$gm,$gd);
            $j_date = $jalali[0] . '/' . sprintf('%02d',$jalali[1]) . '/' . sprintf('%02d',$jalali[2]);
            $output .= '<div class="gtbp-recording-item" style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:#fff;border:1px solid #eee;border-radius:8px;margin-bottom:8px;gap:12px;flex-wrap:wrap;">';
            $output .= '<div><strong style="color:#8B0000;">' . esc_html($row->class_name) . '</strong><br>';
            $output .= '<span style="color:#555;">📅 ' . esc_html($j_date) . '</span> | ';
            $output .= '<span style="color:#555;">⏰ ' . esc_html($row->booking_time) . '</span></div>';
            $output .= '<div style="display:flex;align-items:center;gap:8px;">';
            $output .= '<span style="background:' . esc_attr($it['color']) . ';color:#fff;padding:3px 8px;border-radius:12px;font-size:0.75rem;">' . esc_html($it['label']) . '</span>';
            $action = isset($it['action']) ? $it['action'] : 'مشاهده ضبط';
            $output .= '<a href="' . esc_url($it['url']) . '" target="_blank" rel="noopener" class="gtbp-recording-link" style="background:#111;color:#fff;padding:6px 12px;border-radius:6px;text-decoration:none;font-size:0.85rem;">' . esc_html($action) . '</a>';
            $output .= '</div></div>';
        }
        $output .= '</div>';
        return $output;
    }

    /* ==========================================================================
       USER BOOKINGS SHORTCODE (با نمایش لینک فقط ۳ دقیقه قبل از شروع کلاس)
       ========================================================================== */
    public function render_user_bookings() {
        $this->ensure_table_columns();
        
        if (!is_user_logged_in()) return '<p style="font-family: IRANSansXFaNum, Tahoma; color:red;">برای مشاهده کلاس‌های رزرو شده لطفا وارد سایت شوید.</p>';
        $user = wp_get_current_user(); $email = $user->user_email;
        global $wpdb;
        $today = current_time('Y-m-d');
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE email = %s AND booking_date >= %s AND status = 'confirmed' ORDER BY booking_date ASC, booking_time ASC",
            $email, $today
        ));
        if (empty($results)) return '<p style="padding:15px; background:#f5f5f5; border-radius:5px; font-family: IRANSansXFaNum, Tahoma;">شما هیچ کلاسی برای روزهای آینده رزرو نکرده‌اید.</p>';
        
        $tz = new DateTimeZone('Asia/Tehran');
        $now = new DateTime('now', $tz);
        $now_ts = $now->getTimestamp();
        
        $output = '<div style="font-family: IRANSansXFaNum, Tahoma, sans-serif; direction: rtl; color: #000;"><ul style="list-style-type: none; padding: 0; margin: 0;">';
        foreach ($results as $index => $row) {
            list($gy,$gm,$gd) = explode('-',$row->booking_date);
            $jalali = $this->internal_gregorian_to_jalali($gy,$gm,$gd);
            $j_date = $jalali[0] . '/' . sprintf('%02d',$jalali[1]) . '/' . sprintf('%02d',$jalali[2]);
            $time_parts = explode('-',$row->booking_time);
            $start_time = trim($time_parts[0]); 
            $end_time = trim($time_parts[1]);
            $formatted_time_range = $start_time . '-' . $end_time;
            
            // محاسبه زمان شروع و پایان کلاس (timestamp مطلق به وقت رسمی ایران)
            $class_start_dt = new DateTime($row->booking_date . ' ' . $start_time . ':00', $tz);
            $class_start_ts = $class_start_dt->getTimestamp();
            $class_end_ts = $class_start_ts + 3600; // پیش‌فرض یک ساعت
            if (preg_match('/^\d{1,2}:\d{2}$/', $end_time)) {
                try { $class_end_ts = (new DateTime($row->booking_date . ' ' . $end_time . ':00', $tz))->getTimestamp(); } catch (Exception $e) {}
                if ($class_end_ts <= $class_start_ts) $class_end_ts = $class_start_ts + 3600;
            }

            // نسخه ۱۴.۵: پنجره‌ی لینک = از ۳ دقیقه قبل از شروع تا پایان کلاس (timestamp مطلق؛ برای خارج از ایران هم درست است)
            $show_link = ($now_ts >= ($class_start_ts - 3*60) && $now_ts <= $class_end_ts);
            $class_over = ($now_ts > $class_end_ts);

            $output .= '<li style="margin-bottom: 12px; padding: 8px 0; border-bottom: 1px solid #eee;">';
            $output .= '<span style="color: #8B0000; font-weight:bold;">' . esc_html($row->class_name) . '</span> | ';
            $output .= '<span>' . esc_html($j_date) . '</span> | ';
            $output .= '<span>' . esc_html($formatted_time_range) . '</span>';
            // ستون roomeet_join_link لینک عمومی شاگرد برای هر سرویس است
            $join_link = !empty($row->roomeet_join_link) ? $row->roomeet_join_link : (isset($row->meet_join_link) ? (string)$row->meet_join_link : '');
            if ($join_link !== '') {
                if ($show_link) {
                    $output .= '<br><a href="' . esc_url($join_link) . '" target="_blank" style="display:inline-block; margin-top:5px; background:#28a745; color:white; padding:5px 10px; border-radius:4px; text-decoration:none; font-size:0.85rem;">🔗 لینک ورود به کلاس</a>';
                } elseif ($class_over) {
                    $output .= '<br><span style="display:inline-block; margin-top:5px; color:#9ca3af; font-size:0.85rem;">⛔ زمان این کلاس به پایان رسیده است</span>';
                } else {
                    $output .= '<br><span style="display:inline-block; margin-top:5px; color:#ff9800; font-size:0.85rem;">⏳ لینک کلاس ۳ دقیقه قبل از شروع فعال می‌شود</span>';
                }
            }
            $output .= '</li>';
            if ($index < count($results) - 1) $output .= '<li style="margin: 0; padding: 0;"><hr style="border: 0; border-top: 1px dashed #aaa; margin: 5px 0;"></li>';
        }
        $output .= '</ul></div>';
        return $output;
    }

    /* ==========================================================================
       AJAX LOGIC (با مدیریت پرداخت صفر و به‌روزرسانی طلب)
       ========================================================================== */
    public function ajax_get_calendar_status() {
        $start = sanitize_text_field($_POST['start']);
        $end = sanitize_text_field($_POST['end']);
        $holidays = $this->get_holidays();
        $hours = $this->get_working_hours();
        
        global $wpdb;
        $bookings = $wpdb->get_results($wpdb->prepare(
            "SELECT booking_date, booking_time FROM {$this->table_name} WHERE booking_date >= %s AND booking_date <= %s AND status != 'cancelled'",
            $start, $end
        ));
        $booked_counts = [];
        foreach($bookings as $b) {
            if(!isset($booked_counts[$b->booking_date])) $booked_counts[$b->booking_date] = 0;
            $booked_counts[$b->booking_date]++;
        }
        $ir_fixed_holidays = [
            '01-01'=>'عید نوروز','01-02'=>'عید نوروز','01-03'=>'عید نوروز','01-04'=>'عید نوروز',
            '01-12'=>'روز جمهوری اسلامی','01-13'=>'روز طبیعت',
            '03-14'=>'رحلت امام خمینی','03-15'=>'قیام ۱۵ خرداد',
            '11-22'=>'پیروزی انقلاب اسلامی','12-29'=>'روز ملی شدن صنعت نفت'
        ];
        $de_fixed_holidays = [
            '01-01'=>'Neujahr (سال نو میلادی)','05-01'=>'Tag der Arbeit (روز کارگر)',
            '10-03'=>'Tag der Deutschen Einheit (روز اتحاد آلمان)','12-25'=>'1. Weihnachtstag (روز اول کریسمس)',
            '12-26'=>'2. Weihnachtstag (روز دوم کریسمس)'
        ];
        $tz = new DateTimeZone('Asia/Tehran');
        $today_dt = new DateTime('today', $tz);
        $today_str = $today_dt->format('Y-m-d');
        $result = [];
        $current = strtotime($start); $last = strtotime($end);
        while($current <= $last) {
            $date_str = date('Y-m-d', $current);
            $dow = date('w', $current);
            $is_past = $date_str < $today_str;
            $is_holiday = in_array($date_str, $holidays);
            $g_y = (int)date('Y',$current); $g_m = (int)date('m',$current); $g_d = (int)date('d',$current);
            $j_parts = $this->gregorian_to_jalali($g_y,$g_m,$g_d);
            $j_md = sprintf('%02d-%02d',$j_parts[1],$j_parts[2]);
            $g_md = sprintf('%02d-%02d',$g_m,$g_d);
            $ir_h_name = isset($ir_fixed_holidays[$j_md]) ? $ir_fixed_holidays[$j_md] : null;
            $de_h_name = isset($de_fixed_holidays[$g_md]) ? $de_fixed_holidays[$g_md] : null;
            $day_settings = null;
            foreach($hours as $h) { if($h['day'] == $dow) { $day_settings = $h; break; } }
            $visible_slots = $this->get_visible_slots_for_user_day($day_settings, $dow, get_current_user_id());
            $is_active = !empty($visible_slots);
            $is_full = false;
            $available_count = 0;
            if($is_active && !$is_holiday && !$is_past) {
                $booked_for_day = $wpdb->get_col($wpdb->prepare(
                    "SELECT booking_time FROM {$this->table_name} WHERE booking_date = %s AND status != 'cancelled'",
                    $date_str
                ));
                // نسخه ۱۴.۲: شمارش «واقعاً قابل رزرو» = بدون هم‌پوشانی زمانی + رعایت قانون ۲۴ ساعت
                $now_cal_ts = (new DateTime('now', $tz))->getTimestamp();
                foreach ($visible_slots as $slot_item) {
                    if ($this->slot_conflicts_with_bookings($date_str, $slot_item, 0, $booked_for_day)) continue;
                    $ss = explode('-', $slot_item)[0];
                    try {
                        $sdt = new DateTime($date_str . ' ' . trim($ss) . ':00', $tz);
                        if (($sdt->getTimestamp() - $now_cal_ts) / 3600 < 24) continue;
                    } catch (Exception $e) { continue; }
                    $available_count++;
                }
                if($available_count <= 0) $is_full = true;
            }
            $result[$date_str] = [
                'past' => $is_past, 'holiday' => $is_holiday, 'active' => $is_active,
                'full' => $is_full, 'ir_holiday' => $ir_h_name, 'de_holiday' => $de_h_name,
                'available_count' => $available_count,
            ];
            $current = strtotime('+1 day', $current);
        }
        wp_send_json_success($result);
    }

        public function ajax_get_slots() {
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : '';
        if (empty($date)) wp_send_json_error('تاریخ معتبر نیست.');

        $admin_manual = isset($_POST['admin_manual']) && $_POST['admin_manual'] === '1' && current_user_can('manage_options');

        $holidays = $this->get_holidays();
        if (!$admin_manual && in_array($date, $holidays)) wp_send_json_error('این روز به دلیل تعطیلات بسته است.');

        $day_of_week = date('w', strtotime($date));
        $hours = $this->get_working_hours();
        $day_settings = null;
        foreach ($hours as $h) { if ($h['day'] == $day_of_week) { $day_settings = $h; break; } }
        $slots_array = $this->get_visible_slots_for_user_day($day_settings, $day_of_week, get_current_user_id());
        if (empty($slots_array)) {
            wp_send_json_error('کلاسی در این روز برگزار نمی‌شود.');
        }
        global $wpdb;
        $booked = $wpdb->get_col($wpdb->prepare(
            "SELECT booking_time FROM $this->table_name WHERE booking_date = %s AND status != 'cancelled'",
            $date
        ));
        $tz = new DateTimeZone('Asia/Tehran');
        $now = new DateTime('now', $tz);
        $now_ts = $now->getTimestamp();
        $result = [];
        foreach ($slots_array as $s) {
            $slot_start_time = explode('-', $s)[0];
            $slot_dt = new DateTime($date . ' ' . $slot_start_time . ':00', $tz);
            $slot_ts = $slot_dt->getTimestamp();
            $diff_hours = ($slot_ts - $now_ts) / 3600;
            $is_too_soon = $admin_manual ? false : ($diff_hours < 24);
            $meta = $this->get_slot_period_meta($s);
            // نسخه ۱۴.۱: اسلات اشغال است اگر با هر رزرو موجود همان روز هم‌پوشانی زمانی داشته باشد (نه فقط تطابق دقیق رشته)
            $is_booked = $this->slot_conflicts_with_bookings($date, $s, 0, $booked);
            $result[] = [
                'time' => $s,
                'booked' => $is_booked,
                'too_soon' => $is_too_soon,
                'period_class' => $meta['class'],
                'icon' => $meta['icon'],
                'period' => $meta['period'],
                'private_slot' => $this->is_user_private_slot(get_current_user_id(), $day_of_week, $s)
            ];
        }
        wp_send_json_success($result);
    }


    public function ajax_submit_cart() {
        // Fix #1: CSRF protection on cart submission.
        check_ajax_referer('gtbp_ajax_nonce', 'nonce');
        if (!is_user_logged_in()) wp_send_json_error('لطفا ابتدا وارد سایت شوید.');
        // Fix #14: use wp_unslash (WordPress standard) instead of stripslashes.
        $cart = json_decode(wp_unslash($_POST['cart'] ?? ''), true);
        if (empty($cart)) wp_send_json_error('سبد رزرو خالی است.');
        $payment_method = isset($_POST['payment_method']) ? sanitize_text_field($_POST['payment_method']) : 'online';
        $discount_code = isset($_POST['discount_code']) ? sanitize_text_field($_POST['discount_code']) : '';
        
        $online_enabled = get_option('gtbp_payment_online_enabled', '1') === '1';
        $card_enabled   = get_option('gtbp_payment_card_enabled', '1') === '1';
        
        $package_validation = $this->validate_package_cart($cart);
        if (empty($package_validation['valid'])) {
            wp_send_json_error($package_validation['message']);
        }

        $user = wp_get_current_user();
        $totals = $this->calculate_cart_totals($cart, $discount_code, $user->ID);
        $subtotal = $totals['subtotal'];
        $discount_percent = $totals['coupon_discount_percent'];
        $discount_amount = $totals['coupon_discount_amount'];
        $package_discount_amount = $totals['package_discount_amount'];
        $package_discount_percent = $totals['package_discount_percent'];
        $after_discount = $totals['after_discount'];
        $user_adjustment = $totals['user_adjustment'];
        $payable_total = $totals['payable_total'];
        
        // اگر مبلغ قابل پرداخت صفر است، نیازی به پرداخت نیست و باید طلب/بدهی به‌روز شود.
        $direct_register = ($payable_total == 0);
        
        // بررسی همزمانی و مجاز بودن زمان برای همین کاربر
        global $wpdb;
        foreach ($cart as $item) {
            $date = sanitize_text_field($item['date']);
            $time = sanitize_text_field($item['time']);
            if (!$this->is_slot_available_for_user($date, $time, $user->ID)) {
                wp_send_json_error("زمان $time در تاریخ $date برای حساب کاربری شما قابل رزرو نیست یا دیگر در دسترس نیست.");
            }
            // نسخه ۱۴.۱: تشخیص هم‌پوشانی زمانی (نه فقط تطابق دقیق) تا رزرو روی بازه‌ی متداخل جلوگیری شود
            if ($this->slot_conflicts_with_bookings($date, $time)) {
                wp_send_json_error("متاسفانه زمان $time در تاریخ $date با یک رزرو دیگر تداخل دارد یا همین الان رزرو شد. لطفا سبد خود را اصلاح کنید.");
            }
        }
        
        $f = $user->user_firstname ? $user->user_firstname : $user->display_name;
        $l = $user->user_lastname;
        $e = $user->user_email;
        $p = get_user_meta($user->ID, 'billing_phone', true);
        if(empty($p)) $p = 'ثبت نشده';
        
        $student_name = trim($f . ' ' . $l);
        $teacher_name = get_option('gtbp_bbb_teacher_name', self::BBB_DEFAULT_TEACHER_NAME); // Fix #11
        $jitsi_rooms = [];
        $inserted_ids = [];
        
        // در حالت ثبت مستقیم (بدون پرداخت)، ابتدا اتاق‌های BigBlueButton ساخته می‌شوند و رزروها ثبت می‌گردند.
        foreach ($cart as $item) {
            $date = sanitize_text_field($item['date']);
            $time = sanitize_text_field($item['time']);
            $class_name = sanitize_text_field($item['class']['name']);
                if (!empty($item['package']['name'])) {
                    $class_name .= ' - ' . sanitize_text_field($item['package']['name']);
                }
            $jDate = isset($item['jDate']) ? $item['jDate'] : $this->gregorian_to_jalali_string($date);
            $room_name = $jDate . ' - ' . $student_name;
            $room = $this->create_jitsi_room($room_name, $student_name, $teacher_name);
            $meeting_id = $room ? $room['meeting_id'] : null;
            $student_link = $room ? $room['student_link'] : null;
            $teacher_link = $room ? $room['teacher_link'] : null;
            
            if ($room) {
                $jitsi_rooms[] = [ 
                    'date' => $date, 
                    'time' => $time, 
                    'class_name' => $class_name, 
                    'student_link' => $student_link,
                    'teacher_link' => $teacher_link
                ];
            }
            
            $status = $direct_register ? 'confirmed' : 'temp_card';
            $inserted = $wpdb->insert($this->table_name, [
                'first_name' => $f,
                'last_name' => $l,
                'email' => $e,
                'phone' => $p,
                'booking_date' => $date,
                'booking_time' => $time,
                'class_name' => $class_name,
                'status' => $status,
                'roomeet_room_id' => $meeting_id,
                'roomeet_join_link' => $student_link,
                'bbb_moderator_link' => $teacher_link
            ]);
            
            if ($inserted) {
                $inserted_ids[] = $wpdb->insert_id;
            } else {
                error_log('Insert failed: ' . $wpdb->last_error);
            }
        }
        
        if (empty($inserted_ids)) {
            wp_send_json_error('خطا در ثبت رزرو. لطفا دوباره تلاش کنید.');
        }
        
        // در صورت ثبت مستقیم، لینک‌های BigBlueButton را بروزرسانی می‌کنیم، ایمیل ارسال می‌کنیم و طلب/بدهی را به‌روز می‌کنیم.
        if ($direct_register) {
            foreach ($inserted_ids as $bid) {
                $booking = $wpdb->get_row("SELECT * FROM {$this->table_name} WHERE id = {$bid}");
                $this->update_user_jitsi_links($user->ID, $booking);
            }
            // به‌روزرسانی مبلغ طلب/بدهی
            if ($user_adjustment < 0) {
                // طلب (منفی): مقدار آن را به اندازه مبلغ کل سبد (بعد از تخفیف) کاهش می‌دهیم
                $new_adjustment = $user_adjustment + $after_discount;
                $this->set_user_adjustment($user->ID, $new_adjustment);
            } elseif ($user_adjustment > 0) {
                // بدهی مثبت: اگر مبلغ قابل پرداخت صفر شده باشد (که اینجا شرط direct_register یعنی payable_total=0) پس بدهی نباید وجود داشته باشد. عملاً این حالت رخ نمی‌دهد.
            }
            $this->send_booking_emails($f, $l, $e, $p, $cart, $payable_total, false, false, $jitsi_rooms);
            wp_send_json_success(['type' => 'direct']);
        }
        
        // در غیر این صورت (نیاز به پرداخت) طبق روال قبل عمل می‌کنیم.
        if ($payment_method === 'online') {
            if (!class_exists('WooCommerce')) {
                foreach ($inserted_ids as $bid) {
                    $wpdb->update($this->table_name, ['status' => 'confirmed'], ['id' => $bid]);
                    $booking = $wpdb->get_row("SELECT * FROM {$this->table_name} WHERE id = {$bid}");
                    $this->update_user_jitsi_links($user->ID, $booking);
                }
                $this->send_booking_emails($f, $l, $e, $p, $cart, $payable_total, false, true, $jitsi_rooms);
                wp_send_json_success(['type' => 'online', 'redirect_url' => add_query_arg('gtbp_success', '1', wp_get_referer())]);
            }
            
            $order = wc_create_order();
            $order->set_customer_id($user->ID);
            
            $line_items_added = false;
            foreach ($cart as $item) {
                $class_id = isset($item['class']['id']) ? $item['class']['id'] : '';
                $product_found = false;
                $line_price = 0;
                
                if (!empty($class_id)) {
                    $args = array( 
                        'post_type' => 'product', 
                        'meta_key' => '_gtbp_class_id', 
                        'meta_value' => $class_id, 
                        'posts_per_page' => 1 
                    );
                    $posts = get_posts($args);
                    if ($posts) {
                        $product = wc_get_product($posts[0]->ID);
                        $order->add_product($product, 1);
                        $line_price = floatval($product->get_price());
                        $product_found = true;
                        $line_items_added = true;
                    }
                }
                
                if (!$product_found) {
                    $line_price = floatval($item['class']['price']);
                    $fee = new WC_Order_Item_Fee();
                    $fee->set_name('رزرو کلاس: ' . $item['class']['name']);
                    $fee->set_amount($line_price);
                    $fee->set_total($line_price);
                    $order->add_item($fee);
                    $line_items_added = true;
                }
            }
            
            if (!$line_items_added) {
                $fee = new WC_Order_Item_Fee();
                $fee->set_name('رزرو کلاس');
                $fee->set_amount($subtotal);
                $fee->set_total($subtotal);
                $order->add_item($fee);
            }
            
            if ($package_discount_amount > 0) {
                $package_fee = new WC_Order_Item_Fee();
                $package_name = !empty($totals['package']['name']) ? $totals['package']['name'] : 'طرح چندجلسه‌ای';
                $package_fee->set_name('تخفیف طرح چندجلسه‌ای (' . $package_name . ')');
                $package_fee->set_amount(-$package_discount_amount);
                $package_fee->set_total(-$package_discount_amount);
                $order->add_item($package_fee);
                $order->update_meta_data('_gtbp_package_id', $totals['package']['id']);
                $order->update_meta_data('_gtbp_package_name', $package_name);
                $order->update_meta_data('_gtbp_package_discount_percent', $package_discount_percent);
                $order->update_meta_data('_gtbp_package_discount_amount', $package_discount_amount);
            }
            
            if ($discount_percent > 0 && $subtotal > 0 && $discount_amount > 0) {
                $discount_fee = new WC_Order_Item_Fee();
                $discount_fee->set_name('تخفیف سفارشی (' . $discount_code . ')');
                $discount_fee->set_amount(-$discount_amount);
                $discount_fee->set_total(-$discount_amount);
                $order->add_item($discount_fee);
                $order->update_meta_data('_gtbp_discount_code', $discount_code);
                $order->update_meta_data('_gtbp_discount_percent', $discount_percent);
                $order->update_meta_data('_gtbp_discount_amount', $discount_amount);
            }
            
            if ($user_adjustment != 0) {
                $adjustment_fee = new WC_Order_Item_Fee();
                if ($user_adjustment > 0) {
                    $adjustment_fee->set_name('بدهی کاربر (اضافی)');
                } else {
                    $adjustment_fee->set_name('طلب کاربر (تخفیف ویژه)');
                }
                $adjustment_fee->set_amount($user_adjustment);
                $adjustment_fee->set_total($user_adjustment);
                $order->add_item($adjustment_fee);
                $order->update_meta_data('_gtbp_user_adjustment_applied', $user_adjustment);
            }
            
            $order->calculate_totals();
            $order->update_meta_data('_gtbp_pending_cart', $cart);
            $order->update_meta_data('_gtbp_source_url', wp_get_referer());
            $order->update_meta_data('_gtbp_temp_booking_ids', $inserted_ids);
            $order->save();
            
            $redirect_url = $order->get_checkout_payment_url();
            $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
            if (!empty($available_gateways)) {
                $gateway = current($available_gateways);
                $order->set_payment_method($gateway);
                $order->save();
                try { 
                    $result = $gateway->process_payment($order->get_id()); 
                    if (isset($result['result']) && $result['result'] === 'success' && isset($result['redirect'])) {
                        $redirect_url = $result['redirect'];
                    }
                } catch (Exception $e) {}
            }
            
            wp_send_json_success([ 'type' => 'online', 'redirect_url' => $redirect_url ]);
            
        } else { // روش کارت به کارت
            // اگر مبلغ قابل پرداخت صفر است، نباید به این بخش برسد، چون قبلاً شرط direct_register گرفته شده.
            // نسخه ۱۴.۳: بدهی/طلبِ اعمال‌شده در این پرداخت را ذخیره می‌کنیم تا هنگام تاییدِ کارت‌به‌کارت تسویه شود.
            if ($user_adjustment != 0) {
                set_transient('gtbp_card_adj_' . intval($user->ID), intval($user_adjustment), 2 * HOUR_IN_SECONDS);
            }
            $bank = $this->get_bank_info();
            $subject_temp = "⏳ رزرو موقت - ۱۰ دقیقه فرصت پرداخت";
            $message_temp = "سلام $f عزیز،\n\n";
            $message_temp .= "رزرو شما به صورت موقت ثبت شد. برای قطعی شدن، لطفاً ظرف ۱۰ دقیقه مبلغ را به کارت زیر واریز کنید:\n\n";
            $message_temp .= "کارت: {$bank['card']}\n";
            $message_temp .= "به نام: {$bank['owner']}\n\n";
            $message_temp .= "پس از واریز، در همین صفحه روی دکمه «پرداخت انجام شد» کلیک کنید.\n\n";
            $message_temp .= "در غیر این صورت رزرو شما لغو خواهد شد.\n\nبا تشکر";
            wp_mail($e, $subject_temp, $message_temp);
            wp_mail($this->admin_notification_email(), "رزرو موقت جدید - $f $l", $message_temp);

            $booking_id = $inserted_ids[0];
            $created = time();
            $expires = $created + 600;
            wp_send_json_success([
                'type' => 'bank',
                'booking_id' => $booking_id,
                'booking_ids' => $inserted_ids,
                'expires' => $expires,
            ]);
        }
    }

    private function internal_jalali_to_gregorian($jy, $jm, $jd) {
        $jy = (int)$jy; $jm = (int)$jm; $jd = (int)$jd;
        $jy += 1595;
        $days = -355668 + (365 * $jy) + ((int)($jy / 33) * 8) + ((int)((($jy % 33) + 3) / 4)) + $jd;
        if ($jm < 7) {
            $days += ($jm - 1) * 31;
        } else {
            $days += (($jm - 7) * 30) + 186;
        }
        $gy = 400 * ((int)($days / 146097));
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * ((int)(--$days / 36524));
            $days %= 36524;
            if ($days >= 365) $days++;
        }
        $gy += 4 * ((int)($days / 1461));
        $days %= 1461;
        if ($days > 365) {
            $gy += (int)(($days - 1) / 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $sal_a = [0,31, (($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0)) ? 29 : 28,31,30,31,30,31,31,30,31,30,31];
        for ($gm = 1; $gm <= 12 && $gd > $sal_a[$gm]; $gm++) {
            $gd -= $sal_a[$gm];
        }
        return [$gy, $gm, $gd];
    }

    private function jalali_string_to_gregorian_date($jalali_string) {
        $jalali_string = trim(str_replace('-', '/', (string)$jalali_string));
        if (!preg_match('/^(13|14)\d{2}\/(0?[1-9]|1[0-2])\/(0?[1-9]|[12]\d|3[01])$/', $jalali_string)) {
            return '';
        }
        list($jy, $jm, $jd) = array_map('intval', explode('/', $jalali_string));
        list($gy, $gm, $gd) = $this->internal_jalali_to_gregorian($jy, $jm, $jd);
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    private function internal_gregorian_to_jalali($gy,$gm,$gd) {
        $g_d_m = array(0,31,59,90,120,151,181,212,243,273,304,334);
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100)) + ((int)(($gy2 + 399) / 400)) + $gd + $g_d_m[$gm - 1];
        $jy = -1595 + (33 * ((int)($days / 12053))); $days %= 12053;
        $jy += 4 * ((int)($days / 1461)); $days %= 1461;
        if ($days > 365) { $jy += (int)(($days - 1) / 365); $days = ($days - 1) % 365; }
        $jm = ($days < 186) ? 1 + (int)($days / 31) : 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));
        return array($jy,$jm,$jd);
    }

    /* ==========================================================================
       SMTP & GOOGLE CALENDAR & EMAIL HELPER
       ========================================================================== */
    public function setup_smtp($phpmailer) {
        $host = trim((string)get_option('gtbp_smtp_host', ''));
        $username = trim((string)get_option('gtbp_smtp_username', ''));
        $password = (string)get_option('gtbp_smtp_password', '');
        if ($host === '' || $username === '' || $password === '') return;
        $phpmailer->isSMTP();
        $phpmailer->Host       = $host;
        $phpmailer->SMTPAuth   = true;
        $phpmailer->Port       = max(1, intval(get_option('gtbp_smtp_port', 465)));
        $phpmailer->SMTPSecure = sanitize_key((string)get_option('gtbp_smtp_security', 'ssl'));
        $phpmailer->Username   = $username;
        $phpmailer->Password   = $password;
        $phpmailer->From       = sanitize_email((string)get_option('gtbp_smtp_from', $username));
        $phpmailer->FromName   = sanitize_text_field((string)get_option('gtbp_smtp_from_name', get_bloginfo('name')));
    }

    private function admin_notification_email() {
        $configured = sanitize_email((string)get_option('gtbp_admin_notification_email', ''));
        return $configured !== '' ? $configured : sanitize_email((string)get_option('admin_email'));
    }

    private function generate_gcal_link($date, $time, $class_name, $jalali_date) {
        $tz = new DateTimeZone('Asia/Tehran');
        $time_parts = explode('-', $time);
        $start_time = trim($time_parts[0]);
        $end_time = trim($time_parts[1]);
        $start_dt = new DateTime("$date $start_time:00", $tz);
        $end_dt = new DateTime("$date $end_time:00", $tz);
        $start_dt->setTimezone(new DateTimeZone('UTC'));
        $end_dt->setTimezone(new DateTimeZone('UTC'));
        $start_format = $start_dt->format('Ymd\THis\Z');
        $end_format = $end_dt->format('Ymd\THis\Z');
        $text = urlencode('کلاس آلمانی - ' . $jalali_date);
        $details = urlencode('کلاس رزرو شده با ' . $class_name . ' در تاریخ ' . $jalali_date);
        return "https://www.google.com/calendar/render?action=TEMPLATE&text={$text}&dates={$start_format}/{$end_format}&details={$details}";
    }

    private function send_booking_emails($f, $l, $e, $p, $cart, $total_price, $is_manual = false, $is_online = false, $jitsi_rooms = []) {
        $admin_email = $this->admin_notification_email();
        $receipt_details = "";
        $gcal_links = "
لینک‌های افزودن به تقویم گوگل:
";
        $bbb_links = "
🔗 لینک‌های مدیریت کلاس آنلاین (BigBlueButton):
";
        
        foreach ($cart as $item) {
            $date = $item['date'];
            $time = $item['time'];
            $cname = isset($item['class']['name']) ? $item['class']['name'] : 'کلاس';
            if (!empty($item['package']['name'])) { $cname .= ' - ' . $item['package']['name']; }
            $display_date = isset($item['jDate']) ? $item['jDate'] : $date;
            $receipt_details .= "- کلاس: $cname | تاریخ: $display_date | ساعت: $time
";
            $gcal_link = $this->generate_gcal_link($date, $time, $cname, $display_date);
            $gcal_links .= "- افزودن کلاس $cname به تقویم (تاریخ $display_date): 
$gcal_link

";
            
            foreach ($jitsi_rooms as $room) {
                if (isset($room['date'], $room['time']) && $room['date'] == $date && $room['time'] == $time) {
                    $bbb_links .= "- کلاس $cname ($display_date - $time):
";
                    if (!empty($room['teacher_link'])) {
                        $bbb_links .= "  لینک مدیر/مدرس: {$room['teacher_link']}
";
                    }
                    if (!empty($room['student_link'])) {
                        $bbb_links .= "  لینک زبان‌آموز برای کنترل ادمین: {$room['student_link']}
";
                    }
                    $bbb_links .= "
";
                    break;
                }
            }
        }
        
        $admin_msg = "رزرو جدید توسط $f $l ثبت شد:
موبایل: $p
ایمیل: $e

لیست کلاس‌ها:
$receipt_details";
        if (!$is_manual) {
            $admin_msg .= "
مجموع: " . number_format((float)$total_price) . " تومان";
        }
        $admin_msg .= "
" . $gcal_links . "
" . $bbb_links;
        wp_mail($admin_email, "رزرو جدید کلاس آلمانی - $f $l", $admin_msg);
        
        $u_msg = "سلام $f عزیز،

رزرو شما با موفقیت ثبت شد.

لیست کلاس‌ها:
$receipt_details";
        if (!$is_manual) {
            $u_msg .= "
مبلغ پرداخت‌شده/قابل پرداخت: " . number_format((float)$total_price) . " تومان
";
        }
        $u_msg .= "
🔔 لینک ورود به کلاس برای امنیت بیشتر در ایمیل ارسال نمی‌شود. لینک ۳ دقیقه قبل از شروع کلاس در حساب کاربری فعال می‌شود و اگر با ربات تلگرام ارتباط داشته باشید، همان‌جا هم برایتان ارسال خواهد شد.

";
        $u_msg .= $gcal_links . "با تشکر.";
        wp_mail($e, "تاییدیه رزرو کلاس آلمانی", $u_msg);
    }


    /* ==========================================================================
       HELPERS با کش
       ========================================================================== */

    /* ==========================================================================
       PACKAGE / MULTI-SESSION PLAN HELPERS
       ========================================================================== */
    private function get_booking_packages() {
        if ($this->cached_packages === null) {
            $packages = get_option('gtbp_booking_packages', []);
            if (!is_array($packages)) $packages = [];
            $classes = $this->get_classes_raw();
            $class_ids = array_column($classes, 'id');
            $normalized = [];
            foreach ($packages as $pkg) {
                if (empty($pkg['id']) || empty($pkg['name']) || empty($pkg['class_id'])) continue;
                if (!in_array($pkg['class_id'], $class_ids, true)) continue;
                $session_count = isset($pkg['session_count']) ? intval($pkg['session_count']) : 0;
                $window_days = isset($pkg['window_days']) ? intval($pkg['window_days']) : 0;
                $discount_percent = isset($pkg['discount_percent']) ? floatval($pkg['discount_percent']) : 0;
                if ($session_count < 2 || $window_days < 1) continue;
                if ($discount_percent < 0) $discount_percent = 0;
                if ($discount_percent > 100) $discount_percent = 100;
                $normalized[] = [
                    'id' => sanitize_text_field($pkg['id']),
                    'name' => sanitize_text_field($pkg['name']),
                    'class_id' => sanitize_text_field($pkg['class_id']),
                    'session_count' => $session_count,
                    'window_days' => $window_days,
                    'discount_percent' => $discount_percent,
                    'active' => isset($pkg['active']) ? (bool)$pkg['active'] : true,
                    'sort' => isset($pkg['sort']) ? intval($pkg['sort']) : 0,
                    'bg' => $this->sanitize_card_color($pkg['bg'] ?? ''),
                    'color' => $this->sanitize_card_color($pkg['color'] ?? ''),
                    'accent' => $this->sanitize_card_color($pkg['accent'] ?? ''),
                ];
            }
            $this->cached_packages = $this->sort_cards($normalized);
        }
        return $this->cached_packages;
    }

    private function get_active_booking_packages() {
        return array_values(array_filter($this->get_booking_packages(), function($pkg) {
            return !empty($pkg['active']);
        }));
    }

    private function get_classes_raw() {
        $classes = get_option('gtbp_classes', []);
        return $this->sort_cards(is_array($classes) ? $classes : []);
    }

    /**
     * ترتیب نمایش کارت‌ها: ابتدا مقدار «ترتیب» (کوچک‌تر جلوتر) و در صورت برابری،
     * ترتیب اصلی تعریف حفظ می‌شود تا رفتار سایت‌های قدیمی تغییر نکند.
     */
    private function sort_cards($items) {
        if (!is_array($items)) return [];
        $items = array_values($items);
        $indexed = [];
        foreach ($items as $position => $item) {
            $indexed[] = ['position' => $position, 'sort' => isset($item['sort']) ? intval($item['sort']) : 0, 'item' => $item];
        }
        usort($indexed, function ($a, $b) {
            if ($a['sort'] === $b['sort']) return $a['position'] <=> $b['position'];
            return $a['sort'] <=> $b['sort'];
        });
        return array_map(function ($entry) { return $entry['item']; }, $indexed);
    }

    /** رنگ معتبر CSS به‌صورت #rgb یا #rrggbb، در غیر این صورت رشته خالی. */
    private function sanitize_card_color($value) {
        $value = trim((string) $value);
        return preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $value) ? $value : '';
    }

    private function find_class_by_id($class_id) {
        foreach ($this->get_classes() as $class) {
            if (isset($class['id']) && $class['id'] === $class_id) return $class;
        }
        return null;
    }

    private function find_package_by_id($package_id, $active_only = true) {
        $packages = $active_only ? $this->get_active_booking_packages() : $this->get_booking_packages();
        foreach ($packages as $pkg) {
            if ($pkg['id'] === $package_id) return $pkg;
        }
        return null;
    }

    private function get_cart_package($cart) {
        if (empty($cart) || !is_array($cart)) return null;
        $package_id = '';
        foreach ($cart as $item) {
            $item_package_id = isset($item['package']['id']) ? sanitize_text_field($item['package']['id']) : '';
            if ($item_package_id !== '') {
                if ($package_id !== '' && $package_id !== $item_package_id) return false;
                $package_id = $item_package_id;
            }
        }
        return $package_id ? $this->find_package_by_id($package_id) : null;
    }

    private function validate_package_cart($cart, $package = null) {
        if (empty($cart) || !is_array($cart)) {
            return ['valid' => false, 'message' => 'سبد رزرو خالی است.'];
        }
        if ($package === null) $package = $this->get_cart_package($cart);
        if ($package === false) return ['valid' => false, 'message' => 'در یک رزرو نمی‌توانید چند طرح چندجلسه‌ای را با هم ترکیب کنید.'];
        if (!$package) return ['valid' => true, 'message' => ''];

        $expected = intval($package['session_count']);
        if (count($cart) !== $expected) {
            return ['valid' => false, 'message' => 'برای طرح «' . $package['name'] . '» باید دقیقاً ' . $expected . ' جلسه انتخاب شود.'];
        }
        foreach ($cart as $item) {
            $class_id = isset($item['class']['id']) ? sanitize_text_field($item['class']['id']) : '';
            $item_package_id = isset($item['package']['id']) ? sanitize_text_field($item['package']['id']) : '';
            if ($class_id !== $package['class_id'] || $item_package_id !== $package['id']) {
                return ['valid' => false, 'message' => 'همه جلسات این طرح باید از همان نوع کلاسی باشند که ادمین برای طرح تعیین کرده است.'];
            }
        }
        $dates = array_map(function($item) { return sanitize_text_field($item['date']); }, $cart);
        sort($dates);
        $first_ts = strtotime($dates[0]);
        $last_ts = strtotime(end($dates));
        $allowed_last_ts = strtotime('+' . intval($package['window_days']) . ' days', $first_ts);
        if ($last_ts > $allowed_last_ts) {
            return ['valid' => false, 'message' => 'همه جلسات طرح «' . $package['name'] . '» باید حداکثر تا ' . intval($package['window_days']) . ' روز پس از اولین جلسه انتخاب شوند.'];
        }
        $seen = [];
        foreach ($cart as $item) {
            $key = sanitize_text_field($item['date']) . '|' . sanitize_text_field($item['time']);
            if (isset($seen[$key])) return ['valid' => false, 'message' => 'یک بازه زمانی دوبار در سبد انتخاب شده است.'];
            $seen[$key] = true;
        }
        return ['valid' => true, 'message' => ''];
    }

    private function calculate_cart_totals($cart, $discount_code = '', $user_id = 0) {
        $subtotal = 0;
        foreach ($cart as $item) {
            $subtotal += isset($item['class']['price']) ? floatval($item['class']['price']) : 0;
        }

        $package = $this->get_cart_package($cart);
        if ($package === false) $package = null;
        $package_discount_percent = $package ? floatval($package['discount_percent']) : 0;
        $package_discount_amount = ($package_discount_percent > 0 && $subtotal > 0) ? round(($subtotal * $package_discount_percent) / 100) : 0;

        $coupon_discount_percent = 0;
        $discount_code = sanitize_text_field($discount_code);
        if (!empty($discount_code)) {
            $coupons = get_option('gtbp_discount_coupons', []);
            if (isset($coupons[$discount_code]) && isset($coupons[$discount_code]['percent'])) {
                $coupon_discount_percent = floatval($coupons[$discount_code]['percent']);
            }
        }
        if ($coupon_discount_percent < 0) $coupon_discount_percent = 0;
        if ($coupon_discount_percent > 100) $coupon_discount_percent = 100;
        $coupon_discount_amount = ($coupon_discount_percent > 0 && $subtotal > 0) ? round(($subtotal * $coupon_discount_percent) / 100) : 0;

        $discount_amount = min($subtotal, $package_discount_amount + $coupon_discount_amount);
        $after_discount = max(0, $subtotal - $discount_amount);
        $user_adjustment = $user_id ? $this->get_user_adjustment($user_id) : 0;
        $payable_total = $after_discount + $user_adjustment;
        if ($payable_total < 0) $payable_total = 0;

        return [
            'subtotal' => $subtotal,
            'package' => $package,
            'package_discount_percent' => $package_discount_percent,
            'package_discount_amount' => $package_discount_amount,
            'coupon_discount_percent' => $coupon_discount_percent,
            'coupon_discount_amount' => $coupon_discount_amount,
            'discount_amount' => $discount_amount,
            'after_discount' => $after_discount,
            'user_adjustment' => $user_adjustment,
            'payable_total' => $payable_total,
        ];
    }

    private function get_classes() {
        if ($this->cached_classes === null) {
            $classes = get_transient('gtbp_cached_classes');
            if (false === $classes) {
                $classes = $this->sort_cards(get_option('gtbp_classes', []));
                set_transient('gtbp_cached_classes', $classes, 15 * MINUTE_IN_SECONDS);
            }
            $this->cached_classes = $classes;
        }
        return $this->cached_classes;
    }

    private function get_bank_info() {
        if ($this->cached_bank_info === null) {
            $this->cached_bank_info = get_option('gtbp_bank_info', ['card' => '', 'owner' => '']);
        }
        return $this->cached_bank_info;
    }
    private function get_holidays() {
        if ($this->cached_holidays === null) {
            $this->cached_holidays = get_option('gtbp_holidays', []);
        }
        return $this->cached_holidays;
    }

    private function get_user_working_hours_all() {
        if ($this->cached_user_hours === null) {
            $data = get_option('gtbp_user_working_hours', []);
            $this->cached_user_hours = is_array($data) ? $data : [];
        }
        return $this->cached_user_hours;
    }

    private function normalize_slots_list($slots_string) {
        $items = array_filter(array_map('trim', explode(',', (string)$slots_string)));
        $valid = [];
        foreach ($items as $item) {
            $item = str_replace('–', '-', $item);
            if (preg_match('/^(?:[01]?\d|2[0-3]):[0-5]\d\s*-\s*(?:[01]?\d|2[0-3]):[0-5]\d$/', $item)) {
                $item = preg_replace('/\s*-\s*/', '-', $item);
                $valid[] = $item;
            }
        }
        $valid = array_values(array_unique($valid));
        usort($valid, function($a, $b) {
            return strcmp(substr($a, 0, 5), substr($b, 0, 5));
        });
        return $valid;
    }

    private function get_user_private_slots_for_day($user_id, $day_of_week) {
        $user_id = absint($user_id);
        if (!$user_id) return [];
        $all = $this->get_user_working_hours_all();
        if (empty($all[$user_id]) || !is_array($all[$user_id])) return [];
        foreach ($all[$user_id] as $row) {
            if (isset($row['day']) && intval($row['day']) === intval($day_of_week) && !empty($row['slots'])) {
                return $this->normalize_slots_list($row['slots']);
            }
        }
        return [];
    }

    private function is_user_private_slot($user_id, $day_of_week, $slot) {
        return in_array($slot, $this->get_user_private_slots_for_day($user_id, $day_of_week), true);
    }

    private function get_visible_slots_for_user_day($day_settings, $day_of_week, $user_id = 0) {
        $public_slots = [];
        if ($day_settings && !empty($day_settings['active']) && !empty($day_settings['slots'])) {
            $public_slots = $this->normalize_slots_list($day_settings['slots']);
            if (!empty($day_settings['priority_slots'])) {
                $priority_slots = $this->normalize_slots_list($day_settings['priority_slots']);
                $public_slots = array_values(array_intersect($public_slots, $priority_slots));
            }
        }
        $private_slots = $this->get_user_private_slots_for_day($user_id, $day_of_week);
        $merged = array_values(array_unique(array_merge($public_slots, $private_slots)));
        usort($merged, function($a, $b) { return strcmp(substr($a, 0, 5), substr($b, 0, 5)); });
        return $merged;
    }

    /* ==========================================================================
       نسخه ۱۴.۱: تشخیص هم‌پوشانی زمانی اسلات‌ها (نه صرفاً تطابق رشته‌ای)
       رفع باگ: پس از تغییر ساعت کاری، اسلاتی که با رزرو قبلی هم‌پوشانی دارد باید اشغال شمرده شود.
       ========================================================================== */
    private function hm_to_min($t) {
        $t = trim((string)$t);
        if (!preg_match('/^(\d{1,2}):(\d{2})$/', $t, $m)) return null;
        $min = intval($m[1]) * 60 + intval($m[2]);
        return ($min >= 0 && $min <= 24 * 60) ? $min : null;
    }
    private function parse_time_range_to_minutes($range) {
        $parts = explode('-', (string)$range);
        if (count($parts) !== 2) return null;
        $s = $this->hm_to_min($parts[0]);
        $e = $this->hm_to_min($parts[1]);
        if ($s === null || $e === null) return null;
        if ($e <= $s) $e += 24 * 60; // محافظت در برابر بازه‌های عبوری از نیمه‌شب
        return [$s, $e];
    }
    public function time_ranges_overlap($a, $b) {
        $ra = $this->parse_time_range_to_minutes($a);
        $rb = $this->parse_time_range_to_minutes($b);
        if (!$ra || !$rb) return false;
        return ($ra[0] < $rb[1] && $rb[0] < $ra[1]);
    }
    /**
     * آیا اسلات با هر رزرو غیرلغوشده‌ی همان روز هم‌پوشانی دارد؟
     * @param array|null $booked_times اگر لیست زمان‌های رزروشده از قبل موجود است، برای پرهیز از کوئری اضافه پاس بده.
     */
    private function slot_conflicts_with_bookings($date, $slot, $exclude_id = 0, $booked_times = null) {
        if ($booked_times === null) {
            global $wpdb;
            $sql = "SELECT booking_time FROM {$this->table_name} WHERE booking_date = %s AND status != 'cancelled'";
            if ($exclude_id) $sql .= ' AND id <> ' . intval($exclude_id);
            $booked_times = $wpdb->get_col($wpdb->prepare($sql, $date));
        }
        foreach ((array)$booked_times as $bt) {
            if ($this->time_ranges_overlap($slot, $bt)) return true;
        }
        return false;
    }

    private function is_slot_available_for_user($date, $slot, $user_id) {
        if (!$date || !$slot || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return false;
        $holidays = $this->get_holidays();
        if (in_array($date, $holidays, true)) return false;
        $day_of_week = date('w', strtotime($date));
        $day_settings = null;
        foreach ($this->get_working_hours() as $h) { if (isset($h['day']) && intval($h['day']) === intval($day_of_week)) { $day_settings = $h; break; } }
        $visible_slots = $this->get_visible_slots_for_user_day($day_settings, $day_of_week, $user_id);
        if (!in_array($slot, $visible_slots, true)) return false;
        // نسخه ۱۴.۱: رد کردن اسلاتی که با رزرو موجود (حتی با ساعت متفاوت) هم‌پوشانی دارد
        if ($this->slot_conflicts_with_bookings($date, $slot)) return false;
        $tz = new DateTimeZone('Asia/Tehran');
        try {
            $slot_start_time = explode('-', $slot)[0];
            $slot_dt = new DateTime($date . ' ' . trim($slot_start_time) . ':00', $tz);
            $now = new DateTime('now', $tz);
            if ((($slot_dt->getTimestamp() - $now->getTimestamp()) / 3600) < 24) return false;
        } catch (Exception $e) {
            return false;
        }
        return true;
    }

    private function get_working_hours() {
        if ($this->cached_hours === null) {
            $this->cached_hours = get_option('gtbp_working_hours', []);
        }
        return $this->cached_hours;
    }

    private function gregorian_to_jalali($gy,$gm,$gd) {
        $g_d_m = array(0,31,59,90,120,151,181,212,243,273,304,334);
        $jy = ($gy <= 1600) ? 0 : 979; $gy -= ($gy <= 1600) ? 621 : 1600;
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = (365 * $gy) + ((int)(($gy2 + 3) / 4)) - ((int)(($gy2 + 99) / 100)) + ((int)(($gy2 + 399) / 400)) - 80 + $gd + $g_d_m[$gm - 1];
        $jy += 33 * ((int)($days / 12053)); $days %= 12053;
        $jy += 4 * ((int)($days / 1461)); $days %= 1461;
        if ($days > 365) { $jy += (int)(($days - 1) / 365); $days = ($days - 1) % 365; }
        $jm = ($days < 186) ? 1 + (int)($days / 31) : 7 + (int)(($days - 186) / 30);
        $jd = 1 + (($days < 186) ? ($days % 31) : (($days - 186) % 30));
        return array($jy,$jm,$jd);
    }

    private function gregorian_to_jalali_string($date_string) {
        if (!$date_string) return '';
        $p = explode('-',$date_string);
        if (count($p) != 3) return $date_string;
        $j = $this->gregorian_to_jalali((int)$p[0],(int)$p[1],(int)$p[2]);
        return $j[0] . '/' . sprintf('%02d',$j[1]) . '/' . sprintf('%02d',$j[2]);
    }



    private function phone_starts_with_09($phone) {
        $digits = preg_replace('/\D+/', '', (string)$phone);
        if (strpos($digits, '98') === 0) $digits = '0' . substr($digits, 2);
        return strpos($digits, '09') === 0;
    }

    private function get_slot_period_meta($slot) {
        $start = trim(explode('-', (string)$slot)[0]);
        $hour = intval(substr($start, 0, 2));
        if ($hour < 14) return ['period' => 'morning', 'class' => 'slot-morning', 'icon' => '☀️'];
        if ($hour < 17) return ['period' => 'afternoon', 'class' => 'slot-afternoon', 'icon' => '🌤️'];
        return ['period' => 'night', 'class' => 'slot-night', 'icon' => '🌙'];
    }

    private function get_bot_slot_label($slot) {
        $meta = $this->get_slot_period_meta($slot);
        return $meta['icon'] . ' ' . $slot;
    }

    private function format_gregorian_display($date_string) {
        if (!$date_string) return '';
        $ts = strtotime($date_string);
        if (!$ts) return $date_string;
        return date('d/m/Y', $ts);
    }

    private function validate_coupon_code_for_user($code, $user_id) {
        $code = sanitize_text_field($code);
        if (empty($code)) {
            return ['valid' => false, 'message' => 'کد تخفیف خالی است.'];
        }
        $coupons = get_option('gtbp_discount_coupons', []);
        if (!isset($coupons[$code])) {
            return ['valid' => false, 'message' => 'کد تخفیف نامعتبر است.'];
        }
        $limit = isset($coupons[$code]['limit']) ? intval($coupons[$code]['limit']) : 0;
        $usage = (int) get_user_meta($user_id, '_gtbp_coupon_usage_' . $code, true);
        if ($limit > 0 && $usage >= $limit) {
            return ['valid' => false, 'message' => 'سقف مجاز استفاده شما از این کد تمام شده است.'];
        }
        $percent = isset($coupons[$code]['percent']) ? floatval($coupons[$code]['percent']) : 0;
        if ($percent <= 0) {
            return ['valid' => false, 'message' => 'درصد تخفیف این کد معتبر نیست.'];
        }
        return ['valid' => true, 'code' => $code, 'percent' => $percent, 'message' => 'کد تخفیف با موفقیت اعمال شد: ' . $percent . '٪'];
    }

    private function increase_coupon_usage_if_needed($user_id, $discount_code) {
        $discount_code = sanitize_text_field($discount_code);
        if (!$user_id || empty($discount_code)) return;
        $usage = (int) get_user_meta($user_id, '_gtbp_coupon_usage_' . $discount_code, true);
        update_user_meta($user_id, '_gtbp_coupon_usage_' . $discount_code, $usage + 1);
    }

    public function validate_coupon() {
        if (!is_user_logged_in()) wp_send_json_error(['message' => 'لطفاً ابتدا وارد سایت شوید.']);
        $code = sanitize_text_field($_POST['coupon_code']);
        $coupons = get_option('gtbp_discount_coupons', []);
        if (!isset($coupons[$code])) wp_send_json_error(['message' => 'کد تخفیف نامعتبر است.']);
        $user_id = get_current_user_id();
        $limit = $coupons[$code]['limit'];
        $usage = (int) get_user_meta($user_id, '_gtbp_coupon_usage_' . $code, true);
        if ($usage >= $limit) wp_send_json_error(['message' => 'سقف مجاز استفاده شما از این کد تمام شده است.']);
        wp_send_json_success([ 'percent' => floatval($coupons[$code]['percent']), 'message' => sprintf('کد تخفیف %s%% با موفقیت اعمال شد.', $coupons[$code]['percent']) ]);
    }

    public function admin_coupons_page() {
        if (!current_user_can('manage_options')) return;
        if (isset($_POST['add_coupon'])) {
            $code = sanitize_text_field($_POST['coupon_code']);
            $coupons = get_option('gtbp_discount_coupons', []);
            $coupons[$code] = ['percent' => intval($_POST['coupon_percent']), 'limit' => intval($_POST['coupon_limit'])];
            update_option('gtbp_discount_coupons', $coupons);
            echo '<div class="notice notice-success"><p>کد تخفیف با موفقیت ذخیره شد.</p></div>';
        }
        if (isset($_GET['delete_coupon'])) {
            $code = sanitize_text_field($_GET['delete_coupon']);
            $coupons = get_option('gtbp_discount_coupons', []);
            unset($coupons[$code]);
            update_option('gtbp_discount_coupons', $coupons);
            echo '<div class="notice notice-warning"><p>کد تخفیف حذف شد.</p></div>';
        }
        $coupons = get_option('gtbp_discount_coupons', []);
        ?>
        <div class="wrap" style="direction: rtl; font-family: IRANSansXFaNum, Tahoma, sans-serif;">
            <h1 style="color: #8B0000;">مدیریت کدهای تخفیف</h1>
            <form method="POST" style="background:#fff; padding:20px; border:1px solid #ccc; max-width:400px; margin-bottom:20px;">
                <label>کد تخفیف (مثلاً NOWRUZ):</label><br><input type="text" name="coupon_code" required style="width:100%; margin-bottom:10px;"><br>
                <label>درصد تخفیف (۱ تا ۱۰۰):</label><br><input type="number" name="coupon_percent" min="1" max="100" required style="width:100%; margin-bottom:10px;"><br>
                <label>سقف استفاده (چند بار هر کاربر؟):</label><br><input type="number" name="coupon_limit" min="1" required style="width:100%; margin-bottom:15px;"><br>
                <button type="submit" name="add_coupon" class="button button-primary">ثبت کد تخفیف</button>
            </form>
            <table class="wp-list-table widefat striped" style="max-width:400px;">
                <thead><tr><th>کد</th><th>درصد</th><th>دفعات مجاز</th><th>عملیات</th></tr></thead>
                <tbody>
                    <?php foreach($coupons as $code => $data): ?>
                        <tr>
                            <td><?php echo esc_html($code); ?></td>
                            <td><?php echo $data['percent']; ?>%</td>
                            <td><?php echo $data['limit']; ?></td>
                            <td><a href="?page=gtbp_bookings&tab=coupons&delete_coupon=<?php echo esc_attr($code); ?>" style="color:red;" onclick="return confirm('کد تخفیف حذف شود؟')">حذف</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ==========================================================================
       بخش جدید: ربات تلگرام
       ========================================================================== */

    // ثبت REST API endpoints
    public function register_rest_routes() {
        register_rest_route('gtbp/v1', '/telegram-webhook', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_telegram_webhook'),
            'permission_callback' => '__return_true',
        ));
    }

    // هندلر وب‌هوک تلگرام
    public function handle_telegram_webhook($request) {
        $token = get_option('gtbp_telegram_token', '');
        $enabled = get_option('gtbp_telegram_enabled', 0);
        if (!$enabled || empty($token)) {
            return new WP_REST_Response(['status' => 'disabled'], 200);
        }
        $body = $request->get_json_params();
        return $this->process_bot_update('telegram', $token, $body);
    }

    // پردازش اصلی پیام‌های ربات (وضعیت در ترنزینت)
    private function process_bot_update($platform, $token, $data) {
        // استخراج chat_id و متن پیام (و گزینه‌های دکمه)
        $chat_id = null;
        $text = null;
        $callback_data = null;
        $message_id = null;
        $callback_query_id = null;
        $incoming_file = null;
        if (isset($data['message'])) {
            $chat_id = $data['message']['chat']['id'];
            $message_id = $data['message']['message_id'] ?? null;
            $text = trim($data['message']['text'] ?? ($data['message']['caption'] ?? ''));
            if (!empty($data['message']['document']['file_id'])) {
                $incoming_file = [
                    'file_id' => $data['message']['document']['file_id'],
                    'file_name' => $data['message']['document']['file_name'] ?? 'telegram-homework-file',
                    'mime_type' => $data['message']['document']['mime_type'] ?? 'application/octet-stream',
                    'kind' => 'document'
                ];
            } elseif (!empty($data['message']['photo']) && is_array($data['message']['photo'])) {
                $photo = end($data['message']['photo']);
                $incoming_file = [
                    'file_id' => $photo['file_id'] ?? '',
                    'file_name' => 'telegram-homework-photo.jpg',
                    'mime_type' => 'image/jpeg',
                    'kind' => 'photo'
                ];
            }
        } elseif (isset($data['callback_query'])) {
            $chat_id = $data['callback_query']['from']['id'];
            $callback_data = $data['callback_query']['data'];
            $callback_query_id = $data['callback_query']['id'] ?? null;
            $message_id = $data['callback_query']['message']['message_id'] ?? null;
            $text = ''; // به‌روزرسانی با دکمه
        }
        if (!$chat_id) {
            return new WP_REST_Response(['status' => 'ok'], 200);
        }
        if ($callback_query_id && $platform === 'telegram') $this->answer_bot_callback($token, $callback_query_id);
        if ($message_id && $platform === 'telegram') $this->delete_bot_message($token, $chat_id, $message_id);

        // بارگذاری وضعیت کاربر (از ترنزینت)
        $state_key = 'gtbp_bot_state_' . $platform . '_' . $chat_id;
        $state = get_transient($state_key);
        if (!$state) {
            $state = ['step' => 'start', 'data' => []];
        }

        // مدیریت /start یا شروع
        if ($text === '/start' || ($state['step'] === 'start' && empty($text) && empty($callback_data))) {
            $welcome = get_option('gtbp_bot_welcome_message', 'سلام! برای رزرو کلاس، لطفاً ایمیل خود را وارد کنید:');
            $this->send_bot_message($platform, $token, $chat_id, $welcome);
            $state = ['step' => 'email', 'data' => []];
            set_transient($state_key, $state, 3600);
            return new WP_REST_Response(['status' => 'ok'], 200);
        }

        // مدیریت Callback (دکمه‌های اینلاین)
        if ($callback_data) {
            $this->handle_bot_callback($platform, $token, $chat_id, $callback_data, $state_key, $state);
            return new WP_REST_Response(['status' => 'ok'], 200);
        }

        // مدیریت متن ورودی (مراحل)
        if ($state['step'] === 'email') {
            $email = sanitize_email($text);
            if (!is_email($email)) {
                $this->send_bot_message($platform, $token, $chat_id, 'ایمیل وارد شده معتبر نیست. لطفاً ایمیل خود را وارد کنید:');
                return new WP_REST_Response(['status' => 'ok'], 200);
            }
            $user = get_user_by('email', $email);
            if (!$user) {
                $this->send_bot_message($platform, $token, $chat_id, 'کاربری با این ایمیل یافت نشد. لطفاً ایمیل خود را دوباره وارد کنید:');
                return new WP_REST_Response(['status' => 'ok'], 200);
            }
            $state['data']['email'] = $email;
            $state['data']['user_id'] = $user->ID;
            $state['step'] = 'phone';
            set_transient($state_key, $state, 3600);
            $this->send_bot_message($platform, $token, $chat_id, 'شماره موبایل خود را وارد کنید:');
            return new WP_REST_Response(['status' => 'ok'], 200);
        }

        if ($state['step'] === 'phone') {
            $phone = sanitize_text_field($text);
            $user_id = $state['data']['user_id'] ?? 0;
            $stored_phone = get_user_meta($user_id, 'billing_phone', true);
            if (empty($stored_phone) || $phone !== $stored_phone) {
                $this->send_bot_message($platform, $token, $chat_id, 'شماره موبایل صحیح نیست. لطفاً شماره موبایل خود را وارد کنید:');
                return new WP_REST_Response(['status' => 'ok'], 200);
            }
            $state['step'] = 'main_menu';
            $state['data']['authenticated'] = true;
            $state['data']['phone'] = $stored_phone;
            update_user_meta($user_id, '_gtbp_telegram_chat_id', $chat_id);
            update_user_meta($user_id, '_gtbp_telegram_platform', $platform);
            set_transient($state_key, $state, 3600);
            $this->show_main_menu($platform, $token, $chat_id);
            return new WP_REST_Response(['status' => 'ok'], 200);
        }

        // دریافت تکلیف از داخل ربات
        if ($state['step'] === 'bot_homework_submit') {
            $this->handle_bot_homework_submission($platform, $token, $chat_id, $text, $incoming_file, $state_key, $state);
            return new WP_REST_Response(['status' => 'ok'], 200);
        }

        // اگر در منوی اصلی باشیم و متنی وارد شود، پیغام خطا
        if ($state['step'] === 'main_menu') {
            $this->show_main_menu($platform, $token, $chat_id);
            return new WP_REST_Response(['status' => 'ok'], 200);
        }

        // مدیریت سایر مراحل (انتخاب کلاس، تاریخ، ساعت، کارت، پرداخت و ...)
        $handled = $this->handle_booking_flow($platform, $token, $chat_id, $text, $state_key, $state);
        if (!$handled) {
            $this->send_bot_message($platform, $token, $chat_id, 'دستور نامعتبر. لطفاً از منوی اصلی استفاده کنید.');
            $this->show_main_menu($platform, $token, $chat_id);
            $state['step'] = 'main_menu';
            set_transient($state_key, $state, 3600);
        }
        return new WP_REST_Response(['status' => 'ok'], 200);
    }

    // نمایش منوی اصلی ربات
    private function show_main_menu($platform, $token, $chat_id) {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '📅 رزرو کلاس جدید', 'callback_data' => 'new_booking'], ['text' => '📋 رزروهای من', 'callback_data' => 'my_bookings']],
                [['text' => '📚 جزوه‌ها', 'callback_data' => 'bot_lessons'], ['text' => '🎥 ویدئوها', 'callback_data' => 'bot_videos']],
                [['text' => '📝 تکالیف من', 'callback_data' => 'bot_homework'], ['text' => '📤 ارسال تکلیف', 'callback_data' => 'bot_submit_homework']],
                [['text' => '🔔 اعلان‌ها', 'callback_data' => 'bot_notifications'], ['text' => '🔗 لینک کلاس', 'callback_data' => 'class_link']],
            ]
        ];
        $this->send_bot_message($platform, $token, $chat_id, 'به ربات کلاس‌های آلمانی خوش آمدید. یکی از گزینه‌ها را انتخاب کنید:', $keyboard);
    }

    // مدیریت کلیک دکمه‌های اینلاین
        private function handle_bot_callback($platform, $token, $chat_id, $callback_data, $state_key, &$state) {
        if ($callback_data === 'bot_back') {
            $current_step = isset($state['step']) ? $state['step'] : 'main_menu';
            if ($current_step === 'select_class') {
                $state['step'] = 'main_menu';
                set_transient($state_key, $state, 3600);
                $this->show_main_menu($platform, $token, $chat_id);
            } elseif ($current_step === 'select_date') {
                $state['step'] = 'select_class';
                set_transient($state_key, $state, 3600);
                $this->show_class_selection($platform, $token, $chat_id);
            } elseif ($current_step === 'select_time') {
                $state['step'] = 'select_date';
                set_transient($state_key, $state, 3600);
                $this->show_date_selection($platform, $token, $chat_id, $state);
            } elseif ($current_step === 'cart_summary') {
                $state['step'] = 'select_date';
                set_transient($state_key, $state, 3600);
                $this->show_date_selection($platform, $token, $chat_id, $state);
            } elseif ($current_step === 'payment_method' || $current_step === 'coupon_code') {
                $state['step'] = 'cart_summary';
                set_transient($state_key, $state, 3600);
                $this->show_cart_summary($platform, $token, $chat_id, $state['data']['cart'] ?? [], $state['data']['discount_code'] ?? '');
            } else {
                $state['step'] = 'main_menu';
                set_transient($state_key, $state, 3600);
                $this->show_main_menu($platform, $token, $chat_id);
            }

        } elseif ($callback_data === 'new_booking') {
            $state['step'] = 'select_class';
            $state['data']['cart'] = [];
            $state['data']['temp_booking_ids'] = [];
            $state['data']['discount_code'] = '';
            $state['data']['date_offset'] = 0;
            set_transient($state_key, $state, 3600);
            $this->show_class_selection($platform, $token, $chat_id);

        } elseif ($callback_data === 'my_bookings') {
            $this->show_user_bookings($platform, $token, $chat_id, $state);

        } elseif ($callback_data === 'class_link') {
            $this->send_class_link($platform, $token, $chat_id, $state);

        } elseif ($callback_data === 'bot_lessons') {
            $this->show_bot_lessons($platform, $token, $chat_id, $state);

        } elseif (strpos($callback_data, 'bot_lesson_') === 0) {
            $this->show_bot_lesson_detail($platform, $token, $chat_id, intval(substr($callback_data, strlen('bot_lesson_'))), $state);

        } elseif ($callback_data === 'bot_videos') {
            $this->show_bot_videos($platform, $token, $chat_id, $state);

        } elseif ($callback_data === 'bot_homework') {
            $this->show_bot_homework($platform, $token, $chat_id, $state);

        } elseif (strpos($callback_data, 'bot_hw_detail_') === 0) {
            $this->show_bot_homework_detail($platform, $token, $chat_id, intval(substr($callback_data, strlen('bot_hw_detail_'))), $state);

        } elseif ($callback_data === 'bot_submit_homework') {
            $this->show_bot_homework_session_picker($platform, $token, $chat_id, $state_key, $state);

        } elseif (strpos($callback_data, 'bot_hw_select_') === 0) {
            $session_id = intval(substr($callback_data, strlen('bot_hw_select_')));
            $state['step'] = 'bot_homework_submit';
            $state['data']['homework_session_id'] = $session_id;
            set_transient($state_key, $state, 3600);
            $this->send_bot_message($platform, $token, $chat_id, "تکلیف خود را برای این جلسه ارسال کنید. می‌توانید متن، عکس یا فایل بفرستید.
برای لغو، از منوی اصلی استفاده کنید.", [
                'inline_keyboard' => [
                    [['text' => '🏠 منوی اصلی', 'callback_data' => 'cancel_booking']]
                ]
            ]);

        } elseif ($callback_data === 'bot_notifications') {
            $this->show_bot_notifications($platform, $token, $chat_id, $state);

        } elseif (strpos($callback_data, 'select_class_') === 0) {
            $class_id = substr($callback_data, strlen('select_class_'));
            $classes = $this->get_classes();
            $selected = null;
            foreach ($classes as $c) {
                if ($c['id'] == $class_id) {
                    $selected = $c;
                    break;
                }
            }
            if ($selected) {
                $state['data']['selected_class'] = $selected;
                unset($state['data']['selected_package']);
                $state['step'] = 'select_date';
                set_transient($state_key, $state, 3600);
                $this->show_date_selection($platform, $token, $chat_id, $state);
            } else {
                $this->send_bot_message($platform, $token, $chat_id, 'کلاس مورد نظر یافت نشد.');
                $this->show_main_menu($platform, $token, $chat_id);
                $state['step'] = 'main_menu';
                set_transient($state_key, $state, 3600);
            }

        } elseif (strpos($callback_data, 'select_plan_') === 0) {
            $package_id = substr($callback_data, strlen('select_plan_'));
            $package = $this->find_package_by_id($package_id);
            if (!$package) {
                $this->send_bot_message($platform, $token, $chat_id, 'طرح مورد نظر یافت نشد.');
                $this->show_main_menu($platform, $token, $chat_id);
                $state['step'] = 'main_menu';
                set_transient($state_key, $state, 3600);
                return;
            }
            $class = $this->find_class_by_id($package['class_id']);
            if (!$class) {
                $this->send_bot_message($platform, $token, $chat_id, 'کلاس مربوط به این طرح یافت نشد.');
                $this->show_main_menu($platform, $token, $chat_id);
                $state['step'] = 'main_menu';
                set_transient($state_key, $state, 3600);
                return;
            }
            $class['package'] = $package;
            $state['data']['selected_class'] = $class;
            $state['data']['selected_package'] = $package;
            $state['data']['cart'] = [];
            $state['data']['discount_code'] = '';
            $state['step'] = 'select_date';
            set_transient($state_key, $state, 3600);
            $this->send_bot_message($platform, $token, $chat_id, 'طرح «' . $package['name'] . '» انتخاب شد. باید دقیقاً ' . intval($package['session_count']) . ' جلسه در بازه ' . intval($package['window_days']) . ' روز از اولین رزرو انتخاب کنید.');
            $this->show_date_selection($platform, $token, $chat_id, $state);

        } elseif (strpos($callback_data, 'select_date_') === 0) {
            $date = substr($callback_data, strlen('select_date_'));
            $state['data']['selected_date'] = $date;
            $state['step'] = 'select_time';
            set_transient($state_key, $state, 3600);
            $this->show_time_slots($platform, $token, $chat_id, $date);

        } elseif (strpos($callback_data, 'select_time_') === 0) {
            $time = substr($callback_data, strlen('select_time_'));
            if (empty($state['data']['selected_class']) || empty($state['data']['selected_date'])) {
                $this->send_bot_message($platform, $token, $chat_id, 'اطلاعات انتخاب کلاس کامل نیست. لطفاً از ابتدا شروع کنید.');
                $this->show_main_menu($platform, $token, $chat_id);
                $state['step'] = 'main_menu';
                set_transient($state_key, $state, 3600);
                return;
            }
            $class = $state['data']['selected_class'];
            $date = $state['data']['selected_date'];
            $jDate = $this->gregorian_to_jalali_string($date);
            $cart = $state['data']['cart'] ?? [];

            if (!empty($state['data']['selected_package'])) {
                $package = $state['data']['selected_package'];
                $required = intval($package['session_count']);
                if (count($cart) >= $required) {
                    $this->send_bot_message($platform, $token, $chat_id, 'تعداد جلسات این پکیج کامل شده است. برای ادامه به پرداخت بروید.');
                    $this->show_cart_summary($platform, $token, $chat_id, $cart, $state['data']['discount_code'] ?? '');
                    return;
                }
            }

            $cart[] = [
                'date' => $date,
                'jDate' => $jDate,
                'time' => $time,
                'class' => $class,
                'package' => isset($class['package']) ? $class['package'] : null
            ];
            $state['data']['cart'] = $cart;
            set_transient($state_key, $state, 3600);

            if (!empty($state['data']['selected_package'])) {
                $package = $state['data']['selected_package'];
                $selected_count = count($cart);
                $required_count = intval($package['session_count']);
                $remaining_count = max(0, $required_count - $selected_count);
                $this->send_bot_message($platform, $token, $chat_id, 'این کلاس به پکیج اضافه شد. تا الان ' . $selected_count . ' کلاس انتخاب کرده‌اید و ' . $remaining_count . ' کلاس دیگر باید انتخاب کنید.');
            }
            $state['step'] = 'cart_summary';
            set_transient($state_key, $state, 3600);

            $this->show_cart_summary($platform, $token, $chat_id, $cart, $state['data']['discount_code'] ?? '');

        } elseif ($callback_data === 'add_more') {
            if (!empty($state['data']['selected_package'])) {
                $state['step'] = 'select_date';
                set_transient($state_key, $state, 3600);
                $this->show_date_selection($platform, $token, $chat_id, $state);
            } else {
                $state['step'] = 'select_class';
                set_transient($state_key, $state, 3600);
                $this->show_class_selection($platform, $token, $chat_id);
            }

        } elseif ($callback_data === 'proceed_payment') {
            $validation = $this->validate_package_cart($state['data']['cart'] ?? []);
            if (empty($validation['valid'])) {
                $this->send_bot_message($platform, $token, $chat_id, $validation['message']);
                $this->show_cart_summary($platform, $token, $chat_id, $state['data']['cart'] ?? [], $state['data']['discount_code'] ?? '');
                return;
            }
            $state['step'] = 'payment_method';
            set_transient($state_key, $state, 3600);
            $this->show_payment_methods($platform, $token, $chat_id, $state);

        } elseif ($callback_data === 'enter_coupon') {
            $state['step'] = 'coupon_code';
            set_transient($state_key, $state, 3600);
            $this->send_bot_message($platform, $token, $chat_id, 'لطفاً کد تخفیف را ارسال کنید. برای برگشت، گزینه زیر را بزنید:', [
                'inline_keyboard' => [
                    [['text' => '🔙 بازگشت به مرحله قبلی', 'callback_data' => 'bot_back']],
                    [['text' => '💳 بازگشت به پرداخت', 'callback_data' => 'proceed_payment']],
                    [['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => 'cancel_booking']]
                ]
            ]);

        } elseif ($callback_data === 'remove_coupon') {
            $state['data']['discount_code'] = '';
            $state['step'] = 'payment_method';
            set_transient($state_key, $state, 3600);
            $this->send_bot_message($platform, $token, $chat_id, 'کد تخفیف حذف شد.');
            $this->show_payment_methods($platform, $token, $chat_id, $state);

        } elseif ($callback_data === 'payment_online') {
            $this->process_online_payment($platform, $token, $chat_id, $state, $state_key);

        } elseif ($callback_data === 'payment_card') {
            $this->process_card_payment($platform, $token, $chat_id, $state, $state_key);

        } elseif ($callback_data === 'confirm_payment') {
            $this->confirm_card_payment($platform, $token, $chat_id, $state, $state_key);

        } elseif ($callback_data === 'outside_iran') {
            $this->mark_outside_iran($platform, $token, $chat_id, $state, $state_key);

        } elseif ($callback_data === 'cancel_booking') {
            $state['step'] = 'main_menu';
            set_transient($state_key, $state, 3600);
            $this->show_main_menu($platform, $token, $chat_id);

        } elseif ($callback_data === 'next_dates') {
            $offset = isset($state['data']['date_offset']) ? $state['data']['date_offset'] : 0;
            $state['data']['date_offset'] = $offset + 3;
            set_transient($state_key, $state, 3600);
            $this->show_date_selection($platform, $token, $chat_id, $state);

        } else {
            $this->send_bot_message($platform, $token, $chat_id, 'گزینه نامعتبر است.');
        }
    }


    // نمایش لیست کلاس‌ها برای انتخاب
    private function show_class_selection($platform, $token, $chat_id) {
        $classes = $this->get_classes();
        $packages = $this->get_active_booking_packages();
        if (empty($classes) && empty($packages)) {
            $this->send_bot_message($platform, $token, $chat_id, 'هیچ کلاسی تعریف نشده است. لطفاً بعداً اقدام کنید.');
            $this->show_main_menu($platform, $token, $chat_id);
            return;
        }
        $keyboard = ['inline_keyboard' => []];
        foreach ($classes as $c) {
            $keyboard['inline_keyboard'][] = [['text' => 'جلسه تکی: ' . $c['name'] . ' - ' . number_format($c['price']) . ' تومان', 'callback_data' => 'select_class_' . $c['id']]];
        }
        foreach ($packages as $pkg) {
            $class = $this->find_class_by_id($pkg['class_id']);
            if (!$class) continue;
            $label = 'طرح: ' . $pkg['name'] . ' | ' . $class['name'] . ' | ' . intval($pkg['session_count']) . ' جلسه | ' . $pkg['discount_percent'] . '٪ تخفیف';
            $keyboard['inline_keyboard'][] = [['text' => $label, 'callback_data' => 'select_plan_' . $pkg['id']]];
        }
        $keyboard['inline_keyboard'][] = [['text' => '🔙 بازگشت به مرحله قبلی', 'callback_data' => 'bot_back']];
        $keyboard['inline_keyboard'][] = [['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => 'cancel_booking']];
        $this->send_bot_message($platform, $token, $chat_id, 'لطفاً نوع کلاس یا طرح چندجلسه‌ای مورد نظر خود را انتخاب کنید:', $keyboard);
    }

    // نمایش تاریخ‌های قابل رزرو (۳ روز با قابلیت مشاهده بیشتر)
        private function show_date_selection($platform, $token, $chat_id, $state) {
        $offset = isset($state['data']['date_offset']) ? $state['data']['date_offset'] : 0;
        $limit = 3;
        $dates = $this->get_available_dates_for_bot($limit, $offset);
        if (empty($dates)) {
            $this->send_bot_message($platform, $token, $chat_id, 'هیچ تاریخ قابل رزرو دیگری یافت نشد.');
            $this->show_main_menu($platform, $token, $chat_id);
            return;
        }

        $weekdays = [
            6 => 'شنبه',
            0 => 'یکشنبه',
            1 => 'دوشنبه',
            2 => 'سه‌شنبه',
            3 => 'چهارشنبه',
            4 => 'پنج‌شنبه',
            5 => 'جمعه'
        ];

        $keyboard = ['inline_keyboard' => []];
        foreach ($dates as $date => $j_date) {
            $j_parts = explode('/', $j_date);
            $month = isset($j_parts[1]) ? ltrim($j_parts[1], '0') : '';
            $day = isset($j_parts[2]) ? ltrim($j_parts[2], '0') : '';
            $short_date = $month . '/' . $day;
            $dow = (int)date('w', strtotime($date));
            $weekday = isset($weekdays[$dow]) ? $weekdays[$dow] : '';
            $button_text = $weekday . ' ' . $short_date . ' (' . $this->format_gregorian_display($date) . ')';
            $keyboard['inline_keyboard'][] = [['text' => $button_text, 'callback_data' => 'select_date_' . $date]];
        }
        $more_exists = $this->has_more_available_dates($offset + $limit);
        if ($more_exists) {
            $keyboard['inline_keyboard'][] = [['text' => '📆 نمایش تاریخ های بیشتر', 'callback_data' => 'next_dates']];
        }
        $keyboard['inline_keyboard'][] = [['text' => '🔙 بازگشت به مرحله قبلی', 'callback_data' => 'bot_back']];
        $keyboard['inline_keyboard'][] = [['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => 'cancel_booking']];
        $this->send_bot_message($platform, $token, $chat_id, 'تاریخ مورد نظر خود را انتخاب کنید:', $keyboard);
    }


    // نمایش ساعات آزاد برای یک تاریخ
    private function show_time_slots($platform, $token, $chat_id, $date) {
        $hours = $this->get_working_hours();
        $day_of_week = date('w', strtotime($date));
        $day_settings = null;
        foreach ($hours as $h) {
            if ($h['day'] == $day_of_week) {
                $day_settings = $h;
                break;
            }
        }
        if (!$day_settings || !$day_settings['active'] || empty(trim($day_settings['slots']))) {
            $this->send_bot_message($platform, $token, $chat_id, 'در این تاریخ ساعتی تعریف نشده است.');
            return;
        }
        $slots_array = array_filter(array_map('trim', explode(',', $day_settings['slots'])));
        if (!empty($day_settings['priority_slots'])) {
            $priority_slots = array_filter(array_map('trim', explode(',', $day_settings['priority_slots'])));
            $slots_array = array_values(array_intersect($slots_array, $priority_slots));
        }
        global $wpdb;
        $booked_slots = $wpdb->get_col($wpdb->prepare(
            "SELECT booking_time FROM {$this->table_name} WHERE booking_date = %s AND status != 'cancelled'",
            $date
        ));
        $tz = new DateTimeZone('Asia/Tehran');
        $now = new DateTime('now', $tz);
        $now_ts = $now->getTimestamp();
        $available = [];
        foreach ($slots_array as $slot) {
            $slot_start_time = explode('-', $slot)[0];
            $slot_dt = new DateTime($date . ' ' . $slot_start_time . ':00', $tz);
            $slot_ts = $slot_dt->getTimestamp();
            $diff_hours = ($slot_ts - $now_ts) / 3600;
            if ($diff_hours < 24) {
                continue; // غیرفعال به دلیل قانون ۲۴ ساعت
            }
            // نسخه ۱۴.۱: تشخیص هم‌پوشانی زمانی (نه فقط تطابق دقیق)
            if (!$this->slot_conflicts_with_bookings($date, $slot, 0, $booked_slots)) {
                $available[] = $slot;
            }
        }
        if (empty($available)) {
            $this->send_bot_message($platform, $token, $chat_id, 'هیچ ساعت آزادی برای این تاریخ وجود ندارد.');
            return;
        }
        $keyboard = ['inline_keyboard' => []];
        foreach ($available as $slot) {
            $keyboard['inline_keyboard'][] = [['text' => $this->get_bot_slot_label($slot), 'callback_data' => 'select_time_' . $slot]];
        }
        $keyboard['inline_keyboard'][] = [['text' => '🔙 بازگشت به مرحله قبلی', 'callback_data' => 'bot_back']];
        $keyboard['inline_keyboard'][] = [['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => 'cancel_booking']];
        $this->send_bot_message($platform, $token, $chat_id, 'ساعت مورد نظر را انتخاب کنید:', $keyboard);
    }

    // نمایش خلاصه سبد
        private function show_cart_summary($platform, $token, $chat_id, $cart, $discount_code = '') {
        if (empty($cart)) {
            $this->show_main_menu($platform, $token, $chat_id);
            return;
        }
        $totals = $this->calculate_cart_totals($cart, $discount_code, 0);
        $package = $totals['package'];
        $validation = $this->validate_package_cart($cart, $package);
        $text = "سبد رزرو شما:\n";
        foreach ($cart as $item) {
            $price = $item['class']['price'];
            $gDate = $this->format_gregorian_display($item['date']);
            $text .= "- {$item['class']['name']} | {$item['jDate']} ({$gDate}) | {$item['time']} | " . number_format($price) . " تومان\n";
        }
        $text .= "\nجمع کلاس‌ها: " . number_format($totals['subtotal']) . " تومان";
        if ($package) {
            $selected_count = count($cart);
            $required_count = intval($package['session_count']);
            $remaining_count = max(0, $required_count - $selected_count);
            $text .= "\nطرح انتخابی: {$package['name']}";
            $text .= "\nتعداد کلاس‌های انتخاب‌شده: {$selected_count} از {$required_count}";
            $text .= "\nتعداد باقی‌مانده برای تکمیل پکیج: {$remaining_count}";
            $text .= "\nوضعیت طرح: " . ($validation['valid'] ? 'کامل و قابل پرداخت' : $validation['message']);
            $text .= "\nتخفیف طرح: " . number_format($totals['package_discount_amount']) . " تومان";
        }
        if (!empty($discount_code)) {
            if ($totals['coupon_discount_amount'] > 0) {
                $text .= "\nکد تخفیف اعمال‌شده: " . esc_html($discount_code) . " (" . $totals['coupon_discount_percent'] . "٪)";
                $text .= "\nمبلغ تخفیف کد: " . number_format($totals['coupon_discount_amount']) . " تومان";
            } else {
                $text .= "\nکد تخفیف واردشده معتبر نیست یا تخفیفی ایجاد نکرده است.";
            }
        }
        $text .= "\nمبلغ پس از تخفیف‌ها: " . number_format($totals['after_discount']) . " تومان";
        $keyboard_rows = [];
        if (!$package || count($cart) < intval($package['session_count'])) {
            $keyboard_rows[] = [['text' => '➕ افزودن کلاس دیگر', 'callback_data' => 'add_more']];
        }
        if (!empty($validation['valid'])) {
            $keyboard_rows[] = [['text' => '💳 ادامه به پرداخت', 'callback_data' => 'proceed_payment']];
        }
        $keyboard_rows[] = [['text' => '🔙 بازگشت به مرحله قبلی', 'callback_data' => 'bot_back']];
        $keyboard_rows[] = [['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => 'cancel_booking']];
        $keyboard_rows[] = [['text' => '❌ انصراف', 'callback_data' => 'cancel_booking']];
        $keyboard = ['inline_keyboard' => $keyboard_rows];
        $this->send_bot_message($platform, $token, $chat_id, $text, $keyboard);
    }


    // نمایش روش‌های پرداخت
        private function show_payment_methods($platform, $token, $chat_id, $state = null) {
        $online_enabled = get_option('gtbp_payment_online_enabled', '1') === '1';
        $card_enabled = get_option('gtbp_payment_card_enabled', '1') === '1';
        if (!$online_enabled && !$card_enabled) {
            $this->send_bot_message($platform, $token, $chat_id, 'در حال حاضر هیچ روش پرداختی فعال نیست. لطفاً بعداً اقدام کنید.');
            $this->show_main_menu($platform, $token, $chat_id);
            return;
        }

        $discount_code = '';
        if (is_array($state) && !empty($state['data']['discount_code'])) {
            $discount_code = sanitize_text_field($state['data']['discount_code']);
        }

        $keyboard = ['inline_keyboard' => []];
        if (empty($discount_code)) {
            $keyboard['inline_keyboard'][] = [['text' => '🎟 وارد کردن کد تخفیف', 'callback_data' => 'enter_coupon']];
        } else {
            $keyboard['inline_keyboard'][] = [['text' => '🎟 کد اعمال شده: ' . $discount_code, 'callback_data' => 'enter_coupon']];
            $keyboard['inline_keyboard'][] = [['text' => '🗑 حذف کد تخفیف', 'callback_data' => 'remove_coupon']];
        }
        if ($online_enabled) {
            $keyboard['inline_keyboard'][] = [['text' => '💳 پرداخت آنلاین', 'callback_data' => 'payment_online']];
        }
        if ($card_enabled) {
            $keyboard['inline_keyboard'][] = [['text' => '🏦 پرداخت کارت به کارت', 'callback_data' => 'payment_card']];
        }
        $keyboard['inline_keyboard'][] = [['text' => '🔙 بازگشت به مرحله قبلی', 'callback_data' => 'bot_back']];
        $keyboard['inline_keyboard'][] = [['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => 'cancel_booking']];

        $message = 'روش پرداخت خود را انتخاب کنید:';
        if (!empty($discount_code)) {
            $message .= "\nکد تخفیف فعلی: " . $discount_code;
        } else {
            $message .= "\nدر صورت داشتن کد تخفیف، قبل از انتخاب روش پرداخت آن را وارد کنید.";
        }
        $this->send_bot_message($platform, $token, $chat_id, $message, $keyboard);
    }


    // پردازش پرداخت آنلاین (ووکامرس)
        private function process_online_payment($platform, $token, $chat_id, &$state, $state_key) {
        $cart = $state['data']['cart'] ?? [];
        if (empty($cart)) {
            $this->send_bot_message($platform, $token, $chat_id, 'سبد خرید خالی است.');
            $this->show_main_menu($platform, $token, $chat_id);
            $state['step'] = 'main_menu';
            set_transient($state_key, $state, 3600);
            return;
        }

        $user_id = $state['data']['user_id'] ?? 0;
        $user = get_userdata($user_id);
        if (!$user) {
            $this->send_bot_message($platform, $token, $chat_id, 'خطا در شناسایی کاربر. لطفاً دوباره /start را بزنید.');
            return;
        }

        $validation = $this->validate_package_cart($cart);
        if (empty($validation['valid'])) {
            $this->send_bot_message($platform, $token, $chat_id, $validation['message']);
            $this->show_cart_summary($platform, $token, $chat_id, $cart, $state['data']['discount_code'] ?? '');
            return;
        }

        $discount_code = isset($state['data']['discount_code']) ? sanitize_text_field($state['data']['discount_code']) : '';
        if (!empty($discount_code)) {
            $coupon_check = $this->validate_coupon_code_for_user($discount_code, $user_id);
            if (empty($coupon_check['valid'])) {
                $state['data']['discount_code'] = '';
                set_transient($state_key, $state, 3600);
                $this->send_bot_message($platform, $token, $chat_id, 'کد تخفیف قبلی معتبر نیست و حذف شد: ' . $coupon_check['message']);
                $discount_code = '';
            }
        }

        $totals = $this->calculate_cart_totals($cart, $discount_code, $user_id);
        $subtotal = $totals['subtotal'];
        $user_adjustment = $totals['user_adjustment'];
        $payable_total = $totals['payable_total'];

        if ($payable_total == 0) {
            $this->direct_register_booking($platform, $token, $chat_id, $state, $state_key);
            return;
        }

        if (!class_exists('WooCommerce')) {
            $this->send_bot_message($platform, $token, $chat_id, 'سیستم پرداخت آنلاین در دسترس نیست. لطفاً از روش کارت به کارت استفاده کنید.');
            $this->show_payment_methods($platform, $token, $chat_id, $state);
            return;
        }

        global $wpdb;
        foreach ($cart as $item) {
            $date = sanitize_text_field($item['date']);
            $time = sanitize_text_field($item['time']);
            // نسخه ۱۴.۱: تشخیص هم‌پوشانی زمانی (نه فقط تطابق دقیق)
            $exists = $this->slot_conflicts_with_bookings($date, $time);
            if ($exists) {
                $this->send_bot_message($platform, $token, $chat_id, "متأسفانه زمان {$time} در تاریخ {$date} همین حالا رزرو شده است. لطفاً سبد را اصلاح کنید.");
                $this->show_main_menu($platform, $token, $chat_id);
                $state['step'] = 'main_menu';
                set_transient($state_key, $state, 3600);
                return;
            }
        }

        $order = wc_create_order();
        $order->set_customer_id($user_id);

        foreach ($cart as $item) {
            $class_id = isset($item['class']['id']) ? $item['class']['id'] : '';
            $product_added = false;
            if (!empty($class_id)) {
                $posts = get_posts([
                    'post_type' => 'product',
                    'meta_key' => '_gtbp_class_id',
                    'meta_value' => $class_id,
                    'posts_per_page' => 1
                ]);
                if ($posts) {
                    $product = wc_get_product($posts[0]->ID);
                    if ($product) {
                        $order->add_product($product, 1);
                        $product_added = true;
                    }
                }
            }
            if (!$product_added) {
                $fee = new WC_Order_Item_Fee();
                $fee->set_name('رزرو کلاس: ' . sanitize_text_field($item['class']['name']));
                $fee->set_amount(floatval($item['class']['price']));
                $fee->set_total(floatval($item['class']['price']));
                $order->add_item($fee);
            }
        }

        if (!empty($totals['package_discount_amount'])) {
            $fee = new WC_Order_Item_Fee();
            $package_name = !empty($totals['package']['name']) ? $totals['package']['name'] : 'طرح چندجلسه‌ای';
            $fee->set_name('تخفیف طرح چندجلسه‌ای (' . $package_name . ')');
            $fee->set_amount(-$totals['package_discount_amount']);
            $fee->set_total(-$totals['package_discount_amount']);
            $order->add_item($fee);
            $order->update_meta_data('_gtbp_package_id', $totals['package']['id']);
            $order->update_meta_data('_gtbp_package_name', $package_name);
            $order->update_meta_data('_gtbp_package_discount_amount', $totals['package_discount_amount']);
            $order->update_meta_data('_gtbp_package_discount_percent', $totals['package_discount_percent']);
        }

        if (!empty($discount_code) && $totals['coupon_discount_amount'] > 0) {
            $fee = new WC_Order_Item_Fee();
            $fee->set_name('تخفیف سفارشی (' . $discount_code . ')');
            $fee->set_amount(-$totals['coupon_discount_amount']);
            $fee->set_total(-$totals['coupon_discount_amount']);
            $order->add_item($fee);
            $order->update_meta_data('_gtbp_discount_code', $discount_code);
            $order->update_meta_data('_gtbp_discount_percent', $totals['coupon_discount_percent']);
            $order->update_meta_data('_gtbp_discount_amount', $totals['coupon_discount_amount']);
        }

        if ($user_adjustment != 0) {
            $fee = new WC_Order_Item_Fee();
            $fee->set_name($user_adjustment > 0 ? 'بدهی کاربر (اضافی)' : 'طلب کاربر (تخفیف ویژه)');
            $fee->set_amount($user_adjustment);
            $fee->set_total($user_adjustment);
            $order->add_item($fee);
            $order->update_meta_data('_gtbp_user_adjustment_applied', $user_adjustment);
        }

        $order->calculate_totals();
        $order->update_meta_data('_gtbp_pending_cart', $cart);
        $order->update_meta_data('_gtbp_source_url', home_url());
        $order->update_meta_data('_gtbp_bot_chat_id', $chat_id);
        $order->update_meta_data('_gtbp_bot_platform', $platform);
        $order->save();

        $redirect_url = $order->get_checkout_payment_url();
        $available_gateways = WC()->payment_gateways->get_available_payment_gateways();
        if (!empty($available_gateways)) {
            $gateway = current($available_gateways);
            $order->set_payment_method($gateway);
            $order->save();
            try {
                $result = $gateway->process_payment($order->get_id());
                if (isset($result['result']) && $result['result'] === 'success' && !empty($result['redirect'])) {
                    $redirect_url = $result['redirect'];
                }
            } catch (Exception $e) {
                error_log('GTBP Bot payment redirect error: ' . $e->getMessage());
            }
        }

        $state['step'] = 'main_menu';
        set_transient($state_key, $state, 3600);

        $message = "قبل از پرداخت، لطفاً فیلترشکن/VPN را خاموش کنید.\n";
        $message .= "با پرداخت موفق، حتی اگر به ربات برنگردید، رزرو شما توسط ووکامرس نهایی و در افزونه ثبت می‌شود.\n";
        $message .= "مبلغ قابل پرداخت: " . number_format($order->get_total()) . " تومان";
        $keyboard = ['inline_keyboard' => [
            [['text' => '💳 ورود مستقیم به درگاه پرداخت', 'url' => $redirect_url]],
            [['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => 'cancel_booking']]
        ]];
        $this->send_bot_message($platform, $token, $chat_id, $message, $keyboard);
    }


    // پردازش پرداخت کارت به کارت
        private function process_card_payment($platform, $token, $chat_id, &$state, $state_key) {
        $cart = $state['data']['cart'] ?? [];
        if (empty($cart)) {
            $this->send_bot_message($platform, $token, $chat_id, 'سبد خرید خالی است.');
            $this->show_main_menu($platform, $token, $chat_id);
            $state['step'] = 'main_menu';
            set_transient($state_key, $state, 3600);
            return;
        }
        $user_id = $state['data']['user_id'] ?? 0;
        $user = get_userdata($user_id);
        if (!$user) {
            $this->send_bot_message($platform, $token, $chat_id, 'خطا در شناسایی کاربر. لطفاً دوباره /start را بزنید.');
            return;
        }
        $validation = $this->validate_package_cart($cart);
        if (empty($validation['valid'])) {
            $this->send_bot_message($platform, $token, $chat_id, $validation['message']);
            $this->show_cart_summary($platform, $token, $chat_id, $cart, $state['data']['discount_code'] ?? '');
            return;
        }

        $discount_code = isset($state['data']['discount_code']) ? sanitize_text_field($state['data']['discount_code']) : '';
        if (!empty($discount_code)) {
            $coupon_check = $this->validate_coupon_code_for_user($discount_code, $user_id);
            if (empty($coupon_check['valid'])) {
                $state['data']['discount_code'] = '';
                set_transient($state_key, $state, 3600);
                $this->send_bot_message($platform, $token, $chat_id, 'کد تخفیف قبلی معتبر نیست و حذف شد: ' . $coupon_check['message']);
                $discount_code = '';
            }
        }

        $totals = $this->calculate_cart_totals($cart, $discount_code, $user_id);
        $payable_total = $totals['payable_total'];
        if ($payable_total == 0) {
            $this->direct_register_booking($platform, $token, $chat_id, $state, $state_key);
            return;
        }

        global $wpdb;
        $f = $user->first_name ? $user->first_name : $user->display_name;
        $l = $user->last_name;
        $e = $user->user_email;
        $p = get_user_meta($user_id, 'billing_phone', true);
        if (empty($p)) $p = 'ثبت نشده';
        $jitsi_rooms = [];
        $inserted_ids = [];
        $student_name = trim($f . ' ' . $l);
        $teacher_name = get_option('gtbp_bbb_teacher_name', self::BBB_DEFAULT_TEACHER_NAME);

        foreach ($cart as $item) {
            $date = sanitize_text_field($item['date']);
            $time = sanitize_text_field($item['time']);
            // نسخه ۱۴.۱: تشخیص هم‌پوشانی زمانی (نه فقط تطابق دقیق)
            $exists = $this->slot_conflicts_with_bookings($date, $time);
            if ($exists) {
                $this->send_bot_message($platform, $token, $chat_id, "متأسفانه زمان {$time} در تاریخ {$date} همین حالا رزرو شده است.");
                $this->show_main_menu($platform, $token, $chat_id);
                return;
            }
        }

        foreach ($cart as $item) {
            $date = sanitize_text_field($item['date']);
            $time = sanitize_text_field($item['time']);
            $class_name = sanitize_text_field($item['class']['name']);
            if (!empty($item['package']['name'])) { $class_name .= ' - ' . sanitize_text_field($item['package']['name']); }
            $jDate = $item['jDate'];
            $room_name = $jDate . ' - ' . $student_name;
            $room = $this->create_jitsi_room($room_name, $student_name, $teacher_name);
            $meeting_id = $room ? $room['meeting_id'] : null;
            $student_link = $room ? $room['student_link'] : null;
            $teacher_link = $room ? $room['teacher_link'] : null;
            if ($room) {
                $jitsi_rooms[] = ['date' => $date, 'time' => $time, 'class_name' => $class_name, 'student_link' => $student_link, 'teacher_link' => $teacher_link];
            }
            $wpdb->insert($this->table_name, [
                'first_name' => $f, 'last_name' => $l, 'email' => $e, 'phone' => $p,
                'booking_date' => $date, 'booking_time' => $time, 'class_name' => $class_name,
                'status' => 'temp_card', 'roomeet_room_id' => $meeting_id,
                'roomeet_join_link' => $student_link, 'bbb_moderator_link' => $teacher_link,
                'telegram_chat_id' => (string)$chat_id, 'telegram_platform' => $platform
            ]);
            $inserted_ids[] = $wpdb->insert_id;
        }

        $state['data']['temp_booking_ids'] = $inserted_ids;
        set_transient($state_key, $state, 3600);

        $bank = $this->get_bank_info();
        $message = "لطفاً مبلغ " . number_format($payable_total) . " تومان را به کارت زیر واریز کنید:\n\n";
        $message .= "کارت: {$bank['card']}\nبه نام: {$bank['owner']}\n\n";
        if (!empty($discount_code) && $totals['coupon_discount_amount'] > 0) {
            $message .= "کد تخفیف اعمال‌شده: {$discount_code}\n";
        }
        $message .= "پس از واریز، روی دکمه زیر کلیک کنید:";
        $keyboard = ['inline_keyboard' => [
            [['text' => '✅ پرداخت انجام شد', 'callback_data' => 'confirm_payment']],
        ]];
        $keyboard['inline_keyboard'][] = [['text' => '⏳ کارت‌به‌کارت با مهلت ۲۴ ساعته', 'callback_data' => 'outside_iran']];
        $keyboard['inline_keyboard'][] = [['text' => '🏠 بازگشت به منوی اصلی', 'callback_data' => 'cancel_booking']];
        $this->send_bot_message($platform, $token, $chat_id, $message, $keyboard);
    }


    // تایید پرداخت کارت به کارت
        private function confirm_card_payment($platform, $token, $chat_id, &$state, $state_key) {
        $temp_ids = $state['data']['temp_booking_ids'] ?? [];
        if (empty($temp_ids)) {
            $this->send_bot_message($platform, $token, $chat_id, 'هیچ رزرو موقتی یافت نشد.');
            $this->show_main_menu($platform, $token, $chat_id);
            $state['step'] = 'main_menu';
            set_transient($state_key, $state, 3600);
            return;
        }
        global $wpdb;
        foreach ($temp_ids as $id) {
            $wpdb->update($this->table_name, ['status' => 'confirmed'], ['id' => intval($id)]);
            $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", intval($id)));
            $this->update_user_jitsi_links($state['data']['user_id'], $booking);
        }

        $user_id = $state['data']['user_id'];
        $user = get_userdata($user_id);
        $cart = $state['data']['cart'];
        $discount_code = isset($state['data']['discount_code']) ? sanitize_text_field($state['data']['discount_code']) : '';
        $totals = $this->calculate_cart_totals($cart, $discount_code, $user_id);
        if (!empty($discount_code) && $totals['coupon_discount_amount'] > 0) {
            $this->increase_coupon_usage_if_needed($user_id, $discount_code);
        }

        // نسخه ۱۴.۳: تسویه‌ی بدهی/طلبِ کاربر پس از پرداخت کارت‌به‌کارت در ربات
        // (کل مبلغ تعدیل در مبلغ قابل پرداخت اعمال شده بود).
        if (intval($totals['user_adjustment']) != 0) {
            $current_adj = $this->get_user_adjustment($user_id);
            $this->set_user_adjustment($user_id, $current_adj - intval($totals['user_adjustment']));
        }

        $this->send_booking_emails($user->first_name, $user->last_name, $user->user_email, get_user_meta($user_id, 'billing_phone', true), $cart, $totals['payable_total'], false, true, []);
        $this->send_bot_message($platform, $token, $chat_id, '✅ پرداخت شما تایید شد و رزرو شما قطعی گردید. لینک کلاس ۳ دقیقه قبل از شروع همین‌جا در ربات و در حساب کاربری شما فعال می‌شود.');
        $state['step'] = 'main_menu';
        set_transient($state_key, $state, 3600);
        $this->show_main_menu($platform, $token, $chat_id);
    }


    // ثبت گزینه خارج از ایران
    private function mark_outside_iran($platform, $token, $chat_id, &$state, $state_key) {
        $temp_ids = $state['data']['temp_booking_ids'] ?? [];
        if (empty($temp_ids)) {
            $this->send_bot_message($platform, $token, $chat_id, 'هیچ رزرو موقتی یافت نشد.');
            $this->show_main_menu($platform, $token, $chat_id);
            $state['step'] = 'main_menu';
            set_transient($state_key, $state, 3600);
            return;
        }
        global $wpdb;
        foreach ($temp_ids as $id) {
            $wpdb->update($this->table_name, ['status' => 'pending_card', 'outside_iran' => 1, 'telegram_chat_id' => (string)$chat_id, 'telegram_platform' => $platform], ['id' => $id]);
        }
        $this->send_bot_message($platform, $token, $chat_id, '⏳ مهلت ۲۴ ساعته پرداخت ثبت شد. لطفاً مبلغ را کارت‌به‌کارت کنید و پس از واریز، همین‌جا در ربات روی دکمه «پرداخت انجام شد» بزنید. اگر تا ۱۸ ساعت دیگر پرداخت ثبت نشود، یک یادآوری مودبانه برای شما ارسال می‌شود.');
        $state['step'] = 'main_menu';
        set_transient($state_key, $state, 3600);
        $this->show_main_menu($platform, $token, $chat_id);
    }

    // ثبت مستقیم رزرو بدون پرداخت (زمانی که مبلغ صفر است)
        private function direct_register_booking($platform, $token, $chat_id, &$state, $state_key) {
        $cart = $state['data']['cart'] ?? [];
        if (empty($cart)) {
            $this->send_bot_message($platform, $token, $chat_id, 'سبد خرید خالی است.');
            $this->show_main_menu($platform, $token, $chat_id);
            $state['step'] = 'main_menu';
            set_transient($state_key, $state, 3600);
            return;
        }
        $user_id = $state['data']['user_id'];
        $user = get_userdata($user_id);
        $discount_code = isset($state['data']['discount_code']) ? sanitize_text_field($state['data']['discount_code']) : '';
        $totals = $this->calculate_cart_totals($cart, $discount_code, $user_id);

        $f = $user->first_name ? $user->first_name : $user->display_name;
        $l = $user->last_name;
        $e = $user->user_email;
        $p = get_user_meta($user_id, 'billing_phone', true);
        if (empty($p)) $p = 'ثبت نشده';
        $jitsi_rooms = [];
        $student_name = trim($f . ' ' . $l);
        $teacher_name = get_option('gtbp_bbb_teacher_name', self::BBB_DEFAULT_TEACHER_NAME);
        global $wpdb;

        foreach ($cart as $item) {
            $date = sanitize_text_field($item['date']);
            $time = sanitize_text_field($item['time']);
            // نسخه ۱۴.۱: تشخیص هم‌پوشانی زمانی (نه فقط تطابق دقیق)
            $exists = $this->slot_conflicts_with_bookings($date, $time);
            if ($exists) {
                $this->send_bot_message($platform, $token, $chat_id, "متأسفانه زمان {$time} در تاریخ {$date} همین حالا رزرو شده است.");
                $this->show_main_menu($platform, $token, $chat_id);
                return;
            }
        }

        foreach ($cart as $item) {
            $date = sanitize_text_field($item['date']);
            $time = sanitize_text_field($item['time']);
            $class_name = sanitize_text_field($item['class']['name']);
            if (!empty($item['package']['name'])) { $class_name .= ' - ' . sanitize_text_field($item['package']['name']); }
            $jDate = $item['jDate'];
            $room_name = $jDate . ' - ' . $student_name;
            $room = $this->create_jitsi_room($room_name, $student_name, $teacher_name);
            $meeting_id = $room ? $room['meeting_id'] : null;
            $student_link = $room ? $room['student_link'] : null;
            $teacher_link = $room ? $room['teacher_link'] : null;
            if ($room) {
                $jitsi_rooms[] = ['date' => $date, 'time' => $time, 'class_name' => $class_name, 'student_link' => $student_link, 'teacher_link' => $teacher_link];
            }
            $wpdb->insert($this->table_name, [
                'first_name' => $f, 'last_name' => $l, 'email' => $e, 'phone' => $p,
                'booking_date' => $date, 'booking_time' => $time, 'class_name' => $class_name,
                'status' => 'confirmed', 'roomeet_room_id' => $meeting_id,
                'roomeet_join_link' => $student_link, 'bbb_moderator_link' => $teacher_link,
                'telegram_chat_id' => (string)$chat_id, 'telegram_platform' => $platform
            ]);
            $booking_id = $wpdb->insert_id;
                if ($booking_id && class_exists('GTBP_Learning_Dashboard_v211')) { GTBP_Learning_Dashboard_v211::instance()->create_session_from_booking_id($booking_id); }
            if ($booking_id) {
                $booking = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->table_name} WHERE id = %d", $booking_id));
                $this->update_user_jitsi_links($user_id, $booking);
            }
        }

        if (!empty($discount_code) && $totals['coupon_discount_amount'] > 0) {
            $this->increase_coupon_usage_if_needed($user_id, $discount_code);
        }

        $this->send_booking_emails($f, $l, $e, $p, $cart, $totals['payable_total'], false, false, $jitsi_rooms);
        $this->send_bot_message($platform, $token, $chat_id, '✅ رزرو شما با موفقیت ثبت شد. لینک کلاس ۳ دقیقه قبل از شروع همین‌جا در ربات و در حساب کاربری شما فعال می‌شود.');
        $state['step'] = 'main_menu';
        set_transient($state_key, $state, 3600);
        $this->show_main_menu($platform, $token, $chat_id);
    }



    private function bot_user_from_state($state) {
        $user_id = intval($state['data']['user_id'] ?? 0);
        return $user_id ? get_userdata($user_id) : false;
    }

    private function bot_user_sessions($user_id, $limit = 10) {
        global $wpdb;
        $user = get_userdata($user_id);
        if (!$user) return [];
        $table = $wpdb->prefix . 'gls_sessions';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) return [];
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE session_status='published' AND (user_id=%d OR student_email=%s) ORDER BY booking_date DESC, id DESC LIMIT %d",
            $user_id, $user->user_email, intval($limit)
        ));
    }

    private function bot_strip($html, $limit = 2800) {
        $text = wp_strip_all_tags((string)$html);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        if (function_exists('mb_strlen') && mb_strlen($text, 'UTF-8') > $limit) {
            return mb_substr($text, 0, $limit, 'UTF-8') . '…';
        }
        if (strlen($text) > $limit) return substr($text, 0, $limit) . '…';
        return $text;
    }

    private function show_bot_lessons($platform, $token, $chat_id, $state) {
        $user = $this->bot_user_from_state($state);
        if (!$user) { $this->send_bot_message($platform, $token, $chat_id, 'لطفاً دوباره /start را بزنید.'); return; }
        $sessions = $this->bot_user_sessions($user->ID, 12);
        if (!$sessions) { $this->send_bot_message($platform, $token, $chat_id, 'فعلاً جزوه‌ای برای شما ثبت نشده است.'); $this->show_main_menu($platform,$token,$chat_id); return; }
        $keyboard = ['inline_keyboard'=>[]];
        foreach ($sessions as $s) {
            $keyboard['inline_keyboard'][] = [[ 'text' => '📚 ' . $s->jalali_date . ' | ' . $s->class_name, 'callback_data' => 'bot_lesson_' . intval($s->id) ]];
        }
        $keyboard['inline_keyboard'][] = [[ 'text' => '🏠 منوی اصلی', 'callback_data' => 'cancel_booking' ]];
        $this->send_bot_message($platform, $token, $chat_id, 'جزوه‌های شما:', $keyboard);
    }

    private function show_bot_lesson_detail($platform, $token, $chat_id, $session_id, $state) {
        $user = $this->bot_user_from_state($state);
        if (!$user) return;
        global $wpdb;
        $table = $wpdb->prefix . 'gls_sessions';
        $s = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d AND (user_id=%d OR student_email=%s)", $session_id, $user->ID, $user->user_email));
        if (!$s) { $this->send_bot_message($platform,$token,$chat_id,'این جزوه یافت نشد.'); return; }
        $text = "📚 <b>جزوه جلسه {$s->jalali_date}</b>\n";
        $text .= "کلاس: " . esc_html($s->class_name) . "\n\n";
        $lesson = $this->bot_strip($s->lesson_content, 2600);
        if ($lesson) $text .= $lesson . "\n";
        if (!empty($s->ai_reading_content)) {
            $text .= "\n<b>Lesetext</b>\n" . $this->bot_strip($s->ai_reading_content, 900);
        }
        if (trim($text)==='') $text = 'برای این جلسه هنوز جزوه‌ای ثبت نشده است.';
        $this->send_bot_message($platform, $token, $chat_id, $text, ['inline_keyboard'=>[[['text'=>'🔙 جزوه‌ها','callback_data'=>'bot_lessons'],['text'=>'🏠 منوی اصلی','callback_data'=>'cancel_booking']]]]);
    }

    private function show_bot_videos($platform, $token, $chat_id, $state) {
        $handled = apply_filters('gtbp_bot_videos_handled', false, $platform, $token, $chat_id, $state, $this);
        if ($handled) return;
        $user = $this->bot_user_from_state($state);
        if (!$user) return;
        $sessions = $this->bot_user_sessions($user->ID, 20);
        $text = "🎥 <b>ویدئوهای کلاس</b>\n";
        $found = 0;
        foreach ($sessions as $s) {
            // نسخه ۱۴.۱: ویدئوی مؤثر از هر سرویس (BBB > Meet > روومیت)
            $vid = trim((string)($s->video_url ?? ''));
            $label = 'BigBlueButton';
            if ($vid === '' && isset($s->meet_video_url) && trim((string)$s->meet_video_url) !== '') { $vid = trim((string)$s->meet_video_url); $label = 'Google Meet'; }
            if ($vid === '' && isset($s->rmt_video_url)  && trim((string)$s->rmt_video_url) !== '')  { $vid = trim((string)$s->rmt_video_url);  $label = 'روومیت'; }
            if ($vid !== '') {
                $found++;
                $text .= "\n{$found}. {$s->jalali_date} | {$s->class_name} ({$label})\n" . esc_url($vid) . "\n";
            }
        }
        if (!$found) $text = 'فعلاً ویدئوی ضبط‌شده‌ای برای شما ثبت نشده است.';
        $this->send_bot_message($platform, $token, $chat_id, $text, ['inline_keyboard'=>[[['text'=>'🏠 منوی اصلی','callback_data'=>'cancel_booking']]]]);
    }

    private function show_bot_homework($platform, $token, $chat_id, $state) {
        $user = $this->bot_user_from_state($state);
        if (!$user) return;
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'gls_sessions';
        $items_table = $wpdb->prefix . 'gls_items';
        $subs_table = $wpdb->prefix . 'gls_submissions';
        $keyboard = ['inline_keyboard'=>[]];
        $text = "📝 <b>تکالیف و تمرین‌های شما</b>\n";
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT sub.*, s.jalali_date, s.class_name FROM {$subs_table} sub INNER JOIN {$sessions_table} s ON s.id=sub.session_id WHERE sub.user_id=%d ORDER BY sub.submitted_at DESC LIMIT 10",
            $user->ID
        ));
        if ($rows) {
            $text .= "\nارسال‌های شما:\n";
            foreach ($rows as $r) {
                $status = ($r->status === 'corrected') ? 'تصحیح شد' : 'ارسال شد';
                $grade = ($r->grade !== null && $r->grade !== '') ? ' | نمره: ' . $r->grade : '';
                $keyboard['inline_keyboard'][] = [[ 'text' => $r->jalali_date . ' | ' . $status . $grade, 'callback_data' => 'bot_hw_detail_' . intval($r->id) ]];
            }
        }
        $ex = $wpdb->get_results($wpdb->prepare(
            "SELECT i.*, s.jalali_date FROM {$items_table} i INNER JOIN {$sessions_table} s ON s.id=i.session_id WHERE i.item_type='exercise' AND i.visible=1 AND (s.user_id=%d OR s.student_email=%s) ORDER BY s.booking_date DESC, i.sort_order ASC LIMIT 8",
            $user->ID, $user->user_email
        ));
        if ($ex) {
            $text .= "\nتمرین‌های ثبت‌شده:\n";
            foreach ($ex as $i => $e) $text .= '- ' . $e->jalali_date . ': ' . $e->title . "\n";
        }
        if (!$rows && !$ex) $text .= "فعلاً تکلیف یا تمرینی برای شما ثبت نشده است.";
        $keyboard['inline_keyboard'][] = [[ 'text' => '📤 ارسال تکلیف', 'callback_data' => 'bot_submit_homework' ]];
        $keyboard['inline_keyboard'][] = [[ 'text' => '🏠 منوی اصلی', 'callback_data' => 'cancel_booking' ]];
        $this->send_bot_message($platform, $token, $chat_id, $text, $keyboard);
    }

    private function show_bot_homework_detail($platform, $token, $chat_id, $submission_id, $state) {
        $user = $this->bot_user_from_state($state);
        if (!$user) return;
        global $wpdb;
        $subs_table = $wpdb->prefix . 'gls_submissions';
        $sessions_table = $wpdb->prefix . 'gls_sessions';
        $r = $wpdb->get_row($wpdb->prepare("SELECT sub.*, s.jalali_date, s.class_name FROM {$subs_table} sub INNER JOIN {$sessions_table} s ON s.id=sub.session_id WHERE sub.id=%d AND sub.user_id=%d", $submission_id, $user->ID));
        if (!$r) { $this->send_bot_message($platform,$token,$chat_id,'این تکلیف یافت نشد.'); return; }
        $text = "📝 <b>تکلیف {$r->jalali_date}</b>\n";
        $text .= "وضعیت: " . (($r->status === 'corrected') ? 'تصحیح شد' : 'ارسال شد') . "\n";
        if ($r->grade !== null && $r->grade !== '') $text .= "نمره: {$r->grade}\n";
        if (!empty($r->text_content)) $text .= "\n<b>متن ارسالی:</b>\n" . $this->bot_strip($r->text_content, 1200) . "\n";
        if (!empty($r->feedback_text)) $text .= "\n<b>تصحیح:</b>\n" . $this->bot_strip($r->feedback_text, 1800) . "\n";
        $this->send_bot_message($platform,$token,$chat_id,$text, ['inline_keyboard'=>[[['text'=>'🔙 تکالیف','callback_data'=>'bot_homework'],['text'=>'🏠 منوی اصلی','callback_data'=>'cancel_booking']]]]);
    }

    private function show_bot_homework_session_picker($platform, $token, $chat_id, $state_key, &$state) {
        $user = $this->bot_user_from_state($state);
        if (!$user) return;
        $sessions = $this->bot_user_sessions($user->ID, 10);
        if (!$sessions) { $this->send_bot_message($platform,$token,$chat_id,'برای ارسال تکلیف ابتدا باید جلسه آموزشی داشته باشید.'); return; }
        $keyboard = ['inline_keyboard'=>[]];
        foreach ($sessions as $s) {
            $keyboard['inline_keyboard'][] = [[ 'text' => $s->jalali_date . ' | ' . $s->class_name, 'callback_data' => 'bot_hw_select_' . intval($s->id) ]];
        }
        $keyboard['inline_keyboard'][] = [[ 'text' => '🏠 منوی اصلی', 'callback_data' => 'cancel_booking' ]];
        $this->send_bot_message($platform, $token, $chat_id, 'این تکلیف مربوط به کدام جلسه است؟', $keyboard);
    }

    private function handle_bot_homework_submission($platform, $token, $chat_id, $text, $incoming_file, $state_key, &$state) {
        $user = $this->bot_user_from_state($state);
        $session_id = intval($state['data']['homework_session_id'] ?? 0);
        if (!$user || !$session_id) { $this->send_bot_message($platform,$token,$chat_id,'ارسال تکلیف کامل نشد. لطفاً دوباره تلاش کنید.'); $this->show_main_menu($platform,$token,$chat_id); return; }
        global $wpdb;
        $sessions_table = $wpdb->prefix . 'gls_sessions';
        $subs_table = $wpdb->prefix . 'gls_submissions';
        $logs_table = $wpdb->prefix . 'gls_notifications';
        $s = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$sessions_table} WHERE id=%d AND (user_id=%d OR student_email=%s)", $session_id, $user->ID, $user->user_email));
        if (!$s) { $this->send_bot_message($platform,$token,$chat_id,'این جلسه برای شما قابل دسترسی نیست.'); return; }
        $files = [];
        if ($incoming_file && !empty($incoming_file['file_id'])) {
            $id = $this->save_telegram_file_to_media($token, $incoming_file, $user->ID);
            if ($id) $files[] = intval($id);
        }
        if (trim($text) === '' && empty($files)) {
            $this->send_bot_message($platform,$token,$chat_id,'لطفاً متن، عکس یا فایل تکلیف را ارسال کنید.');
            return;
        }
        $attempt = intval($wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$subs_table} WHERE session_id=%d AND user_id=%d", $session_id, $user->ID))) + 1;
        $wpdb->insert($subs_table, [
            'session_id'=>$session_id,
            'user_id'=>$user->ID,
            'attempt_no'=>$attempt,
            'text_content'=>wp_kses_post($text),
            'file_ids'=>wp_json_encode($files),
            'status'=>'submitted',
            'submitted_at'=>current_time('mysql'),
            'updated_at'=>current_time('mysql')
        ]);
        $teacher_email = get_option('admin_email');
        $wpdb->insert($logs_table, [
            'session_id'=>intval($session_id),
            'user_id'=>intval($user->ID),
            'email'=>sanitize_email($teacher_email),
            'type'=>'homework_submitted',
            'subject'=>'تکلیف جدید از ربات دریافت شد',
            'message'=>'تکلیف جدید برای جلسه ' . $s->jalali_date . ' توسط ' . $user->display_name . ' از طریق ربات ارسال شد.',
            'sent_at'=>current_time('mysql')
        ]);
        wp_mail($teacher_email, 'تکلیف جدید از ربات دریافت شد', 'تکلیف جدید برای جلسه ' . $s->jalali_date . ' توسط ' . $user->display_name . ' از طریق ربات ارسال شد.');
        $state['step'] = 'main_menu';
        unset($state['data']['homework_session_id']);
        set_transient($state_key, $state, 3600);
        $this->send_bot_message($platform,$token,$chat_id,'✅ تکلیف شما ثبت شد. بعد از بررسی، نتیجه در پنل و همین ربات قابل مشاهده خواهد بود.');
        $this->show_main_menu($platform,$token,$chat_id);
    }

    private function save_telegram_file_to_media($token, $file, $user_id) {
        if (empty($file['file_id'])) return 0;
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $info = wp_remote_get('https://api.telegram.org/bot' . $token . '/getFile?file_id=' . rawurlencode($file['file_id']), ['timeout'=>20]);
        if (is_wp_error($info)) return 0;
        $json = json_decode(wp_remote_retrieve_body($info), true);
        if (empty($json['ok']) || empty($json['result']['file_path'])) return 0;
        $url = 'https://api.telegram.org/file/bot' . $token . '/' . ltrim($json['result']['file_path'], '/');
        $tmp = download_url($url, 60);
        if (is_wp_error($tmp)) return 0;
        $name = sanitize_file_name($file['file_name'] ?? ('telegram-file-' . time()));
        $file_array = ['name'=>$name, 'tmp_name'=>$tmp];
        $id = media_handle_sideload($file_array, 0, 'Telegram homework upload for user ' . intval($user_id));
        if (is_wp_error($id)) { @unlink($tmp); return 0; }
        return intval($id);
    }

    private function show_bot_notifications($platform, $token, $chat_id, $state) {
        $user = $this->bot_user_from_state($state);
        if (!$user) return;
        global $wpdb;
        $logs_table = $wpdb->prefix . 'gls_notifications';
        if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $logs_table)) !== $logs_table) { $this->send_bot_message($platform,$token,$chat_id,'اعلان تازه‌ای وجود ندارد.'); return; }
        $read_at = get_user_meta($user->ID, 'gtbp_bot_notifications_read_at', true);
        if (!$read_at) $read_at = '1970-01-01 00:00:00';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$logs_table} WHERE (user_id=%d OR email=%s) AND sent_at>%s ORDER BY sent_at DESC LIMIT 20", $user->ID, $user->user_email, $read_at));
        if (!$rows) { $this->send_bot_message($platform,$token,$chat_id,'اعلان تازه‌ای برای شما وجود ندارد.', ['inline_keyboard'=>[[['text'=>'🏠 منوی اصلی','callback_data'=>'cancel_booking']]]]); return; }
        $text = "🔔 <b>اعلان‌های تازه</b>\n";
        foreach ($rows as $r) {
            $text .= "\n• " . esc_html($r->subject) . "\n" . esc_html($r->sent_at) . "\n";
        }
        $now_read = current_time('mysql');
        update_user_meta($user->ID, 'gtbp_bot_notifications_read_at', $now_read);
        update_user_meta($user->ID, 'gls_notifications_read_at', $now_read);
        $this->send_bot_message($platform,$token,$chat_id,$text, ['inline_keyboard'=>[[['text'=>'🏠 منوی اصلی','callback_data'=>'cancel_booking']]]]);
    }

    public function send_learning_notification_to_telegram($session, $type, $to, $subject, $message) {
        if (!get_option('gtbp_telegram_enabled', 0)) return;
        $token = get_option('gtbp_telegram_token', '');
        if (!$token) return;
        $user_id = 0;
        if ($session && !empty($session->user_id)) $user_id = intval($session->user_id);
        if (!$user_id && $to) { $u = get_user_by('email', $to); if ($u) $user_id = intval($u->ID); }
        if (!$user_id) return;
        $chat_id = get_user_meta($user_id, '_gtbp_telegram_chat_id', true);
        if (!$chat_id) return;
        $clean_subject = str_replace(['هوش مصنوعی','AI','ai'], ['دستیار آموزشی','دستیار آموزشی','دستیار آموزشی'], (string)$subject);
        $text = "🔔 " . $clean_subject;
        if ($session && !empty($session->jalali_date)) $text .= "\nجلسه: " . $session->jalali_date;
        $this->send_bot_message('telegram', $token, $chat_id, $text, ['inline_keyboard'=>[[['text'=>'📚 جزوه‌ها','callback_data'=>'bot_lessons'],['text'=>'📝 تکالیف','callback_data'=>'bot_homework']]]]);
    }

    // نمایش رزروهای کاربر
    private function show_user_bookings($platform, $token, $chat_id, $state) {
        $user_id = $state['data']['user_id'] ?? 0;
        if (!$user_id) {
            $this->send_bot_message($platform, $token, $chat_id, 'خطا در تشخیص کاربر. لطفاً مجدداً /start را بزنید.');
            return;
        }
        $email = get_userdata($user_id)->user_email;
        global $wpdb;
        $today = current_time('Y-m-d');
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE email = %s AND booking_date >= %s AND status = 'confirmed' ORDER BY booking_date ASC, booking_time ASC LIMIT 5",
            $email, $today
        ));
        if (empty($results)) {
            $this->send_bot_message($platform, $token, $chat_id, 'شما هیچ رزرو آینده‌ای ندارید.');
        } else {
            $text = "📋 ۵ رزرو آینده شما:\n";
            foreach ($results as $row) {
                $j_date = $this->gregorian_to_jalali_string($row->booking_date);
                $text .= "- {$row->class_name} | {$j_date} | {$row->booking_time}\n";
            }
            $this->send_bot_message($platform, $token, $chat_id, $text);
        }
        $this->show_main_menu($platform, $token, $chat_id);
    }

    // ارسال لینک کلاس (فقط در صورتی که ۵ دقیقه به شروع مانده)
    private function send_class_link($platform, $token, $chat_id, $state) {
        $user_id = $state['data']['user_id'] ?? 0;
        if (!$user_id) {
            $this->send_bot_message($platform, $token, $chat_id, 'خطا در تشخیص کاربر. لطفاً مجدداً /start را بزنید.');
            return;
        }
        $email = get_userdata($user_id)->user_email;
        global $wpdb;
        $tz = new DateTimeZone('Asia/Tehran');
        $now = new DateTime('now', $tz);
        $now_ts = $now->getTimestamp();
        $today = $now->format('Y-m-d');
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE email = %s AND booking_date = %s AND status = 'confirmed'",
            $email, $today
        ));
        $link_sent = false;
        foreach ($results as $row) {
            $time_parts = explode('-', $row->booking_time);
            $start_time = trim($time_parts[0]);
            $end_time = isset($time_parts[1]) ? trim($time_parts[1]) : '';
            $class_start_dt = new DateTime($row->booking_date . ' ' . $start_time . ':00', $tz);
            $class_start_ts = $class_start_dt->getTimestamp();
            // نسخه ۱۴.۵: پنجره = از ۳ دقیقه قبل از شروع تا پایان کلاس (نه تا شروع)
            $class_end_ts = $class_start_ts + 3600;
            if (preg_match('/^\d{1,2}:\d{2}$/', $end_time)) {
                try { $class_end_ts = (new DateTime($row->booking_date . ' ' . $end_time . ':00', $tz))->getTimestamp(); } catch (Exception $e) {}
                if ($class_end_ts <= $class_start_ts) $class_end_ts = $class_start_ts + 3600;
            }
            if ($now_ts >= ($class_start_ts - 3*60) && $now_ts <= $class_end_ts) {
                if (!empty($row->roomeet_join_link)) {
                    $this->send_bot_message($platform, $token, $chat_id, "لینک کلاس {$row->class_name}:\n" . $row->roomeet_join_link);
                    $link_sent = true;
                } else {
                    $this->send_bot_message($platform, $token, $chat_id, "لینک کلاس {$row->class_name} یافت نشد.");
                }
            }
        }
        if (!$link_sent) {
            $this->send_bot_message($platform, $token, $chat_id, 'هیچ کلاسی برای امروز در بازه ۳ دقیقه قبل از شروع وجود ندارد.');
        }
        $this->show_main_menu($platform, $token, $chat_id);
    }

    // ارسال پیام به ربات تلگرام
    private function send_bot_message($platform, $token, $chat_id, $text, $keyboard = null) {
        $url = '';
        if ($platform === 'telegram') {
            $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        } else {
            return false;
        }
        $body = [
            'chat_id' => $chat_id,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];
        if ($keyboard) {
            $body['reply_markup'] = json_encode($keyboard);
        }
        $response = wp_remote_post($url, [
            'method' => 'POST',
            'timeout' => 30,
            'body' => $body
        ]);
        if (is_wp_error($response)) {
            $this->log_event('telegram_error', $platform, intval($chat_id), $response->get_error_message());
            error_log("GTBP Bot: Error sending message to $platform: " . $response->get_error_message());
            return false;
        }
        return true;
    }

    private function delete_bot_message($token, $chat_id, $message_id) {
        if (!$message_id) return;
        wp_remote_post('https://api.telegram.org/bot' . $token . '/deleteMessage', ['timeout' => 10, 'body' => ['chat_id' => $chat_id, 'message_id' => $message_id]]);
    }

    private function answer_bot_callback($token, $callback_query_id) {
        if (!$callback_query_id) return;
        wp_remote_post('https://api.telegram.org/bot' . $token . '/answerCallbackQuery', ['timeout' => 10, 'body' => ['callback_query_id' => $callback_query_id]]);
    }

    // دریافت تاریخ‌های قابل رزرو با رعایت قانون ۲۴ ساعت و ظرفیت (با صفحه‌بندی)
    private function get_available_dates_for_bot($limit = 3, $offset = 0) {
        $holidays = $this->get_holidays();
        $hours = $this->get_working_hours();
        $tz = new DateTimeZone('Asia/Tehran');
        $now = new DateTime('now', $tz);
        $now->setTime(0, 0, 0);
        // از فردا شروع می‌کنیم (امروز به دلیل قانون ۲۴ ساعت قابل رزرو نیست)
        $start_date = clone $now;
        $start_date->modify('+1 day');
        $found = 0;
        $result = [];
        $current_offset = 0;
        $days_checked = 0;
        $max_days = 60; // حداکثر جستجو تا ۶۰ روز جلو
        while ($found < $limit && $days_checked < $max_days) {
            $date = clone $start_date;
            $date->modify("+$days_checked days");
            $date_str = $date->format('Y-m-d');
            // بررسی تعطیلات
            if (in_array($date_str, $holidays)) {
                $days_checked++;
                continue;
            }
            $dow = (int)$date->format('w');
            $day_settings = null;
            foreach ($hours as $h) {
                if ($h['day'] == $dow) {
                    $day_settings = $h;
                    break;
                }
            }
            if (!$day_settings || !$day_settings['active'] || empty(trim($day_settings['slots']))) {
                $days_checked++;
                continue;
            }
            // بررسی وجود حداقل یک ساعت آزاد با رعایت ۲۴ ساعت و عدم رزرو
            $slots_array = array_filter(array_map('trim', explode(',', $day_settings['slots'])));
            global $wpdb;
            $booked_slots = $wpdb->get_col($wpdb->prepare(
                "SELECT booking_time FROM {$this->table_name} WHERE booking_date = %s AND status != 'cancelled'",
                $date_str
            ));
            $now_ts = $now->getTimestamp();
            $has_available_slot = false;
            foreach ($slots_array as $slot) {
                $slot_start_time = explode('-', $slot)[0];
                $slot_dt = new DateTime($date_str . ' ' . $slot_start_time . ':00', $tz);
                $slot_ts = $slot_dt->getTimestamp();
                $diff_hours = ($slot_ts - $now_ts) / 3600;
                // نسخه ۱۴.۱: تشخیص هم‌پوشانی زمانی
                if ($diff_hours >= 24 && !$this->slot_conflicts_with_bookings($date_str, $slot, 0, $booked_slots)) {
                    $has_available_slot = true;
                    break;
                }
            }
            if ($has_available_slot) {
                if ($current_offset >= $offset) {
                    $j_date = $this->gregorian_to_jalali_string($date_str);
                    $result[$date_str] = $j_date;
                    $found++;
                }
                $current_offset++;
            }
            $days_checked++;
        }
        return $result;
    }

    // بررسی وجود تاریخ‌های بیشتر بعد از offset مشخصی
    private function has_more_available_dates($offset) {
        $next = $this->get_available_dates_for_bot(1, $offset);
        return !empty($next);
    }

    // مدیریت جریان رزرو برای ورودی متنی (در صورت لزوم)
        private function handle_booking_flow($platform, $token, $chat_id, $text, $state_key, &$state) {
        if ($state['step'] === 'coupon_code') {
            $user_id = isset($state['data']['user_id']) ? intval($state['data']['user_id']) : 0;
            $result = $this->validate_coupon_code_for_user($text, $user_id);
            if (empty($result['valid'])) {
                $this->send_bot_message($platform, $token, $chat_id, $result['message'] . "\nلطفاً دوباره کد تخفیف را وارد کنید یا به پرداخت برگردید:", [
                    'inline_keyboard' => [
                        [['text' => '🔙 بازگشت به پرداخت', 'callback_data' => 'proceed_payment']]
                    ]
                ]);
                return true;
            }
            $state['data']['discount_code'] = $result['code'];
            $state['step'] = 'payment_method';
            set_transient($state_key, $state, 3600);
            $this->send_bot_message($platform, $token, $chat_id, $result['message']);
            $this->show_cart_summary($platform, $token, $chat_id, $state['data']['cart'] ?? [], $state['data']['discount_code']);
            $this->show_payment_methods($platform, $token, $chat_id, $state);
            return true;
        }
        return false;
    }


    // منوی ادمین برای تنظیمات تلگرام
    public function add_bot_admin_menus() {
        add_submenu_page('gtbp_bookings', 'تنظیمات تلگرام', 'تنظیمات تلگرام', 'manage_options', 'gtbp_telegram_settings', array($this, 'admin_telegram_page'));
    }

    // پردازش اقدامات ادمین برای تنظیم وب‌هوک
    public function handle_bot_admin_actions() {
        if (!current_user_can('manage_options')) return;
        // تنظیم وب‌هوک تلگرام
        if (isset($_POST['gtbp_set_telegram_webhook'])) {
            $token = get_option('gtbp_telegram_token', '');
            if (empty($token)) {
                add_settings_error('gtbp_telegram', 'token_empty', 'لطفاً ابتدا توکن تلگرام را وارد کنید.', 'error');
            } else {
                $webhook_url = home_url('/wp-json/gtbp/v1/telegram-webhook');
                $response = wp_remote_post('https://api.telegram.org/bot' . $token . '/setWebhook', [
                    'body' => ['url' => $webhook_url]
                ]);
                if (is_wp_error($response)) {
                    add_settings_error('gtbp_telegram', 'webhook_error', 'خطا در تنظیم وب‌هوک: ' . $response->get_error_message(), 'error');
                } else {
                    $body = json_decode(wp_remote_retrieve_body($response), true);
                    if ($body && isset($body['ok']) && $body['ok']) {
                        add_settings_error('gtbp_telegram', 'webhook_success', 'وب‌هوک با موفقیت تنظیم شد.', 'success');
                    } else {
                        add_settings_error('gtbp_telegram', 'webhook_fail', 'خطا: ' . ($body['description'] ?? 'مشخص نیست'), 'error');
                    }
                }
            }
        }
        // ذخیره تنظیمات تلگرام
        if (isset($_POST['gtbp_save_telegram'])) {
            update_option('gtbp_telegram_token', sanitize_text_field($_POST['gtbp_telegram_token']));
            update_option('gtbp_telegram_enabled', isset($_POST['gtbp_telegram_enabled']) ? 1 : 0);
            update_option('gtbp_telegram_secret', sanitize_text_field($_POST['gtbp_telegram_secret']));
            update_option('gtbp_bot_welcome_message', sanitize_text_field($_POST['gtbp_bot_welcome_message']));
            add_settings_error('gtbp_telegram', 'save_success', 'تنظیمات ذخیره شد.', 'success');
        }
    }

    // صفحه ادمین تنظیمات تلگرام
    public function admin_telegram_page() {
        $token = get_option('gtbp_telegram_token', '');
        $enabled = get_option('gtbp_telegram_enabled', 0);
        $secret = get_option('gtbp_telegram_secret', '');
        $welcome = get_option('gtbp_bot_welcome_message', 'سلام! برای رزرو کلاس، لطفاً ایمیل خود را وارد کنید:');
        ?>
        <div class="wrap gtbp-admin-wrap">
            <h1>تنظیمات ربات تلگرام</h1>
            <?php settings_errors('gtbp_telegram'); ?>
            <form method="post" style="max-width:600px;">
                <table class="form-table">
                    <tr>
                        <th><label>توکن ربات</label></th>
                        <td><input type="text" name="gtbp_telegram_token" value="<?php echo esc_attr($token); ?>" class="regular-text" dir="ltr"></td>
                    </tr>
                    <tr>
                                                <th><label>فعال بودن ربات</label></th>
                        <td><input type="checkbox" name="gtbp_telegram_enabled" value="1" <?php checked($enabled, 1); ?>></td>
                    </tr>
                    <tr>
                        <th><label>Secret (اختیاری)</label></th>
                        <td><input type="text" name="gtbp_telegram_secret" value="<?php echo esc_attr($secret); ?>" class="regular-text" dir="ltr"></td>
                    </tr>
                    <tr>
                        <th><label>پیام خوشامدگویی (برای /start)</label></th>
                        <td><textarea name="gtbp_bot_welcome_message" rows="3" class="large-text"><?php echo esc_textarea($welcome); ?></textarea></td>
                    </tr>
                </table>
                <p><button type="submit" name="gtbp_save_telegram" class="button-primary">ذخیره تنظیمات</button>
                <button type="submit" name="gtbp_set_telegram_webhook" class="button">تنظیم Webhook</button></p>
                <p>آدرس Webhook: <code><?php echo home_url('/wp-json/gtbp/v1/telegram-webhook'); ?></code></p>
            </form>
        </div>
        <?php
    }
}



if (!defined('GTBP_PLUGIN_VERSION')) define('GTBP_PLUGIN_VERSION', '14.16.0-meet-recordings');
if (!defined('GTBP_PLUGIN_FILE')) define('GTBP_PLUGIN_FILE', __FILE__);
if (!defined('GTBP_PLUGIN_DIR')) define('GTBP_PLUGIN_DIR', plugin_dir_path(__FILE__));
if (!defined('GTBP_PLUGIN_URL')) define('GTBP_PLUGIN_URL', plugin_dir_url(__FILE__));

require_once GTBP_PLUGIN_DIR . 'includes/class-learning-dashboard.php';
require_once GTBP_PLUGIN_DIR . 'includes/class-visual-unifier.php';
require_once GTBP_PLUGIN_DIR . 'includes/class-complete-migration.php';
require_once GTBP_PLUGIN_DIR . 'includes/class-health-check.php';

$gtbp_booking_plugin_instance = new GermanTeacherBookingPlugin_v10();

if (class_exists('GTBP_Learning_Dashboard_v211')) {
    GTBP_Learning_Dashboard_v211::instance();
    register_activation_hook(__FILE__, function(){
        GTBP_Learning_Dashboard_v211::instance()->activate();
    });
}

if (class_exists('GTBP_Visual_Unifier')) {
    GTBP_Visual_Unifier::instance();
}

if (class_exists('GTBP_Complete_Migration')) {
    GTBP_Complete_Migration::instance();
}

if (class_exists('GTBP_Health_Check')) {
    GTBP_Health_Check::instance();
}

?>
