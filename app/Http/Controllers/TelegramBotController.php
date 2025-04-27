<?php

namespace App\Http\Controllers;

use App\Models\Portfolio;
use App\Models\PriceAlert;
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

        if ($text === '💱 مبدل قیمت') {
            return $this->promptPriceConverter($chatId);
        }

        if ($text === '💼 پورتفولیو') {
            return $this->showPortfolio($chatId, $subscriber);
        }

        if ($text === '🔔 آلارم قیمت') {
            return $this->promptPriceAlert($chatId);
        }

        if ($text === '📚 آموزش کریپتو') {
            return $this->sendCryptoEducation($chatId);
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

        if (preg_match('/💱 تبدیل (\w+) (\d+\.?\d*) (\w+)/', $text, $matches)) {
            return $this->handlePriceConversion($chatId, $matches);
        }

        if (preg_match('/💼 افزودن (\w+) (\d+\.?\d*)/', $text, $matches)) {
            return $this->addToPortfolio($chatId, $subscriber, $matches);
        }

        if (preg_match('/🔔 تنظیم آلارم (\w+) (\d+\.?\d*)/', $text, $matches)) {
            return $this->setPriceAlert($chatId, $subscriber, $matches);
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
                    [['text' => '💱 مبدل قیمت'], ['text' => '💼 پورتفولیو']],
                    [['text' => '🔔 آلارم قیمت'], ['text' => '⚙️ تنظیمات اعلان‌ها']],
                    [['text' => '📝 ثبت نام'], ['text' => '📚 آموزش کریپتو']],
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
                return $this->sendTelegramMessage($chatId, "🎉 ثبت‌نام با موفقیت انجام شد!\nاکنون اعلان‌های جهش قیمت را دریافت خواهید کرد.", true);
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
                $trend = $change24h > 0 ? '📈 صعودی' : ($change24h < 0 ? '📉 نزولی' : '➡️ خنثی');

                $message .= sprintf(
                    "%s %s (%s)\n💵 قیمت: $%.2f\n📈 1 ساعت: %s%.2f%%\n📊 24 ساعت: %s%.2f%%\n📉 7 روز: %s%.2f%%\n🏅 رتبه بازار: #%d\n📦 حجم 24h: $%.1fM\n📊 روند: %s\n🔗 نمودار: %s\n━━━━━━━━━━━━━━━━━━━━\n",
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
                    $trend,
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

    protected function promptPriceConverter($chatId)
    {
        $message = "💱 مبدل قیمت:\nلطفا مقدار و ارز را وارد کنید (مثال: 💱 تبدیل Bitcoin 1 USD یا 💱 تبدیل Bitcoin 1 ETH):\n";
        $keyboard = [];
        foreach ($this->trackedCryptos as $info) {
            $keyboard[] = [['text' => "💱 تبدیل {$info['name']}"]];
        }
        $keyboard[] = [['text' => '🔙 بازگشت']];

        return $this->sendTelegramMessage($chatId, $message, false, $keyboard);
    }

    protected function handlePriceConversion($chatId, $matches)
    {
        if (count($matches) < 4) {
            Log::warning("Invalid price conversion format: " . json_encode($matches));
            return $this->sendTelegramMessage($chatId, '❌ فرمت اشتباه. مثال: 💱 تبدیل Bitcoin 1 USD', true);
        }

        $cryptoName = $matches[1];
        $amount = floatval($matches[2]);
        $targetCurrency = strtoupper($matches[3]);

        $cryptoId = null;
        foreach ($this->trackedCryptos as $id => $info) {
            if ($info['name'] === $cryptoName) {
                $cryptoId = $id;
                break;
            }
        }

        if (!$cryptoId) {
            Log::warning("Crypto not found for conversion: $cryptoName");
            return $this->sendTelegramMessage($chatId, '❌ ارز یافت نشد.', true);
        }

        try {
            $response = Http::get($this->cryptoApiUrl, [
                'vs_currency' => 'usd',
                'ids' => $cryptoId,
            ]);
            $data = $response->json();
            $priceUsd = $data[0]['current_price'] ?? 0;

            $message = "💱 نتیجه تبدیل:\n";
            if ($targetCurrency === 'USD') {
                $result = $amount * $priceUsd;
                $message .= sprintf("%s %s = $%.2f USD\n", $amount, $cryptoName, $result);
            } elseif ($targetCurrency === 'IRR') {
                $result = $amount * $priceUsd * 42000; // نرخ تقریبی دلار به تومان
                $message .= sprintf("%s %s = %.0f IRR\n", $amount, $cryptoName, $result);
            } else {
                $targetId = array_search($targetCurrency, array_column($this->trackedCryptos, 'symbol', 'id'));
                if ($targetId) {
                    $targetResponse = Http::get($this->cryptoApiUrl, ['vs_currency' => 'usd', 'ids' => $targetId]);
                    $targetPriceUsd = $targetResponse->json()[0]['current_price'] ?? 0;
                    if ($targetPriceUsd == 0) {
                        return $this->sendTelegramMessage($chatId, '❌ خطا در دریافت قیمت ارز مقصد.', true);
                    }
                    $result = ($amount * $priceUsd) / $targetPriceUsd;
                    $message .= sprintf("%s %s = %.4f %s\n", $amount, $cryptoName, $result, $targetCurrency);
                } else {
                    return $this->sendTelegramMessage($chatId, '❌ ارز مقصد پشتیبانی نمی‌شود.', true);
                }
            }

            return $this->sendTelegramMessage($chatId, $message, true);
        } catch (\Exception $e) {
            Log::error('Price conversion error: ' . $e->getMessage());
            return $this->sendTelegramMessage($chatId, '❌ خطا در تبدیل قیمت.', true);
        }
    }

    protected function showPortfolio($chatId, $subscriber)
    {
        $portfolios = Portfolio::where('subscriber_id', $subscriber->id)->get();
        if ($portfolios->isEmpty()) {
            $message = "💼 پورتفولیوی شما خالی است.\nبرای افزودن کوین، از گزینه‌های زیر انتخاب کنید:\n";
        } else {
            $message = "💼 پورتفولیوی شما:\n━━━━━━━━━━━━━━━━━━━━\n";
            $totalValue = 0;

            try {
                $response = Http::get($this->cryptoApiUrl, [
                    'vs_currency' => 'usd',
                    'ids' => implode(',', array_keys($this->trackedCryptos)),
                ]);
                $prices = collect($response->json())->keyBy('id');

                foreach ($portfolios as $portfolio) {
                    $cryptoId = $portfolio->crypto_id;
                    $info = $this->trackedCryptos[$cryptoId] ?? null;
                    if (!$info) continue;

                    $price = $prices[$cryptoId]['current_price'] ?? 0;
                    $value = $portfolio->amount * $price;
                    $totalValue += $value;

                    $message .= sprintf(
                        "%s %s: %.4f (%s%.2f)\n",
                        $info['emoji'],
                        $info['name'],
                        $portfolio->amount,
                        $value >= 0 ? '+' : '',
                        $value
                    );
                }

                $message .= sprintf("💰 ارزش کل: $%.2f\n━━━━━━━━━━━━━━━━━━━━\n", $totalValue);
            } catch (\Exception $e) {
                Log::error('Portfolio fetch error: ' . $e->getMessage());
                return $this->sendTelegramMessage($chatId, '❌ خطا در محاسبه پورتفولیو.', true);
            }
        }

        $message .= "➕ برای افزودن کوین، گزینه زیر را انتخاب کنید:";
        $keyboard = [];
        foreach ($this->trackedCryptos as $id => $info) {
            $keyboard[] = [['text' => "💼 افزودن {$info['name']}"]];
        }
        $keyboard[] = [['text' => '🔙 بازگشت']];

        return $this->sendTelegramMessage($chatId, $message, false, $keyboard);
    }

    protected function addToPortfolio($chatId, $subscriber, $matches)
    {
        if (count($matches) < 3) {
            Log::warning("Invalid portfolio add format: " . json_encode($matches));
            return $this->sendTelegramMessage($chatId, '❌ فرمت اشتباه. مثال: 💼 افزودن Bitcoin 0.5', true);
        }

        $cryptoName = $matches[1];
        $amount = floatval($matches[2]);

        $cryptoId = null;
        foreach ($this->trackedCryptos as $id => $info) {
            if ($info['name'] === $cryptoName) {
                $cryptoId = $id;
                break;
            }
        }

        if (!$cryptoId) {
            Log::warning("Crypto not found for portfolio: $cryptoName");
            return $this->sendTelegramMessage($chatId, '❌ ارز یافت نشد.', true);
        }

        try {
            Portfolio::updateOrCreate(
                ['subscriber_id' => $subscriber->id, 'crypto_id' => $cryptoId],
                ['amount' => $amount]
            );
            return $this->sendTelegramMessage($chatId, "✅ $amount $cryptoName به پورتفولیو اضافه شد.", true);
        } catch (\Exception $e) {
            Log::error('Portfolio add error: ' . $e->getMessage());
            return $this->sendTelegramMessage($chatId, '❌ خطا در افزودن به پورتفولیو.', true);
        }
    }

    protected function promptPriceAlert($chatId)
    {
        $message = "🔔 تنظیم آلارم قیمت:\nلطفا ارز و آستانه قیمت را وارد کنید (مثال: 🔔 تنظیم آلارم Bitcoin 60000):\n";
        $keyboard = [];
        foreach ($this->trackedCryptos as $info) {
            $keyboard[] = [['text' => "🔔 تنظیم آلارم {$info['name']}"]];
        }
        $keyboard[] = [['text' => '🔙 بازگشت']];

        return $this->sendTelegramMessage($chatId, $message, false, $keyboard);
    }

    protected function setPriceAlert($chatId, $subscriber, $matches)
    {
        if (count($matches) < 3) {
            Log::warning("Invalid price alert format: " . json_encode($matches));
            return $this->sendTelegramMessage($chatId, '❌ فرمت اشتباه. مثال: 🔔 تنظیم آلارم Bitcoin 60000', true);
        }

        $cryptoName = $matches[1];
        $priceThreshold = floatval($matches[2]);

        $cryptoId = null;
        foreach ($this->trackedCryptos as $id => $info) {
            if ($info['name'] === $cryptoName) {
                $cryptoId = $id;
                break;
            }
        }

        if (!$cryptoId) {
            Log::warning("Crypto not found for price alert: $cryptoName");
            return $this->sendTelegramMessage($chatId, '❌ ارز یافت نشد.', true);
        }

        try {
            PriceAlert::updateOrCreate(
                ['subscriber_id' => $subscriber->id, 'crypto_id' => $cryptoId],
                ['price_threshold' => $priceThreshold]
            );
            return $this->sendTelegramMessage($chatId, "🔔 آلارم قیمت برای $cryptoName در $priceThreshold USD تنظیم شد.", true);
        } catch (\Exception $e) {
            Log::error('Price alert set error: ' . $e->getMessage());
            return $this->sendTelegramMessage($chatId, '❌ خطا در تنظیم آلارم قیمت.', true);
        }
    }

    protected function sendCryptoEducation($chatId)
    {
        $tips = [
            "📚 **بلاکچین چیست؟**\nبلاکچین یک دفتر کل توزیع‌شده است که تراکنش‌ها را به‌صورت امن و شفاف ثبت می‌کند.\n",
            "🔐 **کیف پول کریپتو**\nکیف پول‌ها برای ذخیره کلیدهای خصوصی رمز ارزها استفاده می‌شوند. همیشه نسخه پشتیبان تهیه کنید!\n",
            "💸 **استیکینگ چیست؟**\nاستیکینگ یعنی قفل کردن رمز ارزها برای پشتیبانی از شبکه و کسب پاداش.\n",
        ];

        $message = "📚 نکات آموزشی کریپتو:\n━━━━━━━━━━━━━━━━━━━━\n" . $tips[array_rand($tips)] . "━━━━━━━━━━━━━━━━━━━━\n";
        return $this->sendTelegramMessage($chatId, $message, true);
    }

    protected function sendAboutInfo($chatId)
    {
        $message = "ℹ️ درباره ربات کریپتو:\n━━━━━━━━━━━━━━━━━━━━\n";
        $message .= "🚀 رباتی برای مدیریت رمز ارزها با ویژگی‌های پیشرفته:\n";
        $message .= "🌟 ویژگی‌ها:\n- قیمت‌های لحظه‌ای و نمودار\n- مبدل قیمت\n- پورتفولیو شخصی\n- آلارم قیمت\n- آموزش کریپتو\n";
        $message .= "📩 پشتیبانی: @CryptoBotSupport\n━━━━━━━━━━━━━━━━━━━━\n";

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