document.addEventListener('DOMContentLoaded', function () {
    const passwordInput = document.getElementById('password');
    const confirmInput = document.getElementById('confirm_password');
    const profileInput = document.getElementById('profile_picture');
    const preview = document.getElementById('profilePicturePreview');

    const mobileInput = document.querySelector('[name="mobile_number"]');

    if (mobileInput) {
        mobileInput.addEventListener('input', function () {
            const mobileNumber = this.value.trim();
            const mobilePattern = /^09[0-9]{9}$/;

            if (mobileNumber === '') {
                this.setCustomValidity('');
            } else if (!mobilePattern.test(mobileNumber)) {
                this.setCustomValidity(
                    'Contact number must be 11 digits and start with 09.'
                );
            } else {
                this.setCustomValidity('');
            }
        });
    }

    if (passwordInput) {
        const checklist = document.getElementById('passwordChecklist');
        const counters = {
            length:    value => value.length,
            uppercase: value => (value.match(/[A-Z]/g) || []).length,
            lowercase: value => (value.match(/[a-z]/g) || []).length,
            number:    value => (value.match(/[0-9]/g) || []).length,
            special:   value => (value.match(/[^a-zA-Z0-9]/g) || []).length
        };

        function updatePasswordRules() {
            if (!checklist) return;
            const value = passwordInput.value;
            checklist.querySelectorAll('li[data-rule]').forEach(item => {
                const count = counters[item.dataset.rule];
                if (!count) return;
                item.classList.toggle('is-valid', count(value) >= parseInt(item.dataset.min, 10));
            });
        }

        passwordInput.addEventListener('input', updatePasswordRules);
        updatePasswordRules();
    }

        const nameInputs = [
        document.querySelector('[name="first_name"]'),
        document.querySelector('[name="last_name"]')
    ];


    const namePattern = /^[\p{L}\p{M}]+(?:[ '\u2019-][\p{L}\p{M}]+)*$/u;

    nameInputs.forEach(input => {
        if (!input) return;

        input.addEventListener('input', function () {
            const value = this.value.trim();

            if (value === '') {
                this.setCustomValidity('This field is required.');
            } else if (!namePattern.test(value)) {
                this.setCustomValidity(
                    'Please enter a valid name using letters, spaces, hyphens, or apostrophes.'
                );
            } else {
                this.setCustomValidity('');
            }
        });
    });

    document.querySelectorAll('.password-toggle[data-target]').forEach(button => {
        const input = document.getElementById(button.dataset.target);
        if (!input) return;

        button.addEventListener('click', function () {
            const showPassword = input.type === 'password';
            input.type = showPassword ? 'text' : 'password';
            button.classList.toggle('is-visible', showPassword);
            button.setAttribute('aria-label', showPassword ? 'Hide password' : 'Show password');
            button.setAttribute('aria-pressed', showPassword ? 'true' : 'false');
        });
    });

    if (profileInput && preview) {
        profileInput.addEventListener('change', function () {
            const file = profileInput.files && profileInput.files[0];
            if (!file) {
                preview.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c.5-3.5 3.2-5.5 7-5.5s6.5 2 7 5.5" stroke-linecap="round"/></svg>';
                return;
            }
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) {
                profileInput.value = '';
                return;
            }
            const reader = new FileReader();
            reader.onload = function (event) {
                preview.innerHTML = '';
                const image = document.createElement('img');
                image.src = event.target.result;
                image.alt = 'Selected profile picture preview';
                preview.appendChild(image);
            };
            reader.readAsDataURL(file);
        });
    }
});