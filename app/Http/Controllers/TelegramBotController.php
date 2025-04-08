<?php

// app/Http/Controllers/TelegramBotController.php
namespace App\Http\Controllers;

use App\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Telegram\Bot\Api;
use Telegram\Bot\Exceptions\TelegramSDKException;

class TelegramBotController extends Controller
{
    protected $telegram;

    public function __construct()
    {
        $this->telegram = new Api(env('TELEGRAM_BOT_TOKEN'));
    }

    /**
     * @throws TelegramSDKException
     * @throws \JsonException
     */
    public function webhook(Request $request)
    {
        Log::info('webhook',$request->all());
        $update = $this->telegram->getWebhookUpdate();
        $chatId = $update->getMessage()?->getChat()?->getId();
        $text = trim($update->getMessage()?->getText());

        $subscriber = Subscriber::firstOrCreate(['telegram_id' => $chatId]);

        if ($text === '/start') {
            return $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => "👋 Welcome! Please choose an option below:",
                'reply_markup' => json_encode([
                    'keyboard'          => [
                        [['text' => '📱 Send Phone Number', 'request_contact' => true]],
                        [['text' => '🔁 Resend OTP']],
                        [['text' => 'ℹ️ Help']]
                    ],
                    'resize_keyboard'   => true,
                    'one_time_keyboard' => false
                ], JSON_THROW_ON_ERROR)
            ]);
        }

        // If not verified yet
        if (!$subscriber->phone) {
            if (preg_match('/^\+?\d{10,15}$/', $text)) {
                $subscriber->phone = $text;
                $subscriber->save();
                return $this->sendOtp($subscriber, $chatId);
            }

            return $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'Please enter a valid phone number (e.g. +12345678900).'
            ]);
        }

        if ($subscriber->verified) {
            return $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'You are already subscribed.'
            ]);
        }

        // OTP Check
        if ($text === '/resend') {
            return $this->sendOtp($subscriber, $chatId);
        }

        if ($subscriber->otp && $subscriber->otp_expires_at && now()->lessThan($subscriber->otp_expires_at)) {
            if ($text === $subscriber->otp) {
                $subscriber->verified = true;
                $subscriber->otp = null;
                $subscriber->otp_expires_at = null;
                $subscriber->save();

                return $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '🎉 Your subscription has been successful!'
                ]);
            }

            return $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '❌ Incorrect OTP. Please try again or type /resend to get a new code.'
            ]);
        }

        return $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => '⏱ OTP expired or not found. Type /resend to get a new one.'
        ]);
    }

    protected function sendOtp($subscriber, $chatId)
    {
        // Prevent frequent sending
        if ($subscriber->last_sent_at && now()->diffInSeconds($subscriber->last_sent_at) < 120) {
            return $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '🕒 Please wait a bit before requesting a new code.'
            ]);
        }

        $otp = random_int(100000, 999999); // e.g., 6-digit OTP
        $subscriber->otp = (string)$otp;
        $subscriber->otp_expires_at = now()->addMinutes(5);
        $subscriber->last_sent_at = now();
        $subscriber->save();

        // Simulate SMS
        logger("Sending SMS to {$subscriber->phone}: Your OTP is {$otp}");

        $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "📲 We've sent you an OTP via SMS. Please enter it to complete your subscription.\nIf you didn't receive it, type /resend."
        ]);
    }
}

