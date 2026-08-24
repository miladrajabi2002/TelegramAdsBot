# Graph Report - .  (2026-08-24)

## Corpus Check
- 212 files · ~137,865 words
- Verdict: corpus is large enough that graph structure adds value.

## Summary
- 1107 nodes · 2536 edges · 118 communities (105 shown, 13 thin omitted)
- Extraction: 94% EXTRACTED · 6% INFERRED · 0% AMBIGUOUS · INFERRED: 148 edges (avg confidence: 0.81)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- Kyc Application
- Payment Service
- log Module
- Ledger Account
- Model Module
- Controller Module
- Payment Service Test
- Audit Logger
- Test Case
- Price Feed Service
- User Module
- Support Ticket
- package Module
- Campaign Transition Service
- Telegram Bot Client
- Order Module
- Campaign Controller
- Managed Telegram Ads Platform
- Campaign Correction Controller
- Order Controller
- Audit Log
- web Module
- Send Telegram Message
- scripts Module
- Campaign Transition Service
- install Module
- composer Module
- Mini App Notifier
- update Module
- require dev
- setup Module
- fix mariadb auth
- reload sh script
- config Module
- Ensure Admin
- Ensure Admin Permission
- Ensure Mini App User
- Security Headers
- Campaign Revision
- psr 4
- require Module
- post create project cmd
- White Paper Plane Symbol
- Ads Platform Icon
- app Module
- autoload dev
- laravel Module
- admin settings
- app campaigns
- app campaigns partials telegram native
- STATIC ASSETS
- partials payment result popup
- partials payment result popup
- payment result popup blade
- Nuclear Database Reset
- Wizard Reliability and Navigation Progress
- Open Crawler Policy

## God Nodes (most connected - your core abstractions)
1. `Order` - 95 edges
2. `User` - 64 edges
3. `Controller` - 48 edges
4. `PaymentService` - 47 edges
5. `PaymentIntent` - 45 edges
6. `CampaignTransitionService` - 44 edges
7. `AuditLogger` - 39 edges
8. `KycApplication` - 37 edges
9. `TelegramBotClient` - 35 edges
10. `Admin` - 30 edges

## Surprising Connections (you probably didn't know these)
- `Channel Lookup and Payment Manual Review Fixes` --semantically_similar_to--> `Gateway Response, Fee Tolerance, and Admin Resilience`  [INFERRED] [semantically similar]
  PATCHES_README.md → PATCHES_README_v4.md
- `Mobile Layout and Deployment Pipeline Fixes` --semantically_similar_to--> `Automated Nginx PM2 Server Deployment`  [INFERRED] [semantically similar]
  PATCHES_README_v3.md → docs/SERVER_DEPLOYMENT.md
- `Patch v8: KYC, Persian Amounts, and ZarinPay Callback` --conceptually_related_to--> `Verified Idempotent Payment Ledger`  [INFERRED]
  CHANGES.md → docs/PAYMENTS.md
- `Gateway Response, Fee Tolerance, and Admin Resilience` --conceptually_related_to--> `Verified Idempotent Payment Ledger`  [INFERRED]
  PATCHES_README_v4.md → docs/PAYMENTS.md
- `Managed Telegram Ads Platform` --references--> `cPanel Shared Hosting Deployment`  [EXTRACTED]
  README.md → docs/CPANEL_DEPLOYMENT.md

## Import Cycles
- None detected.

## Hyperedges (group relationships)
- **Managed Manual Advertising Operations** — readme_managed_telegram_ads_platform, docs_architecture_manual_operator_architecture, docs_operator_v1_manual_campaign_operations [INFERRED 0.85]
- **Security and Payment Integrity** — docs_security_kyc_private_data_controls, docs_payments_verified_idempotent_ledger, docs_telegram_setup_secure_miniapp_and_webhook_setup [INFERRED 0.85]

## Communities (118 total, 13 thin omitted)

### Community 0 - "Kyc Application"
Cohesion: 0.06
Nodes (29): KycController, RedirectResponse, Request, Response, View, KycController, RedirectResponse, Request (+21 more)

### Community 1 - "Payment Service"
Cohesion: 0.06
Nodes (17): PaymentController, JsonResponse, RedirectResponse, Request, View, PaymentAttempt, BelongsTo, PaymentIntent (+9 more)

### Community 2 - "log Module"
Cohesion: 0.05
Nodes (20): JsonResponse, RedirectResponse, Request, View, SessionController, AppServiceProvider, Model, createPayment() (+12 more)

### Community 3 - "Ledger Account"
Cohesion: 0.07
Nodes (12): LedgerAccount, HasMany, MorphTo, LedgerEntry, BelongsTo, LedgerTransaction, HasMany, MorphTo (+4 more)

### Community 4 - "Model Module"
Cohesion: 0.06
Nodes (22): BroadcastController, RedirectResponse, Request, View, Broadcast, BelongsTo, HasMany, BroadcastRecipient (+14 more)

### Community 5 - "Controller Module"
Cohesion: 0.07
Nodes (22): AuditController, Request, View, AuthController, RedirectResponse, Request, View, RedirectResponse (+14 more)

### Community 6 - "Payment Service Test"
Cohesion: 0.08
Nodes (17): DashboardController, View, Request, StreamedResponse, View, ReportController, Attribute, BelongsTo (+9 more)

### Community 7 - "Audit Logger"
Cohesion: 0.11
Nodes (15): CatalogController, JsonResponse, RedirectResponse, Request, View, OrderWorkflowController, RedirectResponse, Request (+7 more)

### Community 8 - "Test Case"
Cohesion: 0.07
Nodes (12): BaseTestCase, static, UserFactory, Factory, ExampleTest, TelegramWebhookTest, TestCase, CampaignTransitionServiceTest (+4 more)

### Community 9 - "Price Feed Service"
Cohesion: 0.08
Nodes (13): DbResetCommand, DiagnosePaymentReview, RefreshExchangeRates, RedirectResponse, Request, View, SettingsController, PricingRule (+5 more)

### Community 10 - "User Module"
Cohesion: 0.13
Nodes (7): TelegramWebhookController, HasMany, static, User, Authenticatable, HasOne, MorphMany

### Community 11 - "Support Ticket"
Cohesion: 0.14
Nodes (11): RedirectResponse, Request, View, SupportController, RedirectResponse, Request, View, SupportController (+3 more)

### Community 12 - "package Module"
Cohesion: 0.08
Nodes (25): concurrently, @fontsource-variable/manrope, @fontsource-variable/vazirmatn, @laravel/multiplex, laravel-vite-plugin, dependencies, @fontsource-variable/manrope, @fontsource-variable/vazirmatn (+17 more)

### Community 13 - "Campaign Transition Service"
Cohesion: 0.27
Nodes (4): TelegramSubmission, CampaignTransitionService, Model, OrderStatus

### Community 15 - "Order Module"
Cohesion: 0.19
Nodes (3): Order, BelongsTo, HasMany

### Community 16 - "Campaign Controller"
Cohesion: 0.29
Nodes (5): CampaignController, JsonResponse, RedirectResponse, Request, View

### Community 17 - "Managed Telegram Ads Platform"
Cohesion: 0.15
Nodes (18): Patch v8: KYC, Persian Amounts, and ZarinPay Callback, Manual Operator Architecture, Three-Layer Mini App Authentication, cPanel Shared Hosting Deployment, Advertising Content Policy English, Advertising Content Policy Persian, Terms of Service English, Terms of Service Persian (+10 more)

### Community 18 - "Campaign Correction Controller"
Cohesion: 0.20
Nodes (6): CampaignCorrectionController, RedirectResponse, Request, StreamedResponse, View, CampaignContentValidator

### Community 19 - "Order Controller"
Cohesion: 0.28
Nodes (5): OrderController, RedirectResponse, Request, StreamedResponse, View

### Community 20 - "Audit Log"
Cohesion: 0.22
Nodes (6): RedirectResponse, Request, View, UserController, AuditLog, MorphTo

### Community 21 - "web Module"
Cohesion: 0.22
Nodes (4): AvatarController, Response, JsonResponse, Request

### Community 22 - "Send Telegram Message"
Cohesion: 0.40
Nodes (8): DeleteTelegramMessage, SendBroadcastBatch, SendTelegramMessage, Dispatchable, InteractsWithQueue, Queueable, SerializesModels, ShouldQueue

### Community 23 - "scripts Module"
Cohesion: 0.14
Nodes (14): scripts, dev, post-autoload-dump, post-update-cmd, pre-package-uninstall, test, Composer\\Config::disableProcessTimeout, Illuminate\\Foundation\\ComposerScripts::postAutoloadDump (+6 more)

### Community 24 - "Campaign Transition Service"
Cohesion: 0.19
Nodes (3): OperatorTask, BelongsTo, BelongsTo

### Community 25 - "install Module"
Cohesion: 0.35
Nodes (10): c(), COMPOSER_ALLOW_SUPERUSER, e(), ensure_db_user(), ensure_php_ext(), ok(), PM2_HOME, install.sh script (+2 more)

### Community 26 - "composer Module"
Cohesion: 0.18
Nodes (10): description, keywords, license, minimum-stability, name, prefer-stable, $schema, type (+2 more)

### Community 28 - "update Module"
Cohesion: 0.46
Nodes (7): c(), COMPOSER_ALLOW_SUPERUSER, e(), ok(), update.sh script, step(), warn()

### Community 29 - "require dev"
Cohesion: 0.25
Nodes (8): require-dev, fakerphp/faker, laravel/pail, laravel/pao, laravel/pint, mockery/mockery, nunomaduro/collision, phpunit/phpunit

### Community 30 - "setup Module"
Cohesion: 0.25
Nodes (8): post-root-package-install, setup, composer install, npm install --ignore-scripts, npm run build, @php artisan key:generate, @php artisan migrate --force, @php -r \"file_exists('.env') || copy('.env.example', '.env');\

### Community 31 - "fix mariadb auth"
Cohesion: 0.48
Nodes (5): c(), e(), ok(), fix-mariadb-auth.sh script, warn()

### Community 32 - "reload sh script"
Cohesion: 0.57
Nodes (6): c(), e(), ok(), reload.sh script, step(), warn()

### Community 33 - "config Module"
Cohesion: 0.29
Nodes (7): pestphp/pest-plugin, php-http/discovery, config, allow-plugins, optimize-autoloader, preferred-install, sort-packages

### Community 34 - "Ensure Admin"
Cohesion: 0.53
Nodes (4): EnsureAdmin, Closure, Request, Response

### Community 35 - "Ensure Admin Permission"
Cohesion: 0.53
Nodes (4): EnsureAdminPermission, Closure, Request, Response

### Community 36 - "Ensure Mini App User"
Cohesion: 0.53
Nodes (4): EnsureMiniAppUser, Closure, Request, Response

### Community 37 - "Security Headers"
Cohesion: 0.53
Nodes (4): Closure, Request, Response, SecurityHeaders

### Community 39 - "psr 4"
Cohesion: 0.40
Nodes (5): autoload, psr-4, App\\, Database\\Factories\\, Database\\Seeders\\

### Community 40 - "require Module"
Cohesion: 0.50
Nodes (4): require, laravel/framework, laravel/tinker, php

### Community 41 - "post create project cmd"
Cohesion: 0.50
Nodes (4): post-create-project-cmd, @php artisan key:generate --ansi, @php artisan migrate --graceful --ansi, @php -r \"file_exists('database/database.sqlite') || touch('database/database.sqlite');\

### Community 42 - "White Paper Plane Symbol"
Cohesion: 0.50
Nodes (4): Ads Platform Icon, Blue Rounded Square Background, Blue Diagonal Plane Accent, White Paper Plane Symbol

### Community 43 - "Ads Platform Icon"
Cohesion: 0.67
Nodes (4): Ads Platform, Blue Rounded Square Background, Ads Platform Icon, White Paper Plane Symbol

### Community 44 - "app Module"
Cohesion: 0.67
Nodes (3): onTelegramReady(), IMPORTANT: isStepValid + updateNextButtonState MUST be declared, ready()

### Community 45 - "autoload dev"
Cohesion: 0.67
Nodes (3): autoload-dev, psr-4, Tests\\

### Community 46 - "laravel Module"
Cohesion: 0.67
Nodes (3): extra, laravel, dont-discover

## Knowledge Gaps
- **78 isolated node(s):** `COMPOSER_ALLOW_SUPERUSER`, `PM2_HOME`, `COMPOSER_ALLOW_SUPERUSER`, `$schema`, `name` (+73 more)
  These have ≤1 connection - possible missing edges or undocumented components.
- **13 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `Order` connect `Order Module` to `Kyc Application`, `Payment Service`, `Ledger Account`, `Model Module`, `Payment Service Test`, `Audit Logger`, `Campaign Revision`, `Test Case`, `Campaign Transition Service`, `Campaign Controller`, `Campaign Correction Controller`, `Order Controller`, `web Module`, `Campaign Transition Service`, `Mini App Notifier`?**
  _High betweenness centrality (0.098) - this node is a cross-community bridge._
- **Why does `Controller` connect `Controller Module` to `Kyc Application`, `Payment Service`, `log Module`, `Model Module`, `Payment Service Test`, `Audit Logger`, `Price Feed Service`, `User Module`, `Support Ticket`, `Campaign Controller`, `Campaign Correction Controller`, `Order Controller`, `Audit Log`, `web Module`?**
  _High betweenness centrality (0.069) - this node is a cross-community bridge._
- **Why does `User` connect `User Module` to `Kyc Application`, `Payment Service`, `log Module`, `Ledger Account`, `Model Module`, `Controller Module`, `Payment Service Test`, `Test Case`, `Price Feed Service`, `Telegram Bot Client`, `Audit Log`, `web Module`, `Mini App Notifier`?**
  _High betweenness centrality (0.049) - this node is a cross-community bridge._
- **Are the 11 inferred relationships involving `Order` (e.g. with `.__invoke()` and `.export()`) actually correct?**
  _`Order` has 11 INFERRED edges - model-reasoned connections that need verification._
- **Are the 13 inferred relationships involving `User` (e.g. with `.store()` and `.__invoke()`) actually correct?**
  _`User` has 13 INFERRED edges - model-reasoned connections that need verification._
- **What connects `COMPOSER_ALLOW_SUPERUSER`, `PM2_HOME`, `COMPOSER_ALLOW_SUPERUSER` to the rest of the system?**
  _78 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Kyc Application` be split into smaller, more focused modules?**
  _Cohesion score 0.05696969696969697 - nodes in this community are weakly interconnected._