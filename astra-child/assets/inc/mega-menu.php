<?php
/**
 * Custom Mega Menu Functions for Sarfaraz Gems
 *
 * This file is included from functions.php and contains all the logic
 * for injecting custom HTML into the primary navigation menu.
 */

// Ensure this file is not accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Hook into the WordPress menu walker to replace placeholders with HTML blocks.
add_filter('walker_nav_menu_start_el', 'sarfaraz_inject_mega_menu_html', 10, 4);

function sarfaraz_inject_mega_menu_html($item_output, $item, $depth, $args) {

    // We only want to modify the primary navigation menu.
    if ('primary' !== $args->theme_location) {
        return $item_output;
    }

    $placeholder = trim($item->title);

    if ('[mega_precious]' === $placeholder) {
        return get_precious_gems_menu_html();
    }

    if ('[mega_semi_precious]' === $placeholder) {
        return get_semi_precious_gems_menu_html();
    }

    if ('[mega_rudraksha]' === $placeholder) {
        return get_rudraksha_menu_html();
    }

    return $item_output;
}


// --- HTML for the Precious Gemstones Mega Menu ---
// ALL 8 ORIGINAL ITEMS ARE INCLUDED.
function get_precious_gems_menu_html() {
    ob_start();
    ?>
    <div class="mega-menu">
        <div class="mega-menu-grid mega-menu-grid--precious">
            <div class="mega-menu-column">
                <a href="https://sarfarazgems.com/yellow-sapphire-pukhraj-stone/" class="mega-menu-item">
                    <img src="https://sarfarazgems.com/wp-content/uploads/2024/03/Yellow-Sapphire-Pukhraj.webp" alt="Vibrant yellow cushion-cut Pukhraj gemstone">
                    <span>Yellow Sapphire (Pukhraj)</span>
                </a>
                <a href="https://sarfarazgems.com/ruby-stone/" class="mega-menu-item">
                    <img src="https://sarfarazgems.com/wp-content/uploads/2024/02/Ruby-Manek1.webp" alt="Rich red oval-cut Ruby (Manik) gemstone">
                    <span>Ruby (Manik)</span>
                </a>
                <a href="https://sarfarazgems.com/pearl-stone/" class="mega-menu-item">
                    <img src="https://sarfarazgems.com/wp-content/uploads/2024/02/Pearl-Moti.webp" alt="Lustrous white spherical South Sea Pearl (Moti)">
                    <span>Pearl (Moti)</span>
                </a>
                <a href="https://sarfarazgems.com/hessonite-stone/" class="mega-menu-item">
                    <img src="https://sarfarazgems.com/wp-content/uploads/2024/02/Hessonite-Gomed.webp" alt="Cinnamon-colored oval-cut Hessonite (Gomed) gemstone">
                    <span>Hessonite (Gomed)</span>
                </a>
            </div>
            <div class="mega-menu-column">
                <a href="https://sarfarazgems.com/neelam-stone/" class="mega-menu-item">
                    <img src="https://sarfarazgems.com/wp-content/uploads/2024/02/Blue-Sapphire-Neela.webp" alt="Deep blue oval-cut Neelam gemstone">
                    <span>Blue Sapphire (Neelam)</span>
                </a>
                <a href="https://sarfarazgems.com/emerald-stone/" class="mega-menu-item">
                    <img src="https://sarfarazgems.com/wp-content/uploads/2024/02/Emerald-Panna.webp" alt="Brilliant green emerald-cut Panna gemstone">
                    <span>Emerald (Panna)</span>
                </a>
                <a href="https://sarfarazgems.com/red-coral/" class="mega-menu-item">
                    <img src="https://sarfarazgems.com/wp-content/uploads/2024/02/Red-Coral-Moonga.webp" alt="Polished red triangular Red Coral (Moonga) gemstone">
                    <span>Red Coral (Moonga)</span>
                </a>
                <a href="https://sarfarazgems.com/cats-eye/" class="mega-menu-item">
                    <img src="https://sarfarazgems.com/wp-content/uploads/2024/02/Cats-Eye-Lehsuniya.webp" alt="Greyish-green Cat's Eye gemstone (Lehsuniya)">
                    <span>Cat’s Eye (Lehsuniya)</span>
                </a>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}


// --- HTML for the Semi-Precious Gemstones Mega Menu ---
// ALL 11 ORIGINAL ITEMS ARE INCLUDED.
function get_semi_precious_gems_menu_html() {
    ob_start();
    ?>
    <div class="mega-menu">
        <div class="mega-menu-grid mega-menu-grid--semi-precious">
            <div class="mega-menu-column">
                <a href="https://sarfarazgems.com/agate-stone/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2024/02/Agate-Haqiq.webp" alt="Banded Agate (Hakik) stone"><span>Agate (Hakik)</span></a>
                <a href="https://sarfarazgems.com/blue-topaz-stone/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2024/02/Blue-Topaz.webp" alt="Sky blue faceted Blue Topaz gemstone"><span>Blue Topaz</span></a>
                <a href="https://sarfarazgems.com/lapis-lazuli/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2024/02/Lapis-Lajvart.webp" alt="Deep blue Lapis Lazuli (Lajward) stone"><span>Lapis Lazuli (Lajward)</span></a>
                <a href="https://sarfarazgems.com/onyx-stone/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2024/02/Onex.webp" alt="Polished black Onyx gemstone"><span>Onyx Stone</span></a>
            </div>
            <div class="mega-menu-column">
                <a href="https://sarfarazgems.com/amethyst-stone/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2024/02/Amethyst-Jamuniya.webp" alt="Deep purple oval-cut Amethyst (Jamunia) crystal"><span>Amethyst (Jamunia)</span></a>
                <a href="https://sarfarazgems.com/citrine-stone/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2024/02/Citrne-Sunela.webp" alt="Golden yellow Citrine (Sunela) gemstone"><span>Citrine (Sunela)</span></a>
                <a href="https://sarfarazgems.com/malachite-stone/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2024/02/Malachite-Kidney-Stone.webp" alt="Polished Malachite stone with characteristic green bands"><span>Malachite</span></a>
                <a href="https://sarfarazgems.com/opal-stones/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2024/02/Opel.webp" alt="Australian Opal stone with a vibrant play-of-color"><span>Opal Stone</span></a>
            </div>
            <div class="mega-menu-column">
                <a href="https://sarfarazgems.com/iolite-stone/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2024/02/Iolite-Neeli.webp" alt="Violet-blue Iolite (Neeli) gemstone"><span>Iolite (Neeli)</span></a>
                <a href="https://sarfarazgems.com/moonstone/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2024/02/Moon-Stone.webp" alt="Translucent Moonstone gemstone showing a blue adularescence"><span>Moon Stone</span></a>
                <a href="https://sarfarazgems.com/peridot-stone/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2024/02/Peridot.webp" alt="Bright olive-green Peridot gemstone"><span>Peridot</span></a>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}


// --- HTML for the Rudraksha Mega Menu ---
// ALL 16 ORIGINAL ITEMS ARE INCLUDED.
function get_rudraksha_menu_html() {
    ob_start();
    ?>
    <div class="mega-menu">
        <div class="mega-menu-grid mega-menu-grid--rudraksha">
            <div class="mega-menu-column mega-menu-column--featured">
                <a href="https://sarfarazgems.com/original-gauri-shankar-rudraksha/" class="mega-menu-item-featured">
                    <img src="https://sarfarazgems.com/wp-content/uploads/2024/09/Gouri-Shankar-Rudraksha-4.34-gms-scaled.webp" alt="Two naturally joined Gauri Shankar Rudraksha beads">
                    <h4>Gauri Shankar Rudraksha</h4>
                </a>
            </div>
            <div class="mega-menu-column">
                <a href="https://sarfarazgems.com/1-mukhi-rudraksha/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2024/09/1-Mukhi-1-Face-Rudraksha-1.50-gms-scaled.webp" alt="Certified 1 Mukhi Rudraksha bead"><span>1 Mukhi Rudraksha</span></a>
                <a href="https://sarfarazgems.com/2-mukhi-rudraksha-nepali/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2024/09/2-Mukhi-2-Face-Rudraksha-3.77-gms-scaled.webp" alt="Certified 2 Mukhi Rudraksha bead"><span>2 Mukhi Rudraksha</span></a>
                <a href="https://sarfarazgems.com/nepali-3-mukhi-rudraksha/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2024/09/3-Mukhi-3-Face-Rudraksha-2.42-gms-scaled.webp" alt="Certified 3 Mukhi Rudraksha bead"><span>3 Mukhi Rudraksha</span></a>
                <a href="https://sarfarazgems.com/4-mukhi-rudraksha-nepali/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2024/09/4-Mukhi-4-Face-Rudraksha-2.11-gms-scaled.webp" alt="Certified 4 Mukhi Rudraksha bead"><span>4 Mukhi Rudraksha</span></a>
                <a href="https://sarfarazgems.com/5-mukhi-rudraksha-nepali/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2024/09/5-Mukhi-5-Face-Rudraksha-2.54-gms-scaled.webp" alt="Certified 5 Mukhi Rudraksha bead"><span>5 Mukhi Rudraksha</span></a>
                <a href="https://sarfarazgems.com/6-mukhi-rudraksha-nepali/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2024/09/6-Mukhi-6-Face-Rudraksha-3.01-gms-scaled.webp" alt="Certified 6 Mukhi Rudraksha bead"><span>6 Mukhi Rudraksha</span></a>
                <a href="https://sarfarazgems.com/7-mukhi-rudraksha-nepali/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2024/09/7-Mukhi-7-Face-Rudraksha-2.32-gms-scaled.webp" alt="Certified 7 Mukhi Rudraksha bead"><span>7 Mukhi Rudraksha</span></a>
            </div>
            <div class="mega-menu-column">
                 <a href="https://sarfarazgems.com/8-mukhi-rudraksha-nepali/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2025/06/8-mukhi-rudraksha-nepali-closeup.webp" alt="Certified 8 Mukhi Rudraksha bead"><span>8 Mukhi Rudraksha</span></a>
                 <a href="https://sarfarazgems.com/10-mukhi-rudraksha-nepali/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2025/06/10-mukhi-rudraksha-nepali-closeup.webp" alt="Certified 10 Mukhi Rudraksha bead"><span>10 Mukhi Rudraksha</span></a>
                 <a href="https://sarfarazgems.com/11-mukhi-rudraksha-nepali/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2025/06/11-mukhi-rudraksha-nepali-closeup.webp" alt="Certified 11 Mukhi Rudraksha bead"><span>11 Mukhi Rudraksha</span></a>
                 <a href="https://sarfarazgems.com/12-mukhi-rudraksha-nepali/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2025/06/12-mukhi-rudraksha-nepali-closeup.webp" alt="Certified 12 Mukhi Rudraksha bead"><span>12 Mukhi Rudraksha</span></a>
                 <a href="https://sarfarazgems.com/13-mukhi-rudraksha-nepali/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2025/06/13-mukhi-rudraksha-nepali-front-back.webp" alt="Certified 13 Mukhi Rudraksha bead"><span>13 Mukhi Rudraksha</span></a>
                 <a href="https://sarfarazgems.com/14-mukhi-rudraksha-nepali/" class="mega-menu-item"><img src="https://sarfarazgems.com/wp-content/uploads/2025/06/14-mukhi-rudraksha-nepali-front-back.webp" alt="Certified 14 Mukhi Rudraksha bead"><span>14 Mukhi Rudraksha</span></a>
            </div>
            <div class="mega-menu-column mega-menu-column--featured">
                 <a href="https://sarfarazgems.com/natural-nepali-rudraksha-mala/" class="mega-menu-item-featured">
                    <img src="https://sarfarazgems.com/wp-content/uploads/2024/02/rUDRAKSHA-bEADS.webp" alt="Traditional Rudraksha Mala with 108 beads">
                    <h4>Rudraksha Mala</h4>
                </a>
            </div>
        </div>
    </div>
    <?php
    return ob_get_clean();
}