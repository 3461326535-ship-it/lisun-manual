<?php
/**
 * 分享管理页面
 */

if (!defined('ABSPATH')) {
    exit;
}

$customers = Lisun_Manual_Customer::get_all();
$access_logs = Lisun_Manual_Access_Control::get_logs(50);
$tags = Lisun_Manual_Customer::get_manual_tags();
$user_customer_type = Lisun_Manual_Customer::get_user_customer_type(); // 当前用户可管理的类型

// 从全部日志中收集筛选下拉框的选项值
$all_logs = get_option('lisun_access_logs', []);
$log_users = [];
$log_models = [];
$log_pages = [];
foreach ($all_logs as $log) {
    if (!empty($log['username']) && !in_array($log['username'], $log_users)) {
        $log_users[] = $log['username'];
    }
    if (!empty($log['product_model']) && !in_array($log['product_model'], $log_models)) {
        $log_models[] = $log['product_model'];
    }
    if (!empty($log['post_title']) && !in_array($log['post_title'], $log_pages)) {
        $log_pages[] = $log['post_title'];
    }
}
sort($log_users);
sort($log_models);
sort($log_pages);
?>

<div class="wrap">
    <h1 class="wp-heading-inline">分享管理</h1>
    <hr class="wp-header-end">

    <!-- 操作栏 -->
    <div class="lisun-table-actions">
        <div>
            <button type="button" class="button button-primary" id="lisun-add-customer-btn">
                添加账号
            </button>
            <label class="button" for="lisun-customer-csv-input">
                批量导入 CSV
            </label>
            <input type="file" id="lisun-customer-csv-input" accept=".csv" style="display:none;">
        </div>
    </div>

    <!-- 添加/编辑表单 -->
    <div id="lisun-customer-form" style="display:none; background:#fff; border:1px solid #ddd; padding:20px; margin-bottom:15px; border-radius:4px;">
        <h3 style="margin-top:0;">添加客户</h3>
        <table class="form-table">
            <!-- 序号 (只读) -->
            <tr id="lisun-serial-row" style="display:none;">
                <th><label>序号</label></th>
                <td><input type="text" id="lisun-customer-serial" class="regular-text" readonly style="background:#f0f0f0;"></td>
            </tr>

            <!-- 类型 -->
            <tr>
                <th><label for="lisun-customer-type">类型 <span style="color:red;">*</span></label></th>
                <td>
                    <select id="lisun-customer-type" style="width:240px;">
                        <option value="">— 请选择 —</option>
                        <?php if (!$user_customer_type || $user_customer_type === 'both' || $user_customer_type === 'pre_sale'): ?>
                        <option value="pre_sale">售前</option>
                        <?php endif; ?>
                        <?php if (!$user_customer_type || $user_customer_type === 'both' || $user_customer_type === 'after_sale'): ?>
                        <option value="after_sale">售后</option>
                        <?php endif; ?>
                    </select>
                </td>
            </tr>

            <!-- 产品型号（多选 + 搜索，只读不可新建） -->
            <tr id="lisun-models-row">
                <th><label>产品型号 <span class="lisun-pre-sale-required" style="color:red;display:none;">*</span></label></th>
                <td>
                    <div class="lisun-model-select-wrap" style="max-width:520px;">
                        <div class="lisun-model-search">
                            <input type="text" id="lisun-model-search" placeholder="搜索产品型号..." autocomplete="off" style="width:100%;height:32px;">
                            <div class="lisun-model-dropdown" id="lisun-model-dropdown" style="display:none;"></div>
                        </div>
                        <div class="lisun-model-tags" id="lisun-model-tags"></div>
                        <select id="lisun-customer-models" name="product_models[]" multiple style="display:none;"></select>
                    </div>
                    <p class="description">从已有标签中选择，售前客户至少选择一个型号。</p>
                </td>
            </tr>

            <!-- 单据类型 -->
            <tr>
                <th><label for="lisun-customer-doctype">单据类型 <span style="color:red;">*</span></label></th>
                <td>
                    <select id="lisun-customer-doctype" style="width:240px;">
                        <option value="">— 请选择 —</option>
                        <option value="quotation">报价单号</option>
                        <option value="contract">外销合同号</option>
                        <option value="shipping">出运明细号</option>
                    </select>
                </td>
            </tr>

            <!-- 单据号 = 用户名 -->
            <tr>
                <th><label for="lisun-customer-docnumber">单据号 / 用户名 <span style="color:red;">*</span></label></th>
                <td>
                    <input type="text" id="lisun-customer-docnumber" class="regular-text" required maxlength="14" placeholder="请先选择单据类型" style="font-family:monospace;">
                    <p class="description" id="lisun-docnumber-hint">格式：QTLS20xxxxxx，共 12 位。此单据号即为登录用户名。</p>
                </td>
            </tr>

            <!-- 密码 -->
            <tr>
                <th><label for="lisun-customer-password">密码</label></th>
                <td>
                    <input type="text" id="lisun-customer-password" class="regular-text" style="width:240px;">
                    <button type="button" class="button" id="lisun-gen-password">随机生成</button>
                    <p class="description">留空则自动生成 12 位随机密码。</p>
                </td>
            </tr>

            <!-- 备注 -->
            <tr>
                <th><label for="lisun-customer-note">备注</label></th>
                <td><input type="text" id="lisun-customer-note" class="regular-text"></td>
            </tr>
        </table>
        <p style="margin-top:8px;">
            <input type="hidden" id="lisun-edit-username" value="">
            <button type="button" class="button button-primary" id="lisun-save-customer-btn">创建账号</button>
            <button type="button" class="button" id="lisun-cancel-customer-btn">取消</button>
        </p>
    </div>

    <!-- 筛选栏 -->
    <div class="lisun-filter-bar" style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
        <select id="lisun-filter-type" style="width:140px;">
            <option value="">全部类型</option>
            <?php if ($user_customer_type !== 'after_sale'): ?>
            <option value="pre_sale">售前</option>
            <?php endif; ?>
            <?php if ($user_customer_type !== 'pre_sale'): ?>
            <option value="after_sale">售后</option>
            <?php endif; ?>
        </select>
        <input type="text" id="lisun-filter-search" placeholder="搜索用户名或型号..." style="width:240px;">
        <button type="button" class="button" id="lisun-filter-btn">筛选</button>
        <button type="button" class="button" id="lisun-filter-reset">重置</button>
    </div>

    <!-- 客户列表 -->
    <table class="wp-list-table widefat fixed striped" id="lisun-customer-table">
        <thead>
            <tr>
                <th width="100">序号</th>
                <th width="60">类型</th>
                <th width="120">产品型号</th>
                <th width="110">用户名</th>
                <th width="120">密码</th>
                <th width="105">创建日期</th>
                <th width="105">初访日期</th>
                <th width="70">访问次数</th>
                <th width="220">操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (!empty($customers)): ?>
                <?php foreach ($customers as $username => $data):
                    // 按权限过滤
                    $row_type = $data['type'] ?? '';
                    if ($user_customer_type && $user_customer_type !== 'both' && $row_type !== $user_customer_type) continue;
                    ?>
                <?php
                $model_terms = Lisun_Manual_Customer::get_model_terms($data);
                $model_values = array_map(static function ($term) { return strtoupper((string) $term->name); }, $model_terms);
                foreach (($data['product_models'] ?? []) as $legacy_model) {
                    if (!Lisun_Manual_Customer::resolve_model_term($legacy_model)) {
                        $model_values[] = strtoupper((string) $legacy_model);
                    }
                }
                ?>
                <tr data-username="<?php echo esc_attr($username); ?>"
                    data-type="<?php echo esc_attr($row_type); ?>"
                    data-doctype="<?php echo esc_attr($data['doc_type'] ?? ''); ?>"
                    data-note="<?php echo esc_attr($data['note'] ?? ''); ?>"
                    data-models="<?php echo esc_attr(wp_json_encode(array_values(array_unique($model_values)))); ?>">
                    <td><?php echo esc_html($data['serial'] ?? '—'); ?></td>
                    <td>
                        <?php if (($data['type'] ?? '') === 'pre_sale'): ?>
                            <span class="lisun-type-badge lisun-type-pre">售前</span>
                        <?php else: ?>
                            <span class="lisun-type-badge lisun-type-after">售后</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php
                        $models = $data['product_models'] ?? [];
                        if (!empty($model_terms)):
                            foreach ($model_terms as $term):
                                echo '<span class="lisun-model-chip-inline">' . esc_html(strtoupper($term->name)) . '</span> ';
                            endforeach;
                        elseif (!empty($models)):
                            foreach ($models as $m):
                                echo '<span class="lisun-model-chip-inline">' . esc_html(strtoupper($m)) . '</span> ';
                            endforeach;
                        else:
                            echo '<span style="color:#999;">—</span>';
                        endif;
                        ?>
                    </td>
                    <td>
                        <span class="lisun-copy-text" title="点击复制"><?php echo esc_html($username); ?></span>
                    </td>
                    <td>
                        <span class="lisun-password-text" data-username="<?php echo esc_attr($username); ?>" title="点击复制密码"><?php echo esc_html($data['password_plain'] ?? ($data['hash'] ? '••••••（已加密，请重置）' : '—')); ?></span>
                    </td>
                    <td><?php echo esc_html($data['created'] ?? '—'); ?></td>
                    <td><?php echo esc_html($data['first_visit'] ?? '—'); ?></td>
                    <td><?php echo intval($data['visit_count'] ?? 0); ?></td>
                    <td>
                        <button type="button" class="button button-small lisun-edit-customer" data-username="<?php echo esc_attr($username); ?>">编辑</button>
                        <button type="button" class="button button-small lisun-reset-password"
                                data-username="<?php echo esc_attr($username); ?>">
                            重置密码
                        </button>
                        <?php if (!empty($model_terms) || !empty($data['product_models'] ?? [])): ?>
                        <?php
                        // 售前、售后统一：每个已选型号取一篇对应说明书，
                        // 多个链接在复制时按当前型号顺序用换行连接。
                        $copy_urls = [];
                        foreach ($model_terms as $term) {
                            $m = get_posts([
                                'post_type'      => 'manual',
                                'posts_per_page' => 1,
                                'tax_query'      => [['taxonomy' => 'post_tag', 'field' => 'term_id', 'terms' => Lisun_Manual_Customer::get_equivalent_model_ids($term)]],
                                'post_status'    => 'publish',
                            ]);
                            if (!empty($m)) {
                                $url = get_permalink($m[0]);
                                if ($url) $copy_urls[] = $url;
                            }
                        }
                        $copy_urls = array_values(array_unique(array_filter($copy_urls)));
                        // 保持“选了型号就有按钮”：历史数据若型号已删除或尚无
                        // 对应说明书，沿用原逻辑复制说明书首页。
                        if (empty($copy_urls)) $copy_urls[] = home_url('/manual/');
                        $copy_value = implode("\n", $copy_urls);
                        $copy_password = (string) ($data['password_plain'] ?? '');
                        ?>
                        <button type="button" class="button button-small lisun-copy-auth-url" style="color:#2271b1;"
                                data-url="<?php echo esc_attr($copy_value); ?>">
                            复制链接
                        </button>
                        <button type="button" class="button button-small lisun-copy-all" style="color:#2271b1;"
                                data-url="<?php echo esc_attr($copy_value); ?>"
                                data-username="<?php echo esc_attr($username); ?>"
                                data-password="<?php echo esc_attr($copy_password); ?>">
                            全部复制
                        </button>
                        <?php endif; ?>
                        <button type="button" class="button button-small lisun-delete-customer"
                                data-username="<?php echo esc_attr($username); ?>"
                                style="color:#d63638;">
                            删除
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr><td colspan="9" style="text-align:center;color:#999;">暂无客户账号</td></tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- 访问日志 -->
    <h2 style="margin-top:40px;">访问日志</h2>

    <?php if (!empty($access_logs)): ?>
    <!-- 日志筛选栏 -->
    <div class="lisun-filter-bar" style="margin-bottom:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <select id="lisun-log-filter-user" style="width:160px;">
            <option value="">全部用户</option>
            <?php foreach ($log_users as $u): ?>
                <option value="<?php echo esc_attr($u); ?>"><?php echo esc_html($u); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="lisun-log-filter-model" style="width:160px;">
            <option value="">全部型号</option>
            <?php foreach ($log_models as $m): ?>
                <option value="<?php echo esc_attr($m); ?>"><?php echo esc_html(strtoupper($m)); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="lisun-log-filter-page" style="width:200px;">
            <option value="">全部页面</option>
            <?php foreach ($log_pages as $p): ?>
                <option value="<?php echo esc_attr($p); ?>"><?php echo esc_html($p); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="button button-small" id="lisun-log-filter-reset">重置</button>
        <span id="lisun-log-filter-count" style="font-size:12px;color:#666;"></span>
    </div>

    <div class="lisun-access-logs">
        <table class="wp-list-table widefat fixed striped" id="lisun-log-table">
            <thead>
                <tr>
                    <th>用户</th>
                    <th width="80">国家</th>
                    <th width="80">城市</th>
                    <th>IP</th>
                    <th width="100">产品型号</th>
                    <th>页面</th>
                    <th width="160">时间</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($access_logs as $log): ?>
                <tr data-log-user="<?php echo esc_attr($log['username'] ?? ''); ?>"
                    data-log-model="<?php echo esc_attr($log['product_model'] ?? ''); ?>"
                    data-log-page="<?php echo esc_attr($log['post_title'] ?? ''); ?>">
                    <td><?php echo esc_html($log['username']); ?></td>
                    <td><?php echo esc_html($log['country'] ?? ''); ?></td>
                    <td><?php echo esc_html($log['city'] ?? ''); ?></td>
                    <td><code><?php echo esc_html($log['ip']); ?></code></td>
                    <td>
                        <?php if (!empty($log['product_model'])): ?>
                            <span style="font-weight:600;color:#1677ff;"><?php echo esc_html(strtoupper($log['product_model'])); ?></span>
                        <?php else: ?>
                            <span style="color:#999;">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($log['post_id'])): ?>
                            <a href="<?php echo get_permalink($log['post_id']); ?>" target="_blank">
                                <?php echo esc_html($log['post_title']); ?>
                            </a>
                        <?php else: ?>
                            归档页
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($log['time']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <p style="color:#999;">暂无访问记录。</p>
    <?php endif; ?>
</div>

<script>
jQuery(function ($) {
    var nonce = '<?php echo wp_create_nonce("lisun_manual_nonce"); ?>';

    // ====== 权限锁定：类型下拉自动选中 ======
    <?php if ($user_customer_type && $user_customer_type !== 'both'): ?>
    $('#lisun-customer-type').val('<?php echo $user_customer_type; ?>').trigger('change');
    <?php endif; ?>

    // ====== 产品型号多选（AJAX 搜索，只读不可新建） ======
    var allTags = [];
    var selectedModels = [];

    $.post(ajaxurl, { action: 'lisun_get_manual_tags', _nonce: nonce }, function (res) {
        if (res.success) { allTags = res.data; }
    });

    function renderModelTags() {
        var $container = $('#lisun-model-tags');
        var $hidden = $('#lisun-customer-models');
        $container.empty();
        $hidden.empty();
        selectedModels.forEach(function (m, idx) {
            $container.append(
                '<span class="lisun-model-chip">' + m.name +
                '<button type="button" class="lisun-model-remove" data-idx="' + idx + '">&times;</button></span>'
            );
            $hidden.append('<option value="' + (m.id || m.slug) + '" selected></option>');
        });
    }

    function getModelValues() {
        return selectedModels.map(function (m) { return m.id || m.slug; });
    }

    // data-models 现以 JSON 数组保存型号名称；兼容旧的逗号分隔格式。
    function parseModelValues(raw) {
        raw = (raw || '').trim();
        if (!raw) return [];
        if (raw.charAt(0) === '[') {
            try { return JSON.parse(raw); } catch (e) { return raw.split(','); }
        }
        return raw.split(',');
    }

    function setSelectedModels(values) {
        selectedModels = [];
        (values || []).forEach(function (value) {
            var found = allTags.filter(function (t) { return String(t.id) === String(value) || t.slug === value || t.name === value; });
            if (found.length) selectedModels.push(found[0]);
            else selectedModels.push({ slug: value, name: value });
        });
        renderModelTags();
    }

    $('#lisun-model-tags').on('click', '.lisun-model-remove', function () {
        selectedModels.splice($(this).data('idx'), 1);
        renderModelTags();
    });

    // 搜索输入 + 逗号/回车自动选中
    var searchTimer;
    $('#lisun-model-search').on('input keydown', function (e) {
        if (e.type === 'keydown' && e.key === 'Enter') {
            e.preventDefault();
            var val = $(this).val().trim();
            if (!val) return;
            var exact = allTags.filter(function (t) { return !selectedModels.some(function(m){return m.slug===t.slug;}) && t.name.toLowerCase()===val.toLowerCase(); })[0];
            if (!exact) exact = allTags.filter(function (t) { return !selectedModels.some(function(m){return m.slug===t.slug;}) && t.slug.toLowerCase()===val.toLowerCase(); })[0];
            if (exact) { selectedModels.push(exact); renderModelTags(); $(this).val(''); }
            $('#lisun-model-dropdown').hide();
            return;
        }
        clearTimeout(searchTimer);
        var q = $(this).val().trim();
        var parts = q.split(',');
        var last = parts.pop().trim();
        parts.forEach(function(p){
            p = p.trim(); if (!p) return;
            var exact = allTags.filter(function(t){ return !selectedModels.some(function(m){return m.slug===t.slug;}) && t.name.toLowerCase()===p.toLowerCase(); })[0];
            if (!exact) exact = allTags.filter(function(t){ return !selectedModels.some(function(m){return m.slug===t.slug;}) && t.slug.toLowerCase()===p.toLowerCase(); })[0];
            if (exact) { selectedModels.push(exact); }
        });
        if (parts.length > 0) { $(this).val(last); renderModelTags(); }
        if (!last) { $('#lisun-model-dropdown').hide(); return; }
        searchTimer = setTimeout(function () {
            var results = allTags.filter(function (t) {
                if (selectedModels.some(function (m) { return m.slug === t.slug; })) return false;
                return t.name.toLowerCase().indexOf(last.toLowerCase()) !== -1
                    || t.slug.toLowerCase().indexOf(last.toLowerCase()) !== -1;
            }).slice(0, 15);
            var $dd = $('#lisun-model-dropdown').empty();
            if (results.length === 0) {
                $dd.append('<div class="lisun-model-noresult">无匹配结果</div>');
            } else {
                results.forEach(function (t) {
                    $dd.append('<div class="lisun-model-option" data-slug="' + t.slug + '" data-name="' + t.name + '">' + t.name + '</div>');
                });
            }
            $dd.show();
        }, 200);
    });

    $('#lisun-model-dropdown').on('click', '.lisun-model-option', function () {
        var selectedSlug = $(this).data('slug');
        var selected = allTags.filter(function (t) { return t.slug === selectedSlug; })[0];
        selectedModels.push(selected || { slug: selectedSlug, name: $(this).data('name') });
        renderModelTags();
        $('#lisun-model-search').val('').focus();
        $('#lisun-model-dropdown').hide();
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('.lisun-model-search').length) {
            $('#lisun-model-dropdown').hide();
        }
    });

    // ====== 单据类型切换 → 动态占位符 ======
    var docPrefixes = { quotation: 'QTLS', contract: 'PILS', shipping: 'EXLS/ZKLS/LS/AL033' };
    var docMaxLengths = { quotation: 12, contract: 12, shipping: 14 };
    $('#lisun-customer-doctype').on('change', function () {
        var dt = $(this).val();
        var prefix = docPrefixes[dt] || '';
        var maxLen = docMaxLengths[dt] || 14;
        var placeholder, hint;
        if (dt === 'shipping') {
            placeholder = 'EXLS / ZKLS / LS / AL033 开头';
            hint = '以 EXLS、ZKLS、LS 或 AL033 开头，最大 14 位。此单据号即为登录用户名。';
        } else if (prefix) {
            placeholder = prefix + '20260001';
            hint = '格式：' + prefix + '20xxxxxx，共 12 位。此单据号即为登录用户名。';
        } else {
            placeholder = '';
            hint = '此单据号即为登录用户名。';
        }
        $('#lisun-customer-docnumber').attr({placeholder: placeholder, maxlength: maxLen});
        $('#lisun-docnumber-hint').text(hint);
    });

    // ====== 单据号校验 ======
    function validateDocNumber(docNumber, docType) {
        if (!docNumber || docNumber.length === 0) {
            return '请输入单据号。';
        }
        // 出运明细号：EXLS/ZKLS/LS/AL033 开头，最大 14 位
        if (docType === 'shipping') {
            if (docNumber.length > 14) return '出运明细号最大 14 位。';
            var shippingPrefixes = ['AL033', 'EXLS', 'ZKLS', 'LS'];
            var matched = false;
            for (var i = 0; i < shippingPrefixes.length; i++) {
                if (docNumber.substring(0, shippingPrefixes[i].length) === shippingPrefixes[i]) {
                    matched = true;
                    break;
                }
            }
            if (!matched) return '出运明细号必须以 EXLS、ZKLS、LS 或 AL033 开头。';
            return null;
        }
        // 报价单号 / 外销合同号：前缀 + 年份，12 位
        if (docNumber.length !== 12) return '单据号必须为 12 位。';
        var prefix = docPrefixes[docType];
        if (!prefix || docNumber.substring(0, 4) !== prefix) {
            return '前 4 位必须为 ' + (prefix || '') + '。';
        }
        if (docNumber[4] !== '2' || docNumber[5] !== '0') {
            return '第 5 位必须为 2，第 6 位必须为 0。';
        }
        return null;
    }

    // ====== 类型切换 ======
    $('#lisun-customer-type').on('change', function () {
        var isPre = $(this).val() === 'pre_sale';
        $('.lisun-pre-sale-required').toggle(isPre);
    });

    // ====== 显示添加表单 ======
    $('#lisun-add-customer-btn').on('click', function () {
        $('#lisun-edit-username').val('');
        $('#lisun-customer-serial').val('');
        $('#lisun-serial-row').hide();
        $('#lisun-customer-type').val('');
        selectedModels = []; renderModelTags(); $('#lisun-model-search').val('');
        $('#lisun-customer-doctype').val('');
        $('#lisun-customer-docnumber').val('');
        $('#lisun-customer-password').val('');
        $('#lisun-customer-note').val('');
        $('.lisun-pre-sale-required').hide();
        $('#lisun-customer-form h3').text('添加客户');
        $('#lisun-save-customer-btn').text('创建账号');
        $('#lisun-customer-form').slideDown();
    });

    $('#lisun-cancel-customer-btn').on('click', function () {
        $('#lisun-customer-form').slideUp();
    });

    // ====== 随机生成密码 ======
    var LISUN_PWD_PREFIX = '<?php echo get_option("lisun_page_lang", "zh") === "en" ? "EN" : "CN"; ?>';
    $('#lisun-gen-password').on('click', function () {
        var chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        var pwd = '';
        var len = 12 - LISUN_PWD_PREFIX.length;
        for (var i = 0; i < len; i++) pwd += chars[Math.floor(Math.random() * chars.length)];
        $('#lisun-customer-password').val(LISUN_PWD_PREFIX + pwd);
    });

    // ====== 创建/编辑账号 ======
    $('#lisun-save-customer-btn').on('click', function () {
        var editUser = $('#lisun-edit-username').val();
        var type = $('#lisun-customer-type').val();
        var models = getModelValues();
        var docType = $('#lisun-customer-doctype').val();
        var docNumber = $('#lisun-customer-docnumber').val().trim();
        var password = $('#lisun-customer-password').val().trim();
        var note = $('#lisun-customer-note').val().trim();

        if (!type) { alert('请选择类型。'); return; }
        if (!docType) { alert('请选择单据类型。'); return; }
        if (!docNumber) { alert('请输入单据号。'); return; }
        var docError = validateDocNumber(docNumber, docType);
        if (docError) { alert(docError); return; }
        if (type === 'pre_sale' && models.length === 0) {
            alert('售前客户至少选择一个产品型号。');
            return;
        }

        var action = editUser ? 'lisun_save_customer' : 'lisun_add_customer';
        var params = {
            action: action,
            type: type,
            product_models: models,
            doc_type: docType,
            doc_number: docNumber,
            password: password,
            note: note,
            _nonce: nonce
        };

        if (editUser) {
            params.old_username = editUser;
        }

        $.post(ajaxurl, params, function (res) {
            if (res.success) {
                if (res.data.password) {
                    // 新创建时弹窗提示密码（一次性展示），然后刷新
                    var pwd = res.data.password;
                    alert('账号 "' + res.data.username + '" 创建成功！\n\n密码：' + pwd + '\n\n请妥善保存，密码也会显示在列表中。');
                }
                location.reload();
            } else {
                alert(res.data.message || '操作失败。');
            }
        });
    });

    // ====== 编辑客户 ======
    $('.lisun-edit-customer').on('click', function () {
        var username = $(this).data('username');
        var row = $(this).closest('tr');
        var type = row.data('type');
        var models = parseModelValues(row.attr('data-models'));

        $('#lisun-edit-username').val(username);
        $('#lisun-serial-row').show();
        $('#lisun-customer-serial').val(row.find('td:eq(0)').text());
        $('#lisun-customer-type').val(type);
        setSelectedModels(models);
        $('#lisun-customer-doctype').val(row.attr('data-doctype') || '').trigger('change');
        $('#lisun-customer-docnumber').val(username);
        $('#lisun-customer-password').val('');
        $('#lisun-customer-note').val(row.attr('data-note') || '');
        $('.lisun-pre-sale-required').toggle(type === 'pre_sale');
        $('#lisun-customer-form h3').text('编辑客户');
        $('#lisun-save-customer-btn').text('保存修改');
        $('#lisun-customer-form').slideDown();
    });

    // ====== 重置密码 ======
    $('.lisun-reset-password').on('click', function () {
        var username = $(this).data('username');
        var $btn = $(this);
        if (!confirm('确定重置 "' + username + '" 的密码？旧密码将立即失效。')) return;

        $btn.prop('disabled', true).text('重置中...');

        $.post(ajaxurl, {
            action: 'lisun_reset_customer_password',
            username: username,
            _nonce: nonce
        }, function (res) {
            $btn.prop('disabled', false).text('重置密码');
            if (res.success) {
                // 直接更新表格中的密码单元格
                var $row = $('tr[data-username="' + username + '"]');
                var $pwdCell = $row.find('.lisun-password-text');
                $pwdCell.text(res.data.password)
                       .css('background', '#fff3cd')
                       .attr('title', '点击复制密码（新）');
                setTimeout(function () { $pwdCell.css('background', ''); }, 2000);
                // 复制到剪贴板
                copyToClipboard(res.data.password);
            } else {
                alert(res.data.message || '操作失败。');
            }
        });
    });

    // ====== 删除客户 ======
    $('.lisun-delete-customer').on('click', function () {
        var username = $(this).data('username');
        if (!confirm('确定删除客户 "' + username + '"？')) return;

        $.post(ajaxurl, {
            action: 'lisun_delete_customer',
            username: username,
            _nonce: nonce
        }, function () {
            location.reload();
        });
    });

    // ====== CSV 导入 ======
    $('#lisun-customer-csv-input').on('change', function () {
        var file = this.files[0];
        if (!file) return;

        var formData = new FormData();
        formData.append('action', 'lisun_import_customers_csv');
        formData.append('csv_file', file);
        formData.append('_nonce', nonce);

        $.ajax({ url: ajaxurl, type: 'POST', data: formData, processData: false, contentType: false })
            .done(function (res) {
                if (res.success) {
                    alert('导入完成：' + res.data.imported + ' 个，跳过：' + res.data.skipped);
                    location.reload();
                } else {
                    alert(res.data.message || '导入失败。');
                }
            });
    });

    // ====== 复制链接 ======
    $('.lisun-copy-auth-url').on('click', function () {
        var btn = $(this);
        var url = btn.data('url') || '<?php echo esc_js(home_url('/manual/')); ?>';
        var orig = btn.text();
        if (navigator.clipboard) {
            navigator.clipboard.writeText(url).then(function () {
                btn.text('已复制!').css('color', '#00a32a');
                setTimeout(function () { btn.text(orig).css('color', '#2271b1'); }, 1500);
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = url;
            ta.style.position = 'fixed'; ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            btn.text('已复制!').css('color', '#00a32a');
            setTimeout(function () { btn.text(orig).css('color', '#2271b1'); }, 1500);
        }
    });

    // ====== 一次复制链接、账号、密码 ======
    $('.lisun-copy-all').on('click', function () {
        var btn = $(this);
        var url = btn.data('url') || '<?php echo esc_js(home_url('/manual/')); ?>';
        var username = btn.data('username') || '';
        var password = btn.attr('data-password') || '';
        if (!password) {
            alert('该账号没有可复制的明文密码，请先重置密码。');
            return;
        }
        var text = url + '\nUser: ' + username + '\nPassword: ' + password;
        var orig = btn.text();
        var copied = function () {
            btn.text('已复制!').css('color', '#00a32a');
            setTimeout(function () { btn.text(orig).css('color', '#2271b1'); }, 1500);
        };
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(copied);
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed'; ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            copied();
        }
    });

    // ====== 点击复制密码 / 用户名 ======
    $('#lisun-customer-table').on('click', '.lisun-password-text, .lisun-copy-text', function () {
        var text = $(this).text().trim();
        if (!text || text.indexOf('••••••') === 0) return;
        var $el = $(this);
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text).then(function () {
                var origTitle = $el.attr('title');
                $el.attr('title', '已复制!').css('background', '#d4edda');
                setTimeout(function () { $el.attr('title', origTitle).css('background', ''); }, 1500);
            });
        } else {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed'; ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            var origTitle = $el.attr('title');
            $el.attr('title', '已复制!').css('background', '#d4edda');
            setTimeout(function () { $el.attr('title', origTitle).css('background', ''); }, 1500);
        }
    });

    // ====== 筛选 ======
    function filterTable() {
        var type = $('#lisun-filter-type').val();
        var search = $('#lisun-filter-search').val().toLowerCase();

        $('#lisun-customer-table tbody tr').each(function () {
            var $row = $(this);
            var rowType = $row.data('type');
            var username = $row.data('username').toLowerCase();
            var models = parseModelValues($row.attr('data-models')).join(' ').toLowerCase();

            var typeMatch = !type || rowType === type;
            var searchMatch = !search || username.indexOf(search) !== -1 || models.indexOf(search) !== -1;

            $row.toggle(typeMatch && searchMatch);
        });
    }

    $('#lisun-filter-btn').on('click', filterTable);
    $('#lisun-filter-reset').on('click', function () {
        $('#lisun-filter-type').val('');
        $('#lisun-filter-search').val('');
        filterTable();
    });
    $('#lisun-filter-search').on('keyup', function (e) {
        if (e.key === 'Enter') filterTable();
    });
    $('#lisun-filter-type').on('change', filterTable);

    // ====== 日志筛选 ======
    function filterLogTable() {
        var userQ  = $('#lisun-log-filter-user').val();
        var modelQ = $('#lisun-log-filter-model').val();
        var pageQ  = $('#lisun-log-filter-page').val();

        var visible = 0;
        var total = 0;
        $('#lisun-log-table tbody tr').each(function () {
            var $row  = $(this);
            var user  = ($row.data('log-user') || '').toString();
            var model = ($row.data('log-model') || '').toString();
            var page  = ($row.data('log-page') || '').toString();

            var match = true;
            if (userQ  && user !== userQ)   match = false;
            if (modelQ && model !== modelQ) match = false;
            if (pageQ  && page !== pageQ)   match = false;

            $row.toggle(match);
            if (match) visible++;
            total++;
        });

        var msg = '共 ' + total + ' 条';
        if (userQ || modelQ || pageQ) {
            msg += '，匹配 ' + visible + ' 条';
        }
        $('#lisun-log-filter-count').text(msg);
    }

    $('#lisun-log-filter-user, #lisun-log-filter-model, #lisun-log-filter-page').on('change', function () {
        filterLogTable();
    });
    $('#lisun-log-filter-reset').on('click', function () {
        $('#lisun-log-filter-user, #lisun-log-filter-model, #lisun-log-filter-page').val('');
        filterLogTable();
    });

    // 初始计数
    filterLogTable();
});
</script>
<style>
.lisun-type-badge { display:inline-block; padding:2px 8px; border-radius:3px; font-size:11px; font-weight:600; }
.lisun-type-pre { background:#e6f7ff; color:#1677ff; border:1px solid #91caff; }
.lisun-type-after { background:#f6ffed; color:#52c41a; border:1px solid #b7eb8f; }
.lisun-copy-text { cursor:pointer; border-bottom:1px dashed #999; }
.lisun-copy-text:hover { color:#1677ff; border-color:#1677ff; }
.lisun-password-text { cursor:pointer; font-family:monospace; font-size:13px; padding:2px 6px; border-radius:3px; border-bottom:1px dashed #999; transition:color 0.15s,background 0.15s; }
.lisun-password-text:hover { color:#1677ff; border-color:#1677ff; background:#f0f6ff; }
.lisun-filter-bar { background:#fff; border:1px solid #ddd; padding:10px; border-radius:4px; }
.lisun-model-search { position:relative; }
.lisun-model-search input { border:1px solid #8c8f94; border-radius:4px; padding:4px 8px; }
.lisun-model-search input:focus { border-color:#2271b1; box-shadow:0 0 0 1px #2271b1; outline:none; }
.lisun-model-dropdown {
    position:absolute; top:100%; left:0; right:0; z-index:9999;
    background:#fff; border:1px solid #c3c4c7; border-radius:0 0 4px 4px;
    max-height:200px; overflow-y:auto; box-shadow:0 4px 8px rgba(0,0,0,.08);
}
.lisun-model-option { padding:6px 10px; cursor:pointer; font-size:13px; border-bottom:1px solid #f0f0f0; }
.lisun-model-option:hover { background:#f0f6ff; color:#2271b1; }
.lisun-model-noresult { padding:8px 10px; color:#999; font-size:13px; }
.lisun-model-tags { margin-top:6px; display:flex; flex-wrap:wrap; gap:4px; }
.lisun-model-chip { display:inline-flex; align-items:center; gap:4px; background:#e6f4ff; color:#1677ff; border:1px solid #91caff; border-radius:3px; padding:2px 8px; font-size:12px; }
.lisun-model-remove { background:none; border:none; cursor:pointer; font-size:15px; line-height:1; color:#1677ff; padding:0; margin-left:2px; }
.lisun-model-remove:hover { color:#cf1322; }
.lisun-model-chip-inline { display:inline-block; background:#e6f4ff; color:#1677ff; border:1px solid #91caff; border-radius:3px; padding:0 5px; font-size:11px; margin:1px 0; }
</style>
