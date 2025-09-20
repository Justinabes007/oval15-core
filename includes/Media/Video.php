<?php
namespace Oval15\Core\Media;

if (!defined('ABSPATH')) exit;

class Video {

    /**
     * Validate a video host against allowed list.
     */
    public static function host_allowed($url, array $allowed_hosts = []) {
        if (empty($allowed_hosts)) return true; // no restriction
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
        foreach ($allowed_hosts as $allowed) {
            $allowed = strtolower(trim($allowed));
            if ($allowed && str_contains($host, $allowed)) return true;
        }
        return false;
    }

    /**
     * Handle an uploaded video file into WP media library.
     *
     * @param array $file - standard $_FILES['field'] array
     * @param int   $max_mb - maximum size in MB
     * @return int|\WP_Error attachment ID on success
     */
    public static function handle_upload(array $file, int $max_mb = 100) {
        if (empty($file['name'])) {
            return new \WP_Error('oval15_no_file', 'No video file uploaded.');
        }

        // Ensure WP upload helpers available
        if (!function_exists('wp_handle_upload'))  require_once ABSPATH . 'wp-admin/includes/file.php';
        if (!function_exists('wp_insert_attachment')) require_once ABSPATH . 'wp-admin/includes/media.php';
        if (!function_exists('wp_generate_attachment_metadata')) require_once ABSPATH . 'wp-admin/includes/image.php';
        if (!function_exists('wp_read_video_metadata')) require_once ABSPATH . 'wp-admin/includes/media.php';

        // Size validation
        $size_mb = isset($file['size']) ? ($file['size'] / 1048576) : 0;
        if ($size_mb > $max_mb) {
            return new \WP_Error('oval15_file_too_large', sprintf('Video exceeds max size of %dMB.', $max_mb));
        }

        // Allowed MIME types
        $mimes = [
            'mp4'  => 'video/mp4',
            'mov'  => 'video/quicktime',
            'webm' => 'video/webm',
            'ogg'  => 'video/ogg',
        ];

        $overrides = ['test_form' => false, 'mimes' => $mimes];

        $movefile = wp_handle_upload($file, $overrides);
        if (isset($movefile['error'])) {
            return new \WP_Error('oval15_upload_error', $movefile['error']);
        }

        $filetype = wp_check_filetype($movefile['file'], $mimes);

        $attachment = [
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name(basename($movefile['file'])),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment, $movefile['file']);
        if (is_wp_error($attach_id)) {
            return $attach_id;
        }

        // Generate attachment metadata (works for video & image)
        $attach_data = wp_generate_attachment_metadata($attach_id, $movefile['file']);
        wp_update_attachment_metadata($attach_id, $attach_data);

        return $attach_id;
    }

    /**
     * Render a preview of an uploaded video attachment.
     *
     * @param int $attachment_id
     * @param string $size
     * @return string
     */
    public static function render_uploaded($attachment_id, $size = 'medium') {
        $url = wp_get_attachment_url($attachment_id);
        if (!$url) return '<em>No video available.</em>';

        $mime = get_post_mime_type($attachment_id);
        if (strpos($mime, 'video/') !== 0) {
            return '<a href="'.esc_url($url).'" target="_blank">Download video</a>';
        }

        return sprintf(
            '<video controls style="max-width:100%%;border-radius:8px"><source src="%s" type="%s">Your browser does not support video playback.</video>',
            esc_url($url),
            esc_attr($mime)
        );
    }
}