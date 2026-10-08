// Telegram WebApp SDK access. Outside Telegram (a plain browser) `inTelegram` is false and the app runs on demo data.
export const webApp = window.Telegram?.WebApp ?? null;

export const inTelegram = !!webApp?.initData;

export const initData = webApp?.initData ?? '';

/** The Telegram user who opened the app, or null in a plain browser. */
export const telegramUser = webApp?.initDataUnsafe?.user ?? null;

/** TechDesk works only in the private chat with the bot; Telegram tells where it was opened from. */
export const chatType = webApp?.initDataUnsafe?.chat_type ?? null;
export const openedInGroup = ['group', 'supergroup', 'channel'].includes(chatType);

export const botUsername = import.meta.env.VITE_BOT_USERNAME || 'TstJiraStar_bot';
