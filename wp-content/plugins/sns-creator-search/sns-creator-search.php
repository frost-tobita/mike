<?php
/**
 * Plugin Name:       SNS Creator Search
 * Description:       SNSアカウントを登録し、ジャンル・地域・SNS・フォロワー数から発信者を検索できるようにするプラグイン。
 * Version:           0.6.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            SNS Creator Search Project
 * License:           GPL-2.0-or-later
 * Text Domain:       sns-creator-search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SNSCS_VERSION', '0.6.0' );
define( 'SNSCS_DB_VERSION', '5' );
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
	flush_rewrite_rules();
}

/**
 * 停止時: 定期的な点数計算を止める。
 */
function snscs_deactivate() {
	wp_clear_scheduled_hook( SNSCS_Scores::CRON_HOOK );
	wp_clear_scheduled_hook( SNSCS_Scores::CRON_HOOK . '_once' );
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
	SNSCS_Portal::add_role();
	update_option( 'snscs_columns_setup', 1 );
	update_option( 'snscs_db_version', SNSCS_DB_VERSION );
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
