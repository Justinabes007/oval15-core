<?php
namespace Oval15\Core\Media;

if (!defined('ABSPATH')) exit;

class Video {

    /**
     * Check if a video URL's host is allowed.
     */
    public static function host_allowed(string $url, array $allowed_hosts): bool {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        if ($host === '') return false;
        foreach ($allowed_hosts as $h) {
            $h = strtolower(trim($h));
            if (!$h) continue;
            // exact host or subdomain match
            if ($host === $h || str_ends_with($host, '.'.$h)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Handle a direct video upload and return attachment ID (or WP_Error).
     */
    public static function handle_upload(array $file, int $max_mb = 100) {
        if (empty($file['name']) || empty($file['tmp_name'])) {
            return new \WP_Error('oval15_video_empty', 'No video file provided.');
        }

        // Size check (in bytes)
        $size = isset($file['size']) ? (int) $file['size'] : @filesize($file['tmp_name']);
        if ($size && $size > ($max_mb * 1024 * 1024)) {
            return new \WP_Error('oval15_video_size', 'Video exceeds the maximum allowed size of '.$max_mb.'MB.');
        }

        // Allow common video mimes
        $mimes = [
            'mp4'  => 'video/mp4',
            'mov'  => 'video/quicktime',
            'qt'   => 'video/quicktime',
            'webm' => 'video/webm',
            'm4v'  => 'video/x-m4v',
        ];

        // These helpers are required on the front-end
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Upload (front-end, so disable form test)
        $overrides = ['test_form' => false, 'mimes' => $mimes];
        $moved = wp_handle_upload($file, $overrides);

        if (isset($moved['error']) && $moved['error']) {
            return new \WP_Error('oval15_video_upload', 'Upload failed: ' . $moved['error']);
        }

        $file_path = $moved['file'];
        $file_url  = $moved['url'];
        $check     = wp_check_filetype_and_ext($file_path, basename($file_path), $mimes);

        $attachment = [
            'post_mime_type' => $check['type'] ?: ($moved['type'] ?? 'video/mp4'),
            'post_title'     => sanitize_file_name(pathinfo($file_path, PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];

        $attach_id = wp_insert_attachment($attachment, $file_path);
        if (is_wp_error($attach_id)) {
            return $attach_id;
        }

        // Generate metadata if available (don’t hard-fail if host restrictions block it)
        if (function_exists('wp_generate_attachment_metadata')) {
            // If for any reason the media helpers weren’t loaded by host, avoid fatal:
            if (function_exists('wp_read_video_metadata')) {
                $meta = @wp_generate_attachment_metadata($attach_id, $file_path);
                if (!empty($meta)) {
                    wp_update_attachment_metadata($attach_id, $meta);
                }
            } else {
                // Skip metadata quietly; the attachment still works.
            }
        }

        return (int) $attach_id;
    }
}
