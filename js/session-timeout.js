
(function () {
    const timeoutMinutes = Number(window.sessionTimeoutMinutes) || 2;
    const fallbackLoginUrl = window.location.pathname.indexOf('/admin/') !== -1
        ? '/admin/login.php?timeout=1'
        : '/auth/login.php?timeout=1';
    let timeoutId;

    function resetTimer() {
        clearTimeout(timeoutId);

        timeoutId = setTimeout(function () {
            window.location.href = window.sessionTimeoutLoginUrl || fallbackLoginUrl;
        }, timeoutMinutes * 60 * 1000);
    }

    ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click']
        .forEach(function (eventName) {
            document.addEventListener(eventName, resetTimer);
        });

    resetTimer();
})();
