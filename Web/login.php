<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход</title>
    <style>
        .login-container {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            background-color: #f5f5f5;
        }
        .login-form {
            background-color: #2b2d31;
            padding: 20px;
            border-radius: 5px;
            color: #e1e1e1;
        }
        .login-form input {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            background-color: #404249;
            border: none;
            border-radius: 5px;
            color: #e1e1e1;
        }
        .login-form button {
            width: 100%;
            padding: 10px;
            background-color: #5d8bf4;
            border: none;
            border-radius: 5px;
            color: white;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-form">
            <h2>Вход для поддержки</h2>
            <input type="text" id="username" placeholder="Имя пользователя">
            <input type="password" id="password" placeholder="Пароль">
            <button onclick="login()">Войти</button>
        </div>
    </div>
    <script>
        async function login() {
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;

            if (!username || !password) {
                alert('Пожалуйста, введите имя пользователя и пароль');
                return;
            }

            try {
                const button = document.querySelector('button');
                button.disabled = true;
                button.textContent = 'Вход...';

                const response = await fetch('auth.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ username, password })
                });

                const result = await response.json();
                if (response.ok && result.success) {
                    if (result.role === 'admin') {
                        window.location.href = 'Admin/admin.php';
                    } else if (result.role === 'support') {
                        window.location.href = 'Supports/index.php';
                    } else {
                        alert('Ошибка: неизвестная роль пользователя');
                    }
                } else {
                    alert('Ошибка: ' + (result.error || 'Неверные данные'));
                }
            } catch (e) {
                console.error('Login error:', e);
                alert('Ошибка при входе: ' + e.message);
            } finally {
                const button = document.querySelector('button');
                button.disabled = false;
                button.textContent = 'Войти';
            }
        }
    </script>
</body>
</html>