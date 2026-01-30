<?php
/**
 * Signature Terms printable page at /signature-terms/?user_id=ID
 * Priority:
 * 1) Rich editor content (styled; auto-paragraphs if only inline markup)
 * 2) External URL iframe (fallback)
 * 3) Built-in fallback HTML.
 */
if (!defined('ABSPATH')) {
    exit;
}

$user_id    = isset($_GET['user_id']) ? absint($_GET['user_id']) : 0;
$terms_type = isset($_GET['terms_type']) ? sanitize_key($_GET['terms_type']) : '';
$anketa_url = esc_url(add_query_arg('user_id', $user_id, home_url('/print-anketa/')));

// Determine which content and title to display based on terms_type
$is_specific_terms = false;
if ($terms_type === 'sms') {
    $terms_html_raw = (string) get_option('club_anketa_sms_terms_html', '');
    $page_title     = __('SMS შეტყობინების პირობები', 'club-anketa');
    $is_specific_terms = true;
} elseif ($terms_type === 'call') {
    $terms_html_raw = (string) get_option('club_anketa_call_terms_html', '');
    $page_title     = __('სატელეფონო ზარის პირობები', 'club-anketa');
    $is_specific_terms = true;
} else {
    $terms_html_raw = (string) get_option('club_anketa_terms_html', '');
    $page_title     = __('წესები და პირობები', 'club-anketa');
}
$terms_url      = (string) get_option('club_anketa_terms_url', '');

$fallback_rules = <<<HTML
<p><strong>Arttime-ის კლუბის წევრები სარგებლობენ შემდეგი უპირატესობით:</strong></p>
<ul>
<li>ბარათზე 500-5000 ლარამდე დაგროვების შემთხვევაში ფასდაკლება 5%</li>
<li>ბარათზე 5001-10000 ლარამდე დაგროვების შემთხვევაში ფასდაკლება 10%;</li>
<li>ბარათზე 10 000 ლარზე მეტის დაგროვების შემთხვევაში ფასდაკლება 15%.</li>
</ul>
<p>&nbsp;</p>
<p><strong>გთხოვთ გაითვალისწინოთ:</strong></p>
<ol>
<li>ართთაიმის კლუბის ბარათით გათვალისწინებული ფასდაკლება არ მოქმედებს ფასდაკლებელ პროდუქციაზე;</li>
<li>ფასდაკლებული პროდუქციის შეძენის შემთხვევაში ბარათზე მხოლოდ ქულები დაგერიცხებათ;</li>
<li>ფასდაკლება მოქმედებს, მაგრამ ქულები არ გერიცხებათ პროდუქციის სასაჩუქრე ვაუჩერით შემენისას</li>
<li>სასაჩუქრე ვაუჩერის შეძენისას ფასდაკლება არ მოქმედებს, მაგრამ ქულები გროვდება:</li>
<li>დაგროვილი ქულები ბარათზე აისახება 2 სამუშაო დღის ვადაში;</li>
<li>გაითვალისწინეთ, წინამდებარე წესებით დადგენილი პირობები შეიძლება შეიცვალოს შპს „ართთაიმის“ მიერ, რომელიც სავალდებულო იქნება ბარათების პროექტში ჩართული მომხმარებლებისთვის.</li>
<li>ხელმოწერით ვადასტურებ ჩემი პირადი მონაცემების სიზუსტეს და ბარათის მიღებას</li>
</ol>
HTML;

// Prepare rich content (auto-paragraph if only inline spans/bolds).
$terms_html_prepared = '';
if (trim($terms_html_raw) !== '') {
    $terms_html_prepared = club_anketa_prepare_terms_html($terms_html_raw);
}

?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html__('Terms & Signature', 'club-anketa'); ?></title>
<link rel="stylesheet" href="<?php echo esc_url(CLUB_ANKETA_URL . 'assets/print-anketa.css?v=' . urlencode(CLUB_ANKETA_VERSION)); ?>" media="all" />
<style>
.signature-terms-wrapper { max-width: 210mm; margin: 0 auto; padding: 14mm; background: #fff; }
.terms-iframe {
  width: 100%;
  min-height: 240mm;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  background: #fff;
}
.rules-inner { margin-top: 8mm; }
.rules-inner p { margin: 0 0 8px; }
</style>
</head>
<body>
<div class="print-actions">
    <button onclick="window.print()"><?php echo esc_html__('Print Terms', 'club-anketa'); ?></button>
    <a class="button button-secondary print-terms-btn" href="<?php echo $anketa_url; ?>"><?php echo esc_html__('Print Anketa', 'club-anketa'); ?></a>
</div>

<div class="signature-terms-wrapper page">
    <h2 style="text-align:center; margin:0 0 8mm;"><?php echo esc_html($page_title); ?></h2>

    <?php if ($terms_html_prepared !== ''): ?>
        <div class="rules-inner">
            <?php
            // Already sanitized + formatted; output directly.
            echo $terms_html_prepared; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            ?>
        </div>
    <?php elseif ($is_specific_terms): ?>
        <div class="rules-inner">
            <p style="color:#555;"><?php echo esc_html__('No content has been configured for this terms type. Please configure it in the Club Anketa Settings.', 'club-anketa'); ?></p>
        </div>
    <?php elseif (!empty($terms_url)): ?>
        <iframe class="terms-iframe" src="<?php echo esc_url($terms_url); ?>"></iframe>
        <p class="description" style="margin-top:8px;color:#555;"><?php echo esc_html__('Loaded from configured URL (no custom editor content provided).', 'club-anketa'); ?></p>
    <?php else: ?>
        <div class="rules-inner">
            <?php echo wp_kses_post(apply_filters('club_anketa_rules_text', $fallback_rules)); ?>
        </div>
    <?php endif; ?>

    <div class="row signature-row no-break" style="margin-top:12mm;">
        <div class="label"><?php echo esc_html__('მომხმარებლის ხელმოწერა', 'club-anketa'); ?></div>
        <div class="value value-line"></div>
    </div>
</div>
</body>
</html>