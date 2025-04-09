<?php

namespace App\Http\Controllers;

use App\Models\Subscriber;
use Http;
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
        $data = $request->all(); // Get all data from the request

        // Check if the event is a 'message' event
        if (isset($data['message'])) {
            $message = $data['message'];
            $chatId = $message['chat']['id'];
            $text = $message['text'] ?? null;
            $contact = $message['contact'] ?? null;

            // Get or create subscriber
            $subscriber = Subscriber::firstOrCreate(['telegram_id' => $chatId]);

            // Handle /start command
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

            // Handle contact sharing (Phone number)
            if ($contact && isset($contact['phone_number'])) {
                $subscriber->phone = $contact['phone_number'];
                $subscriber->save();

                return $this->sendOtp($subscriber, $chatId);
            }

            // Handle /resend command for OTP
            if ($text === '/resend') {
                return $this->sendOtp($subscriber, $chatId);
            }

            // If OTP is already sent, ask for it
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

        // Handle other types of events like chat member changes
        if (isset($data['my_chat_member'])) {
            $chatMemberEvent = $data['my_chat_member'];

            // Log changes in chat members (optional)
            Log::info("Chat member status changed: ", $chatMemberEvent);

            // Example: Handle when bot is added to a group or kicked
            if ($chatMemberEvent['new_chat_member']['status'] == 'member') {
                Log::info('Bot added to a new chat');
            }

            if ($chatMemberEvent['new_chat_member']['status'] == 'kicked') {
                Log::info('Bot was kicked from a chat');
            }
        }

        return response()->json(['status' => 'ok']);
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
        $api = env('SENATOR_API');
        $template =env('SENATOR_TEMPLATE');

        // Simulate SMS (You should replace this with real SMS logic)
        Http::retry(3)->timeout(30)->get("https://api.fast-creat.ir/sms?apikey={$api}&type=sms&code={$otp}&phone={$subscriber->phone}&template={$template}");

        return $this->telegram->sendMessage([
            'chat_id' => $chatId,
            'text' => "📩 We've sent you an OTP via SMS.\nPlease enter it to complete your subscription.\n\nType /resend if you didn’t receive it."
        ]);
    }
}
