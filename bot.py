import json
import os
import asyncio
import logging
import portalocker
import aiohttp
import hashlib
import sqlite3
from datetime import datetime
from PIL import Image, ImageDraw, ImageFont
from aiogram import Bot, Dispatcher, types
from aiogram.contrib.fsm_storage.memory import MemoryStorage
from aiogram.dispatcher import FSMContext
from aiogram.dispatcher.filters.state import State, StatesGroup
from aiogram.types import ReplyKeyboardMarkup, KeyboardButton, InlineKeyboardMarkup, InlineKeyboardButton
from aiohttp import web

# Configure logging
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# Initialize bots
bots = []
dispatchers = []
TICKETS_FILE = 'Config/tickets.json'
QUESTIONNAIRES_FILE = 'Config/questionnaires.json'
CHANNELS_FILE = 'Config/channels.json'
BOTS_FILE = 'Config/bots.json'
AVATARS_DIR = 'Assets/avatars'
DB_FILE = 'web/users.db'

# Load bot tokens and names
def load_bots():
    if not os.path.exists(BOTS_FILE):
        logger.info("bots.json does not exist, initializing empty file")
        with open(BOTS_FILE, 'w', encoding='utf-8') as f:
            json.dump([], f, ensure_ascii=False, indent=2)
        return []
    try:
        with open(BOTS_FILE, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception as e:
        logger.error(f"Error reading bots.json: {e}")
        return []

# Initialize all bots
async def initialize_bots():
    bot_configs = load_bots()
    for config in bot_configs:
        try:
            bot = Bot(token=config['token'])
            storage = MemoryStorage()
            dp = Dispatcher(bot, storage=storage)
            register_handlers(dp, config['token'])
            bots.append(bot)
            dispatchers.append(dp)
            logger.info(f"Initialized bot {config['name']} with token ending in {config['token'][-4:]}")
        except Exception as e:
            logger.error(f"Failed to initialize bot {config['name']} with token ending in {config['token'][-4:]}: {e}")

# Save questionnaire answers to SQLite
def save_questionnaire_answers(user_id, questionnaire_id, answers, bot_token):
    logger.info(f"Saving answers: user_id={user_id}, questionnaire_id={questionnaire_id}, bot_token={bot_token}, answers={answers}")
    try:
        conn = sqlite3.connect(DB_FILE)
        cursor = conn.cursor()
        cursor.execute(
            "INSERT INTO questionnaire_answers (user_id, questionnaire_id, bot_token, answers, timestamp) VALUES (?, ?, ?, ?, ?)",
            (user_id, questionnaire_id, bot_token, json.dumps(answers), datetime.now().isoformat())
        )
        conn.commit()
        logger.info(f"Saved questionnaire answers for user {user_id}, questionnaire {questionnaire_id}, bot {bot_token[-4:]}")
    except sqlite3.Error as e:
        logger.error(f"Error saving questionnaire answers: {e}")
    finally:
        conn.close()

# States for ticket creation and questionnaire
class TicketForm(StatesGroup):
    topic = State()
    description = State()

class QuestionnaireState(StatesGroup):
    answering = State()
    waiting_for_subscription = State()

# Main menu keyboard
main_menu = ReplyKeyboardMarkup(resize_keyboard=True)
create_ticket_btn = KeyboardButton("Создать тикет")
my_tickets_btn = KeyboardButton("Мои тикеты")
main_menu.add(create_ticket_btn, my_tickets_btn)

# Ensure avatars directory exists
if not os.path.exists(AVATARS_DIR):
    os.makedirs(AVATARS_DIR)
    logger.info(f"Created avatars directory: {AVATARS_DIR}")

# Predefined colors for avatar backgrounds
AVATAR_COLORS = [
    (240, 173, 78),  # Orange
    (234, 76, 137),  # Pink
    (88, 86, 214),   # Purple
    (74, 191, 188),  # Teal
    (52, 168, 83),   # Green
    (244, 81, 30),   # Red
    (66, 133, 244),  # Blue
]

# Generate initials avatar
def generate_initials_avatar(telegram_user_id, first_name, last_name):
    avatar_path = os.path.join(AVATARS_DIR, f"{telegram_user_id}.jpg")
    initials = ""
    if first_name:
        initials += first_name[0].upper()
    if last_name:
        initials += last_name[0].upper()
    if not initials:
        initials = str(telegram_user_id)[0].upper()
    color_index = int(hashlib.md5(str(telegram_user_id).encode()).hexdigest(), 16) % len(AVATAR_COLORS)
    bg_color = AVATAR_COLORS[color_index]
    size = (128, 128)
    image = Image.new('RGB', size, bg_color)
    draw = ImageDraw.Draw(image)
    try:
        font = ImageFont.truetype("arial.ttf", 60)
    except:
        font = ImageFont.load_default()
    text_bbox = draw.textbbox((0, 0), initials, font=font)
    text_width = text_bbox[2] - text_bbox[0]
    text_height = text_bbox[3] - text_bbox[1]
    text_x = (size[0] - text_width) / 2
    text_y = (size[1] - text_height) / 2
    draw.text((text_x, text_y), initials, fill=(255, 255, 255), font=font)
    try:
        image.save(avatar_path, 'JPEG', quality=95)
        logger.info(f"Generated initials avatar for user {telegram_user_id} at {avatar_path}")
        return f"/{avatar_path}"
    except Exception as e:
        logger.error(f"Failed to save initials avatar for user {telegram_user_id}: {str(e)}")
        return None

# Load tickets
def load_tickets():
    if not os.path.exists(TICKETS_FILE):
        logger.info("tickets.json does not exist, initializing empty file")
        with open(TICKETS_FILE, 'w', encoding='utf-8') as f:
            json.dump({}, f, ensure_ascii=False, indent=2)
        return {}
    try:
        with open(TICKETS_FILE, 'r', encoding='utf-8') as f:
            content = f.read().strip()
            if not content:
                return {}
            return json.loads(content)
    except Exception as e:
        logger.error(f"Error reading tickets.json: {e}")
        return {}

# Save tickets
def save_tickets(tickets, max_retries=3, retry_delay=0.5):
    for attempt in range(1, max_retries + 1):
        try:
            with open(TICKETS_FILE, 'w', encoding='utf-8') as f:
                with portalocker.Lock(f.fileno(), timeout=1):
                    json.dump(tickets, f, ensure_ascii=False, indent=2)
                    f.flush()
                    os.fsync(f.fileno())
                    logger.info("Successfully saved tickets.json")
                    return True
        except portalocker.exceptions.LockException:
            logger.warning(f"Attempt {attempt}/{max_retries}: Failed to acquire file lock")
            if attempt == max_retries:
                return False
        except Exception as e:
            logger.error(f"Attempt {attempt}/{max_retries}: Error saving tickets.json: {e}")
            if attempt == max_retries:
                return False
        if attempt < max_retries:
            asyncio.sleep(retry_delay)
    return False

# Load questionnaires for a specific bot
def load_questionnaires(bot_token):
    if not os.path.exists(QUESTIONNAIRES_FILE):
        with open(QUESTIONNAIRES_FILE, 'w', encoding='utf-8') as f:
            json.dump([], f, ensure_ascii=False, indent=2)
        return []
    try:
        with open(QUESTIONNAIRES_FILE, 'r', encoding='utf-8') as f:
            questionnaires = json.load(f)
            return [q for q in questionnaires if q.get('bot_token') == bot_token]
    except Exception as e:
        logger.error(f"Error reading questionnaires.json for bot {bot_token[-4:]}: {e}")
        return []

# Load channels
def load_channels():
    if not os.path.exists(CHANNELS_FILE):
        with open(CHANNELS_FILE, 'w', encoding='utf-8') as f:
            json.dump([], f, ensure_ascii=False, indent=2)
        return []
    try:
        with open(CHANNELS_FILE, 'r', encoding='utf-8') as f:
            return json.load(f)
    except Exception as e:
        logger.error(f"Error reading channels.json: {e}")
        return []

# Check if user has an open ticket
def has_open_ticket(user_id, tickets):
    if user_id in tickets:
        for ticket in tickets[user_id]:
            if ticket['status'] == 'Открыт':
                return ticket
    return None

# Download and save user profile photo
async def get_user_profile_photo(telegram_user_id, bot, max_retries=3, retry_delay=1):
    avatar_path = os.path.join(AVATARS_DIR, f"{telegram_user_id}.jpg")
    if os.path.exists(avatar_path):
        return f"/{avatar_path}"
    for attempt in range(1, max_retries + 1):
        try:
            chat = await bot.get_chat(telegram_user_id)
            first_name = chat.first_name or ""
            last_name = chat.last_name or ""
            photos = await bot.get_user_profile_photos(telegram_user_id, limit=1)
            if photos.photos:
                file_id = photos.photos[0][-1].file_id
                file = await bot.get_file(file_id)
                file_url = f"https://api.telegram.org/file/bot{bot.token}/{file.file_path}"
                async with aiohttp.ClientSession() as session:
                    async with session.get(file_url) as response:
                        if response.status == 200:
                            content = await response.read()
                            with open(avatar_path, 'wb') as f:
                                f.write(content)
                            if os.path.exists(avatar_path):
                                return f"/{avatar_path}"
                            else:
                                return generate_initials_avatar(telegram_user_id, first_name, last_name)
                        else:
                            return generate_initials_avatar(telegram_user_id, first_name, last_name)
            else:
                return generate_initials_avatar(telegram_user_id, first_name, last_name)
        except Exception as e:
            logger.error(f"Attempt {attempt}/{max_retries} failed for user {telegram_user_id}: {str(e)}")
            if attempt == max_retries:
                return generate_initials_avatar(telegram_user_id, first_name, last_name)
            await asyncio.sleep(retry_delay)
    return generate_initials_avatar(telegram_user_id, first_name, last_name)

# Check channel subscription
async def check_subscription(user_id, bot):
    channels = load_channels()
    if not channels:
        return True
    try:
        for channel in channels:
            member = await bot.get_chat_member(channel, user_id)
            if member.status in ['left', 'kicked']:
                return False
        return True
    except Exception as e:
        logger.error(f"Error checking subscription for user {user_id}: {e}")
        return False

# Register handlers for a dispatcher
def register_handlers(dp, bot_token):
    # Start command
    @dp.message_handler(commands=['start'])
    async def send_welcome(message: types.Message, state: FSMContext):
        bot = message.bot
        questionnaires = load_questionnaires(bot_token)
        if questionnaires:
            questionnaire = questionnaires[0]  # Use the first active questionnaire for this bot
            async with state.proxy() as data:
                data['questionnaire_id'] = questionnaire['id']
                data['bot_token'] = bot_token
                data['questions'] = questionnaire['questions']
                data['current_question'] = 0
                data['answers'] = []
            await message.reply("Пожалуйста, заполните анкету.")
            await message.reply(questionnaire['questions'][0])
            await QuestionnaireState.answering.set()
        else:
            await proceed_to_subscription_check(message, state)

    # Handle questionnaire answers
    @dp.message_handler(state=QuestionnaireState.answering)
    async def handle_answer(message: types.Message, state: FSMContext):
        async with state.proxy() as data:
            data['answers'].append(message.text)
            data['current_question'] += 1
            questions = data['questions']
            if data['current_question'] < len(questions):
                await message.reply(questions[data['current_question']])
            else:
                logger.info(f"Collected answers: {data['answers']}")
                save_questionnaire_answers(
                    user_id=str(message.from_user.id),
                    questionnaire_id=data['questionnaire_id'],
                    bot_token=data['bot_token'],
                    answers=data['answers']
                )
                await proceed_to_subscription_check(message, state)

    # Proceed to subscription check
    async def proceed_to_subscription_check(message, state: FSMContext):
        user_id = message.from_user.id
        bot = message.bot
        channels = load_channels()
        if not channels:
            await bot.send_message(user_id, "Добро пожаловать в систему тикетов! Теперь вы можете создавать тикеты с помощью кнопки 'Создать тикет'.", reply_markup=main_menu)
            await state.finish()
            return
        subscribed = await check_subscription(user_id, bot)
        if subscribed:
            await bot.send_message(user_id, "Добро пожаловать в систему тикетов! Теперь вы можете создавать тикеты с помощью кнопки 'Создать тикет'.", reply_markup=main_menu)
            await state.finish()
        else:
            keyboard = InlineKeyboardMarkup()
            for channel in channels:
                keyboard.add(InlineKeyboardButton("Подписаться", url=f"https://t.me/{channel.lstrip('@')}"))
            keyboard.add(InlineKeyboardButton("Проверить подписку", callback_data="check_subscription"))
            await bot.send_message(user_id, "Пожалуйста, подпишитесь на наши каналы:", reply_markup=keyboard)
            await QuestionnaireState.waiting_for_subscription.set()

    # Handle subscription check
    @dp.callback_query_handler(text="check_subscription", state=QuestionnaireState.waiting_for_subscription)
    async def check_subscription_callback(callback: types.CallbackQuery, state: FSMContext):
        subscribed = await check_subscription(callback.from_user.id, callback.bot)
        if subscribed:
            await callback.message.edit_text("Добро пожаловать в систему тикетов! Теперь вы можете создавать тикеты с помощью кнопки 'Создать тикет'.", reply_markup=main_menu)
            await state.finish()
        else:
            await callback.answer("Вы не подписаны на все каналы. Пожалуйста, подпишитесь.", show_alert=True)

    # Create ticket
    @dp.message_handler(text="Создать тикет")
    async def create_ticket(message: types.Message):
        user_id = str(message.from_user.id)
        try:
            await message.bot.get_chat(user_id)
        except Exception as e:
            logger.error(f"User {user_id} has not started bot: {str(e)}")
            await message.reply("Пожалуйста, начните диалог с ботом, отправив /start, чтобы создать тикет.", reply_markup=main_menu)
            return
        tickets = load_tickets()
        open_ticket = has_open_ticket(user_id, tickets)
        if open_ticket:
            await message.reply(
                f"У вас уже есть открытый тикет!\nТема: {open_ticket['topic']}\nID: {open_ticket['id']}\nПожалуйста, дождитесь его обработки.",
                reply_markup=main_menu
            )
            return
        subscribed = await check_subscription(user_id, message.bot)
        if not subscribed:
            keyboard = InlineKeyboardMarkup()
            for channel in load_channels():
                keyboard.add(InlineKeyboardButton("Подписаться", url=f"https://t.me/{channel.lstrip('@')}"))
            keyboard.add(InlineKeyboardButton("Проверить подписку", callback_data="check_subscription"))
            await message.reply("Пожалуйста, подпишитесь на наши каналы:", reply_markup=keyboard)
            return
        await TicketForm.topic.set()
        await message.reply("Напишите тему запроса:")

    # Handle ticket topic
    @dp.message_handler(state=TicketForm.topic)
    async def process_topic(message: types.Message, state: FSMContext):
        async with state.proxy() as data:
            data['topic'] = message.text
        await TicketForm.next()
        await message.reply("Напишите ваш запрос:")

    # Handle ticket description
    @dp.message_handler(state=TicketForm.description)
    async def process_description(message: types.Message, state: FSMContext):
        async with state.proxy() as data:
            data['description'] = message.text
            tickets = load_tickets()
            ticket_id = str(message.message_id)
            user_id = str(message.from_user.id)
            if user_id not in tickets:
                tickets[user_id] = []
            username = message.from_user.username if message.from_user.username else None
            profile_photo_url = await get_user_profile_photo(user_id, message.bot)
            tickets[user_id].append({
                'id': ticket_id,
                'topic': data['topic'],
                'description': data['description'],
                'telegram_user_id': user_id,
                'telegram_username': username,
                'profile_photo_url': profile_photo_url if profile_photo_url else '/Assets/default_avatar.png',
                'timestamp': message.date.isoformat(),
                'status': 'Открыт',
                'replies': [],
                'assigned_to': None
            })
            if not save_tickets(tickets):
                await message.reply("Ошибка при сохранении тикета. Попробуйте снова.", reply_markup=main_menu)
                await state.finish()
                return
            await message.reply(f"Тикет успешно создан!\nТема: {data['topic']}", reply_markup=main_menu)
        await state.finish()

    # My tickets
    @dp.message_handler(text="Мои тикеты")
    async def my_tickets(message: types.Message):
        tickets = load_tickets()
        user_id = str(message.from_user.id)
        if user_id not in tickets or not tickets[user_id]:
            await message.reply("У вас нет активных тикетов.", reply_markup=main_menu)
            return
        response = "Ваши тикеты:\n\n"
        for ticket in tickets[user_id]:
            response += f"📌 Тема: {ticket['topic']}\n"
            response += f"Статус: {ticket['status']}\n"
            response += f"Дата: {ticket['timestamp']}\n"
            if ticket['replies']:
                response += "Ответы:\n"
                for reply in ticket['replies']:
                    response += f"- {reply['text']} ({reply['timestamp']})\n"
            response += "\n"
        await message.reply(response, reply_markup=main_menu)

    # Handle user replies
    @dp.message_handler(lambda message: not message.text.startswith('/'), state=None)
    async def handle_user_reply(message: types.Message):
        user_id = str(message.from_user.id)
        tickets = load_tickets()
        if user_id not in tickets or not tickets[user_id]:
            await message.reply("У вас нет активных тикетов. Создайте тикет с помощью 'Создать тикет'.", reply_markup=main_menu)
            return
        target_ticket = None
        for ticket in reversed(tickets[user_id]):
            if any(reply['sender'] == 'admin' for reply in ticket['replies']):
                target_ticket = ticket
                break
        if not target_ticket:
            await message.reply("Нет тикетов с ответами поддержки. Создайте новый тикет или дождитесь ответа.", reply_markup=main_menu)
            return
        tickets[user_id] = [t for t in tickets[user_id]]
        for ticket in tickets[user_id]:
            if ticket['id'] == target_ticket['id']:
                ticket['replies'].append({
                    'text': message.text,
                    'timestamp': message.date.isoformat(),
                    'sender': 'user'
                })
                break
        if not save_tickets(tickets):
            await message.reply("Ошибка при сохранении ответа. Попробуйте снова.", reply_markup=main_menu)
            return
        await message.reply("Ваш ответ добавлен к тикету.", reply_markup=main_menu)

# Notify user of reply
async def notify_user_of_reply(ticket_id, reply_text, telegram_user_id):
    try:
        await bots[0].send_message(
            telegram_user_id,
            f"Новый ответ на ваш тикет (ID: {ticket_id}):\n{reply_text}"
        )
        logger.info(f"Sent notification to user {telegram_user_id} for ticket {ticket_id}")
    except Exception as e:
        logger.error(f"Failed to notify user {telegram_user_id}: {e}")

# Notify user of close
async def notify_user_of_close(ticket_id, telegram_user_id):
    try:
        await bots[0].send_message(
            telegram_user_id,
            f"Ваш тикет (ID: {ticket_id}) был закрыт."
        )
        logger.info(f"Sent close notification to user {telegram_user_id} for ticket {ticket_id}")
    except Exception as e:
        logger.error(f"Failed to notify user {telegram_user_id}: {e}")

# HTTP endpoints
async def handle_reply_notification(request):
    try:
        data = await request.json()
        if not all(k in data for k in ['ticket_id', 'reply_text', 'telegram_user_id']):
            logger.error(f"Invalid notification request: {data}")
            return web.json_response({'error': 'Invalid input'}, status=400)
        await notify_user_of_reply(data['ticket_id'], data['reply_text'], data['telegram_user_id'])
        return web.json_response({'success': True})
    except Exception as e:
        logger.error(f"Error handling reply notification: {e}")
        return web.json_response({'error': str(e)}, status=500)

async def handle_close_notification(request):
    try:
        data = await request.json()
        if not all(k in data for k in ['ticket_id', 'telegram_user_id']):
            logger.error(f"Invalid close notification request: {data}")
            return web.json_response({'error': 'Invalid input'}, status=400)
        await notify_user_of_close(data['ticket_id'], data['telegram_user_id'])
        return web.json_response({'success': True})
    except Exception as e:
        logger.error(f"Error handling close notification: {e}")
        return web.json_response({'error': str(e)}, status=500)

# Set up aiohttp server
app = web.Application()
app.add_routes([web.post('/notify_reply', handle_reply_notification)])
app.add_routes([web.post('/notify_close', handle_close_notification)])

# Run bots and server
async def start_bot_and_server():
    await initialize_bots()
    tasks = [dp.start_polling() for dp in dispatchers]
    await asyncio.gather(*tasks)

async def main():
    runner = web.AppRunner(app)
    await runner.setup()
    site = web.TCPSite(runner, 'localhost', 8080)
    await site.start()
    logger.info("Started aiohttp server on localhost:8080")
    await start_bot_and_server()

if __name__ == '__main__':
    asyncio.run(main())