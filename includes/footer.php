</main>
<?php
  $script = basename($_SERVER['PHP_SELF']);
  $hideMobileFooter = in_array($script, ['login.php','register.php'], true);
?>
<?php if (!$hideMobileFooter): ?>
<footer>
  <nav class="mobile-tabbar" aria-label="Primary">
    <?php
      $isHome = $script === 'index.php';
      $isVote = $script === 'vote.php';
      $isStats = $script === 'stats.php';
      $isProfile = $script === 'profile.php';
      $currentUser = current_user();
      $profileLink = $currentUser ? 'profile.php' : 'login.php';
      $profileLabel = $currentUser ? t('profile') : t('login');
    ?>
    <a class="tab-link<?= $isHome ? ' active' : '' ?>" href="index.php">
      <span class="icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M3 10.5L12 3l9 7.5V21a1 1 0 0 1-1 1h-6v-6H10v6H4a1 1 0 0 1-1-1v-10.5Z" /></svg>
      </span>
      <span class="label">Home</span>
    </a>
    <a class="tab-link<?= $isVote ? ' active' : '' ?>" href="index.php?search=1">
      <span class="icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M12 2l3 7h7l-5.5 4.5L18 21l-6-3.8L6 21l1.5-7.5L2 9h7z" /></svg>
      </span>
      <span class="label">Vote</span>
    </a>
    <?php
      // Admin users see full stats, normal users see only their votes
      $isAdmin = function_exists('is_admin') && is_admin();
      $statsUrl = $isAdmin ? 'stats.php' : 'stats.php?mine=1';
      $statsLabel = $isAdmin ? 'Stats' : t('my_votes');
    ?>
    <a class="tab-link<?= $isStats ? ' active' : '' ?>" href="<?= $statsUrl ?>">
      <span class="icon" aria-hidden="true">
        <svg viewBox="0 0 24 24"><path d="M4 21V10" /><path d="M10 21V3" /><path d="M16 21v-6" /><path d="M22 21v-9" /></svg>
      </span>
      <span class="label"><?= e($statsLabel) ?></span>
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
</body></html>
