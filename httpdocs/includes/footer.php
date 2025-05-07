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
<footer style="display:flex;justify-content:center;align-items:center;padding:18px 24px;background:<?php echo htmlspecialchars($primary); ?>;border-top:1.5px solid #e0e4ea;font-size:1em;color:#fff;flex-wrap:wrap;gap:15px;">
    <div style="flex: 1; text-align: center; min-width: 150px;">
        <?php if (!empty($settings['terms_url'])): ?>
            <a href="<?php echo htmlspecialchars($settings['terms_url']); ?>" target="_blank" rel="noopener" style="<?php echo $buttonStyle; ?>">Terms &amp; Conditions</a>
        <?php endif; ?>
    </div>
    <div style="flex: 2; text-align:center; min-width: 200px;">
        <?php if (!empty($footer_logo) && ($logo_location === 'footer' || $logo_location === 'both')): ?>
            <img src="/<?php echo ltrim(htmlspecialchars($footer_logo), '/'); ?>" alt="Site Logo" class="footer-logo" style="max-width:120px;max-height:48px;display:block;margin:0 auto;">
        <?php endif; ?>
        <?php if (!empty($footer_text)): ?>
            <div style="margin-top:6px;"><?php echo htmlspecialchars($footer_text); ?></div>
        <?php endif; ?>
    </div>
    <div style="flex: 1; text-align: center; min-width: 150px;">
        <?php if (!empty($settings['privacy_policy_url'])): ?>
            <a href="<?php echo htmlspecialchars($settings['privacy_policy_url']); ?>" target="_blank" rel="noopener" style="<?php echo $buttonStyle; ?>">Privacy Policy</a>
        <?php endif; ?>
    </div>
</footer>
<style>
@media (max-width: 600px) {
    footer {
        flex-direction: row !important;
        flex-wrap: wrap !important;
        align-items: flex-start !important;
        padding: 10px !important;
        gap: 5px !important;
    }
    
    footer > div:first-child {
        width: auto !important;
        order: 1;
        margin-right: 5px !important;
    }
    
    footer > div:last-child {
        width: auto !important;
        order: 2;
        margin-left: 5px !important;
    }
    
    footer > div:nth-child(2) {
        width: 100% !important;
        order: 3;
        margin-top: 10px !important;
        text-align: left !important;
    }
    
    footer a {
        padding: 6px 10px !important;
        font-size: 0.85em !important;
        width: auto !important;
        max-width: none !important;
        margin: 0 !important;
    }
    
    .footer-logo {
        max-width: 90px !important;
        max-height: 36px !important;
        margin: 0 !important;
    }
}

@media (max-width: 360px) {
    footer > div:first-child,
    footer > div:last-child {
        width: 100% !important;
        margin: 2px 0 !important;
    }
    
    footer a {
        width: 100% !important;
        display: block !important;
        text-align: center !important;
        margin-bottom: 5px !important;
    }
}
</style>
</body>
</html>