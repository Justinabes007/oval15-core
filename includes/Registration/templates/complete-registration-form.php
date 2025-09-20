<?php
// This file is included from Endpoint::shortcode(), with the following in scope:
// $u (WP_User), $data (prefill array), $countries (array), $positions (array), $order (WC_Order|null)
if (!defined('ABSPATH')) exit;
?>
<div id="oval15-loading"><div class="spinner">Submitting… please wait</div></div>

<?php if ($order instanceof \WC_Order): ?>
    <div class="oval15-order-context" style="margin:15px 0;padding:12px 14px;border:1px solid #ddd;border-radius:6px;background:#fafafa">
        <strong>Order #<?php echo esc_html($order->get_order_number()); ?></strong>
        — <?php echo esc_html(wc_format_datetime($order->get_date_created())); ?> —
        Total: <?php echo wp_kses_post($order->get_formatted_order_total()); ?>
    </div>
<?php endif; ?>

<form class="oval15-complete-registration" method="post" enctype="multipart/form-data" style="margin-bottom:20px;">
    <?php wp_nonce_field(\Oval15\Core\Registration\Endpoint::NONCE, \Oval15\Core\Registration\Endpoint::NONCE); ?>
    <input type="hidden" name="action" value="<?php echo esc_attr(\Oval15\Core\Registration\Endpoint::ACTION_POST); ?>">
    <?php if ($order instanceof \WC_Order): ?>
        <input type="hidden" name="order_id"  value="<?php echo esc_attr($order->get_id()); ?>">
        <input type="hidden" name="order_key" value="<?php echo esc_attr($order->get_order_key()); ?>">
    <?php endif; ?>

    <h3>Personal</h3>
    <div class="form-group">
        <p><label for="f_name">First Name <span class="required">*</span></label></p>
        <p><input type="text" class="form-control" id="f_name" name="f_name" required value="<?php echo esc_attr($data['f_name']); ?>"></p>
    </div>
    <div class="form-group">
        <p><label for="l_name">Last Name <span class="required">*</span></label></p>
        <p><input type="text" class="form-control" id="l_name" name="l_name" required value="<?php echo esc_attr($data['l_name']); ?>"></p>
    </div>
    <div class="form-group">
        <p><label for="email">Email <span class="required">*</span></label></p>
        <p><input type="email" class="form-control" id="email" name="email" required readonly value="<?php echo esc_attr($u->user_email); ?>"></p>
    </div>
    <div class="form-group">
        <p><label for="gender">Gender <span class="required">*</span></label></p>
        <p>
            <select class="input-text" name="gender" id="gender" required>
                <option value="Male"   <?php selected($data['gender'],'Male'); ?>>Male</option>
                <option value="Female" <?php selected($data['gender'],'Female'); ?>>Female</option>
            </select>
        </p>
    </div>
    <div class="form-group">
        <p><label for="dob">Date Of Birth</label></p>
        <p><input type="date" class="input-text" name="dob" id="dob" value="<?php echo esc_attr($data['dob']); ?>"></p>
    </div>

    <p class="form-row form-row-last">
        <label for="main-position">Main Position</label>
    </p>
    <p>
        <select class="input-text" name="main-position" id="main-position">
            <option><?php echo esc_html('<-- Select Main Position -->'); ?></option>
            <?php foreach ($positions as $p): ?>
                <option value="<?php echo esc_attr($p); ?>" <?php selected($data['main-position'], $p); ?>>
                    <?php echo esc_html($p); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <p class="form-row form-row-last">
        <label for="secondary-position">Secondary Position(s)</label>
    </p>
    <p>
        <select class="input-text select2_box" name="secondary-position[]" id="secondary-position" multiple>
            <?php foreach ($positions as $p): ?>
                <option value="<?php echo esc_attr($p); ?>" <?php selected(in_array($p,(array)$data['secondary-position'],true)); ?>>
                    <?php echo esc_html($p); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </p>

    <div class="form-group">
        <p><label for="lop">Level of player <span class="required">*</span></label></p>
        <p>
            <select class="input-text" name="lop" id="lop" required>
                <option value="Amateur"      <?php selected($data['lop'],'Amateur'); ?>>Amateur</option>
                <option value="Semi-Pro"     <?php selected($data['lop'],'Semi-Pro'); ?>>Semi-Pro</option>
                <option value="Professional" <?php selected($data['lop'],'Professional'); ?>>Professional</option>
            </select>
        </p>
    </div>

    <div class="form-group">
        <p><label for="c_number">Contact number <span class="required">*</span></label></p>
        <p>
            <input type="tel" class="form-control" id="c_number" name="c_number" required
                   value="<?php echo esc_attr($data['c_number']); ?>" autocomplete="off">
        </p>
        <input type="hidden" value="<?php echo esc_attr($data['country_code']); ?>" name="country_code" id="country-code">
    </div>

    <div class="form-group">
        <p><label for="nation">Nationality <span class="required">*</span></label></p>
        <p>
            <select class="input-text select2_box" name="nation" id="nation" required>
                <?php foreach ($countries as $c): ?>
                    <option value="<?php echo esc_attr($c); ?>" <?php selected($data['nation'], $c); ?>>
                        <?php echo esc_html($c); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
    </div>

    <div class="form-group">
        <p><label for="passport">Passport <span class="required">*</span></label></p>
        <p>
            <select class="input-text select2_box" name="passport[]" id="passport" multiple required>
                <?php foreach ($countries as $c): ?>
                    <option value="<?php echo esc_attr($c); ?>" <?php selected(in_array($c,(array)$data['passport'],true)); ?>>
                        <?php echo esc_html($c); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
    </div>

    <div class="form-group">
        <p><label for="current_location_country">Current Location (Country) <span class="required">*</span></label></p>
        <p>
            <select class="input-text select2_box" name="current_location_country" id="current_location_country" required>
                <?php foreach ($countries as $c): ?>
                    <option value="<?php echo esc_attr($c); ?>" <?php selected($data['current_location_country'], $c); ?>>
                        <?php echo esc_html($c); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </p>
    </div>

    <div class="form-group">
        <p><label for="weight">Weight (In KG) <span class="required">*</span></label></p>
        <p><input type="number" class="form-control" id="weight" name="weight" required value="<?php echo esc_attr($data['weight']); ?>"></p>
    </div>

    <div class="form-group">
        <p><label for="height">Height (In CM) <span class="required">*</span></label></p>
        <p><input type="number" class="form-control" id="height" name="height" min="150" max="1000" step="1" required value="<?php echo esc_attr($data['height']); ?>"></p>
    </div>

    <div class="form-group" style="display:flex;gap:10px;margin:0;">
        <div>
            <p><label for="available_from_year">Available From (Year)</label></p>
            <p><input type="number" class="form-control" id="available_from_year" name="available_from_year" value="<?php echo esc_attr($data['available_from_year']); ?>" placeholder="<?php echo esc_attr(date('Y')); ?>"></p>
        </div>
        <div>
            <p><label for="available_from_month">Available From (Month)</label></p>
            <p>
                <select class="input-text" id="available_from_month" name="available_from_month">
                    <option value="">--</option>
                    <?php for($m=1;$m<=12;$m++): ?>
                        <option value="<?php echo $m; ?>" <?php selected((int)$data['available_from_month'], $m); ?>>
                            <?php echo esc_html(date('F', mktime(0,0,0,$m,1))); ?>
                        </option>
                    <?php endfor; ?>
                </select>
            </p>
        </div>
    </div>

    <h3>Current playing history</h3>
    <?php for ($i=1; $i<=3; $i++): ?>
        <div class="form-group" style="display:flex;gap:10px;margin:0;">
            <div>
                <p><label for="club_<?php echo $i; ?>">Club Name<?php echo $i===1?' <span class="required">*</span>':''; ?></label></p>
                <p><input type="text" class="form-control" id="club_<?php echo $i; ?>" name="club_<?php echo $i; ?>" <?php echo $i===1?'required':''; ?> value="<?php echo esc_attr($data["club_{$i}"]); ?>"></p>
            </div>
            <div>
                <p><label for="tournament_<?php echo $i; ?>">League<?php echo $i===1?' <span class="required">*</span>':''; ?></label></p>
                <p><input type="text" class="form-control" id="tournament_<?php echo $i; ?>" name="tournament_<?php echo $i; ?>" <?php echo $i===1?'required':''; ?> value="<?php echo esc_attr($data["tournament_{$i}"]); ?>"></p>
            </div>
            <div>
                <p><label for="period_<?php echo $i; ?>">Period<?php echo $i===1?' <span class="required">*</span>':''; ?></label></p>
                <p><input type="text" class="form-control" id="period_<?php echo $i; ?>" name="period_<?php echo $i; ?>" <?php echo $i===1?'required':''; ?> value="<?php echo esc_attr($data["period_{$i}"]); ?>"></p>
            </div>
        </div>
    <?php endfor; ?>

    <h3 class="mb-0 mt-4">Player Profile</h3>
    <p><small>Tell us about your strengths as a player, notable achievements, any representative teams, etc.</small><br>
        <a class="custom__link" target="_blank" href="/news/how-to-sell-yourself-in-your-player-profile/">How To Sell Yourself In Your Player Profile</a>
    </p>
    <div class="form-group">
        <p><label for="profile">Profile <span class="required">*</span></label></p>
        <p><textarea name="p_profile" class="p-profile-count" id="profile" cols="50" rows="10"><?php echo esc_textarea($data['p_profile']); ?></textarea></p>
        <div id="word-count">Word Count: <!-- JS can fill this --></div>
    </div>

    <h3>Media</h3>
    <div class="form-group">
        <p><label for="upload_photo">Upload Photo (Square Shaped)</label></p>
        <p><input type="file" class="input-text" name="upload_photo" id="upload_photo" accept="image/png, image/jpeg, image/webp"></p>
        <?php
        $thumb_id = (int) get_user_meta($u->ID, 'profile_photo_id', true);
        if ($thumb_id) {
            $thumb_url = wp_get_attachment_image_url($thumb_id, 'thumbnail');
            if ($thumb_url) {
                echo '<div id="user-thumnail"><div class="show-if-value image-wrap" style="max-width:240px;margin-bottom:1rem;">';
                echo '<img src="'.esc_url($thumb_url).'" alt="" style="max-height:100%;">';
                echo '</div></div>';
            }
        }
        ?>
    </div>

    <div class="form-group">
        <p><label for="v_upload_id">Highlight Video (upload)</label></p>
        <p><input type="file" class="input-text" name="v_upload_id" id="v_upload_id" accept="video/mp4,video/quicktime,video/webm"></p>
    </div>

    <div class="form-group">
        <p><label for="yt_video">Or Video Link (YouTube or Vimeo)</label></p>
        <p><input type="url" class="form-control" id="yt_video" name="yt_video" value="<?php echo esc_attr($data['yt_video']); ?>"></p>
    </div>

    <p><button type="submit" class="button button-primary">Complete Registration</button></p>
</form>