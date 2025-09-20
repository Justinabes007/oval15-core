<?php
/**
 * Template: Complete Registration Form
 * Variables available: 
 *   $user (WP_User), $vals (prefill array), $order (WC_Order|null)
 */
?>

<?php if ($order): ?>
<div class="woocommerce-order-overview order_details" style="margin-bottom:20px;padding:15px;border:1px solid #eee;border-radius:8px;background:#fafafa">
    <h3>Order Information</h3>
    <p><strong>Order #<?php echo esc_html($order->get_id()); ?></strong> — <?php echo wp_kses_post($order->get_formatted_order_total()); ?></p>
    <p><strong>Date:</strong> <?php echo esc_html($order->get_date_created()->date_i18n(get_option('date_format'))); ?></p>
    <p><strong>Email:</strong> <?php echo esc_html($order->get_billing_email()); ?></p>
</div>
<?php endif; ?>

<div id="oval15-loading"><div class="spinner">Submitting… please wait</div></div>

<form class="oval15-complete-registration" method="post" enctype="multipart/form-data">
    <?php wp_nonce_field(\Oval15\Core\Registration\Endpoint::NONCE, \Oval15\Core\Registration\Endpoint::NONCE); ?>
    <input type="hidden" name="action" value="<?php echo esc_attr(\Oval15\Core\Registration\Endpoint::ACTION_POST); ?>">
    <?php if ($order): ?>
        <input type="hidden" name="order_id" value="<?php echo esc_attr($order->get_id()); ?>">
        <input type="hidden" name="order_key" value="<?php echo esc_attr($order->get_order_key()); ?>">
    <?php endif; ?>

    <h3>Personal</h3>
    <p><label>First Name *</label><input type="text" name="f_name" value="<?php echo esc_attr($vals['f_name']); ?>" required></p>
    <p><label>Last Name *</label><input type="text" name="l_name" value="<?php echo esc_attr($vals['l_name']); ?>" required></p>
    <p><label>Email *</label><input type="email" name="email" value="<?php echo esc_attr($user->user_email); ?>" readonly></p>
    <p><label>Gender *</label>
        <select name="gender" required>
            <option value="">Select…</option>
            <option value="Male" <?php selected($vals['gender'],'Male'); ?>>Male</option>
            <option value="Female" <?php selected($vals['gender'],'Female'); ?>>Female</option>
        </select>
    </p>
    <p><label>Date of Birth</label><input type="date" name="dob" value="<?php echo esc_attr($vals['dob']); ?>"></p>
    <p><label>Contact Number *</label><input type="tel" name="c_number" value="<?php echo esc_attr($vals['c_number'] ?? ''); ?>" required></p>
    <input type="hidden" name="country_code" id="country-code" value="<?php echo esc_attr($vals['country_code'] ?? ''); ?>">

    <h3>Nationality & Passport</h3>
    <p><label>Nationality *</label><input type="text" name="nation" value="<?php echo esc_attr($vals['nation']); ?>" required></p>
    <p><label>Passport(s) *</label>
        <select name="passport[]" multiple class="select2_box" required>
            <?php foreach (\Oval15\Core\Registration\Endpoint::countries() as $c): ?>
                <option value="<?php echo esc_attr($c); ?>" <?php selected(in_array($c, (array)$vals['passport'], true)); ?>>
                    <?php echo esc_html($c); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>
    <p><label>Current Location (Country) *</label>
        <select name="current_location_country" class="select2_box" required>
            <option value="">Select…</option>
            <?php foreach (\Oval15\Core\Registration\Endpoint::countries() as $c): ?>
                <option value="<?php echo esc_attr($c); ?>" <?php selected($vals['current_location_country'], $c); ?>>
                    <?php echo esc_html($c); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <h3>Physical</h3>
    <p><label>Height (cm) *</label><input type="number" name="height" value="<?php echo esc_attr($vals['height']); ?>" required></p>
    <p><label>Weight (kg) *</label><input type="number" name="weight" value="<?php echo esc_attr($vals['weight']); ?>" required></p>

    <h3>Availability</h3>
    <p><label>Available From (Year)</label><input type="number" name="available_from_year" value="<?php echo esc_attr($vals['available_from_year'] ?? ''); ?>" placeholder="<?php echo esc_attr(date('Y')); ?>"></p>
    <p><label>Available From (Month)</label>
        <select name="available_from_month">
            <option value="">--</option>
            <?php foreach (range(1,12) as $m): ?>
                <option value="<?php echo $m; ?>" <?php selected(intval($vals['available_from_month'] ?? 0), $m); ?>>
                    <?php echo esc_html(date('F', mktime(0,0,0,$m,1))); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <h3>Playing History</h3>
    <?php for ($i=1;$i<=3;$i++): ?>
        <fieldset style="margin-bottom:15px">
            <legend>Club <?php echo $i; ?></legend>
            <p><label>Club Name</label><input type="text" name="club_<?php echo $i; ?>" value="<?php echo esc_attr($vals["club_{$i}"]); ?>"></p>
            <p><label>League</label><input type="text" name="tournament_<?php echo $i; ?>" value="<?php echo esc_attr($vals["tournament_{$i}"] ?? ''); ?>"></p>
            <p><label>Period</label><input type="text" name="period_<?php echo $i; ?>" value="<?php echo esc_attr($vals["period_{$i}"]); ?>"></p>
        </fieldset>
    <?php endfor; ?>

    <h3>Player Profile</h3>
    <p><small>Tell us about your strengths, achievements, and representative teams.</small></p>
    <p><textarea name="p_profile" rows="5"><?php echo esc_textarea($vals['p_profile']); ?></textarea></p>

    <h3>Media</h3>
    <p><label>Profile Photo *</label><input type="file" name="upload_photo" accept="image/*"></p>
    <p><label>Highlight Video (upload)</label><input type="file" name="v_upload_id" accept="video/*"></p>
    <p><label>Or Video Link (YouTube/Vimeo)</label><input type="url" name="yt_video" value="<?php echo esc_attr($vals['yt_video']); ?>"></p>

    <h3>Consents</h3>
    <p><label><input type="checkbox" name="oval15_sole_rep" value="1" <?php checked($vals['_oval15_sole_rep'] ?? '', 'yes'); ?>> I confirm Oval15 is my sole representative.</label></p>
    <p><label><input type="checkbox" name="marketing_consent" value="1" <?php checked($vals['_oval15_marketing_consent'] ?? '', 'yes'); ?>> I agree to receive marketing updates.</label></p>

    <p><button type="submit" class="button button-primary">Complete Registration</button></p>
</form>