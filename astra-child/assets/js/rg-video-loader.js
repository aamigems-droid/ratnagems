/**
 * FILE 3 of 4: assets/js/rg-video-loader.js (REPLACE ENTIRE FILE)
 *
 * Ratna Gems - Upgraded High-Performance YouTube Video Loader
 *
 * This script finds all video facades and loads the YouTube iframe on interaction.
 * Includes performance preconnects, full keyboard accessibility, and safe defaults.
 */
document.addEventListener('DOMContentLoaded', function() {
    const videoWrappers = document.querySelectorAll('.rg-video-wrapper');
    if (videoWrappers.length === 0) return;

    // Preconnect to YouTube domains to speed up video load
    function preconnectToYoutube() {
        const preconnectHints = [
            "https://www.youtube.com",
            "https://i.ytimg.com",
            "https://www.google.com",
            "https://googleads.g.doubleclick.net",
            "https://static.doubleclick.net"
        ];
        preconnectHints.forEach(function(url) {
            if (!document.querySelector(`link[rel="preconnect"][href="${url}"]`)) {
                const link = document.createElement('link');
                link.rel = 'preconnect';
                link.href = url;
                document.head.appendChild(link);
            }
        });
    }

    videoWrappers.forEach(function(wrapper) {
        const videoId = wrapper.dataset.videoId;
        if (!videoId) return;

        const facade = wrapper.querySelector('.rg-video-facade');
        if (!facade) return;

        // Set thumbnail background
        const thumbnailUrl = `https://i.ytimg.com/vi/${videoId}/hqdefault.jpg`;
        facade.style.backgroundImage = `url('${thumbnailUrl}')`;
        facade.style.backgroundSize = 'cover';
        facade.style.backgroundPosition = 'center';

        // Ensure facade is keyboard-focusable
        if (!facade.hasAttribute('tabindex')) {
            facade.setAttribute('tabindex', '0');
        }

        // Add preconnect on hover/focus
        wrapper.addEventListener('mouseover', preconnectToYoutube, { once: true });
        wrapper.addEventListener('focusin', preconnectToYoutube, { once: true });

        function loadVideo() {
            if (wrapper.querySelector('iframe')) return;

            const iframe = document.createElement('iframe');
            iframe.setAttribute(
                'src',
                `https://www.youtube.com/embed/${videoId}?autoplay=1&rel=0&modestbranding=1&playsinline=1`
            );
            iframe.setAttribute('title', 'Product Video');
            iframe.setAttribute('frameborder', '0');
            iframe.setAttribute(
                'allow',
                'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share'
            );
            iframe.setAttribute('allowfullscreen', '');
            iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin');

            wrapper.innerHTML = '';
            wrapper.appendChild(iframe);
            wrapper.classList.add('video-activated');
        }

        function activateVideo(event) {
            if (
                event.type === 'click' ||
                (event.type === 'keydown' && (event.key === 'Enter' || event.key === ' '))
            ) {
                event.preventDefault();
                loadVideo();
                wrapper.removeEventListener('click', activateVideo);
                wrapper.removeEventListener('keydown', activateVideo);
            }
        }

        wrapper.addEventListener('click', activateVideo);
        wrapper.addEventListener('keydown', activateVideo);
    });
});
