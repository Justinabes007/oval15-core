<?php
namespace Oval15\Core\Media;

if (!defined('ABSPATH')) exit;

class Video {

    /** Default allowed hosts; can be overridden via settings. */
    public static function default_hosts() {
        return ['youtube.com','youtu.be','vimeo.com'];
    }

    /** Is URL allowed by host whitelist? */
    public static function host_allowed($url, $allowed_hosts) {
        $p = wp_parse_url($url);
        if (empty($p['host'])) return false;
        $host = strtolower($p['host']);
        foreach ($allowed_hosts as $allowed) {
            $allowed = ltrim(strtolower($allowed), '.');
            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return true;
            }
        }
        return false;
    }

    /** Extract provider + id if we can (YouTube/Vimeo), else null. */
    public static function detect($url) {
        $u = trim((string)$url);
        if ($u === '' || !filter_var($u, FILTER_VALIDATE_URL)) return null;
        $h = strtolower(parse_url($u, PHP_URL_HOST) ?: '');

        // YouTube
        if ($h === 'youtu.be') {
            $id = trim(parse_url($u, PHP_URL_PATH), '/');
            if ($id) return ['provider'=>'youtube','id'=>$id,'url'=>$u];
        }
        if (str_contains($h, 'youtube.com')) {
            parse_str(parse_url($u, PHP_URL_QUERY) ?? '', $q);
            if (!empty($q['v'])) return ['provider'=>'youtube','id'=>$q['v'],'url'=>$u];
            // share links like /shorts/<id>
            $path = trim(parse_url($u, PHP_URL_PATH) ?? '', '/');
            if (preg_match('~^shorts/([A-Za-z0-9_-]{6,})$~', $path, $m)) {
                return ['provider'=>'youtube','id'=>$m[1],'url'=>$u];
            }
        }

        // Vimeo
        if (str_contains($h, 'vimeo.com')) {
            $path = trim(parse_url($u, PHP_URL_PATH) ?? '', '/');
            if (preg_match('~^(\d{6,})~', $path, $m)) {
                return ['provider'=>'vimeo','id'=>$m[1],'url'=>$u];
            }
        }

        return ['provider'=>'oembed','id'=>null,'url'=>$u];
    }

    /** Generate lightweight, click-to-play embed HTML. */
    public static function lite_embed($url) {
        $info = self::detect($url);
        if (!$info) return '';

        if ($info['provider']==='youtube' && $info['id']) {
            $thumb = 'https://i.ytimg.com/vi/'.esc_attr($info['id']).'/hqdefault.jpg';
            $iframe = 'https://www.youtube.com/embed/'.rawurlencode($info['id']).'?rel=0&modestbranding=1';
            return self::lite_shell($thumb, $iframe);
        }

        if ($info['provider']==='vimeo' && $info['id']) {
            // No free thumbnail without API; fall back to a neutral placeholder
            $thumb = 'data:image/svg+xml;charset=UTF-8,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="1280" height="720"><rect width="100%" height="100%" fill="#111827"/><text x="50%" y="50%" fill="#9CA3AF" font-size="42" text-anchor="middle" dominant-baseline="middle">Vimeo Video</text></svg>');
            $iframe = 'https://player.vimeo.com/video/'.rawurlencode($info['id']);
            return self::lite_shell($thumb, $iframe);
        }

        // Fallback to WP oEmbed (lazy)
        $html = function_exists('wp_oembed_get') ? wp_oembed_get($url) : '';
        if ($html) return '<div class="oval15-embed oval15-oembed">'.$html.'</div>';
        return '<a class="oval15-video-link" href="'.esc_url($url).'" target="_blank" rel="noopener">View video</a>';
    }

    private static function lite_shell($thumb_url, $iframe_url) {
        $id = 'v'.wp_generate_password(6, false, false);
        ob_start(); ?>
        <div class="oval15-lite-embed" id="<?php echo esc_attr($id); ?>" data-src="<?php echo esc_url($iframe_url); ?>" style="position:relative;cursor:pointer;background:#000;aspect-ratio:16/9;border-radius:8px;overflow:hidden">
            <img src="<?php echo esc_url($thumb_url); ?>" alt="" style="width:100%;height:100%;object-fit:cover;filter:brightness(0.85)">
            <button type="button" aria-label="Play" style="position:absolute;inset:0;margin:auto;width:64px;height:64px;border-radius:50%;border:0;background:rgba(255,255,255,0.85);display:flex;align-items:center;justify-content:center">
                <span style="display:block;width:0;height:0;border-left:18px solid #111827;border-top:12px solid transparent;border-bottom:12px solid transparent;margin-left:6px"></span>
            </button>
        </div>
        <script>
        (function(){
          var el=document.getElementById(<?php echo json_encode($id); ?>);
          if(!el) return;
          el.addEventListener('click', function(){
            if(el.dataset.loaded) return;
            var s=el.dataset.src;
            var ifr=document.createElement('iframe');
            ifr.setAttribute('src', s);
            ifr.setAttribute('allow','accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share');
            ifr.setAttribute('allowfullscreen','');
            ifr.style.width='100%'; ifr.style.height='100%'; ifr.style.border='0';
            el.innerHTML=''; el.appendChild(ifr); el.dataset.loaded='1';
          });
        }());
        </script>
        <?php
        return ob_get_clean();
    }

    /** Handle a direct video upload with size/mime checks. Returns attachment ID or WP_Error. */
    public static function handle_upload($file_array, $max_mb = 100) {
        if (empty($file_array['name'])) return null;

        $size_ok = !empty($file_array['size']) && ($file_array['size'] <= ($max_mb * 1024 * 1024));
        if (!$size_ok) return new \WP_Error('oval15_video_size', 'Video exceeds the maximum size of '.$max_mb.'MB.');

        // Whitelist common video mimes
        $mimes = ['mp4'=>'video/mp4','webm'=>'video/webm','mov'=>'video/quicktime','qt'=>'video/quicktime'];
        require_once ABSPATH . 'wp-admin/includes/file.php';

        $overrides = ['test_form'=>false,'mimes'=>$mimes];
        $file = wp_handle_upload($file_array, $overrides);
        if (isset($file['error'])) return new \WP_Error('oval15_video_upload', 'Upload failed: '.$file['error']);

        // Create attachment
        $attachment = [
            'post_mime_type' => $file['type'],
            'post_title'     => sanitize_file_name(basename($file['file'])),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];
        $attach_id = wp_insert_attachment($attachment, $file['file']);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata($attach_id, $file['file']);
        wp_update_attachment_metadata($attach_id, $metadata);

        return $attach_id;
    }

    /** Render a <video> tag for an uploaded attachment ID. */
    public static function render_uploaded($attachment_id) {
        $src = wp_get_attachment_url((int)$attachment_id);
        if (!$src) return '';
        $type = get_post_mime_type((int)$attachment_id) ?: 'video/mp4';
        $attrs = 'controls playsinline preload="metadata" style="width:100%;max-width:800px;border-radius:8px;border:1px solid #e5e7eb"';
        return sprintf('<video %s><source src="%s" type="%s"></video>', $attrs, esc_url($src), esc_attr($type));
    }
}