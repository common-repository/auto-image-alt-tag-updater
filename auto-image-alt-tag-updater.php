<?php
/*
Plugin Name: Auto Image Alt Tag Updater
Description: Updates alt tags of images using the SEO title of the page/post
Version: 1.7
Author: Sourabh Nagori
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

if (!defined('ABSPATH')) exit; // Prevent direct access to the file

// Hook into the save_post action
add_action('save_post', 'auto_img_alt_update_image_alt_tags', 10, 3);

function auto_img_alt_update_image_alt_tags($post_id, $post, $update) {
    // Bail if this is an autosave, revision, or user lacks permission
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;
    if (!current_user_can('edit_post', $post_id)) return;

    // Only proceed if the post type is post or page
    if (!in_array($post->post_type, array('post', 'page'))) return;

    $post_content = $post->post_content;
    $seo_title = auto_img_alt_get_seo_title($post_id);
    $updated_count = 0;

    // Update img tags with missing or incorrect alt attributes
    $post_content = preg_replace_callback(
        '/<img[^>]+>/i',
        function ($match) use ($seo_title, &$updated_count) {
            $img = $match[0];
            if (strpos($img, 'alt=') === false) {
                // Add alt attribute if missing
                $img = str_replace('<img', '<img alt="' . esc_attr($seo_title) . '"', $img);
                $updated_count++;
            } else {
                // Update alt attribute if it doesn't match the SEO title
                $img = preg_replace('/alt=["\'][^"\']*["\']/i', 'alt="' . esc_attr($seo_title) . '"', $img);
                $updated_count++;
            }
            return $img;
        },
        $post_content
    );

    // Update the post content if any changes were made
    if ($updated_count > 0) {
        remove_action('save_post', 'auto_img_alt_update_image_alt_tags', 10);
        wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $post_content
        ));
        add_action('save_post', 'auto_img_alt_update_image_alt_tags', 10, 3);

        // Update the summary of changes made
        $updated_posts = get_option('auto_img_alt_updater_summary', array());
        $updated_posts[$post_id] = array(
            'title' => get_the_title($post_id),
            'count' => $updated_count,
            'date' => current_time('mysql')
        );
        update_option('auto_img_alt_updater_summary', $updated_posts);
    }
}

function auto_img_alt_get_seo_title($post_id) {
    if (defined('WPSEO_VERSION')) {
        $yoast_title = get_post_meta($post_id, '_yoast_wpseo_title', true);
        if (!empty($yoast_title)) {
            return wpseo_replace_vars($yoast_title, get_post($post_id));
        }
    }

    return get_the_title($post_id);
}

// Add admin menu
add_action('admin_menu', 'auto_img_alt_add_admin_menu');
add_action('admin_init', 'auto_img_alt_settings_init');

function auto_img_alt_add_admin_menu() {
    add_menu_page(
        'Auto Alt Tag Updater',
        'Auto Alt Tag',
        'manage_options',
        'auto_img_alt_updater',
        'auto_img_alt_options_page',
        'dashicons-format-image'
    );
    add_submenu_page(
        'auto_img_alt_updater',
        'Summary',
        'Summary',
        'manage_options',
        'auto_img_alt_updater_summary',
        'auto_img_alt_summary_page'
    );
}

function auto_img_alt_settings_init() {
    register_setting('auto_img_alt_updater', 'auto_img_alt_updater_options', 'sanitize_text_field');

    add_settings_section(
        'auto_img_alt_section',
        'Settings',
        'auto_img_alt_section_callback',
        'auto_img_alt_updater'
    );

    add_settings_field(
        'posts_per_batch',
        'Posts per batch',
        'auto_img_alt_posts_per_batch_render',
        'auto_img_alt_updater',
        'auto_img_alt_section'
    );

    add_settings_field(
        'exclude_year',
        'Exclude posts from year',
        'auto_img_alt_exclude_year_render',
        'auto_img_alt_updater',
        'auto_img_alt_section'
    );
}

function auto_img_alt_section_callback() {
    echo 'Configure the Auto Alt Tag Updater settings:';
}

function auto_img_alt_posts_per_batch_render() {
    $options = get_option('auto_img_alt_updater_options');
    ?>
    <input type='number' name='auto_img_alt_updater_options[posts_per_batch]' value='<?php echo esc_attr($options['posts_per_batch'] ?? 10); ?>'>
    <?php
}

function auto_img_alt_exclude_year_render() {
    $options = get_option('auto_img_alt_updater_options');
    ?>
    <input type='number' name='auto_img_alt_updater_options[exclude_year]' value='<?php echo esc_attr($options['exclude_year'] ?? ''); ?>' placeholder='YYYY'>
    <?php
}

function auto_img_alt_options_page() {
    ?>
    <div class="wrap">
        <h1>Auto Alt Tag Updater</h1>
        <form action='options.php' method='post'>
            <?php
            settings_fields('auto_img_alt_updater');
            do_settings_sections('auto_img_alt_updater');
            submit_button();
            ?>
        </form>
        <p><a href="<?php echo esc_url(admin_url('admin.php?page=auto_img_alt_updater_summary')); ?>" class="button button-secondary">View Update Summary</a></p>
    </div>
    <?php
}

function auto_img_alt_summary_page() {
    $updated_posts = get_option('auto_img_alt_updater_summary', array());
    $total_images_updated = array_sum(array_column($updated_posts, 'count'));
    ?>
    <div class="wrap">
        <h1>Auto Alt Tag Update Summary</h1>
        <p>Total images updated: <?php echo esc_html($total_images_updated); ?></p>
        <?php
        if (empty($updated_posts)) {
            echo "<p>No updates have been recorded yet.</p>";
        } else {
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Post/Page Title</th>
                    <th>Images Updated</th>
                    <th>Last Updated</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($updated_posts as $post_id => $data): ?>
                <tr>
                    <td><a href="<?php echo esc_url(get_edit_post_link($post_id)); ?>"><?php echo esc_html($data['title']); ?></a></td>
                    <td><?php echo esc_html($data['count']); ?></td>
                    <td><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($data['date']))); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        }
        ?>
    </div>
    <?php
}

// Clear summary
add_action('admin_init', 'auto_img_alt_clear_summary');

function auto_img_alt_clear_summary() {
    if (isset($_POST['clear_auto_img_alt_summary']) && check_admin_referer('clear_summary_nonce')) {
        delete_option('auto_img_alt_updater_summary');
        wp_redirect(admin_url('admin.php?page=auto_img_alt_updater_summary&cleared=1'));
        exit;
    }
}

// Add a notice when summary is cleared
add_action('admin_notices', 'auto_img_alt_admin_notices');

function auto_img_alt_admin_notices() {
    if (isset($_GET['page']) && $_GET['page'] == 'auto_img_alt_updater_summary' && isset($_GET['cleared'])) {
        ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Summary has been cleared.', 'auto-image-alt-tag-updater'); ?></p>
        </div>
        <?php
    }
}
?>
