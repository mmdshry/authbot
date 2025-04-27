<?php

namespace App\Http\Controllers;

use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;

class TelegramBotController extends Controller
{
    protected $telegram;
    protected $cryptoApiUrl = 'https://api.coingecko.com/api/v3/coins/markets';
    protected $globalApiUrl = 'https://api.coingecko.com/api/v3/global';
    protected $chartBaseUrl = 'https://www.coingecko.com/en/coins/';
    protected $trackedCryptos = [
        'bitcoin' => ['name' => 'Bitcoin', 'symbol' => 'BTC', 'emoji' => '₿'],
        'ethereum' => ['name' => 'Ethereum', 'symbol' => 'ETH', 'emoji' => 'Ξ'],
        'binancecoin' => ['name' => 'BNB', 'symbol' => 'BNB', 'emoji' => '💰'],
        'cardano' => ['name' => 'Cardano', 'symbol' => 'ADA', 'emoji' => '🌱'],
        'solana' => ['name' => 'Solana', 'symbol' => 'SOL', 'emoji' => '☀️'],
    ];

    public function __construct()
    {
        $this->telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
    }

    public function webhook(Request $request)
    {
        try {
            $data = $request->all();

            if (isset($data['message'])) {
                return $this->handleMessage($data['message']);
            }

            if (isset($data['my_chat_member'])) {
                return $this->handleChatMemberUpdate($data['my_chat_member']);
            }

            return response()->json(['status' => 'ok']);
        } catch (\Exception $e) {
            Log::error('Webhook error: ' . $e->getMessage());
            return response()->json(['status' => 'error'], 500);
        }
    }

    protected function handleMessage(array $message)
    {
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? null;
        $contact = $message['contact'] ?? null;

        $subscriber = Subscriber::firstOrCreate(['telegram_id' => $chatId]);

        if ($text === '/start' || $text === '🔙 بازگشت') {
            return $this->showMainMenu($chatId);
        }

        if ($text === '📈 قیمت رمز ارزها') {
            return $this->sendCryptoPrices($chatId);
        }

        if ($text === '📊 آمار بازار') {
            return $this->sendMarketStats($chatId);
        }

        if ($text === '⚙️ تنظیمات اعلان‌ها') {
            return $this->showNotificationSettings($chatId, $subscriber);
        }

        if ($text === 'ℹ️ درباره ربات') {
            return $this->sendAboutInfo($chatId);
        }

        if ($text === '📝 ثبت نام') {
            if ($subscriber->verified) {
                return $this->sendTelegramMessage($chatId, "✅ شما قبلا ثبت‌نام کرده‌اید\nبرای مشاهده قیمت‌ها از منوی اصلی اقدام کنید.", true);
            }
            return $this->promptPhoneNumber($chatId);
        }

        if ($contact && isset($contact['phone_number'])) {
            $subscriber->phone = $contact['phone_number'];
            $subscriber->save();
            return $this->sendOtp($subscriber, $chatId);
        }

        if ($text === '/resend') {
            return $this->sendOtp($subscriber, $chatId);
        }

        if (str_starts_with($text, '📊 نمودار ')) {
            $period = str_replace('📊 نمودار ', '', $text);
            return $this->sendChartOptions($chatId, $period);
        }

        if (str_starts_with($text, '🔔 ')) {
            return $this->toggleNotificationCrypto($subscriber, $chatId, $text);
        }

        if ($text === '🔔 فعال/غیرفعال کردن اعلان‌ها') {
            return $this->toggleNotifications($subscriber, $chatId);
        }

        return $this->handleOtpInput($subscriber, $chatId, $text);
    }

    protected function showMainMenu($chatId)
    {
        return $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "🌟 به ربات کریپتو خوش آمدید!\nجهان رمز ارزها در دستان شما! 🚀\nلطفا یک گزینه را انتخاب کنید:",
            'reply_markup' => json_encode([
                'keyboard' => [
                    [['text' => '📈 قیمت رمز ارزها'], ['text' => '📊 آمار بازار']],
                    [['text' => '⚙️ تنظیمات اعلان‌ها'], ['text' => '📝 ثبت نام']],
                    [['text' => 'ℹ️ درباره ربات']],
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ]),
        ]);
    }

    protected function promptPhoneNumber($chatId)
    {
        return $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '📱 برای ثبت‌نام و دریافت اعلان‌های جهش قیمت، شماره تلفن خود را وارد کنید:',
            'reply_markup' => json_encode([
                'keyboard' => [
                    [['text' => '📲 اشتراک‌گذاری شماره', 'request_contact' => true]],
                    [['text' => '🔙 بازگشت']],
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => true,
            ]),
        ]);
    }

    protected function sendOtp($subscriber, $chatId)
    {
        if ($subscriber->verified) {
            return $this->sendTelegramMessage($chatId, "✅ شما قبلا ثبت‌نام کرده‌اید\nبرای مشاهده قیمت‌ها از منوی اصلی اقدام کنید.", true);
        }

        if ($subscriber->last_sent_at && now()->diffInMinutes($subscriber->last_sent_at) < 5) {
            return $this->sendTelegramMessage($chatId, '🕒 لطفا ۵ دقیقه صبر کنید.', true);
        }

        $otp = (string) random_int(100000, 999999);

        $subscriber->update([
            'otp' => $otp,
            'otp_expires_at' => now()->addMinutes(5),
            'last_sent_at' => now(),
        ]);

        try {
            Http::retry(3, 100)->timeout(30)->get('https://api.fast-creat.ir/sms', [
                'apikey' => env('SENATOR_API'),
                'type' => 'sms',
                'code' => $otp,
                'phone' => $subscriber->phone,
                'template' => env('SENATOR_TEMPLATE'),
            ]);
        } catch (\Exception $e) {
            Log::error('SMS sending failed: ' . $e->getMessage());
            return $this->sendTelegramMessage($chatId, '❌ خطا در ارسال کد. لطفا دوباره تلاش کنید.', true);
        }

        return $this->sendTelegramMessage($chatId, '📩 کد یک‌بارمصرف ارسال شد. لطفا کد را وارد کنید.', true);
    }

    protected function handleOtpInput($subscriber, $chatId, $text)
    {
        if ($subscriber->otp && $subscriber->otp_expires_at && now()->lessThan($subscriber->otp_expires_at)) {
            if ($text === $subscriber->otp) {
                $subscriber->update([
                    'verified' => true,
                    'otp' => null,
                    'otp_expires_at' => null,
                    'receive_notifications' => true,
                    'notification_cryptos' => array_keys($this->trackedCryptos),
                ]);
                return $this->sendTelegramMessage($chatId, "🎉 ثبت‌نام با موفقیت انجام شد!\nاکنون اعلان‌های جهش قیمت را دریافت خواهید کرد. از منوی اصلی ادامه دهید.", true);
            }
            return $this->sendTelegramMessage($chatId, '❌ کد اشتباه است. دوباره تلاش کنید.', true);
        }
        return $this->sendTelegramMessage($chatId, '⏱ کد منقضی شده یا یافت نشد.', true);
    }

    protected function sendCryptoPrices($chatId)
    {
        try {
            $response = Http::get($this->cryptoApiUrl, [
                'vs_currency' => 'usd',
                'ids' => implode(',', array_keys($this->trackedCryptos)),
                'order' => 'market_cap_desc',
                'per_page' => 10,
                'page' => 1,
                'sparkline' => false,
                'price_change_percentage' => '1h,24h,7d',
            ]);

            $data = $response->json();
            $message = "🌟 قیمت لحظه‌ای رمز ارزهای برتر:\n━━━━━━━━━━━━━━━━━━━━\n";

            foreach ($data as $crypto) {
                $cryptoId = $crypto['id'];
                if (!isset($this->trackedCryptos[$cryptoId])) {
                    continue;
                }

                $info = $this->trackedCryptos[$cryptoId];
                $price = $crypto['current_price'];
                $change1h = $crypto['price_change_percentage_1h_in_currency'] ?? 0;
                $change24h = $crypto['price_change_percentage_24h_in_currency'] ?? 0;
                $change7d = $crypto['price_change_percentage_7d_in_currency'] ?? 0;
                $marketCapRank = $crypto['market_cap_rank'];
                $volume24h = $crypto['total_volume'] / 1e6;
                $chartUrl = $this->chartBaseUrl . $cryptoId;

                $message .= sprintf(
                    "%s %s (%s)\n💵 قیمت: $%.2f\n📈 1 ساعت: %s%.2f%%\n📊 24 ساعت: %s%.2f%%\n📉 7 روز: %s%.2f%%\n🏅 رتبه بازار: #%d\n📦 حجم 24h: $%.1fM\n🔗 نمودار: %s\n━━━━━━━━━━━━━━━━━━━━\n",
                    $info['emoji'],
                    $info['name'],
                    $info['symbol'],
                    $price,
                    $change1h >= 0 ? '+' : '',
                    $change1h,
                    $change24h >= 0 ? '+' : '',
                    $change24h,
                    $change7d >= 0 ? '+' : '',
                    $change7d,
                    $marketCapRank,
                    $volume24h,
                    $chartUrl
                );
            }

            $message .= "📊 برای مشاهده نمودار در بازه‌های مختلف، گزینه زیر را انتخاب کنید:";
            return $this->sendTelegramMessage($chatId, $message, true, [
                [['text' => '📊 نمودار 1 ساعته'], ['text' => '📊 نمودار 24 ساعته'], ['text' => '📊 نمودار 7 روزه']],
                [['text' => '🔙 بازگشت']],
            ]);
        } catch (\Exception $e) {
            Log::error('Crypto API error: ' . $e->getMessage());
            return $this->sendTelegramMessage($chatId, '❌ خطا در دریافت قیمت‌ها. لطفا دوباره تلاش کنید.', true);
        }
    }

    protected function sendChartOptions($chatId, $period)
    {
        $periodMap = [
            '1 ساعته' => '1h',
            '24 ساعته' => '24h',
            '7 روزه' => '7d',
        ];

        $selectedPeriod = $periodMap[$period] ?? '24h';
        $message = "📊 نمودار قیمت در بازه $period:\nلطفا یک ارز را انتخاب کنید:\n";

        $keyboard = [];
        foreach ($this->trackedCryptos as $id => $info) {
            $chartUrl = $this->chartBaseUrl . $id;
            $message .= "{$info['emoji']} {$info['name']}: $chartUrl\n";
            $keyboard[] = [['text' => "{$info['emoji']} {$info['name']}"]];
        }
        $keyboard[] = [['text' => '🔙 بازگشت']];

        return $this->sendTelegramMessage($chatId, $message, false, $keyboard);
    }

    protected function sendMarketStats($chatId)
    {
        try {
            $response = Http::get($this->globalApiUrl);
            $data = $response->json()['data'] ?? [];

            $totalMarketCap = $data['total_market_cap']['usd'] / 1e12 ?? 0;
            $marketCapChange24h = $data['market_cap_change_percentage_24h_usd'] ?? 0;
            $totalVolume = $data['total_volume']['usd'] / 1e9 ?? 0;
            $btcDominance = $data['market_cap_percentage']['btc'] ?? 0;

            $message = "📊 آمار کلی بازار رمز ارز:\n━━━━━━━━━━━━━━━━━━━━\n";
            $message .= sprintf(
                "💰 سرمایه کل بازار: $%.2fT\n📈 تغییر 24 ساعته: %s%.2f%%\n📦 حجم معاملات 24h: $%.1fB\n🏅 تسلط بیت‌کوین: %.1f%%\n━━━━━━━━━━━━━━━━━━━━\n",
                $totalMarketCap,
                $marketCapChange24h >= 0 ? '+' : '',
                $marketCapChange24h,
                $totalVolume,
                $btcDominance
            );

            return $this->sendTelegramMessage($chatId, $message, true);
        } catch (\Exception $e) {
            Log::error('Global API error: ' . $e->getMessage());
            return $this->sendTelegramMessage($chatId, '❌ خطا در دریافت آمار بازار. لطفا دوباره تلاش کنید.', true);
        }
    }

    protected function showNotificationSettings($chatId, $subscriber)
    {
        $status = $subscriber->receive_notifications ? 'فعال' : 'غیرفعال';
        $message = "⚙️ تنظیمات اعلان‌ها:\nوضعیت اعلان‌ها: $status\nارزهای انتخاب‌شده برای اعلان:\n";

        $keyboard = [[['text' => '🔔 فعال/غیرفعال کردن اعلان‌ها']]];
        $notificationCryptos = is_array($subscriber->notification_cryptos) ? $subscriber->notification_cryptos : [];

        foreach ($this->trackedCryptos as $id => $info) {
            $isSelected = in_array($id, $notificationCryptos) ? '✅' : '⬜';
            $message .= "{$info['emoji']} {$info['name']}: $isSelected\n";
            $keyboard[] = [['text' => "🔔 {$info['name']}"]];
        }

        $keyboard[] = [['text' => '🔙 بازگشت']];

        return $this->sendTelegramMessage($chatId, $message, false, $keyboard);
    }

    protected function toggleNotifications($subscriber, $chatId)
    {
        $subscriber->update([
            'receive_notifications' => !$subscriber->receive_notifications,
        ]);

        $status = $subscriber->receive_notifications ? 'فعال' : 'غیرفعال';
        return $this->sendTelegramMessage($chatId, "🔔 اعلان‌ها $status شدند.", true);
    }

    protected function toggleNotificationCrypto($subscriber, $chatId, $text)
    {
        $cryptoName = str_replace('🔔 ', '', $text);
        $cryptoId = null;
        foreach ($this->trackedCryptos as $id => $info) {
            if ($info['name'] === $cryptoName) {
                $cryptoId = $id;
                break;
            }
        }

        if (!$cryptoId) {
            Log::warning("Crypto not found: $cryptoName");
            return $this->sendTelegramMessage($chatId, '❌ ارز یافت نشد.', true);
        }

        $notificationCryptos = is_array($subscriber->notification_cryptos) ? $subscriber->notification_cryptos : [];
        $isCurrentlyActive = in_array($cryptoId, $notificationCryptos);

        if ($isCurrentlyActive) {
            $notificationCryptos = array_values(array_diff($notificationCryptos, [$cryptoId]));
            $message = "🔔 اعلان برای $cryptoName غیرفعال شد.";
        } else {
            $notificationCryptos[] = $cryptoId;
            $notificationCryptos = array_values(array_unique($notificationCryptos));
            $message = "🔔 اعلان برای $cryptoName فعال شد.";
        }

        try {
            $subscriber->update(['notification_cryptos' => $notificationCryptos]);
            Log::info("Updated notification_cryptos for subscriber {$subscriber->telegram_id}: " . json_encode($notificationCryptos));
        } catch (\Exception $e) {
            Log::error("Failed to update notification_cryptos for subscriber {$subscriber->telegram_id}: " . $e->getMessage());
            return $this->sendTelegramMessage($chatId, '❌ خطا در به‌روزرسانی تنظیمات اعلان.', true);
        }

        return $this->sendTelegramMessage($chatId, $message, true);
    }

    protected function sendAboutInfo($chatId)
    {
        $message = "ℹ️ درباره ربات کریپتو:\n━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "🚀 این ربات برای ارائه قیمت‌های لحظه‌ای و اعلان‌های جهش قیمت رمز ارزها طراحی شده است.\n";
        $message .= "🌟 ویژگی‌ها:\n- قیمت‌های لحظه‌ای با نمودار\n- اعلان جهش قیمت\n- آمار بازار\n";

        return $this->sendTelegramMessage($chatId, $message, true);
    }

    protected function handleChatMemberUpdate(array $chatMemberEvent)
    {
        $status = $chatMemberEvent['new_chat_member']['status'] ?? null;

        if ($status === 'member') {
            Log::info('Bot added to a new chat');
        } elseif ($status === 'kicked') {
            Log::info('Bot was kicked from a chat');
        }

        return response()->json(['status' => 'ok']);
    }

    protected function sendTelegramMessage($chatId, $text, $showBackButton = false, $customKeyboard = null)
    {
        try {
            $replyMarkup = $customKeyboard ? json_encode(['keyboard' => $customKeyboard, 'resize_keyboard' => true, 'one_time_keyboard' => true]) :
                ($showBackButton ? json_encode(['keyboard' => [[['text' => '🔙 بازگشت']]], 'resize_keyboard' => true, 'one_time_keyboard' => true]) :
                    json_encode(['remove_keyboard' => true]));

            return $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => $text,
                'reply_markup' => $replyMarkup,
                'disable_web_page_preview' => false,
                'parse_mode' => 'HTML',
            ]);
        } catch (\Exception $e) {
            Log::error('Telegram message sending failed: ' . $e->getMessage());
            return response()->json(['status' => 'error'], 500);
        }
    }
}