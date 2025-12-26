/**
 * Sarfaraz Gems - High-Performance YouTube Video Loader
 */
document.addEventListener('DOMContentLoaded', function() {
    const videoWrappers = document.querySelectorAll('.sg-video-wrapper');
    if (!videoWrappers.length) return;

    function preconnectToYoutube() {
        ["https://www.youtube.com", "https://i.ytimg.com"].forEach(url => {
            if (!document.querySelector(`link[href="${url}"]`)) {
                const link = document.createElement('link');
                link.rel = 'preconnect';
                link.href = url;
                document.head.appendChild(link);
            }
        });
    }

    videoWrappers.forEach(wrapper => {
        const videoId = wrapper.dataset.videoId;
        if (!videoId) return;

        const facade = wrapper.querySelector('.sg-video-facade');
        if (!facade) return;

        // Accessible wrapper
        wrapper.setAttribute('tabindex', '0');
        wrapper.setAttribute('role', 'button');
        wrapper.setAttribute('aria-label', 'Play product video');

        // Thumbnail
        const thumbnailUrl = `https://i.ytimg.com/vi/${videoId}/hqdefault.jpg`;
        facade.style.backgroundImage = `url('${thumbnailUrl}')`;

        // Preconnect on hover/focus
        wrapper.addEventListener('mouseover', preconnectToYoutube, { once: true });
        wrapper.addEventListener('focus', preconnectToYoutube, { once: true });

        // Load actual iframe
        function loadVideo() {
            if (wrapper.querySelector('iframe')) return;

            const iframe = document.createElement('iframe');
            iframe.src = `https://www.youtube.com/embed/${videoId}?autoplay=1&rel=0&modestbranding=1&playsinline=1`;
            iframe.title = 'Product Video';
            iframe.frameBorder = '0';
            iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
            iframe.allowFullscreen = true;
            iframe.loading = 'lazy';

            wrapper.innerHTML = '';
            wrapper.appendChild(iframe);
            wrapper.classList.add('video-activated');
        }

        // Activate on click or keyboard
        function activateVideo(event) {
            if (event.type === 'click' || (event.type === 'keydown' && (event.key === 'Enter' || event.key === ' '))) {
                event.preventDefault();
                loadVideo();
            }
        }

        wrapper.addEventListener('click', activateVideo, { once: true });
        wrapper.addEventListener('keydown', activateVideo, { once: true });
    });
});
