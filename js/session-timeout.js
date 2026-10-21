
(function () {
    const timeoutMinutes = Number(window.sessionTimeoutMinutes) || 2;
    let timeoutId;

    function resetTimer() {
        clearTimeout(timeoutId);

        timeoutId = setTimeout(function () {
            window.location.href = window.sessionTimeoutLoginUrl || '/auth/login.php?timeout=1';
        }, timeoutMinutes * 60 * 1000);
    }

    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click']
        .forEach(function (eventName) {
            document.addEventListener(eventName, resetTimer);
        });

    resetTimer();
})();
