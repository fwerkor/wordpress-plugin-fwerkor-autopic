<?php
/**
 * Plugin Name: FWERKOR Auto Pic
 * Plugin URI: https://github.com/fwerkor/wordpress-plugin-fwerkor-autopic
 * Description: Automatic featured images for WordPress posts using existing content images or deterministic generated artwork.
 * Version: 1.0.1
 * Author: FWERKOR
 * License: GPL-2.0-or-later
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

final class FWERKOR_Auto_Pic {
    private const OPTION = 'fwerkor_autopic_options';
    private const META_ID = '_fwerkor_autopic_attachment_id';
    private const META_SIG = '_fwerkor_autopic_signature';

    public function __construct() {
        add_action('wp_after_insert_post', array($this, 'after_insert'), 40, 4);
        add_action('admin_menu', array($this, 'menu'));
        add_action('admin_post_fwerkor_autopic_generate_all', array($this, 'generate_all_action'));
    }

    public static function activate(): void {
        add_option(
            self::OPTION,
            array(
                'enabled' => 1,
                'prefer_content_image' => 1,
                'width' => 1440,
                'height' => 900,
            ),
            '',
            false
        );
    }

    public function after_insert(int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before): void {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        if ('post' !== $post->post_type || 'publish' !== $post->post_status) {
            return;
        }
        $o = $this->options();
        if (empty($o['enabled'])) {
            return;
        }

        $this->ensure_featured_image($post_id, $post);
    }

    public function ensure_featured_image(int $post_id, ?WP_Post $post = null): int {
        $post = $post ?: get_post($post_id);
        if (!$post instanceof WP_Post || 'post' !== $post->post_type) {
            return 0;
        }

        $current = (int) get_post_thumbnail_id($post_id);
        $managed = (int) get_post_meta($post_id, self::META_ID, true);

        if ($current > 0 && $current !== $managed) {
            return $current;
        }

        $o = $this->options();

        if (!$current && !empty($o['prefer_content_image'])) {
            $candidate = $this->content_attachment_id($post);
            if ($candidate > 0) {
                set_post_thumbnail($post_id, $candidate);
                return $candidate;
            }
        }

        $signature = hash(
            'sha256',
            $post->ID . '|' . $post->post_title . '|' . (int) $o['width'] . '|' . (int) $o['height']
        );

        if ($current > 0 && $current === $managed && hash_equals((string) get_post_meta($post_id, self::META_SIG, true), $signature)) {
            return $current;
        }

        $new_id = $this->generate($post, (int) $o['width'], (int) $o['height'], $signature);
        if ($new_id <= 0) {
            return $current;
        }

        set_post_thumbnail($post_id, $new_id);
        update_post_meta($post_id, self::META_ID, $new_id);
        update_post_meta($post_id, self::META_SIG, $signature);

        if ($managed > 0 && $managed !== $new_id && $managed === $current) {
            wp_delete_attachment($managed, true);
        }

        return $new_id;
    }

    private function content_attachment_id(WP_Post $post): int {
        if (preg_match_all('/wp-image-([0-9]+)/', $post->post_content, $matches)) {
            foreach ($matches[1] as $id) {
                $id = (int) $id;
                if ($id > 0 && str_starts_with((string) get_post_mime_type($id), 'image/')) {
                    return $id;
                }
            }
        }

        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $post->post_content, $matches)) {
            foreach ($matches[1] as $url) {
                $id = (int) attachment_url_to_postid($url);
                if ($id > 0) {
                    return $id;
                }
            }
        }

        $attached = get_children(array(
            'post_parent' => $post->ID,
            'post_type' => 'attachment',
            'post_mime_type' => 'image',
            'numberposts' => 1,
            'orderby' => 'menu_order ID',
            'order' => 'ASC',
        ));

        if (!empty($attached)) {
            return (int) array_key_first($attached);
        }

        return 0;
    }

    private function generate(WP_Post $post, int $width, int $height, string $signature): int {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagepng')) {
            return 0;
        }

        $width = max(640, min(2560, $width));
        $height = max(360, min(1600, $height));

        $img = imagecreatetruecolor($width, $height);
        if (!$img) {
            return 0;
        }
        imagealphablending($img, true);
        imagesavealpha($img, true);

        $palettes = array(
            array(array(11, 87, 208), array(105, 183, 255), array(8, 21, 47)),
            array(array(24, 128, 56), array(109, 213, 143), array(11, 39, 24)),
            array(array(111, 94, 211), array(183, 169, 255), array(30, 24, 66)),
            array(array(180, 95, 6), array(244, 181, 104), array(58, 30, 4)),
            array(array(27, 117, 187), array(79, 215, 194), array(5, 35, 54)),
            array(array(88, 86, 82), array(209, 206, 199), array(24, 24, 23)),
        );

        $bytes = array_values(unpack('C*', hex2bin(substr($signature, 0, 32))));
        $palette = $palettes[$bytes[0] % count($palettes)];
        [$a, $b, $dark] = $palette;

        for ($y = 0; $y < $height; $y++) {
            $t = $height > 1 ? $y / ($height - 1) : 0;
            $r = (int) round($a[0] * (1 - $t) + $dark[0] * $t);
            $g = (int) round($a[1] * (1 - $t) + $dark[1] * $t);
            $bb = (int) round($a[2] * (1 - $t) + $dark[2] * $t);
            imageline($img, 0, $y, $width, $y, imagecolorallocate($img, $r, $g, $bb));
        }

        $accent = imagecolorallocatealpha($img, $b[0], $b[1], $b[2], 48);
        $accent_soft = imagecolorallocatealpha($img, $b[0], $b[1], $b[2], 82);
        $line = imagecolorallocatealpha($img, 255, 255, 255, 100);

        $count = 8 + ($bytes[1] % 7);
        for ($i = 0; $i < $count; $i++) {
            $bx = $bytes[($i * 3 + 2) % count($bytes)];
            $by = $bytes[($i * 3 + 3) % count($bytes)];
            $bs = $bytes[($i * 3 + 4) % count($bytes)];
            $x = (int) (($bx / 255) * $width);
            $y = (int) (($by / 255) * $height);
            $size = (int) (($bs / 255) * min($width, $height) * 0.32) + 48;
            imagefilledellipse($img, $x, $y, $size, $size, 0 === $i % 2 ? $accent : $accent_soft);
        }

        $grid = max(48, (int) ($width / 18));
        for ($x = 0; $x <= $width; $x += $grid) {
            imageline($img, $x, 0, $x, $height, $line);
        }
        for ($y = 0; $y <= $height; $y += $grid) {
            imageline($img, 0, $y, $width, $y, $line);
        }

        $uploads = wp_upload_dir();
        if (!empty($uploads['error'])) {
            imagedestroy($img);
            return 0;
        }

        wp_mkdir_p($uploads['path']);
        $filename = wp_unique_filename(
            $uploads['path'],
            'fwerkor-autopic-' . $post->ID . '-' . substr($signature, 0, 12) . '.png'
        );
        $file = trailingslashit($uploads['path']) . $filename;

        $ok = imagepng($img, $file, 7);
        imagedestroy($img);
        if (!$ok) {
            return 0;
        }

        $attachment_id = wp_insert_attachment(
            array(
                'post_mime_type' => 'image/png',
                'post_title' => sanitize_text_field($post->post_title),
                'post_excerpt' => '',
                'post_status' => 'inherit',
            ),
            $file,
            $post->ID
        );

        if (is_wp_error($attachment_id)) {
            @unlink($file);
            return 0;
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        $meta = wp_generate_attachment_metadata($attachment_id, $file);
        wp_update_attachment_metadata($attachment_id, $meta);
        update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($post->post_title));

        return (int) $attachment_id;
    }

    public function menu(): void {
        add_options_page(
            'FWERKOR Auto Pic',
            'FWERKOR Auto Pic',
            'manage_options',
            'fwerkor-autopic',
            array($this, 'render')
        );
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? '') && isset($_POST['fwerkor_autopic_settings'])) {
            check_admin_referer('fwerkor_autopic_settings');
            update_option(
                self::OPTION,
                array(
                    'enabled' => isset($_POST['enabled']) ? 1 : 0,
                    'prefer_content_image' => isset($_POST['prefer_content_image']) ? 1 : 0,
                    'width' => max(640, min(2560, absint($_POST['width'] ?? 1440))),
                    'height' => max(360, min(1600, absint($_POST['height'] ?? 900))),
                ),
                false
            );
            wp_safe_redirect(admin_url('options-general.php?page=fwerkor-autopic&updated=1'));
            exit;
        }

        $o = $this->options();
        ?>
        <div class="wrap">
            <h1>FWERKOR Auto Pic</h1>
            <p>Uses an existing article image when possible. If none exists, it creates deterministic abstract artwork without external APIs or bundled fonts.</p>
            <?php if (isset($_GET['updated'])) : ?><div class="notice notice-success is-dismissible"><p>Settings saved.</p></div><?php endif; ?>
            <?php if (isset($_GET['generated'])) : ?><div class="notice notice-success is-dismissible"><p>Processed <?php echo esc_html((string) absint($_GET['generated'])); ?> published posts.</p></div><?php endif; ?>

            <form method="post">
                <?php wp_nonce_field('fwerkor_autopic_settings'); ?>
                <input type="hidden" name="fwerkor_autopic_settings" value="1">
                <table class="form-table">
                    <tr><th>Enabled</th><td><label><input type="checkbox" name="enabled" value="1" <?php checked(!empty($o['enabled'])); ?>> Generate featured images automatically</label></td></tr>
                    <tr><th>Article images</th><td><label><input type="checkbox" name="prefer_content_image" value="1" <?php checked(!empty($o['prefer_content_image'])); ?>> Prefer an existing image from the post or its attachments</label></td></tr>
                    <tr><th>Generated size</th><td><input type="number" name="width" min="640" max="2560" value="<?php echo esc_attr((string) $o['width']); ?>"> × <input type="number" name="height" min="360" max="1600" value="<?php echo esc_attr((string) $o['height']); ?>"></td></tr>
                </table>
                <p><button class="button button-primary" type="submit">Save settings</button></p>
            </form>

            <hr>
            <h2>Process existing posts</h2>
            <p>Manual featured images are never replaced.</p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('fwerkor_autopic_generate_all'); ?>
                <input type="hidden" name="action" value="fwerkor_autopic_generate_all">
                <button class="button" type="submit">Process all published posts</button>
            </form>
        </div>
        <?php
    }

    public function generate_all_action(): void {
        if (!current_user_can('manage_options')) {
            wp_die('Insufficient permission.');
        }
        check_admin_referer('fwerkor_autopic_generate_all');
        $ids = get_posts(array(
            'post_type' => 'post',
            'post_status' => 'publish',
            'numberposts' => -1,
            'fields' => 'ids',
        ));
        foreach ($ids as $id) {
            $this->ensure_featured_image((int) $id);
        }
        wp_safe_redirect(admin_url('options-general.php?page=fwerkor-autopic&generated=' . count($ids)));
        exit;
    }

    private function options(): array {
        return wp_parse_args(
            (array) get_option(self::OPTION, array()),
            array('enabled' => 1, 'prefer_content_image' => 1, 'width' => 1440, 'height' => 900)
        );
    }
}

register_activation_hook(__FILE__, array('FWERKOR_Auto_Pic', 'activate'));
new FWERKOR_Auto_Pic();
