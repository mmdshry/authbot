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

    public function __construct()
    {
        $this->telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
    }

    public function webhook(Request $request)
    {
        $data = $request->all();

        if (isset($data['message'])) {
            return $this->handleMessage($data['message']);
        }

        if (isset($data['my_chat_member'])) {
            return $this->handleChatMemberUpdate($data['my_chat_member']);
        }

        return response()->json(['status' => 'ok']);
    }

    protected function handleMessage(array $message)
    {
        $chatId = $message['chat']['id'];
        $text = $message['text'] ?? null;
        $contact = $message['contact'] ?? null;

        $subscriber = Subscriber::firstOrCreate(['telegram_id' => $chatId]);

        if ($subscriber->verified) {
            return $this->sendTelegramMessage($chatId, '✅ شما قبلا ثبت نام کرده اید');
        }

        if ($text === '/start') {
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

        return $this->handleOtpInput($subscriber, $chatId, $text);
    }

    protected function promptPhoneNumber($chatId)
    {
        return $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "👋 خوش آمدید! لطفا شماره تلفن همراه خود را وارد کنید:",
            'reply_markup' => json_encode([
                'keyboard' => [
                    [[
                         'text' => '📱 به اشتراک گذاری شماره تلفن',
                         'request_contact' => true
                     ]]
                ],
                'resize_keyboard' => true,
                'one_time_keyboard' => true
            ])
        ]);
    }

    protected function handleOtpInput($subscriber, $chatId, $text)
    {
        if ($subscriber->otp && $subscriber->otp_expires_at && now()->lessThan($subscriber->otp_expires_at)) {
            if ($text === $subscriber->otp) {
                $subscriber->update([
                    'verified' => true,
                    'otp' => null,
                    'otp_expires_at' => null,
                ]);

                return $this->sendTelegramMessage($chatId, '🎉 ثبت نام موفقیت آمیز بود!');
            }

            return $this->sendTelegramMessage($chatId, '❌ کد وارد شده اشتباه است. لطف مجددا تلاش کنید.');
        }

        return $this->sendTelegramMessage($chatId, '⏱ کد پیدا نشد.');
    }

    protected function sendOtp($subscriber, $chatId)
    {
        if ($subscriber->verified) {
            return $this->sendTelegramMessage($chatId, '✅ شما قبلا ثبت نام کرده اید');
        }

        if ($subscriber->last_sent_at && now()->diffInMinutes($subscriber->last_sent_at) < 5) {
            return $this->sendTelegramMessage($chatId, '🕒 لطفا حداقل 5 دقیقه قبل از درخئاست کد جدید تامل فرمایید.');
        }

        $otp = (string)random_int(100000, 999999);

        $subscriber->update([
            'otp' => $otp,
            'otp_expires_at' => now()->addMinutes(5),
            'last_sent_at' => now(),
        ]);

        Http::retry(3)
            ->timeout(30)
            ->get('https://api.fast-creat.ir/sms', [
                'apikey' => env('SENATOR_API'),
                'type' => 'sms',
                'code' => $otp,
                'phone' => $subscriber->phone,
                'template' => env('SENATOR_TEMPLATE'),
            ]);

        return $this->sendTelegramMessage($chatId, "📩 کد یکبارمصرف برای شما ارسال شد.\nلطفا جهت تکمیل ثبت نام کد را وارد کنید.");
    }

    protected function handleChatMemberUpdate(array $chatMemberEvent)
    {
        Log::info("Chat member status changed: ", $chatMemberEvent);

        $status = $chatMemberEvent['new_chat_member']['status'] ?? null;

        if ($status === 'member') {
            Log::info('Bot added to a new chat');
        }

        if ($status === 'kicked') {
            Log::info('Bot was kicked from a chat');
        }

        return response()->json(['status' => 'ok']);
    }

    protected function sendTelegramMessage($chatId, $text)
    {
        return $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => $text
        ]);
    }
}
