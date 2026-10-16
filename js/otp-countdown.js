
(function () {
    const input = document.getElementById('otp_code');

    if (input) {
        input.addEventListener('input', function () {
            input.value = input.value.replace(/\D/g, '').slice(0, 6);
        });
    }

    const box = document.getElementById('otpCountdown');

    if (!box) {
        return;
    }

    const timer = document.getElementById('otpTimer');
    const button = document.getElementById('otpVerifyButton');
    const seconds = Number(box.dataset.seconds);

    if (!timer) {
        return;
    }

    if (!Number.isFinite(seconds) || seconds <= 0) {
        timer.textContent = '00:00';

        box.setAttribute('role', 'status');

        if (button) {
            button.disabled = true;
        }

        return;
    }

    const endTime = Date.now() + seconds * 1000;

    function pad(number) {
        return String(number).padStart(2, '0');
    }

    function tick() {
        const left = Math.max(
            0,
            Math.ceil((endTime - Date.now()) / 1000)
        );

        timer.textContent =
            pad(Math.floor(left / 60)) + ':' + pad(left % 60);

        if (left <= 0) {
            clearInterval(interval);

            box.innerHTML =
                'This code has expired. Click "Resend Code &amp; Link" to get a new one.';

            if (button) {
                button.disabled = true;
            }
        }
    }

    tick();

    const interval = setInterval(tick, 1000);
})();