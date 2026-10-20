document.addEventListener('DOMContentLoaded', function () {
    var collapsibleCards = document.querySelectorAll('.collapsible-card');

    collapsibleCards.forEach(function (card) {
        var header = card.querySelector('.collapsible-header');
        var body = card.querySelector('.collapsible-body');
        var btnText = card.querySelector('.collapse-btn-text');

        if (!header || !body) return;

        header.addEventListener('click', function (e) {
            // Prevent toggling if user clicks directly on a link or button inside header
            if (e.target.closest('a') || (e.target.closest('button') && !e.target.closest('.collapse-toggle-btn'))) {
                return;
            }

            var isOpen = card.classList.contains('is-open');

            if (isOpen) {
                card.classList.remove('is-open');
                body.hidden = true;
                if (btnText) btnText.textContent = btnText.getAttribute('data-edit-text') || 'Edit';
            } else {
                card.classList.add('is-open');
                body.hidden = false;
                if (btnText) btnText.textContent = 'Cancel';

                // Automatically focus the first input inside
                var input = body.querySelector('input:not([type="hidden"]), select, textarea');
                if (input) input.focus();
            }
        });
    });
});