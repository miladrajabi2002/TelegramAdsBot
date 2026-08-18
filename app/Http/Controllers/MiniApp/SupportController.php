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
        // Only load campaigns dropdown when creating a ticket — saves 50 rows
        // + their eager-loaded currentRevision on every existing-ticket view.
        $campaigns = $ticket
            ? collect()
            : $request->user()->orders()->with('currentRevision')->latest()->limit(50)->get();
        if ($ticket) {
            abort_unless($ticket->user_id === $request->user()->getKey(), 404);
            $ticket->load('messages.sender');
            // Mark admin messages as read using the morph class directly — the
            // previous 'like %Admin' pattern would silently break if Laravel
            // changed its morph-map formatting (e.g. via Relation::enforceMorphMap).
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
            'subject' => ['required', 'string', 'min:4', 'max:150'],
            'body' => ['required', 'string', 'min:5', 'max:4000'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
        ]);
        if (! empty($data['order_id'])) {
            abort_unless($request->user()->orders()->whereKey($data['order_id'])->exists(), 404);
        }

        $ticket = DB::transaction(function () use ($request, $data): SupportTicket {
            $ticket = SupportTicket::create([
                'user_id' => $request->user()->getKey(),
                'order_id' => $data['order_id'] ?? null,
                'subject' => $data['subject'],
                'status' => 'open',
                'priority' => 'normal',
                'last_message_at' => now(),
            ]);
            $ticket->messages()->create([
                'sender_type' => $request->user()->getMorphClass(),
                'sender_id' => $request->user()->getKey(),
                'body' => $data['body'],
            ]);

            return $ticket;
        });

        SendTelegramMessage::dispatch($request->user()->telegram_user_id, 'تیکت شما با شناسه '.$ticket->public_id.' دریافت شد. پاسخ از طریق مینی‌اپ و همین ربات اطلاع‌رسانی می‌شود.');

        return redirect()->route('app.support.show', $ticket)->with('success', 'پیام شما دریافت شد.');
    }

    public function reply(Request $request, SupportTicket $ticket): RedirectResponse
    {
        abort_unless($ticket->user_id === $request->user()->getKey(), 404);
        abort_if($ticket->status === 'closed', 422, 'این تیکت بسته شده است.');
        $data = $request->validate(['body' => ['required', 'string', 'min:2', 'max:4000']]);
        $ticket->messages()->create([
            'sender_type' => $request->user()->getMorphClass(),
            'sender_id' => $request->user()->getKey(),
            'body' => $data['body'],
        ]);
        $ticket->update(['status' => 'open', 'last_message_at' => now()]);

        return back()->with('success', 'پیام ارسال شد.');
    }
}
