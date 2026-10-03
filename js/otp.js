document.addEventListener('DOMContentLoaded', function () {

    const otpInput = document.getElementById('otp');
    const countdown = document.getElementById('otp-countdown');

    if (!otpInput || !countdown) {
        return;
    }

    let timeLeft = 60;

    const timer = setInterval(function () {

        countdown.textContent = `Resend OTP in ${timeLeft} seconds`;

        timeLeft--;

        if (timeLeft < 0) {
            clearInterval(timer);
            countdown.textContent = 'You can request a new OTP.';
        }

    }, 1000);

});