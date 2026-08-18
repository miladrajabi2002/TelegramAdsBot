<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendTelegramMessage;
use App\Models\Admin;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SupportController extends Controller
{
    public function index(Request $request, ?SupportTicket $ticket = null): View
    {
        $status = $request->input('status') === 'pending_user' ? 'waiting_user' : $request->input('status');
        // Escape LIKE wildcards so a user typing "%" doesn't match every row.
        $term = trim((string) $request->input('q', ''));
        $escapedTerm = $term !== '' ? str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) : '';

        $tickets = SupportTicket::with(['user', 'order'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $status))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->when($request->filled('priority'), fn ($q) => $q->where('priority', $request->input('priority')))
            ->when($request->filled('assigned_admin_id'), function ($q) use ($request): void {
                if ($request->input('assigned_admin_id') === 'me') {
                    $q->where('assigned_admin_id', auth('admin')->id());
                } else {
                    $q->where('assigned_admin_id', $request->integer('assigned_admin_id'));
                }
            })
            ->when($escapedTerm !== '', function ($query) use ($escapedTerm): void {
                $query->where(fn ($nested) => $nested->where('subject', 'like', "%{$escapedTerm}%")
                    ->orWhereHas('user', fn ($user) => $user->where('display_name', 'like', "%{$escapedTerm}%")
                        ->orWhere('telegram_username', 'like', "%{$escapedTerm}%"))
                    ->orWhereHas('order', fn ($order) => $order->where('public_id', 'like', "%{$escapedTerm}%")));
            })
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 WHEN status = 'waiting_user' THEN 1 ELSE 2 END")
            ->latest('last_message_at')->paginate(25)->withQueryString();
        if ($ticket) {
            $ticket->load(['user', 'order', 'messages.sender', 'assignee']);
            // Use the morph class directly — previously the LIKE pattern could
            // silently break if Laravel changed morph-map formatting.
            $ticket->messages()
                ->where('sender_type', User::class)
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
        }

        $availableAdmins = Admin::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return view('admin.support.index', [
            'tickets' => $tickets,
            'selectedTicket' => $ticket,
            'activeTicket' => $ticket,
            'availableAdmins' => $availableAdmins,
        ]);
    }

    public function reply(Request $request, SupportTicket $ticket, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'min:2', 'max:4000'],
            'assigned_admin_id' => ['nullable', 'integer', 'exists:admins,id'],
            'status' => ['nullable', Rule::in(['open', 'pending_user', 'waiting_user', 'closed'])],
        ]);
        $admin = auth('admin')->user();
        $ticket->messages()->create([
            'sender_type' => $admin->getMorphClass(),
            'sender_id' => $admin->getKey(),
            'body' => $data['body'],
        ]);
        // Simplify the dead-code ternary: validation already guarantees $data['status']
        // is one of the allowed values or null.
        $status = ($data['status'] ?? 'waiting_user') === 'pending_user'
            ? 'waiting_user'
            : ($data['status'] ?? 'waiting_user');
        $ticket->update([
            'status' => $status,
            'assigned_admin_id' => $data['assigned_admin_id'] ?? $admin->getKey(),
            'last_message_at' => now(),
        ]);
        $audit->log('support.replied', $admin, $ticket);
        SendTelegramMessage::dispatch($ticket->user->telegram_user_id, 'پشتیبانی به تیکت «'.htmlspecialchars($ticket->subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'» پاسخ داد. پاسخ را در مینی‌اپ مشاهده کنید.');

        return back()->with('success', 'پاسخ ثبت و اعلان تلگرام در صف ارسال قرار گرفت.');
    }

    public function status(Request $request, SupportTicket $ticket, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'pending_user', 'waiting_user', 'closed'])]]);
        $before = $ticket->status;
        $status = $data['status'] === 'pending_user' ? 'waiting_user' : $data['status'];
        $ticket->update(['status' => $status, 'assigned_admin_id' => auth('admin')->id()]);
        $audit->log('support.status_changed', auth('admin')->user(), $ticket, before: ['status' => $before], after: ['status' => $status]);

        return back()->with('success', 'وضعیت تیکت تغییر کرد.');
    }
}
