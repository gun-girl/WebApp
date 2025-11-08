</main>
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
	/* Ensure page content isn't hidden behind the fixed tabs */
	main { padding-bottom: 140px !important; }
</style>
<footer></footer>
</body></html>
