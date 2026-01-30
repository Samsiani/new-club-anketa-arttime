<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Custom sanitizer allowing richer HTML (styles, classes, ids, data-*).
 */
function club_anketa_sanitize_terms_html($html) {
    if (!is_string($html)) {
        return '';
    }

    $allowed = [
        'div'   => ['class'=>true,'id'=>true,'style'=>true,'data-*'=>true],
        'span'  => ['class'=>true,'id'=>true,'style'=>true,'data-*'=>true],
        'p'     => ['class'=>true,'id'=>true,'style'=>true,'data-*'=>true],
        'br'    => [],
        'hr'    => ['class'=>true,'id'=>true,'style'=>true],
        'strong'=> ['class'=>true,'id'=>true,'style'=>true],
        'em'    => ['class'=>true,'id'=>true,'style'=>true],
        'b'     => ['class'=>true,'id'=>true,'style'=>true],
        'i'     => ['class'=>true,'id'=>true,'style'=>true],
        'u'     => ['class'=>true,'id'=>true,'style'=>true],
        'small' => ['class'=>true,'id'=>true,'style'=>true],
        'mark'  => ['class'=>true,'id'=>true,'style'=>true],
        'sub'   => ['class'=>true,'id'=>true,'style'=>true],
        'sup'   => ['class'=>true,'id'=>true,'style'=>true],
        'code'  => ['class'=>true,'id'=>true,'style'=>true],
        'pre'   => ['class'=>true,'id'=>true,'style'=>true],
        'h1'    => ['class'=>true,'id'=>true,'style'=>true],
        'h2'    => ['class'=>true,'id'=>true,'style'=>true],
        'h3'    => ['class'=>true,'id'=>true,'style'=>true],
        'h4'    => ['class'=>true,'id'=>true,'style'=>true],
        'h5'    => ['class'=>true,'id'=>true,'style'=>true],
        'h6'    => ['class'=>true,'id'=>true,'style'=>true],
        'ul'    => ['class'=>true,'id'=>true,'style'=>true],
        'ol'    => ['class'=>true,'id'=>true,'style'=>true],
        'li'    => ['class'=>true,'id'=>true,'style'=>true],
        'table' => ['class'=>true,'id'=>true,'style'=>true,'data-*'=>true],
        'thead' => ['class'=>true,'id'=>true,'style'=>true],
        'tbody' => ['class'=>true,'id'=>true,'style'=>true],
        'tr'    => ['class'=>true,'id'=>true,'style'=>true],
        'td'    => ['class'=>true,'id'=>true,'style'=>true,'colspan'=>true,'rowspan'=>true,'data-*'=>true],
        'th'    => ['class'=>true,'id'=>true,'style'=>true,'colspan'=>true,'rowspan'=>true,'data-*'=>true],
        'a'     => ['class'=>true,'id'=>true,'style'=>true,'href'=>true,'title'=>true,'target'=>true,'rel'=>true,'data-*'=>true],
        'img'   => ['class'=>true,'id'=>true,'style'=>true,'src'=>true,'alt'=>true,'title'=>true,'width'=>true,'height'=>true,'loading'=>true,'data-*'=>true],
    ];

    $expanded_allowed = [];
    foreach ($allowed as $tag => $attrs) {
        $expanded_attrs = [];
        foreach ($attrs as $attr => $v) {
            if ($attr === 'data-*') {
                $expanded_attrs['data-title']   = true;
                $expanded_attrs['data-name']    = true;
                $expanded_attrs['data-id']      = true;
                $expanded_attrs['data-value']   = true;
                $expanded_attrs['data-type']    = true;
                $expanded_attrs['data-state']   = true;
                $expanded_attrs['data-extra']   = true;
                $expanded_attrs['data-label']   = true;
                $expanded_attrs['data-key']     = true;
                $expanded_attrs['data-role']    = true;
                $expanded_attrs['data-index']   = true;
                $expanded_attrs['data-active']  = true;
                $expanded_attrs['data-toggle']  = true;
                $expanded_attrs['data-color']   = true;
            } else {
                $expanded_attrs[$attr] = true;
            }
        }
        $expanded_allowed[$tag] = $expanded_attrs;
    }

    return wp_kses($html, $expanded_allowed);
}

/**
 * Settings page for Club Anketa plugin.
 */
add_action('admin_menu', function () {
    add_options_page(
        __('Club Anketa Settings', 'club-anketa'),
        __('Club Anketa Settings', 'club-anketa'),
        'manage_options',
        'club-anketa-settings',
        'club_anketa_render_settings_page'
    );
});

add_action('admin_init', function () {
    register_setting('club_anketa_settings_group', 'club_anketa_terms_url', [
        'type'              => 'string',
        'sanitize_callback' => 'esc_url_raw',
        'default'           => '',
    ]);

    register_setting('club_anketa_settings_group', 'club_anketa_terms_html', [
        'type'              => 'string',
        'sanitize_callback' => 'club_anketa_sanitize_terms_html',
        'default'           => '',
    ]);

    register_setting('club_anketa_settings_group', 'club_anketa_sms_terms_html', [
        'type'              => 'string',
        'sanitize_callback' => 'club_anketa_sanitize_terms_html',
        'default'           => '',
    ]);

    register_setting('club_anketa_settings_group', 'club_anketa_call_terms_html', [
        'type'              => 'string',
        'sanitize_callback' => 'club_anketa_sanitize_terms_html',
        'default'           => '',
    ]);

    // MS Group SMS API Settings
    register_setting('club_anketa_settings_group', 'club_anketa_sms_username', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ]);

    register_setting('club_anketa_settings_group', 'club_anketa_sms_password', [
        'type'              => 'string',
        'sanitize_callback' => 'sanitize_text_field',
        'default'           => '',
    ]);

    register_setting('club_anketa_settings_group', 'club_anketa_sms_client_id', [
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 0,
    ]);

    register_setting('club_anketa_settings_group', 'club_anketa_sms_service_id', [
        'type'              => 'integer',
        'sanitize_callback' => 'absint',
        'default'           => 0,
    ]);

    add_settings_section(
        'club_anketa_main',
        __('General', 'club-anketa'),
        function () {
            echo '<p>' . esc_html__('Configure the Club Anketa plugin.', 'club-anketa') . '</p>';
        },
        'club_anketa_settings'
    );

    // SMS API Settings Section
    add_settings_section(
        'club_anketa_sms_api',
        __('MS Group SMS API Settings', 'club-anketa'),
        function () {
            echo '<p>' . esc_html__('Configure your MS Group SMS API credentials for OTP verification.', 'club-anketa') . '</p>';
        },
        'club_anketa_settings'
    );

    add_settings_field(
        'club_anketa_sms_username',
        __('SMS API Username', 'club-anketa'),
        function () {
            $val = esc_attr(get_option('club_anketa_sms_username', ''));
            echo '<input type="text" name="club_anketa_sms_username" value="' . $val . '" class="regular-text" placeholder="Your API username" />';
        },
        'club_anketa_settings',
        'club_anketa_sms_api'
    );

    add_settings_field(
        'club_anketa_sms_password',
        __('SMS API Password', 'club-anketa'),
        function () {
            $val = esc_attr(get_option('club_anketa_sms_password', ''));
            echo '<input type="password" name="club_anketa_sms_password" value="' . $val . '" class="regular-text" placeholder="Your API password" />';
        },
        'club_anketa_settings',
        'club_anketa_sms_api'
    );

    add_settings_field(
        'club_anketa_sms_client_id',
        __('SMS API Client ID', 'club-anketa'),
        function () {
            $val = esc_attr(get_option('club_anketa_sms_client_id', ''));
            echo '<input type="number" name="club_anketa_sms_client_id" value="' . $val . '" class="regular-text" placeholder="Client identifier" />';
        },
        'club_anketa_settings',
        'club_anketa_sms_api'
    );

    add_settings_field(
        'club_anketa_sms_service_id',
        __('SMS API Service ID', 'club-anketa'),
        function () {
            $val = esc_attr(get_option('club_anketa_sms_service_id', ''));
            echo '<input type="number" name="club_anketa_sms_service_id" value="' . $val . '" class="regular-text" placeholder="Brand-name identifier" />';
        },
        'club_anketa_settings',
        'club_anketa_sms_api'
    );

    add_settings_field(
        'club_anketa_terms_url',
        __('Terms & Conditions URL (Print Terms fallback)', 'club-anketa'),
        function () {
            $val = esc_url(get_option('club_anketa_terms_url', ''));
            echo '<input type="url" name="club_anketa_terms_url" value="' . $val . '" class="regular-text" placeholder="https://example.com/terms" />';
            echo '<p class="description">' . esc_html__('Used only if the rich text editor content is empty.', 'club-anketa') . '</p>';
        },
        'club_anketa_settings',
        'club_anketa_main'
    );

    add_settings_field(
        'club_anketa_terms_html',
        __('Terms & Conditions Content (rich HTML)', 'club-anketa'),
        function () {
            $val = get_option('club_anketa_terms_html', '');
            echo '<p class="description" style="margin-top:-6px;">' . esc_html__('If provided, this full styled HTML is printed (URL ignored). Inline styles & classes are preserved.', 'club-anketa') . '</p>';
            wp_editor(
                $val,
                'club_anketa_terms_html',
                [
                    'textarea_name' => 'club_anketa_terms_html',
                    'media_buttons' => true,
                    'textarea_rows' => 14,
                    'teeny'         => false,
                    'editor_height' => 320,
                ]
            );
        },
        'club_anketa_settings',
        'club_anketa_main'
    );

    add_settings_field(
        'club_anketa_sms_terms_html',
        __('SMS Terms Content (rich HTML)', 'club-anketa'),
        function () {
            $val = get_option('club_anketa_sms_terms_html', '');
            echo '<p class="description" style="margin-top:-6px;">' . esc_html__('Content for the Print SMS Terms button.', 'club-anketa') . '</p>';
            wp_editor(
                $val,
                'club_anketa_sms_terms_html',
                [
                    'textarea_name' => 'club_anketa_sms_terms_html',
                    'media_buttons' => true,
                    'textarea_rows' => 14,
                    'teeny'         => false,
                    'editor_height' => 320,
                ]
            );
        },
        'club_anketa_settings',
        'club_anketa_main'
    );

    add_settings_field(
        'club_anketa_call_terms_html',
        __('Phone Call Terms Content (rich HTML)', 'club-anketa'),
        function () {
            $val = get_option('club_anketa_call_terms_html', '');
            echo '<p class="description" style="margin-top:-6px;">' . esc_html__('Content for the Print Phone Call Terms button.', 'club-anketa') . '</p>';
            wp_editor(
                $val,
                'club_anketa_call_terms_html',
                [
                    'textarea_name' => 'club_anketa_call_terms_html',
                    'media_buttons' => true,
                    'textarea_rows' => 14,
                    'teeny'         => false,
                    'editor_height' => 320,
                ]
            );
        },
        'club_anketa_settings',
        'club_anketa_main'
    );

    add_settings_section(
        'club_anketa_shortcodes',
        __('Shortcodes', 'club-anketa'),
        function () {
            echo '<p>' . esc_html__('Available shortcodes:', 'club-anketa') . '</p>';
            echo '<ul><li><code>[club_anketa_form]</code> — ' . esc_html__('Displays the Anketa registration form.', 'club-anketa') . '</li></ul>';
            echo '<p class="description">' . esc_html__('Printable pages: /print-anketa/, /signature-terms/', 'club-anketa') . '</p>';
        },
        'club_anketa_settings'
    );
});

function club_anketa_render_settings_page() {
    if (!current_user_can('manage_options')) {
        return;
    }
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Club Anketa Settings', 'club-anketa'); ?></h1>
        <form action="options.php" method="post">
            <?php
            settings_fields('club_anketa_settings_group');
            do_settings_sections('club_anketa_settings');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

/**
 * Format terms HTML for output:
 * - If no block-level elements detected, apply wpautop to create paragraphs.
 */
function club_anketa_prepare_terms_html($html) {
    if (!is_string($html) || $html === '') {
        return '';
    }
    // Detect if content already has block-level tags.
    if (preg_match('/<(p|div|ul|ol|li|table|thead|tbody|tr|td|th|h[1-6])\b/i', $html)) {
        return $html;
    }
    // Otherwise attempt to create paragraphs.
    $html_with_paragraphs = wpautop($html);
    return $html_with_paragraphs;
}