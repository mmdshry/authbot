<?php

namespace App\Http\Controllers;

use App\Models\Subscriber;
use Illuminate\Http\Request;
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
        $update = $this->telegram->getWebhookUpdate();

        $message = $update->getMessage();
        $chatId = $message?->getChat()?->getId();
        $text = trim($message?->getText());
        $contact = $message?->getContact();

        // Get or create subscriber
        $subscriber = Subscriber::firstOrCreate(['telegram_id' => $chatId]);

        // Handle /start
        if ($text === '/start') {
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

        // Handle contact sharing
        if ($contact && $contact->getPhoneNumber()) {
            $subscriber->phone = $contact->getPhoneNumber();
            $subscriber->save();

            return $this->sendOtp($subscriber, $chatId);
        }

        // If not verified and no phone number, block manual input
        if (!$subscriber->phone) {
            return $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '⚠️ Please use the button to share your phone number.'
            ]);
        }

        // Already verified
        if ($subscriber->verified) {
            return $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '✅ You are already subscribed.'
            ]);
        }

        // Handle /resend
        if ($text === '/resend') {
            return $this->sendOtp($subscriber, $chatId);
        }

        // OTP check
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
            } else {
                return $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => '❌ Incorrect OTP. Please try again or type /resend to get a new code.'
                ]);
            }
        } else {
            return $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '⏱ OTP expired or not found. Type /resend to get a new one.'
            ]);
        }
    }

    protected function sendOtp($subscriber, $chatId)
    {
        // Rate limit SMS resend (2 mins)
        if ($subscriber->last_sent_at && now()->diffInSeconds($subscriber->last_sent_at) < 120) {
            return $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => '🕒 Please wait before requesting a new OTP.'
            ]);
        }

        $otp = random_int(100000, 999999); // 6-digit OTP

        $subscriber->otp = (string)$otp;
        $subscriber->otp_expires_at = now()->addMinutes(5);
        $subscriber->last_sent_at = now();
        $subscriber->save();

        // Simulate SMS
        Log::info("📲 Sending OTP to {$subscriber->phone}: $otp");

        return $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "📩 We've sent you an OTP via SMS.\nPlease enter it to complete your subscription.\n\nType /resend if you didn’t receive it."
        ]);
    }
}
