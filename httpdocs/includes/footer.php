<?php
if (!isset($settings)) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/config.php';
    $settings = function_exists('get_settings') ? get_settings() : [];
}
$primary = $settings['primary_color'] ?? '#4CAF50';
$lighterGreen = '#8BC34A';
$footer_text = $settings['footer_text'] ?? '';
$footer_logo = $settings['site_logo'] ?? '';
$logo_location = $settings['logo_location'] ?? 'header';
$buttonStyle = "display:inline-block;padding:7px 18px;background:{$lighterGreen};color:#fff;border-radius:5px;text-decoration:none;font-weight:600;margin-bottom:15px;";
?>
<footer style="display:flex;justify-content:space-between;align-items:center;padding:18px 24px;background:<?php echo htmlspecialchars($primary); ?>;border-top:1.5px solid #e0e4ea;font-size:1em;color:#fff;">
    <div>
        <?php if (!empty($settings['terms_url'])): ?>
            <a href="<?php echo htmlspecialchars($settings['terms_url']); ?>" target="_blank" rel="noopener" style="<?php echo $buttonStyle; ?>">Terms &amp; Conditions</a>
        <?php endif; ?>
    </div>
    <div style="text-align:center;">
        <?php if (!empty($footer_logo) && ($logo_location === 'footer' || $logo_location === 'both')): ?>
            <img src="/<?php echo ltrim(htmlspecialchars($footer_logo), '/'); ?>" alt="Site Logo" style="max-width:120px;max-height:48px;display:block;margin:0 auto;">
        <?php endif; ?>
        <?php if (!empty($footer_text)): ?>
            <div style="margin-top:6px;"><?php echo htmlspecialchars($footer_text); ?></div>
        <?php endif; ?>
    </div>
    <div>
        <?php if (!empty($settings['privacy_policy_url'])): ?>
            <a href="<?php echo htmlspecialchars($settings['privacy_policy_url']); ?>" target="_blank" rel="noopener" style="<?php echo $buttonStyle; ?>">Privacy Policy</a>
        <?php endif; ?>
    </div>
</footer>
</body>
</html>