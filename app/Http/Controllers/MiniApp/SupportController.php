<?php

namespace App\Http\Controllers\MiniApp;

use App\Http\Controllers\Controller;
use App\Jobs\SendTelegramMessage;
use App\Models\Admin;
use App\Models\SupportTicket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SupportController extends Controller
{
    public function index(Request $request, ?SupportTicket $ticket = null): View
    {
        $tickets = $request->user()->supportTickets()->latest('last_message_at')->paginate(15);
        $campaigns = $ticket
            ? collect()
            : $request->user()->orders()->with('currentRevision')->latest()->limit(50)->get();

        if ($ticket) {
            abort_unless($ticket->user_id === $request->user()->getKey(), 404);
            $ticket->load('messages.sender');
            $ticket->messages()
                ->where('sender_type', Admin::class)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        return view('app.support.index', [
            'tickets' => $tickets,
            'selectedTicket' => $ticket,
            'activeTicket' => $ticket,
            'campaigns' => $campaigns,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'min:4', 'max:150', 'regex:/\S/u'],
            'body' => ['required', 'string', 'min:5', 'max:4000', 'regex:/\S/u'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
        ], [
            'subject.regex' => 'موضوع تیکت نمی‌تواند فقط شامل فاصله باشد.',
            'body.regex' => 'متن پیام نمی‌تواند فقط شامل فاصله باشد.',
        ]);

        if (! empty($data['order_id'])) {
            abort_unless($request->user()->orders()->whereKey($data['order_id'])->exists(), 404);
        }

        $subject = preg_replace('/\s+/u', ' ', trim($data['subject'])) ?? trim($data['subject']);
        $body = trim($data['body']);

        $ticket = DB::transaction(function () use ($request, $data, $subject, $body): SupportTicket {
            $ticket = SupportTicket::create([
                'user_id' => $request->user()->getKey(),
                'order_id' => $data['order_id'] ?? null,
                'subject' => $subject,
                'status' => 'open',
                'priority' => 'normal',
                'last_message_at' => now(),
            ]);

            $ticket->messages()->create([
                'sender_type' => $request->user()->getMorphClass(),
                'sender_id' => $request->user()->getKey(),
                'body' => $body,
            ]);

            return $ticket;
        });

        SendTelegramMessage::dispatch(
            $request->user()->telegram_user_id,
            'تیکت شما با شناسه '.$ticket->public_id.' دریافت شد. پاسخ از طریق مینی‌اپ و همین ربات اطلاع‌رسانی می‌شود.',
        );

        return redirect()->route('app.support.show', $ticket)->with('success', 'پیام شما دریافت شد.');
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        abort_unless($ticket->user_id === $request->user()->getKey(), 404);

        if ($ticket->status === 'closed') {
            return back()->with('warning', 'این تیکت بسته شده است. اگر موضوع جدیدی دارید، یک تیکت تازه ثبت کنید.');
        }

        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:4000', 'regex:/\S/u'],
        ], [
            'body.regex' => 'متن پاسخ نمی‌تواند فقط شامل فاصله باشد.',
        ]);

        $ticket->messages()->create([
            'sender_type' => $request->user()->getMorphClass(),
            'sender_id' => $request->user()->getKey(),
            'body' => trim($data['body']),
        ]);
        $ticket->update(['status' => 'open', 'last_message_at' => now()]);

        return back()->with('success', 'پیام ارسال شد.');
    }
}
