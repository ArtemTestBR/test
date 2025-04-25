<?php
session_start();
if (!isset($_SESSION['support_authenticated']) || !$_SESSION['support_authenticated']) {
    header('Location: login.php');
    exit;
}
$tickets = json_decode(file_get_contents('../../Config/tickets.json'), true) ?: [];
$currentUsername = $_SESSION['support_username'] ?? 'Support'; // Получаем логин из сессии

// Получаем текущую вкладку и выбранный тикет из URL
$currentTab = $_GET['tab'] ?? 'all';
$selectedTicketId = $_GET['ticket_id'] ?? null;
$selected_ticket = null;

if ($selectedTicketId) {
    foreach ($tickets as $user_tickets) {
        foreach ($user_tickets as $ticket) {
            if ($ticket['id'] === $selectedTicketId) {
                $selected_ticket = $ticket;
                break 2;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Система поддержки</title>
    <link rel="stylesheet" href="/Assets/style.css">
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>Тикеты</h2>
            <button onclick="logout()">Выйти</button>
        </div>
        <div class="sidebar-tabs">
            <div class="tab <?php echo $currentTab === 'all' ? 'active' : ''; ?>" onclick="switchTab('all')">Все тикеты</div>
            <div class="tab <?php echo $currentTab === 'my' ? 'active' : ''; ?>" onclick="switchTab('my')">Мои тикеты</div>
        </div>
        <div class="ticket-list" id="ticketList">
            <!-- Тикеты будут добавлены через JavaScript -->
        </div>
    </div>
    
    <div class="content">
        <div class="content-header">
            <h3><?php echo $selected_ticket ? htmlspecialchars($selected_ticket['topic']) : 'Выберите тикет'; ?></h3>
            <div>
                <span style="margin-right: 15px; font-size: 14px; color: #a3a3a3;">
                    Статус: <span id="ticketStatus"><?php echo $selected_ticket ? htmlspecialchars($selected_ticket['status']) : 'N/A'; ?></span>
                </span>
                <?php if ($selected_ticket && $selected_ticket['status'] === 'Открыт'): ?>
                    <button onclick="closeTicket('<?php echo htmlspecialchars($selected_ticket['id']); ?>')">Закрыть тикет</button>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="ticket-info" id="ticketInfo">
            <div style="display: flex; flex-direction: column;">
            <?php if ($selected_ticket): ?>
                    <div class="message message-incoming" data-timestamp="<?php echo htmlspecialchars($selected_ticket['timestamp']); ?>">
                        <img src="<?php echo htmlspecialchars($selected_ticket['profile_photo_url'] ?? '/Assets/default_avatar.png'); ?>" class="message-avatar" alt="Avatar">
                        <div class="message-content">
                            <div class="message-sender">
                                <a href="<?php echo $selected_ticket['telegram_username'] ? 'https://t.me/' . htmlspecialchars($selected_ticket['telegram_username']) : 'https://t.me/+' . htmlspecialchars($selected_ticket['telegram_user_id']); ?>" target="_blank">
                                    <?php echo $selected_ticket['telegram_username'] ? htmlspecialchars($selected_ticket['telegram_username']) : htmlspecialchars($selected_ticket['telegram_user_id']); ?>
                                </a>
                            </div>
                            <div class="message-text"><?php echo htmlspecialchars($selected_ticket['description']); ?></div>
                            <div class="message-time"><?php echo date('H:i', strtotime($selected_ticket['timestamp'])); ?></div>
                        </div>
                    </div>
                    <?php foreach ($selected_ticket['replies'] ?? [] as $reply): ?>
                        <div class="message <?php echo $reply['sender'] === 'admin' ? 'message-outgoing' : 'message-incoming'; ?>" data-timestamp="<?php echo htmlspecialchars($reply['timestamp']); ?>">
                            <?php if ($reply['sender'] !== 'admin'): ?>
                                <img src="<?php echo htmlspecialchars($selected_ticket['profile_photo_url'] ?? '/Assets/default_avatar.png'); ?>" class="message-avatar" alt="Avatar">
                            <?php endif; ?>
                            <div class="message-content">
                                <div class="message-sender">
                                    <?php if ($reply['sender'] === 'admin'): ?>
                                        <?php echo htmlspecialchars($currentUsername); ?>
                                    <?php else: ?>
                                        <a href="<?php echo $selected_ticket['telegram_username'] ? 'https://t.me/' . htmlspecialchars($selected_ticket['telegram_username']) : 'https://t.me/+' . htmlspecialchars($selected_ticket['telegram_user_id']); ?>" target="_blank">
                                            <?php echo $selected_ticket['telegram_username'] ? htmlspecialchars($selected_ticket['telegram_username']) : htmlspecialchars($selected_ticket['telegram_user_id']); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <div class="message-text"><?php echo htmlspecialchars($reply['text']); ?></div>
                                <div class="message-time"><?php echo date('H:i', strtotime($reply['timestamp'])); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="input-area <?php echo (!$selected_ticket || $selected_ticket['status'] === 'Закрыт') ? 'hidden' : ''; ?>">
            <textarea class="message-input" id="replyInput" placeholder="Напишите сообщение..."></textarea>
            <button onclick="submitReply('<?php echo $selectedTicketId ?? ''; ?>')">Отправить</button>
        </div>
    </div>

    <script>
        let currentTab = '<?php echo $currentTab; ?>';
        const existingTicketIds = new Set();
        const currentUsername = '<?php echo $currentUsername; ?>';

        // Добавление тикета в список
        function addTicketToList(ticket) {
            if (existingTicketIds.has(ticket.id)) return;
            existingTicketIds.add(ticket.id);

            const ticketList = document.getElementById('ticketList');
            const ticketItem = document.createElement('div');
            ticketItem.classList.add('ticket-item');
            ticketItem.dataset.id = ticket.id;
            ticketItem.innerHTML = `
                <div class="ticket-title">${ticket.topic}</div>
                <div class="ticket-preview">${ticket.description.substring(0, 50)}...</div>
            `;
            ticketItem.onclick = function() {
                window.location.href = `?tab=${currentTab}&ticket_id=${encodeURIComponent(this.dataset.id)}`;
            };
            if (ticket.id === '<?php echo $selectedTicketId ?? ''; ?>') {
                ticketItem.classList.add('active');
            }
            ticketList.appendChild(ticketItem);
        }

        function addReplyToChat(reply, sender, ticketData = null) {
    const ticketInfo = document.querySelector('.ticket-info > div');
    if (!ticketInfo) return;
    
    // Проверяем, не существует ли уже это сообщение
    const existingMessage = document.querySelector(`.message[data-timestamp="${reply.timestamp}"]`);
    if (existingMessage) return;
    
    const messageDiv = document.createElement('div');
    messageDiv.classList.add('message', sender === 'admin' ? 'message-outgoing' : 'message-incoming');
    messageDiv.dataset.timestamp = reply.timestamp;
    
    let senderName = sender === 'admin' ? currentUsername : (ticketData?.telegram_username || ticketData?.telegram_user_id || 'Пользователь');
    let avatarUrl = sender === 'admin' ? null : (ticketData?.profile_photo_url || '/Assets/default_avatar.png');
    
    messageDiv.innerHTML = `
        ${sender !== 'admin' ? `<img src="${avatarUrl}" class="message-avatar" alt="Avatar">` : ''}
        <div class="message-content">
            <div class="message-sender">${senderName}</div>
            <div class="message-text">${reply.text}</div>
            <div class="message-time">${new Date(reply.timestamp).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}</div>
        </div>
    `;
    
    ticketInfo.appendChild(messageDiv);
    messageDiv.scrollIntoView({ behavior: 'smooth' });
}

        // Отправка ответа
        async function submitReply(ticketId) {
            if (!ticketId) return alert('Выберите тикет');
            const replyText = document.getElementById('replyInput').value.trim();
            if (!replyText) return alert('Введите сообщение');

            try {
                const response = await fetch('send_reply.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ticket_id: ticketId, telegram_user_id: '<?php echo $selected_ticket['telegram_user_id'] ?? ''; ?>', reply_text: replyText })
                });
                const result = await response.json();
                if (response.ok && result.success) {
                    addReplyToChat(result.reply, 'admin');
                    document.getElementById('replyInput').value = '';
                    pollTickets(); // Обновляем список после ответа
                } else {
                    alert('Ошибка: ' + (result.error || 'Неизвестная ошибка'));
                }
            } catch (e) {
                console.error('Ошибка:', e);
                alert('Ошибка при отправке');
            }
        }

        // Закрытие тикета
        async function closeTicket(ticketId) {
            try {
                const response = await fetch('close_ticket.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ ticket_id: ticketId })
                });
                const result = await response.json();
                if (response.ok && result.success) {
                    document.getElementById('ticketStatus').textContent = 'Закрыт';
                    document.querySelector('.input-area').classList.add('hidden');
                    document.querySelector('.content-header button')?.remove();
                    pollTickets();
                } else {
                    alert('Ошибка: ' + (result.error || 'Неизвестная ошибка'));
                }
            } catch (e) {
                console.error('Ошибка:', e);
                alert('Ошибка при закрытии');
            }
        }

        // Обновление списка тикетов
        async function pollTickets() {
    try {
        const response = await fetch(`get_tickets.php?tab=${currentTab}`);
        const tickets = await response.json();
        const ticketList = document.getElementById('ticketList');
        ticketList.innerHTML = '';
        existingTicketIds.clear();

        tickets.forEach(userTickets => {
            userTickets.forEach(ticket => addTicketToList(ticket));
        });

        // Проверка обновлений в открытом тикете
        const selectedTicketId = '<?php echo $selectedTicketId ?? ""; ?>';
        if (selectedTicketId) {
            checkForTicketUpdates(selectedTicketId);
        }
    } catch (e) {
        console.error('Ошибка загрузки тикетов:', e);
    }
}

async function checkForTicketUpdates(ticketId) {
    try {
        const response = await fetch(`get_ticket_updates.php?ticket_id=${ticketId}`);
        const updates = await response.json();
        if (updates.replies && updates.ticketData) {
            const existingReplies = document.querySelectorAll('.ticket-info .message');
            const lastTimestamp = existingReplies.length > 0 ? 
                existingReplies[existingReplies.length - 1].dataset.timestamp : null;
            
            updates.replies.forEach(reply => {
                if (!lastTimestamp || new Date(reply.timestamp) > new Date(lastTimestamp)) {
                    // Передаем полные данные о тикете вместе с ответом
                    addReplyToChat(reply, reply.sender === 'admin' ? 'admin' : 'user', updates.ticketData);
                }
            });
        }
    } catch (e) {
        console.error('Ошибка проверки обновлений:', e);
    }
}
        setInterval(pollTickets, 2000); // Обновление каждые 5 секунд
        pollTickets(); // Начальная загрузка

        // Переключение вкладок
        function switchTab(tab) {
            currentTab = tab;
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelector(`.tab[onclick="switchTab('${tab}')"]`).classList.add('active');
            const url = new URL(window.location);
            url.searchParams.set('tab', tab);
            window.history.pushState({}, '', url);
            pollTickets();
        }


        // Выход из системы
        async function logout() {
            try {
                const response = await fetch('logout.php', { method: 'POST' });
                if (response.ok) window.location.href = 'login.php';
                else alert('Ошибка при выходе');
            } catch (e) {
                console.error('Ошибка:', e);
                alert('Ошибка при выходе');
            }
        }
    </script>
</body>
</html>