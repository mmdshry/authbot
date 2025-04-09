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
            return $this->sendTelegramMessage($chatId, '✅ You are already verified.');
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
            'text' => "👋 Welcome! Please share your phone number to continue:",
            'reply_markup' => json_encode([
                'keyboard' => [
                    [[
                         'text' => '📱 Share My Phone Number',
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

                return $this->sendTelegramMessage($chatId, '🎉 Your subscription has been successful!');
            }

            return $this->sendTelegramMessage($chatId, '❌ Incorrect OTP. Please try again or type /resend to get a new code.');
        }

        return $this->sendTelegramMessage($chatId, '⏱ OTP expired or not found. Type /resend to get a new one.');
    }

    protected function sendOtp($subscriber, $chatId)
    {
        if ($subscriber->verified) {
            return $this->sendTelegramMessage($chatId, '✅ You are already verified. No need for an OTP.');
        }

        if ($subscriber->last_sent_at && now()->diffInMinutes($subscriber->last_sent_at) < 5) {
            return $this->sendTelegramMessage($chatId, '🕒 Please wait before requesting a new OTP (5 minutes cooldown).');
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

        return $this->sendTelegramMessage($chatId, "📩 We've sent you an OTP via SMS.\nPlease enter it to complete your subscription.\n\nType /resend if you didn’t receive it.");
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
