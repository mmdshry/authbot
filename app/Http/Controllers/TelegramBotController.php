<?php

// app/Http/Controllers/TelegramBotController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Telegram\Bot\Api;
use App\Models\Subscriber;

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
        $chatId = $update->getMessage()?->getChat()?->getId();
        $text = $update->getMessage()?->getText();

        $subscriber = Subscriber::firstOrCreate(['telegram_id' => $chatId]);

        if (!$subscriber->phone) {
            if (preg_match('/^\+?\d{10,15}$/', $text)) {
                $subscriber->phone = $text;

                if ($subscriber->verified) {
                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => 'You are already subscribed.'
                    ]);
                } else {
                    // Simulate sending SMS with OTP '0'
                    // You can integrate real SMS API here

                    $subscriber->save();

                    $this->telegram->sendMessage([
                        'chat_id' => $chatId,
                        'text' => "We sent you an OTP via SMS. Please enter the code."
                    ]);
                }
            } else {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Please enter your phone number (e.g., +12345678900).'
                ]);
            }
        } elseif (!$subscriber->verified) {
            if ($text === '0') {
                $subscriber->verified = true;
                $subscriber->save();

                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Your subscription has been successful ✅'
                ]);
            } else {
                $this->telegram->sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Invalid OTP. Try again.'
                ]);
            }
        } else {
            $this->telegram->sendMessage([
                'chat_id' => $chatId,
                'text' => 'You are already subscribed.'
            ]);
        }

        return response('ok', 200);
    }
}

