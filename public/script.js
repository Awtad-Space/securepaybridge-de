document.addEventListener('DOMContentLoaded', function () {
    // Message handling
    const messages = document.querySelectorAll('.message');
    messages.forEach(msg => {
        // Auto-hide messages after 7 seconds
        setTimeout(() => {
            if (msg) {
                msg.style.opacity = '0';
                setTimeout(() => {
                    msg.style.display = 'none';
                }, 500);
            }
        }, 7000);

        // Close button functionality
        const closeButton = msg.querySelector('.close-message');
        if (closeButton) {
            closeButton.addEventListener('click', () => {
                msg.style.opacity = '0';
                setTimeout(() => {
                    msg.style.display = 'none';
                }, 500);
            });
        }
    });

    // License type change handler
    const licenseTypeSelect = document.getElementById('license_type');
    const expiresAtInput = document.getElementById('expires_at');
    
    if (licenseTypeSelect && expiresAtInput) {
        function toggleExpiresAt() {
            const isLifetime = (licenseTypeSelect.value === 'Lifetime');
            expiresAtInput.disabled = isLifetime;
            if (isLifetime) {
                expiresAtInput.value = '';
            }
        }
        
        licenseTypeSelect.addEventListener('change', toggleExpiresAt);
        toggleExpiresAt(); // Run on page load
    }
});