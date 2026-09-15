<?php
if (!defined('ABSPATH')) exit;

/**
 * Complete, portable backup for data owned or referenced by this plugin.
 * Archives contain credentials and must be handled as confidential files.
 */
final class GTBP_Complete_Migration {
    const FORMAT = 1;
    const PAGE = 'gtbp_complete_migration';
    private static $instance;

    public static function instance() {
        return self::$instance ?: (self::$instance = new self());
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'menu'), 30);
        add_action('admin_post_gtbp_complete_export', array($this, 'export_download'));
        add_action('admin_post_gtbp_complete_restore', array($this, 'restore_upload'));
        add_action('admin_post_gtbp_sync_save', array($this, 'sync_save'));
        add_action('admin_post_gtbp_sync_pull', array($this, 'sync_pull'));
        add_action('rest_api_init', array($this, 'sync_routes'));
    }

    public function menu() {
        add_submenu_page('gtbp_bookings', 'بکاپ و انتقال کامل', 'بکاپ کامل', 'manage_options', self::PAGE, array($this, 'page'));
    }

    public function page() {
        if (!current_user_can('manage_options')) return;
        $notice = isset($_GET['gtbp_migration_notice']) ? sanitize_text_field(wp_unslash($_GET['gtbp_migration_notice'])) : '';
        ?>
        <div class="wrap" dir="rtl">
            <h1>بکاپ و انتقال کامل افزونه</h1>
            <?php if ($notice): ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html($notice); ?></p></div><?php endif; ?>
            <div class="notice notice-warning inline"><p><strong>محرمانه:</strong> فایل خروجی شامل تنظیمات، کلیدهای API و توکن‌ها است. آن را عمومی یا برای شخص دیگری ارسال نکنید.</p></div>
            <div class="card" style="max-width:900px;padding:22px;margin-top:18px">
                <h2>دریافت بسته کامل</h2>
                <p>رزروها، پرداخت‌ها، جلسات، جزوه‌ها، تکالیف، آزمون‌ها، ویدئوها و لینک‌های ضبط، تنظیمات همه سرویس‌ها، کلیدها، داده‌های ربات، همه کاربران غیرمدیرکل با نقش و اطلاعاتشان و فایل‌های پیوست در یک فایل ZIP قرار می‌گیرند.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="gtbp_complete_export">
                    <?php wp_nonce_field('gtbp_complete_export'); ?>
                    <button class="button button-primary button-hero">دانلود بکاپ کامل</button>
                </form>
            </div>
            <div class="card" style="max-width:900px;padding:22px;margin-top:18px">
                <h2>بازیابی روی این سایت</h2>
                <p>ابتدا همین نسخه یا نسخه جدیدتر افزونه را روی سایت مقصد نصب کنید. بازیابی قبل از هر تغییری یک نسخه ایمنی روی سرور مقصد می‌سازد. اگر مقصد از قبل داده داشته باشد، اطلاعات با نگاشت شناسه‌ها ادغام می‌شوند و داده‌های نامرتبط حذف نخواهند شد.</p>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="gtbp_complete_restore">
                    <?php wp_nonce_field('gtbp_complete_restore'); ?>
                    <input type="file" name="gtbp_backup" accept=".zip,application/zip" required>
                    <p><label><input type="checkbox" name="confirm_sensitive" value="1" required> تأیید می‌کنم که این فایل بکاپ متعلق به خودم است و اطلاعات فعلی سایت مقصد نباید جایگزین داده‌های نامرتبط شود.</label></p>
                    <button class="button button-primary">اعتبارسنجی و بازیابی کامل</button>
                </form>
            </div>
            <div class="card" style="max-width:900px;padding:22px;margin-top:18px">
                <h2>همگام‌سازی بین دو سایت</h2>
                <p>این افزونه را روی هر دو سایت نصب کنید و روی هر دو، همین «رمز همگام‌سازی» یکسان (حداقل ۳۲ نویسه تصادفی) و آدرس سایت مقابل را ذخیره کنید. دکمه «دریافت و ادغام» همه داده‌های سایت مقابل را می‌گیرد و فقط رکوردهایی را که این‌جا وجود ندارند اضافه می‌کند (هیچ داده‌ای حذف نمی‌شود). برای همگام‌سازی کاملِ دوطرفه، همین دکمه را روی سایت مقابل هم بزنید.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="gtbp_sync_save">
                    <?php wp_nonce_field('gtbp_sync_save'); ?>
                    <p><label>آدرس سایت مقابل<br><input type="url" name="gtbp_sync_remote_url" dir="ltr" style="width:420px" placeholder="https://example.com" value="<?php echo esc_attr(get_option('gtbp_sync_remote_url', '')); ?>"></label></p>
                    <p><label>رمز همگام‌سازی (برای تغییر، مقدار جدید وارد کنید؛ خالی یعنی بدون تغییر)<br><input type="password" name="gtbp_sync_secret" dir="ltr" style="width:420px" autocomplete="new-password" placeholder="<?php echo strlen((string) get_option('gtbp_sync_secret', '')) >= 32 ? 'ذخیره شده است' : 'حداقل ۳۲ نویسه تصادفی'; ?>"></label></p>
                    <button class="button">ذخیره تنظیمات همگام‌سازی</button>
                </form>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:14px" onsubmit="return confirm('همه داده‌های سایت مقابل دریافت و رکوردهای جدید به این سایت اضافه می‌شوند. قبل از تغییر، یک نسخه ایمنی خودکار ساخته می‌شود. ادامه می‌دهید؟');">
                    <input type="hidden" name="action" value="gtbp_sync_pull">
                    <?php wp_nonce_field('gtbp_sync_pull'); ?>
                    <button class="button button-primary">دریافت و ادغام از سایت مقابل</button>
                </form>
            </div>
        </div>
        <?php
    }

    private function guard($nonce_action) {
        if (!current_user_can('manage_options')) wp_die('دسترسی کافی ندارید.', 403);
        check_admin_referer($nonce_action);
        if (!class_exists('ZipArchive')) wp_die('افزونه ZipArchive روی هاست فعال نیست. از پشتیبانی هاست بخواهید PHP Zip را فعال کند.');
    }

    private function table_names() {
        global $wpdb;
        $like = array('german\_%', 'gls\_%', 'gtbp\_%', 'otg\_results');
        $found = array();
        foreach ($like as $suffix) {
            $rows = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . str_replace('\\_', '_', $suffix)));
            foreach ((array) $rows as $name) {
                if (strpos($name, $wpdb->prefix) === 0) $found[$name] = substr($name, strlen($wpdb->prefix));
            }
        }
        return $found;
    }

    private function site_local_option($name) {
        // این گزینه‌ها مخصوص همین سایت هستند و نباید بین سایت‌ها منتقل شوند:
        // نگاشت شناسه‌های بازیابی، سابقه بازیابی و تنظیمات همگام‌سازی.
        return strpos($name, 'gtbp_restore_map_') === 0
            || $name === 'gtbp_last_complete_restore'
            || $name === 'gtbp_sync_remote_url'
            || $name === 'gtbp_sync_secret';
    }

    private function option_rows() {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'gtbp\\_%' OR option_name LIKE 'gls\\_%' OR option_name LIKE 'rmt\\_%' OR option_name LIKE 'meet\\_%' ORDER BY option_name", ARRAY_A);
        return array_values(array_filter((array) $rows, function($row) { return !$this->site_local_option($row['option_name']); }));
    }

    private function portable_users() {
        global $wpdb;
        $users = array();
        $ids = array();
        $rows = $wpdb->get_results("SELECT ID,user_login,user_pass,user_nicename,user_email,user_url,user_registered,user_activation_key,user_status,display_name FROM {$wpdb->users} ORDER BY ID", ARRAY_A);
        foreach ((array) $rows as $row) {
            $user = get_userdata((int) $row['ID']);
            if (!$user) continue;
            if (is_super_admin($user->ID) || in_array('administrator', (array) $user->roles, true) || $user->has_cap('manage_options')) continue;
            $users[] = $row;
            $ids[] = (int) $row['ID'];
        }
        $meta = array();
        if ($ids) {
            $list = implode(',', array_map('intval', $ids));
            $meta = $wpdb->get_results("SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id IN ($list) AND meta_key NOT IN ('session_tokens','application_passwords') ORDER BY user_id, umeta_id", ARRAY_A);
        }
        return array($users, $meta);
    }

    private function attachment_ids($tables, $meta) {
        global $wpdb;
        $ids = array();
        foreach ($tables as $table => $suffix) {
            $columns = $wpdb->get_col("SHOW COLUMNS FROM `" . esc_sql($table) . "`");
            foreach (array('attachment_id', 'file_ids', 'correction_file_ids') as $column) {
                if (!in_array($column, $columns, true)) continue;
                foreach ((array) $wpdb->get_col("SELECT `" . esc_sql($column) . "` FROM `" . esc_sql($table) . "`") as $value) {
                    if ($column === 'attachment_id') $ids[(int) $value] = true;
                    else foreach ((array) json_decode($value, true) as $id) $ids[(int) $id] = true;
                }
            }
        }
        foreach ($meta as $row) {
            if (preg_match('/(avatar|photo|image|attachment).*(_id|id)$/i', $row['meta_key']) && ctype_digit((string) $row['meta_value'])) $ids[(int) $row['meta_value']] = true;
        }
        unset($ids[0]);
        return array_keys($ids);
    }

    private function build_archive($path) {
        global $wpdb;
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('ساخت فایل ZIP ممکن نشد.');
        $tables = $this->table_names();
        $options = $this->option_rows();
        list($users, $usermeta) = $this->portable_users();
        $files = array();
        $temporary_payloads = array();
        $inventory = array('tables' => array(), 'options' => count($options), 'users' => count($users), 'usermeta' => count($usermeta), 'attachments' => 0, 'files' => 0);
        foreach ($tables as $table => $suffix) {
            $schema = $wpdb->get_row("SHOW CREATE TABLE `" . esc_sql($table) . "`", ARRAY_N);
            $payload_file = wp_tempnam('gtbp-table-' . sanitize_file_name($suffix) . '.json');
            $handle = fopen($payload_file, 'wb');
            if (!$handle) throw new RuntimeException('ساخت فایل موقت جدول ممکن نشد: ' . $suffix);
            fwrite($handle, '{"suffix":' . wp_json_encode($suffix) . ',"schema":' . wp_json_encode(isset($schema[1]) ? $schema[1] : '') . ',"rows":[');
            $offset = 0; $count = 0; $first = true;
            do {
                $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM `" . esc_sql($table) . "` LIMIT %d OFFSET %d", 250, $offset), ARRAY_A);
                foreach ((array) $rows as $row) {
                    $encoded = wp_json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($encoded === false) { fclose($handle); throw new RuntimeException('کدگذاری یکی از ردیف‌های جدول ممکن نشد: ' . $suffix); }
                    fwrite($handle, ($first ? '' : ',') . $encoded);
                    $first = false; $count++;
                }
                $offset += count((array) $rows);
            } while (count((array) $rows) === 250);
            fwrite($handle, ']}');
            fclose($handle);
            $temporary_payloads[] = $payload_file;
            if (!$zip->addFile($payload_file, 'database/' . sanitize_file_name($suffix) . '.json')) throw new RuntimeException('افزودن جدول به ZIP ممکن نشد: ' . $suffix);
            $inventory['tables'][$suffix] = $count;
        }
        $zip->addFromString('options.json', wp_json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('users.json', wp_json_encode($users, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('usermeta.json', wp_json_encode($usermeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->addFromString('roles.json', wp_json_encode(wp_roles()->roles, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $attachment_ids = $this->attachment_ids($tables, $usermeta);
        $attachments = array();
        $upload = wp_upload_dir();
        foreach ($attachment_ids as $id) {
            $post = get_post($id, ARRAY_A);
            if (!$post || $post['post_type'] !== 'attachment') continue;
            $metadata = get_post_meta($id);
            $attached = get_post_meta($id, '_wp_attached_file', true);
            $attachments[] = array('post' => $post, 'meta' => $metadata, 'relative_file' => $attached);
            if ($attached) {
                $relative_files = array($attached);
                $wp_meta = wp_get_attachment_metadata($id);
                if (is_array($wp_meta)) {
                    $base = dirname($attached);
                    foreach ((array) ($wp_meta['sizes'] ?? array()) as $size) if (!empty($size['file'])) $relative_files[] = trailingslashit($base) . $size['file'];
                    if (!empty($wp_meta['original_image'])) $relative_files[] = trailingslashit($base) . $wp_meta['original_image'];
                }
                foreach (array_unique($relative_files) as $relative_file) {
                    $absolute = trailingslashit($upload['basedir']) . ltrim($relative_file, '/');
                    if (!is_file($absolute) || !is_readable($absolute)) continue;
                    $archive_name = 'uploads/' . str_replace(array('..', '\\'), array('', '/'), ltrim($relative_file, '/'));
                    $zip->addFile($absolute, $archive_name);
                    $files[$archive_name] = hash_file('sha256', $absolute);
                }
            }
        }
        $inventory['attachments'] = count($attachments);
        $inventory['files'] = count($files);
        $zip->addFromString('attachments.json', wp_json_encode($attachments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $manifest = array(
            'format' => self::FORMAT,
            'plugin_version' => defined('GTBP_PLUGIN_VERSION') ? GTBP_PLUGIN_VERSION : '14.16.0-meet-recordings',
            'created_at_utc' => gmdate('c'),
            'source_url' => home_url('/'),
            'source_prefix' => $wpdb->prefix,
            'inventory' => $inventory,
            'file_sha256' => $files,
        );
        $zip->addFromString('manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->close();
        foreach ($temporary_payloads as $temporary_payload) @unlink($temporary_payload);
        return $inventory;
    }

    public function export_download() {
        $this->guard('gtbp_complete_export');
        $tmp = wp_tempnam('gtbp-complete-backup.zip');
        try {
            $this->build_archive($tmp);
            nocache_headers();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="gtbp-complete-' . gmdate('Ymd-His') . '.zip"');
            header('Content-Length: ' . filesize($tmp));
            readfile($tmp);
        } catch (Throwable $e) {
            @unlink($tmp);
            wp_die(esc_html($e->getMessage()));
        }
        @unlink($tmp);
        exit;
    }

    private function safe_extract(ZipArchive $zip, $dir) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (!$name || strpos($name, '..') !== false || $name[0] === '/' || preg_match('/^[A-Za-z]:/', $name)) throw new RuntimeException('مسیر ناامن در فایل بکاپ شناسایی شد.');
        }
        if (!$zip->extractTo($dir)) throw new RuntimeException('استخراج فایل بکاپ ممکن نشد.');
    }

    private function json_file($dir, $name) {
        $path = $dir . '/' . $name;
        if (!is_file($path)) throw new RuntimeException('فایل ضروری ' . $name . ' در بکاپ وجود ندارد.');
        $data = json_decode(file_get_contents($path), true);
        if (!is_array($data)) throw new RuntimeException('ساختار ' . $name . ' معتبر نیست.');
        return $data;
    }

    private function payload_priority($suffix) {
        $order = array(
            'german_bookings' => 10,
            'otg_results' => 20,
            'gls_sessions' => 30,
            'gls_submissions' => 40,
            'gls_items' => 50,
            'gls_notifications' => 60,
            'gls_ai_student_profiles' => 70,
            'gls_quiz_reports' => 80,
            'gls_ai_logs' => 90,
        );
        return isset($order[$suffix]) ? $order[$suffix] : 200;
    }

    private function remap_row_links($row, $id_maps, $user_map, $attachment_map) {
        $links = array(
            'booking_id' => 'german_bookings',
            'session_id' => 'gls_sessions',
            'last_session_id' => 'gls_sessions',
            'submission_id' => 'gls_submissions',
            'result_id' => 'otg_results',
        );
        if (isset($row['user_id'])) $row['user_id'] = isset($user_map[(int) $row['user_id']]) ? $user_map[(int) $row['user_id']] : 0;
        if (isset($row['attachment_id'], $attachment_map[(int) $row['attachment_id']])) $row['attachment_id'] = $attachment_map[(int) $row['attachment_id']];
        foreach ($links as $column => $parent) {
            if (isset($row[$column], $id_maps[$parent][(int) $row[$column]])) $row[$column] = $id_maps[$parent][(int) $row[$column]];
        }
        foreach (array('file_ids', 'correction_file_ids') as $field) if (!empty($row[$field])) {
            $ids = json_decode($row[$field], true);
            if (is_array($ids)) {
                foreach ($ids as &$id) if (isset($attachment_map[(int) $id])) $id = $attachment_map[(int) $id];
                unset($id);
                $row[$field] = wp_json_encode($ids);
            }
        }
        return $row;
    }

    private function natural_existing_id($table, $suffix, $row) {
        global $wpdb;
        $sets = array(
            'german_bookings' => array('email', 'booking_date', 'booking_time', 'class_name'),
            'gls_sessions' => array('booking_id'),
            'gls_ai_student_profiles' => array('user_id', 'student_email'),
            'gls_quiz_reports' => array('result_id'),
            'gtbp_recording_transport' => array('record_id'),
        );
        if (empty($sets[$suffix])) return 0;
        $where = array(); $values = array();
        foreach ($sets[$suffix] as $key) {
            if (!array_key_exists($key, $row)) return 0;
            $where[] = '`' . esc_sql($key) . '` = %s';
            $values[] = (string) $row[$key];
        }
        $sql = "SELECT id FROM `" . esc_sql($table) . "` WHERE " . implode(' AND ', $where) . ' LIMIT 1';
        return (int) $wpdb->get_var($wpdb->prepare($sql, $values));
    }

    private function restore_data($dir, $manifest) {
        global $wpdb;
        foreach ((array) $manifest['file_sha256'] as $name => $hash) {
            $path = $dir . '/' . $name;
            if (!is_file($path) || !hash_equals($hash, hash_file('sha256', $path))) throw new RuntimeException('صحت یکی از فایل‌های پیوست تأیید نشد: ' . $name);
        }
        $database = glob($dir . '/database/*.json');
        $payloads = array();
        foreach ($database as $file) {
            $payload = json_decode(file_get_contents($file), true);
            if (!is_array($payload) || empty($payload['suffix']) || !preg_match('/^(german_|gls_|gtbp_|otg_results$)[a-zA-Z0-9_]*$/', $payload['suffix'])) throw new RuntimeException('تعریف جدول نامعتبر است.');
            $payloads[] = array('file' => $file, 'suffix' => $payload['suffix']);
            $table = $wpdb->prefix . $payload['suffix'];
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
                $schema = isset($payload['schema']) ? trim($payload['schema']) : '';
                if (!$schema || stripos($schema, 'CREATE TABLE ') !== 0 || strpos($schema, ';') !== false) throw new RuntimeException('ساختار امن جدول در بکاپ وجود ندارد: ' . $payload['suffix']);
                $schema = preg_replace('/^CREATE TABLE `[^`]+`/i', 'CREATE TABLE `' . $table . '`', $schema, 1);
                if (!$schema || $wpdb->query($schema) === false) throw new RuntimeException('ساخت جدول مقصد ناموفق بود: ' . $payload['suffix'] . '، ' . $wpdb->last_error);
            }
            unset($payload);
        }
        usort($payloads, function($a, $b) { return $this->payload_priority($a['suffix']) <=> $this->payload_priority($b['suffix']); });
        $wpdb->query('START TRANSACTION');

        $users = $this->json_file($dir, 'users.json');
        $roles = $this->json_file($dir, 'roles.json');
        foreach ($roles as $role_key => $role_definition) {
            if ($role_key === 'administrator' || empty($role_definition['name']) || !is_array($role_definition['capabilities'] ?? null)) continue;
            if (!get_role($role_key)) add_role(sanitize_key($role_key), sanitize_text_field($role_definition['name']), $role_definition['capabilities']);
        }
        $user_map = array();
        foreach ($users as $row) {
            $existing = !empty($row['user_email']) ? get_user_by('email', $row['user_email']) : false;
            if (!$existing && !empty($row['user_login'])) $existing = get_user_by('login', $row['user_login']);
            if ($existing) {
                if (is_super_admin($existing->ID) || in_array('administrator', (array) $existing->roles, true) || $existing->has_cap('manage_options')) throw new RuntimeException('یک کاربر مقصد با ایمیل یا نام کاربری مشابه، مدیرکل است. برای محافظت از حساب مدیر، بازیابی متوقف شد.');
                $new_id = $existing->ID;
                $updated = wp_update_user(array(
                    'ID' => $new_id,
                    'user_nicename' => $row['user_nicename'],
                    'user_email' => sanitize_email($row['user_email']),
                    'user_url' => esc_url_raw($row['user_url']),
                    'display_name' => $row['display_name'],
                ));
                if (is_wp_error($updated)) throw new RuntimeException('به‌روزرسانی کاربر مقصد ناموفق بود: ' . $updated->get_error_message());
                $wpdb->update($wpdb->users, array('user_pass' => $row['user_pass'], 'user_activation_key' => $row['user_activation_key'], 'user_status' => (int) $row['user_status']), array('ID' => $new_id));
            }
            else {
                $login = sanitize_user($row['user_login'], true);
                if (!$login) $login = 'gtbp_user_' . (int) $row['ID'];
                $new_id = wp_insert_user(array('user_login' => $login, 'user_pass' => $row['user_pass'], 'user_email' => sanitize_email($row['user_email']), 'display_name' => $row['display_name'], 'user_registered' => $row['user_registered'], 'role' => 'subscriber'));
                if (is_wp_error($new_id)) throw new RuntimeException('ساخت کاربر مقصد ناموفق بود: ' . $new_id->get_error_message());
                $wpdb->update($wpdb->users, array('user_pass' => $row['user_pass']), array('ID' => $new_id));
            }
            $user_map[(int) $row['ID']] = (int) $new_id;
        }

        $upload = wp_upload_dir();
        $attachments = $this->json_file($dir, 'attachments.json');
        $attachment_map = array();
        foreach ($attachments as $item) {
            $old_id = (int) $item['post']['ID'];
            $relative = ltrim((string) $item['relative_file'], '/');
            $src = $dir . '/uploads/' . $relative;
            $dest = trailingslashit($upload['basedir']) . $relative;
            if ($relative && is_file($src)) {
                wp_mkdir_p(dirname($dest));
                if (!is_file($dest) && !copy($src, $dest)) throw new RuntimeException('کپی فایل پیوست ممکن نشد: ' . $relative);
            }
            $new_id = 0;
            if ($relative) {
                $candidate = (int) $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value=%s ORDER BY post_id LIMIT 1", $relative));
                if ($candidate && get_post_type($candidate) === 'attachment') $new_id = $candidate;
            }
            if (!$new_id) {
                $post = $item['post']; unset($post['ID']);
                if (!empty($post['post_author']) && isset($user_map[(int) $post['post_author']])) $post['post_author'] = $user_map[(int) $post['post_author']];
                $new_id = wp_insert_attachment(wp_slash($post), $dest, 0, true);
                if (is_wp_error($new_id)) throw new RuntimeException('ثبت پیوست ناموفق بود: ' . $new_id->get_error_message());
                foreach ((array) $item['meta'] as $key => $values) foreach ((array) $values as $value) add_post_meta($new_id, $key, maybe_unserialize($value));
            }
            $attachment_map[$old_id] = (int) $new_id;
        }

        $map_option = 'gtbp_restore_map_' . substr(hash('sha256', (string) $manifest['source_url']), 0, 20);
        $id_maps = (array) get_option($map_option, array());
        $same_site = untrailingslashit((string) $manifest['source_url']) === untrailingslashit(home_url('/'));
        foreach ($payloads as $payload_ref) {
            $payload = json_decode(file_get_contents($payload_ref['file']), true);
            if (!is_array($payload)) throw new RuntimeException('خواندن داده جدول ' . $payload_ref['suffix'] . ' ممکن نشد.');
            $table = $wpdb->prefix . $payload['suffix'];
            foreach ((array) $payload['rows'] as $row) {
                $old_id = isset($row['id']) ? (int) $row['id'] : 0;
                $row = $this->remap_row_links($row, $id_maps, $user_map, $attachment_map);
                $target_id = 0;
                if ($old_id && isset($id_maps[$payload['suffix']][$old_id])) {
                    $mapped = (int) $id_maps[$payload['suffix']][$old_id];
                    $target_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM `" . esc_sql($table) . "` WHERE id=%d", $mapped));
                }
                if (!$target_id) $target_id = $this->natural_existing_id($table, $payload['suffix'], $row);
                if (!$target_id && $old_id && $same_site) $target_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM `" . esc_sql($table) . "` WHERE id=%d", $old_id));
                if ($target_id) {
                    $write = $row; unset($write['id']);
                    if ($wpdb->update($table, $write, array('id' => $target_id)) === false) throw new RuntimeException('به‌روزرسانی جدول ' . $payload['suffix'] . ' ناموفق بود: ' . $wpdb->last_error);
                    $new_id = $target_id;
                } else {
                    if ($old_id && !$same_site) {
                        $collision = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM `" . esc_sql($table) . "` WHERE id=%d", $old_id));
                        if ($collision) unset($row['id']);
                    }
                    if ($wpdb->insert($table, $row) === false) throw new RuntimeException('ورود داده به جدول ' . $payload['suffix'] . ' ناموفق بود: ' . $wpdb->last_error);
                    $new_id = isset($row['id']) ? (int) $row['id'] : (int) $wpdb->insert_id;
                }
                if ($old_id) $id_maps[$payload['suffix']][$old_id] = $new_id;
            }
            unset($payload);
        }
        foreach ($this->json_file($dir, 'options.json') as $row) { if ($this->site_local_option($row['option_name'])) continue; update_option($row['option_name'], maybe_unserialize($row['option_value']), $row['autoload'] === 'yes'); }
        update_option($map_option, $id_maps, false);
        foreach ($this->json_file($dir, 'usermeta.json') as $row) {
            $uid = isset($user_map[(int) $row['user_id']]) ? $user_map[(int) $row['user_id']] : 0;
            if (!$uid) continue;
            if ($row['meta_key'] === $manifest['source_prefix'] . 'capabilities') $row['meta_key'] = $wpdb->prefix . 'capabilities';
            if ($row['meta_key'] === $manifest['source_prefix'] . 'user_level') $row['meta_key'] = $wpdb->prefix . 'user_level';
            $value = maybe_unserialize($row['meta_value']);
            if ($row['meta_key'] === $wpdb->prefix . 'capabilities' && is_array($value)) unset($value['administrator']);
            if (preg_match('/(avatar|photo|image|attachment).*(_id|id)$/i', $row['meta_key']) && isset($attachment_map[(int) $value])) $value = $attachment_map[(int) $value];
            update_user_meta($uid, $row['meta_key'], $value);
        }
        update_option('gtbp_last_complete_restore', array('at' => current_time('mysql'), 'source' => $manifest['source_url'], 'inventory' => $manifest['inventory']), false);
        $wpdb->query('COMMIT');
    }

    private function restore_extracted($work) {
        $manifest = $this->json_file($work, 'manifest.json');
        if ((int) $manifest['format'] !== self::FORMAT) throw new RuntimeException('نسخه ساختار بکاپ پشتیبانی نمی‌شود.');
        $safety_dir = trailingslashit(wp_upload_dir()['basedir']) . 'gtbp-private-backups';
        wp_mkdir_p($safety_dir);
        $safety = trailingslashit($safety_dir) . 'before-restore-' . gmdate('Ymd-His') . '-' . wp_generate_password(20, false, false) . '.zip';
        $this->build_archive($safety);
        if (file_exists($safety_dir . '/index.php') === false) file_put_contents($safety_dir . '/index.php', '<?php http_response_code(403); exit;');
        if (file_exists($safety_dir . '/.htaccess') === false) file_put_contents($safety_dir . '/.htaccess', "Deny from all\n");
        if (file_exists($safety_dir . '/web.config') === false) file_put_contents($safety_dir . '/web.config', '<configuration><system.webServer><authorization><deny users="*" /></authorization></system.webServer></configuration>');
        $this->restore_data($work, $manifest);
        return $manifest;
    }

    /* ---- همگام‌سازی بین دو سایت -------------------------------------------
     * هر دو سایت یک «رمز همگام‌سازی» مشترک دارند. دکمه «دریافت و ادغام» بستهٔ
     * کامل سایت مقابل را از REST endpoint امضاشده می‌گیرد و با همان مسیر
     * بازیابیِ ادغامی (بدون حذف داده) وارد می‌کند. اجرای همین دکمه روی هر دو
     * سایت یعنی همگام‌سازی دوطرفه: هر رکوردی که فقط روی یکی است به دیگری می‌رود.
     */

    private function sync_secret() { return (string) get_option('gtbp_sync_secret', ''); }

    private function sync_signature_valid($timestamp, $signature) {
        $secret = $this->sync_secret();
        if (strlen($secret) < 32 || !ctype_digit((string) $timestamp) || abs(time() - (int) $timestamp) > 300) return false;
        if (!preg_match('/^[a-f0-9]{64}$/', (string) $signature)) return false;
        return hash_equals(hash_hmac('sha256', $timestamp . '.gtbp-sync-export', $secret), (string) $signature);
    }

    public function sync_routes() {
        register_rest_route('gtbp-sync/v1', '/export', array(
            'methods' => 'GET',
            'callback' => array($this, 'sync_export'),
            'permission_callback' => '__return_true',
        ));
    }

    public function sync_export(WP_REST_Request $request) {
        if (!class_exists('ZipArchive')) return new WP_Error('unavailable', 'ZipArchive is not enabled', array('status' => 500));
        if (!$this->sync_signature_valid($request->get_header('x-gtbp-sync-timestamp'), $request->get_header('x-gtbp-sync-signature'))) {
            return new WP_Error('forbidden', 'Invalid sync signature', array('status' => 403));
        }
        if (function_exists('wp_raise_memory_limit')) wp_raise_memory_limit('admin');
        if (function_exists('set_time_limit')) @set_time_limit(0);
        $tmp = wp_tempnam('gtbp-sync-export.zip');
        try {
            $this->build_archive($tmp);
        } catch (Throwable $e) {
            @unlink($tmp);
            return new WP_Error('export_failed', $e->getMessage(), array('status' => 500));
        }
        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="gtbp-sync-' . gmdate('Ymd-His') . '.zip"');
        header('Content-Length: ' . filesize($tmp));
        readfile($tmp);
        @unlink($tmp);
        exit;
    }

    public function sync_save() {
        if (!current_user_can('manage_options')) wp_die('دسترسی کافی ندارید.', 403);
        check_admin_referer('gtbp_sync_save');
        $url = esc_url_raw(rtrim((string) wp_unslash($_POST['gtbp_sync_remote_url'] ?? ''), '/'));
        if ($url !== '' && stripos($url, 'https://') !== 0) wp_die('آدرس سایت مقابل باید با https:// شروع شود.');
        update_option('gtbp_sync_remote_url', $url, false);
        $secret = trim((string) wp_unslash($_POST['gtbp_sync_secret'] ?? ''));
        if ($secret !== '') {
            if (strlen($secret) < 32) wp_die('رمز همگام‌سازی باید حداقل ۳۲ نویسه باشد. می‌توانید از یک رشته تصادفی طولانی استفاده کنید.');
            update_option('gtbp_sync_secret', $secret, false);
        }
        wp_safe_redirect(add_query_arg(array('page' => self::PAGE, 'gtbp_migration_notice' => rawurlencode('تنظیمات همگام‌سازی ذخیره شد.')), admin_url('admin.php')));
        exit;
    }

    public function sync_pull() {
        global $wpdb;
        $this->guard('gtbp_sync_pull');
        if (function_exists('wp_raise_memory_limit')) wp_raise_memory_limit('admin');
        if (function_exists('set_time_limit')) @set_time_limit(0);
        $remote = (string) get_option('gtbp_sync_remote_url', '');
        $secret = $this->sync_secret();
        if ($remote === '' || strlen($secret) < 32) wp_die('ابتدا آدرس سایت مقابل و رمز همگام‌سازی (حداقل ۳۲ نویسه، یکسان روی هر دو سایت) را ذخیره کنید.');
        if (untrailingslashit($remote) === untrailingslashit(home_url())) wp_die('آدرس سایت مقابل نباید همین سایت باشد.');
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp . '.gtbp-sync-export', $secret);
        $download = wp_tempnam('gtbp-sync-download.zip');
        $response = wp_remote_get($remote . '/wp-json/gtbp-sync/v1/export', array(
            'timeout' => 600,
            'stream' => true,
            'filename' => $download,
            'headers' => array('X-GTBP-Sync-Timestamp' => $timestamp, 'X-GTBP-Sync-Signature' => $signature),
        ));
        if (is_wp_error($response)) { @unlink($download); wp_die('اتصال به سایت مقابل ناموفق بود: ' . esc_html($response->get_error_message())); }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200) { @unlink($download); wp_die('سایت مقابل بسته همگام‌سازی را نداد (HTTP ' . $code . '). مطمئن شوید افزونه و رمزِ یکسان روی هر دو سایت فعال است.'); }
        $work = trailingslashit(get_temp_dir()) . 'gtbp-sync-' . wp_generate_uuid4();
        wp_mkdir_p($work);
        $zip = new ZipArchive();
        $zip_open = false;
        try {
            if ($zip->open($download) !== true) throw new RuntimeException('فایل دریافتی ZIP معتبر نیست.');
            $zip_open = true;
            $this->safe_extract($zip, $work);
            $zip->close();
            $zip_open = false;
            $manifest = $this->restore_extracted($work);
            @unlink($download);
            $inventory = isset($manifest['inventory']['tables']) ? array_sum((array) $manifest['inventory']['tables']) : 0;
            $url = add_query_arg(array('page' => self::PAGE, 'gtbp_migration_notice' => rawurlencode('همگام‌سازی از ' . $manifest['source_url'] . ' انجام شد (' . $inventory . ' ردیف بررسی و ادغام شد). برای همگام‌سازی دوطرفه، همین دکمه را روی سایت مقابل هم بزنید.')), admin_url('admin.php'));
            wp_safe_redirect($url); exit;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($zip_open) $zip->close();
            @unlink($download);
            wp_die('<h1>همگام‌سازی انجام نشد</h1><p>' . esc_html($e->getMessage()) . '</p><p>هیچ داده‌ای از این سایت حذف نشده است.</p>');
        }
    }

    public function restore_upload() {
        global $wpdb;
        $this->guard('gtbp_complete_restore');
        if (function_exists('wp_raise_memory_limit')) wp_raise_memory_limit('admin');
        if (function_exists('set_time_limit')) @set_time_limit(0);
        if (empty($_POST['confirm_sensitive']) || empty($_FILES['gtbp_backup']['tmp_name']) || !is_uploaded_file($_FILES['gtbp_backup']['tmp_name'])) wp_die('فایل بکاپ معتبر انتخاب نشده است.');
        $work = trailingslashit(get_temp_dir()) . 'gtbp-restore-' . wp_generate_uuid4();
        wp_mkdir_p($work);
        $zip = new ZipArchive();
        $zip_open = false;
        try {
            if ($zip->open($_FILES['gtbp_backup']['tmp_name']) !== true) throw new RuntimeException('فایل ZIP قابل خواندن نیست.');
            $zip_open = true;
            $this->safe_extract($zip, $work);
            $zip->close();
            $zip_open = false;
            $this->restore_extracted($work);
            $url = add_query_arg(array('page' => self::PAGE, 'gtbp_migration_notice' => rawurlencode('بازیابی کامل با موفقیت انجام شد. نسخه ایمنی قبل از بازیابی نیز روی سرور ذخیره شد.')), admin_url('admin.php'));
            wp_safe_redirect($url); exit;
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            if ($zip_open) $zip->close();
            wp_die('<h1>بازیابی انجام نشد</h1><p>' . esc_html($e->getMessage()) . '</p><p>هیچ جدول موجودی حذف نشده است.</p>');
        }
    }
}
