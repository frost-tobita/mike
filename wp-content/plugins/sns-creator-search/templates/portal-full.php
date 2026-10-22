<?php
/**
 * ページテンプレート「SNS検索ポータル（全幅）」（SNSCS_Portal_Top）。
 * テーマのヘッダー・フッターの代わりに、ポータルサイトの形のヘッダー・フッターで表示する。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$snscs_is_top = has_shortcode( (string) get_post_field( 'post_content', get_queried_object_id() ), 'sns_portal_top' );
$snscs_user   = is_user_logged_in() && snscs_is_creator_user();
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a class="screen-reader-text" href="#snscs-portal-main">本文へ移動</a>
<header class="snscs-ph">
	<div class="snscs-ph__inner">
		<a class="snscs-ph__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
			<?php if ( has_custom_logo() ) : ?>
				<?php echo wp_get_attachment_image( (int) get_theme_mod( 'custom_logo' ), 'medium', false, array( 'class' => 'snscs-ph__logo-image', 'alt' => get_bloginfo( 'name' ) ) ); ?>
			<?php else : ?>
				<?php bloginfo( 'name' ); ?>
			<?php endif; ?>
		</a>
		<nav class="snscs-ph__nav" aria-label="サイト全体">
			<?php if ( has_nav_menu( 'snscs_portal_header' ) ) : ?>
				<?php
				wp_nav_menu(
					array(
						'theme_location' => 'snscs_portal_header',
						'container'      => false,
						'menu_class'     => 'snscs-ph__menu',
						'depth'          => 1,
					)
				);
				?>
			<?php else : ?>
				<ul class="snscs-ph__menu">
					<?php foreach ( SNSCS_Portal_Top::header_links() as $snscs_link ) : ?>
						<li><a href="<?php echo esc_url( $snscs_link[0] ); ?>"><?php echo esc_html( $snscs_link[1] ); ?></a></li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
			<a class="snscs-ph__login" href="<?php echo esc_url( snscs_portal_url() ); ?>"><?php echo $snscs_user ? 'マイページ' : 'ログイン'; ?></a>
		</nav>
	</div>
</header>

<main id="snscs-portal-main" class="snscs-pm<?php echo $snscs_is_top ? ' snscs-pm--top' : ''; ?>">
	<?php while ( have_posts() ) : ?>
		<?php the_post(); ?>
		<?php if ( $snscs_is_top ) : ?>
			<?php the_content(); ?>
		<?php else : ?>
			<article class="snscs-pm__page">
				<h1 class="snscs-pm__title"><?php the_title(); ?></h1>
				<?php the_content(); ?>
			</article>
		<?php endif; ?>
	<?php endwhile; ?>
</main>

<footer class="snscs-pf">
	<div class="snscs-pf__inner">
		<?php if ( has_nav_menu( 'snscs_portal_footer' ) ) : ?>
			<?php
			wp_nav_menu(
				array(
					'theme_location' => 'snscs_portal_footer',
					'container'      => false,
					'menu_class'     => 'snscs-pf__menu',
					'depth'          => 1,
				)
			);
			?>
		<?php else : ?>
			<ul class="snscs-pf__menu">
				<?php foreach ( SNSCS_Portal_Top::footer_links() as $snscs_link ) : ?>
					<li><a href="<?php echo esc_url( $snscs_link[0] ); ?>"><?php echo esc_html( $snscs_link[1] ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<p class="snscs-pf__copy">&copy; <?php echo esc_html( wp_date( 'Y' ) . ' ' . get_bloginfo( 'name' ) ); ?></p>
	</div>
</footer>
<?php wp_footer(); ?>
</body>
</html>
