<?php
/**
 * Plugin Name: Custom Price Approval System (Improved)
 * Description: دریافت تغییرات قیمت از اسکریپت خارجی و تأیید قبل از اعمال
 * Version: 2.0
 * Author: ari.jsx
 * Requires Plugins: woocommerce
 */

// جلوگیری از دسترسی مستقیم
if (!defined('ABSPATH')) {
    exit;
}

// ==================== وابستگی به ووکامرس ====================
if (!class_exists('WooCommerce')) {
    add_action('admin_notices', function () {
        echo '<div class="error"><p><strong>خطا در افزونه تأیید قیمت:</strong> این افزونه نیاز به نصب و فعال‌سازی ووکامرس دارد.</p></div>';
    });
    return;
}

// ==================== تنظیمات امنیتی ====================
// توکن را اینجا تعریف کن، یا از فیلتر برای تغییر آن استفاده کن
function cpa_get_api_token() {
    return apply_filters('cpa_api_token', 'moaserhome2024');
}

// ایجاد جدول لاگ هنگام فعال‌سازی
register_activation_hook(__FILE__, 'cpa_create_log_table');
function cpa_create_log_table() {
    global $wpdb;
    $table = $wpdb->prefix . 'cpa_price_log';
    $charset = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE IF NOT EXISTS $table (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id BIGINT UNSIGNED NOT NULL,
        product_name VARCHAR(255),
        sku VARCHAR(100),
        color VARCHAR(100),
        old_price DECIMAL(15,2),
        new_price DECIMAL(15,2),
        changed_by VARCHAR(100),
        changed_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_product (product_id),
        INDEX idx_date (changed_at)
    ) $charset;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);
}

// ==================== منوهای مدیریت ====================
add_action('admin_menu', 'cpa_add_admin_menu');
function cpa_add_admin_menu() {
    add_menu_page(
        'تأیید قیمت',
        'تأیید قیمت',
        'manage_options',
        'cpa-price-approval',
        'cpa_render_approval_page',
        'dashicons-update-alt',
        25
    );
    add_submenu_page(
        'cpa-price-approval',
        'گزارش تغییرات',
        '📊 گزارش تغییرات',
        'manage_options',
        'cpa-price-log',
        'cpa_render_log_page'
    );
}

// ==================== جلوگیری از کش شدن API ====================
add_action('rest_api_init', function () {
    if (strpos($_SERVER['REQUEST_URI'] ?? '', '/cpa/v1/') !== false) {
        nocache_headers();
        header('X-LiteSpeed-Cache-Control: no-cache');
        do_action('litespeed_control_set_nocache', 'CPA API endpoint');
    }
});

// ==================== دریافت داده از پایتون (API) ====================
add_action('rest_api_init', function () {
    register_rest_route('cpa/v1', '/pending-changes', [
        'methods' => 'POST',
        'callback' => 'cpa_receive_pending_changes',
        'permission_callback' => function () {
            // ✅ فقط هدر رو چک کن (امن‌تر)
            $token = $_SERVER['HTTP_X_API_TOKEN'] ?? '';
            if ($token === cpa_get_api_token()) {
                return true;
            }
            return new WP_Error('rest_forbidden', 'توکن نامعتبر است', ['status' => 403]);
        }
    ]);
});

function cpa_receive_pending_changes($request) {
    $changes = $request->get_json_params();

    // اعتبارسنجی اولیه
    if (!is_array($changes) || empty($changes)) {
        return new WP_REST_Response([
            'status' => 'error',
            'message' => 'داده‌های ارسالی معتبر نیستند یا خالی هستند.'
        ], 400);
    }

    $valid_changes = [];
    $errors = [];

    foreach ($changes as $index => $change) {
        // بررسی وجود کلیدهای ضروری
        if (!isset($change['sku'], $change['color'], $change['new_price'])) {
            $errors[] = "ردیف {$index}: کلیدهای sku, color, new_price الزامی هستند.";
            continue;
        }

        // اعتبارسنجی قیمت
        if (!is_numeric($change['new_price']) || $change['new_price'] < 0) {
            $errors[] = "ردیف {$index} (SKU: {$change['sku']}): قیمت نامعتبر است.";
            continue;
        }

        $product_id = cpa_find_product_by_sku_and_color(
            sanitize_text_field($change['sku']),
            sanitize_text_field($change['color'])
        );

        if (!$product_id) {
            $errors[] = "ردیف {$index} (SKU: {$change['sku']}, رنگ: {$change['color']}): محصول پیدا نشد.";
            continue;
        }

        $product = wc_get_product($product_id);
        if (!$product) {
            $errors[] = "ردیف {$index} (ID: {$product_id}): محصول معتبر نیست.";
            continue;
        }

        $valid_changes[] = [
            'product_id'    => $product_id,
            'product_name'  => $product->get_name(),
            'sku'           => sanitize_text_field($change['sku']),
            'color'         => sanitize_text_field($change['color']),
            'current_price' => (float) $product->get_regular_price(),
            'new_price'     => (float) $change['new_price'],
            'image'         => get_the_post_thumbnail_url($product_id, 'thumbnail') ?: '',
        ];
    }

    // ذخیره تغییرات معتبر در آپشن
    update_option('cpa_pending_changes', $valid_changes, false);

    return new WP_REST_Response([
        'status'      => 'success',
        'count'       => count($valid_changes),
        'errors'      => $errors,
        'errors_count' => count($errors)
    ], 200);
}

// ==================== پیدا کردن محصول (بهبود یافته) ====================
function cpa_find_product_by_sku_and_color($sku, $color) {
    $product_id = wc_get_product_id_by_sku($sku);
    if (!$product_id) {
        return null;
    }

    $product = wc_get_product($product_id);
    if (!$product) {
        return null;
    }

    // اگر محصول ساده است، رنگ اهمیتی ندارد (برگردان خود محصول)
    if ($product->is_type('simple')) {
        return $product_id;
    }

    // اگر محصول متغیر است، حتماً باید رنگ داشته باشد
    if ($product->is_type('variable')) {
        if (empty($color)) {
            return null; // برای متغیرها، رنگ اجباری است
        }

        // جستجوی variation با تطابق رنگ
        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                continue;
            }
            $color_attr = $variation->get_attribute('pa_color');
            if (strtolower(trim($color_attr)) === strtolower(trim($color))) {
                return $variation_id;
            }
        }
        return null; // رنگ مورد نظر پیدا نشد
    }

    // سایر انواع محصول (مثلاً external) را پشتیبانی نمی‌کنیم
    return null;
}

// ==================== پاک کردن کش (همان نسخه عالی قبلی) ====================
function cpa_clear_product_cache($product_id) {
    wc_delete_product_transients($product_id);
    clean_post_cache($product_id);

    $parent_id = wp_get_post_parent_id($product_id);
    if ($parent_id) {
        wc_delete_product_transients($parent_id);
        clean_post_cache($parent_id);
    }

    if (function_exists('wp_cache_flush_group')) {
        wp_cache_flush_group('product');
    }
}

// ==================== ثبت لاگ ====================
function cpa_log_change($item) {
    global $wpdb;
    $user = wp_get_current_user();
    $wpdb->insert(
        $wpdb->prefix . 'cpa_price_log',
        [
            'product_id'   => $item['product_id'],
            'product_name' => $item['product_name'],
            'sku'          => $item['sku'],
            'color'        => $item['color'],
            'old_price'    => $item['current_price'],
            'new_price'    => $item['new_price'],
            'changed_by'   => $user->user_login ?: 'system',
        ],
        ['%d', '%s', '%s', '%s', '%f', '%f', '%s']
    );
}

// ==================== صفحه اصلی تأیید تغییرات ====================
function cpa_render_approval_page() {
    $pending = get_option('cpa_pending_changes', []);
    ?>
    <div class="wrap cpa-wrap">
        <div class="cpa-header">
            <h1>📋 تأیید تغییرات قیمت</h1>
            <div class="cpa-stats">
                <span class="stat-badge"><?= count($pending) ?> محصول در انتظار تأیید</span>
                <label style="margin-right:15px; cursor:pointer;">
                    <input type="checkbox" id="cpa-select-all"> انتخاب همه
                </label>
                <button id="cpa-approve-all" class="button button-primary">✅ تأیید همه</button>
                <button id="cpa-reject-all" class="button">🗑️ رد همه</button>
            </div>
        </div>

        <?php if (empty($pending)): ?>
            <div class="cpa-empty">
                <div class="cpa-empty-icon">🎉</div>
                <h2>هیچ تغییر در انتظار تأییدی وجود ندارد</h2>
                <p>اسکریپت پایتون را اجرا کنید تا لیست تغییرات جدید دریافت شود</p>
            </div>
        <?php else: ?>
            <div class="cpa-grid">
                <?php foreach ($pending as $index => $item): ?>
                    <div class="cpa-card" data-index="<?= $index ?>">
                        <div class="cpa-card-header">
                            <div class="cpa-checkbox">
                                <input type="checkbox" class="cpa-select-item" id="item-<?= $index ?>" data-index="<?= $index ?>">
                                <label for="item-<?= $index ?>"></label>
                            </div>
                            <div class="cpa-product-image">
                                <?php if ($item['image']): ?>
                                    <img src="<?= esc_url($item['image']) ?>" alt="<?= esc_attr($item['product_name']) ?>">
                                <?php else: ?>
                                    <div class="cpa-no-image">📦</div>
                                <?php endif; ?>
                            </div>
                            <div class="cpa-product-info">
                                <h3><?= esc_html($item['product_name']) ?></h3>
                                <div class="cpa-product-meta">
                                    <span class="cpa-sku">کد: <?= esc_html($item['sku']) ?></span>
                                    <span class="cpa-color">رنگ: <?= esc_html($item['color']) ?></span>
                                </div>
                            </div>
                        </div>
                        <div class="cpa-card-body">
                            <div class="cpa-price-comparison">
                                <div class="cpa-old-price">
                                    <span class="label">قیمت فعلی</span>
                                    <span class="value"><?= number_format($item['current_price']) ?> ریال</span>
                                </div>
                                <div class="cpa-arrow">→</div>
                                <div class="cpa-new-price">
                                    <span class="label">قیمت جدید</span>
                                    <span class="value highlight"><?= number_format($item['new_price']) ?> ریال</span>
                                </div>
                                <div class="cpa-diff">
                                    <?php $diff = $item['new_price'] - $item['current_price']; ?>
                                    <span class="diff-badge <?= $diff > 0 ? 'increase' : ($diff < 0 ? 'decrease' : 'no-change') ?>">
                                        <?= $diff > 0 ? '▲' : ($diff < 0 ? '▼' : '●') ?>
                                        <?= number_format(abs($diff)) ?> ریال
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="cpa-footer">
                <button id="cpa-approve-selected" class="button button-primary button-hero">✅ اعمال تغییرات انتخاب‌شده</button>
                <button id="cpa-cancel" class="button">🗑️ لغو و حذف همه</button>
            </div>
        <?php endif; ?>
    </div>

    <style>
        /* ===== تمام استایل‌های قبلی ===== */
        .cpa-wrap { padding: 20px; background: #f0f2f5; min-height: calc(100vh - 32px); font-family: -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif; }
        .cpa-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 30px; flex-wrap: wrap; gap: 15px; background: white; padding: 20px 25px; border-radius: 15px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
        .cpa-stats { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
        .stat-badge { background: #2271b1; color: white; padding: 8px 20px; border-radius: 30px; font-weight: 500; font-size: 0.9rem; }
        .cpa-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 25px; margin-bottom: 30px; }
        .cpa-card { background: white; border-radius: 18px; box-shadow: 0 6px 20px rgba(0,0,0,0.08); overflow: hidden; transition: all 0.3s ease; border: 1px solid #e9ecef; }
        .cpa-card:hover { transform: translateY(-4px); box-shadow: 0 15px 35px rgba(0,0,0,0.12); }
        .cpa-card-header { display: flex; gap: 15px; padding: 18px; background: #fafcfc; border-bottom: 1px solid #edf2f7; align-items: center; }
        .cpa-checkbox { flex-shrink: 0; }
        .cpa-checkbox input { width: 22px; height: 22px; cursor: pointer; accent-color: #2271b1; }
        .cpa-product-image img, .cpa-no-image { width: 65px; height: 65px; border-radius: 14px; object-fit: cover; background: #f1f3f5; display: flex; align-items: center; justify-content: center; font-size: 30px; flex-shrink:0; }
        .cpa-product-info { flex: 1; min-width:0; }
        .cpa-product-info h3 { margin: 0 0 8px 0; font-size: 1.05rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cpa-product-meta { display: flex; gap: 15px; font-size: 0.8rem; color: #5b6e8c; }
        .cpa-card-body { padding: 18px; }
        .cpa-price-comparison { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        .cpa-old-price, .cpa-new-price { flex: 1; text-align: center; padding: 10px; border-radius: 14px; background: #f8f9fc; min-width:80px; }
        .cpa-old-price .label, .cpa-new-price .label { font-size: 0.65rem; text-transform: uppercase; color: #6c7a91; display: block; margin-bottom: 6px; letter-spacing: 0.5px; }
        .cpa-old-price .value { font-size: 0.95rem; font-weight: 600; color: #2c3e50; }
        .cpa-new-price .value { font-size: 1.15rem; font-weight: 700; color: #2c7a4d; }
        .cpa-arrow { font-size: 1.6rem; color: #98a2b3; }
        .diff-badge { display: inline-block; padding: 6px 14px; border-radius: 40px; font-weight: 600; font-size: 0.8rem; white-space: nowrap; }
        .diff-badge.increase { background: #e6f7ec; color: #2c7a4d; }
        .diff-badge.decrease { background: #fee9e6; color: #c2412c; }
        .diff-badge.no-change { background: #f1f3f5; color: #5b6e8c; }
        .cpa-footer { display: flex; justify-content: center; gap: 20px; margin-top: 15px; padding: 20px 0; }
        .cpa-empty { text-align: center; padding: 80px 20px; background: white; border-radius: 32px; }
        .cpa-empty-icon { font-size: 70px; margin-bottom: 20px; }
        @media (max-width: 768px) { .cpa-grid { grid-template-columns: 1fr; } .cpa-price-comparison { flex-direction: column; } .cpa-arrow { transform: rotate(90deg); } .cpa-header { flex-direction: column; align-items: stretch; } }
    </style>

    <script>
        jQuery(document).ready(function($) {
            // ===== انتخاب همه =====
            $('#cpa-select-all').on('change', function() {
                $('.cpa-select-item').prop('checked', this.checked);
            });

            // ===== اعمال انتخاب‌شده‌ها =====
            $('#cpa-approve-selected').on('click', function() {
                let selected = $('.cpa-select-item:checked').map(function() {
                    return $(this).data('index');
                }).get();
                if (selected.length === 0) {
                    alert('لطفاً حداقل یک محصول را انتخاب کنید.');
                    return;
                }
                if (!confirm('آیا از اعمال تغییرات روی ' + selected.length + ' محصول مطمئن هستید؟')) return;

                $.post(ajaxurl, {
                    action: 'cpa_apply_changes',
                    items: selected,
                    _ajax_nonce: '<?= wp_create_nonce('cpa_nonce') ?>'
                }, function(res) {
                    if (res.success) location.reload();
                    else alert('خطا: ' + (res.data || 'مشخص نیست'));
                });
            });

            // ===== تأیید همه =====
            $('#cpa-approve-all').on('click', function() {
                if (!confirm('همه تغییرات (<?= count($pending) ?> مورد) اعمال شوند؟')) return;
                $.post(ajaxurl, {
                    action: 'cpa_apply_all',
                    _ajax_nonce: '<?= wp_create_nonce('cpa_nonce') ?>'
                }, function(res) {
                    if (res.success) location.reload();
                    else alert('خطا: ' + (res.data || 'مشخص نیست'));
                });
            });

            // ===== رد همه / لغو =====
            $('#cpa-reject-all, #cpa-cancel').on('click', function() {
                if (!confirm('همه تغییرات حذف شوند؟ این کار قابل بازگشت نیست.')) return;
                $.post(ajaxurl, {
                    action: 'cpa_reject_all',
                    _ajax_nonce: '<?= wp_create_nonce('cpa_nonce') ?>'
                }, function(res) {
                    if (res.success) location.reload();
                    else alert('خطا: ' + (res.data || 'مشخص نیست'));
                });
            });
        });
    </script>
    <?php
}

// ==================== AJAX Handlerها ====================
add_action('wp_ajax_cpa_apply_changes', 'cpa_ajax_apply_changes');
function cpa_ajax_apply_changes() {
    check_ajax_referer('cpa_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('دسترسی غیرمجاز');
    }

    $items = array_map('intval', $_POST['items'] ?? []);
    $pending = get_option('cpa_pending_changes', []);
    $remaining = [];

    foreach ($pending as $index => $item) {
        if (in_array((int)$index, $items, true)) {
            // اعمال قیمت
            $product = wc_get_product($item['product_id']);
            if ($product) {
                $product->set_regular_price($item['new_price']);
                $product->set_price($item['new_price']);
                $product->save();
            }

            cpa_clear_product_cache($item['product_id']);
            cpa_log_change($item);
        } else {
            $remaining[] = $item;
        }
    }

    update_option('cpa_pending_changes', $remaining);
    wp_send_json_success();
}

add_action('wp_ajax_cpa_apply_all', 'cpa_ajax_apply_all');
function cpa_ajax_apply_all() {
    check_ajax_referer('cpa_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('دسترسی غیرمجاز');
    }

    $pending = get_option('cpa_pending_changes', []);
    foreach ($pending as $item) {
        $product = wc_get_product($item['product_id']);
        if ($product) {
            $product->set_regular_price($item['new_price']);
            $product->set_price($item['new_price']);
            $product->save();
        }

        cpa_clear_product_cache($item['product_id']);
        cpa_log_change($item);
    }

    update_option('cpa_pending_changes', []);
    wp_send_json_success();
}

add_action('wp_ajax_cpa_reject_all', 'cpa_ajax_reject_all');
function cpa_ajax_reject_all() {
    check_ajax_referer('cpa_nonce');
    if (!current_user_can('manage_options')) {
        wp_send_json_error('دسترسی غیرمجاز');
    }
    update_option('cpa_pending_changes', []);
    wp_send_json_success();
}

// ==================== صفحه گزارش تغییرات ====================
function cpa_render_log_page() {
    global $wpdb;
    $table = $wpdb->prefix . 'cpa_price_log';

    $from = sanitize_text_field($_GET['from'] ?? date('Y-m-01'));
    $to   = sanitize_text_field($_GET['to']   ?? date('Y-m-d'));

    if (!empty($_GET['clear_log']) && check_admin_referer('cpa_clear_log')) {
        $wpdb->query("TRUNCATE TABLE $table");
        echo '<div class="notice notice-success"><p>✅ لاگ با موفقیت پاک شد.</p></div>';
    }

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM $table WHERE DATE(changed_at) BETWEEN %s AND %s ORDER BY changed_at DESC LIMIT 500",
        $from, $to
    ));
    ?>
    <div class="wrap">
        <h1>📊 گزارش تغییرات قیمت</h1>
        <form method="get" style="margin:15px 0;display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
            <input type="hidden" name="page" value="cpa-price-log">
            <label>از: <input type="date" name="from" value="<?= esc_attr($from) ?>"></label>
            <label>تا: <input type="date" name="to" value="<?= esc_attr($to) ?>"></label>
            <button class="button">فیلتر</button>
            <a href="<?= wp_nonce_url(admin_url('admin.php?page=cpa-price-log&clear_log=1'), 'cpa_clear_log') ?>"
               class="button" onclick="return confirm('همه لاگ‌ها پاک شوند؟')">🗑️ پاک کردن لاگ</a>
        </form>

        <table class="widefat striped" style="direction:rtl;border-radius:12px;overflow:hidden;">
            <thead>
                <tr>
                    <th>تاریخ</th><th>نام محصول</th><th>SKU</th><th>رنگ</th>
                    <th>قیمت قبلی</th><th>قیمت جدید</th><th>تغییر</th><th>توسط</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="8" style="text-align:center;padding:40px;">هیچ رکوردی یافت نشد</td></tr>
            <?php else: foreach ($rows as $r):
                $diff = $r->new_price - $r->old_price;
                $pct  = $r->old_price > 0 ? round($diff / $r->old_price * 100, 1) : 0;
                $color = $diff > 0 ? '#2c7a4d' : ($diff < 0 ? '#c2412c' : '#555');
            ?>
                <tr>
                    <td><?= esc_html($r->changed_at) ?></td>
                    <td><?= esc_html($r->product_name) ?></td>
                    <td><?= esc_html($r->sku) ?></td>
                    <td><?= esc_html($r->color) ?></td>
                    <td><?= number_format($r->old_price) ?> ریال</td>
                    <td><?= number_format($r->new_price) ?> ریال</td>
                    <td style="color:<?= $color ?>;font-weight:600;">
                        <?= $diff >= 0 ? '+' : '' ?><?= number_format($diff) ?> ریال (<?= $pct ?>%)
                    </td>
                    <td><?= esc_html($r->changed_by) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}