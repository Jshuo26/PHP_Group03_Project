document.addEventListener('DOMContentLoaded', function () {
    var modal = document.getElementById('trackingModal');
    if (!modal) return;
    
    var closeBtn = modal.querySelector('.tracking-modal-close');
    var backdrop = modal.querySelector('.tracking-modal-backdrop');
    var modalOrderNum = document.getElementById('modalOrderNumber');
    var modalBadge = document.getElementById('modalStatusBadge');
    var timelineSteps = modal.querySelectorAll('.timeline-step');

    function openTracking(btn) {
        var orderNum = btn.getAttribute('data-order');
        var status = btn.getAttribute('data-status');
        var label = btn.getAttribute('data-status-label');
        var currentStep = parseInt(btn.getAttribute('data-step'), 10) || 1;

        if (modalOrderNum) modalOrderNum.textContent = orderNum;
        if (modalBadge) {
            modalBadge.textContent = label;
            modalBadge.className = 'order-status status-' + status;
        }

        timelineSteps.forEach(function (stepEl, idx) {
            var stepNum = idx + 1;
            stepEl.classList.remove('completed', 'active');
            var marker = stepEl.querySelector('.timeline-dot span');
            if (stepNum < currentStep) {
                stepEl.classList.add('completed');
                if (marker) marker.textContent = '✓';
            } else if (stepNum === currentStep) {
                stepEl.classList.add('active');
                if (marker) marker.textContent = stepNum;
            } else {
                if (marker) marker.textContent = stepNum;
            }
        });

        modal.hidden = false;
        document.body.classList.add('modal-open');
    }

    function closeModal() {
        modal.hidden = true;
        document.body.classList.remove('modal-open');
    }

    document.querySelectorAll('.track-order-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openTracking(btn);
        });
    });

    if (closeBtn) closeBtn.addEventListener('click', closeModal);
    if (backdrop) backdrop.addEventListener('click', closeModal);

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !modal.hidden) {
            closeModal();
        }
    });
});