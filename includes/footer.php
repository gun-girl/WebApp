</main>
<?php
  $script = basename($_SERVER['PHP_SELF']);
  $hideMobileFooter = in_array($script, ['login.php','register.php'], true);
?>
<?php if (!$hideMobileFooter): ?>
<style>
	/* Ensure the sheet-tabs bar is always full-width and flush to the viewport sides.
		 This overrides per-sheet inline .sheet-tabs rules that set max-width/margin. */
	.sheet-tabs {
		position: fixed !important;
		left: 0 !important;
		right: 0 !important;
		bottom: 0 !important;
		width: 100% !important;
		max-width: none !important;
		margin: 0 !important;
		padding: .25rem .5rem !important;
		background: rgba(8,8,8,0.95) !important;
		border-top: 1px solid rgba(255,255,255,0.03) !important;
		border-radius: 0 !important;
		box-shadow: 0 -6px 18px rgba(0,0,0,0.35) !important;
		overflow-x: auto !important;
		-webkit-overflow-scrolling: touch !important;
		z-index: 1400 !important;
	}
	.sheet-tabs .sheet-tab { flex: 0 0 auto !important; margin: 0 .25rem !important; }
	/* Base bottom padding so fixed elements never overlap content */
	main { padding-bottom: 160px !important; }

	.mobile-tabbar { display: none; }
	.mobile-tabbar a { text-decoration: none; }

	@media (max-width: 860px) {
		.sheet-tabs { bottom: 68px !important; }
		footer { max-width: none; margin: 0; padding: 0; background: transparent; border: 0; }
		main { padding-bottom: 200px !important; }
		.mobile-tabbar {
			display: flex;
			position: fixed;
			left: 0;
			right: 0;
			bottom: 0;
			width: 100%;
			margin: 0;
			justify-content: space-evenly;
			align-items: center;
			padding: .55rem 1.1rem .65rem;
			background: rgba(5,5,5,0.98);
			border-top: 1px solid rgba(255,255,255,0.06);
			box-shadow: 0 -12px 32px rgba(0,0,0,0.6);
			border-radius: 1.35rem 1.35rem 0 0;
			overflow: hidden;
			z-index: 1500;
		}
		.mobile-tabbar .tab-link {
			flex: 1;
			display: flex;
			flex-direction: column;
			align-items: center;
			justify-content: center;
			padding: .35rem 0;
			gap: .3rem;
			color: #aaa;
			font-weight: 600;
			font-size: .72rem;
			text-transform: uppercase;
			letter-spacing: .05em;
		}
		.mobile-tabbar .tab-link .icon {
			width: 2.35rem;
			height: 2.35rem;
			display: flex;
			align-items: center;
			justify-content: center;
		}
		.mobile-tabbar .tab-link .icon svg {
			width: 22px;
			height: 22px;
			stroke: currentColor;
			stroke-width: 1.8;
			stroke-linecap: round;
			stroke-linejoin: round;
			fill: none;
		}
		.mobile-tabbar .tab-link .label { font-size: .68rem; }
		.mobile-tabbar .tab-link.active { color: #f44437; }
		.mobile-tabbar .tab-link:focus-visible { outline: 2px solid #f44437; outline-offset: 6px; border-radius: 1rem; }
	}
</style>
<?php
	$profileLink = current_user() ? '/movie-club-app/profile.php' : '/movie-club-app/login.php';
	$profileLabel = current_user() ? t('your_profile') : t('login');
	$isHome = in_array($script, ['index.php', '']);
	$hasSearchQuery = isset($_GET['search']) && $script === 'index.php';
	$profilePages = ['profile.php','settings.php','login.php','register.php'];
	$isProfile = in_array($script, $profilePages, true);
?>
<footer>
	<nav class="mobile-tabbar" aria-label="<?= e(t('navigation')) ?>">
		<a class="tab-link<?= $isHome ? ' active' : '' ?>" href="/movie-club-app/index.php">
			<span class="icon" aria-hidden="true">
				<svg viewBox="0 0 24 24">
					<path d="M4 10.5L12 4l8 6.5V20a1 1 0 0 1-1 1h-5v-6h-4v6H5a1 1 0 0 1-1-1z" />
				</svg>
			</span>
			<span class="label"><?= e(t('home')) ?></span>
		</a>
	<a class="tab-link<?= $hasSearchQuery ? ' active' : '' ?>" href="/movie-club-app/index.php?search">
			<span class="icon" aria-hidden="true">
				<svg viewBox="0 0 24 24">
					<circle cx="11" cy="11" r="6" />
					<path d="M16 16l5 5" />
				</svg>
			</span>
			<span class="label"><?= e(t('search')) ?></span>
		</a>
		<a class="tab-link<?= $isProfile ? ' active' : '' ?>" href="<?= $profileLink ?>">
			<span class="icon" aria-hidden="true">
				<svg viewBox="0 0 24 24">
					<circle cx="12" cy="9" r="4" />
					<path d="M5 20c0-3.314 3.134-6 7-6s7 2.686 7 6" />
				</svg>
			</span>
			<span class="label"><?= e($profileLabel) ?></span>
		</a>
	</nav>
</footer>
<?php endif; ?>
</body></html>
