document.addEventListener('DOMContentLoaded', function () {

    const passwordInput = document.getElementById('password');

    if (!passwordInput) {
        return;
    }

    const checklist = document.createElement('ul');

    checklist.className = 'password-checklist';

    checklist.innerHTML = `
        <li id="length-rule">At least 12 characters</li>
        <li id="uppercase-rule">At least one uppercase letter</li>
        <li id="lowercase-rule">At least one lowercase letter</li>
        <li id="number-rule">At least one number</li>
        <li id="special-rule">At least one special character</li>
    `;

    passwordInput.parentNode.appendChild(checklist);

    passwordInput.addEventListener('input', function () {

        const password = passwordInput.value;

        updateRule(
            'length-rule',
            password.length >= 12
        );

        updateRule(
            'uppercase-rule',
            /[A-Z]/.test(password)
        );

        updateRule(
            'lowercase-rule',
            /[a-z]/.test(password)
        );

        updateRule(
            'number-rule',
            /[0-9]/.test(password)
        );

        updateRule(
            'special-rule',
            /[^a-zA-Z0-9]/.test(password)
        );
    });


    function updateRule(id, valid) {

        const rule = document.getElementById(id);

        if (valid) {
            rule.textContent = '✓ ' + rule.textContent.replace('✓ ', '');
        } else {
            rule.textContent = rule.textContent.replace('✓ ', '');
        }
    }

});