<?php
/**
 * Displays the "Filter Everything" plugin widget on shop archive pages.
 *
 * This file hooks into WooCommerce and uses a shortcode to render the main
 * product filter widget, which is configured in the plugin's settings.
 * This version enables the horizontal, three-column layout.
 *
 * @package Ratna Gems
 * @version 2.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * Hook the display function into the correct WooCommerce action.
 */
add_action( 'woocommerce_before_shop_loop', 'rg_display_filter_everything_widget', 15 );

/**
 * Renders the Filter Everything widget using its shortcode.
 *
 * This ensures the filter controls appear right above the product grid on
 * the Shop page, category pages, and tag archive pages.
 */
function rg_display_filter_everything_widget(): void {
    if ( is_shop() || is_product_category() || is_product_tag() ) {
        ?>
        <div class="rg-filters-section">
            <div class="rg-filters-inner">
                <?php echo do_shortcode( '[fe_widget horizontal="yes" columns="3"]' ); ?>
            </div>
        </div>
        <?php
    }
}
