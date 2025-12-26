(function() {
    document.addEventListener('DOMContentLoaded', function() {
        var whatsappButton = document.getElementById('sg-whatsapp-link');
        if (!whatsappButton) return;

        whatsappButton.addEventListener('click', function(event) {
            event.preventDefault();

            // WhatsApp phone number in international format (no + or spaces)
            var phoneNumber = '919978038886';

            // Pre-filled message (fully encoded)
            var message = encodeURIComponent(
                'Hello! I am visiting your website (' + location.href + ') and have a question.'
            );

            // Construct WhatsApp link
            var whatsappUrl = 'https://wa.me/' + phoneNumber + '?text=' + message;

            // Open in new tab
            window.open(whatsappUrl, '_blank', 'noopener,noreferrer');
        });
    });
})();
