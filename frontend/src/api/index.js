// Inside Telegram the app talks to the real backend; in a plain browser it runs on sample data.
import { inTelegram } from '@/lib/telegram.js';
import live from './live.js';
import demo from './demo.js';

export const api = inTelegram ? live : demo;
