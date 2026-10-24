<?php
/**
 * Plugin Name:       SNS Creator Search
 * Description:       SNSアカウントを登録し、ジャンル・地域・SNS・フォロワー数から発信者を検索できるようにするプラグイン。
 * Version:           1.27.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            SNS Creator Search Project
 * License:           GPL-2.0-or-later
 * Text Domain:       sns-creator-search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SNSCS_VERSION', '1.27.0' );
define( 'SNSCS_DB_VERSION', '30' );
define( 'SNSCS_FILE', __FILE__ );
define( 'SNSCS_DIR', plugin_dir_path( __FILE__ ) );
define( 'SNSCS_URL', plugin_dir_url( __FILE__ ) );

require_once SNSCS_DIR . 'includes/functions.php';
require_once SNSCS_DIR . 'includes/class-snscs-post-types.php';
require_once SNSCS_DIR . 'includes/class-snscs-accounts.php';
require_once SNSCS_DIR . 'includes/class-snscs-search-backend.php';
require_once SNSCS_DIR . 'includes/class-snscs-mysql-backend.php';
require_once SNSCS_DIR . 'includes/class-snscs-search.php';
require_once SNSCS_DIR . 'includes/class-snscs-admin.php';
require_once SNSCS_DIR . 'includes/class-snscs-frontend.php';
require_once SNSCS_DIR . 'includes/class-snscs-rest.php';
require_once SNSCS_DIR . 'includes/class-snscs-submissions.php';
require_once SNSCS_DIR . 'includes/class-snscs-notify.php';
require_once SNSCS_DIR . 'includes/class-snscs-portal.php';
require_once SNSCS_DIR . 'includes/class-snscs-review-admin.php';
require_once SNSCS_DIR . 'includes/class-snscs-referrals.php';
require_once SNSCS_DIR . 'includes/class-snscs-scores.php';
require_once SNSCS_DIR . 'includes/class-snscs-ranking-admin.php';
require_once SNSCS_DIR . 'includes/class-snscs-ads.php';
require_once SNSCS_DIR . 'includes/class-snscs-ads-admin.php';
require_once SNSCS_DIR . 'includes/class-snscs-legal.php';
require_once SNSCS_DIR . 'includes/class-snscs-columns.php';
require_once SNSCS_DIR . 'includes/class-snscs-tracking.php';
require_once SNSCS_DIR . 'includes/class-snscs-articles.php';
require_once SNSCS_DIR . 'includes/class-snscs-articles-review.php';
require_once SNSCS_DIR . 'includes/class-snscs-recommend.php';
require_once SNSCS_DIR . 'includes/class-snscs-usage.php';
require_once SNSCS_DIR . 'includes/class-snscs-invites.php';
require_once SNSCS_DIR . 'includes/class-snscs-hub.php';
require_once SNSCS_DIR . 'includes/class-snscs-personalize.php';
require_once SNSCS_DIR . 'includes/class-snscs-trends.php';
require_once SNSCS_DIR . 'includes/class-snscs-trend-pages.php';
require_once SNSCS_DIR . 'includes/class-snscs-trend-alerts.php';
require_once SNSCS_DIR . 'includes/class-snscs-quiz.php';
require_once SNSCS_DIR . 'includes/class-snscs-column-kinds.php';
require_once SNSCS_DIR . 'includes/class-snscs-autolink.php';
require_once SNSCS_DIR . 'includes/class-snscs-feed.php';
require_once SNSCS_DIR . 'includes/class-snscs-feed-admin.php';
require_once SNSCS_DIR . 'includes/class-snscs-schema.php';
require_once SNSCS_DIR . 'includes/class-snscs-similar.php';
require_once SNSCS_DIR . 'includes/class-snscs-awards.php';
require_once SNSCS_DIR . 'includes/class-snscs-collab.php';
require_once SNSCS_DIR . 'includes/class-snscs-embed.php';
require_once SNSCS_DIR . 'includes/class-snscs-official.php';
require_once SNSCS_DIR . 'includes/class-snscs-line.php';
require_once SNSCS_DIR . 'includes/class-snscs-subpages.php';
require_once SNSCS_DIR . 'includes/class-snscs-ugc.php';
require_once SNSCS_DIR . 'includes/class-snscs-lists.php';
require_once SNSCS_DIR . 'includes/class-snscs-qa.php';
require_once SNSCS_DIR . 'includes/class-snscs-profile-ai.php';
require_once SNSCS_DIR . 'includes/class-snscs-gamify.php';
require_once SNSCS_DIR . 'includes/class-snscs-game.php';
require_once SNSCS_DIR . 'includes/class-snscs-press.php';
require_once SNSCS_DIR . 'includes/class-snscs-glossary.php';
require_once SNSCS_DIR . 'includes/class-snscs-english.php';
require_once SNSCS_DIR . 'includes/class-snscs-features.php';
require_once SNSCS_DIR . 'includes/class-snscs-rankings.php';
require_once SNSCS_DIR . 'includes/class-snscs-share.php';
require_once SNSCS_DIR . 'includes/class-snscs-newsletter.php';
require_once SNSCS_DIR . 'includes/class-snscs-tools.php';
require_once SNSCS_DIR . 'includes/class-snscs-interviews.php';
require_once SNSCS_DIR . 'includes/class-snscs-inquiries.php';
require_once SNSCS_DIR . 'includes/class-snscs-qr.php';
require_once SNSCS_DIR . 'includes/class-snscs-biolink-design.php';
require_once SNSCS_DIR . 'includes/class-snscs-biolink.php';
require_once SNSCS_DIR . 'includes/class-snscs-biolink-kit.php';
require_once SNSCS_DIR . 'includes/class-snscs-biolink-extra.php';
require_once SNSCS_DIR . 'includes/class-snscs-calendar.php';
require_once SNSCS_DIR . 'includes/class-snscs-brand.php';
require_once SNSCS_DIR . 'includes/class-snscs-portal-top.php';
require_once SNSCS_DIR . 'includes/class-snscs-timeline.php';
require_once SNSCS_DIR . 'includes/class-snscs-autopost.php';
require_once SNSCS_DIR . 'includes/class-snscs-text.php';
require_once SNSCS_DIR . 'includes/class-snscs-index.php';
require_once SNSCS_DIR . 'includes/class-snscs-claude.php';
require_once SNSCS_DIR . 'includes/class-snscs-tagger.php';
require_once SNSCS_DIR . 'includes/class-snscs-feedback.php';
require_once SNSCS_DIR . 'includes/class-snscs-graph.php';
require_once SNSCS_DIR . 'includes/class-snscs-learner.php';
require_once SNSCS_DIR . 'includes/class-snscs-ranker.php';
require_once SNSCS_DIR . 'includes/class-snscs-query.php';
require_once SNSCS_DIR . 'includes/class-snscs-search-cache.php';
require_once SNSCS_DIR . 'includes/class-snscs-search-quality.php';
require_once SNSCS_DIR . 'includes/class-snscs-search-ui.php';
require_once SNSCS_DIR . 'includes/class-snscs-business.php';
require_once SNSCS_DIR . 'includes/class-snscs-promo.php';
require_once SNSCS_DIR . 'includes/class-snscs-app.php';
require_once SNSCS_DIR . 'includes/class-snscs-founder.php';
require_once SNSCS_DIR . 'includes/class-snscs-session.php';
require_once SNSCS_DIR . 'includes/class-snscs-provisional.php';
require_once SNSCS_DIR . 'includes/class-snscs-integrity.php';
require_once SNSCS_DIR . 'includes/class-snscs-social.php';
require_once SNSCS_DIR . 'includes/class-snscs-inbox.php';
require_once SNSCS_DIR . 'includes/class-snscs-stories.php';
require_once SNSCS_DIR . 'includes/class-snscs-people.php';
require_once SNSCS_DIR . 'includes/class-snscs-push.php';
require_once SNSCS_DIR . 'includes/class-snscs-sync.php';
require_once SNSCS_DIR . 'includes/class-snscs-report.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SNSCS_DIR . 'includes/class-snscs-cli.php';
	WP_CLI::add_command( 'snscs', 'SNSCS_CLI' );
}

register_activation_hook( __FILE__, 'snscs_activate' );
register_deactivation_hook( __FILE__, 'snscs_deactivate' );

/**
 * 有効化時: テーブル作成とパーマリンク更新。
 */
function snscs_activate() {
	snscs_install();
	SNSCS_Post_Types::register();
	SNSCS_Referrals::add_rewrite_rule();
	SNSCS_Ads::add_rewrite_rules();
	SNSCS_Articles::register();
	SNSCS_Invites::add_rewrite_rule();
	SNSCS_Features::add_rewrite_rule();
	SNSCS_Share::add_rewrite_rules();
	SNSCS_Biolink::add_rewrite_rule();
	SNSCS_English::add_rewrite_rules();
	SNSCS_Report::add_rewrite_rule();
	SNSCS_Stories::register();
	SNSCS_Post_Types::install_default_terms();
	flush_rewrite_rules();
}

/**
 * 有効化・更新のあとの最初の表示で1回だけ: 既定のジャンル・地域（無ければ）と、URLの規則の作り直し。
 * ファイルを入れ替えて更新したとき（有効化し直さないとき）も、新しいURL（記事のハッシュタグなど）が使えるようにする。
 */
function snscs_after_install() {
	if ( get_option( 'snscs_after_install' ) ) {
		delete_option( 'snscs_after_install' );
		SNSCS_Post_Types::install_default_terms();
		// 機能ページを作ってあるサイトには、あとから加わったページ（トレンド・診断）も1度だけ作る。
		$added = (array) get_option( 'snscs_pages_added', get_option( 'snscs_trends_page_added' ) ? array( 'trends' ) : array() );
		$new   = array_diff( array( 'trends', 'shindan', 'awards', 'collab', 'similar', 'embed', 'lists', 'qa', 'profile-check', 'press', 'glossary', 'mylink' ), $added );
		if ( get_option( SNSCS_Hub::PAGES ) && $new ) {
			update_option( 'snscs_pages_added', array_merge( $added, $new ) );
			SNSCS_Hub::create_pages( array_values( $new ) );
		}
		flush_rewrite_rules();
	}
}
add_action( 'init', 'snscs_after_install', 99 );

/**
 * 停止時: 定期的な点数計算を止める。
 */
function snscs_deactivate() {
	wp_clear_scheduled_hook( SNSCS_Scores::CRON_HOOK );
	wp_clear_scheduled_hook( SNSCS_Scores::CRON_HOOK . '_once' );
	wp_clear_scheduled_hook( SNSCS_Newsletter::CRON_HOOK );
	wp_clear_scheduled_hook( SNSCS_Newsletter::CRON_HOOK . '_more' );
	wp_clear_scheduled_hook( SNSCS_Calendar::CRON_HOOK );
	wp_clear_scheduled_hook( SNSCS_Autopost::CRON_HOOK );
	wp_clear_scheduled_hook( 'snscs_nightly_engine' );
	wp_clear_scheduled_hook( 'snscs_hourly_engine' );
	wp_clear_scheduled_hook( SNSCS_Inbox::CRON_HOOK );
	wp_clear_scheduled_hook( SNSCS_Push::SEND_HOOK );
	wp_clear_scheduled_hook( SNSCS_Sync::CRON_HOOK );
	wp_clear_scheduled_hook( SNSCS_Line::SEND_HOOK );
	wp_clear_scheduled_hook( SNSCS_English::CRON_HOOK );
	flush_rewrite_rules();
}

/**
 * テーブルと「発信者」権限を用意する。何度実行しても安全。
 */
function snscs_install() {
	SNSCS_Accounts::install();
	SNSCS_Submissions::install();
	SNSCS_Referrals::install();
	SNSCS_Scores::install();
	SNSCS_Ads::install();
	SNSCS_Tracking::install();
	SNSCS_Usage::install();
	SNSCS_Personalize::install();
	SNSCS_Trends::install();
	SNSCS_Trend_Alerts::install();
	SNSCS_Feed::install();
	SNSCS_Awards::install();
	SNSCS_Collab::install();
	SNSCS_Lists::install();
	SNSCS_QA::install();
	SNSCS_Gamify::install();
	SNSCS_Game::install();
	SNSCS_Rankings::install();
	SNSCS_Newsletter::install();
	SNSCS_Inquiries::install();
	SNSCS_Biolink::install();
	SNSCS_Biolink_Extra::install();
	SNSCS_Calendar::install();
	SNSCS_Index::install();
	SNSCS_Feedback::install();
	SNSCS_Query::install();
	SNSCS_Search_Quality::install();
	SNSCS_Search_UI::install();
	SNSCS_Promo::install();
	SNSCS_Integrity::install();
	SNSCS_Social::install();
	SNSCS_Inbox::install();
	SNSCS_People::install();
	SNSCS_Push::install();
	SNSCS_Founder::install();
	SNSCS_Sync::install();
	SNSCS_Report::install();
	SNSCS_Portal::add_role();
	update_option( 'snscs_columns_setup', 1 );
	update_option( 'snscs_db_version', SNSCS_DB_VERSION );
	update_option( 'snscs_after_install', 1, false );
	// 新しいURL（特集・バッジ・シェア画像）を使えるよう、次の読み込みでパーマリンクを更新する。
	update_option( 'snscs_flush_rewrite', 1 );
}

/**
 * プラグイン更新でテーブル定義が変わったときに作り直す。
 */
function snscs_maybe_upgrade() {
	if ( get_option( 'snscs_db_version' ) !== SNSCS_DB_VERSION ) {
		snscs_install();
	}
}

add_action( 'plugins_loaded', 'snscs_maybe_upgrade' );
add_action( 'init', array( 'SNSCS_Post_Types', 'register' ) );

SNSCS_Admin::init();
SNSCS_Frontend::init();
SNSCS_Rest::init();
SNSCS_Notify::init();
SNSCS_Portal::init();
SNSCS_Review_Admin::init();
SNSCS_Referrals::init();
SNSCS_Scores::init();
SNSCS_Ranking_Admin::init();
SNSCS_Ads::init();
SNSCS_Ads_Admin::init();
SNSCS_Legal::init();
SNSCS_Columns::init();
SNSCS_Tracking::init();
SNSCS_Articles::init();
SNSCS_Articles_Review::init();
SNSCS_Recommend::init();
SNSCS_Usage::init();
SNSCS_Invites::init();
SNSCS_Hub::init();
SNSCS_Personalize::init();
SNSCS_Trends::init();
SNSCS_Trend_Pages::init();
SNSCS_Trend_Alerts::init();
SNSCS_Quiz::init();
SNSCS_Column_Kinds::init();
SNSCS_Autolink::init();
SNSCS_Feed::init();
SNSCS_Feed_Admin::init();
SNSCS_Schema::init();
SNSCS_Similar::init();
SNSCS_Awards::init();
SNSCS_Collab::init();
SNSCS_Embed::init();
SNSCS_Official::init();
SNSCS_Line::init();
SNSCS_Subpages::init();
SNSCS_UGC::init();
SNSCS_Lists::init();
SNSCS_QA::init();
SNSCS_Profile_AI::init();
SNSCS_Gamify::init();
SNSCS_Game::init();
SNSCS_Press::init();
SNSCS_Glossary::init();
SNSCS_English::init();
SNSCS_Features::init();
SNSCS_Rankings::init();
SNSCS_Share::init();
SNSCS_Newsletter::init();
SNSCS_Tools::init();
SNSCS_Interviews::init();
SNSCS_Inquiries::init();
SNSCS_Biolink::init();
SNSCS_Biolink_Kit::init();
SNSCS_Biolink_Extra::init();
SNSCS_Calendar::init();
SNSCS_Brand::init();
SNSCS_Portal_Top::init();
SNSCS_Timeline::init();
SNSCS_Autopost::init();
SNSCS_Index::init();
SNSCS_Feedback::init();
SNSCS_Graph::init();
SNSCS_Learner::init();
SNSCS_Query::init();
SNSCS_Search_Cache::init();
SNSCS_Search_Quality::init();
SNSCS_Search_UI::init();
SNSCS_Business::init();
SNSCS_Promo::init();
SNSCS_App::init();
SNSCS_Founder::init();
SNSCS_Session::init();
SNSCS_Provisional::init();
SNSCS_Tagger::init();
SNSCS_Integrity::init();
SNSCS_Social::init();
SNSCS_Inbox::init();
SNSCS_Stories::init();
SNSCS_People::init();
SNSCS_Push::init();
SNSCS_Sync::init();
SNSCS_Report::init();
