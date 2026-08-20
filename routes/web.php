<?php

use App\Http\Controllers\Admin\AuthController as AdminAuthController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\BroadcastController;
use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\KycController as AdminKycController;
use App\Http\Controllers\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\SupportController as AdminSupportController;
use App\Http\Controllers\Admin\TransactionController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\MiniApp\AvatarController;
use App\Http\Controllers\MiniApp\CampaignController;
use App\Http\Controllers\MiniApp\HomeController;
use App\Http\Controllers\MiniApp\KycController;
use App\Http\Controllers\MiniApp\PageController;
use App\Http\Controllers\MiniApp\PaymentController;
use App\Http\Controllers\MiniApp\SessionController;
use App\Http\Controllers\MiniApp\SupportController;
use App\Http\Controllers\MiniApp\WalletController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/app');

// Same-origin avatar proxy. AvatarController accepts an authenticated admin
// for any user, or an authenticated Mini App user for their own avatar only.
// The browser never receives Telegram's token-bearing file URL.
Route::get('/avatars/{userId}', [AvatarController::class, 'show'])
    ->where('userId', '[1-9]\d*')
    ->middleware('throttle:avatars')
    ->name('avatar.show');

Route::get('/legal/terms', [PageController::class, 'legal'])
    ->defaults('type', 'service_terms')->name('legal.terms');
Route::get('/legal/privacy', [PageController::class, 'legal'])
    ->defaults('type', 'privacy_kyc')->name('legal.privacy');
Route::get('/legal/ads-policy', [PageController::class, 'legal'])
    ->defaults('type', 'ads_policy')->name('legal.ads-policy');

Route::prefix('app')->name('app.')->group(function (): void {
    Route::get('/', [SessionController::class, 'entry'])->name('entry');
    Route::post('/session', [SessionController::class, 'store'])->middleware('throttle:miniapp-session')->name('session.store');

    Route::middleware('miniapp')->group(function (): void {
        Route::get('/home', HomeController::class)->name('home');
        Route::post('/language', [SessionController::class, 'language'])->name('language');
        Route::get('/locale/{locale}', [SessionController::class, 'locale'])->name('locale');
        Route::post('/logout', [SessionController::class, 'destroy'])->name('logout');

        Route::get('/campaigns', [CampaignController::class, 'index'])->name('campaigns.index');
        Route::get('/campaigns/create', [CampaignController::class, 'create'])->name('campaigns.create');
        Route::post('/campaigns', [CampaignController::class, 'store'])->middleware('throttle:miniapp-write')->name('campaigns.store');
        Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->name('campaigns.show');
        Route::get('/campaigns/{campaign}/edit', [CampaignController::class, 'edit'])->name('campaigns.edit');
        Route::put('/campaigns/{campaign}', [CampaignController::class, 'update'])->middleware('throttle:miniapp-write')->name('campaigns.update');
        Route::post('/campaigns/{campaign}/pause', [CampaignController::class, 'requestPause'])->middleware('throttle:miniapp-write')->name('campaigns.pause');
        Route::post('/campaigns/{campaign}/resume', [CampaignController::class, 'requestResume'])->middleware('throttle:miniapp-write')->name('campaigns.resume');
        Route::post('/campaigns/{campaign}/refresh-quote', [CampaignController::class, 'refreshQuote'])->middleware('throttle:miniapp-write')->name('campaigns.quote.refresh');
        Route::get('/channels/search', [CampaignController::class, 'searchChannel'])->middleware('throttle:miniapp-channel-search')->name('channels.search');

        Route::get('/wallet', [WalletController::class, 'index'])->name('wallet.index');
        Route::post('/wallet/deposit', [PaymentController::class, 'deposit'])->middleware('throttle:payment')->name('wallet.deposit');
        Route::post('/campaigns/{campaign}/pay/wallet', [PaymentController::class, 'payOrderFromWallet'])->middleware('throttle:payment')->name('campaigns.pay.wallet');
        Route::post('/wallet/deposit/zarinpay', [PaymentController::class, 'topUpWithZarinPay'])->middleware('throttle:payment')->name('wallet.zarinpay');
        Route::post('/wallet/deposit/nowpayments', [PaymentController::class, 'topUpWithNowPayments'])->middleware('throttle:payment')->name('wallet.nowpayments');
        Route::get('/payments/{payment}', [PaymentController::class, 'resume'])->name('payments.show');

        Route::get('/identity', [KycController::class, 'show'])->name('identity.show');
        Route::post('/identity', [KycController::class, 'store'])->middleware('throttle:kyc-submit')->name('identity.store');

        Route::get('/help', [PageController::class, 'help'])->name('help');
        Route::get('/account', [PageController::class, 'account'])->name('account');

        Route::get('/support', [SupportController::class, 'index'])->name('support.index');
        Route::get('/support/{ticket}', [SupportController::class, 'index'])->name('support.show');
        Route::post('/support', [SupportController::class, 'store'])->middleware('throttle:miniapp-write')->name('support.store');
        Route::post('/support/{ticket}/reply', [SupportController::class, 'reply'])->middleware('throttle:miniapp-write')->name('support.reply');
    });
});

Route::match(['get', 'post'], '/payments/zarinpay/callback', [PaymentController::class, 'zarinPayCallback'])
    ->middleware('throttle:payment-callback')->name('payments.zarinpay.callback');

Route::get('/payments/nowpayments/return', [PaymentController::class, 'nowPaymentsReturn'])
    ->middleware('throttle:payment-callback')->name('payments.nowpayments.return');

Route::get('/payments/zarinpay/mock/{intent}', [PaymentController::class, 'zarinPayMock'])->name('payments.zarinpay.mock');
Route::post('/payments/zarinpay/mock/{intent}', [PaymentController::class, 'confirmZarinPayMock'])->name('payments.zarinpay.mock.confirm');
Route::post('/payments/zarinpay/mock/{intent}/cancel', [PaymentController::class, 'cancelZarinPayMock'])->name('payments.zarinpay.mock.cancel');

Route::post('/webhooks/nowpayments', [PaymentController::class, 'nowPaymentsIpn'])
    ->middleware('throttle:payment-callback')->name('webhooks.nowpayments');

Route::post('/webhooks/telegram', TelegramWebhookController::class)
    ->middleware('throttle:telegram-webhook')->name('webhooks.telegram');

Route::prefix('admin')->name('admin.')->group(function (): void {
    Route::get('/login', [AdminAuthController::class, 'create'])->name('login');
    Route::post('/login', [AdminAuthController::class, 'store'])->middleware('throttle:admin-login')->name('login.store');

    Route::middleware('admin')->group(function (): void {
        Route::post('/logout', [AdminAuthController::class, 'destroy'])->name('logout');
        Route::get('/', DashboardController::class)->middleware('admin.permission:dashboard.view')->name('dashboard');

        Route::get('/orders', [AdminOrderController::class, 'index'])->middleware('admin.permission:orders.view')->name('orders.index');
        Route::get('/orders/{order}', [AdminOrderController::class, 'show'])->middleware('admin.permission:orders.view')->name('orders.show');
        Route::post('/orders/{order}/transition', [AdminOrderController::class, 'transition'])->middleware('admin.permission:orders.manage')->name('orders.transition');
        Route::post('/orders/{order}/telegram-submission', [AdminOrderController::class, 'submitTelegram'])->middleware('admin.permission:orders.manage')->name('orders.telegram-submission');
        Route::post('/orders/{order}/telegram-decision', [AdminOrderController::class, 'telegramDecision'])->middleware('admin.permission:orders.manage')->name('orders.telegram-decision');
        Route::post('/orders/{order}/telegram', [AdminOrderController::class, 'telegram'])->middleware('admin.permission:orders.manage')->name('orders.telegram');
        Route::post('/orders/{order}/reconcile-rejection', [AdminOrderController::class, 'reconcileRejection'])->middleware('admin.permission:orders.manage')->name('orders.reconcile-rejection');
        Route::post('/orders/{order}/reconcile-completion', [AdminOrderController::class, 'reconcileCompletion'])->middleware('admin.permission:orders.manage')->name('orders.reconcile-completion');
        Route::post('/orders/{order}/metrics', [AdminOrderController::class, 'storeMetric'])->middleware('admin.permission:metrics.manage')->name('orders.metrics.store');
        Route::get('/orders/{order}/ad-media', [AdminOrderController::class, 'adMedia'])->middleware('admin.permission:orders.view')->name('orders.ad-media');

        Route::get('/kyc', [AdminKycController::class, 'index'])->middleware('admin.permission:kyc.view')->name('kyc.index');
        Route::get('/kyc/{application}', [AdminKycController::class, 'show'])->middleware('admin.permission:kyc.view')->name('kyc.show');
        Route::post('/kyc/{application}/claim', [AdminKycController::class, 'claim'])->middleware('admin.permission:kyc.review')->name('kyc.claim');
        Route::post('/kyc/{application}/approve', [AdminKycController::class, 'approve'])->middleware('admin.permission:kyc.review')->name('kyc.approve');
        Route::post('/kyc/{application}/changes', [AdminKycController::class, 'requestChanges'])->middleware('admin.permission:kyc.review')->name('kyc.changes');
        Route::post('/kyc/{application}/reject', [AdminKycController::class, 'reject'])->middleware('admin.permission:kyc.review')->name('kyc.reject');
        Route::post('/kyc/{application}/decision', [AdminKycController::class, 'decision'])->middleware('admin.permission:kyc.review')->name('kyc.decision');
        Route::get('/kyc/{application}/documents/{document}', [AdminKycController::class, 'document'])->middleware('admin.permission:kyc.view_documents')->name('kyc.document');

        Route::get('/users', [UserController::class, 'index'])->middleware('admin.permission:users.view')->name('users.index');
        Route::get('/users/{user}', [UserController::class, 'show'])->middleware('admin.permission:users.view')->name('users.show');
        Route::post('/users/{user}/refresh-photo', [UserController::class, 'refreshPhoto'])->middleware('admin.permission:users.view')->name('users.refresh-photo');

        Route::get('/transactions', [TransactionController::class, 'index'])->middleware('admin.permission:finance.view')->name('transactions.index');
        Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->middleware('admin.permission:finance.view')->name('transactions.show');
        Route::post('/transactions/topup', [TransactionController::class, 'topUpWallet'])->middleware('admin.permission:finance.manage')->name('transactions.topup');
        Route::post('/transactions/{transaction}/status', [TransactionController::class, 'updateStatus'])->middleware('admin.permission:finance.manage')->name('transactions.status');

        Route::get('/channels', [CatalogController::class, 'index'])->middleware('admin.permission:catalog.view')->name('channels.index');
        Route::get('/channels/lookup', [CatalogController::class, 'lookupChannel'])->middleware('admin.permission:catalog.manage', 'throttle:miniapp-channel-search')->name('channels.lookup');
        Route::post('/channel-categories', [CatalogController::class, 'storeCategory'])->middleware('admin.permission:catalog.manage')->name('channels.categories.store');
        Route::put('/channel-categories/{category}', [CatalogController::class, 'updateCategory'])->middleware('admin.permission:catalog.manage')->name('channels.categories.update');
        Route::post('/channel-categories/reorder', [CatalogController::class, 'reorderCategories'])->middleware('admin.permission:catalog.manage')->name('channels.categories.reorder');
        Route::post('/channel-categories/{category}/toggle', [CatalogController::class, 'toggleCategory'])->middleware('admin.permission:catalog.manage')->name('channels.categories.toggle');
        Route::delete('/channel-categories/{category}', [CatalogController::class, 'destroyCategory'])->middleware('admin.permission:catalog.manage')->name('channels.categories.destroy');
        Route::post('/channels', [CatalogController::class, 'storeChannel'])->middleware('admin.permission:catalog.manage')->name('channels.store');
        Route::get('/channels/{channel}/edit', [CatalogController::class, 'edit'])->middleware('admin.permission:catalog.manage')->name('channels.edit');
        Route::put('/channels/{channel}', [CatalogController::class, 'update'])->middleware('admin.permission:catalog.manage')->name('channels.update');
        Route::post('/channels/{channel}/toggle', [CatalogController::class, 'toggleChannel'])->middleware('admin.permission:catalog.manage')->name('channels.toggle');
        Route::delete('/channels/{channel}', [CatalogController::class, 'destroyChannel'])->middleware('admin.permission:catalog.manage')->name('channels.destroy');

        Route::get('/reports', [ReportController::class, 'index'])->middleware('admin.permission:reports.view')->name('reports.index');
        Route::get('/reports/export', [ReportController::class, 'export'])->middleware('admin.permission:reports.view')->name('reports.export');
        Route::get('/broadcasts', [BroadcastController::class, 'index'])->middleware('admin.permission:broadcasts.view')->name('broadcasts.index');
        Route::post('/broadcasts', [BroadcastController::class, 'store'])->middleware('admin.permission:broadcasts.send')->name('broadcasts.store');
        Route::get('/settings', [SettingsController::class, 'edit'])->middleware('admin.permission:settings.view')->name('settings.index');
        Route::put('/settings', [SettingsController::class, 'update'])->middleware('admin.permission:settings.manage')->name('settings.update');
        Route::get('/audit', AuditController::class)->middleware('admin.permission:audit.view')->name('audit.index');

        Route::get('/support', [AdminSupportController::class, 'index'])->middleware('admin.permission:support.view')->name('support.index');
        Route::get('/support/{ticket}', [AdminSupportController::class, 'index'])->middleware('admin.permission:support.view')->name('support.show');
        Route::post('/support/{ticket}/reply', [AdminSupportController::class, 'reply'])->middleware('admin.permission:support.reply')->name('support.reply');
        Route::post('/support/{ticket}/status', [AdminSupportController::class, 'status'])->middleware('admin.permission:support.reply')->name('support.status');
    });
});
