</main>
<?php
  // Robust path detection for active tab highlighting
  $parts = parse_url($_SERVER['REQUEST_URI']);
  $path = $parts['path'] ?? '';
  $script = basename($path ?: $_SERVER['PHP_SELF']);
  $hideMobileFooter = in_array($script, ['login.php','register.php'], true);
?>
<?php if (!$hideMobileFooter): ?>
<footer>
  <nav class="mobile-tabbar" aria-label="Primary">
    <?php
      // Active states: treat index.php?search=1 as Vote flow
      $isSearch = isset($_GET['search']);
      $isHome = (($script === 'index.php') && !$isSearch) || (rtrim($path,'/') === ADDRESS.'' && !$isSearch);
      $isVote = ($script === 'vote.php') || ($script === 'index.php' && $isSearch);
      $isStats = ($script === 'stats.php');
      $isProfile = ($script === 'profile.php');
      $currentUser = current_user();
      $profileLink = $currentUser ? 'profile.php' : 'login.php';
      $profileLabel = $currentUser ? t('profile') : t('login');
    ?>
    <a class="tab-link<?= $isHome ? ' active' : '' ?>" href="index.php">
      <span class="icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M3 10.5L12 3l9 7.5V21a1 1 0 0 1-1 1h-6v-6H10v6H4a1 1 0 0 1-1-1v-10.5Z" /></svg>
      </span>
      <span class="label"><?= e(t('home')) ?></span>
    </a>
    <a class="tab-link<?= $isVote ? ' active' : '' ?>" href="index.php?search=1">
      <span class="icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-3.8L6 21l1.5-7.5L2 9h7z" /></svg>
      </span>
      <span class="label"><?= e(t('vote')) ?></span>
    </a>
    <a class="tab-link<?= $isStats ? ' active' : '' ?>" href="stats.php">
      <span class="icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M4 21V10" /><path d="M10 21V3" /><path d="M16 21v-6" /><path d="M22 21v-9" /></svg>
      </span>
      <span class="label"><?= e(t('stats')) ?></span>
    </a>
    <a class="tab-link<?= $isProfile ? ' active' : '' ?>" href="<?= $profileLink ?>">
      <span class="icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="9" r="4" /><path d="M5 20c0-3.314 3.134-6 7-6s7 2.686 7 6" /></svg>
      </span>
      <span class="label"><?= e($profileLabel) ?></span>
    </a>
  </nav>
</footer>
<?php endif; ?>
<script>
  // Initialize starfield effect with slower and subtler settings
  (function() {
    if (typeof Starfield === 'undefined') {
      console.error('Starfield library not loaded');
      return;
    }
    
    try {
      Starfield.setup({
        numStars: 150,              // Fewer stars for subtlety
        baseSpeed: 0.3,             // Much slower base speed
        trailLength: 0.9,           // Longer trails for smoother effect
        starColor: 'rgb(200, 200, 220)',  // Subtle bluish-white stars
        canvasColor: 'rgb(0, 0, 0)',
        hueJitter: 10,              // Slight color variation
        maxAcceleration: 3,         // Lower max acceleration
        accelerationRate: 0.03,     // Much slower acceleration
        decelerationRate: 0.03,     // Much slower deceleration
        minSpawnRadius: 100,
        maxSpawnRadius: 600,
        auto: false,                // Manual mode - no hover effects
        originX: null,              // Center of screen
        originY: null
      });
      console.log('Starfield initialized successfully');
    } catch (error) {
      console.error('Error initializing starfield:', error);
    }
  })();
</script>
</body></html>
