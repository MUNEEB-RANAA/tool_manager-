/**
 * Tool Manager - Authentication & Session Client Logic
 */

document.addEventListener('DOMContentLoaded', () => {
    const tabLogin = document.getElementById('tab-login');
    const tabRegister = document.getElementById('tab-register');
    const formLogin = document.getElementById('form-login');
    const formRegister = document.getElementById('form-register');
    const authAlert = document.getElementById('auth-alert');

    // Tab switching with active indicator
    if (tabLogin && tabRegister) {
        tabLogin.addEventListener('click', () => {
            tabLogin.classList.add('active');
            tabRegister.classList.remove('active');
            if (formLogin) formLogin.style.display = 'block';
            if (formRegister) formRegister.style.display = 'none';
            clearAlert();
        });

        tabRegister.addEventListener('click', () => {
            tabRegister.classList.add('active');
            tabLogin.classList.remove('active');
            if (formLogin) formLogin.style.display = 'none';
            if (formRegister) formRegister.style.display = 'block';
            clearAlert();
        });
    }

    function showAlert(msg, isError = true) {
        if (!authAlert) return;
        authAlert.textContent = msg;
        authAlert.style.display = 'block';
        authAlert.className = isError ? 'auth-alert alert-danger' : 'auth-alert alert-success';
    }

    function clearAlert() {
        if (authAlert) {
            authAlert.style.display = 'none';
            authAlert.textContent = '';
        }
    }

    // Login validation
    if (formLogin) {
        formLogin.addEventListener('submit', (e) => {
            const email = formLogin.querySelector('[name="email"]').value.trim();
            const password = formLogin.querySelector('[name="password"]').value;

            if (!email || !password) {
                e.preventDefault();
                showAlert('Please fill in both email and password.');
                return;
            }

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                showAlert('Please enter a valid email address.');
                return;
            }
        });
    }

    // Registration validation
    if (formRegister) {
        formRegister.addEventListener('submit', (e) => {
            const username = formRegister.querySelector('[name="username"]').value.trim();
            const email = formRegister.querySelector('[name="email"]').value.trim();
            const password = formRegister.querySelector('[name="password"]').value;
            const confirm = formRegister.querySelector('[name="password_confirm"]').value;

            if (!username || !email || !password || !confirm) {
                e.preventDefault();
                showAlert('All fields are required.');
                return;
            }

            if (username.length < 3) {
                e.preventDefault();
                showAlert('Username must be at least 3 characters.');
                return;
            }

            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(email)) {
                e.preventDefault();
                showAlert('Please enter a valid email address.');
                return;
            }

            if (password.length < 6) {
                e.preventDefault();
                showAlert('Password must be at least 6 characters.');
                return;
            }

            if (password !== confirm) {
                e.preventDefault();
                showAlert('Passwords do not match.');
                return;
            }
        });
    }

    // Quick demo login fill buttons
    window.fillDemoUser = function (email, password) {
        if (tabLogin && formLogin) {
            tabLogin.click();
            formLogin.querySelector('[name="email"]').value = email;
            formLogin.querySelector('[name="password"]').value = password;
            formLogin.querySelector('button[type="submit"]').focus();
        }
    };

    document.addEventListener('click', (e) => {
        const demoBtn = e.target.closest('.demo-pill-btn');
        if (demoBtn && demoBtn.dataset.demoEmail && demoBtn.dataset.demoPass) {
            window.fillDemoUser(demoBtn.dataset.demoEmail, demoBtn.dataset.demoPass);
        }
    });

    // Password visibility toggle
    window.togglePassword = function (inputId, btn) {
        const input = document.getElementById(inputId);
        if (!input) return;
        if (input.type === 'password') {
            input.type = 'text';
            btn.innerHTML = '👁️';
            btn.title = 'Hide password';
        } else {
            input.type = 'password';
            btn.innerHTML = '👁️‍🗨️';
            btn.title = 'Show password';
        }
    };
});
