<?php
session_start();
if (!isset($_SESSION['support_authenticated']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}
$currentTab = $_GET['tab'] ?? 'questionnaires';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Админ-панель</title>
    <style>
        .admin-container { display: flex; height: 100vh; }
        .sidebar { width: 250px; background: #2b2d31; color: #e1e1e1; }
        .sidebar-header { padding: 20px; border-bottom: 1px solid #404249; }
        .sidebar-tabs .tab { padding: 15px; cursor: pointer; }
        .sidebar-tabs .tab.active { background: #404249; }
        .content { flex: 1; padding: 20px; background: #f5f5f5; }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; }
        .form-group input, .form-group textarea, .form-group select { width: 100%; padding: 10px; }
        .form-group button { padding: 10px 20px; background: #5d8bf4; color: white; border: none; cursor: pointer; }
        .list-item { padding: 10px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; }
        .answer-item { margin: 10px 0; padding: 10px; background: #fff; border: 1px solid #ddd; }
        .answer-item p { margin: 5px 0; }
    </style>
</head>
<body>
    <div class="admin-container">
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>Админ-панель</h2>
                <button onclick="logout()">Выйти</button>
            </div>
            <div class="sidebar-tabs">
                <div class="tab <?php echo $currentTab === 'questionnaires' ? 'active' : ''; ?>" onclick="switchTab('questionnaires')">Анкеты</div>
                <div class="tab <?php echo $currentTab === 'answers' ? 'active' : ''; ?>" onclick="switchTab('answers')">Ответы на анкеты</div>
                <div class="tab <?php echo $currentTab === 'accounts' ? 'active' : ''; ?>" onclick="switchTab('accounts')">Аккаунты</div>
                <div class="tab <?php echo $currentTab === 'channels' ? 'active' : ''; ?>" onclick="switchTab('channels')">Каналы</div>
                <div class="tab <?php echo $currentTab === 'bots' ? 'active' : ''; ?>" onclick="switchTab('bots')">Боты</div>
            </div>
        </div>
        <div class="content">
            <h2><?php echo htmlspecialchars(ucfirst($currentTab === 'questionnaires' ? 'Анкеты' : ($currentTab === 'answers' ? 'Ответы на анкеты' : ($currentTab === 'accounts' ? 'Аккаунты' : $currentTab)))); ?></h2>
            <?php if ($currentTab === 'questionnaires'): ?>
                <div class="form-group">
                    <label>Вопросы анкеты (каждый с новой строки)</label>
                    <textarea id="questionnaireQuestions" placeholder="Вопрос 1\nВопрос 2\nВопрос 3"></textarea>
                    <label>Бот</label>
                    <select id="botToken">
                        <option value="">Выберите бота</option>
                    </select>
                    <button onclick="createQuestionnaire()">Создать анкету</button>
                </div>
                <div id="questionnaireList"></div>
            <?php elseif ($currentTab === 'answers'): ?>
                <div id="answersList"></div>
            <?php elseif ($currentTab === 'accounts'): ?>
                <div class="form-group">
                    <label>Имя пользователя</label>
                    <input type="text" id="username" placeholder="Введите имя пользователя">
                    <label>Пароль</label>
                    <input type="password" id="password" placeholder="Введите пароль">
                    <label>Роль</label>
                    <select id="role">
                        <option value="support">Агент Поддержки</option>
                        <option value="admin">Админ</option>
                    </select>
                    <button onclick="addAccount()">Добавить аккаунт</button>
                </div>
                <div id="accountList"></div>
            <?php elseif ($currentTab === 'channels'): ?>
                <div class="form-group">
                    <label>ID канала (например, @ChannelName)</label>
                    <input type="text" id="channelId" placeholder="Введите ID канала">
                    <button onclick="addChannel()">Добавить канал</button>
                </div>
                <div id="channelList"></div>
            <?php elseif ($currentTab === 'bots'): ?>
                <div class="form-group">
                    <label>Имя бота</label>
                    <input type="text" id="botName" placeholder="Введите имя бота">
                    <label>Токен бота</label>
                    <input type="text" id="botToken" placeholder="Введите токен бота">
                    <button onclick="addBot()">Добавить бота</button>
                </div>
                <div id="botList"></div>
            <?php endif; ?>
        </div>
    </div>
    <script>
        async function loadBotsForQuestionnaire() {
            try {
                const response = await fetch('manage_bots.php?action=list');
                const bots = await response.json();
                const botSelect = document.getElementById('botToken');
                botSelect.innerHTML = '<option value="">Выберите бота</option>';
                bots.forEach(bot => {
                    const option = document.createElement('option');
                    option.value = bot.token;
                    option.textContent = bot.name;
                    botSelect.appendChild(option);
                });
            } catch (e) {
                console.error('Ошибка загрузки ботов:', e);
            }
        }

        function switchTab(tab) {
            window.location.href = `admin.php?tab=${tab}`;
        }

        async function createQuestionnaire() {
            const questions = document.getElementById('questionnaireQuestions').value.split('\n').map(q => q.trim()).filter(q => q);
            const botToken = document.getElementById('botToken').value;
            if (questions.length < 1) return alert('Введите минимум один вопрос');
            if (!botToken) return alert('Выберите бота для анкеты');
            try {
                const response = await fetch('manage_questionnaires.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'create', questions, bot_token: botToken })
                });
                const result = await response.json();
                if (response.ok && result.success) {
                    alert('Анкета создана');
                    loadQuestionnaires();
                    document.getElementById('questionnaireQuestions').value = '';
                    document.getElementById('botToken').value = '';
                } else {
                    alert('Ошибка: ' + (result.error || 'Неизвестная ошибка'));
                }
            } catch (e) {
                alert('Ошибка при создании анкеты');
            }
        }

        async function loadQuestionnaires() {
            try {
                const response = await fetch('manage_questionnaires.php?action=list');
                const questionnaires = await response.json();
                const questionnaireList = document.getElementById('questionnaireList');
                questionnaireList.innerHTML = '';
                questionnaires.forEach(questionnaire => {
                    const item = document.createElement('div');
                    item.className = 'list-item';
                    const botInfo = questionnaire.bot_name ? ` (Бот: ${questionnaire.bot_name})` : ' (Без бота)';
                    item.innerHTML = `${questionnaire.questions[0].substring(0, 50)}...${botInfo} <button onclick="deleteQuestionnaire('${questionnaire.id}')">Удалить</button>`;
                    questionnaireList.appendChild(item);
                });
            } catch (e) {
                console.error('Ошибка загрузки анкет:', e);
            }
        }

        async function deleteQuestionnaire(id) {
            try {
                const response = await fetch('manage_questionnaires.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', id })
                });
                const result = await response.json();
                if (response.ok && result.success) {
                    loadQuestionnaires();
                } else {
                    alert('Ошибка: ' + (result.error || 'Неизвестная ошибка'));
                }
            } catch (e) {
                alert('Ошибка при удалении анкеты');
            }
        }

        async function loadAnswers() {
            try {
                const response = await fetch('manage_questionnaires.php?action=list_answers');
                const answers = await response.json();
                const answersList = document.getElementById('answersList');
                answersList.innerHTML = '';
                answers.forEach(answer => {
                    const item = document.createElement('div');
                    item.className = 'answer-item';
                    const botInfo = answer.bot_name ? ` (Бот: ${answer.bot_name})` : '';
                    const questionAnswerList = answer.questions.length > 0 && answer.answers.length > 0
                        ? answer.questions.map((q, i) => `<li>${q}: ${answer.answers[i] || 'Нет ответа'}</li>`).join('')
                        : '<li>Нет данных по вопросам или ответам</li>';
                    item.innerHTML = `
                        <p><strong>Пользователь:</strong> ${answer.user_id}${botInfo}</p>
                        <p><strong>Время:</strong> ${answer.timestamp}</p>
                        <p><strong>Вопросы и ответы:</strong></p>
                        <ul>${questionAnswerList}</ul>
                    `;
                    answersList.appendChild(item);
                });
            } catch (e) {
                console.error('Ошибка загрузки ответов:', e);
            }
        }

        async function addAccount() {
            const username = document.getElementById('username').value;
            const password = document.getElementById('password').value;
            const role = document.getElementById('role').value;
            if (!username || !password) return alert('Введите имя пользователя и пароль');
            try {
                const response = await fetch('manage_users.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'add', username, password, role })
                });
                const result = await response.json();
                if (response.ok && result.success) {
                    alert('Аккаунт добавлен');
                    loadAccounts();
                    document.getElementById('username').value = '';
                    document.getElementById('password').value = '';
                    document.getElementById('role').value = 'support';
                } else {
                    alert('Ошибка: ' + (result.error || 'Неизвестная ошибка'));
                }
            } catch (e) {
                alert('Ошибка при добавлении аккаунта');
            }
        }

        async function loadAccounts() {
            try {
                const response = await fetch('manage_users.php?action=list');
                const accounts = await response.json();
                const accountList = document.getElementById('accountList');
                accountList.innerHTML = '';
                accounts.forEach(account => {
                    const item = document.createElement('div');
                    item.className = 'list-item';
                    item.innerHTML = `${account.username} (${account.role === 'admin' ? 'Админ' : 'Агент Поддержки'}) <button onclick="deleteAccount('${account.username}')">Удалить</button>`;
                    accountList.appendChild(item);
                });
            } catch (e) {
                console.error('Ошибка загрузки аккаунтов:', e);
            }
        }

        async function deleteAccount(username) {
            try {
                const response = await fetch('manage_users.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', username })
                });
                const result = await response.json();
                if (response.ok && result.success) {
                    loadAccounts();
                } else {
                    alert('Ошибка: ' + (result.error || 'Неизвестная ошибка'));
                }
            } catch (e) {
                alert('Ошибка при удалении аккаунта');
            }
        }

        async function addChannel() {
            const channelId = document.getElementById('channelId').value;
            if (!channelId || !channelId.startsWith('@')) return alert('Введите корректный ID канала (начинается с @)');
            try {
                const response = await fetch('manage_channels.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'add', channelId })
                });
                const result = await response.json();
                if (response.ok && result.success) {
                    alert('Канал добавлен');
                    loadChannels();
                    document.getElementById('channelId').value = '';
                } else {
                    alert('Ошибка: ' + (result.error || 'Неизвестная ошибка'));
                }
            } catch (e) {
                alert('Ошибка при добавлении канала');
            }
        }

        async function loadChannels() {
            try {
                const response = await fetch('manage_channels.php?action=list');
                const channels = await response.json();
                const channelList = document.getElementById('channelList');
                channelList.innerHTML = '';
                channels.forEach(channel => {
                    const item = document.createElement('div');
                    item.className = 'list-item';
                    item.innerHTML = `${channel} <button onclick="deleteChannel('${channel}')">Удалить</button>`;
                    channelList.appendChild(item);
                });
            } catch (e) {
                console.error('Ошибка загрузки каналов:', e);
            }
        }

        async function deleteChannel(channelId) {
            try {
                const response = await fetch('manage_channels.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', channelId })
                });
                const result = await response.json();
                if (response.ok && result.success) {
                    loadChannels();
                } else {
                    alert('Ошибка: ' + (result.error || 'Неизвестная ошибка'));
                }
            } catch (e) {
                alert('Ошибка при удалении канала');
            }
        }

        async function addBot() {
            const botName = document.getElementById('botName').value;
            const botToken = document.getElementById('botToken').value;
            if (!botName || !botToken) return alert('Введите имя и токен бота');
            try {
                const response = await fetch('manage_bots.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'add', botToken, botName })
                });
                const result = await response.json();
                if (response.ok && result.success) {
                    alert('Бот добавлен');
                    loadBots();
                    document.getElementById('botName').value = '';
                    document.getElementById('botToken').value = '';
                } else {
                    alert('Ошибка: ' + (result.error || 'Неизвестная ошибка'));
                }
            } catch (e) {
                alert('Ошибка при добавлении бота');
            }
        }

        async function loadBots() {
            try {
                const response = await fetch('manage_bots.php?action=list');
                const bots = await response.json();
                const botList = document.getElementById('botList');
                botList.innerHTML = '';
                bots.forEach(bot => {
                    const item = document.createElement('div');
                    item.className = 'list-item';
                    item.innerHTML = `${bot.name} (${bot.token.substring(0, 10)}...) <button onclick="deleteBot('${bot.token}')">Удалить</button>`;
                    botList.appendChild(item);
                });
            } catch (e) {
                console.error('Ошибка загрузки ботов:', e);
            }
        }

        async function deleteBot(botToken) {
            try {
                const response = await fetch('manage_bots.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'delete', botToken })
                });
                const result = await response.json();
                if (response.ok && result.success) {
                    loadBots();
                } else {
                    alert('Ошибка: ' + (result.error || 'Неизвестная ошибка'));
                }
            } catch (e) {
                alert('Ошибка при удалении бота');
            }
        }

        async function logout() {
            try {
                const response = await fetch('../logout.php', { method: 'POST' });
                if (response.ok) window.location.href = '../login.php';
                else alert('Ошибка при выходе');
            } catch (e) {
                alert('Ошибка при выходе');
            }
        }

        // Initial load
        if (document.getElementById('questionnaireList')) {
            loadQuestionnaires();
            loadBotsForQuestionnaire();
        }
        if (document.getElementById('answersList')) loadAnswers();
        if (document.getElementById('accountList')) loadAccounts();
        if (document.getElementById('channelList')) loadChannels();
        if (document.getElementById('botList')) loadBots();
    </script>
</body>
</html>